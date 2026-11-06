<?php
/**
 * AVIF media protection regression tests (no WordPress bootstrap required).
 */
define('ABSPATH', __DIR__ . '/');
define('ESP_VERSION', '0.7.39');
define('WEEK_IN_SECONDS', 7 * 24 * 60 * 60);
require __DIR__ . '/../includes/class-esp-config.php';
class ESP_Option {
    public static function get_current_setting($name) { return $name === 'media' ? ['enabled' => true, 'litespeed_key' => 'testkey123'] : []; }
}
class TestWPDB {
    public $postmeta = 'wp_postmeta';
    public $posts = 'wp_posts';
    public $last_error = '';
    public $rows = [];
    public $metadata = [];
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        return ['sql' => $sql, 'args' => $args];
    }
    public function esc_like($value) { return addcslashes($value, '_%\\'); }
    public function get_results($query, $format = null) {
        $this->last_error = '';
        $candidates = array_slice($query['args'], 1);
        $found = [];
        foreach ($this->rows as $id => $path) {
            if (in_array($path, $candidates, true)) {
                $found[] = ['post_id' => (string) $id, 'meta_value' => $path];
            }
        }
        return $found;
    }
    public function get_var($query) {
        if (strpos($query['sql'], 'COUNT(*)') !== false) { return 1; }
        $target = $query['args'][0] ?? '';
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
define('ARRAY_A', 'ARRAY_A');
$wpdb = new TestWPDB();
$GLOBALS['test_upload_root'] = sys_get_temp_dir() . '/esp-avif-' . bin2hex(random_bytes(6));
mkdir($GLOBALS['test_upload_root'] . '/2026/10', 0700, true);
mkdir($GLOBALS['test_upload_root'] . '/2026/11', 0700, true);
function is_admin() { return true; }
function sanitize_text_field($value) { return (string) $value; }
function get_transient($name) { return []; }
function add_filter(...$args) {}
function add_action(...$args) {}
function home_url($path = '') { return 'https://example.test' . $path; }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function wp_upload_dir() { return ['basedir' => $GLOBALS['test_upload_root'], 'baseurl' => 'https://example.test/wp-content/uploads']; }
function add_rewrite_rule($regex, $redirect, $position) { $GLOBALS['rewrites'][] = $regex; }
function wp_get_attachment_metadata($id) { return $GLOBALS['wpdb']->metadata[$id] ?? false; }
function check_avif($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
require __DIR__ . '/../includes/class-esp-media-file-resolver.php';
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
check_avif(strpos($htaccess, 'esp-media=$1 "[B= %&+#?;,L]"') !== false,
    'Apache media rewrite escapes query delimiters without escaping directory slashes');
$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed/6.0';
$htaccess = $apache->invoke($media);
check_avif(strpos($htaccess, 'avif') !== false, 'LiteSpeed rules protect AVIF');
check_avif(strpos($htaccess, 'esp-media=$1 [B,L,QSA]') !== false &&
    strpos($htaccess, '[B=') === false,
    'LiteSpeed media rewrite uses ordinary B instead of unsupported selective B= and retains QSA');
check_avif(strpos($htaccess, '# ESP Rewrite Writer: 0.7.39') !== false,
    'LiteSpeed Rewrite carries the new 0.7.39 writer version');
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
$base = rtrim($GLOBALS['test_upload_root'], '/') . '/';
foreach (['photo.avif', 'photo-150x150.avif', 'other-150x150.avif',
          'photo-320x240.avif', 'nonexistent.avif'] as $name) {
    file_put_contents($base . '2026/10/' . $name, 'AVIF test bytes');
}
file_put_contents($base . '2026/11/photo-150x150.avif', 'AVIF test bytes');
check_avif($lookup->invoke($media, $base . '2026/10/photo.avif') === 123, 'AVIF original resolves to attachment');
check_avif($lookup->invoke($media, $base . '2026/10/photo-150x150.avif') === 123, 'AVIF thumbnail resolves to protected parent');
check_avif($lookup->invoke($media, $base . '2026/10/other-150x150.avif') === 456, 'Converted AVIF thumbnail resolves to original JPEG parent');
check_avif($lookup->invoke($media, $base . '2026/10/photo-320x240.avif') === false, 'Unregistered AVIF thumbnail is not attributed to unrelated media');
check_avif($lookup->invoke($media, $base . '2026/11/photo-150x150.avif') === false, 'AVIF thumbnail cannot cross upload directories');
check_avif($lookup->invoke($media, $base . '2026/10/nonexistent.avif') === false, 'Unregistered AVIF remains unprotected');

foreach (glob($base . '2026/10/*') as $path) { unlink($path); }
unlink($base . '2026/11/photo-150x150.avif');
rmdir($base . '2026/10'); rmdir($base . '2026/11');
rmdir($base . '2026'); rmdir($GLOBALS['test_upload_root']);
