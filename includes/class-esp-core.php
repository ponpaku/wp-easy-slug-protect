<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * プラグインのコア機能を提供するクラス
 */
class ESP_Core {
    /**
     * @var ESP_Auth 認証クラスのインスタンス
     */
    private $auth;

    /**
     * @var ESP_Security セキュリティクラスのインスタンス
     */
    private $security;

    /**
     * @var ESP_Session セッション管理クラスのインスタンス
     */
    private $session;

    /**
     * @var ESP_Logout ログアウト処理クラスのインスタンス
     */
    private $logout;
    
    /**
     * @var ESP_Cookie ログアウト処理クラスのインスタンス
     */
    private $cookie;

    /**
     * @var ESP_Filter フィルタークラスのインスタンス
     */
    private $filter;



    /**
     * コンストラクタ
     */
    public function __construct() {
        $this->auth = new ESP_Auth();
        $this->logout = new ESP_Logout();
        $this->security = new ESP_Security();
        $this->session = ESP_Session::get_instance();
        $this->cookie = ESP_Cookie::get_instance();
        $this->filter = new ESP_Filter();
    }

    /**
     * 初期化処理
     */
    public function init() {
        // アクションとフックの登録
        add_action('template_redirect', [$this, 'check_protected_page']);
        add_action('wp_ajax_esp_clean_old_data', [$this, 'clean_old_data']);
        add_action('wp_ajax_nopriv_esp_clean_old_data',[$this, 'clean_old_data']);
        // スタイルの読み込み
        add_action('wp_enqueue_scripts', [$this, 'register_front_styles']);
        //  ログアウト処理のハンドリング
        add_action('init', [$this, 'handle_logout']);
        // Cookie設定のハンドリング
        add_action('init', [$this, 'handle_cookies'], 1); // 優先度を高く設定
        
        // ショートコードの登録
        add_shortcode('esp_login_form', [$this, 'render_login_form']);
        add_shortcode('esp_logout_button', [$this, 'render_logout_button']);

        // フィルターの初期化
        add_action('wp_loaded', [$this->filter, 'init']);
    }

    /**
     * Cookie設定のハンドリング
     */
    public function handle_cookies() {
        if ($this->cookie->has_pending_cookies()) {
            $this->cookie->set_pending_cookies();
        }
    }

    /**
     * ショートコードに応じてフロントエンド用のCSSを登録
     */        
    public function register_front_styles() {
        wp_enqueue_style(
            'esp-front-styles',
            ESP_URL . 'front/esp-styles.css',
            array(),
            ESP_VERSION
        );
    }

    /**
     * 保護ページのチェックと制御
     */
    public function check_protected_page() {
        global $wp;
        global $post;

        // REST APIリクエストと管理画面は除外
        if ($this->is_excluded_request()) {
            return;
        }

        // 現在のパスを取得
        $current_path = $this->get_current_path();

        // 保護対象のパスを取得
        $protected_paths = ESP_Option::get_current_setting('path');

        // 保護対象のパスかチェック
        $target_path = $this->get_matching_protected_path($current_path, $protected_paths);

        // ページ情報が取得出来ない場合終了
        if (is_null($post) || !isset($post->ID)){
            return;
        }
        // 現在のページがログインページかチェックし設定取得
		$is_login_page = $this->is_login_page($post->ID);

        // POSTリクエストの場合はログイン処理を優先
        if (isset($_POST['esp_password'])) {
            $this->handle_login_request($is_login_page);
            return;
        }

        // ログインページもしくは保護対象でない場合は処理終了
        if ($is_login_page || !$target_path) {
            return;
        }

        // ログイン済みの場合は処理終了（アクセスを許可）
        if ($this->auth->is_logged_in($target_path['path'])) {
            return;
        }

        // 未ログインの保護ページアクセスはログインページへリダイレクト
        $this->redirect_to_login_page($target_path, $current_path);
    }

    /**
     * 除外するリクエストかどうかの判定
     * 
     * @return bool 除外する場合はtrue
     */
    private function is_excluded_request() {
        return (
            // REST APIリクエスト
            (defined('REST_REQUEST') && REST_REQUEST) ||
            // 管理画面
            is_admin() ||
            // WP-CLI
            (defined('WP_CLI') && WP_CLI) ||
            // AJAX
            wp_doing_ajax()
        );
    }

    /**
     * 現在のパスを取得する
     * 
     * @return string パス
     */
    private function get_current_path(){
        global $wp;
        $current_path = '/' . trim($wp->request, '/') . '/';
        return $current_path;
    }

    /**
     * 保護対象のパスを取得
     * 
     * @param string $current_path 現在のパス
     * @param array $protected_paths 保護対象のパス一覧
     * @return array|false マッチしたパスの設定。ない場合はfalse
     */
    private function get_matching_protected_path($current_path, $protected_paths) {
        foreach ($protected_paths as $protected_path) {
            if (strpos($current_path, $protected_path['path']) === 0) {
                return $protected_path;
            }
        }
        return false;
    }

    /**
     * 指定されたページIDがいずれかの保護パスのログインページとして設定されているか確認
     * 
     * @param int $page_id ページID
     * @return array ログインページで無い場合false
     */
    private function is_login_page($page_id) {
        $protected_paths = ESP_Option::get_current_setting('path');
        foreach ($protected_paths as $path_setting) {
            if ($path_setting['login_page'] == $page_id) {
                return $path_setting;
            }
        }
        return false;
    }

