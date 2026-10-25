<?php
/**
 * Real WordPress privilege-context test in a fresh PHP process.
 * No WP_CLI constant: that would mask anonymous admin endpoint behavior.
 */
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT')) {
    fwrite(STDERR, "Disposable WordPress environment required.\n"); exit(1);
}
$entry = getenv('ESP_TEST_ADMIN_ENTRY');
if (!in_array($entry, ['ajax', 'post', 'cron'], true)) { exit(1); }
define('WP_INSTALLING', true);
if ($entry !== 'cron') { define('WP_ADMIN', true); }
if ($entry === 'ajax') { define('DOING_AJAX', true); }
if ($entry === 'cron') { define('DOING_CRON', true); }
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
wp_installing(false);
if (strpos(DB_NAME, 'esp_') !== 0 || (defined('WP_CLI') && WP_CLI)) {
    throw new RuntimeException('Expected disposable, non-CLI WordPress context');
}
if (!defined('ESP_PATH')) { require dirname(__DIR__) . '/easy-slug-protect.php'; }
Easy_Slug_Protect::load_dependencies();
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
$wpdb->suppress_errors(true);
function check_context($valid, $message) {
    if (!$valid) { throw new RuntimeException('FAIL: ' . $message); }
    echo "PASS: $message\n";
}
wp_set_current_user(0);
if ($entry === 'cron') {
    check_context(!is_admin() && ESP_Updater::can_finish_now(),
        'explicit Cron can run migrations without an administrator login');
    exit(0);
}
check_context(is_admin() && !current_user_can('manage_options') && !ESP_Updater::can_finish_now(),
    'anonymous admin-' . $entry . ' request is not permitted to migrate');

// Simulate a pending runtime boot. Its lightweight retry scheduling may run,
// but no schema inspection/mutation may start from an anonymous request.
update_option(ESP_Protection_State::RUNTIME_STATE_OPTION,
    ['state' => 'PENDING', 'version' => null], false);
wp_cache_delete(ESP_Protection_State::RUNTIME_STATE_OPTION, 'options');
$migration_queries = 0;
$track_migration = static function ($sql) use (&$migration_queries) {
    if (strpos($sql, 'information_schema.TABLES') !== false ||
        strpos($sql, 'CREATE TABLE') !== false || strpos($sql, 'ALTER TABLE') !== false) {
        $migration_queries++;
    }
    return $sql;
};
add_filter('query', $track_migration);
$ready = ESP_Protection_State::boot(new ESP_Setup());
remove_filter('query', $track_migration);
$runtime = get_option(ESP_Protection_State::RUNTIME_STATE_OPTION);
check_context(!$ready && $migration_queries === 0 &&
    is_array($runtime) && ($runtime['state'] ?? '') === 'PENDING',
    'anonymous admin-' . $entry . ' boot does not start DB migration or mark READY');

wp_set_current_user(1);
check_context(current_user_can('manage_options') && ESP_Updater::can_finish_now(),
    'capable administrator retains the normal migration entry point');
wp_set_current_user(0);
check_context(ESP_Updater::during_activation(static function () {
    return ESP_Updater::can_finish_now();
}), 'explicit activation retains trusted migration entry point');
