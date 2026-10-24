<?php
/** Advisory lock tests using two actual MySQL connections, through wpdb-style methods. */
define('ABSPATH', __DIR__ . '/');
define('ESP_VERSION', '0.7.39');
class ESP_Config {
    const VERSION_OPTION_KEY = 'esp_plugin_version';
}
require __DIR__ . '/../includes/class-esp-protection-state.php';

mysqli_report(MYSQLI_REPORT_OFF);
function db_connect() {
    $db = new mysqli(
        getenv('ESP_TEST_DB_HOST') ?: '127.0.0.1',
        getenv('ESP_TEST_DB_USER') ?: 'root',
        getenv('ESP_TEST_DB_PASSWORD') ?: '',
        getenv('ESP_TEST_DB_NAME') ?: 'esp_test'
    );
    if ($db->connect_errno) {
        throw new RuntimeException('Cannot connect to test database');
    }
    return $db;
}
class VerificationTestWPDB {
    public $prefix = 'esp_test_';
    public $last_error = '';
    private $connection;
    public function __construct($db) { $this->connection = $db; }
    public function prepare($sql, ...$args) {
        $index = 0;
        return preg_replace_callback('/%[sd]/', function ($part) use ($args, &$index) {
            $value = $args[$index++];
            return $part[0] === '%d' ? (string) (int)$value
                : "'" . $this->connection->real_escape_string((string)$value) . "'";
        }, $sql);
    }
    public function get_var($query) {
        $result = $this->connection->query($query);
        $this->last_error = $result === false ? $this->connection->error : '';
        if ($result === false) { return null; }
        $row = $result->fetch_row();
        $result->free();
        return $row[0] ?? null;
    }
    public function query($sql) {
        $result = $this->connection->query($sql);
        $this->last_error = $result === false ? $this->connection->error : '';
        return $result !== false;
    }
}
function get_option($name, $default = null) {
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare(
        'SELECT option_value FROM esp_test_runtime_option WHERE option_name = %s', $name));
    return $value === null ? $default : unserialize($value);
}
function update_option($name, $value, $autoload = null) {
    global $wpdb;
    return $wpdb->query($wpdb->prepare(
        'INSERT INTO esp_test_runtime_option (option_name, option_value) VALUES (%s, %s) ' .
        'ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
        $name, serialize($value)));
}
function verify($ok, $message) {
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS: {$message}\n";
}
$class = new ReflectionClass('ESP_Protection_State');
$acquire = $class->getMethod('acquire_verification_lock');
$owns = $class->getMethod('owns_verification_lock');
$release = $class->getMethod('release_verification_lock');
$a = db_connect();
$b = db_connect();
$wpdb = new VerificationTestWPDB($a);
$lockA = $acquire->invoke(null);
verify(is_array($lockA), 'first DB connection obtains verification lock');
$wpdb = new VerificationTestWPDB($b);
verify($acquire->invoke(null) === null, 'second DB connection cannot acquire active lock');
verify($owns->invoke(null, $lockA) === false, 'other connection is not the owner');
$release->invoke(null, $lockA);
verify($acquire->invoke(null) === null, 'non-owner release cannot free active lock');
$wpdb = new VerificationTestWPDB($a);
verify($owns->invoke(null, $lockA), 'original connection remains lock owner');
$release->invoke(null, $lockA);
$wpdb = new VerificationTestWPDB($b);
$lockB = $acquire->invoke(null);
verify(is_array($lockB), 'waiting connection obtains lock once previous owner releases');
$wpdb = new VerificationTestWPDB($a);
$release->invoke(null, $lockB);
$wpdb = new VerificationTestWPDB($b);
verify($owns->invoke(null, $lockB), 'previous owner cannot release new owner lock');
$b->close();
$c = db_connect();
$wpdb = new VerificationTestWPDB($c);
$lockC = $acquire->invoke(null);
verify(is_array($lockC), 'server frees advisory lock when original DB connection closes');
$release->invoke(null, $lockC);

// 状態の書込みも同じGET_LOCKを使う。2つの実MySQL接続で競合を再現。
verify($a->query("CREATE TABLE IF NOT EXISTS esp_test_runtime_option (
    option_name varchar(191) NOT NULL PRIMARY KEY,
    option_value longtext NOT NULL
) ENGINE=InnoDB"), 'create isolated runtime-state test options table');
$set = $class->getMethod('set_runtime_state');
$wpdb = new VerificationTestWPDB($a);
verify(update_option('esp_runtime_state',
    ['state' => 'READY', 'version' => ESP_VERSION]), 'seed current READY state');
verify(update_option('esp_plugin_version', ESP_VERSION), 'seed current plugin version');
$lockA = $acquire->invoke(null);
verify(is_array($lockA), 'first connection holds writer lock before state transition');
$wpdb = new VerificationTestWPDB($c);
verify($set->invoke(null, 'UPDATING') === false &&
    get_option('esp_runtime_state')['state'] === 'READY',
    'second connection cannot change READY while first connection holds DB lock');
$wpdb = new VerificationTestWPDB($a);
$release->invoke(null, $lockA);
$wpdb = new VerificationTestWPDB($c);
verify($set->invoke(null, 'UPDATING') === true &&
    get_option('esp_runtime_state')['state'] === 'UPDATING',
    'second connection commits UPDATING after owner releases lock');
$wpdb = new VerificationTestWPDB($a);
verify($set->invoke(null, 'VERIFY_REQUIRED', null, 'UPDATING') === true &&
    get_option('esp_runtime_state')['state'] === 'VERIFY_REQUIRED',
    'shutdown transition validates expected state while holding lock');
verify(update_option('esp_runtime_state',
    ['state' => 'READY', 'version' => '0.7.40']), 'seed newer verified version');
$wpdb = new VerificationTestWPDB($c);
verify($set->invoke(null, 'FAILED') === false &&
    get_option('esp_runtime_state') === ['state' => 'READY', 'version' => '0.7.40'],
    'older worker cannot overwrite a later READY from another connection');
$wpdb = new VerificationTestWPDB($a);
verify(update_option('esp_runtime_state',
    ['state' => 'READY', 'version' => ESP_VERSION]), 'reset for late shutdown test');
$wpdb = new VerificationTestWPDB($c);
verify($set->invoke(null, 'VERIFY_REQUIRED', null, 'UPDATING') === false &&
    get_option('esp_runtime_state')['state'] === 'READY',
    'late shutdown cannot move verified READY backwards');
$a->query('DROP TABLE esp_test_runtime_option');
$a->close();
$c->close();
echo "All verification advisory lock integration tests passed.\n";
