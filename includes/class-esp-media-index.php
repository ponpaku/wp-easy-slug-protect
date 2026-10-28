<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Issue #63: derived WordPress attachment file ownership / protection index.
 *
 * Source of truth: wp_posts + _wp_attached_file + _wp_attachment_metadata +
 * _esp_media_protected_path_id. The index is never the source of truth.
 *
 * Read-only path for requests; writing, installation and auditing are handled
 * separately. This is deliberately independent of #37's DB-free gate format.
 */
final class ESP_Media_Index {
    const ENABLE_OPTION = 'esp_media_index_v1_enabled';
    const READY_OPTION = 'esp_media_index_v1_ready';
    const SCHEMA_OPTION = 'esp_media_index_v1_schema';
    const CURSOR_OPTION = 'esp_media_index_v1_cursor';
    const BATCH_HOOK = 'esp_media_index_v1_batch';
    const BATCH_SIZE = 200;
    const MAX_METADATA_SIZES = 128;
    const MAX_PATHS = 160;
    const PROTECTION_META = '_esp_media_protected_path_id';
    private const KEYS = ['_wp_attached_file', '_wp_attachment_metadata', self::PROTECTION_META];
    private static $scheduled = false;
    private static $deleting = [];
    private static $by_mid = [];
    /** Pre-hooks whose metadata mutation did not produce a post-hook (e.g. no-op). */
    private static $local_pending = [];

    public static function files_table() {
        global $wpdb;
        return $wpdb->prefix . 'esp_media_files_v1';
    }
    public static function state_table() {
        global $wpdb;
        return $wpdb->prefix . 'esp_media_file_state_v1';
    }
    private static function table($table) {
        if (!is_string($table) || !preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
            throw new InvalidArgumentException('Invalid index table name');
        }
        return $table;
    }
    public static function enabled() {
        return (string)get_option(self::ENABLE_OPTION, '0') === '1';
    }
    public static function ready() {
        return (string)get_option(self::READY_OPTION, '0') === '1';
    }
    private static function installed() {
        return (string)get_option(self::SCHEMA_OPTION, '0') === '1';
    }

