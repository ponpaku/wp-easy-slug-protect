<?php

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cookie操作を管理するクラス
 */
class ESP_Cookie {
    /**
     * Cookie設定用の一時データ
     * @var array
     */
    private $pending_cookies = [];

    /**
     * Cookie名のプレフィックス
     * @var array|null
     */
    private $cookie_prefixes = null;

    /**
     * 高速ゲート機能の有効状態キャッシュ
     * @var bool|null
     */
    private $fast_gate_active = null;

    /**
     * シングルトンインスタンス
     * @var ESP_Cookie
     */
    private static $instance = null;

    /**
     * シングルトンパターンのためprivate
     */
    private function __construct() {}

    /**
     * インスタンスの取得
     * 
     * @return ESP_Cookie
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Cookie設定とリダイレクトを実行
     * 
     * @param string $url リダイレクト先URL
     * @param bool home_urlを使用して行うか
     * @param bool $safe_redirect wp_safe_redirectを使用するか
     */
    public function do_redirect($url, $home = true, $safe_redirect = true) {
        // 保留中のCookieがあれば設定
        if ($this->has_pending_cookies()) {
            $this->set_pending_cookies();
        }

        if ($home) $url = home_url($url);

        // リダイレクト実行
        if ($safe_redirect) {
            wp_safe_redirect($url);
        } else {
            wp_redirect($url);
        }
        exit;
    }

    /**
     * Cookieプレフィックスを取得
     */
    private function get_prefixes() {
        if ($this->cookie_prefixes === null) {
            $this->cookie_prefixes = ESP_Config::get_cookie_prefixes();
        }

        return $this->cookie_prefixes;
    }

    /**
     * ゲートCookieのプレフィックスを取得
     */
    private function get_gate_cookie_prefix() {
        $prefixes = $this->get_prefixes();
        return isset($prefixes['gate']) ? $prefixes['gate'] : 'esp_gate_';
    }

    /**
     * 高速ゲート機能が有効かどうかを判定
     */
    private function is_fast_gate_active() {
        if ($this->fast_gate_active === null) {
            $settings = class_exists('ESP_Option') ? ESP_Option::get_current_setting('media') : array();
            $enabled = false;

            if (is_array($settings)) {
                $media_enabled = true;
                if (array_key_exists('enabled', $settings)) {
                    // メディア自体が無効なら高速ゲートも停止する
                    $media_enabled = (bool) $settings['enabled'];
                }

                // メディア保護と高速ゲートの両方が有効なときのみ起動
                $enabled = $media_enabled && !empty($settings['fast_gate_enabled']);
            }

            $this->fast_gate_active = $enabled;
        }

        return $this->fast_gate_active;
    }

    /**
     * ゲートCookie用のMACを生成
     */
    private function build_gate_cookie_value($path_id, $token, $expires) {
        if (!class_exists('ESP_Media_Protection')) {
            // ゲート用のキー管理が利用できない場合は中断
            return null;
        }

        $key = ESP_Media_Protection::get_media_gate_key_value();
        if ($key === '') {
            // キー未生成ならここで生成を試みる
            $key = ESP_Media_Protection::ensure_media_gate_key_exists();
        }

        if ($key === '') {
            // キーが確保できなければMACも発行しない
            return null;
        }

        $payload = $path_id . '|' . $token . '|' . $expires;
        $mac = hash_hmac('sha256', $payload, $key);

        if (!is_string($mac) || $mac === '') {
            // HMAC生成に失敗した場合はCookieを発行しない
            return null;
        }

        return $mac . '.' . $expires;
    }

    /**
     * ゲートCookieの発行を予約
     */
    public function queue_gate_cookie($path_id, $token, $expires) {
        $path_id = (string) $path_id;
        if ($path_id === '') {
            // パスIDが無いリクエストは対象外
            return;
        }

        if (!$this->is_fast_gate_active()) {
            // 高速ゲート無効時は既存Cookieを破棄する
            $this->clear_gate_cookie($path_id);
            return;
        }

        if (!is_string($token) || $token === '') {
            // トークン未取得なら発行しない
            return;
        }

        $expires = intval($expires);
        if ($expires <= 0) {
            // 有効期限が正しくない場合も中断
            return;
        }

        $value = $this->build_gate_cookie_value($path_id, $token, $expires);
        if ($value === null) {
            // MAC生成に失敗した場合はCookie設定しない
            return;
        }

        $prefix = $this->get_gate_cookie_prefix();
        $this->pending_cookies[$prefix . $path_id] = [
            'value' => $value,
            'expires' => $expires
        ];
    }

    /**
     * ゲートCookieを破棄
     */
    public function clear_gate_cookie($path_id) {
        $path_id = (string) $path_id;
        if ($path_id === '') {
            // パスID不明なら処理しない
            return;
        }

        $prefix = $this->get_gate_cookie_prefix();
        $this->pending_cookies[$prefix . $path_id] = [
            'value' => '',
            'expires' => time() - HOUR_IN_SECONDS
        ];
    }

