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
     * @var ESP_Cookie cookie管理クラスのインスタンス
     */
    private $cookie;

    /**
     * コンストラクタ
     */
    public function __construct() {
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
            // パスマッチャーを使用して現在のパスに該当する設定を取得
            $path_matcher = new ESP_Path_Matcher();
            global $wp;
            $current_path = '/' . trim($wp->request, '/') . '/';
            $path_settings = $path_matcher->match($current_path);
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
        
        foreach ($protected_paths as $path_settings) {
            if ($auth->is_logged_in($path_settings)) {
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
            ESP_Message::set_error(__('不正なリクエストです。', ESP_Config::TEXT_DOMAIN));
            return;
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
}