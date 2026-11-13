#!/usr/bin/env python3
"""Browser E2E for the real Fast Gate WordPress Settings UI.

Runs only against the disposable Apache/WordPress lifecycle fixture. Screenshots
contain only the CI administrator's settings page, never credentials or cookies.
"""
import json
import os
from pathlib import Path

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
                screenshot(page, "01-initial")

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
                media(context.request, "esp-unknown-probe.jpg", 403)
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
