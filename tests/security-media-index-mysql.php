<?php
/** Issue #63: disposable MySQL integration test for the opt-in production index. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('ESP_VERSION', 'test');
class ESP_Protection_State { const RUNTIME_STATE_OPTION = 'esp_test_runtime'; }
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
    public $fail_state_write = false;
    public $fail_cleanup = false;
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
        if ($this->fail_state_write &&
            strpos($sql, 'INSERT INTO esp63_esp_media_file_state_v1') !== false) {
            $this->last_error = 'injected index state failure';
            return false;
        }
        if ($this->fail_cleanup &&
            strpos($sql, 'DELETE FROM esp63_esp_media_files_v1') !== false &&
            strpos($sql, 'generation<>') !== false) {
            $this->last_error = 'injected post-publication cleanup failure';
            return false;
        }
        try {
            $result = $this->db->query($sql);
            return $result instanceof mysqli_result ? $result : $this->db->affected_rows;
        }
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
    ESP_Media_Index::SCHEMA_OPTION => '2',
    ESP_Protection_State::RUNTIME_STATE_OPTION => ['state'=>'READY', 'version'=>ESP_VERSION],
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
function get_metadata_by_mid($type, $id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_id=%d",
        $id), ARRAY_A);
    return $row ? (object)$row : false;
}
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
        generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        protected_path_id VARCHAR(191) NOT NULL DEFAULT '',
        PRIMARY KEY(path_hash,attachment_id,generation),
        KEY attachment_id(attachment_id),KEY protected_path_id(protected_path_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    esp63_sql("CREATE TABLE $state (
        attachment_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        status VARCHAR(16) NOT NULL DEFAULT 'dirty',
        revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
        active_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        pending_count INT UNSIGNED NOT NULL DEFAULT 0,
        changed_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        retry_after BIGINT UNSIGNED NOT NULL DEFAULT 0,
        failure_count INT UNSIGNED NOT NULL DEFAULT 0,
        KEY status (status),
        KEY retry_queue (status,pending_count,retry_after,changed_at)
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
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg')['status']
        === 'error', 'post-hook leaves index dirty until independent worker');
    $wpdb->fail_cleanup = true; // Old-generation rows will remain.
    esp63_assert(ESP_Media_Index::reindex_attachment(10),
        'independent reindex after committed metadata update');
    $wpdb->fail_cleanup = false;
    $old_generations = (int)$wpdb->get_var("SELECT COUNT(*) FROM $files WHERE attachment_id=10");
    esp63_assert($old_generations >= 6,
        'failed cleanup retains previous and new generation rows');
    $changed = ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg');
    esp63_assert($changed['status'] === 'resolved' &&
        $changed['protected_path_id'] === 'secret_B', 'post-update protection visible');

    // Even a direct-SQL protection change that bypasses hooks must NOT allow
    // an outdated materialized public/protected ID to authorize delivery.
    esp63_sql("UPDATE esp63_postmeta SET meta_value='secret_C'
        WHERE post_id=10 AND meta_key='_esp_media_protected_path_id'");
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg')['status']
        === 'error', 'live postmeta disagreement blocks stale protection ID');
    esp63_sql("UPDATE esp63_postmeta SET meta_value='secret_B'
        WHERE post_id=10 AND meta_key='_esp_media_protected_path_id'");
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg')['status']
        === 'resolved', 'restored protection metadata agrees with index');

    // Deterministic two-writer interleaving: the first writer's completion
    // cannot publish while another WordPress metadata mutation is pending.
    esp63_assert(ESP_Media_Index::guard_meta(null, 10,
        '_esp_media_protected_path_id', 'writer_A') === null,
        'first mutation marks pending');
    esp63_assert(ESP_Media_Index::guard_meta(null, 10,
        '_esp_media_protected_path_id', 'writer_B') === null,
        'second mutation increments pending');
    esp63_sql("UPDATE esp63_postmeta SET meta_value='writer_A'
        WHERE post_id=10 AND meta_key='_esp_media_protected_path_id'");
    ESP_Media_Index::on_meta_saved(30, 10, '_esp_media_protected_path_id', 'writer_A');
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg')['status']
        === 'error', 'first completion cannot publish with second pending');
    esp63_sql("UPDATE esp63_postmeta SET meta_value='writer_B'
        WHERE post_id=10 AND meta_key='_esp_media_protected_path_id'");
    ESP_Media_Index::on_meta_saved(31, 10, '_esp_media_protected_path_id', 'writer_B');
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg')['status']
        === 'error', 'second post-hook still does not publish');
    esp63_assert(ESP_Media_Index::reindex_attachment(10),
        'worker publishes only after both writers complete');
    $settled = ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg');
    esp63_assert($settled['status'] === 'resolved' &&
        $settled['protected_path_id'] === 'writer_B',
        'last completion publishes latest metadata');

    // Duplicate owners, including protected + public, are ambiguous.
    esp63_sql("INSERT INTO esp63_posts (ID,post_type) VALUES (11,'attachment')");
    esp63_sql("INSERT INTO esp63_postmeta(post_id,meta_key,meta_value) VALUES
        (11,'_wp_attached_file','2026/10/cat-150x150.jpg')");
    esp63_assert(ESP_Media_Index::reindex_attachment(11), 'second attachment index');
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat-150x150.jpg')['status']
        === 'ambiguous', 'overlapping owners cannot silently publish');
    // Generation staging: a dirty protected competing owner must remain a
    // blocker while new-generation rows are partly written. Deleting old
    // rows first would make the ready/public owner look unique here.
    esp63_assert(ESP_Media_Index::guard_meta(null, 11,
        '_wp_attached_file', '2026/10/cat-150x150.jpg') === null,
        'pre-fence competing owner');
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat-150x150.jpg')['status']
        === 'error', 'old generation still blocks during rebuild');
    $stage_generation = (int)$wpdb->get_var("SELECT revision FROM $state WHERE attachment_id=11");
    esp63_sql("INSERT INTO $files (path_hash,file_path,attachment_id,generation,protected_path_id)
        VALUES ('" . hash('sha256', '2026/10/cat-150x150.jpg') . "',
                '2026/10/cat-150x150.jpg',11,$stage_generation,'')");
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat-150x150.jpg')['status']
        === 'error', 'staged new generation cannot hide dirty owner');
    esp63_sql("DELETE FROM $files WHERE attachment_id=11 AND generation=$stage_generation");
    ESP_Media_Index::on_meta_saved(33,11,'_wp_attached_file','2026/10/cat-150x150.jpg');
    esp63_assert(ESP_Media_Index::reindex_attachment(11),
        'publish competing owner only after full rebuild');
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat-150x150.jpg')['status']
        === 'ambiguous', 'published competing owners remain ambiguous');
    ESP_Media_Index::on_attachment_deleting(11);
    esp63_sql('DELETE FROM esp63_postmeta WHERE post_id=11');
    esp63_sql('DELETE FROM esp63_posts WHERE ID=11');
    ESP_Media_Index::on_post_deleted(11);
    esp63_assert(ESP_Media_Index::reindex_attachment(11), 'worker removes deleted attachment');
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

    // R02: never start/commit a nested transaction. The dirty marker and
    // WordPress metadata share the caller's transaction and roll back together.
    esp63_sql("CREATE TABLE esp63_external_txn (id INT PRIMARY KEY) ENGINE=InnoDB");
    $peer = new mysqli(
        getenv('ESP_TEST_DB_HOST') ?: '127.0.0.1',
        getenv('ESP_TEST_DB_USER') ?: 'root',
        getenv('ESP_TEST_DB_PASSWORD') ?: '',
        getenv('ESP_TEST_DB_NAME') ?: 'esp_test'
    );
    $peer->set_charset('utf8mb4');
    esp63_sql('START TRANSACTION');
    esp63_sql('INSERT INTO esp63_external_txn VALUES (1)');
    esp63_assert(ESP_Media_Index::guard_meta(null,10,
        '_esp_media_protected_path_id','txn_rollback') === null,
        'external transaction: pre-hook participates without COMMIT');
    esp63_sql("UPDATE esp63_postmeta SET meta_value='txn_rollback'
        WHERE post_id=10 AND meta_key='_esp_media_protected_path_id'");
    ESP_Media_Index::on_meta_saved(101,10,'_esp_media_protected_path_id','txn_rollback');
    $peer_state = $peer->query("SELECT status FROM $state WHERE attachment_id=10")->fetch_assoc();
    $peer_protection = $peer->query("SELECT meta_value FROM esp63_postmeta
        WHERE post_id=10 AND meta_key='_esp_media_protected_path_id'")->fetch_assoc();
    esp63_assert($peer_state['status'] === 'ready' &&
        $peer_protection['meta_value'] === 'writer_B',
        'separate DB connection sees old committed state until transaction ends');
    esp63_assert(ESP_Media_Index::reindex_attachment(10),
        'manual rebuild does not COMMIT the caller transaction');
    esp63_sql('ROLLBACK');
    esp63_assert((int)$wpdb->get_var('SELECT COUNT(*) FROM esp63_external_txn') === 0,
        'external INSERT was not implicitly committed');
    $rolled = ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg');
    esp63_assert($rolled['status'] === 'resolved' &&
        $rolled['protected_path_id'] === 'writer_B',
        'rolled back metadata and dirty marker together');
    esp63_sql('START TRANSACTION');
    esp63_sql('INSERT INTO esp63_external_txn VALUES (2)');
    esp63_assert(ESP_Media_Index::guard_meta(null,10,
        '_esp_media_protected_path_id','txn_committed') === null,
        'external transaction second pre-hook');
    esp63_sql("UPDATE esp63_postmeta SET meta_value='txn_committed'
        WHERE post_id=10 AND meta_key='_esp_media_protected_path_id'");
    ESP_Media_Index::on_meta_saved(102,10,'_esp_media_protected_path_id','txn_committed');
    esp63_sql('COMMIT');
    $peer_committed = $peer->query("SELECT status FROM $state
        WHERE attachment_id=10")->fetch_assoc();
    esp63_assert($peer_committed['status'] === 'dirty',
        'another DB connection sees dirty state atomically after COMMIT');
    esp63_assert((int)$wpdb->get_var('SELECT COUNT(*) FROM esp63_external_txn') === 1,
        'external COMMIT controlled by caller');
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg')['status']
        === 'error', 'committed change is dirty before worker runs');
    esp63_assert(ESP_Media_Index::reindex_attachment(10), 'worker indexes committed transaction');
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg')['protected_path_id']
        === 'txn_committed', 'worker published committed protection');
    $peer->close();

    // R03: fail only state-table writes, not WordPress postmeta or option
    // writes. Bypass hooks in compatibility mode only after global invalidation.
    $mid = (int)$wpdb->get_var("SELECT meta_id FROM esp63_postmeta WHERE post_id=10
        AND meta_key='_wp_attached_file'");
    $options[ESP_Media_Index::ENABLE_OPTION] = '0';
    $options[ESP_Media_Index::READY_OPTION] = '1';
    $wpdb->fail_state_write = true;
    esp63_assert(ESP_Media_Index::guard_by_mid(null, $mid, '2026/10/new.jpg') === null,
        'by-mid failure admitted only after compatibility invalidation');
    esp63_assert($options[ESP_Media_Index::READY_OPTION] === '0',
        'failed by-mid pre-fence revokes global READY');
    $options[ESP_Media_Index::READY_OPTION] = '1';
    esp63_assert(ESP_Media_Index::guard_delete_by_mid(null, $mid) === null &&
        $options[ESP_Media_Index::READY_OPTION] === '0',
        'failed by-mid delete revokes READY');
    $options[ESP_Media_Index::READY_OPTION] = '1';
    ESP_Media_Index::on_attachment_deleting(10);
    esp63_assert($options[ESP_Media_Index::READY_OPTION] === '0',
        'failed attachment deletion revokes READY');
    $options[ESP_Media_Index::ENABLE_OPTION] = '1';
    $options[ESP_Media_Index::READY_OPTION] = '1';
    esp63_assert(ESP_Media_Index::guard_by_mid(null, $mid, '2026/10/new.jpg') === false &&
        $options[ESP_Media_Index::READY_OPTION] === '0',
        'strict mode rejects by-mid update on pre-fence failure');
    $wpdb->fail_state_write = false;
    $options[ESP_Media_Index::READY_OPTION] = '1';
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/cat.jpg')['status']
        === 'resolved', 'correct mapping remains usable after fault reset');

    // F01: state-table write failure during attachment deletion. The
    // global READY invalidation must not become reversible until the stale
    // public owner and all its files have been removed by the orphan audit.
    esp63_sql("INSERT INTO esp63_posts (ID,post_type) VALUES (30,'attachment')");
    esp63_sql("INSERT INTO esp63_postmeta(post_id,meta_key,meta_value) VALUES
        (30,'_wp_attached_file','2026/10/reused.jpg')");
    file_put_contents($uploads . '/2026/10/reused.jpg', 'reused-file');
    esp63_assert(ESP_Media_Index::reindex_attachment(30), 'F01 public owner indexed');
    $options[ESP_Media_Index::ENABLE_OPTION] = '0';
    $options[ESP_Media_Index::READY_OPTION] = '1';
    $wpdb->fail_state_write = true;
    ESP_Media_Index::on_attachment_deleting(30);
    esp63_assert($options[ESP_Media_Index::READY_OPTION] === '0',
        'F01 global invalidated when deletion fence fails');
    esp63_sql('DELETE FROM esp63_postmeta WHERE post_id=30');
    esp63_sql('DELETE FROM esp63_posts WHERE ID=30');
    ESP_Media_Index::on_post_deleted(30);
    $wpdb->fail_state_write = false;
    esp63_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM $files WHERE attachment_id=30") > 0,
        'F01 stale ownership exists before orphan reconciliation');
    ESP_Media_Index::run_batch(); // orphan purge + normal audit
    ESP_Media_Index::run_batch(); // publish only after complete coverage verification
    esp63_assert($options[ESP_Media_Index::READY_OPTION] === '1' &&
        (int)$wpdb->get_var("SELECT COUNT(*) FROM $files WHERE attachment_id=30") === 0 &&
        (int)$wpdb->get_var("SELECT COUNT(*) FROM $state WHERE attachment_id=30") === 0,
        'F01 READY cannot return until deleted public owner and state are purged');
    esp63_sql("INSERT INTO esp63_posts (ID,post_type) VALUES (31,'attachment')");
    esp63_sql("INSERT INTO esp63_postmeta(post_id,meta_key,meta_value) VALUES
        (31,'_wp_attached_file','2026/10/reused.jpg'),
        (31,'_esp_media_protected_path_id','secret_Z')");
    esp63_assert(ESP_Media_Index::reindex_attachment(31),
        'F01 reused path reindexed under new protected owner');
    $options[ESP_Media_Index::ENABLE_OPTION] = '1';
    $reused = ESP_Media_Index::resolve($uploads . '/2026/10/reused.jpg');
    esp63_assert($reused['status'] === 'resolved' &&
        $reused['attachment_id'] === 31 &&
        $reused['protected_path_id'] === 'secret_Z',
        'F01 old public owner never reappears after READY republish');

    // F02: one persistently invalid attachment may be retried but cannot
    // keep later healthy attachments from rebuilding or advancing the scan.
    esp63_sql("INSERT INTO esp63_posts (ID,post_type) VALUES
        (40,'attachment'),(41,'attachment')");
    esp63_sql("INSERT INTO esp63_postmeta(post_id,meta_key,meta_value) VALUES
        (40,'_wp_attached_file','../invalid.jpg'),
        (41,'_wp_attached_file','2026/10/healthy.jpg'),
        (41,'_esp_media_protected_path_id','secret_Z')");
    file_put_contents($uploads . '/2026/10/healthy.jpg', 'healthy-file');
    esp63_assert(ESP_Media_Index::guard_meta(null,40,'_wp_attached_file','../invalid.jpg')
        === null, 'F02 invalid attachment enqueued');
    ESP_Media_Index::on_meta_saved(401,40,'_wp_attached_file','../invalid.jpg');
    esp63_assert(ESP_Media_Index::guard_meta(null,41,'_wp_attached_file',
        '2026/10/healthy.jpg') === null, 'F02 healthy attachment enqueued');
    ESP_Media_Index::on_meta_saved(411,41,'_wp_attached_file','2026/10/healthy.jpg');
    ESP_Media_Index::run_batch();
    $failed = $wpdb->get_row("SELECT status,failure_count,retry_after
        FROM $state WHERE attachment_id=40");
    $healthy = $wpdb->get_row("SELECT status,active_generation
        FROM $state WHERE attachment_id=41");
    esp63_assert($failed['status'] === 'dirty' &&
        (int)$failed['failure_count'] > 0 &&
        (int)$failed['retry_after'] > time() &&
        $healthy['status'] === 'ready' &&
        (int)$healthy['active_generation'] > 0,
        'F02 bad attachment deferred while healthy attachment becomes ready');
    $failed_count = (int)$failed['failure_count'];
    ESP_Media_Index::run_batch();
    esp63_assert((int)$wpdb->get_var("SELECT failure_count FROM $state
        WHERE attachment_id=40") === $failed_count &&
        $options[ESP_Media_Index::READY_OPTION] === '0',
        'F02 retry cooldown prevents starvation and incomplete coverage stays disabled');
    // Once the metadata is repaired, a normal WordPress mutation resets its
    // retry and the next batch can publish it.
    esp63_assert(ESP_Media_Index::guard_meta(null,40,'_wp_attached_file',
        '2026/10/fixed.jpg') === null, 'F02 repaired metadata resets retry');
    esp63_sql("UPDATE esp63_postmeta SET meta_value='2026/10/fixed.jpg'
        WHERE post_id=40 AND meta_key='_wp_attached_file'");
    file_put_contents($uploads . '/2026/10/fixed.jpg', 'fixed-file');
    ESP_Media_Index::on_meta_saved(402,40,'_wp_attached_file','2026/10/fixed.jpg');
    ESP_Media_Index::run_batch();
    ESP_Media_Index::run_batch();
    esp63_assert($options[ESP_Media_Index::READY_OPTION] === '1' &&
        ESP_Media_Index::resolve($uploads . '/2026/10/fixed.jpg')['status']
          === 'resolved',
        'F02 repaired attachment eventually restores complete coverage');

    file_put_contents($uploads . '/2026/10/unknown.jpg', 'unregistered');
    esp63_assert(ESP_Media_Index::resolve($uploads . '/2026/10/unknown.jpg')['status']
        === 'unresolved', 'unregistered is not automatically known-public');
    echo "Issue 63 production index MySQL tests passed.\n";
} finally {
    foreach (glob($uploads . '/2026/10/*') as $file) { @unlink($file); }
    @rmdir($uploads . '/2026/10'); @rmdir($uploads . '/2026'); @rmdir($uploads);
    foreach ([$files,$state,'esp63_postmeta','esp63_posts','esp63_external_txn'] as $table) {
        $db->query("DROP TABLE IF EXISTS $table");
    }
    $db->close();
}
