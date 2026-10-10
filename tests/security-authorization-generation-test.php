<?php
/** Authorization cache contract: known-empty, settings change, stale writer. */
define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../includes/class-esp-config.php';
require __DIR__ . '/../includes/class-esp-authorization-cache.php';
$GLOBALS['options'] = [
    ESP_Config::OPTION_KEY => ['path' => [], 'media' => ['enabled' => true]],
];
$GLOBALS['transients'] = [];
function get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) {
    $GLOBALS['options'][$key] = $value;
    return true;
}
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); return true; }
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

unset($GLOBALS['options'][ESP_Config::OPTION_KEY]['path']);
check_auth(ESP_Authorization_Cache::signature('post') === null, 'missing path settings remain an error');
check_auth(ESP_Authorization_Cache::read('post', 'post-cache') === null, 'malformed settings are never accepted as public');
echo "All authorization generation tests passed.\n";
