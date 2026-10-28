<?php
/** Issue #63: disposable MySQL integration test for the opt-in production index. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
require dirname(__DIR__) . '/includes/class-esp-media-index.php';

function esp63_assert($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
final class ESP63_DB {
    public $prefix = 'esp63_';
    public $posts = 'esp63_posts';
    public $postmeta = 'esp63_postmeta';
    public $last_error = '';
    public $dbh;
    private $db;
    public function __construct($db) { $this->db = $db; $this->dbh = $db; }
    public function esc_like($str) { return addcslashes($str, '_%\\'); }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $index = 0;
        $ret = preg_replace_callback('/%[ds]/', function ($m) use ($args, &$index) {
            if (!array_key_exists($index, $args)) { throw new RuntimeException('argument missing'); }
            $arg = $args[$index++];
            return $m[0] === '%d' ? (string)(int)$arg :
                "'" . $this->db->real_escape_string((string)$arg) . "'";
        }, $sql);
        if ($index !== count($args)) { throw new RuntimeException('argument overflow'); }
        return $ret;
    }
    public function query($sql) {
        $this->last_error = '';
        try { return $this->db->query($sql); }
        catch (Throwable $e) { $this->last_error = $e->getMessage(); return false; }
    }
    public function get_results($sql, $mode = ARRAY_A) {
        $res = $this->query($sql);
        return $res instanceof mysqli_result ? $res->fetch_all(MYSQLI_ASSOC) : null;
    }
    public function get_row($sql, $mode = ARRAY_A) {
        $rows = $this->get_results($sql, $mode);
        return is_array($rows) && $rows ? $rows[0] : null;
    }
    public function get_var($sql) {
        $row = $this->get_row($sql);
        return is_array($row) ? reset($row) : null;
    }
    public function get_col($sql) {
        $rows = $this->get_results($sql);
        return is_array($rows) ? array_map('reset', $rows) : null;
    }
}
$db = new mysqli(
    getenv('ESP_TEST_DB_HOST') ?: '127.0.0.1',
    getenv('ESP_TEST_DB_USER') ?: 'root',
    getenv('ESP_TEST_DB_PASSWORD') ?: '',
    getenv('ESP_TEST_DB_NAME') ?: 'esp_test'
);
$db->set_charset('utf8mb4');
$db->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
$wpdb = new ESP63_DB($db);
$GLOBALS['wpdb'] = $wpdb;
$options = [
    ESP_Media_Index::SCHEMA_OPTION => '1',
    ESP_Media_Index::READY_OPTION => '1',
    ESP_Media_Index::ENABLE_OPTION => '1',
];
function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default;
}
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$name] = $value; return true; }
function maybe_unserialize($raw) {
    $data = @unserialize($raw, ['allowed_classes' => false]);
    return $data === false && $raw !== 'b:0;' ? $raw : $data;
}
function get_post_type($id) {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT post_type FROM {$wpdb->posts} WHERE ID=%d", $id));
}
function get_metadata_by_mid($type, $id) { return false; }
$uploads = sys_get_temp_dir() . '/esp63-prod-' . getmypid();
@mkdir($uploads . '/2026/10', 0700, true);
$GLOBALS['uploads'] = $uploads;
function wp_upload_dir() { return ['basedir' => $GLOBALS['uploads'], 'error' => false]; }
function esp63_sql($sql) {
    global $wpdb;
    if ($wpdb->query($sql) === false) {
        throw new RuntimeException($wpdb->last_error . ' query: ' . $sql);
    }
}
$files = ESP_Media_Index::files_table();
$state = ESP_Media_Index::state_table();
try {
    foreach ([$files,$state,'esp63_postmeta','esp63_posts'] as $table) {
        esp63_sql("DROP TABLE IF EXISTS $table");
    }
    esp63_sql("CREATE TABLE esp63_posts (ID BIGINT UNSIGNED PRIMARY KEY,
        post_type VARCHAR(20) NOT NULL) ENGINE=InnoDB");
    esp63_sql("CREATE TABLE esp63_postmeta (
        meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        post_id BIGINT UNSIGNED NOT NULL, meta_key VARCHAR(255), meta_value LONGTEXT,
        KEY post_id(post_id),KEY meta_key(meta_key(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    esp63_sql("CREATE TABLE $files (
        path_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        file_path TEXT NOT NULL, attachment_id BIGINT UNSIGNED NOT NULL,
        protected_path_id VARCHAR(191) NOT NULL DEFAULT '',
        PRIMARY KEY(path_hash,attachment_id),
        KEY attachment_id(attachment_id),KEY protected_path_id(protected_path_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    esp63_sql("CREATE TABLE $state (
        attachment_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        status VARCHAR(16) NOT NULL DEFAULT 'dirty',
        revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
        pending_count INT UNSIGNED NOT NULL DEFAULT 0,
        changed_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        KEY status (status)
    ) ENGINE=InnoDB");
    $metadata = serialize(['file'=>'2026/10/cat.jpg',
        'sizes'=>['thumb'=>['file'=>'cat-150x150.jpg']],
        'original_image'=>'cat-original.jpg']);
    $escmeta = $db->real_escape_string($metadata);
    esp63_sql("INSERT INTO esp63_posts (ID,post_type) VALUES (10,'attachment')");
    esp63_sql("INSERT INTO esp63_postmeta (post_id,meta_key,meta_value) VALUES
        (10,'_wp_attached_file','2026/10/cat.jpg'),
        (10,'_wp_attachment_metadata','$escmeta'),
        (10,'_esp_media_protected_path_id','secret_A')");
    foreach (['cat.jpg','cat-150x150.jpg','cat-original.jpg'] as $file) {
        file_put_contents($uploads . '/2026/10/' . $file, 'fixture');
    }
    esp63_assert(ESP_Media_Index::reindex_attachment(10), 'initial attachment index');
    $lookup = ESP_Media_Index::resolve($uploads . '/2026/10/cat-150x150.jpg');
    esp63_assert($lookup['status'] === 'resolved' &&
        $lookup['attachment_id'] === 10 && $lookup['protected_path_id'] === 'secret_A',
        'one lookup returns attachment and protection');
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat-original.jpg')['status']
        === 'resolved', 'original_image belongs to attachment');
    $meta = ESP_Media_Index::enumerate('2026/10/document.pdf',
        ['sizes' => ['full' => ['file' => 'document-pdf.jpg']]]);
    esp63_assert($meta === ['2026/10/document.pdf','2026/10/document-pdf.jpg'],
        'PDF metadata without file');
    esp63_assert(ESP_Media_Index::enumerate('2026/10/cat.jpg',
        ['sizes'=>[['file'=>'../secret.jpg']]]) === null, 'unsafe metadata rejected');

    // The pre-update guard fences old public/protected data before WP writes.
    esp63_assert(ESP_Media_Index::guard_meta(null,10,
        '_esp_media_protected_path_id','secret_B') === null, 'pre-update fence stored');
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg')['status']
        === 'error', 'dirty attachment never resolves');
    esp63_sql("UPDATE esp63_postmeta SET meta_value='secret_B'
        WHERE post_id=10 AND meta_key='_esp_media_protected_path_id'");
    ESP_Media_Index::on_meta_saved(20,10,'_esp_media_protected_path_id','secret_B');
    $changed = ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg');
    esp63_assert($changed['status'] === 'resolved' &&
        $changed['protected_path_id'] === 'secret_B', 'post-update protection visible');

    // Duplicate owners, including protected + public, are ambiguous.
    esp63_sql("INSERT INTO esp63_posts (ID,post_type) VALUES (11,'attachment')");
    esp63_sql("INSERT INTO esp63_postmeta(post_id,meta_key,meta_value) VALUES
        (11,'_wp_attached_file','2026/10/cat-150x150.jpg')");
    esp63_assert(ESP_Media_Index::reindex_attachment(11), 'second attachment index');
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat-150x150.jpg')['status']
        === 'ambiguous', 'overlapping owners cannot silently publish');
    ESP_Media_Index::on_attachment_deleting(11);
    esp63_sql('DELETE FROM esp63_postmeta WHERE post_id=11');
    esp63_sql('DELETE FROM esp63_posts WHERE ID=11');
    ESP_Media_Index::on_post_deleted(11);
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat-150x150.jpg')['status']
        === 'resolved', 'deleted overlapping owner removed');

    $options[ESP_Media_Index::READY_OPTION] = '0';
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg')['status']
        === 'error', 'incomplete index cannot grant access');
    $options[ESP_Media_Index::READY_OPTION] = '1';

    // Invalid metadata must leave the prior row non-authoritative.
    esp63_assert(ESP_Media_Index::guard_meta(null,10,
        '_wp_attached_file','../outside.jpg') === null, 'metadata write guarded');
    esp63_sql("UPDATE esp63_postmeta SET meta_value='../outside.jpg'
        WHERE post_id=10 AND meta_key='_wp_attached_file'");
    ESP_Media_Index::on_meta_saved(21,10,'_wp_attached_file','../outside.jpg');
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg')['status']
        === 'error', 'failed rebuild leaves old index invalid');
    // Explicit restoration followed by a fresh, guarded reindex.
    esp63_sql("UPDATE esp63_postmeta SET meta_value='2026/10/cat.jpg'
        WHERE post_id=10 AND meta_key='_wp_attached_file'");
    esp63_assert(ESP_Media_Index::reindex_attachment(10), 'recovery from broken metadata');
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg')['status']
        === 'resolved', 'restored index resolves');

    file_put_contents($uploads . '/2026/10/unknown.jpg', 'unregistered');
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/unknown.jpg')['status']
        === 'unresolved', 'unregistered is not automatically known-public');
    echo "Issue 63 production index MySQL tests passed.\n";
} finally {
    foreach (glob($uploads . '/2026/10/*') as $file) { @unlink($file); }
    @rmdir($uploads . '/2026/10'); @rmdir($uploads . '/2026'); @rmdir($uploads);
    foreach ([$files,$state,'esp63_postmeta','esp63_posts'] as $table) {
        $db->query("DROP TABLE IF EXISTS $table");
    }
    $db->close();
}
