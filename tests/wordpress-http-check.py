#!/usr/bin/env python3
"""Assert response bodies through a real Apache/PHP/WordPress fixture."""
import sys
import http.cookiejar
import re
import os
import json
import urllib.request
import urllib.parse
import urllib.error

base = 'http://localhost:8094'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

def client():
    return urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def request(c, path, fields=None, method=None, headers=None):
    data = None if fields is None else urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(base + path, data=data, method=method, headers=headers or {})
    try:
        r = c.open(req, timeout=15)
    except urllib.error.HTTPError as e:
        r = e
    return r.code, r.read(), r.headers

def check(ok, label):
    if not ok:
        raise AssertionError(label)
    print('PASS:', label)

def check_generated_media(c, filename, expected_status, marker=None, pdf=False):
    check(isinstance(filename, str) and filename.startswith(('esp42-', 'esp31-protected-')) and
          filename.endswith(('.jpg', '.pdf')) and '/' not in filename,
          'fixture file name is strictly inside disposable uploads')
    status, body, headers = request(c, '/wp-content/uploads/' + filename)
    check(status == expected_status, 'generated file HTTP status: ' + filename)
    if expected_status == 200:
        if pdf:
            check(body.startswith(b'%PDF'), 'WordPress PDF document bytes: ' + filename)
        else:
            check(body.startswith(b'\xff\xd8') and body.endswith(b'\xff\xd9'),
                  'WordPress JPEG preview bytes: ' + filename)
        if marker:
            check(marker.encode() in body, 'fixture marker: ' + filename)
        check('no-store' in headers.get('Cache-Control', '').lower(),
              'generated protected file no-store: ' + filename)
    else:
        check(b'%PDF' not in body and (not marker or marker.encode() not in body),
              'protected generated file bytes not leaked: ' + filename)

fixture_path = os.environ.get('ESP42_PDF_MANIFEST')
generated = None
if fixture_path:
    with open(fixture_path, encoding='utf-8') as handle:
        generated = json.load(handle)
    check(set(generated.keys()) == {'pdf_original', 'pdf_full', 'pdf_thumb',
                                    'image_unique', 'gif_poster_unique'},
          'real core PDF/unique-name fixture has all expected files')

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
status, body, public_headers = request(anon, '/wp-content/uploads/esp44-public.txt')
check(status == 200 and body == b'ESP44_PUBLIC_MEDIA', 'public direct media returns exact original bytes')
status, public_jpeg, jpeg_headers = request(anon, '/wp-content/uploads/esp31-public.jpg')
check(status == 200 and public_jpeg.startswith(b'\xff\xd8') and
      public_jpeg.endswith(b'\xff\xd9') and b'ESP31_PUBLIC_JPEG' in public_jpeg,
      'public JPEG direct URL returns intact image bytes')
check('public' in jpeg_headers.get('Cache-Control', '').lower() and
      'no-store' not in jpeg_headers.get('Cache-Control', '').lower(),
      'public JPEG retains long-lived public cache policy')
status, thumbnail, thumbnail_headers = request(anon, '/wp-content/uploads/esp31-public-150x150.jpg')
check(status == 200 and b'ESP42_PUBLIC_THUMB' in thumbnail and
      'public' in thumbnail_headers.get('Cache-Control', '').lower(),
      'WordPress standard public JPEG thumbnail stays available')
check('no-store' not in public_headers.get('Cache-Control', '').lower(),
      'public media is not forced into protected no-store cache policy')
for path, secret in [('/esp44-secret/', b'ESP44_SECRET_PAGE'),
                     ('/wp-content/uploads/esp44-protected.txt', b'ESP44_PROTECTED_MEDIA'),
                     ('/wp-content/uploads/esp31-protected.jpg', b'ESP31_PROTECTED_JPEG'),
                     ('/wp-content/uploads/esp31-protected-150x150.jpg', b'ESP42_PROTECTED_THUMB')]:
    status, body, _ = request(anon, path)
    check(status in (302, 403) and secret not in body, 'anonymous protected URL returns no secret bytes: ' + path)
