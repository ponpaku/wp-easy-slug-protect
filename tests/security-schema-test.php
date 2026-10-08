<?php
/** Schema safety checks without a WordPress runtime. Run: php tests/security-schema-test.php */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
class ESP_Config {
    const DB_TABLES = ['limit' => 'esp_login_limits'];
}
function dbDelta($sql) {
    $GLOBALS['schema_create_sql'] = $sql;
    return [];
}
class SchemaWPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $engine = 'InnoDB';
    public $columns = ['ip_address', 'path_id', 'window_started', 'attempts',
                       'blocked_until', 'updated_at', 'attempt_times', 'last_attempt_token'];
    public $primary = ['ip_address', 'path_id'];
    public $fail = false;
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function prepare($sql, ...$args) {
        foreach ($args as $arg) {
            $sql = preg_replace('/%s/', "'" . str_replace("'", "''", $arg) . "'", $sql, 1);
        }
        return $sql;
    }
    public function get_var($sql) {
        $this->last_error = $this->fail ? 'Simulated DB error' : '';
        return $this->engine;
    }
    public function get_col($sql) { return $this->columns; }
    public function get_results($sql, $output = null) {
        return array_map(static function ($column, $seq) {
            return ['Seq_in_index' => $seq + 1, 'Column_name' => $column];
        }, $this->primary, array_keys($this->primary));
    }
}
require __DIR__ . '/../includes/class-esp-setup.php';
function check($value, $message) {
    if (!$value) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . PHP_EOL;
}
$method = new ReflectionMethod('ESP_Setup', 'ensure_login_limits_table');
$setup = new ESP_Setup();
$wpdb = new SchemaWPDB();
check($method->invoke($setup), 'valid InnoDB schema accepted');
check(strpos($GLOBALS['schema_create_sql'], 'ENGINE=InnoDB') !== false, 'schema specifies InnoDB');
check(strpos($GLOBALS['schema_create_sql'], 'attempt_times longtext') !== false, 'rolling timestamp column created');
$wpdb = new SchemaWPDB();
$wpdb->engine = 'MyISAM';
check(!$method->invoke($setup), 'MyISAM is rejected');
$wpdb = new SchemaWPDB();
$wpdb->columns = array_diff($wpdb->columns, ['attempt_times']);
check(!$method->invoke($setup), 'missing rolling timestamp column rejected');
$wpdb = new SchemaWPDB();
$wpdb->primary = ['path_id', 'ip_address'];
check(!$method->invoke($setup), 'wrong primary-key order rejected');
$wpdb = new SchemaWPDB();
$wpdb->primary = ['ip_address'];
check(!$method->invoke($setup), 'non-unique IP/path lock row rejected');
$wpdb = new SchemaWPDB();
$wpdb->fail = true;
check(!$method->invoke($setup), 'schema inspection DB error rejected');
echo 'All schema validation tests passed.' . PHP_EOL;
