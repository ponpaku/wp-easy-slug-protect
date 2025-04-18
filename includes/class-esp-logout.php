<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ログアウト処理を管理するクラス
 */
class ESP_Logout {
    /**
     * @var ESP_Session セッション管理クラスのインスタンス
     */
    private $session;

    /**
     * @var ESP_Cookie cookie管理クラスのインスタンス
     */
    private $cookie;

    /**
     * コンストラクタ
     */
    public function __construct() {
        $this->session = ESP_Session::get_instance();
        $this->cookie = ESP_Cookie::get_instance();
    }

    /**
     * ログアウトボタンの生成
     * 
     * @param array $atts ショートコード属性
     * @return string ログアウトボタンのHTML
     */
    public function get_logout_button($atts = array()) {
        $atts = shortcode_atts(array(
            'redirect_to' => '',
            'text' => __('ログアウト', ESP_Config::TEXT_DOMAIN),
            'class' => 'esp-logout-button',
            'path' => null,  // ログアウト対象のパスを指定可能に
            'path_id' => null // ログアウト対象のパスIDを指定可能に
        ), $atts);

        // パスIDが指定されている場合はそれを優先
        $path_settings = null;
        $protected_paths = ESP_Option::get_current_setting('path');
        
        if ($atts['path_id'] && isset($protected_paths[$atts['path_id']])) {
            $path_settings = $protected_paths[$atts['path_id']];
        }
        // パスが指定されている場合はそのパスを検索
        else if ($atts['path']) {
            $target_path = '/' . trim($atts['path'], '/') . '/';
            foreach ($protected_paths as $path_id => $path) {
                if ($path['path'] === $target_path) {
                    $path_settings = $path;
                    break;
                }
            }
        }
        // どちらも指定されていない場合は現在のパスを検索
        else {
            global $wp;
            $current_path = '/' . trim($wp->request, '/') . '/';
            foreach ($protected_paths as $path_id => $path) {
                if (strpos($current_path, $path['path']) === 0) {
                    $path_settings = $path;
                    break;
                }
            }
        }

        // 対象の設定が見つからないか、ログインしていない場合は何も表示しない
        if (!$path_settings) {
            return '';
        }

        // 指定されたパスにログインしているか確認
        $auth = new ESP_Auth();
        if (!$auth->is_logged_in($path_settings)) {
            return '';
        }

        return sprintf(
            '<div class="esp-logout-form">
                <form method="post" action="%s" class="esp-logout-form">
                    <input type="hidden" name="esp_action" value="logout">
                    <input type="hidden" name="esp_nonce" value="%s">
                    <input type="hidden" name="esp_logout_path_id" value="%s">
                    <input type="hidden" name="esp_logout_path" value="%s">
                    <input type="hidden" name="redirect_to" value="%s">
                    <button type="submit" class="esp-submit %s">%s</button>
                </form>
            </div>',
            esc_url(home_url('/')),
            wp_create_nonce('esp_logout'),
            esc_attr($path_settings['id']),
            esc_attr($path_settings['path']),
            esc_url($atts['redirect_to']),
            esc_attr($atts['class']),
            esc_html($atts['text'])
        );
    }
    /**
     * いずれかのパスにログインしているか確認
     * 
     * @return bool
     */
    private function is_any_path_logged_in() {
        $auth = new ESP_Auth();
        $protected_paths = ESP_Option::get_current_setting('path');
        
        foreach ($protected_paths as $path) {
            if ($auth->is_logged_in($path['path'])) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * ログアウト処理
     */
    public function process_logout() {
        if (!isset($_POST['esp_nonce']) || !wp_verify_nonce($_POST['esp_nonce'], 'esp_logout')) {
            wp_die(__('不正なリクエストです。', ESP_Config::TEXT_DOMAIN));
        }

        // ログアウト対象のパスを取得
        $logout_path_id = isset($_POST['esp_logout_path_id']) ? $_POST['esp_logout_path_id'] : null;
        $logout_path = isset($_POST['esp_logout_path']) ? $_POST['esp_logout_path'] : null;
        
        // 保護パス設定を取得
        $protected_paths = ESP_Option::get_current_setting('path');
        
        if ($logout_path_id && isset($protected_paths[$logout_path_id])) {
            // 特定のパスからのログアウト
            $this->logout_from_path($protected_paths[$logout_path_id]);
        } else if ($logout_path) {
            // パスIDがなくパスだけの場合（旧データ対応）
            foreach ($protected_paths as $path_id => $path_settings) {
                if ($path_settings['path'] === $logout_path) {
                    $this->logout_from_path($path_settings);
                    break;
                }
            }
        } else {
            // すべてのパスからログアウト
            $this->logout_from_all_paths();
        }
    }

    /**
     * 各データの削除
     * 
     * @param array $path_settings 削除対象のパス設定
     */
    private function logout_from_path($path_settings) {
        $path_id = $path_settings['id'];
        
        // セッションデータのクリア
        $this->session->delete('esp_auth_' . $path_id);
        
        // DBデータのクリア
        $cookie_data = $this->cookie->get_remember_cookies_for_path($path_settings);
        if ($cookie_data) {
            global $wpdb;
            $wpdb->delete(
                $wpdb->prefix . ESP_Config::DB_TABLES['remember'],
                array(
                    'user_id' => $cookie_data['id'],
                    'path_id' => $path_id
                ),
                array('%s', '%s')
            );
        }
        
        // Cookieのクリア
        $this->cookie->clear_all_cookies_for_path($path_settings);
    }

    /**
     * 全てのページからログアウトさせる
     */
    private function logout_from_all_paths() {
        $protected_paths = ESP_Option::get_current_setting('path');
        foreach ($protected_paths as $path_id => $path_settings) {
            $this->logout_from_path($path_settings);
        }
    }

    /**
     * Cookie削除の準備
     */
    private function prepare_clear_cookies() {
        // セッションCookieのクリア
        $protected_paths = ESP_Option::get_current_setting('path');
        foreach ($protected_paths as $path) {
            $this->cookie->clear_session_cookie($path['path']);
        }
        
        // ログイン保持Cookieのクリア
        $this->cookie->clear_remember_cookies();
    }

    // /**
    //  * リダイレクトURLの生成
    //  * 
    //  * @param string|null $redirect_to リダイレクト先のパス
    //  * @return string 完全なリダイレクトURL
    //  */
    // private function get_redirect_url($redirect_to = null) {
    //     // リダイレクト先が指定されていない場合はサイトホームURLを使用
    //     if (empty($redirect_to)) {
    //         return get_home_url();
    //     }

    //     // 現在のURLを取得
    //     $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . 
    //         "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    //     $current_path = parse_url($current_url, PHP_URL_PATH);

    //     // WordPressのホームURLを取得
    //     $home_url = get_home_url();
    //     $home_path = parse_url($home_url, PHP_URL_PATH);
    //     $home_path = $home_path ?: '/';

    //     // リダイレクト先の処理
    //     if (strpos($redirect_to, 'http') === 0) {
    //         return $redirect_to;
    //     } elseif (strpos($redirect_to, '/') === 0) {
    //         if ($home_path !== '/') {
    //             $redirect_to = rtrim($home_path, '/') . $redirect_to;
    //         }
    //         return home_url($redirect_to);
    //     } else {
    //         $current_dir = dirname($current_path);
    //         if ($current_dir === '\\' || $current_dir === '/') {
    //             $current_dir = '';
    //         }
    //         $resolved_path = $this->resolve_relative_path($current_dir . '/' . $redirect_to);
            
    //         if ($home_path !== '/') {
    //             if (strpos($resolved_path, $home_path) !== 0) {
    //                 $resolved_path = rtrim($home_path, '/') . '/' . ltrim($resolved_path, '/');
    //             }
    //         }
    //         return home_url($resolved_path);
    //     }
    // }

    // /**
    //  * 相対パスの解決
    //  * 
    //  * @param string $path 解決する相対パス
    //  * @return string 解決された絶対パス
    //  */
    // private function resolve_relative_path($path) {
    //     $path = str_replace('\\', '/', $path);
    //     $parts = array_filter(explode('/', $path), 'strlen');
    //     $absolutes = array();

    //     foreach ($parts as $part) {
    //         if ($part === '.') {
    //             continue;
    //         }
    //         if ($part === '..') {
    //             array_pop($absolutes);
    //         } else {
    //             $absolutes[] = $part;
    //         }
    //     }

    //     return '/' . implode('/', $absolutes);
    // }
}