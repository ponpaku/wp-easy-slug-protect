<?php
// プラグインが直接呼び出された場合は終了
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

// 安全のため、削除対象のプラグインか確認
$plugin_file = basename(dirname(__FILE__)) . '/easy-slug-protect.php';
if (WP_UNINSTALL_PLUGIN !== $plugin_file) {
    die;
}

// ESP_Config を読み込むためにパスを設定
if (!defined('ESP_PATH')) {
    define('ESP_PATH', plugin_dir_path(__FILE__)); // uninstall.php がプラグインルートにある前提
}
// コンフィグ読み込み
if (file_exists(ESP_PATH . 'includes/class-esp-config.php')) {
    require_once ESP_PATH . 'includes/class-esp-config.php';
} else {
    // コンフィグファイルが見つからない場合、フォールバック値やエラー処理
    // ここでは単純に終了しますが、実際にはログ出力などを検討
    error_log('ESP Uninstall: ESP_Config.php not found.');
    die;
}

// プラグインのデータを完全に削除
global $wpdb;
// コンフィグ読み込み
require_once plugin_dir_path(__FILE__) . 'includes/class-esp-config.php';

// テーブルの削除
foreach(ESP_Config::DB_TABLES as $table_name){
    $table = $wpdb->prefix . $table_name;
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

// オプションの削除
delete_option(ESP_Config::OPTION_KEY);

// 投稿メタデータの削除
if (class_exists('ESP_Config') && defined('ESP_Config::PERMALINK_PATH_META_KEY')) {
    $meta_key = ESP_Config::PERMALINK_PATH_META_KEY;
    $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) );
}

// Cronジョブのスケジュール解除
if (class_exists('ESP_Config') && defined('ESP_Config::DAILY_CLEANUP_HOOK')) {
    wp_clear_scheduled_hook(ESP_Config::DAILY_CLEANUP_HOOK);
}
if (class_exists('ESP_Config') && defined('ESP_Config::INTEGRITY_CHECK_HOOK')) {
    wp_clear_scheduled_hook(ESP_Config::INTEGRITY_CHECK_HOOK);
}

// キャッシュクリア
if (file_exists(ESP_PATH . 'includes/class-esp-filter.php')) {
    require_once ESP_PATH . 'includes/class-esp-filter.php';
    if (class_exists('ESP_Filter') && defined('ESP_Filter::CACHE_KEY')) {
         delete_transient(ESP_Filter::CACHE_KEY);
    }
}

// データベースの最適化
$wpdb->query("OPTIMIZE TABLE {$wpdb->prefix}options");