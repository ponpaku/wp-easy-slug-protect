<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 保護されたページをクエリから除外し、パーマリンクパスの整合性を管理するクラス
 */
class ESP_Filter {
    /** @var ESP_Auth 認証インスタンス */
    private $auth;

    /** @var string トランジェントキー */
    const CACHE_KEY = 'esp_protected_posts';

    /** @var int キャッシュ有効期間（秒） */
    const CACHE_DURATION = DAY_IN_SECONDS;

    /** @var int[] メタ更新が保留の投稿ID */
    private $pending_meta_updates = [];

    /** コンストラクタ */
    public function __construct() {
        $this->auth = new ESP_Auth();
    }

    /**
     * フィルタリング機能の初期化
     * - キャッシュ準備
     * - WP_Query / REST 用の各種フック登録
     */
    public function init() {
        // キャッシュ存在チェック。なければ生成
        $this->check_and_generate_cache();

        // メインクエリの除外処理。pre_get_posts は最もコストが低い層
        add_action('pre_get_posts', [$this, 'exclude_protected_posts']);

        // 投稿変更に追随してメタとキャッシュを同期
        add_action('save_post',   [$this, 'handle_save_post'], 10, 2);
        add_action('delete_post', [$this, 'handle_delete_post']);
        add_action('trash_post',  [$this, 'regenerate_protected_posts_cache']);
        add_action('untrash_post',[$this, 'regenerate_protected_posts_cache']);

        // 設定変更に追随
        add_action('update_option_' . ESP_Config::OPTION_KEY, [$this, 'regenerate_protected_posts_cache']);

        // パーマリンク構造変更。実データ全件更新は重いのでここでは軽処理＋推奨ログのみ
        add_action('permalink_structure_changed', [$this, 'handle_permalink_structure_change'], 10, 2);

        // REST API: 一覧引数修正 + 単一取得時のアクセス制御
        $post_types = get_post_types(['public' => true], 'names');
        foreach ($post_types as $post_type) {
            add_filter("rest_{$post_type}_query",     [$this, 'filter_rest_post_type_query'], 10, 2);
            add_filter("rest_prepare_{$post_type}",  [$this, 'check_rest_single_post_access'], 10, 3);
        }

        // サイトマップでも除外を適用
        add_filter('wp_sitemaps_posts_query_args', [$this, 'filter_sitemap_posts_query'], 10, 2);
        add_filter('wp_sitemaps_taxonomies_query_args', [$this, 'filter_sitemap_taxonomies_query'], 10, 2);
    }

    /*----- handler ------*/

    /**
     * 投稿保存時の処理
     * - 自動保存/リビジョンは除外
     * - 公開中のみメタ更新。それ以外はメタ削除
     * - キャッシュ再生成
     * @param int     $post_id
     * @param WP_Post $post
     */
    public function handle_save_post($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return; // 自動生成系は対象外
        }

        if ($post->post_status !== 'publish') {
            delete_post_meta($post_id, ESP_Config::PERMALINK_PATH_META_KEY); // 非公開はメタ不要
        } else {
            $this->update_single_post_permalink_path_meta($post_id); // 公開のみ更新
        }

