<?php
/** Isolated .htaccess failure and atomic replacement tests. */
define('ABSPATH', __DIR__ . '/');
define('WEEK_IN_SECONDS', 604800);
require __DIR__ . '/../includes/class-esp-config.php';
class ESP_Option {
    public static function get_current_setting($name) { return ['enabled' => $GLOBALS['enabled'] ?? true, 'litespeed_key' => 'key123']; }
}
class WP_Error {
    private $code;
    public function __construct($code, $message) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class RewriteTestDB {
    public $postmeta = 'wp_postmeta';
    public $last_error = '';
    public $count = 1;
    public function prepare($sql, ...$args) { return $sql; }
    public function get_var($query) { return $this->count; }
}
function __($value, $domain = null) { return $value; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function home_url($path = '') { return 'https://example.test' . $path; }
function wp_upload_dir() { return ['basedir' => $GLOBALS['test_dir'], 'error' => '']; }
function trailingslashit($path) { return rtrim($path, '/') . '/'; }
function check($ok, $description) {
    if (!$ok) { fwrite(STDERR, "FAIL: $description\n"); exit(1); }
    echo "PASS: $description\n";
}
require __DIR__ . '/../includes/class-esp-media-protection.php';
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
$wpdb = new RewriteTestDB();
$GLOBALS['test_dir'] = sys_get_temp_dir() . '/esp-p0-' . bin2hex(random_bytes(6));
mkdir($GLOBALS['test_dir'], 0700);
$file = $GLOBALS['test_dir'] . '/.htaccess';
$foreign = "# Other plugin rule\nRewriteEngine On\n";
$old = "# BEGIN ESP Media Protection\nRewriteRule ^secret private\n# END ESP Media Protection\n" . $foreign;
file_put_contents($file, $old);
$reflection = new ReflectionClass('ESP_Media_Protection');
$media = $reflection->newInstanceWithoutConstructor();
$matchesPolicy = $reflection->getMethod('rewrite_file_matches_policy');
check($matchesPolicy->invoke(null, true) === false, 'incomplete legacy rewrite cannot satisfy policy marker');

$wpdb->count = null;
$wpdb->last_error = 'DB query failed';
check($media->update_htaccess() === true, 'Rewrite policy does not depend on an unavailable COUNT');
check(strpos(file_get_contents($file), 'esp-media') !== false, 'Rewrite stays installed when DB count is unknown');
check($matchesPolicy->invoke(null, true) === true, 'installed rewrite is verified on disk');

$wpdb->count = '';
$wpdb->last_error = '';
check($media->update_htaccess() === true, 'Rewrite policy also ignores invalid COUNT results');
$wpdb->count = 1;
$lockHandle = fopen($file . '.esp.lock', 'c');
flock($lockHandle, LOCK_EX);
$error = $media->update_htaccess();
check($error instanceof WP_Error && $error->get_error_code() === 'esp_htaccess_locked', 'competing writer cannot replace rewrite');
check(strpos(file_get_contents($file), 'esp-media') !== false, 'lock contention preserves existing file');
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
check($media->update_htaccess() === true, 'known protected media updates rewrite');
$new = file_get_contents($file);
check(strpos($new, 'esp-media') !== false && strpos($new, '# Other plugin rule') !== false, 'generated rewrite keeps unrelated rules');
check(strpos($new, 'avif') !== false, 'AVIF is covered in replacement');

$wpdb->count = 0;
check($media->update_htaccess() === true, 'zero protected records still install rewrite');
check(strpos(file_get_contents($file), 'esp-media') !== false, 'zero-to-one transition already covered by rewrite');
$GLOBALS['enabled'] = false;
check($media->update_htaccess() === true, 'explicitly disabled media protection removes rewrite');
check(file_get_contents($file) === $foreign, 'only explicit disable removes ESP block');
check($matchesPolicy->invoke(null, true) === false, 'removed rule cannot pass enabled policy');
check($matchesPolicy->invoke(null, false) === true, 'explicitly disabled policy matches no rule');
check($media->update_htaccess() === true, 'disabled rewrite update is idempotent');

@unlink($file);
@unlink($file . '.esp.lock');
@rmdir($GLOBALS['test_dir']);
echo "All rewrite fail-closed tests passed.\n";
