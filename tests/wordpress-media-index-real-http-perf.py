#!/usr/bin/env python3
"""Real Apache/WordPress protected image HTTP microbenchmark and leak probes.

Latency is descriptive, not a CI SLO: shared GitHub runners are noisy.
Security assertions (status, bytes, headers) ARE hard failures. Strict index
mode must be explicitly enabled by the disposable integration test first.
"""
import concurrent.futures
import http.cookiejar
import json
import math
import statistics
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://127.0.0.1:8094"
PUBLIC = "/wp-content/uploads/esp31-public.jpg"
PROTECTED = "/wp-content/uploads/esp31-protected.jpg"
THUMB = "/wp-content/uploads/esp31-protected-150x150.jpg"
UNKNOWN = "/wp-content/uploads/esp63-unregistered.jpg"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def session(jar=None):
    return urllib.request.build_opener(
        urllib.request.ProxyHandler({}),
        NoRedirect(),
        urllib.request.HTTPCookieProcessor(jar or http.cookiejar.CookieJar()),
    )


def req(opener, path, method=None, fields=None, headers=None):
    data = None if fields is None else urllib.parse.urlencode(fields).encode()
    # None lets urllib choose POST for form data; explicit GET/HEAD only for media probes.
    request = urllib.request.Request(BASE + path, data=data, method=method, headers=headers or {})
    before = time.perf_counter()
    try:
        response = opener.open(request, timeout=15)
    except urllib.error.HTTPError as exc:
        response = exc
    body = response.read()
    return response.status, body, response.headers, (time.perf_counter() - before) * 1000


def verify(ok, label):
    if not ok:
        raise AssertionError(label)


anon = session()
authorized = session()
_, login, _, _ = req(authorized, "/esp44-login/")
import re
nonce = re.search(rb'name="esp_nonce" value="([^"]+)"', login)
verify(nonce is not None, "protected login nonce missing")
code, _, _, _ = req(authorized, "/esp44-login/", fields={
    "esp_password": "disposable-esp-password",
    "esp_nonce": nonce.group(1).decode(),
    "esp_path_id": "path_http",
    "redirect_to": "/esp44-secret/",
})
verify(code == 302, "protected-area session auth did not log in")
print("PASS: protected media session authenticated via real WordPress form")

# Assert safety, including exact content class and response cache policy.
def check(op, path, expected, marker=None, protected=False, method="GET", headers=None):
    code, data, response_headers, duration = req(op, path, method=method, headers=headers)
    verify(code == expected, "%s %s status=%s, expected=%s" % (method, path, code, expected))
    if expected in (302, 403, 503):
        verify(not marker or marker not in data, "anonymous/invalid request leaked protected marker")
        verify(b"\xff\xd8" not in data, "anonymous/invalid request leaked JPEG header")
    if expected == 200 and method == "GET":
        verify(data.startswith(b"\xff\xd8") and data.endswith(b"\xff\xd9"),
               "JPEG direct response not byte-valid")
        verify(marker is None or marker in data, "JPEG marker mismatch")
    if method == "HEAD":
        verify(data == b"", "HEAD returned a response body")
    cache = response_headers.get("Cache-Control", "").lower()
    if protected:
        verify("no-store" in cache and "public" not in cache,
               "protected/denied media must never be public-cacheable")
    return duration


check(anon, PUBLIC, 200, b"ESP31_PUBLIC_JPEG")
check(anon, PROTECTED, 302, b"ESP31_PROTECTED_JPEG", protected=True)
check(anon, THUMB, 302, b"ESP42_PROTECTED_THUMB", protected=True)
check(anon, UNKNOWN, 403, protected=True)
check(authorized, PROTECTED, 200, b"ESP31_PROTECTED_JPEG", protected=True)
check(authorized, THUMB, 200, b"ESP42_PROTECTED_THUMB", protected=True)
check(authorized, PROTECTED, 200, protected=True, method="HEAD")
code, data, hdrs, _ = req(authorized, PROTECTED, headers={"Range": "bytes=0-3"})
verify(code == 206 and data == b"\xff\xd8\xff\xfe" and
       "no-store" in hdrs.get("Cache-Control", "").lower(),
       "authenticated JPEG Range must be bounded and private")
code, data, _, _ = req(anon, PROTECTED, headers={"Range": "bytes=0-3"})
verify(code in (302, 403) and not data.startswith(b"\xff\xd8"),
       "unauthorized Range request leaked image bytes")
print("PASS: strict-index anonymous/authorized original+thumbnail, HEAD, Range, cache and unknown-file boundary")

# Warmup without including connection/process startup in measured p50/p95.
cases = [
    ("public_jpeg", anon, PUBLIC, 200, b"ESP31_PUBLIC_JPEG", False),
    ("protected_jpeg", authorized, PROTECTED, 200, b"ESP31_PROTECTED_JPEG", True),
    ("protected_thumb", authorized, THUMB, 200, b"ESP42_PROTECTED_THUMB", True),
    ("anonymous_denial", anon, PROTECTED, 302, b"ESP31_PROTECTED_JPEG", True),
]
report = {"measurement": "real Apache/PHP/WordPress HTTP; CI runner, warmup+sequential+parallel"}
for name, op, path, code, marker, privacy in cases:
    for _ in range(3):
        check(op, path, code, marker, privacy)
    samples = [check(op, path, code, marker, privacy) for _ in range(25)]
    ordered = sorted(samples)
    report[name] = {
        "count": len(samples), "median_ms": round(statistics.median(samples), 3),
        "p95_ms": round(ordered[math.ceil(len(ordered) * .95) - 1], 3),
        "max_ms": round(max(samples), 3),
    }

# Genuine overlapping HTTP requests; fail if a concurrent response leaks,
# changes status, or improperly caches a protected image.
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
    started = time.perf_counter()
    futures = [pool.submit(check, authorized, PROTECTED, 200,
                           b"ESP31_PROTECTED_JPEG", True) for _ in range(24)]
    durations = [future.result(timeout=30) for future in futures]
    wall = time.perf_counter() - started
report["protected_parallel"] = {
    "requests": len(durations), "concurrency": 4,
    "wall_seconds": round(wall, 3),
    "throughput_rps": round(len(durations) / max(wall, .0001), 2),
    "p95_ms": round(sorted(durations)[math.ceil(len(durations) * .95) - 1], 3),
}
print("PASS: 4-way parallel protected image HTTP remains authorized and non-cacheable")
print("ESP63_REAL_HTTP_PERF " + json.dumps(report, sort_keys=True))
