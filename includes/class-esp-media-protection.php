<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * メディアファイルの保護機能を管理するクラス
 */
class ESP_Media_Protection {
    /**
     * @var ESP_Auth 認証クラスのインスタンス
     */
    private $auth;

    /**
     * @var ESP_Cookie Cookie管理クラスのインスタンス
     */
    private $cookie;

    /**
     * @var ESP_Core コアクラスのインスタンス
     */
    private $core;

    /**
     * @var string メディア保護用メタキー
     */
    const META_KEY_PROTECTED_PATH = '_esp_media_protected_path_id';

    /**
     * @var string リライトルールのエンドポイント
     */
    const REWRITE_ENDPOINT = 'esp-media';

    /**
     * @var array 保護対象のファイル拡張子
     */
    private $protected_extensions = [
        // 画像
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp',
        // ドキュメント
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        // 動画
        'mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm',
        // 音声
        'mp3', 'wav', 'ogg', 'wma', 'aac', 'flac',
        // アーカイブ
        'zip', 'rar', '7z', 'tar', 'gz',
        // その他
        'txt', 'csv', 'xml', 'json'
    ];

    /**
     * コンストラクタ
     */
    public function __construct() {
        
        // フックの登録
        // add_action('init', [$this, 'init']);
        $this->init();
    }

    /**
     * 初期化処理
     */
    public function init() {
        // 管理画面の場合
        if (is_admin()) {
            // メディアライブラリのカスタムフィールドを追加
            add_filter('attachment_fields_to_edit', [$this, 'add_media_protection_field'], 10, 2);
            add_filter('attachment_fields_to_save', [$this, 'save_media_protection_field'], 10, 2);
            
            // メディアライブラリの列を追加
            add_filter('manage_media_columns', [$this, 'add_media_columns']);
            add_action('manage_media_custom_column', [$this, 'render_media_columns'], 10, 2);
            
            // 一括操作の追加
            add_filter('bulk_actions-upload', [$this, 'add_bulk_actions']);
            add_filter('handle_bulk_actions-upload', [$this, 'handle_bulk_actions'], 10, 3);

            $this->auth = new ESP_Auth();
            $this->cookie = ESP_Cookie::get_instance();
            add_action('template_redirect', [$this, 'handle_media_access'], 1);
        } else {
            // フロントエンドでのアクセス制御
            $this->auth = new ESP_Auth();
            $this->cookie = ESP_Cookie::get_instance();
            add_action('template_redirect', [$this, 'handle_media_access'], 1);
        }

        // リライトルールの追加
        add_action('init', [$this, 'add_rewrite_rules']);
        
        // アップロード時の自動保護設定
        add_action('add_attachment', [$this, 'auto_protect_on_upload']);
        
        // 保護パス削除時の処理
        add_action('update_option_' . ESP_Config::OPTION_KEY, [$this, 'handle_path_deletion'], 10, 2);
    }

    /**
     * メディア編集画面に保護パス選択フィールドを追加
     *
     * @param array $form_fields 既存のフォームフィールド
     * @param WP_Post $post 添付ファイルのポストオブジェクト
     * @return array 修正されたフォームフィールド
     */
    public function add_media_protection_field($form_fields, $post) {
        $protected_paths = ESP_Option::get_current_setting('path');
        $current_path_id = get_post_meta($post->ID, self::META_KEY_PROTECTED_PATH, true);
        
        $options = '<option value="">' . __('保護しない', ESP_Config::TEXT_DOMAIN) . '</option>';
        
        foreach ($protected_paths as $path_id => $path_settings) {
            $selected = selected($current_path_id, $path_id, false);
            $options .= sprintf(
                '<option value="%s" %s>%s</option>',
                esc_attr($path_id),
                $selected,
                esc_html($path_settings['path'])
            );
        }
        
        $form_fields['esp_protected_path'] = [
            'label' => __('保護パス', ESP_Config::TEXT_DOMAIN),
            'input' => 'html',
            'html' => '<select name="attachments[' . $post->ID . '][esp_protected_path]" id="attachments-' . $post->ID . '-esp_protected_path">' . $options . '</select>',
            'helps' => __('このメディアファイルを保護するパスを選択してください。', ESP_Config::TEXT_DOMAIN)
        ];
        
        return $form_fields;
    }

