<?php
/** Event-driven runtime gate: normal reads never invoke update/DB/Rewrite checks. */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('ESP_VERSION', '0.7.38');
define('ESP_PATH', __DIR__ . '/../');
require __DIR__ . '/../includes/class-esp-config.php';

class TestSetup {
    public $ok = true;
    public $checks = 0;
    public function check_plugin_version() { $this->checks++; return $this->ok; }
}
class BootTestDB {
    public $last_error = '';
    public $online = true;
    public $legacy_schema_valid = true;
    public $queries = 0;
    public $prefix = 'wp_';
    public function prepare($query, ...$args) {
        return str_replace('%s', "'" . ($args[0] ?? '') . "'", $query);
    }
    public function get_col($query) {
        $this->queries++;
        $this->last_error = $this->online ? '' : 'Disconnected';
        if (!$this->online || !$this->legacy_schema_valid) { return []; }
        return ['ip_address', 'path', 'path_id', 'time', 'password_version', 'user_id',
            'token', 'expires', 'window_started', 'attempts', 'blocked_until',
            'updated_at', 'attempt_times', 'last_attempt_token'];
    }
    public function get_results($query, $output = null) {
        $this->queries++;
        $this->last_error = $this->online ? '' : 'Disconnected';
        if (!$this->online || !$this->legacy_schema_valid) { return []; }
        return [
            ['Seq_in_index' => 1, 'Column_name' => 'ip_address'],
            ['Seq_in_index' => 2, 'Column_name' => 'path_id'],
        ];
    }
    public function get_var($query) {
        $this->queries++;
        if (!$this->online) { $this->last_error = 'Disconnected'; return null; }
        $this->last_error = '';
        return strpos($query, 'information_schema.TABLES') !== false
            ? ($this->legacy_schema_valid ? 'InnoDB' : 'MyISAM') : 1;
    }
}
class ESP_Media_Protection {
    public static $apply_ok = true;
    public static $audit_ok = true;
    public static $checks = 0;
    public static $audits = 0;
    public static function ensure_rewrite_policy() {
        self::$checks++;
        return self::$apply_ok;
    }
    public static function audit_rewrite_policy() {
        self::$audits++;
        return self::$audit_ok;
    }
}
class WP_Error {
    private $data;
    private $code;
    public function __construct($code, $message = '', $data = []) {
        $this->code = $code; $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function __($msg, $domain = null) { return $msg; }
function esc_html__($msg, $domain = null) { return $msg; }
function esc_url($url) { return $url; }
function admin_url($uri) { return '/wp-admin/' . $uri; }
function wp_nonce_field($action) { echo '<input name="_wpnonce" value="test" />'; }
function get_option($name, $default = null) {
    return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default;
}
function add_option($name, $value, $deprecated = '', $autoload = null) {
    if (array_key_exists($name, $GLOBALS['options'])) { return false; }
    $GLOBALS['options'][$name] = $value;
    return true;
}
function delete_option($name) { unset($GLOBALS['options'][$name]); return true; }
function wp_next_scheduled($name) { return $GLOBALS['scheduled'][$name] ?? false; }
function wp_schedule_single_event($when, $name) {
    $GLOBALS['scheduled'][$name] = $when;
    return true;
}
function update_option($name, $value, $autoload = null) {
    if (!empty($GLOBALS['reject_update'])) { return false; }
    $GLOBALS['options'][$name] = $value;
    return true;
}
function add_filter($hook, $callback, $priority = 10, $args = 1) {
    $GLOBALS['filters'][$hook][] = [$callback, $priority];
}
function add_action($hook, $callback, $priority = 10, $args = 1) {
    $GLOBALS['actions'][$hook][] = [$callback, $priority];
}
function plugin_basename($file) { return 'easy-slug-protect/easy-slug-protect.php'; }
function current_user_can($capability) { return $capability === 'manage_options'; }
function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback, $icon = '', $position = null) {
    $GLOBALS['admin_menu'] = compact('page_title', 'menu_title', 'capability', 'menu_slug', 'callback');
}
function check($ok, $msg) {
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    echo "PASS: $msg\n";
}
require __DIR__ . '/../includes/class-esp-protection-state.php';
$wpdb = new BootTestDB();
$setup = new TestSetup();
$GLOBALS['options'] = [
    ESP_Config::OPTION_KEY => ['path' => [], 'media' => ['enabled' => true]],
    'esp_db_version' => ESP_Config::OPTION_DEFAULTS['db_version'],
    ESP_Config::VERSION_OPTION_KEY => ESP_VERSION,
];
check(ESP_Protection_State::boot($setup) === true,
    'initial installation verifies once and recovers without administrator visit');
check($setup->checks === 1 && $wpdb->queries > 0 && ESP_Media_Protection::$checks === 1,
    'first unverified boot alone may perform full validation');
check(ESP_Protection_State::verify_and_commit($setup) === true,
    'administrator can also explicitly validate and commit READY');
check(get_option('esp_runtime_state') === 'READY', 'valid state is saved');
check($wpdb->queries > 0 && $setup->checks === 2 && ESP_Media_Protection::$checks === 2,
    'explicit verification performs migration, schema and Rewrite checks');

// 自動・手動の両検証は同じ排他を使い、同時移行を開始しない。
$GLOBALS['options']['esp_runtime_verify_lock'] = time();
$before_checks = $setup->checks;
check(ESP_Protection_State::verify_and_commit($setup) === false &&
    $setup->checks === $before_checks &&
    get_option('esp_runtime_state') === 'READY',
    'concurrent explicit verification cannot race with another migration');
unset($GLOBALS['options']['esp_runtime_verify_lock']);

$wpdb->queries = 0;
$setup->checks = 0;
ESP_Media_Protection::$checks = 0;
for ($i = 0; $i < 10; $i++) {
    check(ESP_Protection_State::boot($setup) === true, 'ready boot succeeds without heavyweight validation');
}
check($wpdb->queries === 0 && $setup->checks === 0 && ESP_Media_Protection::$checks === 0,
    'ten normal boot calls perform zero extra DB queries, version checks and Rewrite checks');

$wpdb->online = false;
check(ESP_Protection_State::boot($setup) === true,
    'ordinary boot does not query DB solely for liveness (authorization reads still fail closed)');
$wpdb->online = true;

$GLOBALS['options'][ESP_Config::OPTION_KEY] = ['path' => [['path' => '']]];
check(ESP_Protection_State::boot($setup) === false, 'invalid path rejected before authorization');
$GLOBALS['options'][ESP_Config::OPTION_KEY] = ['path' => [['path' => '/protected']]];
check(ESP_Protection_State::boot($setup) === true, 'valid protection paths remain accepted');

$GLOBALS['options']['esp_runtime_state'] = 'PENDING';
check(ESP_Protection_State::boot($setup) === true, 'legacy PENDING is verified once by the first request');
$GLOBALS['options']['esp_runtime_state'] = 'FAILED';
check(ESP_Protection_State::boot($setup) === false, 'failed validation refuses frontend');
$GLOBALS['options']['esp_runtime_state'] = 'UNKNOWN';
check(ESP_Protection_State::boot($setup) === false, 'unknown state refuses frontend');
$rest = $GLOBALS['filters']['rest_pre_dispatch'][0][0];
check($rest(null) instanceof WP_Error && $rest(null)->get_error_data()['status'] === 503,
    'REST access refused when protection state unknown');

ESP_Protection_State::register_runtime_hooks($setup);
check(isset($GLOBALS['filters']['upgrader_pre_install']), 'upgrade-preflight hook registered');
$pre = $GLOBALS['filters']['upgrader_pre_install'][0][0];
$GLOBALS['options']['esp_runtime_state'] = 'READY';
check($pre(true, ['action'=>'update', 'type'=>'plugin', 'plugin'=>'some-plugin/main.php']) === true &&
    get_option('esp_runtime_state') === 'READY', 'other plugins do not trigger ESP pending');
check($pre(true, ['action'=>'update', 'type'=>'plugin',
    'plugin'=>'easy-slug-protect/easy-slug-protect.php']) === true &&
    get_option('esp_runtime_state') === 'UPDATING', 'ESP upgrade is marked UPDATING before installation');
$setup->checks = 0;
check(ESP_Protection_State::boot($setup) === false && $setup->checks === 0,
    'in-progress update never triggers premature validation');
$post = $GLOBALS['filters']['upgrader_post_install'][0][0];
check($post(true, ['action'=>'update', 'type'=>'plugin',
    'plugin'=>'easy-slug-protect/easy-slug-protect.php'], []) === true &&
    get_option('esp_runtime_state') === 'VERIFY_REQUIRED',
    'successful updater stage schedules new-code validation');
check(!empty($GLOBALS['scheduled']['esp_verify_runtime_once']),
    'one-off Cron is scheduled for unattended validation');
check(ESP_Protection_State::boot($setup) === true &&
    get_option('esp_runtime_state') === 'READY' && $setup->checks === 1,
    'first anonymous request verifies and recovers unattended update');
// WordPress CoreのPlugin_Upgrader::bulk_upgrade()は個別インストールに
// type/actionを渡さない。AJAX更新もこの形式を使う。
$bulk_extra = [
    'plugin' => 'easy-slug-protect/easy-slug-protect.php',
    'temp_backup' => ['slug' => 'easy-slug-protect'],
];
$GLOBALS['options']['esp_runtime_state'] = 'READY';
check($pre(true, ['plugin' => 'other-plugin/main.php']) === true &&
    get_option('esp_runtime_state') === 'READY',
    'other plugin in bulk update never changes ESP state');
check($pre(true, ['type'=>'plugin','action'=>'install',
    'plugin'=>'easy-slug-protect/easy-slug-protect.php']) === true &&
    get_option('esp_runtime_state') === 'READY',
    'ESP installation is not mistaken for a plugin upgrade');
check($pre(true, $bulk_extra) === true &&
    get_option('esp_runtime_state') === 'UPDATING',
    'bulk/AJAX update marks ESP UPDATING without type or action');
check($post(true, $bulk_extra, []) === true &&
    get_option('esp_runtime_state') === 'VERIFY_REQUIRED',
    'bulk/AJAX post-install schedules new-code validation');
check(ESP_Protection_State::boot($setup) === true,
    'anonymous traffic after successful bulk upgrade verifies once');

$complete = $GLOBALS['actions']['upgrader_process_complete'][0][0];
// 失敗した一括更新では、shutdownでバックアップが復元されるまで
// 新コード検証を開始してはいけない。
$GLOBALS['options']['esp_runtime_state'] = 'UPDATING';
$complete(null, ['action'=>'update', 'type'=>'plugin',
    'plugins'=>['easy-slug-protect/easy-slug-protect.php']]);
check(get_option('esp_runtime_state') === 'UPDATING',
    'bulk process completion waits for shutdown rollback before verification');
foreach ($GLOBALS['actions']['shutdown'] as $entry) {
    if ($entry[1] === 200) { call_user_func($entry[0]); }
}
check(get_option('esp_runtime_state') === 'VERIFY_REQUIRED',
    'bulk completion at shutdown enables one-shot verification');
check(ESP_Protection_State::boot($setup) === true,
    'bulk failure recovery verifies before reopening site');

$GLOBALS['options']['esp_runtime_state'] = 'UPDATING';
$failed_result = $GLOBALS['filters']['upgrader_install_package_result'][0][0];
$failure = new WP_Error('filesystem_failure', 'Install failed');
check($failed_result($failure, $bulk_extra) === $failure &&
    get_option('esp_runtime_state') === 'UPDATING',
    'failed individual install stays UPDATING until rollback finishes');
foreach ($GLOBALS['actions']['shutdown'] as $entry) {
    if ($entry[1] === 200) { call_user_func($entry[0]); }
}
check(get_option('esp_runtime_state') === 'VERIFY_REQUIRED',
    'failed upgrade schedules verification after WordPress rollback');
check(ESP_Protection_State::boot($setup) === true,
    'rolled-back update resumes only after strict validation');

$GLOBALS['options']['esp_runtime_state'] = 'READY';
$GLOBALS['reject_update'] = true;
$err = $pre(true, $bulk_extra);
check($err instanceof WP_Error && $err->get_error_code() === 'esp_upgrade_pending_unavailable',
    'bulk/AJAX update blocked if UPDATING cannot be durably recorded');
unset($GLOBALS['reject_update']);

$setup->ok = false;
check(ESP_Protection_State::verify_and_commit($setup) === false &&
    get_option('esp_runtime_state') === 'FAILED', 'failed update validation persists FAILED');
$setup->ok = true;
check(ESP_Protection_State::verify_and_commit($setup) === true, 'explicit validation recovers failed update');

ESP_Media_Protection::$audit_ok = false;
$wpdb->queries = 0;
check(ESP_Protection_State::audit() === false, 'Cron audit reports Rewrite mismatch');
check(in_array('rewrite_mismatch', get_option('esp_integrity_last_audit')['errors'], true),
    'Cron persists an actionable non-secret error code');
check(get_option('esp_runtime_state') === 'READY' && ESP_Media_Protection::$checks > 0,
    'read-only Cron does not auto-repair Rewrite or overwrite READY');
ESP_Media_Protection::$audit_ok = true;
check(ESP_Protection_State::audit() === true, 'successful Cron audit persists no errors');

ESP_Protection_State::register_recovery_admin();
$hook = $GLOBALS['actions']['admin_menu'][0][0];
$hook();
check(($GLOBALS['admin_menu']['capability'] ?? null) === 'manage_options',
    'recovery panel requires administrator rights');
ob_start();
call_user_func($GLOBALS['admin_menu']['callback']);
$html = ob_get_clean();
check(strpos($html, 'uploads/.htaccess') !== false &&
    strpos($html, 'esp_verify_runtime') !== false,
    'recovery panel offers explicit CSRF-protected validation');
echo "All protection runtime tests passed.\n";
