<?php
/** 認可キャッシュの回帰テスト：確認済み空状態・設定更新・旧世代の書き戻し。 */
define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../includes/class-esp-config.php';
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
require __DIR__ . '/../includes/class-esp-authorization-cache.php';
require __DIR__ . '/../includes/class-esp-media-protection.php';
require __DIR__ . '/../includes/class-esp-filter.php';
class ESP_Option {
    public static function get_current_setting($key) {
        return $GLOBALS['options'][ESP_Config::OPTION_KEY][$key] ?? [];
    }
}

$GLOBALS['options'] = [
    ESP_Config::OPTION_KEY => ['path' => [], 'media' => ['enabled' => true]],
];
$GLOBALS['transients'] = [];
function get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function get_metadata_by_mid($type, $id) {
    return $type === 'post' ? ($GLOBALS['by_mid_meta'][$id] ?? false) : false;
}
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

// 保護OFF中に外部プラグインがpostmetaだけ保存する経路を先に止める。
$GLOBALS['options'][ESP_Config::OPTION_KEY]['media']['enabled'] = false;
check_auth(ESP_Authorization_Cache::guard_postmeta_write(null, 42,
    ESP_Media_Protection::META_KEY_PROTECTED_PATH, 'one') === false,
    'media OFF rejects direct update_post_meta/add_post_meta protected assignment');
check_auth(ESP_Authorization_Cache::guard_postmeta_delete(null, 42,
    ESP_Media_Protection::META_KEY_PROTECTED_PATH, '') === null,
    'media OFF permits clearing stale protected metadata');
$GLOBALS['options'][ESP_Config::OPTION_KEY]['media']['enabled'] = true;

// by-mid APIは通常の更新前フィルターを迂回するため独立して守る。
$GLOBALS['options'][ESP_Config::OPTION_KEY]['media']['enabled'] = false;
$GLOBALS['by_mid_meta'] = [
    9 => (object) ['post_id' => 42,
        'meta_key' => ESP_Media_Protection::META_KEY_PROTECTED_PATH, 'meta_value' => 'one'],
    10 => (object) ['post_id' => 42, 'meta_key' => '_unrelated', 'meta_value' => 'plain'],
    11 => (object) ['post_id' => 42,
        'meta_key' => ESP_Config::PERMALINK_PATH_META_KEY, 'meta_value' => '/members/'],
];
check_auth(ESP_Authorization_Cache::guard_postmeta_update_by_mid(null, 9, 'one') === false,
    'media OFF also rejects update_post_metadata_by_mid to a protected key');
check_auth(ESP_Authorization_Cache::guard_postmeta_update_by_mid(null, 10, 'one',
    ESP_Media_Protection::META_KEY_PROTECTED_PATH) === false,
    'media OFF rejects rekeying unrelated meta to protected media');
check_auth(ESP_Authorization_Cache::guard_postmeta_delete_by_mid(null, 9) === null,
    'media OFF permits deleting protected media by meta ID');
check_auth(ESP_Authorization_Cache::guard_postmeta_update_by_mid(null, 11, 'new',
    '_unrelated') === null,
    'rekeying protected permalink metadata invalidates the old scope');
check_auth(ESP_Authorization_Cache::guard_postmeta_update_by_mid(null, 999, 'x') === null,
    'missing metadata IDs are left to WordPress validation');

$GLOBALS['options'][ESP_Config::OPTION_KEY]['media']['enabled'] = true;
unset($_SERVER['SERVER_SOFTWARE']);
check_auth(ESP_Authorization_Cache::guard_postmeta_update_by_mid(null, 9, 'one') === false,
    'by-mid protected media update cannot bypass unknown-server rejection');
$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26';
check_auth(ESP_Authorization_Cache::guard_postmeta_update_by_mid(null, 10, 'one',
    ESP_Media_Protection::META_KEY_PROTECTED_PATH) === null,
    'explicit Nginx permits protected by-mid rekey after cache invalidation');
