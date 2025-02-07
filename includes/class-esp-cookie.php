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
     * @param string $path パス
     * @param string $token トークン
     */
    public function prepare_session_cookie($path, $token) {
        $this->pending_cookies['esp_auth_' . $path] = [
            'value' => $token,
            'expires' => time() + DAY_IN_SECONDS
        ];
    }

    /**
     * ログイン保持用Cookieの準備
     * 
     * @param string $path パス
     * @param string $user_id ユーザーID
     * @param string $token トークン
     * @param int $expires 有効期限のタイムスタンプ
     */
    public function prepare_remember_cookies($path, $user_id, $token, $expires) {
        $path_hash = md5($path); // パスをハッシュ化して識別子として使用
        $this->pending_cookies["esp_remember_id_{$path_hash}"] = [
            'value' => $user_id,
            'expires' => $expires
        ];
        $this->pending_cookies["esp_remember_token_{$path_hash}"] = [
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

        $this->pending_cookies = [];
    }

    /**
     * 特定のパスのすべてのCookieをクリア
     * 
     * @param string $path パス
     */
    public function clear_all_cookies_for_path($path) {
        $this->clear_session_cookie($path);
        $this->clear_remember_cookies_for_path($path);
    }

    /**
     * 特定のパスの認証Cookieをクリア
     * 
     * @param string $path パス
     */
    public function clear_session_cookie($path) {
        $this->pending_cookies['esp_auth_' . $path] = [
            'value' => '',
            'expires' => time() - HOUR_IN_SECONDS
        ];
    }


    /**
     * 特定のパスのログイン保持Cookieをクリア
     * 
     * @param string $path パス
     */
    public function clear_remember_cookies_for_path($path) {
        $path_hash = md5($path);
        foreach (["esp_remember_id_{$path_hash}", "esp_remember_token_{$path_hash}"] as $name) {
            $this->pending_cookies[$name] = [
                'value' => '',
                'expires' => time() - HOUR_IN_SECONDS
            ];
        }
    }

    /**
     * 特定のパスのセッションCookie値を取得
     * 
     * @param string $path パス
     * @return string|null Cookie値。存在しない場合はnull
     */
    public function get_session_cookie($path) {
        return isset($_COOKIE['esp_auth_' . $path]) ? $_COOKIE['esp_auth_' . $path] : null;
    }

    /**
     * 特定のパスのログイン保持Cookie値を取得
     * 
     * @param string $path パス
     * @return array|null ID,トークンの配列。存在しない場合はnull
     */
    public function get_remember_cookies_for_path($path) {
        $path_hash = md5($path);
        if (!isset($_COOKIE["esp_remember_id_{$path_hash}"]) || 
            !isset($_COOKIE["esp_remember_token_{$path_hash}"])) {
            return null;
        }

        return [
            'id' => $_COOKIE["esp_remember_id_{$path_hash}"],
            'token' => $_COOKIE["esp_remember_token_{$path_hash}"]
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
     * Cookie設定のデフォルト値を取得
     * 
     * @return array
     */
    private function get_cookie_defaults() {
        return [
            'path' => COOKIEPATH,
            'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => true
        ];
    }
}