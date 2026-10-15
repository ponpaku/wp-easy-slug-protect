<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * メディアファイルの保護機能を管理するクラス（キャッシュ機能付き）
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
     * @var ESP_Media_Deriver メディア配信用クラス
     */
    private $deriver;

    /**
     * @var bool メディア保護が有効かどうか
     */
    private $enabled = true;

    /** Rewrite置換完了までDBに保存しないLiteSpeedキー（更新実行中のみ）。 */
    private $rewrite_key_candidate = null;

    /** 内部キー保存の update_option フックでRewriteを再入更新しない。 */
    private static $litespeed_key_commit_in_progress = false;

    /**
     * @var string メディア保護用メタキー
     */
    const META_KEY_PROTECTED_PATH = '_esp_media_protected_path_id';

    /**
     * @var string メディアキャッシュのトランジェントキー
     */
    const MEDIA_CACHE_KEY = 'esp_protected_media_v2';

    /**
     * @var int キャッシュの有効期間（秒）- 7日間に延長
     */
    const MEDIA_CACHE_DURATION = WEEK_IN_SECONDS;

    /**
     * @var string リライトルールのエンドポイント
     */
    const REWRITE_ENDPOINT = 'esp-media';

    /**
     * LiteSpeed用の配信キーを格納するオプションのキー名
     */
    const OPTION_LITESPEED_KEY = 'litespeed_key';

    /**
     * 保護対象のファイル拡張子
     */
    private const PROTECTED_EXTENSIONS = [
        // 画像
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
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
        $settings = ESP_Option::get_current_setting('media');
        if (is_array($settings) && isset($settings['enabled'])) {
            $this->enabled = (bool) $settings['enabled'];
        }

        $this->deriver = new ESP_Media_Deriver();
        $this->init();
    }

    /**
     * 初期化処理
     */
    public function init() {
        // キャッシュの初期チェック
        $this->check_and_generate_cache();

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

            // AJAX ハンドラーの登録
            add_action('wp_ajax_esp_clear_media_cache', [$this, 'ajax_clear_media_cache']);
            add_action('wp_ajax_esp_reset_htaccess_rules', [$this, 'ajax_reset_htaccess_rules']);

            $this->auth = new ESP_Auth();
            $this->cookie = ESP_Cookie::get_instance();
            add_action('template_redirect', [$this, 'handle_media_access'], 1);
        } else {
            // フロントエンドでのアクセス制御
            $this->auth = new ESP_Auth();
            $this->cookie = ESP_Cookie::get_instance();
            add_action('template_redirect', [$this, 'handle_media_access'], 1);
        }

        // REST API フィルタリング
        add_filter('rest_attachment_query', [$this, 'filter_rest_media_query'], 10, 2);
        add_filter('rest_prepare_attachment', [$this, 'check_rest_media_access'], 10, 3);

        // リライトルールの追加
        add_action('init', [$this, 'add_rewrite_rules']);
        
        // アップロード時の自動保護設定
        add_action('add_attachment', [$this, 'auto_protect_on_upload']);
        // 他プラグインによるupdate_post_meta()を含め、保護情報の更新時には
        // 認可キャッシュを必ず無効化する。
        // 保護メタ更新の監視フックはプラグイン起動時に一度だけ登録する。
        // 管理画面からの更新を取りこぼさず、複数インスタンスの重複登録も防止。
        
        // メディアの削除時にキャッシュを更新
        add_action('delete_attachment', [$this, 'regenerate_media_cache']);
        
        // 保護パス削除時の処理
        add_action('update_option_' . ESP_Config::OPTION_KEY, [$this, 'handle_path_deletion'], 10, 2);
    }

    /*----- キャッシュ管理 -----*/

    /**
     * キャッシュの存在確認と生成
     */
    private function check_and_generate_cache() {
        if (!$this->enabled) {
            delete_transient(self::MEDIA_CACHE_KEY);
            return;
        }

        if (ESP_Authorization_Cache::read('media', self::MEDIA_CACHE_KEY) === null) {
            $this->regenerate_media_cache(false);
        }
    }

    /**
     * 保護されたメディアのキャッシュを再生成
     */
    public function regenerate_media_cache($invalidate = true) {
        if (!$this->enabled) {
            delete_transient(self::MEDIA_CACHE_KEY);
            return;
        }

        global $wpdb;
        if ($invalidate && !ESP_Authorization_Cache::invalidate('media', self::MEDIA_CACHE_KEY)) {
            return;
        }
        $snapshot = ESP_Authorization_Cache::signature('media');
        if ($snapshot === null) {
            delete_transient(self::MEDIA_CACHE_KEY);
            return;
        }

        // 設定側に対応する保護パスがなくても、保護済み添付IDは保持する。
        // パス設定の欠損を公開扱いに変換しない。

        // パスIDごとのメディアIDをグループ化
        $media_by_path = [];
        
        $protected_media = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value as path_id 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = %s",
            self::META_KEY_PROTECTED_PATH
        ));
        
        if (!is_array($protected_media) || (isset($wpdb->last_error) && $wpdb->last_error !== '')) {
            // 古いキャッシュには追加直後の保護画像が含まれない可能性がある。
            // REST側が古い許可情報を使わないよう無効化する。
            delete_transient(self::MEDIA_CACHE_KEY);
            error_log('ESP: protected media cache query failed');
            return;
        }
        foreach ($protected_media as $media) {
            if (!isset($media_by_path[$media->path_id])) {
                $media_by_path[$media->path_id] = [];
            }
            $media_by_path[$media->path_id][] = (int) $media->post_id;
        }
        
        ESP_Authorization_Cache::publish('media', self::MEDIA_CACHE_KEY, $snapshot, $media_by_path, self::MEDIA_CACHE_DURATION);

        // デバッグ
        // error_log(print_r($media_by_path, true));
    }

    /**
     * 外部からのキャッシュ更新用
     */
    public function reset_cache() {
        $this->regenerate_media_cache();
    }

    /**
     * 現在のユーザーがアクセスできない保護されたメディアIDを取得（キャッシュ利用）
     *
     * @return array 除外すべきメディアIDの配列
     */
    private function get_protected_media_ids_for_current_user() {
        if (!$this->enabled) {
            return [];
        }

        $cached_data = ESP_Authorization_Cache::read('media', self::MEDIA_CACHE_KEY);
        if ($cached_data === null) {
            $this->regenerate_media_cache(false);
            $cached_data = ESP_Authorization_Cache::read('media', self::MEDIA_CACHE_KEY);
        }
        
        if (!is_array($cached_data)) {
            return null; // Unknown authorization data; callers must deny.
        }

        $excluded_ids = [];
        $protected_paths = ESP_Option::get_current_setting('path');
        
        foreach ($cached_data as $path_id => $media_ids) {
            if (!isset($protected_paths[$path_id]) || !$this->auth->is_logged_in($protected_paths[$path_id])) {
                $excluded_ids = array_merge($excluded_ids, $media_ids);
            }
        }
        
        return array_unique($excluded_ids);
    }

    /*----- AJAX ハンドラー -----*/

    /**
     * AJAXハンドラ: メディアキャッシュをクリア
     */
    public function ajax_clear_media_cache() {
        check_ajax_referer('esp_clear_media_cache_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('権限がありません。', ESP_Config::TEXT_DOMAIN)], 403);
        }

        delete_transient(self::MEDIA_CACHE_KEY);
        $this->regenerate_media_cache();

        wp_send_json_success(['message' => __('メディア保護キャッシュをクリアしました。', ESP_Config::TEXT_DOMAIN)]);
    }

    /**
     * AJAXハンドラ: .htaccessルールを再設定
     */
    public function ajax_reset_htaccess_rules() {
        check_ajax_referer('esp_reset_htaccess_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('権限がありません。', ESP_Config::TEXT_DOMAIN)], 403);
        }

        if (!$this->enabled && !$this->is_apache()) {
            $this->update_htaccess();
            wp_send_json_success([
                'message' => __('.htaccessのルールを再設定しました。', ESP_Config::TEXT_DOMAIN)
            ]);
        }

        if (!$this->is_apache()) {
            wp_send_json_error([
                'message' => __('この機能はApacheまたはLiteSpeed環境でのみ利用できます。', ESP_Config::TEXT_DOMAIN)
            ], 400);
        }

        $media_settings = ESP_Option::get_current_setting('media');
        $delivery_method = is_array($media_settings) && isset($media_settings['delivery_method'])
            ? $media_settings['delivery_method']
            : 'auto';
        $force_litespeed_key_regeneration = ($delivery_method === 'litespeed');

        // 実際の書き込み結果を取得（WP_Errorの可能性あり）
        $result = $this->update_htaccess($force_litespeed_key_regeneration);

        if ($result === true) {
            wp_send_json_success([
                'message' => __('.htaccessのルールを再設定しました。', ESP_Config::TEXT_DOMAIN)
            ]);
        }

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message()
            ]);
        }

        wp_send_json_error([
            'message' => __('.htaccessの再設定に失敗しました。書き込み権限を確認してください。', ESP_Config::TEXT_DOMAIN)
        ]);
    }

    /*----- REST API フィルタリング -----*/

    /**
     * REST APIのメディア一覧クエリをフィルタリング
     *
     * @param array           $args    WP_Queryの引数配列
     * @param WP_REST_Request $request リクエストオブジェクト
     * @return array 修正されたWP_Queryの引数配列
     */
    public function filter_rest_media_query($args, $request) {
        if (!$this->enabled) {
            return $args;
        }

        // REST APIリクエストであることを確認
        if (!(defined('REST_REQUEST') && REST_REQUEST)) {
            return $args;
        }
        
        // 既存WordPressのメディア管理者例外を維持する。
        // 権限ポリシーを全経路で変更する場合は#43に分離する。
        if (current_user_can('upload_files')) {
            return $args;
        }
        // 保護されたメディアIDを取得（キャッシュ利用）
        $excluded_media_ids = $this->get_protected_media_ids_for_current_user();
        if ($excluded_media_ids === null) {
            $args['post__in'] = [0]; // Empty result instead of leaking all media.
            return $args;
        }

        if (!empty($excluded_media_ids)) {
            $current_excluded = isset($args['post__not_in']) ? (array) $args['post__not_in'] : [];
            $args['post__not_in'] = array_unique(array_merge($current_excluded, $excluded_media_ids));
        }
        
        return $args;
    }

    /**
     * REST APIで単一のメディアへのアクセスをチェック
     *
     * @param WP_REST_Response $response レスポンスオブジェクト
     * @param WP_Post          $post     投稿（メディア）オブジェクト
     * @param WP_REST_Request  $request  リクエストオブジェクト
     * @return WP_REST_Response|WP_Error 変更されたレスポンスまたはエラー
     */
    public function check_rest_media_access($response, $post, $request) {
        if (!$this->enabled) {
            return $response;
        }

        // REST APIリクエストであることを確認
        if (!(defined('REST_REQUEST') && REST_REQUEST)) {
            return $response;
        }
        
        // 保存/更新/削除などの非GETリクエストは対象外
        $method = $request->get_method();
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return $response;
        }
        
        // 既にエラーの場合はそのまま返す
        if (is_wp_error($response)) {
            return $response;
        }
        if ($response instanceof WP_REST_Response && $response->is_error()) {
            return $response;
        }
        
        // 既存WordPressのメディア管理者例外を維持する。
        // 権限ポリシーを全経路で変更する場合は#43に分離する。
        if (current_user_can('upload_files')) {
            return $response;
        }
        // キャッシュから保護されたメディアIDを取得して確認
        $excluded_media_ids = $this->get_protected_media_ids_for_current_user();
        if ($excluded_media_ids === null) {
            return new WP_REST_Response(['code' => 'esp_media_state_unknown'], 503);
        }

        // WP_Queryが事前ロードしたpostmetaのキャッシュを「最新DB値」と扱わない。
        // 非管理者のメディアREST要素だけ、応答直前に直接DBから確認する。
        // これは最終チェック地点の鮮度を上げる限定策で、他リクエストとの
        // 完全な原子性（チェック後の競合までの防止）は保証しない。
        $live_path = self::read_live_protected_media_path($post->ID);
        if ($live_path === null) {
            return new WP_REST_Response(['code' => 'esp_media_state_unknown'], 503);
        }
        $live_path_id = $live_path['path_id'];
        if (!empty($live_path_id)) {
            $paths = ESP_Option::get_current_setting('path');
            if (!is_array($paths) || !isset($paths[$live_path_id]) ||
                !$this->auth->is_logged_in($paths[$live_path_id])) {
                return new WP_REST_Response(['code' => 'esp_rest_media_forbidden'], 403);
            }
        }

        if (in_array($post->ID, $excluded_media_ids)) {
            // 未認証の場合はエラーレスポンスを返す
            $error_data = [
                'code'    => 'esp_rest_media_forbidden',
                'message' => __('このメディアは保護されています。', ESP_Config::TEXT_DOMAIN),
                'data'    => ['status' => 403],
            ];
            
            return new WP_REST_Response($error_data, 403);
        }
        
        return $response;
    }

    /**
     * REST応答直前の保護メタをWPオブジェクトキャッシュ抜きで取得する。
     * DBエラーと保護なしを混同しない。通常の画像URL配信には追加SQLしない。
     *
     * @return array{path_id:string}|null null=DB状態不明
     */
    private static function read_live_protected_media_path($post_id) {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->postmeta)) {
            return null;
        }
        try {
            $path_id = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
                (int) $post_id,
                self::META_KEY_PROTECTED_PATH
            ));
            if ($wpdb->last_error !== '' ||
                ($path_id !== null && !is_scalar($path_id))) {
                return null;
            }
            return ['path_id' => $path_id === null ? '' : (string)$path_id];
        } catch (\Throwable $error) {
            return null;
        }
    }

    /*----- メディア編集画面の機能 -----*/

    /**
     * メディア編集画面に保護パス選択フィールドを追加
     *
     * @param array $form_fields 既存のフォームフィールド
     * @param WP_Post $post 添付ファイルのポストオブジェクト
     * @return array 修正されたフォームフィールド
     */
    public function add_media_protection_field($form_fields, $post) {
        if (!$this->enabled) {
            // 保護OFF中は新しい保護対象を登録できない。
            unset($form_fields['esp_protected_path']);
            return $form_fields;
        }
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
     * メディア保護設定を保存（キャッシュ更新付き）
     *
     * @param array $post 添付ファイルのデータ
     * @param array $attachment 添付ファイルの入力データ
     * @return array 修正された添付ファイルデータ
     */
    public function save_media_protection_field($post, $attachment) {
        if (isset($attachment['esp_protected_path'])) {
            if (!$this->enabled && !empty($attachment['esp_protected_path'])) {
                // 保護OFF中は保護メタの新規追加を拒否。解除は許可する。
                return $post;
            }
            if (empty($attachment['esp_protected_path'])) {
                delete_post_meta($post['ID'], self::META_KEY_PROTECTED_PATH);
            } else {
                update_post_meta($post['ID'], self::META_KEY_PROTECTED_PATH, sanitize_text_field($attachment['esp_protected_path']));
            }
            
            // キャッシュを再生成
            $this->regenerate_media_cache();
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
     * 一括操作を処理（キャッシュ更新付き）
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
            
            // キャッシュを再生成
            $this->regenerate_media_cache();
            
            $redirect_to = add_query_arg('esp_unprotected', count($post_ids), $redirect_to);
        }
        
        return $redirect_to;
    }

    /*----- メディアファイルアクセス制御 -----*/

    /**
     * リライトルールを追加
     */
    public function add_rewrite_rules() {
        // 保存済みWordPress Rewriteや.htaccessが一時的に残る場合にも
        // OFF時の公開ファイルをPHPから安全に配信できるよう変数だけ登録する。
        add_filter('query_vars', static function ($vars) {
            $vars[] = self::REWRITE_ENDPOINT;
            return $vars;
        });
        if (!$this->enabled) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $upload_path = str_replace(home_url(), '', $upload_dir['baseurl']);
        $upload_path = trim($upload_path, '/');
        
        // wp-content/uploads/以下のファイルへのアクセスをキャッチ
        // 保護された拡張子のみを対象にする
        $extensions_pattern = implode('|', array_map('preg_quote', self::PROTECTED_EXTENSIONS));
        
        add_rewrite_rule(
            '^' . $upload_path . '/(.+\.(' . $extensions_pattern . '))$',
            'index.php?' . self::REWRITE_ENDPOINT . '=$matches[1]',
            'top'
        );
        

    }

    /**
    * メディアファイルへのアクセスを処理（クリーンアップ版）
    */
    public function handle_media_access() {
        // リクエストURIから直接判定
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $is_media_request = false;
        
        // 保護拡張子のパターンマッチング
        $extensions_pattern = implode('|', array_map('preg_quote', self::PROTECTED_EXTENSIONS));
        if (preg_match('/wp-content\/uploads\/.*\.(' . $extensions_pattern . ')$/i', $request_uri)) {
            $is_media_request = true;
        }
        
        // 404状態をリセット（メディアリクエストの場合）
        if ($is_media_request && is_404()) {
            global $wp_query;
            $wp_query->is_404 = false;
            $wp_query->is_page = false;
            $wp_query->is_single = false;
            $wp_query->is_home = false;
            status_header(200);
        }
        
        $requested_file = get_query_var(self::REWRITE_ENDPOINT);
        
        // リライトルールが機能していない場合の代替処理
        if (empty($requested_file) && $is_media_request) {
            if (preg_match('/wp-content\/uploads\/(.+)$/', $request_uri, $matches)) {
                $requested_file = $matches[1];
            }
        }
        
        if (empty($requested_file)) {
            return;
        }

        // 公開クエリ変数は誰でも任意に指定できる。内部Rewriteの結果と
        // 外部の ?esp-media=... を混同して第三者のuploads拒否を迂回させない。
        // REQUEST_URIはRewrite前の元URLなので、要求されたuploadsの相対パスと
        // 配信対象の一致を必要条件とする（URL形状だけで認可したわけではない）。
        if (!self::is_allowed_media_request($requested_file) ||
            !self::is_original_upload_request($requested_file)) {
            $this->send_404();
            return;
        }

        // ファイルパスの検証とサニタイズ
        $file_path = $this->validate_file_path($requested_file);
        if (!$file_path) {
            $this->send_404();
            return;
        }

        // 管理者が明示的にOFFにしたときは保護メタの有無を問わず公開。
        // 古いRewriteが残ってPHPに転送されても、公開画像を404にしない。
        // OFFは公開の意思表示であり、メタ情報自体は削除しない。
        if (!$this->enabled) {
            $this->deliver_media($file_path);
            return;
        }

        // 管理画面またはアップロード権限者はスキップ
        if (is_admin() || current_user_can('upload_files')) {
            $this->deliver_media($file_path);
            return;
        }
        
        // ファイル拡張子の確認
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::PROTECTED_EXTENSIONS, true)) {
            $this->deliver_media($file_path);
            return;
        }
        
        // 添付IDの照会失敗と正常な未登録を区別する。未知なら配信しない。
        $attachment_id = $this->get_attachment_id_from_path($file_path);
        if ($attachment_id === null) {
            $this->send_503();
            return;
        }
        if ($attachment_id === false) {
            // 正常なDB照会で未登録と確定した場合だけ、従来の配信を維持。
            $this->deliver_media($file_path);
            return;
        }

        // 認可に必要な保護メタも、キャッシュの空値やDB失敗を公開と誤認しない。
        // 既存のREST用の直接DB照会を再利用する。
        $live_path = self::read_live_protected_media_path($attachment_id);
        if ($live_path === null) {
            $this->send_503();
            return;
        }
        $protected_path_id = $live_path['path_id'];
        if ($protected_path_id === '') {
            $this->deliver_media($file_path);
            return;
        }
        
        // 保護パス設定を取得
        $protected_paths = ESP_Option::get_current_setting('path');
        if (!isset($protected_paths[$protected_path_id])) {
            // 保護済み添付の参照先パスが失われた場合、未認証で配信しない。
            $this->send_403();
            return;
        }
        
        $path_settings = $protected_paths[$protected_path_id];
        
        // 認証チェック
        if (!$this->auth->is_logged_in($path_settings)) {
            $this->redirect_to_login($path_settings, $requested_file);
            return;
        }
        
        // 認証済みの場合はファイルを配信
        $this->deliver_media($file_path);
    }

    /**
     * メディアファイルを配信（失敗時は404）
     *
     * @param string $file_path ファイルパス
     */
    private function deliver_media($file_path) {
        if (!$this->deriver->deliver($file_path)) {
            $status_code = function_exists('http_response_code') ? http_response_code() : null;

            if (416 === $status_code) {
                exit;
            }

            $this->send_404();
        }
    }

    /**
     * 公開esp-mediaクエリ経由で配信してよいファイル名だけを受け付ける。
     * 配信許可のための入口検査であり、添付IDの帰属判定（#42）ではない。
     */
    private static function is_allowed_media_request($requested_file) {
        if (!is_string($requested_file) || $requested_file === '') {
            return false;
        }

        // validate_file_path()と同じ最大3回だけURLデコードして検査する。
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($requested_file);
            if ($decoded === $requested_file) {
                break;
            }
            $requested_file = $decoded;
        }

        // 隠しファイル・隠しディレクトリ、パスの不正区切り、制御文字を拒否。
        // .htaccess / .htpasswd / .user.ini 等はOFF時でも配信してはならない。
        if ($requested_file === '' || $requested_file[0] === '/' ||
            strpos($requested_file, '\\') !== false ||
            preg_match('/[\\x00-\\x1F\\x7F]/', $requested_file)) {
            return false;
        }
        foreach (explode('/', $requested_file) as $segment) {
            if ($segment === '' || $segment[0] === '.') {
                return false;
            }
        }

        return in_array(strtolower(pathinfo($requested_file, PATHINFO_EXTENSION)),
            self::PROTECTED_EXTENSIONS, true);
    }

    /**
     * 直接クエリはWordPressのトップURLからuploads内の任意ファイルへ到達する。
     * 本来のuploads URLに向けた要求以外は、OFF時・ON時とも配信しない。
     * サーバー側Rewriteで元URLが保持されない環境では安全側の404とする。
     */
    private static function is_original_upload_request($requested_file) {
        if (!is_string($requested_file) || $requested_file === '') {
            return false;
        }
        $uploads = wp_upload_dir(null, false);
        if (!is_array($uploads) || !empty($uploads['error']) ||
            empty($uploads['baseurl']) || !is_string($uploads['baseurl'])) {
            return false;
        }
        $root = parse_url($uploads['baseurl'], PHP_URL_PATH);
        $original = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!is_string($root) || !is_string($original)) {
            return false;
        }
        $prefix = rtrim($root, '/') . '/';
        if (strncmp($original, $prefix, strlen($prefix)) !== 0) {
            return false;
        }
        $relative = substr($original, strlen($prefix));
        // 二重・三重エンコードも同じ最大回数で比較し、リクエスト改ざんを拒否。
        for ($i = 0; $i < 3; $i++) {
            $next = rawurldecode($relative);
            if ($next === $relative) { break; }
            $relative = $next;
        }
        for ($i = 0; $i < 3; $i++) {
            $next = rawurldecode($requested_file);
            if ($next === $requested_file) { break; }
            $requested_file = $next;
        }
        return $relative === $requested_file && self::is_allowed_media_request($relative);
    }

    /**
    * ファイルパスの検証とサニタイズ
    *
    * @param string $requested_file リクエストされたファイルパス
    * @return string|false 検証済みのファイルパス、無効な場合はfalse
    */
    private function validate_file_path($requested_file) {
        // nullバイト攻撃対策
        $requested_file = str_replace("\0", '', $requested_file);
        
        // URLエンコードされた文字をデコード（複数回）
        $max_decode = 3;
        for ($i = 0; $i < $max_decode; $i++) {
            $decoded = rawurldecode($requested_file);
            if ($decoded === $requested_file) break;
            $requested_file = $decoded;
        }
        
        // ディレクトリトラバーサル対策（強化版）
        // 様々なパターンの相対パス指定を除去
        $patterns = [
            '#(\.\.+[/\\\\])#',           // ../ または ..\
            '#([/\\\\]\.\.+)#',           // /.. または \..
            '#(\.\.+)#',                  // 連続した..
            '#^[/\\\\]+#',                // 先頭のスラッシュ
            '#[/\\\\]+$#',                // 末尾のスラッシュ
            '#[/\\\\]{2,}#'               // 連続したスラッシュ
        ];
        
        foreach ($patterns as $pattern) {
            $requested_file = preg_replace($pattern, '', $requested_file);
        }
        
        // 空文字になった場合は無効
        if (empty($requested_file)) {
            return false;
        }
        
        // 安全なパス構築
        $upload_dir = wp_upload_dir();
        $base_path = realpath($upload_dir['basedir']);
        
        if ($base_path === false) {
            return false;
        }
        
        // ファイルパスを構築
        $file_path = $base_path . DIRECTORY_SEPARATOR . $requested_file;
        $file_path = realpath($file_path);
        
        // realpathが失敗した場合
        if ($file_path === false) {
            return false;
        }
        
        // 接頭辞だけの比較ではuploads-backup等の兄弟ディレクトリを通してしまう。
        // 実体パスでディレクトリ境界を確認し、リンク先の隠しファイルも拒否する。
        $base_prefix = rtrim($base_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($file_path, $base_prefix) !== 0 ||
            !self::is_allowed_media_request(substr($file_path, strlen($base_prefix)))) {
            return false;
        }

        // ファイルが存在し、通常ファイルであることを確認
        if (!file_exists($file_path) || !is_file($file_path) || is_link($file_path)) {
            return false;
        }
        
        return $file_path;
    }

    /**
     * ファイルパスから添付ファイルIDを取得
     *
     * @param string $file_path ファイルパス
     * @return int|false|null ID=登録済み、false=正常な未登録、null=DB状態不明
     */
    private function get_attachment_id_from_path($file_path) {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->postmeta)) {
            return null;
        }
        try {
            $upload_dir = wp_upload_dir();
            if (!is_array($upload_dir) || empty($upload_dir['basedir'])) {
                return null;
            }
            $relative_path = str_replace(rtrim($upload_dir['basedir'], '/') . '/', '', $file_path);

            // SQL失敗を「IDなし」と混同しない。単に見つからなかった場合のみfalse。
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = '_wp_attached_file'
                AND meta_value = %s
                LIMIT 1",
                $relative_path
            ));
            if ($wpdb->last_error !== '' ||
                ($attachment_id !== null && (!is_numeric($attachment_id) || (int) $attachment_id <= 0))) {
                return null;
            }
            if ($attachment_id !== null) {
                return (int) $attachment_id;
            }

        // AVIFサムネイルは _wp_attached_file に登録されない場合がある。
        // 親画像の候補を同じディレクトリから探し、メタデータに記録された
        // サムネイル名と一致した場合だけ保護設定を継承する。
        if (strtolower(pathinfo($relative_path, PATHINFO_EXTENSION)) !== 'avif') {
            return false;
        }

        $thumbnail_name = basename($relative_path);
        if (!preg_match('/^(.+)-[0-9]+x[0-9]+\\.avif$/i', $thumbnail_name, $matches)) {
            return false;
        }

        $directory = dirname($relative_path);
        $parent_prefix = ($directory === '.' ? '' : $directory . '/') . $matches[1];
        $parent_extensions = ['avif', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'];
        $candidates = [];
        foreach ($parent_extensions as $extension) {
            $candidates[] = $parent_prefix . '.' . $extension;
        }

        $placeholders = implode(', ', array_fill(0, count($candidates), '%s'));
        $candidate_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ({$placeholders})",
            array_merge(['_wp_attached_file'], $candidates)
        ));
        if ($wpdb->last_error !== '' || !is_array($candidate_ids)) {
            return null;
        }

        foreach ($candidate_ids as $candidate_id) {
            $metadata = wp_get_attachment_metadata((int) $candidate_id);
            if (!is_array($metadata) || !isset($metadata['file'], $metadata['sizes']) ||
                !is_array($metadata['sizes']) || dirname($metadata['file']) !== $directory) {
                continue;
            }

            foreach ($metadata['sizes'] as $size) {
                if (is_array($size) && isset($size['file']) && $size['file'] === $thumbnail_name) {
                    return (int) $candidate_id;
                }
            }
        }

            return false;
        } catch (\Throwable $error) {
            return null;
        }
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
        
        // 正しいURLパスを構築
        $upload_dir = wp_upload_dir();
        $upload_path = str_replace(home_url(), '', $upload_dir['baseurl']);
        
        // リダイレクト後の戻り先URLを正しく構築
        // esp-media/... ではなく /wp-content/uploads/... を使用
        $current_url = home_url($upload_path . '/' . $requested_file);
        
        $login_url = get_permalink($login_page_id);
        $login_url = add_query_arg('redirect_to', urlencode($current_url), $login_url);
        
        // ESP_Cookieを使用してリダイレクト
        $this->cookie->do_redirect($login_url, false);
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

    /** 認可用のDB照会失敗は本文を返さず、一時的な配信不能として扱う。 */
    private function send_503() {
        nocache_headers();
        status_header(503);
        wp_die(__('メディアの保護状態を確認できません。', ESP_Config::TEXT_DOMAIN),
            '', ['response' => 503]);
        exit;
    }

    /**
     * アップロード時の自動保護設定
     *
     * @param int $attachment_id 添付ファイルID
     */
    public function auto_protect_on_upload($attachment_id) {
        if (!$this->enabled) {
            return;
        }
        // 現在のユーザーがアップロードしているコンテキストから保護パスを推定
        // 例：特定のページや投稿からアップロードされた場合など
        // この実装は要件に応じてカスタマイズが必要
        
        // フィルターフックで外部から制御できるようにする
        $auto_protect_path_id = apply_filters('esp_media_auto_protect_path_id', '', $attachment_id);
        
        if (!empty($auto_protect_path_id)) {
            update_post_meta($attachment_id, self::META_KEY_PROTECTED_PATH, $auto_protect_path_id);
            
            // キャッシュを再生成
            $this->regenerate_media_cache();
        }
    }

    /**
     * ESP自身の保存処理を経由しないpostmeta更新でも、
     * 古いメディア認可キャッシュを無効化する。
     */
    public static function on_protection_meta_mutation($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key === self::META_KEY_PROTECTED_PATH) {
            ESP_Authorization_Cache::invalidate('media', self::MEDIA_CACHE_KEY);
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
            
            // キャッシュを再生成
            $this->regenerate_media_cache();
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
        
        if (empty($path_ids) || !is_array($path_ids)) {
            return [];
        }
        
        // パスIDをサニタイズ
        $sanitized_path_ids = array();
        foreach ($path_ids as $path_id) {
            // パスIDの形式を検証（英数字とアンダースコアのみ許可）
            if (preg_match('/^[a-zA-Z0-9_]+$/', $path_id)) {
                $sanitized_path_ids[] = $path_id;
            }
        }
        
        if (empty($sanitized_path_ids)) {
            return [];
        }
        
        // wpdbのprepareを使用して安全にクエリを構築
        $placeholders = implode(', ', array_fill(0, count($sanitized_path_ids), '%s'));
        
        $query = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = %s 
            AND meta_value IN ($placeholders)",
            array_merge(
                array(self::META_KEY_PROTECTED_PATH),
                $sanitized_path_ids
            )
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
     * ESPの設定画面を初期化できない段階の失敗も含め、
     * 管理者向けの警告を継続して表示する。
     */
    public static function render_rewrite_admin_notice() {
        $error = get_option('esp_rewrite_last_error', '');
        if (!$error || !current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-error"><p>' .
            esc_html__('Easy Slug Protect: メディア保護Rewriteの適用に失敗しました。uploads/.htaccess の状態と書込権限を確認してください。管理者が再検証するまで新規保護メディアの登録を拒否します。', ESP_Config::TEXT_DOMAIN) .
            ' (' . esc_html((string) $error) . ')</p></div>';
    }

    /**
     * バージョン番号が変わらなくても、既存環境では初回アクセス時にルールを検証する。
     * 保護画像が0件から1件になる際、設定保存の遅延に依存させない。
     */
    public static function ensure_rewrite_policy() {
        if (!preg_match('/apache|litespeed/i', $_SERVER['SERVER_SOFTWARE'] ?? '')) {
            // 明示されたNginxは外部設定の責務。未知のサーバーで保護メタが
            // 存在する／DBが不明なら、安全な配信経路を確認できないため拒否。
            // 保護OFFまたは保護対象0件なら不要なサイト全体503を避ける。
            return self::is_nginx_server() || !self::is_media_protection_enabled() ||
                self::has_any_protected_media_records() === false;
        }
        $enabled = self::is_media_protection_enabled();
        $policy = 'always-route-v2:' . ($enabled ? 'on' : 'off');
        // .htaccessの実際の内容を正とし、バージョン印は管理用の目安とする。
        // 保護無効など、実ファイルが既に正しければロック作成を試みない。
        if (self::rewrite_file_matches_policy($enabled)) {
            if (get_option('esp_rewrite_policy_version', '') !== $policy) {
                update_option('esp_rewrite_policy_version', $policy, false);
            }
            delete_option('esp_rewrite_last_error');
            return true;
        }
        // 保護ゲート中の修復で通常コンストラクタを起動しない。
        // 管理フックやDBキャッシュ構築よりRewriteだけを修復する。
        $media = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $result = $media->update_htaccess();
        if ($result !== true) {
            update_option('esp_rewrite_last_error', $result instanceof WP_Error
                ? $result->get_error_code() : 'esp_rewrite_unknown', false);
            // 実DBで保護済みメディアが0件と確定した場合に限り、
            // Rewriteの修復失敗で通常の公開ページまで503にしない。
            // 新規保護メタの追加はcan_assign_protected_mediaが別途拒否する。
            return self::has_any_protected_media_records() === false;
        }
        update_option('esp_rewrite_policy_version', $policy, false);
        delete_option('esp_rewrite_last_error');
        return get_option('esp_rewrite_policy_version', '') === $policy;
    }

    /**
     * WP-Cronによる読取専用点検。修復やロック取得は行わない。
     */
    public static function audit_rewrite_policy() {
        if (!preg_match('/apache|litespeed/i', $_SERVER['SERVER_SOFTWARE'] ?? '')) {
            // Nginxの外部ルールは別途運用確認が必要。未知のサーバーは
            // 保護対象がある状態を正常な監査結果として報告しない。
            return self::is_nginx_server() || !self::is_media_protection_enabled() ||
                self::has_any_protected_media_records() === false;
        }
        return self::rewrite_file_matches_policy(self::is_media_protection_enabled());
    }

    /**
     * キャッシュ上のバージョン印だけでなく、配置済みルールの内容を検証する。
     * 実際のWebサーバーでのRewrite動作検証に代わるものではない。
     */
    private static function rewrite_file_matches_policy($enabled, $litespeed_key_override = null) {
        $dir = wp_upload_dir(null, false);
        if (!empty($dir['error']) || empty($dir['basedir'])) {
            return false;
        }
        $file = trailingslashit($dir['basedir']) . '.htaccess';
        if (!is_file($file) || is_link($file)) {
            return !$enabled && !file_exists($file);
        }
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return false;
        }
        $start = strpos($contents, '# BEGIN ESP Media Protection');
        $begin_count = substr_count($contents, '# BEGIN ESP Media Protection');
        $end_count = substr_count($contents, '# END ESP Media Protection');
        if (!$enabled) {
            // 片側だけ残った壊れたESPブロックも「設定OFFの正常状態」ではない。
            return $begin_count === 0 && $end_count === 0;
        }
        if ($start === false || $begin_count !== 1 || $end_count !== 1) {
            return false;
        }
        $end = strpos($contents, '# END ESP Media Protection', $start);
        if ($end === false) {
            return false;
        }
        $block = substr($contents, $start, $end - $start + strlen('# END ESP Media Protection'));
        // ルール本文が合致していても先頭配置なら第三者の拒否を迂回する。
        // 既存ルール末尾に配置されていること、第三者に保護迂回の危険が
        // あるRewriteRuleが無いことを、保護成立の必要条件にする。
        $after_esp = substr($contents, $end + strlen('# END ESP Media Protection'));
        $before_esp = substr($contents, 0, $start);
        if (trim($after_esp) !== '' ||
            self::foreign_rewrite_can_bypass_protection($before_esp)) {
            return false;
        }
        // リクエストごとのルール照合では、キャッシュや各種フックを初期化しない。
        // .htaccessの内容を比較する処理だけを実行する。
        $media = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $expected = $media->get_htaccess_rules(true, true, $litespeed_key_override);
        if (is_wp_error($expected)) {
            return false;
        }
        return rtrim($block, "\r\n") === rtrim($expected, "\r\n");
    }

    /**
     * .htaccessファイルを更新（Apache環境用）
     *
     * @param bool $force_litespeed_key_regeneration LiteSpeedキーを再生成するかどうか
     * @return bool 成功時true
     */
    public function update_htaccess($force_litespeed_key_regeneration = false, $deactivating = false, $route_override = null) {
        // WordPress管理画面と異なりWP-CLIではSERVER_SOFTWAREが空になり得る。
        // ESP自身の既存ブロックの削除はWebサーバー種別に依存しない。
        // ただし、サーバー不明時の保護ONルール新規設置は推測で許可しない。
        if (!$this->is_apache() && !$deactivating && $this->enabled) {
            return new WP_Error('esp_htaccess_unsupported',
                __('ApacheまたはLiteSpeed環境でのみ利用できます。', ESP_Config::TEXT_DOMAIN));
        }

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error']) || empty($upload_dir['basedir']) || !is_dir($upload_dir['basedir'])) {
            return new WP_Error('esp_htaccess_upload_dir', __('アップロードディレクトリを確認できません。', ESP_Config::TEXT_DOMAIN));
        }

        $htaccess_file = trailingslashit($upload_dir['basedir']) . '.htaccess';
        if ($deactivating || ($route_override === null && !self::is_media_protection_enabled())) {
            // OFF→ONの事前Rewrite設置（route_override=true）は素通りさせない。
            // 既存ESPブロックなしなら変更不要。CLI/Nginxで空ファイルやロックを
            // 作らず、読み取り失敗・部分マーカーは後続のエラー経路へ渡す。
            if (!file_exists($htaccess_file) && !is_link($htaccess_file)) {
                return true;
            }
            $present = @file_get_contents($htaccess_file);
            if ($present === false) {
                return new WP_Error('esp_htaccess_read_failed',
                    __('既存の.htaccessを読み取れません。', ESP_Config::TEXT_DOMAIN));
            }
            if (!str_contains($present, '# BEGIN ESP Media Protection') &&
                !str_contains($present, '# END ESP Media Protection')) {
                return true;
            }
        }
        $lock_file = $htaccess_file . '.esp.lock';
        $lock = @fopen($lock_file, 'c');
        if ($lock === false) {
            return new WP_Error('esp_htaccess_lock_open', __('保護ルールの更新ロックを作成できません。', ESP_Config::TEXT_DOMAIN));
        }

        $temp = null;
        $backup = null;
        $keep_backup = false;
        try {
            if (!@flock($lock, LOCK_EX | LOCK_NB)) {
                return new WP_Error('esp_htaccess_locked', __('別の保護ルール更新が進行中です。', ESP_Config::TEXT_DOMAIN));
            }
            // 保護設定が有効なら、対象画像が0件から1件になる場合もRewriteを維持する。
            // DBの件数ではなく、保護の有効設定に従ってルールを決める。
            $route_media = !$deactivating &&
                ($route_override === null ? self::is_media_protection_enabled() : (bool) $route_override);
            if (is_link($htaccess_file)) {
                return new WP_Error('esp_htaccess_symlink', __('シンボリックリンクの保護ルールは自動置換できません。', ESP_Config::TEXT_DOMAIN));
            }
            $exists = file_exists($htaccess_file);
            $old = $exists ? @file_get_contents($htaccess_file) : '';
            if ($old === false) {
                return new WP_Error('esp_htaccess_read_failed', __('既存の.htaccessを読み取れません。', ESP_Config::TEXT_DOMAIN));
            }

            // マーカーの欠損や重複時は既存のESPブロックの範囲を推測しない。
            $begin_count = substr_count($old, '# BEGIN ESP Media Protection');
            $end_count = substr_count($old, '# END ESP Media Protection');
            $begin_at = strpos($old, '# BEGIN ESP Media Protection');
            $end_at = strpos($old, '# END ESP Media Protection');
            // 各1個あってもEND→BEGINの逆順は正しいブロックではない。
            if ($begin_count !== $end_count || $begin_count > 1 ||
                ($begin_count === 1 && ($begin_at === false || $end_at === false ||
                    $end_at <= $begin_at))) {
                return new WP_Error('esp_htaccess_ambiguous', __('ESP保護ルールの境界が不正なため自動更新しません。', ESP_Config::TEXT_DOMAIN));
            }
            // WordPressの明示的なプラグイン無効化では、保護メタ件数にかかわらず
            // ESPのRewriteだけを取り除く。他者のルールは従来どおり保全する。

            // マーカーを独立した行として厳密に認識し、ESP以外の全バイトを保持。
            // コメント中の同名文字列や不正な境界にマッチさせない。
            $pattern = '/^# BEGIN ESP Media Protection[ \\t]*\\r?\\n.*?^# END ESP Media Protection[ \\t]*(?:\\r?\\n|\\z)/ms';
            $replacements = 0;
            $without_esp = preg_replace($pattern, '', $old, 1, $replacements);
            if ($without_esp === null || ($begin_count === 1 && $replacements !== 1)) {
                return new WP_Error('esp_htaccess_parse_failed', __('保護ルールの境界を厳密に解析できません。', ESP_Config::TEXT_DOMAIN));
            }
            // RewriteとDBのキーを同時更新できないため、まず新しいキーを
            // メモリに保持してRewriteを設置・検証し、その後DBへ保存する。
            $this->rewrite_key_candidate = null;
            if ($route_media && $this->is_litespeed() &&
                ($force_litespeed_key_regeneration || self::get_litespeed_key_value() === '')) {
                $this->rewrite_key_candidate = $this->generate_litespeed_key();
            }
            $esp_rules = $this->get_htaccess_rules($route_media, false, $this->rewrite_key_candidate);
            if (is_wp_error($esp_rules)) {
                return $esp_rules;
            }
            // 他者の拒否をESPのPHP配信で飛ばさないため、ESPは既存ルールの後。
            // ただし他者の[L]などがESP保護を回避し得る場合は設置を拒否。
            if ($route_media && self::foreign_rewrite_can_bypass_protection($without_esp)) {
                return new WP_Error('esp_htaccess_foreign_rewrite_conflict',
                    __('他のRewriteルールがESPの保護を迂回する可能性があるため、自動設置しません。管理者がルールの優先順位を確認してください。', ESP_Config::TEXT_DOMAIN));
            }
            $separator = $route_media && $without_esp !== '' &&
                !preg_match('/(?:\\r\\n|\\n|\\r)\\z/', $without_esp) ? "\n" : '';
            $new_rules = $route_media ? $without_esp . $separator . $esp_rules : $without_esp;
            // 書込み前に、新しいESPブロックが一組だけ存在することを確認する。
            if ($route_media && (substr_count($new_rules, '# BEGIN ESP Media Protection') !== 1 ||
                substr_count($new_rules, '# END ESP Media Protection') !== 1 ||
                substr($new_rules, -strlen($esp_rules)) !== $esp_rules)) {
                return new WP_Error('esp_htaccess_invalid_result',
                    __('生成したRewrite保護ルールの構造が不正です。', ESP_Config::TEXT_DOMAIN));
            }
            if ($new_rules === $old) {
                return true;
            }

            // 実運用中の.htaccessへ直接書き込むと途中で破損するおそれがある。
            // 同じディレクトリに一時ファイルを作成・検証してから置換する。
            $temp = @tempnam($upload_dir['basedir'], '.htaccess.esp-');
            // uploadsがシンボリックリンクの場合、tempnam()は実体側のパスを返し得る。
            // 文字列のパスではなく実体ディレクトリを比較し、別ディレクトリへの
            // フォールバック（権限不足など）だけを拒否する。
            $expected_dir = @realpath($upload_dir['basedir']);
            $temp_dir = $temp ? @realpath(dirname($temp)) : false;
            if (!$temp || $expected_dir === false || $temp_dir === false ||
                $temp_dir !== $expected_dir) {
                return new WP_Error('esp_htaccess_temp_failed', __('保護ルールの一時ファイルを作成できません。', ESP_Config::TEXT_DOMAIN));
            }
            if ($exists) {
                $mode = @fileperms($htaccess_file);
                if ($mode === false) {
                    return new WP_Error('esp_htaccess_mode_read', __('元の.htaccess権限を取得できません。', ESP_Config::TEXT_DOMAIN));
                }
                $mode &= 0777;
            } else {
                $mode = 0644;
            }
            if (($mode & 0444) === 0 || !@chmod($temp, $mode) || ((@fileperms($temp) & 0777) !== $mode)) {
                return new WP_Error('esp_htaccess_mode_failed', __('一時ファイルの読取権限を設定・確認できません。', ESP_Config::TEXT_DOMAIN));
            }
            $written = @file_put_contents($temp, $new_rules);
            if ($written !== strlen($new_rules) || @file_get_contents($temp) !== $new_rules) {
                return new WP_Error('esp_htaccess_write_failed', __('保護ルールの一時ファイル書込・検証に失敗しました。', ESP_Config::TEXT_DOMAIN));
            }

            // 置換後の読戻し・保護ルール検証が失敗しても元のファイルを
            // 復元できるよう、既存の内容を同じFS上に事前退避する。
            // バックアップは0600、ファイル名もApache標準の .ht* 拒否対象に置く。
            // これだけで全サーバーのWeb配信拒否を保証するものではない。
            if ($exists) {
                $backup = @tempnam($upload_dir['basedir'], '.htaccess.esp-restore-');
                $backup_dir = $backup ? @realpath(dirname($backup)) : false;
                if (!$backup || $backup_dir !== $expected_dir ||
                    !@chmod($backup, 0600) ||
                    (@fileperms($backup) & 0777) !== 0600 ||
                    @file_put_contents($backup, $old) !== strlen($old) ||
                    @file_get_contents($backup) !== $old) {
                    return new WP_Error('esp_htaccess_backup_failed',
                        __('旧Rewrite設定の安全な退避に失敗したため、置換しませんでした。', ESP_Config::TEXT_DOMAIN));
                }
            }

            // ESPのロックを使わない外部変更との競合も検出する。
            if ($exists !== file_exists($htaccess_file) ||
                ($exists && @file_get_contents($htaccess_file) !== $old)) {
                return new WP_Error('esp_htaccess_changed', __('更新中に.htaccessが変更されたため、置換しませんでした。', ESP_Config::TEXT_DOMAIN));
            }
            if (!@rename($temp, $htaccess_file)) {
                return new WP_Error('esp_htaccess_rename_failed', __('保護ルールを安全に置換できません。', ESP_Config::TEXT_DOMAIN));
            }
            $temp = null;

            $verified = $this->verify_rewrite_installation($htaccess_file, $new_rules, $route_media);
            $key_committed = false;
            if ($verified) {
                try {
                    $key_committed = $this->rewrite_key_candidate === null ||
                        self::commit_litespeed_key($this->rewrite_key_candidate);
                } catch (\Throwable $error) {
                    // update_option()のフックが保存後に例外を投げることもある。
                    // 保存済みなら新Rewriteを維持し、未保存なら既存の復元経路へ。
                    error_log('ESP: LiteSpeed key commit raised an exception');
                    try {
                        $key_committed = $this->rewrite_key_candidate !== null &&
                            self::get_litespeed_key_value() === $this->rewrite_key_candidate;
                    } catch (\Throwable $read_error) {
                        $key_committed = false;
                    }
                }
            }
            if (!$verified || !$key_committed) {
                // 他のプロセスによる変更を旧データで上書きしない。
                if (!$exists || @file_get_contents($htaccess_file) !== $new_rules) {
                    $keep_backup = $exists;
                    return new WP_Error($verified ? 'esp_litespeed_key_write' : 'esp_htaccess_verify_failed',
                        __('RewriteまたはLiteSpeedキーの確定に失敗しました。安全に元のファイルへ復元できず、管理者による確認が必要です。', ESP_Config::TEXT_DOMAIN));
                }
                if (!@chmod($backup, $mode) || (@fileperms($backup) & 0777) !== $mode ||
                    @file_get_contents($backup) !== $old) {
                    $keep_backup = true;
                    return new WP_Error('esp_htaccess_restore_prepare_failed',
                        __('旧Rewrite設定を復元する準備に失敗しました。退避ファイルを保持します。', ESP_Config::TEXT_DOMAIN));
                }
                if (@file_get_contents($htaccess_file) !== $new_rules || !@rename($backup, $htaccess_file)) {
                    // 復元を断念して退避ファイルを残すときは権限を狭め直す。
                    @chmod($backup, 0600);
                    $keep_backup = true;
                    return new WP_Error('esp_htaccess_restore_failed',
                        __('旧Rewrite設定を復元できませんでした。退避ファイルを保持します。', ESP_Config::TEXT_DOMAIN));
                }
                $backup = null;
                if (@file_get_contents($htaccess_file) !== $old) {
                    return new WP_Error('esp_htaccess_restore_verify_failed',
                        __('旧Rewrite設定を復元しましたが照合に失敗しました。', ESP_Config::TEXT_DOMAIN));
                }
                return new WP_Error($verified ? 'esp_litespeed_key_write' : 'esp_htaccess_rolled_back',
                    $verified
                        ? __('LiteSpeedキーの保存に失敗したため、以前のRewrite設定に復元しました。', ESP_Config::TEXT_DOMAIN)
                        : __('Rewrite検証に失敗したため、以前の設定に復元しました。', ESP_Config::TEXT_DOMAIN));
            }
            return true;
        } finally {
            $this->rewrite_key_candidate = null;
            if ($temp && is_file($temp)) {
                @unlink($temp);
            }
            if ($backup && !$keep_backup && is_file($backup)) {
                @unlink($backup);
            }
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * RewriteがESPより先に評価される際に、保護対象を迂回させる可能性のある
     * 第三者Rewriteを「安全」と推測しない。明示的な[F]/[G]拒否だけ受理。
     * 条件式の成立をPHPで完全解析するのは不可能なので、曖昧な場合は拒否。
     */
    private static function foreign_rewrite_can_bypass_protection($foreign_rules) {
        foreach (preg_split('/\\r\\n|\\n|\\r/', $foreign_rules) as $line) {
            $line = trim($line);
            if (preg_match('/^RewriteRule\\s/i', $line) !== 1) {
                continue;
            }
            if (!preg_match('/^RewriteRule\\s+\\S+\\s+\\S+(?:\\s+\\[([^\\]]*)\\])?(?:\\s*(?:#.*)?)?$/i',
                $line, $matches)) {
                return true;
            }
            $flags = isset($matches[1]) ? explode(',', $matches[1]) : [];
            $denies = false;
            foreach ($flags as $flag) {
                if (in_array(strtoupper(trim($flag)), ['F', 'FORBIDDEN', 'G', 'GONE'], true)) {
                    $denies = true;
                }
            }
            if (!$denies) {
                return true;
            }
        }
        return false;
    }

    /**
     * 配置後の確認。副作用のない検査として切り出し、
     * テスト時は置換直後の故障を再現できるようにする。
     */
    protected function verify_rewrite_installation($file, $expected, $enabled) {
        return @file_get_contents($file) === $expected &&
            self::rewrite_file_matches_policy($enabled, $this->rewrite_key_candidate);
    }

    /**
     * .htaccess用のルールを生成
     *
     * @param bool|null $has_protected_media ルールを適用するか
     * @return string|WP_Error .htaccessルール
     */
    private function get_htaccess_rules($has_protected_media = null, $read_only = false, $litespeed_key_override = null) {
        if ($has_protected_media === null) {
            $has_protected_media = self::is_media_protection_enabled();
        }
        $home_path = parse_url(home_url(), PHP_URL_PATH);
        $home_path = $home_path ? trailingslashit($home_path) : '/';
        
        // wp-content/uploads/のパスを取得
        $upload_dir = wp_upload_dir(null, !$read_only);
        $upload_path = str_replace(ABSPATH, '', $upload_dir['basedir']);
        $upload_path = str_replace('\\', '/', $upload_path); // Windows対応
        
        $rules = "# BEGIN ESP Media Protection\n";
        $rules .= "# 保護されたメディアファイルへの直接アクセスをWordPress経由にリダイレクト\n";
        $indent = '    ';
        $rules .= "<IfModule mod_rewrite.c>\n";
        $rules .= "{$indent}RewriteEngine On\n";
        $rules .= "{$indent}RewriteBase {$home_path}\n";
        
        // 保護対象の拡張子パターンを生成
        $extensions_pattern = implode('|', array_map('preg_quote', self::PROTECTED_EXTENSIONS));
        
        // 有効なら保護メディアが0件でもルールを適用（初回追加の直配信漏洩防止）
        if ($has_protected_media) {
            if ($this->is_litespeed()) {
                // 監査・既存ルール比較はキーを生成しない。
                // キーの作成・更新は明示的なRewrite更新処理だけで行う。
                $litespeed_key = $litespeed_key_override !== null
                    ? $litespeed_key_override : self::get_litespeed_key_value();
                if ($litespeed_key instanceof WP_Error) {
                    return $litespeed_key;
                }
                if ($litespeed_key === '') {
                    return new WP_Error('esp_litespeed_key_missing',
                        __('LiteSpeedの保護キーがありません。管理者によるRewrite更新が必要です。', ESP_Config::TEXT_DOMAIN));
                }
                $escaped_key = preg_quote($litespeed_key, '/');
                $query_key = ESP_Config::LITESPEED_QUERY_KEY;

                $rules .= "{$indent}# LiteSpeed: 認証済みキーがある場合のみ直接配信を許可\n";
                $rules .= "{$indent}RewriteCond %{REQUEST_FILENAME} -f\n";
                $rules .= "{$indent}RewriteCond %{REQUEST_URI} ^.*\\.({$extensions_pattern})$ [NC]\n";
                $rules .= "{$indent}RewriteCond %{QUERY_STRING} (^|&)" . $query_key . "=" . $escaped_key . "(&|$)\n";
                $rules .= "{$indent}RewriteRule ^ - [L]\n";
            }

            $rules .= "{$indent}# 保護されたファイル拡張子へのアクセスをWordPress経由に\n";
            $rules .= "{$indent}RewriteCond %{REQUEST_FILENAME} -f\n";
            $rules .= "{$indent}RewriteCond %{REQUEST_URI} ^.*\\.({$extensions_pattern})$ [NC]\n";

            $rewrite_flags = $this->is_litespeed() ? '[L,QSA]' : '[L]';
            $rules .= "{$indent}RewriteRule ^(.+)$ {$home_path}index.php?" . self::REWRITE_ENDPOINT . "=$1 {$rewrite_flags}\n";
        }
        
        $rules .= "</IfModule>\n";
        $rules .= "# END ESP Media Protection\n";
        
        return $rules;
    }
    /**
     * Apache 互換サーバー（Apache / LiteSpeed）か確認
     *
     * @return bool
     */
    private function is_apache() {
        $software = self::get_server_software();

        return stripos($software, 'Apache') !== false
            || stripos($software, 'LiteSpeed') !== false;
    }

    /**
     * LiteSpeed環境か確認
     */
    private function is_litespeed() {
        $software = self::get_server_software();
        return stripos($software, 'LiteSpeed') !== false;
    }

    /**
     * Nginx環境か確認
     */
    public static function is_nginx_server() {
        $software = self::get_server_software();

        return stripos($software, 'nginx') !== false
            || stripos($software, 'openresty') !== false
            || stripos($software, 'tengine') !== false;
    }

    /**
     * サーバーソフトウェア文字列を取得
     */
    private static function get_server_software() {
        return $_SERVER['SERVER_SOFTWARE'] ?? '';
    }

    /**
     * 管理画面で表示するNginx用ルールを生成
     */
    public static function generate_nginx_rules_for_admin() {
        if (!is_admin()) {
            return new WP_Error(
                'esp_nginx_rules_context',
                __('Nginx設定の生成は管理画面からのみ実行できます。', ESP_Config::TEXT_DOMAIN)
            );
        }

        if (!self::is_nginx_server()) {
            return new WP_Error(
                'esp_nginx_rules_not_nginx',
                __('現在のサーバーソフトウェアではNginx用ルールは必要ありません。', ESP_Config::TEXT_DOMAIN)
            );
        }

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return new WP_Error(
                'esp_nginx_rules_upload_dir',
                sprintf(__('アップロードディレクトリを取得できません: %s', ESP_Config::TEXT_DOMAIN), $upload_dir['error'])
            );
        }

        $upload_path = parse_url($upload_dir['baseurl'], PHP_URL_PATH);
        if (!$upload_path) {
            $upload_path = '/wp-content/uploads';
        }
        $upload_path = rtrim($upload_path, '/');

        $home_path = parse_url(home_url(), PHP_URL_PATH);
        $home_path = $home_path ? trailingslashit($home_path) : '/';

        $extensions_pattern = implode('|', array_map('preg_quote', self::PROTECTED_EXTENSIONS));

        $rules  = "# BEGIN ESP Media Protection (nginx)\n";
        $rules .= "# このブロックをserverディレクティブ内に追加してください。\n";
        $rules .= "location ~* ^{$upload_path}/(.+\\.({$extensions_pattern}))$ {\n";
        $rules .= "    rewrite ^{$upload_path}/(.+)$ {$home_path}index.php?" . self::REWRITE_ENDPOINT . "=$1 last;\n";
        $rules .= "}\n";
        $rules .= "# END ESP Media Protection (nginx)\n";

        return array(
            'rules' => $rules,
            'media_enabled' => self::is_media_protection_enabled(),
            'has_protected_media' => self::has_any_protected_media_records(),
        );
    }

    /**
     * 配置済みRewriteを照合した後に限ってキーを保存する。
     * 保存できなければ呼出元でRewriteを元の内容へ復元する。
     */
    private static function commit_litespeed_key($key) {
        $settings = ESP_Option::get_all_settings();
        if (!is_array($settings)) {
            return false;
        }
        if (!isset($settings['media']) || !is_array($settings['media'])) {
            $settings['media'] = [];
        }
        $settings['media'][self::OPTION_LITESPEED_KEY] = $key;
        self::$litespeed_key_commit_in_progress = true;
        try {
            ESP_Option::update_settings($settings);
            return self::get_litespeed_key_value() === $key;
        } finally {
            self::$litespeed_key_commit_in_progress = false;
        }
    }

    /**
     * LiteSpeed用のキーを取得（存在しない場合は空文字）
     */
    public static function get_litespeed_key_value() {
        $settings = ESP_Option::get_current_setting('media');
        if (!is_array($settings) || !isset($settings[self::OPTION_LITESPEED_KEY])) {
            return '';
        }

        // 保存済みのキーをサニタイズして返却
        $key = sanitize_text_field($settings[self::OPTION_LITESPEED_KEY]);
        $key = preg_replace('/[^a-zA-Z0-9]/', '', $key);

        return is_string($key) ? $key : '';
    }

    /**
     * LiteSpeed用のキーを生成
     */
    private function generate_litespeed_key() {
        try {
            $bytes = random_bytes(16);
            return bin2hex($bytes);
        } catch (Exception $e) {
            if (function_exists('wp_generate_password')) {
                $password = wp_generate_password(32, false, false);
                return preg_replace('/[^a-zA-Z0-9]/', '', $password);
            }

            return substr(md5(uniqid((string) mt_rand(), true)), 0, 32);
        }
    }

    /**
     * 保護されたメディアが存在するか確認
     *
     * @return bool
     */
    private function has_protected_media() {
        if (!$this->enabled) {
            return false;
        }

        return self::has_any_protected_media_records();
    }

    /**
     * メディア保護が有効かどうか
     */
    private static function is_media_protection_enabled() {
        $settings = ESP_Option::get_current_setting('media');

        if (!is_array($settings) || !array_key_exists('enabled', $settings)) {
            return true;
        }

        return (bool) $settings['enabled'];
    }

    /**
     * 管理者がOFFにする際の警告判定。true:保護メタあり、
     * false:0件、null:DB状態不明（確認を要するが明示的OFFは妨げない）。
     */
    public static function protected_media_records_exist() {
        return self::has_any_protected_media_records();
    }

    /** 管理者の明示的なOFFはDB照会状態によって拒否しない。 */
    public static function can_disable_media_protection() {
        return true;
    }

    /**
     * Rewriteが実際に配置済みのときだけ新しい保護メタを受け入れる。
     * 失敗時は保護メタの書込みより先に中止する。
     */
    public static function can_assign_protected_media() {
        if (!self::is_media_protection_enabled()) {
            return false;
        }
        $software = self::get_server_software();
        if (self::is_nginx_server()) {
            return true; // Nginxの保護ルールは外部設定の責務。
        }
        if (!preg_match('/apache|litespeed/i', $software)) {
            // WP-CLI等でSERVER_SOFTWAREが未設定なら配信方式は特定できない。
            // Nginxと推測して保護メタの先行登録を許可しない。
            return false;
        }
        // ensureは0件時に一般公開ページの503を回避し得るため、
        // 保護メタを書き込む際は必ず実ファイルの捕捉ルールを検証する。
        return self::rewrite_file_matches_policy(true) ||
            (self::ensure_rewrite_policy() && self::rewrite_file_matches_policy(true));
    }

    /**
     * OFF→ONで既に保護メタがある場合は、設定をONに保存する前に
     * サーバー側のRewriteを確実に設置・確認する。0件なら副作用は不要。
     * 保存失敗で設定がOFFのまま残っても、PHP側はOFFとして公開配信可能。
     */
    public static function can_enable_media_protection() {
        $records = self::has_any_protected_media_records();
        if ($records === null) {
            return false;
        }
        if ($records === false) {
            return true;
        }
        if (!preg_match('/apache|litespeed/i', $_SERVER['SERVER_SOFTWARE'] ?? '')) {
            // 保護メタありのOFF→ONは、明示されたNginxだけ外部設定に委ねる。
            // WP-CLI等の未知の実行環境で安全なRewriteがあると推測しない。
            return self::is_nginx_server();
        }
        if (self::rewrite_file_matches_policy(true)) {
            return true;
        }
        $media = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        return $media->update_htaccess(false, false, true) === true &&
            self::rewrite_file_matches_policy(true);
    }

    /**
     * 保護されたメディアが存在するかどうか
     */
    private static function has_any_protected_media_records() {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::META_KEY_PROTECTED_PATH
        ));

        if ((!is_int($count) && (!is_string($count) || !ctype_digit($count))) ||
            (isset($wpdb->last_error) && $wpdb->last_error !== '')) {
            return null; // DB failure, never interpreted as an empty collection.
        }
        return (int) $count > 0;
    }

    /**
     * 設定保存時に呼び出される処理
     */
    public function on_settings_save() {
        // Rewrite更新の一部でLiteSpeedキーだけを保存した場合、
        // 同じフックから再びRewriteを書こうとすると自分自身のflockと競合する。
        if (self::$litespeed_key_commit_in_progress) {
            return true;
        }
        // キャッシュを再生成
        $this->regenerate_media_cache();
        
        // .htaccessを更新
        $result = $this->update_htaccess();
        if ($result !== true) {
            update_option('esp_rewrite_last_error', $result instanceof WP_Error
                ? $result->get_error_code() : 'esp_rewrite_unknown', false);
            if (function_exists('add_settings_error')) {
                add_settings_error(ESP_Config::OPTION_KEY, 'esp_rewrite_failed',
                    __('設定は保存されましたが、メディア保護ルールの適用に失敗しました。サーバー設定を確認して再適用してください。', ESP_Config::TEXT_DOMAIN),
                    'error');
            }
            return $result;
        }
        delete_option('esp_rewrite_last_error');
        update_option('esp_rewrite_policy_version', 'always-route-v2:' . (self::is_media_protection_enabled() ? 'on' : 'off'), false);

        // リライトルールをフラッシュ
        flush_rewrite_rules();
        return true;
    }

    /**
     * Cronタスクからの定期的なキャッシュ更新
     * ESP_Setupから呼び出される静的メソッド
     */
    public static function cron_regenerate_media_cache() {
        $instance = new self();
        $instance->regenerate_media_cache();
    }
}