$by_mid_signature = ESP_Authorization_Cache::signature('media');
ESP_Authorization_Cache::publish('media', 'media-cache', $by_mid_signature, ['one' => [42]], 60);
check_auth(ESP_Authorization_Cache::guard_postmeta_update_by_mid(null, 9, 'plain',
    '_unrelated') === null &&
    ESP_Authorization_Cache::read('media', 'media-cache') === null,
    'removing the protected key by meta ID invalidates stale media authorization');

// by-midは旧メタキーを更新後フックに渡さない。DB更新前に別リクエストが
// 旧DB状態でキャッシュを再構築しても、更新後に旧スコープを再失効させる。
$GLOBALS['by_mid_meta'][9]->meta_key = ESP_Media_Protection::META_KEY_PROTECTED_PATH;
$before_rekey = ESP_Authorization_Cache::signature('media');
check_auth(ESP_Authorization_Cache::publish('media', 'media-cache', $before_rekey,
    ['one' => [42]], 60), 'by-mid media rekey starts with protected snapshot');
check_auth(ESP_Authorization_Cache::guard_postmeta_update_by_mid(null, 9, 'plain',
    '_unrelated') === null, 'by-mid media rekey guard permits invalidated old key');
$race_signature = ESP_Authorization_Cache::signature('media');
check_auth(ESP_Authorization_Cache::publish('media', 'media-cache', $race_signature,
    ['one' => [42]], 60), 'concurrent request can build old DB state after pre-write invalidation');
$GLOBALS['by_mid_meta'][9]->meta_key = '_unrelated';
ESP_Authorization_Cache::invalidate_after_meta_key_change(9, 42, '_unrelated', 'plain');
check_auth(ESP_Authorization_Cache::read('media', 'media-cache') === null,
    'post-write by-mid rekey invalidates cache rebuilt from old protected media key');

$GLOBALS['by_mid_meta'][11]->meta_key = ESP_Config::PERMALINK_PATH_META_KEY;
check_auth(ESP_Authorization_Cache::guard_postmeta_update_by_mid(null, 11, 'other',
    '_other_meta') === null, 'by-mid permalink rekey begins with old post scope');
$post_race = ESP_Authorization_Cache::signature('post');
check_auth(ESP_Authorization_Cache::publish('post', 'post-cache', $post_race,
    ['one' => [42]], 60), 'post cache can rebuild during by-mid DB write');
$GLOBALS['by_mid_meta'][11]->meta_key = '_other_meta';
ESP_Authorization_Cache::invalidate_after_meta_key_change(11, 42, '_other_meta', 'other');
check_auth(ESP_Authorization_Cache::read('post', 'post-cache') === null,
    'post-write by-mid permalink rekey invalidates rebuilt old protected-post snapshot');

$unrelated_signature = ESP_Authorization_Cache::signature('media');
check_auth(ESP_Authorization_Cache::publish('media', 'media-cache', $unrelated_signature,
    [], 60), 'fresh media cache can be published after old-key rekey');
check_auth(ESP_Authorization_Cache::guard_postmeta_update_by_mid(null, 10, 'another',
    '_other_meta') === null, 'unrelated by-mid rekey remains permitted');
ESP_Authorization_Cache::invalidate_after_meta_key_change(10, 42, '_other_meta', 'another');
check_auth(ESP_Authorization_Cache::read('media', 'media-cache') === [],
    'unrelated by-mid rekey leaves media authorization cache untouched');

// WP-CLIはSERVER_SOFTWAREがないので、Nginxと誤認して書込みを通してはならない。
unset($_SERVER['SERVER_SOFTWARE']);
check_auth(ESP_Authorization_Cache::guard_postmeta_write(null, 42,
    ESP_Media_Protection::META_KEY_PROTECTED_PATH, 'one') === false,
    'WP-CLI without server identity rejects protected media metadata write');
// 以後の失効失敗注入は、Nginx外部Rewriteが設定されている前提の分岐で実施。
$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26';

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
