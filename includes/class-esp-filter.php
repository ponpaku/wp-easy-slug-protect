<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 保護されたページをクエリから除外し、パーマリンクパスの整合性を管理するクラス
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

        // 投稿の変更時にキャッシュを更新 & メタデータ更新
        add_action('save_post', [$this, 'handle_save_post'], 10, 2); // 優先度を少し標準的に
        add_action('delete_post', [$this, 'handle_delete_post']); // delete_post時にはメタデータは自動で消えるはずだがキャッシュは更新
        add_action('trash_post', [$this, 'regenerate_protected_posts_cache']); // ゴミ箱移動時もキャッシュ更新
        add_action('untrash_post', [$this, 'regenerate_protected_posts_cache']); // ゴミ箱から戻した時もキャッシュ更新

        // オプション変更時
        add_action('update_option_' . ESP_Config::OPTION_KEY, [$this, 'regenerate_protected_posts_cache']);

        // パーマリンク構造が変更された時
        add_action('permalink_structure_changed', [$this, 'handle_permalink_structure_change']);

        // REST API フィルタリング
        // 登録されているすべての公開投稿タイプに対してフックを追加
        $post_types = get_post_types(['public' => true], 'names');
        foreach ($post_types as $post_type) {
            add_filter("rest_{$post_type}_query", [$this, 'filter_rest_post_type_query'], 10, 2);
            add_filter("rest_prepare_{$post_type}", [$this, 'check_rest_single_post_access'], 10, 3);
        }
    }

    /*----- handler ------*/

    /**
     * 投稿保存時の処理 (メタデータ更新とキャッシュ再生成)
     */
    public function handle_save_post($post_id, $post) {
        // 自動保存やリビジョンは対象外
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        // 公開ステータスの投稿のみ対象 (あるいはプラグイン設定で対象投稿タイプを絞るなど)
        if ($post->post_status !== 'publish') {
            // 公開されていない場合は、関連するメタデータを削除してもよい
            delete_post_meta($post_id, ESP_Config::PERMALINK_PATH_META_KEY);
        } else {
            $this->update_single_post_permalink_path_meta($post_id);
        }
        // キャッシュを再生成
        $this->regenerate_protected_posts_cache();
    }

    /**
     * 投稿削除時の処理 (キャッシュ再生成)
     */
    public function handle_delete_post($post_id) {
        // _esp_permalink_path メタデータは投稿削除時に自動で削除される
        $this->regenerate_protected_posts_cache();
    }
    
    /**
     * パーマリンク構造変更時の処理
     * 全投稿のパーマリンクパスマークを更新するようフラグを立てるか、直接実行を試みる
     * 同時に保護キャッシュもクリアする
     */
    public function handle_permalink_structure_change() {
        // 全投稿のメタデータ更新を促す (ここではまずキャッシュクリアのみ)
        // 本来はここで全件更新処理をキックするのが理想だが、重いため管理画面からの手動実行をメインとする
        error_log('ESP: Permalink structure changed. Manual regeneration of permalink path meta data is recommended.');
        $this->force_regenerate_all_permalink_paths_meta(false); // 非同期やバッチ処理を推奨するためここでは直接的な重い処理は避ける
        $this->regenerate_protected_posts_cache();
    }

    /*- ajax handler -*/

    /**
     * AJAXハンドラ: 全投稿のパーマリンクパスマークをバッチ処理で再生成
     */
    public function ajax_regenerate_permalink_paths_batch() {
        // nonceチェック
        check_ajax_referer('esp_regenerate_permalinks_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('権限がありません。', 'easy-slug-protect')], 403);
        }

        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 50; // 1回の処理件数
        // $limit の上限も設けた方が良いかもしれない

        // force_regenerate_all_permalink_paths_meta_batch は既に wp_send_json_success/error を呼ぶのでそのまま
        $this->force_regenerate_all_permalink_paths_meta_batch(true, $offset, $limit);
        // このメソッドは wp_send_json_success/error を呼び出し、wp_die() するので、これ以上の出力は不要
    }

    /**
     * AJAXハンドラ: 保護キャッシュをクリア
     */
    public function ajax_clear_protection_cache() {
        check_ajax_referer('esp_clear_cache_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('権限がありません。', 'easy-slug-protect')], 403);
        }

        delete_transient(self::CACHE_KEY);
        // 必要であれば、ここで明示的にキャッシュを再生成する regenerate_protected_posts_cache() を呼んでも良いが、
        // 通常は次にキャッシュが必要になった際に自動生成されるため、delete_transient だけで十分な場合が多い。
        // 今回は明示的に再生成を試みる。
        $this->regenerate_protected_posts_cache();

        wp_send_json_success(['message' => __('保護キャッシュをクリアしました。', 'easy-slug-protect')]);
    }


    /*----- control post_meta -----*/

    /**
     * 単一投稿の _esp_permalink_path メタデータを更新する
     *
     * @param int $post_id 更新する投稿のID
     * @return bool 更新成功でtrue、失敗でfalse
     */
    public function update_single_post_permalink_path_meta($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') { // 公開中の投稿のみ
            if ($post) { // 存在はするが公開中でないならメタは消す
                 delete_post_meta($post_id, ESP_Config::PERMALINK_PATH_META_KEY);
            }
            return false;
        }

        $permalink = get_permalink($post_id);
        if (!$permalink) {
            delete_post_meta($post_id, ESP_Config::PERMALINK_PATH_META_KEY);
            return false;
        }

        $parsed_url = parse_url($permalink);
        $post_path = isset($parsed_url['path']) ? $parsed_url['path'] : '/';

        // サイトがサブディレクトリにある場合、そのパス部分を除去
        $home_path = parse_url(home_url(), PHP_URL_PATH);
        if ($home_path && $home_path !== '/') {
            $home_path = trailingslashit(untrailingslashit($home_path)); // 先頭と末尾のスラッシュを正規化
            if (strpos($post_path, $home_path) === 0) {
                $post_path = substr($post_path, strlen($home_path));
            }
        }

        // パスを正規化 (先頭と末尾にスラッシュを付与)
        $normalized_path = '/' . trim($post_path, '/') . '/';
        if ($normalized_path === '//') {
            $normalized_path = '/';
        }

        return update_post_meta($post_id, ESP_Config::PERMALINK_PATH_META_KEY, $normalized_path);
    }

    /**
     * 全公開投稿の _esp_permalink_path メタデータを強制的に再生成する (バッチ処理対応版)
     *
     * @param bool $is_ajax AJAX経由での呼び出しかどうか
     * @param int $offset 開始オフセット
     * @param int $limit 1回の処理件数
     * @return array|WP_Error 処理結果 (進捗情報など) またはエラー
     */
    public function force_regenerate_all_permalink_paths_meta_batch($is_ajax = true, $offset = 0, $limit = 50) {
        $args = [
            'post_type'      => 'any',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];
        $post_ids = get_posts($args);

        if (empty($post_ids)) {
            // 全件処理完了
            $this->regenerate_protected_posts_cache(); // 最後にキャッシュを更新
            if ($is_ajax) {
                wp_send_json_success(['status' => 'completed', 'message' => __('全てのパーマリンクパス情報の更新が完了しました。', 'easy-slug-protect'), 'offset' => $offset + count($post_ids)]);
            }
            return ['status' => 'completed', 'processed' => 0, 'total_processed_session' => 0];
        }

        $processed_count = 0;
        foreach ($post_ids as $post_id) {
            $this->update_single_post_permalink_path_meta($post_id);
            $processed_count++;
        }

        $total_published_posts = 0;
        foreach ( get_post_types( [ 'public' => true ], 'names' ) as $post_type ) {
            $counts = wp_count_posts( $post_type );
            $total_published_posts += isset( $counts->publish ) ? (int) $counts->publish : 0;
        }   

        if ($is_ajax) {
            wp_send_json_success([
                'status'    => 'processing',
                'message'   => sprintf(__('%d件の投稿を処理しました。(合計 %d / %d 件処理済み)', 'easy-slug-protect'), $processed_count, $offset + $processed_count, $total_published_posts),
                'offset'    => $offset + $processed_count,
                'processed' => $processed_count,
                'limit'     => $limit,
                'total'     => $total_published_posts
            ]);
        }
        return ['status' => 'processing', 'processed' => $processed_count, 'offset' => $offset + $processed_count, 'total_processed_session' => $processed_count];
    }
    
    /**
     * 全公開投稿のメタデータを再生成する（同期処理用 - 非推奨だが構造変更時などに限定的に使用）
     *
     * @return int 更新された投稿数
     */
    public function force_regenerate_all_permalink_paths_meta_sync() {
        $updated_count = 0;
        $args = [
            'post_type'   => 'any',
            'post_status' => 'publish',
            'numberposts' => -1, // 全件取得
            'fields'      => 'ids',
        ];
        $post_ids = get_posts($args);

        if (!empty($post_ids)) {
            foreach ($post_ids as $post_id) {
                if ($this->update_single_post_permalink_path_meta($post_id)) {
                    $updated_count++;
                }
            }
        }
        $this->regenerate_protected_posts_cache(); // 最後にキャッシュを更新
        return $updated_count;
    }


    /*----- seach filter -----*/ 

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

        // is_home() や is_front_page() も考慮に入れるか要件次第
        if ($query->is_search() || $query->is_archive() || $query->is_tag() || $query->is_feed() || (function_exists('is_sitemap') && is_sitemap())) {
            $excluded_post_ids = $this->get_excluded_post_ids();
            if (!empty($excluded_post_ids)) {
                $current_excluded = $query->get('post__not_in', []);
                if (!is_array($current_excluded)) $current_excluded = []; // 念のため配列化
                $query->set('post__not_in', array_unique(array_merge($current_excluded, $excluded_post_ids)));
            }
        }
    }

    /**
     * 除外すべき投稿IDを取得
     */
    private function get_excluded_post_ids() {
        $cached_data = get_transient(self::CACHE_KEY);
        if ($cached_data === false) {
            // キャッシュがない場合はその場で生成 (ここでの呼び出しはパフォーマンスに影響する可能性あり)
            // 通常は init や save_post で事前に生成されているはず
            error_log('ESP_Filter: Cache miss in get_excluded_post_ids. Regenerating on the fly.');
            $this->regenerate_protected_posts_cache();
            $cached_data = get_transient(self::CACHE_KEY);
        }
        
        return $this->filter_cached_ids($cached_data);
    }

    /**
     * キャッシュされた投稿IDをログイン状態に基づいてフィルタリング
     */
    private function filter_cached_ids($cached_data) {
        if (!is_array($cached_data)) {
            return [];
        }

        $result = [];
        $protected_paths_settings = ESP_Option::get_current_setting('path');
        
        foreach ($cached_data as $path_id => $post_ids_for_path) {
            // path_id が現在の保護設定に存在し、かつそのパスに対して未ログインの場合
            if (isset($protected_paths_settings[$path_id]) && !$this->auth->is_logged_in($protected_paths_settings[$path_id])) {
                $result = array_merge($result, $post_ids_for_path);
            }
        }
        return array_unique($result);
    }

    /*----- REST filter -----*/

    /**
     * REST APIの投稿タイプ一覧クエリをフィルタリングする
     *
     * @param array           $args    WP_Queryの引数配列
     * @param WP_REST_Request $request リクエストオブジェクト
     * @return array 修正されたWP_Queryの引数配列
     */
    public function filter_rest_post_type_query($args, $request) {
        // REST APIリクエストであることを確認 (念のため)
        if (!(defined('REST_REQUEST') && REST_REQUEST)) {
            return $args;
        }

        // 認証済みユーザー（WordPressのログインユーザー）は保護対象外とするか検討
        // if (current_user_can('edit_posts')) { // 例: 編集権限のあるユーザーは全て閲覧可能
        //     return $args;
        // }

        $excluded_post_ids = $this->get_excluded_post_ids(); //

        if (!empty($excluded_post_ids)) {
            $current_excluded = isset($args['post__not_in']) ? (array) $args['post__not_in'] : [];
            $args['post__not_in'] = array_unique(array_merge($current_excluded, $excluded_post_ids));
        }
        return $args;
    }

    /**
     * REST APIで単一の投稿へのアクセスをチェックする
     *
     * @param WP_REST_Response $response レスポンスオブジェクト
     * @param WP_Post          $post     投稿オブジェクト
     * @param WP_REST_Request  $request  リクエストオブジェクト
     * @return WP_REST_Response|WP_Error 変更されたレスポンスまたはWP_Errorオブジェクト
     */
    public function check_rest_single_post_access($response, $post, $request) {
        // REST APIリクエストであることを確認
        if (!(defined('REST_REQUEST') && REST_REQUEST)) {
            return $response;
        }

        // 保存/更新/削除などの非GETは対象外（保存時に引っかからないように）
        $method = $request->get_method();
        if ( ! in_array( $method, ['GET','HEAD'], true ) ) {
            return $response;
        }

        // $responseが既にエラーオブジェクトである場合は、そのまま返す
        // (例: 投稿が見つからない場合など、コントローラーが既にエラーをセットしているケース)
        if (is_wp_error($response)) {
            return $response;
        }
        // WP_REST_Response オブジェクトで、かつエラーがセットされている場合も同様
        if ($response instanceof WP_REST_Response && $response->is_error()) {
             return $response;
        }

        $excluded_post_ids = $this->get_excluded_post_ids(); //

        if (in_array($post->ID, $excluded_post_ids)) {
            // この投稿は保護されており、現在のリクエストではアクセスできない
            // 新しい WP_REST_Response オブジェクトを作成し、エラー情報のみを設定する
            $error_data = [
                'code'    => 'esp_rest_forbidden',
                'message' => __('このコンテンツは保護されています。', ESP_Config::TEXT_DOMAIN), //
                'data'    => ['status' => 403],
            ];
            // 新しいレスポンスオブジェクトをエラーデータとステータスで初期化
            $error_response = new WP_REST_Response($error_data, 403);
            
            return $error_response;
        }

        return $response;
    }



    /*----- control chace ------*/
    
    /**
     * 外部からのキャッシュ更新用
     */
    public function reset_cache(){
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
     * 保護された投稿のキャッシュを再生成 (メタデータ利用版)
     */
    public function regenerate_protected_posts_cache() {
        if (wp_doing_cron() && !defined('ESP_DOING_CRON_INTEGRITY_CHECK')) {
             // 通常のCronジョブ（整合性チェック以外）では、重いキャッシュ再生成をスキップすることも検討
             // return;
        }

        $protected_paths_settings = ESP_Option::get_current_setting('path');
        if (empty($protected_paths_settings)) {
            delete_transient(self::CACHE_KEY);
            // error_log('ESP_Filter: No protected paths found, cache cleared.'); // ログレベル検討
            return;
        }

        $all_published_post_ids = get_posts([
            'post_type'      => 'any',
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'fields'         => 'ids',
        ]);

        if (empty($all_published_post_ids)) {
            delete_transient(self::CACHE_KEY);
            // error_log('ESP_Filter: No published posts found to build cache.');
            return;
        }
        
        // 全公開投稿の _esp_permalink_path メタデータを一括取得 (ただし、投稿数が多いとこれ自体も負荷になる可能性)
        // 投稿数に応じて、get_post_meta をループ内で使うか、ここで一括取得するかを選択。
        // ここではループ内で get_post_meta を使うアプローチを示し、フォールバックもそこで行う。
        
        $all_protected_posts_map = []; // path_id => [post_id, post_id, ...]

        // サイトのサブディレクトリパスを取得（一度だけ）
        $home_url_path_parsed = parse_url(home_url(), PHP_URL_PATH);
        $site_path_prefix = $home_url_path_parsed ? trailingslashit(untrailingslashit($home_url_path_parsed)) : '';
        if ($site_path_prefix === '/') $site_path_prefix = ''; // ルートの場合は空文字に

        foreach ($protected_paths_settings as $path_id => $path_setting) {
            if (empty($path_setting['path'])) continue;

            // 保護対象として設定されたパスを正規化
            $configured_protection_path = '/' . trim($path_setting['path'], '/') . '/';
            if ($configured_protection_path === '//') $configured_protection_path = '/';

            $current_path_protected_ids = [];

            foreach ($all_published_post_ids as $post_id) {
                $post_permalink_meta_path = get_post_meta($post_id, ESP_Config::PERMALINK_PATH_META_KEY, true);

                if (empty($post_permalink_meta_path)) {
                    // メタデータが存在しない場合、その場で生成・保存し、それを使用
                    if ($this->update_single_post_permalink_path_meta($post_id)) {
                        $post_permalink_meta_path = get_post_meta($post_id, ESP_Config::PERMALINK_PATH_META_KEY, true);
                    }
                }

                if (!empty($post_permalink_meta_path)) {
                    // メタデータのパスは既にサイトルート相対＆正規化済みと仮定
                    // (update_single_post_permalink_path_meta でそのように保存しているため)
                    
                    // パスプレフィックスで比較
                    if (strpos($post_permalink_meta_path, $configured_protection_path) === 0) {
                        // 特殊ケースハンドリング: 保護パスがルート('/')の場合
                        if ($configured_protection_path === '/') {
                            // ルート保護の場合、全てのパスが対象になる (strposの挙動通り)
                            // もしフロントページのみを対象としたい場合は、$post_permalink_meta_path === '/' のチェックが必要
                             $current_path_protected_ids[] = $post_id;
                        } else {
                            $current_path_protected_ids[] = $post_id;
                        }
                    }
                }
            }
            if (!empty($current_path_protected_ids)) {
                $all_protected_posts_map[$path_id] = array_unique($current_path_protected_ids);
            }
        }
        set_transient(self::CACHE_KEY, $all_protected_posts_map, self::CACHE_DURATION);
        // error_log('ESP_Filter: Protected posts cache regenerated using meta data. Found ' . count($all_protected_posts_map) . ' protected path groups.');
    }

    /**
     * 定期的な整合性チェックと修正 (WP-Cronから呼び出される)
     * このメソッドはバッチ処理で少しずつ実行されることを想定
     *
     * @param int $offset 開始オフセット
     * @param int $limit 1回の処理件数
     * @return array 処理結果
     */
    /**
     * 定期的な整合性チェックと修正 (WP-Cronから呼び出される)
     * このメソッドはバッチ処理で少しずつ実行される
     */
    public static function cron_check_and_fix_permalink_paths() {
        if(!defined('ESP_DOING_CRON_INTEGRITY_CHECK')) {
            define('ESP_DOING_CRON_INTEGRITY_CHECK', true);
        }

        $instance = new self();
        $option_name = 'esp_integrity_check_progress';
        $progress = get_option($option_name, ['offset' => 0, 'last_run_start' => 0, 'total_fixed_this_session' => 0]);
        
        $offset = isset($progress['offset']) ? absint($progress['offset']) : 0;
        $limit = apply_filters('esp_integrity_check_cron_limit', 100); // 1回の処理件数、フィルターで変更可能に

        // 前回の実行から時間が経ちすぎていたらオフセットをリセットすることも検討 (例: 24時間以上)
        // if ( $progress['last_run_start'] < ( time() - DAY_IN_SECONDS ) ) {
        //     $offset = 0;
        // }

        error_log(sprintf('ESP Cron Integrity Check: Starting batch from offset %d, limit %d.', $offset, $limit));
        update_option($option_name, ['offset' => $offset, 'last_run_start' => time(), 'total_fixed_this_session' => 0]);


        $args = [
            'post_type'      => 'any', // 必要に応じて見直す
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];
        $post_ids = get_posts($args);

        if (empty($post_ids)) {
            // 全件処理完了
            $instance->regenerate_protected_posts_cache();
            error_log('ESP Cron Integrity Check: All posts processed. Total fixed in this session: ' . $progress['total_fixed_this_session']);
            delete_option($option_name); // 完了したら進捗オプションを削除
            return;
        }

        $fixed_count_this_batch = 0;
        $checked_count_this_batch = 0;

        foreach ($post_ids as $post_id) {
            $checked_count_this_batch++;
            $current_meta_value = get_post_meta($post_id, ESP_Config::PERMALINK_PATH_META_KEY, true);
            
            $expected_meta_value = '';
            // update_single_post_permalink_path_meta のロジックを一部利用して期待値を計算
            $post_for_meta = get_post($post_id);
            if ($post_for_meta && $post_for_meta->post_status === 'publish') {
                $permalink = get_permalink($post_id);
                if ($permalink) {
                    $parsed_url = parse_url($permalink);
                    $post_path = isset($parsed_url['path']) ? $parsed_url['path'] : '/';
                    $home_path = parse_url(home_url(), PHP_URL_PATH);
                    if ($home_path && $home_path !== '/') {
                        $home_path = trailingslashit(untrailingslashit($home_path));
                        if (strpos($post_path, $home_path) === 0) {
                            $post_path = substr($post_path, strlen($home_path));
                        }
                    }
                    $expected_meta_value = '/' . trim($post_path, '/') . '/';
                    if ($expected_meta_value === '//') $expected_meta_value = '/';
                }
            }


            if ($current_meta_value !== $expected_meta_value) {
                if (!empty($expected_meta_value)) {
                    update_post_meta($post_id, ESP_Config::PERMALINK_PATH_META_KEY, $expected_meta_value);
                    $fixed_count_this_batch++;
                } elseif (empty($expected_meta_value) && !empty($current_meta_value)) {
                     delete_post_meta($post_id, ESP_Config::PERMALINK_PATH_META_KEY);
                     $fixed_count_this_batch++;
                }
            }
        }
        
        $new_offset = $offset + $checked_count_this_batch;
        $total_fixed_session = (isset($progress['total_fixed_this_session']) ? $progress['total_fixed_this_session'] : 0) + $fixed_count_this_batch;

        if ($fixed_count_this_batch > 0) {
            $instance->regenerate_protected_posts_cache(); // このバッチで修正があった場合のみキャッシュ更新
        }
        
        error_log(sprintf('ESP Cron Integrity Check: Processed %d posts in this batch (fixed %d). Next offset: %d. Total fixed this session: %d.', $checked_count_this_batch, $fixed_count_this_batch, $new_offset, $total_fixed_session));
        
        // 次回の実行のために進捗を保存
        update_option($option_name, ['offset' => $new_offset, 'last_run_start' => $progress['last_run_start'], 'total_fixed_this_session' => $total_fixed_session]);
    }
}