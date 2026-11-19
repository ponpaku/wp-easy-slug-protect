#!/usr/bin/env python3
"""Browser E2E for the real Fast Gate WordPress Settings UI.

Runs only against the disposable Apache/WordPress lifecycle fixture. Screenshots
contain only the CI administrator's settings page, never credentials or cookies.
"""
import json
import os
import subprocess
from pathlib import Path
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright


BASE = "http://127.0.0.1:8096"
ADMIN = BASE + "/wp-admin/admin.php?page=esp-settings"
SECTION = "#esp-fast-gate"
ARTIFACTS = Path(os.environ["ESP_FG_UI_ARTIFACTS"])
ARTIFACTS.mkdir(parents=True, exist_ok=True)
checks = []


def check(condition, message):
    if not condition:
        raise AssertionError(message)
    checks.append(message)


def screenshot(page, name):
    if "admin.php?page=esp-settings" in page.url and page.locator(SECTION).count():
        page.locator(SECTION).screenshot(path=str(ARTIFACTS / (name + ".png")))


def state(page, expected):
    text = page.locator(SECTION).inner_text()
    check("状態: " + expected in text, "settings state: " + expected)


def action(page, name, result, expected_state):
    selector = 'form:has(input[name="action"][value="esp_fast_gate_' + name + '"])'
    button = page.locator(SECTION).locator(selector).locator('input[type="submit"]')
    check(button.count() == 1 and button.is_visible(), name + " button visible")
    with page.expect_navigation(wait_until="domcontentloaded"):
        button.click()
    check(page.url.startswith(ADMIN + "&esp_fg_result=")
          and page.url.endswith("#esp-fast-gate"),
          name + " redirects to the ESP settings section")
    notice = page.locator(SECTION + " .notice p")
    expected_notice = ("操作が完了しました。" if result == "success"
                       else "操作に失敗しました。エラーコード: " + result)
    check(notice.count() == 1 and notice.inner_text() == expected_notice,
          name + " notice: " + result)
    state(page, expected_state)


def media(api, path, code):
    response = api.get(BASE + "/wp-content/uploads/" + path,
                       max_redirects=0, timeout=15000)
    check(response.status == code, path + " HTTP " + str(code))


def navigation_codes(response):
    codes = []
    request = response.request if response is not None else None
    while request is not None:
        item = request.response()
        codes.append(item.status if item is not None else None)
        request = request.redirected_from
    return list(reversed(codes))


