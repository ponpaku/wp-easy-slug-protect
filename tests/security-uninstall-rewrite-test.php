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

// 検証異常後の復元関数を直接試験する：自分の置換だけ安全に戻す。
$installed = "# Other plugin\\r\\nRewriteRule ^test$ index.php [L]\\r\\n";
$previous = "# Original third-party bytes\\r\\nRewriteEngine On\\r\\n";
$backup = $GLOBALS['test_dir'] . '/.htaccess.esp-restore-test';
file_put_contents($htaccess, $installed);
file_put_contents($backup, $previous);
chmod($backup, 0600);
check_uninstall(esp_uninstall_restore_if_unchanged($htaccess, $backup, $installed, 0640),
    'uninstall restores previous bytes when installed file is unchanged');
check_uninstall(file_get_contents($htaccess) === $previous &&
    (fileperms($htaccess) & 0777) === 0640,
    'uninstall rollback preserves old bytes and permissions');
file_put_contents($htaccess, '# External change\\n');
file_put_contents($backup, $previous);
chmod($backup, 0600);
check_uninstall(!esp_uninstall_restore_if_unchanged($htaccess, $backup, $installed, 0640),
    'uninstall never overwrites a concurrent external change');
check_uninstall(file_get_contents($htaccess) === '# External change\\n' &&
    file_get_contents($backup) === $previous,
    'backup remains available when safe restoration is impossible');
unlink($backup);

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
