#!/usr/bin/env python3
"""Actual HTTP WordPress password-login / Remember Me / logout vs Fast Gate.

Runs ONLY in the CI disposable Apache installation. Requests use real HTTP
Set-Cookie / Cookie headers, not manually generated signed tickets. It also
tests parallel protected/anonymous requests; it never changes production.
"""
import argparse
import concurrent.futures
import hashlib
import http.cookiejar
import json
from pathlib import Path
import statistics
import time
import urllib.error
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument("--url", default="http://127.0.0.1:8096")
parser.add_argument("--output", required=True)
args = parser.parse_args()
base = args.url.rstrip("/")
protected = base + "/wp-content/uploads/fg-life-protected.jpg"
headers_reject = []
results = {"mode": "real-wordpress-http-auth", "test_cases": []}


def check(expr, what):
    if not expr:
        raise RuntimeError("Fast Gate HTTP auth regression: " + what)
    results["test_cases"].append(what)


def cookie_header(jar):
    return "; ".join(f"{c.name}={c.value}" for c in jar)


def get_cookies(jar):
    return {c.name: c.value for c in jar}


def fetch(url, cookie=None, method="GET", headers=None, opener=None):
    request_headers = {"Cache-Control": "no-cache"}
    request_headers.update(headers or {})
    if cookie:
        request_headers["Cookie"] = cookie
    request = urllib.request.Request(
        url, headers=request_headers, method=method
    )
    opened = opener.open if opener is not None else urllib.request.urlopen
    start = time.perf_counter_ns()
    try:
        with opened(request, timeout=15) as response:
            code, body = response.status, response.read()
            response_headers = dict(response.headers.items())
    except urllib.error.HTTPError as e:
        code, body = e.code, e.read()
        response_headers = dict(e.headers.items())
    return code, body, response_headers, (time.perf_counter_ns() - start) / 1e6


class RedirectRecorder(urllib.request.HTTPRedirectHandler):
    def __init__(self):
        super().__init__()
        self.hops = []

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        self.hops.append((code, newurl, headers.get_all("Set-Cookie") or []))
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def action(jar, command, expect=True):
    recorder = RedirectRecorder()
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar), recorder
    )
    status, body, hdr, ms = fetch(
        base + "/?esp_fg_ci_auth=" + command, opener=opener
    )
    try:
        data = json.loads(body)
    except (ValueError, UnicodeDecodeError):
        raise RuntimeError(
            f"HTTP WordPress {command} returned {status}, non-JSON response"
        )
    if expect:
        check(status == 200, f"WordPress HTTP {command} successful")
    else:
        check(status == 409, f"WordPress HTTP {command} denied")
    if command.startswith("redirect-"):
        check(len(recorder.hops) == 1 and recorder.hops[0][0] == 302,
              f"Actual ESP_Cookie::do_redirect emitted HTTP 302 for {command}")
        check("esp_fg_ci_redirect_landed=1" in recorder.hops[0][1],
              "Redirect Location matches the WordPress landing URL")
        issued = recorder.hops[0][2]
        check(any("esp_gate_fg_path=" in item for item in issued),
              "Signed gate ticket appears on original redirect Set-Cookie")
        check(data.get("landed") is True and
              data.get("source_cookie_seen") is True and
              data.get("signed_cookie_seen") is True,
              "Redirect destination received both cookies from client")
    return data


def require_gate_credentials(jar, kind):
    cookies = get_cookies(jar)
    needed = {"esp_gate_fg_path"}
    if kind == "session":
        needed.add("esp_auth_fg_path")
    else:
        needed.update({
            "esp_remember_id_fg_path",
            "esp_remember_token_fg_path",
        })
    check(needed.issubset(cookies),
          f"WordPress HTTP {kind} issued real Set-Cookie credentials")
    return cookies


