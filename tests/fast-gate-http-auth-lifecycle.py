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


def action(jar, command, expect=True):
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar)
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

session_jar = http.cookiejar.CookieJar()
action(session_jar, "session")
session = require_gate_credentials(session_jar, "session")
session_header = cookie_header(session_jar)
payload = gate(200, session_header)
check(len(payload) > 100, "Real WordPress session serves protected bytes")

gate(403, "esp_auth_fg_path=" + session["esp_auth_fg_path"])
gate(403, signed_cookie_only(session))
gate(403, mutate_token(session))
bad_session = dict(session)
bad_session["esp_auth_fg_path"] = "x" * 64
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
