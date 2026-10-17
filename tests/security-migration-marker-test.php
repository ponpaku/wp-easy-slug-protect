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
    public $missing_session_created = false;
    public $writes = 0;
    public function prepare($sql, ...$args) {
        foreach ($args as $arg) {
            $sql = preg_replace('/%s/', "'" . str_replace("'", "''", (string)$arg) . "'", $sql, 1);
        }
        return $sql;
    }
    public function get_col($sql) {
        if (!$this->schema_complete) { return []; }
        $columns = ['id', 'ip_address', 'path', 'path_id', 'time', 'password_version',
            'user_id', 'token', 'created', 'expires', 'window_started', 'attempts',
            'blocked_until', 'updated_at', 'attempt_times', 'last_attempt_token'];
        if ($this->missing_session_created && strpos($sql, 'esp_login_session') !== false) {
            return array_values(array_diff($columns, ['created']));
        }
        return $columns;
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

// 版印が古いだけの完全移行済みサイトでもIDを書き換えない。
$GLOBALS['options']['esp_db_version'] = 0;
check_marker($setup->update_check() === true &&
    get_option('esp_db_version') === ESP_Config::OPTION_DEFAULTS['db_version'] &&
    serialize($GLOBALS['options'][ESP_Config::OPTION_KEY]) === $original &&
    $wpdb->writes === 0,
    'stale version-zero marker on completed schema preserves existing path IDs');

// V1の途中でID付き・旧形式のパスが混在しても、IDを再発行しない。
$GLOBALS['options']['esp_db_version'] = 0;
$GLOBALS['options'][ESP_Config::OPTION_KEY]['path'][0] = ['path' => '/legacy/'];
$mixed_paths = serialize($GLOBALS['options'][ESP_Config::OPTION_KEY]['path']);
check_marker($setup->update_check() === false &&
    $GLOBALS['options']['esp_db_version'] === 0 &&
    serialize($GLOBALS['options'][ESP_Config::OPTION_KEY]['path']) === $mixed_paths &&
    $wpdb->writes === 0,
    'mixed V1 and legacy paths refuse destructive ID reassignment');
unset($GLOBALS['options'][ESP_Config::OPTION_KEY]['path'][0]);

// 重要な認証列を欠いたスキーマは、版印を復元する根拠にできない。
unset($GLOBALS['options']['esp_db_version']);
$wpdb->missing_session_created = true;
check_marker($setup->update_check() === false &&
    get_option('esp_db_version', null) === null && $wpdb->writes === 0,
    'missing session created column rejects completed-schema classification');
$wpdb->missing_session_created = false;

unset($GLOBALS['options']['esp_db_version']);
$wpdb->schema_complete = false;
check_marker($setup->update_check() === false &&
    !array_key_exists('esp_db_version', $GLOBALS['options']) && $wpdb->writes === 0,
    'unknown or incomplete schema is not treated as old version zero');
// 旧版の移行が部分的に終わりパスIDだけ存在する場合も、
 // V1処理を再適用せず管理者による復旧を待つ。
$GLOBALS['options']['esp_db_version'] = 0;
check_marker($setup->update_check() === false && $wpdb->writes === 0 &&
    $GLOBALS['options'][ESP_Config::OPTION_KEY]['path'][$id]['id'] === $id,
    'incomplete schema with existing IDs refuses destructive re-migration');
unset($GLOBALS['options']['esp_db_version']);
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
