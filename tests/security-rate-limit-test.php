<?php
/**
 * Run: php tests/security-rate-limit-test.php
 * Mocked wpdb state machine; real MySQL concurrency still needs an integration test.
 */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
class ESP_Config {
    const OPTION_DEFAULTS = ['db_version' => 6];
    const DB_TABLES = ['limit' => 'esp_login_limits', 'brute' => 'esp_login_attempts'];
}
function get_option($key, $default = null) {
    return $GLOBALS['mock_esp_db_version'] ?? 6;
}
class ESP_Option {
    public static function get_current_setting($name) {
        return ['attempts_threshold' => 5, 'time_frame' => 10,
                'block_time_frame' => 60, 'whitelist_ips' => ''];
    }
}
class ESP_Mail {
    public static $count = 0;
    public static function get_instance() { return new self(); }
    public function notify_brute_force_attempt($ip, $path, $attempts) { self::$count++; }
}
class FakeWPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $row = null;
    public $fail = '';
    public $throws = '';
    public $rollbacks = 0;
    private $snapshot = null;

    public function prepare($sql, ...$args) {
        $i = 0;
        return preg_replace_callback('/%[sd]/', static function ($m) use (&$i, $args) {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : "'" . str_replace("'", "''", (string) $v) . "'";
        }, $sql);
    }
    private function failure($query) {
        if ($this->throws && strpos($query, $this->throws) !== false) {
            throw new RuntimeException('Simulated query exception');
        }
        if ($this->fail && strpos($query, $this->fail) !== false) {
            $this->last_error = 'Simulated database failure';
            return true;
        }
        $this->last_error = '';
        return false;
    }
    public function query($sql) {
        if ($this->failure($sql)) { return false; }
        if (strpos($sql, 'INSERT IGNORE') !== false) {
            if ($this->row !== null) { return 0; }
            $this->row = ['attempts' => 0, 'window_started' => time(),
                          'blocked_until' => 0, 'attempt_times' => null, 'last_attempt_token' => ''];
            return 1;
        }
        if ($sql === 'START TRANSACTION') {
            $this->snapshot = $this->row;
            return 0;
        }
        if ($sql === 'ROLLBACK') {
            $this->row = $this->snapshot;
            $this->rollbacks++;
            return 0;
        }
        if ($sql === 'COMMIT') { return 0; }
        if (strpos($sql, 'UPDATE') !== false) {
            if (preg_match("/SET attempts = (\\d+), window_started = (\\d+), blocked_until = (\\d+), updated_at = \\d+, attempt_times = '([^']+)', last_attempt_token = '([^']+)'/", $sql, $m)) {
                $this->row['attempts'] = (int) $m[1];
                $this->row['window_started'] = (int) $m[2];
                $this->row['blocked_until'] = (int) $m[3];
                $this->row['attempt_times'] = $m[4];
                $this->row['last_attempt_token'] = $m[5];
                return 1;
            }
            if (preg_match("/SET attempts = 0, blocked_until = 0, window_started = (\\d+), updated_at = \\d+, attempt_times = '\\[\\]', last_attempt_token = '' WHERE .* last_attempt_token = '([^']+)'/", $sql, $m)) {
                if ($this->row['last_attempt_token'] !== $m[2]) { return 0; }
                $this->row['attempts'] = 0;
                $this->row['blocked_until'] = 0;
                $this->row['window_started'] = (int) $m[1];
                $this->row['attempt_times'] = '[]';
                $this->row['last_attempt_token'] = '';
                return 1;
            }
            throw new RuntimeException('Unexpected UPDATE in FakeWPDB: ' . $sql);
        }
        return 1;
    }
    public function get_row($sql, $mode = null) {
        if ($this->failure($sql)) { return null; }
        return $this->row;
    }
    public function insert($table, $data, $formats) {
        $this->last_error = '';
        return 1;
    }
}
require __DIR__ . '/../includes/class-esp-security.php';
function check($value, $label) {
    if (!$value) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'PASS: ' . $label . PHP_EOL;
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.52';
$path = ['id' => 'protected', 'path' => '/protected/'];
$wpdb = new FakeWPDB();
$security = new ESP_Security();
for ($i = 0; $i < 5; $i++) {
    check($security->can_try_login($path), 'reservation ' . ($i + 1));
    $security->record_failed_attempt($path);
}
check(!$security->can_try_login($path), 'sixth attempt rejected');
check($wpdb->row['blocked_until'] > time() + 3500, '60-minute block survives window');
check(ESP_Mail::$count === 1, 'threshold notification sent once');
$wpdb->row['window_started'] = time() - 660;
check(!$security->can_try_login($path), 'still blocked after 11 minutes');
$wpdb->row['blocked_until'] = time() - 1;
check($security->can_try_login($path), 'allowed after block expiry');
check($wpdb->row['attempts'] === 1, 'counter restarts after expiry');
$security->reset_successful_attempts($path);
check($wpdb->row['attempts'] === 0, 'success clears counter');
$wpdb = new FakeWPDB();
$now = time();
// Oldest event belongs to the previous fixed window. Four recent ones must
// still be counted even though window_started has already expired.
$wpdb->row = ['attempts' => 4, 'window_started' => $now - 601,
              'blocked_until' => 0, 'attempt_times' => json_encode([$now - 599, $now - 598, $now - 597, $now - 596]),
              'last_attempt_token' => 'earlier'];
$security = new ESP_Security();
check($security->can_try_login($path), 'rolling window counts four recent reservations across old boundary');
check($wpdb->row['attempts'] === 5, 'rolling threshold reached without fixed-window reset');
check(!$security->can_try_login($path), 'rolling threshold rejects sixth attempt');

// Simulate a v0.7.36 row immediately after its v6 schema upgrade: the new
// timestamp column is NULL, but existing attempts and blocks still matter.
$wpdb = new FakeWPDB();
$wpdb->row = ['attempts' => 4, 'window_started' => time() - 30, 'blocked_until' => 0,
              'attempt_times' => null, 'last_attempt_token' => ''];
$security = new ESP_Security();
check($security->can_try_login($path), 'v5 unexpired reservations inherited on v6 upgrade');
check($wpdb->row['attempts'] === 5, 'v5 reservations reach threshold without being dropped');
$wpdb = new FakeWPDB();
$wpdb->row = ['attempts' => 5, 'window_started' => time() - 660,
              'blocked_until' => time() + 3600, 'attempt_times' => null,
              'last_attempt_token' => ''];
check(!(new ESP_Security())->can_try_login($path), 'v5 active block preserved after v6 upgrade');

$wpdb = new FakeWPDB();
$first = new ESP_Security();
$second = new ESP_Security();
check($first->can_try_login($path), 'first concurrent reservation');
check($second->can_try_login($path), 'later concurrent reservation');
$first->reset_successful_attempts($path);
check($wpdb->row['attempts'] === 2, 'earlier success preserves later reservation');
$second->record_failed_attempt($path);
check($wpdb->row['attempts'] === 2, 'later failure remains counted');
$second->reset_successful_attempts($path);
check($wpdb->row['attempts'] === 0, 'latest successful login can reset');

$wpdb = new FakeWPDB();
$wpdb->row = ['attempts' => 2, 'window_started' => $now, 'blocked_until' => 0,
              'attempt_times' => 'not json', 'last_attempt_token' => ''];
check(!(new ESP_Security())->can_try_login($path), 'invalid stored timestamps fail closed');
$GLOBALS['mock_esp_db_version'] = 5;
check(!(new ESP_Security())->can_try_login($path), 'incomplete DB migration denies login');
$GLOBALS['mock_esp_db_version'] = 6;
foreach (['INSERT IGNORE', 'START TRANSACTION', 'SELECT attempts', 'UPDATE', 'COMMIT'] as $stage) {
    $wpdb = new FakeWPDB();
    $wpdb->fail = $stage;
    $security = new ESP_Security();
    check(!$security->can_try_login($path), 'database failure denies: ' . $stage);
}
$wpdb = new FakeWPDB();
$wpdb->throws = 'SELECT attempts';
$security = new ESP_Security();
check(!$security->can_try_login($path), 'exception denies access');
check($wpdb->rollbacks === 1, 'exception triggers rollback');
echo 'All isolated rate-limit tests passed.' . PHP_EOL;
