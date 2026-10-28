<?php
/**
 * Disposable WordPress 7.1.3 integration smoke test for #63.
 * Enabled ONLY in isolated GitHub Actions after the existing media fixture.
 * It must not run on a real WordPress installation.
 */
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT')) { exit(1); }
define('WP_INSTALLING', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
wp_installing(false);
if (strpos(DB_NAME, 'esp_') !== 0 ||
    !class_exists('ESP_Media_Index') || !class_exists('ESP_Protection_State')) {
    throw new RuntimeException('Unsafe or missing WP/ESP environment');
}
if (!ESP_Media_Index::install_schema()) {
    throw new RuntimeException('dbDelta failed to create dedicated index schema');
}
ESP_Media_Index::run_batch();
ESP_Media_Index::run_batch();
if (!ESP_Media_Index::ready()) {
    throw new RuntimeException('index batch did not publish verified initial scan');
}
$uploads = wp_upload_dir();
$path = $uploads['basedir'] . '/esp31-protected-150x150.jpg';
$public = $uploads['basedir'] . '/esp31-public-150x150.jpg';
$index = ESP_Media_Index::resolve($path);
if ($index['status'] !== 'resolved' ||
    ($index['protected_path_id'] ?? null) !== 'path_http') {
    throw new RuntimeException('protected JPEG thumbnail index mismatch: ' . json_encode($index));
}
$public_index = ESP_Media_Index::resolve($public);
if ($public_index['status'] !== 'resolved' ||
    ($public_index['protected_path_id'] ?? null) !== '') {
    throw new RuntimeException('public JPEG thumbnail index mismatch: ' . json_encode($public_index));
}
$manifest = json_decode(file_get_contents($uploads['basedir'] . '/.esp42-pdf-fixture.json'), true);
if (!is_array($manifest)) { throw new RuntimeException('Missing PDF manifest'); }
foreach (['pdf_full','pdf_thumb','image_unique','gif_poster_unique'] as $variant) {
    $result = ESP_Media_Index::resolve($uploads['basedir'] . '/' . $manifest[$variant]);
    if ($result['status'] !== 'resolved' ||
        ($result['protected_path_id'] ?? null) !== 'path_http') {
        throw new RuntimeException($variant . ' ownership/authorization mismatch: ' . json_encode($result));
    }
}
$unknown = $uploads['basedir'] . '/esp63-unregistered.jpg';
file_put_contents($unknown, 'ESP63_UNREGISTERED_BUT_REAL_FILE');
// Keep strict mode on for the following real Apache curl checks.
update_option(ESP_Media_Index::ENABLE_OPTION, '1', false);
echo "PASS: dbDelta, batched WordPress metadata backfill, original+derivative protection and strict opt-in\n";
