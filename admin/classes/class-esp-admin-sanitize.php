<?php
if (!defined('ABSPATH')) {
    exit;
}

class ESP_Sanitize {

    /**
     * パスワードが既にハッシュ化されているかチェック
     * 
     * @param string $password チェックする文字列
     * @return bool
     */
    private function is_hashed_password($password) {
        return preg_match('/^\$P\$B/', $password) === 1;
    }

    /**
     * 保護パス設定のサニタイズ
     */
    public function sanitize_protected_paths($paths) {
        if (!is_array($paths)) {
            return array();
        }

        $sanitized = array();
        $existing_paths = ESP_Option::get_current_setting('path');
        $unique_paths = array(); // 重複チェック用
        $login_pages = array();  // login_pageの重複チェック用
        $raw_passwords = array(); // 平文パスワード一時保存用

        foreach ($paths as $path) {
            if (empty($path['path']) || empty($path['login_page'])) {
                continue;
            }

            // パスの正規化と重複チェック
            $normalized_path = '/' . trim(sanitize_text_field($path['path']), '/') . '/';
            if (in_array($normalized_path, $unique_paths, true)) {
                continue;
            }
            $unique_paths[] = $normalized_path;

            // ログインページの重複チェック
            $login_page_id = absint($path['login_page']);
            if (in_array($login_page_id, $login_pages, true)) {
                add_settings_error(
                    'esp_protected_paths',
                    'duplicate_login_page',
                    __('同じログインページが複数のパスに設定されています。各パスに一意のログインページを選択してください。', 'easy-slug-protect')
                );
                return new WP_Error(
                    'esp_duplicate_login_page',
                    __('同じログインページが複数のパスに設定されています。', 'easy-slug-protect')
                );
            }
            $login_pages[] = $login_page_id;

            // パスワードの処理
            $hashed_password = '';
            
            if (!empty($path['password'])) {
                if ($this->is_hashed_password($path['password'])) {
                    // 既にハッシュ化済みの場合はそのまま使用
                    $hashed_password = $path['password'];
                } else {
                    // 新しいパスワードの場合のみハッシュ化
                    $raw_passwords[$normalized_path] = $path['password'];
                    $hashed_password = wp_hash_password($path['password']);
                }
            } else {
                // 既存のパスワードを維持
                foreach ($existing_paths as $existing) {
                    if ($existing['path'] === $normalized_path && !empty($existing['password'])) {
                        $hashed_password = $existing['password'];
                        break;
                    }
                }
            }

            // パスワードが設定されていない場合はスキップ
            if (empty($hashed_password)) {
                continue;
            }

            // サニタイズされたパス情報を準備
            $sanitized_path = array(
                'path' => $normalized_path,
                'login_page' => $login_page_id,
                'password' => $hashed_password
            );

            $sanitized[] = $sanitized_path;
        }

        // 平文パスワードを一時的に保存（メール通知用）
        if (!empty($raw_passwords)) {
            set_transient('esp_raw_passwords', $raw_passwords, 30);
        }

        return $sanitized;
    }

    /**
     * ブルートフォース対策設定のサニタイズ
     */
    public function sanitize_bruteforce_settings($settings) {
        return array(
            'attempts_threshold' => max(1, absint($settings['attempts_threshold'])),
            'time_frame' => max(1, absint($settings['time_frame'])),
            'block_time_frame' => max(1, absint($settings['block_time_frame']))
        );
    }

    /**
     * ログイン保持設定のサニタイズ
     */
    public function sanitize_remember_settings($settings) {
        return array(
            'time_frame' => max(1, absint($settings['time_frame'])),
            'cookie_prefix' => 'esp' // 固定値
        );
    }

    /**
     * メール設定のサニタイズ
     */
    public function sanitize_mail_settings($settings) {
        if (!is_array($settings)) {
            return ESP_Cofig::OPTION_DEFAULTS['mail'];
        }

        $sanitized_settings = array();

        // 'enable_notifications' のサニタイズ
        $sanitized_settings['enable_notifications'] = !empty($settings['enable_notifications']) ? true : false;

        // 'notifications' のサニタイズ
        $sanitized_settings['notifications'] = array();
        $notification_keys = array('new_path', 'password_change', 'path_remove', 'brute_force', 'critical_error');
        foreach ($notification_keys as $key) {
            $sanitized_settings['notifications'][$key] = !empty($settings['notifications'][$key]) ? true : false;
        }

        return $sanitized_settings;
    }
}