    /**
     * メディア保護設定を保存
     *
     * @param array $post 添付ファイルのデータ
     * @param array $attachment 添付ファイルの入力データ
     * @return array 修正された添付ファイルデータ
     */
    public function save_media_protection_field($post, $attachment) {
        if (isset($attachment['esp_protected_path'])) {
            if (empty($attachment['esp_protected_path'])) {
                delete_post_meta($post['ID'], self::META_KEY_PROTECTED_PATH);
            } else {
                update_post_meta($post['ID'], self::META_KEY_PROTECTED_PATH, sanitize_text_field($attachment['esp_protected_path']));
            }
        }
        
        return $post;
    }

    /**
     * メディアライブラリに列を追加
     *
     * @param array $columns 既存の列
     * @return array 修正された列
     */
    public function add_media_columns($columns) {
        $columns['esp_protection'] = __('保護状態', ESP_Config::TEXT_DOMAIN);
        return $columns;
    }

    /**
     * メディアライブラリの列をレンダリング
     *
     * @param string $column_name 列名
     * @param int $post_id 投稿ID
     */
    public function render_media_columns($column_name, $post_id) {
        if ($column_name === 'esp_protection') {
            $path_id = get_post_meta($post_id, self::META_KEY_PROTECTED_PATH, true);
            
            if ($path_id) {
                $protected_paths = ESP_Option::get_current_setting('path');
                if (isset($protected_paths[$path_id])) {
                    echo '<span class="dashicons dashicons-lock" style="color: #d63638;"></span> ';
                    echo esc_html($protected_paths[$path_id]['path']);
                } else {
                    echo '<span class="dashicons dashicons-warning" style="color: #dba617;"></span> ';
                    _e('無効な保護設定', ESP_Config::TEXT_DOMAIN);
                }
            } else {
                echo '<span class="dashicons dashicons-unlock" style="color: #00a32a;"></span> ';
                _e('保護なし', ESP_Config::TEXT_DOMAIN);
            }
        }
    }