    public static function register() {
        foreach (['add_post_metadata', 'update_post_metadata', 'delete_post_metadata'] as $hook) {
            add_filter($hook, [self::class, 'guard_meta'], 5, 5);
        }
        add_filter('update_post_metadata_by_mid', [self::class, 'guard_by_mid'], 5, 4);
        add_filter('delete_post_metadata_by_mid', [self::class, 'guard_delete_by_mid'], 5, 2);
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [self::class, 'on_meta_saved'], 20, 4);
        }
        add_action('add_attachment', [self::class, 'on_attachment_created'], 20, 1);
        add_action('delete_attachment', [self::class, 'on_attachment_deleting'], 1, 1);
        add_action('deleted_post', [self::class, 'on_post_deleted'], 20, 1);
        add_filter('cron_schedules', [self::class, 'cron_intervals']);
        add_action(self::BATCH_HOOK, [self::class, 'run_batch']);
        add_action('init', [self::class, 'schedule'], 20);
        add_action('shutdown', [self::class, 'finish_noop_writes'], 1);
    }
    public static function cron_intervals($schedules) {
        $schedules['esp_media_index_five_minutes'] = [
            'interval' => 300, 'display' => 'ESP media index incremental audit'
        ];
        return $schedules;
    }
    public static function schedule() {
        if (self::$scheduled) { return; }
        self::$scheduled = true;
        if (!wp_next_scheduled(self::BATCH_HOOK)) {
            wp_schedule_event(time() + 30, 'esp_media_index_five_minutes', self::BATCH_HOOK);
        }
    }
    public static function unschedule() {
        wp_clear_scheduled_hook(self::BATCH_HOOK);
    }

    /**
     * One-time additive schema creation, independent of the plugin's existing
     * security migration and activation state. Never install during HTTP lookup.
     */
    public static function install_schema() {
        global $wpdb;
        if (!is_object($wpdb) || empty($wpdb->prefix)) { return false; }
        $files = self::table(self::files_table());
        $state = self::table(self::state_table());
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $files (
            path_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            file_path text NOT NULL,
            attachment_id bigint(20) unsigned NOT NULL,
            protected_path_id varchar(191) NOT NULL DEFAULT '',
            PRIMARY KEY  (path_hash,attachment_id),
            KEY attachment_id (attachment_id),
            KEY protected_path_id (protected_path_id)
        ) $charset;");
        dbDelta("CREATE TABLE $state (
            attachment_id bigint(20) unsigned NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'dirty',
            revision bigint(20) unsigned NOT NULL DEFAULT 0,
            pending_count int(11) unsigned NOT NULL DEFAULT 0,
            changed_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (attachment_id),
            KEY status (status)
        ) $charset;");
        foreach ([$files, $state] as $table) {
            $row = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($wpdb->last_error !== '' || $row !== $table) { return false; }
        }
        return update_option(self::SCHEMA_OPTION, '1', false) ||
            (string)get_option(self::SCHEMA_OPTION) === '1';
    }

    /** WordPress metadata paths are relative to the uploads root. */
    public static function valid_path($path) {
        if (!is_string($path) || $path === '' || $path[0] === '/' ||
            strpos($path, '\\') !== false || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part[0] === '.' || $part === '..') { return false; }
        }
        return true;
    }
    private static function valid_name($name) {
        return is_string($name) && $name !== '' && $name[0] !== '.' &&
            strpos($name, '/') === false && strpos($name, '\\') === false &&
            !preg_match('/[\x00-\x1f\x7f]/', $name);
    }
    /** No filename guessing. Invalid / overflowing metadata is an error, never public. */
    public static function enumerate($attached, $metadata) {
        if (!self::valid_path($attached)) { return null; }
        $paths = [$attached => true];
        if ($metadata === false || $metadata === null || $metadata === []) {
            return array_keys($paths);
        }
        if (!is_array($metadata)) { return null; }
        $base = $attached;
        if (isset($metadata['file'])) {
            if (!self::valid_path($metadata['file']) ||
                dirname($metadata['file']) !== dirname($attached)) {
                return null;
            }
            $base = $metadata['file'];
            $paths[$base] = true;
        }
        $dir = dirname($base);
        $prefix = $dir === '.' ? '' : $dir . '/';
        $sizes = $metadata['sizes'] ?? [];
        if (!is_array($sizes) || count($sizes) > self::MAX_METADATA_SIZES) {
            return null;
        }
        foreach ($sizes as $size) {
            if (!is_array($size) || !isset($size['file'])) { continue; }
            if (!self::valid_name($size['file'])) { return null; }
            $paths[$prefix . $size['file']] = true;
        }
        if (isset($metadata['file'])) {
            foreach (['original_image', 'source_image', 'animated_video', 'animated_video_poster'] as $key) {
                if (!isset($metadata[$key])) { continue; }
                $name = $metadata[$key];
                if (is_array($name)) { $name = $name['file'] ?? null; }
                if (!self::valid_name($name)) { return null; }
                $paths[$prefix . $name] = true;
            }
        }
        return count($paths) <= self::MAX_PATHS ? array_keys($paths) : null;
    }
    private static function relative_path($file) {
        if (!is_string($file) || $file === '' || strpos($file, "\0") !== false ||
            strpos($file, '\\') !== false) { return null; }
        $uploads = wp_upload_dir();
        if (!is_array($uploads) || !empty($uploads['error']) ||
            empty($uploads['basedir'])) { return null; }
        $root = realpath($uploads['basedir']);
        $real = realpath($file);
        if ($root === false || $real === false || !is_file($real)) { return null; }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp($real, $prefix, strlen($prefix)) !== 0) { return null; }
        // Reject nested symlinks (root symlink is supported).
        $given_root = rtrim((string)$uploads['basedir'], '/');
        $suffix = substr($real, strlen($prefix));
        $parts = explode('/', str_replace(DIRECTORY_SEPARATOR, '/', $suffix));
        $walk = $given_root;
        foreach ($parts as $part) {
            $walk .= '/' . $part;
            if (is_link($walk)) { return null; }
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $suffix);
        return self::valid_path($relative) ? $relative : null;
    }

    /**
     * A missing row is unresolved, not an authenticated/known-public decision.
     * A ready marker covers the completed initial scan but cannot attest to
     * direct SQL/FTP modifications that bypass WordPress hooks.
     */
    public static function resolve($file_path) {
        global $wpdb;
        if (!self::ready() || !self::installed() || !is_object($wpdb)) {
            return ['status' => 'error'];
        }
        $relative = self::relative_path($file_path);
        if ($relative === null) { return ['status' => 'error']; }
        $files = self::table(self::files_table());
        $state = self::table(self::state_table());
        $sql = $wpdb->prepare("SELECT f.file_path, f.attachment_id, f.protected_path_id,
                s.status, s.pending_count
            FROM $files f INNER JOIN $state s ON s.attachment_id=f.attachment_id
            WHERE f.path_hash=%s", hash('sha256', $relative));
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== '') { return ['status' => 'error']; }
        $owners = [];
        foreach ($rows as $row) {
            if (!is_array($row) ||
                !isset($row['file_path'], $row['attachment_id'],
                         $row['protected_path_id'], $row['status'], $row['pending_count']) ||
                !hash_equals((string)$row['file_path'], $relative) ||
                !ctype_digit((string)$row['attachment_id']) ||
                (int)$row['attachment_id'] <= 0 ||
                !is_string($row['protected_path_id']) ||
                $row['status'] !== 'ready' || (int)$row['pending_count'] !== 0) {
                return ['status' => 'error'];
            }
            $owners[(int)$row['attachment_id']] = $row['protected_path_id'];
        }
        if (count($owners) > 1) { return ['status' => 'ambiguous']; }
        if (!$owners) { return ['status' => 'unresolved']; }
        $id = (int)array_key_first($owners);
        return ['status' => 'resolved', 'attachment_id' => $id,
                'protected_path_id' => $owners[$id]];
    }

    private static function relevant($key) {
        return is_string($key) && in_array($key, self::KEYS, true);
    }
    /**
     * BEFORE a standard metadata mutation, persist dirty state. If this fails
     * under enabled mode, prevent WordPress's ordinary metadata write.
     * Existing authorization-cache guards run first at priority 1.
     */
    public static function guard_meta($check, $post_id, $meta_key, $meta_value, $extra = null) {
        if ($check !== null || !self::relevant($meta_key) || !self::installed()) { return $check; }
        // WordPress delete_metadata(..., delete_all=true) may mutate every post.
        // Without an exact finite affected set, do not admit it in strict mode.
        if ($extra === true) {
            return self::enabled() ? false : null;
        }
        if (get_post_type((int)$post_id) !== 'attachment') { return $check; }
        if (self::mark_dirty((int)$post_id)) {
            self::track_local((int)$post_id);
            return null;
        }
        return self::enabled() ? false : null;
    }
    public static function guard_by_mid($check, $meta_id, $meta_value, $meta_key = false) {
        if ($check !== null || !self::installed()) { return $check; }
        $old = get_metadata_by_mid('post', $meta_id);
        if (!is_object($old)) { return $check; }
        if (!self::relevant($old->meta_key) && !self::relevant($meta_key)) { return $check; }
        if (self::mark_dirty((int)$old->post_id)) {
            self::$by_mid[(int)$meta_id] = (int)$old->post_id;
            self::track_local((int)$old->post_id);
            return null;
        }
        return self::enabled() ? false : null;
    }
    public static function guard_delete_by_mid($check, $meta_id) {
        if ($check !== null || !self::installed()) { return $check; }
        $old = get_metadata_by_mid('post', $meta_id);
        if (!is_object($old) || !self::relevant($old->meta_key)) { return $check; }
        if (self::mark_dirty((int)$old->post_id)) {
            self::track_local((int)$old->post_id);
            return null;
        }
        return self::enabled() ? false : null;
    }
    public static function on_meta_saved($meta_id, $post_id, $meta_key, $meta_value) {
        if (!self::installed()) { return; }
        $was_by_mid = self::$by_mid[(int)$meta_id] ?? null;
        unset(self::$by_mid[(int)$meta_id]);
        if (!self::relevant($meta_key) && $was_by_mid === null) { return; }
        if (get_post_type((int)$post_id) !== 'attachment') { return; }
        self::finish_local((int)$post_id);
        self::complete_mutation((int)$post_id);
    }
    public static function on_attachment_created($id) {
        if (self::installed() && self::mark_dirty((int)$id)) {
            self::track_local((int)$id);
            self::finish_local((int)$id);
            self::complete_mutation((int)$id);
        }
    }
    public static function on_attachment_deleting($id) {
        if (self::installed() && self::mark_dirty((int)$id)) {
            self::$deleting[(int)$id] = true;
            self::track_local((int)$id);
        }
    }
    public static function on_post_deleted($id) {
        if (!isset(self::$deleting[(int)$id])) { return; }
        unset(self::$deleting[(int)$id]);
        self::finish_local((int)$id);
        self::complete_mutation((int)$id);
    }

    private static function track_local($id) {
        self::$local_pending[$id] = (self::$local_pending[$id] ?? 0) + 1;
    }
    private static function finish_local($id) {
        if (!empty(self::$local_pending[$id])) {
            --self::$local_pending[$id];
            if (self::$local_pending[$id] === 0) { unset(self::$local_pending[$id]); }
        }
    }
    /**
     * Core may return false for no-op metadata updates without emitting an
     * after-hook. Never leave those attachments in a perpetual pending state.
     * The originating PHP request is ending; another request's pending count
     * remains independently tracked in the DB.
     */
    public static function finish_noop_writes() {
        $pending = self::$local_pending;
        self::$local_pending = [];
        foreach ($pending as $id => $count) {
            for ($i = 0; $i < $count; ++$i) {
                self::complete_mutation((int)$id);
            }
        }
    }
    private static function mark_dirty($id) {
        global $wpdb;
        if ($id <= 0) { return false; }
        $table = self::table(self::state_table());
        $sql = $wpdb->prepare("INSERT INTO $table
          (attachment_id,status,revision,pending_count,changed_at)
          VALUES (%d,'dirty',1,1,%d)
          ON DUPLICATE KEY UPDATE status='dirty',
          revision=revision+1, pending_count=pending_count+1,changed_at=VALUES(changed_at)",
          $id, time());
        return $wpdb->query($sql) !== false && $wpdb->last_error === '';
    }
    private static function complete_mutation($id) {
        global $wpdb;
        if ($id <= 0) { return false; }
        $state = self::table(self::state_table());
        // This is also used for post-hooks where another plugin bypassed the
        // corresponding pre-hook: reindex rather than trusting old data.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status,pending_count FROM $state WHERE attachment_id=%d", $id), ARRAY_A);
        if ($wpdb->last_error !== '') { return false; }
        if (!$row) {
            if (!self::mark_dirty($id)) { return false; }
        } else if ((int)$row['pending_count'] === 0) {
            if (!self::mark_dirty($id)) { return false; }
        }
        if ($wpdb->query($wpdb->prepare("UPDATE $state SET pending_count=GREATEST(pending_count-1,0)
              WHERE attachment_id=%d", $id)) === false) { return false; }
        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT pending_count FROM $state WHERE attachment_id=%d", $id));
        if ($wpdb->last_error !== '' || $pending === null || (int)$pending !== 0) {
            return false;
        }
        return self::reindex_attachment($id);
    }

    /**
     * Build from direct DB reads (not stale WP object cache) while holding the
     * index-state row lock. Hook guards must mark this row dirty before WP writes.
     * Any crash leaves dirty records non-public; Cron retries after a grace period.
     */
    public static function reindex_attachment($id, $repair = false) {
        global $wpdb;
        if ($id <= 0 || !self::installed()) { return false; }
        $state = self::table(self::state_table());
        $files = self::table(self::files_table());
        if ($wpdb->query('START TRANSACTION') === false) { return false; }
        try {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT status,pending_count,changed_at FROM $state WHERE attachment_id=%d FOR UPDATE",
                $id), ARRAY_A);
            if ($wpdb->last_error !== '') { throw new RuntimeException('state read'); }
            if (!$row) {
                if ($wpdb->query($wpdb->prepare("INSERT INTO $state
                    (attachment_id,status,revision,pending_count,changed_at)
                    VALUES (%d,'dirty',1,0,%d)", $id, time())) === false) {
                    throw new RuntimeException('state init');
                }
                $row = ['status' => 'dirty', 'pending_count' => 0, 'changed_at' => 0];
            }
            if ((int)$row['pending_count'] !== 0) {
                throw new RuntimeException('mutation in progress');
            }
            $type = $wpdb->get_var($wpdb->prepare(
                "SELECT post_type FROM {$wpdb->posts} WHERE ID=%d", $id));
            if ($wpdb->last_error !== '') { throw new RuntimeException('post query'); }
            $paths = []; $protection = '';
            if ($type === 'attachment') {
                $values = $wpdb->get_results($wpdb->prepare(
                    "SELECT meta_key,meta_value FROM {$wpdb->postmeta}
                     WHERE post_id=%d AND meta_key IN (%s,%s,%s)
                     ORDER BY meta_id ASC",
                    $id, '_wp_attached_file', '_wp_attachment_metadata',
                    self::PROTECTION_META), ARRAY_A);
                if (!is_array($values) || $wpdb->last_error !== '') {
                    throw new RuntimeException('meta query');
                }
                $attached = null; $metadata = false;
                $seen = [];
                foreach ($values as $value) {
                    $key = $value['meta_key'];
                    if (isset($seen[$key])) { throw new RuntimeException('duplicate metadata'); }
                    $seen[$key] = true;
                    if ($key === '_wp_attached_file') { $attached = $value['meta_value']; }
                    if ($key === '_wp_attachment_metadata') {
                        $metadata = maybe_unserialize($value['meta_value']);
                    }
                    if ($key === self::PROTECTION_META) {
                        $protection = $value['meta_value'];
                    }
                }
                if (!is_string($protection) || strlen($protection) > 191 ||
                    !is_string($attached) || !self::valid_path($attached)) {
                    throw new RuntimeException('invalid attachment metadata');
                }
                $paths = self::enumerate($attached, $metadata);
                if (!is_array($paths) || !$paths) {
                    throw new RuntimeException('invalid derivative metadata');
                }
            }
            if ($wpdb->query($wpdb->prepare(
                "DELETE FROM $files WHERE attachment_id=%d", $id)) === false) {
                throw new RuntimeException('delete index rows');
            }
            foreach ($paths as $path) {
                $sql = $wpdb->prepare("INSERT INTO $files
                    (path_hash,file_path,attachment_id,protected_path_id)
                    VALUES (%s,%s,%d,%s)",
                    hash('sha256', $path), $path, $id, $protection);
                if ($wpdb->query($sql) === false) {
                    throw new RuntimeException('insert index row');
                }
            }
            if ($wpdb->query($wpdb->prepare(
                "UPDATE $state SET status='ready',changed_at=%d WHERE attachment_id=%d",
                time(), $id)) === false) {
                throw new RuntimeException('publish state');
            }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('commit'); }
            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            // A previously ready row might predate the failed rebuild.
            $wpdb->query($wpdb->prepare("UPDATE $state SET status='dirty',changed_at=%d
                WHERE attachment_id=%d", time(), $id));
            error_log('ESP: Media index reindex failed, attachment ' . $id);
            return false;
        }
    }

    /** Bounded incremental backfill and continuous audit, never HTTP request path. */
    public static function run_batch() {
        global $wpdb;
        // Never perform index schema changes or a rebuild while the plugin's
        // existing protection-state upgrade/verification has not completed.
        $runtime = get_option(ESP_Protection_State::RUNTIME_STATE_OPTION, null);
        if (!is_array($runtime) || ($runtime['state'] ?? '') !== 'READY' ||
            ($runtime['version'] ?? '') !== ESP_VERSION) { return; }
        if (!self::installed() && !self::install_schema()) {
            error_log('ESP: Media index schema install failed');
            return;
        }
        $cursor = max(0, (int)get_option(self::CURSOR_OPTION, 0));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'
             AND ID>%d ORDER BY ID ASC LIMIT %d", $cursor, self::BATCH_SIZE));
        if (!is_array($ids) || $wpdb->last_error !== '') { return; }
        if (!$ids) {
            $state = self::table(self::state_table());
            $missing = $wpdb->get_var("SELECT p.ID FROM {$wpdb->posts} p
                LEFT JOIN $state s ON p.ID=s.attachment_id
                WHERE p.post_type='attachment' AND (s.attachment_id IS NULL OR
                s.status<>'ready' OR s.pending_count<>0) LIMIT 1");
            if ($wpdb->last_error === '' && $missing === null) {
                update_option(self::READY_OPTION, '1', false);
            } else {
                update_option(self::READY_OPTION, '0', false);
            }
            update_option(self::CURSOR_OPTION, 0, false);
            return;
        }
        foreach ($ids as $id) {
            $id = (int)$id;
            $state = self::table(self::state_table());
            $current = $wpdb->get_row($wpdb->prepare(
                "SELECT status,pending_count,changed_at FROM $state WHERE attachment_id=%d",
                $id), ARRAY_A);
            if ($wpdb->last_error !== '') { return; }
            // Never let Cron claim an in-flight WP mutation as completed.
            if ($current && (int)$current['pending_count'] > 0 &&
                time() - (int)$current['changed_at'] < 900) {
                continue;
            }
            if ($current && (int)$current['pending_count'] > 0) {
                // Stuck mutation: must not silently reset pending and publish.
                // Operator intervention / explicit recovery is required.
                error_log('ESP: Media index pending mutation requires investigation: ' . $id);
                continue;
            }
            if (!self::reindex_attachment($id, true)) { return; }
            $cursor = $id;
        }
        update_option(self::CURSOR_OPTION, (int)end($ids), false);
    }
}
