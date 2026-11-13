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
    private const LOCK_OPTION = 'esp_fast_gate_v2_deploy_lock';
    private const RECOVERY_AFTER_SECONDS = 900;
    private static $changing = false;
    private static $owned_lock = null;

    public static function register() {
        add_filter('pre_update_option_' . self::ROUTE_OPTION,
            [self::class, 'guard_route_option'], 5, 2);
        add_action('admin_post_esp_fast_gate_prepare', [self::class, 'admin_prepare']);
        add_action('admin_post_esp_fast_gate_enable', [self::class, 'admin_enable']);
        add_action('admin_post_esp_fast_gate_disable', [self::class, 'admin_disable']);
        add_action('admin_post_esp_fast_gate_recover', [self::class, 'admin_recover']);
        add_filter('pre_update_option_' . ESP_Config::OPTION_KEY,
            [self::class, 'guard_media_disable'], 4, 2);
        // Before #81's runtime UPDATING marker and WordPress file swap:
        // a live standalone reader must not survive code replacement while
        // the WordPress-side SQLite revocation bridge is unavailable.
        add_filter('upgrader_pre_install',
            [self::class, 'before_plugin_upgrade'], 5, 2);
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
        // READY establishes complete initial coverage, not the absence of
        // subsequently dirtied/deleted attachments. The SQLite export refuses
        // ANY unsettled owner. An explicit administrator prepare can first
        // perform one bounded repair batch (including orphan purging).
        $index_status = ESP_Media_Index::build_status(false);
        if (!empty($index_status['error'])) {
            return self::error('index_status_unavailable');
        }
        if (!empty($index_status['repairable_dirty'])) {
            ESP_Media_Index::run_batch();
        }
        // Recheck from SQL, not cached progress counts: pending writers,
        // backoff rows and a busy scan worker must not be silently omitted.
        global $wpdb;
        $state = ESP_Media_Index::state_table();
        $unfinished = $wpdb->get_var("SELECT attachment_id FROM $state
            WHERE attachment_id>0 AND (status<>'ready' OR pending_count<>0)
            LIMIT 1");
        if ($wpdb->last_error !== '' || $unfinished !== null) {
            return self::error('index_repair_pending');
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
        $root = realpath($uploads['basedir'] ?? '');
        if (!$root || !is_dir($root) || !is_string($base) ||
            !preg_match('~^https?://~i', $base)) {
            return false;
        }
        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (\Throwable $e) { return false; }
        $filename = 'esp-fast-gate-route-' . $nonce . '.jpg';
        $probe_file = $root . '/' . $filename;
        // An absent-file probe is insufficient: a reverse proxy may pass
        // 404s to Apache while delivering EXISTING uploads as static files.
        // A new non-secret physical file must still reach the signed
        // standalone PHP handler before serving can be enabled.
        $handle = @fopen($probe_file, 'x');
        if ($handle === false) { return false; }
        $probe_ok = false;
        $removed = false;
        try {
            $bytes = "ESP_ROUTE_PROBE\n";
            if (@fwrite($handle, $bytes) === strlen($bytes) &&
                @fflush($handle) && @fclose($handle)) {
                $handle = null;
                $proof = hash_hmac('sha256', 'route|' . $nonce, $keys['sig']);
                $url = rtrim($base,'/') . '/' . $filename;
                $response = wp_remote_get($url, [
                    'timeout'=>8, 'redirection'=>0, 'sslverify'=>true,
                    'headers'=>['X-ESP-Gate-Route-Probe'=>$nonce . ':' . $proof],
                ]);
                $probe_ok = !is_wp_error($response) &&
                    wp_remote_retrieve_response_code($response) === 204 &&
                    hash_equals(
                        hash_hmac('sha256', 'ack|' . $nonce, $keys['sig']),
                        (string)wp_remote_retrieve_header(
                            $response, 'X-ESP-Gate-Route-Ack'));
            }
        } catch (\Throwable $e) {
            $probe_ok = false;
        } finally {
            if (is_resource($handle)) { @fclose($handle); }
            $removed = @unlink($probe_file);
        }
        if (!$removed || !$probe_ok) { return false; }

        // Some servers intentionally conceal private resources with 404
        // instead of 403. Validate that this site's content URL reaches the
        // authenticated standalone handler, AND that an existing disposable
        // file inside the private directory cannot be retrieved. A bare 404
        // for the ledger alone proves neither the URL mapping nor denial.
        return self::private_http_probe($keys, $nonce);
    }

    private static function private_http_probe($keys, $nonce) {
        if (!defined('WP_CONTENT_DIR') || !defined('ESP_PATH')) { return false; }
        $content = realpath(WP_CONTENT_DIR);
        $gate = realpath(ESP_PATH . 'gate/fast-gate.php');
        $dir = ESP_Fast_Gate_WP::private_dir();
        if (!$content || !$gate || !$dir ||
            strncmp($gate, rtrim($content, '/') . '/', strlen(rtrim($content, '/') . '/')) !== 0 ||
            !is_file($dir . '/gate-ledger.sqlite') ||
            !is_file($dir . '/keys.php')) {
            return false;
        }
        // Positive control: use content_url() to reach the real, signed gate
        // entrypoint. This also checks the URL origin and content-root mapping
        // without requiring arbitrary write access to WP_CONTENT_DIR.
        $relative_gate = substr($gate, strlen(rtrim($content, '/') . '/'));
        $direct = content_url('/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative_gate));
        $proof = hash_hmac('sha256', 'route|' . $nonce, $keys['sig']);
        $request = [
            'timeout'=>8, 'redirection'=>0, 'sslverify'=>true,
            'limit_response_size'=>128,
            'headers'=>['X-ESP-Gate-Route-Probe'=>$nonce . ':' . $proof,
                        'Cache-Control'=>'no-cache'],
        ];
        $response = wp_remote_get($direct, $request);
        if (is_wp_error($response) ||
            wp_remote_retrieve_response_code($response) !== 204 ||
            !hash_equals(hash_hmac('sha256', 'ack|' . $nonce, $keys['sig']),
                (string)wp_remote_retrieve_header($response, 'X-ESP-Gate-Route-Ack'))) {
            return false;
        }

        // Negative control: a real, harmless file placed in the SAME private
        // directory as the ledger must be inaccessible via its public URL.
        // Test the ledger and keys too; 404 is accepted only alongside this
        // positive/negative control pair, never by itself.
        $marker_name = 'esp-gate-private-check-' . $nonce . '.txt';
        $marker = $dir . '/' . $marker_name;
        $handle = @fopen($marker, 'x');
        if ($handle === false) { return false; }
        $denied = false;
        $removed = false;
        try {
            $bytes = "ESP_PRIVATE_HTTP_CHECK\n";
            if (@fwrite($handle, $bytes) !== strlen($bytes) ||
                !@fflush($handle) || !@fclose($handle)) {
                return false;
            }
            $handle = null;
            $urls = [
                content_url('/esp-fast-gate-v2/' . $marker_name),
                content_url('/esp-fast-gate-v2/gate-ledger.sqlite'),
                content_url('/esp-fast-gate-v2/keys.php'),
            ];
            $denied = true;
            foreach ($urls as $url) {
                $private = wp_remote_get($url, [
                    'timeout'=>8, 'redirection'=>0, 'sslverify'=>true,
                    'limit_response_size'=>128,
                    'headers'=>['Cache-Control'=>'no-cache'],
                ]);
                $code = is_wp_error($private) ? 0 :
                    wp_remote_retrieve_response_code($private);
                if ($code !== 403 && $code !== 404) {
                    $denied = false;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $denied = false;
        } finally {
            if (is_resource($handle)) { @fclose($handle); }
            $removed = @unlink($marker);
        }
        return $removed && $denied;
    }

    private static function clear_lock_cache() {
        wp_cache_delete(self::LOCK_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
    }

    private static function lock() {
        // WordPress option names have a UNIQUE DB index; add_option is the
        // lock acquisition. The token also fences stale workers during
        // explicit administrator-led interruption recovery.
        $token = time() . ':' . wp_generate_uuid4();
        if (!add_option(self::LOCK_OPTION, $token, '', false)) {
            return false;
        }
        self::$owned_lock = $token;
        return true;
    }

    private static function owns_lock() {
        global $wpdb;
        if (!is_string(self::$owned_lock) || !is_object($wpdb)) {
            return false;
        }
        $stored = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
            self::LOCK_OPTION));
        return $wpdb->last_error === '' &&
            is_string($stored) && hash_equals(self::$owned_lock, $stored);
    }

    private static function unlock() {
        global $wpdb;
        if (self::owns_lock()) {
            // An old worker must not delete a newer recovery worker's token.
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name=%s AND option_value=%s",
                self::LOCK_OPTION, self::$owned_lock));
            self::clear_lock_cache();
        }
        self::$owned_lock = null;
    }

    /**
     * Explicit recovery of a crashed PHP request. Never auto-steal the
     * deployment lock; require 15m minimum age and admin/CLI invocation.
     * Keep serving off until a verified legacy rewrite is back in place.
     */
    public static function recover($server_hint = null) {
        $previous = $_SERVER['SERVER_SOFTWARE'] ?? null;
        if (defined('WP_CLI') && WP_CLI && $server_hint !== null) {
            if (!in_array($server_hint, ['apache','litespeed'], true)) {
                return self::error('unsupported_server');
            }
            $_SERVER['SERVER_SOFTWARE'] = $server_hint === 'apache'
                ? 'Apache/2.4 (WP-CLI target)' : 'LiteSpeed (WP-CLI target)';
        }
        try {
            if (!self::server_supported()) {
                return self::error('unsupported_server');
            }
            global $wpdb;
            $old = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
                self::LOCK_OPTION));
            if (!is_string($old) ||
                !preg_match('/^([0-9]{10,}):[a-fA-F0-9-]{36}$/D', $old, $parts)) {
                return self::error('no_recoverable_lock');
            }
            $age = time() - (int)$parts[1];
            if ($age < self::RECOVERY_AFTER_SECONDS) {
                return self::error('transition_still_recent');
            }
            $new = time() . ':' . wp_generate_uuid4();
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value=%s
                 WHERE option_name=%s AND option_value=%s",
                $new, self::LOCK_OPTION, $old));
            if ($claimed !== 1) { return self::error('recovery_claim_failed'); }
            self::$owned_lock = $new;
            self::clear_lock_cache();
            try {
                $db = ESP_Fast_Gate_WP::ledger(true);
                if (!$db || !$db->set_serving(false)) {
                    return self::error('recovery_cannot_stop_serving');
                }
                if (!self::write_route('legacy') || !self::rewrite(false)) {
                    self::write_route('fast');
                    return self::error('recovery_legacy_route_failed');
                }
                if (!self::write_active(false)) {
                    return self::error('recovery_active_option_failed');
                }
                return true;
            } finally {
                self::unlock();
            }
        } finally {
            if ($previous === null) {
                unset($_SERVER['SERVER_SOFTWARE']);
            } else {
                $_SERVER['SERVER_SOFTWARE'] = $previous;
            }
        }
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
                if (!self::owns_lock()) { return self::error('transition_lock_lost'); }
                // Persist the configured same-origin WordPress bridge route
                // before installing the Fast Gate; never infer it from Host.
                $bridge_path = ESP_Fast_Gate_WP::bridge_path_config();
                if (!ESP_Fast_Gate_Handoff::crypto_available()) {
                    return self::error('bridge_crypto_unavailable');
                }
                if ($bridge_path === null || !$db->set_bridge_path($bridge_path)) {
                    return self::error('bridge_route_unsupported');
                }
                // 1. Install fail-closed route: serving=0 -> 503.
                $installed = self::write_route('fast') && self::rewrite(true);
                if (!self::owns_lock()) { return self::error('transition_lock_lost'); }
                if (!$installed) {
                    self::rollback_enable($db);
                    return self::error('route_install_failed');
                }
                // 2. Verify the REAL HTTP handler and private directory denial.
                $http_ok = self::http_probe($keys);
                if (!self::owns_lock()) { return self::error('transition_lock_lost'); }
                if (!$http_ok) {
                    self::rollback_enable($db);
                    return self::error('http_probe_failed');
                }
                // 3. Last full reconciliation under the non-serving route.
                // During the rebuild, media/password writes are fenced or
                // rejected by the existing WP index bridge even though the
                // user-facing active flag is not yet set.
                $catalog_ok = ESP_Fast_Gate_WP::build_catalog() &&
                    self::prepared();
                if (!self::owns_lock()) { return self::error('transition_lock_lost'); }
                if (!$catalog_ok) {
                    self::rollback_enable($db);
                    return self::error('catalog_recheck_failed');
                }
                // 4. Every re-activation must invalidate tickets copied
                // before a legacy-mode logout. Legacy WP logout does not
                // append SQLite revocations while Fast Gate is disabled.
                $rotated = $db->rotate_ticket_signing_key();
                if (!self::owns_lock()) { return self::error('transition_lock_lost'); }
                if (!$rotated) {
                    self::rollback_enable($db);
                    return self::error('ticket_generation_failed');
                }
                // 5. Start WP mutation/logout synchronization before serving.
                $active_ok = self::write_active(true);
                if (!self::owns_lock()) { return self::error('transition_lock_lost'); }
                if (!$active_ok) {
                    self::rollback_enable($db);
                    return self::error('active_option_failed');
                }
                // 6. Only now permit the standalone reader to serve media.
                if (!self::owns_lock()) { return self::error('transition_lock_lost'); }
                if (!$db->set_serving(true)) {
                    self::rollback_enable($db);
                    return self::error('serving_start_failed');
                }
                if (!self::owns_lock()) {
                    $db->set_serving(false);
                    return self::error('transition_lock_lost');
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
            $existing = ESP_Fast_Gate_WP::ledger(false);
            if (!self::route_fast() && !ESP_Fast_Gate_WP::active() &&
                (!$existing || !$existing->serving())) { return true; }
            if (!self::server_supported()) { return self::error('unsupported_server'); }
            if (!self::lock()) { return self::error('transition_locked'); }
            try {
                if (!self::owns_lock()) { return self::error('transition_lock_lost'); }
                $db = ESP_Fast_Gate_WP::ledger(true);
                if (!$db || !$db->set_serving(false)) {
                    return self::error('cannot_stop_serving');
                }
                if (!self::owns_lock()) { return self::error('transition_lock_lost'); }
                // Restore the legacy WP/PHP protection while gate is STOPPED.
                $restored = self::write_route('legacy') && self::rewrite(false);
                if (!self::owns_lock()) { return self::error('transition_lock_lost'); }
                if (!$restored) {
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

    /**
     * Keep already-issued tickets and SQLite authorization synchronized
     * across plugin code updates. A supported HTTP updater transparently
     * restores the existing WordPress/PHP media route before the files
     * change. Unknown/serverless updater contexts refuse the install until
     * an administrator disables Fast Gate from a verified HTTP context.
     */
    public static function before_plugin_upgrade($response, $extra) {
        if (is_wp_error($response) || !is_array($extra) ||
            (isset($extra['type']) && $extra['type'] !== 'plugin') ||
            (isset($extra['action']) && $extra['action'] !== 'update') ||
            ($extra['plugin'] ?? null) !==
                plugin_basename(ESP_PATH . 'easy-slug-protect.php')) {
            return $response;
        }
        $db = ESP_Fast_Gate_WP::ledger(false);
        $serving = $db && $db->serving();
        if (!self::route_fast() && !ESP_Fast_Gate_WP::active() && !$serving) {
            return $response;
        }
        if (!self::server_supported()) {
            return new WP_Error('esp_gate_upgrade_needs_http_disable',
                '高速ゲートを管理画面から無効化した後、プラグインの更新を再実行してください。');
        }
        if (self::disable() !== true) {
            return new WP_Error('esp_gate_upgrade_legacy_restore_failed',
                '従来のメディア配信経路へ安全に切り替えられないため、プラグインの更新を中止しました。');
        }
        return $response;
    }

    /**
     * Part of the primary Easy Slug Protect settings page, intentionally
     * OUTSIDE its options.php form: each deployment action posts separately
     * with its own nonce and capability check.
     */
    public static function render_settings_section() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        $db = ESP_Fast_Gate_WP::ledger(false);
        $state = self::route_fast() ? (ESP_Fast_Gate_WP::active() &&
            $db && $db->serving() ? 'running' : 'stopped') :
            (self::prepared() ? 'prepared' : 'unprepared');
        echo '<div class="esp-section" id="esp-fast-gate"><h2>高速ゲート（Fast Gate v2）</h2><p>';
        $states = [
            'running'=>'稼働中',
            'stopped'=>'停止中（配信を拒否しています）',
            'prepared'=>'準備完了（従来方式で配信中）',
            'unprepared'=>'未準備（従来方式で配信中）',
        ];
        echo esc_html('状態: ' . ($states[$state] ?? $state) .
            '。Apache / LiteSpeed 専用。');
        echo '</p><p>準備操作だけでは配信方式は変わりません。高速ゲートの有効化時に実HTTPで検証します。</p>';
        $result = isset($_GET['esp_fg_result']) ?
            sanitize_key(wp_unslash($_GET['esp_fg_result'])) : '';
        if ($result !== '') {
            $succeeded = $result === 'success';
            $notice = $succeeded ? 'notice-success' : 'notice-error';
            $message = $succeeded ? '操作が完了しました。' :
                '操作に失敗しました。エラーコード: ' . $result;
            echo '<div class="notice ' . esc_attr($notice) .
                ' inline" role="status"><p>' . esc_html($message) . '</p></div>';
        }
        $actions = [
            'prepare'=>'台帳を準備する',
            'enable'=>'高速ゲートを有効化する（HTTP検証あり）',
            'disable'=>'高速ゲートを無効化して従来方式に戻す',
        ];
        if (get_option(self::LOCK_OPTION, false) !== false) {
            echo '<p>切替処理が中断された場合のみ、進行中の処理がないことを確認して復旧してください。15分未満のロックは解除しません。</p>';
            $actions['recover'] = '中断した切替を復旧する';
        }
        foreach ($actions as $action=>$label) {
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
        } elseif ($action === 'recover') {
            $result = self::recover();
        } else {
            $result = self::disable();
        }
        $message = $result === true ? 'success' :
            ($result instanceof WP_Error ? $result->get_error_code() : 'failed');
        wp_safe_redirect(add_query_arg('esp_fg_result', $message,
            admin_url('admin.php?page=esp-settings')) . '#esp-fast-gate');
        exit;
    }

    public static function admin_prepare() { self::admin_action('prepare'); }
    public static function admin_enable() { self::admin_action('enable'); }
    public static function admin_disable() { self::admin_action('disable'); }
    public static function admin_recover() { self::admin_action('recover'); }
}
