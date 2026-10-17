<?php
/**
 * The PHP media fallback must not stream file bytes for HEAD.
 * Exercise deliver() in subprocesses because successful delivery calls exit.
 */
if (isset($argv[1]) && $argv[1] === '--worker') {
    define('ABSPATH', __DIR__ . '/');
    class ESP_Option {
        public static function get_current_setting($section) {
            return $section === 'media' ? ['delivery_method' => 'php'] : [];
        }
    }
    function wp_check_filetype($file) { return ['type' => 'image/jpeg']; }
    require __DIR__ . '/../includes/class-esp-media-deriver.php';
    $_SERVER['REQUEST_METHOD'] = $argv[2] ?? 'GET';
    if (!empty($argv[4])) {
        $_SERVER['HTTP_RANGE'] = $argv[4];
    }
    (new ESP_Media_Deriver())->deliver($argv[3]);
    fwrite(STDERR, "Delivery unexpectedly returned\n");
    exit(1);
}

function check_head($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
function run_delivery($method, $file, $range = '') {
    $command = [PHP_BINARY, __FILE__, '--worker', $method, $file, $range];
    $process = proc_open($command, [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start media delivery subprocess');
    }
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    check_head($exit === 0, "delivery $method completed: $stderr");
    return $body;
}
$file = tempnam(sys_get_temp_dir(), 'esp-head-');
check_head($file !== false, 'temporary media fixture created');
try {
    $bytes = str_repeat('public-media-content-', 16000); // > 256 KiB
    check_head(file_put_contents($file, $bytes) === strlen($bytes), 'media fixture written');
    check_head(run_delivery('GET', $file) === $bytes,
        'GET still streams the complete file over PHP fallback');
    check_head(run_delivery('HEAD', $file) === '',
        'HEAD returns no file bytes on PHP fallback');
    check_head(run_delivery('HEAD', $file, 'bytes=0-31') === '',
        'HEAD ignores Range and never streams bytes');
    check_head(run_delivery('GET', $file, 'bytes=0-31') === substr($bytes, 0, 32),
        'GET Range remains functional');
} finally {
    @unlink($file);
}
echo "All PHP HEAD media delivery tests passed.\n";
