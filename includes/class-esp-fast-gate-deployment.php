<?php
/**
 * Explicit deployment of the already-reviewed Fast Gate v2 runtime.
 *
 * The existing ESP media Rewrite writer owns uploads/.htaccess in BOTH modes.
 * No new storage setting, public secret, or second Rewrite block is introduced.
 * A failed transition keeps the standalone route non-serving (503), never a
 * permissive static-file fallback.
 */
if (!defined('ABSPATH')) { exit; }

final class ESP_Fast_Gate_Deployment {
    public const ROUTE_OPTION = 'esp_fast_gate_v2_route';
    private static $changing = false;

    public static function register() {
        add_filter('pre_update_option_' . self::ROUTE_OPTION,
            [self::class, 'guard_route_option'], 5, 2);
        add_action('admin_menu', [self::class, 'register_admin']);
        add_action('admin_post_esp_fast_gate_prepare', [self::class, 'admin_prepare']);
        add_action('admin_post_esp_fast_gate_enable', [self::class, 'admin_enable']);
        add_action('admin_post_esp_fast_gate_disable', [self::class, 'admin_disable']);
        add_filter('pre_update_option_' . ESP_Config::OPTION_KEY,
            [self::class, 'guard_media_disable'], 4, 2);
    }

    public static function route_fast() {
        return get_option(self::ROUTE_OPTION, 'legacy') === 'fast';
    }

    public static function guard_route_option($new, $old) {
        return self::$changing && in_array($new, ['fast','legacy'], true)
            ? $new : $old;
    }

    public static function allow_active_option_write() {
        return self::$changing;
    }

    public static function guard_media_disable($new, $old) {
        // A live gate must retain its rewrite until the explicit OFF operation.
        if (self::route_fast() && is_array($new) && is_array($old) &&
            (bool)($old['media']['enabled'] ?? true) &&
            !($new['media']['enabled'] ?? true)) {
            return $old;
        }
        return $new;
    }

    private static function error($code) {
        return new WP_Error('esp_gate_' . $code, $code);
    }

    private static function write_route($route) {
        self::$changing = true;
        try {
            update_option(self::ROUTE_OPTION, $route, false);
            return get_option(self::ROUTE_OPTION, 'legacy') === $route;
        } finally {
            self::$changing = false;
        }
    }

    private static function write_active($enabled) {
        self::$changing = true;
        try {
            update_option(ESP_Fast_Gate_WP::ACTIVE_OPTION, $enabled ? '1' : '0', false);
            return ESP_Fast_Gate_WP::active() === $enabled;
        } finally {
            self::$changing = false;
        }
    }

    private static function server_supported() {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? '';
        return is_string($software) && preg_match('/(?:Apache|LiteSpeed)/i', $software) === 1 &&
            preg_match('/(?:nginx|openresty|tengine)/i', $software) !== 1;
    }

    public static function gate_target() {
        if (!defined('ESP_URL')) { return null; }
        $path = parse_url(ESP_URL . 'gate/fast-gate.php', PHP_URL_PATH);
        return is_string($path) && preg_match('~^/[A-Za-z0-9/._%-]+/gate/fast-gate\.php$~D', $path)
            ? $path : null;
    }

    private static function keys() {
        $dir = ESP_Fast_Gate_WP::private_dir();
        if (!$dir || is_link($dir . '/keys.php') ||
            !is_file($dir . '/keys.php')) { return null; }
        $keys = require $dir . '/keys.php';
        if (!is_array($keys) || ($keys['site'] ?? null) !== (string)get_current_blog_id() ||
            !is_string($keys['sig'] ?? null) || strlen($keys['sig']) < 32 ||
            !is_string($keys['id'] ?? null) || strlen($keys['id']) < 32) {
            return null;
        }
        $uploads = wp_upload_dir(null, false);
        $root = realpath($uploads['basedir'] ?? '');
        $prefix = isset($uploads['baseurl'])
            ? parse_url($uploads['baseurl'], PHP_URL_PATH) : null;
        if (!$root || !is_string($prefix) || $prefix === '' ||
            $root !== ($keys['root'] ?? null) ||
            rtrim($prefix,'/') . '/' !== ($keys['prefix'] ?? null)) {
            return null;
        }
        return $keys;
    }

    public static function prepared() {
        $db = ESP_Fast_Gate_WP::ledger(false);
        $media = ESP_Option::get_current_setting('media');
        if (!is_array($media) || empty($media['enabled']) ||
            !$db || !$db->ready() || !self::keys() ||
            !ESP_Media_Index::enabled() || !ESP_Media_Index::ready()) {
            return false;
        }
        $status = ESP_Media_Index::build_status(false);
        return !empty($status['ready']) && empty($status['error']);
    }

