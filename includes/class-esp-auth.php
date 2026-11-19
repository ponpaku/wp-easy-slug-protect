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
     * @var ESP_Cookie cookie管理クラスのインスタンス
     */
    private $cookie;

    private $text_domain;

    /**
     * コンストラクタ
     */
    public function __construct() {
        $this->security = new ESP_Security();
        $this->cookie = ESP_Cookie::get_instance();
        $this->text_domain = ESP_Config::TEXT_DOMAIN;
    }

    /**
     * ログインフォームの生成
     * 
     * @param array $path_settings 保護対象のパス設定
     * @param string $redirect_to リダイレクト先のURL
     * @param string $place_holder プレースホルダーテキスト
     * @return string HTML形式のログインフォーム
     */
    public function get_login_form($path_settings, $redirect_to, $place_holder) {
        // CSRFトークンの生成
        $nonce = wp_create_nonce('esp_login_' . $path_settings['id']);
        
        // メッセージの取得（セッション不使用版）
        $notice_data = ESP_Message::get_message();
        $notice_message = null;
        $notice_class = '';

        if ($notice_data && !empty($notice_data['message'])) {
            $notice_message = $notice_data['message'];
            $notice_type = isset($notice_data['type']) ? $notice_data['type'] : '';

            if ($notice_type === 'error') {
                $notice_class = 'esp-error';
            } else {
                $notice_class = 'esp-notice';
                if (!empty($notice_type)) {
                    $notice_class .= ' esp-notice--' . sanitize_html_class($notice_type);
                }
            }
        }

        ob_start();
        ?>
        <div class="esp-login-form">
            <?php if ($notice_message): ?>
                <div class="<?php echo esc_attr($notice_class); ?>"><?php echo esc_html($notice_message); ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('esp_login_' . $path_settings['id'], 'esp_nonce'); ?>
                <input type="hidden" name="esp_path_id" value="<?php echo esc_attr($path_settings['id']); ?>">
                <input type="hidden" name="esp_path" value="<?php echo esc_attr($path_settings['path']); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to) ?>">
                
                <div class="esp-form-group">
                    <input type="password" name="esp_password" id="esp-password" placeholder="<?php echo esc_attr($place_holder) ?>" required>
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
        return ob_get_clean();
    }

    /**
     * ログイン処理
     * 
     * @param array $path_settings ターゲットのパス設定
     * @param string $password 入力されたパスワード
     * @return bool ログイン成功ならtrue
     */
    public function process_login($path_settings, $password) {
        if (!$path_settings) {
            ESP_Message::set_error(__('無効なリクエストです。', $this->text_domain));
            return false;
        }

        // ブルートフォース対策のチェック
        if (!$this->security->can_try_login($path_settings)) {
            ESP_Message::set_error(__('試行回数が制限を超えました。しばらく時間をおいてお試しください。', $this->text_domain));
            return false;
        }

        if (!wp_check_password($password, $path_settings['password'])) {
            $this->security->record_failed_attempt($path_settings);
            ESP_Message::set_error(__('パスワードが正しくありません。', $this->text_domain));
            return false;
        }

        // ログイン成功時の処理
        $remember = isset($_POST['esp_remember']) && $_POST['esp_remember'];
        if ($remember) {
            if (!$this->set_remember_login($path_settings)) {
                ESP_Message::set_error(__('ログイン情報の保存に失敗しました。時間をおいて再度お試しください。', $this->text_domain));
                return false;
            }
        } else {
            if (!$this->set_cookie_login($path_settings)) {
                ESP_Message::set_error(__('ログイン情報の保存に失敗しました。時間をおいて再度お試しください。', $this->text_domain));
                return false;
            }
        }

        // A successful password check and persisted session clear the rate-limit state.
        $this->security->reset_successful_attempts($path_settings);
        return true;
    }


    /**
     * Cookieベースのログイン情報を設定
     */
    private function set_cookie_login($path_settings) {
        global $wpdb;

        $path_id = $path_settings['id'];
        $token = wp_generate_password(64, false);
        $token_hash = hash_hmac('sha256', $token, AUTH_SALT);
        $expires = time() + DAY_IN_SECONDS;

        $password_version = isset($path_settings['password_version']) ? intval($path_settings['password_version']) : 0;

        $result = $wpdb->insert(
            $wpdb->prefix . ESP_Config::DB_TABLES['session'],
            array(
                'path_id' => $path_id,
                'password_version' => $password_version,
                'token' => $token_hash,
                'created' => current_time('mysql'),
                'expires' => date('Y-m-d H:i:s', $expires)
            ),
            array('%s', '%d', '%s', '%s', '%s')
        );

        if ($result === false) {
            error_log('ESP_Auth: Failed to store session token for path ' . $path_id);
            return false;
        }

        // Cookie設定を一時保存
        $this->cookie->prepare_session_cookie($path_id, $token, $expires);
        if (class_exists('ESP_Fast_Gate_WP')) {
            ESP_Fast_Gate_WP::issue_cookie($path_id, 'session', '', $token,
                $password_version, $expires);
        }

        return true;
    }


    /**
     * 永続的なログイン情報を設定
     */
    private function set_remember_login($path_settings) {
        global $wpdb;
        
        // ランダムなIDとトークンを生成
        $user_id = wp_generate_password(32, false);
        $token = wp_generate_password(64, false);
        
        // トークンハッシュ
        $token_hash = hash_hmac('sha256', $token, AUTH_SALT);
        
        // 設定から有効期限を参照
        $remember_settings = ESP_Option::get_current_setting('remember');
        $expires = time() + (DAY_IN_SECONDS * $remember_settings['time_frame']);

        // DBに保存
        $result = $wpdb->insert(
            $wpdb->prefix . ESP_Config::DB_TABLES['remember'],
            array(
                'path' => $path_settings['path'],
                'path_id' => $path_settings['id'],
                // パスワードバージョンも保存
                'password_version' => isset($path_settings['password_version']) ? intval($path_settings['password_version']) : 0,
                'token' => $token_hash, // ハッシュ化されたトークンを保存
                'user_id' => $user_id,
                'created' => current_time('mysql'),
                'expires' => date('Y-m-d H:i:s', $expires)
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            error_log('ESP_Auth: Failed to store remember token for path ' . $path_settings['id']);
            return false;
        }

        // Cookie設定を準備（Cookieにはプレーンテキストのトークン）
        $this->cookie->prepare_remember_cookies($path_settings, $user_id, $token, $expires);
        if (class_exists('ESP_Fast_Gate_WP')) {
            ESP_Fast_Gate_WP::issue_cookie($path_settings['id'], 'remember',
                $user_id, $token, (int)($path_settings['password_version'] ?? 0), $expires);
        }

        return true;
    }


    /**
     * Bridge-only MySQL authentication. Unlike is_logged_in(), this does
     * not treat a MySQL hit as delivery permission and does not silently
     * issue a Fast Gate ticket. Returns null on query failure, [] on no auth.
     * Raw tokens remain PHP-internal and must not be logged or serialized.
     */
    public function fast_gate_bridge_candidates($path_settings) {
        global $wpdb;
        if (!is_array($path_settings) || !isset($path_settings['id']) ||
            !is_object($wpdb)) { return null; }
        $path = (string)$path_settings['id'];
        $version = (int)($path_settings['password_version'] ?? 0);
        $candidates = [];
        $session_token = $this->cookie->get_session_cookie($path);
        if (is_string($session_token) && strlen($session_token) >= 32 &&
            strlen($session_token) <= 256) {
            $hash = hash_hmac('sha256', $session_token, AUTH_SALT);
            $table = $wpdb->prefix . ESP_Config::DB_TABLES['session'];
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT path_id, password_version, UNIX_TIMESTAMP(expires) AS expires_ts
                 FROM $table WHERE token=%s AND expires>NOW() LIMIT 1", $hash));
            if ($wpdb->last_error !== '') { return null; }
            if ($row && (string)$row->path_id === $path &&
                (int)$row->password_version === $version &&
                (int)$row->expires_ts > time()) {
                $candidates[] = ['kind'=>'session', 'user'=>'',
                    'token'=>$session_token, 'expires'=>(int)$row->expires_ts];
            }
        }
        $remember = $this->cookie->get_remember_cookies_for_path($path_settings);
        if (is_array($remember) && isset($remember['id'], $remember['token']) &&
            is_string($remember['id']) && strlen($remember['id']) >= 1 &&
            strlen($remember['id']) <= 191 && is_string($remember['token']) &&
            strlen($remember['token']) >= 32 && strlen($remember['token']) <= 256) {
            $hash = hash_hmac('sha256', $remember['token'], AUTH_SALT);
            $table = $wpdb->prefix . ESP_Config::DB_TABLES['remember'];
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, user_id, path_id, password_version,
                        UNIX_TIMESTAMP(expires) AS expires_ts
                 FROM $table WHERE user_id=%s AND token=%s
                 AND path_id=%s AND expires>NOW() LIMIT 1",
                $remember['id'], $hash, $path));
            if ($wpdb->last_error !== '') { return null; }
            if ($row && (string)$row->user_id === $remember['id'] &&
                (string)$row->path_id === $path &&
                (int)$row->password_version === $version &&
                (int)$row->expires_ts > time()) {
                $candidates[] = ['kind'=>'remember', 'user'=>$remember['id'],
                    'token'=>$remember['token'], 'expires'=>(int)$row->expires_ts,
                    'row_id'=>(int)$row->id];
            }
        }
        return $candidates;
    }

    /**
     * ログイン状態のチェック
     * 
     * @param array $path_settings 保護対象のパス設定
     * @return bool ログイン済みならtrue
     */
    public function is_logged_in($path_settings) {
        $path_id = $path_settings['id'];
        
        // Cookieトークンチェック
        if ($this->check_session_login($path_settings)) {
            return true;
        }

        // 永続的ログインのチェック
        return $this->check_remember_login($path_settings);
    }


    /**
     * 通常ログインのチェック
     */
    private function check_session_login($path_settings) {
        global $wpdb;

        $path_id = $path_settings['id'];
        $cookie_token = $this->cookie->get_session_cookie($path_id);
        if (!$cookie_token) {
            return false;
        }

        $token_hash = hash_hmac('sha256', $cookie_token, AUTH_SALT);
        $table = $wpdb->prefix . ESP_Config::DB_TABLES['session'];

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT id, path_id, password_version, UNIX_TIMESTAMP(expires) AS expires_ts
             FROM $table WHERE token = %s AND expires > NOW()",
            $token_hash
        ));

        if (!$session) {
            return false;
        }

        if (!hash_equals(strval($session->path_id), strval($path_id))) {
            return false;
        }

        $current_version = isset($path_settings['password_version']) ? intval($path_settings['password_version']) : 0;
        $token_version = isset($session->password_version) ? intval($session->password_version) : 0;

        if ($current_version !== $token_version) {
            ESP_Message::set_message('warning', __('パスワードが変更されました。再度ログインしてください。', $this->text_domain));
            $this->cookie->clear_session_cookie($path_id);
            $wpdb->delete(
                $table,
                array('id' => $session->id),
                array('%d')
            );
            return false;
        }

        if (class_exists('ESP_Fast_Gate_WP')) {
            ESP_Fast_Gate_WP::issue_cookie($path_id, 'session', '', $cookie_token,
                $current_version, (int)($session->expires_ts ?? 0));
        }
        return true;
    }

 
    /**
     * 永続的ログインのチェック
     */
    private function check_remember_login($path_settings) {
        global $wpdb;

        $cookie_data = $this->cookie->get_remember_cookies_for_path($path_settings);
        if (!$cookie_data) {
            return false;
        }

        $path_id = $path_settings['id'];
        $table = $wpdb->prefix . ESP_Config::DB_TABLES['remember'];
        
        // Cookieから取得したトークンをハッシュ化して比較
        $token_hash = hash_hmac('sha256', $cookie_data['token'], AUTH_SALT);
        
        $login_info = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table
            WHERE user_id = %s 
            AND token = %s 
            AND path_id = %s 
            AND expires > NOW()",
            $cookie_data['id'],
            $token_hash,
            $path_id
        ));

        if ($login_info) {
            $current_version = isset($path_settings['password_version']) ? intval($path_settings['password_version']) : 0;
            $token_version = isset($login_info->password_version) ? intval($login_info->password_version) : 0;

            if ($current_version === $token_version) {
                // MySQL延長が確定しなければ認証を成功扱いしない。
                return $this->refresh_remember_login(
                    $path_settings, $login_info->id,
                    $cookie_data['id'], $cookie_data['token']
                ) === true;
            }

            // バージョンが古いので失効させる
            ESP_Message::set_message('warning', __('パスワードが変更されました。再度ログインしてください。', $this->text_domain));
            $this->cookie->clear_remember_cookies_for_path($path_settings);
            $wpdb->delete(
                $table,
                array('id' => $login_info->id),
                array('%d')
            );
        }

        return false;
    }

    /**
     * 永続的ログイントークンの更新
     * 
     * @param array $path_settings パス設定
     * @param int $id DBのレコードID
     * @param string $user_id ユーザーID
     * @param string $token トークン
     */
    private function refresh_remember_login($path_settings, $id, $user_id, $token) {
        global $wpdb;
        
        // 期限を再計算
        $remember_settings = ESP_Option::get_current_setting('remember');
        $expires = time() + (DAY_IN_SECONDS * $remember_settings['time_frame']);

        // A prior SELECT is not a lease: logout or expiry cleanup may remove
        // the row between check_remember_login() and this refresh.
        // Match the credential, path and generation again in the UPDATE.
        $table = $wpdb->prefix . ESP_Config::DB_TABLES['remember'];
        $token_hash = hash_hmac('sha256', $token, AUTH_SALT);
        $version = isset($path_settings['password_version'])
            ? (int)$path_settings['password_version'] : 0;
        $db_expires = date('Y-m-d H:i:s', $expires);
        $where = "id=%d AND user_id=%s AND token=%s AND path_id=%s
                  AND password_version=%d";
        $args = [$id, $user_id, $token_hash, $path_settings['id'], $version];
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET expires=%s
             WHERE $where AND expires>NOW()",
            $db_expires, ...$args
        ));
        if ($updated === false || $wpdb->last_error !== '' ||
            ($updated !== 0 && $updated !== 1)) {
            error_log('ESP_Auth: Remember Me expiration refresh failed');
            return false;
        }
        if ($updated === 0) {
            // MySQL reports 0 for an unchanged value AND for a missing row.
            // Accept only an existing, still-valid matching credential whose
            // expiry is already at least the requested extension.
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE $where
                 AND expires>NOW() AND expires >= %s LIMIT 1",
                ...array_merge($args, [$db_expires])
            ));
            if ($wpdb->last_error !== '' || (string)$existing !== (string)$id) {
                return false;
            }
        }
        // パスを含めてCookieの更新を準備
        $this->cookie->prepare_remember_cookies($path_settings, $user_id, $token, $expires);
        if (class_exists('ESP_Fast_Gate_WP')) {
            ESP_Fast_Gate_WP::issue_cookie($path_settings['id'], 'remember',
                $user_id, $token, (int)($path_settings['password_version'] ?? 0), $expires);
        }
        return true;
    }
}