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
    if (!isset($_GET['esp_fg_ci_auth'])) { return; }
    if (getenv('ESP_FG_CI_HTTP_AUTH') !== '1' ||
        !defined('DB_NAME') || DB_NAME !== 'esp_fg_lifecycle' ||
        !class_exists('ESP_Fast_Gate_WP') ||
        !class_exists('ESP_Auth')) {
        status_header(404);
        exit;
    }
    $action = (string)$_GET['esp_fg_ci_auth'];
    $supported = ['session', 'remember', 'refresh', 'logout', 'rotate'];
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
    if ($action === 'session' || $action === 'remember') {
        if ($action === 'remember') { $_POST['esp_remember'] = '1'; }
        else { unset($_POST['esp_remember']); }
        $auth = new ESP_Auth();
        $ok = $auth->process_login($path, 'disposable-ci-password');
        if ($ok) { ESP_Cookie::get_instance()->set_pending_cookies(); }
        $result['authenticated'] = $ok;
    } elseif ($action === 'refresh') {
        $auth = new ESP_Auth();
        $ok = $auth->is_logged_in($path);
        if ($ok) { ESP_Cookie::get_instance()->set_pending_cookies(); }
        $result['authenticated'] = $ok;
    } elseif ($action === 'logout') {
        $logout = new ESP_Logout();
        $method = new ReflectionMethod('ESP_Logout', 'logout_from_path');
        $ok = $method->invoke($logout, $path) === true;
        ESP_Cookie::get_instance()->set_pending_cookies();
        $result['logged_out'] = $ok;
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
