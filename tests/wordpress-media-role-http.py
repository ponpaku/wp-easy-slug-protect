#!/usr/bin/env python3
"""#43 role-based actual WordPress HTTP media authorization, disposable CI only.

Keep legacy upload_files bypass unchanged: an Author can receive protected media
without the ESP path password, while a Subscriber must authenticate to ESP.
"""
import http.cookiejar
import re
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8094"
IMAGE = "/wp-content/uploads/esp31-protected.jpg"
MARKER = b"ESP31_PROTECTED_JPEG"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def request(opener, path, form=None):
    data = None if form is None else urllib.parse.urlencode(form).encode()
    req = urllib.request.Request(BASE + path, data=data)
    try:
        resp = opener.open(req, timeout=15)
    except urllib.error.HTTPError as exc:
        resp = exc
    return resp.code, resp.read(), resp.headers


def login(username, password):
    jar = http.cookiejar.CookieJar()
    common = [urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(jar)]
    follow = urllib.request.build_opener(*common)
    no_redirect = urllib.request.build_opener(
        urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(jar), NoRedirect()
    )
    request(follow, "/wp-login.php")  # Obtain WordPress test cookie.
    request(follow, "/wp-login.php", {
        "log": username, "pwd": password, "wp-submit": "Log In",
        "redirect_to": BASE + "/wp-admin/profile.php", "testcookie": "1",
    })
    status, body, _ = request(follow, "/wp-admin/profile.php")
    require(status == 200 and b'id="your-profile"' in body,
            username + ": WordPress login/profile verification failed")
    return no_redirect


def assert_image(opener, label, expected, allowed):
    code, body, headers = request(opener, IMAGE)
    require(code == expected, label + ": unexpected protected image status " + str(code))
    if allowed:
        require(body.startswith(b"\xff\xd8") and body.endswith(b"\xff\xd9")
                and MARKER in body, label + ": authenticated media bytes missing/corrupt")
    else:
        require(MARKER not in body and not body.startswith(b"\xff\xd8"),
                label + ": protected media bytes leaked")
    cache = headers.get("Cache-Control", "").lower()
    require("no-store" in cache and "public" not in cache,
            label + ": protected response is public-cacheable")
    print("PASS: #43 " + label + " status=" + str(code) + ", protected cache no-store")


anon = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
assert_image(anon, "anonymous", 302, False)

# Users are created only in the disposable CI database with WP-CLI.
subscriber = login("esp-ci-subscriber", "esp-ci-subscriber-only")
assert_image(subscriber, "logged-in subscriber without upload_files", 302, False)

author = login("esp-ci-author", "esp-ci-author-only")
assert_image(author, "author with upload_files privileged bypass", 200, True)

admin = login("esp-test", "disposable-ci-test-only")
assert_image(admin, "admin with upload_files privileged bypass", 200, True)

print("PASS: #43 real WordPress HTTP role policy (anonymous, subscriber, author, admin)")
