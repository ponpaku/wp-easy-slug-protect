#!/usr/bin/env python3
"""Fresh real subdirectory HTTP layout; generated PDF and one-frame MP4."""
import base64
import json
from pathlib import Path
import runpy
import sys
from urllib.parse import parse_qs, urlsplit
from playwright.sync_api import sync_playwright

ORIGIN = "http://127.0.0.1:8096"
BASE = ORIGIN + "/blog"
UPLOADS = BASE + "/wp-content/uploads/"
GATE = "esp_gate_fg_path"
checks = 0


def check(ok, message):
    global checks
    if not ok:
        raise RuntimeError("Subdirectory E2E: " + message)
    checks += 1


def build(root):
    # Self-contained one-page empty PDF with valid xref offsets.
    pdf = bytearray(b"%PDF-1.4\n")
    objects = [b"<< /Type /Catalog /Pages 2 0 R >>",
               b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
               b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 20 20] >>"]
    offsets = []
    for i, obj in enumerate(objects, 1):
        offsets.append(len(pdf))
        pdf += str(i).encode() + b" 0 obj\n" + obj + b"\nendobj\n"
    xref = len(pdf)
    pdf += b"xref\n0 4\n0000000000 65535 f \n"
    for offset in offsets:
        pdf += (f"{offset:010d} 00000 n \n").encode()
    pdf += f"trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode()
    (root / "fg-ci-range.pdf").write_bytes(pdf)
    # Original synthetic black frame, encoded locally using FFmpeg/libx264.
    # It contains no third-party footage and needs no external download.
    mp4 = base64.b64decode(
        "AAAAIGZ0eXBpc29tAAACAGlzb21pc28yYXZjMW1wNDEAAAMMbW9vdgAAAGxtdmhkAAAAAAAAAAAAAAAAAAAD6AAAA+gAAQAAAQAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgAAAjd0cmFrAAAAXHRraGQAAAADAAAAAAAAAAAAAAABAAAAAAAAA+gAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAAABAAAAAQAAAAAAAkZWR0cwAAABxlbHN0AAAAAAAAAAEAAAPoAAAAAAABAAAAAAGvbWRpYQAAACBtZGhkAAAAAAAAAAAAAAAAAABAAAAAQABVxAAAAAAALWhkbHIAAAAAAAAAAHZpZGUAAAAAAAAAAAAAAABWaWRlb0hhbmRsZXIAAAABWm1pbmYAAAAUdm1oZAAAAAEAAAAAAAAAAAAAACRkaW5mAAAAHGRyZWYAAAAAAAAAAQAAAAx1cmwgAAAAAQAAARpzdGJsAAAAtnN0c2QAAAAAAAAAAQAAAKZhdmMxAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAABAAEABIAAAASAAAAAAAAAABFUxhdmM2MS4xOS4xMDEgbGlieDI2NAAAAAAAAAAAAAAAGP//AAAALGF2Y0MBQsAK/+EAFWdCwAraewEQAAADABAAAAMAIPEiagEABGjOD8gAAAAQcGFzcAAAAAEAAAABAAAAFGJ0cnQAAAAAAAATKAAAAAAAAAAYc3R0cwAAAAAAAAABAAAAAQAAQAAAAAAcc3RzYwAAAAAAAAABAAAAAQAAAAEAAAABAAAAFHN0c3oAAAAAAAACZQAAAAEAAAAUc3RjbwAAAAAAAAABAAADPAAAAGF1ZHRhAAAAWW1ldGEAAAAAAAAAIWhkbHIAAAAAAAAAAG1kaXJhcHBsAAAAAAAAAAAAAAAALGlsc3QAAAAkqXRvbwAAABxkYXRhAAAAAQAAAABMYXZmNjEuNy4xMDMAAAAIZnJlZQAAAm1tZGF0AAACUwYF//9P3EXpvebZSLeWLNgg2SPu73gyNjQgLSBjb3JlIDE2NCByMzEwOCAzMWUxOWY5IC0gSC4yNjQvTVBFRy00IEFWQyBjb2RlYyAtIENvcHlsZWZ0IDIwMDMtMjAyMyAtIGh0dHA6Ly93d3cudmlkZW9sYW4ub3JnL3gyNjQuaHRtbCAtIG9wdGlvbnM6IGNhYmFjPTAgcmVmPTEgZGVibG9jaz0wOjA6MCBhbmFseXNlPTA6MCBtZT1kaWEgc3VibWU9MCBwc3k9MSBwc3lfcmQ9MS4wMDowLjAwIG1peGVkX3JlZj0wIG1lX3JhbmdlPTE2IGNocm9tYV9tZT0xIHRyZWxsaXM9MCA4eDhkY3Q9MCBjcW09MCBkZWFkem9uZT0yMSwxMSBmYXN0X3Bza2lwPTEgY2hyb21hX3FwX29mZnNldD0wIHRocmVhZHM9MSBsb29rYWhlYWRfdGhyZWFkcz0xIHNsaWNlZF90aHJlYWRzPTAgbnI9MCBkZWNpbWF0ZT0xIGludGVybGFjZWQ9MCBibHVyYXlfY29tcGF0PTAgY29uc3RyYWluZWRfaW50cmE9MCBiZnJhbWVzPTAgd2VpZ2h0cD0wIGtleWludD0yNTAga2V5aW50X21pbj0xIHNjZW5lY3V0PTAgaW50cmFfcmVmcmVzaD0wIHJjPWNyZiBtYnRyZWU9MCBjcmY9MjMuMCBxY29tcD0wLjYwIHFwbWluPTAgcXBtYXg9NjkgcXBzdGVwPTQgaXBfcmF0aW89MS40MCBhcT0wAIAAAAAKZYiEOiYoAAkC4A==",
        validate=True)
    (root / "fg-ci-range.mp4").write_bytes(mp4)


