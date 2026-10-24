<?php
/**
 * Failed WordPress activation must not strand public uploads behind ESP Rewrite.
 * Stub the verifier/DB while exercising the real ESP_Setup::activate() flow.
 */
$root = sys_get_temp_dir() . '/esp-activation-failure-' . getmypid();
if (!@mkdir($root . '/wp-admin/includes', 0700, true)) {
    fwrite(STDERR, "Cannot create test fixture directory\n");
    exit(1);
}
file_put_contents($root . '/wp-admin/includes/upgrade.php', '<?php // Stub for activation tests');
register_shutdown_function(static function () use ($root) {
    @unlink($root . '/wp-admin/includes/upgrade.php');
    @rmdir($root . '/wp-admin/includes');
    @rmdir($root . '/wp-admin');
    @rmdir($root);
});
define('ABSPATH', $root . '/');
define('ARRAY_A', 'ARRAY_A');
define('ESP_PATH', dirname(__DIR__) . '/');

class ESP_Config {
    const DB_TABLES = [
        'brute' => 'esp_brute', 'remember' => 'esp_remember',
        'session' => 'esp_session', 'limit' => 'esp_limit',
    ];
    const OPTION_KEY = 'esp_settings';
    const OPTION_DEFAULTS = ['path' => [], 'media' => ['enabled' => true]];
    const DAILY_CLEANUP_HOOK = 'esp_daily_cleanup';
    const INTEGRITY_CHECK_HOOK = 'esp_integrity_check';
}
class ActivationTestDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
    public function prepare($sql, ...$args) { return $sql; }
    public function get_var($sql) { return 'InnoDB'; }
    public function get_col($sql) {
        return ['ip_address', 'path_id', 'window_started', 'attempts',
            'blocked_until', 'updated_at', 'attempt_times', 'last_attempt_token'];
    }
    public function get_results($sql, $output = null) {
        return [
            ['Seq_in_index' => 1, 'Column_name' => 'ip_address'],
            ['Seq_in_index' => 2, 'Column_name' => 'path_id'],
        ];
    }
}
class ESP_Media_Protection {
    public static $records = false;
    public static $cleanup_ok = true;
    public static $calls = [];
    const FOREIGN = "# Other plugin: do not modify\n";
    const ESP_BLOCK = "# BEGIN ESP Media Protection\nRewriteRule ^ media-handler\n# END ESP Media Protection\n";
    public function update_htaccess($rotate = false, $deactivating = false) {
        self::$calls[] = $deactivating ? 'remove' : 'install';
        if ($deactivating) {
            if (!self::$cleanup_ok) { return false; }
            // Represents existing Rewrite updater's ESP-only removal contract.
            $GLOBALS['htaccess'] = str_replace(self::ESP_BLOCK, '', $GLOBALS['htaccess']);
        } else {
            $GLOBALS['htaccess'] = self::FOREIGN . self::ESP_BLOCK;
        }
        return true;
    }
    public function regenerate_media_cache() {}
    public static function protected_media_records_exist() { return self::$records; }
}
class ESP_Filter {
    public function regenerate_protected_posts_cache() {}
}
class ESP_Protection_State {
    public static $verified = false;
    public static $checks = 0;
    public static $activation_compatible = true;
    public static function can_run_activation_migration() {
        return self::$activation_compatible;
    }
    public static function verify_and_commit($setup) {
        self::$checks++;
        return self::$verified;
    }
}
function dbDelta($sql) { $GLOBALS['ddl_count'] = ($GLOBALS['ddl_count'] ?? 0) + 1; return []; }
function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default;
}
function add_option($key, $value) { $GLOBALS['options'][$key] = $value; return true; }
function wp_next_scheduled($name) { return false; }
function wp_schedule_event($timestamp, $recurrence, $hook) { return true; }
function flush_rewrite_rules() {}
function check_activation_failure($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    echo "PASS: {$message}\n";
}
require __DIR__ . '/../includes/class-esp-setup.php';
$wpdb = new ActivationTestDB();
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
// Schema ownership moved to Migrator. This suite exercises only activation's
// failed-Rewrite cleanup; real migration admission is tested against WordPress/MySQL.
class ActivationFlowSetup extends ESP_Setup {
    public function update_check($lock = null) { $GLOBALS['ddl_count']++; return true; }
}
$setup = new ActivationFlowSetup();

function reset_activation_test($records, $verifies, $cleanup_ok = true) {
    $GLOBALS['options'] = [];
    $GLOBALS['htaccess'] = ESP_Media_Protection::FOREIGN;
    ESP_Media_Protection::$calls = [];
    ESP_Media_Protection::$records = $records;
    ESP_Media_Protection::$cleanup_ok = $cleanup_ok;
    ESP_Protection_State::$verified = $verifies;
    ESP_Protection_State::$activation_compatible = true;
    $GLOBALS['ddl_count'] = 0;
}

// M2: 新版のDB版印がある時は、旧ZIPの有効化でDDL等を実行しない。
reset_activation_test(true, false);
ESP_Protection_State::$activation_compatible = false;
$checks_before = ESP_Protection_State::$checks;
check_activation_failure($setup->activate() === false &&
    $GLOBALS['ddl_count'] === 0 &&
    ESP_Media_Protection::$calls === [] &&
    ESP_Protection_State::$checks === $checks_before,
    'M2: stale plugin activation refuses before dbDelta, Rewrite and verifier');

// Initial install: no protected metadata, verifier fails after Rewrite installation.
// An inactive plugin cannot serve PHP-routed public media, so remove only ESP rules.
reset_activation_test(false, false);
check_activation_failure($setup->activate() === false, 'schema verifier failure aborts activation');
check_activation_failure(ESP_Media_Protection::$calls === ['install', 'remove'] &&
    $GLOBALS['htaccess'] === ESP_Media_Protection::FOREIGN,
    'known zero media removes ESP Rewrite and preserves foreign rules');

// Existing protected content must not become direct-public after failed reactivation.
reset_activation_test(true, false);
check_activation_failure($setup->activate() === false &&
    ESP_Media_Protection::$calls === ['install'] &&
    str_contains($GLOBALS['htaccess'], ESP_Media_Protection::ESP_BLOCK),
    'protected media retains Rewrite after failed activation');

// DB errors may make the count unknowable: do not infer that there is no protection.
reset_activation_test(null, false);
check_activation_failure($setup->activate() === false &&
    ESP_Media_Protection::$calls === ['install'] &&
    str_contains($GLOBALS['htaccess'], ESP_Media_Protection::ESP_BLOCK),
    'unknown protection count retains Rewrite after failed activation');

// If cleanup itself cannot complete, failure must not be represented as success.
reset_activation_test(false, false, false);
check_activation_failure($setup->activate() === false &&
    ESP_Media_Protection::$calls === ['install', 'remove'] &&
    str_contains($GLOBALS['htaccess'], ESP_Media_Protection::ESP_BLOCK),
    'failed cleanup does not masquerade as successful activation');

// A successful install preserves the always-route rule for the zero-to-one transition.
reset_activation_test(false, true);
check_activation_failure($setup->activate() === true &&
    ESP_Media_Protection::$calls === ['install'] &&
    str_contains($GLOBALS['htaccess'], ESP_Media_Protection::ESP_BLOCK),
    'successful activation retains Rewrite with zero protected media');

check_activation_failure(ESP_Protection_State::$checks === 5,
    'all activation paths reached the same strict verifier');
echo "All activation failure cleanup tests passed.\n";
