#!/usr/bin/env python3
"""Real HTTP Core bulk update in the disposable Fast Gate lifecycle site."""
import copy
import http.cookiejar
import json
import re
import sys
import time
import urllib.error
import urllib.request
import zipfile

BASE = "http://127.0.0.1:8096"
MEDIA = "/wp-content/uploads/fg-life-protected.jpg"
PUBLIC = "/wp-content/uploads/fg-life-public.jpg"
MISSING = "/wp-content/uploads/esp-missing-probe.jpg"
GATE = "esp_gate_fg_path"
checks = 0


def check(ok, message):
    global checks
    if not ok:
        raise RuntimeError("Core upgrade E2E: " + message)
    checks += 1


def build(source, target):
    with zipfile.ZipFile(source) as original, zipfile.ZipFile(target, "w") as out:
        for entry in original.infolist():
            data = original.read(entry)
            if entry.filename == "easy-slug-protect/easy-slug-protect.php":
                old = re.search(rb"^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)$", data, re.M).group(1)
                a, b, c = old.split(b".")
                new = b".".join((a, b, str(int(c)+1).encode()))
                for prefix in (b" * Version: ", b"define('ESP_VERSION', '"):
                    assert data.count(prefix + old) == 1
                    data = data.replace(prefix + old, prefix + new)
            out.writestr(entry, data)
    print("FG_SYNTHETIC_NEXT_ZIP built in disposable CI only", flush=True)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def jar_from(source, exclude=()):
    jar = http.cookiejar.CookieJar()
    for c in source:
        if c.name not in exclude:
            jar.set_cookie(copy.copy(c))
    return jar


def fetch(path, jar=None, timeout=45):
    opener = urllib.request.build_opener(NoRedirect(),
        urllib.request.HTTPCookieProcessor(jar if jar is not None else jar_from(())))
    try:
        try:
            response = opener.open(BASE + path, timeout=timeout)
        except urllib.error.HTTPError as e:
            response = e
        with response:
            return response.code, response.read(), response.headers
    except Exception as exc:
        raise RuntimeError("HTTP failed: " + type(exc).__name__) from None


def call(path, jar=None, timeout=60):
    code, body, _ = fetch(path, jar, timeout)
    try:
        data = json.loads(body)
    except ValueError:
        raise RuntimeError("fixture returned non-JSON HTTP " + str(code)) from None
    check(code == 200, "fixture HTTP " + str(code))
    return data


def deployment(action):
    data = call("/?esp_fg_ci_auth=deploy-" + action)
    check(data["success"], "deployment " + action)
    return data


def assert_bridge(jar, payload):
    code, body, headers = fetch(MEDIA, jar)
    check(code == 307 and body == b"" and
          headers.get("Location", "").startswith("/?esp_gate_bridge=") and
          "no-store" in headers.get("Cache-Control", ""), "Gate bridge issued")
    code, body, headers = fetch(headers["Location"], jar)
    check(code == 200 and body == payload and
          "no-store" in headers.get("Cache-Control", ""), "WordPress bridge bytes")
    check(any(c.name == GATE for c in jar), "Gate Cookie accepted")
    code, body, headers = fetch(MEDIA, jar)
    check(code == 200 and body == payload and not headers.get("Location"),
          "subsequent directly authorized bytes")


