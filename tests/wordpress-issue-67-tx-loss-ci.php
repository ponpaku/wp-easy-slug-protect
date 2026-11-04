<?php
/**
 * #67: actual WordPress Core metadata API / wpdb reconnect at caller-owned
 * transaction boundary. Disposable Apache CI WordPress only.
 *
 * Kill the active MySQL session in an update_post_metadata filter AFTER
 * ESP_Media_Index's priority-5 pre-fence but BEFORE Core's canonical write.
 * An independent MySQL connection kills it; the next Core SQL must handle
 * the broken connection. Inspect real postmeta, real index state and resolve.
 * This is NOT a simulated SQL replay or a production code change.
 */
declare(strict_types=1);

if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' ||
    !getenv('ESP_WP_ROOT')) { exit(1); }
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
if (strpos(DB_NAME, 'esp_') !== 0 ||
    !class_exists('ESP_Media_Index') ||
    !ESP_Media_Index::enabled() ||
    !ESP_Media_Index::ready()) {
    throw new RuntimeException('Q01 transaction probe requires disposable strict READY WordPress');
}
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
global $wpdb;
$state = ESP_Media_Index::state_table();
$key = ESP_Media_Index::PROTECTION_META;
$id = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_title=%s LIMIT 1",
    'ESP31 protected JPEG'));
if ($id <= 0) { throw new RuntimeException('Q01 protected WordPress fixture missing'); }
$initial_meta = get_post_meta($id, $key, true);
$before = $wpdb->get_row($wpdb->prepare(
    "SELECT status,revision,pending_count,active_generation FROM $state WHERE attachment_id=%d",
    $id), ARRAY_A);
