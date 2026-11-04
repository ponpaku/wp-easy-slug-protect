<?php
/**
 * Issue #67: durable, idempotent per-operation state tickets.
 *
 * One column in the existing media state table, no ticket/gate table.
 * An ACK-lost SQL replay cannot consume a different writer's ticket.
 * POSIX flock is only an orphan-liveness hint on a single shared filesystem;
 * delayed SQL is additionally fenced by the database triggers.
 */
if (!defined('ABSPATH')) { exit; }

final class ESP_Media_Mutation_State {
    private static $tickets = [];
    private static $locks = [];

    private static function table() {
        return ESP_Media_Index::state_table();
    }

    private static function lock_path($id) {
        global $wpdb;
        $upload = wp_upload_dir();
        if (!is_array($upload) || !isset($upload['basedir']) ||
            !is_string($upload['basedir'])) { return false; }
        $root = rtrim($upload['basedir'], '/') . '/.esp-media-mutation-locks';
        if (!is_dir($root) && !wp_mkdir_p($root)) { return false; }
        // Never delete these names: unlink/recreate would split flock identity.
        $namespace = (string) DB_NAME . "\0" . (string) $wpdb->prefix . "\0" . $id;
        return $root . '/' . hash('sha256', $namespace) . '.lock';
    }

    private static function stable_lock($id, $exclusive, $nonblock = false) {
        $path = self::lock_path($id);
        if (!is_string($path) || is_link($path)) { return false; }
        $handle = @fopen($path, 'c');
        if (!is_resource($handle)) { return false; }
        $mode = $exclusive ? LOCK_EX : LOCK_SH;
        if ($nonblock) { $mode |= LOCK_NB; }
        if (!@flock($handle, $mode)) { fclose($handle); return false; }
        clearstatcache(true, $path);
        $by_name = @stat($path);
        $by_handle = @fstat($handle);
        if (!is_array($by_name) || !is_array($by_handle) ||
            $by_name['ino'] !== $by_handle['ino'] ||
            $by_name['dev'] !== $by_handle['dev'] || is_link($path)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return false;
        }
        return $handle;
    }

    private static function parse_ids($raw) {
        if ($raw === null || $raw === '') { return []; }
        if (!is_string($raw)) { return false; }
        $list = json_decode($raw, true);
        if (!is_array($list) || array_values($list) !== $list) { return false; }
        $seen = [];
        foreach ($list as $token) {
            if (!is_string($token) || !preg_match('/^[0-9a-f]{32}$/D', $token) ||
                isset($seen[$token])) { return false; }
            $seen[$token] = true;
        }
        return $list;
    }

    /** Revision CAS is idempotent under wpdb reconnect/SQL reply loss. */
    private static function change($id, $token, $add) {
        global $wpdb;
        $state = self::table();
        for ($attempt = 0; $attempt < 32; ++$attempt) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT revision,active_mutation_ids FROM $state WHERE attachment_id=%d",
                $id), ARRAY_A);
            if ($wpdb->last_error !== '') { return false; }
            if (!is_array($row)) {
                if (!$add) { return false; }
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO $state
                     (attachment_id,status,revision,pending_count,changed_at,active_mutation_ids)
                     VALUES (%d,'dirty',1,0,%d,'[]')", $id, time()));
                if ($wpdb->last_error !== '') { return false; }
                continue;
            }
            $ids = self::parse_ids($row['active_mutation_ids']);
            if ($ids === false) { return false; }
            $exists = in_array($token, $ids, true);
            if ($add && $exists) { return true; }
            if (!$add && !$exists) { return true; }
            if ($add) {
                $ids[] = $token;
            } else {
                $ids = array_values(array_filter($ids,
                    static function($value) use ($token) { return $value !== $token; }));
            }
            $json = wp_json_encode($ids);
            if (!is_string($json)) { return false; }
            $sql = $wpdb->prepare(
                "UPDATE $state SET active_mutation_ids=%s,pending_count=%d,
                    status='dirty',revision=revision+1,changed_at=%d,
                    retry_after=0 WHERE attachment_id=%d AND revision=%d",
                $json, count($ids), time(), $id, (int)$row['revision']);
            $written = $wpdb->query($sql);
            // On an ambiguous ACK, observe durable state before a retry.
            if ($written === 1) { return true; }
            if ($wpdb->last_error !== '') {
                $check = $wpdb->get_var($wpdb->prepare(
                    "SELECT active_mutation_ids FROM $state WHERE attachment_id=%d", $id));
                if ($wpdb->last_error !== '') { return false; }
                $observed = self::parse_ids($check);
                if ($observed === false) { return false; }
                if (in_array($token, $observed, true) === (bool)$add) { return true; }
                return false;
            }
        }
        return false;
    }

    private static function actor($token) {
        global $wpdb;
        return $wpdb->query($wpdb->prepare('SET @esp_media_mutation_actor=%s', $token)) !== false
            && $wpdb->last_error === '';
    }

    /** Called before any canonical WordPress mutation. */
    public static function begin($id) {
        $id = (int)$id;
        if ($id < 0) { return false; }
        try { $token = bin2hex(random_bytes(16)); }
        catch (\Throwable $e) { return false; }

        if (!isset(self::$locks[$id])) {
            $lock = self::stable_lock($id, false);
            if ($lock === false) { return false; }
            self::$locks[$id] = $lock;
        }
        // Do not release the lock until PHP terminates. A reaper must never
        // retire the ticket while this PHP request could still issue SQL.
        if (!self::change($id, $token, true)) { return false; }
        self::$tickets[$id][] = $token;
        if (!self::actor($token)) { return false; }
        return true;
    }

    /** Remove precisely our own token, never a blind pending_count decrement. */
    public static function finish($id) {
        $id = (int)$id;
        if (empty(self::$tickets[$id])) { return false; }
        $token = self::$tickets[$id][0];
        if (!self::change($id, $token, false)) { return false; }
        array_shift(self::$tickets[$id]);
        if (empty(self::$tickets[$id])) { unset(self::$tickets[$id]); }
        return true;
    }

    /**
     * Orphan cleanup. Returns number of state rows recovered, not ticket count.
     * Never unlink a lock file. On non-shared multi-host storage, this reaper
     * must not be enabled without an equivalent cluster-wide liveness gate.
     */
    public static function reap($limit = 25) {
        global $wpdb;
        $state = self::table();
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT attachment_id FROM $state WHERE pending_count>0
             ORDER BY changed_at ASC LIMIT %d", max(1,(int)$limit)));
        if (!is_array($ids) || $wpdb->last_error !== '') { return 0; }
        $reaped = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            $lock = self::stable_lock($id, true, true);
            if ($lock === false) { continue; }
            try {
                for ($attempt = 0; $attempt < 32; ++$attempt) {
                    $row = $wpdb->get_row($wpdb->prepare(
                        "SELECT revision,active_mutation_ids FROM $state
                         WHERE attachment_id=%d", $id), ARRAY_A);
                    if (!is_array($row) || $wpdb->last_error !== '') { break; }
                    $tokens = self::parse_ids($row['active_mutation_ids']);
                    if ($tokens === false || !$tokens) { break; }
                    $written = $wpdb->query($wpdb->prepare(
                        "UPDATE $state SET active_mutation_ids='[]',pending_count=0,
                         status='dirty',revision=revision+1,audit_cursor=0,changed_at=%d
                         WHERE attachment_id=%d AND revision=%d",
                        time(), $id, (int)$row['revision']));
                    if ($written === 1) { ++$reaped; break; }
                    if ($wpdb->last_error !== '') { break; }
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        return $reaped;
    }
}
