<?php
/** Uninstall must not destroy third-party .htaccess bytes. */
define('ABSPATH', __DIR__ . '/../');
define('WP_UNINSTALL_PLUGIN', true);

$GLOBALS['test_dir'] = sys_get_temp_dir() . '/esp-uninstall-' . bin2hex(random_bytes(6));
mkdir($GLOBALS['test_dir'], 0700);
$htaccess = $GLOBALS['test_dir'] . '/.htaccess';
$foreign = "# Another plugin\r\nRewriteEngine On\r\nRewriteRule ^other$ index.php [L]\r\n";
$esp = "# BEGIN ESP Media Protection\nRewriteRule ^protected$ index.php?esp-media=x [L]\n# END ESP Media Protection\n";
file_put_contents($htaccess, $esp . $foreign);
chmod($htaccess, 0640);

class UninstallTestDB {
    public $prefix = 'wp_';
    public $postmeta = 'wp_postmeta';
    public $queries = [];
    public function query($query) { $this->queries[] = $query; return 1; }
    public function delete($table, $args) { return 1; }
}
$wpdb = new UninstallTestDB();
function plugin_dir_path($file) { return dirname($file) . '/'; }
function wp_upload_dir() { return ['basedir' => $GLOBALS['test_dir'], 'error' => '']; }
function delete_option($key) { return true; }
function delete_transient($key) { return true; }
function wp_clear_scheduled_hook($key) { return true; }
function check_uninstall($value, $message) {
    if (!$value) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
require __DIR__ . '/../uninstall.php';
check_uninstall(file_get_contents($htaccess) === $foreign,
    'uninstall removes only ESP block, preserving foreign rules byte for byte');
check_uninstall((fileperms($htaccess) & 0777) === 0640,
    'uninstall preserves file permissions');
check_uninstall(count($wpdb->queries) === 4, 'DB cleanup continues only after safe Rewrite removal');
check_uninstall(glob($GLOBALS['test_dir'] . '/.htaccess.esp-*') === [],
    'temporary and backup files are removed after successful uninstall');

$ambiguous = "# BEGIN ESP Media Protection\n# END ESP Media Protection\n# END ESP Media Protection\n" . $foreign;
file_put_contents($htaccess, $ambiguous);
check_uninstall(esp_uninstall_remove_rewrite_safely() === false,
    'invalid markers are rejected on uninstall');
check_uninstall(file_get_contents($htaccess) === $ambiguous,
    'invalid file is never overwritten');

$lock = fopen($htaccess . '.esp.lock', 'c');
flock($lock, LOCK_EX);
check_uninstall(esp_uninstall_remove_rewrite_safely() === false,
    'uninstall respects a concurrent Rewrite update');
check_uninstall(file_get_contents($htaccess) === $ambiguous,
    'lock contention cannot mutate htaccess');
flock($lock, LOCK_UN);
fclose($lock);
unlink($htaccess);
unlink($htaccess . '.esp.lock');
rmdir($GLOBALS['test_dir']);
echo "All uninstall rewrite safety tests passed.\n";
