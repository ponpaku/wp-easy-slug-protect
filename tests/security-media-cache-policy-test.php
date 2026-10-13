<?php
/**
 * 保護メディアのキャッシュ方針と認証結果の引き渡しを検証する回帰テスト。
 * PHP単体テストであり、CDN・LiteSpeedの実HTTP検証には代わらない。
 */
define('ABSPATH', __DIR__ . '/');
define('WEEK_IN_SECONDS', 604800);
ob_start(); // Keep output buffered so header() smoke tests can run in CLI.
require __DIR__ . '/../includes/class-esp-config.php';

class ESP_Option {
    public static function get_current_setting($kind) {
        return $kind === 'media'
            ? ['enabled' => true, 'litespeed_key' => 'testkey123']
            : [];
    }
}
function check_cache_policy($pass, $message) {
    if (!$pass) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
function sanitize_text_field($text) { return (string) $text; }
require __DIR__ . '/../includes/class-esp-media-deriver.php';
require __DIR__ . '/../includes/class-esp-media-protection.php';

$deriverClass = new ReflectionClass('ESP_Media_Deriver');
$deriver = $deriverClass->newInstanceWithoutConstructor();
$headers = $deriverClass->getMethod('get_cache_header_lines');

foreach (['image/jpeg', 'image/png', 'image/avif', 'video/mp4', 'application/pdf'] as $mime) {
    $private = $headers->invoke($deriver, $mime, true);
    $joined = implode("\n", $private);
    check_cache_policy(
        in_array('Cache-Control: private, no-store, max-age=0', $private, true) &&
        in_array('Expires: 0', $private, true) &&
        in_array('Surrogate-Control: no-store', $private, true) &&
        in_array('CDN-Cache-Control: no-store', $private, true) &&
        in_array('X-LiteSpeed-Cache-Control: no-cache', $private, true) &&
        strpos($joined, 'Cache-Control: public') === false,
        "Protected $mime cannot be stored by shared caches"
    );
}

foreach (['image/jpeg', 'image/avif', 'image/webp'] as $mime) {
    $public = $headers->invoke($deriver, $mime, false);
    check_cache_policy(
        in_array('Cache-Control: public, max-age=31536000', $public, true),
        "Unprotected $mime retains long-lived public caching"
    );
}
check_cache_policy(
    in_array('Cache-Control: private, max-age=3600',
        $headers->invoke($deriver, 'application/pdf', false), true),
    'Unprotected non-image delivery retains its previous cache policy'
);

// Media Protectionからは保護・公開の判定を明示して引き渡す。
// 古い呼び出し元が省略した場合、Deriver側は安全側の非キャッシュ配信とする。
check_cache_policy(
    $deriverClass->getMethod('deliver')->getParameters()[1]->getDefaultValue() === true,
    'Bare Deriver calls default to protected private delivery'
);

$protectionClass = new ReflectionClass('ESP_Media_Protection');
$media = $protectionClass->newInstanceWithoutConstructor();
$spy = new class {
    public $calls = [];
    public function deliver($path, $protected) {
        $this->calls[] = [$path, $protected];
        return true;
    }
};
$property = $protectionClass->getProperty('deriver');
$property->setValue($media, $spy);
$handoff = $protectionClass->getMethod('deliver_media');
check_cache_policy($handoff->getNumberOfRequiredParameters() === 2,
    'Media Protection requires an explicit protected/public decision');
$handoff->invoke($media, '/uploads/public-image.jpg', false);
$handoff->invoke($media, '/uploads/protected-image.avif', true);
check_cache_policy(
    $spy->calls === [
        ['/uploads/public-image.jpg', false],
        ['/uploads/protected-image.avif', true],
    ],
    'Media Protection preserves public/private classification for Deriver'
);

// X-Sendfile等の内部配信も共通のキャッシュ方針を適用できることを確認する。
// 実サーバーがヘッダーを上書きする可能性はあるため、実HTTP検証は別途必要。
$delegate = $deriverClass->getMethod('attempt_web_server_delivery');
check_cache_policy($delegate->getNumberOfParameters() === 3,
    'Internal delivery passes a protected flag through the delegate boundary');

// CLIでは最終HTTPヘッダーを検査できないため、実Webサーバーに依存せず、
// Apache・Nginx等への委譲経路を呼び出せることだけ確認する。
check_cache_policy(
    $delegate->invoke($deriver, ['type' => 'apache'], '/uploads/protected.avif', true),
    'Apache sendfile directive can be issued with protected policy'
);
check_cache_policy(
    $delegate->invoke($deriver, ['type' => 'nginx', 'path' => '/protected-uploads/a.avif'],
        '/uploads/protected.avif', true),
    'Nginx internal redirect can be issued with protected policy'
);
check_cache_policy(
    $delegate->invoke($deriver, ['type' => 'litespeed', 'path' => '/wp-content/uploads/a.avif'],
        '/uploads/protected.avif', true),
    'LiteSpeed internal redirection can be issued with protected policy'
);
echo "All protected media cache policy tests passed.\n";
ob_end_flush();
