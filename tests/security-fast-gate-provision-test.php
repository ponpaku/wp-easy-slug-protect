<?php
/**
 * Fast Gate v2 provisioning contract, without a full WordPress bootstrap.
 * The on-disk directory is always derived from the content/plugin layout.
 */
require_once __DIR__ . '/../includes/class-esp-fast-gate-token.php';
require_once __DIR__ . '/../includes/class-esp-fast-gate-store.php';
require_once __DIR__ . '/../includes/class-esp-fast-gate-wp.php';
function esp_provision_assert($ok, $label) {
    if (!$ok) { throw new RuntimeException($label); }
}
if (!class_exists('SQLite3')) {
    echo "Fast Gate provision skipped: SQLite3 unavailable\n";
    exit;
}
$base = sys_get_temp_dir() . '/esp-gate-provision-' . bin2hex(random_bytes(5));
mkdir($base . '/wp-content/plugins/easy-slug-protect', 0700, true);
mkdir($base . '/wp-content/uploads', 0700, true);
define('WP_CONTENT_DIR', $base . '/wp-content');
define('ESP_PATH', $base . '/wp-content/plugins/easy-slug-protect/');
function get_current_blog_id() { return 1; }
function wp_upload_dir() {
    return ['basedir' => WP_CONTENT_DIR . '/uploads',
        'baseurl' => 'https://example.invalid/wp-content/uploads'];
}
try {
    esp_provision_assert(ESP_Fast_Gate_WP::private_dir() === null,
        'nothing auto-provisioned on plugin load');
    esp_provision_assert(ESP_Fast_Gate_WP::provision(), 'provision must succeed');
    $dir = WP_CONTENT_DIR . '/esp-fast-gate-v2';
    $php = $dir . '/keys.php';
    esp_provision_assert(is_file($php), 'key is a PHP file');
    esp_provision_assert(!is_file($dir . '/gate-keys.json'), 'no JSON key to serve');
    $key = require $php;
    esp_provision_assert(is_array($key) && strlen($key['sig']) >= 64 &&
        $key['id'] !== $key['sig'], 'separate random site-scoped keys');
    ob_start();
    include $php;
    $output = ob_get_clean();
    esp_provision_assert($output === '', 'direct PHP execution does not echo keys');
    $rules = file_get_contents($dir . '/.htaccess');
    esp_provision_assert(strpos($rules, 'Require all denied') !== false &&
        strpos($rules, 'Deny from all') !== false, 'Apache denial present');
    esp_provision_assert((fileperms($php) & 0777) === 0600, 'mode 0600');
    $db = ESP_Fast_Gate_WP::ledger(false);
    esp_provision_assert($db !== null && $db->schema_ok() && !$db->serving(),
        'provisioned ledger cannot serve');
    unset($db);
    esp_provision_assert(!ESP_Fast_Gate_WP::provision(), 'no silent key rotation');
    echo "Fast Gate PHP-key provisioning test OK\n";
} finally {
    foreach (['gate-ledger.sqlite', 'gate-ledger.sqlite-wal',
              'gate-ledger.sqlite-shm', 'keys.php', '.htaccess'] as $name) {
        @unlink($base . '/wp-content/esp-fast-gate-v2/' . $name);
    }
    @rmdir($base . '/wp-content/esp-fast-gate-v2');
    @rmdir($base . '/wp-content/uploads');
    @rmdir($base . '/wp-content/plugins/easy-slug-protect');
    @rmdir($base . '/wp-content/plugins');
    @rmdir($base . '/wp-content');
    @rmdir($base);
}
