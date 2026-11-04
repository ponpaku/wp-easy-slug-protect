<?php
/**
 * Issue #67: durable, idempotent per-operation state tickets.
 *
 * One column in the existing media state table, no ticket/gate table.
 * An ACK-lost SQL replay cannot consume a different writer's ticket.
 * Connection-scoped MySQL named locks establish ticket liveness across PHP
 * hosts without another SQL table. Reconnect/late SQL is independently fenced
 * by database triggers that revalidate tokens at actual SQL execution time.
 */
if (!defined('ABSPATH')) { exit; }

final class ESP_Media_Mutation_State {
    private static $tickets = [];
    private static $locks = [];

    private static function table() {
        return ESP_Media_Index::state_table();
    }

    private static function lock_name($token) {
        global $wpdb;
        // DB namespace and blog prefix keep multiple sites independent.
        return 'esp67_ticket_' . substr(hash('sha256',
            (string) DB_NAME . "\0" . (string) $wpdb->prefix . "\0" . $token), 0, 48);
    }

    private static function named_lock($token, $acquire) {
        global $wpdb;
        $name = self::lock_name($token);
        $function = $acquire ? 'GET_LOCK' : 'RELEASE_LOCK';
        $sql = $acquire
            ? $wpdb->prepare("SELECT GET_LOCK(%s,0)", $name)
            : $wpdb->prepare("SELECT RELEASE_LOCK(%s)", $name);
        $value = $wpdb->get_var($sql);
        return $wpdb->last_error === '' && (string)$value === '1';
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

        // Keep the connection-scoped lock until PHP/DB session termination.
        // Lost locks after wpdb reconnect only cause a safe global fallback.
        if (!self::named_lock($token, true)) { return false; }
        self::$locks[$token] = true;
        if (!self::change($id, $token, true)) {
            self::named_lock($token, false);
            unset(self::$locks[$token]);
            return false;
        }
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
     * Reap only tokens whose DB named lock is demonstrably unheld.
     * Global SQL fallback fences any late write after token retirement.
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
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT active_mutation_ids FROM $state WHERE attachment_id=%d",
                $id), ARRAY_A);
            if (!is_array($row) || $wpdb->last_error !== '') { break; }
            $tokens = self::parse_ids($row['active_mutation_ids']);
            if ($tokens === false) { break; }
            foreach ($tokens as $token) {
                if (isset(self::$locks[$token])) { continue; }
                $name = self::lock_name($token);
                $holder = $wpdb->get_var($wpdb->prepare(
                    'SELECT IS_USED_LOCK(%s)', $name));
                if ($wpdb->last_error !== '' || $holder !== null) { continue; }
                if (!self::named_lock($token, true)) { continue; }
                try {
                    if (self::change($id, $token, false)) { ++$reaped; }
                } finally {
                    self::named_lock($token, false);
                }
            }
        }
        return $reaped;
    }
}