if (!is_array($before) || $before['status'] !== 'ready' ||
    (int)$before['pending_count'] !== 0 || $initial_meta !== 'path_http') {
    throw new RuntimeException('Q01 requires clean READY attachment with path_http');
}
$observed_dirty = false;
$killed_connection = 0;
$hook = static function ($check, $post_id, $meta_key) use (
    $id, $key, $state, &$observed_dirty, &$killed_connection, $wpdb
) {
    if ((int)$post_id !== $id || $meta_key !== $key) { return $check; }
    $dirty = $wpdb->get_row($wpdb->prepare(
        "SELECT status,pending_count FROM $state WHERE attachment_id=%d", $id), ARRAY_A);
    if (!is_array($dirty) || $dirty['status'] !== 'dirty' ||
        (int)$dirty['pending_count'] < 1) {
        throw new RuntimeException('Q01 ESP priority-5 pre-hook did not fence inside transaction');
    }
    $observed_dirty = true;
    $killed_connection = (int)$wpdb->get_var('SELECT CONNECTION_ID()');
    if ($killed_connection <= 0) {
        throw new RuntimeException('Q01 cannot inspect transaction connection');
    }
    $killer = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $killer->query('KILL CONNECTION ' . $killed_connection);
    $killer->close();
    return $check;
};
$old_suppression = $wpdb->suppress_errors(true);
$updated = null;
$exception = null;
try {
    if ($wpdb->query('START TRANSACTION') === false) {
        throw new RuntimeException('Q01 could not start external transaction');
    }
    add_filter('update_post_metadata', $hook, 7, 5);
    try {
        $updated = update_post_meta($id, $key, '');
    } catch (Throwable $e) {
        $exception = get_class($e) . ': ' . $e->getMessage();
    } finally {
        remove_filter('update_post_metadata', $hook, 7);
    }

    // No intentional explicit COMMIT/ROLLBACK: reconnect is expected to drop
    // the old session and its uncommitted fence. Read through the real wpdb.
    $connection_after = (int)$wpdb->get_var('SELECT CONNECTION_ID()');
    $current = $wpdb->get_row($wpdb->prepare(
        "SELECT status,revision,pending_count,active_generation FROM $state WHERE attachment_id=%d",
        $id), ARRAY_A);
    // wpdb::get_var() intentionally returns null for SQL empty-string values:
    // use get_row() and an independent MySQL session so '' is not mistaken for
    // a missing row or an unchanged canonical protection value.
    $canonical = $wpdb->get_row($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s
         ORDER BY meta_id ASC LIMIT 1", $id, $key), ARRAY_A);
    $actual_meta = is_array($canonical) ? $canonical['meta_value'] : null;
    $direct = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $direct_sql = $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s
         ORDER BY meta_id ASC LIMIT 1", $id, $key);
    $direct_row = $direct->query($direct_sql)->fetch_assoc();
    $direct->close();
    $direct_meta = is_array($direct_row) ? $direct_row['meta_value'] : null;
    if (!$observed_dirty || $killed_connection <= 0 ||
        $connection_after <= 0 || $connection_after === $killed_connection ||
        !is_array($current)) {
        throw new RuntimeException('Q01 connection loss/real wpdb state could not be measured');
    }
    $same_ready = $current['status'] === 'ready' &&
        (int)$current['pending_count'] === 0 &&
        (int)$current['revision'] === (int)$before['revision'];
    $canonical_changed = $actual_meta === '' && $direct_meta === '';
    $outcome = [
        'hook_fenced_before_kill' => $observed_dirty,
        'connection_replaced' => true,
        'update_post_meta_return' => $updated,
        'exception' => $exception,
        'canonical_changed' => $canonical_changed,
        'wpdb_meta_value' => $actual_meta,
        'independent_mysql_meta_value' => $direct_meta,
        'state_ready_without_fence' => $same_ready,
        'canonical_changed_with_missing_fence' => $canonical_changed && $same_ready,
        'strict_resolve_status' => ESP_Media_Index::resolve(
            wp_upload_dir()['basedir'] . '/esp31-protected.jpg')['status'],
    ];
    echo 'Q01_REAL_WP_TRANSACTION_RECONNECT ' .
        wp_json_encode($outcome, JSON_UNESCAPED_SLASHES) . "\n";
    if (!$canonical_changed) {
        echo "Q01_OBSERVATION: canonical write did not commit after session kill\n";
    } elseif ($same_ready) {
        echo "Q01_WP_CONFIRMED_GAP: canonical write committed without ESP fence\n";
    } else {
        echo "Q01_WP_OBSERVATION: canonical changed, but index fence remained\n";
    }
} finally {
    // Restore via the actual WP API, after the one-off kill hook is gone.
    // Its own pre-hook must fence and its completion must allow safe rebuild.
    wp_cache_delete($id, 'post_meta');
    if (get_post_meta($id, $key, true) !== $initial_meta) {
        $restored = update_post_meta($id, $key, $initial_meta);
        if ($restored === false) {
            throw new RuntimeException('Q01 cleanup could not restore WP metadata');
        }
    }
    ESP_Media_Index::finish_noop_writes();
    if (!ESP_Media_Index::reindex_attachment($id)) {
        throw new RuntimeException('Q01 cleanup could not reindex protected fixture');
    }
    // The production SQL-time fallback can atomically revoke GLOBAL READY
    // after wpdb reconnect. Local owner reindex alone does not restore global
    // coverage; an independent full audit must certify the new epoch.
    for ($attempt=0; $attempt<130; ++$attempt) {
        ESP_Media_Index::run_batch();
        $global=$wpdb->get_var("SELECT status FROM $state WHERE attachment_id=0");
        if ($global==='ready') { break; }
    }
    if ($global!=='ready') {
        throw new RuntimeException('Q01 cleanup global audit did not recover');
    }
    $after_meta = get_post_meta($id, $key, true);
    $after_path = ESP_Media_Index::resolve(
        wp_upload_dir()['basedir'] . '/esp31-protected.jpg');
    if ($after_meta !== $initial_meta ||
        $after_path['status'] !== 'resolved' ||
        ($after_path['protected_path_id'] ?? null) !== 'path_http') {
        throw new RuntimeException('Q01 WordPress fixture did not recover');
    }
    $wpdb->suppress_errors($old_suppression);
    echo "PASS: Q01 real WordPress metadata/strict-index fixture restored\n";
}
