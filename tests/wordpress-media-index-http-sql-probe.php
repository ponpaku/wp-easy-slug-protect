<?php
/**
 * Disposable HTTP SQL-roundtrip profiler. Copied as an MU plugin only by CI.
 * Counts requests AFTER MU-plugin bootstrap; no production instrumentation.
 */
if (!defined('ABSPATH') || !defined('DB_NAME') ||
    strpos(DB_NAME, 'esp_') !== 0) { return; }
$probe = $_SERVER['HTTP_X_ESP_SQL_PROBE'] ?? '';
if (!is_string($probe) || !preg_match('/^[a-z0-9_-]{1,40}$/D', $probe)) {
    return;
}
$esp_http_sql_queries = [];
add_filter('query', static function ($sql) use (&$esp_http_sql_queries) {
    $esp_http_sql_queries[] = $sql;
    return $sql;
}, PHP_INT_MAX);
register_shutdown_function(static function () use ($probe, &$esp_http_sql_queries) {
    $count_index = 0;
    $count_version = 0;
    $count_options = 0;
    $count_index_options = 0;
    foreach ($esp_http_sql_queries as $sql) {
        if (preg_match('/^\s*SELECT\b/i', $sql) &&
            strpos($sql, 'esp_media_files_v1') !== false) { ++$count_index; }
        if (preg_match('/^\s*SELECT\s+VERSION\s*\(\s*\)/i', $sql)) {
            ++$count_version;
        }
        if (preg_match('/^\s*SELECT\b/i', $sql) &&
            preg_match('/\b(?:wp_[a-z0-9_]*options|[a-z0-9_]+_options)\b/i', $sql)) {
            ++$count_options;
            if (strpos($sql, 'esp_media_index_v1_') !== false) {
                ++$count_index_options;
            }
        }
    }
    $record = [
        'request' => $probe,
        'observed_since_mu_plugin_load' => true,
        'queries_total' => count($esp_http_sql_queries),
        'index_lookup_sql' => $count_index,
        'version_sql' => $count_version,
        'options_sql' => $count_options,
        'media_index_option_sql' => $count_index_options,
    ];
    file_put_contents(WP_CONTENT_DIR . '/uploads/.esp-http-sql-profile.jsonl',
        json_encode($record, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX);
});
