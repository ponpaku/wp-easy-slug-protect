<?php
/** End-to-end PHP-level regression: no protected paths is a normal state. */
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('REST_REQUEST', true);
require __DIR__ . '/../includes/class-esp-config.php';
require __DIR__ . '/../includes/class-esp-authorization-cache.php';
class ESP_Option {
    public static function get_current_setting($key) {
        return $key === 'path' ? $GLOBALS['options'][ESP_Config::OPTION_KEY]['path'] : [];
    }
}
class WP_REST_Response {
    private $status;
    public function __construct($data = null, $status = 200) { $this->status = $status; }
    public function get_status() { return $this->status; }
}
class WP_REST_Request { public function get_method() { return 'GET'; } }
$GLOBALS['options'] = [
    ESP_Config::OPTION_KEY => ['path' => [], 'media' => ['enabled' => true]]
];
$GLOBALS['transients'] = [];
function get_option($name, $default = null) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$name] = $value; return true; }
function get_transient($name) { return $GLOBALS['transients'][$name] ?? false; }
function set_transient($name, $value, $ttl) { $GLOBALS['transients'][$name] = $value; return true; }
function delete_transient($name) { unset($GLOBALS['transients'][$name]); return true; }
function wp_doing_cron() { return false; }
function is_admin() { return false; }
function is_wp_error($value) { return false; }
function check_zero($okay, $message) {
    if (!$okay) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
require __DIR__ . '/../includes/class-esp-filter.php';
$filter = (new ReflectionClass('ESP_Filter'))->newInstanceWithoutConstructor();
$filter->regenerate_protected_posts_cache(false);
check_zero(ESP_Authorization_Cache::read('post', ESP_Filter::CACHE_KEY) === [],
    'No protected paths generates a valid empty cache');
check_zero($filter->filter_rest_post_type_query([], new WP_REST_Request()) === [],
    'REST list remains available without protected paths');
$result = $filter->check_rest_single_post_access(new WP_REST_Response(), (object) ['ID'=>101], new WP_REST_Request());
check_zero($result->get_status() === 200, 'Single REST post remains available');
check_zero($filter->filter_sitemap_posts_query([], 'post') === [], 'Posts sitemap remains available');
check_zero($filter->filter_sitemap_taxonomies_query([], 'category') === [], 'Terms sitemap remains available');

unset($GLOBALS['options'][ESP_Config::OPTION_KEY]['path']);
$filter->regenerate_protected_posts_cache(false);
check_zero(ESP_Authorization_Cache::read('post', ESP_Filter::CACHE_KEY) === null,
    'Missing path configuration remains unknown, not an empty cache');
check_zero(($filter->filter_rest_post_type_query([], new WP_REST_Request())['post__in'] ?? null) === [0],
    'Invalid configuration still denies REST');
echo "All zero-protection regression checks passed.\n";
