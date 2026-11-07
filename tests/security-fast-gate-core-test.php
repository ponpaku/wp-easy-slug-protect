<?php
/* Fast Gate v2 core regressions: independent of WordPress and MySQL. */
require_once __DIR__ . '/../includes/class-esp-fast-gate-token.php';
require_once __DIR__ . '/../includes/class-esp-fast-gate-store.php';
require_once __DIR__ . '/../gate/class-esp-fast-gate-server.php';

function fg_assert($ok, $message) {
    if (!$ok) { throw new RuntimeException($message); }
}
$site = '1';
$path = 'path_7';
$token = str_repeat('t', 64);
$sig = str_repeat('S', 64);
$idkey = str_repeat('I', 64);
$now = 1800000000;
$expires = $now + 50000;
$normal = ['esp_auth_' . $path => $token];
$ticket = ESP_Fast_Gate_Token::issue($site, $path, 'session', '', $token,
    3, $expires, $sig, $idkey, $now);
fg_assert(is_string($ticket), 'issue');
$verified = ESP_Fast_Gate_Token::verify($ticket, $site, $path, $normal,
    3, $sig, $idkey, $now + 1);
fg_assert(is_array($verified), 'verify valid');
$ref = $verified['ref'];
fg_assert(ESP_Fast_Gate_Token::verify($ticket, $site, $path, $normal,
    4, $sig, $idkey, $now + 1) === null, 'password version revokes old');
fg_assert(ESP_Fast_Gate_Token::verify($ticket, $site, 'path_8', $normal,
    3, $sig, $idkey, $now + 1) === null, 'path scope');
fg_assert(ESP_Fast_Gate_Token::verify($ticket, '2', $path, $normal,
    3, $sig, $idkey, $now + 1) === null, 'site scope');
fg_assert(ESP_Fast_Gate_Token::verify($ticket, $site, $path,
    ['esp_auth_' . $path => str_repeat('x', 64)],
    3, $sig, $idkey, $now + 1) === null, 'normal token binding');
fg_assert(ESP_Fast_Gate_Token::verify($ticket, $site, $path, [],
    3, $sig, $idkey, $now + 1) === null, 'ticket alone unusable');
fg_assert(ESP_Fast_Gate_Token::verify($ticket, $site, $path, $normal,
    3, $sig, $idkey, $now + 86401) === null, 'expire');
$chunks = explode('.', $ticket);
$tampered = $chunks[0] . '.' . str_repeat('A', strlen($chunks[1]));
fg_assert(ESP_Fast_Gate_Token::verify($tampered, $site, $path, $normal,
    3, $sig, $idkey, $now + 1) === null, 'signature tampering');
fg_assert(ESP_Fast_Gate_Token::verify($ticket, $site, $path, $normal,
    3, str_repeat('Z', 64), $idkey, $now + 1) === null, 'key rotation');
fg_assert(ESP_Fast_Gate_Token::issue($site, $path, 'session', '', $token,
    3, $now, $sig, $idkey, $now) === null, 'expired MySQL session');
fg_assert(ESP_Fast_Gate_Token::issue($site, $path, 'session', '', 'weak',
    3, $expires, $sig, $idkey, $now) === null, 'weak token rejected');
fg_assert(ESP_Fast_Gate_Token::session_ref($site, $path, 'session', '', $token,
    $idkey) === $ref, 'stable ref');
fg_assert(ESP_Fast_Gate_Token::session_ref($site, $path, 'remember', 'u1', $token,
    $idkey) !== $ref, 'kind and user domain separation');

$remember = [
    'esp_remember_id_' . $path => 'user_1',
    'esp_remember_token_' . $path => $token,
];
$remTicket = ESP_Fast_Gate_Token::issue($site, $path, 'remember', 'user_1',
    $token, 3, $expires, $sig, $idkey, $now);
fg_assert(is_array(ESP_Fast_Gate_Token::verify($remTicket, $site, $path,
    $remember, 3, $sig, $idkey, $now + 1)), 'remember valid');
$badRemember = $remember;
$badRemember['esp_remember_id_' . $path] = 'user_2';
fg_assert(ESP_Fast_Gate_Token::verify($remTicket, $site, $path,
    $badRemember, 3, $sig, $idkey, $now + 1) === null, 'remember user binding');

if (!class_exists('SQLite3')) {
    echo "Fast Gate token tests OK; SQLite3 store tests skipped (extension unavailable)\n";
    exit(0);
}