    /**
     * 認証用セッションCookieの準備
     *
     * @param string $path_id パスID
     * @param string $token トークン
     */
    public function prepare_session_cookie($path_id, $token, $expires = null) {
        if ($expires === null) {
            $expires = time() + DAY_IN_SECONDS;
        }
        $path_id = (string) $path_id;
        $prefixes = $this->get_prefixes();
        $this->pending_cookies[$prefixes['session'] . $path_id] = [
            'value' => $token,
            'expires' => $expires
        ];

        $this->queue_gate_cookie($path_id, $token, $expires);
    }

    /**
     * ログイン保持用Cookieの準備
     * 
     * @param array $path_settings パス設定
     * @param string $user_id ユーザーID
     * @param string $token トークン
     * @param int $expires 有効期限のタイムスタンプ
     */
    public function prepare_remember_cookies($path_settings, $user_id, $token, $expires) {
        $path_id = (string) $path_settings['id'];
        $prefixes = $this->get_prefixes();
        $this->pending_cookies[$prefixes['remember_id'] . $path_id] = [
            'value' => $user_id,
            'expires' => $expires
        ];
        $this->pending_cookies[$prefixes['remember_token'] . $path_id] = [
            'value' => $token,
            'expires' => $expires
        ];

        $this->queue_gate_cookie($path_id, $token, $expires);
    }


    /**
     * 保留中のCookieを実際に設定
     * リダイレクト直前に呼び出す
     */
    public function set_pending_cookies() {
        if (empty($this->pending_cookies)) {
            return;
        }

        foreach ($this->pending_cookies as $name => $data) {
            if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
                // PHP 7.3以上ではオプション配列を使用
                $options = $this->get_cookie_options();
                $options['expires'] = $data['expires'];
                setcookie(
                    $name,
                    $data['value'],
                    $options
                );
            } else {
                // 古いPHPバージョン用の互換性コード
                setcookie(
                    $name,
                    $data['value'],
                    $data['expires'],
                    COOKIEPATH,
                    COOKIE_DOMAIN,
                    is_ssl(),
                    true
                );
            }
        }

        $this->pending_cookies = [];
    }

    /**
     * 特定のパスのすべてのCookieをクリア
     * 
     * @param array $path_settings パス設定
     */
    public function clear_all_cookies_for_path($path_settings) {
        $this->clear_session_cookie($path_settings['id']);
        $this->clear_remember_cookies_for_path($path_settings);
    }

    /**
     * 特定のパスの認証Cookieをクリア
     * 
     * @param string $path_id パスID
     */
    public function clear_session_cookie($path_id) {
        $path_id = (string) $path_id;
        $prefixes = $this->get_prefixes();
        $this->pending_cookies[$prefixes['session'] . $path_id] = [
            'value' => '',
            'expires' => time() - HOUR_IN_SECONDS
        ];

        $this->clear_gate_cookie($path_id);
    }

    /**
     * 特定のパスのログイン保持Cookieをクリア
     * 
     * @param array $path_settings パス設定
     */
    public function clear_remember_cookies_for_path($path_settings) {
        $path_id = (string) $path_settings['id'];
        $prefixes = $this->get_prefixes();
        foreach (array(
            $prefixes['remember_id'] . $path_id,
            $prefixes['remember_token'] . $path_id
        ) as $name) {
            $this->pending_cookies[$name] = [
                'value' => '',
                'expires' => time() - HOUR_IN_SECONDS
            ];
        }

        $this->clear_gate_cookie($path_id);
    }

    /**
     * 特定のパスのセッションCookie値を取得
     * 
     * @param string $path_id パスID
     * @return string|null Cookie値。存在しない場合はnull
     */
    public function get_session_cookie($path_id) {
        $prefixes = $this->get_prefixes();
        $name = $prefixes['session'] . $path_id;
        return isset($_COOKIE[$name]) ? $_COOKIE[$name] : null;
    }

    /**
     * 特定のパスのログイン保持Cookie値を取得
     * 
     * @param array $path_settings パス設定
     * @return array|null ID,トークンの配列。存在しない場合はnull
     */
    public function get_remember_cookies_for_path($path_settings) {
        $path_id = $path_settings['id'];
        $prefixes = $this->get_prefixes();
        $id_name = $prefixes['remember_id'] . $path_id;
        $token_name = $prefixes['remember_token'] . $path_id;

        if (!isset($_COOKIE[$id_name]) || !isset($_COOKIE[$token_name])) {
            return null;
        }

        return [
            'id' => $_COOKIE[$id_name],
            'token' => $_COOKIE[$token_name]
        ];
    }


    /**
     * 保留中のCookieがあるか確認
     * 
     * @return bool
     */
    public function has_pending_cookies() {
        return !empty($this->pending_cookies);
    }

    /**
     * Cookie名の取得
     * 
     * @return array
     */
    public function get_cookie_names() {
        $prefixes = $this->get_prefixes();

        return [
            'session_prefix' => $prefixes['session'],
            'remember' => [
                'id' => rtrim($prefixes['remember_id'], '_'),
                'token' => rtrim($prefixes['remember_token'], '_')
            ]
        ];
    }

    /**
     * Cookie設定のオプションを取得
     * 
     * @return array
     */
    private function get_cookie_options() {
        $options = [
            'expires' => 0,
            'path' => COOKIEPATH,
            'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => true,
        ];

        // PHP 7.3以上では SameSite を追加
        if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
            $options['samesite'] = 'Strict';
        }

        return $options;
    }
}