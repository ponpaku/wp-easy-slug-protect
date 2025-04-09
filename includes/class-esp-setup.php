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
            `path_id` varchar(50) NOT NULL,
            `time` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `ip_path_time` (`ip_address`, `path`, `time`),
            KEY `ip_path_id` (`ip_address`, `path_id`)
        ) {$charset_collate};";

        // ログイン保持用テーブル
        $sql2 = "CREATE TABLE IF NOT EXISTS `{$table_remember}` (
            `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `path` varchar(255) NOT NULL,
            `path_id` varchar(50) NOT NULL,
            `user_id` varchar(32) NOT NULL,
            `token` varchar(64) NOT NULL,
            `created` datetime NOT NULL,
            `expires` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `user_token` (`user_id`, `token`),
            KEY `path_expires` (`path`, `expires`),
            KEY `path_id` (`path_id`)
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

    /**
     * アップデート確認と実行
     */
    public function update_check() {
        $current_db_version = get_option('esp_db_version', 0);
        $required_db_version = ESP_Config::OPTION_DEFAULTS['db_version'];
        
        if ($current_db_version < $required_db_version) {
            $this->migrate_to_version($current_db_version, $required_db_version);
            update_option('esp_db_version', $required_db_version);
        }
    }

    /**
     * バージョン間のマイグレーション処理
     */
    private function migrate_to_version($from, $to) {
        if ($from < 1 && $to >= 1) {
            $this->migrate_to_version_1();
        }
        // 将来的に処理をここに追加
    }

    /**
     * バージョン1へのマイグレーション（パスにIDを追加）
     */
    private function migrate_to_version_1() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $brute_table = $wpdb->prefix . ESP_Config::DB_TABLES['brute'];
        $remember_table = $wpdb->prefix . ESP_Config::DB_TABLES['remember'];
        
        // ブルートフォーステーブルにpath_idカラム追加
        $wpdb->query("ALTER TABLE `{$brute_table}` ADD COLUMN `path_id` varchar(50) NOT NULL AFTER `path`");
        $wpdb->query("ALTER TABLE `{$brute_table}` ADD INDEX `ip_path_id` (`ip_address`, `path_id`)");
        
        // ログイン保持テーブルにpath_idカラム追加
        $wpdb->query("ALTER TABLE `{$remember_table}` ADD COLUMN `path_id` varchar(50) NOT NULL AFTER `path`");
        $wpdb->query("ALTER TABLE `{$remember_table}` ADD INDEX `path_id` (`path_id`)");
        
        // パスの配列をID付きの形式に変更
        $settings = ESP_Option::get_all_settings();
        
        if (isset($settings['path']) && is_array($settings['path'])) {
            $new_paths = array();
            $path_id_map = array(); // パスとIDのマッピング
            
            foreach ($settings['path'] as $index => $path_data) {
                $id = 'path_' . uniqid();
                $new_paths[$id] = $path_data;
                $new_paths[$id]['id'] = $id;
                
                // パスとIDのマッピングを保存
                $path_id_map[$path_data['path']] = $id;
            }
            
            $settings['path'] = $new_paths;
            ESP_Option::update_settings($settings);
            
            // 既存のブルートフォースデータを更新
            foreach ($path_id_map as $path => $id) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE `{$brute_table}` SET `path_id` = %s WHERE `path` = %s",
                    $id, $path
                ));
                
                $wpdb->query($wpdb->prepare(
                    "UPDATE `{$remember_table}` SET `path_id` = %s WHERE `path` = %s",
                    $id, $path
                ));
            }
        }
        
        // DBバージョンを更新
        update_option('esp_db_version', 1);
    }


    public function deactivate() {
        flush_rewrite_rules();
    }

}
