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
     * @var ESP_PATH_MATCHER パスマッチャーのインスタンス
     */
    private $path_matcher;

    /**
     * @var ESP_Media_Protection メディア保護機能のインスタンス
     */
    private $media_protection;



    /**
     * コンストラクタ
     */
    public function __construct() {
        $this->auth = new ESP_Auth();
        $this->logout = new ESP_Logout();
        $this->security = new ESP_Security();
        $this->cookie = ESP_Cookie::get_instance();
        $this->filter = new ESP_Filter();
        $this->path_matcher = new ESP_Path_Matcher();
        $this->media_protection = new ESP_Media_Protection();
    }

    /**
     * 初期化処理
     */
    public function init() {
        // アクションとフックの登録
        add_action('template_redirect', [$this, 'check_protected_page']);
        // add_action('wp_ajax_esp_clean_old_data', [$this, 'clean_old_data']);
        // add_action('wp_ajax_nopriv_esp_clean_old_data',[$this, 'clean_old_data']);
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
        // REST APIリクエストと管理画面は除外
        if ($this->is_excluded_request()) {
            return;
        }

        // 現在のパスを取得
        $current_path = $this->get_current_path();

        // パスマッチャーを使用して保護対象のパスを取得
        $target_path = $this->path_matcher->match($current_path);

        // ページ情報が取得出来ない場合終了
        global $post;
        if (is_null($post) || !isset($post->ID)){
            return;
        }
        
        // 現在のページがログインページかチェック
        $is_login_page_setting = $this->is_login_page($post->ID);

        // 保護対象ページかつログインページ以外
        if ($target_path && !$is_login_page_setting) {
            static $nocache_headers_sent = false;
            // まだ送信されていない場合のみ送信
            if (!$nocache_headers_sent && !headers_sent()) {
                nocache_headers();

                $cache_control_sent = false;
                $pragma_sent = false;
                // headers_list() が利用可能な場合のみ確認
                if (function_exists('headers_list')) {
                    foreach (headers_list() as $header_line) {
                        $lower_header = strtolower($header_line);
                        // Cache-Control ヘッダー検出
                        if (strpos($lower_header, 'cache-control:') === 0) {
                            $cache_control_sent = true;
                        }
                        // Pragma ヘッダー検出
                        if (strpos($lower_header, 'pragma:') === 0) {
                            $pragma_sent = true;
                        }
                        // 両方検出したら終了
                        if ($cache_control_sent && $pragma_sent) {
                            break;
                        }
                    }
                }

                // まだ送信されていない場合のみ追加
                if (!$cache_control_sent) {
                    header('Cache-Control: no-cache, must-revalidate, max-age=0');
                }
                // まだ送信されていない場合のみ追加
                if (!$pragma_sent) {
                    header('Pragma: no-cache');
                }

                $nocache_headers_sent = true;
            }
        }

        // POSTリクエストの場合はログイン処理を優先
        if (isset($_POST['esp_password'])) {
            $login_handling_path_setting = $is_login_page_setting ? $is_login_page_setting : $target_path;
            if ($login_handling_path_setting) { // パス設定が存在する場合のみ処理
                 $this->handle_login_request($login_handling_path_setting);
            }
            return;
        }

        // ログインページもしくは保護対象でない場合は処理終了
        if ($is_login_page_setting || !$target_path) {
            return;
        }

        // ログイン済みの場合は処理終了（アクセスを許可）
        if ($this->auth->is_logged_in($target_path)) {
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
        $current_path = home_url(add_query_arg(array(), $wp->request));
        $current_path = '/' . trim($wp->request, '/') . '/';
        return $current_path;
    }

    /**
     * 指定されたページIDが「存在していて公開状態か」を返す
     *
     * @param int $page_id ページID。
     * @return bool 有効な場合は true、そうでない場合は false。
     */
    private function is_valid_login_page($page_id) {
        if (empty($page_id) || !is_numeric($page_id)) { // 空または数値でないIDは無効
            return false;
        }
        $page = get_post(absint($page_id)); // absint() で正の整数に
        return $page && $page->post_status === 'publish';
    }

    // /**
    //  * 保護対象のパスを取得
    //  * 
    //  * @param string $current_path 現在のパス
    //  * @param array $protected_paths 保護対象のパス一覧
    //  * @return array|false マッチしたパスの設定。ない場合はfalse
    //  */
    // private function get_matching_protected_path($current_path, $protected_paths) {
    //     foreach ($protected_paths as $path_id => $protected_path) {
    //         if (strpos($current_path, $protected_path['path']) === 0) {
    //             return $protected_path;
    //         }
    //     }
    //     return false;
    // }


    /**
     * 指定されたページIDがいずれかの保護パスのログインページとして設定され、かつ有効であるか確認
     *
     * @param int $page_id ページID
     * @return array|bool 有効なログインページである場合はパス設定、そうでない場合はfalse
     */
    private function is_login_page($page_id) { // 有効性チェックを追加
        if (empty($page_id) || !is_numeric($page_id)) { // 不正なページIDの場合は早期リターン
            return false;
        }
        $protected_paths = ESP_Option::get_current_setting('path');
        if (empty($protected_paths) || !is_array($protected_paths)) { // 設定がない場合は早期リターン
             return false;
        }

        foreach ($protected_paths as $path_id => $path_setting) {
            // MODIFIED: 設定されたログインページIDが現在のページIDと一致し、かつそのページが有効かチェック
            if (isset($path_setting['login_page']) && $path_setting['login_page'] == $page_id) {
                if ($this->is_valid_login_page($path_setting['login_page'])) {
                    return $path_setting; // 有効なログインページとして設定されている
                } else {
                    // 設定はされているが、ページ自体が無効（存在しない、非公開など）
                    // この場合、このページは「ログインページではない」と扱うことでループを防ぐ
                    return false;
                }
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
        if (!isset($_POST['esp_nonce']) || !$this->security->verify_nonce($_POST['esp_nonce'], $path_settings['id'])) {
            ESP_Message::set_error(__('不正なリクエストです。', ESP_Config::TEXT_DOMAIN));
            $this->redirect_to_login_page($path_settings);
            return;
        }

        // リダイレクト先取得
        $redirect_to = $this->get_redirect_to($path_settings);

        // ログイン処理
        $password = isset($_POST['esp_password']) ? $_POST['esp_password'] : '';
        if ($this->auth->process_login($path_settings, $password)) {
            // ログイン成功時は元のページへリダイレクト（cookieクラス使用でcookie適用させる）
            $this->cookie->do_redirect($redirect_to);
            exit; 
        }

        // ログイン失敗時はログインページへリダイレクト
        $this->redirect_to_login_page($path_settings, $redirect_to);
        exit;
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
            // フォールバック先としてホームページのパスを返す
            return home_url('/');
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

        // 先頭と末尾のスラッシュを正規化し、常に先頭にスラッシュを付与
        $safe_path = '/' . trim($normalized, '/');
        if ($safe_path === '/') { // ルートの場合、末尾のスラッシュは任意だが、ここでは統一
             // $safe_path .= '/'; // 必要に応じて
        } elseif (substr($safe_path, -1) !== '/' && strpos($safe_path, '?') === false && strpos($safe_path, '#') === false) {
            // クエリやフラグメントがない場合のみ末尾にスラッシュを追加（ディレクトリ形式を期待する場合）
            // $safe_path .= '/';
        }


        // 危険な文字や連続したスラッシュを除去
        $safe_path = preg_replace(
            [
                '#[<>:"\'%\{\}\(\)\[\]\^`\\\\]#',  // 危険な文字を除去
                '#//+#',                            // 連続したスラッシュを単一のスラッシュに (正規化後なので不要かも)
            ],
            [
                '',
                '/'
            ],
            $safe_path
        );

        // 相対パス ../ を解決
        $parts = explode('/', $safe_path);
        $absolutes = array();
        foreach ($parts as $part) {
            if ('.' == $part) continue;
            if ('..' == $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        $safe_path = implode('/', $absolutes);

        // ここではパス文字列のみを返す前提のため、このまま
        if (strpos($safe_path, '/') !== 0 && !empty($safe_path)) { // 先頭にスラッシュがない場合（ありえないはずだが念のため）
            $safe_path = '/' . $safe_path;
        }
        if (empty($safe_path)) { // パスが空になった場合
            return '/';
        }

        return $safe_path;
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

        // ログインページの有効性チェックとフォールバック処理
        $login_page_id = isset($path_settings['login_page']) ? $path_settings['login_page'] : 0;

        if ($this->is_valid_login_page($login_page_id)) {
            $login_url = get_permalink($login_page_id);
            if ($login_url) { // get_permalinkが失敗しないか確認
                $login_url_with_redirect = add_query_arg(
                    array('redirect_to' => urlencode($current_path)),
                    $login_url
                );
                $this->cookie->do_redirect($login_url_with_redirect, false);
                exit; // リダイレクト後は確実に終了
            }
        }
        
        // フォールバック：ログインページが無効な場合はホームページへリダイレクト
        // エラーメッセージを設定
        ESP_Message::set_error(__('ログインページが見つかりません。管理者にお問い合わせください。', ESP_Config::TEXT_DOMAIN));
        $this->cookie->do_redirect(home_url('/'), false);
        exit;
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
            'path_id' => '',
            'place_holder' =>  __('ログイン', ESP_Config::TEXT_DOMAIN)
        ), $atts, 'esp_login_form');

        // 現在のページのパスを取得
        global $post;
        if (!$post) {
            return '';
        }

        // ショートコードの属性が指定されているか確認
        $lock_path = $atts['path'] ? '/' . trim($atts['path'], '/') . '/' : null;
        $lock_path_id = $atts['path_id'] ?: null;

        // 保護パス設定から対応するパスを検索
        $protected_paths = ESP_Option::get_current_setting('path');
        $target_path_setting = null;

        
        if (empty($protected_paths) || !is_array($protected_paths)) {
            ESP_Message::set_error(__('保護設定が見つかりません。', ESP_Config::TEXT_DOMAIN));
            return;
        }

        foreach ($protected_paths as $path_id => $path) {
            // path_id属性が指定されている場合はそれを優先
            if ($lock_path_id && $path_id === $lock_path_id) {
                $target_path_setting = $path;
                break;
            }
            // path属性が指定されている場合
            elseif ($lock_path && $path['path'] === $lock_path) {
                $target_path_setting = $path;
                break;
            }
            // 指定がない場合は現在のページIDに基づいてパスを検索
            elseif (!$lock_path && !$lock_path_id && $path['login_page'] == $post->ID) {
                $target_path_setting = $path;
                break;
            }
        }

        if (!$target_path_setting) {
            if ($atts['path'] || $atts['path_id']) {
                ESP_Message::set_error(__('保護設定が見つかりません。', ESP_Config::TEXT_DOMAIN));
                return;
            }
            return '';
        }

        // MODIFIED: ログインページの有効性をチェック
        $login_page_id_for_form = isset($target_path_setting['login_page']) ? $target_path_setting['login_page'] : 0;
        if (!$this->is_valid_login_page($login_page_id_for_form)) {
            // フォームを表示しようとしているログインページ自体が無効な場合
            // このショートコードがlogin_pageに置かれていれば、このメッセージが表示
            SP_Message::set_error(__('このページは現在有効ではありません。', ESP_Config::TEXT_DOMAIN));
            return;
        }

        // リダイレクト先取得
        $redirect_to_param = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '';
        if (empty($redirect_to_param) && isset($target_path_setting['path'])) { // redirect_to がなければ保護対象パスをセット
            $redirect_to_param = $target_path_setting['path'];
        }
        // redirect_to もサニタイズした方がより安全
        $redirect_to_param = $this->sanitize_path($redirect_to_param);

        return $this->auth->get_login_form($target_path_setting, $redirect_to_param, $atts['place_holder']);
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