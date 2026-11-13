<?php
/** Standalone policy/HTTP contract regression for Issue #89. */
require_once __DIR__ . '/../includes/class-esp-fast-gate-token.php';
require_once __DIR__ . '/../includes/class-esp-fast-gate-handoff.php';
require_once __DIR__ . '/../includes/class-esp-fast-gate-range.php';
require_once __DIR__ . '/../gate/class-esp-fast-gate-server.php';

function check($condition, $name) {
    if (!$condition) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
}

$site = '1';
$path = 'protected-1';
$ticket_key = random_bytes(32);
$id_key = random_bytes(32);
$relative = '2026/11/example.jpg';
$handoff = ESP_Fast_Gate_Handoff::issue($site, $path, $relative,
    'GET', $ticket_key, 1700000000);
check(is_string($handoff), 'handoff issue');
check(strpos($handoff, base64_encode($relative)) === false &&
    strpos($handoff, $relative) === false, 'protected path not plaintext in URL');
$login_handoff = ESP_Fast_Gate_Handoff::issue($site, $path, $relative,
    'GET', $ticket_key, 1700000000, 'login');
check(ESP_Fast_Gate_Handoff::verify($login_handoff, $site, 'GET',
    $ticket_key, 1700000020)['purpose'] === 'login', 'separate login purpose');
check(ESP_Fast_Gate_Handoff::verify($handoff, $site, 'GET',
    $ticket_key, 1700000020)['file'] === $relative, 'valid handoff');
check(ESP_Fast_Gate_Handoff::verify($handoff, $site, 'HEAD',
    $ticket_key, 1700000020) === null, 'method mismatch');
check(ESP_Fast_Gate_Handoff::verify($handoff, '2', 'GET',
    $ticket_key, 1700000020) === null, 'site mismatch');
check(ESP_Fast_Gate_Handoff::verify($handoff, $site, 'GET',
    $ticket_key, 1700000060) === null, 'expired handoff');
check(ESP_Fast_Gate_Handoff::verify($handoff, $site, 'GET',
    random_bytes(32), 1700000020) === null, 'rotated epoch');
check(ESP_Fast_Gate_Handoff::verify('!' . $handoff, $site, 'GET',
    $ticket_key, 1700000020) === null, 'tamper');
check(ESP_Fast_Gate_Handoff::issue($site, $path, '../x.jpg',
    'GET', $ticket_key) === null, 'traversal');
check(ESP_Fast_Gate_Handoff::issue($site, $path, 'foo%2fbar.jpg',
    'GET', $ticket_key) === null, 'encoded separator');

$cases = [
    ['bytes=0-0', 100, 206, 0, 0],
    ['bytes=5-', 100, 206, 5, 99],
    ['bytes=-2', 100, 206, 98, 99],
    ['bytes=-200', 100, 206, 0, 99],
    ['bytes=999999-', 100, 416],
    ['bytes=70-50', 100, 416],
    ['bytes=-', 100, 416],
    ['bytes=-0', 100, 416],
    ['bytes=0-0,2-2', 100, 416],
    ['bytes=0-0', 0, 416],
    ['bytes=999999999999999999999999-', 100, 416],
];
foreach ($cases as $case) {
    $result = ESP_Fast_Gate_Range::parse($case[0], $case[1]);
    check($result['status'] === $case[2] &&
        (count($case) === 3 || ($result['start'] === $case[3] &&
            $result['end'] === $case[4])), 'range ' . $case[0]);
}
check(ESP_Fast_Gate_Range::parse(null, 100) === null,
    'HEAD ignores range (caller passes null)');