    public static function prepare() {
        if (self::route_fast() || ESP_Fast_Gate_WP::active()) {
            return self::error('already_routed');
        }
        // Preparing the Fast Gate is an explicit administrator opt-in to the
        // *existing* incremental media index. Do not require a separate hidden
        // option change or undocumented WP-CLI command.
        if (!ESP_Media_Index::ready()) {
            ESP_Media_Index::run_batch();
            if (!ESP_Media_Index::ready()) {
                return self::error('index_build_pending');
            }
        }
        if (!ESP_Media_Index::enabled()) {
            update_option(ESP_Media_Index::ENABLE_OPTION, '1', false);
            if (!ESP_Media_Index::enabled()) {
                return self::error('index_enable_failed');
            }
        }
        $dir = ESP_Fast_Gate_WP::private_dir();
        if (!$dir && !ESP_Fast_Gate_WP::provision()) {
            return self::error('provision_failed');
        }
        $db = ESP_Fast_Gate_WP::ledger(true);
        if (!$db || !$db->schema_ok() || !self::keys()) {
            return self::error('invalid_ledger_or_keys');
        }
        if (!$db->ready() && !ESP_Fast_Gate_WP::build_catalog()) {
            return self::error('catalog_build_failed');
        }
        return self::prepared() ? true : self::error('catalog_not_ready');
    }

    private static function rewrite($expected_fast) {
        if (!class_exists('ESP_Media_Protection') || !self::server_supported()) {
            return false;
        }
        // Reuse its atomic replacement, lock, conflict detection and readback.
        // Instantiating normally also registers admin and media hooks, which
        // should not happen during this administrative transition.
        $media = (new ReflectionClass('ESP_Media_Protection'))
            ->newInstanceWithoutConstructor();
        $result = $media->update_htaccess();
        return $result === true && self::route_fast() === $expected_fast &&
            ESP_Media_Protection::audit_rewrite_policy() === true;
    }

    private static function http_probe($keys) {
        if (!function_exists('wp_remote_get')) { return false; }
        $uploads = wp_upload_dir(null, false);
        $base = $uploads['baseurl'] ?? null;
        if (!is_string($base) || !preg_match('~^https?://~i', $base)) {
            return false;
        }
        $nonce = bin2hex(random_bytes(16));
        $proof = hash_hmac('sha256', 'route|' . $nonce, $keys['sig']);
        $url = rtrim($base,'/') . '/esp-fast-gate-route-' . $nonce . '.jpg';
        $args = [
            'timeout'=>8, 'redirection'=>0, 'sslverify'=>true,
            'headers'=>['X-ESP-Gate-Route-Probe'=>$nonce . ':' . $proof],
        ];
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 204 ||
            !hash_equals(hash_hmac('sha256', 'ack|' . $nonce, $keys['sig']),
                (string)wp_remote_retrieve_header($response, 'X-ESP-Gate-Route-Ack'))) {
            return false;
        }

