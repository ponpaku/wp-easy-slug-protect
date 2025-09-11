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
     * ログイン試行が可能か確認
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
        // ホワイトリストのチェック
        if (isset($brute_settings['whitelist_ips']) && !empty($brute_settings['whitelist_ips'])) {
            $whitelisted_ips_raw = explode(',', $brute_settings['whitelist_ips']);
            $whitelisted_ips = array_map('trim', $whitelisted_ips_raw);

            // 大文字・小文字を区別せずにIPアドレスを比較するため、配列内のIPアドレスも現在のIPアドレスも小文字に変換する
            // (IPv6アドレスは大文字・小文字を区別しないため)
            $normalized_current_ip = strtolower($ip);
            $normalized_whitelisted_ips = array_map('strtolower', $whitelisted_ips);


            if (in_array($normalized_current_ip, $normalized_whitelisted_ips, true)) {
                return true; // ホワイトリストに合致すれば試行許可
            }
        }

        $path = $path_settings['path'];
        $path_id = $path_settings['id'];

        global $wpdb;
        $table = $wpdb->prefix . ESP_Config::DB_TABLES['brute'];

        // 試行回数カウント期間内のレコード数を取得
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

        // 最新の試行時刻を取得
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

        // ブロック時間が経過していれば許可
        // strtotime は失敗すると false を返すため、エラーハンドリングを追加
        $latest_attempt_timestamp = strtotime($latest_attempt);
        if ($latest_attempt_timestamp === false) {
            // 致命的エラーにしたほうがいいかどうか
            error_log("ESP_Security: Failed to parse latest_attempt time: {$latest_attempt}");
            return false; // 時刻のパースに失敗した場合、安全のため試行不可
        }
        
        $block_end_time = $latest_attempt_timestamp + ($brute_settings['block_time_frame'] * 60);
        return time() > $block_end_time;
    }


    /**
     * ログイン失敗を記録
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

        // 現在の試行回数を取得 (今回追加したものを含む)
        $current_attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
            FROM $table 
            WHERE ip_address = %s 
            AND path_id = %s 
            AND time > DATE_SUB(NOW(), INTERVAL %d MINUTE)",
            $ip,
            $path_id,
            $settings['time_frame']
        ));

        // 試行回数が閾値に達した場合に通知
        if ($current_attempts == $settings['attempts_threshold']) {
            // ESP_Mailクラスとnotify_brute_force_attemptメソッドが存在し、
            // 適切にオートロードされるか、事前に読み込まれていることを確認してください。
            if (class_exists('ESP_Mail') && method_exists(ESP_Mail::class, 'get_instance')) {
                $mailer = ESP_Mail::get_instance();
                if (method_exists($mailer, 'notify_brute_force_attempt')) {
                    $mailer->notify_brute_force_attempt($ip, $path, $current_attempts);
                } else {
                    error_log('ESP_Security: ESP_Mail class does not have notify_brute_force_attempt method.');
                }
            } else {
                error_log('ESP_Security: ESP_Mail class or get_instance method not found.');
            }
        }

        // 古いレコードを削除
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
}