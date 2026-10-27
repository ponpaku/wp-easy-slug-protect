<?php
/**
 * Verify the exact distribution ZIP installs/activates through WordPress Core,
 * then boots successfully in a fresh PHP worker.
 *
 * ESP_SMOKE_STAGE=install|activate|verify
 * Never operate outside an explicitly opt-in disposable esp_* database.
 */
$stage = getenv('ESP_SMOKE_STAGE');
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT') ||
    !in_array($stage, ['install', 'activate', 'verify'], true)) { exit(1); }
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
define('FS_METHOD', 'direct');
define('WP_ADMIN', true);
if ($stage !== 'verify') {
    // Prevent an existing active plugin from being loaded during the installation
    // (the new PHP worker in 'verify' must perform a genuine plugin bootstrap).
    define('WP_INSTALLING', true);
} else {
    define('WP_CLI', true);
}
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
wp_installing(false);
if (strpos(DB_NAME, 'esp_') !== 0) { throw new RuntimeException('Refuse non-disposable database'); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
function smoke_assert($condition, $message) {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . "\n";
}
$expected = getenv('ESP_TARGET_VERSION');
$plugin = 'easy-slug-protect/easy-slug-protect.php';
if (!is_string($expected) || !preg_match('/^\d+\.\d+\.\d+$/D', $expected)) {
    throw new RuntimeException('Missing target version');
}
if ($stage === 'install') {
    global $wpdb, $wp_filesystem;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'esp\\_%'");
    wp_cache_flush();
    update_option('active_plugins', []);
    WP_Filesystem();
    $folder = WP_PLUGIN_DIR . '/easy-slug-protect';
    if ($wp_filesystem->exists($folder)) { $wp_filesystem->delete($folder, true); }
    $zip = getenv('ESP_PR_ZIP');
    smoke_assert(is_string($zip) && is_file($zip), 'tested release ZIP exists');
    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    smoke_assert($upgrader->install($zip) === true,
        'real Core installer accepts exact tested ZIP on a clean WordPress');
    smoke_assert(get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin)['Version'] === $expected,
        'installed PHP header matches artifact version');
    exit(0);
}
if ($stage === 'activate') {
    $activated = activate_plugin($plugin);
    smoke_assert(!is_wp_error($activated) && is_plugin_active($plugin),
        'real WordPress activates installed release ZIP');
    exit(0);
}
smoke_assert(defined('ESP_VERSION') && ESP_VERSION === $expected && is_plugin_active($plugin),
    'fresh PHP worker bootstraps the installed release');
wp_set_current_user(1);
if (!did_action('wp_loaded')) { do_action('wp_loaded'); }
smoke_assert(ESP_Protection_State::verify_and_commit(new ESP_Setup()) &&
    get_option('esp_runtime_state')['state'] === 'READY' &&
    get_option('esp_plugin_version') === $expected &&
    get_option('esp_update_state')['status'] === 'complete',
    'release ZIP passes runtime and post-install verification in fresh worker');
