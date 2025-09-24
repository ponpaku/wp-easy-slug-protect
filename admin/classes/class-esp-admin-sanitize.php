<?php
if (!defined('ABSPATH')) {
    exit;
}

class ESP_Sanitize {

    /**
     * @var ESP_Mail メール送信インスタンス
     */
    private $mail;

    /**
     * コンストラクタ
     */
    public function __construct() {
        $this->mail = ESP_Mail::get_instance();
    }

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

        foreach ($paths as $_index => $path) {
            if (empty($path['path']) || empty($path['login_page'])) {
                continue;
            }

            $submitted_id = '';
            if (isset($path['id'])) {
                $submitted_id = sanitize_text_field($path['id']);
            }

            $is_existing = ($submitted_id !== '') && isset($existing_paths[$submitted_id]);
            $current_id = $is_existing ? $submitted_id : 'path_' . uniqid();
            $existing_path_data = $is_existing ? $existing_paths[$current_id] : null;

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
            $raw_password_for_mail = null;
            
            // 既存バージョンを取得
            $current_version = $existing_path_data ? intval($existing_path_data['password_version']) : 0;

            if (!empty($path['password'])) {
                if ($this->is_hashed_password($path['password'])) {
                    // 既にハッシュ化済みの場合はそのまま使用
                    $hashed_password = $path['password'];
                } else {
                    // 新しいパスワードの場合
                    $raw_password_for_mail = $path['password'];
                    $hashed_password = wp_hash_password($path['password']);
                }
            } else {
                // 既存のパスワードを維持
                if ($existing_path_data && !empty($existing_path_data['password'])) {
                    $hashed_password = $existing_path_data['password'];
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

            if ($raw_password_for_mail && $this->mail) {
                if ($is_existing) {
                    $this->mail->notify_password_change($normalized_path, $raw_password_for_mail);
                } else {
                    $this->mail->notify_new_protected_path($normalized_path, $raw_password_for_mail);
                }
            }

            // パスワードバージョンを決定
            $password_version = $current_version;
            if ($is_existing) {
                if ($raw_password_for_mail) {
                    // パスワード変更時はバージョンを更新
                    $password_version = $current_version + 1;
                } elseif (
                    isset($existing_path_data['password']) &&
                    $existing_path_data['password'] !== $hashed_password
                ) {
                    // 差分があれば安全のため加算
                    $password_version = $current_version + 1;
                }
            }

            // サニタイズされたパス情報を準備
            $sanitized_path = array(
                'id' => $current_id,
                'path' => $normalized_path,
                'login_page' => $login_page_id,
                'password' => $hashed_password,
                'password_version' => max(0, $password_version)
            );

            $sanitized[$current_id] = $sanitized_path;
        }
        
        // passをメモリから削除
        $raw_password_for_mail = null;
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

        // パスワードをメールに含めるかどうか
        $sanitized_settings['include_password'] = !empty($settings['include_password']) ? true : false;

        return $sanitized_settings;
    }

    /**
     * メディア配信設定のサニタイズ
     */
    public function sanitize_media_settings($settings) {
        if (!is_array($settings)) {
            $settings = array();
        }

        $allowed = array('auto', 'x-sendfile', 'x-litespeed-location', 'x-accel-redirect');
        $method = isset($settings['delivery_method']) ? sanitize_text_field($settings['delivery_method']) : 'auto';

        if (!in_array($method, $allowed, true)) {
            $method = 'auto';
        }

        return array(
            'delivery_method' => $method,
        );
    }
}
