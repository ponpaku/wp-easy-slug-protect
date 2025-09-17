<?php
if (!defined('ABSPATH')) {
    exit;
}

class ESP_Settings {
    
    const DEFAULT_SETTINGS = ESP_Config::OPTION_DEFAULTS;

    /**
     * シングルトンインスタンス
     * @var ESP_Settings
     */
    private static $instance = null;
    /**
     * @var bool 初期化フラグ
     */
    private static $initialized = false;

    /**
     * @var ESP_Sanitize サニタイズクラスのインスタンス
     */
    private $sanitize;

    /**
     * @var ESP_Mail メールクラスのインスタンス
     */
    private $mail;

    /**
     * シングルトンのためprivateコンストラクタ
     */
    private function __construct() {
        $this->sanitize = new ESP_Sanitize();
        $this->mail = ESP_Mail::get_instance();
    }

    /**
     * インスタンスの取得
     * 
     * @return ESP_Settings
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 設定の初期化（実行は一度だけ）
     */
    public function init() {
        if (self::$initialized) {
            return;
        }

        add_action('admin_init', [$this, 'register_settings']);
        add_action('update_option_' . ESP_Config::OPTION_KEY, [$this, 'handle_settings_update'], 10, 2);

        self::$initialized = true;
    }

    /**
     * 設定の登録
     */
    public function register_settings() {
        register_setting(
            'esp_settings_group',
            ESP_Config::OPTION_KEY,
            array(
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => ESP_Config::OPTION_DEFAULTS
            )
        );
    }

    /**
     * 
     */
    public function sanitize_settings($input) {
        $sanitized = array();

        // error_log('esp: '. json_encode($input));
        
        // パス設定のサニタイズ
        $sanitized['path'] = $this->sanitize->sanitize_protected_paths(
            isset($input['path']) ? $input['path'] : ESP_Option::get_current_setting('path')
        );
        // error_log('esp: '.  json_encode($sanitized));

        // ブルートフォース設定のサニタイズ
        $sanitized['brute'] = $this->sanitize->sanitize_bruteforce_settings(
            isset($input['brute']) ? $input['brute'] : ESP_Option::get_current_setting('brute')
        );
        // error_log('esp: '.  json_encode($sanitized));

        // ログイン保持設定のサニタイズ
        $sanitized['remember'] = $this->sanitize->sanitize_remember_settings(
            isset($input['remember']) ? $input['remember'] : ESP_Option::get_current_setting('remember')
        );
        // error_log('esp: '.  json_encode($sanitized));

        // メール設定のサニタイズ
        $sanitized['mail'] = $this->sanitize->sanitize_mail_settings(
            isset($input['mail']) ? $input['mail'] : ESP_Option::get_current_setting('mail')
        );

        return $sanitized;
    }

    /**
     * ハンドラーへのハンドラー
     */
    public function handle_settings_update($old_value, $value) {
        // パス設定の変更を処理
        if (isset($value['path'])) {
            $this->handle_update_path(
                $old_value['path'] ?? array(),
                $value['path']
            );
            
            // パスマッチャーのキャッシュを無効化
            if (class_exists('ESP_Path_Matcher')) {
                ESP_Path_Matcher::invalidate();
            }
        }

        // メディア保護の設定更新
        if (class_exists('ESP_Media_Protection')) {
            $media_protection = new ESP_Media_Protection();
            $media_protection->on_settings_save();
        }

        // 現状他は不要
        return;
    }
    
    /**
     * パス設定が変更された時のハンドラー
     * 
     * @param array $old_value 古い設定値
     * @param array $new_value 新しい設定値
     */
    private function handle_update_path($old_value, $new_value) {
        if (!is_array($new_value) || !is_array($old_value)) {
            return;
        }

        $old_paths_map = array();
        foreach ($old_value as $path_id => $old_path) {
            if (isset($old_path['path'])) {
                $old_paths_map[$path_id] = $old_path;
            }
        }

        $current_paths = array();
        // 削除されたパスの検出
        foreach ($new_value as $path_id => $new_path) {
            if (!isset($new_path['path'])) {
                continue;
            }
            $current_paths[] = $path_id;
        }

        // 削除されたパスの検出と通知
        foreach ($old_paths_map as $path_id => $old_path) {
            if (!in_array($path_id, $current_paths, true)) {
                $this->mail->notify_path_removed($old_path['path']);
            }
        }

        // 保護ページリストの更新
        $filter = new ESP_Filter;
        $filter->reset_cache();
    }

    /**
     * brute
     */
    private function handle_update_brute($old_value, $value){
        return;
    }

    /**
     * remember
     */
    private function handle_update_remember($old_value, $value){
        return;
    }

    /**
     * mail
     */
    private function handle_update_mail($old_value, $value){
        return;
    }

}