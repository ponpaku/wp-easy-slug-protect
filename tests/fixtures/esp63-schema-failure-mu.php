<?php
/**
 * Disposable WordPress/MySQL fault-injection MU plugin for index schema setup.
 * Copy into wp-content/mu-plugins ONLY inside the CI disposable container.
 * Enabled only with ESP_ALLOW_DISPOSABLE_DB=1 and ESP_INDEX_SCHEMA_FAULT.
 */
$mode = getenv('ESP_INDEX_SCHEMA_FAULT');
if (!in_array($mode, ['fail', 'busy'], true)) { return; }
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' ||
    !defined('DB_NAME') || strpos(DB_NAME, 'esp_') !== 0) {
    throw new RuntimeException('R02 schema test refuses a non-disposable WordPress DB');
}
global $wpdb;
$schema_option = 'esp_media_index_v1_schema';
$ready_option = 'esp_media_index_v1_ready';
$cursor_option = 'esp_media_index_v1_cursor';
$original_schema = get_option($schema_option, '0');
$original_ready = get_option($ready_option, '0');
$original_cursor = get_option($cursor_option, 0);
if ((string)$original_schema !== '3' || (string)$original_ready !== '1') {
    throw new RuntimeException('R02 schema test requires an already verified READY fixture');
}

$holder = null;
if ($mode === 'busy') {
    $lock_name = 'esp63_scan_' .
        substr(hash('sha256', DB_NAME . "\0" . $wpdb->prefix), 0, 32);
    $holder = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $query = $holder->query("SELECT GET_LOCK('" .
        $holder->real_escape_string($lock_name) . "',0)");
    if (!$query || (int)$query->fetch_row()[0] !== 1) {
        throw new RuntimeException('R02 schema test cannot hold independent scan lock');
    }
}
register_shutdown_function(static function () use (
    $schema_option, $ready_option, $cursor_option,
    $original_schema, $original_ready, $original_cursor, $holder, $mode
) {
    // The fixture simulates an uninstalled index without altering actual
    // tables. Restore persisted options even when WP_CLI::error() exits.
    update_option($schema_option, $original_schema, false);
    update_option($ready_option, $original_ready, false);
    update_option($cursor_option, $original_cursor, false);
    if ($holder instanceof mysqli) {
        $lock_name = 'esp63_scan_' .
            substr(hash('sha256', DB_NAME . "\0" . $GLOBALS['wpdb']->prefix), 0, 32);
        $holder->query("SELECT RELEASE_LOCK('" .
            $holder->real_escape_string($lock_name) . "')");
        $holder->close();
    }
});

update_option($schema_option, '0', false);
if ($mode === 'fail') {
    // Only spoof install_schema()'s post-dbDelta verification of one column.
    // Real tables remain intact; no ALTER/CREATE or DROP is injected.
    add_filter('query', static function ($sql) use ($wpdb) {
        $prefix = 'SHOW COLUMNS FROM ' . $wpdb->prefix .
            'esp_media_file_state_v1 LIKE ';
        if (strpos($sql, $prefix) === 0 && strpos($sql, 'retry_after') !== false) {
            return 'SELECT NULL';
        }
        return $sql;
    }, 1);
}