$file = tempnam(sys_get_temp_dir(), 'esp-fg-');
fg_assert(is_string($file), 'temp DB');
try {
    $db = ESP_Fast_Gate_Store::open($file, true);
    fg_assert($db !== null && $db->install(), 'install');
    fg_assert(!$db->ready(), 'not ready until audited');
    fg_assert($db->set_path($path, 3), 'set path');
    fg_assert($db->replace_media([
        '2026/11/a.jpg' => ['policy' => 'public', 'path_id' => ''],
        '2026/11/b.jpg' => ['policy' => 'protected', 'path_id' => $path],
        '2026/11/c.jpg' => ['policy' => 'deny', 'path_id' => ''],
    ]), 'replace media');
    fg_assert($db->get_media('2026/11/a.jpg') === null, 'not ready no public');
    fg_assert($db->set_ready(true), 'mark ready');
    fg_assert($db->get_media('2026/11/a.jpg')['policy'] === 'public', 'public');
    fg_assert($db->get_media('2026/11/b.jpg')['path_id'] === $path, 'protected');
    fg_assert($db->get_media('2026/11/c.jpg')['policy'] === 'deny', 'deny');
    fg_assert($db->get_media('2026/11/unknown.jpg') === null, 'missing not public');
    fg_assert($db->is_revoked($ref) === false, 'not revoked');

    $root = sys_get_temp_dir() . '/esp-gate-media-' . bin2hex(random_bytes(6));
    mkdir($root . '/2026/11', 0700, true);
    file_put_contents($root . '/2026/11/a.jpg', 'public image');
    file_put_contents($root . '/2026/11/b.jpg', 'protected image');
    file_put_contents($root . '/2026/11/c.jpg', 'denied image');
    $prefix = '/wp-content/uploads/';
    $authCookies = $normal;
    $authCookies['esp_gate_' . $path] = $ticket;
    $checked = ESP_Fast_Gate_Server::authorize($prefix . '2026/11/b.jpg',
        $authCookies, $site, $root, $prefix, $sig, $idkey, $db, $now + 1);
    fg_assert($checked['status'] === 200 && $checked['protected'] === true,
        'standalone protected gate');
    fg_assert(ESP_Fast_Gate_Server::authorize($prefix . '2026/11/b.jpg',
        [], $site, $root, $prefix, $sig, $idkey, $db, $now + 1)['status']
        === 403, 'no cookie cannot access protected');
    fg_assert(ESP_Fast_Gate_Server::authorize($prefix . '2026/11/a.jpg',
        [], $site, $root, $prefix, $sig, $idkey, $db, $now + 1)['status']
        === 200, 'verified public');
    fg_assert(ESP_Fast_Gate_Server::authorize($prefix . '2026/11/c.jpg',
        $authCookies, $site, $root, $prefix, $sig, $idkey, $db, $now + 1)['status']
        === 403, 'deny row');
    fg_assert(ESP_Fast_Gate_Server::authorize($prefix . '2026/11/z.jpg',
        $authCookies, $site, $root, $prefix, $sig, $idkey, $db, $now + 1)['status']
        === 403, 'unknown must not be public');
    foreach (['../2026/11/b.jpg','%252e%252e/b.jpg','2026/%2f/b.jpg',
             '2026/11/.hidden.jpg'] as $invalid) {
        fg_assert(ESP_Fast_Gate_Server::authorize($prefix . $invalid,
            $authCookies, $site, $root, $prefix, $sig, $idkey, $db, $now + 1)['status']
            !== 200, 'unsafe URL rejected: ' . $invalid);
    }


    // Persistence before WordPress DB deletion; crash/reopen must not lose it.
    fg_assert($db->revoke_many([$ref, $verified['ref']], $now), 'revoke atomic');
    fg_assert($db->is_revoked($ref) === true, 'revoked');
    fg_assert(ESP_Fast_Gate_Server::authorize($prefix . '2026/11/b.jpg',
        $authCookies, $site, $root, $prefix, $sig, $idkey, $db, $now + 1)['status']
        === 403, 'stolen old cookies denied after logout');

    fg_assert($db->gc($now + 1000000), 'gc before DB confirmation');
    fg_assert($db->is_revoked($ref) === true, 'unconfirmed cannot GC');
    unset($db);
    $reader = ESP_Fast_Gate_Store::open($file, false);
    fg_assert($reader !== null && $reader->is_revoked($ref) === true,
        'read-only reopening retains revocation');
    unset($reader);
    $db = ESP_Fast_Gate_Store::open($file, true);
    fg_assert($db->confirm_deleted($ref), 'confirm DB deletion');
    fg_assert($db->gc($now + 30), 'early GC');
    fg_assert($db->is_revoked($ref) === true, 'not before ticket max age');
    fg_assert($db->gc($now + ESP_Fast_Gate_Token::MAX_AGE + 400),
        'late GC');
    fg_assert($db->is_revoked($ref) === false, 'safe expired GC');
    fg_assert($db->block_path($path), 'preemptive block');
    fg_assert($db->get_path($path)['blocked'] === true, 'blocked');
    fg_assert($db->set_path($path, 3), 'stale same-generation write');
    fg_assert($db->get_path($path)['blocked'] === true,
        'stale callback must not release a pre-save block');
    fg_assert($db->set_path($path, 4), 'publish next generation');
    fg_assert($db->get_path($path)['version'] === 4, 'new version');
    fg_assert(!$db->get_path($path)['blocked'], 'unblocked after verification');
    fg_assert($db->replace_media([
        '2026/11/b.jpg' => ['policy' => 'protected', 'path_id' => $path],
    ]), 'rebuild');
    fg_assert(!$db->ready() && $db->get_media('2026/11/b.jpg') === null,
        'rebuild must disable gate');
    echo "Fast Gate token and SQLite ledger tests OK\n";
} finally {
    unset($db);
    foreach ([$file, $file . '-wal', $file . '-shm'] as $item) {
        if (file_exists($item)) { unlink($item); }
    }
    if (isset($root) && is_dir($root)) {
        foreach (['a.jpg','b.jpg','c.jpg'] as $name) {
            @unlink($root . '/2026/11/' . $name);
        }
        @rmdir($root . '/2026/11');
        @rmdir($root . '/2026');
        @rmdir($root);
    }

}
