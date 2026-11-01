<?php
/**
 * Issue #43: explicitly opted-in strict and compatibility media scope.
 *
 * Uses only the disposable esp_* WordPress/MySQL CI database. The HTTP
 * assertions are performed separately through real Apache requests.
 * ESP_SCOPE_PHASE=compat|strict|index-gap|dirty|restore
 */
$phase = getenv('ESP_SCOPE_PHASE');
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' ||
    !getenv('ESP_WP_ROOT') ||
    !in_array($phase, ['compat', 'strict', 'index-gap', 'dirty', 'restore'], true)) {
    exit(1);
}
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
if (strpos(DB_NAME, 'esp_') !== 0 || !class_exists('ESP_Media_Index')) {
    throw new RuntimeException('#43 refuses non-disposable WordPress');
}

function esp_scope_assert($condition, $message) {
    if (!$condition) { throw new RuntimeException('#43: ' . $message); }
}
global $wpdb;
$files = ESP_Media_Index::files_table();
$state = ESP_Media_Index::state_table();
$uploads = wp_upload_dir();
$relative = 'esp31-protected-150x150.jpg';
$file_path = $uploads['basedir'] . '/' . $relative;
$id = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'
     AND post_title=%s LIMIT 1", 'ESP31 protected JPEG'));
esp_scope_assert($wpdb->last_error === '' && $id > 0, 'protected WordPress owner missing');

if ($phase === 'compat') {
    // Only the explicitly selected test mode is modified, never production.
    update_option(ESP_Media_Index::ENABLE_OPTION, '0', false);
    esp_scope_assert(!ESP_Media_Index::enabled(), 'compatibility mode not selected');
    echo "PASS: #43 compatibility mode selected; externally unmanaged file is outside protection warranty\n";
    exit(0);
}
if ($phase === 'strict') {
    update_option(ESP_Media_Index::ENABLE_OPTION, '1', false);
    esp_scope_assert(ESP_Media_Index::enabled() && ESP_Media_Index::ready(),
        'strict opt-in requires verified global READY');
    $resolved = ESP_Media_Index::resolve($file_path);
    esp_scope_assert($resolved['status'] === 'resolved' &&
        $resolved['attachment_id'] === $id &&
        $resolved['protected_path_id'] === 'path_http',
        'indexed WordPress thumbnail must remain protected');
    $unknown = ESP_Media_Index::resolve($uploads['basedir'] . '/esp63-unregistered.jpg');
    esp_scope_assert($unknown['status'] === 'unresolved',
        'unregistered disk file unexpectedly indexed as WordPress media');
    echo "PASS: #43 strict mode explicitly selected; registered thumbnail protected, unknown not attested\n";
    exit(0);
}

esp_scope_assert(ESP_Media_Index::enabled() && ESP_Media_Index::ready(),
    'strict verified READY required for corruption probes');
if ($phase === 'index-gap') {
    // Simulate a broken index while canonical WP metadata still owns the file.
    // A missing row must NOT become an anonymous public image.
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM $files WHERE path_hash=%s AND attachment_id=%d",
        hash('sha256', $relative), $id));
    esp_scope_assert($deleted > 0 && $wpdb->last_error === '',
        'could not remove indexed derivative from disposable fixture');
    esp_scope_assert(ESP_Media_Index::resolve($file_path)['status'] === 'unresolved',
        'index-gap must be unresolved, not proven public');
    echo "PASS: #43 deliberately missing index derivative is unresolved under strict mode\n";
    exit(0);
}
if ($phase === 'dirty') {
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE $state SET status='dirty', revision=revision+1,
         pending_count=0,retry_after=0,changed_at=%d
         WHERE attachment_id=%d AND status='ready'", time(), $id));
    esp_scope_assert($updated === 1 && $wpdb->last_error === '',
        'could not dirty indexed WordPress owner');
    esp_scope_assert(ESP_Media_Index::resolve($file_path)['status'] === 'error',
        'dirty owner cannot produce an affirmative ownership');
    echo "PASS: #43 dirty registered attachment is an authorization error, not public\n";
    exit(0);
}
// Restore the derivative from canonical metadata through the production API,
// not by hand-crafting new index rows or altering the protected file.
esp_scope_assert(ESP_Media_Index::reindex_attachment($id), 'failed canonical rebuild');
$restored = ESP_Media_Index::resolve($file_path);
esp_scope_assert($restored['status'] === 'resolved' &&
    $restored['protected_path_id'] === 'path_http' &&
    ESP_Media_Index::ready(), 'protected WordPress derivative not restored');
echo "PASS: #43 protected derivative restored from WordPress source-of-truth\n";
