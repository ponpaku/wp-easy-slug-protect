<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * セットアップクラス
 */
class ESP_Setup {
    public function __construct() {
        require_once ESP_PATH . 'includes/class-esp-config.php';
    }

    public function activate() {
        $this->create_tables();
        $this->create_options();
        flush_rewrite_rules();
    }

    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_brute = $wpdb->prefix . ESP_Config::DB_TABLES['brute'];
        $table_remember = $wpdb->prefix . ESP_Config::DB_TABLES['remember'];

        // ブルートフォース対策用テーブル
        $sql1 = "CREATE TABLE IF NOT EXISTS `{$table_brute}` (
            `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `ip_address` varchar(45) NOT NULL,
            `path` varchar(255) NOT NULL,
            `time` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `ip_path_time` (`ip_address`, `path`, `time`)
        ) {$charset_collate};";

        // ログイン保持用テーブル
        $sql2 = "CREATE TABLE IF NOT EXISTS `{$table_remember}` (
            `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `path` varchar(255) NOT NULL,
            `user_id` varchar(32) NOT NULL,
            `token` varchar(64) NOT NULL,
            `created` datetime NOT NULL,
            `expires` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `user_token` (`user_id`, `token`),
            KEY `path_expires` (`path`, `expires`)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);

        // エラーチェック（オプショナル）
        if ($wpdb->last_error) {
            error_log('ESP Table Creation Error: ' . $wpdb->last_error);
        }
    }

    /**
     * コンフィグに基づいてオプション作成
     */
    private function create_options() {
        if (get_option(ESP_Config::OPTION_KEY) === false) {                
            add_option(ESP_Config::OPTION_KEY, ESP_Config::OPTION_DEFAULTS);
        }
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

}
