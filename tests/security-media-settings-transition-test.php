<?php
/** Explicit OFF confirmation and safe ON recovery with protected metadata. */
define('ABSPATH', __DIR__ . '/../');
require __DIR__ . '/../includes/class-esp-config.php';

$GLOBALS['current_settings'] = [
    'path' => [],
    'brute' => [],
    'remember' => [],
    'mail' => [],
    'media' => ['enabled' => true, 'delivery_method' => 'auto'],
];
$GLOBALS['protected_records'] = true;
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
    const OPTION_LITESPEED_KEY = 'litespeed_key';
    public static function get_litespeed_key_value() {
        return $GLOBALS['preinstalled_litespeed_key'] ?? '';
    }
    public static function protected_media_records_exist() {
        $GLOBALS['count_checks']++;
        return $GLOBALS['protected_records'];
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

// 保護メタあり：明示確認なしでは意図しない公開を防ぐ。
$sanitized = $settings->sanitize_settings($input);
check_transition($sanitized['media']['enabled'] === true,
    'protected media requires explicit confirmation before disabling');
check_transition(count($GLOBALS['settings_errors']) === 1 &&
    $GLOBALS['settings_errors'][0]['code'] === 'esp_media_disable_rejected',
    'settings UI reports missing disclosure confirmation');

// 管理者が公開を確認した場合はメタを削除せずOFFにできる。
$input['media']['confirm_disable'] = '1';
$GLOBALS['settings_errors'] = [];
$sanitized = $settings->sanitize_settings($input);
check_transition($sanitized['media']['enabled'] === false &&
    !array_key_exists('confirm_disable', $sanitized['media']),
    'confirmed OFF is allowed and confirmation is not persisted');
check_transition($GLOBALS['settings_errors'] === [],
    'confirmed disable needs no error');

$GLOBALS['protected_records'] = null;
$GLOBALS['settings_errors'] = [];
$sanitized = $settings->sanitize_settings($input);
check_transition($sanitized['media']['enabled'] === true &&
    count($GLOBALS['settings_errors']) === 1,
    'unknown DB state rejects disabling even with confirmation');

$GLOBALS['protected_records'] = false;
unset($input['media']['confirm_disable']);
$GLOBALS['settings_errors'] = [];
$sanitized = $settings->sanitize_settings($input);
check_transition($sanitized['media']['enabled'] === false &&
    $GLOBALS['settings_errors'] === [],
    'zero protected records permit disabling without extra confirmation');

$checks = $GLOBALS['count_checks'];
$input['media']['enabled'] = true;
$GLOBALS['protected_records'] = true;
$sanitized = $settings->sanitize_settings($input);
check_transition($sanitized['media']['enabled'] === true &&
    $GLOBALS['count_checks'] === $checks,
    'keeping ON does not require a protected-media COUNT');

// 旧版でOFF + 保護メタが存在していても、正しいRewriteを先に置ければONへ復旧。
$GLOBALS['current_settings']['media']['enabled'] = false;
$GLOBALS['known_zero_media_before_enabling'] = false;
$GLOBALS['settings_errors'] = [];
$sanitized = $settings->sanitize_settings($input);
check_transition($sanitized['media']['enabled'] === false &&
    $GLOBALS['settings_errors'][0]['code'] === 'esp_media_enable_rejected',
    'OFF to ON cannot be saved if protective Rewrite installation failed');
$GLOBALS['known_zero_media_before_enabling'] = true;
$GLOBALS['preinstalled_litespeed_key'] = 'key_from_verified_rules';
$GLOBALS['settings_errors'] = [];
$sanitized = $settings->sanitize_settings($input);
check_transition($sanitized['media']['enabled'] === true &&
    $GLOBALS['settings_errors'] === [],
    'OFF to ON restores protection with existing metadata when rewrite is verified');
check_transition($sanitized['media']['litespeed_key'] === 'key_from_verified_rules',
    'OFF-to-ON save retains the LiteSpeed key preinstalled by Rewrite recovery');
echo "All media setting transition tests passed.\\n";
