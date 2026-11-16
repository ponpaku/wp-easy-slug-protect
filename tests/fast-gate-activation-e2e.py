#!/usr/bin/env python3
"""Disposable real Apache transition regression. No production config changes."""
import concurrent.futures
import copy
import http.cookiejar
from http.cookies import SimpleCookie
import json
import math
import statistics
import threading
import time
import urllib.error
import urllib.request
from urllib.parse import parse_qs, urlsplit


BASE = "http://127.0.0.1:8096"
passed = 0
MEDIA = BASE + "/wp-content/uploads/fg-life-protected.jpg"
GATE_COOKIE = "esp_gate_fg_path"
BRIDGE_REPORT = []


def request(path, jar=None, cookie=None, method="GET"):
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(
            jar if jar is not None else http.cookiejar.CookieJar()))
    target = urllib.request.Request(BASE + path,
        headers={"Cookie": cookie} if cookie is not None else {}, method=method)
    try:
        with opener.open(target, timeout=18) as response:
            return response.status, response.read(), dict(response.headers.items())
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read(), dict(exc.headers.items())


def check(ok, text):
    global passed
    if not ok:
        raise RuntimeError("Fast Gate activation E2E: " + text)
    passed += 1


def command(action, status, jar=None):
    code, body, _ = request("/?esp_fg_ci_auth=deploy-" + action, jar=jar)
    try:
        data = json.loads(body)
    except Exception as e:
        raise RuntimeError(f"Deployment {action} non-JSON {code}: {body[:180]!r}") from e
    print("FG_DEPLOY_ACTION HTTP=" + str(code) + " " + json.dumps(data), flush=True)
    check(code == status, f"{action} HTTP code {code}; expected {status}; {data}")
    return data



class BridgeRedirect(urllib.request.HTTPRedirectHandler):
    """Never follow a second hop or a cross-origin redirect in this fixture."""

    def __init__(self):
        super().__init__()
        self.hops = 0

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        self.hops += 1
        target = urlsplit(newurl)
        query = parse_qs(target.query)
        if (self.hops != 1 or code != 307 or
                (target.scheme, target.netloc, target.path) !=
                ("http", "127.0.0.1:8096", "/") or
                set(query) != {"esp_gate_bridge"} or
                len(query["esp_gate_bridge"]) != 1 or
                "no-store" not in headers.get("Cache-Control", "") or
                headers.get("Referrer-Policy") != "no-referrer"):
            raise RuntimeError("Unexpected bridge redirect or cache policy")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class StopRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class RejectGateCookie(http.cookiejar.DefaultCookiePolicy):
    def set_ok(self, cookie, request):
        return cookie.name != GATE_COOKIE and super().set_ok(cookie, request)


def source_cookies(jar, kind):
    names = ({"esp_auth_fg_path"} if kind == "session" else
             {"esp_remember_id_fg_path", "esp_remember_token_fg_path"})
    sources = tuple(copy.copy(c) for c in jar if c.name in names)
    check({c.name for c in sources} == names,
          kind + " fixture has the original ESP credentials")
    return sources


def client_jar(cookies, reject=False):
    jar = http.cookiejar.CookieJar(policy=RejectGateCookie() if reject else None)
    for c in cookies:
        jar.set_cookie(copy.copy(c))
    return jar


def bridge_round_trip(sources, expected, barrier=None, reject=False):
    # Each in-flight request starts with the same source credentials but no
    # Gate cookie. Separate jars avoid races in Python's cookie processing
    # and model requests already dispatched before a browser sees Set-Cookie.
    jar = client_jar(sources, reject)
    recorder = BridgeRedirect()
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar), recorder)
    if barrier is not None:
        barrier.wait(timeout=20)
    times = []
    for attempt in range(2):
        recorder.hops = 0
        start = time.perf_counter()
        try:
            with opener.open(MEDIA, timeout=30) as response:
                status, body = response.status, response.read()
                cache = response.headers.get("Cache-Control", "")
                referrer = response.headers.get("Referrer-Policy")
                issued = []
                for raw in response.headers.get_all("Set-Cookie", []):
                    parsed = SimpleCookie()
                    parsed.load(raw)
                    if GATE_COOKIE in parsed:
                        issued.append(parsed[GATE_COOKIE])
        except Exception as exc:
            # Exception URLs may contain encrypted handoffs; do not print
            # them, response bodies, credentials or Set-Cookie values.
            raise RuntimeError("Bridge request failed: " +
                               type(exc).__name__) from None
        elapsed = time.perf_counter() - start
        needs_bridge = attempt == 0 or reject
        if (status != 200 or body != expected or "no-store" not in cache or
                recorder.hops != int(needs_bridge) or
                len(issued) != int(needs_bridge) or
                elapsed > 30):
            raise RuntimeError("Bridge first/repeat response contract failed")
        if needs_bridge and (referrer != "no-referrer" or
                issued[0]["path"] != "/" or
                not issued[0]["httponly"] or
                issued[0]["samesite"].lower() != "strict"):
            raise RuntimeError("Bridge cookie attributes/referrer policy failed")
        if any(c.name == GATE_COOKIE for c in jar) == reject:
            raise RuntimeError("Unexpected Gate cookie acceptance")
        times.append(elapsed * 1000)
    return times


