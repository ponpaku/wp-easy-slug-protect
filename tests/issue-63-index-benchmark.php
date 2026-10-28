<?php
/**
 * Issue #63 synthetic MySQL comparison, NOT a WordPress/HTTP production benchmark.
 * Runs actual PR #62 Resolver::resolve() with a small wpdb compatibility shim,
 * and the isolated dedicated-table lookup against the same 10K/100K corpus.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
require dirname(__DIR__) . '/includes/class-esp-media-file-resolver.php';
require dirname(__DIR__) . '/includes/class-esp63-media-index-prototype.php';

final class ESP63_Bench_DB {
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
    return microtime(true) - $start;
}
function esp63_percentile(array $values, float $p): float {
    sort($values, SORT_NUMERIC);
    return round($values[max(0, (int)ceil(count($values) * $p) - 1)], 3);
}
function esp63_measure(callable $lookup, array $targets, int $iterations): array {
    foreach ($targets as $target) { $lookup($target); }
    $durations = [];
    for ($round = 0; $round < $iterations; $round++) {
        foreach ($targets as $target) {
            $start = hrtime(true);
            $result = $lookup($target);
            $durations[] = (hrtime(true) - $start) / 1000000.0;
            if ($result['status'] !== 'resolved') {
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
    $report = ['benchmark' => 'issue-63-synthetic', 'php' => PHP_VERSION,
               'word_press' => 'stub metadata, actual PR #62 Resolver SQL',
               'dataset_files_per_attachment' => 5, 'iterations_per_target' => 20,
               'results' => []];
    foreach ([10000, 100000] as $size) {
        $seed_secs = esp63_seed($mysqli, $size);
        // Refresh InnoDB persistent stats before reporting table size. Prior
        // values were stale/default (163840 bytes) and must not be interpreted.
        foreach (['esp63_posts', 'esp63_postmeta', 'esp63_media_index'] as $table) {
            $mysqli->query('ANALYZE TABLE ' . $table);
        }
        $targets = [];
        foreach ([1000, 1001, (int)($size / 2), $size - 1] as $id) {
            $relative = '2026/10/' . esp63_name($id) . '-150x150.jpg';
            $absolute = $uploads . '/' . $relative;
            file_put_contents($absolute, 'bench');
            $targets[] = $absolute;
            $expected = ($id % 8 === 0) ? 'secret_A' : '';
            $found = ESP63_Media_Index_Prototype::lookup($wpdb, 'esp63_media_index', $relative);
            if ($found['status'] !== 'resolved' || $found['attachment_id'] !== $id ||
                $found['protected_path_id'] !== $expected) {
                throw new RuntimeException('index correctness failed: ' . json_encode($found));
            }
        }
        $old = new ESP_Media_File_Resolver();
        $baseline = esp63_measure(fn($path) => $old->resolve($path), $targets, 20);
        $indexed = esp63_measure(fn($path) =>
            ESP63_Media_Index_Prototype::lookup($wpdb, 'esp63_media_index',
                substr($path, strlen($uploads) + 1)), $targets, 20);
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
            'seed_seconds' => round($seed_secs, 2),
            'resolver_pr62' => $baseline,
            'dedicated_index' => $indexed,
            'db_size_bytes_estimate' => $db_bytes,
        ];
        foreach ($targets as $path) { @unlink($path); }
        echo $size . " attachments: Resolver p95 " . $baseline['p95_ms'] .
            " ms; dedicated p95 " . $indexed['p95_ms'] . " ms\n";
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