if generated:
    for name, marker in [('pdf_original', None), ('pdf_full', None),
                         ('pdf_thumb', None), ('image_unique', 'ESP42_NUMBERED_IMAGE'),
                         ('gif_poster_unique', 'ESP42_NUMBERED_GIF_POSTER')]:
        check_generated_media(anon, generated[name], 302, marker)

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
    status, media, media_headers = request(c, '/wp-content/uploads/esp44-protected.txt')
    check(status == 200 and media == b'ESP44_PROTECTED_MEDIA', 'session/remember permits exact protected media bytes')
    check('no-store' in media_headers.get('Cache-Control', '').lower(),
          'authenticated protected media cannot enter shared cache')
    status, media, head_headers = request(c, '/wp-content/uploads/esp44-protected.txt', method='HEAD')
    check(status == 200 and media == b'' and 'no-store' in head_headers.get('Cache-Control', '').lower(),
          'authorized protected HEAD has no bytes and remains uncacheable')
    status, media, range_headers = request(c, '/wp-content/uploads/esp44-protected.txt',
                                          headers={'Range': 'bytes=0-4'})
    check(status == 206 and media == b'ESP44' and
          'no-store' in range_headers.get('Cache-Control', '').lower(),
          'authorized protected byte range remains uncacheable')
    status, jpeg, headers = request(c, '/wp-content/uploads/esp31-protected.jpg')
    check(status == 200 and jpeg.startswith(b'\xff\xd8') and
          jpeg.endswith(b'\xff\xd9') and b'ESP31_PROTECTED_JPEG' in jpeg,
          'session/remember returns intact protected JPEG bytes')
    check('no-store' in headers.get('Cache-Control', '').lower() and
          'public' not in headers.get('Cache-Control', '').lower(),
          'authenticated protected JPEG is uncacheable')
    status, jpeg_head, headers = request(c, '/wp-content/uploads/esp31-protected.jpg',
                                         method='HEAD')
    check(status == 200 and jpeg_head == b'' and
          'no-store' in headers.get('Cache-Control', '').lower(),
          'protected JPEG HEAD is uncacheable and has no body')
    status, jpeg_range, headers = request(c, '/wp-content/uploads/esp31-protected.jpg',
                                          headers={'Range': 'bytes=0-3'})
    check(status == 206 and jpeg_range == jpeg[:4] and
          'no-store' in headers.get('Cache-Control', '').lower(),
          'protected JPEG Range serves expected bytes without caching')
    status, thumb_body, thumb_headers = request(c, '/wp-content/uploads/esp31-protected-150x150.jpg')
    check(status == 200 and b'ESP42_PROTECTED_THUMB' in thumb_body and
          'no-store' in thumb_headers.get('Cache-Control', '').lower(),
          'WordPress standard protected thumbnail inherits exact attachment authorization')
    if generated:
        for name, marker in [('pdf_original', None), ('pdf_full', None),
                             ('pdf_thumb', None), ('image_unique', 'ESP42_NUMBERED_IMAGE'),
                             ('gif_poster_unique', 'ESP42_NUMBERED_GIF_POSTER')]:
            check_generated_media(c, generated[name], 200, marker,
                                  pdf=name == 'pdf_original')
    logout_nonce = re.search(rb'name="esp_nonce" value="([^"]+)"', body).group(1).decode()
    request(c, '/', {'esp_action': 'logout', 'esp_nonce': logout_nonce, 'esp_logout_path_id': 'path_http', 'redirect_to': '/'})
    status, media, logout_headers = request(c, '/wp-content/uploads/esp44-protected.txt')
    check(status in (302, 403) and b'ESP44_PROTECTED_MEDIA' not in media, 'logout revokes access to identical media URL')
    check('no-store' in logout_headers.get('Cache-Control', '').lower(),
          'unauthorized media redirect/denial cannot be cached')
    status, jpeg_after_logout, headers = request(c, '/wp-content/uploads/esp31-protected.jpg')
    check(status in (302, 403) and b'ESP31_PROTECTED_JPEG' not in jpeg_after_logout,
          'logout denies the same protected JPEG URL without leaking image bytes')
    check('no-store' in headers.get('Cache-Control', '').lower(),
          'protected JPEG logout denial cannot be cached')
    status, thumb_after_logout, _ = request(c, '/wp-content/uploads/esp31-protected-150x150.jpg')
    check(status in (302, 403) and b'ESP42_PROTECTED_THUMB' not in thumb_after_logout,
          'logout revokes access to WordPress standard protected JPEG thumbnail')
    if generated:
        for name, marker in [('pdf_original', None), ('pdf_full', None),
                             ('pdf_thumb', None), ('image_unique', 'ESP42_NUMBERED_IMAGE'),
                             ('gif_poster_unique', 'ESP42_NUMBERED_GIF_POSTER')]:
            status, leaked_body, _ = request(c, '/wp-content/uploads/' + generated[name])
            check(status in (302, 403) and b'%PDF' not in leaked_body and
                  (not marker or marker.encode() not in leaked_body),
                  'logout revokes generated protected media: ' + generated[name])
print('All real Apache HTTP checks passed.')
