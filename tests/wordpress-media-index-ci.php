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
// WP_INSTALLING intentionally skips normal active-plugin boot in this fixture.
// Load the plugin dependencies explicitly, like wordpress-http-prepare.php.
require WP_PLUGIN_DIR . '/esp44-custom/easy-slug-protect.php';
Easy_Slug_Protect::load_dependencies();
// The fixture uses WP_INSTALLING; register the production metadata hooks here.
ESP_Media_Index::register();
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

// WordPress native metadata API must invalidate + republish the derived rows.
$id = (int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'
    AND post_title='ESP31 protected JPEG' LIMIT 1");
if ($id <= 0 || !update_post_meta($id, ESP_Media_Index::PROTECTION_META, '')) {
    throw new RuntimeException('Cannot unprotect fixture via normal WP API');
}
$unprotected = ESP_Media_Index::resolve($path);
if ($unprotected['status'] !== 'resolved' || $unprotected['protected_path_id'] !== '') {
    throw new RuntimeException('WP metadata OFF transition not indexed');
}
if (!update_post_meta($id, ESP_Media_Index::PROTECTION_META, 'path_http')) {
    throw new RuntimeException('Cannot reprotect fixture via WP API');
}
$restored = ESP_Media_Index::resolve($path);
if ($restored['status'] !== 'resolved' || $restored['protected_path_id'] !== 'path_http') {
    throw new RuntimeException('WP metadata ON transition not indexed');
}
// A no-op update has a pre-hook but may not have a WordPress after-hook.
update_post_meta($id, ESP_Media_Index::PROTECTION_META, 'path_http');
ESP_Media_Index::finish_noop_writes();
if (ESP_Media_Index::resolve($path)['status'] !== 'resolved') {
    throw new RuntimeException('No-op metadata update stranded pending state');
}
$meta = wp_get_attachment_metadata($id, true);
$new_name = 'esp63-added-175x175.jpg';
copy($uploads['basedir'] . '/esp31-protected.jpg', $uploads['basedir'] . '/' . $new_name);
$meta['sizes']['esp63_extra'] = ['file'=>$new_name, 'width'=>175,
    'height'=>175, 'mime-type'=>'image/jpeg'];
wp_update_attachment_metadata($id, $meta);
$new_owner = ESP_Media_Index::resolve($uploads['basedir'] . '/' . $new_name);
if ($new_owner['status'] !== 'resolved' || $new_owner['protected_path_id'] !== 'path_http') {
    throw new RuntimeException('new WP subsize was not indexed on metadata update');
}
// Keep strict mode on for the following real Apache curl checks.
update_option(ESP_Media_Index::ENABLE_OPTION, '1', false);
echo "PASS: dbDelta, batched WordPress metadata backfill, original+derivative protection and strict opt-in\n";
