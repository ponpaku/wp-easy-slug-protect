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
        // DDLやRewriteを変更する前にダウングレードを拒否する。
        // 新しいESPが検証済みの環境へ旧ZIPを有効化しても、古いdbDeltaを適用しない。
        if (!class_exists('ESP_Protection_State') ||
            !ESP_Protection_State::can_run_activation_migration()) {
            error_log('ESP: activation refused before schema changes due to future version');
            return false;
        }
        // Classification must precede creation: a missing marker is not a clean install.
        if (!$this->update_check()) {
            return false;
        }
        $this->schedule_cron_jobs();

        // WP-CLIではWebサーバー種別が未設定のことがある。サーバー側ルールを
        // 推測で更新せず、安全性を確認できる場合だけ初回HTTPへ検証を延期する。
        $cli_without_web_server = defined('WP_CLI') && WP_CLI &&
            trim((string) ($_SERVER['SERVER_SOFTWARE'] ?? '')) === '';
        // メディア保護の初期設定
        if (class_exists('ESP_Media_Protection')) {
            $media_protection = new ESP_Media_Protection();
            if (!$cli_without_web_server) {
                $media_protection->update_htaccess();
            }
            // 初回のメディアキャッシュ生成
            $media_protection->regenerate_media_cache();
        }

        // 投稿フィルターの初期キャッシュ生成
        if (class_exists('ESP_Filter')) {
            $filter = new ESP_Filter();
            $filter->regenerate_protected_posts_cache();
        }

        flush_rewrite_rules();

        // Webサーバー不明のWP-CLIでは保護メディアなしを確認できる場合だけ
        // VERIFY_REQUIREDで有効化し、次のHTTPでRewriteを含めて厳密検証。
        // 保護メディアが残る場合は静的直URLの漏洩を防げないため拒否する。
        if ($cli_without_web_server) {
            return ESP_Protection_State::defer_cli_activation_verification();
        }
        // 初回・再有効化とも検証を必須にする。失敗を成功として扱わない。
        $ready = ESP_Protection_State::verify_and_commit($this);
        if (!$ready && class_exists('ESP_Media_Protection')) {
            // 有効化失敗時はWordPressの無効化フックが実行されない。
            // 保護メタ0件をDBから確定できたときだけ、残存Rewriteを撤去する。
            // 保護メタあり／DB不明なら静的直URLの漏洩を避けるため維持する。
            try {
                if (ESP_Media_Protection::protected_media_records_exist() === false) {
                    $cleanup = (new ESP_Media_Protection())->update_htaccess(false, true);
                    if ($cleanup !== true) {
                        error_log('ESP: Activation failed and media Rewrite cleanup failed; manual review required');
                    }
                } else {
                    error_log('ESP: Activation failed; media Rewrite retained because protected records exist or are unknown');
                }
            } catch (\Throwable $error) {
                error_log('ESP: Activation failed and media Rewrite cleanup could not be completed; manual review required');
            }
        }
        return $ready;
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
        // 既存の日次ジョブを利用し、重複するCron予約を増やさない。
        // 点検は読取専用。検知したRewrite不整合を自動上書きしない。
        if (class_exists('ESP_Protection_State')) {
            ESP_Protection_State::audit();
        }
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
            if (!defined('ESP_DOING_CRON_CACHE_REFRESH')) {
                define('ESP_DOING_CRON_CACHE_REFRESH', true);
            }
            $filter = new ESP_Filter();
            $filter->regenerate_protected_posts_cache();
        }

        // メディア保護キャッシュの更新
        if (class_exists('ESP_Media_Protection')) {
            $media_protection = new ESP_Media_Protection();
            $media_protection->regenerate_media_cache();
        }

        // ログ出力
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ESP: All caches refreshed at ' . current_time('mysql'));
        }
    }

    public function deactivate() {
        // Cronタスクの削除
        wp_clear_scheduled_hook(ESP_Config::DAILY_CLEANUP_HOOK);
        wp_clear_scheduled_hook(ESP_Config::INTEGRITY_CHECK_HOOK);
        wp_clear_scheduled_hook('esp_media_cache_refresh');
        wp_clear_scheduled_hook('esp_all_cache_refresh');
        wp_clear_scheduled_hook('esp_verify_runtime_once');
        wp_clear_scheduled_hook('esp_update_retry');
        
        // キャッシュのクリア
        delete_transient('esp_protected_posts');
        delete_transient('esp_protected_media');
        delete_transient('esp_protected_posts_v2');
        delete_transient('esp_protected_media_v2');
        
        // .htaccessからESPルールを削除
        if (class_exists('ESP_Media_Protection')) {
            $media_protection = new ESP_Media_Protection();
            $result = $media_protection->update_htaccess(false, true);
            if ($result !== true) {
                error_log('ESP: Deactivation could not remove ESP media Rewrite rules; manual .htaccess cleanup may be needed');
            }
        }

        flush_rewrite_rules();
    }

    /** Existing Cron and integrations can continue to use these entry points. */
    public function update_check($lock = null) {
        require_once ESP_PATH . 'includes/class-esp-migrator.php';
        return (new ESP_Migrator())->run($lock);
    }

    public function check_plugin_version($lock = null) {
        require_once ESP_PATH . 'includes/class-esp-updater.php';
        return ESP_Updater::finish_update($this, $lock);
    }
}
