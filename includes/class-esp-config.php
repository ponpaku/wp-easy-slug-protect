<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

class ESP_Config {
    const TEXT_DOMAIN = 'easy-slug-protect';
    const OPTION_KEY = 'esp_settings';
    const LITESPEED_QUERY_KEY = 'esp_media_key';
    const COOKIE_PREFIX_DEFAULT = 'esp';
    
    // バージョン管理用の定数を追加
    const VERSION_OPTION_KEY = 'esp_plugin_version';
    // Cronフック名の定数
    const DAILY_CLEANUP_HOOK = 'esp_daily_cleanup'; 
    const INTEGRITY_CHECK_HOOK = 'esp_integrity_check_permalinks'; // 整合性チェック用

    // メタデータのキー
    const PERMALINK_PATH_META_KEY = '_esp_permalink_path'; 

    const OPTION_DEFAULTS = array(
        'path' => array(),
        'brute' => array(
            'attempts_threshold' => 5,  // 試行回数の上限
            'time_frame' => 10,         // 試行回数のカウント期間（分）
            'block_time_frame' => 60,   // ブロック時間（分）
            'whitelist_ips' => ''       // ホワイトリストIPアドレス (カンマ区切り)
        ),
        'remember' => array(
            'time_frame' => 15         // ログイン保持期間（日）
        ),
        'mail' => array(
            'enable_notifications' => true,
            'include_password' => true,
            'notifications' => array(
                'new_path' => true,
                'password_change' => true,
                'path_remove' => true,
                'brute_force' => true,
                'critical_error' => true
            )
        ),
        'media' => array(
            'enabled' => true,
            'delivery_method' => 'auto',
            'fast_gate_enabled' => false,
            'litespeed_key' => '',
            'media_gate_key' => ''
        ),
        'db_version' => 4 // DBバージョン

    );

    const DB_TABLES = array(
        'remember' => 'esp_login_remember',
        'brute' => 'esp_login_attempts',
        'session' => 'esp_login_session'
    );

    /**
     * Cookie名のプレフィックスを取得
     */
    public static function get_cookie_prefixes() {
        $base = rtrim(self::COOKIE_PREFIX_DEFAULT, '_');

        return array(
            'session' => $base . '_auth_',
            'remember_id' => $base . '_remember_id_',
            'remember_token' => $base . '_remember_token_',
        );
    }
}
