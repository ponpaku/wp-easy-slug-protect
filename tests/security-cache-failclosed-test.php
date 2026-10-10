<?php
/** REST/listing authorization must fail closed on unreadable caches. */
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
function get_transient($key) { return null; /* Corrupt or unreadable cache. */ }
function delete_transient($key) { return true; }
function current_user_can($capability) { return false; }
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
    public function prepare($sql, ...$args) { return $sql; }
    public function get_results($query) { return null; }
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
echo "All cache fail-closed tests passed.\n";
