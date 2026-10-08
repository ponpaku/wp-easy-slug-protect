<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * セキュリティ関連の処理を管理するクラス
 */
class ESP_Security {
    private $notify_on_failed_attempt = false;

    /**
     * IPアドレスの取得
     * 保守性・反複雑化を最優先し、REMOTE_ADDRのみを使用
     * 
     * @return string|false IPアドレス。取得できない場合はfalse
     */
    private function get_ip() {
        // REMOTE_ADDRの存在チェック
        if (!isset($_SERVER['REMOTE_ADDR']) || 
            !is_string($_SERVER['REMOTE_ADDR']) || 
            $_SERVER['REMOTE_ADDR'] === '') {
            
            // サーバー設定の問題として記録
            error_log('ESP_Security: Webserver misconfigured - REMOTE_ADDR not set');
            return false;
        }
        
        $ip = $_SERVER['REMOTE_ADDR'];
        
        // IPアドレスの妥当性検証（IPv4とIPv6両対応）
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) === false) {
            error_log('ESP_Security: Invalid IP address format: ' . $ip);
            return false;
        }
        
        // 追加の検証：予約済みアドレスのチェック（オプション）
        // 0.0.0.0 や ::0 などの無効なアドレスを除外
        if ($ip === '0.0.0.0' || $ip === '::' || $ip === '::0') {
            error_log('ESP_Security: Reserved or invalid IP address: ' . $ip);
            return false;
        }
        
        return $ip;
    }

    /**
     * Reserve a login attempt before password verification. Fail closed on DB errors.
     * A unique InnoDB row for each IP/path serializes concurrent reservations.
     */
    public function can_try_login($path_settings) {
        $this->notify_on_failed_attempt = false;
        $ip = $this->get_ip();
        if (!$ip) {
            return false;
        }
        $settings = ESP_Option::get_current_setting('brute');
        $whitelist = $this->parse_whitelist($settings['whitelist_ips'] ?? '');
        if ($this->is_ip_whitelisted($ip, $whitelist)) {
            return true;
        }

        global $wpdb;
        $table = $wpdb->prefix . ESP_Config::DB_TABLES['limit'];
        $now = time();
        $window = max(1, (int) $settings['time_frame']) * 60;
        $duration = max(1, (int) $settings['block_time_frame']) * 60;
        $threshold = max(1, (int) $settings['attempts_threshold']);
        $active = false;
        try {
            $created = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (ip_address, path_id, window_started, attempts, blocked_until, updated_at) VALUES (%s, %s, %d, 0, 0, %d)",
                $ip, $path_settings['id'], $now, $now
            ));
            if ($created === false || $wpdb->last_error !== '') {
                error_log('ESP_Security: Unable to initialize rate limit - ' . $wpdb->last_error);
                return false;
            }
            if ($wpdb->query('START TRANSACTION') === false) {
                error_log('ESP_Security: Unable to start rate limit transaction - ' . $wpdb->last_error);
                return false;
            }
            $active = true;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT attempts, window_started, blocked_until FROM {$table} WHERE ip_address = %s AND path_id = %s FOR UPDATE",
                $ip, $path_settings['id']
            ), ARRAY_A);
            if (!is_array($row) || $wpdb->last_error !== '') {
                return $this->abort_login_transaction($wpdb, 'Unable to read rate limit');
            }
            $blocked = (int) $row['blocked_until'];
            if ($blocked > $now) {
                $this->rollback_login_transaction($wpdb);
                return false;
            }
            $attempts = (int) $row['attempts'];
            $window_start = (int) $row['window_started'];
            if ($blocked > 0 || $window_start <= 0 || $now - $window_start >= $window) {
                $attempts = 0;
                $window_start = $now;
            }
            if ($attempts >= $threshold) {
                return $this->abort_login_transaction($wpdb, 'Rate limit state exceeds threshold');
            }
            $next = $attempts + 1;
            $until = $next >= $threshold ? $now + $duration : 0;
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET attempts = %d, window_started = %d, blocked_until = %d, updated_at = %d WHERE ip_address = %s AND path_id = %s",
                $next, $window_start, $until, $now, $ip, $path_settings['id']
            ));
            if ($changed !== 1 || $wpdb->last_error !== '') {
                return $this->abort_login_transaction($wpdb, 'Unable to update rate limit');
            }
            if ($wpdb->query('COMMIT') === false) {
                return $this->abort_login_transaction($wpdb, 'Unable to commit rate limit');
            }
            $active = false;
            $this->notify_on_failed_attempt = ($next === $threshold);
            return true;
        } catch (\Throwable $e) {
            if ($active) {
                $this->rollback_login_transaction($wpdb);
            }
            error_log('ESP_Security: Rate limit exception - ' . $e->getMessage());
            return false;
        }
    }

    private function rollback_login_transaction($wpdb) {
        try {
            $wpdb->query('ROLLBACK');
        } catch (\Throwable $e) {
            error_log('ESP_Security: Rollback exception - ' . $e->getMessage());
        }
    }

    private function abort_login_transaction($wpdb, $reason) {
        $error = $wpdb->last_error ?: 'no database error details';
        $this->rollback_login_transaction($wpdb);
        error_log('ESP_Security: ' . $reason . ' - ' . $error);
        return false;
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

        $transaction_open = false;
        try {
            // トランザクション開始（MyISAMの場合は機能しないが、InnoDBでは有効）
            if (false === $wpdb->query('START TRANSACTION')) {
                error_log('ESP_Security: Failed to start login attempt transaction - ' . $wpdb->last_error);
                return;
            }

            $transaction_open = true;

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

            // COUNT(*) は成功すれば0件でも値を返す。DBエラー時は記録を中断する。
            if ($current_attempts === null || $wpdb->last_error !== '') {
                // ROLLBACK時のwpdb::query()でlast_errorが初期化されるため退避する。
                $db_error = $wpdb->last_error ?: 'COUNT query returned no value';
                $wpdb->query('ROLLBACK');
                error_log('ESP_Security: Failed to query login attempts - ' . $db_error);
                return;
            }

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
                $db_error = $wpdb->last_error ?: 'unknown database error';
                $wpdb->query('ROLLBACK');
                error_log('ESP_Security: Failed to insert login attempt record - ' . $db_error);
                return;
            }

            // コミット成功後にのみ通知・クリーンアップを行う。
            if (false === $wpdb->query('COMMIT')) {
                $db_error = $wpdb->last_error ?: 'unknown database error';
                $wpdb->query('ROLLBACK');
                error_log('ESP_Security: Failed to commit login attempt transaction - ' . $db_error);
                return;
            }
            $transaction_open = false;

        } catch (\Throwable $e) {
            if ($transaction_open) {
                try {
                    $wpdb->query('ROLLBACK');
                } catch (\Throwable $rollback_error) {
                    error_log('ESP_Security: Failed to roll back login attempt transaction - ' . $rollback_error->getMessage());
                }
            }
            error_log('ESP_Security: Exception while recording login attempt - ' . $e->getMessage());
            return;
        }

        // 試行回数が閾値に達した場合に通知
        if (($current_attempts + 1) == $settings['attempts_threshold']) {
            $this->send_brute_force_notification($ip, $path, $current_attempts + 1);
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
