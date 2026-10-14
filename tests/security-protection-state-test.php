<?php
/** 起動時の保護状態が不明な場合に、安全側で拒否する単体テスト。 */
define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../includes/class-esp-config.php';
class TestSetup {
    public $ok = true;
    public function check_plugin_version() { return $this->ok; }
}
class BootTestDB {
    public $last_error = '';
    public $online = true;
    public function get_var($query) {
        if (!$this->online) {
            $this->last_error = 'Disconnected';
            return null;
        }
        $this->last_error = '';
        return 1;
    }
}
class WP_Error {
    private $data;
    public function __construct($code, $message, $data) { $this->data = $data; }
    public function get_error_data() { return $this->data; }
}
function __($msg, $domain = null) { return $msg; }
function get_option($name, $default = null) {
    return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default;
}
function add_filter($hook, $callback, $priority = 10, $args = 1) {
    $GLOBALS['filters'][$hook][] = [$callback, $priority];
}
function add_action($hook, $callback, $priority = 10, $args = 1) {
    $GLOBALS['actions'][$hook][] = [$callback, $priority];
}
function check($ok, $msg) {
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    echo "PASS: $msg\n";
}
require __DIR__ . '/../includes/class-esp-protection-state.php';
$wpdb = new BootTestDB();
$setup = new TestSetup();
$GLOBALS['options'] = ['esp_settings' => ['path' => [], 'media' => ['enabled' => true]]];
check(ESP_Protection_State::boot($setup) === true, 'valid configuration is ready');
check(!isset($GLOBALS['actions']['template_redirect']), 'ready state does not intercept frontend');

$setup->ok = false;
check(ESP_Protection_State::boot($setup) === false, 'migration failure is not ready');
check(($GLOBALS['actions']['template_redirect'][0][1] ?? null) === -1000, 'frontend blocked before rendering');
$rest = $GLOBALS['filters']['rest_pre_dispatch'][0][0];
check($rest(null) instanceof WP_Error && $rest(null)->get_error_data()['status'] === 503, 'REST requests return 503');

$setup->ok = true;
unset($GLOBALS['options']['esp_settings']);
check(ESP_Protection_State::boot($setup) === false, 'missing settings fail closed');
$GLOBALS['options']['esp_settings'] = ['path' => [['path' => '/private']]];
check(ESP_Protection_State::boot($setup) === true, 'legacy valid settings are accepted');
$GLOBALS['options']['esp_settings'] = ['path' => [['path' => '']]];
check(ESP_Protection_State::boot($setup) === false, 'empty path detected by boot gate before matcher initialization');
$GLOBALS['options']['esp_settings'] = ['path' => [['path' => '0']]];
check(ESP_Protection_State::boot($setup) === false, 'zero-like path rejected consistently with matcher');
$GLOBALS['options']['esp_settings'] = ['path' => [['path' => '  ']]];
check(ESP_Protection_State::boot($setup) === false, 'whitespace-only path rejected');
$GLOBALS['options']['esp_settings'] = ['path' => [['path' => '///']]];
check(ESP_Protection_State::boot($setup) === false, 'slash-only path detected before matcher');
$GLOBALS['options']['esp_settings'] = ['path' => [['path' => '/private']]];
$wpdb->online = false;
check(ESP_Protection_State::boot($setup) === false, 'stale cached settings with DB outage fail closed');
// 起動不能でも管理者用の読み取り専用ページを登録し、復旧方法を確認できる。
function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback, $icon = '', $position = null) {
    $GLOBALS['admin_menu'] = compact('page_title', 'menu_title', 'capability', 'menu_slug', 'callback');
}
function current_user_can($capability) { return $capability === 'manage_options'; }
function esc_html__($message, $domain = null) { return $message; }
ESP_Protection_State::register_recovery_admin();
$adminRegistration = $GLOBALS['actions']['admin_menu'][0][0] ?? null;
check(is_callable($adminRegistration), 'administration recovery menu hook registered');
$adminRegistration();
check(($GLOBALS['admin_menu']['capability'] ?? null) === 'manage_options',
    'recovery UI requires administrator privileges');
check(($GLOBALS['admin_menu']['menu_slug'] ?? null) === 'esp-settings',
    'recovery page retains ESP menu path');
ob_start();
call_user_func($GLOBALS['admin_menu']['callback']);
$recoveryHtml = ob_get_clean();
check(strpos($recoveryHtml, 'uploads/.htaccess') !== false &&
    strpos($recoveryHtml, '<form') === false,
    'read-only recovery panel describes server-side repair without editable configuration');
echo "All protection boot tests passed.\n";
