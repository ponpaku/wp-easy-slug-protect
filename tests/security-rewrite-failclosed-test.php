<?php
/** .htaccessの更新失敗時の拒否と、安全な置換処理を検証する単体テスト。 */
define('ABSPATH', __DIR__ . '/');
define('WEEK_IN_SECONDS', 604800);
define('ESP_VERSION', '0.7.38');
require __DIR__ . '/../includes/class-esp-config.php';
class ESP_Option {
    public static function get_current_setting($name) {
        return ['enabled' => $GLOBALS['enabled'] ?? true,
            'litespeed_key' => $GLOBALS['litespeed_key'] ?? 'key123'];
    }
    public static function get_all_settings() {
        return ['media' => self::get_current_setting('media')];
    }
    public static function update_settings($settings) {
        $GLOBALS['litespeed_key_writes'] = ($GLOBALS['litespeed_key_writes'] ?? 0) + 1;
        if (!empty($GLOBALS['litespeed_key_write_failure'])) { return false; }
        if (!empty($GLOBALS['litespeed_key_throw_before_write'])) {
            throw new RuntimeException('Simulated failure before key persistence');
        }
        if (!empty($GLOBALS['simulate_litespeed_option_hook'])) {
            $nested = (new ReflectionClass('ESP_Media_Protection'))->newInstanceWithoutConstructor();
            $GLOBALS['nested_key_update_safe'] = $nested->on_settings_save() === true;
        }
        $GLOBALS['litespeed_key'] = $settings['media']['litespeed_key'];
        if (!empty($GLOBALS['litespeed_key_throw_after_write'])) {
            throw new RuntimeException('Simulated option hook failure after persistence');
        }
        return true;
    }
}
class WP_Error {
    private $code;
    private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class RewriteTestDB {
    public $postmeta = 'wp_postmeta';
    public $last_error = '';
    public $count = 1;
    public $live_path = null;
    public $live_error = false;
    public $attached_id = null;
    public $attached_query_error = false;
    public $candidate_ids = [];
    public $candidate_query_error = false;
    public function prepare($sql, ...$args) { return $sql; }
    public function get_var($query) {
        if (strpos($query, 'SELECT meta_value FROM') !== false) {
            $this->last_error = $this->live_error ? 'DB unavailable' : '';
            return $this->live_error ? null : $this->live_path;
        }
        if (strpos($query, 'SELECT post_id FROM') !== false) {
            $this->last_error = $this->attached_query_error ? 'Attached-file SELECT failed' : '';
            return $this->attached_query_error ? null : $this->attached_id;
        }
        return $this->count;
    }
    public function get_col($query) {
        $this->last_error = $this->candidate_query_error ? 'AVIF candidate SELECT failed' : '';
        return $this->candidate_query_error ? null : $this->candidate_ids;
    }
}
function __($value, $domain = null) { return $value; }
function sanitize_text_field($value) { return trim((string) $value); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function home_url($path = '') { return 'https://example.test' . $path; }
function wp_upload_dir() { return ['basedir' => $GLOBALS['test_dir'], 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => '']; }
function get_query_var($key) { return $GLOBALS['test_media_query'] ?? ''; }
function is_admin() { return false; }
function current_user_can($capability) { return !empty($GLOBALS['ajax_admin']); }
function check_ajax_referer($action, $query_arg = false, $stop = true) { return true; }
function wp_send_json_success($data) {
    throw new RuntimeException('ajax:success:' . ($data['message'] ?? ''));
}
function wp_send_json_error($data, $status_code = null) {
    throw new RuntimeException('ajax:error:' . (string) ($status_code ?? '') . ':' . ($data['message'] ?? ''));
}
function wp_die($message, $title = '', $args = []) {
    throw new RuntimeException('intercepted media ' . (is_array($args) ? ($args['response'] ?? 0) : $title));
}
function is_404() { return false; }
function status_header($code) { $GLOBALS['test_http_status'] = $code; }
function nocache_headers() {}
function get_404_template() { return $GLOBALS['test_dir'] . '/esp-test-404.php'; }
function trailingslashit($path) { return rtrim($path, '/') . '/'; }
function get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
function check($ok, $description) {
    if (!$ok) { fwrite(STDERR, "FAIL: $description\n"); exit(1); }
    echo "PASS: $description\n";
}
require __DIR__ . '/../includes/class-esp-media-protection.php';
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
$wpdb = new RewriteTestDB();
$GLOBALS['test_dir'] = sys_get_temp_dir() . '/esp-p0-' . bin2hex(random_bytes(6));
mkdir($GLOBALS['test_dir'], 0700);
$file = $GLOBALS['test_dir'] . '/.htaccess';
$foreign = "# Other plugin rule\nRewriteEngine On\n";
$old = "# BEGIN ESP Media Protection\nRewriteRule ^secret private\n# END ESP Media Protection\n" . $foreign;
file_put_contents($file, $old);
$reflection = new ReflectionClass('ESP_Media_Protection');
$media = $reflection->newInstanceWithoutConstructor();

// esp-mediaは公開クエリ変数であり、.htaccessの拡張子Rewriteを経ずに到達できる。
// ON/OFFや管理者の早期配信分岐よりも先に、配信対象を必ず制限する。
$allowedRequest = $reflection->getMethod('is_allowed_media_request');
foreach (['.htaccess', '.htpasswd', '.user.ini', '.env.jpg',
    'private/.hidden/image.jpg', 'private/%2Ehtpasswd',
    '%252ehtaccess', '../secret.jpg', 'private/../secret.jpg',
    'image.php', 'wp-config.php', 'private\\image.jpg', ['image.jpg']] as $bad) {
    check($allowedRequest->invoke(null, $bad) === false,
        'public media query rejects configuration/hidden/invalid paths');
}
foreach (['public.jpg', 'nested/image.JPG', 'public.avif', 'notes.txt'] as $good) {
    check($allowedRequest->invoke(null, $good) === true,
        'public media query keeps allowed media formats available');
}
file_put_contents($GLOBALS['test_dir'] . '/esp-test-404.php',
    '<?php throw new RuntimeException("intercepted media 404");');
$GLOBALS['test_media_query'] = '.htaccess';
$_SERVER['REQUEST_URI'] = '/?esp-media=.htaccess';
$enabledProp = $reflection->getProperty('enabled');
foreach ([false, true] as $media_enabled) {
    $enabledProp->setValue($media, $media_enabled);
    unset($GLOBALS['test_http_status']);
    $denied = false;
    try {
        $media->handle_media_access();
    } catch (RuntimeException $error) {
        $denied = $error->getMessage() === 'intercepted media 404';
    }
    check($denied && $GLOBALS['test_http_status'] === 404,
        'public query for .htaccess is rejected before OFF/ON delivery');
}
// リンクの表示名が画像でも、実体が隠し設定ファイルなら配信しない。
// uploadsと同じ接頭辞の兄弟ディレクトリもuploads配下と誤認しない。
$link_alias = $GLOBALS['test_dir'] . '/alias.jpg';
$outside_dir = $GLOBALS['test_dir'] . '-backup';
mkdir($outside_dir, 0700);
$outside_file = $outside_dir . '/outside.jpg';
file_put_contents($outside_file, 'outside uploads');
check(symlink($file, $link_alias), 'setup file alias to hidden Rewrite configuration');
check(symlink($outside_file, $GLOBALS['test_dir'] . '/escape.jpg'),
    'setup uploads sibling-directory alias');
foreach (['alias.jpg', 'escape.jpg'] as $alias) {
    $GLOBALS['test_media_query'] = $alias;
    $_SERVER['REQUEST_URI'] = '/?esp-media=' . $alias;
    foreach ([false, true] as $media_enabled) {
        $enabledProp->setValue($media, $media_enabled);
        unset($GLOBALS['test_http_status']);
        $denied = false;
        try {
            $media->handle_media_access();
        } catch (RuntimeException $error) {
            $denied = $error->getMessage() === 'intercepted media 404';
        }
        check($denied && $GLOBALS['test_http_status'] === 404,
            'canonical media validation rejects hidden or outside linked targets');
    }
}
unlink($link_alias);
unlink($GLOBALS['test_dir'] . '/escape.jpg');
unlink($outside_file);
rmdir($outside_dir);
unset($GLOBALS['test_media_query']);
$enabledProp->setValue($media, true);

$restLive = $reflection->getMethod('read_live_protected_media_path');
check($restLive->invoke(null, 42) === ['path_id' => ''],
    'uncached REST boundary reads empty protection metadata from DB');
$wpdb->live_path = 'recently_protected';
check($restLive->invoke(null, 42) === ['path_id' => 'recently_protected'],
    'REST boundary detects a newly protected attachment without consulting postmeta cache');
$wpdb->live_error = true;
check($restLive->invoke(null, 42) === null,
    'REST boundary refuses to classify a DB failure as public metadata');
$wpdb->live_error = false;
$wpdb->live_path = null;
$wpdb->last_error = '';

// SERVER_SOFTWARE未設定のWP-CLIではNginxと推測して保護メタを追加しない。
unset($_SERVER['SERVER_SOFTWARE']);
check(ESP_Media_Protection::can_assign_protected_media() === false,
    'WP-CLI without web-server context cannot register protected media');
$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26';
check(ESP_Media_Protection::can_assign_protected_media() === true,
    'explicit Nginx deployment retains externally administered Rewrite policy');
check(ESP_Media_Protection::ensure_rewrite_policy() === true,
    'explicit Nginx uses an externally administered policy during verification');
unset($_SERVER['SERVER_SOFTWARE']);
$wpdb->count = 1;
check(ESP_Media_Protection::ensure_rewrite_policy() === false &&
    ESP_Media_Protection::audit_rewrite_policy() === false &&
    ESP_Media_Protection::can_enable_media_protection() === false,
    'unknown server cannot verify an existing protected-media deployment');
$wpdb->count = null;
$wpdb->last_error = 'DB unavailable';
check(ESP_Media_Protection::ensure_rewrite_policy() === false &&
    ESP_Media_Protection::audit_rewrite_policy() === false,
    'unknown server plus unknown media records never passes verification');
$wpdb->count = 0;
$wpdb->last_error = '';
check(ESP_Media_Protection::ensure_rewrite_policy() === true &&
    ESP_Media_Protection::can_enable_media_protection() === true,
    'unknown server with confirmed zero records keeps public site available');
$GLOBALS['enabled'] = false;
$wpdb->count = 1;
check(ESP_Media_Protection::ensure_rewrite_policy() === true,
    'explicitly disabled media protection remains available on unknown server');
$GLOBALS['enabled'] = true;
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';

$matchesPolicy = $reflection->getMethod('rewrite_file_matches_policy');
check($matchesPolicy->invoke(null, true) === false, 'incomplete legacy rewrite cannot satisfy policy marker');

$wpdb->count = null;
$wpdb->last_error = 'DB query failed';
check($media->update_htaccess() === true, 'Rewrite policy does not depend on an unavailable COUNT');
check(strpos(file_get_contents($file), 'esp-media') !== false, 'Rewrite stays installed when DB count is unknown');
check($matchesPolicy->invoke(null, true) === true, 'installed rewrite is verified on disk');

$wpdb->count = '';
$wpdb->last_error = '';
check($media->update_htaccess() === true, 'Rewrite policy also ignores invalid COUNT results');
$wpdb->count = 1;
check(ESP_Media_Protection::can_disable_media_protection() === true &&
    ESP_Media_Protection::protected_media_records_exist() === true,
    'intentional OFF permits protected records while surfacing warning state');
$wpdb->count = null;
$wpdb->last_error = 'DB unavailable';
check(ESP_Media_Protection::can_disable_media_protection() === true &&
    ESP_Media_Protection::protected_media_records_exist() === null,
    'DB failure does not veto an explicit administrator OFF');
$wpdb->count = 0;
$wpdb->last_error = '';
check(ESP_Media_Protection::can_disable_media_protection() === true,
    'pre-save guard allows OFF only after verified zero records');
$wpdb->count = 1;
$lockHandle = fopen($file . '.esp.lock', 'c');
flock($lockHandle, LOCK_EX);
$error = $media->update_htaccess();
check($error instanceof WP_Error && $error->get_error_code() === 'esp_htaccess_locked', 'competing writer cannot replace rewrite');
check(strpos(file_get_contents($file), 'esp-media') !== false, 'lock contention preserves existing file');
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
check($media->update_htaccess() === true, 'known protected media updates rewrite');
$new = file_get_contents($file);
check(strpos($new, 'esp-media') !== false && strpos($new, '# Other plugin rule') !== false, 'generated rewrite keeps unrelated rules');
check(strpos($new, "# ESP Rewrite Writer: " . ESP_VERSION . "\n") !== false,
    'newly installed Rewrite records ESP writer version');

// 古いPHPが新しいRewriteを復元・削除・書換えできないこと。
$future_rules = str_replace('# ESP Rewrite Writer: ' . ESP_VERSION,
    '# ESP Rewrite Writer: 0.7.39', $new);
file_put_contents($file, $future_rules);
$future_conflict = $media->update_htaccess();
check($future_conflict instanceof WP_Error &&
    $future_conflict->get_error_code() === 'esp_htaccess_future_writer' &&
    file_get_contents($file) === $future_rules,
    'older PHP writer cannot overwrite a newer-version ESP Rewrite block');
$future_cleanup = $media->update_htaccess(false, true);
check($future_cleanup instanceof WP_Error &&
    $future_cleanup->get_error_code() === 'esp_htaccess_future_writer' &&
    file_get_contents($file) === $future_rules,
    'older PHP worker cannot delete future Rewrite during deactivation');
$GLOBALS['enabled'] = false;
$future_off = $media->update_htaccess();
check($future_off instanceof WP_Error &&
    file_get_contents($file) === $future_rules,
    'older PHP worker cannot remove future Rewrite on media OFF');
$GLOBALS['enabled'] = true;

$malformed_writer = str_replace('# ESP Rewrite Writer: ' . ESP_VERSION,
    '# ESP Rewrite Writer: bad-version', $new);
file_put_contents($file, $malformed_writer);
$invalid = $media->update_htaccess();
check($invalid instanceof WP_Error &&
    $invalid->get_error_code() === 'esp_htaccess_writer_ambiguous' &&
    file_get_contents($file) === $malformed_writer,
    'unknown writer-version header is preserved for administrator review');

file_put_contents($file, $new);
$GLOBALS['options']['esp_runtime_state'] = ['state' => 'UPDATING', 'version' => ESP_VERSION];
$pending_error = $media->update_htaccess();
check($pending_error instanceof WP_Error &&
    $pending_error->get_error_code() === 'esp_htaccess_upgrade_in_progress' &&
    file_get_contents($file) === $new,
    'Rewrite writer respects persisted UPDATING state');
unset($GLOBALS['options']['esp_runtime_state']);
$GLOBALS['options'][ESP_Config::VERSION_OPTION_KEY] = '0.7.39';
$installed_newer = $media->update_htaccess();
check($installed_newer instanceof WP_Error &&
    $installed_newer->get_error_code() === 'esp_htaccess_stale_writer' &&
    file_get_contents($file) === $new,
    'newer installed-code version blocks an old worker even before Rewrite changes');
unset($GLOBALS['options'][ESP_Config::VERSION_OPTION_KEY]);

check(strpos($new, '# BEGIN ESP Media Protection') === 0 &&
    strpos($new, $foreign) > 0 && $matchesPolicy->invoke(null, true) === true,
    'R01: normal foreign Rewrite is placed after the ESP capture block');

// 明示的な他プラグイン拒否はESPより優先しなければならない。
$foreign_deny = "RewriteEngine On\nRewriteRule ^private/ - [F,L]\n";
file_put_contents($file, $foreign_deny);
check($media->update_htaccess() === true,
    'R01: foreign [F,L] denial remains compatible with ESP installation');
$with_deny = file_get_contents($file);
check(strpos($with_deny, $foreign_deny) === 0 &&
    $matchesPolicy->invoke(null, true) === true,
    'R01: explicit third-party denial executes before ESP');
$foreign_redirect_deny = "RewriteEngine On\nRewriteRule ^blocked/ - [R=403,L]\n";
file_put_contents($file, $foreign_redirect_deny);
check($media->update_htaccess() === true &&
    strpos(file_get_contents($file), $foreign_redirect_deny) === 0,
    'R01: explicit 403 redirect denial is not bypassed by ESP');

// 単純な[L]やWebP/AVIF変換はESP側の認可処理を先行させる。
$foreign_bypass = "RewriteEngine On\nRewriteRule ^private/ - [L]\n";
file_put_contents($file, $foreign_bypass);
check($media->update_htaccess() === true &&
    strpos(file_get_contents($file), '# BEGIN ESP Media Protection') === 0 &&
    $matchesPolicy->invoke(null, true) === true,
    'R01: early exit Rewrite runs only after ESP protection');
$foreign_image = "RewriteEngine On\nRewriteRule ^public\\.jpg$ - [L]\n";
file_put_contents($file, $foreign_image);
check($media->update_htaccess() === true &&
    strpos(file_get_contents($file), '# BEGIN ESP Media Protection') === 0,
    'R02: exact image early-exit is subordinate to ESP');

$cfm_style = "RewriteEngine On\nRewriteRule . - [L]\n" .
    "RewriteRule ^(.+)\\.(jpg|png|webp)$ /wp-content/uploads-webpc/uploads/$1.$2.avif [NC,T=image/avif,L]\n";
file_put_contents($file, $cfm_style);
check($media->update_htaccess() === true &&
    strpos(file_get_contents($file), '# BEGIN ESP Media Protection') === 0 &&
    $matchesPolicy->invoke(null, true) === true,
    'R02: static image optimization pass-through does not block ESP-first rewrite');

// 判定できない認可ゲートは上書きしない。
$foreign_gate = "RewriteEngine On\nRewriteRule ^protected/ /authorize.php?path=$1 [L]\n";
file_put_contents($file, $foreign_gate);
$gate_conflict = $media->update_htaccess();
check($gate_conflict instanceof WP_Error &&
    $gate_conflict->get_error_code() === 'esp_htaccess_foreign_rewrite_conflict' &&
    file_get_contents($file) === $foreign_gate,
    'R02: foreign PHP authorization gate cannot be superseded by ESP');
$foreign_proxy = "RewriteEngine On\nRewriteRule ^assets/ https://cdn.example.test/$1 [P,L]\n";
file_put_contents($file, $foreign_proxy);
check($media->update_htaccess() instanceof WP_Error &&
    file_get_contents($file) === $foreign_proxy,
    'R02: proxy gate is not presumed safe to bypass');

// 拒否・最適化が混在するとどちらの配置でも安全を証明できない。
$mixed = $foreign_deny . $foreign_bypass;
file_put_contents($file, $mixed);
check($media->update_htaccess() instanceof WP_Error &&
    file_get_contents($file) === $mixed,
    'R02: mixed deny and pass Rewrite retains original file without guessing');

// 明らかに非重複のリテラルRewriteは拒否ルールとの同居を許す。
$foreign_unrelated = "RewriteEngine On\nRewriteRule ^healthcheck\\.php$ - [L]\n";
file_put_contents($file, $foreign_deny . $foreign_unrelated);
check($media->update_htaccess() === true &&
    strpos(file_get_contents($file), $foreign_deny . $foreign_unrelated) === 0 &&
    $matchesPolicy->invoke(null, true) === true,
    'R02: provably unrelated non-media rewrite can precede ESP with deny');
$foreign_protected_txt = "RewriteEngine On\nRewriteRule ^healthcheck\\.txt$ - [L]\n";
file_put_contents($file, $foreign_protected_txt);
check($media->update_htaccess() === true &&
    strpos(file_get_contents($file), '# BEGIN ESP Media Protection') === 0,
    'R02: protected txt early exit is captured by ESP before foreign Rewrite');
$foreign_wildcard = "RewriteEngine On\nRewriteRule ^anything.* - [L]\n";
file_put_contents($file, $foreign_wildcard);
check($media->update_htaccess() === true &&
    strpos(file_get_contents($file), '# BEGIN ESP Media Protection') === 0,
    'R02: wildcard early exit is subordinate to ESP');
file_put_contents($file, $new);
check($matchesPolicy->invoke(null, true) === true,
    'R02: restoring verified ESP-first policy remains possible');

check(strpos($new, 'avif') !== false, 'AVIF is covered in replacement');
// LiteSpeedキー未設定の読取専用監査は、設定・Rewriteを一切変更しない。
$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
$GLOBALS['litespeed_key'] = '';
$GLOBALS['litespeed_key_writes'] = 0;
$unchanged = file_get_contents($file);
check(ESP_Media_Protection::audit_rewrite_policy() === false,
    'LiteSpeed audit reports missing key as a mismatch without generating it');
check($GLOBALS['litespeed_key_writes'] === 0 &&
    $GLOBALS['litespeed_key'] === '' &&
    file_get_contents($file) === $unchanged,
    'read-only LiteSpeed audit does not mutate key or Rewrite contents');
// 一方、明示的なRewrite書換えはキーを生成し、次回監査が純粋な読取となる。
check($media->update_htaccess() === true && $GLOBALS['litespeed_key'] !== '',
    'explicit LiteSpeed Rewrite update may initialize the missing key');
$writes_after_install = $GLOBALS['litespeed_key_writes'];
$installed_litespeed_rules = file_get_contents($file);
check(ESP_Media_Protection::audit_rewrite_policy() === true,
    'LiteSpeed audit accepts a complete Rewrite with existing key');
check($GLOBALS['litespeed_key_writes'] === $writes_after_install &&
    file_get_contents($file) === $installed_litespeed_rules,
    'LiteSpeed audit stays read-only after successful rule installation');
// 新キーが先にDBへ保存され、Rewrite失敗で旧キーと不一致になることを防ぐ。
$previous_litespeed_key = $GLOBALS['litespeed_key'];
$previous_litespeed_rules = file_get_contents($file);
$lock_file_handle = fopen($file . '.esp.lock', 'c');
flock($lock_file_handle, LOCK_EX);
$write_error = $media->update_htaccess(true);
check($write_error instanceof WP_Error &&
    $GLOBALS['litespeed_key'] === $previous_litespeed_key &&
    file_get_contents($file) === $previous_litespeed_rules,
    'LiteSpeed forced rotation under Rewrite lock failure preserves the old key and rules');
flock($lock_file_handle, LOCK_UN);
fclose($lock_file_handle);

class ESP_Failing_LiteSpeed_Verifier extends ESP_Media_Protection {
    protected function verify_rewrite_installation($file, $expected, $enabled) {
        return false;
    }
}
$bad_litespeed = (new ReflectionClass('ESP_Failing_LiteSpeed_Verifier'))->newInstanceWithoutConstructor();
$write_error = $bad_litespeed->update_htaccess(true);
check($write_error instanceof WP_Error &&
    $write_error->get_error_code() === 'esp_htaccess_rolled_back' &&
    $GLOBALS['litespeed_key'] === $previous_litespeed_key &&
    file_get_contents($file) === $previous_litespeed_rules,
    'LiteSpeed verification failure restores the previous Rewrite without changing the key');

$GLOBALS['litespeed_key_write_failure'] = true;
$write_error = $media->update_htaccess(true);
unset($GLOBALS['litespeed_key_write_failure']);
check($write_error instanceof WP_Error &&
    $write_error->get_error_code() === 'esp_litespeed_key_write' &&
    $GLOBALS['litespeed_key'] === $previous_litespeed_key &&
    file_get_contents($file) === $previous_litespeed_rules,
    'LiteSpeed database key commit failure rolls back to the old Rewrite and key');
$GLOBALS['simulate_litespeed_option_hook'] = true;
check($media->update_htaccess(true) === true &&
    $GLOBALS['litespeed_key'] !== $previous_litespeed_key &&
    strpos(file_get_contents($file), $GLOBALS['litespeed_key']) !== false &&
    ESP_Media_Protection::audit_rewrite_policy() === true,
    'successful LiteSpeed forced key rotation commits the new key only after rules pass verification');
check(!empty($GLOBALS['nested_key_update_safe']),
    'WordPress update_option hook does not recurse into another Rewrite write while the lock is held');
unset($GLOBALS['simulate_litespeed_option_hook']);

// DB保存前の例外はRewriteを旧状態へ戻す。保存済みの例外は
// DB/Rewriteの一致を優先し、旧Rewriteに誤って巻き戻さない。
$before_exception_key = $GLOBALS['litespeed_key'];
$before_exception_rules = file_get_contents($file);
$GLOBALS['litespeed_key_throw_before_write'] = true;
$write_error = $media->update_htaccess(true);
unset($GLOBALS['litespeed_key_throw_before_write']);
check($write_error instanceof WP_Error &&
    $write_error->get_error_code() === 'esp_litespeed_key_write' &&
    $GLOBALS['litespeed_key'] === $before_exception_key &&
    file_get_contents($file) === $before_exception_rules,
    'LiteSpeed exception before DB persistence rolls back to old key and rules');

$GLOBALS['litespeed_key_throw_after_write'] = true;
$success_after_hook_exception = $media->update_htaccess(true);
unset($GLOBALS['litespeed_key_throw_after_write']);
check($success_after_hook_exception === true &&
    $GLOBALS['litespeed_key'] !== $before_exception_key &&
    strpos(file_get_contents($file), $GLOBALS['litespeed_key']) !== false &&
    ESP_Media_Protection::audit_rewrite_policy() === true,
    'LiteSpeed exception after DB persistence retains matching new key and rules');

// 既存のApache分岐テストへ戻す。
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
check($media->update_htaccess() === true,
    'Apache rule restoration works after testing LiteSpeed');
$new = file_get_contents($file);


// 不完全・重複した保護ブロックを暗黙修復して安全と判定しない。
$duplicate = $new . "# BEGIN ESP Media Protection\n# END ESP Media Protection\n";
file_put_contents($file, $duplicate);
check($matchesPolicy->invoke(null, true) === false, 'duplicated rule markers cannot pass readiness');
$error = $media->update_htaccess();
check($error instanceof WP_Error && $error->get_error_code() === 'esp_htaccess_ambiguous', 'duplicate marker update rejected');
check(file_get_contents($file) === $duplicate, 'malformed rewrite contents not overwritten');
$partial = $new . "# BEGIN ESP Media Protection\n";
file_put_contents($file, $partial);
check($media->update_htaccess() instanceof WP_Error, 'unfinished ESP marker rejected');
check(file_get_contents($file) === $partial, 'incomplete existing rewrite remains untouched');
file_put_contents($file, $new);
// BEGIN/ENDが1回ずつ存在しても順序が逆なら拒否する。
$reversed = "# END ESP Media Protection\n# BEGIN ESP Media Protection\n" . $foreign;
file_put_contents($file, $reversed);
check($matchesPolicy->invoke(null, true) === false, 'reversed markers fail readiness');
$error = $media->update_htaccess();
check($error instanceof WP_Error && $error->get_error_code() === 'esp_htaccess_ambiguous', 'reversed markers rejected before writing');
check(file_get_contents($file) === $reversed, 'reversed marker file remains intact');
file_put_contents($file, $new);

// 置換後の検証失敗を明示的に注入し、以前のファイルが維持されることを確認する。
class ESP_Failing_Rewrite_Verifier extends ESP_Media_Protection {
    protected function verify_rewrite_installation($file, $expected, $enabled) {
        return false;
    }
}
$failing = (new ReflectionClass('ESP_Failing_Rewrite_Verifier'))->newInstanceWithoutConstructor();
file_put_contents($file, $old);
chmod($file, 0640);
$error = $failing->update_htaccess();
check($error instanceof WP_Error && $error->get_error_code() === 'esp_htaccess_rolled_back',
    'failed post-write verification triggers rollback');
check(file_get_contents($file) === $old, 'rollback restores existing file verbatim');
check((fileperms($file) & 0777) === 0640, 'rollback restores old permissions');
check(glob($GLOBALS['test_dir'] . '/.htaccess.esp-restore-*') === [],
    'rolled-back update cleans its restore staging files');
file_put_contents($file, $new);
chmod($file, 0644);

// もともとファイルが無いサイトでは、検証失敗を理由に新規ルールを削除しない。
unlink($file);
$error = $failing->update_htaccess();
check($error instanceof WP_Error && $error->get_error_code() === 'esp_htaccess_verify_failed',
    'new Rewrite file verification failure is not success');
check(file_exists($file) && strpos(file_get_contents($file), 'esp-media') !== false,
    'new protective file is not deleted by failed verification');
file_put_contents($file, $new);

// 管理者が明示的に設定OFFを選んだ場合は、保護メタが残っていても
// ONルールを除去可能（保護メタの値そのものは維持する）。
$GLOBALS['enabled'] = false;
$wpdb->count = 1;
$wpdb->last_error = '';
check($media->update_htaccess() === true,
    'intentional OFF permits rewrite removal even with protected metadata');
check(file_get_contents($file) === $foreign,
    'intentional OFF removes ESP rules without touching third-party rules');
$wpdb->count = null;
$wpdb->last_error = 'unavailable';
check($media->update_htaccess() === true,
    'repeated OFF update does not depend on protected media COUNT');
check(file_get_contents($file) === $foreign,
    'repeated OFF update preserves unrelated rules');
$GLOBALS['enabled'] = true;
$wpdb->last_error = '';
$wpdb->count = 0;
check($media->update_htaccess() === true, 'zero protected records still install rewrite');
check(strpos(file_get_contents($file), 'esp-media') !== false, 'zero-to-one transition already covered by rewrite');

// デプロイ後にバージョン印がなくても、既存ルールが正しければ
// 書き込みロックを作らず、そのまま利用できることを検証する。
@unlink($file . '.esp.lock');
unset($GLOBALS['options']['esp_rewrite_policy_version']);
chmod($GLOBALS['test_dir'], 0555);
check(ESP_Media_Protection::ensure_rewrite_policy() === true,
    'enabled site with correct Rewrite remains ready in a read-only directory');
check(get_option('esp_rewrite_policy_version') === 'always-route-v2:on',
    'enabled on-disk policy synchronizes an absent version marker');
check(!file_exists($file . '.esp.lock'), 'enabled no-op policy never opens a writer lock');
chmod($GLOBALS['test_dir'], 0700);

$wpdb->count = 1;
check($media->update_htaccess(false, true) === true,
    'explicit plugin deactivation removes ESP Rewrite with protected media');
check(file_get_contents($file) === $foreign,
    'deactivation preserves unrelated Rewrite rules');
check($media->update_htaccess() === true, 'enabled media can install Rewrite again');
$wpdb->count = null;
$wpdb->last_error = 'DB unavailable';
check($media->update_htaccess(false, true) === true,
    'explicit deactivation does not depend on protected-media DB COUNT');
check(file_get_contents($file) === $foreign,
    'deactivation removes only ESP Rewrite when DB state is unknown');
$wpdb->last_error = '';
$wpdb->count = 0;
check($media->update_htaccess(false, true) === true,
    'deactivation is idempotent after ESP rules are removed');
check(file_get_contents($file) === $foreign, 'repeated deactivation preserves other rules');
$GLOBALS['enabled'] = false;
check($media->update_htaccess() === true, 'explicitly disabled media protection removes rewrite');
check(file_get_contents($file) === $foreign, 'only explicit disable removes ESP block');
check($matchesPolicy->invoke(null, true) === false, 'removed rule cannot pass enabled policy');
check($matchesPolicy->invoke(null, false) === true, 'explicitly disabled policy matches no rule');
check($media->update_htaccess() === true, 'disabled rewrite update is idempotent');

// 保護無効かつRewrite未設定のサイトでは、バージョン印が未初期化でも
// .htaccessへの書き込み権限を要求しないことを検証する。
@unlink($file . '.esp.lock');
unset($GLOBALS['options']['esp_rewrite_policy_version']);
chmod($GLOBALS['test_dir'], 0555);
check(ESP_Media_Protection::ensure_rewrite_policy() === true,
    'disabled site remains ready when a correct Rewrite state is read-only');
check(get_option('esp_rewrite_policy_version') === 'always-route-v2:off',
    'correct on-disk state synchronizes the policy marker');
check(!file_exists($file . '.esp.lock'), 'no lock file is opened for a no-op policy');
chmod($GLOBALS['test_dir'], 0700);

// 保護対象0件ならRewrite修復不能でも通常のサイト全体を503にしない。
// ただしルールを設置できるまでは保護メタの追加を許可しない。
$wpdb->count = 0;
$wpdb->last_error = '';
$GLOBALS['enabled'] = true;
file_put_contents($file, $foreign);
$writeLock = fopen($file . '.esp.lock', 'c');
flock($writeLock, LOCK_EX);
check(ESP_Media_Protection::ensure_rewrite_policy() === true,
    'zero protected media keeps public site ready when rewrite repair is locked');
check(ESP_Media_Protection::can_assign_protected_media() === false,
    'unknown Rewrite policy prevents assigning first protected image');
$GLOBALS['enabled'] = false;
check(ESP_Media_Protection::can_enable_media_protection() === true,
    'OFF to ON pre-save validation checks only zero protected media');
check(file_get_contents($file) === $foreign,
    'pre-save validation never installs Rewrite, even if an option write subsequently fails');
$wpdb->count = null;
$wpdb->last_error = 'DB unavailable';
check(ESP_Media_Protection::can_enable_media_protection() === false,
    'OFF to ON rejects unknown protection state without touching Rewrite');
check(file_get_contents($file) === $foreign,
    'DB-failed setting save cannot alter foreign Rewrite rules');
$wpdb->count = 0;
$wpdb->last_error = '';
flock($writeLock, LOCK_UN);
fclose($writeLock);
$GLOBALS['enabled'] = true;
check($media->update_htaccess() === true,
    'confirmed settings save installs Rewrite in post-save handler');
check($matchesPolicy->invoke(null, true) === true,
    'post-save enabled Rewrite is complete');
check(ESP_Media_Protection::can_assign_protected_media() === true,
    'protected metadata becomes assignable only with active Rewrite');
check(strpos(file_get_contents($file), $foreign) !== false,
    'post-save Rewrite preserves foreign rules');

// 旧版のOFF + 保護メタありから、ON保存前にRewriteを検証付きで再設置。
$GLOBALS['enabled'] = false;
$wpdb->count = 1;
$wpdb->last_error = '';
file_put_contents($file, $foreign);
check(ESP_Media_Protection::can_enable_media_protection() === true,
    'legacy OFF with protected metadata can preinstall Rewrite for safe recovery');
check($matchesPolicy->invoke(null, true) === true,
    'recovery preflight confirms enabled Rewrite before saving ON');
check($GLOBALS['enabled'] === false,
    'preflight does not switch DB setting to ON before WordPress option save');
file_put_contents($file, $foreign);
$blocked = fopen($file . '.esp.lock', 'c');
flock($blocked, LOCK_EX);
check(ESP_Media_Protection::can_enable_media_protection() === false &&
    file_get_contents($file) === $foreign,
    'failed rewrite preflight rejects recovery without losing foreign rules');
flock($blocked, LOCK_UN);
fclose($blocked);
$wpdb->count = null;
$wpdb->last_error = 'DB down';
check(ESP_Media_Protection::can_enable_media_protection() === false,
    'unknown legacy metadata blocks unsafe ON restoration');
$wpdb->count = 0;
$wpdb->last_error = '';

// OFFへの保存後に保護メタが残り、旧Rewriteがあっても公開画像を配信。
$public_image = $GLOBALS['test_dir'] . '/public.jpg';
file_put_contents($public_image, 'public bytes');
$GLOBALS['enabled'] = false;
$wpdb->count = 1; // 旧版の保護メタは残っている
$wpdb->last_error = '';
$GLOBALS['test_media_query'] = 'public.jpg';
$_SERVER['REQUEST_URI'] = '/wp-content/uploads/public.jpg';
$enabledProp = $reflection->getProperty('enabled');
$enabledProp->setValue($media, false);
$delivered = new class {
    public $files = [];
    public function deliver($file) { $this->files[] = $file; return true; }
};
$reflection->getProperty('deriver')->setValue($media, $delivered);
$media->handle_media_access();
check($delivered->files === [realpath($public_image)],
    'disabled media serves public image when stale ON Rewrite still routes it into WordPress');
// OFFでも公開トップのクエリがuploadsの拒否ルールを迂回しない。
$GLOBALS['test_media_query'] = 'public.jpg';
$_SERVER['REQUEST_URI'] = '/?esp-media=public.jpg';
$direct_denied = false;
try { $media->handle_media_access(); } catch (RuntimeException $error) {
    $direct_denied = $error->getMessage() === 'intercepted media 404';
}
check($direct_denied && $GLOBALS['test_http_status'] === 404 &&
    $delivered->files === [realpath($public_image)],
    'R02: direct public esp-media query cannot deliver uploads file while OFF');
// 元URLのファイル名とクエリの指定先が異なる場合も拒否する。
$_SERVER['REQUEST_URI'] = '/wp-content/uploads/another.jpg?esp-media=public.jpg';
$mismatch_denied = false;
try { $media->handle_media_access(); } catch (RuntimeException $error) {
    $mismatch_denied = $error->getMessage() === 'intercepted media 404';
}
check($mismatch_denied && count($delivered->files) === 1,
    'R02: mismatched source URL cannot route to a different uploads file');
$_SERVER['REQUEST_URI'] = '/wp-content/uploads/public.jpg';

unlink($public_image);
unset($GLOBALS['test_media_query']);
$enabledProp->setValue($media, true);
$GLOBALS['enabled'] = true;

// uploadsのルートがシンボリックリンクでも、実体ディレクトリ内の一時ファイルを
// 安全に置換できることを検証する（PHPのtempnam()はリンク先を返す場合がある）。
$original_dir = $GLOBALS['test_dir'];
$actual_dir = $original_dir . '/uploads-real';
$link_dir = $original_dir . '/uploads-link';
mkdir($actual_dir, 0700);
check(symlink($actual_dir, $link_dir), 'uploadsルートのシンボリックリンクを作成できる');
$GLOBALS['test_dir'] = $link_dir;
$GLOBALS['enabled'] = true;
$wpdb->count = 1;
$symlink_htaccess = $link_dir . '/.htaccess';
file_put_contents($symlink_htaccess, $foreign);
chmod($symlink_htaccess, 0640);
check($media->update_htaccess() === true, 'uploadsルートがシンボリックリンクでもRewriteを更新できる');
$updated = file_get_contents($symlink_htaccess);
check(strpos($updated, 'esp-media') !== false &&
    strpos($updated, $foreign) !== false, '実体ディレクトリのルール更新で他プラグインの設定を維持する');
check((fileperms($symlink_htaccess) & 0777) === 0640, 'シンボリックリンク環境でも権限を保持する');
check($matchesPolicy->invoke(null, true) === true, 'シンボリックリンク経由でもRewrite更新後の内容を検証できる');
check(glob($actual_dir . '/.esp-*') === [] &&
    glob($actual_dir . '/.htaccess.esp-*') === [], '一時ファイルと退避ファイルを残さない');
@unlink($symlink_htaccess);
@unlink($symlink_htaccess . '.esp.lock');
@unlink($link_dir);
@rmdir($actual_dir);
$GLOBALS['test_dir'] = $original_dir;

// R01: 添付照会に失敗した時だけ配信を止める（正常な未登録は従来公開）。
$test_public = $GLOBALS['test_dir'] . '/ordinary.jpg';
$test_avif = $GLOBALS['test_dir'] . '/ordinary-200x200.avif';
file_put_contents($test_public, 'ordinary bytes');
file_put_contents($test_avif, 'avif bytes');
$resolveAttachment = $reflection->getMethod('get_attachment_id_from_path');
$wpdb->attached_id = null;
$wpdb->attached_query_error = false;
$wpdb->candidate_ids = [];
check($resolveAttachment->invoke($media, $test_public) === false,
    'R01: successful attachment SELECT with no rows is explicitly unregistered');
$wpdb->attached_query_error = true;
check($resolveAttachment->invoke($media, $test_public) === null,
    'R01: primary attachment SELECT error is unknown, not unregistered');
$wpdb->attached_query_error = false;
$wpdb->candidate_query_error = true;
check($resolveAttachment->invoke($media, $test_avif) === null,
    'R01: AVIF parent candidate SELECT error is also unknown');
$wpdb->candidate_query_error = false;
check($resolveAttachment->invoke($media, $test_avif) === false,
    'R01: successful AVIF candidate SELECT with zero rows remains unregistered');
$wpdb->attached_id = 42;
check($resolveAttachment->invoke($media, $test_public) === 42,
    'R01: valid primary attachment ID is retained');

$delivery = new class {
    public $files = [];
    public function deliver($path) { $this->files[] = $path; return true; }
};
$reflection->getProperty('deriver')->setValue($media, $delivery);
$enabledProp->setValue($media, true);
$GLOBALS['test_media_query'] = 'ordinary.jpg';
$_SERVER['REQUEST_URI'] = '/wp-content/uploads/ordinary.jpg';
$wpdb->attached_query_error = true;
$denied = false;
try { $media->handle_media_access(); } catch (RuntimeException $e) {
    $denied = $e->getMessage() === 'intercepted media 503';
}
check($denied && $GLOBALS['test_http_status'] === 503 && $delivery->files === [],
    'R01: anonymous PHP media response is denied when attachment lookup fails');
$wpdb->attached_query_error = false;
$wpdb->attached_id = 42;
$wpdb->live_error = true;
$denied = false;
try { $media->handle_media_access(); } catch (RuntimeException $e) {
    $denied = $e->getMessage() === 'intercepted media 503';
}
check($denied && $GLOBALS['test_http_status'] === 503 && $delivery->files === [],
    'R01: attached media fails closed when live protected-path read fails');
$wpdb->live_error = false;
$wpdb->live_path = null;
$media->handle_media_access();
check($delivery->files === [realpath($test_public)],
    'R01: attached public file remains available after successful unprotected metadata read');
$wpdb->attached_id = null;
$media->handle_media_access();
check(count($delivery->files) === 2,
    'R01: normal unregistered public media remains available');
unset($GLOBALS['test_media_query']);
unlink($test_public);
unlink($test_avif);

// R02: Webサーバー情報がないWP-CLIでもESP自身のRewriteだけ除去する。
$GLOBALS['enabled'] = true;
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
check($media->update_htaccess() === true, 'R02: prepare installed ESP Rewrite for CLI removal test');
$before_cli = file_get_contents($file);
check(strpos($before_cli, '# BEGIN ESP Media Protection') !== false,
    'R02: Rewrite is installed before CLI deactivation');
unset($_SERVER['SERVER_SOFTWARE']);
check($media->update_htaccess(false, true) === true &&
    file_get_contents($file) === $foreign,
    'R02: CLI without SERVER_SOFTWARE removes ESP block but preserves foreign bytes');
@unlink($file . '.esp.lock');
check($media->update_htaccess(false, true) === true && !file_exists($file . '.esp.lock'),
    'R02: repeated CLI deactivation needs no rewrite lock without ESP marker');
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
check($media->update_htaccess() === true,
    'R02: reinstall ESP block before simulated lock failure');
unset($_SERVER['SERVER_SOFTWARE']);
$cli_lock = fopen($file . '.esp.lock', 'c');
flock($cli_lock, LOCK_EX);
$cli_error = $media->update_htaccess(false, true);
check($cli_error instanceof WP_Error && $cli_error->get_error_code() === 'esp_htaccess_locked' &&
    strpos(file_get_contents($file), '# BEGIN ESP Media Protection') !== false,
    'R02: locked CLI deactivation reports failure instead of claiming deletion');
flock($cli_lock, LOCK_UN);
fclose($cli_lock);
check($media->update_htaccess(false, true) === true && file_get_contents($file) === $foreign,
    'R02: CLI can finish removal when writer lock is released');
// AJAXのOFF・非Apache系: 削除失敗時に成功応答を返さない。
$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.27';
$GLOBALS['enabled'] = false;
$GLOBALS['ajax_admin'] = true;
$enabledProp->setValue($media, false);
file_put_contents($file, $foreign);
try {
    $media->ajax_reset_htaccess_rules();
    check(false, 'AJAX should terminate after successful response');
} catch (RuntimeException $error) {
    check(strpos($error->getMessage(), 'ajax:success:') === 0,
        'OFF on non-Apache with no ESP block reports success');
}
$esp_block = "# BEGIN ESP Media Protection\nRewriteEngine On\n# END ESP Media Protection\n";
file_put_contents($file, $foreign . $esp_block);
$ajax_lock = fopen($file . '.esp.lock', 'c');
flock($ajax_lock, LOCK_EX);
try {
    $media->ajax_reset_htaccess_rules();
    check(false, 'AJAX must not return normally after failed cleanup');
} catch (RuntimeException $error) {
    check(strpos($error->getMessage(), 'ajax:error::') === 0 &&
        strpos($error->getMessage(), '別の保護ルール更新が進行中です') !== false,
        'OFF on non-Apache reports Rewrite cleanup lock failure as error');
}
check(file_get_contents($file) === $foreign . $esp_block,
    'failed AJAX cleanup preserves existing ESP and third-party Rewrite bytes');
flock($ajax_lock, LOCK_UN);
fclose($ajax_lock);
try {
    $media->ajax_reset_htaccess_rules();
    check(false, 'AJAX should terminate after successful cleanup');
} catch (RuntimeException $error) {
    check(strpos($error->getMessage(), 'ajax:success:') === 0,
        'OFF on non-Apache reports success only after removing the ESP block');
}
check(file_get_contents($file) === $foreign,
    'successful AJAX cleanup preserves third-party Rewrite bytes');
$enabledProp->setValue($media, true);
$GLOBALS['enabled'] = true;
try {
    $media->ajax_reset_htaccess_rules();
    check(false, 'AJAX should terminate after unsupported ON response');
} catch (RuntimeException $error) {
    check(strpos($error->getMessage(), 'ajax:error:400:') === 0,
        'ON on non-Apache still returns the existing unsupported-server error');
}
unset($GLOBALS['ajax_admin']);
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';

@unlink($file);
@unlink($file . '.esp.lock');
@unlink($GLOBALS['test_dir'] . '/esp-test-404.php');
@rmdir($GLOBALS['test_dir']);
echo "All rewrite fail-closed tests passed.\n";
