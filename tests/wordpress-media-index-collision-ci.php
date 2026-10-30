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
} elseif ($mode === 'by_mid_prepare') {
    // WordPress Core sanitizes update_metadata_by_mid() AFTER its prefilter.
    // A protected B must reserve the actual sanitized path belonging to
    // public A before WP commits B's new _wp_attached_file.
    $mid = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT meta_id FROM {$wpdb->postmeta}
         WHERE post_id=%d AND meta_key='_wp_attached_file' LIMIT 1", $id));
    $original = (string)get_post_meta($id, '_wp_attached_file', true);
    if ($mid < 1 || $original === '') { throw new RuntimeException('Missing by-mid attachment meta'); }
    update_option('esp63_test_by_mid_original', $original, false);
    $calls = 0;
    $sanitizer = function ($value) use (&$calls) {
        ++$calls;
        return 'esp31-public-150x150.jpg';
    };
    add_filter('sanitize_post_meta__wp_attached_file', $sanitizer, 10, 1);
    $saved = update_metadata_by_mid('post', $mid, $original . '-ignored-input');
    remove_filter('sanitize_post_meta__wp_attached_file', $sanitizer, 10);
    if (!$saved || $calls !== 1 ||
        (string)get_post_meta($id, '_wp_attached_file', true) !==
            'esp31-public-150x150.jpg' ||
        ESP_Media_Index::resolve($public)['status'] !== 'error' ||
        !ESP_Media_Index::ready()) {
        throw new RuntimeException('by-mid sanitized path was not pre-reserved before SQL');
    }
} elseif ($mode === 'by_mid_metadata_prepare') {
    // Verify the serialized _wp_attachment_metadata path as well as the
    // scalar _wp_attached_file path. Core passes a serialized value to
    // update_postmeta after sanitize_meta() has changed the sizes array.
    $mid = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT meta_id FROM {$wpdb->postmeta}
         WHERE post_id=%d AND meta_key='_wp_attachment_metadata' LIMIT 1", $id));
    if ($mid < 1) { throw new RuntimeException('Missing by-mid attachment metadata row'); }
    update_option('esp63_test_by_mid_original_meta', $meta, false);
    $calls = 0;
    $sanitizer = function ($value) use (&$calls) {
        ++$calls;
        if (!is_array($value)) { return $value; }
        $value['sizes']['esp63_sanitized_collision'] = [
            'file'=>'esp31-public-150x150.jpg',
            'width'=>150,'height'=>150,'mime-type'=>'image/jpeg'
        ];
        return $value;
    };
    add_filter('sanitize_post_meta__wp_attachment_metadata', $sanitizer, 10, 1);
    $saved = update_metadata_by_mid('post', $mid, $meta);
    remove_filter('sanitize_post_meta__wp_attachment_metadata', $sanitizer, 10);
    $updated_meta = wp_get_attachment_metadata($id, true);
    if (!$saved || $calls !== 1 ||
        !isset($updated_meta['sizes']['esp63_sanitized_collision']) ||
        ESP_Media_Index::resolve($public)['status'] !== 'error' ||
        !ESP_Media_Index::ready()) {
        throw new RuntimeException('by-mid sanitized serialized metadata was not pre-reserved');
    }
} elseif ($mode === 'by_mid_metadata_restore') {
    $mid = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT meta_id FROM {$wpdb->postmeta}
         WHERE post_id=%d AND meta_key='_wp_attachment_metadata' LIMIT 1", $id));
    $original = get_option('esp63_test_by_mid_original_meta', false);
    if ($mid < 1 || !is_array($original) ||
        !update_metadata_by_mid('post', $mid, $original) ||
        !ESP_Media_Index::reindex_attachment($id)) {
        throw new RuntimeException('Could not restore original attachment metadata by-mid');
    }
    delete_option('esp63_test_by_mid_original_meta');
    $resolved = ESP_Media_Index::resolve($public);
    if ($resolved['status'] !== 'resolved' || $resolved['protected_path_id'] !== '') {
        throw new RuntimeException('by-mid metadata restoration left public owner blocked');
    }
} elseif ($mode === 'by_mid_restore') {
    $mid = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT meta_id FROM {$wpdb->postmeta}
         WHERE post_id=%d AND meta_key='_wp_attached_file' LIMIT 1", $id));
    $original = get_option('esp63_test_by_mid_original', '');
    if ($mid < 1 || !is_string($original) || $original === '' ||
        !update_metadata_by_mid('post', $mid, $original) ||
        !ESP_Media_Index::reindex_attachment($id)) {
        throw new RuntimeException('Could not restore original attached-file by-mid');
    }
    delete_option('esp63_test_by_mid_original');
    $resolved = ESP_Media_Index::resolve($public);
    if ($resolved['status'] !== 'resolved' || $resolved['protected_path_id'] !== '') {
        throw new RuntimeException('by-mid restoration left public owner blocked');
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
