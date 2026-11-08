<?php
/**
 * Isolated Fast Gate HTTP E2E fixture. Installed as a MUST-USE plugin only
 * inside the disposable Actions WordPress image, never in the release ZIP.
 *
 * Requests are permitted exclusively when the Docker container has the
 * explicit ESP_FG_CI_HTTP_AUTH=1 flag and uses the disposable database.
 */
if (!defined('ABSPATH')) { exit; }

add_action('init', static function () {
    if (!isset($_GET['esp_fg_ci_auth']) &&
        !isset($_GET['esp_fg_ci_redirect_landed'])) { return; }
    if (getenv('ESP_FG_CI_HTTP_AUTH') !== '1' ||
        !defined('DB_NAME') || DB_NAME !== 'esp_fg_lifecycle' ||
        !class_exists('ESP_Fast_Gate_WP') ||
        !class_exists('ESP_Auth')) {
        status_header(404);
        exit;
    }
    if (isset($_GET['esp_fg_ci_redirect_landed'])) {
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode([
            'landed'=>true,
            'source_cookie_seen'=>isset($_COOKIE['esp_auth_fg_path']) ||
                isset($_COOKIE['esp_remember_token_fg_path']),
            'signed_cookie_seen'=>isset($_COOKIE['esp_gate_fg_path']),
        ]);
        exit;
    }
    $action = (string)$_GET['esp_fg_ci_auth'];
    $supported = ['session', 'remember', 'redirect-session',
        'redirect-remember', 'refresh', 'logout',
        'logout-fail-session', 'logout-fail-remember', 'rotate'];
    if (!in_array($action, $supported, true)) {
        status_header(404);
        exit;
    }
    // This override exists only for the controlled HTTP endpoint. Production
    // option updates must still reject activation until Rewrite is shipped.
    add_filter('pre_option_' . ESP_Fast_Gate_WP::ACTIVE_OPTION,
        static function () { return '1'; }, 1);
    $all_paths = ESP_Option::get_current_setting('path');
    $path = $all_paths['fg_path'] ?? null;
    $ledger = ESP_Fast_Gate_WP::ledger(true);
    if (!$path || !$ledger || !$ledger->serving()) {
        status_header(503);
        exit;
    }
    $ok = false;
    $result = ['action' => $action];
    if (in_array($action,
        ['session','remember','redirect-session','redirect-remember'], true)) {
        if (in_array($action, ['remember','redirect-remember'], true)) {
            $_POST['esp_remember'] = '1';
        } else { unset($_POST['esp_remember']); }
        $auth = new ESP_Auth();
        $ok = $auth->process_login($path, 'disposable-ci-password');
        if ($ok && strpos($action, 'redirect-') === 0) {
            // This invokes the actual shipped cookie + redirect method.
            // The client must receive Set-Cookie on the HTTP 302 and send
            // both original and signed cookies on the following GET.
            ESP_Cookie::get_instance()->do_redirect(
                '/?esp_fg_ci_redirect_landed=1');
        }
        if ($ok) { ESP_Cookie::get_instance()->set_pending_cookies(); }
        $result['authenticated'] = $ok;
    } elseif ($action === 'refresh') {
        $auth = new ESP_Auth();
        $ok = $auth->is_logged_in($path);
        if ($ok) { ESP_Cookie::get_instance()->set_pending_cookies(); }
        $result['authenticated'] = $ok;
    } elseif (in_array($action, [
        'logout', 'logout-fail-session', 'logout-fail-remember'
    ], true)) {
        // A deterministic real-MySQL fault: substitute only the targeted
        // DELETE with a statement against a nonexistent disposable table.
        // No SQL wrapper mock, and the other login-kind delete is unaffected.
        // The test expects the SQLite ref to be revoked BEFORE either DELETE.
        $kind = null;
        if ($action === 'logout-fail-session') { $kind = 'session'; }
        if ($action === 'logout-fail-remember') { $kind = 'remember'; }
        global $wpdb;
        $attempted = 0;
        $inject = null;
        if ($kind !== null) {
            $table = $wpdb->prefix . ESP_Config::DB_TABLES[$kind];
            $inject = static function ($query) use ($table, &$attempted) {
                if (strpos($query, 'DELETE FROM `' . $table . '` ') !== false ||
                    strpos($query, 'DELETE FROM ' . $table . ' ') !== false) {
                    $attempted++;
                    return 'DELETE FROM esp_fg_ci_nonexistent_table WHERE id=1';
                }
                return $query;
            };
            add_filter('query', $inject, 1);
        }
        try {
            $logout = new ESP_Logout();
            $method = new ReflectionMethod('ESP_Logout', 'logout_from_path');
            $ok = $method->invoke($logout, $path) === true;
        } finally {
            if ($inject !== null) { remove_filter('query', $inject, 1); }
        }
        ESP_Cookie::get_instance()->set_pending_cookies();
        $result['logged_out'] = $ok;
        if ($kind !== null) {
            $result['injected_delete_failed'] = $attempted === 1;
            $c = ESP_Cookie::get_instance();
            $token = $kind === 'session'
                ? $c->get_session_cookie('fg_path')
                : ($c->get_remember_cookies_for_path($path)['token'] ?? null);
            // Test that the failed delete left the exact original credential
            // in MySQL, not just a different login record.
            $target_table = $wpdb->prefix . ESP_Config::DB_TABLES[$kind];
            $result['target_row_retained'] =
                is_string($token) && (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $target_table
                     WHERE token=%s AND path_id=%s",
                    hash_hmac('sha256', $token, AUTH_SALT), 'fg_path'
                )) === 1;
        }
    } elseif ($action === 'rotate') {
        $settings = get_option(ESP_Config::OPTION_KEY);
        if (is_array($settings) &&
            isset($settings['path']['fg_path']['password_version'])) {
            $settings['path']['fg_path']['password_version']++;
            $ok = update_option(ESP_Config::OPTION_KEY, $settings);
            $result['password_version'] =
                (int)get_option(ESP_Config::OPTION_KEY)['path']['fg_path']['password_version'];
        }
        $result['rotated'] = $ok;
    }
    $result['serving'] = $ledger->serving();
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=utf-8');
    if (!$ok) { status_header(409); }
    echo wp_json_encode($result);
    exit;
}, PHP_INT_MAX);
