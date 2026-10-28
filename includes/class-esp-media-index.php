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
        // The fifth argument differs across these three WordPress hooks.
        add_filter('add_post_metadata', [self::class, 'guard_add_meta'], 5, 5);
        add_filter('update_post_metadata', [self::class, 'guard_update_meta'], 5, 5);
        add_filter('delete_post_metadata', [self::class, 'guard_delete_meta'], 5, 5);
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
        // Reject nested symlinks BEFORE realpath() erases link components.
        // The uploads root itself may be a symlink and remains supported.
        $given_root = rtrim((string)$uploads['basedir'], '/');
        $suffix = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($prefix)));
        $original_prefix = rtrim($given_root, '/') . '/';
        $canonical_prefix = rtrim($root, '/') . '/';
        if (strncmp($file, $original_prefix, strlen($original_prefix)) === 0) {
            $original_suffix = substr($file, strlen($original_prefix));
            $walk = $given_root;
        } elseif (strncmp($file, $canonical_prefix, strlen($canonical_prefix)) === 0) {
            $original_suffix = substr($file, strlen($canonical_prefix));
            $walk = $root;
        } else {
            return null;
        }
        if ($original_suffix !== $suffix || !self::valid_path($original_suffix)) {
            return null; // Alias, traversal, changed capitalization or alternate inode.
        }
        foreach (explode('/', $original_suffix) as $part) {
            $walk .= '/' . $part;
            if (is_link($walk)) { return null; }
        }
        $relative = $suffix;
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
        // Read the WordPress protection meta in the SAME indexed SQL roundtrip
        // and reject disagreement with our materialized ID. This catches some
        // bypassed index updates and duplicate protection meta records without
        // a second post-ID lookup or trusting an unverified cached permission.
        $sql = $wpdb->prepare("SELECT f.file_path, f.attachment_id, f.protected_path_id,
                s.status, s.pending_count, pm.meta_value AS live_protected_path
            FROM $files f INNER JOIN $state s ON s.attachment_id=f.attachment_id
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=f.attachment_id
                AND pm.meta_key=%s
            WHERE f.path_hash=%s",
            self::PROTECTION_META, hash('sha256', $relative));
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== '') { return ['status' => 'error']; }
        $owners = [];
        foreach ($rows as $row) {
            if (!is_array($row) ||
                !isset($row['file_path'], $row['attachment_id'],
                         $row['protected_path_id'], $row['status'], $row['pending_count']) ||
                !array_key_exists('live_protected_path', $row) ||
                !hash_equals((string)$row['file_path'], $relative) ||
                !ctype_digit((string)$row['attachment_id']) ||
                (int)$row['attachment_id'] <= 0 ||
                !is_string($row['protected_path_id']) ||
                ($row['live_protected_path'] !== null &&
                 !is_string($row['live_protected_path'])) ||
                $row['protected_path_id'] !== (string)($row['live_protected_path'] ?? '') ||
                $row['status'] !== 'ready' || (int)$row['pending_count'] !== 0) {
                return ['status' => 'error'];
            }
            // A duplicate postmeta row can yield the same owner twice.
            $id = (int)$row['attachment_id'];
            if (array_key_exists($id, $owners)) { return ['status' => 'error']; }
            $owners[$id] = $row['protected_path_id'];
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
    /** The fifth argument of add_post_metadata is unique, not delete_all. */
    public static function guard_add_meta($check, $post_id, $meta_key, $value, $unique = false) {
        return self::guard_meta($check, $post_id, $meta_key, $value);
    }
    /** The fifth argument of update_post_metadata is prev_value. */
    public static function guard_update_meta($check, $post_id, $meta_key, $value, $prev_value = '') {
        return self::guard_meta($check, $post_id, $meta_key, $value);
    }
    /** Only delete_post_metadata has a delete_all fifth argument. */
    public static function guard_delete_meta($check, $post_id, $meta_key, $value, $delete_all = false) {
        if ($check !== null || !self::relevant($meta_key) || !self::installed()) {
            return $check;
        }
        if ($delete_all) {
            // Cross-post mutation cannot be fenced by a single attachment ID.
            if (self::enabled()) { return false; }
            return self::invalidate_global() ? null : false;
        }
        return self::guard_meta($check, $post_id, $meta_key, $value);
    }
    /** Single-attachment common guard; never interprets operation-specific args. */
    public static function guard_meta($check, $post_id, $meta_key, $meta_value, $unused = null) {
        if ($check !== null || !self::relevant($meta_key) || !self::installed()) { return $check; }
        if (get_post_type((int)$post_id) !== 'attachment') { return $check; }
        if (self::mark_dirty((int)$post_id)) {
            self::track_local((int)$post_id);
            return null;
        }
        return self::failed_pre_fence();
    }
    /**
     * Persist global coverage invalidation before admitting a metadata write
     * whose per-attachment pre-fence failed. A direct DB read verifies the
     * stored value (not merely the local WordPress option cache).
     */
    private static function invalidate_global() {
        global $wpdb;
        update_option(self::READY_OPTION, '0', false);
        update_option(self::CURSOR_OPTION, 0, false);
        if (!isset($wpdb->options)) {
            return (string)get_option(self::READY_OPTION, '1') === '0';
        }
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
            self::READY_OPTION
        ));
        return $wpdb->last_error === '' && (string)$value === '0';
    }
    private static function failed_pre_fence() {
        // In strict mode, do not admit the WordPress write. In compatibility
        // mode only allow it if coverage invalidation was durably verified.
        $invalidated = self::invalidate_global();
        return (self::enabled() || !$invalidated) ? false : null;
    }
    public static function guard_by_mid($check, $meta_id, $meta_value, $meta_key = false) {
        global $wpdb;
        if ($check !== null || !self::installed()) { return $check; }
        $old = get_metadata_by_mid('post', $meta_id);
        if (!is_object($old)) {
            return $wpdb->last_error !== '' ? self::failed_pre_fence() : $check;
        }
        if (!self::relevant($old->meta_key) && !self::relevant($meta_key)) { return $check; }
        if (self::mark_dirty((int)$old->post_id)) {
            self::$by_mid[(int)$meta_id] = (int)$old->post_id;
            self::track_local((int)$old->post_id);
            return null;
        }
        return self::failed_pre_fence();
    }
    public static function guard_delete_by_mid($check, $meta_id) {
        global $wpdb;
        if ($check !== null || !self::installed()) { return $check; }
        $old = get_metadata_by_mid('post', $meta_id);
        if (!is_object($old)) {
            return $wpdb->last_error !== '' ? self::failed_pre_fence() : $check;
        }
        if (!self::relevant($old->meta_key)) { return $check; }
        if (self::mark_dirty((int)$old->post_id)) {
            self::track_local((int)$old->post_id);
            return null;
        }
        return self::failed_pre_fence();
    }
    public static function on_meta_saved($meta_id, $post_id, $meta_key, $meta_value) {
        if (!self::installed()) { return; }
        $mid = (is_int($meta_id) || (is_string($meta_id) && ctype_digit($meta_id)))
            ? (int)$meta_id : 0;
        $was_by_mid = self::$by_mid[$mid] ?? null;
        unset(self::$by_mid[$mid]);
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
        if (!self::installed()) { return; }
        if (!self::mark_dirty((int)$id)) {
            // delete_attachment is an action with no cancellation return. If
            // global invalidation also fails, abort the operation rather than
            // let a stale, still-READY mapping survive a file deletion.
            if (!self::invalidate_global()) {
                throw new RuntimeException('ESP media index: cannot fence attachment deletion');
            }
            return;
        }
        self::$deleting[(int)$id] = true;
        self::track_local((int)$id);
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
        // Never START/COMMIT a transaction inside a WordPress metadata hook.
        // A caller may have an open transaction; the dirty marker participates
        // in that transaction and becomes visible atomically with the metadata.
        // The independent Cron worker rebuilds only after it can read the
        // committed dirty marker (and pending_count = 0).
        return true;
    }

    /**
     * Build from direct DB reads (not stale WP object cache) while holding the
     * index-state row lock. Hook guards must mark this row dirty before WP writes.
     * Any crash leaves dirty records non-public; Cron retries after a grace period.
     */
    /**
     * Best-effort rebuild without issuing START TRANSACTION/COMMIT/ROLLBACK.
     * The caller owns its transaction, if any. Before changing any file rows
     * the derived state is marked dirty, so partial writes cannot authorize.
     * Revision CAS at publication prevents concurrent pre-hooks from
     * turning old materialized data into a ready authorization decision.
     *
     * Named lock prevents simultaneous workers from replacing the same rows.
     * This lock is connection-scoped and does not commit an enclosing txn.
     */
    public static function reindex_attachment($id, $repair = false) {
        global $wpdb;
        if ($id <= 0 || !self::installed()) { return false; }
        $state = self::table(self::state_table());
        $files = self::table(self::files_table());
        $lock_name = 'esp63_' . substr(hash('sha256', $wpdb->prefix . ':' . $id), 0, 40);
        $lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', $lock_name));
        if ($wpdb->last_error !== '' || (string)$lock !== '1') { return false; }
        try {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT status,revision,pending_count FROM $state WHERE attachment_id=%d",
                $id), ARRAY_A);
            if ($wpdb->last_error !== '') { throw new RuntimeException('state read'); }
            if ($row && (int)$row['pending_count'] !== 0) {
                return false;
            }
            if (!$row) {
                $sql = $wpdb->prepare("INSERT INTO $state
                    (attachment_id,status,revision,pending_count,changed_at)
                    VALUES (%d,'dirty',1,0,%d)", $id, time());
                if ($wpdb->query($sql) === false) {
                    throw new RuntimeException('state init');
                }
                $revision = 1;
            } else {
                $revision = (int)$row['revision'];
                // In autocommit mode the dirty marker becomes visible before
                // any DELETE/INSERT, even if a later database operation fails.
                $sql = $wpdb->prepare("UPDATE $state SET status='dirty',
                    revision=revision+1,changed_at=%d
                    WHERE attachment_id=%d AND revision=%d AND pending_count=0",
                    time(), $id, $revision);
                $changed = $wpdb->query($sql);
                if ($changed !== 1) {
                    throw new RuntimeException('pre-rebuild dirty fence');
                }
                ++$revision;
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
            // If the source changed while rebuilding, revision or pending
            // will differ, and the state must stay dirty until another pass.
            $published = $wpdb->query($wpdb->prepare(
                "UPDATE $state SET status='ready',changed_at=%d
                 WHERE attachment_id=%d AND revision=%d
                   AND pending_count=0 AND status='dirty'",
                time(), $id, $revision));
            if ($published !== 1) {
                throw new RuntimeException('stale revision or publish failure');
            }
            return true;
        } catch (\Throwable $e) {
            // Never undo another plugin's transaction. The committed dirty
            // marker already blocks authorization; a worker can retry.
            error_log('ESP: Media index rebuild incomplete for attachment ' . $id);
            return false;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
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
        // Prioritize changed attachments over the expensive full scan.
        // Dirty rows are visible to this connection only after an external
        // metadata transaction commits. Never rebuild in-flight mutations.
        $state_table = self::table(self::state_table());
        $dirty_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT attachment_id FROM $state_table
             WHERE status='dirty' AND pending_count=0
             ORDER BY changed_at ASC LIMIT %d", self::BATCH_SIZE));
        if (!is_array($dirty_ids) || $wpdb->last_error !== '') { return; }
        foreach ($dirty_ids as $dirty_id) {
            if (!self::reindex_attachment((int)$dirty_id, true)) { return; }
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