def describe_ms(values):
    ordered = sorted(values)
    return {"p50_ms": round(statistics.median(ordered), 3),
            "p95_ms": round(ordered[math.ceil(len(ordered)*.95)-1], 3),
            "p99_ms": round(ordered[math.ceil(len(ordered)*.99)-1], 3),
            "max_ms": round(ordered[-1], 3)}


def bridge_burst(sources, expected, workers):
    # 1/20/100 concurrent clients: synchronize the actual first requests,
    # then require every client's next request to stay on the standalone path.
    # A 30-second end-to-end bound per request is a CI liveness guard, not a
    # production speed SLA. The server's real worker limits are unchanged.
    barrier = threading.Barrier(workers)
    start = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as pool:
        futures = [pool.submit(bridge_round_trip, sources, expected, barrier)
                   for _ in range(workers)]
        readings = [f.result() for f in futures]
    record = {"workers": workers, "first_307_wp200": len(readings),
              "next_direct_200": len(readings),
              "wall_seconds": round(time.perf_counter()-start, 3),
              "bridge": describe_ms([v[0] for v in readings]),
              "standalone": describe_ms([v[1] for v in readings])}
    BRIDGE_REPORT.append(record)
    print("FG_BRIDGE_BURST " + json.dumps(record), flush=True)
    check(len(readings) == workers, str(workers) + " bridge clients passed")


def rejected_handoffs(sources):
    jar = client_jar(sources)
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar), StopRedirect())
    try:
        opener.open(MEDIA, timeout=15).close()
    except urllib.error.HTTPError as exc:
        code, location = exc.code, exc.headers.get("Location", "")
        exc.close()
    else:
        raise RuntimeError("Missing initial bridge redirect")
    check(code == 307 and location.startswith("/?esp_gate_bridge="),
          "actual product Rewrite produced origin-relative auth handoff")
    token = location.split("=", 1)[1]
    tampered = ("A" if token[0] != "A" else "B") + token[1:]
    for path, method, credentials in [
        ("/?esp_gate_bridge=" + tampered, "GET", sources),
        ("/?esp_gate_bridge=made-up", "GET", sources),
        (location, "HEAD", sources),
        (location + "&extra=1", "GET", sources),
        (location, "GET", ()),
    ]:
        test_jar = client_jar(credentials)
        nofollow = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(test_jar), StopRedirect())
        try:
            with nofollow.open(urllib.request.Request(BASE + path, method=method),
                               timeout=15) as response:
                status, body = response.status, response.read()
        except urllib.error.HTTPError as exc:
            status, body = exc.code, exc.read()
            exc.close()
        except Exception as exc:
            raise RuntimeError("Failed to exercise denied handoff: " +
                               type(exc).__name__) from None
        check(status == 403 and body == b"" and
              all(c.name != GATE_COOKIE for c in test_jar),
              "invalid or unauthenticated handoff refused without ticket/bytes")


before = command("status", 200)
check(before["route"] == "legacy" and before["serving"] is False and
      before["active"] is False,
      "before preparation must be fully disabled")
print("FG_DEPLOY_STATUS_BEFORE " + json.dumps(before), flush=True)
prepared = command("prepare", 200)
check(prepared["success"] is True and prepared["prepared"] is True,
      "explicit preparation must opt-in the index and verify catalog")
print("FG_DEPLOY_PREPARED " + json.dumps(prepared), flush=True)

failed = command("enable-fail-probe", 409)
check(failed["error"] == "esp_gate_http_probe_failed" and
      failed["route"] == "legacy" and failed["active"] is False and
      failed["serving"] is False,
      "failed live HTTP route probe must roll back safely")
check(request("/wp-content/uploads/fg-life-public.jpg")[0] == 200,
      "legacy HTTP route must remain operational after probe failure")

