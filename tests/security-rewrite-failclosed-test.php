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
function get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
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

// The version marker might be absent after a deployment. Existing correct
// rules must be accepted without opening a writer lock.
@unlink($file . '.esp.lock');
unset($GLOBALS['options']['esp_rewrite_policy_version']);
chmod($GLOBALS['test_dir'], 0555);
check(ESP_Media_Protection::ensure_rewrite_policy() === true,
    'enabled site with correct Rewrite remains ready in a read-only directory');
check(get_option('esp_rewrite_policy_version') === 'always-route-v2:on',
    'enabled on-disk policy synchronizes an absent version marker');
check(!file_exists($file . '.esp.lock'), 'enabled no-op policy never opens a writer lock');
chmod($GLOBALS['test_dir'], 0700);

$wpdb->count = 1;
$error = $media->update_htaccess(false, true);
check($error instanceof WP_Error && $error->get_error_code() === 'esp_deactivate_protected_media',
    'Deactivation refuses to expose existing protected media');
check(strpos(file_get_contents($file), 'esp-media') !== false,
    'Deactivation leaves protection in place when protected files exist');
$wpdb->count = null;
$wpdb->last_error = 'DB unavailable';
$error = $media->update_htaccess(false, true);
check($error instanceof WP_Error, 'Deactivation fails closed when media count is unknown');
check(strpos(file_get_contents($file), 'esp-media') !== false, 'Unknown DB state never removes routing');
$wpdb->last_error = '';
$wpdb->count = 0;
check($media->update_htaccess(false, true) === true, 'Deactivation can remove Rewrite after verified zero media');
check(file_get_contents($file) === $foreign, 'No protected media leaves other rules intact on deactivation');
$GLOBALS['enabled'] = false;
check($media->update_htaccess() === true, 'explicitly disabled media protection removes rewrite');
check(file_get_contents($file) === $foreign, 'only explicit disable removes ESP block');
check($matchesPolicy->invoke(null, true) === false, 'removed rule cannot pass enabled policy');
check($matchesPolicy->invoke(null, false) === true, 'explicitly disabled policy matches no rule');
check($media->update_htaccess() === true, 'disabled rewrite update is idempotent');

// A disabled, already-unrouted site must not need write permissions merely
// because the new policy-version option has not been initialized.
@unlink($file . '.esp.lock');
unset($GLOBALS['options']['esp_rewrite_policy_version']);
chmod($GLOBALS['test_dir'], 0555);
check(ESP_Media_Protection::ensure_rewrite_policy() === true,
    'disabled site remains ready when a correct Rewrite state is read-only');
check(get_option('esp_rewrite_policy_version') === 'always-route-v2:off',
    'correct on-disk state synchronizes the policy marker');
check(!file_exists($file . '.esp.lock'), 'no lock file is opened for a no-op policy');
chmod($GLOBALS['test_dir'], 0700);

@unlink($file);
@unlink($file . '.esp.lock');
@rmdir($GLOBALS['test_dir']);
echo "All rewrite fail-closed tests passed.\n";
