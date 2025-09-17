<?php
/**
 * Easy Slug Protect アンインストール処理
 */

// プラグインが直接呼び出された場合は終了
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

// プラグインパスを定義
if (!defined('ESP_PATH')) {
    define('ESP_PATH', plugin_dir_path(__FILE__));
}

// コンフィグファイルを読み込み
if (file_exists(ESP_PATH . 'includes/class-esp-config.php')) {
    require_once ESP_PATH . 'includes/class-esp-config.php';
} else {
    error_log('ESP Uninstall: Configuration file not found.');
    return;
}

// データベースの削除処理
global $wpdb;

// テーブルの削除（存在確認してから削除）
foreach (ESP_Config::DB_TABLES as $table_name) {
    $table = $wpdb->prefix . $table_name;
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

// オプションの削除
delete_option(ESP_Config::OPTION_KEY);
delete_option(ESP_Config::VERSION_OPTION_KEY);
delete_option('esp_db_version');
delete_option('esp_integrity_check_progress');

// 投稿メタデータの削除
$wpdb->delete($wpdb->postmeta, array('meta_key' => ESP_Config::PERMALINK_PATH_META_KEY));

// メディア保護メタデータの削除
if (defined('ESP_Media_Protection::META_KEY_PROTECTED_PATH')) {
    $wpdb->delete($wpdb->postmeta, array('meta_key' => '_esp_media_protected_path_id'));
}

// トランジェントの削除
delete_transient('esp_protected_posts');
delete_transient('esp_protected_media');
delete_transient('esp_path_index');

// Cronジョブのスケジュール解除
$cron_hooks = [
    ESP_Config::DAILY_CLEANUP_HOOK,
    ESP_Config::INTEGRITY_CHECK_HOOK,
    'esp_media_cache_refresh',
    'esp_all_cache_refresh'
];

foreach ($cron_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

// .htaccessからESPルールを削除（Apache環境の場合）
$upload_dir = wp_upload_dir();
$htaccess_file = $upload_dir['basedir'] . '/.htaccess';

if (file_exists($htaccess_file)) {
    $content = file_get_contents($htaccess_file);
    $pattern = '/# BEGIN ESP Media Protection.*?# END ESP Media Protection\s*/s';
    $content = preg_replace($pattern, '', $content);
    file_put_contents($htaccess_file, $content);
}

// データベースの最適化（オプション）
$wpdb->query("OPTIMIZE TABLE {$wpdb->options}");
$wpdb->query("OPTIMIZE TABLE {$wpdb->postmeta}");