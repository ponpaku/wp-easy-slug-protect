<?php
/**
 * WordPress-side adapter for the Fast Gate v2 authorization protocol.
 *
 * IMPORTANT: no automatic activation or Rewrite changes in this review slice.
 * A future gate deployment must validate media coverage and HTTP routing.
 */
final class ESP_Fast_Gate_WP {
    public const ACTIVE_OPTION = 'esp_fast_gate_v2_active';
    private const KEY_FILE = 'gate-keys.json';
    private const DB_FILE = 'gate-ledger.sqlite';

    public static function register() {
        add_filter('pre_update_option_' . ESP_Config::OPTION_KEY,
            [self::class, 'before_settings_save'], 5, 2);
        add_action('update_option_' . ESP_Config::OPTION_KEY,
            [self::class, 'after_settings_save'], 30, 2);
        // Share the existing 5-minute media-index Cron, no second scheduler.
        add_action(ESP_Media_Index::BATCH_HOOK,
            [self::class, 'maintenance'], 30);
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('esp gate-ledger', 'ESP_Fast_Gate_CLI');
        }
    }

    /** Only administrator-selected, non-web-accessible directories are valid. */
    public static function private_dir() {
        if (!defined('ESP_GATE_PRIVATE_DIR') ||
            !is_string(ESP_GATE_PRIVATE_DIR) || ESP_GATE_PRIVATE_DIR === '') {
            return null;
        }
        $dir = realpath(ESP_GATE_PRIVATE_DIR);
        $docroot = realpath(ABSPATH);
        $content = defined('WP_CONTENT_DIR') ? realpath(WP_CONTENT_DIR) : false;
        if ($dir === false || $docroot === false || !is_dir($dir) ||
            is_link(ESP_GATE_PRIVATE_DIR)) {
            return null;
        }
        foreach ([$docroot, $content] as $forbidden) {
            if (is_string($forbidden) &&
                ($dir === $forbidden ||
                 strpos($dir . '/', rtrim($forbidden, '/') . '/') === 0)) {
                return null;
            }
        }
        return $dir;
    }

    public static function active() {
        return (string)get_option(self::ACTIVE_OPTION, '0') === '1';
    }

    public static function ledger($write = false) {
        $dir = self::private_dir();
        return $dir === null ? null :
            ESP_Fast_Gate_Store::open($dir . '/' . self::DB_FILE, $write);
    }

    private static function keys() {
        $dir = self::private_dir();
        if ($dir === null) { return null; }
        $file = $dir . '/' . self::KEY_FILE;
        if (!is_file($file) || is_link($file) || !is_readable($file)) {
            return null;
        }
        $keys = json_decode((string)@file_get_contents($file), true);
        if (!is_array($keys) ||
            ($keys['site'] ?? '') !== (string)get_current_blog_id() ||
            !is_string($keys['sig'] ?? null) || strlen($keys['sig']) < 32 ||
            !is_string($keys['id'] ?? null) || strlen($keys['id']) < 32) {
            return null;
        }
        return $keys;
    }

    public static function provision() {
        $dir = self::private_dir();
        if ($dir === null || !is_writable($dir) ||
            !class_exists('SQLite3')) {
            return false;
        }
        $file = $dir . '/' . self::KEY_FILE;
        if (file_exists($file) || is_link($file)) { return false; }
        $uploads = wp_upload_dir();
        $root = realpath($uploads['basedir'] ?? '');
        $prefix = isset($uploads['baseurl'])
            ? parse_url($uploads['baseurl'], PHP_URL_PATH) : null;
        if (!$root || !$prefix || !is_dir($root)) { return false; }
        $keys = [
            'site' => (string)get_current_blog_id(),
            'sig' => bin2hex(random_bytes(32)),
            'id' => bin2hex(random_bytes(32)),
            'root' => $root,
            'prefix' => rtrim($prefix, '/') . '/',
        ];
        $handle = @fopen($file, 'x');
        if (!$handle) { return false; }
        @chmod($file, 0600);
        try {
            $written = fwrite($handle, wp_json_encode($keys));
            if ($written === false || $written < 1 || !fflush($handle)) {
                @unlink($file);
                return false;
            }
        } finally {
            fclose($handle);
        }
        $db = self::ledger(true);
        if (!$db || !$db->install()) {
            // Do not silently regenerate a key after partial provisioning:
            // an operator must inspect and explicitly reset the state.
            return false;
        }
        return true;
    }

    /**
     * Explicit initial catalog build. Cross-check each indexed owner against
     * ESP_Media_Index::resolve(), which also reads current MySQL postmeta.
     * The build is intentionally NOT an activation step.
     *
     * Future work: incremental mutation replication and strict routing tests.
     */
    public static function build_catalog() {
        global $wpdb;
        if (!ESP_Media_Index::enabled() || !ESP_Media_Index::ready() ||
            !is_object($wpdb)) {
            return false;
        }
        $status = ESP_Media_Index::build_status(false);
        if (empty($status['ready']) || !empty($status['error'])) {
            return false;
        }
        $db = self::ledger(true);
        $keys = self::keys();
        if (!$db || !$keys || !$db->schema_ok() || !$db->set_ready(false)) {
            return false;
        }

        $uploads = wp_upload_dir(null, false);
        $root = realpath($uploads['basedir'] ?? '');
        $prefix = isset($uploads['baseurl'])
            ? parse_url($uploads['baseurl'], PHP_URL_PATH) : null;
        if (!$root || $root !== ($keys['root'] ?? null) ||
            rtrim((string)$prefix, '/') . '/' !== ($keys['prefix'] ?? null)) {
            return false;
        }

        $files = ESP_Media_Index::files_table();
        $state = ESP_Media_Index::state_table();
        $paths = ESP_Option::get_current_setting('path');
        if (!is_array($paths)) { return false; }

        // A stale/dirty owner is never silently excluded from an otherwise
        // "ready" export: require the index to be settled before building.
        $unstable = $wpdb->get_var("SELECT COUNT(*) FROM $state
            WHERE attachment_id>0 AND (status<>'ready' OR pending_count<>0)");
        if ($unstable === null || $wpdb->last_error !== '' || (int)$unstable > 0) {
            return false;
        }
        $rows = $wpdb->get_col("SELECT DISTINCT f.file_path
            FROM $files f JOIN $state s ON s.attachment_id=f.attachment_id
            WHERE s.status='ready' AND s.pending_count=0
              AND f.generation=s.active_generation");
        if (!is_array($rows) || $wpdb->last_error !== '') { return false; }

        $catalog = [];
        foreach ($rows as $file) {
            if (!ESP_Media_Index::valid_path($file)) { return false; }
            $result = ESP_Media_Index::resolve($root . '/' . $file);
            if ($result['status'] === 'error') { return false; }
            if ($result['status'] === 'ambiguous' ||
                $result['status'] === 'unresolved') {
                $catalog[$file] = ['policy' => 'deny', 'path_id' => ''];
                continue;
            }
            if ($result['status'] !== 'resolved') { return false; }
            $id = (string)$result['protected_path_id'];
            if ($id !== '' && !isset($paths[$id])) {
                $catalog[$file] = ['policy' => 'deny', 'path_id' => ''];
            } else {
                $catalog[$file] = [
                    'policy' => $id === '' ? 'public' : 'protected',
                    'path_id' => $id,
                ];
            }
        }

        $versions = [];
        foreach ($paths as $id => $item) {
            if (!is_array($item) || !isset($item['password_version'])) {
                return false;
            }
            $versions[(string)$id] = (int)$item['password_version'];
        }
        // These operations disable the gate until the final explicit READY.
        if (!$db->replace_media($catalog) || !$db->replace_paths($versions)) {
            return false;
        }
        $after = ESP_Media_Index::build_status(false);
        if (empty($after['ready']) || !empty($after['error'])) {
            return false;
        }
        return $db->set_ready(true);
    }

    /**
     * Only issue tickets after a MySQL-backed authorization has succeeded.
     * Failure to issue does not change ordinary WordPress login behavior.
     */
    public static function issue_cookie($path, $kind, $user, $token, $version, $expires) {
        if (!self::active()) { return; }
        $keys = self::keys();
        $db = self::ledger(false);
        if (!$keys || !$db || !$db->ready()) { return; }
        $p = $db->get_path((string)$path);
        if (!$p || $p['blocked'] || $p['version'] !== (int)$version) {
            return;
        }
        $ref = ESP_Fast_Gate_Token::session_ref($keys['site'],
            (string)$path, $kind, (string)$user, (string)$token, $keys['id']);
        if (!$ref || $db->is_revoked($ref) !== false) { return; }
        $ticket = ESP_Fast_Gate_Token::issue(
            $keys['site'], (string)$path, $kind, (string)$user, (string)$token,
            (int)$version, (int)$expires, $keys['sig'], $keys['id']
        );
        if ($ticket !== null) {
            ESP_Cookie::get_instance()->queue_gate_ticket((string)$path, $ticket,
                min((int)$expires, time() + ESP_Fast_Gate_Token::MAX_AGE));
        }
    }

    /**
     * Persist every present login kind before any MySQL deletion.
     * Returns an associative map of kind -> revocation ID, or false on failure.
     */
    public static function begin_logout($path, $session, $remember) {
        if (!self::active()) { return []; }
        $keys = self::keys();
        $db = self::ledger(true);
        if (!$keys || !$db || !$db->ready()) { return false; }
        $refs = [];
        if (is_string($session) && $session !== '') {
            $refs['session'] = ESP_Fast_Gate_Token::session_ref(
                $keys['site'], (string)$path, 'session', '', $session, $keys['id']);
        }
        if (is_array($remember) && isset($remember['id'], $remember['token'])) {
            $refs['remember'] = ESP_Fast_Gate_Token::session_ref(
                $keys['site'], (string)$path, 'remember',
                (string)$remember['id'], (string)$remember['token'], $keys['id']);
        }
        if (in_array(null, $refs, true) ||
            !$db->revoke_many(array_values($refs), time())) {
            return false;
        }
        return $refs;
    }

    public static function confirm_logout($ref) {
        if (!self::active() || $ref === null) { return; }
        $db = self::ledger(true);
        if (!$db || !$db->confirm_deleted($ref)) {
            error_log('ESP Fast Gate: could not confirm revoked session deletion');
        }
    }

    /** Expired confirmed revocations are maintained with the media index Cron. */
    public static function maintenance() {
        if (!self::active()) { return; }
        $db = self::ledger(true);
        if (!$db || !$db->schema_ok() || !$db->gc(time())) {
            error_log('ESP Fast Gate: Cron revocation maintenance failed');
        }
    }

    /** Require path-scoped pre-revocation for password changes or path removal. */
    public static function before_settings_save($new, $old) {
        if (!self::active() || !is_array($new) || !is_array($old)) {
            return $new;
        }
        $old_paths = $old['path'] ?? [];
        $new_paths = $new['path'] ?? [];
        if (!is_array($old_paths) || !is_array($new_paths)) { return $old; }
        $to_block = [];
        foreach ($old_paths as $id => $old_path) {
            if (!isset($new_paths[$id]) ||
                (int)($old_path['password_version'] ?? 0) !==
                (int)($new_paths[$id]['password_version'] ?? 0)) {
                $to_block[] = (string)$id;
            }
        }
        if (!$to_block) { return $new; }
        $db = self::ledger(true);
        if (!$db || !$db->ready()) { return $old; }
        foreach ($to_block as $id) {
            if (!$db->block_path($id)) {
                error_log('ESP Fast Gate: pre-change authorization block failed');
                return $old;
            }
        }
        return $new;
    }

    /** Re-enable only the generation saved in the WordPress settings. */
    public static function after_settings_save($old, $new) {
        if (!self::active() || !is_array($new)) { return; }
        $db = self::ledger(true);
        if (!$db || !$db->ready()) { return; }
        foreach ($new['path'] ?? [] as $id => $path) {
            $version = (int)($path['password_version'] ?? 0);
            if (!$db->set_path((string)$id, $version)) {
                $db->set_ready(false);
                error_log('ESP Fast Gate: protected path state sync failed');
                return;
            }
        }
    }
}

class ESP_Fast_Gate_CLI {
    public function status($args, $assoc_args) {
        $dir = ESP_Fast_Gate_WP::private_dir();
        $db = ESP_Fast_Gate_WP::ledger(false);
        \WP_CLI::line(wp_json_encode([
            'configured' => $dir !== null,
            'sqlite_available' => class_exists('SQLite3'),
            'ledger_exists' => $db !== null,
            'schema_ok' => $db ? $db->schema_ok() : false,
            'ready' => $db ? $db->ready() : false,
            'active' => ESP_Fast_Gate_WP::active(),
            'counts' => $db ? $db->counts() : null,
        ]));
    }

    /** Initial provision only. Never silently overwrite existing keys. */
    public function provision($args, $assoc_args) {
        if (!ESP_Fast_Gate_WP::provision()) {
            \WP_CLI::error('Provisioning failed: configure a private directory, ' .
                'check SQLite3 and ensure the key file does not already exist.');
        }
        \WP_CLI::success('Fast Gate private keys and empty ledger created; ' .
            'gate is not ready or active.');
    }

    public function build($args, $assoc_args) {
        if (!ESP_Fast_Gate_WP::build_catalog()) {
            \WP_CLI::error('Catalog build failed. Check strict media index readiness, ' .
                'live metadata, private keys, and uploads configuration.');
        }
        \WP_CLI::success('Verified media catalog built. Fast Gate is still inactive.');
    }

    public function gc($args, $assoc_args) {
        $db = ESP_Fast_Gate_WP::ledger(true);
        if (!$db || !$db->schema_ok() || !$db->gc(time())) {
            \WP_CLI::error('Cannot safely collect expired, confirmed revocations.');
        }
        \WP_CLI::success('Only confirmed and expired revocations removed.');
    }
}