        // Directory-level denial is tested against the ACTUAL HTTP server,
        // not inferred from an .htaccess file existing on disk.
        $private_url = content_url('/esp-fast-gate-v2/gate-ledger.sqlite');
        $private = wp_remote_get($private_url, [
            'timeout'=>8, 'redirection'=>0, 'sslverify'=>true,
        ]);
        return !is_wp_error($private) &&
            wp_remote_retrieve_response_code($private) === 403;
    }

    private static function lock() {
        // The option's unique name is an atomic DB acquisition operation.
        // A failed/crashed transition is recoverable by an administrator after
        // manual inspection; never silently steal a possibly live lock.
        return add_option('esp_fast_gate_v2_deploy_lock',
            (string)(time() . ':' . wp_generate_uuid4()), '', false);
    }

    private static function unlock() {
        delete_option('esp_fast_gate_v2_deploy_lock');
    }

    /**
     * Called from a real Apache/LiteSpeed web request, or CLI with an
     * explicitly declared server family. HTTP probes still have to pass.
     */
    public static function enable($server_hint = null) {
        $original_server = $_SERVER['SERVER_SOFTWARE'] ?? null;
        if (defined('WP_CLI') && WP_CLI && $server_hint !== null) {
            if (!in_array($server_hint, ['apache','litespeed'], true)) {
                return self::error('unsupported_server');
            }
            $_SERVER['SERVER_SOFTWARE'] = $server_hint === 'apache' ?
                'Apache/2.4 (WP-CLI target)' : 'LiteSpeed (WP-CLI target)';
        }
        try {
            if (!self::server_supported() || self::gate_target() === null ||
                !self::prepared() || self::route_fast() ||
                ESP_Fast_Gate_WP::active()) {
                return self::error('not_prepared_or_unsupported');
            }
            $db = ESP_Fast_Gate_WP::ledger(true);
            $keys = self::keys();
            if (!$db || !$db->ready() || $db->serving() || !$keys ||
                !is_file(ESP_PATH . 'gate/fast-gate.php')) {
                return self::error('ledger_not_stopped');
            }
            if (!self::lock()) { return self::error('transition_locked'); }
            try {
                // 1. Install fail-closed route: serving=0 -> 503.
                if (!self::write_route('fast') || !self::rewrite(true)) {
                    self::rollback_enable($db);
                    return self::error('route_install_failed');
                }
                // 2. Verify the REAL HTTP handler and private directory denial.
                if (!self::http_probe($keys)) {
                    self::rollback_enable($db);
                    return self::error('http_probe_failed');
                }
                // 3. Last full reconciliation under the non-serving route.
                // During the rebuild, media/password writes are fenced or
                // rejected by the existing WP index bridge even though the
                // user-facing active flag is not yet set.
                if (!ESP_Fast_Gate_WP::build_catalog() || !self::prepared()) {
                    self::rollback_enable($db);
                    return self::error('catalog_recheck_failed');
                }
                // 4. Start WP mutation/logout synchronization before serving.
                if (!self::write_active(true)) {
                    self::rollback_enable($db);
                    return self::error('active_option_failed');
                }
                // 5. Only now permit the standalone reader to serve media.
                if (!$db->set_serving(true)) {
                    self::rollback_enable($db);
                    return self::error('serving_start_failed');
                }
                return true;
            } finally {
                self::unlock();
            }
        } finally {
            if ($original_server === null) {
                unset($_SERVER['SERVER_SOFTWARE']);
            } else {
                $_SERVER['SERVER_SOFTWARE'] = $original_server;
            }
        }
    }

    private static function rollback_enable($db) {
        // Never put a live gate back on after a failed transition.
        $db->set_serving(false);
        self::write_active(false);
        if (self::write_route('legacy') && self::rewrite(false)) {
            return true;
        }
        // We could not prove the legacy route. Keep the last installed gate
        // rule and its reader stopped, rather than risking static disclosure.
        self::write_route('fast');
        return false;
    }

    public static function disable($server_hint = null) {
        $original_server = $_SERVER['SERVER_SOFTWARE'] ?? null;
        if (defined('WP_CLI') && WP_CLI && $server_hint !== null) {
            if (!in_array($server_hint, ['apache','litespeed'], true)) {
                return self::error('unsupported_server');
            }
            $_SERVER['SERVER_SOFTWARE'] = $server_hint === 'apache' ?
                'Apache/2.4 (WP-CLI target)' : 'LiteSpeed (WP-CLI target)';
        }
        try {
            if (!self::route_fast() && !ESP_Fast_Gate_WP::active()) { return true; }
            if (!self::server_supported()) { return self::error('unsupported_server'); }
            if (!self::lock()) { return self::error('transition_locked'); }
            try {
                $db = ESP_Fast_Gate_WP::ledger(true);
                if (!$db || !$db->set_serving(false)) {
                    return self::error('cannot_stop_serving');
                }
                // Restore the legacy WP/PHP protection while gate is STOPPED.
                if (!self::write_route('legacy') || !self::rewrite(false)) {
                    self::write_route('fast');
                    return self::error('legacy_route_restore_failed');
                }
                if (!self::write_active(false)) {
                    return self::error('active_option_disable_failed');
                }
                return true;
            } finally {
                self::unlock();
            }
        } finally {
            if ($original_server === null) {
                unset($_SERVER['SERVER_SOFTWARE']);
            } else {
                $_SERVER['SERVER_SOFTWARE'] = $original_server;
            }
        }
    }

    public static function register_admin() {
        add_options_page('Fast Gate v2', 'Fast Gate', 'manage_options',
            'esp-fast-gate', [self::class,'render_admin']);
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        $db = ESP_Fast_Gate_WP::ledger(false);
        $state = self::route_fast() ? (ESP_Fast_Gate_WP::active() &&
            $db && $db->serving() ? 'running' : 'stopped') :
            (self::prepared() ? 'prepared' : 'unprepared');
        echo '<div class="wrap"><h1>Fast Gate v2</h1><p>';
        echo esc_html('State: ' . $state . '. Apache/LiteSpeed only. ');
        echo esc_html('The current WordPress media protection stays in place until explicit activation.');
        echo '</p>';
        $result = isset($_GET['esp_fg_result']) ?
            sanitize_key(wp_unslash($_GET['esp_fg_result'])) : '';
        if ($result !== '') {
            echo '<div class="notice notice-info"><p>' . esc_html($result) . '</p></div>';
        }
        foreach (['prepare'=>'Prepare ledger','enable'=>'Enable verified gate',
            'disable'=>'Disable / restore WordPress route'] as $action=>$label) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="esp_fast_gate_' .
                esc_attr($action) . '">';
            wp_nonce_field('esp_fast_gate_' . $action);
            submit_button($label, 'secondary');
            echo '</form>';
        }
        echo '</div>';
    }

    private static function admin_action($action) {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', '', ['response'=>403]); }
        check_admin_referer('esp_fast_gate_' . $action);
        if ($action === 'prepare') {
            $result = self::prepare();
        } elseif ($action === 'enable') {
            $result = self::enable();
        } else {
            $result = self::disable();
        }
        $message = $result === true ? 'success' :
            ($result instanceof WP_Error ? $result->get_error_code() : 'failed');
        wp_safe_redirect(add_query_arg('esp_fg_result', $message,
            admin_url('options-general.php?page=esp-fast-gate')));
        exit;
    }

    public static function admin_prepare() { self::admin_action('prepare'); }
    public static function admin_enable() { self::admin_action('enable'); }
    public static function admin_disable() { self::admin_action('disable'); }
}
