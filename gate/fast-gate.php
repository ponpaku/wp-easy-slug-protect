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
    $store = ESP_Fast_Gate_Store::open($private . '/gate-ledger.sqlite', false);
    $decision = ESP_Fast_Gate_Server::authorize(
        $_SERVER['REQUEST_URI'] ?? '', $_COOKIE, $keys['site'],
        $keys['root'], $keys['prefix'], $keys['sig'], $keys['id'], $store
    );
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
    $start = 0;
    $end = $length > 0 ? $length - 1 : 0;
    $partial = false;
    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = (string)$_SERVER['HTTP_RANGE'];
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $m) ||
            ($m[1] === '' && $m[2] === '') || $length === 0) {
            fclose($handle);
            header('Content-Range: bytes */' . $length);
            esp_fast_gate_status(416);
        }
        if ($m[1] === '') {
            $suffix = (int)$m[2];
            if ($suffix <= 0) {
                fclose($handle);
                header('Content-Range: bytes */' . $length);
                esp_fast_gate_status(416);
            }
            $start = max(0, $length - $suffix);
        } else {
            $start = (int)$m[1];
        }
        if ($m[2] !== '' && $m[1] !== '') {
            $end = min($length - 1, (int)$m[2]);
        } else {
            $end = $length - 1;
        }
        if ($start >= $length || $end < $start) {
            fclose($handle);
            header('Content-Range: bytes */' . $length);
            esp_fast_gate_status(416);
        }
        $partial = true;
    }

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
