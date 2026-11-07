#!/usr/bin/env python3
"""Local disposable Apache HTTP benchmark for real Fast Gate vs legacy media."""
import argparse
import hashlib
import json
import statistics
import time
import urllib.error
import urllib.request
from pathlib import Path

p = argparse.ArgumentParser()
p.add_argument("--mode", required=True, choices=("legacy", "fast"))
p.add_argument("--url", default="http://127.0.0.1:8096")
p.add_argument("--cookie-file", required=True)
p.add_argument("--output", required=True)
p.add_argument("--requests", type=int, default=60)
args = p.parse_args()
if args.requests < 12 or args.requests > 300:
    raise SystemExit("Unexpected benchmark sample count")
cookie = Path(args.cookie_file).read_text().strip()
if not cookie.startswith("esp_auth_fg_path=") or "esp_gate_fg_path=" not in cookie:
    raise RuntimeError("Missing test-only cookie credentials")
base = args.url.rstrip("/") + "/wp-content/uploads/"
files = ("fg-life-protected.jpg", "fg-life-public.jpg")


def fetch(name, authenticated, method="GET", headers=None):
    h = dict(headers or {})
    if authenticated:
        h["Cookie"] = cookie
    req = urllib.request.Request(base + name, headers=h, method=method)
    before = time.perf_counter_ns()
    try:
        with urllib.request.urlopen(req, timeout=12) as response:
            body = response.read()
            code = response.status
            response_headers = dict(response.headers.items())
    except urllib.error.HTTPError as error:
        body = error.read()
        code = error.code
        response_headers = dict(error.headers.items())
    elapsed_ms = (time.perf_counter_ns() - before) / 1_000_000
    return code, body, response_headers, elapsed_ms


results = {"mode": args.mode, "sample_count": args.requests, "samples": {}}
for name in files:
    auth = name == "fg-life-protected.jpg"
    warmup = [fetch(name, auth) for _ in range(5)]
    if any(code != 200 for code, _, _, _ in warmup):
        raise RuntimeError(f"{args.mode} warmup {name} HTTP codes: {[x[0] for x in warmup]}")
    entries = [fetch(name, auth) for _ in range(args.requests)]
    if any(code != 200 for code, _, _, _ in entries):
        raise RuntimeError(f"{args.mode} {name} unexpected status")
    digests = {hashlib.sha256(body).hexdigest() for _, body, _, _ in entries}
    if len(digests) != 1:
        raise RuntimeError(f"{args.mode} {name} inconsistent bytes")
    ms = [x[-1] for x in entries]
    ms_sorted = sorted(ms)
    results["samples"][name] = {
        "bytes": len(entries[0][1]),
        "sha256": next(iter(digests)),
        "median_ms": round(statistics.median(ms), 4),
        "p95_ms": round(ms_sorted[int((len(ms_sorted) - 1) * .95)], 4),
        "min_ms": round(min(ms), 4),
        "max_ms": round(max(ms), 4),
        "raw_ms": [round(x, 4) for x in ms],
    }

public_code, _, _, _ = fetch("fg-life-public.jpg", False)
private_code, _, _, _ = fetch("fg-life-protected.jpg", False)
if public_code != 200 or private_code not in (302, 403):
    raise RuntimeError(f"{args.mode}: anonymous HTTP policy public={public_code} private={private_code}")
results["anonymous_private_status"] = private_code
if args.mode == "fast":
    if private_code != 403:
        raise RuntimeError("Fast Gate anonymous protected request must be 403")
    for name, method, headers, want in [
        ("fg-life-protected.jpg", "HEAD", {}, 200),
        ("fg-life-protected.jpg", "GET", {"Range": "bytes=0-31"}, 206),
        ("fg-life-does-not-exist.jpg", "GET", {}, (403, 404)),
    ]:
        status, body, _, _ = fetch(name, name == "fg-life-protected.jpg", method, headers)
        acceptable = want if isinstance(want, tuple) else (want,)
        if status not in acceptable:
            raise RuntimeError(f"Fast Gate {method} {name}: {status}, expected {want}")
        if method == "HEAD" and body:
            raise RuntimeError("HEAD returned a response body")
        if "Range" in headers and len(body) != 32:
            raise RuntimeError("Range yielded incorrect length")
    results["fast_gate_http_checks"] = ["protected anonymous 403", "GET/HEAD", "Range 206", "unknown denied 403/404"]

Path(args.output).write_text(json.dumps(results, indent=2, ensure_ascii=False) + "\n")
print("FG_HTTP_BENCHMARK " + json.dumps({
    "mode": args.mode,
    "sample_count": args.requests,
    "samples": {k: {field: value for field, value in v.items() if field != "raw_ms"}
                for k, v in results["samples"].items()},
    "anonymous_private_status": results["anonymous_private_status"],
}, ensure_ascii=False))
