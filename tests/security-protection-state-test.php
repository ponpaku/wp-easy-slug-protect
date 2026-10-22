<?php
/** Event-driven runtime gate: normal reads never invoke update/DB/Rewrite checks. */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('ESP_VERSION', '0.7.38');
define('ESP_PATH', __DIR__ . '/../');
require __DIR__ . '/../includes/class-esp-config.php';

class TestSetup {
    public $ok = true;
    public $checks = 0;
    public function check_plugin_version() {
        $this->checks++;
        if (!empty($GLOBALS['simulate_db_reconnect'])) {
            global $wpdb;
            $wpdb->lock_owner = null;
            $wpdb->lock_connection = 18;
        }
        return $this->ok;
    }
}
class BootTestDB {
    public $last_error = '';
    public $online = true;
    public $legacy_schema_valid = true;
    public $queries = 0;
    public $prefix = 'wp_';
    public $lock_busy = false;
    public $lock_connection = 17;
    public $lock_owner = null;
    public $complete_on_acquire = false;
    public $state_on_acquire = null;
    public function prepare($query, ...$args) {
        return str_replace('%s', "'" . ($args[0] ?? '') . "'", $query);
    }
    public function get_col($query) {
        $this->queries++;
        $this->last_error = $this->online ? '' : 'Disconnected';
        if (!$this->online || !$this->legacy_schema_valid) { return []; }
        return ['id', 'ip_address', 'path', 'path_id', 'time', 'password_version', 'user_id',
            'token', 'created', 'expires', 'window_started', 'attempts', 'blocked_until',
            'updated_at', 'attempt_times', 'last_attempt_token'];
    }
    public function get_results($query, $output = null) {
        $this->queries++;
        $this->last_error = $this->online ? '' : 'Disconnected';
        if (!$this->online || !$this->legacy_schema_valid) { return []; }
        return [
            ['Seq_in_index' => 1, 'Column_name' => 'ip_address'],
            ['Seq_in_index' => 2, 'Column_name' => 'path_id'],
        ];
    }
    public function get_var($query) {
        $this->queries++;
        if (!$this->online) { $this->last_error = 'Disconnected'; return null; }
        $this->last_error = '';
        if (strpos($query, 'SELECT GET_LOCK(') !== false) {
            if ($this->lock_busy || $this->lock_owner !== null) { return 0; }
            $this->lock_owner = $this->lock_connection;
            if ($this->state_on_acquire !== null) {
                $GLOBALS['options']['esp_runtime_state'] = $this->state_on_acquire;
                $this->state_on_acquire = null;
            }
            if ($this->complete_on_acquire) {
                $GLOBALS['options']['esp_runtime_state'] = runtime_value('READY');
                $this->complete_on_acquire = false;
            }
            return 1;
        }
        if (strpos($query, 'SELECT IS_USED_LOCK(') !== false) {
            return $this->lock_owner;
        }
        if (strpos($query, 'SELECT RELEASE_LOCK(') !== false) {
            if ($this->lock_owner !== $this->lock_connection) { return 0; }
            $this->lock_owner = null;
            return 1;
        }
        if (strpos($query, 'SELECT CONNECTION_ID()') !== false) {
            return $this->lock_connection;
        }
        return strpos($query, 'information_schema.TABLES') !== false
            ? ($this->legacy_schema_valid ? 'InnoDB' : 'MyISAM') : 1;
    }
}
class ESP_Media_Protection {
    const REWRITE_ENDPOINT = 'esp-media';
    public static $protected_records = false;
    public static function protected_media_records_exist() { return self::$protected_records; }
    public static $apply_ok = true;
    public static $apply_exception = false;
    public static $audit_ok = true;
    public static $checks = 0;
    public static $audits = 0;
    public static function ensure_rewrite_policy() {
        self::$checks++;
        if (self::$apply_exception) {
            throw new RuntimeException('simulated Rewrite exception');
        }
        if (self::$apply_ok) {
            delete_option('esp_rewrite_last_error');
            return true;
        }
        return false;
    }
    public static function audit_rewrite_policy() {
        self::$audits++;
        return self::$audit_ok;
    }
}
class WP_Error {
    private $data;
    private $code;
    public function __construct($code, $message = '', $data = []) {
        $this->code = $code; $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function __($msg, $domain = null) { return $msg; }
function esc_html__($msg, $domain = null) { return $msg; }
function esc_url($url) { return $url; }
function admin_url($uri) { return '/wp-admin/' . $uri; }
function wp_nonce_field($action) { echo '<input name="_wpnonce" value="test" />'; }
function get_option($name, $default = null) {
    return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default;
}
function add_option($name, $value, $deprecated = '', $autoload = null) {
    if (array_key_exists($name, $GLOBALS['options'])) { return false; }
    $GLOBALS['options'][$name] = $value;
    return true;
}
function delete_option($name) { unset($GLOBALS['options'][$name]); return true; }
function wp_next_scheduled($name) { return $GLOBALS['scheduled'][$name] ?? false; }
function wp_schedule_single_event($when, $name) {
    $GLOBALS['scheduled'][$name] = $when;
    return true;
}
function update_option($name, $value, $autoload = null) {
    if ($name === 'esp_runtime_state') {
        $GLOBALS['last_runtime_autoload'] = $autoload;
    }
    if (!empty($GLOBALS['reject_update']) ||
        ($name === 'esp_rewrite_last_error' &&
            !empty($GLOBALS['reject_rewrite_error_update']))) {
        return false;
    }
    $GLOBALS['options'][$name] = $value;
    return true;
}
function add_filter($hook, $callback, $priority = 10, $args = 1) {
    $GLOBALS['filters'][$hook][] = [$callback, $priority];
}
function add_action($hook, $callback, $priority = 10, $args = 1) {
    $GLOBALS['actions'][$hook][] = [$callback, $priority];
}
function plugin_basename($file) { return 'easy-slug-protect/easy-slug-protect.php'; }
function current_user_can($capability) { return $capability === 'manage_options' && ($GLOBALS['test_manage_options'] ?? true); }
function is_singular() { return $GLOBALS['test_singular'] ?? true; }
function is_front_page() { return $GLOBALS['test_front_page'] ?? false; }
function is_home() { return $GLOBALS['test_home'] ?? false; }
function is_attachment() { return $GLOBALS['test_attachment'] ?? false; }
function get_queried_object_id() { return $GLOBALS['test_queried_id'] ?? 42; }
function get_permalink($id) { return $GLOBALS['test_permalink'] ?? 'https://example.com/about/'; }
function home_url($path = '') { return 'https://example.com' . $path; }
function get_query_var($name, $default = null) { return $GLOBALS['test_query_vars'][$name] ?? $default; }
function wp_doing_ajax() { return $GLOBALS['test_doing_ajax'] ?? false; }
function nocache_headers() {}
function status_header($status) { $GLOBALS['test_http_status'] = $status; }
function wp_die($message, $title = '', $options = []) {
    throw new RuntimeException('wp_die_' . ($options['response'] ?? 0));
}
function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback, $icon = '', $position = null) {
    $GLOBALS['admin_menu'] = compact('page_title', 'menu_title', 'capability', 'menu_slug', 'callback');
}
function check($ok, $msg) {
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    echo "PASS: $msg\n";
}
function runtime_value($state, $version = ESP_VERSION) {
    $result = ['state' => $state, 'version' => $version];
    if ($state === 'UPDATING') {
        $result['since'] = time();
    }
    return $result;
}
function saved_runtime_state() {
    $value = get_option('esp_runtime_state', null);
    return is_array($value) ? ($value['state'] ?? null) : $value;
}
require __DIR__ . '/../includes/class-esp-protection-state.php';
$wpdb = new BootTestDB();
$setup = new TestSetup();
$GLOBALS['options'] = [
    ESP_Config::OPTION_KEY => ['path' => [], 'media' => ['enabled' => true]],
    'esp_db_version' => ESP_Config::OPTION_DEFAULTS['db_version'],
    ESP_Config::VERSION_OPTION_KEY => ESP_VERSION,
];
check(ESP_Protection_State::boot($setup) === true,
    'initial installation verifies once and recovers without administrator visit');
check($setup->checks === 1 && $wpdb->queries > 0 && ESP_Media_Protection::$checks === 1,
    'first unverified boot alone may perform full validation');
check(ESP_Protection_State::verify_and_commit($setup) === true,
    'administrator can also explicitly validate and commit READY');
check(get_option('esp_runtime_state') === runtime_value('READY'),
    'successful verification atomically stores READY with verified code version');
check($GLOBALS['last_runtime_autoload'] === false,
    'M1: runtime state is stored as non-autoload to avoid alloptions rollback races');
check(saved_runtime_state() === 'READY', 'valid state is saved');
check($wpdb->queries > 0 && $setup->checks === 2 && ESP_Media_Protection::$checks === 2,
    'explicit verification performs migration, schema and Rewrite checks');

// メディア配信のサーバー側Rewrite失敗はPHP認可の起動失敗ではない。
// 既存保護は「安全」と保証せず、原因を持続表示する。新規保護のガードは別。
ESP_Media_Protection::$protected_records = true;
ESP_Media_Protection::$apply_ok = false;
$GLOBALS['options']['esp_runtime_state'] = runtime_value('VERIFY_REQUIRED');
$rewrite_attempts = ESP_Media_Protection::$checks;
check(ESP_Protection_State::boot($setup) === true &&
    saved_runtime_state() === 'READY' &&
    ESP_Media_Protection::$checks === $rewrite_attempts + 1 &&
    get_option('esp_rewrite_last_error') === 'esp_rewrite_unverified',
    'media Rewrite conflict is diagnosed without disabling PHP authorization');
$rewrite_attempts = ESP_Media_Protection::$checks;
check(ESP_Protection_State::boot($setup) === true &&
    ESP_Media_Protection::$checks === $rewrite_attempts,
    'READY authorization startup never rechecks Rewrite on every request');

// 想定された配置競合と違い、予期しない例外はDB・設定不整合も疑い拒否する。
ESP_Media_Protection::$apply_exception = true;
$GLOBALS['options']['esp_runtime_state'] = runtime_value('VERIFY_REQUIRED');
check(ESP_Protection_State::boot($setup) === false &&
    saved_runtime_state() === 'FAILED' &&
    get_option('esp_rewrite_last_error') === 'esp_rewrite_verify_exception',
    'unexpected Rewrite verification exception still fails closed');
ESP_Media_Protection::$apply_exception = false;
ESP_Media_Protection::$apply_ok = true;
$GLOBALS['options']['esp_runtime_state'] = runtime_value('VERIFY_REQUIRED');
check(ESP_Protection_State::boot($setup) === true &&
    saved_runtime_state() === 'READY' &&
    get_option('esp_rewrite_last_error', '') === '',
    'explicit successful Rewrite reverification clears previous degraded warning');

// メディア保護未保証の通知すら保存できないなら、READYとは確定しない。
ESP_Media_Protection::$apply_ok = false;
$GLOBALS['reject_rewrite_error_update'] = true;
$GLOBALS['options']['esp_runtime_state'] = runtime_value('VERIFY_REQUIRED');
check(ESP_Protection_State::boot($setup) === false &&
    saved_runtime_state() === 'FAILED' &&
    get_option('esp_rewrite_last_error', '') === '',
    'unreportable degraded media state must not be silently certified READY');
unset($GLOBALS['reject_rewrite_error_update']);
ESP_Media_Protection::$apply_ok = true;
$GLOBALS['options']['esp_runtime_state'] = runtime_value('VERIFY_REQUIRED');
check(ESP_Protection_State::boot($setup) === true &&
    saved_runtime_state() === 'READY',
    'explicit administrator re-verification can recover after diagnostic storage failure');
ESP_Media_Protection::$protected_records = false;

// DB版印が欠損しても、移行済みpath IDとスキーマが安定していれば既存サイトを維持。
// 不正・途中移行のpath IDを「正常なバージョン欠損」と誤認しない。
$runtime_saved_settings = $GLOBALS['options'][ESP_Config::OPTION_KEY];
$runtime_saved_marker = $GLOBALS['options']['esp_db_version'];
$stable_path = 'path_original_123';
$GLOBALS['options'][ESP_Config::OPTION_KEY]['path'] = [
    $stable_path => ['id' => $stable_path, 'path' => '/members/'],
];
unset($GLOBALS['options']['esp_db_version']);
$GLOBALS['options']['esp_runtime_state'] = runtime_value('VERIFY_REQUIRED');
check(ESP_Protection_State::boot($setup) === true &&
    saved_runtime_state() === 'READY' &&
    !array_key_exists('esp_db_version', $GLOBALS['options']) &&
    $GLOBALS['options'][ESP_Config::OPTION_KEY]['path'][$stable_path]['id'] === $stable_path,
    'missing db marker accepts verified, stable legacy path IDs without rewriting them');

foreach ([
    ['path' => [$stable_path => ['id' => 'path_mismatch', 'path' => '/members/']],
        'label' => 'mismatched path ID'],
    ['path' => [$stable_path => ['path' => '/members/']],
        'label' => 'missing path ID'],
    ['path' => [['id' => 'path_numeric', 'path' => '/members/']],
        'label' => 'numeric-key legacy path'],
] as $case) {
    $GLOBALS['options'][ESP_Config::OPTION_KEY]['path'] = $case['path'];
    $GLOBALS['options']['esp_runtime_state'] = runtime_value('VERIFY_REQUIRED');
    check(ESP_Protection_State::boot($setup) === false &&
        saved_runtime_state() === 'FAILED' &&
        !array_key_exists('esp_db_version', $GLOBALS['options']),
        'missing db marker rejects ' . $case['label'] . ' without migration');
}
$GLOBALS['options'][ESP_Config::OPTION_KEY]['path'] = [];
$GLOBALS['options']['esp_runtime_state'] = runtime_value('VERIFY_REQUIRED');
check(ESP_Protection_State::boot($setup) === true &&
    saved_runtime_state() === 'READY',
    'fresh site with zero protected paths still validates without a DB marker');

$GLOBALS['options']['esp_db_version'] = 'corrupt-version-marker';
$GLOBALS['options']['esp_runtime_state'] = runtime_value('VERIFY_REQUIRED');
check(ESP_Protection_State::boot($setup) === false &&
    saved_runtime_state() === 'FAILED',
    'unrecognized DB marker does not pass READY even with valid settings and schema');
$GLOBALS['options'][ESP_Config::OPTION_KEY] = $runtime_saved_settings;
$GLOBALS['options']['esp_db_version'] = $runtime_saved_marker;
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY');
// このスタブは単一PHPプロセスで複数リクエストを模擬するため、先ほどの
// FAILEDテストで登録された拒否フックを次の独立ケースへ持ち越さない。
$GLOBALS['filters']['rest_pre_dispatch'] = [];
$GLOBALS['actions']['template_redirect'] = [];

// 旧文字列READYには検証済み版の証拠がない。現在版を勝手に付けず再検証する。
$GLOBALS['options']['esp_runtime_state'] = 'READY';
$setup->checks = 0;
check(ESP_Protection_State::boot($setup) === true && $setup->checks === 1 &&
    get_option('esp_runtime_state') === runtime_value('READY'),
    'legacy string READY verifies once before converting to versioned state');

// WordPress Upgraderのフックを通さずコードを更新した場合も検証し直す。
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY', '0.7.37');
$setup->checks = 0;
check(ESP_Protection_State::boot($setup) === true && $setup->checks === 1 &&
    get_option('esp_runtime_state') === runtime_value('READY'),
    'mismatched READY version triggers one-shot verification and stamps current version');




// 自動・手動の両検証は同じ排他を使い、同時移行を開始しない。
$wpdb->lock_busy = true;
$before_checks = $setup->checks;
check(ESP_Protection_State::verify_and_commit($setup) === false &&
    $setup->checks === $before_checks &&
    saved_runtime_state() === 'READY',
    'busy advisory lock refuses competing migration without changing state');
$wpdb->lock_busy = false;
$wpdb->lock_owner = 99;
check(ESP_Protection_State::verify_and_commit($setup) === false,
    'other connection owns advisory lock, even if it is long-running');
$wpdb->lock_owner = null;

$wpdb->queries = 0;
$setup->checks = 0;
ESP_Media_Protection::$checks = 0;
for ($i = 0; $i < 10; $i++) {
    check(ESP_Protection_State::boot($setup) === true, 'ready boot succeeds without heavyweight validation');
}
check($wpdb->queries === 0 && $setup->checks === 0 && ESP_Media_Protection::$checks === 0,
    'ten normal boot calls perform zero extra DB queries, version checks and Rewrite checks');

$wpdb->online = false;
check(ESP_Protection_State::boot($setup) === true,
    'ordinary boot does not query DB solely for liveness (authorization reads still fail closed)');
$wpdb->online = true;

$GLOBALS['options'][ESP_Config::OPTION_KEY] = ['path' => [['path' => '']]];
check(ESP_Protection_State::boot($setup) === false, 'invalid path rejected before authorization');
$GLOBALS['options'][ESP_Config::OPTION_KEY] = ['path' => [['path' => '/protected']]];
check(ESP_Protection_State::boot($setup) === true, 'valid protection paths remain accepted');

$GLOBALS['options']['esp_runtime_state'] = 'PENDING';
$setup->checks = 0;
$wpdb->complete_on_acquire = true;
check(ESP_Protection_State::boot($setup) === true && $setup->checks === 0,
    'auto verification skips migration when another process completed before lock acquisition');
$GLOBALS['options']['esp_runtime_state'] = 'PENDING';
check(ESP_Protection_State::boot($setup) === true, 'legacy PENDING is verified once by the first request');
$GLOBALS['options']['esp_runtime_state'] = 'VERIFY_REQUIRED';
$GLOBALS['simulate_db_reconnect'] = true;
check(ESP_Protection_State::boot($setup) === false &&
    saved_runtime_state() === 'PENDING',
    'loss of MySQL lock during version check cannot publish READY or FAILED');
unset($GLOBALS['simulate_db_reconnect']);
$wpdb->lock_connection = 17;
check(ESP_Protection_State::boot($setup) === true,
    'next request recovers after transient DB reconnection');
$GLOBALS['options']['esp_runtime_state'] = 'FAILED';
check(ESP_Protection_State::boot($setup) === false, 'failed validation refuses frontend');
$GLOBALS['options']['esp_runtime_state'] = 'UNKNOWN';
check(ESP_Protection_State::boot($setup) === false, 'unknown state refuses frontend');
$rest = $GLOBALS['filters']['rest_pre_dispatch'][0][0];
check($rest(null) instanceof WP_Error && $rest(null)->get_error_data()['status'] === 503,
    'REST access refused when protection state unknown');


// R02: 設定が判明している場合は状態FAILEDだけではサイト全体を止めない。
// 以後は一つのPHPプロセス内で複数リクエストを模擬するためフックを初期化する。
$GLOBALS['filters']['rest_pre_dispatch'] = [];
$GLOBALS['actions']['template_redirect'] = [];
$GLOBALS['actions']['admin_init'] = [];
$GLOBALS['options']['esp_runtime_state'] = 'FAILED';
$GLOBALS['options'][ESP_Config::OPTION_KEY] = ['path' => [], 'media' => ['enabled' => false]];
$checks_before = $setup->checks;
check(ESP_Protection_State::boot($setup) === false &&
    $setup->checks === $checks_before &&
    empty($GLOBALS['actions']['template_redirect']) &&
    empty($GLOBALS['filters']['rest_pre_dispatch']),
    'R02: path empty and explicitly media OFF keeps unrelated WordPress content available');

// 設定上は保護パス0件でも、media ON中のPHP配信は保護ゲートを通す。
$GLOBALS['options'][ESP_Config::OPTION_KEY] = ['path' => [], 'media' => ['enabled' => true]];
$GLOBALS['filters']['rest_pre_dispatch'] = [];
$GLOBALS['actions']['template_redirect'] = [];
check(ESP_Protection_State::boot($setup) === false &&
    !empty($GLOBALS['actions']['template_redirect']),
    'R02: media ON and non-READY installs an explicit PHP media deny gate');
$GLOBALS['test_manage_options'] = false;
$media_rest = $GLOBALS['filters']['rest_pre_dispatch'][0][0];
$request_media = new class {
    public function get_route() { return '/wp/v2/media/5'; }
};
$request_unrelated = new class {
    public function get_route() { return '/wp/v2/types'; }
};
check($media_rest(null, null, $request_media) instanceof WP_Error &&
    $media_rest(null, null, $request_media)->get_error_data()['status'] === 503 &&
    $media_rest('early_response', null, $request_media) instanceof WP_Error &&
    $media_rest(null, null, $request_unrelated) === null,
    'R02: media-only failure rejects REST media but not unrelated REST');
$media_redirect = $GLOBALS['actions']['template_redirect'][0][0];
$GLOBALS['wp'] = (object) ['request' => 'about', 'query_vars' => []];
$GLOBALS['test_singular'] = true;
$media_redirect(); // unrelated single page is not denied
$_GET['esp-media'] = 'private/photo.jpg';
$media_denied = false;
try { $media_redirect(); } catch (RuntimeException $e) { $media_denied = $e->getMessage() === 'wp_die_503'; }
unset($_GET['esp-media']);
check($media_denied && $GLOBALS['test_http_status'] === 503,
    'R02: explicitly routed media cannot be delivered on failed startup');

// 保護パスが既知でも集約表示/正規URL/RESTを漏洩させず、無関係な単体ページは維持。
$GLOBALS['options'][ESP_Config::OPTION_KEY] = [
    'path' => [['path' => '/members', 'id' => 'path_1']],
    'media' => ['enabled' => false],
];
$GLOBALS['filters']['rest_pre_dispatch'] = [];
$GLOBALS['actions']['template_redirect'] = [];
check(ESP_Protection_State::boot($setup) === false,
    'R02: known protected paths remain non-READY without normal auth init');
$path_redirect = $GLOBALS['actions']['template_redirect'][0][0];
$GLOBALS['wp'] = (object) ['request' => 'about', 'query_vars' => []];
$GLOBALS['test_permalink'] = 'https://example.com/about/';
$path_redirect(); // unrelated singular
$GLOBALS['wp']->request = 'members/secret';
$protected_denied = false;
try { $path_redirect(); } catch (RuntimeException $e) { $protected_denied = true; }
check($protected_denied, 'R02: known protected URL is refused while normal auth is unavailable');
$GLOBALS['wp']->request = '';
$GLOBALS['test_permalink'] = 'https://example.com/members/secret/';
$canonical_denied = false;
try { $path_redirect(); } catch (RuntimeException $e) { $canonical_denied = true; }
check($canonical_denied, 'R02: query-ID alias of protected permalink cannot bypass deny');
$GLOBALS['test_permalink'] = 'https://example.com/about/';
$GLOBALS['test_front_page'] = true;
$front_denied = false;
try { $path_redirect(); } catch (RuntimeException $e) { $front_denied = true; }
check($front_denied, 'R02: static front page is treated as potentially aggregate content');
$GLOBALS['test_front_page'] = false;
$GLOBALS['test_singular'] = false;
$aggregate_denied = false;
try { $path_redirect(); } catch (RuntimeException $e) { $aggregate_denied = true; }
check($aggregate_denied, 'R02: archive/feed/search-style aggregates remain denied');
$protected_rest = $GLOBALS['filters']['rest_pre_dispatch'][0][0];
check($protected_rest(null, null, $request_unrelated) instanceof WP_Error &&
    $protected_rest('early_response', null, $request_unrelated) instanceof WP_Error,
    'R02: anonymous REST stays closed even if another filter pre-populated a response');

// 設定破損・欠損は例外として広範囲を拒否する。
$GLOBALS['options'][ESP_Config::OPTION_KEY] = false;
$GLOBALS['filters']['rest_pre_dispatch'] = [];
$GLOBALS['actions']['template_redirect'] = [];
check(ESP_Protection_State::boot($setup) === false,
    'R02: unreadable path settings must never be treated as empty paths');
$broken_rest = $GLOBALS['filters']['rest_pre_dispatch'][0][0];
check($broken_rest(null)->get_error_data()['status'] === 503,
    'R02: unknown protection scope still refuses public REST');
$broken_redirect = $GLOBALS['actions']['template_redirect'][0][0];
$broken_denied = false;
try { $broken_redirect(); } catch (RuntimeException $e) { $broken_denied = true; }
check($broken_denied, 'R02: completely unknown protection scope fails closed');
// 後続の既存更新テストは正常設定・管理者権限に戻して継続する。
$GLOBALS['test_manage_options'] = true;
$GLOBALS['test_singular'] = true;
$GLOBALS['options'][ESP_Config::OPTION_KEY] = ['path' => [], 'media' => ['enabled' => true]];


ESP_Protection_State::register_runtime_hooks($setup);
check(isset($GLOBALS['filters']['upgrader_pre_install']), 'upgrade-preflight hook registered');
$pre = $GLOBALS['filters']['upgrader_pre_install'][0][0];
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY');
check($pre(true, ['action'=>'update', 'type'=>'plugin', 'plugin'=>'some-plugin/main.php']) === true &&
    saved_runtime_state() === 'READY', 'other plugins do not trigger ESP pending');
check($pre(true, ['action'=>'update', 'type'=>'plugin',
    'plugin'=>'easy-slug-protect/easy-slug-protect.php']) === true &&
    get_option('esp_runtime_state') === runtime_value('UPDATING'),
    'ESP upgrade is marked UPDATING without losing verified version');
$setup->checks = 0;
check(ESP_Protection_State::boot($setup) === false && $setup->checks === 0,
    'in-progress update never triggers premature validation');
$post = $GLOBALS['filters']['upgrader_post_install'][0][0];
check($post(true, ['action'=>'update', 'type'=>'plugin',
    'plugin'=>'easy-slug-protect/easy-slug-protect.php'], []) === true &&
    saved_runtime_state() === 'UPDATING',
    'successful updater stage waits until request shutdown to mark verification');
foreach ($GLOBALS['actions']['shutdown'] as $entry) {
    if ($entry[1] === 200) { call_user_func($entry[0]); }
}
check(saved_runtime_state() === 'VERIFY_REQUIRED' &&
    !empty($GLOBALS['scheduled']['esp_verify_runtime_once']),
    'one-off Cron is scheduled after update lifecycle has finished');
check(ESP_Protection_State::boot($setup) === true &&
    saved_runtime_state() === 'READY' && $setup->checks === 1,
    'first anonymous request verifies and recovers unattended update');
// WordPress CoreのPlugin_Upgrader::bulk_upgrade()は個別インストールに
// type/actionを渡さない。AJAX更新もこの形式を使う。
$bulk_extra = [
    'plugin' => 'easy-slug-protect/easy-slug-protect.php',
    'temp_backup' => ['slug' => 'easy-slug-protect'],
];
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY');
check($pre(true, ['plugin' => 'other-plugin/main.php']) === true &&
    saved_runtime_state() === 'READY',
    'other plugin in bulk update never changes ESP state');
check($pre(true, ['type'=>'plugin','action'=>'install',
    'plugin'=>'easy-slug-protect/easy-slug-protect.php']) === true &&
    saved_runtime_state() === 'READY',
    'ESP installation is not mistaken for a plugin upgrade');
check($pre(true, $bulk_extra) === true &&
    saved_runtime_state() === 'UPDATING',
    'bulk/AJAX update marks ESP UPDATING without type or action');
check($post(true, $bulk_extra, []) === true &&
    saved_runtime_state() === 'UPDATING',
    'bulk/AJAX post-install waits for shutdown before completion');
foreach ($GLOBALS['actions']['shutdown'] as $entry) {
    if ($entry[1] === 200) { call_user_func($entry[0]); }
}
check(saved_runtime_state() === 'VERIFY_REQUIRED',
    'bulk/AJAX update schedules one-time validation at shutdown');
check(ESP_Protection_State::boot($setup) === true,
    'anonymous traffic after successful bulk upgrade verifies once');

$complete = $GLOBALS['actions']['upgrader_process_complete'][0][0];
// 失敗した一括更新では、shutdownでバックアップが復元されるまで
// 新コード検証を開始してはいけない。
$GLOBALS['options']['esp_runtime_state'] = 'UPDATING';
$complete(null, ['action'=>'update', 'type'=>'plugin',
    'plugins'=>['easy-slug-protect/easy-slug-protect.php']]);
check(saved_runtime_state() === 'UPDATING',
    'bulk process completion waits for shutdown rollback before verification');
foreach ($GLOBALS['actions']['shutdown'] as $entry) {
    if ($entry[1] === 200) { call_user_func($entry[0]); }
}
check(saved_runtime_state() === 'VERIFY_REQUIRED',
    'bulk completion at shutdown enables one-shot verification');
check(ESP_Protection_State::boot($setup) === true,
    'bulk failure recovery verifies before reopening site');

$GLOBALS['options']['esp_runtime_state'] = 'UPDATING';
$failed_result = $GLOBALS['filters']['upgrader_install_package_result'][0][0];
$failure = new WP_Error('filesystem_failure', 'Install failed');
check($failed_result($failure, $bulk_extra) === $failure &&
    saved_runtime_state() === 'UPDATING',
    'failed individual install stays UPDATING until rollback finishes');
foreach ($GLOBALS['actions']['shutdown'] as $entry) {
    if ($entry[1] === 200) { call_user_func($entry[0]); }
}
check(saved_runtime_state() === 'VERIFY_REQUIRED',
    'failed upgrade schedules verification after WordPress rollback');
check(ESP_Protection_State::boot($setup) === true,
    'rolled-back update resumes only after strict validation');

$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY');
$GLOBALS['reject_update'] = true;
$err = $pre(true, $bulk_extra);
check($err instanceof WP_Error && $err->get_error_code() === 'esp_upgrade_pending_unavailable',
    'bulk/AJAX update blocked if UPDATING cannot be durably recorded');
unset($GLOBALS['reject_update']);

$GLOBALS['options']['esp_runtime_state'] = runtime_value('VERIFY_REQUIRED', '0.7.37');
$setup->ok = false;
check(ESP_Protection_State::verify_and_commit($setup) === false &&
    saved_runtime_state() === 'FAILED' &&
    get_option('esp_runtime_state') === runtime_value('FAILED', '0.7.37'),
    'failed update validation preserves last verified version');
$setup->ok = true;
check(ESP_Protection_State::verify_and_commit($setup) === true &&
    get_option('esp_runtime_state') === runtime_value('READY'),
    'explicit validation recovers and stamps the current version');

ESP_Media_Protection::$audit_ok = false;
$wpdb->queries = 0;
check(ESP_Protection_State::audit() === false, 'Cron audit reports Rewrite mismatch');
check(in_array('rewrite_mismatch', get_option('esp_integrity_last_audit')['errors'], true),
    'Cron persists an actionable non-secret error code');
check(saved_runtime_state() === 'READY' && ESP_Media_Protection::$checks > 0,
    'read-only Cron does not auto-repair Rewrite or overwrite READY');
ESP_Media_Protection::$audit_ok = true;
check(ESP_Protection_State::audit() === true, 'successful Cron audit persists no errors');

ESP_Protection_State::register_recovery_admin();
$hook = $GLOBALS['actions']['admin_menu'][0][0];
$hook();
check(($GLOBALS['admin_menu']['capability'] ?? null) === 'manage_options',
    'recovery panel requires administrator rights');
ob_start();
call_user_func($GLOBALS['admin_menu']['callback']);
$html = ob_get_clean();
check(strpos($html, 'uploads/.htaccess') !== false &&
    strpos($html, 'esp_verify_runtime') !== false,
    'recovery panel offers explicit CSRF-protected validation');
// WP-CLIの自動CronではWebサーバーのRewriteを検証できないことがある。
define('WP_CLI', true);
unset($_SERVER['SERVER_SOFTWARE']);
$GLOBALS['options']['esp_runtime_state'] = 'VERIFY_REQUIRED';
$before_cli_checks = $setup->checks;
check(ESP_Protection_State::boot($setup) === false &&
    saved_runtime_state() === 'VERIFY_REQUIRED' &&
    $setup->checks === $before_cli_checks,
    'WP-CLI without server identity defers verification without persisting FAILED');
$cli_cron = $GLOBALS['actions']['esp_verify_runtime_once'][0][0];
$cli_cron();
check(saved_runtime_state() === 'VERIFY_REQUIRED' &&
    $setup->checks === $before_cli_checks,
    'CLI-triggered verification Cron does not strand a valid site in FAILED');
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
check(ESP_Protection_State::boot($setup) === true &&
    saved_runtime_state() === 'READY' &&
    $setup->checks === $before_cli_checks + 1,
    'next HTTP server context can complete deferred strict verification');
// CLI有効化時は保護メディアが無いことを確定できる時だけHTTP検証を延期する。
unset($_SERVER['SERVER_SOFTWARE']);
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY');
$GLOBALS['options'][ESP_Config::OPTION_KEY] = ['path' => [], 'media' => ['enabled' => true]];
ESP_Media_Protection::$protected_records = false;
check(ESP_Protection_State::defer_cli_activation_verification() === true &&
    saved_runtime_state() === 'VERIFY_REQUIRED',
    'R03: CLI activation with zero protected media defers to verified HTTP startup');
ESP_Media_Protection::$protected_records = true;
check(ESP_Protection_State::defer_cli_activation_verification() === false &&
    saved_runtime_state() === 'VERIFY_REQUIRED',
    'R03: CLI activation must not leave already-protected media without verified Rewrite');
ESP_Media_Protection::$protected_records = null;
check(ESP_Protection_State::defer_cli_activation_verification() === false,
    'R03: unknown protected-media count cannot be silently deferred');
$GLOBALS['options'][ESP_Config::OPTION_KEY] = ['path' => [], 'media' => ['enabled' => false]];
check(ESP_Protection_State::defer_cli_activation_verification() === true,
    'R03: explicit media OFF can defer CLI activation despite old protection metadata');
$GLOBALS['options'][ESP_Config::OPTION_KEY] = ['path' => [], 'media' => ['enabled' => true]];
ESP_Media_Protection::$protected_records = false;
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
check(ESP_Protection_State::boot($setup) === true,
    'R03: next HTTP request completes verification after safe CLI deferral');


// バージョン不一致でもDBロック競合なら旧READYを受理せず、次回へ再試行。
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY', '0.7.37');
$wpdb->lock_busy = true;
$setup->checks = 0;
check(ESP_Protection_State::boot($setup) === false && $setup->checks === 0 &&
    get_option('esp_runtime_state') === runtime_value('READY', '0.7.37'),
    'version mismatch cannot bypass lock contention or falsely stamp READY');
$wpdb->lock_busy = false;
check(ESP_Protection_State::boot($setup) === true &&
    get_option('esp_runtime_state') === runtime_value('READY'),
    'retry after lock contention completes verification');

// H1: 起動中に異常終了したUPDATINGから管理者が安全に復旧できる。
$GLOBALS['options']['esp_runtime_state'] = runtime_value('UPDATING');
$recovery_checks_before = $setup->checks;
check(ESP_Protection_State::recover_stuck_upgrade($setup) === false &&
    saved_runtime_state() === 'UPDATING' &&
    $setup->checks === $recovery_checks_before,
    'H1: recovery never runs without explicit administrator confirmation');
check(ESP_Protection_State::recover_stuck_upgrade($setup, true) === false &&
    saved_runtime_state() === 'UPDATING',
    'H1: ongoing update younger than 30 minutes cannot be recovered');
$GLOBALS['options']['esp_runtime_state']['since'] = time() - 1900;
ob_start();
ESP_Protection_State::render_recovery_admin();
$recovery_html = ob_get_clean();
check(strpos($recovery_html, 'esp_recover_stuck_upgrade') !== false &&
    strpos($recovery_html, 'confirm_upgrade_stopped') !== false,
    'H1: recovery screen requires explicit administrator confirmation');
check(ESP_Protection_State::recover_stuck_upgrade($setup, true) === true &&
    saved_runtime_state() === 'READY',
    'H1: manager may recover stale update only through locked full verification');
// レガシーの更新フラグはsinceを持たない。確認なしで自動解除しない。
$GLOBALS['options']['esp_runtime_state'] = 'UPDATING';
check(ESP_Protection_State::recover_stuck_upgrade($setup, true) === true &&
    saved_runtime_state() === 'READY',
    'H1: confirmed administrator can recover legacy UPDATING without timestamp');
$GLOBALS['options']['esp_runtime_state'] = runtime_value('UPDATING');
$GLOBALS['options']['esp_runtime_state']['since'] = time() - 1900;
$GLOBALS['test_manage_options'] = false;
check(ESP_Protection_State::recover_stuck_upgrade($setup, true) === false &&
    saved_runtime_state() === 'UPDATING',
    'H1: unprivileged user cannot reset an in-progress upgrade');
$GLOBALS['test_manage_options'] = true;
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY');

// 壊れた配列を正常READYとして解釈しない。
$GLOBALS['options']['esp_runtime_state'] = ['not_state' => 'READY'];
$setup->checks = 0;
check(ESP_Protection_State::boot($setup) === false && $setup->checks === 0,
    'malformed runtime option remains UNKNOWN without automatic promotion');
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY');

// DB状態の単独遷移も検証と同じMySQLロックを取得する。
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY');
$wpdb->lock_busy = true;
$lock_state_before = $GLOBALS['options']['esp_runtime_state'];
$updating_denied = $pre(true, $bulk_extra);
check($updating_denied instanceof WP_Error &&
    $updating_denied->get_error_code() === 'esp_upgrade_pending_unavailable' &&
    get_option('esp_runtime_state') === $lock_state_before,
    'upgrade preflight cannot publish UPDATING while the verifier lock is held');
$wpdb->lock_busy = false;
check($pre(true, $bulk_extra) === true &&
    saved_runtime_state() === 'UPDATING',
    'upgrade preflight commits UPDATING with exclusive DB lock');
$wpdb->lock_busy = true;
ESP_Protection_State::mark_verification_required();
check(saved_runtime_state() === 'UPDATING',
    'shutdown cannot change UPDATING while another verification holds lock');
$wpdb->lock_busy = false;
ESP_Protection_State::mark_verification_required();
check(saved_runtime_state() === 'VERIFY_REQUIRED',
    'shutdown marks VERIFY_REQUIRED only after acquiring shared DB lock');
// 古いshutdownの遅延コールバックが新コードのREADYを後戻りさせない。
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY');
ESP_Protection_State::mark_verification_required();
check(saved_runtime_state() === 'READY',
    'late old shutdown cannot rewind a new READY to VERIFY_REQUIRED');

// 明示検証も更新中の状態を越えてREADYに進めない。
$GLOBALS['options']['esp_runtime_state'] = runtime_value('UPDATING');
$checks_before = $setup->checks;
check(ESP_Protection_State::verify_and_commit($setup) === false &&
    saved_runtime_state() === 'UPDATING' &&
    $setup->checks === $checks_before,
    'explicit verifier does not overtake an in-progress plugin upgrade');
$duplicate_upgrade = $pre(true, $bulk_extra);
check($duplicate_upgrade instanceof WP_Error &&
    $duplicate_upgrade->get_error_code() === 'esp_upgrade_pending_unavailable' &&
    saved_runtime_state() === 'UPDATING',
    'second upgrade cannot claim another already-UPDATING generation');
ESP_Protection_State::mark_verification_required();
check(saved_runtime_state() === 'VERIFY_REQUIRED',
    'completed upgrade proceeds to one-time verification state');

// ロック待ち中に別ワーカーが新版READYへ進めた場合は取得後に再判定。
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY');
$wpdb->state_on_acquire = runtime_value('READY', '0.7.39');
$stale_start = $pre(true, $bulk_extra);
check($stale_start instanceof WP_Error &&
    $stale_start->get_error_code() === 'esp_upgrade_pending_unavailable' &&
    get_option('esp_runtime_state') === runtime_value('READY', '0.7.39'),
    'locked writer re-reads state and cannot replace a newer READY');
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY');

// 新版READY後に残っている旧PHPワーカーは共有状態を書き換えない。
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY', '0.7.39');
$setup->checks = 0;
check(ESP_Protection_State::boot($setup) === false &&
    $setup->checks === 0 &&
    get_option('esp_runtime_state') === runtime_value('READY', '0.7.39'),
    'stale worker refuses this request without overwriting newer READY');

$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY');
$GLOBALS['options'][ESP_Config::VERSION_OPTION_KEY] = '0.7.39';
$setup->checks = 0;
check(ESP_Protection_State::boot($setup) === false &&
    $setup->checks === 0 &&
    get_option('esp_runtime_state') === runtime_value('READY'),
    'stale worker does not trust its own READY when installed version is newer');
check(ESP_Protection_State::verify_and_commit($setup) === false &&
    $setup->checks === 0 &&
    get_option('esp_runtime_state') === runtime_value('READY'),
    'newer installed-version marker cannot be changed to FAILED by stale verifier');
$GLOBALS['options'][ESP_Config::VERSION_OPTION_KEY] = ESP_VERSION;

// M2: 有効化前の無副作用な判定で旧コードのDDLと将来スキーマを拒否。
check(ESP_Protection_State::can_run_activation_migration() === true,
    'M2: current code can activate against a compatible database');
$GLOBALS['options'][ESP_Config::VERSION_OPTION_KEY] = '0.7.39';
check(ESP_Protection_State::can_run_activation_migration() === false,
    'M2: prior installed version blocks stale activation before schema migration');
$GLOBALS['options'][ESP_Config::VERSION_OPTION_KEY] = ESP_VERSION;
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY', '0.7.39');
check(ESP_Protection_State::can_run_activation_migration() === false,
    'M2: newer verified READY also blocks stale activation');
$GLOBALS['options']['esp_runtime_state'] = runtime_value('READY');
$GLOBALS['options']['esp_db_version'] = (int) ESP_Config::OPTION_DEFAULTS['db_version'] + 1;
check(ESP_Protection_State::can_run_activation_migration() === false,
    'M2: future schema marker cannot be mutated by old activation DDL');
$GLOBALS['options']['esp_db_version'] = ESP_Config::OPTION_DEFAULTS['db_version'];

echo "All protection runtime tests passed.\n";