def gate(code_expected, header=None, method="GET", range_header=None):
    extra = {"Range": range_header} if range_header is not None else {}
    actual, body, response, ms = fetch(
        protected, header, method=method, headers=extra
    )
    check(actual == code_expected,
          f"Standalone {method} HTTP {code_expected} expected, got {actual}")
    if actual == 200:
        h = {k.lower(): v for k, v in response.items()}
        check("no-store" in h.get("cache-control", ""),
              "Standalone protected media returned no-store")
    return body


def signed_cookie_only(cookies):
    return "esp_gate_fg_path=" + cookies["esp_gate_fg_path"]


def mutate_token(cookies):
    token = cookies["esp_gate_fg_path"]
    last = "A" if token[-1] != "A" else "B"
    changed = dict(cookies)
    changed["esp_gate_fg_path"] = token[:-1] + last
    return "; ".join(f"{k}={v}" for k, v in changed.items())


def parallel_access(valid_cookie):
    good_status, original, _, _ = fetch(protected, valid_cookie)
    check(good_status == 200 and len(original) > 100,
          "Pre-concurrency real HTTP signed credential accepted")
    expected = hashlib.sha256(original).hexdigest()
    work = [(True, valid_cookie)] * 64 + [(False, None)] * 64

    def exercise(job):
        authorized, cookie = job
        status, data, hdrs, elapsed = fetch(protected, cookie)
        if authorized and (status != 200 or
                           hashlib.sha256(data).hexdigest() != expected):
            raise RuntimeError("Parallel authorized request failed/changed body")
        if not authorized and status != 403:
            raise RuntimeError("Parallel anonymous media was not denied")
        return authorized, elapsed

    start = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
        readings = list(executor.map(exercise, work))
    wall = time.perf_counter() - start
    def summary(who):
        values = sorted(x[1] for x in readings if x[0] == who)
        return {
            "count": len(values),
            "median_ms": round(statistics.median(values), 4),
            "p95_ms": round(values[int((len(values)-1)*.95)], 4),
            "max_ms": round(max(values), 4),
        }

    results["parallel"] = {
        "workers": 8,
        "elapsed_seconds": round(wall, 4),
        "requests_per_second": round(len(work)/wall, 2),
        "authorized": summary(True),
        "anonymous": summary(False),
    }
    check(True, "8 concurrent HTTP workers; 64 authorized GET 200 / 64 anonymous GET 403")


# Every case below exercises the real Apache VirtualHost Rewrite, WordPress
# plugin options and MySQL credential tables. Only the CI MU plugin provides
# a test-only HTTP endpoint for driving existing authentication methods.
gate(403)  # known protected file, no cookies

# The actual shipped redirect function emits queued cookies before
# returning a 302; the redirected WordPress GET receives both credentials.
for kind in ("session", "remember"):
    redirected_jar = http.cookiejar.CookieJar()
    action(redirected_jar, "redirect-" + kind)
    require_gate_credentials(redirected_jar, kind)
    redirected_header = cookie_header(redirected_jar)
    gate(200, redirected_header)
    action(redirected_jar, "logout")
    gate(403, redirected_header)

session_jar = http.cookiejar.CookieJar()
action(session_jar, "session")
session = require_gate_credentials(session_jar, "session")
session_header = cookie_header(session_jar)
payload = gate(200, session_header)
check(len(payload) > 100, "Real WordPress session serves protected bytes")

# A client authenticated before Fast Gate activation holds only the old
# ESP session cookie. The first image request follows Gate 307 -> WordPress,
# confirms the live MySQL credential, receives a fresh Gate cookie, and
# downloads the original media in the SAME WordPress response.
legacy_jar = http.cookiejar.CookieJar()
for old_cookie in session_jar:
    if old_cookie.name == "esp_auth_fg_path":
        legacy_jar.set_cookie(old_cookie)
recorder = RedirectRecorder()
legacy_opener = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(legacy_jar), recorder
)
bridge_status, bridge_body, bridge_headers, _ = fetch(
    protected, opener=legacy_opener
)
check(bridge_status == 200 and bridge_body == payload,
      "Old ESP session first image restored by WordPress direct delivery")
