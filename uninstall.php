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

// データベースの最適化
$wpdb->query("OPTIMIZE TABLE {$wpdb->prefix}options");