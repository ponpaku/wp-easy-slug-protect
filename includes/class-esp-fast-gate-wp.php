<?php
/**
 * WordPress-side adapter for the Fast Gate v2 authorization protocol.
 *
 * Activation and Rewrite switching are handled explicitly by ESP_Fast_Gate_Deployment.
 * A future gate deployment must validate media coverage and HTTP routing.
 */
final class ESP_Fast_Gate_WP {
    public const ACTIVE_OPTION = 'esp_fast_gate_v2_active';
    private const KEY_FILE = 'keys.php';
    private const DB_FILE = 'gate-ledger.sqlite';
    private static $touched = [];

    public static function register() {
        add_filter('pre_update_option_' . ESP_Config::OPTION_KEY,
            [self::class, 'before_settings_save'], 5, 2);
        add_filter('pre_update_option_' . self::ACTIVE_OPTION,
            [self::class, 'before_active_change'], 5, 2);
        add_action('update_option_' . ESP_Config::OPTION_KEY,
            [self::class, 'after_settings_save'], 30, 2);
        // Share the existing 5-minute media-index Cron, no second scheduler.
        add_action(ESP_Media_Index::BATCH_HOOK,
            [self::class, 'maintenance'], 30);
        // Reuse the index's successful publication event; never trust dirty data.
        add_action('esp_media_index_published', [self::class, 'refresh_owner'], 10, 1);
        add_action('shutdown', [self::class, 'flush_modified_owners'], PHP_INT_MAX);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('esp gate-ledger', 'ESP_Fast_Gate_CLI');
        }
    }

    /**
     * No user-supplied storage path. The standalone gate derives the same
     * content-directory location from the standard plugin layout.
     */
    public static function storage_path() {
        if (!defined('WP_CONTENT_DIR') || !defined('ESP_PATH')) { return null; }
        $content = realpath(WP_CONTENT_DIR);
        $plugin_content = realpath(dirname(rtrim(ESP_PATH, '/'), 2));
        if (!$content || !$plugin_content || $content !== $plugin_content) {
            return null; // Nonstandard plugin layout is not auto-provisioned.
        }
        return $content . '/esp-fast-gate-v2';
    }

    public static function private_dir() {
        $expected = self::storage_path();
        if ($expected === null || is_link($expected) ||
            !is_dir($expected) || realpath($expected) !== $expected) {
            return null;
        }
        return $expected;
    }

    public static function active() {
        return (string)get_option(self::ACTIVE_OPTION, '0') === '1';
    }

    /**
     * Route installation precedes enabling the option. During that short
     * transition, media/password mutations still require SQLite fencing
     * even though the reader is deliberately stopped. No auth ticket is
     * issued until active() AND serving() have both been enabled.
     */
    private static function guarding() {
        return self::active() ||
            (class_exists('ESP_Fast_Gate_Deployment') &&
             ESP_Fast_Gate_Deployment::route_fast());
    }

    /**
     * OFF first revokes standalone serving. A rewrite that remains pointed
     * at the standalone script must not silently survive a disabled WP sync.
     * This hook intentionally does NOT install a fast-gate rewrite.
     */
    public static function before_active_change($new, $old) {
        if ((string)$old === '1' && (string)$new !== '1') {
            $db = self::ledger(true);
            if (!$db || !$db->set_serving(false)) {
                return $old;
            }
        }
        // An arbitrary WordPress option update is never a deployment action.
        // Only the verified transition controller may enable media/logout hooks.
        if ((string)$new === '1' &&
            (!class_exists('ESP_Fast_Gate_Deployment') ||
             !ESP_Fast_Gate_Deployment::allow_active_option_write())) {
            return $old;
        }
        return $new;
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
        // A direct HTTP PHP request only executes return; it prints no keys.
        $keys = require $file;
        if (!is_array($keys) ||
            ($keys['site'] ?? '') !== (string)get_current_blog_id() ||
            !is_string($keys['sig'] ?? null) || strlen($keys['sig']) < 32 ||
            !is_string($keys['id'] ?? null) || strlen($keys['id']) < 32) {
            return null;
        }
        return $keys;
    }

    public static function provision() {
        $dir = self::storage_path();
        if ($dir === null || !class_exists('SQLite3')) { return false; }
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0700) || !@chmod($dir, 0700)) { return false; }
        }
        if (self::private_dir() === null || !is_writable($dir)) { return false; }
        // Deny direct HTTP access to SQLite, WAL/SHM and even the PHP key.
        // If AllowOverride is disabled, gate activation must fail HTTP checks.
        $deny = $dir . '/.htaccess';
        $rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
        if (!is_file($deny)) {
            if (@file_put_contents($deny, $rules, LOCK_EX) !== strlen($rules)) {
                return false;
            }
        } elseif (@file_get_contents($deny) !== $rules) {
            return false; // Never overwrite administrator-modified rules.
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
        $php = "<?php\nreturn " . var_export($keys, true) . ";\n";
        $handle = @fopen($file, 'x');
        if (!$handle) { return false; }
        $written = false;
        try {
            if (@chmod($file, 0600) &&
                (fileperms($file) & 0777) === 0600) {
                $bytes = strlen($php);
                $offset = 0;
                while ($offset < $bytes) {
                    $n = fwrite($handle, substr($php, $offset));
                    if (!is_int($n) || $n <= 0) { break; }
                    $offset += $n;
                }
                $written = $offset === $bytes && fflush($handle);
            }
        } finally {
            fclose($handle);
        }
        if (!$written) { @unlink($file); return false; }
        $db = self::ledger(true);
        if (!$db || !$db->install()) {
            // No key regeneration after partial provisioning.
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
        $rows = $wpdb->get_results("SELECT f.file_path, f.attachment_id
            FROM $files f JOIN $state s ON s.attachment_id=f.attachment_id
            WHERE s.status='ready' AND s.pending_count=0
              AND f.generation=s.active_generation", ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== '') { return false; }

        $catalog = [];
        $owners = [];
        foreach ($rows as $record) {
            $file = $record['file_path'] ?? null;
            $id_owner = isset($record['attachment_id']) ? (int)$record['attachment_id'] : 0;
            if (!ESP_Media_Index::valid_path($file) || $id_owner <= 0) { return false; }
            $owners[$id_owner][] = $file;
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
        if (self::active() || $db->serving()) { return false; }
        if (!$db->replace_catalog($catalog, $owners) ||
            !$db->replace_paths($versions)) {
            return false;
        }
        $after = ESP_Media_Index::build_status(false);
        if (empty($after['ready']) || !empty($after['error'])) {
            return false;
        }
        // Ready is ONLY catalog completeness; serving remains OFF.
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
        if (!$keys || !$db || !$db->serving()) { return; }
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

    /**
     * Fast-Gate-specific pre-fence invoked by the EXISTING media-index guards.
     * New candidate paths protect collisions with another public owner.
     * Missing/invalid source evidence halts serving before the WP mutation.
     */
    public static function fence_media_change($id, $meta_key = null, $new_value = null) {
        if (!self::guarding()) { return true; }
        $db = self::ledger(true);
        if (!$db) { return false; }
        if (!$db->ready()) { return false; }
        $candidates = self::candidate_paths((int)$id, $meta_key, $new_value);
        if ($candidates === null) {
            return $db->set_serving(false);
        }
        if (!$db->fence_owner((int)$id, $candidates)) {
            return $db->set_serving(false);
        }
        self::$touched[(int)$id] = true;
        return true;
    }

    private static function candidate_paths($id, $meta_key, $new_value) {
        global $wpdb;
        if ($id < 1) { return null; }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key,meta_value FROM {$wpdb->postmeta}
             WHERE post_id=%d AND meta_key IN (%s,%s)
             ORDER BY meta_id ASC", $id, '_wp_attached_file',
            '_wp_attachment_metadata'), ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== '') { return null; }
        $attached = null; $metadata = false; $seen = [];
        foreach ($rows as $row) {
            if (!is_string($row['meta_key']) || isset($seen[$row['meta_key']])) {
                return null;
            }
            $seen[$row['meta_key']] = true;
            if ($row['meta_key'] === '_wp_attached_file') {
                $attached = $row['meta_value'];
            } elseif ($row['meta_key'] === '_wp_attachment_metadata') {
                $metadata = maybe_unserialize($row['meta_value']);
            }
        }
        if ($meta_key === '_wp_attached_file') { $attached = $new_value; }
        if ($meta_key === '_wp_attachment_metadata') {
            $metadata = is_string($new_value) ? maybe_unserialize($new_value) : $new_value;
        }
        return ESP_Media_Index::enumerate($attached, $metadata);
    }

    /** A missed/uncancellable guard must not leave the fast gate serving. */
    public static function halt_if_active() {
        if (!self::guarding()) { return true; }
        $db = self::ledger(true);
        return $db && $db->set_serving(false);
    }

    /**
     * Index has completed a successful owner generation. Publish only after
     * live MySQL ownership/protection checks. Fences stay until published.
     */
    public static function refresh_owner($id) {
        if (!self::guarding()) { return true; }
        global $wpdb;
        $id = (int)$id;
        $db = self::ledger(true);
        if (!$db || !$db->ready() || $id <= 0) { return false; }
        $epoch = $db->owner_epoch($id);
        $before = $db->owner_paths($id);
        if ($epoch === null || $before === null) { return false; }
        $files = ESP_Media_Index::files_table();
        $state = ESP_Media_Index::state_table();
        $current = $wpdb->get_col($wpdb->prepare(
            "SELECT f.file_path FROM $files f
             JOIN $state s ON s.attachment_id=f.attachment_id
             WHERE f.attachment_id=%d AND s.status='ready'
               AND s.pending_count=0 AND f.generation=s.active_generation",
            $id));
        if (!is_array($current) || $wpdb->last_error !== '') { return false; }
        $paths = array_unique(array_merge($before, $current));
        // Snapshot shared file generations BEFORE reading current MySQL
        // protection. A different attachment's fence/publish invalidates
        // this snapshot and cannot later be overwritten by our old result.
        $path_epochs = $db->path_epochs($paths);
        if ($path_epochs === null) { return false; }
        $uploads = wp_upload_dir(null, false);
        $root = realpath($uploads['basedir'] ?? '');
        $settings = ESP_Option::get_current_setting('path');
        if (!$root || !is_array($settings)) { return false; }
        $policies = [];
        foreach ($paths as $file) {
            if (!ESP_Media_Index::valid_path($file)) { return false; }
            $real = realpath($root . '/' . $file);
            if (!$real || !is_file($real)) {
                $policies[$file] = ['policy'=>'deny','path_id'=>''];
                continue;
            }
            $resolved = ESP_Media_Index::resolve($real);
            if ($resolved['status'] === 'error') { return false; }
            if ($resolved['status'] !== 'resolved') {
                $policies[$file] = ['policy'=>'deny','path_id'=>''];
                continue;
            }
            $path_id = (string)$resolved['protected_path_id'];
            $policies[$file] = $path_id !== '' && !isset($settings[$path_id])
                ? ['policy'=>'deny','path_id'=>'']
                : ['policy'=>($path_id === '' ? 'public' : 'protected'),
                   'path_id'=>$path_id];
        }
        return $db->publish_owner($id, $epoch, $policies, $current, $path_epochs);
    }

    /**
     * Opportunistically publish the touched owner before the request ends,
     * after the existing index has retired its pending writes. If it cannot
     * publish, the fence remains and the shared Cron retries via index.
     */
    public static function flush_modified_owners() {
        if (!self::guarding() || !self::$touched) { return; }
        $ids = array_keys(self::$touched);
        self::$touched = [];
        foreach ($ids as $id) {
            ESP_Media_Index::reindex_attachment((int)$id);
        }
    }

    /** Expired confirmed revocations are maintained with the media index Cron. */
    public static function maintenance() {
        if (!self::guarding()) { return; }
        $db = self::ledger(true);
        if (!$db || !$db->schema_ok()) {
            error_log('ESP Fast Gate: Cron ledger unavailable');
            return;
        }
        if (!$db->gc(time())) {
            error_log('ESP Fast Gate: Cron revocation maintenance failed');
        }
        // A PHP worker may terminate after fencing but before publishing.
        // Rebuild only pending owners; do not rescan all uploads each time.
        $pending = $db->pending_owners(25);
        if ($pending === null) {
            error_log('ESP Fast Gate: cannot read fenced owners');
            return;
        }
        foreach ($pending as $id) {
            ESP_Media_Index::reindex_attachment($id, true);
        }
    }

    /** Require path-scoped pre-revocation for password changes or path removal. */
    public static function before_settings_save($new, $old) {
        if (!self::guarding() || !is_array($new) || !is_array($old)) {
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
        if (!$db->block_paths($to_block)) {
            error_log('ESP Fast Gate: atomic pre-change authorization block failed');
            return $old;
        }
        return $new;
    }

    /** Re-enable only the generation saved in the WordPress settings. */
    public static function after_settings_save($old, $new) {
        if (!self::guarding() || !is_array($new)) { return; }
        $db = self::ledger(true);
        if (!$db || !$db->ready()) { return; }
        // Only changed generations and newly created paths are published.
        // An unrelated options save must NOT release another worker's
        // in-flight password-change block.
        $prior = is_array($old['path'] ?? null) ? $old['path'] : [];
        foreach ($new['path'] ?? [] as $id => $path) {
            $version = (int)($path['password_version'] ?? 0);
            if (isset($prior[$id]) &&
                (int)($prior[$id]['password_version'] ?? 0) === $version) {
                continue;
            }
            // A delayed callback must not publish obsolete settings.
            $current = ESP_Option::get_current_setting('path');
            if (!isset($current[$id]) ||
                (int)($current[$id]['password_version'] ?? 0) !== $version) {
                continue;
            }
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
            'serving' => $db ? $db->serving() : false,
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

    public function activate($args, $assoc_args) {
        $hint = $assoc_args['server'] ?? null;
        $result = ESP_Fast_Gate_Deployment::enable($hint);
        if ($result !== true) {
            \WP_CLI::error($result instanceof WP_Error ?
                $result->get_error_code() : 'esp_gate_activate_failed');
        }
        \WP_CLI::success('Fast Gate v2 verified and enabled.');
    }

    public function deactivate($args, $assoc_args) {
        $hint = $assoc_args['server'] ?? null;
        $result = ESP_Fast_Gate_Deployment::disable($hint);
        if ($result !== true) {
            \WP_CLI::error($result instanceof WP_Error ?
                $result->get_error_code() : 'esp_gate_deactivate_failed');
        }
        \WP_CLI::success('Fast Gate v2 stopped and legacy media route restored.');
    }

    public function gc($args, $assoc_args) {
        $db = ESP_Fast_Gate_WP::ledger(true);
        if (!$db || !$db->schema_ok() || !$db->gc(time())) {
            \WP_CLI::error('Cannot safely collect expired, confirmed revocations.');
        }
        \WP_CLI::success('Only confirmed and expired revocations removed.');
    }
}
