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
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return $ip;
    }

    /**
     * ログイン試行が可能か確認
     * 
     * @param string $path 保護対象のパス
     * @return bool 試行可能な場合はtrue
     */
    public function can_try_login($path) {
        $ip = $this->get_ip();
        if (!$ip) {
            return false;
        }

        global $wpdb;
        $settings = ESP_Option::get_current_setting('brute');
        $table = $wpdb->prefix . ESP_Config::DB_TABLES['brute'];

        // 試行回数カウント期間内のレコード数を取得
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
            FROM $table 
            WHERE ip_address = %s 
            AND path = %s 
            AND time > DATE_SUB(NOW(), INTERVAL %d MINUTE)",
            $ip,
            $path,
            $settings['time_frame']
        ));

        // 試行回数が上限未満なら許可
        if ($count < $settings['attempts_threshold']) {
            return true;
        }

        // 最新の試行時刻を取得
        $latest_attempt = $wpdb->get_var($wpdb->prepare(
            "SELECT time 
            FROM $table 
            WHERE ip_address = %s 
            AND path = %s 
            ORDER BY time DESC 
            LIMIT 1",
            $ip,
            $path
        ));

        // ブロック時間が経過していれば許可
        $block_end_time = strtotime($latest_attempt) + ($settings['block_time_frame'] * 60);
        return time() > $block_end_time;
    }

    /**
     * ログイン失敗を記録
     * 
     * @param string $path 保護対象のパス
     */
    public function record_failed_attempt($path) {
        $ip = $this->get_ip();
        if (!$ip) {
            return;
        }

        global $wpdb;

        // 新規レコードを追加
        $result = $wpdb->insert(
            $wpdb->prefix . ESP_Config::DB_TABLES['brute'],
            array(
                'ip_address' => $ip,
                'path' => $path,
                'time' => current_time('mysql')
            ),
            array('%s', '%s', '%s')
        );

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
     * @param string $path 保護対象のパス
     * @return bool 検証成功時はtrue
     */
    public function verify_nonce($nonce, $path) {
        return wp_verify_nonce($nonce, 'esp_login_' . $path);
    }
}