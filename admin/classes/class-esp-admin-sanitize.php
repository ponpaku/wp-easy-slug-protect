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

        foreach ($paths as $id => $path) {
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
                // 既存のパスワードを維持（IDをキーとして検索）
                if (isset($existing_paths[$id]) && !empty($existing_paths[$id]['password'])) {
                    $hashed_password = $existing_paths[$id]['password'];
                } else {
                    // 新規追加で既存のIDがないか、元々パスワードがない場合
                    foreach ($existing_paths as $existing_id => $existing_path) {
                        if ($existing_path['path'] === $normalized_path && !empty($existing_path['password'])) {
                            $hashed_password = $existing_path['password'];
                            break;
                        }
                    }
                }
            }

            // パスワードが設定されていない場合はスキップ
            if (empty($hashed_password)) {
                continue;
            }

            // IDがない場合は新しく生成（新規追加の場合）
            if (empty($id) || $id === 'new') {
                $id = 'path_' . uniqid();
            }

            // サニタイズされたパス情報を準備
            $sanitized_path = array(
                'id' => $id,
                'path' => $normalized_path,
                'login_page' => $login_page_id,
                'password' => $hashed_password
            );

            $sanitized[$id] = $sanitized_path;
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
        $sanitized = array(
            'attempts_threshold' => max(1, absint($settings['attempts_threshold'])),
            'time_frame' => max(1, absint($settings['time_frame'])),
            'block_time_frame' => max(1, absint($settings['block_time_frame'])),
            'whitelist_ips' => $settings['whitelist_ips'] 
        );

        if (isset($settings['whitelist_ips'])) {
            $raw_ips = explode(',', $settings['whitelist_ips']);
            $valid_ips = [];
            foreach ($raw_ips as $ip) {
                $trimmed_ip = trim($ip);
                if (!empty($trimmed_ip)) {
                    // IPアドレス形式 (IPv4またはIPv6) を検証
                    if (filter_var($trimmed_ip, FILTER_VALIDATE_IP)) {
                        $valid_ips[] = $trimmed_ip;
                   }
                }
            }
            if (!empty($valid_ips)) {
                $sanitized['whitelist_ips'] = implode(',', array_unique($valid_ips));
            }
        }
        return $sanitized;
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
            return ESP_Config::OPTION_DEFAULTS['mail'];
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
