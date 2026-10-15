<?php
/** Media protection configuration must reject unsafe OFF transitions before WordPress saves options. */
define('ABSPATH', __DIR__ . '/../');
require __DIR__ . '/../includes/class-esp-config.php';

$GLOBALS['current_settings'] = [
    'path' => [],
    'brute' => [],
    'remember' => [],
    'mail' => [],
    'media' => ['enabled' => true, 'delivery_method' => 'auto'],
];
$GLOBALS['safe_to_disable'] = false;
$GLOBALS['known_zero_media_before_enabling'] = true;
$GLOBALS['pre_save_media_checks'] = 0;
$GLOBALS['settings_errors'] = [];
$GLOBALS['count_checks'] = 0;
class ESP_Option {
    public static function get_current_setting($key) {
        return $GLOBALS['current_settings'][$key] ?? [];
    }
}
class ESP_Media_Protection {
    public static function can_disable_media_protection() {
        $GLOBALS['count_checks']++;
        return $GLOBALS['safe_to_disable'] === true;
    }
    public static function can_enable_media_protection() {
        $GLOBALS['pre_save_media_checks']++;
        return $GLOBALS['known_zero_media_before_enabling'] === true;
    }
}
class ESP_Mail {
    public static function get_instance() { return new self(); }
}
class ESP_Sanitize {
    public function sanitize_protected_paths($value) { return $value; }
    public function sanitize_bruteforce_settings($value) { return $value; }
    public function sanitize_remember_settings($value) { return $value; }
    public function sanitize_mail_settings($value) { return $value; }
    public function sanitize_media_settings($value) {
        return ['enabled' => !empty($value['enabled']), 'delivery_method' => 'auto'];
    }
}
function __($value, $domain = null) { return $value; }
function add_settings_error($key, $code, $message, $type = 'error') {
    $GLOBALS['settings_errors'][] = compact('key', 'code', 'message', 'type');
}
function check_transition($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
require __DIR__ . '/../admin/classes/class-esp-admin-setting.php';

$settings = ESP_Settings::get_instance();
$input = [
    'path' => [], 'brute' => [], 'remember' => [], 'mail' => [],
    'media' => ['enabled' => false],
];

$sanitized = $settings->sanitize_settings($input);
check_transition($sanitized['media']['enabled'] === true,
    'ON to OFF rejected before saving when protected media remains');
check_transition(count($GLOBALS['settings_errors']) === 1 &&
    $GLOBALS['settings_errors'][0]['code'] === 'esp_media_disable_rejected' &&
    $GLOBALS['settings_errors'][0]['type'] === 'error',
    'administrator gets an explicit save-time error');
$GLOBALS['current_settings'] = $sanitized;
check_transition($GLOBALS['current_settings']['media']['enabled'] === true,
    'save result preserves enabled routing rather than leaving inconsistent OFF state');

$GLOBALS['safe_to_disable'] = null; // Unknown DB results must be treated like nonzero media.
$GLOBALS['settings_errors'] = [];
$sanitized = $settings->sanitize_settings($input);
check_transition($sanitized['media']['enabled'] === true,
    'DB count unknown rejects OFF');
check_transition(count($GLOBALS['settings_errors']) === 1, 'DB uncertainty is reported');

$GLOBALS['safe_to_disable'] = true; // Verified zero protected records.
$GLOBALS['settings_errors'] = [];
$sanitized = $settings->sanitize_settings($input);
check_transition($sanitized['media']['enabled'] === false,
    'verified zero protected records allows OFF normally');
check_transition($GLOBALS['settings_errors'] === [], 'normal OFF does not raise errors');

$checks = $GLOBALS['count_checks'];
$input['media']['enabled'] = true;
$GLOBALS['safe_to_disable'] = false;
$sanitized = $settings->sanitize_settings($input);
check_transition($sanitized['media']['enabled'] === true,
    'ON restoration is not blocked by protected media');
check_transition($GLOBALS['count_checks'] === $checks,
    'saving ON does not incur unnecessary protected-media COUNT');
// OFF→ONでは保護対象0件の確認のみ行い、Rewriteは設定保存成功後に設置する。
$GLOBALS['current_settings']['media']['enabled'] = false;
$GLOBALS['known_zero_media_before_enabling'] = false;
$GLOBALS['settings_errors'] = [];
$sanitized = $settings->sanitize_settings($input);
check_transition($sanitized['media']['enabled'] === false,
    'OFF to ON rejected when protected count cannot be confirmed empty');
check_transition(count($GLOBALS['settings_errors']) === 1 &&
    $GLOBALS['settings_errors'][0]['code'] === 'esp_media_enable_rejected',
    'pre-save media count failure is visible in the settings UI');
$GLOBALS['known_zero_media_before_enabling'] = true;
$GLOBALS['settings_errors'] = [];
$sanitized = $settings->sanitize_settings($input);
check_transition($sanitized['media']['enabled'] === true,
    'OFF to ON allowed with verified zero protected media');
check_transition($GLOBALS['settings_errors'] === [],
    'metadata preflight permits saving without touching Rewrite');
echo "All media setting transition tests passed.\n";
