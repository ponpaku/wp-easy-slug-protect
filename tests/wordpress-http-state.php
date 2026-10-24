<?php
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT')) { exit(1); }
define('WP_INSTALLING', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php'; wp_installing(false);
if (strpos(DB_NAME, 'esp_') !== 0) { exit(1); }
require WP_PLUGIN_DIR . '/esp44-custom/easy-slug-protect.php';
$state = getenv('ESP_RUNTIME_TEST_STATE');
if (!in_array($state, ['FAILED', 'UPDATING', 'READY'], true)) { exit(1); }
update_option('esp_runtime_state', ['state' => $state, 'version' => ESP_VERSION, 'since' => time()], false);
echo "Set disposable HTTP fixture state: $state\n";
