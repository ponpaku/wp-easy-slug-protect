<?php
/** Advisory lock tests using two actual MySQL connections, through wpdb-style methods. */
define('ABSPATH', __DIR__ . '/');
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
$a->close();
$c->close();
echo "All verification advisory lock integration tests passed.\n";