check(len(recorder.hops) == 1 and recorder.hops[0][0] == 307,
      "Only one Gate-to-WordPress handoff redirect was required")
check("esp_gate_fg_path" in get_cookies(legacy_jar),
      "WordPress handoff issued browser-accepted Fast Gate cookie")
gate(200, cookie_header(legacy_jar))
check(True, "Subsequent old-session image served by standalone Fast Gate")

# HEAD handoff also retains the method and must not include media bytes.
head_jar = http.cookiejar.CookieJar()
for c in session_jar:
    if c.name == "esp_auth_fg_path":
        head_jar.set_cookie(c)
head_hops = RedirectRecorder()
head_opener = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(head_jar), head_hops
)
head_status, head_body, head_headers, _ = fetch(
    protected, method="HEAD", opener=head_opener
)
print("FG_BRIDGE_HEAD_DIAGNOSTIC " + json.dumps({
    "status": head_status, "body_len": len(head_body),
    "redirect_codes": [h[0] for h in head_hops.hops],
    "content_type": head_headers.get("Content-Type"),
}))
check(head_status == 200 and head_body == b"" and
      len(head_hops.hops) == 1 and head_hops.hops[0][0] == 307,
      "Old-session HEAD handoff preserved HEAD and streamed no bytes")

# Range is relevant for PDFs and video. Verify this HTTP client's Range
# header survives the handoff and WordPress returns only requested bytes.
range_jar = http.cookiejar.CookieJar()
for c in session_jar:
    if c.name == "esp_auth_fg_path":
        range_jar.set_cookie(c)
range_hops = RedirectRecorder()
range_opener = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(range_jar), range_hops
)
range_status, range_body, range_headers, _ = fetch(
    protected, headers={"Range": "bytes=0-31"}, opener=range_opener
)
print("FG_BRIDGE_RANGE_DIAGNOSTIC " + json.dumps({
    "status": range_status, "body_len": len(range_body),
    "redirect_codes": [h[0] for h in range_hops.hops],
    "content_range": range_headers.get("Content-Range"),
}))
check(range_status == 206 and range_body == payload[:32] and
      len(range_hops.hops) == 1 and range_hops.hops[0][0] == 307,
      "Old-session GET Range handoff preserved partial-media delivery")

gate(403, signed_cookie_only(session))
# An invalid Gate ticket alone cannot authenticate. A still-valid source
# credential may be safely rechecked by WordPress and have the ticket repaired.
gate(200, mutate_token(session))
bad_session = dict(session)
# The disposable setup deliberately mints an all-"x" credential; do NOT
# use that value as the supposed forgery here.
bad_session["esp_auth_fg_path"] = "never-minted-token-" + "z" * 64
gate(403, "; ".join(f"{k}={v}" for k, v in bad_session.items()))
gate(405, session_header, method="POST")
check(gate(200, session_header, method="HEAD") == b"",
      "HEAD returns no protected media bytes")
check(len(gate(206, session_header, range_header="bytes=0-31")) == 32,
      "HTTP byte Range 0-31 returns 32 bytes")
gate(416, session_header, range_header="bytes=9999999-")
gate(416, session_header, range_header="bytes=0-1,3-4")

parallel_access(session_header)
action(session_jar, "logout")
gate(403, session_header)
check(not action(session_jar, "refresh", expect=False).get("authenticated", False),
      "Deleted session cannot be refreshed into a new gate ticket")

remember_jar = http.cookiejar.CookieJar()
action(remember_jar, "remember")
remember = require_gate_credentials(remember_jar, "remember")
remember_header = cookie_header(remember_jar)
gate(200, remember_header)
action(remember_jar, "refresh")
refreshed = require_gate_credentials(remember_jar, "remember")
gate(200, cookie_header(remember_jar))
action(remember_jar, "logout")
gate(403, remember_header)
gate(403, cookie_header(remember_jar))

