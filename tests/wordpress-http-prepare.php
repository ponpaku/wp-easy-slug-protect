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
update_option('active_plugins', ['esp44-custom/easy-slug-protect.php']);
ESP_Updater::during_activation(static function () { return ESP_Protection_State::verify_and_commit(new ESP_Setup()); });
file_put_contents(ABSPATH . '.htaccess', "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n");
echo "Prepared disposable HTTP fixture.\n";
