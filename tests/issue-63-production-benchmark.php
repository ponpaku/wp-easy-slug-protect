<?php
/**
 * Issue #63 synthetic MySQL comparison, NOT a WordPress/HTTP production benchmark.
 * Runs the EXACT main branch method get_attachment_id_from_path() extracted
 * from the pinned source (via class name isolation), actual PR #62 Resolver,
 * and the isolated dedicated-table lookup on the same 10K/100K corpus.
 * The main implementation doesn't resolve ordinary JPEG thumbnails;
 * speed and correctness are measured separately.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('WEEK_IN_SECONDS', 604800);
// GitHub Actions supplies main's unmodified source from git show at a pinned SHA.
$main_file = getenv('ESP63_MAIN_CLASS_SOURCE');
if (!is_string($main_file) || !is_file($main_file)) {
    fwrite(STDERR, "Missing pinned main source; see workflow git show step\n");
    exit(1);
}
$main_source = file_get_contents($main_file);
if (!is_string($main_source)) { throw new RuntimeException('Could not read main'); }
$renamed_main = str_replace('class ESP_Media_Protection {',
    'class ESP63_Main_Media_Protection {', $main_source, $main_replacements);
if ($main_replacements !== 1) { throw new RuntimeException('main class signature changed'); }
$main_copy = tempnam(sys_get_temp_dir(), 'esp63-main-');
file_put_contents($main_copy, $renamed_main);
try { require $main_copy; } finally { @unlink($main_copy); }
$main_reflection = new ReflectionClass('ESP63_Main_Media_Protection');
$main_object = $main_reflection->newInstanceWithoutConstructor();
$main_lookup_method = $main_reflection->getMethod('get_attachment_id_from_path');
function esp63_main_lookup($path): array {
    global $main_lookup_method, $main_object;
    $value = $main_lookup_method->invoke($main_object, $path);
    if ($value === null) { return ['status' => 'error']; }
    if ($value === false) { return ['status' => 'unresolved']; }
    return ['status' => 'resolved', 'attachment_id' => (int)$value];
}

require dirname(__DIR__) . '/includes/class-esp-media-file-resolver.php';
require dirname(__DIR__) . '/includes/class-esp63-media-index-prototype.php';
require dirname(__DIR__) . '/includes/class-esp-media-index.php';
function get_option($name, $default = false) {
    if ($name === ESP_Media_Index::SCHEMA_OPTION ||
        $name === ESP_Media_Index::READY_OPTION ||
        $name === ESP_Media_Index::ENABLE_OPTION) { return '1'; }
    return $default;
}

final class ESP63_Bench_DB {
    public string $prefix = 'esp63_';
    public string $postmeta = 'esp63_postmeta';
    public string $posts = 'esp63_posts';
    public string $last_error = '';
    public int $queries = 0;
    public mysqli $db;
    public function __construct(mysqli $db) { $this->db = $db; }
    public function esc_like($s) { return addcslashes((string)$s, '_%\\'); }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        $prepared = preg_replace_callback('/%[dsf]/', function ($m) use (&$i, $args) {
            if (!array_key_exists($i, $args)) { throw new RuntimeException('missing bind'); }
            $value = $args[$i++];
            if ($m[0] === '%d') { return (string)(int)$value; }
            if ($m[0] === '%f') { return (string)(float)$value; }
            return "'" . $this->db->real_escape_string((string)$value) . "'";
        }, $sql);
        if ($i !== count($args)) { throw new RuntimeException('unused binds'); }
        return $prepared;
    }
    public function get_results($sql, $output_type = null) {
        $this->queries++;
        $this->last_error = '';
        try {
            $result = $this->db->query($sql);
            if (!$result instanceof mysqli_result) { $this->last_error = 'query failed'; return null; }
            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (Throwable $e) { $this->last_error = $e->getMessage(); return null; }
    }
    public function get_var($sql) {
        $rows = $this->get_results($sql, ARRAY_A);
        if (!is_array($rows) || !$rows) { return null; }
        $first = reset($rows[0]);
        return $first === false ? null : $first;
    }
    public function get_col($sql) {
        $rows = $this->get_results($sql, ARRAY_A);
        if (!is_array($rows)) { return null; }
        return array_map(static function ($row) { return reset($row); }, $rows);
    }
    public function query($sql) {
        $this->queries++;
        $this->last_error = '';
        try { return $this->db->query($sql); }
        catch (Throwable $e) { $this->last_error = $e->getMessage(); return false; }
    }
}
$mysqli = new mysqli(
    getenv('ESP_TEST_DB_HOST') ?: '127.0.0.1',
    getenv('ESP_TEST_DB_USER') ?: 'root',
    getenv('ESP_TEST_DB_PASSWORD') ?: '',
    getenv('ESP_TEST_DB_NAME') ?: 'esp_test'
);
$mysqli->set_charset('utf8mb4');
$mysqli->query('SET SESSION information_schema_stats_expiry=0');
$mysqli->query("SET SESSION sql_mode='STRICT_ALL_TABLES'");
$wpdb = new ESP63_Bench_DB($mysqli);
$GLOBALS['wpdb'] = $wpdb;
$uploads = sys_get_temp_dir() . '/esp63-uploads-' . getmypid();
@mkdir($uploads . '/2026/10', 0700, true);
function wp_upload_dir() { return ['basedir' => $GLOBALS['uploads'], 'error' => false]; }
$GLOBALS['uploads'] = $uploads;

function esp63_name(int $id): string { return 'img-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT); }
function esp63_metadata(int $id): array {
    $stem = esp63_name($id);
    return [
        'file' => '2026/10/' . $stem . '.jpg',
        'sizes' => [
            'thumbnail' => ['file' => $stem . '-150x150.jpg'],
            'medium' => ['file' => $stem . '-300x200.webp'],
            'large' => ['file' => $stem . '-1024x768.jpg'],
        ],
        'original_image' => $stem . '-original.jpg',
    ];
}
function wp_get_attachment_metadata($id, $unfiltered = false) {
    return esp63_metadata((int)$id);
}
function esp63_run_sql(mysqli $mysqli, string $sql): void {
    if ($mysqli->query($sql) === false) { throw new RuntimeException($mysqli->error); }
}
function esp63_seed(mysqli $mysqli, int $size): float {
    $start = microtime(true);
    esp63_run_sql($mysqli, 'TRUNCATE TABLE esp63_postmeta');
    esp63_run_sql($mysqli, 'TRUNCATE TABLE esp63_posts');
    esp63_run_sql($mysqli, 'TRUNCATE TABLE esp63_media_index');
    esp63_run_sql($mysqli, 'TRUNCATE TABLE esp63_esp_media_files_v1');
    esp63_run_sql($mysqli, 'TRUNCATE TABLE esp63_esp_media_file_state_v1');
    $batch_posts = []; $batch_meta = []; $batch_idx = [];
    for ($id = 1; $id <= $size; $id++) {
        $name = esp63_name($id);
        $relative = '2026/10/' . $name . '.jpg';
        $protect = $id % 8 === 0 ? 'secret_A' : '';
        $batch_posts[] = '(' . $id . ", 'attachment')";
        $batch_meta[] = '(' . $id . ", '_wp_attached_file', '" . $relative . "')";
        if ($protect !== '') {
            $batch_meta[] = '(' . $id . ", '_esp_media_protected_path_id', '" . $protect . "')";
        }
        $paths = ESP63_Media_Index_Prototype::enumerate($relative, esp63_metadata($id));
        if (!is_array($paths) || count($paths) !== 5) { throw new RuntimeException('enumeration mismatch'); }
        foreach ($paths as $path) {
            $batch_idx[] = "('" . hash('sha256', $path) . "', '" . $path . "', " .
                $id . ", '" . $protect . "')";
        }
        if (count($batch_posts) >= 400 || $id === $size) {
            esp63_run_sql($mysqli, 'INSERT INTO esp63_posts(ID, post_type) VALUES ' .
                implode(',', $batch_posts));
            esp63_run_sql($mysqli, 'INSERT INTO esp63_postmeta(post_id, meta_key, meta_value) VALUES ' .
                implode(',', $batch_meta));
            esp63_run_sql($mysqli, 'INSERT INTO esp63_media_index(path_hash, file_path, attachment_id, protected_path_id) VALUES ' .
                implode(',', $batch_idx));
            $batch_posts = []; $batch_meta = []; $batch_idx = [];
        }
    }
    // Clone the synthetic corpus into the production schema, preserving each
    // attachment's protection ID and a per-owner ready state. This excludes
    // the real WordPress backfill lifecycle and isolates indexed lookup speed.
    esp63_run_sql($mysqli, 'INSERT INTO esp63_esp_media_files_v1
        (path_hash,file_path,attachment_id,protected_path_id)
        SELECT path_hash,file_path,attachment_id,protected_path_id FROM esp63_media_index');
    esp63_run_sql($mysqli, "INSERT INTO esp63_esp_media_file_state_v1
        (attachment_id,status,revision,pending_count,changed_at)
        SELECT ID,'ready',1,0,0 FROM esp63_posts");
    return microtime(true) - $start;
}
function esp63_percentile(array $values, float $p): float {
    sort($values, SORT_NUMERIC);
    return round($values[max(0, (int)ceil(count($values) * $p) - 1)], 3);
}
function esp63_measure(callable $lookup, array $targets, int $iterations,
                       string $expected_status = 'resolved'): array {
    foreach ($targets as $target) { $lookup($target); }
    $durations = [];
    for ($round = 0; $round < $iterations; $round++) {
        foreach ($targets as $target) {
            $start = hrtime(true);
            $result = $lookup($target);
            $durations[] = (hrtime(true) - $start) / 1000000.0;
            if ($result['status'] !== $expected_status) {
                throw new RuntimeException('Unexpected status ' . json_encode($result));
            }
        }
    }
    return ['p50_ms' => esp63_percentile($durations, 0.5),
            'p95_ms' => esp63_percentile($durations, 0.95),
            'samples' => count($durations)];
}
try {
    esp63_run_sql($mysqli, 'DROP TABLE IF EXISTS esp63_media_index');
    esp63_run_sql($mysqli, 'DROP TABLE IF EXISTS esp63_esp_media_files_v1');
    esp63_run_sql($mysqli, 'DROP TABLE IF EXISTS esp63_esp_media_file_state_v1');
    esp63_run_sql($mysqli, 'DROP TABLE IF EXISTS esp63_postmeta');
    esp63_run_sql($mysqli, 'DROP TABLE IF EXISTS esp63_posts');
    esp63_run_sql($mysqli, 'CREATE TABLE esp63_posts (
        ID BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        post_type VARCHAR(20) NOT NULL
    ) ENGINE=InnoDB');
    esp63_run_sql($mysqli, 'CREATE TABLE esp63_postmeta (
        meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id BIGINT UNSIGNED NOT NULL,
        meta_key VARCHAR(255),
        meta_value LONGTEXT,
        KEY post_id (post_id), KEY meta_key (meta_key(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    esp63_run_sql($mysqli, ESP63_Media_Index_Prototype::schema_sql('esp63_media_index'));
    esp63_run_sql($mysqli, "CREATE TABLE esp63_esp_media_files_v1 (
        path_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        file_path TEXT NOT NULL, attachment_id BIGINT UNSIGNED NOT NULL,
        protected_path_id VARCHAR(191) NOT NULL DEFAULT '',
        PRIMARY KEY (path_hash,attachment_id),KEY attachment_id (attachment_id),
        KEY protected_path_id (protected_path_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    esp63_run_sql($mysqli, "CREATE TABLE esp63_esp_media_file_state_v1 (
        attachment_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        status VARCHAR(16) NOT NULL DEFAULT 'dirty',
        revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
        pending_count INT UNSIGNED NOT NULL DEFAULT 0,
        changed_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        KEY status (status)
    ) ENGINE=InnoDB");
    $report = ['benchmark' => 'issue-63-synthetic', 'php' => PHP_VERSION,
               'word_press' => 'synthetic metadata; pinned main + PR62 + prototype + production live-join query',
               'main_commit' => 'a5c0e3f518e8204d085bdaf492fcc203bf8bca87',
               'dataset_files_per_attachment' => 5, 'iterations_per_target' => 20,
               'results' => []];
    foreach ([10000, 100000] as $size) {
        $seed_secs = esp63_seed($mysqli, $size);
        // Refresh InnoDB persistent stats before reporting table size. Prior
        // values were stale/default (163840 bytes) and must not be interpreted.
        foreach (['esp63_posts', 'esp63_postmeta', 'esp63_media_index',
                  'esp63_esp_media_files_v1', 'esp63_esp_media_file_state_v1'] as $table) {
            $mysqli->query('ANALYZE TABLE ' . $table);
        }
        $targets = [];
        $original_targets = [];
        foreach ([1000, 1001, (int)($size / 2), $size - 1] as $id) {
            $relative = '2026/10/' . esp63_name($id) . '-150x150.jpg';
            $absolute = $uploads . '/' . $relative;
            file_put_contents($absolute, 'bench');
            $targets[] = $absolute;
            $original = $uploads . '/2026/10/' . esp63_name($id) . '.jpg';
            file_put_contents($original, 'bench');
            $original_targets[] = $original;
            $main_original = esp63_main_lookup($original);
            $main_thumb = esp63_main_lookup($absolute);
            if ($main_original['status'] !== 'resolved' ||
                $main_original['attachment_id'] !== $id ||
                $main_thumb['status'] !== 'unresolved') {
                throw new RuntimeException('Unexpected main original/thumb resolution');
            }
            $expected = ($id % 8 === 0) ? 'secret_A' : '';
            $found = ESP63_Media_Index_Prototype::lookup($wpdb, 'esp63_media_index', $relative);
            if ($found['status'] !== 'resolved' || $found['attachment_id'] !== $id ||
                $found['protected_path_id'] !== $expected) {
                throw new RuntimeException('index correctness failed: ' . json_encode($found));
            }
        }
        $old = new ESP_Media_File_Resolver();
        $index_lookup = fn($path) => ESP63_Media_Index_Prototype::lookup(
            $wpdb, 'esp63_media_index', substr($path, strlen($uploads) + 1));
        // Original: all three implementations resolve to the same owner.
        $production_original = esp63_measure(
            fn($path) => ESP_Media_Index::resolve($path), $original_targets, 20);
        $main_original = esp63_measure('esp63_main_lookup', $original_targets, 20);
        $pr62_original = esp63_measure(fn($path) => $old->resolve($path), $original_targets, 20);
        $indexed_original = esp63_measure($index_lookup, $original_targets, 20);
        // Thumbnail: main returns unresolved; PR #62 and dedicated lookup resolve.
        $production_thumb = esp63_measure(
            fn($path) => ESP_Media_Index::resolve($path), $targets, 20);
        $main_thumb = esp63_measure('esp63_main_lookup', $targets, 20, 'unresolved');
        $baseline = esp63_measure(fn($path) => $old->resolve($path), $targets, 20);
        $indexed = esp63_measure($index_lookup, $targets, 20);
        $db_bytes = (int)$mysqli->query("SELECT COALESCE(SUM(data_length+index_length),0) AS bytes
            FROM information_schema.tables WHERE table_schema=DATABASE()
            AND table_name IN ('esp63_posts','esp63_postmeta','esp63_media_index')")
            ->fetch_assoc()['bytes'];
        $count_row = $mysqli->query('SELECT COUNT(*) AS n FROM esp63_media_index')->fetch_assoc();
        if ((int)$count_row['n'] !== $size * 5) {
            throw new RuntimeException('Unexpected dedicated index row count');
        }
        $report['results'][] = [
            'attachments' => $size, 'indexed_files' => $size * 5,
            'production_original' => $production_original,
            'production_jpeg_thumbnail' => $production_thumb,
            'original_file' => ['main' => $main_original, 'resolver_pr62' => $pr62_original,
                               'dedicated_index' => $indexed_original],
            'jpeg_thumbnail' => ['main' => $main_thumb, 'resolver_pr62' => $baseline,
                                  'dedicated_index' => $indexed],
            'main_jpeg_thumbnail_status' => 'unresolved',
            'seed_seconds' => round($seed_secs, 2),
            'resolver_pr62' => $baseline,
            'dedicated_index' => $indexed,
            'db_size_bytes_estimate' => $db_bytes,
        ];
        foreach (array_merge($targets, $original_targets) as $path) { @unlink($path); }
        echo $size . " originals: main p95 " . $main_original['p95_ms'] .
            " ms; PR62 p95 " . $pr62_original['p95_ms'] .
            " ms; prototype p95 " . $indexed_original['p95_ms'] .
            " ms; production p95 " . $production_original['p95_ms'] . " ms\n";
        echo $size . " JPEG thumbnails: main unresolved p95 " . $main_thumb['p95_ms'] .
            " ms; PR62 resolved p95 " . $baseline['p95_ms'] .
            " ms; prototype p95 " . $indexed['p95_ms'] .
            " ms; production resolved p95 " . $production_thumb['p95_ms'] . " ms\n";
    }
    // Backfill helper updates a single attachment without leaving partial rows.
    $changed_id = 99;
    $changed_base = '2026/10/' . esp63_name($changed_id) . '.jpg';
    if (!ESP63_Media_Index_Prototype::replace_attachment(
        $wpdb, 'esp63_media_index', $changed_id,
        $changed_base, esp63_metadata($changed_id), 'secret_Z')) {
        throw new RuntimeException('Attachment replacement transaction failed');
    }
    $changed_lookup = ESP63_Media_Index_Prototype::lookup(
        $wpdb, 'esp63_media_index', '2026/10/' . esp63_name($changed_id) . '-150x150.jpg');
    if ($changed_lookup['status'] !== 'resolved' ||
        $changed_lookup['protected_path_id'] !== 'secret_Z') {
        throw new RuntimeException('Attachment protection update failed');
    }
    $report['attachment_replacement_check'] = 'passed';
    // Competing ownership and corruption must not yield a resolved owner.
    $rel = '2026/10/conflict.jpg';
    esp63_run_sql($mysqli, "INSERT INTO esp63_media_index
        (path_hash, file_path, attachment_id, protected_path_id) VALUES
        ('" . hash('sha256', $rel) . "', '$rel', 2, 'secret_A'),
        ('" . hash('sha256', $rel) . "', '$rel', 3, '')");
    if (ESP63_Media_Index_Prototype::lookup($wpdb, 'esp63_media_index', $rel)['status'] !== 'ambiguous') {
        throw new RuntimeException('Duplicate-owner safety check failed');
    }
    $report['duplicate_owner_check'] = 'passed';
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo $json . "\n";
    file_put_contents(getenv('ESP63_BENCH_REPORT') ?: 'issue-63-benchmark.json', $json . "\n");
} finally {
    $mysqli->close();
    @rmdir($uploads . '/2026/10');
    @rmdir($uploads . '/2026');
    @rmdir($uploads);
}
