<?php
/** LiteSpeed key-only writes must never rehash saved protected-path passwords. */
define('ABSPATH', __DIR__ . '/../');
define('WEEK_IN_SECONDS', 604800);
require __DIR__ . '/../includes/class-esp-config.php';
require __DIR__ . '/../includes/class-esp-option.php';

class ESP_Mail {
    public static function get_instance() { return new self(); }
    public function notify_password_change($path, $password) { $GLOBALS['mail_count']++; }
    public function notify_new_protected_path($path, $password) { $GLOBALS['mail_count']++; }
}
function __($s, $domain = '') { return $s; }
function sanitize_text_field($s) { return trim((string) $s); }
function absint($n) { return abs((int) $n); }
function add_settings_error($setting, $code, $message, $type = 'error') {
    $GLOBALS['settings_errors'][] = $code;
}
function wp_hash_password($password) {
    $data = base64_encode(hash_hmac('sha384', trim($password), 'wp-sha384', true));
    return '$wp' . password_hash($data, PASSWORD_BCRYPT, ['cost' => 4]);
}
function wp_check_password($password, $hash) {
    if (strncmp($hash, '$wp$2y$', 7) === 0) {
        $data = base64_encode(hash_hmac('sha384', trim($password), 'wp-sha384', true));
        return password_verify($data, substr($hash, 3));
    }
    return password_verify($password, $hash);
}
function get_option($name, $default = false) {
    return $name === ESP_Config::OPTION_KEY ? $GLOBALS['stored_settings'] : $default;
}
function update_option($name, $value, $autoload = null) {
    if ($name !== ESP_Config::OPTION_KEY) { return false; }
    // WordPress core calls sanitize_option() on update_option(), regardless
    // of whether the update originates in options.php or internal PHP code.
    $GLOBALS['sanitize_calls']++;
    $old_value = $GLOBALS['stored_settings'];
    $value = call_user_func($GLOBALS['setting_sanitizer'], $value);
    $GLOBALS['stored_settings'] = $value;
    // The option-update action also runs during internal key writes.
    // Exercise that specific path here without bootstrapping unrelated WP hooks.
    if (ESP_Media_Protection::is_committing_litespeed_key()) {
        $GLOBALS['internal_update_hook_calls']++;
        $GLOBALS['settings_instance']->handle_settings_update($old_value, $value);
    }
    return true;
}
function register_setting($group, $key, $args) {
    $GLOBALS['setting_sanitizer'] = $args['sanitize_callback'];
}
function check_password($condition, $description) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$description}\n"); exit(1); }
    echo "PASS: {$description}\n";
}
require __DIR__ . '/../includes/class-esp-media-protection.php';
require __DIR__ . '/../admin/classes/class-esp-admin-sanitize.php';
require __DIR__ . '/../admin/classes/class-esp-admin-setting.php';

$GLOBALS['mail_count'] = 0;
$GLOBALS['sanitize_calls'] = 0;
$GLOBALS['settings_errors'] = [];
$password = 'old-password-not-to-be-lost';
$hash = wp_hash_password($password);
check_password(strncmp($hash, '$wp$2y$', 7) === 0 &&
    wp_check_password($password, $hash), 'test uses working WordPress 6.8-style bcrypt hash');
$GLOBALS['stored_settings'] = ESP_Config::OPTION_DEFAULTS;
$GLOBALS['stored_settings']['path'] = [
    'path_main' => [
        'id' => 'path_main',
        'path' => '/members/',
        'login_page' => 24,
        'password' => $hash,
        'password_version' => 7,
    ],
];
$settings = ESP_Settings::get_instance();
$GLOBALS['settings_instance'] = $settings;
$GLOBALS['internal_update_hook_calls'] = 0;
$settings->register_settings();
$reflection = new ReflectionClass('ESP_Media_Protection');
$write_key = $reflection->getMethod('commit_litespeed_key');
$initial_paths = $GLOBALS['stored_settings']['path'];
$expected_session_version = 7;
$key = 'AABBCCDDEEFF11223344556677889900';
check_password($write_key->invoke(null, $key) === true,
    'internal LiteSpeed key update succeeds through option sanitizer');
check_password($GLOBALS['sanitize_calls'] === 1 &&
    $GLOBALS['internal_update_hook_calls'] === 1 &&
    $GLOBALS['stored_settings']['media']['litespeed_key'] === $key &&
    $GLOBALS['stored_settings']['path'] === $initial_paths &&
    $GLOBALS['mail_count'] === 0,
    'key-only update preserves password hash, path settings and notification state');
check_password($GLOBALS['stored_settings']['path']['path_main']['password_version'] === $expected_session_version &&
    wp_check_password($password, $GLOBALS['stored_settings']['path']['path_main']['password']) &&
    !ESP_Media_Protection::is_committing_litespeed_key(),
    'existing password and session version survive key rotation');

// A normal WordPress settings save must also recognize $wp$2y$ as a stored hash.
$input = $GLOBALS['stored_settings'];
check_password(update_option(ESP_Config::OPTION_KEY, $input) === true &&
    $GLOBALS['stored_settings']['path'] === $initial_paths &&
    $GLOBALS['mail_count'] === 0,
    'normal settings save does not rehash modern WordPress password');
$input = $GLOBALS['stored_settings'];
$input['path']['path_main']['password'] = 'a-new-password';
update_option(ESP_Config::OPTION_KEY, $input);
$new_path = $GLOBALS['stored_settings']['path']['path_main'];
check_password($new_path['password_version'] === 8 &&
    wp_check_password('a-new-password', $new_path['password']) &&
    !wp_check_password($password, $new_path['password']) &&
    $GLOBALS['mail_count'] === 1,
    'explicit plaintext password change still rehashes and advances session version');

// Other previously stored hashing formats should not be mistaken for plaintext.
$legacy = $GLOBALS['stored_settings'];
$legacy['path']['path_main']['password'] = '$P$Babcdefghijklmnopqrstuvwxyz1234567890';
$legacy['path']['path_main']['password_version'] = 12;
$GLOBALS['stored_settings'] = $legacy;
$write_key->invoke(null, '11223344556677889900AABBCCDDEEFF');
check_password($GLOBALS['stored_settings']['path'] === $legacy['path'] &&
    $GLOBALS['mail_count'] === 1,
    'internal key-only update also preserves legacy phpass and session version');
$input = $GLOBALS['stored_settings'];
update_option(ESP_Config::OPTION_KEY, $input);
check_password($GLOBALS['stored_settings']['path'] === $legacy['path'],
    'normal settings save preserves legacy phpass hash');

$sanitize = new ESP_Sanitize();
$method = (new ReflectionClass('ESP_Sanitize'))->getMethod('is_hashed_password');
check_password($method->invoke($sanitize, '$wp$2y$10$abc') === true &&
    $method->invoke($sanitize, '$2y$10$abc') === true &&
    $method->invoke($sanitize, '$argon2id$v=19$m=65536,t=4,p=1$abc') === true,
    'modern bcrypt and Argon2 prefixes are recognized');
echo "All LiteSpeed password preservation tests passed.\n";
