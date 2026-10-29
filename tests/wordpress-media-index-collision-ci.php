<?php
/**
 * Disposable two-phase real-HTTP collision fixture for issue #63.
 * Run via the Apache workflow, never against a production WordPress site.
 */
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT')) { exit(1); }
define('WP_INSTALLING', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
wp_installing(false);
require WP_PLUGIN_DIR . '/esp44-custom/easy-slug-protect.php';
Easy_Slug_Protect::load_dependencies();
ESP_Media_Index::register();
if (strpos(DB_NAME, 'esp_') !== 0 ||
    (string)get_option(ESP_Media_Index::ENABLE_OPTION, '0') !== '1' ||
    !ESP_Media_Index::ready()) {
    throw new RuntimeException('Unsafe or incomplete collision test environment');
}
$id = (int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts}
    WHERE post_type='attachment' AND post_title='ESP31 protected JPEG' LIMIT 1");
if ($id < 1) { throw new RuntimeException('Missing protected media fixture'); }
$meta = wp_get_attachment_metadata($id, true);
if (!is_array($meta)) { throw new RuntimeException('Missing canonical attachment metadata'); }
$uploads = wp_upload_dir();
$public = $uploads['basedir'] . '/esp31-public-150x150.jpg';
$mode = getenv('ESP_COLLISION_PHASE');
if ($mode === 'prepare') {
    $meta['sizes']['esp63_new_owner_overlap'] = [
        'file' => 'esp31-public-150x150.jpg',
        'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg'
    ];
    if (!wp_update_attachment_metadata($id, $meta) ||
        ESP_Media_Index::resolve($public)['status'] !== 'error') {
        throw new RuntimeException('New protected owner did not block public path');
    }
} elseif ($mode === 'restore') {
    unset($meta['sizes']['esp63_new_owner_overlap']);
    if (!wp_update_attachment_metadata($id, $meta) ||
        !ESP_Media_Index::reindex_attachment($id)) {
        throw new RuntimeException('Failed to restore protected metadata');
    }
    $resolved = ESP_Media_Index::resolve($public);
    if ($resolved['status'] !== 'resolved' ||
        $resolved['protected_path_id'] !== '') {
        throw new RuntimeException('Original public owner not restored');
    }
} else {
    throw new RuntimeException('Unknown collision test phase');
}
echo "PASS: real HTTP collision fixture " . $mode . "\n";