# Simulate a crashed PHP transition AFTER the non-serving gate Rewrite was
# installed and a stale durable lock remained. Administrator-led recovery
# must first stop SQLite serving, restore legacy Rewrite, then clear the lock.
stale = command("inject-stale-lock", 200)
check(stale["success"] and stale["route"] == "fast" and
      not stale["active"] and not stale["serving"],
      "disposable interrupted transition has armed route but stopped reader")
check(request("/wp-content/uploads/fg-life-public.jpg")[0] == 503,
      "interrupted transition cannot expose static media")
recovered = command("recover", 200)
check(recovered["success"] and recovered["route"] == "legacy" and
      not recovered["active"] and not recovered["serving"],
      "explicit stale-lock recovery restores safe WordPress route")
check(request("/wp-content/uploads/fg-life-public.jpg")[0] == 200,
      "legacy HTTP recovered after interrupted transition")

# A private HTTP 200 must block activation and restore legacy safely.
# A host using 404 to conceal existing private files is acceptable ONLY
# after the product's signed direct gate + disposable private-file denial.
exposed = command("enable-private-exposed", 409)
check(exposed["error"] == "esp_gate_http_probe_failed" and
      exposed["route"] == "legacy" and exposed["active"] is False and
      exposed["serving"] is False,
      "publicly exposed private path must fail activation and roll back")
check(request("/wp-content/uploads/fg-life-public.jpg")[0] == 200,
      "legacy route restored after unsafe private response")
enabled = command("enable-private-404", 200)
check(enabled["success"] is True and enabled["route"] == "fast" and
      enabled["active"] is True and enabled["serving"] is True,
      "verified activation must synchronize route, hooks and serving")
check(request("/wp-content/uploads/fg-life-public.jpg")[0] == 200,
      "installed Fast Gate serves catalog public media")
check(request("/wp-content/uploads/fg-life-protected.jpg")[0] == 403,
      "installed Fast Gate denies anonymous protected media")
# The same previously missing URL must not become a static bypass when
# an unregistered file appears on disk after the negative existence check.
unknown = "/wp-content/uploads/esp-unknown-probe.jpg"
check(request(unknown)[0] == 404,
      "physically absent media terminates with server 404")
check(request(unknown + "?test=missing")[0] == 404,
      "missing media query string does not change the 404 policy")
head_status, head_body, _ = request(unknown, method="HEAD")
check(head_status == 404 and head_body == b"",
      "missing media HEAD returns 404 without a body")
created = command("materialize-unknown", 200)
check(created["success"] and created["serving"] and created["active"],
      "disposable real file created without a ledger record")
unknown_status, unknown_body, unknown_headers = request(unknown)
check(unknown_status == 403 and unknown_body == b"" and
      "no-store" in {k.lower(): v for k, v in unknown_headers.items()}.get(
          "cache-control", ""),
      "newly materialized unregistered media reaches Gate and is denied")
check(request(unknown + "?test=exists")[0] == 403,
      "query string does not bypass existing unregistered media denial")
check(request(unknown, method="HEAD")[0] == 403,
      "existing unregistered media HEAD is denied")
check(request("/wp-content/uploads/esp-missing-probe.jpg")[0] == 404,
      "another absent media URL still returns 404")

jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
with opener.open(BASE + "/?esp_fg_ci_auth=session", timeout=12) as res:
    check(res.status == 200, "WordPress login with product active option")
check(any(x.name == "esp_gate_fg_path" for x in jar),
      "verified activation issues signed ticket from live WordPress login")
with opener.open(BASE + "/wp-content/uploads/fg-life-protected.jpg", timeout=12) as res:
    protected_payload = res.read()
    check(res.status == 200 and len(protected_payload) > 100,
          "live signed ticket authorizes protected image")
check(request(unknown, jar=jar)[0] == 403,
      "a valid ESP ticket does not authorize unregistered media")

session_sources = source_cookies(jar, "session")
for workers in (1, 20, 100):
    bridge_burst(session_sources, protected_payload, workers)
bridge_round_trip(session_sources, protected_payload, reject=True)
check(True, "client rejecting only Gate cookie receives two bounded bridges")
rejected_handoffs(session_sources)

remember_jar = http.cookiejar.CookieJar()
check(request("/?esp_fg_ci_auth=remember", jar=remember_jar)[0] == 200,
      "live WordPress Remember Me login")
remember_sources = source_cookies(remember_jar, "remember")
bridge_round_trip(remember_sources, protected_payload)
check(True, "Remember-only client bridges then serves directly")

disabled = command("disable", 200)
check(disabled["success"] is True and disabled["route"] == "legacy" and
      disabled["active"] is False and disabled["serving"] is False,
      "deactivation restores legacy HTTP route before disabling hooks")
