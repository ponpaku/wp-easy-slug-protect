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
    const RETRY_BASE_SECONDS = 60;
    const RETRY_MAX_SECONDS = 3600;
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
        return (string)get_option(self::SCHEMA_OPTION, '0') === '3';
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
        // WordPress shutdown callbacks may themselves call update_post_meta()
        // and get a no-op result. A late WP hook plus PHP's post-WordPress
        // shutdown callback close that normal lifecycle gap.
        add_action('shutdown', [self::class, 'finish_noop_writes'], PHP_INT_MAX);
        register_shutdown_function([self::class, 'finish_noop_writes']);
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
        // Schema upgrades must never inherit READY from an older generation:
        // its audit did not check deleted owners or retry state.
        if (!self::installed()) {
            update_option(self::READY_OPTION, '0', false);
            update_option(self::CURSOR_OPTION, 0, false);
        }
        $files = self::table(self::files_table());
        $state = self::table(self::state_table());
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $files (
            path_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            file_path text NOT NULL,
            attachment_id bigint(20) unsigned NOT NULL,
            generation bigint(20) unsigned NOT NULL DEFAULT 0,
            protected_path_id varchar(191) NOT NULL DEFAULT '',
            PRIMARY KEY  (path_hash,attachment_id,generation),
            KEY attachment_id (attachment_id),
            KEY protected_path_id (protected_path_id)
        ) $charset;");
        dbDelta("CREATE TABLE $state (
            attachment_id bigint(20) unsigned NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'dirty',
            revision bigint(20) unsigned NOT NULL DEFAULT 0,
            active_generation bigint(20) unsigned NOT NULL DEFAULT 0,
            pending_count int(11) unsigned NOT NULL DEFAULT 0,
            changed_at bigint(20) unsigned NOT NULL DEFAULT 0,
            retry_after bigint(20) unsigned NOT NULL DEFAULT 0,
            failure_count int(11) unsigned NOT NULL DEFAULT 0,
            audit_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (attachment_id),
            KEY status (status),
            KEY retry_queue (status,pending_count,retry_after,changed_at)
        ) $charset;");
        foreach ([$files, $state] as $table) {
            $row = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($wpdb->last_error !== '' || $row !== $table) { return false; }
        }
        // dbDelta may report a partial upgrade without throwing. Verify
        // the specific columns required by the retry/ownership contract.
        foreach (['retry_after', 'failure_count', 'audit_cursor'] as $column) {
            $row = $wpdb->get_var($wpdb->prepare(
                "SHOW COLUMNS FROM $state LIKE %s", $column));
            if ($wpdb->last_error !== '' || $row !== $column) { return false; }
        }
        if ($wpdb->query("INSERT IGNORE INTO $state
            (attachment_id,status,revision,audit_cursor) VALUES (0,'dirty',1,0)") === false) {
            return false;
        }
        return update_option(self::SCHEMA_OPTION, '3', false) ||
            (string)get_option(self::SCHEMA_OPTION) === '3';
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
        // Match #42's ownership evidence: absent top-level file may
        // authorize derivatives ONLY for real PDF attachment metadata.
        if (!isset($metadata['file']) && !empty($sizes) &&
            strtolower(pathinfo($attached, PATHINFO_EXTENSION)) !== 'pdf') {
            return null;
        }
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
        $sql = $wpdb->prepare("SELECT f.file_path, f.attachment_id, f.generation,
                f.protected_path_id, s.status, s.pending_count, s.active_generation,
                c.status AS coverage_status, p.post_type AS live_post_type,
                pm.meta_value AS live_protected_path
            FROM $files f
            LEFT JOIN $state s ON s.attachment_id=f.attachment_id
            LEFT JOIN $state c ON c.attachment_id=0
            LEFT JOIN {$wpdb->posts} p ON p.ID=f.attachment_id
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
                         $row['protected_path_id'], $row['status'], $row['pending_count'],
                         $row['generation'], $row['active_generation']) ||
                !array_key_exists('live_protected_path', $row) ||
                ($row['coverage_status'] ?? null) !== 'ready' ||
                ($row['live_post_type'] ?? null) !== 'attachment' ||
                !hash_equals((string)$row['file_path'], $relative) ||
                !ctype_digit((string)$row['attachment_id']) ||
                (int)$row['attachment_id'] <= 0 ||
                !is_string($row['protected_path_id']) ||
                $row['status'] !== 'ready' || (int)$row['pending_count'] !== 0) {
                return ['status' => 'error'];
            }
            // Published state can retain old generation rows until cleanup.
            // While dirty, *all* old/new rows block access, even when stale.
            // Once ready, inactive generations are deliberately ignored,
            // including their old protection IDs (which may now disagree).
            if ((string)$row['generation'] !== (string)$row['active_generation']) {
                continue;
            }
            if (($row['live_protected_path'] !== null &&
                 !is_string($row['live_protected_path'])) ||
                $row['protected_path_id'] !== (string)($row['live_protected_path'] ?? '')) {
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
        return self::guard_meta($check, $post_id, $meta_key, $value, false);
    }
    /** Single-attachment common guard; never interprets operation-specific args. */
    public static function guard_meta($check, $post_id, $meta_key, $meta_value, $reserve = true) {
        if ($check !== null || !self::relevant($meta_key) || !self::installed()) { return $check; }
        if (get_post_type((int)$post_id) !== 'attachment') { return $check; }
        if (self::mark_dirty((int)$post_id)) {
            self::track_local((int)$post_id);
            if (!$reserve || self::reserve_new_paths((int)$post_id, $meta_key, $meta_value)) {
                return null;
            }
            // The reservation failed, so the new path cannot be admitted
            // under local dirty-state coverage alone.
            return self::failed_pre_fence();
        }
        return self::failed_pre_fence();
    }
    /**
     * Reserve all proposed paths BEFORE WordPress writes the canonical meta.
     * The dirty state was persisted by guard_meta() first. A prospective
     * owner row immediately makes every matching old public owner fail closed.
     * Within a caller-owned transaction the reservation commits atomically
     * with WordPress metadata; without one it is visible earlier.
     */
    private static function reserve_new_paths($id, $key, $value) {
        global $wpdb;
        if ($key === self::PROTECTION_META) { return true; }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key,meta_value FROM {$wpdb->postmeta}
             WHERE post_id=%d AND meta_key IN (%s,%s) ORDER BY meta_id ASC",
            $id, '_wp_attached_file', '_wp_attachment_metadata'), ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== '') { return false; }
        $attached = null; $metadata = false;
        foreach ($rows as $row) {
            if ($row['meta_key'] === '_wp_attached_file') {
                $attached = $row['meta_value'];
            } else {
                $metadata = maybe_unserialize($row['meta_value']);
            }
        }
        if ($key === '_wp_attached_file') { $attached = $value; }
        if ($key === '_wp_attachment_metadata') {
            $metadata = is_string($value) ? maybe_unserialize($value) : $value;
        }
        // WordPress can write metadata during attachment initialization before
        // the _wp_attached_file row exists. No safe candidate can be derived,
        // so the caller must invalidate global coverage or reject the write.
        $paths = self::enumerate($attached, $metadata);
        if ($paths === null || !$paths) { return false; }
        $state = self::table(self::state_table());
        $revision = $wpdb->get_var($wpdb->prepare(
            "SELECT revision FROM $state WHERE attachment_id=%d
             AND status='dirty' AND pending_count>0", $id));
        if ($wpdb->last_error !== '' || $revision === null) { return false; }
        $files = self::table(self::files_table());
        foreach ($paths as $path) {
            $written = $wpdb->query($wpdb->prepare(
                "INSERT INTO $files
                 (path_hash,file_path,attachment_id,generation,protected_path_id)
                 VALUES (%s,%s,%d,%d,'')",
                hash('sha256', $path), $path, $id, $revision));
            if ($written === false || $wpdb->last_error !== '') { return false; }
        }
        return true;
    }

    /**
     * Persist global coverage invalidation before admitting a metadata write
     * whose per-attachment pre-fence failed. A direct DB read verifies the
     * stored value (not merely the local WordPress option cache).
     */
    /** Revoke global coverage and start a NEW audit epoch atomically. */
    private static function invalidate_global() {
        global $wpdb;
        $state = self::table(self::state_table());
        $changed = $wpdb->query(
            "UPDATE $state SET status='dirty',revision=revision+1,
             audit_cursor=0 WHERE attachment_id=0"
        );
        if ($changed !== 1 || $wpdb->last_error !== '') { return false; }
        update_option(self::READY_OPTION, '0', false);
        update_option(self::CURSOR_OPTION, 0, false);
        // The persisted control row, not a cached option, is authoritative.
        return true;
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
            $next_key = is_string($meta_key) ? $meta_key : $old->meta_key;
            if (self::reserve_new_paths((int)$old->post_id, $next_key, $meta_value)) {
                return null;
            }
            return self::failed_pre_fence();
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
        // Only retire our local ticket AFTER its DB decrement succeeds.
        // A nonzero pending_count belonging to another writer is not failure.
        if (self::complete_mutation((int)$post_id)) {
            self::finish_local((int)$post_id);
        }
    }
    public static function on_attachment_created($id) {
        if (self::installed() && self::mark_dirty((int)$id)) {
            self::track_local((int)$id);
            if (self::complete_mutation((int)$id)) {
                self::finish_local((int)$id);
            }
        }
    }
    public static function on_attachment_deleting($id) {
        if (!self::installed()) { return; }
        if (!self::mark_dirty((int)$id)) {
            // No cancellable WordPress action here. Record the deletion even
            // when we have to fall back to global invalidation. Recovery
            // MUST remove orphaned rows before READY can be republished.
            if (!self::invalidate_global()) {
                throw new RuntimeException('ESP media index: cannot fence attachment deletion');
            }
            self::$deleting[(int)$id] = false;
            return;
        }
        self::$deleting[(int)$id] = true;
        self::track_local((int)$id);
    }
    public static function on_post_deleted($id) {
        if (!isset(self::$deleting[(int)$id])) { return; }
        $fenced = self::$deleting[(int)$id];
        unset(self::$deleting[(int)$id]);
        if (!$fenced) {
            // The pre-delete state write failed. Keep global coverage invalid;
            // the orphan collector can now purge the deleted attachment.
            return;
        }
        if (self::complete_mutation((int)$id)) {
            self::finish_local((int)$id);
        }
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
        // This snapshot is intentionally not cleared first. An SQL failure
        // retains the ticket for the later PHP shutdown pass.
        foreach (self::$local_pending as $id => $count) {
            for ($i = 0; $i < $count; ++$i) {
                if (!self::complete_mutation((int)$id)) {
                    error_log('ESP: Media index pending completion not confirmed: ' . $id);
                    break;
                }
                self::finish_local((int)$id);
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
          revision=revision+1, pending_count=pending_count+1,
          retry_after=0, failure_count=0, changed_at=VALUES(changed_at)",
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
        $decremented = $wpdb->query($wpdb->prepare(
            "UPDATE $state SET pending_count=pending_count-1
             WHERE attachment_id=%d AND pending_count>0", $id));
        // The result is about THIS decrement, not whether other concurrent
        // writers still have pending operations. In particular there is no
        // separate SELECT whose failure could disguise a successful decrement.
        if ($decremented !== 1 || $wpdb->last_error !== '') { return false; }
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
                    (attachment_id,status,revision,active_generation,pending_count,changed_at)
                    VALUES (%d,'dirty',1,0,0,%d)", $id, time());
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
            // Stage new rows alongside the old live generation. Never remove
            // old owner evidence while the state is dirty: another public
            // attachment could otherwise appear to be the only owner.
            foreach ($paths as $path) {
                $sql = $wpdb->prepare("INSERT INTO $files
                    (path_hash,file_path,attachment_id,generation,protected_path_id)
                    VALUES (%s,%s,%d,%d,%s)",
                    hash('sha256', $path), $path, $id, $revision, $protection);
                if ($wpdb->query($sql) === false) {
                    throw new RuntimeException('insert index row');
                }
            }
            // If the source changed while rebuilding, revision or pending
            // will differ, and the state must stay dirty until another pass.
            $published = $wpdb->query($wpdb->prepare(
                "UPDATE $state SET status='ready',active_generation=%d,changed_at=%d
                 WHERE attachment_id=%d AND revision=%d
                   AND pending_count=0 AND status='dirty'",
                $revision, time(), $id, $revision));
            if ($published !== 1) {
                throw new RuntimeException('stale revision or publish failure');
            }
            // Cleanup happens AFTER generation switch. A failure leaves
            // redundant inert rows, not a partially published ownership map.
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $files WHERE attachment_id=%d AND generation<>%d",
                $id, $revision));
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

    /**
     * Keep failed individual attachments dirty, but give them a bounded retry
     * window. A later normal metadata mutation resets their failure counters.
     * Persisting retry state uses revision CAS, so concurrent new mutations
     * are not accidentally postponed.
     */
    private static function postpone_failed_rebuild($id) {
        global $wpdb;
        $state = self::table(self::state_table());
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT revision,status,pending_count,failure_count FROM $state
             WHERE attachment_id=%d", $id), ARRAY_A);
        if ($wpdb->last_error !== '' || !$row) { return false; }
        if ($row['status'] !== 'dirty' || (int)$row['pending_count'] !== 0) {
            return true; // a concurrent writer already changed its eligibility
        }
        $failures = (int)$row['failure_count'];
        $delay = min(self::RETRY_MAX_SECONDS,
            self::RETRY_BASE_SECONDS * (1 << min($failures, 6)));
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $state SET failure_count=failure_count+1, retry_after=%d
             WHERE attachment_id=%d AND revision=%d AND status='dirty'
               AND pending_count=0",
            time() + $delay, $id, $row['revision']));
        if ($updated === false || $wpdb->last_error !== '') { return false; }
        return true; // zero updated means revision changed concurrently
    }

    /** A row-level failure must not starve unrelated healthy attachments. */
    private static function rebuild_in_batch($id) {
        if (self::reindex_attachment($id, true)) { return true; }
        error_log('ESP: Media index retry scheduled for attachment ' . $id);
        return self::postpone_failed_rebuild($id);
    }

    /**
     * Remove ownership belonging to a deleted/non-attachment post. Invariant:
     * state is dirtied BEFORE deleting file rows; state is deleted LAST. If
     * interrupted, either old paths remain blocked, or there are no paths.
     * The same connection-scoped lock serializes against normal reindex.
     */
    private static function purge_orphan($id) {
        global $wpdb;
        $state = self::table(self::state_table());
        $files = self::table(self::files_table());
        $lock_name = 'esp63_' . substr(hash('sha256', $wpdb->prefix . ':' . $id), 0, 40);
        $lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', $lock_name));
        if ($wpdb->last_error !== '' || (string)$lock !== '1') { return false; }
        try {
            $type = $wpdb->get_var($wpdb->prepare(
                "SELECT post_type FROM {$wpdb->posts} WHERE ID=%d", $id));
            if ($wpdb->last_error !== '') { return false; }
            if ($type === 'attachment') { return true; } // was restored; let normal scan handle it
            if ($wpdb->query($wpdb->prepare(
                "UPDATE $state SET status='dirty',revision=revision+1,
                 retry_after=0 WHERE attachment_id=%d", $id)) === false) {
                return false;
            }
            if ($wpdb->query($wpdb->prepare(
                "DELETE FROM $files WHERE attachment_id=%d", $id)) === false) {
                return false;
            }
            return $wpdb->query($wpdb->prepare(
                "DELETE FROM $state WHERE attachment_id=%d", $id)) !== false;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * Incremental orphan collection, including failed deletion hooks and file
     * rows missing a state row. Do not publish global READY until *both*
     * sources of orphan evidence have been checked clean.
     */
    private static function reconcile_orphans($check_file_rows) {
        global $wpdb;
        $state = self::table(self::state_table());
        $files = self::table(self::files_table());
        $orphans = $wpdb->get_col($wpdb->prepare(
            "SELECT s.attachment_id FROM $state s
             LEFT JOIN {$wpdb->posts} p ON p.ID=s.attachment_id
               AND p.post_type='attachment'
             WHERE p.ID IS NULL AND s.attachment_id>0 LIMIT %d", self::BATCH_SIZE));
        if (!is_array($orphans) || $wpdb->last_error !== '') { return false; }
        foreach ($orphans as $id) {
            if (!self::purge_orphan((int)$id)) { return false; }
        }
        // The file table can have many derivatives per attachment. Scan
        // orphan file owners at the END of an audit cycle, not every 5-minute
        // batch, to preserve bounded work during normal incremental repair.
        if (!$check_file_rows) { return true; }
        // A crash or legacy index may leave file rows without state; these
        // must not be invisible to resolve() or the READY revalidation.
        $unowned = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT f.attachment_id FROM $files f
             LEFT JOIN $state s ON s.attachment_id=f.attachment_id
             WHERE s.attachment_id IS NULL LIMIT %d", self::BATCH_SIZE));
        if (!is_array($unowned) || $wpdb->last_error !== '') { return false; }
        foreach ($unowned as $id) {
            $type = $wpdb->get_var($wpdb->prepare(
                "SELECT post_type FROM {$wpdb->posts} WHERE ID=%d", $id));
            if ($wpdb->last_error !== '') { return false; }
            if ($type === 'attachment') {
                if (!self::rebuild_in_batch((int)$id)) { return false; }
            } elseif (!self::purge_orphan((int)$id)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Global READY means initial ownership coverage is complete, not that
     * every attachment is currently unmodified. During steady state, dirty
     * attachments fail closed individually without taking down unrelated
     * ready media. After global invalidation, all attachments must be ready
     * before re-publishing READY.
     */
    private static function coverage_is_complete($require_all_ready) {
        global $wpdb;
        $state = self::table(self::state_table());
        $files = self::table(self::files_table());
        $state_conditions = $require_all_ready
            ? "(s.attachment_id IS NULL OR s.status<>'ready' OR s.pending_count<>0)"
            : "s.attachment_id IS NULL";
        $checks = [
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN $state s ON p.ID=s.attachment_id
             WHERE p.post_type='attachment' AND $state_conditions LIMIT 1",
            "SELECT s.attachment_id FROM $state s
             LEFT JOIN {$wpdb->posts} p ON p.ID=s.attachment_id
               AND p.post_type='attachment'
             WHERE p.ID IS NULL AND s.attachment_id>0 LIMIT 1",
            "SELECT f.attachment_id FROM $files f
             LEFT JOIN $state s ON s.attachment_id=f.attachment_id
             WHERE s.attachment_id IS NULL LIMIT 1",
        ];
        foreach ($checks as $sql) {
            $found = $wpdb->get_var($sql);
            if ($wpdb->last_error !== '' || $found !== null) { return false; }
        }
        return true;
    }

    /** Bounded incremental backfill and continuous audit; never HTTP path. */
    public static function run_batch() {
        global $wpdb;
        $runtime = get_option(ESP_Protection_State::RUNTIME_STATE_OPTION, null);
        if (!is_array($runtime) || ($runtime['state'] ?? '') !== 'READY' ||
            ($runtime['version'] ?? '') !== ESP_VERSION) { return; }
        if (!self::installed() && !self::install_schema()) {
            error_log('ESP: Media index schema install failed');
            return;
        }
        // First remove orphans. Individual corrupt attachments can be retried
        // later, but a DB outage or failed orphan purge must halt the cycle.
        if (!self::reconcile_orphans(false)) { return; }
        $state = self::table(self::state_table());
        $dirty_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT attachment_id FROM $state
             WHERE attachment_id>0 AND status='dirty'
               AND pending_count=0 AND retry_after<=%d
             ORDER BY changed_at ASC LIMIT %d", time(), self::BATCH_SIZE));
        if (!is_array($dirty_ids) || $wpdb->last_error !== '') { return; }
        foreach ($dirty_ids as $id) {
            if (!self::rebuild_in_batch((int)$id)) { return; }
        }

        // The full audit advances even when an individual attachment cannot
        // rebuild. Its dirty record and retry_after preserve the problem.
        $control = $wpdb->get_row("SELECT revision,audit_cursor,status FROM $state
            WHERE attachment_id=0", ARRAY_A);
        if (!$control || $wpdb->last_error !== '') { return; }
        $epoch = (int)$control['revision'];
        $cursor = (int)$control['audit_cursor'];
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'
             AND ID>%d ORDER BY ID ASC LIMIT %d", $cursor, self::BATCH_SIZE));
        if (!is_array($ids) || $wpdb->last_error !== '') { return; }
        if (!$ids) {
            // The full file-owner anti-join is intentionally cycle-scoped.
            if (!self::reconcile_orphans(true)) { return; }
            $already_ready = $control['status'] === 'ready';
            $valid = self::coverage_is_complete(!$already_ready);
            if (!$valid) {
                // If previously published coverage has become incomplete,
                // revoke it under a NEW audit epoch instead of reusing stale
                // cursor progress. Local dirty is not a global gap.
                if ($already_ready) { self::invalidate_global(); }
                return;
            }
            // All audit publication and cursor writes are CAS against one
            // row. A concurrent invalidate_global() advances revision and
            // makes this stale worker's result unusable.
            $written = $wpdb->query($wpdb->prepare(
                "UPDATE $state SET status='ready',audit_cursor=0
                 WHERE attachment_id=0 AND revision=%d AND audit_cursor=%d",
                $epoch, $cursor));
            if ($written === false || $wpdb->last_error !== '') { return; }
            $fresh = $wpdb->get_row("SELECT revision,status FROM $state
                WHERE attachment_id=0", ARRAY_A);
            if (!$fresh || $wpdb->last_error !== '' ||
                (int)$fresh['revision'] !== $epoch || $fresh['status'] !== 'ready') {
                return;
            }
            // This WordPress option is a compatibility cache; the SQL
            // control row is authoritative for every indexed owner lookup.
            update_option(self::READY_OPTION, '1', false);
            update_option(self::CURSOR_OPTION, 0, false);
            return;
        }
        foreach ($ids as $id) {
            $id = (int)$id;
            $current = $wpdb->get_row($wpdb->prepare(
                "SELECT status,pending_count,retry_after FROM $state
                 WHERE attachment_id=%d", $id), ARRAY_A);
            if ($wpdb->last_error !== '') { return; }
            if ($current && ((int)$current['pending_count'] > 0 ||
                ($current['status'] === 'dirty' &&
                 (int)$current['retry_after'] > time()))) {
                continue;
            }
            if (!self::rebuild_in_batch($id)) { return; }
        }
        $advanced = $wpdb->query($wpdb->prepare(
            "UPDATE $state SET audit_cursor=%d
             WHERE attachment_id=0 AND revision=%d AND audit_cursor=%d",
            (int)end($ids), $epoch, $cursor));
        if ($advanced !== 1 || $wpdb->last_error !== '') { return; }
        update_option(self::CURSOR_OPTION, (int)end($ids), false);
    }
}
