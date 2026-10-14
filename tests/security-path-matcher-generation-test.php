<?php
define('ABSPATH', __DIR__ . '/../');
define('DAY_IN_SECONDS', 86400);
require __DIR__ . '/../includes/class-esp-config.php';

$GLOBALS['settings'] = ['path' => ['p1' => ['id' => 'p1', 'path' => '/private/']]];
$GLOBALS['transients'] = [];
function get_option($key, $default = null) {
    return $key === ESP_Config::OPTION_KEY ? $GLOBALS['settings'] : $default;
}
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); return true; }
function check_matcher($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
require __DIR__ . '/../includes/class-esp-path-matcher.php';

$original = ESP_Path_Matcher::all();
check_matcher(count($original) === 1, 'valid protected paths are indexed');
$matcher = new ESP_Path_Matcher();
check_matcher($matcher->match('/private/document/') !== null, 'private path matched');

$GLOBALS['settings']['path'] = ['p2' => ['id' => 'p2', 'path' => '/other/']];
$updated = ESP_Path_Matcher::all();
check_matcher(isset($updated['other']) && !isset($updated['private']), 'old path index invalid on settings change');

$GLOBALS['settings']['path'] = [];
check_matcher(ESP_Path_Matcher::all() === [], 'verified empty paths remain usable');
$GLOBALS['transients']['esp_path_index_v2'] = ['signature' => 'stale', 'paths' => $original];
check_matcher(ESP_Path_Matcher::all() === [], 'stale snapshot cannot revive protected route');

$GLOBALS['settings'] = null;
try {
    ESP_Path_Matcher::all();
    check_matcher(false, 'missing settings must throw');
} catch (UnexpectedValueException $expected) {
    check_matcher(true, 'missing settings not interpreted as an empty path list');
}
echo "All path matcher generation tests passed.\n";