def action(client, name):
    res = client.get(BASE + "/?esp_fg_ci_auth=" + name, max_redirects=0)
    try:
        data = res.json()
    except Exception:
        raise RuntimeError("Subdirectory action non-JSON: " + name + " " +
                           str(res.status)) from None
    check(res.status == 200, name + " " + str(data.get("error")))
    return data


def no_store(res):
    return "no-store" in res.headers.get("cache-control", "")


def range_contract(client, url, data, mime, mode):
    res = client.get(url, max_redirects=0)
    check(res.status == 200 and res.body() == data and no_store(res) and
          res.headers.get("content-type", "").startswith(mime), mode + " full")
    res = client.get(url, headers={"Range": "bytes=7-31"}, max_redirects=0)
    check(res.status == 206 and res.body() == data[7:32] and no_store(res) and
          res.headers.get("content-range") == f"bytes 7-31/{len(data)}",
          mode + " partial")
    res = client.head(url, headers={"Range": "bytes=7-31"}, max_redirects=0)
    check(res.status == 200 and res.body() == b"" and no_store(res) and
          int(res.headers.get("content-length", "-1")) == len(data), mode + " HEAD")
    res = client.get(url, headers={"Range": f"bytes={len(data)}-"},
                     max_redirects=0)
    check(res.status == 416 and no_store(res) and res.body() == b"",
          mode + " invalid range")


def run(root):
    browser_login = runpy.run_path(
        str(Path(__file__).with_name("fast-gate-admin-ui-e2e.py")))["media_login_browser"]
    cases = [(UPLOADS + name, (root/name).read_bytes(), mime)
             for name, mime in (("fg-ci-range.pdf", "application/pdf"),
                                ("fg-ci-range.mp4", "video/mp4"))]
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        control = browser.new_context()
        guest = browser.new_context()
        try:
            initial = action(control.request, "deploy-status")
            check(initial["route"] == "legacy" and not initial["active"] and
                  not initial["serving"], "relocated site stopped")
            check(action(control.request, "deploy-prepare")["prepared"], "new real ledger")
            active = action(control.request, "deploy-enable")
            check(active["route"] == "fast" and active["serving"], "verified nested route")
            check(control.request.get(UPLOADS + "fg-life-public.jpg",
                                      max_redirects=0).status == 200, "nested public")
            check(control.request.get(UPLOADS + "no-such-subdir.jpg",
                                      max_redirects=0).status == 404, "nested missing")
            browser_login(browser, base=BASE)
            check(True, "real browser login and logout in physical subdirectory")
            for url, payload, mime in cases:
                check(control.request.get(url, max_redirects=0).status == 403, "anonymous " + mime)
                check(action(guest.request, "session")["authenticated"], "valid nested session")
                guest.clear_cookies(name=GATE)
                hdr = {"Range": "bytes=7-31"}
                res = guest.request.get(url, headers=hdr, max_redirects=0)
                location = res.headers.get("location", "")
                target = urlsplit(location)
                check(res.status == 307 and target.path == "/blog/" and
                      not target.netloc and set(parse_qs(target.query)) == {"esp_gate_bridge"},
                      "origin-local nested handoff for " + mime)
                # Explicitly replay the same Range over the real handoff.
                res = guest.request.get(ORIGIN+location, headers=hdr, max_redirects=0)
                check(res.status == 206 and res.body() == payload[7:32] and
                      res.headers.get("content-range") == f"bytes 7-31/{len(payload)}" and
                      no_store(res), "bridge partial " + mime)
                check(any(c["name"] == GATE and c["path"] == "/blog/"
                          for c in guest.cookies([url])), "nested scoped proof")
                range_contract(guest.request, url, payload, mime, "fast " + mime)
            stopped = action(control.request, "deploy-disable")
            check(not stopped["serving"] and stopped["route"] == "legacy", "legacy fallback")
            for url, payload, mime in cases:
                range_contract(guest.request, url, payload, mime, "legacy " + mime)
                check(control.request.get(url, max_redirects=0).status == 403,
                      "anonymous legacy " + mime)
            action(guest.request, "deploy-legacy-logout")
            for url, _, mime in cases:
                check(guest.request.get(url, max_redirects=0).status == 403,
                      "new legacy HTTP after logout " + mime)
            print("FG_REAL_SUBDIRECTORY_RANGE " + json.dumps({
                "checks": checks, "layout": "/blog", "browser_login": "passed",
                "pdf_mp4": "passed", "fast_bridge_206": "passed",
                "standalone_and_legacy": "passed", "logout": "passed",
                "cache_policy": "no-store on 200/206/HEAD/416"}), flush=True)
        except Exception as exc:
            # Do not print Playwright's potentially token-bearing URLs/errors.
            if isinstance(exc, (RuntimeError, AssertionError)):
                raise
            raise RuntimeError("Subdirectory request error: " + type(exc).__name__) from None
        finally:
            control.close()
            guest.close()
            browser.close()


if __name__ == "__main__":
    if sys.argv[1] == "build":
        build(Path(sys.argv[2]))
    elif sys.argv[1] == "run":
        run(Path(sys.argv[2]))
    else:
        raise SystemExit("expected build or run")
