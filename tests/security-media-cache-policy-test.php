<?php
/**
 * Issue #31: inspect real HTTP headers (PHP built-in server) for all delivery paths.
 * No WordPress bootstrap or live site is used.
 */
if (PHP_SAPI === 'cli-server') {
    define('ABSPATH', __DIR__ . '/');
    class ESP_Option {
        public static function get_current_setting($section) {
            return $section === 'media'
                ? ['delivery_method' => ($_GET['mode'] ?? 'php') === 'delegate' ? 'x_sendfile' : 'php']
                : [];
        }
    }
    function wp_check_filetype($path) { return ['type' => 'image/jpeg']; }
    class ESP_Config { const LITESPEED_QUERY_KEY = 'esp_test_key'; }
    class ESP_Media_Protection {
        public static function get_litespeed_key_value() { return 'test-key'; }
    }
    require __DIR__ . '/../includes/class-esp-media-deriver.php';
    $delivery = new ESP_Media_Deriver();
    $mode = $_GET['mode'] ?? 'php';
    $protected = ($_GET['protected'] ?? '') === '1';
    if ($mode === 'nginx' || $mode === 'litespeed') {
        // Exercise the internal redirect response headers without a real external server.
        $method = new ReflectionMethod('ESP_Media_Deriver', 'attempt_web_server_delivery');
        $method->setAccessible(true);
        $method->invoke($delivery, ['type' => $mode, 'path' => '/internal/fixture.jpg'],
            __FILE__, $protected);
        exit;
    }
    $delivery->deliver(__FILE__, $protected);
    exit;
}

function verify_cache($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
function media_request($port, $mode, $protected, $method = 'GET', $range = '') {
    $url = "http://127.0.0.1:$port/?mode=$mode&protected=" . ($protected ? '1' : '0');
    $options = ['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 5]];
    if ($range !== '') {
        $options['http']['header'] = "Range: $range\r\n";
    }
    $body = @file_get_contents($url, false, stream_context_create($options));
    if ($body === false) {
        throw new RuntimeException("HTTP request failed: $method $mode");
    }
    $status = 0;
    $headers = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $match)) {
            $status = (int) $match[1];
        } elseif (strpos($line, ':') !== false) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }
    return [$status, $body, $headers];
}
function protected_headers($headers, $label) {
    $cache = strtolower($headers['cache-control'] ?? '');
    verify_cache(strpos($cache, 'no-store') !== false &&
        strpos($cache, 'private') !== false &&
        strpos($cache, 'public') === false, "$label: private no-store without public");
    verify_cache(($headers['x-litespeed-cache-control'] ?? '') === 'no-store',
        "$label: LiteSpeed cache disabled");
    verify_cache(($headers['x-accel-expires'] ?? '') === '0',
        "$label: Nginx cache disabled");
    verify_cache(strpos(strtolower($headers['pragma'] ?? ''), 'no-cache') !== false,
        "$label: legacy cache disabled");
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException("Cannot reserve test port: $error"); }
$address = stream_socket_get_name($socket, false);
$port = (int) substr(strrchr($address, ':'), 1);
fclose($socket);
$command = [PHP_BINARY, '-S', "127.0.0.1:$port", __FILE__];
$process = proc_open($command, [
    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
], $pipes, __DIR__);
if (!is_resource($process)) { throw new RuntimeException('Cannot start PHP HTTP test server'); }
try {
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $connection = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 0.1);
        if ($connection !== false) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    verify_cache($ready, 'temporary HTTP server started');

    $bytes = file_get_contents(__FILE__);
    [$status, $body, $headers] = media_request($port, 'php', true);
    verify_cache($status === 200 && $body === $bytes, 'protected PHP GET serves exact bytes');
    protected_headers($headers, 'protected PHP GET');

    [$status, $body, $headers] = media_request($port, 'php', true, 'HEAD');
    verify_cache($status === 200 && $body === '', 'protected PHP HEAD contains no bytes');
    protected_headers($headers, 'protected PHP HEAD');

    [$status, $body, $headers] = media_request($port, 'php', true, 'GET', 'bytes=0-31');
    verify_cache($status === 206 && $body === substr($bytes, 0, 32),
        'protected PHP Range returns exactly 32 bytes');
    protected_headers($headers, 'protected PHP Range 206');

    [$status, $body, $headers] = media_request($port, 'php', true, 'GET', 'bytes=invalid');
    verify_cache($status === 416 && $body === '', 'invalid protected Range is rejected');
    protected_headers($headers, 'protected PHP Range 416');

    [$status, $body, $headers] = media_request($port, 'php', false);
    verify_cache($status === 200 && $body === $bytes, 'public PHP GET unchanged');
    verify_cache(($headers['cache-control'] ?? '') === 'public, max-age=31536000' &&
        !isset($headers['x-litespeed-cache-control']) &&
        !isset($headers['x-accel-expires']), 'public JPEG keeps long-lived cache');

    [$status, $body, $headers] = media_request($port, 'php', false, 'HEAD');
    verify_cache($status === 200 && $body === '' &&
        ($headers['cache-control'] ?? '') === 'public, max-age=31536000',
        'public PHP HEAD keeps long-lived cache and no bytes');

    [$status, $body, $headers] = media_request($port, 'php', false, 'GET', 'bytes=0-31');
    verify_cache($status === 206 && $body === substr($bytes, 0, 32) &&
        ($headers['cache-control'] ?? '') === 'public, max-age=31536000',
        'public PHP Range keeps long-lived cache');

    [$status, $body, $headers] = media_request($port, 'php', false, 'GET', 'bytes=invalid');
    verify_cache($status === 416 && $body === '' && !isset($headers['cache-control']),
        'invalid public Range does not introduce a long-lived cache policy');

    [$status, $body, $headers] = media_request($port, 'delegate', true);
    verify_cache($status === 200 && $body === '' &&
        ($headers['x-sendfile'] ?? '') === __FILE__,
        'protected X-Sendfile delegation emits header without PHP bytes');
    protected_headers($headers, 'protected X-Sendfile');

    [$status, $body, $headers] = media_request($port, 'delegate', false);
    verify_cache($status === 200 && $body === '' &&
        ($headers['x-sendfile'] ?? '') === __FILE__,
        'public X-Sendfile delegation preserved');
    verify_cache(!isset($headers['x-litespeed-cache-control']) &&
        !isset($headers['x-accel-expires']) &&
        !isset($headers['cache-control']), 'public X-Sendfile does not impose cache policy');

    foreach (['nginx' => ['x-accel-redirect', '/internal/fixture.jpg'],
              'litespeed' => ['x-litespeed-location', '/internal/fixture.jpg?esp_test_key=test-key']] as
             $mode => $expected) {
        [$status, $body, $headers] = media_request($port, $mode, true);
        verify_cache($status === 200 && $body === '' &&
            ($headers[$expected[0]] ?? '') === $expected[1],
            "$mode protected internal redirect emits expected header");
        protected_headers($headers, "$mode internal redirect");

        [$status, $body, $headers] = media_request($port, $mode, false);
        verify_cache($status === 200 && $body === '' &&
            ($headers[$expected[0]] ?? '') === $expected[1],
            "$mode public internal redirect remains available");
        verify_cache(!isset($headers['cache-control']) &&
            !isset($headers['x-litespeed-cache-control']) &&
            !isset($headers['x-accel-expires']),
            "$mode public internal redirect does not impose cache policy");
    }
} finally {
    proc_terminate($process);
    foreach ($pipes as $pipe) { fclose($pipe); }
    proc_close($process);
}
echo "All media HTTP cache-policy tests passed.\n";