def media_login_browser(browser, base=BASE):
    # Actual Chromium top-level navigation and actual ESP password form,
    # under the installed product-managed route. An isolated guest context
    # has no WordPress administrator privilege.
    guest = browser.new_context(locale="ja-JP")
    media_url = base + "/wp-content/uploads/fg-life-protected.jpg"
    gate_name = "esp_gate_fg_path"
    source_name = "esp_auth_fg_path"
    setup = False
    try:
        result = guest.request.get(base + "/?esp_fg_ci_auth=login-page-setup")
        setup = result.status == 200
        check(setup and result.json().get("login_page_configured") is True,
              "disposable browser login page configured")
        tab = guest.new_page()
        tab.set_default_timeout(30000)
        tab.set_default_navigation_timeout(60000)
        landing = tab.goto(media_url, wait_until="domcontentloaded")
        check(navigation_codes(landing) == [307, 302, 200],
              "real Chromium anonymous media navigated to login via 307/302/200")
        form = tab.locator(".esp-login-form form")
        check(form.count() == 1 and form.is_visible(),
              "configured ESP password form rendered")
        check(form.locator('input[name="redirect_to"]').input_value() ==
              urlsplit(media_url).path[len(urlsplit(base).path):],
              "form carries original home-relative media return path")
        form.locator('input[name="esp_password"]').fill("disposable-ci-password")
        with tab.expect_navigation(wait_until="domcontentloaded") as nav:
            form.locator('button[type="submit"]').click()
        image = nav.value
        check(image is not None and image.status == 200 and
              image.url == media_url and
              image.headers.get("content-type", "").startswith("image/jpeg") and
              "no-store" in image.headers.get("cache-control", ""),
              "actual ESP form submission returned to original protected JPEG")
        cookies = {c["name"]: c for c in guest.cookies([media_url])}
        check(source_name in cookies and gate_name in cookies and
              cookies[gate_name]["path"] == (urlsplit(base).path.rstrip("/") + "/") and
              cookies[gate_name]["httpOnly"] is True and
              cookies[gate_name]["sameSite"] == "Strict",
              "actual Chromium accepted scoped ESP and Gate cookies")
        # Remove only the Gate proof, not the source session, then require a
        # browser-driven bridge and a subsequent non-redirected request.
        guest.clear_cookies(name=gate_name)
        check(any(c["name"] == source_name for c in guest.cookies([media_url])),
              "source login persists when Gate cookie is removed")
        bridged = tab.goto(media_url, wait_until="domcontentloaded")
        check(navigation_codes(bridged) == [307, 200] and
              "no-store" in bridged.headers.get("cache-control", "") and
              any(c["name"] == gate_name for c in guest.cookies([media_url])),
              "real browser old-session bridge delivered JPEG and installed ticket")
        direct = tab.goto(media_url, wait_until="domcontentloaded")
        check(navigation_codes(direct) == [200] and direct.url == media_url,
              "subsequent real browser image stayed on fast path")
        logout = guest.request.get(base + "/?esp_fg_ci_auth=logout")
        check(logout.status == 200, "browser session logout completed")
        denied = guest.request.get(media_url, max_redirects=0)
        check(denied.status == 403, "new media request denied after browser logout")
        print("FG_REAL_BROWSER_MEDIA_LOGIN " + json.dumps({
            "layout": urlsplit(base).path or "/",
            "login": "passed", "first_bridge": "passed",
            "direct_repeat": "passed", "logout": "passed"
        }), flush=True)
    except AssertionError:
        raise
    except Exception as exc:
        # Playwright exception diagnostics may include handoff URLs.
        raise RuntimeError("Browser media login failed: " +
                           type(exc).__name__) from None
    finally:
        try:
            if setup:
                restored = guest.request.get(
                    base + "/?esp_fg_ci_auth=login-page-teardown")
                check(restored.status == 200 and
                      restored.json().get("login_page_restored_to_zero") is True,
                      "browser login fixture restored to original setting")
        finally:
            guest.close()


def audit_cli(code):
    # Only the explicitly disposable lifecycle container, never an external site.
    container = os.environ["ESP_AUDIT_CI_CONTAINER"]
    assert os.environ.get("CI") == "true" and os.environ.get("ESP_ALLOW_DISPOSABLE_DB") == "1"
    prefix = ('require "/var/www/html/wp-load.php"; '
              'if (DB_NAME !== "esp_fg_lifecycle") exit(2); ')
    return subprocess.check_output(
        ["docker", "exec", "-u", "www-data", container, "env", "-u",
         "SERVER_SOFTWARE", "php", "-r", prefix + code], text=True).strip()


def audit_record():
    return json.loads(audit_cli('echo json_encode(get_option("esp_integrity_last_audit"));'))


def audit_fingerprint():
    return audit_cli('$d=wp_upload_dir(null,false); '
                     'echo hash("sha256",serialize([hash_file("sha256",$d["basedir"]."/.htaccess"),'
                     'get_option(ESP_Config::OPTION_KEY),get_option("esp_runtime_state"),'
                     'get_option("esp_fast_gate_v2_route"),get_option("esp_fast_gate_v2_active")]));')


def raw_cron_audit(expected):
    before = audit_fingerprint()
    audit_cli('wp_clear_scheduled_hook("esp_daily_cleanup"); '
              'if (!wp_schedule_event(time()-10,"daily","esp_daily_cleanup")) exit(3); '
              'delete_transient("doing_cron");')
    subprocess.run(["docker", "exec", "-u", "www-data",
                    os.environ["ESP_AUDIT_CI_CONTAINER"], "env", "-u",
                    "SERVER_SOFTWARE", "php", "-f", "/var/www/html/wp-cron.php"],
                   check=True)
    record = audit_record()
    rewrite = record["rewrite"]
    check(record["source"] == "daily_hook" and rewrite["context"] == "cli"
          and rewrite["server_family"] == "unknown" and rewrite["status"] == expected
          and rewrite["matched_policy"] == ("fast" if expected == "ok" else "apache")
          and not record["errors"], "real bare PHP wp-cron audit: " + expected)
    check(audit_fingerprint() == before, "raw cron preserves protection options and Rewrite")
    return record


