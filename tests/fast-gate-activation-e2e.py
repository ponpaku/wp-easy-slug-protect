#!/usr/bin/env python3
"""Disposable real Apache transition regression. No production config changes."""
import http.cookiejar
import json
import urllib.error
import urllib.request


BASE = "http://127.0.0.1:8096"
passed = 0


def request(path, jar=None):
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar or http.cookiejar.CookieJar()))
    try:
        with opener.open(BASE + path, timeout=18) as response:
            return response.status, response.read(), dict(response.headers.items())
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read(), dict(exc.headers.items())


def check(ok, text):
    global passed
    if not ok:
        raise RuntimeError("Fast Gate activation E2E: " + text)
    passed += 1


def command(action, status):
    code, body, _ = request("/?esp_fg_ci_auth=deploy-" + action)
    check(code == status, f"{action} HTTP code {code}; expected {status}")
    try:
        data = json.loads(body)
    except Exception as e:
        raise RuntimeError(f"Deployment {action} non-JSON {code}: {body[:180]!r}") from e
    print("FG_DEPLOY_ACTION " + json.dumps(data), flush=True)
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
check(request("/wp-content/esp-fast-gate-v2/gate-ledger.sqlite")[0] == 403,
      "direct SQLite HTTP request remains denied")

print("FG_VERIFIED_DEPLOYMENT_HTTP " + json.dumps(
    {"checks_passed": passed, "activation": "passed",
     "failed_probe_rollback": "passed", "disable_to_legacy": "passed"}))
