<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * メール通知を管理するクラス
 */
class ESP_Mail {
    /**
     * シングルトンインスタンス 
     * @var ESP_Mail
     */
    private static $instance = null;

    /**
     * シングルトンパターンのためprivate
     */
    private function __construct() {}

    /**
     * インスタンスの取得
     * 
     * @return ESP_Mail
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 通知が有効かチェック
     * 
     * @param string $type 通知タイプ
     * @return bool 通知が有効な場合はtrue
     */
    private function is_notification_enabled($type) {
        $settings = ESP_Option::get_current_setting('mail');
        return $settings['enable_notifications'] && 
               isset($settings['notifications'][$type]) && 
               $settings['notifications'][$type];
    }

    /**
     * 管理者メールアドレスを取得
     * 
     * @return string メールアドレス
     */
    private function get_admin_email() {
        return get_option('admin_email');
    }

    /**
     * サイト名を取得
     * 
     * @return string サイト名
     */
    private function get_site_name() {
        return wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
    }

    /**
     * メールにパスワードを含めるかどうかを判定
     *
     * @return bool
     */
    private function should_include_password_in_email() {
        $settings = ESP_Option::get_current_setting('mail');
        return !empty($settings['include_password']);
    }

    /**
     * メール送信のラッパー関数
     * 
     * @param string $subject 件名
     * @param string $message 本文
     * @return bool すべての送信が成功した場合はtrue
     */
    private function send_mail($subject, $message) {
        // メール通知が無効な場合は送信しない
        $settings = ESP_Option::get_current_setting('mail');
        if (!$settings['enable_notifications']) {
            return false;
        }

        // 管理者ロールを持つユーザーを取得
        $user_query = new WP_User_Query(['role' => 'Administrator']);
        if (!$user_query->results) {
            error_log('ESP: No administrators found to send email to.');
            return false;
        }

        $message .= "\n\n----------------\nplugin: easy slug protect";

        $all_success = true;
        foreach ($user_query->results as $user) {
            $user_email = $user->get('user_email');
            $result = wp_mail($user_email, $subject, $message);
            
            if (!$result) {
                error_log(sprintf(
                    'ESP: Failed to send mail to administrator. To: %s, Subject: %s',
                    $user_email,
                    $subject
                ));
                $all_success = false;
            }
        }

        return $all_success;
    }

    /**
     * 新しい保護パス追加時の通知
     */
    public function notify_new_protected_path($path, $password) {
        if (!$this->is_notification_enabled('new_path')) {
            return false;
        }

        $subject = sprintf(
            '[%s] 新しい保護パスが追加されました',
            $this->get_site_name()
        );

        $message = "新しい保護パスが追加されました。\n\n";
        $message .= "保護パス: {$path}\n";

        if ($this->should_include_password_in_email()) {
            $message .= "パスワード: {$password}\n\n";
        } else {
            $message .= "設定によりパスワードはこのメールには含まれていません。\n\n";
        }
        $message .= "このメールは " . home_url() . " より自動送信されています。";

        return $this->send_mail($subject, $message);
    }

    /**
     * パスワード変更時の通知
     * 
     * @param string $path パス
     * @param string $new_password 新しいパスワード（平文）
     * @return bool 送信成功時はtrue
     */
    public function notify_password_change($path, $new_password) {
        if (!$this->is_notification_enabled('password_change')) {
            return false;
        }
        $subject = sprintf(
            '[%s] 保護パスのパスワードが変更されました',
            $this->get_site_name()
        );

        $message = "保護パスのパスワードが変更されました。\n\n";
        $message .= "保護パス: {$path}\n";

        if ($this->should_include_password_in_email()) {
            $message .= "新しいパスワード: {$new_password}\n\n";
        } else {
            $message .= "設定により新しいパスワードはこのメールには含まれていません。\n\n";
        }
        $message .= "このメールは " . home_url() . " より自動送信されています。";

        return $this->send_mail($subject, $message);
    }

    /**
     * 保護パス削除時の通知
     * 
     * @param string $path 削除されたパス
     * @return bool 送信成功時はtrue
     */
    public function notify_path_removed($path) {
        if (!$this->is_notification_enabled('path_remove')) {
            return false;
        }

        $subject = sprintf(
            '[%s] 保護パスが削除されました',
            $this->get_site_name()
        );

        $message = "以下の保護パスが削除されました。\n\n";
        $message .= "保護パス: {$path}\n\n";
        $message .= "このメールは " . home_url() . " より自動送信されています。";

        return $this->send_mail($subject, $message);
    }

    /**
     * 致命的なエラーの通知
     * 
     * @param string $error_message エラーメッセージ
     * @param array $context エラーのコンテキスト情報
     * @return bool 送信成功時はtrue
     */
    public function notify_critical_error($error_message, $context = []) {
        if (!$this->is_notification_enabled('critical_error')) {
            return false;
        }

        $subject = sprintf(
            '[%s] Easy Slug Protect: 重要なエラーが発生しました',
            $this->get_site_name()
        );

        $message = "Easy Slug Protect で重要なエラーが発生しました。\n\n";
        $message .= "エラーメッセージ:\n{$error_message}\n\n";
        
        if (!empty($context)) {
            $message .= "エラー詳細:\n";
            foreach ($context as $key => $value) {
                $message .= "{$key}: {$value}\n";
            }
            $message .= "\n";
        }

        $message .= "発生日時: " . current_time('Y-m-d H:i:s') . "\n";
        $message .= "サイトURL: " . home_url() . "\n";

        return $this->send_mail($subject, $message);
    }

    /**
     * ブルートフォース攻撃の疑いがある場合の通知
     * 
     * @param string $ip IPアドレス
     * @param string $path 対象パス
     * @param int $attempts 試行回数
     * @return bool 送信成功時はtrue
     */
    public function notify_brute_force_attempt($ip, $path, $attempts) {
        if (!$this->is_notification_enabled('brute_force')) {
            return false;
        }

        $subject = sprintf(
            '[%s] ブルートフォース攻撃の疑いがあります',
            $this->get_site_name()
        );

        $message = "ブルートフォース攻撃の疑いがある行為を検出しました。\n\n";
        $message .= "IPアドレス: {$ip}\n";
        $message .= "対象パス: {$path}\n";
        $message .= "試行回数: {$attempts}\n";
        $message .= "検出日時: " . current_time('Y-m-d H:i:s') . "\n\n";
        $message .= "アクセスは一時的にブロックされています。\n";

        return $this->send_mail($subject, $message);
    }

    /**
     * カスタム通知メールの送信
     * 
     * @param string $subject 件名
     * @param string $message メッセージ
     * @param array $headers 追加ヘッダー
     * @return bool 送信成功時はtrue
     */
    public function send_custom_notification($subject, $message, $headers = []) {
        return $this->send_mail($subject, $message, $headers);
    }
}