# Both login kinds present: logout must revoke both even though the gate
# cookie currently points at only one authentication kind.
both_jar = http.cookiejar.CookieJar()
action(both_jar, "session")
first_session_ticket = cookie_header(both_jar)
gate(200, first_session_ticket)
action(both_jar, "remember")
both_cookies = require_gate_credentials(both_jar, "remember")
check("esp_auth_fg_path" in both_cookies,
      "Both session and Remember Me source cookies coexist")
remember_ticket = cookie_header(both_jar)
gate(200, remember_ticket)
action(both_jar, "logout")
gate(403, first_session_ticket)
gate(403, remember_ticket)

# Force real MySQL DELETE errors for each login kind separately. The
# canonical credential remains in MySQL but the pre-delete SQLite revocation
# MUST still reject the previously valid signed gate ticket.
for kind in ("session", "remember"):
    failing_jar = http.cookiejar.CookieJar()
    action(failing_jar, kind)
    pre_failure = cookie_header(failing_jar)
    gate(200, pre_failure)
    failure = action(failing_jar, "logout-fail-" + kind, expect=False)
    check(failure.get("injected_delete_failed") is True,
          f"Real MySQL {kind} DELETE failure injected")
    check(failure.get("target_row_retained") is True,
          f"Real MySQL {kind} credential survived failed deletion")
    gate(403, pre_failure)
    check(True, f"Revocation persists despite failed {kind} SQL DELETE")

# A short-lived handoff can be replayed while still in its 60-second
# validity window. If one of two presented sessions was revoked *after* the
# handoff was issued but before WordPress authorization, the other MySQL-valid
# session must NOT rescue the revoked one.
class StopAtRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


handoff_jar = http.cookiejar.CookieJar()
action(handoff_jar, "session")
action(handoff_jar, "remember")
handoff_sources = {k: v for k, v in get_cookies(handoff_jar).items()
                   if k != "esp_gate_fg_path"}
handoff_source_header = "; ".join(f"{k}={v}" for k, v in handoff_sources.items())
pending_status, _, pending_headers, _ = fetch(
    protected, handoff_source_header,
    opener=urllib.request.build_opener(StopAtRedirect())
)
check(pending_status == 307 and "Location" in pending_headers,
      "Gate produced signed handoff before mixed-session revocation")
from urllib.parse import urljoin
pending_url = urljoin(base, pending_headers["Location"])
remember_only = http.cookiejar.CookieJar()
for c in handoff_jar:
    if c.name in {"esp_remember_id_fg_path", "esp_remember_token_fg_path"}:
        remember_only.set_cookie(c)
failure = action(remember_only, "logout-fail-remember", expect=False)
check(failure.get("injected_delete_failed") is True and
      failure.get("target_row_retained") is True,
      "Revoked Remember identity remains in MySQL after deliberate failure")
replay_status, _, _, _ = fetch(pending_url, handoff_source_header)
check(replay_status == 403,
      "Replay of pre-revocation handoff cannot rescue revoked Remember")
gate(403, handoff_source_header)

# Change the canonical WordPress password generation with real option
# hooks and an active test-only gate. Old tickets must fail; a fresh
# WordPress login must issue a new version and succeed.
version_jar = http.cookiejar.CookieJar()
action(version_jar, "session")
old_version_cookie = cookie_header(version_jar)
gate(200, old_version_cookie)
rotated = action(version_jar, "rotate")
check(rotated.get("password_version") == 2,
      "WordPress settings incremented the protection password version")
gate(403, old_version_cookie)
new_jar = http.cookiejar.CookieJar()
action(new_jar, "session")
new_cookie = cookie_header(new_jar)
gate(200, new_cookie)
action(new_jar, "logout")
gate(403, new_cookie)

Path(args.output).write_text(
    json.dumps(results, indent=2, ensure_ascii=False) + "\n"
)
print("FG_REAL_HTTP_AUTH " + json.dumps({
    "tested": len(results["test_cases"]),
    "parallel": results.get("parallel"),
    "note": "WordPress HTTP auth + real MySQL, disposable CI only"
}))
