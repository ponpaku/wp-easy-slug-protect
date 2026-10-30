#!/usr/bin/env python3
"""Exercise real WordPress admin login, rendered controls, and authenticated AJAX."""
import http.cookiejar
import json
import re
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://127.0.0.1:8094"
ADMIN = "/wp-admin/admin.php?page=esp-settings"
AJAX = "/wp-admin/admin-ajax.php"


def opener():
    return urllib.request.build_opener(
        urllib.request.ProxyHandler({}),
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
    )


def request(session, path, fields=None):
    data = None if fields is None else urllib.parse.urlencode(fields).encode("utf-8")
    req = urllib.request.Request(BASE + path, data=data)
    try:
        response = session.open(req, timeout=20)
    except urllib.error.HTTPError as exc:
        response = exc
    return response.status, response.read(), response.headers


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def button_enabled(html):
    m = re.search(rb'<button\b(?=[^>]*\bid="esp-media-index-build")[^>]*>', html, re.S)
    require(m is not None, "actual settings HTML has no media index build button")
    return re.search(rb'\bdisabled(?:\s|=|>)', m.group()) is None


session = opener()
# Exercise unauthenticated HTTP route, not only a direct PHP callback.
code, body, _ = request(opener(), AJAX, {"action": "esp_media_index_build_slice", "nonce": "bogus"})
require(code == 403 and b"Forbidden" in body,
        "anonymous AJAX must reject media-index build with 403")
print("PASS: actual HTTP AJAX denies unauthenticated requests")

# Browser-equivalent WordPress admin login with persistent cookie jar.
request(session, "/wp-login.php")
code, body, _ = request(session, "/wp-login.php", {
    "log": "esp-test",
    "pwd": "disposable-ci-test-only",
    "wp-submit": "Log In",
    "redirect_to": BASE + ADMIN,
    "testcookie": "1",
})
require(code == 200 and b'id="esp-media-index-build"' in body,
        "real WP admin login did not reach media-index settings page")

code, page, _ = request(session, ADMIN)
require(code == 200 and button_enabled(page),
        "global READY + eligible DIRTY must enable the real admin repair button")
nonce_match = re.search(rb'"mediaIndexNonce"\s*:\s*"([^"]+)"', page)
require(nonce_match is not None, "nonce not localized into actual admin HTML")
nonce = nonce_match.group(1).decode("ascii")
print("PASS: actual admin login renders enabled repair control and nonce")

code, bad, _ = request(session, AJAX, {
    "action": "esp_media_index_build_slice", "nonce": "invalid-nonce",
})
require(code == 403 and bad.strip() == b"-1",
        "authenticated bad-nonce AJAX was not denied")
print("PASS: actual authenticated AJAX rejects invalid nonce")

code, payload, _ = request(session, AJAX, {
    "action": "esp_media_index_build_slice", "nonce": nonce,
})
require(code == 200, "authenticated AJAX repair HTTP status " + str(code))
result = json.loads(payload)
require(result["success"] is True, "AJAX repair returned failure: " + str(result))
status = result["data"]
require(status["ready"] is True and status["repairable_dirty"] is False,
        "AJAX did not clear actionable dirty owner under global READY")
print("PASS: real authenticated admin HTTP AJAX repaired dirty attachment")

code, after, _ = request(session, ADMIN)
require(code == 200 and not button_enabled(after),
        "repair button must disable after no eligible dirty owners remain")
print("PASS: refreshed actual settings UI reflects completed repair")

print("ESP63_UI_HTTP_REPORT " + json.dumps({
    "admin_login": "verified",
    "anonymous_ajax": 403,
    "invalid_nonce_ajax": 403,
    "authorized_ajax": code,
    "repairable_dirty_after": status["repairable_dirty"],
}, sort_keys=True))