final class BridgeMockLedger {
    public $entry;
    public $path;
    public $revoked = [];
    public $serves = true;
    public function __construct($relative, $id) {
        $this->entry = ['policy' => 'protected', 'path_id' => $id];
        $this->path = ['version' => 1, 'blocked' => false];
    }
    public function serving() { return $this->serves; }
    public function get_media($relative) { return $this->entry; }
    public function get_path($path) { return $this->path; }
    public function is_revoked($ref) { return array_key_exists($ref, $this->revoked) ? $this->revoked[$ref] : false; }
}
$tmp = sys_get_temp_dir() . '/esp-bridge-test-' . bin2hex(random_bytes(5));
mkdir($tmp . '/2026/11', 0700, true);
file_put_contents($tmp . '/' . $relative, 'media');
$ledger = new BridgeMockLedger($relative, $path);
$auth_cookie = str_repeat('A', 64);
$cookies = ['esp_auth_' . $path => $auth_cookie];
$uri = '/wp-content/uploads/' . $relative;
$prefix = '/wp-content/uploads/';
$decision = ESP_Fast_Gate_Server::authorize($uri, $cookies, $site,
    $tmp, $prefix, $ticket_key, $id_key, $ledger);
check($decision['status'] === 307 && $decision['path_id'] === $path,
    'old ESP session enters bridge, never 200');
$none = ESP_Fast_Gate_Server::authorize($uri, [], $site,
    $tmp, $prefix, $ticket_key, $id_key, $ledger);
check($none['status'] === 403, 'anonymous denied');
$direct = ESP_Fast_Gate_Server::authorize($uri, [], $site,
    $tmp, $prefix, $ticket_key, $id_key, $ledger, null,
    'navigate', 'document');
check($direct['status'] === 307 && $direct['purpose'] === 'login',
    'direct navigation login handoff');
$embedded = ESP_Fast_Gate_Server::authorize($uri, [], $site,
    $tmp, $prefix, $ticket_key, $id_key, $ledger, null,
    'no-cors', 'image');
check($embedded['status'] === 403,
    'embedded image never sent login HTML');
$revocation = ESP_Fast_Gate_Token::session_ref($site, $path, 'session',
    '', $auth_cookie, $id_key);
$ledger->revoked[$revocation] = true;
$decision = ESP_Fast_Gate_Server::authorize($uri, $cookies, $site,
    $tmp, $prefix, $ticket_key, $id_key, $ledger);
check($decision['status'] === 403, 'revoked old session cannot bridge');
$ledger->revoked[$revocation] = null;
$decision = ESP_Fast_Gate_Server::authorize($uri, $cookies, $site,
    $tmp, $prefix, $ticket_key, $id_key, $ledger);
check($decision['status'] === 403, 'unknown revocation fails closed');
$ledger->revoked = [];
$gate_ticket = ESP_Fast_Gate_Token::issue($site, $path, 'session',
    '', $auth_cookie, 1, time() + 1000, $ticket_key, $id_key);
$valid = ESP_Fast_Gate_Server::authorize($uri,
    $cookies + ['esp_gate_' . $path => $gate_ticket], $site,
    $tmp, $prefix, $ticket_key, $id_key, $ledger);
check($valid['status'] === 200, 'valid gate ticket direct serve');
$ledger->entry = ['policy'=>'deny','path_id'=>$path];
check(ESP_Fast_Gate_Server::authorize($uri, $cookies, $site,
    $tmp, $prefix, $ticket_key, $id_key, $ledger)['status'] === 403,
    'explicit deny never bridges');
$ledger->entry = null;
check(ESP_Fast_Gate_Server::authorize($uri, $cookies, $site,
    $tmp, $prefix, $ticket_key, $id_key, $ledger)['status'] === 403,
    'unknown media never bridges');
$ledger->entry = ['policy'=>'protected','path_id'=>$path];
$ledger->path['blocked'] = true;
check(ESP_Fast_Gate_Server::authorize($uri, $cookies, $site,
    $tmp, $prefix, $ticket_key, $id_key, $ledger)['status'] === 403,
    'blocked path never bridges');
$ledger->serves = false;
check(ESP_Fast_Gate_Server::authorize($uri, $cookies, $site,
    $tmp, $prefix, $ticket_key, $id_key, $ledger)['status'] === 503,
    'nonserving gate cannot bridge');
unlink($tmp . '/' . $relative);
rmdir($tmp . '/2026/11'); rmdir($tmp . '/2026'); rmdir($tmp);
echo "Fast Gate bridge policy contract PASS\n";
