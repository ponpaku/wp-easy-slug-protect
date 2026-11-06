<?php
/**
 * Real WordPress/MySQL media-index read-path profiling in disposable CI.
 * Times are measurements, NOT cross-run performance budgets.
 * "No unexpected DB writes", READY, exact ownership and zero COUNT(*) in
 * lightweight status are mandatory invariants.
 */
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT')) { exit(1); }
define('WP_ADMIN', true);
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
if (strpos(DB_NAME, 'esp_') !== 0 || !class_exists('ESP_Media_Index')) {
    throw new RuntimeException('DB perf probe requires disposable WordPress/MySQL');
}
global $wpdb;
$ready = ESP_Media_Index::build_status(false);
if ($ready['error'] || !$ready['ready'] || $ready['repairable_dirty']) {
    throw new RuntimeException('DB perf probe requires stable READY coverage');
}
$uploads = wp_upload_dir();
$owner = $uploads['basedir'] . '/esp31-protected-150x150.jpg';
$public = $uploads['basedir'] . '/esp31-public-150x150.jpg';
$state = ESP_Media_Index::state_table();
$files = ESP_Media_Index::files_table();

function esp_profile($label, $runs, $callback, $read_only = true) {
    global $wpdb;
    $queries = [];
    $watch = static function ($sql) use (&$queries) {
        $queries[] = $sql;
        return $sql;
    };
    $samples = [];
    $query_counts = [];
    $count_queries = 0;
    $writes = [];
    add_filter('query', $watch, PHP_INT_MAX);
    try {
        for ($i = 0; $i < $runs; ++$i) {
            $before = count($queries);
            $started = hrtime(true);
            $callback();
            $samples[] = (hrtime(true) - $started) / 1000000;
            if ($wpdb->last_error !== '') {
                throw new RuntimeException($label . ' DB failure: ' . $wpdb->last_error);
            }
            $sqls = array_slice($queries, $before);
            $query_counts[] = count($sqls);
            foreach ($sqls as $sql) {
                if (preg_match('/^\s*SELECT\s+COUNT\s*\(\s*\*\s*\)/i', $sql)) {
                    ++$count_queries;
                }
                if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|CREATE|ALTER|DROP)\b/i', $sql)) {
                    $writes[] = substr($sql, 0, 90);
                }
            }
        }
    } finally {
        remove_filter('query', $watch, PHP_INT_MAX);
    }
    if ($read_only && $writes) {
        throw new RuntimeException($label . ' unexpectedly wrote to DB: ' . implode('; ', $writes));
    }
    sort($samples);
    return [
        'runs' => $runs,
        'median_ms' => round($samples[(int)floor($runs / 2)], 4),
        'p95_ms' => round($samples[(int)ceil($runs * .95) - 1], 4),
        'max_ms' => round(max($samples), 4),
        'sql_queries_total' => array_sum($query_counts),
        'sql_queries_max_per_call' => max($query_counts),
        'count_star_queries' => $count_queries,
        'db_writes' => count($writes),
    ];
}

$before_rows = (int)$wpdb->get_var("SELECT COUNT(*) FROM $files");
$before_ready = (int)$wpdb->get_var("SELECT COUNT(*) FROM $state WHERE status='ready'");
$report = [
    'measurement' => 'real WP+MySQL PHP read paths; synthetic disposable attachment fixtures',
    'mysql_version' => $wpdb->get_var('SELECT VERSION()'),
];
$report['build_status_fast'] = esp_profile('build_status(false)', 40, static function () {
    $s = ESP_Media_Index::build_status(false);
    if ($s['error'] || !$s['ready'] || $s['repairable_dirty']) {
        throw new RuntimeException('status not READY during DB profile');
    }
});
if ($report['build_status_fast']['count_star_queries'] !== 0) {
    throw new RuntimeException('build_status(false) issued display-only COUNT(*)');
}
$report['resolve_protected'] = esp_profile('resolve(protected)', 40, static function () use ($owner) {
    $s = ESP_Media_Index::resolve($owner);
    if ($s['status'] !== 'resolved' || $s['protected_path_id'] !== 'path_http') {
        throw new RuntimeException('protected thumbnail ownership mismatch');
    }
});
$report['resolve_public'] = esp_profile('resolve(public)', 40, static function () use ($public) {
    $s = ESP_Media_Index::resolve($public);
    if ($s['status'] !== 'resolved' || $s['protected_path_id'] !== '') {
        throw new RuntimeException('public thumbnail ownership mismatch');
    }
});
$after_rows = (int)$wpdb->get_var("SELECT COUNT(*) FROM $files");
$after_ready = (int)$wpdb->get_var("SELECT COUNT(*) FROM $state WHERE status='ready'");
if ($wpdb->last_error !== '' || $before_rows !== $after_rows ||
    $before_ready !== $after_ready) {
    throw new RuntimeException('read-only DB profile mutated index state');
}
$report['indexed_file_rows_unchanged'] = true;
$report['ready_rows_unchanged'] = true;
echo "PASS: real MySQL status/ownership queries are read-only, avoid COUNT(*) in fast status, preserve READY\n";
echo 'ESP63_REAL_DB_PERF ' . wp_json_encode($report) . "\n";
