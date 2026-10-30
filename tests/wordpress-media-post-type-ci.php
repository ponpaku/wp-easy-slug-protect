<?php
/**
 * R01: post->attachment ownership transition through real WordPress Core.
 * Four separate PHP/DB workers plus Apache HTTP; disposable CI only.
 */
$phase = getenv('ESP_TYPE_PHASE');
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT') ||
    !in_array($phase, ['seed', 'failure', 'convert', 'during', 'after'], true)) { exit(1); }
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
if (strpos(DB_NAME, 'esp_') !== 0 || !class_exists('ESP_Media_Index')) {
    throw new RuntimeException('R01 requires disposable real WordPress');
}
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
global $wpdb;
function esp_type_assert($ok, $description) {
    if (!$ok) { throw new RuntimeException('R01 post-type: ' . $description); }
}
$uploads = wp_upload_dir();
$path = $uploads['basedir'] . '/esp31-public-150x150.jpg';
$dir = $uploads['basedir'] . '/.esp-type-transition-ci';
wp_mkdir_p($dir);
$state = ESP_Media_Index::state_table();
$id = (int)get_option('esp_ci_type_transition_id', 0);
if ($phase === 'seed') {
    esp_type_assert(ESP_Media_Index::enabled() && ESP_Media_Index::ready(),
        'strict index must be READY');
    $before = ESP_Media_Index::resolve($path);
    esp_type_assert($before['status'] === 'resolved' &&
        $before['protected_path_id'] === '', 'original public owner missing');
    esp_type_assert($id === 0, 'leftover conversion fixture');
    $id = wp_insert_post([
        'post_type' => 'post', 'post_status' => 'draft',
        'post_title' => 'ESP_R01_post_type_promotion',
    ], true);
    esp_type_assert(!is_wp_error($id) && (int)$id > 0, 'cannot create ordinary post');
    $id = (int)$id;
    // WordPress metadata APIs on a non-attachment do not create index rows.
    esp_type_assert(add_post_meta($id, '_wp_attached_file',
        'esp31-public-150x150.jpg', true) !== false, 'cannot add attached_file');
    esp_type_assert(add_post_meta($id, ESP_Media_Index::PROTECTION_META,
        'path_http', true) !== false, 'cannot add protection ID');
    update_option('esp_ci_type_transition_id', $id, false);
    esp_type_assert(ESP_Media_Index::resolve($path)['status'] === 'resolved',
        'ordinary post must not yet appear as an attachment owner');
    @unlink($dir . '/prehook');
    @unlink($dir . '/resume');
    echo "PASS: R01 seeded an ordinary post with a protected competing file path\n";
    exit;
}
esp_type_assert($id > 0, 'conversion fixture ID absent');
if ($phase === 'failure') {
    // Simulate a failure in the global-coverage UPDATE. WordPress's
    // pre_post_update action cannot cancel by returning false: the ESP guard
    // must throw BEFORE wp_posts changes.
    $intercepted = 0;
    $fail_guard = function ($sql) use (&$intercepted) {
        if (strpos($sql, 'audit_cursor=0 WHERE attachment_id=0') !== false &&
            strpos($sql, 'UPDATE ') !== false) {
            ++$intercepted;
            return 'R01_INTENTIONALLY_INVALID_GLOBAL_FENCE_SQL';
        }
        return $sql;
    };
    add_filter('query', $fail_guard, 1);
    $threw = false;
    $wpdb->suppress_errors(true);
    try {
        wp_update_post(['ID' => $id, 'post_type' => 'attachment',
            'post_status' => 'inherit'], true);
    } catch (RuntimeException $e) {
        $threw = strpos($e->getMessage(), 'cannot revoke coverage') !== false;
    } finally {
        remove_filter('query', $fail_guard, 1);
        $wpdb->suppress_errors(false);
    }
    esp_type_assert($intercepted === 1 && $threw,
        'failed pre-update global fence did not stop the Core write');
    esp_type_assert(get_post_type($id) === 'post' &&
        ESP_Media_Index::ready(), 'failed fence changed wp_posts or global READY');
    ESP_Media_Index::finish_noop_writes();
    esp_type_assert(ESP_Media_Index::resolve($path)['status'] === 'resolved',
        'failed post-type transition unexpectedly changed public ownership');
    echo "PASS: R01 failed global fence blocks post-type UPDATE before Core writes\n";
    exit;
}
if ($phase === 'convert') {
    $seen_attachment_updated = false;
    add_action('attachment_updated', function ($updated) use ($id, &$seen_attachment_updated) {
        if ((int)$updated === $id) { $seen_attachment_updated = true; }
    }, 50, 1);
    // The production pre_post_update guard runs at priority 5. This barrier
    // holds Core BEFORE the wp_posts UPDATE, after the durable index fence.
    add_action('pre_post_update', function ($post_id, $data) use ($id, $dir) {
        if ((int)$post_id !== $id || ($data['post_type'] ?? '') !== 'attachment') { return; }
        file_put_contents($dir . '/prehook', 'fenced');
        $deadline = microtime(true) + 35;
        while (!file_exists($dir . '/resume') && microtime(true) < $deadline) {
            usleep(100000);
        }
        esp_type_assert(file_exists($dir . '/resume'), 'conversion barrier timed out');
    }, 20, 2);
    $changed = wp_update_post([
        'ID' => $id, 'post_type' => 'attachment',
        'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg',
    ], true);
    esp_type_assert(!is_wp_error($changed) && (int)$changed === $id,
        'wp_update_post promotion failed');
    esp_type_assert($seen_attachment_updated, 'WordPress attachment_updated hook did not fire');
    ESP_Media_Index::finish_noop_writes(); // Simulate end of originating PHP request.
    $n = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT pending_count FROM $state WHERE attachment_id=%d", $id));
    esp_type_assert($n === 0, 'originating type-change ticket did not complete');
    echo "PASS: R01 wp_update_post promoted post with attachment_updated after fence\n";
    exit;
}
if ($phase === 'during') {
    esp_type_assert(get_post_type($id) === 'post',
        'Core must still be waiting before post-type UPDATE');
    $control = $wpdb->get_row("SELECT status,revision FROM $state WHERE attachment_id=0", ARRAY_A);
    $pending = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT pending_count FROM $state WHERE attachment_id=%d", $id));
    esp_type_assert($control['status'] === 'dirty' && $pending > 0,
        'global coverage and per-post pending must fence transition');
    // A concurrent Cron scan must NOT purge the temporary "orphan" pending
    // row or republish global READY before WordPress commits the conversion.
    ESP_Media_Index::run_batch();
    $control_after = $wpdb->get_row(
        "SELECT status FROM $state WHERE attachment_id=0", ARRAY_A);
    $pending_after = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT pending_count FROM $state WHERE attachment_id=%d", $id));
    esp_type_assert($control_after['status'] === 'dirty' && $pending_after > 0,
        'Cron illegally dropped pending conversion or republished READY');
    esp_type_assert(ESP_Media_Index::resolve($path)['status'] === 'error',
        'old public owner must fail closed before Core update');
    echo "PASS: R01 Cron cannot republish READY or erase pre-update transition ticket\n";
    exit;
}
esp_type_assert(get_post_type($id) === 'attachment',
    'Core must have completed post type conversion');
$pending = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT pending_count FROM $state WHERE attachment_id=%d", $id));
esp_type_assert($pending === 0, 'conversion request left a pending ticket');
for ($i = 0; $i < 5; ++$i) {
    ESP_Media_Index::run_batch();
    if (ESP_Media_Index::ready() &&
        ESP_Media_Index::resolve($path)['status'] === 'ambiguous') { break; }
}
$resolved = ESP_Media_Index::resolve($path);
esp_type_assert(ESP_Media_Index::ready() && $resolved['status'] === 'ambiguous',
    'Cron must repair both public and protected owners as ambiguous');
echo "PASS: R01 WordPress source-of-truth conflict indexed and ready without public authorization\n";