    /**
     * ログインリクエストの処理
     * 
     * @param array $path_settings ターゲットパスの設定
     */
    private function handle_login_request($path_settings) {
        // CSRFチェック
        if (!isset($_POST['esp_nonce']) || !$this->security->verify_nonce($_POST['esp_nonce'], $path_settings['path'])) {
            $this->session->set_error(__('不正なリクエストです。', ESP_Config::TEXT_DOMAIN));
            $this->redirect_to_login_page($path_settings);
            return;
        }

        // リダイレクト先取得
        $redirect_to = $this->get_redirect_to($path_settings);

        // ログイン処理
        $password = isset($_POST['esp_password']) ? $_POST['esp_password'] : '';
        if ($this->auth->process_login($path_settings, $password)) {
            // エラー削除しておく
            $this->session->del_error();
            // ログイン成功時は元のページへリダイレクト（cookieクラス使用でcookie適用させる）
            $this->cookie->do_redirect($redirect_to);
        }

        // ログイン失敗時はログインページへリダイレクト
        $this->redirect_to_login_page($path_settings, $redirect_to);
    }

    /**
     * リダイレクト先の取得
     * パスを安全な形式に整形して返す
     * 
     * @param array|null $path_settings パスの設定
     * @return string サニタイズ済みのエンコードされたパス
     */
    private function get_redirect_to($path_settings = null) {
        // リダイレクト先のパスを取得
        if (isset($_POST['redirect_to'])) {
            $path = $_POST['redirect_to'];
        } elseif (isset($_GET['redirect_to'])) {
            $path = $_GET['redirect_to'];
        } elseif (!is_null($path_settings) && isset($path_settings['path'])) {
            $path = $path_settings['path'];
        } else {
            return '/';
        }
        
        // 整形したパスを返す   
        return $this->sanitize_path($path);
    }

    /**
     * パスを安全な形式に整形する
     * 
     * @param string $path 整形前のパス
     * @return string 整形後の安全なパス
     */
    private function sanitize_path($path) {
        // URLデコード（エンコードされている場合）
        $decoded_path = rawurldecode($path);
        
        // プロトコルとドメインを除去
        $path_only = preg_replace('#^https?://[^/]+#', '', $decoded_path);
        
        // パスを正規化
        $normalized = wp_normalize_path($path_only);
        
        // 先頭と末尾のスラッシュを正規化
        $safe_path = '/' . trim($normalized, '/') . '/';
        
        // 危険な文字や連続したスラッシュを除去
        $safe_path = preg_replace(
            [
                '#[<>:"\'%\{\}\(\)\[\]\^`\\\\]#',  // 危険な文字を除去
                '#/+#'                              // 連続したスラッシュを単一のスラッシュに
            ],
            [
                '',
                '/'
            ],
            $safe_path
        );
        
        // 親ディレクトリへの参照（../）を除去
        $parts = explode('/', $safe_path);
        $safe_parts = array();
        
        foreach ($parts as $part) {
            if ($part === '.' || $part === '..') {
                continue;
            }
            if ($part !== '') {
                $safe_parts[] = $part;
            }
        }
        
        return '/' . implode('/', $safe_parts) . '/';
    }


    /**
     * ログインページへのリダイレクト
     * 
     * @param array $path_settings パスの設定
     * @param string $current_url 現在のURL
     */
    private function redirect_to_login_page($path_settings, $current_path = null) {
        if (is_null($current_path)) {
            $current_path = $this->get_current_path();
        }
        $login_url = add_query_arg(
            array(
                'redirect_to' => urlencode($current_path)
            ),
            get_permalink($path_settings['login_page'])
        );
        $this->cookie->do_redirect($login_url, false);
    }

    /**
     * ログインフォームのレンダリング
     * ショートコード [esp_login_form] で使用
     * 
     * @param array $atts ショートコード属性
     * @return string ログインフォームのHTML
     */
    public function render_login_form($atts = array()) {
        // ショートコードの属性を取得（デフォルトで空の path）
        $atts = shortcode_atts(array(
            'path' => '',
            'place_holder' => 'パスワード'
        ), $atts);

        // 現在のページのパスを取得
        global $post;
        if (!$post) {
            return '';
        }

        // ショートコードの `path` 属性が指定されているか確認
        $lock_path = $atts['path'] ? '/' . trim($atts['path'], '/') . '/' : null;

        // 保護パス設定から対応するパスを検索
        $protected_paths = ESP_Option::get_current_setting('path');
        $target_path = null;

        foreach ($protected_paths as $path) {
            // `path`属性が指定されている場合はそれを使用
            if ($lock_path && $path['path'] === $lock_path) {
                $target_path = $path;
                break;
            }
            // 指定がない場合は現在のページIDに基づいてパスを検索
            elseif (!$lock_path && $path['login_page'] == $post->ID) {
                $target_path = $path;
                break;
            }
        }

        if (!$target_path) {
            return '';
        }

        // リダイレクト先の取得
        $redirect_to = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '';
        return $this->auth->get_login_form($target_path['path'], $redirect_to, $atts['place_holder']);
    }

    /**
     * ログアウトボタンのレンダリング
     * 
     * @param array $atts ショートコード属性
     * @return string ログアウトボタンのHTML
     */
    public function render_logout_button($atts = array()) {
        return $this->logout->get_logout_button($atts);
    }

    /**
     * ログアウト処理のハンドリング
     */
    public function handle_logout() {
        if (isset($_POST['esp_action']) && $_POST['esp_action'] === 'logout') {
            // ログアウト処理
            $this->logout->process_logout();
            // リダイレクト実行
            $redirect_to = $this->get_redirect_to();
            $this->cookie->do_redirect($redirect_to);
        }
    }

}