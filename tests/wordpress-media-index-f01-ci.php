<?php
/**
 * F01: real WordPress Core multi-row postmeta update and two PHP/MySQL workers.
 * Independent phase commands run only in disposable WordPress CI.
 */
$phase = getenv('ESP_F01_PHASE');
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' ||
    !getenv('ESP_WP_ROOT') ||
    !in_array($phase, ['seed', 'hold-b', 'update-a', 'restore'], true)) { exit(1); }
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
if (strpos(DB_NAME, 'esp_') !== 0 || !class_exists('ESP_Media_Index')) {
    throw new RuntimeException('F01 only runs against disposable WordPress');
}
global $wpdb;
$id = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'
     AND post_title=%s LIMIT 1", 'ESP31 protected JPEG'));
if ($id <= 0) { throw new RuntimeException('F01 protected fixture missing'); }
$key = ESP_Media_Index::PROTECTION_META;
$state = ESP_Media_Index::state_table();
$uploads = wp_upload_dir();
$relative = 'esp31-protected.jpg';
$root = $uploads['basedir'] . '/.esp-f01-ci';
wp_mkdir_p($root);
$ready_marker = $root . '/b-prehook-ready';
$resume_marker = $root . '/b-resume';
function f01_require($ok, $message) {
    if (!$ok) { throw new RuntimeException('F01: ' . $message); }
}
function f01_pending($id, $state) {
    global $wpdb;
    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT pending_count FROM $state WHERE attachment_id=%d", $id));
}
if ($phase === 'seed') {
    @unlink($ready_marker);
    @unlink($resume_marker);
    f01_require(ESP_Media_Index::enabled() && ESP_Media_Index::ready(),
        'strict test fixture must be globally READY');
    f01_require(ESP_Media_Index::resolve($uploads['basedir'] . '/' . $relative)['status']
        === 'resolved', 'initial protected fixture must resolve');
    // Explicitly duplicate only a disposable protection meta row, without a
    // WordPress pre-hook, then test strict handling during two real updates.
    $count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta}
         WHERE post_id=%d AND meta_key=%s", $id, $key));
    f01_require($count === 1, 'seed expects exactly one protection meta');
    f01_require($wpdb->insert($wpdb->postmeta, [
        'post_id' => $id, 'meta_key' => $key, 'meta_value' => 'path_http'
    ]) === 1, 'cannot seed second row');
    wp_cache_delete($id, 'post_meta');
    echo "PASS: F01 seeded duplicate protected metadata in disposable WordPress\n";
    exit(0);
}
if ($phase === 'hold-b') {
    // Runs in a separate PHP process and separate wpdb connection. Pause
    // AFTER ESP's priority-5 guard has obtained B's DB pending ticket.
    $block = function ($check, $post_id, $meta_key) use ($id, $key, $ready_marker, $resume_marker, $state) {
        if ((int)$post_id !== $id || $meta_key !== $key) { return $check; }
        f01_require(f01_pending($id, $state) >= 1,
            'worker B pre-guard ticket not persisted before barrier');
        file_put_contents($ready_marker, 'ready');
        $deadline = microtime(true) + 35;
        while (!file_exists($resume_marker) && microtime(true) < $deadline) {
            usleep(100000);
        }
        f01_require(file_exists($resume_marker), 'timed out waiting for worker B resume');
        return $check;
    };
    add_filter('update_post_metadata', $block, 7, 5);
    $ok = update_post_meta($id, $key, 'path_http');
    remove_filter('update_post_metadata', $block, 7);
    f01_require($ok !== false, 'worker B update unexpectedly failed');
    ESP_Media_Index::finish_noop_writes(); // Model end-of-request before inspecting.
    f01_require(f01_pending($id, $state) === 0,
        'worker B completion did not retire its own remaining ticket');
    echo "PASS: F01 worker B finishes without early A consumption\n";
    exit(0);
}
if ($phase === 'update-a') {
    f01_require(file_exists($ready_marker), 'worker B not at prehook barrier');
    f01_require(f01_pending($id, $state) === 1, 'worker B ticket not held');
    $hooks = 0;
    $count_hooks = function ($meta_id, $post_id, $meta_key) use ($id, $key, &$hooks) {
        if ((int)$post_id === $id && $meta_key === $key) { ++$hooks; }
    };
    add_action('updated_post_meta', $count_hooks, 30, 4);
    f01_require(update_post_meta($id, $key, 'path_f01_temp') !== false,
        'worker A duplicate metadata update failed');
    remove_action('updated_post_meta', $count_hooks, 30);
    f01_require($hooks === 2, 'WordPress did not fire two per-row post-hooks');
    f01_require(f01_pending($id, $state) === 2,
        'per-row post-hooks prematurely consumed a pending ticket');
    ESP_Media_Index::finish_noop_writes(); // A ends, B is still mid-update.
    f01_require(f01_pending($id, $state) === 1,
        'A must retire exactly one ticket, preserving B');
    f01_require(!ESP_Media_Index::reindex_attachment($id),
        'rebuild must remain blocked until B finishes');
    f01_require(ESP_Media_Index::resolve($uploads['basedir'] . '/' . $relative)['status']
        === 'error', 'dirty protected media must not resolve');
    echo "PASS: F01 one Core update with two row hooks consumes only A ticket; B remains pending\n";
    exit(0);
}
if ($phase === 'restore') {
    f01_require(file_exists($resume_marker), 'worker B release not signalled');
    f01_require(f01_pending($id, $state) === 0, 'B should have finished');
    // Cleanup of deliberately malformed duplicate fixture is DB-only and
    // disposable. Then rebuild from authoritative WordPress metadata.
    $meta_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT meta_id FROM {$wpdb->postmeta}
         WHERE post_id=%d AND meta_key=%s ORDER BY meta_id", $id, $key));
    f01_require(count($meta_ids) === 2, 'two duplicate test rows not present');
    f01_require($wpdb->delete($wpdb->postmeta, [
        'meta_id' => (int)end($meta_ids)
    ]) === 1, 'could not remove disposable duplicate row');
    wp_cache_delete($id, 'post_meta');
    f01_require(ESP_Media_Index::reindex_attachment($id),
        'rebuild after both workers finish failed');
    $path = ESP_Media_Index::resolve($uploads['basedir'] . '/' . $relative);
    f01_require($path['status'] === 'resolved' &&
        $path['protected_path_id'] === 'path_http', 'protected canonical owner did not recover');
    echo "PASS: F01 both workers finished; repaired index preserves protected authorization\n";
}
