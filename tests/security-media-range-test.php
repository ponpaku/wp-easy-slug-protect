<?php
/**
 * HTTP Rangeの境界検証（WordPressを起動しないPHP単体テスト）。
 * 実Webサーバーから返されるHTTPヘッダーの確認は別途必要。
 */
define('ABSPATH', __DIR__ . '/');
ob_start();
require __DIR__ . '/../includes/class-esp-media-deriver.php';

function check_media_range($ok, $description) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $description\n");
        exit(1);
    }
    echo "PASS: $description\n";
}

$deriver = new ESP_Media_Deriver();
$method = (new ReflectionClass('ESP_Media_Deriver'))->getMethod('deliver_with_range');
$file = tempnam(sys_get_temp_dir(), 'esp-range-');
$empty = tempnam(sys_get_temp_dir(), 'esp-range-empty-');
file_put_contents($file, str_repeat('X', 100));
file_put_contents($empty, '');

try {
    // 末尾以降の開始位置、逆順指定、空ファイルは必ず416で拒否する。
    foreach ([
        ['bytes=100-', 100, $file],
        ['bytes=100-120', 100, $file],
        ['bytes=200-300', 100, $file],
        ['bytes=50-49', 100, $file],
        ['bytes=0-', 0, $empty],
        ['bytes=-1', 0, $empty],
        ['bytes=1.5-', 100, $file],
        ['bytes=abc-', 100, $file],
        ['bytes0-1', 100, $file],
    ] as [$range, $size, $path]) {
        http_response_code(200);
        ob_start();
        $result = $method->invoke($deriver, $path, $size, 'image/jpeg', $range, true);
        $body = ob_get_clean();
        check_media_range($result === false && http_response_code() === 416 && $body === '',
            "$range ($size bytes) returns 416 without image body");
    }

    // 有効な末尾1バイトと終了位置の切り詰めは206で配信する。
    foreach ([
        ['bytes=99-', 1],
        ['bytes=0-0', 1],
        ['bytes=98-200', 2],
        ['bytes=-1', 1],
    ] as [$range, $expected_length]) {
        http_response_code(200);
        ob_start();
        $result = $method->invoke($deriver, $file, 100, 'image/jpeg', $range, true);
        $body = ob_get_clean();
        check_media_range($result === true && http_response_code() === 206 &&
            strlen($body) === $expected_length && $body === str_repeat('X', $expected_length),
            "$range delivers only the satisfiable range");
    }

    // 未保護ファイルでも不正な範囲指定を206へ丸めない。
    http_response_code(200);
    ob_start();
    $result = $method->invoke($deriver, $file, 100, 'image/jpeg', 'bytes=100-', false);
    $body = ob_get_clean();
    check_media_range($result === false && http_response_code() === 416 && $body === '',
        'Public file also rejects unsatisfiable Range');
} finally {
    @unlink($file);
    @unlink($empty);
}
echo "All media Range boundary tests passed.\n";
ob_end_flush();
