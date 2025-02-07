<?php

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 認証処理を管理するクラス
 */
class ESP_Auth {
    /**
     * @var ESP_Security セキュリティクラスのインスタンス
     */
    private $security;

    /**
     * @var ESP_Session セッション管理クラスのインスタンス
     */
    private $session;

    /**
     * @var ESP_Cookie cookie管理クラスのインスタンス
     */
    private $cookie;

    private $text_domain;

    /**
     * コンストラクタ
     */
    public function __construct() {
        $this->security = new ESP_Security();
        $this->session = ESP_Session::get_instance();
        $this->cookie = ESP_Cookie::get_instance();
        $this->text_domain = ESP_Config::TEXT_DOMAIN;
    }

    /**
     * ログインフォームの生成
     * 
     * @param string $path 保護対象のパス
     * @param string $redirect_to リダイレクト先のURL(基本はパス)
     * @return string HTML形式のログインフォーム
     */
    public function get_login_form($path, $redirect_to, $place_holder) {
        // CSRFトークンの生成
        $nonce = wp_create_nonce('esp_login_' . $path);
        
        // エラーメッセージの取得
        $error = $this->session->get_error();
        
        ob_start();
        ?>
        <div class="esp-login-form">
            <?php if ($error): ?>
                <div class="esp-error"><?php echo esc_html($error); ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('esp_login_' . $path, 'esp_nonce'); ?>
                <input type="hidden" name="esp_path" value="<?php echo esc_attr($path); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to) ?>">
                
                <div class="esp-form-group">
                    <!-- <label for="esp-password"><?php _e('パスワード:', $this->text_domain); ?></label> -->
                    <input type="password" name="esp_password" id="esp-password" placeholder="<?php echo $place_holder ?>" required>
                </div>

                <div class="esp-form-group">
                    <label>
                        <input type="checkbox" name="esp_remember" value="1">
                        <?php _e('ログインを記憶する', $this->text_domain); ?>
                    </label>
                </div>

                <div class="esp-form-group">
                    <button type="submit" class="esp-submit">
                        <?php _e('ログイン', $this->text_domain); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
        $this->session->del_error();
        return ob_get_clean();
    }

    /**
     * ログイン処理
     * 
     * @param string $path_settings ターゲットのパス設定
     * @param string $password 入力されたパスワード
     * @return bool ログイン成功ならtrue
     */
    public function process_login($path_settings, $password) {
        if (!$path_settings) {
            $this->session->set_error(__('無効なリクエストです。', $this->text_domain));
            return false;
        }

        // ブルートフォース対策のチェック
        if (!$this->security->can_try_login($path_settings['path'])) {
            $this->session->set_error(__('試行回数が制限を超えました。しばらく時間をおいてお試しください。', $this->text_domain));
            return false;
        }

        if (!wp_check_password($password, $path_settings['password'])) {
            $this->security->record_failed_attempt($path_settings['path']);
            $this->session->set_error(__('パスワードが正しくありません。', $this->text_domain));
            return false;
        }

        // ログイン成功時の処理
        $remember = isset($_POST['esp_remember']) && $_POST['esp_remember'];
        if ($remember) {
            $this->set_remember_login($path_settings['path']);
        } else {
            $this->set_session_login($path_settings['path']);
        }

        return true;
    }

    /**
     * セッションベースのログイン情報を設定
     */
    private function set_session_login($path) {
        $token = wp_generate_password(64, false);
        $this->session->set('esp_auth_' . $path, $token);
        
        // Cookie設定を一時保存
        $this->cookie->prepare_session_cookie($path, $token);
    }


    /**
     * 永続的なログイン情報を設定
     */
    private function set_remember_login($path) {
        global $wpdb;
        
        // ランダムなIDとトークンを生成
        $user_id = wp_generate_password(32, false);
        $token = wp_generate_password(64, false);
        
        $remember_settings = ESP_Option::get_current_setting('remember');
        $expires = time() + (DAY_IN_SECONDS * $remember_settings['time_frame']);

        // DBに保存
        $wpdb->insert(
            $wpdb->prefix . ESP_Config::DB_TABLES['remember'],
            array(
                'path' => $path,
                'token' => $token,
                'user_id' => $user_id,
                'created' => current_time('mysql'),
                'expires' => date('Y-m-d H:i:s', $expires)
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );

        // パスを含めてCookie設定を準備
        $this->cookie->prepare_remember_cookies($path, $user_id, $token, $expires);
    }

    /**
     * ログイン状態のチェック
     * 
     * @param string $path チェック対象のパス
     * @return bool ログイン済みならtrue
     */
    public function is_logged_in($path) {
        // セッションベースのチェック
        $session_token = $this->session->get('esp_auth_' . $path);
        $cookie_token = $this->cookie->get_session_cookie($path);

        if ($session_token && $cookie_token && $session_token === $cookie_token) {
            return true;
        }

        // 永続的ログインのチェック
        return $this->check_remember_login($path);
    }

    /**
     * 永続的ログインのチェック
     */
    private function check_remember_login($path) {
        global $wpdb;

        $cookie_data = $this->cookie->get_remember_cookies_for_path($path);
        if (!$cookie_data) {
            return false;
        }

        $table = $wpdb->prefix . ESP_Config::DB_TABLES['remember'];
        $login_info = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table
            WHERE user_id = %s 
            AND token = %s 
            AND path = %s 
            AND expires > NOW()",
            $cookie_data['id'],  // $_COOKIEの直接参照を修正
            $cookie_data['token'],  // $_COOKIEの直接参照を修正
            $path
        ));

        if ($login_info) {
            $this->refresh_remember_login($path, $login_info->id, $cookie_data['id'], $cookie_data['token']);
            return true;
        }

        return false;
    }

    /**
     * 永続的ログイントークンの更新
     * 
     * @param string $path パス
     * @param int $id DBのレコードID
     * @param string $user_id ユーザーID
     * @param string $token トークン
     */
    private function refresh_remember_login($path, $id, $user_id, $token) {
        global $wpdb;
        
        $remember_settings = ESP_Option::get_current_setting('remember');
        $expires = time() + (DAY_IN_SECONDS * $remember_settings['time_frame']);

        // データベースの有効期限を更新
        $wpdb->update(
            $wpdb->prefix . ESP_Config::DB_TABLES['remember'],
            array('expires' => date('Y-m-d H:i:s', $expires)),
            array('id' => $id),
            array('%s'),
            array('%d')
        );

        // パスを含めてCookieの更新を準備
        $this->cookie->prepare_remember_cookies($path, $user_id, $token, $expires);
    }
}