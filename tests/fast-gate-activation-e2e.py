#!/usr/bin/env python3
"""Disposable real Apache transition regression. No production config changes."""
import http.cookiejar
import json
import urllib.error
import urllib.request


BASE = "http://127.0.0.1:8096"
passed = 0


def request(path, jar=None, cookie=None):
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(
            jar if jar is not None else http.cookiejar.CookieJar()))
    target = urllib.request.Request(BASE + path,
        headers={"Cookie": cookie} if cookie is not None else {})
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

enabled = command("enable", 200)
check(enabled["success"] is True and enabled["route"] == "fast" and
      enabled["active"] is True and enabled["serving"] is True,
      "verified activation must synchronize route, hooks and serving")
check(request("/wp-content/uploads/fg-life-public.jpg")[0] == 200,
      "installed Fast Gate serves catalog public media")
check(request("/wp-content/uploads/fg-life-protected.jpg")[0] == 403,
      "installed Fast Gate denies anonymous protected media")
check(request("/wp-content/uploads/esp-unknown-probe.jpg")[0] == 403,
      "installed Fast Gate denies media absent from the ledger")

jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
with opener.open(BASE + "/?esp_fg_ci_auth=session", timeout=12) as res:
    check(res.status == 200, "WordPress login with product active option")
check(any(x.name == "esp_gate_fg_path" for x in jar),
      "verified activation issues signed ticket from live WordPress login")
with opener.open(BASE + "/wp-content/uploads/fg-life-protected.jpg", timeout=12) as res:
    check(res.status == 200 and len(res.read()) > 100,
          "live signed ticket authorizes protected image")

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
check(request("/wp-content/uploads/fg-life-protected.jpg",
              cookie=retained_cookies)[0] == 403,
      "previous deployment's signed ticket is invalid after generation rotation")

fresh = http.cookiejar.CookieJar()
fresh_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(fresh))
with fresh_opener.open(BASE + "/?esp_fg_ci_auth=session", timeout=12) as res:
    check(res.status == 200, "fresh WordPress login succeeds after reactivation")
with fresh_opener.open(BASE + "/wp-content/uploads/fg-life-protected.jpg",
                       timeout=12) as res:
    check(res.status == 200 and len(res.read()) > 100,
          "new generation ticket authorizes the protected image")
last = command("disable", 200)
check(last["success"] and last["route"] == "legacy" and
      not last["serving"] and not last["active"],
      "final deactivate returns to legacy routing")
check(request("/wp-content/esp-fast-gate-v2/gate-ledger.sqlite")[0] == 403,
      "direct SQLite HTTP request remains denied")

print("FG_VERIFIED_DEPLOYMENT_HTTP " + json.dumps(
    {"checks_passed": passed, "activation": "passed",
     "failed_probe_rollback": "passed", "disable_to_legacy": "passed",
     "generation_rotation_after_legacy_logout": "passed"}))
