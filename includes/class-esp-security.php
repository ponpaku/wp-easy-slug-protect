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
    private $last_reservation_token = null;

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
     * Reserve an attempt before password verification. The single InnoDB row
     * serializes requests for this IP/path; individual UTC timestamps enforce
     * an exact rolling window rather than a first-attempt fixed window.
     */
    public function can_try_login($path_settings) {
        $this->notify_on_failed_attempt = false;
        $this->last_reservation_token = null;
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
                "SELECT attempts, window_started, blocked_until, attempt_times FROM {$table} WHERE ip_address = %s AND path_id = %s FOR UPDATE",
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

            // Rows created by v0.7.36 have no timestamp list. Preserve their
            // existing unexpired reservations conservatively until they expire.
            if ($blocked > 0) {
                $times = [];
            } elseif ($row['attempt_times'] === null) {
                $started = (int) $row['window_started'];
                $count = max(0, (int) $row['attempts']);
                $times = ($started > $now - $window && $started <= $now)
                    ? array_fill(0, min($count, $threshold), $started) : [];
            } else {
                $times = json_decode($row['attempt_times'], true);
                if (!is_array($times)) {
                    return $this->abort_login_transaction($wpdb, 'Invalid rate limit timestamp list');
                }
            }

            $recent = [];
            foreach ($times as $timestamp) {
                if (!is_int($timestamp) || $timestamp < 0) {
                    return $this->abort_login_transaction($wpdb, 'Invalid rate limit timestamp');
                }
                if ($timestamp > $now - $window) {
                    $recent[] = $timestamp;
                }
            }
            if (count($recent) >= $threshold) {
                $this->rollback_login_transaction($wpdb);
                return false;
            }

            $recent[] = $now;
            $next = count($recent);
            $until = $next >= $threshold ? $now + $duration : 0;
            $encoded = json_encode($recent);
            if ($encoded === false) {
                return $this->abort_login_transaction($wpdb, 'Unable to encode rate limit timestamps');
            }
            $token = bin2hex(random_bytes(16));
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET attempts = %d, window_started = %d, blocked_until = %d, updated_at = %d, attempt_times = %s, last_attempt_token = %s WHERE ip_address = %s AND path_id = %s",
                $next, $recent[0], $until, $now, $encoded, $token, $ip, $path_settings['id']
            ));
            if ($changed !== 1 || $wpdb->last_error !== '') {
                return $this->abort_login_transaction($wpdb, 'Unable to update rate limit');
            }
            if ($wpdb->query('COMMIT') === false) {
                return $this->abort_login_transaction($wpdb, 'Unable to commit rate limit');
            }
            $active = false;
            $this->last_reservation_token = $token;
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


    /** Store failure history for auditing; the counter was reserved before the password check. */
    public function record_failed_attempt($path_settings) {
        $ip = $this->get_ip();
        if (!$ip) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . ESP_Config::DB_TABLES['brute'];
        try {
            $stored = $wpdb->insert($table, array(
                'ip_address' => $ip,
                'path' => $path_settings['path'],
                'path_id' => $path_settings['id'],
                'time' => gmdate('Y-m-d H:i:s')
            ), array('%s', '%s', '%s', '%s'));
            if ($stored === false) {
                error_log('ESP_Security: Failure audit insert failed - ' . $wpdb->last_error);
            }
        } catch (\Throwable $e) {
            error_log('ESP_Security: Failure audit exception - ' . $e->getMessage());
        }
        if ($this->notify_on_failed_attempt) {
            $this->notify_on_failed_attempt = false;
            try {
                $settings = ESP_Option::get_current_setting('brute');
                $this->send_brute_force_notification($ip, $path_settings['path'], (int) $settings['attempts_threshold']);
            } catch (\Throwable $e) {
                error_log('ESP_Security: Notification exception - ' . $e->getMessage());
            }
        }
    }

    /**
     * Clear only this request's reservations. If another request has reserved
     * a later slot, its counter must not be overwritten by this login success.
     */
    public function reset_successful_attempts($path_settings) {
        $this->notify_on_failed_attempt = false;
        if ($this->last_reservation_token === null) {
            return;
        }
        $ip = $this->get_ip();
        if (!$ip) {
            return;
        }
        $settings = ESP_Option::get_current_setting('brute');
        if ($this->is_ip_whitelisted($ip, $this->parse_whitelist($settings['whitelist_ips'] ?? ''))) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . ESP_Config::DB_TABLES['limit'];
        try {
            $now = time();
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET attempts = 0, blocked_until = 0, window_started = %d, updated_at = %d, attempt_times = '[]', last_attempt_token = '' WHERE ip_address = %s AND path_id = %s AND last_attempt_token = %s",
                $now, $now, $ip, $path_settings['id'], $this->last_reservation_token
            ));
            if ($result === false) {
                error_log('ESP_Security: Rate limit reset failed - ' . $wpdb->last_error);
            }
        } catch (\Throwable $e) {
            error_log('ESP_Security: Rate limit reset exception - ' . $e->getMessage());
        } finally {
            $this->last_reservation_token = null;
        }
    }

    /**
     * 古いログイン試行記録の削除
     */
    public function cleanup_old_attempts() {
        global $wpdb;
        $settings = ESP_Option::get_current_setting('brute');
        $history = $wpdb->prefix . ESP_Config::DB_TABLES['brute'];
        $limits = $wpdb->prefix . ESP_Config::DB_TABLES['limit'];
        // Long enough to avoid discarding an active block, even with custom settings.
        $seconds = max(604800, ((int) $settings['block_time_frame'] + (int) $settings['time_frame']) * 60 + 3600);
        try {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$history} WHERE time < %s",
                gmdate('Y-m-d H:i:s', time() - $seconds)
            ));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$limits} WHERE updated_at < %d AND blocked_until < %d",
                time() - $seconds, time()
            ));
        } catch (\Throwable $e) {
            error_log('ESP_Security: Rate limit cleanup exception - ' . $e->getMessage());
        }
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
