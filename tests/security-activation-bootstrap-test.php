<?php
/** WordPress initial activation runs after plugins_loaded without calling esp_init(). */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['activation_callback'] = null;
$GLOBALS['plugins_loaded_callbacks'] = [];
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/esp/'; }
function is_admin() { return false; }
function add_action($hook, $callback, $priority = 10) {
    if ($hook === 'plugins_loaded') {
        $GLOBALS['plugins_loaded_callbacks'][] = $callback;
    }
}
function register_activation_hook($file, $callback) {
    $GLOBALS['activation_callback'] = $callback;
}
function register_deactivation_hook($file, $callback) {}
function check_activation($ok, $message) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}
require __DIR__ . '/../easy-slug-protect.php';
check_activation(is_callable($GLOBALS['activation_callback']), 'activation hook registered');
check_activation($GLOBALS['activation_callback'] === 'esp_activate',
    'activation hook invokes dedicated initialization');
check_activation(!class_exists('ESP_Protection_State', false) &&
    !class_exists('ESP_Media_Protection', false),
    'activation begins without classes loaded by plugins_loaded');

// Activation must use the same loader as normal bootstrap instead of depending
// on plugins_loaded being triggered again for a previously inactive plugin.
Easy_Slug_Protect::load_dependencies();
check_activation(class_exists('ESP_Protection_State', false) &&
    class_exists('ESP_Media_Protection', false) &&
    class_exists('ESP_Setup', false) &&
    class_exists('ESP_Option', false) &&
    class_exists('ESP_Authorization_Cache', false),
    'shared activation loader makes all required security classes available');
Easy_Slug_Protect::load_dependencies();
check_activation(true, 'repeated loader calls are safe for reactivation');
echo "All activation bootstrap tests passed.\n";
