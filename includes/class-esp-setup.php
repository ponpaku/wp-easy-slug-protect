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
        if (!class_exists('ESP_Config')) {
            require_once ESP_PATH . 'includes/class-esp-config.php';
        }
    }

    public function activate() {
        $this->create_tables();
        $this->create_options();
        $this->schedule_cron_jobs();

        // メディア保護の初期設定
        if (class_exists('ESP_Media_Protection')) {
            $media_protection = new ESP_Media_Protection();
            $media_protection->update_htaccess();
            // 初回のメディアキャッシュ生成
            $media_protection->regenerate_media_cache();
        }

        // 投稿フィルターの初期キャッシュ生成
        if (class_exists('ESP_Filter')) {
            $filter = new ESP_Filter();
            $filter->regenerate_protected_posts_cache();
        }

        flush_rewrite_rules();
    }

    /**
     * Cronジョブのスケジュール登録
     */
    public function schedule_cron_jobs() {
        // 日次クリーンアップジョブ
        if (!wp_next_scheduled(ESP_Config::DAILY_CLEANUP_HOOK)) {
            wp_schedule_event(time(), 'daily', ESP_Config::DAILY_CLEANUP_HOOK);
        }

        // パーマリンク整合性チェックジョブ
        if (!wp_next_scheduled(ESP_Config::INTEGRITY_CHECK_HOOK)) {
            // 毎日深夜3時など、アクセスの少ない時間帯を狙う
            wp_schedule_event(strtotime('tomorrow 3:00am'), 'daily', ESP_Config::INTEGRITY_CHECK_HOOK);
        }

        // メディアキャッシュ更新ジョブ
        if (!wp_next_scheduled('esp_media_cache_refresh')) {
            // 1日2回（12時間ごと）にメディアキャッシュを更新
            wp_schedule_event(time(), 'twicedaily', 'esp_media_cache_refresh');
        }

        // 全キャッシュの定期更新ジョブ
        if (!wp_next_scheduled('esp_all_cache_refresh')) {
            // 週1回、日曜日の深夜に全キャッシュを更新
            wp_schedule_event(strtotime('next sunday 2:00am'), 'weekly', 'esp_all_cache_refresh');
        }
    }

    /**
     * クリーンアップタスクの実行 (既存のメソッド)
     */
    public static function run_cleanup_tasks() {
        if (class_exists('ESP_Security')) {
            ESP_Security::cron_cleanup_brute();
            ESP_Security::cron_cleanup_remember();
            ESP_Security::cron_cleanup_sessions();
        }
    }

    /**
     * メディアキャッシュ更新タスク
     */
    public static function run_media_cache_refresh() {
        if (class_exists('ESP_Media_Protection')) {
            ESP_Media_Protection::cron_regenerate_media_cache();
        }
    }

    /**
     * 全キャッシュ更新タスク
     */
    public static function run_all_cache_refresh() {
        // 投稿保護キャッシュの更新
        if (class_exists('ESP_Filter')) {
            $filter = new ESP_Filter();
            $filter->regenerate_protected_posts_cache();
        }

        // メディア保護キャッシュの更新
        if (class_exists('ESP_Media_Protection')) {
            $media_protection = new ESP_Media_Protection();
            $media_protection->regenerate_media_cache();
        }

        // ログ出力
        // error_log('ESP: All caches refreshed at ' . current_time('mysql'));
    }

    public function deactivate() {
        // Cronタスクの削除
        wp_clear_scheduled_hook(ESP_Config::DAILY_CLEANUP_HOOK);
        wp_clear_scheduled_hook(ESP_Config::INTEGRITY_CHECK_HOOK);
        wp_clear_scheduled_hook('esp_media_cache_refresh');
        wp_clear_scheduled_hook('esp_all_cache_refresh');
        
        // キャッシュのクリア
        delete_transient('esp_protected_posts');
        delete_transient('esp_protected_media');
        
        // .htaccessからESPルールを削除
        if (class_exists('ESP_Media_Protection')) {
            $media_protection = new ESP_Media_Protection();
            $media_protection->update_htaccess(); // 保護メディアがない場合、ルールが削除される
        }

        flush_rewrite_rules();
    }

    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_brute = $wpdb->prefix . ESP_Config::DB_TABLES['brute'];
        $table_remember = $wpdb->prefix . ESP_Config::DB_TABLES['remember'];
        $table_session = $wpdb->prefix . ESP_Config::DB_TABLES['session'];

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

        // 通常ログインセッション用テーブル
        $sql3 = "CREATE TABLE IF NOT EXISTS `{$table_session}` (
            `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `path_id` varchar(50) NOT NULL,
            `token` varchar(64) NOT NULL,
            `created` datetime NOT NULL,
            `expires` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `token_unique` (`token`),
            KEY `path_id` (`path_id`),
            KEY `expires` (`expires`)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);

        // エラーチェック
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
     * プラグインバージョンをチェックして必要に応じて更新
     */
    public function check_plugin_version() {
        $current_version = get_option(ESP_Config::VERSION_OPTION_KEY, '0.0.0');
        
        // バージョンが変更された場合の処理
        if (version_compare($current_version, ESP_VERSION, '<')) {
            // バージョンに応じた更新処理
            $this->update_check();
            
            // バージョン情報を更新
            update_option(ESP_Config::VERSION_OPTION_KEY, ESP_VERSION);
        }
    }

    /**
     * バージョン間のマイグレーション処理
     */
    private function migrate_to_version($from, $to) {
        if ($from < 1 && $to >= 1) {
            $this->migrate_to_version_1();
        }
        if ($from < 2 && $to >= 2) {
            $this->migrate_to_version_2();
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

    /**
     * バージョン2へのマイグレーション（通常ログインセッションテーブルの作成）
     */
    private function migrate_to_version_2() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $session_table = $wpdb->prefix . ESP_Config::DB_TABLES['session'];

        $sql = "CREATE TABLE IF NOT EXISTS `{$session_table}` (
            `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `path_id` varchar(50) NOT NULL,
            `token` varchar(64) NOT NULL,
            `created` datetime NOT NULL,
            `expires` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `token_unique` (`token`),
            KEY `path_id` (`path_id`),
            KEY `expires` (`expires`)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        update_option('esp_db_version', 2);
    }

}