        $this->regenerate_protected_posts_cache(); // 全体の整合性を確保
    }

    /**
     * 投稿削除時の処理
     * - メタはWPが削除するためキャッシュのみ再生成
     */
    public function handle_delete_post($post_id) {
        $this->regenerate_protected_posts_cache();
    }

    /**
     * パーマリンク構造変更時の処理
     * - 全件更新は管理画面のバッチ（AJAX）を推奨
     * - ここでは軽い通知とキャッシュ再生成のみ
     * @param string|null $old_structure
     * @param string|null $new_structure
     */
    public function handle_permalink_structure_change($old_structure = null, $new_structure = null) {
        // error_log('ESP: Permalink structure changed. Manual regeneration of permalink path meta data is recommended.');
        $this->force_regenerate_all_permalink_paths_meta_sync();
        $this->regenerate_protected_posts_cache();
    }

    /*- ajax handler -*/

    /**
     * AJAX: 全投稿のパーマリンクパスをバッチ再生成
     * - 進捗は offset/limit で管理
     */
    public function ajax_regenerate_permalink_paths_batch() {
        check_ajax_referer('esp_regenerate_permalinks_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('権限がありません。', 'easy-slug-protect')], 403);
        }

        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $limit  = isset($_POST['limit'])  ? absint($_POST['limit'])  : 50; // 1回あたりの処理件数

        // 内部で wp_send_json_*/wp_die を呼ぶ
        $this->force_regenerate_all_permalink_paths_meta_batch(true, $offset, $limit);
    }

    /**
     * AJAX: 保護キャッシュをクリアして即時再生成
     */
    public function ajax_clear_protection_cache() {
        check_ajax_referer('esp_clear_cache_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('権限がありません。', 'easy-slug-protect')], 403);
        }

        delete_transient(self::CACHE_KEY);
        $this->regenerate_protected_posts_cache();

        wp_send_json_success(['message' => __('保護キャッシュをクリアしました。', 'easy-slug-protect')]);
    }


    /*----- control post_meta -----*/

    /**
     * 単一投稿の _esp_permalink_path メタデータを更新
     * - 計算不可時はメタ削除
     * @param int $post_id
     * @return bool 更新成否
     */
    public function update_single_post_permalink_path_meta($post_id) {
        $normalized_path = self::compute_post_permalink_path((int) $post_id);

        if ($normalized_path === '') {
            delete_post_meta($post_id, ESP_Config::PERMALINK_PATH_META_KEY); // 無効値は削除
            return false;
        }

        return (bool) update_post_meta($post_id, ESP_Config::PERMALINK_PATH_META_KEY, $normalized_path);
    }

    /**
     * 全公開投稿のメタを強制再生成（バッチ）
     * - 大量投稿時のタイムアウト回避用
     * @param bool $is_ajax
     * @param int  $offset
     * @param int  $limit
     * @return array|WP_Error
     */
    public function force_regenerate_all_permalink_paths_meta_batch($is_ajax = true, $offset = 0, $limit = 50) {
        $args = [
            'post_type'      => 'any',
            'post_status'    => 'publish',
            'posts_per_page' => (int) $limit,
            'offset'         => (int) $offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];
        $post_ids = get_posts($args);

        if (empty($post_ids)) {
            // 全件処理完了
            $this->regenerate_protected_posts_cache();
            if ($is_ajax) {
                wp_send_json_success([
                    'status'  => 'completed',
                    'message' => __('全てのパーマリンクパス情報の更新が完了しました。', 'easy-slug-protect'),
                    'offset'  => $offset,
                ]);
            }
            return ['status' => 'completed', 'processed' => 0, 'total_processed_session' => 0];
        }

        $processed_count = 0;
        foreach ($post_ids as $pid) {
            $this->update_single_post_permalink_path_meta($pid);
            $processed_count++;
        }

        // 進捗総数の概算（公開投稿タイプ合算）
        $total_published_posts = 0;
        foreach (get_post_types(['public' => true], 'names') as $post_type) {
            $counts = wp_count_posts($post_type);
            $total_published_posts += isset($counts->publish) ? (int) $counts->publish : 0;
        }

        if ($is_ajax) {
            wp_send_json_success([
                'status'    => 'processing',
                'message'   => sprintf(__('%d件の投稿を処理しました。(合計 %d / %d 件処理済み)', 'easy-slug-protect'), $processed_count, $offset + $processed_count, $total_published_posts),
                'offset'    => $offset + $processed_count,
                'processed' => $processed_count,
                'limit'     => $limit,
                'total'     => $total_published_posts,
            ]);
        }

        return [
            'status'                  => 'processing',
            'processed'               => $processed_count,
            'offset'                  => $offset + $processed_count,
            'total_processed_session' => $processed_count,
        ];
    }

    /**
     * 全公開投稿のメタを同期再生成（小規模向け）
     * @return int 更新件数
     */
    public function force_regenerate_all_permalink_paths_meta_sync() {
        $updated_count = 0;
        $args = [
            'post_type'   => 'any',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
        ];
        $post_ids = get_posts($args);

        if (!empty($post_ids)) {
            foreach ($post_ids as $pid) {
                if ($this->update_single_post_permalink_path_meta($pid)) {
                    $updated_count++;
                }
            }
        }

        $this->regenerate_protected_posts_cache();
        return $updated_count;
    }


    /*----- search filter -----*/

    /**
     * 保護対象をメインクエリから除外
     * - 管理画面/REST/サブクエリは対象外
     * - 検索・アーカイブ・タグ・フィード・サイトマップで適用
     * @param WP_Query $query
     */
    public function exclude_protected_posts($query) {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return; // 管理/REST は除外対象外
        }
        if (!$query->is_main_query()) {
            return; // メインクエリのみ対象
        }

        if ($query->is_search() || $query->is_archive() || $query->is_tag() || $query->is_feed() || (function_exists('is_sitemap') && is_sitemap())) {
            $excluded_post_ids = $this->get_excluded_post_ids();
            if (!empty($excluded_post_ids)) {
                $current_excluded = $query->get('post__not_in', []);
                if (!is_array($current_excluded)) {
                    $current_excluded = [];
                }
                $query->set('post__not_in', array_unique(array_merge($current_excluded, $excluded_post_ids)));
            }
        }
    }

    /**
     * 除外すべき投稿IDを取得
     * - トランジェント未命中時はその場再生成（性能注意）
     * @return int[]
     */
    private function get_excluded_post_ids() {
        $cached_data = get_transient(self::CACHE_KEY);
        if ($cached_data === false) {
            // error_log('ESP_Filter: Cache miss in get_excluded_post_ids. Regenerating on the fly.');
            $this->regenerate_protected_posts_cache(); // 次回以降のために構築
            $cached_data = get_transient(self::CACHE_KEY);
        }

        return $this->filter_cached_ids($cached_data);
    }

    /**
     * キャッシュをログイン状態に基づいてフィルタ
     * - 認証通過していない保護パス配下の投稿IDのみ抽出
     * @param array $cached_data [path_id => post_id[]]
     * @return int[] post ID list
     */
    private function filter_cached_ids($cached_data) {
        if (!is_array($cached_data)) {
            return [];
        }

        $result = [];
        $protected_paths_settings = ESP_Option::get_current_setting('path');
        if (!is_array($protected_paths_settings) || empty($protected_paths_settings)) {
            return [];
        }

        foreach ($cached_data as $path_id => $post_ids_for_path) {
            // 対応する保護パス設定が存在し、現状未ログインなら除外候補に加える
            if (isset($protected_paths_settings[$path_id]) && !$this->auth->is_logged_in($protected_paths_settings[$path_id])) {
                $result = array_merge($result, (array) $post_ids_for_path);
            }
        }
        return array_values(array_unique(array_map('intval', $result)));
    }

    /*----- REST filter -----*/

    /**
     * REST: 投稿タイプ一覧クエリをフィルタ
     * - post__not_in に除外IDをマージ
     * @param array           $args
     * @param WP_REST_Request $request
     * @return array
     */
    public function filter_rest_post_type_query($args, $request) {
        if (!(defined('REST_REQUEST') && REST_REQUEST)) {
            return (array) $args; // 念のためキャスト
        }

        $excluded_post_ids = $this->get_excluded_post_ids();
        if (!empty($excluded_post_ids)) {
            $current_excluded     = isset($args['post__not_in']) ? (array) $args['post__not_in'] : [];
            $args['post__not_in'] = array_unique(array_merge($current_excluded, $excluded_post_ids));
        }
        return $args;
    }

    /**
     * サイトマップ用クエリの除外処理
     * - post__not_in に除外IDをマージ
     * @param array  $args
     * @param string $post_type
     * @return array
     */
    public function filter_sitemap_posts_query($args, $post_type) {
        $args = (array) $args;

        $excluded_post_ids = $this->get_excluded_post_ids();
        if (!empty($excluded_post_ids)) {
            // 既存指定がある場合でも配列化して統合
            $current_excluded     = isset($args['post__not_in']) ? (array) $args['post__not_in'] : [];
            $args['post__not_in'] = array_unique(array_merge($current_excluded, $excluded_post_ids));
        }

        return $args;
    }

    /**
     * サイトマップ用タクソノミークエリの除外処理
     * - exclude に保護対象投稿が紐づくタームを追加
     * @param array  $args
     * @param string $taxonomy
     * @return array
     */
    public function filter_sitemap_taxonomies_query($args, $taxonomy) {
        $args = (array) $args;

        $excluded_post_ids = $this->get_excluded_post_ids();
        if (empty($excluded_post_ids)) {
            return $args; // 除外対象がなければ処理不要
        }

        $taxonomy = (string) $taxonomy;
        if ($taxonomy === '') {
            return $args; // タクソノミー未指定なら何もしない
        }

        $excluded_term_ids = wp_get_object_terms($excluded_post_ids, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($excluded_term_ids) || empty($excluded_term_ids)) {
            return $args; // 紐づくタームが無ければ終了
        }

        $excluded_term_ids = array_values(array_unique(array_map('intval', $excluded_term_ids)));

        $taxonomy_object = get_taxonomy($taxonomy);
        if ($taxonomy_object && !empty($taxonomy_object->object_type)) {
            $object_types = (array) $taxonomy_object->object_type; // 紐づく投稿タイプを利用
        } else {
            $object_types = get_post_types(['public' => true], 'names'); // 定義が無ければ公開投稿タイプにフォールバック
            if (empty($object_types)) {
                $object_types = 'any'; // さらに無ければ any を指定
            }
        }

        $post_statuses = ['publish'];
        if ($object_types === 'any' || (is_array($object_types) && in_array('attachment', $object_types, true))) {
            $post_statuses[] = 'inherit'; // 添付ファイルの公開判定を可能にする
        }

        $terms_to_exclude = [];
        foreach ($excluded_term_ids as $term_id) {
            $has_public_posts = get_posts([
                'post_type'           => $object_types,
                'post_status'         => $post_statuses,
                'posts_per_page'      => 1,
                'fields'              => 'ids',
                'no_found_rows'       => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'post__not_in'        => $excluded_post_ids,
                'tax_query'           => [[
                    'taxonomy'         => $taxonomy,
                    'terms'            => $term_id,
                    'include_children' => false,
                ]],
            ]);

            if (!empty($has_public_posts)) {
                continue; // 公開投稿が残っているタームはサイトマップに保持
            }

            $terms_to_exclude[] = $term_id; // 公開投稿が無いタームのみ除外対象へ
        }

        if (empty($terms_to_exclude)) {
            return $args; // 追加除外が無ければ既存設定を維持
        }

        $current_excluded = isset($args['exclude']) ? wp_parse_id_list($args['exclude']) : [];
        $args['exclude']  = array_values(array_unique(array_merge($current_excluded, $terms_to_exclude)));

        return $args;
    }

    /**
     * REST: 単一取得のアクセス制御
     * - 未認証で保護対象なら 403 を返す
     * @param WP_REST_Response|WP_Error $response
     * @param WP_Post                   $post
     * @param WP_REST_Request           $request
     * @return WP_REST_Response|WP_Error
     */
    public function check_rest_single_post_access($response, $post, $request) {
        if (!(defined('REST_REQUEST') && REST_REQUEST)) {
            return $response; // REST以外はそのまま
        }

        $method = $request->get_method();
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return $response; // 取得系のみ対象
        }

        // 既存のエラー応答は尊重
        if (is_wp_error($response)) {
            return $response;
        }
        if ($response instanceof WP_REST_Response) {
            $status = (int) $response->get_status();
            if ($status >= 400) {
                return $response;
            }
        }

        $excluded_post_ids = $this->get_excluded_post_ids();
        if (in_array((int) $post->ID, $excluded_post_ids, true)) {
            $error_data = [
                'code'    => 'esp_rest_forbidden',
                'message' => __('このコンテンツは保護されています。', ESP_Config::TEXT_DOMAIN),
                'data'    => ['status' => 403],
            ];
            return new WP_REST_Response($error_data, 403);
        }

        return $response;
    }



    /*----- control cache ------*/

    /** 外部からのキャッシュ更新用 */
    public function reset_cache() {
        $this->regenerate_protected_posts_cache();
    }

    /**
     * キャッシュの存在確認と生成
     * - 初回アクセス時のコールドスタートを吸収
     */
    private function check_and_generate_cache() {
        $cached_ids = get_transient(self::CACHE_KEY);
        if ($cached_ids === false) {
            $this->regenerate_protected_posts_cache();
        }
    }

    /**
     * 保護された投稿のキャッシュを再生成（バッチ）
     * - 各保護パスにマッチする投稿IDを集計
     * - メタ欠落は遅延生成キューに積む
     * - メモリ使用量を監視して安全に中断
     */
    public function regenerate_protected_posts_cache() {
        if (wp_doing_cron() && !defined('ESP_DOING_CRON_INTEGRITY_CHECK')) {
            return; // 通常の Cron ではスキップ
        }

        $protected_paths_settings = ESP_Option::get_current_setting('path');
        if (empty($protected_paths_settings) || !is_array($protected_paths_settings)) {
            delete_transient(self::CACHE_KEY); // 設定が空ならキャッシュ不要
            return;
        }

        $all_protected_posts_map = [];
        $batch_size = 500; // 取得単位
        $offset = 0;

        // メモリ監視（閾値 80%）
        $memory_limit     = $this->get_memory_limit();
        $memory_threshold = $memory_limit * 0.8;

        // 設定パスを事前正規化して比較を高速化
        $normalized_settings = [];
        foreach ($protected_paths_settings as $path_id => $path_setting) {
            if (!empty($path_setting['path'])) {
                $normalized_settings[$path_id] = self::normalize_path($path_setting['path']);
            }
        }

        while (true) {
            if (memory_get_usage(true) > $memory_threshold) {
                // error_log('ESP_Filter: Memory usage high, stopping batch processing');
                break; // 多量サイトでの安全装置
            }

            // 投稿IDをチャンク取得
            $post_ids = get_posts([
                'post_type'      => 'any',
                'post_status'    => 'publish',
                'posts_per_page' => $batch_size,
                'offset'         => $offset,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);

            if (empty($post_ids)) {
                break; // 末尾
            }

            // メタを一括取得（N+1 回避）
            $meta_data = $this->get_post_meta_batch($post_ids, ESP_Config::PERMALINK_PATH_META_KEY);

            // 各保護パスと突き合わせ（前方一致）
            foreach ($normalized_settings as $path_id => $configured_protection_path) {
                $current_path_protected_ids = [];

                foreach ($post_ids as $pid) {
                    $post_meta_path = isset($meta_data[$pid]) ? $meta_data[$pid] : '';

                    if ($post_meta_path === '' || $post_meta_path === null) {
                        $this->mark_for_meta_update($pid); // 欠落は後で生成
                        continue;
                    }

                    // 先頭一致で保護パス配下を判定
                    if (strpos($post_meta_path, $configured_protection_path) === 0) {
                        $current_path_protected_ids[] = (int) $pid;
                    }
                }

                if (!empty($current_path_protected_ids)) {
                    if (!isset($all_protected_posts_map[$path_id])) {
                        $all_protected_posts_map[$path_id] = [];
                    }
                    $all_protected_posts_map[$path_id] = array_merge($all_protected_posts_map[$path_id], $current_path_protected_ids);
                }
            }

            $offset += $batch_size;

            // キャッシュ系のメモリを開放（オブジェクトキャッシュ環境では効果に差）
            wp_cache_flush();
            unset($post_ids, $meta_data);
        }

        // 遅延メタ更新を処理
        $this->process_pending_meta_updates();

        // 重複除去し配列を整形
        foreach ($all_protected_posts_map as $path_id => &$ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
        }
        unset($ids);

        set_transient(self::CACHE_KEY, $all_protected_posts_map, self::CACHE_DURATION);
    }

    /**
     * 投稿メタをバッチ取得
     * - IN 句はプレースホルダでエスケープ
     * @param int[]  $post_ids
     * @param string $meta_key
     * @return array post_id => meta_value
     */
    private function get_post_meta_batch($post_ids, $meta_key) {
        global $wpdb;

        if (empty($post_ids)) {
            return [];
        }

        $post_ids     = array_map('intval', (array) $post_ids);
        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));

        $query = $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) AND meta_key = %s",
            array_merge($post_ids, [$meta_key])
        );

        $results = $wpdb->get_results($query, ARRAY_A);

        $meta_data = [];
        foreach ((array) $results as $row) {
            $meta_data[(int) $row['post_id']] = $row['meta_value'];
        }

        return $meta_data;
    }

    /**
     * メモリ制限値（バイト）を取得
     * - 例: 128M, 1G 等を数値化
     * @return int
     */
    private function get_memory_limit() {
        $memory_limit = ini_get('memory_limit');

        if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
            $value = (int) $matches[1];
            switch (strtoupper($matches[2])) {
                case 'G':
                    $value *= 1024;
                case 'M':
                    $value *= 1024;
                case 'K':
                    $value *= 1024;
            }
            return $value;
        }

        return 128 * 1024 * 1024; // デフォ 128MB
    }

    /** メタ更新が必要な投稿をマーク */
    private function mark_for_meta_update($post_id) {
        $this->pending_meta_updates[] = (int) $post_id;
    }

    /**
     * 保留中のメタ更新を処理
     * - 小分けに処理して瞬間負荷を抑制
     */
    private function process_pending_meta_updates() {
        if (empty($this->pending_meta_updates)) {
            return;
        }

        $batch_size = 50;
        $chunks = array_chunk($this->pending_meta_updates, $batch_size);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $pid) {
                $this->update_single_post_permalink_path_meta($pid);
            }
            if (count($chunks) > 1) {
                usleep(100000); // 0.1秒スリープ
            }
        }

        $this->pending_meta_updates = [];
    }

    /**
     * WP-Cron: パス整合性チェックと修正
     * - 途中経過はオプションに保存し次回に継続
     */
    public static function cron_check_and_fix_permalink_paths() {
        if (!defined('ESP_DOING_CRON_INTEGRITY_CHECK')) {
            define('ESP_DOING_CRON_INTEGRITY_CHECK', true);
        }

        $instance    = new self();
        $option_name = 'esp_integrity_check_progress';
        $progress    = get_option($option_name, ['offset' => 0, 'last_run_start' => 0, 'total_fixed_this_session' => 0]);

        $offset = isset($progress['offset']) ? absint($progress['offset']) : 0;
        $limit  = apply_filters('esp_integrity_check_cron_limit', 100);

        // error_log(sprintf('ESP Cron Integrity Check: Starting batch from offset %d, limit %d.', $offset, $limit));
        update_option($option_name, ['offset' => $offset, 'last_run_start' => time(), 'total_fixed_this_session' => (int) $progress['total_fixed_this_session']]);

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
            // 全件処理済み
            $instance->regenerate_protected_posts_cache();
            // error_log('ESP Cron Integrity Check: All posts processed. Total fixed in this session: ' . (int) $progress['total_fixed_this_session']);
            delete_option($option_name);
            return;
        }

        $fixed_count_this_batch   = 0;
        $checked_count_this_batch = 0;

        foreach ($post_ids as $pid) {
            $checked_count_this_batch++;

            $current_meta_value  = get_post_meta($pid, ESP_Config::PERMALINK_PATH_META_KEY, true);
            $expected_meta_value = self::compute_post_permalink_path((int) $pid); // 期待値を再計算

            if ($current_meta_value !== $expected_meta_value) {
                if ($expected_meta_value !== '') {
                    update_post_meta($pid, ESP_Config::PERMALINK_PATH_META_KEY, $expected_meta_value);
                    $fixed_count_this_batch++;
                } elseif ($current_meta_value) {
                    delete_post_meta($pid, ESP_Config::PERMALINK_PATH_META_KEY);
                    $fixed_count_this_batch++;
                }
            }
        }

        $new_offset          = $offset + $checked_count_this_batch;
        $total_fixed_session = (isset($progress['total_fixed_this_session']) ? (int) $progress['total_fixed_this_session'] : 0) + $fixed_count_this_batch;

        if ($fixed_count_this_batch > 0) {
            $instance->regenerate_protected_posts_cache(); // 差分があった場合のみ再生成
        }

        // error_log(sprintf('ESP Cron Integrity Check: Processed %d posts in this batch (fixed %d). Next offset: %d. Total fixed this session: %d.', $checked_count_this_batch, $fixed_count_this_batch, $new_offset, $total_fixed_session));

        update_option($option_name, [
            'offset'                  => $new_offset,
            'last_run_start'          => $progress['last_run_start'],
            'total_fixed_this_session'=> $total_fixed_session,
        ]);
    }

    /* ----- Helper: Permalink Path ----- */

    /**
     * 投稿IDから正規化済みパーマリンクパスを計算
     * - 公開投稿のみ対象
     * - サブディレクトリ設置時はホームパスを除去
     * @param int $post_id
     * @return string 正規化パス（例: "/foo/bar/"）。取得不可時は空文字
     */
    private static function compute_post_permalink_path($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return '';
        }

        $permalink = get_permalink($post_id);
        if (!$permalink) {
            return '';
        }

        $parsed_url = parse_url($permalink);
        $post_path  = isset($parsed_url['path']) ? $parsed_url['path'] : '/';

        // サブディレクトリ設置時: 先頭のホームパスを取り除く
        $home_path = parse_url(home_url(), PHP_URL_PATH);
        if ($home_path && $home_path !== '/') {
            $home_path = trailingslashit(untrailingslashit($home_path));
            if (strpos($post_path, $home_path) === 0) {
                $post_path = substr($post_path, strlen($home_path));
            }
        }

        return self::normalize_path($post_path);
    }

    /**
     * パスを正規化
     * - 先頭・末尾にスラッシュを付与
     * - 空や "//" は "/" に丸める
     * @param string $path
     * @return string
     */
    private static function normalize_path($path) {
        $normalized_path = '/' . trim((string) $path, '/') . '/';
        return ($normalized_path === '//' ? '/' : $normalized_path);
    }
}
