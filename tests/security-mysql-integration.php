<?php
/** MySQL/InnoDB concurrency integration test; run only against a disposable DB. */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
class ESP_Config {
    const DB_TABLES = ['limit' => 'login_limits', 'brute' => 'login_attempts'];
}
class ESP_Option {
    public static function get_current_setting($section) {
        return ['attempts_threshold' => 5, 'time_frame' => 10,
                'block_time_frame' => 60, 'whitelist_ips' => ''];
    }
}
class IntegrationWPDB {
    public $prefix = 'esp_test_';
    public $last_error = '';
    private $db;

    public function __construct($db) { $this->db = $db; }
    public function prepare($sql, ...$values) {
        $i = 0;
        return preg_replace_callback('/%[sd]/', function ($m) use ($values, &$i) {
            $value = $values[$i++];
            return $m[0] === '%d' ? (string) (int) $value
                : "'" . $this->db->real_escape_string((string) $value) . "'";
        }, $sql);
    }
    public function query($sql) {
        $result = $this->db->query($sql);
        $this->last_error = $result === false ? $this->db->error : '';
        if ($result === false) { return false; }
        if ($result instanceof mysqli_result) {
            $result->free();
            return 0;
        }
        return $this->db->affected_rows;
    }
    public function get_row($sql, $type = null) {
        $result = $this->db->query($sql);
        $this->last_error = $result === false ? $this->db->error : '';
        if ($result === false) { return null; }
        $row = $result->fetch_assoc();
        $result->free();
        return $row ?: null;
    }
}
require __DIR__ . '/../includes/class-esp-security.php';

mysqli_report(MYSQLI_REPORT_OFF);
function connect_db() {
    $db = new mysqli(
        getenv('ESP_TEST_DB_HOST') ?: '127.0.0.1',
        getenv('ESP_TEST_DB_USER') ?: 'root',
        getenv('ESP_TEST_DB_PASSWORD') ?: '',
        getenv('ESP_TEST_DB_NAME') ?: 'esp_test'
    );
    if ($db->connect_errno) {
        throw new RuntimeException('MySQL connection failed: ' . $db->connect_error);
    }
    return $db;
}
function create_schema($db) {
    $sql = "CREATE TABLE IF NOT EXISTS esp_test_login_limits (
        ip_address varchar(45) NOT NULL,
        path_id varchar(50) NOT NULL,
        window_started bigint unsigned NOT NULL DEFAULT 0,
        attempts int unsigned NOT NULL DEFAULT 0,
        blocked_until bigint unsigned NOT NULL DEFAULT 0,
        updated_at bigint unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (ip_address,path_id)
    ) ENGINE=InnoDB";
    if (!$db->query($sql)) {
        throw new RuntimeException('MySQL setup failed: ' . $db->error);
    }
}
function assert_true($value, $message) {
    if (!$value) { throw new RuntimeException($message); }
}
if (!function_exists('pcntl_fork')) {
    throw new RuntimeException('pcntl is required to exercise parallel requests');
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.52';
$db = connect_db();
create_schema($db);
$engine = $db->query("SHOW TABLE STATUS LIKE 'esp_test_login_limits'")->fetch_assoc()['Engine'];
assert_true(strcasecmp($engine, 'InnoDB') === 0, 'InnoDB is required');
$db->close();

foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
    $db = connect_db();
    $db->query('DELETE FROM esp_test_login_limits');
    $db->close();
    $gate = tempnam(sys_get_temp_dir(), 'esp-gate-');
    unlink($gate);
    $children = [];
    $path = ['id' => 'parallel', 'path' => '/parallel/'];
    for ($i = 0; $i < 20; $i++) {
        $pid = pcntl_fork();
        if ($pid < 0) { throw new RuntimeException('fork failed'); }
        if ($pid === 0) {
            while (!file_exists($gate)) { usleep(1000); }
            $child_db = connect_db();
            $child_db->query('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
            $wpdb = new IntegrationWPDB($child_db);
            $security = new ESP_Security();
            $allowed = $security->can_try_login($path);
            $child_db->close();
            exit($allowed ? 0 : 1);
        }
        $children[] = $pid;
    }
    touch($gate);
    $allowed = 0;
    $denied = 0;
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (!pcntl_wifexited($status)) {
            throw new RuntimeException('child did not terminate normally');
        }
        $code = pcntl_wexitstatus($status);
        if ($code === 0) { $allowed++; }
        elseif ($code === 1) { $denied++; }
        else { throw new RuntimeException('unexpected child exit code'); }
    }
    unlink($gate);
    assert_true($allowed === 5 && $denied === 15, "Incorrect parallel limits under {$isolation}: {$allowed}/{$denied}");

    $db = connect_db();
    $row = $db->query('SELECT attempts, blocked_until FROM esp_test_login_limits')->fetch_assoc();
    assert_true((int) $row['attempts'] === 5, 'counter must be exactly five');
    assert_true((int) $row['blocked_until'] > time() + 3500, 'block must last 60 minutes');
    $db->query('UPDATE esp_test_login_limits SET window_started = ' . (time() - 660));
    $wpdb = new IntegrationWPDB($db);
    $security = new ESP_Security();
    assert_true(!$security->can_try_login($path), '11-minute window expiry must not lift block');
    $db->query('UPDATE esp_test_login_limits SET blocked_until = ' . (time() - 1));
    assert_true($security->can_try_login($path), 'attempt must be permitted after block expires');
    $db->close();
    echo 'PASS MySQL 8 ' . $isolation . ': 20 concurrent requests, block lifetime and reset' . PHP_EOL;
}
echo 'All MySQL concurrency tests passed.' . PHP_EOL;
