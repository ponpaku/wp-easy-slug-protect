<?php
/** REST・一覧の認可キャッシュが読めない場合、公開せず拒否することを検証する。 */
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('REST_REQUEST', true);
require __DIR__ . '/../includes/class-esp-config.php';
class ESP_Option {
    public static function get_current_setting($key) {
        return $key === 'path' ? ['path_test' => ['path' => '/members']] : ['enabled' => true];
    }
}
class WP_REST_Response {
    private $status;
    public function __construct($data = null, $status = 200) { $this->status = $status; }
    public function get_status() { return $this->status; }
    public function is_error() { return $this->status >= 400; }
}
class WP_REST_Request {
    public function get_method() { return 'GET'; }
}
function is_admin() { return false; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? null; /* Initially unreadable. */ }
function delete_transient($key) { return true; }
function current_user_can($capability) { return !empty($GLOBALS['can_upload']) && $capability === 'upload_files'; }
function get_post_meta($post_id, $key, $single = false) {
    return $GLOBALS['live_media_meta'][$post_id] ?? '';
}
function is_wp_error($value) { return false; }
function get_option($name, $default = null) {
    if ($name === ESP_Config::OPTION_KEY) {
        return ['path' => ['test' => ['path' => '/protected']], 'media' => ['enabled' => true]];
    }
    return $default;
}
function wp_doing_cron() { return true; }
class BrokenDB {
    public $postmeta = 'wp_postmeta';
    public $last_error = 'Database unavailable';
    public $live_online = false;
    public $last_post_id = 0;
    public function prepare($sql, ...$args) {
        if (strpos($sql, 'SELECT meta_value FROM') !== false) {
            $this->last_post_id = (int)($args[0] ?? 0);
        }
        return $sql;
    }
    public function get_results($query) { return null; }
    public function get_var($query) {
        $this->last_error = $this->live_online ? '' : 'Database unavailable';
        return $this->live_online
            ? ($GLOBALS['live_media_meta'][$this->last_post_id] ?? null)
            : null;
    }
}
$wpdb = new BrokenDB();
function __($msg, $domain = null) { return $msg; }
function check($ok, $desc) {
    if (!$ok) { fwrite(STDERR, "FAIL: $desc\n"); exit(1); }
    echo "PASS: $desc\n";
}
require __DIR__ . '/../includes/class-esp-authorization-cache.php';
require __DIR__ . '/../includes/class-esp-filter.php';
require __DIR__ . '/../includes/class-esp-media-protection.php';

$filter = (new ReflectionClass('ESP_Filter'))->newInstanceWithoutConstructor();
$request = new WP_REST_Request();
$args = $filter->filter_rest_post_type_query([], $request);
check(($args['post__in'] ?? null) === [0], 'REST post collection is empty on unknown authorization cache');
$args = $filter->filter_sitemap_posts_query([], 'post');
check(($args['post__in'] ?? null) === [0], 'post sitemap does not publish unknown protected content');
$args = $filter->filter_sitemap_taxonomies_query([], 'category');
check(($args['include'] ?? null) === [0], 'term sitemap is conservative on cache failure');
$response = $filter->check_rest_single_post_access(new WP_REST_Response(), (object) ['ID' => 1], $request);
check($response->get_status() === 503, 'REST single post returns 503 on unknown cache');

$media = (new ReflectionClass('ESP_Media_Protection'))->newInstanceWithoutConstructor();
$args = $media->filter_rest_media_query([], $request);
check(($args['post__in'] ?? null) === [0], 'REST media collection is empty on unknown cache');
$response = $media->check_rest_media_access(new WP_REST_Response(), (object) ['ID' => 1], $request);
check($response->get_status() === 503, 'REST single media returns 503 on unknown cache');

// upload_filesを持つサイト編集者・管理者のREST例外は従来互換とし、
// 変更しない。新しい一律認可ポリシーは#43に分離する。
$GLOBALS['can_upload'] = true;
$args = $media->filter_rest_media_query([], $request);
check($args === [], 'existing upload_files capability preserves REST media list');
$response = $media->check_rest_media_access(new WP_REST_Response(), (object) ['ID' => 11], $request);
check($response->get_status() === 200, 'existing upload_files capability preserves REST media single item');

$GLOBALS['can_upload'] = false;
// 認可キャッシュ検査を通るケースではREST最終DB照会を成功させる。
$wpdb->live_online = true;
$authProperty = (new ReflectionClass('ESP_Media_Protection'))->getProperty('auth');
$authProperty->setValue($media, new class {
    public function is_logged_in($path) { return false; }
});
$GLOBALS['transients'][ESP_Media_Protection::MEDIA_CACHE_KEY] = [
    'signature' => ESP_Authorization_Cache::signature('media'),
    'value' => ['path_test' => [11]],
];
$args = $media->filter_rest_media_query([], $request);
check(($args['post__not_in'] ?? null) === [11], 'unprivileged media list excludes protected media');
$response = $media->check_rest_media_access(new WP_REST_Response(), (object) ['ID' => 11], $request);
check($response->get_status() === 403, 'unprivileged media single item requires ESP authorization');
// WriterがDB更新前のepoch E1で組み直された古い一覧を公開した場合にも、
// rest_prepare_attachment の各要素で新規保護メタを直接確認する。
$GLOBALS['transients'][ESP_Media_Protection::MEDIA_CACHE_KEY] = [
    'signature' => ESP_Authorization_Cache::signature('media'),
    'value' => [], // stale scan omitted newly protected media 99
];
$GLOBALS['live_media_meta'][99] = 'path_test';
$response = $media->check_rest_media_access(new WP_REST_Response(), (object) ['ID' => 99], $request);
check($response->get_status() === 403,
    'live media metadata prevents REST response after stale generation omitted new protected item');
unset($GLOBALS['live_media_meta'][99]);
$wpdb->live_online = false;
$response = $media->check_rest_media_access(new WP_REST_Response(), (object) ['ID' => 99], $request);
check($response->get_status() === 503,
    'REST revalidation refuses publication when the live metadata query fails');
echo "All cache fail-closed tests passed.\n";
