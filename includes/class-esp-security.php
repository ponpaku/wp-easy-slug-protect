<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * セキュリティ関連の処理を管理するクラス
 */
class ESP_Security {
    /**
     * IPアドレスの取得
     * 
     * @return string|false IPアドレス。取得できない場合はfalse
     */
    private function get_ip() {
        $ip = false;

        // Cloudflare経由の場合
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        // 通常のアクセスの場合
        elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
            // プロキシ経由の場合は実IPを取得
            if (preg_match('/^(?:127|10)\.0\.0\.[12]?\d{1,2}$/', $ip)) {
                if (isset($_SERVER['HTTP_X_REAL_IP'])) {
                    $ip = $_SERVER['HTTP_X_REAL_IP'];
                } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
                }
            }
        }

        // IPアドレスの検証
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return $ip;
    }

    /**
    * ログイン試行が可能か確認（改善版）
    * 
    * @param array $path_settings 保護対象のパス設定
    * @return bool 試行可能な場合はtrue
    */
    public function can_try_login($path_settings) {
        $ip = $this->get_ip();
        if (!$ip) {
            return false;
        }

        $brute_settings = ESP_Option::get_current_setting('brute');
        
        // ホワイトリストのチェック（キャッシュ利用）
        static $whitelist_cache = null;
        if ($whitelist_cache === null) {
            $whitelist_cache = $this->parse_whitelist($brute_settings['whitelist_ips'] ?? '');
        }
        
        if ($this->is_ip_whitelisted($ip, $whitelist_cache)) {
            return true;
        }

        $path_id = $path_settings['id'];

        global $wpdb;
        $table = $wpdb->prefix . ESP_Config::DB_TABLES['brute'];

        // 試行回数カウント期間内のレコード数を取得（インデックス利用）
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
            FROM $table 
            WHERE ip_address = %s 
            AND path_id = %s 
            AND time > DATE_SUB(NOW(), INTERVAL %d MINUTE)",
            $ip,
            $path_id,
            $brute_settings['time_frame']
        ));

        // 試行回数が上限未満なら許可
        if ($count < $brute_settings['attempts_threshold']) {
            return true;
        }

        // ブロック期間のチェック
        $latest_attempt = $wpdb->get_var($wpdb->prepare(
            "SELECT time 
            FROM $table 
            WHERE ip_address = %s 
            AND path_id = %s 
            ORDER BY time DESC 
            LIMIT 1",
            $ip,
            $path_id
        ));

        if (!$latest_attempt) {
            return true;
        }

        $latest_attempt_timestamp = strtotime($latest_attempt);
        if ($latest_attempt_timestamp === false) {
            error_log("ESP_Security: Failed to parse latest_attempt time: {$latest_attempt}");
            return false;
        }
        
        $block_end_time = $latest_attempt_timestamp + ($brute_settings['block_time_frame'] * 60);
        return time() > $block_end_time;
    }

    /**
    * ホワイトリストを解析
    */
    private function parse_whitelist($whitelist_string) {
        if (empty($whitelist_string)) {
            return [];
        }
        
        $ips = explode(',', $whitelist_string);
        $parsed = [];
        
        foreach ($ips as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $parsed[] = strtolower($ip);
            }
        }
        
        return $parsed;
    }

    /**
    * IPがホワイトリストに含まれるかチェック
    */
    private function is_ip_whitelisted($ip, $whitelist) {
        return in_array(strtolower($ip), $whitelist, true);
    }


    /**
    * ログイン失敗を記録（トランザクション対応版）
    * 
    * @param array $path_settings 保護対象のパス設定
    */
    public function record_failed_attempt($path_settings) {
        $ip = $this->get_ip();
        if (!$ip) {
            return;
        }

        $path = $path_settings['path'];
        $path_id = $path_settings['id'];
        $settings = ESP_Option::get_current_setting('brute');

        global $wpdb;
        $table = $wpdb->prefix . ESP_Config::DB_TABLES['brute'];

        // トランザクション開始（MyISAMの場合は機能しないが、InnoDBでは有効）
        $wpdb->query('START TRANSACTION');
        
        try {
            // 現在の試行回数を取得（ロック付き）
            $current_attempts = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) 
                FROM $table 
                WHERE ip_address = %s 
                AND path_id = %s 
                AND time > DATE_SUB(NOW(), INTERVAL %d MINUTE)
                FOR UPDATE",
                $ip,
                $path_id,
                $settings['time_frame']
            ));

            // 既に閾値を超えている場合は記録せずに終了
            if ($current_attempts >= $settings['attempts_threshold']) {
                $wpdb->query('ROLLBACK');
                return;
            }

            // 新規レコードを追加
            $result = $wpdb->insert(
                $table,
                array(
                    'ip_address' => $ip,
                    'path' => $path,
                    'path_id' => $path_id,
                    'time' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s')
            );

            if ($result === false) {
                $wpdb->query('ROLLBACK');
                error_log('ESP_Security: Failed to insert login attempt record');
                return;
            }

            // コミット
            $wpdb->query('COMMIT');

            // 試行回数が閾値に達した場合に通知
            if (($current_attempts + 1) == $settings['attempts_threshold']) {
                $this->send_brute_force_notification($ip, $path, $current_attempts + 1);
            }

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('ESP_Security: Transaction failed - ' . $e->getMessage());
        }

        // 古いレコードを削除（トランザクション外で実行）
        $this->cleanup_old_attempts();
    }

    /**
     * 古いログイン試行記録の削除
     */
    public function cleanup_old_attempts() {
        global $wpdb;
        $settings = ESP_Option::get_current_setting('brute');
        $table = $wpdb->prefix . ESP_Config::DB_TABLES['brute'];

        // ブロック時間より古いレコードを削除
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table 
            WHERE time < DATE_SUB(NOW(), INTERVAL %d MINUTE)",
            $settings['block_time_frame']
        ));
    }

    /**
    * ブルートフォース通知を送信
    */
    private function send_brute_force_notification($ip, $path, $attempts) {
        if (class_exists('ESP_Mail')) {
            $mailer = ESP_Mail::get_instance();
            if (method_exists($mailer, 'notify_brute_force_attempt')) {
                $mailer->notify_brute_force_attempt($ip, $path, $attempts);
            }
        }
    }

    /**
     * CSRFトークンの検証
     * 
     * @param string $nonce POSTされたnonce
     * @param string $path_id パスID
     * @return bool 検証成功時はtrue
     */
    public function verify_nonce($nonce, $path_id) {
        return wp_verify_nonce($nonce, 'esp_login_' . $path_id);
    }

    /**
     * Cron用の古いブルートフォース試行ログクリーンアップ
     */
    public static function cron_cleanup_brute() {
        (new self)->cleanup_old_attempts();
    }

    /**
     * Cron用の古いRemember Meトークンクリーンアップ
     */
    public static function cron_cleanup_remember() {
        global $wpdb;
        $table = $wpdb->prefix . ESP_Config::DB_TABLES['remember'];
        $wpdb->query("DELETE FROM {$table} WHERE expires < NOW()");
    }

    /**
     * Cron用の通常ログインセッションクリーンアップ
     */
    public static function cron_cleanup_sessions() {
        global $wpdb;
        $table = $wpdb->prefix . ESP_Config::DB_TABLES['session'];
        $wpdb->query("DELETE FROM {$table} WHERE expires < NOW()");
    }
}
