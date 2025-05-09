<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * エラーメッセージなどの一時メッセージを管理するクラス
 * Cookieベースではなく、トランジェント（一時データ）ベースに変更
 */
class ESP_Message {
    /**
     * @var string トランジェントのプレフィックス
     */
    const TRANSIENT_PREFIX = 'esp_msg_';
    
    /**
     * @var int データの有効期間（秒）
     */
    const EXPIRY = 60; // 1分間のみ有効
    
    /**
     * エラーメッセージを設定
     * 
     * @param string $message エラーメッセージ
     */
    public static function set_error($message) {
        self::set_message('error', $message);
    }
    
    /**
     * メッセージを設定
     * 
     * @param string $type メッセージタイプ
     * @param string $message メッセージ内容
     */
    public static function set_message($type, $message) {
        // ユニークなIDを生成
        $id = md5(uniqid('esp', true));
        
        // メッセージデータを保存
        $data = [
            'type' => $type,
            'message' => $message,
            'created' => time()
        ];
        
        // トランジェントに保存
        set_transient(self::TRANSIENT_PREFIX . $id, $data, self::EXPIRY);
        
        // IDをCookieに保存（ヘッダーがまだ送信されていない場合のみ）
        if (!headers_sent()) {
            if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
                setcookie(
                    'esp_msg_id',
                    $id,
                    [
                        'expires' => time() + self::EXPIRY,
                        'path' => COOKIEPATH,
                        'domain' => COOKIE_DOMAIN,
                        'secure' => is_ssl(),
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]
                );
            } else {
                setcookie(
                    'esp_msg_id',
                    $id,
                    time() + self::EXPIRY,
                    COOKIEPATH,
                    COOKIE_DOMAIN,
                    is_ssl(),
                    true
                );
            }
        } else {
            // Cookieが設定できない場合はリダイレクトURLにIDを付加
            add_filter('wp_redirect', function($location) use ($id) {
                // URLにクエリ文字列を追加
                $separator = (strpos($location, '?') !== false) ? '&' : '?';
                return $location . $separator . 'esp_msg_id=' . $id;
            });
        }
    }
    
    /**
     * エラーメッセージを取得
     * 
     * @return string|null エラーメッセージ
     */
    public static function get_error() {
        $message = self::get_message();
        
        if ($message && $message['type'] === 'error') {
            return $message['message'];
        }
        
        return null;
    }
    
    /**
     * メッセージを取得
     * 
     * @return array|null ['type' => string, 'message' => string]形式のメッセージ
     */
    public static function get_message() {
        $id = self::get_message_id();
        
        if (!$id) {
            return null;
        }
        
        // トランジェントからデータを取得
        $data = get_transient(self::TRANSIENT_PREFIX . $id);
        
        if (!$data) {
            return null;
        }
        
        // メッセージを取得したらトランジェントを削除（1回だけ表示）
//         delete_transient(self::TRANSIENT_PREFIX . $id);
        
        // クッキーも削除（可能な場合）
        self::clear_cookie();
        
        return $data;
    }
    
    /**
     * メッセージIDを取得
     * 
     * @return string|null メッセージID
     */
    private static function get_message_id() {
        // URLからIDを取得
        if (isset($_GET['esp_msg_id'])) {
            return $_GET['esp_msg_id'];
        }
        
        // Cookieからメッセージを取得
        if (isset($_COOKIE['esp_msg_id'])) {
            return $_COOKIE['esp_msg_id'];
        }
        
        return null;
    }
    
    /**
     * Cookieをクリア
     */
    private static function clear_cookie() {
        if (headers_sent()) {
            return;
        }
        
        if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
            setcookie(
                'esp_msg_id',
                '',
                [
                    'expires' => time() - 3600,
                    'path' => COOKIEPATH,
                    'domain' => COOKIE_DOMAIN,
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );
        } else {
            setcookie(
                'esp_msg_id',
                '',
                time() - 3600,
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                true
            );
        }
    }
}