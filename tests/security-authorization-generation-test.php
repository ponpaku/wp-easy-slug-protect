<?php
/** 認可キャッシュの回帰テスト：確認済み空状態・設定更新・旧世代の書き戻し。 */
define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../includes/class-esp-config.php';
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
require __DIR__ . '/../includes/class-esp-authorization-cache.php';
require __DIR__ . '/../includes/class-esp-media-protection.php';
require __DIR__ . '/../includes/class-esp-filter.php';
$GLOBALS['options'] = [
    ESP_Config::OPTION_KEY => ['path' => [], 'media' => ['enabled' => true]],
];
$GLOBALS['transients'] = [];
function get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) {
    if (!empty($GLOBALS['simulate_epoch_write_failure']) &&
        strpos($key, '_auth_epoch') !== false) {
        return false;
    }
    $GLOBALS['options'][$key] = $value;
    return true;
}
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient($key) {
    if (!empty($GLOBALS['simulate_transient_delete_failure'])) {
        return false;
    }
    unset($GLOBALS['transients'][$key]); return true;
}
function check_auth($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}

$initial = ESP_Authorization_Cache::signature('post');
check_auth(is_string($initial), 'zero protected paths is a valid state');
check_auth(ESP_Authorization_Cache::publish('post', 'post-cache', $initial, [], 60), 'valid empty cache publishes');
check_auth(ESP_Authorization_Cache::read('post', 'post-cache') === [], 'valid empty cache stays an empty array');

$GLOBALS['options'][ESP_Config::OPTION_KEY]['path'] = ['one' => ['path' => '/private']];
check_auth(ESP_Authorization_Cache::read('post', 'post-cache') === null, 'settings change rejects stale cache');
check_auth(!ESP_Authorization_Cache::publish('post', 'post-cache', $initial, [], 60), 'old generator cannot publish after settings change');
$fresh = ESP_Authorization_Cache::signature('post');
ESP_Authorization_Cache::publish('post', 'post-cache', $fresh, ['one' => [123]], 60);
check_auth(ESP_Authorization_Cache::read('post', 'post-cache') === ['one' => [123]], 'current map can be published');
check_auth(ESP_Authorization_Cache::invalidate('post', 'post-cache'), 'mutation increments generation');
check_auth(ESP_Authorization_Cache::read('post', 'post-cache') === null, 'mutation invalidates previous generation');
check_auth(!ESP_Authorization_Cache::publish('post', 'post-cache', $fresh, ['one' => []], 60), 'old in-flight scan cannot overwrite newer generation');
$new = ESP_Authorization_Cache::signature('post');
ESP_Authorization_Cache::publish('post', 'post-cache', $new, ['one' => [123,456]], 60);
check_auth(ESP_Authorization_Cache::read('post', 'post-cache') === ['one' => [123,456]], 'new generation publishes correctly');

$media = (new ReflectionClass('ESP_Media_Protection'))->newInstanceWithoutConstructor();
$mediaSnapshot = ESP_Authorization_Cache::signature('media');
check_auth(ESP_Authorization_Cache::publish('media', 'media-cache', $mediaSnapshot, ['one' => [42]], 60),
    'Media authorization snapshot is published');
ESP_Media_Protection::on_protection_meta_mutation(9, 42, '_unrelated_meta', 'x');
check_auth(ESP_Authorization_Cache::read('media', 'media-cache') === ['one' => [42]],
    'Unrelated metadata preserves authorization cache');
ESP_Media_Protection::on_protection_meta_mutation(9, 42, ESP_Media_Protection::META_KEY_PROTECTED_PATH, 'one');
check_auth(ESP_Authorization_Cache::read('media', 'media-cache') === null,
    'External protected-media meta mutation invalidates cached authorization');

$filter = (new ReflectionClass('ESP_Filter'))->newInstanceWithoutConstructor();
$postSignature = ESP_Authorization_Cache::signature('post');
check_auth(ESP_Authorization_Cache::publish('post', 'post-cache', $postSignature, ['one' => [42]], 60),
    'Post authorization snapshot is current');
$filter->on_permalink_meta_mutation(1, 42, '_unrelated', 'x');
check_auth(ESP_Authorization_Cache::read('post', 'post-cache') === ['one' => [42]],
    'Unrelated postmeta does not invalidate post authorization');
$filter->on_permalink_meta_mutation(1, 42, ESP_Config::PERMALINK_PATH_META_KEY, '/members/new');
check_auth(ESP_Authorization_Cache::read('post', 'post-cache') === null,
    'Direct permalink metadata mutation invalidates post authorization');

unset($GLOBALS['options'][ESP_Config::OPTION_KEY]['path']);
check_auth(ESP_Authorization_Cache::signature('post') === null, 'missing path settings remain an error');
check_auth(ESP_Authorization_Cache::read('post', 'post-cache') === null, 'malformed settings are never accepted as public');
// 直前の設定欠損テストの変更を戻し、ここからは正常設定の障害注入とする。
$GLOBALS['options'][ESP_Config::OPTION_KEY]['path'] = ['one' => ['path' => '/private']];
// メタデータ更新前ガード: 失効できる変更だけ通す。
check_auth(ESP_Authorization_Cache::guard_postmeta_write(null, 42, '_unrelated', 'x') === null,
    'unrelated metadata does not require authorization invalidation');
check_auth(ESP_Authorization_Cache::guard_postmeta_write(null, 42, ESP_Config::PERMALINK_PATH_META_KEY, '/new') === null,
    'protected postmeta write accepted when invalidation succeeds');

// 同時にepochの保存とtransient削除が失敗するケースは、現在のリクエストで
// 保護メタデータの更新をブロックし、読み取り・公開も拒否する。
$oldGeneration = ESP_Authorization_Cache::signature('media');
check_auth(ESP_Authorization_Cache::publish('media', 'media-cache', $oldGeneration,
    ['one' => [42]], 60), 'current media map available before simulated storage failure');
$GLOBALS['simulate_epoch_write_failure'] = true;
$GLOBALS['simulate_transient_delete_failure'] = true;
check_auth(ESP_Authorization_Cache::guard_postmeta_write(null, 42,
    ESP_Media_Protection::META_KEY_PROTECTED_PATH, 'one') === false,
    'metadata write denied if epoch and transient invalidation both fail');
check_auth(ESP_Authorization_Cache::read('media', 'media-cache') === null,
    'failed invalidation taints and rejects authorization in current request');
check_auth(ESP_Authorization_Cache::signature('media') === null,
    'failed invalidation cannot publish another authorization snapshot');
check_auth(isset($GLOBALS['transients']['media-cache']),
    'test fixture confirms old transient persists across simulated failure');
$GLOBALS['simulate_epoch_write_failure'] = false;
$GLOBALS['simulate_transient_delete_failure'] = false;

// ストレージ異常を検知済みの同一リクエストは復旧後も拒否し続ける。
check_auth(ESP_Authorization_Cache::read('media', 'media-cache') === null,
    'tainted request remains fail-closed after persistence recovers');
echo "All authorization generation tests passed.\n";
