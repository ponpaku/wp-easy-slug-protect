<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * セッション管理を行うクラス
 */
class ESP_Session {
    /**
     * インスタンス
     * @var ESP_Session
     */
    private static $instance = null;

    /**
     * シングルトンパターンのためprivate
     */
    private function __construct() {
        /**
         * initだとヘッダー書き出し後にセッション張ってもダメだと怒られることがあり、
         * filterクラス用にpre_get_postsより前にセッションを張る必要もあるので、暫定的wp_loaded 
         */
        // ↑wp-cronを省いてなかったからかも。initに戻す20250410
        add_action('init', [$this, 'start_session'], 0);
        
        add_action('wp_logout', [$this, 'end_session']);
    }

    /**
     * セッションの開始
     */
    public function start_session() {
        // admin, ajax, cron, rest_api,はセッション開始しない
        if (!is_admin() && !wp_doing_ajax() && !wp_doing_cron() && !(defined('REST_REQUEST') && REST_REQUEST) && session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * セッションの終了
     */
    public function end_session() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * インスタンスの取得
     * 
     * @return ESP_Session
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * セッション値の設定
     * 
     * @param string $key キー
     * @param mixed $value 値
     */
    public function set($key, $value) {
        $_SESSION['esp_' . $key] = $value;
    }

    /**
     * セッション値の取得
     * 
     * @param string $key キー
     * @return mixed セッション値。存在しない場合はnull
     */
    public function get($key) {
        return $_SESSION['esp_' . $key] ?? null;
    }

    /**
     * セッション値の削除
     * 
     * @param string $key キー
     */
    public function delete($key) {
        unset($_SESSION['esp_' . $key]);
    }

    /**
     * エラーメッセージの設定
     * 
     * @param string $message エラーメッセージ
     */
    public function set_error($message) {
        $this->set('error', $message);
    }

    /**
     * エラーメッセージの削除
     * 
     */
    public function del_error(){
        $this->delete('error');
    }

    /**
     * エラーメッセージの取得
     * 
     * @return string|null エラーメッセージ
     */
    public function get_error() {
        $error = $this->get('error');
        $this->delete('error');
        return $error;
    }
}