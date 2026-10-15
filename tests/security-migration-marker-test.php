<?php
/** Missing db_version must not replay destructive legacy path-ID migrations. */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('ESP_PATH', __DIR__ . '/../');
define('ESP_VERSION', '0.7.38');
require __DIR__ . '/../includes/class-esp-config.php';
require __DIR__ . '/../includes/class-esp-option.php';
require __DIR__ . '/../includes/class-esp-protection-state.php';
require __DIR__ . '/../includes/class-esp-setup.php';

$GLOBALS['options'] = [];
function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default;
}
function update_option($name, $value, $autoload = null) {
    $GLOBALS['options'][$name] = $value;
    return true;
}
function check_marker($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    echo "PASS: {$message}\n";
}
class MigrationMarkerDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $schema_complete = true;
    public $writes = 0;
    public function prepare($sql, ...$args) {
        foreach ($args as $arg) {
            $sql = preg_replace('/%s/', "'" . str_replace("'", "''", (string)$arg) . "'", $sql, 1);
        }
        return $sql;
    }
    public function get_col($sql) {
        if (!$this->schema_complete) { return []; }
        return ['ip_address', 'path', 'path_id', 'time', 'password_version',
            'user_id', 'token', 'expires', 'window_started', 'attempts',
            'blocked_until', 'updated_at', 'attempt_times', 'last_attempt_token'];
    }
    public function get_var($sql) {
        return $this->schema_complete ? 'InnoDB' : null;
    }
    public function get_results($sql, $output = null) {
        return $this->schema_complete ? [
            ['Seq_in_index' => 1, 'Column_name' => 'ip_address'],
            ['Seq_in_index' => 2, 'Column_name' => 'path_id'],
        ] : [];
    }
    public function query($sql) {
        $this->writes++;
        throw new RuntimeException('A destructive migration must not execute: ' . $sql);
    }
}
$wpdb = new MigrationMarkerDB();
$setup = new ESP_Setup();
$id = 'path_original_123';
$GLOBALS['options'][ESP_Config::OPTION_KEY] = [
    'path' => [$id => ['id' => $id, 'path' => '/members/', 'password_version' => 1]],
    'media' => ['enabled' => true],
];
$original = serialize($GLOBALS['options'][ESP_Config::OPTION_KEY]);
$protected_media_path_id = $id;
check_marker($setup->update_check() === true,
    'missing db_version with fully migrated schema adopts existing schema');
check_marker(get_option('esp_db_version') === ESP_Config::OPTION_DEFAULTS['db_version'],
    'missing db_version marker restored without applying migrations');
check_marker(serialize($GLOBALS['options'][ESP_Config::OPTION_KEY]) === $original &&
    $protected_media_path_id === $id && $wpdb->writes === 0,
    'existing path ID and attached media reference stay unchanged');

unset($GLOBALS['options']['esp_db_version']);
$wpdb->schema_complete = false;
check_marker($setup->update_check() === false &&
    !array_key_exists('esp_db_version', $GLOBALS['options']) && $wpdb->writes === 0,
    'unknown or incomplete schema is not treated as old version zero');
$wpdb->schema_complete = true;
$GLOBALS['options'][ESP_Config::OPTION_KEY]['path'] = [
    $id => ['id' => 'path_mismatched', 'path' => '/members/'],
];
check_marker($setup->update_check() === false &&
    $wpdb->writes === 0 && get_option('esp_db_version', null) === null,
    'mismatched existing path IDs are never reassigned by legacy migration');
$GLOBALS['options'][ESP_Config::OPTION_KEY]['path'] = [];
check_marker($setup->update_check() === true && $wpdb->writes === 0,
    'fresh installation with no protected paths can adopt verified completed schema');
$GLOBALS['options']['esp_db_version'] = 'untrusted';
check_marker($setup->update_check() === false && $wpdb->writes === 0,
    'unknown db_version string cannot trigger untrusted migrations');
echo "All migration marker compatibility tests passed.\n";