def run():
    active = deployment("enable")
    check(active["active"] and active["serving"], "initial real Gate active")
    session, remember = jar_from(()), jar_from(())
    for kind, jar in (("session", session), ("remember", remember)):
        call("/?esp_fg_ci_auth=" + kind, jar)
        check(any(c.name == GATE for c in jar), kind + " original Gate proof")
    code, payload, _ = fetch(MEDIA, session)
    check(code == 200 and len(payload) > 100, "before update protected bytes")
    check(fetch(MEDIA)[0] == 403 and fetch(PUBLIC)[0] == 200, "before update policy")
    revoked = jar_from(())
    call("/?esp_fg_ci_auth=session", revoked)
    copied_revoked = jar_from(revoked)
    call("/?esp_fg_ci_auth=logout", revoked)
    check(fetch(MEDIA, copied_revoked)[0] == 403, "revoked ticket before upgrade")
    # Core replacement, old PHP still loaded, real signed release digest.
    report = call("/?esp_fg_ci_upgrade=perform", timeout=120)
    old, nxt = report["before"]["loaded"], report["next"]
    pre, after = report["preinstall"], report["old_after"]
    check(report["core_success"] and not report["core_error"] and
          report["old_identity_preserved"], "Core update and auth row preservation")
    check(pre["runtime"] == "UPDATING" and pre["disk"] == old and
          pre["writer"] == old and pre["route"] == "legacy" and
          not pre["active"] and not pre["serving"] and pre["legacy_rule"] and
          pre["audit"], "pre-copy Gate stopped and legacy verified")
    check(after["loaded"] == old and after["disk"] == nxt and
          after["installed"] == old and after["verified"] == old and
          after["runtime"] == "UPDATING" and after["plugin_active"],
          "old PHP did not certify new files")
    # Auto-Cron is disabled in this fixture: explicit genuine wp-cron.php
    # executes post-update verification using the freshly loaded PHP.
    check(fetch("/wp-cron.php")[0] == 200, "real post-update Cron HTTP")
    status = call("/?esp_fg_ci_upgrade=status")
    shutdown, new = status["old_shutdown"], status["current"]
    check(shutdown["loaded"] == old and shutdown["disk"] == nxt and
          shutdown["runtime"] == "VERIFY_REQUIRED" and
          shutdown["installed"] == old and status["verify_scheduled"],
          "old shutdown scheduled new-code verification")
    check(new["loaded"] == nxt and new["disk"] == nxt and
          new["runtime"] == "READY" and new["installed"] == nxt and
          new["verified"] == nxt and new["update"] == "complete" and
          new["writer"] == nxt and new["audit"] and new["legacy_rule"] and
          new["plugin_active"] and new["route"] == "legacy" and
          not new["active"] and not new["serving"] and
          status["identity_preserved"], "new-code verification and Rewrite")
    check(fetch(MEDIA)[0] == 403 and fetch(PUBLIC)[0] == 200 and
          fetch(MISSING)[0] == 404, "legacy media policy after update")
    for jar in (session, remember):
        code, body, _ = fetch(MEDIA, jar)
        check(code == 200 and body == payload, "original credential in legacy")
    check(fetch(MEDIA, copied_revoked)[0] == 403, "revoked remains denied in legacy")
    active = deployment("enable")
    check(active["active"] and active["serving"], "new version verified enable")
    for jar in (session, remember):
        # Preserve old signed proof but require bridge after generation rotation.
        assert_bridge(jar, payload)
    check(fetch(MEDIA, copied_revoked)[0] == 403 and fetch(MEDIA)[0] == 403 and
          fetch(PUBLIC)[0] == 200 and fetch(MISSING)[0] == 404, "post-enable policy")
    for jar in (session, remember):
        copied = jar_from(jar)
        call("/?esp_fg_ci_auth=logout", jar)
        check(fetch(MEDIA, copied)[0] == 403, "post-upgrade real logout")
    disabled = deployment("disable")
    check(not disabled["serving"] and not disabled["active"] and
          disabled["route"] == "legacy", "final safe legacy")
    print("FG_REAL_CORE_VERSION_UPGRADE " + json.dumps({
        "checks_passed": checks, "from": old, "to": nxt,
        "preinstall": pre, "old_shutdown": shutdown, "new_verified": new,
        "identity_preserved": True, "session_remember_bridge": "passed",
        "revoked": "passed", "logout": "passed"}), flush=True)


if __name__ == "__main__":
    if sys.argv[1] == "build":
        build(sys.argv[2], sys.argv[3])
    elif sys.argv[1] == "run":
        run()
    else:
        raise SystemExit("Expected build or run")