    /**
     * 一括操作を追加
     *
     * @param array $bulk_actions 既存の一括操作
     * @return array 修正された一括操作
     */
    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['esp_protect'] = __('保護パスを設定', ESP_Config::TEXT_DOMAIN);
        $bulk_actions['esp_unprotect'] = __('保護を解除', ESP_Config::TEXT_DOMAIN);
        return $bulk_actions;
    }

    /**
     * 一括操作を処理
     *
     * @param string $redirect_to リダイレクト先URL
     * @param string $doaction 実行されたアクション
     * @param array $post_ids 対象の投稿ID
     * @return string リダイレクト先URL
     */
    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ($doaction === 'esp_unprotect') {
            foreach ($post_ids as $post_id) {
                delete_post_meta($post_id, self::META_KEY_PROTECTED_PATH);
            }
            
            $redirect_to = add_query_arg('esp_unprotected', count($post_ids), $redirect_to);
        }
        
        return $redirect_to;
    }

    /**
     * リライトルールを追加
     */
    public function add_rewrite_rules() {
        $upload_dir = wp_upload_dir();
        $upload_path = str_replace(home_url(), '', $upload_dir['baseurl']);
        $upload_path = trim($upload_path, '/');
        
        // wp-content/uploads/以下のファイルへのアクセスをキャッチ
        add_rewrite_rule(
            '^' . $upload_path . '/(.+)$',
            'index.php?' . self::REWRITE_ENDPOINT . '=$matches[1]',
            'top'
        );
        
        // クエリ変数を追加
        add_filter('query_vars', function($vars) {
            $vars[] = self::REWRITE_ENDPOINT;
            return $vars;
        });
    }

    /**
     * メディアファイルへのアクセスを処理
     */
    public function handle_media_access() {
        $requested_file = get_query_var(self::REWRITE_ENDPOINT);

        if (empty($requested_file)) {
            return;
        }
        
        // ファイルパスの検証とサニタイズ
        $file_path = $this->validate_file_path($requested_file);
        if (!$file_path) {
            $this->send_404();
            return;
        }

        // 管理画面スキップ
        if (is_admin()) {
            $this->deliver_file($file_path);
            return;
        }

        // ファイルアップ権限者スキップ
        if ( current_user_can( 'upload_files' ) ) {
            $this->deliver_file( $file_path );
            return;
        }
        
        // ファイル拡張子の確認
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->protected_extensions, true)) {
            // 保護対象外の拡張子の場合は通常通りファイルを配信
            $this->deliver_file($file_path);
            return;
        }
        
        // メディアIDを取得
        $attachment_id = $this->get_attachment_id_from_path($file_path);
        if (!$attachment_id) {
            // メディアライブラリに登録されていないファイルは通常配信
            $this->deliver_file($file_path);
            return;
        }
        
        // 保護設定を確認
        $protected_path_id = get_post_meta($attachment_id, self::META_KEY_PROTECTED_PATH, true);
        if (empty($protected_path_id)) {
            // 保護されていないファイルは通常配信
            $this->deliver_file($file_path);
            return;
        }
        
        // 保護パス設定を取得
        $protected_paths = ESP_Option::get_current_setting('path');
        if (!isset($protected_paths[$protected_path_id])) {
            // 無効な保護設定の場合は通常配信
            $this->deliver_file($file_path);
            return;
        }
        
        $path_settings = $protected_paths[$protected_path_id];
        
        // 認証チェック
        if (!$this->auth->is_logged_in($path_settings)) {
            // 未認証の場合はログインページへリダイレクト
            // redirect_to_loginでは$home_pathを含めるようになっているので、リダイレクト先に$home_pathを含むとだめ。
            $this->redirect_to_login($path_settings, $requested_file);
            return;
        }
        
        // 認証済みの場合はファイルを配信
        $this->deliver_file($file_path);
    }

    /**
     * ファイルパスの検証とサニタイズ
     *
     * @param string $requested_file リクエストされたファイルパス
     * @return string|false 検証済みのファイルパス、無効な場合はfalse
     */
    private function validate_file_path($requested_file) {
        // ディレクトリトラバーサル対策
        $requested_file = str_replace(['../', '..\\'], '', $requested_file);
        $requested_file = trim($requested_file, '/\\');
        
        if (empty($requested_file)) {
            return false;
        }
        
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/' . $requested_file;
        $file_path = realpath($file_path);
        
        // realpathが失敗した場合
        if ($file_path === false) {
            return false;
        }
        
        // アップロードディレクトリ内のファイルかチェック
        $upload_basedir = realpath($upload_dir['basedir']);
        if (strpos($file_path, $upload_basedir) !== 0) {
            return false;
        }
        
        // ファイルが存在するかチェック
        if (!file_exists($file_path) || !is_file($file_path)) {
            return false;
        }
        
        return $file_path;
    }

    /**
     * ファイルパスから添付ファイルIDを取得
     *
     * @param string $file_path ファイルパス
     * @return int|false 添付ファイルID、見つからない場合はfalse
     */
    private function get_attachment_id_from_path($file_path) {
        global $wpdb;
        
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);
        
        // メタデータから検索
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_wp_attached_file' 
            AND meta_value = %s 
            LIMIT 1",
            $relative_path
        ));
        
        return $attachment_id ? (int) $attachment_id : false;
    }

    /**
     * ログインページへリダイレクト
     *
     * @param array $path_settings 保護パス設定
     * @param string $requested_file リクエストされたファイル
     */
    private function redirect_to_login($path_settings, $requested_file) {
        $login_page_id = $path_settings['login_page'];
        if (empty($login_page_id) || !get_post($login_page_id)) {
            $this->send_403();
            return;
        }
        
        $login_url = get_permalink($login_page_id);
        $current_url = home_url(self::REWRITE_ENDPOINT . '/' . $requested_file);
        $login_url = add_query_arg('redirect_to', urlencode($current_url), $login_url);
        
        // ESP_Cookieを使用してリダイレクト
        $this->cookie->do_redirect($login_url, false);
    }

    /**
     * ファイルを配信
     *
     * @param string $file_path ファイルパス
     */
    private function deliver_file($file_path) {
        $mime_type = wp_check_filetype($file_path);
        $mime_type = $mime_type['type'] ?: 'application/octet-stream';
        
        // ファイルサイズ
        $file_size = filesize($file_path);
        
        // Rangeヘッダーの処理（部分的なダウンロード対応）
        $range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : '';
        
        if ($range) {
            $this->deliver_file_with_range($file_path, $file_size, $mime_type, $range);
        } else {
            // 通常の配信
            header('Content-Type: ' . $mime_type);
            header('Content-Length: ' . $file_size);
            header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
            
            // キャッシュヘッダー
            $this->set_cache_headers($mime_type);
            
            // X-Sendfileが利用可能な場合は使用
            if ($this->is_x_sendfile_available()) {
                header('X-Sendfile: ' . $file_path);
            } else {
                // PHP経由でファイルを出力
                $this->readfile_chunked($file_path);
            }
        }
        
        exit;
    }

    /**
     * Range対応のファイル配信
     *
     * @param string $file_path ファイルパス
     * @param int $file_size ファイルサイズ
     * @param string $mime_type MIMEタイプ
     * @param string $range Rangeヘッダー
     */
    private function deliver_file_with_range($file_path, $file_size, $mime_type, $range) {
        list($size_unit, $range_orig) = explode('=', $range, 2);
        
        if ($size_unit != 'bytes') {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes */$file_size");
            exit;
        }
        
        // 複数範囲は非対応
        if (strpos($range_orig, ',') !== false) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes */$file_size");
            exit;
        }
        
        // 範囲を解析
        if ($range_orig == '-') {
            $c_start = $file_size - 1;
            $c_end = $file_size - 1;
        } else {
            $range = explode('-', $range_orig);
            $c_start = $range[0];
            $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $file_size - 1;
        }
        
        $c_start = max(0, min($c_start, $file_size - 1));
        $c_end = max($c_start, min($c_end, $file_size - 1));
        $length = $c_end - $c_start + 1;
        
        header('HTTP/1.1 206 Partial Content');
        header('Content-Type: ' . $mime_type);
        header('Content-Length: ' . $length);
        header("Content-Range: bytes $c_start-$c_end/$file_size");
        header('Accept-Ranges: bytes');
        header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
        
        $this->set_cache_headers($mime_type);
        
        $fp = fopen($file_path, 'rb');
        fseek($fp, $c_start);
        
        $bytes_send = 0;
        while (!feof($fp) && (!connection_aborted()) && ($bytes_send < $length)) {
            $buffer = fread($fp, min(1024 * 16, $length - $bytes_send));
            echo $buffer;
            flush();
            $bytes_send += strlen($buffer);
        }
        
        fclose($fp);
        exit;
    }

    /**
     * ファイルをチャンク単位で読み込んで出力
     *
     * @param string $file_path ファイルパス
     * @param int $chunk_size チャンクサイズ
     * @return bool 成功時true
     */
    private function readfile_chunked($file_path, $chunk_size = 1048576) {
        $handle = fopen($file_path, 'rb');
        
        if ($handle === false) {
            return false;
        }
        
        while (!feof($handle)) {
            $buffer = fread($handle, $chunk_size);
            echo $buffer;
            flush();
            
            if (connection_aborted()) {
                break;
            }
        }
        
        fclose($handle);
        return true;
    }

    /**
     * キャッシュヘッダーを設定
     *
     * @param string $mime_type MIMEタイプ
     */
    private function set_cache_headers($mime_type) {
        // 画像やCSSなどの静的ファイルは長めのキャッシュ
        if (strpos($mime_type, 'image/') === 0 || 
            strpos($mime_type, 'text/css') === 0 ||
            strpos($mime_type, 'application/javascript') === 0) {
            header('Cache-Control: public, max-age=31536000');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
        } else {
            // その他のファイルは短めのキャッシュ
            header('Cache-Control: private, max-age=3600');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
        }
    }

    /**
     * X-Sendfileが利用可能か確認
     *
     * @return bool
     */
    private function is_x_sendfile_available() {
        // Apache mod_xsendfile
        if (function_exists('apache_get_modules') && in_array('mod_xsendfile', apache_get_modules())) {
            return true;
        }
        
        // Nginx X-Accel-Redirect（要設定確認）
        // この判定は環境依存のため、実際にはオプション設定で制御する方が良い
        
        return false;
    }

    /**
     * 404エラーを送信
     */
    private function send_404() {
        status_header(404);
        nocache_headers();
        include(get_404_template());
        exit;
    }

    /**
     * 403エラーを送信
     */
    private function send_403() {
        status_header(403);
        nocache_headers();
        wp_die(__('このコンテンツへのアクセスは禁止されています。', ESP_Config::TEXT_DOMAIN), 403);
        exit;
    }

    /**
     * アップロード時の自動保護設定
     *
     * @param int $attachment_id 添付ファイルID
     */
    public function auto_protect_on_upload($attachment_id) {
        // 現在のユーザーがアップロードしているコンテキストから保護パスを推定
        // 例：特定のページや投稿からアップロードされた場合など
        // この実装は要件に応じてカスタマイズが必要
        
        // フィルターフックで外部から制御できるようにする
        $auto_protect_path_id = apply_filters('esp_media_auto_protect_path_id', '', $attachment_id);
        
        if (!empty($auto_protect_path_id)) {
            update_post_meta($attachment_id, self::META_KEY_PROTECTED_PATH, $auto_protect_path_id);
        }
    }

    /**
     * 保護パス削除時の処理
     *
     * @param array $old_value 古い設定値
     * @param array $value 新しい設定値
     */
    public function handle_path_deletion($old_value, $value) {
        if (!isset($old_value['path']) || !isset($value['path'])) {
            return;
        }
        
        // 削除されたパスIDを特定
        $old_path_ids = array_keys($old_value['path']);
        $new_path_ids = array_keys($value['path']);
        $deleted_path_ids = array_diff($old_path_ids, $new_path_ids);
        
        if (empty($deleted_path_ids)) {
            return;
        }
        
        // 削除されたパスに関連付けられていたメディアを取得
        $orphaned_media = $this->get_media_by_path_ids($deleted_path_ids);
        
        if (empty($orphaned_media)) {
            return;
        }
        
        // 管理者に通知を送信
        $this->notify_orphaned_media($orphaned_media, $deleted_path_ids);
        
        // デフォルトでは保護を解除（フィルターで変更可能）
        $action = apply_filters('esp_orphaned_media_action', 'unprotect', $orphaned_media, $deleted_path_ids);
        
        if ($action === 'unprotect') {
            foreach ($orphaned_media as $media_id) {
                delete_post_meta($media_id, self::META_KEY_PROTECTED_PATH);
            }
        }
    }

    /**
     * 指定されたパスIDに関連付けられたメディアを取得
     *
     * @param array $path_ids パスIDの配列
     * @return array メディアIDの配列
     */
    private function get_media_by_path_ids($path_ids) {
        global $wpdb;
        
        $placeholders = array_fill(0, count($path_ids), '%s');
        $query = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = %s 
            AND meta_value IN (" . implode(',', $placeholders) . ")",
            array_merge([self::META_KEY_PROTECTED_PATH], $path_ids)
        );
        
        return $wpdb->get_col($query);
    }

    /**
     * 孤立したメディアについて管理者に通知
     *
     * @param array $media_ids メディアID
     * @param array $deleted_path_ids 削除されたパスID
     */
    private function notify_orphaned_media($media_ids, $deleted_path_ids) {
        $mail = ESP_Mail::get_instance();
        
        $message = sprintf(
            __("保護パスが削除されたため、以下のメディアファイルの保護設定が解除されました：\n\n", ESP_Config::TEXT_DOMAIN)
        );
        
        foreach ($media_ids as $media_id) {
            $title = get_the_title($media_id);
            $url = wp_get_attachment_url($media_id);
            $message .= sprintf("- %s (%s)\n", $title, $url);
        }
        
        $message .= sprintf(
            __("\n管理画面から新しい保護パスを設定することができます。\n", ESP_Config::TEXT_DOMAIN)
        );
        
        $mail->send_custom_notification(
            sprintf('[%s] 保護メディアファイルの設定解除', get_bloginfo('name')),
            $message
        );
    }

    /**
     * .htaccessファイルを更新（Apache環境用）
     *
     * @return bool 成功時true
     */
    public function update_htaccess() {
        if (!$this->is_apache()) {
            return false;
        }
        
        $upload_dir = wp_upload_dir();
        $htaccess_file = $upload_dir['basedir'] . '/.htaccess';
        
        // 既存の.htaccessを読み込み
        $current_rules = file_exists($htaccess_file) ? file_get_contents($htaccess_file) : '';
        
        // ESP用のルールを定義
        $esp_rules = $this->get_htaccess_rules();
        
        // 既存のESPルールを削除
        $pattern = '/# BEGIN ESP Media Protection.*?# END ESP Media Protection\s*/s';
        $current_rules = preg_replace($pattern, '', $current_rules);
        
        // 保護が有効な場合は新しいルールを追加
        if ($this->has_protected_media()) {
            $new_rules = $esp_rules . "\n" . $current_rules;
        } else {
            $new_rules = $current_rules;
        }
        
        // .htaccessを更新
        return file_put_contents($htaccess_file, $new_rules) !== false;
    }

    /**
     * .htaccess用のルールを生成
     *
     * @return string .htaccessルール
     */
    private function get_htaccess_rules() {
        $home_path = parse_url(home_url(), PHP_URL_PATH);
        $home_path = $home_path ? trailingslashit($home_path) : '/';
        
        $rules = "# BEGIN ESP Media Protection\n";
        $rules .= "<IfModule mod_rewrite.c>\n";
        $rules .= "RewriteEngine On\n";
        $rules .= "RewriteBase {$home_path}\n";
        
        // 保護対象の拡張子パターンを生成
        $extensions_pattern = implode('|', array_map('preg_quote', $this->protected_extensions));
        
        $rules .= "RewriteCond %{REQUEST_FILENAME} -f\n";
        $rules .= "RewriteRule ^(.+\.({$extensions_pattern}))$ {$home_path}index.php?" . self::REWRITE_ENDPOINT . "=$1 [L]\n";
        $rules .= "</IfModule>\n";
        $rules .= "# END ESP Media Protection\n";
        
        return $rules;
    }

    /**
     * Apache 互換サーバー（Apache / LiteSpeed）か確認
     *
     * @return bool
     */
    private function is_apache()
    {
        // $_SERVER['SERVER_SOFTWARE'] が未定義でもエラーにならないように
        $software = $_SERVER['SERVER_SOFTWARE'] ?? '';

        // Apache または LiteSpeed(OpenLiteSpeed を含む) なら true
        return stripos($software, 'Apache') !== false
            || stripos($software, 'LiteSpeed') !== false;
    }

    /**
     * 保護されたメディアが存在するか確認
     *
     * @return bool
     */
    private function has_protected_media() {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::META_KEY_PROTECTED_PATH
        ));
        
        return $count > 0;
    }

    /**
     * 設定保存時に呼び出される処理
     */
    public function on_settings_save() {
        // .htaccessを更新
        $this->update_htaccess();
        
        // リライトルールをフラッシュ
        flush_rewrite_rules();
    }
}