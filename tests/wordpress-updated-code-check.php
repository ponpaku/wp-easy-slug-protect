<?php
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT')) { exit(1); }
define('WP_CLI', true);
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
$expected = getenv('ESP_EXPECT_VERSION') ?: '0.7.41';
if (ESP_VERSION !== $expected || get_option('esp_plugin_version') !== ESP_VERSION ||
    get_option('esp_runtime_state')['state'] !== 'READY' || get_option('esp_update_state')['status'] !== 'complete') {
    throw new RuntimeException('Fresh PHP process failed to complete new-code processing: ' . wp_json_encode([
        get_option('esp_runtime_state'), get_option('esp_migration_state'), get_option('esp_update_state')]));
}
echo "PASS: fresh PHP worker loads the installed version, verifies migration/Rewrite/Cron, then commits completed version\n";
