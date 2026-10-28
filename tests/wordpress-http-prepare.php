<?php
/** Prepare only a disposable Apache HTTP fixture; never use against a live site. */
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT')) { exit(1); }
define('WP_INSTALLING', true); define('FS_METHOD', 'direct');
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php'; wp_installing(false);
if (strpos(DB_NAME, 'esp_') !== 0) { exit(1); }
require WP_PLUGIN_DIR . '/esp44-custom/easy-slug-protect.php'; Easy_Slug_Protect::load_dependencies();
require_once ABSPATH . 'wp-admin/includes/file.php';
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
foreach (ESP_Config::DB_TABLES as $name) { $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}$name`"); }
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'esp\\_%'");
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_esp_media_protected_path_id','_esp_permalink_path')");
wp_cache_flush();
$uploads = wp_upload_dir(); wp_mkdir_p($uploads['basedir']);
file_put_contents($uploads['basedir'] . '/.htaccess', "# ESP44 fixture third-party text\n");
if (!(new ESP_Migrator())->run()) { throw new RuntimeException('Fixture migration failed'); }
update_option('home', 'http://localhost:8094'); update_option('siteurl', 'http://localhost:8094');
update_option('permalink_structure', '/%postname%/');
$ids = [];
foreach (['login' => '[esp_login_form]', 'secret' => 'ESP44_SECRET_PAGE [esp_logout_button]', 'public' => 'ESP44_PUBLIC_PAGE'] as $slug => $body) {
    $existing = get_page_by_path('esp44-' . $slug);
    if ($existing) { wp_delete_post($existing->ID, true); }
    $ids[$slug] = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'ESP44 ' . $slug,
        'post_name' => 'esp44-' . $slug, 'post_content' => $body]);
}
$s = get_option('esp_settings');
$s['path'] = ['path_http' => ['id' => 'path_http', 'path' => '/esp44-secret/', 'password' => wp_hash_password('disposable-esp-password'),
    'password_version' => 1, 'login_page' => $ids['login']]];
update_option('esp_settings', $s);
foreach ($wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title IN ('ESP44 protected','ESP44 public')") as $old_id) { wp_delete_attachment((int) $old_id, true); }
foreach (['protected', 'public'] as $kind) {
    $filename = 'esp44-' . $kind . '.txt'; $path = $uploads['basedir'] . '/' . $filename;
    file_put_contents($path, 'ESP44_' . strtoupper($kind) . '_MEDIA');
    $id = wp_insert_attachment(['post_title' => 'ESP44 ' . $kind, 'post_mime_type' => 'text/plain', 'post_status' => 'inherit'], $path);
    update_attached_file($id, $path);
    if ($kind === 'protected') { $wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => '_esp_media_protected_path_id', 'meta_value' => 'path_http']); }
}
// JPEG variants exercise the image-specific caching policy through real Apache.
// These are valid 2x2 JPEG bytes, with a COM marker to identify body leaks.
$base_jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAsICAoIBwsKCQoNDAsNERwSEQ8PESIZGhQcKSQrKigkJyctMkA3LTA9MCcnOEw5PUNFSElIKzZPVU5GVEBHSEX/2wBDAQwNDREPESESEiFFLicuRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUX/wAARCAACAAIDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAABAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCegKgT/9k=', true);
if (!is_string($base_jpeg) || substr($base_jpeg, 0, 2) !== "\xFF\xD8") {
    throw new RuntimeException('Invalid JPEG fixture');
}
foreach (['protected', 'public'] as $kind) {
    $marker = 'ESP31_' . strtoupper($kind) . '_JPEG';
    $jpeg = substr($base_jpeg, 0, 2) . "\xFF\xFE" .
        pack('n', strlen($marker) + 2) . $marker . substr($base_jpeg, 2);
    $name = 'esp31-' . $kind . '.jpg';
    $path = $uploads['basedir'] . '/' . $name;
    if (file_put_contents($path, $jpeg) !== strlen($jpeg)) {
        throw new RuntimeException('Cannot write disposable JPEG fixture');
    }
    $attachment = wp_insert_attachment([
        'post_title' => 'ESP31 ' . $kind . ' JPEG',
        'post_mime_type' => 'image/jpeg',
        'post_status' => 'inherit',
    ], $path);
    if (is_wp_error($attachment) || !$attachment) {
        throw new RuntimeException('Cannot register JPEG fixture');
    }
    update_attached_file($attachment, $path);
    if ($kind === 'protected') {
        $wpdb->insert($wpdb->postmeta, [
            'post_id' => $attachment,
            'meta_key' => '_esp_media_protected_path_id',
            'meta_value' => 'path_http',
        ]);
    }
}
update_option('active_plugins', ['esp44-custom/easy-slug-protect.php']);
ESP_Updater::during_activation(static function () { return ESP_Protection_State::verify_and_commit(new ESP_Setup()); });
file_put_contents(ABSPATH . '.htaccess', "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n");
echo "Prepared disposable HTTP fixture.\n";
