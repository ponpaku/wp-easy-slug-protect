<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 保護されたページをクエリから除外するクラス
 */
class ESP_Filter {
    /**
     * @var ESP_Auth 認証クラスのインスタンス
     */
    private $auth;

    /**
     * @var string トランジェントのキー
     */
    const CACHE_KEY = 'esp_protected_posts';

    /**
     * @var int キャッシュの有効期間（秒）
     */
    const CACHE_DURATION = DAY_IN_SECONDS;

    /**
     * コンストラクタ
     */
    public function __construct() {
        $this->auth = new ESP_Auth();
    }

    /**
     * フィルタリング機能の初期化
     */
    public function init() {
        // キャッシュを確認
        $this->check_and_generate_cache();

        // メインクエリの書き換え
        add_action('pre_get_posts', [$this, 'exclude_protected_posts']);

        // 投稿の変更時にキャッシュを更新
        add_action('save_post', [$this, 'regenerate_protected_posts_cache']);
        add_action('delete_post', [$this, 'regenerate_protected_posts_cache']);
        add_action('trash_post', [$this, 'regenerate_protected_posts_cache']);
        add_action('update_option_' . ESP_Config::OPTION_KEY, [$this, 'regenerate_protected_posts_cache']);

        // パーマリンク構造が変更された時もキャッシュを更新
        add_action('permalink_structure_changed', [$this, 'regenerate_protected_posts_cache']);
    }

    /**
     * 外部からのキャッシュ更新用
     */
    public function reset_cach(){
        $this->regenerate_protected_posts_cache();
    }

    /**
     * キャッシュの存在確認と生成
     */
    private function check_and_generate_cache() {
        $cached_ids = get_transient(self::CACHE_KEY);
        if ($cached_ids === false) {
            $this->regenerate_protected_posts_cache();
        }
    }

    /**
     * 保護されたページをクエリから除外
     */
    public function exclude_protected_posts($query) {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        if (!$query->is_main_query()) {
            return;
        }

        if ($query->is_search() || $query->is_archive() || $query->is_tag() || $query->is_feed() || (function_exists('is_sitemap') && is_sitemap())) {
            $excluded_post_ids = $this->get_excluded_post_ids();
            if (!empty($excluded_post_ids)) {
                $current_excluded = $query->get('post__not_in', []);
                $query->set('post__not_in', array_merge($current_excluded, $excluded_post_ids));
            }
        }
    }

    /**
     * 除外すべき投稿IDを取得
     * 
     * @return array 除外すべき投稿IDの配列
     */
    private function get_excluded_post_ids() {
        // キャッシュをチェック
        $cached_ids = get_transient(self::CACHE_KEY);
        if ($cached_ids !== false) {
            return $this->filter_cached_ids($cached_ids);
        }

        // キャッシュがない場合は生成
        $this->regenerate_protected_posts_cache();
        $cached_ids = get_transient(self::CACHE_KEY);
        
        return $this->filter_cached_ids($cached_ids);
    }

    /**
     * キャッシュされた投稿IDをログイン状態に基づいてフィルタリング
     * 
     * @param array $cached_ids キャッシュされた投稿ID
     * @return array フィルタリング済みの投稿ID
     */
    private function filter_cached_ids($cached_ids) {
        if (!is_array($cached_ids)) {
            return [];
        }

        $result = [];
        foreach ($cached_ids as $path => $ids) {
            if (!$this->auth->is_logged_in($path)) {
                $result = array_merge($result, $ids);
            }
        }

        return array_unique($result);
    }

    /**
     * 保護された投稿のキャッシュを再生成
     */
    public function regenerate_protected_posts_cache() {
        // init後まで待つ
        if (!did_action('init')) {
            add_action('init', [$this, 'regenerate_protected_posts_cache'], 999);
            return;
        }

        $protected_paths = ESP_Option::get_current_setting('path');
        if (empty($protected_paths)) {
            delete_transient(self::CACHE_KEY);
            error_log('eps: No protected paths found');
            return;
        }

        // すべての公開された投稿を取得
        $all_posts = get_posts([
            'post_type'   => 'any',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);

        $all_protected_posts = [];

        foreach ($protected_paths as $path_setting) {
            $path = $path_setting['path'];
            $protected_path = '/' . trim($path, '/') . '/';
            $post_ids = [];

            foreach ($all_posts as $post_id) {
                $permalink = get_permalink($post_id);
                $parsed_url = parse_url($permalink);
                $post_path = isset($parsed_url['path']) ? '/' . trim($parsed_url['path'], '/') . '/' : '/';
            
                if (strpos($post_path, $protected_path) !== false) {
                    // ここで$all_protected_posts[$protected_path]が配列かどうかを確認し、配列でなければ初期化
                    if (!isset($all_protected_posts[$protected_path])) {
                        $all_protected_posts[$protected_path] = [];
                    }
                    $all_protected_posts[$protected_path][] = $post_id; 
                }
            }
        }
        set_transient(self::CACHE_KEY, $all_protected_posts, self::CACHE_DURATION);
    }
}