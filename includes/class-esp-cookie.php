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
     * 認証用セッションCookieの準備
     * 
     * @param string $path_id パスID
     * @param string $token トークン
     */
    public function prepare_session_cookie($path_id, $token) {
        $this->pending_cookies['esp_auth_' . $path_id] = [
            'value' => $token,
            'expires' => time() + DAY_IN_SECONDS
        ];
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
        $path_id = $path_settings['id'];
        $this->pending_cookies["esp_remember_id_{$path_id}"] = [
            'value' => $user_id,
            'expires' => $expires
        ];
        $this->pending_cookies["esp_remember_token_{$path_id}"] = [
            'value' => $token,
            'expires' => $expires
        ];
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
        $this->pending_cookies['esp_auth_' . $path_id] = [
            'value' => '',
            'expires' => time() - HOUR_IN_SECONDS
        ];
    }

    /**
     * 特定のパスのログイン保持Cookieをクリア
     * 
     * @param array $path_settings パス設定
     */
    public function clear_remember_cookies_for_path($path_settings) {
        $path_id = $path_settings['id'];
        foreach (["esp_remember_id_{$path_id}", "esp_remember_token_{$path_id}"] as $name) {
            $this->pending_cookies[$name] = [
                'value' => '',
                'expires' => time() - HOUR_IN_SECONDS
            ];
        }
    }

    /**
     * 特定のパスのセッションCookie値を取得
     * 
     * @param string $path_id パスID
     * @return string|null Cookie値。存在しない場合はnull
     */
    public function get_session_cookie($path_id) {
        return isset($_COOKIE['esp_auth_' . $path_id]) ? $_COOKIE['esp_auth_' . $path_id] : null;
    }

    /**
     * 特定のパスのログイン保持Cookie値を取得
     * 
     * @param array $path_settings パス設定
     * @return array|null ID,トークンの配列。存在しない場合はnull
     */
    public function get_remember_cookies_for_path($path_settings) {
        $path_id = $path_settings['id'];
        if (!isset($_COOKIE["esp_remember_id_{$path_id}"]) || 
            !isset($_COOKIE["esp_remember_token_{$path_id}"])) {
            return null;
        }

        return [
            'id' => $_COOKIE["esp_remember_id_{$path_id}"],
            'token' => $_COOKIE["esp_remember_token_{$path_id}"]
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
        return [
            'session_prefix' => 'esp_auth_',
            'remember' => [
                'id' => 'esp_remember_id',
                'token' => 'esp_remember_token'
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