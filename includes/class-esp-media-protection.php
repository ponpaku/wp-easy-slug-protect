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

    /**
     * @var bool 高速ゲートが有効かどうか
     */
    private $fast_gate_enabled = false;

    /**
     * @var string|null 使用するゲートスクリプト
     */
    private $fast_gate_deriver = null;

    /**
     * @var string メディア保護用メタキー
     */
    const META_KEY_PROTECTED_PATH = '_esp_media_protected_path_id';

    /**
     * @var string メディアキャッシュのトランジェントキー
     */
    const MEDIA_CACHE_KEY = 'esp_protected_media';

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
     * 高速ゲートの有効フラグ
     */
    const OPTION_FAST_GATE_ENABLED = 'fast_gate_enabled';

    /**
     * メディアゲートで利用するキー
     */
    const OPTION_MEDIA_GATE_KEY = 'media_gate_key';

    /**
     * ゲート用のシークレットフォルダ名
     */
    private const SECRET_DIR_NAME = 'secret';

    /**
     * ゲートの設定ファイル名
     */
    private const SECRET_CONFIG_FILE = 'config.php';

    /**
     * 保護ファイルリストのファイル名
     */
    private const PROTECTED_LIST_FILENAME = 'protected-files.json';

    /**
     * 環境変数に利用するキー名
     */
    private const GATE_ENV_KEY = 'ESP_MEDIA_GATE_KEY';

    /**
     * サイト識別子を渡す環境変数
     */
    private const GATE_SITE_ENV_KEY = 'ESP_MEDIA_SITE_ID';

    /**
     * Nginxで利用する内部パスのプレフィックス
     */
    private const NGINX_INTERNAL_PREFIX = '/protected-uploads';

    /**
     * 保護対象のファイル拡張子
     */
    private const PROTECTED_EXTENSIONS = [
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
        $settings = ESP_Option::get_current_setting('media');
        if (is_array($settings) && isset($settings['enabled'])) {
            $this->enabled = (bool) $settings['enabled'];
        }

        if ($this->enabled) {
            $this->fast_gate_deriver = self::get_active_gate_deriver_slug($settings);
            $this->fast_gate_enabled = $this->fast_gate_deriver !== null;
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

        if ($this->fast_gate_enabled) {
            // ゲート環境を初期化
            $this->maybe_initialize_gate_environment();
        }

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
        
        // メディアの削除時にキャッシュを更新
        add_action('delete_attachment', [$this, 'regenerate_media_cache']);
        
        // 保護パス削除時の処理
        add_action('update_option_' . ESP_Config::OPTION_KEY, [$this, 'handle_path_deletion'], 10, 2);
    }

    /**
     * ゲート用の環境を準備
     */
    private function maybe_initialize_gate_environment($force_gate_key_regeneration = false) {
        if (!$this->fast_gate_enabled) {
            return;
        }

        $this->ensure_secret_directory_exists();
        $this->ensure_protected_list_exists();
        $this->ensure_media_gate_key($force_gate_key_regeneration);
        $this->write_gate_config();
    }

    /**
     * シークレットディレクトリを取得
     */
    private function get_secret_directory_path() {
        return self::get_secret_directory_path_static();
    }

    /**
     * シークレットディレクトリの相対パス
     */
    private function get_secret_directory_relative_path() {
        return self::get_secret_directory_relative_path_static();
    }

    /**
     * シークレットディレクトリの相対パス（静的）
     */
    private static function get_secret_directory_relative_path_static() {
        $secret_url = trailingslashit(ESP_URL) . self::SECRET_DIR_NAME . '/';
        $path = wp_parse_url($secret_url, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return '';
        }

        $relative = trim($path, '/');

        return $relative;
    }

    /**
     * シークレットディレクトリのフルパス（静的）
     */
    private static function get_secret_directory_path_static() {
        $plugin_root = dirname(__DIR__);
        return trailingslashit($plugin_root) . self::SECRET_DIR_NAME;
    }

    /**
     * サイト固有のファイル名を生成
     */
    private static function build_site_specific_filename($base_filename) {
        $token = self::get_current_site_storage_token();
        $dot_position = strrpos($base_filename, '.');

        if ($dot_position === false) {
            return $base_filename . '-' . $token;
        }

        $name = substr($base_filename, 0, $dot_position);
        $extension = substr($base_filename, $dot_position);

        return $name . '-' . $token . $extension;
    }

    /**
     * 設定ファイルのフルパス（静的）
     */
    private static function get_gate_config_path_static() {
        $filename = self::build_site_specific_filename(self::SECRET_CONFIG_FILE);

        return trailingslashit(self::get_secret_directory_path_static()) . $filename;
    }

    /**
     * 保護リストファイルのフルパス（静的）
     */
    private static function get_protected_list_path_static() {
        $filename = self::build_site_specific_filename(self::PROTECTED_LIST_FILENAME);

        return trailingslashit(self::get_secret_directory_path_static()) . $filename;
    }

    /**
     * 旧形式の設定ファイルパス
     */
    private static function get_legacy_gate_config_path_static() {
        return trailingslashit(self::get_secret_directory_path_static()) . self::SECRET_CONFIG_FILE;
    }

    /**
     * 旧形式の保護リストファイルパス
     */
    private static function get_legacy_protected_list_path_static() {
        return trailingslashit(self::get_secret_directory_path_static()) . self::PROTECTED_LIST_FILENAME;
    }

    /**
     * 旧ファイルの書き込みを避けるべきかどうか
     */
    private static function should_skip_legacy_secret_files() {
        return function_exists('is_multisite') && is_multisite();
    }

    /**
     * 現在のサイトを識別するID
     */
    private static function get_current_site_identifier_value() {
        if (function_exists('get_current_blog_id')) {
            $blog_id = get_current_blog_id();
            if (is_numeric($blog_id)) {
                $blog_id = (int) $blog_id;
                if ($blog_id > 0) {
                    return (string) $blog_id;
                }
            }
        }

        return 'single';
    }

    /**
     * 現在のサイトURL
     */
    private static function get_current_site_url_value() {
        $url = home_url('/');
        if (!is_string($url)) {
            return '';
        }

        return untrailingslashit($url);
    }

    /**
     * サイト識別子をファイル名向けのトークンに変換
     */
    private static function get_current_site_storage_token() {
        $identifier = strtolower((string) self::get_current_site_identifier_value());
        $identifier = preg_replace('/[^a-z0-9]+/', '-', $identifier);
        $identifier = trim((string) $identifier, '-');

        if ($identifier === '') {
            $identifier = 'single';
        }

        return $identifier;
    }

    /**
     * 保護リストを書き出すためのJSON文字列
     */
    private static function build_protected_list_payload(array $map) {
        $payload = array(
            'site_id' => self::get_current_site_identifier_value(),
            'site_url' => self::get_current_site_url_value(),
            'site_slug' => self::get_current_site_storage_token(),
            'items' => $map,
        );

        $json = wp_json_encode($payload);
        if ($json === false) {
            $json = json_encode($payload);
        }
        if ($json === false) {
            $json = '{"site_id":"","site_url":"","items":[]}';
        }

        return $json;
    }

    /**
     * シークレットディレクトリを作成
     */
    private function ensure_secret_directory_exists() {
        self::ensure_secret_directory_exists_static();
    }

    /**
     * 設定ファイルのフルパスを取得
     */
    private function get_gate_config_path() {
        return self::get_gate_config_path_static();
    }

    /**
     * 保護ファイルリストのフルパスを取得
     */
    private function get_protected_list_path() {
        return self::get_protected_list_path_static();
    }

    /**
     * 保護ファイルリストを空で初期化
     */
    private function ensure_protected_list_exists() {
        $path = $this->get_protected_list_path();
        if (!file_exists($path)) {
            $this->write_protected_file_list([]);
        }
    }

    /**
     * シークレットディレクトリを静的に作成
     */
    private static function ensure_secret_directory_exists_static() {
        $secret_dir = self::get_secret_directory_path_static();
        if (!is_dir($secret_dir)) {
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($secret_dir);
            } else {
                @mkdir($secret_dir, 0755, true);
            }
        }
    }

    /**
     * ゲート設定を書き出し（静的）
     */
    private static function write_gate_config_static() {
        if (self::get_active_gate_deriver_slug() === null) {
            return;
        }

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            // アップロードディレクトリが取得できない場合は終了
            return;
        }

        // フォルダとキーの下準備
        self::ensure_secret_directory_exists_static();
        $config_path = self::get_gate_config_path_static();
        $media_gate_key = self::ensure_media_gate_key_exists();
        $cookie_prefixes = ESP_Config::get_cookie_prefixes();
        $litespeed_key = self::get_litespeed_key_value();

        $protected_list_path = self::get_protected_list_path_static();
        if (!file_exists($protected_list_path)) {
            // 空の保護リストを強制生成
            @file_put_contents($protected_list_path, self::build_protected_list_payload(array()));
        }

        $home_path = parse_url(home_url('/'), PHP_URL_PATH);
        if (!is_string($home_path) || $home_path === '') {
            $home_path = '/';
        }

        // gate.phpで利用する値をまとめて書き出す
        $config = array(
            'media_gate_key' => $media_gate_key,
            'upload_base' => $upload_dir['basedir'],
            'protected_list_file' => $protected_list_path,
            'site_id' => self::get_current_site_identifier_value(),
            'site_url' => self::get_current_site_url_value(),
            'site_slug' => self::get_current_site_storage_token(),
            'session_cookie_prefix' => $cookie_prefixes['session'],
            'remember_id_cookie_prefix' => $cookie_prefixes['remember_id'],
            'remember_token_cookie_prefix' => $cookie_prefixes['remember_token'],
            'gate_cookie_prefix' => $cookie_prefixes['gate'],
            'document_root' => isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '',
            'abs_path' => ABSPATH,
            'home_path' => $home_path,
            'litespeed_query_key' => ESP_Config::LITESPEED_QUERY_KEY,
            'litespeed_access_key' => $litespeed_key,
            'nginx_internal_prefix' => self::get_nginx_internal_prefix_static(),
        );

        $export = var_export($config, true);
        $php = "<?php\n";
        $php .= "if (!defined('ESP_GATE_CONFIG_ALLOWED')) {\n    return array();\n}\n";
        $php .= 'return ' . $export . ';' . "\n";

        @file_put_contents($config_path, $php);

        if (!self::should_skip_legacy_secret_files()) {
            $legacy_path = self::get_legacy_gate_config_path_static();
            if ($legacy_path !== $config_path) {
                @file_put_contents($legacy_path, $php);
            }
        }
    }

    /**
     * ゲート設定を書き出し
     */
    private function write_gate_config() {
        self::write_gate_config_static();
    }

    /**
     * 保護ファイルリストを書き出し
     */
    private function write_protected_file_list(array $map) {
        $this->ensure_secret_directory_exists();
        $path = $this->get_protected_list_path();
        $payload = self::build_protected_list_payload($map);
        @file_put_contents($path, $payload);

        if (!self::should_skip_legacy_secret_files()) {
            $legacy_path = self::get_legacy_protected_list_path_static();
            if ($legacy_path !== $path) {
                @file_put_contents($legacy_path, $payload);
            }
        }
    }

    /**
     * ファイルパスをアップロードディレクトリからの相対パスに変換
     */
    private function convert_to_relative_upload_path($file_path, $upload_base) {
        if (!is_string($file_path) || $file_path === '' || !is_string($upload_base) || $upload_base === '') {
            // 必須情報が欠けている場合は空文字
            return '';
        }

        $normalized_file = wp_normalize_path($file_path);
        $normalized_base = rtrim(wp_normalize_path($upload_base), '/');

        if (strpos($normalized_file, $normalized_base . '/') !== 0 && $normalized_file !== $normalized_base) {
            // アップロード配下に無い場合は対象外
            return '';
        }

        $relative = substr($normalized_file, strlen($normalized_base));
        $relative = ltrim($relative, '/');

        return trim($relative);
    }

    /**
     * Nginx内部パスのプレフィックス
     */
    private function get_nginx_internal_prefix() {
        return self::NGINX_INTERNAL_PREFIX;
    }

    /**
     * Nginx内部パスのプレフィックス（静的）
     */
    private static function get_nginx_internal_prefix_static() {
        return self::NGINX_INTERNAL_PREFIX;
    }

    /**
     * 設定から配信方法を取得
     */
    private static function get_delivery_method_from_settings($settings = null) {
        if ($settings === null) {
            $settings = ESP_Option::get_current_setting('media');
        }

        $method = 'auto';
        $allowed = array('auto', 'x_sendfile', 'litespeed', 'x_accel_redirect', 'php');

        if (is_array($settings) && isset($settings['delivery_method'])) {
            $candidate = $settings['delivery_method'];
            if (in_array($candidate, $allowed, true)) {
                $method = $candidate;
            }
        }

        return $method;
    }

    /**
     * 高速ゲート設定が有効か確認
     */
    private static function is_fast_gate_option_enabled($settings = null) {
        if ($settings === null) {
            $settings = ESP_Option::get_current_setting('media');
        }

        if (!is_array($settings)) {
            return false;
        }

        if (array_key_exists('enabled', $settings) && !$settings['enabled']) {
            return false;
        }

        return !empty($settings[self::OPTION_FAST_GATE_ENABLED]);
    }

    /**
     * 設定に基づき利用するゲートスクリプトを決定
     */
    private static function determine_gate_deriver_slug($settings = null) {
        $method = self::get_delivery_method_from_settings($settings);

        switch ($method) {
            case 'php':
                return null;
            case 'x_sendfile':
                return 'deriver-apache.php';
            case 'litespeed':
                return 'deriver-litespeed.php';
            case 'x_accel_redirect':
                return 'deriver-nginx.php';
        }

        if (self::is_litespeed_server()) {
            return 'deriver-litespeed.php';
        }

        if (self::is_apache_server() && self::is_x_sendfile_module_enabled_static()) {
            return 'deriver-apache.php';
        }

        if (self::is_nginx_server()) {
            return 'deriver-nginx.php';
        }

        return null;
    }

    /**
     * 現在の設定で有効なゲートスクリプトを取得
     */
    private static function get_active_gate_deriver_slug($settings = null) {
        if (!self::is_fast_gate_option_enabled($settings)) {
            return null;
        }

        return self::determine_gate_deriver_slug($settings);
    }

    /**
     * 設定された配信方法
     */
    private function get_selected_delivery_method() {
        return self::get_delivery_method_from_settings();
    }

    /**
     * Rewriteで利用するderiverファイル
     */
    private function resolve_gate_deriver_slug() {
        if (!$this->fast_gate_enabled) {
            return null;
        }

        if ($this->fast_gate_deriver === null) {
            $this->fast_gate_deriver = self::get_active_gate_deriver_slug();
            if ($this->fast_gate_deriver === null) {
                $this->fast_gate_enabled = false;
                return null;
            }
        }

        return $this->fast_gate_deriver;
    }

    /**
     * X-Sendfileモジュールの有無
     */
    private function is_x_sendfile_module_enabled() {
        return self::is_x_sendfile_module_enabled_static();
    }

    /**
     * X-Sendfileモジュールの有無（静的）
     */
    private static function is_x_sendfile_module_enabled_static() {
        return function_exists('apache_get_modules') && in_array('mod_xsendfile', apache_get_modules());
    }

    /**
     * .htaccess向けのリライトルート
     */
    private function build_gate_rewrite_target($home_path) {
        $deriver = $this->resolve_gate_deriver_slug();
        if ($deriver === null || $deriver === 'deriver-nginx.php') {
            // NginxはRewriteを利用しない
            return null;
        }

        $relative_secret = $this->get_secret_directory_relative_path();
        if ($relative_secret === '') {
            // 相対パスが計算できない場合は中止
            return null;
        }

        $target_path = $relative_secret . '/' . $deriver;
        $target_path = trim($target_path, '/');

        return $home_path . $target_path . '?file=$1';
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

        $cached_data = get_transient(self::MEDIA_CACHE_KEY);
        if ($cached_data === false) {
            $this->regenerate_media_cache();
        }
    }

    /**
     * 保護されたメディアのキャッシュを再生成
     */
    public function regenerate_media_cache() {
        if (!$this->enabled) {
            delete_transient(self::MEDIA_CACHE_KEY);
            if ($this->fast_gate_enabled) {
                $this->write_protected_file_list([]);
                $this->write_gate_config();
            }
            return;
        }

        global $wpdb;

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            // アップロードディレクトリが無い場合はキャッシュを削除
            delete_transient(self::MEDIA_CACHE_KEY);
            if ($this->fast_gate_enabled) {
                $this->write_protected_file_list([]);
            }
            return;
        }

        $protected_paths = ESP_Option::get_current_setting('path');
        if (empty($protected_paths)) {
            delete_transient(self::MEDIA_CACHE_KEY);
            if ($this->fast_gate_enabled) {
                $this->write_protected_file_list([]);
                $this->write_gate_config();
            }
            return;
        }

        // パスIDごとのメディアIDをグループ化
        $media_by_path = [];
        $protected_file_map = [];

        $protected_media = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value as path_id
             FROM {$wpdb->postmeta}
             WHERE meta_key = %s",
            self::META_KEY_PROTECTED_PATH
        ));

        foreach ($protected_media as $media) {
            if (!isset($protected_paths[$media->path_id])) {
                continue;
            }

            if (!isset($media_by_path[$media->path_id])) {
                $media_by_path[$media->path_id] = [];
            }

            $attachment_id = (int) $media->post_id;
            $media_by_path[$media->path_id][] = $attachment_id;

            $file_path = get_attached_file($attachment_id);
            if (!is_string($file_path) || $file_path === '') {
                continue;
            }

            $relative = $this->convert_to_relative_upload_path($file_path, $upload_dir['basedir']);
            if ($relative === '') {
                continue;
            }

            // gate.phpで参照する保護リストを構築
            $protected_file_map[$relative] = $media->path_id;
        }

        set_transient(self::MEDIA_CACHE_KEY, $media_by_path, self::MEDIA_CACHE_DURATION);

        if ($this->fast_gate_enabled) {
            $this->write_protected_file_list($protected_file_map);
            $this->write_gate_config();
        }

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

        $cached_data = get_transient(self::MEDIA_CACHE_KEY);

        if ($cached_data === false) {
            $this->regenerate_media_cache();
            $cached_data = get_transient(self::MEDIA_CACHE_KEY);
        }
        
        if (!is_array($cached_data)) {
            return [];
        }
        
        $excluded_ids = [];
        $protected_paths = ESP_Option::get_current_setting('path');
        
        foreach ($cached_data as $path_id => $media_ids) {
            if (isset($protected_paths[$path_id]) && !$this->auth->is_logged_in($protected_paths[$path_id])) {
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
        
        // 管理者権限がある場合はスキップ
        if (current_user_can('upload_files')) {
            return $args;
        }
        
        // 保護されたメディアIDを取得（キャッシュ利用）
        $excluded_media_ids = $this->get_protected_media_ids_for_current_user();
        
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
        
        // 管理者権限がある場合はスキップ
        if (current_user_can('upload_files')) {
            return $response;
        }
        
        // キャッシュから保護されたメディアIDを取得して確認
        $excluded_media_ids = $this->get_protected_media_ids_for_current_user();
        
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

    /*----- メディア編集画面の機能 -----*/

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
     * メディア保護設定を保存（キャッシュ更新付き）
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
        
        // クエリ変数を追加
        add_filter('query_vars', function($vars) {
            $vars[] = self::REWRITE_ENDPOINT;
            return $vars;
        });
    }

    /**
    * メディアファイルへのアクセスを処理（クリーンアップ版）
    */
    public function handle_media_access() {
        if (!$this->enabled) {
            return;
        }

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
        
        // ファイルパスの検証とサニタイズ
        $file_path = $this->validate_file_path($requested_file);
        if (!$file_path) {
            $this->send_404();
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
        
        // メディアIDを取得
        $attachment_id = $this->get_attachment_id_from_path($file_path);
        if (!$attachment_id) {
            // メディアライブラリに登録されていないファイルは通常配信
            $this->deliver_media($file_path);
            return;
        }
        
        // 保護設定を確認
        $protected_path_id = get_post_meta($attachment_id, self::META_KEY_PROTECTED_PATH, true);
        if (empty($protected_path_id)) {
            $this->deliver_media($file_path);
            return;
        }
        
        // 保護パス設定を取得
        $protected_paths = ESP_Option::get_current_setting('path');
        if (!isset($protected_paths[$protected_path_id])) {
            $this->deliver_media($file_path);
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
        
        // ベースディレクトリ外へのアクセスを防ぐ
        if (strpos($file_path, $base_path) !== 0) {
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
            
            // キャッシュを再生成
            $this->regenerate_media_cache();
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
     * .htaccessファイルを更新（Apache環境用）
     *
     * @param bool $force_litespeed_key_regeneration LiteSpeedキーを再生成するかどうか
     * @return bool 成功時true
     */
    public function update_htaccess($force_litespeed_key_regeneration = false) {
        if (!$this->is_apache()) {
            return $this->enabled
                ? new WP_Error('esp_htaccess_unsupported', __('ApacheまたはLiteSpeed環境でのみ利用できます。', ESP_Config::TEXT_DOMAIN))
                : true;
        }

        $upload_dir = wp_upload_dir();

        if (!empty($upload_dir['error'])) {
            return new WP_Error('esp_htaccess_upload_dir', sprintf(__('アップロードディレクトリを取得できません: %s', ESP_Config::TEXT_DOMAIN), $upload_dir['error']));
        }

        $htaccess_file = trailingslashit($upload_dir['basedir']) . '.htaccess';

        // 既存の.htaccessを読み込み（失敗した場合は空文字で継続）
        $current_rules = '';
        if (file_exists($htaccess_file)) {
            $contents = file_get_contents($htaccess_file);
            if ($contents === false) {
                return new WP_Error('esp_htaccess_read_failed', __('既存の.htaccessを読み込めませんでした。ファイル権限を確認してください。', ESP_Config::TEXT_DOMAIN));
            }
            $current_rules = $contents;
        }

        // ESP用のルールを定義
        $esp_rules = $this->get_htaccess_rules($force_litespeed_key_regeneration);

        // 既存のESPルールを削除
        $pattern = '/# BEGIN ESP Media Protection.*?# END ESP Media Protection\s*/s';
        $current_rules = preg_replace($pattern, '', $current_rules);

        // 保護が有効な場合は新しいルールを追加
        $new_rules = $this->has_protected_media() ? $esp_rules . "\n" . ltrim($current_rules) : ltrim($current_rules);

        // WP_Filesystemを優先的に使用
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        global $wp_filesystem;

        if (!is_object($wp_filesystem)) {
            WP_Filesystem();
        }

        // 書き込み処理を共通化
        $write_success = false;

        if (is_object($wp_filesystem) && $wp_filesystem instanceof WP_Filesystem_Base) {
            // WP_Filesystem経由で書き込み
            $write_success = $wp_filesystem->put_contents($htaccess_file, $new_rules, FS_CHMOD_FILE);
        }

        if (!$write_success) {
            // フォールバックで直接書き込み
            $bytes = @file_put_contents($htaccess_file, $new_rules);
            $write_success = ($bytes !== false);
        }

        if (!$write_success) {
            return new WP_Error('esp_htaccess_write_failed', __('.htaccessを書き込めませんでした。ファイル/ディレクトリの権限を確認してください。', ESP_Config::TEXT_DOMAIN));
        }

        return true;
    }

    /**
     * .htaccess用のルールを生成
     *
     * @param bool $force_litespeed_key_regeneration LiteSpeedキーを再生成するかどうか
     * @return string .htaccessルール
     */
    private function get_htaccess_rules($force_litespeed_key_regeneration = false) {
        $home_path = parse_url(home_url(), PHP_URL_PATH);
        $home_path = $home_path ? trailingslashit($home_path) : '/';

        if ($this->fast_gate_enabled) {
            $this->maybe_initialize_gate_environment($force_litespeed_key_regeneration);
        }

        // wp-content/uploads/のパスを取得
        $upload_dir = wp_upload_dir();
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
        
        // 保護されたメディアが存在する場合のみルールを適用
        if ($this->has_protected_media()) {
            if ($this->is_litespeed()) {
                // LiteSpeed用の認証キーを必ず確保
                $litespeed_key = $this->ensure_litespeed_key($force_litespeed_key_regeneration);
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

            $rewrite_target = $this->build_gate_rewrite_target($home_path);
            if ($rewrite_target !== null) {
                $media_gate_key = self::ensure_media_gate_key_exists($force_litespeed_key_regeneration);
                $site_token = self::get_current_site_storage_token();
                $rules .= "{$indent}# 環境変数でゲートキーとサイトIDを渡し高速ゲートにリダイレクト\n";
                $rules .= "{$indent}RewriteRule ^(.+)$ {$rewrite_target} [L,QSA,E=" . self::GATE_ENV_KEY . ":{$media_gate_key},E=" . self::GATE_SITE_ENV_KEY . ":{$site_token}]\n";
            } else {
                $rewrite_flags = $this->is_litespeed() ? '[L,QSA]' : '[L]';
                $rules .= "{$indent}RewriteRule ^(.+)$ {$home_path}index.php?" . self::REWRITE_ENDPOINT . "=$1 {$rewrite_flags}\n";
            }
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
        return self::is_apache_server();
    }

    /**
     * Apache互換サーバーか確認（静的）
     */
    private static function is_apache_server() {
        $software = self::get_server_software();

        return stripos($software, 'Apache') !== false
            || stripos($software, 'LiteSpeed') !== false;
    }

    /**
     * LiteSpeed環境か確認
     */
    private function is_litespeed() {
        return self::is_litespeed_server();
    }

    /**
     * LiteSpeed環境か確認（静的）
     */
    private static function is_litespeed_server() {
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
        $settings = ESP_Option::get_current_setting('media');
        $deriver_slug = self::get_active_gate_deriver_slug($settings);
        $use_fast_gate = ($deriver_slug === 'deriver-nginx.php');

        if ($use_fast_gate) {
            $secret_relative = self::get_secret_directory_relative_path_static();
            $deriver_path = trim($secret_relative . '/' . $deriver_slug, '/');
            $media_gate_key = self::ensure_media_gate_key_exists();
            $site_token = self::get_current_site_storage_token();
            self::write_gate_config_static();

            $rules .= "    set \\$esp_media_gate_key \"{$media_gate_key}\";\n";
            $rules .= "    set \\$esp_media_site_id \"{$site_token}\";\n";
            $rules .= "    rewrite ^{$upload_path}/(.+)$ {$home_path}{$deriver_path}?file=$1 last;\n";
            $rules .= "    # PHPハンドラ側で fastcgi_param " . self::GATE_ENV_KEY . " \\$esp_media_gate_key;\n";
            $rules .= "    # fastcgi_param " . self::GATE_SITE_ENV_KEY . " \\$esp_media_site_id; を追加してください。\n";
        } else {
            $rules .= "    rewrite ^{$upload_path}/(.+)$ {$home_path}index.php?" . self::REWRITE_ENDPOINT . "=$1 last;\n";
        }

        $rules .= "}\n";
        $rules .= "# END ESP Media Protection (nginx)\n";

        return array(
            'rules' => $rules,
            'media_enabled' => self::is_media_protection_enabled(),
            'has_protected_media' => self::has_any_protected_media_records(),
            'fast_gate_active' => $use_fast_gate,
        );
    }

    /**
     * LiteSpeed用のキーを取得（存在しない場合は生成して保存）
     *
     * @param bool $force_regeneration 強制的にキーを再生成するかどうか
     */
    private function ensure_litespeed_key($force_regeneration = false) {
        // 強制再生成が不要で、保存済みのキーがあればそれを利用
        $existing = self::get_litespeed_key_value();
        if (!$force_regeneration && $existing !== '') {
            return $existing;
        }

        // 未保存または強制再生成が必要な場合は新たにキーを生成
        $key = $this->generate_litespeed_key();

        $settings = ESP_Option::get_all_settings();
        if (!isset($settings['media']) || !is_array($settings['media'])) {
            $settings['media'] = array();
        }

        $settings['media'][self::OPTION_LITESPEED_KEY] = $key;
        ESP_Option::update_settings($settings);

        $this->write_gate_config();

        return $key;
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
     * メディアゲートキーを確保
     */
    private function ensure_media_gate_key($force_regeneration = false) {
        return self::ensure_media_gate_key_exists($force_regeneration);
    }

    /**
     * メディアゲートキーを取得または生成
     */
    public static function ensure_media_gate_key_exists($force_regeneration = false) {
        $settings = ESP_Option::get_current_setting('media');
        $current = '';
        if (is_array($settings) && isset($settings[self::OPTION_MEDIA_GATE_KEY])) {
            // 既存キーを信頼する
            $current = sanitize_text_field($settings[self::OPTION_MEDIA_GATE_KEY]);
        }

        if (!$force_regeneration && $current !== '') {
            return $current;
        }

        // 新しいキーを生成して保存
        $new_key = self::generate_media_gate_key();

        if (!is_array($settings)) {
            $settings = array();
        }
        $settings[self::OPTION_MEDIA_GATE_KEY] = $new_key;
        ESP_Option::update_section('media', $settings);

        return $new_key;
    }

    /**
     * メディアゲートキーを取得
     */
    public static function get_media_gate_key_value() {
        $settings = ESP_Option::get_current_setting('media');
        if (!is_array($settings) || !isset($settings[self::OPTION_MEDIA_GATE_KEY])) {
            return '';
        }

        return sanitize_text_field($settings[self::OPTION_MEDIA_GATE_KEY]);
    }

    /**
     * メディアゲートキー生成
     */
    private static function generate_media_gate_key() {
        try {
            // 安全な乱数で生成
            return bin2hex(random_bytes(18));
        } catch (Exception $e) {
            if (function_exists('wp_generate_password')) {
                $password = wp_generate_password(36, false, false);
                $password = preg_replace('/[^a-zA-Z0-9]/', '', $password);
                if (is_string($password) && $password !== '') {
                    // WordPressのヘルパーをフォールバック利用
                    return $password;
                }
            }

            // 最終手段として一意な文字列をハッシュ
            return substr(md5(uniqid((string) mt_rand(), true)), 0, 36);
        }
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
     * 保護されたメディアが存在するかどうか
     */
    private static function has_any_protected_media_records() {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::META_KEY_PROTECTED_PATH
        ));

        return (int) $count > 0;
    }

    /**
     * 設定保存時に呼び出される処理
     */
    public function on_settings_save() {
        // キャッシュを再生成
        $this->regenerate_media_cache();
        
        // .htaccessを更新
        $this->update_htaccess();
        
        // リライトルールをフラッシュ
        flush_rewrite_rules();
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