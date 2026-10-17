<?php
/** Uninstall must not destroy third-party .htaccess bytes. */
define('ABSPATH', __DIR__ . '/../');
define('WP_UNINSTALL_PLUGIN', true);

$GLOBALS['test_dir'] = sys_get_temp_dir() . '/esp-uninstall-' . bin2hex(random_bytes(6));
mkdir($GLOBALS['test_dir'], 0700);
$htaccess = $GLOBALS['test_dir'] . '/.htaccess';
$foreign = "# Another plugin\r\nRewriteEngine On\r\nRewriteRule ^other$ index.php [L]\r\n";
$esp = "# BEGIN ESP Media Protection\nRewriteRule ^protected$ index.php?esp-media=x [L]\n# END ESP Media Protection\n";
$initial_invalid = "# BEGIN ESP Media Protection\n# END ESP Media Protection\n# END ESP Media Protection\n" . $foreign;
file_put_contents($htaccess, $initial_invalid);
chmod($htaccess, 0640);

class UninstallTestDB {
    public $prefix = 'wp_';
    public $postmeta = 'wp_postmeta';
    public $queries = [];
    public $meta_rows = [
        ['meta_key' => '_esp_media_protected_path_id', 'post_id' => 123],
        ['meta_key' => '_another_plugin_meta', 'post_id' => 123],
    ];
    public function query($query) { $this->queries[] = $query; return 1; }
    public function delete($table, $args) {
        if ($table !== $this->postmeta || !isset($args['meta_key'])) {
            return 0;
        }
        $before = count($this->meta_rows);
        $this->meta_rows = array_values(array_filter(
            $this->meta_rows,
            static function ($row) use ($args) { return $row['meta_key'] !== $args['meta_key']; }
        ));
        return $before - count($this->meta_rows);
    }
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
// 標準アンインストールは無効化済みプラグインの保護クラスをロードしない。
check_uninstall(!class_exists('ESP_Media_Protection', false),
    'uninstall starts without loading ESP_Media_Protection');
// WordPressから削除を指示されたら、Rewrite保全に失敗しても削除処理は止めない。
require __DIR__ . '/../uninstall.php';
check_uninstall(!class_exists('ESP_Media_Protection', false),
    'uninstall cleanup does not depend on the media class being loaded');
check_uninstall($wpdb->meta_rows === [['meta_key' => '_another_plugin_meta', 'post_id' => 123]],
    'uninstall removes protected-media postmeta and preserves unrelated postmeta');
check_uninstall(file_get_contents($htaccess) === $initial_invalid,
    'uninstall leaves ambiguous third-party/ESP markers untouched');
check_uninstall(count($wpdb->queries) === 4,
    'DB cleanup continues even if Rewrite cleanup needs manual action');

// 正常なマーカーでの部分除去は独立して確認する。
file_put_contents($htaccess, $esp . $foreign);
check_uninstall(esp_uninstall_remove_rewrite_safely() === true,
    'uninstall safely removes its own valid Rewrite block');
check_uninstall(file_get_contents($htaccess) === $foreign,
    'uninstall removes only ESP block, preserving foreign rules byte for byte');
check_uninstall((fileperms($htaccess) & 0777) === 0640,
    'uninstall preserves file permissions');
check_uninstall(glob($GLOBALS['test_dir'] . '/.htaccess.esp-*') === [],
    'temporary and backup files are removed after successful Rewrite cleanup');

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
