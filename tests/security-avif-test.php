<?php
/**
 * AVIF media protection regression tests (no WordPress bootstrap required).
 */
define('ABSPATH', __DIR__ . '/');
define('ESP_VERSION', '0.7.38');
class ESP_Config { const TEXT_DOMAIN = 'easy-slug-protect'; }
class ESP_Option {
    public static function get_current_setting($name) { return $name === 'media' ? ['enabled' => true, 'litespeed_key' => 'testkey123'] : []; }
}
class TestWPDB {
    public $postmeta = 'wp_postmeta';
    public $rows = [];
    public $metadata = [];
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        return ['sql' => $sql, 'args' => $args];
    }
    public function get_var($query) {
        if (strpos($query['sql'], 'COUNT(*)') !== false) { return 1; }
        $target = $query['args'][1] ?? '';
        foreach ($this->rows as $id => $path) { if ($path === $target) { return $id; } }
        return null;
    }
    public function get_col($query) {
        $matches = [];
        foreach ($this->rows as $id => $path) {
            if (in_array($path, array_slice($query['args'], 1), true)) { $matches[] = $id; }
        }
        return $matches;
    }
}
$wpdb = new TestWPDB();
function is_admin() { return true; }
function get_transient($name) { return []; }
function add_filter(...$args) {}
function add_action(...$args) {}
function home_url($path = '') { return 'https://example.test' . $path; }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function wp_upload_dir() { return ['basedir' => '/var/www/wp-content/uploads', 'baseurl' => 'https://example.test/wp-content/uploads']; }
function add_rewrite_rule($regex, $redirect, $position) { $GLOBALS['rewrites'][] = $regex; }
function wp_get_attachment_metadata($id) { return $GLOBALS['wpdb']->metadata[$id] ?? false; }
function check_avif($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
require __DIR__ . '/../includes/class-esp-media-protection.php';
require __DIR__ . '/../includes/class-esp-media-deriver.php';

$media_class = new ReflectionClass('ESP_Media_Protection');
$media = $media_class->newInstanceWithoutConstructor();
$enabled = $media_class->getProperty('enabled');
$enabled->setAccessible(true);
$enabled->setValue($media, true);

$media->add_rewrite_rules();
check_avif(count($GLOBALS['rewrites']) === 1 && strpos($GLOBALS['rewrites'][0], 'avif') !== false, 'WordPress rewrite protects AVIF');

$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
$apache = $media_class->getMethod('get_htaccess_rules');
$apache->setAccessible(true);
$htaccess = $apache->invoke($media);
check_avif(strpos($htaccess, 'avif') !== false && strpos($htaccess, 'webp') !== false, 'Apache/LiteSpeed rules protect AVIF and existing formats');
$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed/6.0';
$htaccess = $apache->invoke($media);
check_avif(strpos($htaccess, 'avif') !== false, 'LiteSpeed rules protect AVIF');
$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.27';
$nginx = ESP_Media_Protection::generate_nginx_rules_for_admin();
check_avif(is_array($nginx) && strpos($nginx['rules'], 'avif') !== false, 'Nginx rules protect AVIF');

$deriver = new ReflectionClass('ESP_Media_Deriver');
$inline = $deriver->getMethod('should_inline');
$inline->setAccessible(true);
check_avif($inline->invoke($deriver->newInstanceWithoutConstructor(), 'image/avif'), 'PHP media delivery displays AVIF inline');

$lookup = $media_class->getMethod('get_attachment_id_from_path');
$lookup->setAccessible(true);
$wpdb->rows = [123 => '2026/10/photo.avif', 456 => '2026/10/other.jpg'];
$wpdb->metadata = [
    123 => ['file' => '2026/10/photo.avif', 'sizes' => ['thumbnail' => ['file' => 'photo-150x150.avif']]],
    456 => ['file' => '2026/10/other.jpg', 'sizes' => ['thumbnail' => ['file' => 'other-150x150.avif']]],
];
$base = '/var/www/wp-content/uploads/';
check_avif($lookup->invoke($media, $base . '2026/10/photo.avif') === 123, 'AVIF original resolves to attachment');
check_avif($lookup->invoke($media, $base . '2026/10/photo-150x150.avif') === 123, 'AVIF thumbnail resolves to protected parent');
check_avif($lookup->invoke($media, $base . '2026/10/other-150x150.avif') === 456, 'Converted AVIF thumbnail resolves to original JPEG parent');
check_avif($lookup->invoke($media, $base . '2026/10/photo-320x240.avif') === false, 'Unregistered AVIF thumbnail is not attributed to unrelated media');
check_avif($lookup->invoke($media, $base . '2026/11/photo-150x150.avif') === false, 'AVIF thumbnail cannot cross upload directories');
check_avif($lookup->invoke($media, $base . '2026/10/nonexistent.avif') === false, 'Unregistered AVIF remains unprotected');
