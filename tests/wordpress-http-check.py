#!/usr/bin/env python3
"""Assert response bodies through a real Apache/PHP/WordPress fixture."""
import sys
import http.cookiejar
import re
import urllib.request
import urllib.parse
import urllib.error

base = 'http://localhost:8094'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

def client():
    return urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def request(c, path, fields=None):
    data = None if fields is None else urllib.parse.urlencode(fields).encode()
    try:
        r = c.open(base + path, data=data, timeout=15)
    except urllib.error.HTTPError as e:
        r = e
    return r.code, r.read(), r.headers

def check(ok, label):
    if not ok:
        raise AssertionError(label)
    print('PASS:', label)

anon = client()
if '--unready' in sys.argv:
    for path, secret in [('/esp44-secret/', b'ESP44_SECRET_PAGE'), ('/wp-content/uploads/esp44-protected.txt', b'ESP44_PROTECTED_MEDIA'), ('/wp-json/wp/v2/pages', b'ESP44_SECRET_PAGE'), ('/feed/', b'ESP44_SECRET_PAGE')]:
        status, body, _ = request(anon, path)
        check(status == 503 and secret not in body, 'unready runtime rejects protected/aggregate bytes: ' + path)
    status, body, _ = request(anon, '/esp44-public/')
    check(status == 200 and b'ESP44_PUBLIC_PAGE' in body, 'unready runtime preserves known public singular page')
    sys.exit(0)

status, body, _ = request(anon, '/esp44-public/')
check(status == 200 and b'ESP44_PUBLIC_PAGE' in body, 'public WordPress page remains available')
status, body, _ = request(anon, '/wp-content/uploads/esp44-public.txt')
check(status == 200 and body == b'ESP44_PUBLIC_MEDIA', 'public direct media returns exact original bytes')
for path, secret in [('/esp44-secret/', b'ESP44_SECRET_PAGE'), ('/wp-content/uploads/esp44-protected.txt', b'ESP44_PROTECTED_MEDIA')]:
    status, body, _ = request(anon, path)
    check(status in (302, 403) and secret not in body, 'anonymous protected URL returns no secret bytes: ' + path)
for remember in (False, True):
    c = client()
    status, form, _ = request(c, '/esp44-login/')
    nonce = re.search(rb'name="esp_nonce" value="([^"]+)"', form).group(1).decode()
    fields = {'esp_password': 'disposable-esp-password', 'esp_nonce': nonce, 'esp_path_id': 'path_http', 'redirect_to': '/esp44-secret/'}
    if remember:
        fields['esp_remember'] = '1'
    status, _, _ = request(c, '/esp44-login/', fields)
    check(status == 302, 'login redirects after session/remember authentication')
    status, body, _ = request(c, '/esp44-secret/')
    check(status == 200 and b'ESP44_SECRET_PAGE' in body, 'session/remember permits the protected page')
    status, media, _ = request(c, '/wp-content/uploads/esp44-protected.txt')
    check(status == 200 and media == b'ESP44_PROTECTED_MEDIA', 'session/remember permits exact protected media bytes')
    logout_nonce = re.search(rb'name="esp_nonce" value="([^"]+)"', body).group(1).decode()
    request(c, '/', {'esp_action': 'logout', 'esp_nonce': logout_nonce, 'esp_logout_path_id': 'path_http', 'redirect_to': '/'})
    status, media, _ = request(c, '/wp-content/uploads/esp44-protected.txt')
    check(status in (302, 403) and b'ESP44_PROTECTED_MEDIA' not in media, 'logout revokes access to identical media URL')
print('All real Apache HTTP checks passed.')
