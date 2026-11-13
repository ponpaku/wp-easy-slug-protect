<?php
/**
 * Standalone Fast Gate v2 entrypoint. Absolutely no WordPress bootstrap.
 *
 * NOT wired into .htaccess/Nginx automatically. The HTTP routing and update
 * synchronization must be reviewed before this endpoint is used in production.
 * Uses the plugin-owned wp-content/esp-fast-gate-v2 directory, no user path input.
 */
ini_set('display_errors', '0');
require_once __DIR__ . '/../includes/class-esp-fast-gate-token.php';
require_once __DIR__ . '/../includes/class-esp-fast-gate-handoff.php';
require_once __DIR__ . '/../includes/class-esp-fast-gate-range.php';
require_once __DIR__ . '/../includes/class-esp-fast-gate-store.php';
require_once __DIR__ . '/class-esp-fast-gate-server.php';

function esp_fast_gate_status($code) {
    http_response_code($code);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    exit;
}
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method !== 'GET' && $method !== 'HEAD') {
        header('Allow: GET, HEAD');
        esp_fast_gate_status(405);
    }
    // Standard plugin layout only; no environment variable or user input.
    $dir = dirname(__DIR__, 3) . '/esp-fast-gate-v2';
    $private = realpath($dir);
    if (!$private || $private !== $dir || !is_dir($private) || is_link($dir)) {
        esp_fast_gate_status(503);
    }
    $keyfile = $private . '/keys.php';
    if (!is_file($keyfile) || is_link($keyfile) || !is_readable($keyfile)) {
        esp_fast_gate_status(503);
    }
    $keys = require $keyfile;
    if (!is_array($keys) ||
        !is_string($keys['site'] ?? null) ||
        !is_string($keys['sig'] ?? null) || strlen($keys['sig']) < 32 ||
        !is_string($keys['id'] ?? null) || strlen($keys['id']) < 32 ||
        !is_string($keys['root'] ?? null) ||
        !is_string($keys['prefix'] ?? null)) {
        esp_fast_gate_status(503);
    }
    // Authentication-only installation probe. It returns NO media bytes and
    // no secret; a random nonce must be authenticated with a site-wide HMAC.
    // The admin verifier needs this to prove Apache routed an uploads URL
    // to THIS standalone PHP script rather than to WordPress or static files.
    $probe = $_SERVER['HTTP_X_ESP_GATE_ROUTE_PROBE'] ?? null;
    if (is_string($probe) &&
        preg_match('/^([a-f0-9]{32}):([a-f0-9]{64})$/D', $probe, $parts) &&
        hash_equals(hash_hmac('sha256', 'route|' . $parts[1], $keys['sig']),
            $parts[2])) {
        http_response_code(204);
        header('Cache-Control: private, no-store');
        header('X-ESP-Gate-Route-Ack: ' .
            hash_hmac('sha256', 'ack|' . $parts[1], $keys['sig']));
        header('X-Content-Type-Options: nosniff');
        exit;
    }
    $store = ESP_Fast_Gate_Store::open($private . '/gate-ledger.sqlite', false);
    $ticket_signing_key = $store ? $store->ticket_signing_key_strict($keys['sig']) : null;
    if ($ticket_signing_key === null) { esp_fast_gate_status(503); }
    $decision = ESP_Fast_Gate_Server::authorize(
        $_SERVER['REQUEST_URI'] ?? '', $_COOKIE, $keys['site'],
        $keys['root'], $keys['prefix'], $ticket_signing_key, $keys['id'], $store,
        null, $_SERVER['HTTP_SEC_FETCH_MODE'] ?? null,
        $_SERVER['HTTP_SEC_FETCH_DEST'] ?? null
    );
    if ($decision['status'] === 307) {
        $bridge_path = $store->bridge_path();
        if ($bridge_path === null) { esp_fast_gate_status(503); }
        $handoff = ESP_Fast_Gate_Handoff::issue(
            $keys['site'], $decision['path_id'], $decision['relative'],
            $method, $ticket_signing_key, null,
            $decision['purpose'] ?? 'auth');
        if ($handoff === null) { esp_fast_gate_status(503); }
        // Location is an origin-relative configured path. Never use Host.
        header('Location: ' . $bridge_path . '?esp_gate_bridge=' . $handoff,
            true, 307);
        header('Cache-Control: private, no-store, max-age=0');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        exit;
    }
    if ($decision['status'] !== 200) {
        esp_fast_gate_status($decision['status']);
    }

    $file = $decision['file'];
    $handle = @fopen($file, 'rb');
    $length = $handle ? @filesize($file) : false;
    if (!$handle || !is_int($length) || $length < 0) {
        if ($handle) { fclose($handle); }
        esp_fast_gate_status(503);
    }
    // RFC 9110: Range applies to GET only. HEAD ignores it.
    $range_header = $method === 'GET' && isset($_SERVER['HTTP_RANGE'])
        ? (string)$_SERVER['HTTP_RANGE'] : null;
    $range = ESP_Fast_Gate_Range::parse($range_header, $length);
    if ($range !== null && $range['status'] !== 206) {
        fclose($handle);
        header('Content-Range: bytes */' . $length);
        esp_fast_gate_status(416);
    }
    $partial = $range !== null;
    $start = $partial ? $range['start'] : 0;
    $end = $partial ? $range['end'] : ($length > 0 ? $length - 1 : 0);

    http_response_code($partial ? 206 : 200);
    header('Content-Type: ' . ESP_Fast_Gate_Server::content_type($file));
    header('X-Content-Type-Options: nosniff');
    // Public files also use no-store: they may become protected shortly after.
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Accept-Ranges: bytes');
    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'svg') {
        header("Content-Security-Policy: sandbox; default-src 'none'");
    }
    if ($partial) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $length);
    }
    header('Content-Length: ' . ($partial ? $end - $start + 1 : $length));
    if ($method === 'HEAD') {
        fclose($handle);
        exit;
    }
    if ($start > 0) { fseek($handle, $start); }
    $remaining = $partial ? $end - $start + 1 : $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(65536, $remaining));
        if ($chunk === false || $chunk === '') { break; }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($handle);
} catch (\Throwable $e) {
    if (!headers_sent()) { esp_fast_gate_status(503); }
}