def recheck_audit(page, browser):
    before = audit_fingerprint()
    previous = audit_record()
    form = page.locator("#esp-daily-audit form")
    check(form.count() == 1 and form.locator('[name="_wpnonce"]').count() == 1,
          "audit form is separate and carries a nonce")
    check(page.locator("#esp-settings-form #esp-daily-audit").count() == 0,
          "audit form not nested in options form")
    other = browser.new_context()
    try:
        denied = other.request.post(BASE + "/wp-admin/admin-post.php",
            form={"action": "esp_recheck_audit"}, max_redirects=0)
        check(denied.status in (400, 403), "anonymous audit request denied")
    finally:
        other.close()
    denied = page.request.post(BASE + "/wp-admin/admin-post.php",
        form={"action": "esp_recheck_audit", "_wpnonce": "invalid"}, max_redirects=0)
    check(denied.status == 403 and audit_record() == previous,
          "bad nonce rejected without changing audit evidence")
    with page.expect_navigation(wait_until="domcontentloaded"):
        form.locator('button[type="submit"]').click()
    record = audit_record()
    check(record["source"] == "manual" and record["rewrite"]["context"] == "http"
          and record["rewrite"]["server_family"] == "apache"
          and record["rewrite"]["status"] == "ok" and not record["errors"],
          "authenticated HTTP recheck records actual server and current file match")
    check(record["last_daily_hook_at"] == previous["last_daily_hook_at"],
          "manual recheck does not mask the last daily-hook timestamp")
    check(audit_fingerprint() == before, "HTTP recheck does not change protection or Rewrite")
    check(page.url.endswith("#esp-daily-audit"), "recheck returns to saved audit results")


