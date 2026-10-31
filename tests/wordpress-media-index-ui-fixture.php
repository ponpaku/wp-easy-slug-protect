<?php
/**
 * Disposable-only seed/verification for admin UI repair via actual HTTP AJAX.
 * No synthetic "success" shortcut: the HTTP client must invoke the registered
 * WordPress AJAX endpoint while logged in as the disposable admin.
 */
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' ||
    !getenv('ESP_WP_ROOT') ||
    !in_array(getenv('ESP_UI_PHASE'), ['seed', 'verify'], true)) { exit(1); }
define('WP_ADMIN', true);
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
if (strpos(DB_NAME, 'esp_') !== 0 || !class_exists('ESP_Media_Index')) {
    throw new RuntimeException('UI fixture refuses non-disposable WordPress DB');
}
global $wpdb;
$state = ESP_Media_Index::state_table();
$id = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'
     AND post_title=%s LIMIT 1", 'ESP31 public JPEG'));
if ($id <= 0 || $wpdb->last_error !== '') {
    throw new RuntimeException('UI fixture public JPEG missing');
}
$status = ESP_Media_Index::build_status(false);
if ($status['error'] || !$status['ready']) {
    throw new RuntimeException('UI fixture expects initial full READY');
}
if (getenv('ESP_UI_PHASE') === 'seed') {
    $written = $wpdb->query($wpdb->prepare(
        "UPDATE $state SET status='dirty',revision=revision+1,
         pending_count=0,retry_after=0,changed_at=%d
         WHERE attachment_id=%d AND status='ready' AND pending_count=0",
        time(), $id));
    if ($written !== 1 || $wpdb->last_error !== '' ||
        !ESP_Media_Index::build_status(false)['repairable_dirty']) {
        throw new RuntimeException('UI fixture could not seed repairable DIRTY');
    }
    delete_transient('esp_media_index_progress_counts_v1');
    echo "PASS: UI fixture seeded eligible dirty attachment under global READY\n";
} else {
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT status,pending_count FROM $state WHERE attachment_id=%d", $id), ARRAY_A);
    if (!is_array($row) || $row['status'] !== 'ready' ||
        (int)$row['pending_count'] !== 0 ||
        ESP_Media_Index::build_status(false)['repairable_dirty']) {
        throw new RuntimeException('Authenticated real admin AJAX did not repair the owner');
    }
    echo "PASS: real admin HTTP AJAX restored the owner to READY\n";
}