check(request("/wp-content/uploads/fg-life-public.jpg")[0] == 200,
      "legacy public media works after deactivation")
with opener.open(BASE + "/wp-content/uploads/fg-life-protected.jpg", timeout=12) as res:
    check(res.status == 200 and len(res.read()) > 100,
          "existing WordPress login cookie survives Fast Gate disable")
# Simulate copied cookies while the product is disabled, then a *legacy*
# logout. This logout must delete MySQL but does not write SQLite revocations;
# the subsequent activation must nonetheless reject the old signed cookie.
retained_cookies = "; ".join(f"{x.name}={x.value}" for x in jar)
check("esp_gate_fg_path=" in retained_cookies and
      "esp_auth_fg_path=" in retained_cookies,
      "saved cookies include both login and signed proof")
legacy_logout = command("legacy-logout", 200, jar)
check(legacy_logout["success"] and not legacy_logout["active"] and
      legacy_logout["route"] == "legacy",
      "ordinary WordPress logout succeeds while Fast Gate is disabled")
check(request("/wp-content/uploads/fg-life-protected.jpg",
              cookie=retained_cookies)[0] == 403,
      "copied login cookie is invalid against legacy MySQL after logout")

reenabled = command("enable", 200)
check(reenabled["success"] and reenabled["serving"] and reenabled["active"],
      "verified reactivation succeeds after a legacy-only logout")
check(request(unknown)[0] == 403 and
      request("/wp-content/uploads/esp-missing-probe.jpg")[0] == 404,
      "reactivation preserves absent 404 and unregistered real-file 403")
check(request("/wp-content/uploads/fg-life-protected.jpg",
              cookie=retained_cookies)[0] == 403,
      "previous deployment's signed ticket is invalid after generation rotation")
# The Remember login was not logged out while the Gate was disabled.
# Even the old signed ticket must now require the WP bridge and recover
# using the separately verified source credential, not the old proof.
old_remember_client = tuple(copy.copy(c) for c in remember_jar)
check(any(c.name == GATE_COOKIE for c in old_remember_client),
      "Remember client retained ticket from previous generation")
bridge_round_trip(old_remember_client, protected_payload)
check(True, "live Remember credential recovers after ticket generation changes")
check(request("/?esp_fg_ci_auth=logout", jar=remember_jar)[0] == 200,
      "Remember credential revoked after reactivation")
check(request("/wp-content/uploads/fg-life-protected.jpg",
              jar=client_jar(remember_sources))[0] == 403,
      "revoked Remember-only source cannot recover a new Gate ticket")

fresh = http.cookiejar.CookieJar()
fresh_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(fresh))
with fresh_opener.open(BASE + "/?esp_fg_ci_auth=session", timeout=12) as res:
    check(res.status == 200, "fresh WordPress login succeeds after reactivation")
with fresh_opener.open(BASE + "/wp-content/uploads/fg-life-protected.jpg",
                       timeout=12) as res:
    check(res.status == 200 and len(res.read()) > 100,
          "new generation ticket authorizes the protected image")
unknown_updater = command("upgrade-no-server", 409)
check(unknown_updater["error"] == "esp_gate_upgrade_needs_http_disable" and
      unknown_updater["route"] == "fast" and unknown_updater["serving"],
      "unknown-server updater cannot replace PHP while Fast Gate is live")
upgrade = command("upgrade-preflight", 200)
check(upgrade["success"] and upgrade["route"] == "legacy" and
      not upgrade["active"] and not upgrade["serving"],
      "verified updater pre-install restores WordPress route before code swap")
with fresh_opener.open(BASE + "/wp-content/uploads/fg-life-protected.jpg",
                       timeout=12) as res:
    check(res.status == 200 and len(res.read()) > 100,
          "WordPress credential remains valid after upgrade route fallback")
last = command("disable", 200)
check(last["success"] and last["route"] == "legacy" and
      not last["serving"] and not last["active"],
      "final deactivate returns to legacy routing")
check(request("/wp-content/esp-fast-gate-v2/gate-ledger.sqlite")[0] == 403,
      "direct SQLite HTTP request remains denied")

print("FG_VERIFIED_DEPLOYMENT_HTTP " + json.dumps(
    {"checks_passed": passed, "activation": "passed",
     "failed_probe_rollback": "passed", "disable_to_legacy": "passed",
     "generation_rotation_after_legacy_logout": "passed",
     "stale_lock_recovery": "passed",
     "upgrade_preinstall_fallback": "passed",
     "bridge_bursts": BRIDGE_REPORT}))