def main():
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            context = browser.new_context(viewport={"width": 1280, "height": 900},
                                          locale="ja-JP")
            page = context.new_page()
            page.set_default_timeout(30000)
            page.set_default_navigation_timeout(60000)
            try:
                # Verify that an anonymous browser cannot access the Settings UI.
                anon = browser.new_context()
                try:
                    guest = anon.new_page()
                    guest.goto(ADMIN, wait_until="domcontentloaded")
                    check("wp-login.php" in guest.url,
                          "anonymous settings request redirects to WordPress login")
                finally:
                    anon.close()

                page.goto(BASE + "/wp-login.php", wait_until="domcontentloaded")
                page.locator("#user_login").fill("fg-ci")
                page.locator("#user_pass").fill("disposable-CI-only")
                with page.expect_navigation(wait_until="domcontentloaded"):
                    page.locator("#wp-submit").click()
                check("/wp-admin/" in page.url, "WordPress administrator signed in")

                page.goto(ADMIN, wait_until="domcontentloaded")
                check(page.get_by_role("heading", name="Easy Slug Protect 設定").count() == 1,
                      "primary ESP settings page heading")
                check(page.get_by_role("heading", name="高速ゲート（Fast Gate v2）").count() == 1,
                      "Fast Gate appears as a section within the ESP settings page")
                check(page.locator("#esp-settings-form").count() == 1,
                      "existing ESP options.php settings form remains intact")
                check(page.locator("#esp-settings-form " + SECTION).count() == 0,
                      "deployment forms are NOT nested in the options.php form")
                check(page.locator(SECTION + " form").count() == 3,
                      "each deployment action has a separate administrator form")
                check(page.locator("#adminmenu a[href*='page=esp-fast-gate']").count() == 0,
                      "no separate WordPress Settings Fast Gate menu")
                check(page.locator("a[href='#esp-fast-gate']").count() == 1,
                      "media settings links to the Fast Gate section")
                check("Apache / LiteSpeed 専用" in page.locator(SECTION).inner_text(),
                      "supported server warning is visible")
                state(page, "準備完了（従来方式で配信中）")
                # Diagnose only: query values are display hints, not authority
                # to change the ledger or the media authorization path.
                for error_code in ("esp_gate_catalog_build_failed",
                                   "esp_gate_catalog_recheck_failed"):
                    page.goto(ADMIN + "&esp_fg_result=" + error_code +
                              "&esp_fg_stage=index_owner_scan",
                              wait_until="domcontentloaded")
                    notice = page.locator(SECTION + " .notice p")
                    check(notice.count() == 1 and
                          error_code in notice.inner_text() and
                          "失敗段階: index_owner_scan" in notice.inner_text() and
                          "MySQL索引" in notice.inner_text(),
                          "allowlisted catalog stage visible: " + error_code)
                page.goto(ADMIN +
                          "&esp_fg_result=esp_gate_catalog_build_failed" +
                          "&esp_fg_stage=%2Fprivate%2Fsecret",
                          wait_until="domcontentloaded")
                check("失敗段階:" not in
                      page.locator(SECTION + " .notice p").inner_text(),
                      "unapproved diagnostic stage is not reflected")
                page.goto(ADMIN +
                          "&esp_fg_result=esp_gate_http_probe_failed" +
                          "&esp_fg_stage=index_owner_scan",
                          wait_until="domcontentloaded")
                check("失敗段階:" not in
                      page.locator(SECTION + " .notice p").inner_text(),
                      "unrelated deployment errors ignore catalog stage")
                page.goto(ADMIN, wait_until="domcontentloaded")
                state(page, "準備完了（従来方式で配信中）")
                screenshot(page, "01-initial")

                if os.environ.get("ESP_AUDIT_CI_CONTAINER"):
                    raw_cron_audit("partial")
                    page.goto(ADMIN, wait_until="domcontentloaded")
                    recheck_audit(page, browser)

                action(page, "prepare", "success", "準備完了（従来方式で配信中）")

                # Inject a route-probe transport error ONLY for this one
                # administrator-submitted form in the disposable MU fixture.
                context.set_extra_http_headers({"X-ESP-CI-Fail-Probe": "1"})
                try:
                    action(page, "enable", "esp_gate_http_probe_failed",
                           "準備完了（従来方式で配信中）")
                finally:
                    context.set_extra_http_headers({})
                screenshot(page, "02-failed-probe-notice")
                media(context.request, "fg-life-public.jpg", 200)

                # A deliberately crashed controller leaves a stale lock and
                # fail-closed Rewrite. Recovery itself MUST use the actual UI.
                response = context.request.get(
                    BASE + "/?esp_fg_ci_auth=deploy-inject-stale-lock")
                injected = response.json()
                check(response.status == 200 and injected["success"] is True
                      and injected["route"] == "fast"
                      and injected["serving"] is False,
                      "disposable crashed transition staged")
                media(context.request, "fg-life-public.jpg", 503)
                page.goto(ADMIN, wait_until="domcontentloaded")
                state(page, "停止中（配信を拒否しています）")
                check("15分未満のロックは解除しません" in
                      page.locator(SECTION).inner_text(),
                      "stale lock recovery instructions visible")
                screenshot(page, "03-stale-transition")
                action(page, "recover", "success", "準備完了（従来方式で配信中）")
                media(context.request, "fg-life-public.jpg", 200)

                action(page, "enable", "success", "稼働中")
                screenshot(page, "04-running")
                media(context.request, "fg-life-public.jpg", 200)
                media(context.request, "fg-life-protected.jpg", 403)
                # Activation E2E created this physical unregistered file.
                media(context.request, "esp-unknown-probe.jpg", 403)
                media(context.request, "esp-missing-probe.jpg", 404)
                media_login_browser(browser)
                if os.environ.get("ESP_AUDIT_CI_CONTAINER"):
                    raw_cron_audit("ok")
                    page.goto(ADMIN, wait_until="domcontentloaded")
                    recheck_audit(page, browser)
                    print("ESP84_REAL_AUDIT_PASS", flush=True)
                action(page, "disable", "success", "準備完了（従来方式で配信中）")
                screenshot(page, "05-disabled")
                media(context.request, "fg-life-public.jpg", 200)

                # Test the normal error result without changing authorization.
                action(page, "enable", "success", "稼働中")
                action(page, "prepare", "esp_gate_already_routed", "稼働中")
                screenshot(page, "06-already-routed-notice")
                action(page, "disable", "success", "準備完了（従来方式で配信中）")

                (ARTIFACTS / "summary.json").write_text(
                    json.dumps({"passed": len(checks), "checks": checks},
                               ensure_ascii=False, indent=2) + "\n",
                    encoding="utf-8")
                print("FG_ADMIN_BROWSER_E2E " + json.dumps({
                    "checks_passed": len(checks),
                    "real_admin_forms": ["prepare", "enable", "disable", "recover"],
                    "failed_probe_notice": "passed",
                    "stale_lock_recovery_ui": "passed",
                    "integrated_in_esp_settings": True,
                    "screenshots": 6,
                }), flush=True)
            except Exception:
                screenshot(page, "failure-settings")
                raise
            finally:
                context.close()
        finally:
            browser.close()


if __name__ == "__main__":
    main()
