<?php
/**
 * PR #81: REAL WordPress + MySQL + SQLite end-to-end integration.
 *
 * This file may delete its own disposable media and mutate plugin options.
 * Never run on a production installation, even for diagnostics.
 */
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' ||
    getenv('CI') !== 'true' || !getenv('ESP_WP_ROOT')) {
    fwrite(STDERR, "Refusing non-disposable Fast Gate WordPress test\n");
    exit(1);
}
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.62 (disposable CI)';
$_SERVER['REQUEST_URI'] = '/';
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (DB_NAME !== 'esp_fast_gate_wp' ||
    !is_plugin_active('easy-slug-protect/easy-slug-protect.php') ||
    !class_exists('ESP_Fast_Gate_WP') || !class_exists('SQLite3')) {
    throw new RuntimeException('Disposable WordPress/ESP/SQLite environment not active');
}
global $wpdb;
function fg_wp_assert($condition, $reason) {
    if (!$condition) { throw new RuntimeException('Fast Gate real WP: ' . $reason .
        '; wpdb=' . ($GLOBALS['wpdb']->last_error ?? '')); }
}
function fg_wp_stage($name) { echo "FAST_GATE_REAL_WP " . $name . "\n"; }
fg_wp_assert($wpdb->get_var('SELECT DATABASE()') === 'esp_fast_gate_wp',
    'incorrect MySQL database');

$settings = get_option(ESP_Config::OPTION_KEY, ESP_Config::OPTION_DEFAULTS);
fg_wp_assert(is_array($settings), 'missing settings');
$path_id = 'fg_path';
$protected = [
    'id' => $path_id,
    'path' => '/fg-ci-protected/',
    'password' => wp_hash_password('ci-only-secret'),
    'password_version' => 1,
    'login_page' => 0,
];
$settings['path'] = [$path_id => $protected];
fg_wp_assert(update_option(ESP_Config::OPTION_KEY, $settings), 'settings update');
// The installed CLI plugin can be VERIFY_REQUIRED until a web-server
// context validates Rewrite. Use the same verification step as the existing
// real Apache/WordPress CI fixture after configuring protected paths.
$verified = ESP_Updater::during_activation(static function () {
    return ESP_Protection_State::verify_and_commit(new ESP_Setup());
});
fg_wp_assert($verified === true,
    'real plugin protection verification after setting: ' .
    wp_json_encode(get_option(ESP_Protection_State::RUNTIME_STATE_OPTION)));

// R03: this is a real WordPress update_option() with real registered filters.
update_option(ESP_Fast_Gate_WP::ACTIVE_OPTION, '0', false);
update_option(ESP_Fast_Gate_WP::ACTIVE_OPTION, '1', false);
fg_wp_assert(!ESP_Fast_Gate_WP::active(),
    'Apache must not be able to enable an unfinished Fast Gate');
fg_wp_stage('R03_OPTION_REJECTED');

$uploads = wp_upload_dir();
fg_wp_assert(empty($uploads['error']) && is_dir($uploads['basedir']),
    'upload directory not writable');
$public_name = 'fg-real-public.jpg';
$private_name = 'fg-real-private.jpg';
$public_file = $uploads['basedir'] . '/' . $public_name;
$private_file = $uploads['basedir'] . '/' . $private_name;
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAsICAoIBwsKCQoNDAsNERwSEQ8PESIZGhQcKSQrKigkJyctMkA3LTA9MCcnOEw5PUNFSElIKzZPVU5GVEBHSEX/wAARCAACAAIDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAABAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCegKgT/9k=', true);
fg_wp_assert(is_string($jpeg) && strlen($jpeg)>100, 'JPEG fixture');
foreach ([$public_file,$private_file] as $file) {
    fg_wp_assert(file_put_contents($file, $jpeg) === strlen($jpeg),
        'cannot write fixture image');
}
// WordPress metadata may retain image derivatives that were pruned or not
// copied during migration. The index must keep ownership evidence for those
// paths, but the initial SQLite catalog must never make them public.
$missing_derivatives = [
    'public' => 'fg-real-missing-public-derivative.jpg',
    'private' => 'fg-real-missing-private-derivative.jpg',
];
foreach ($missing_derivatives as $name) {
    fg_wp_assert(!file_exists($uploads['basedir'] . '/' . $name),
        'missing-derivative test fixture must be physically absent');
}
$ids = [];
foreach (['public'=>$public_file,'private'=>$private_file] as $kind=>$file) {
    $id = wp_insert_attachment([
        'post_title' => 'FG real CI ' . $kind,
        'post_status' => 'inherit',
        'post_mime_type' => 'image/jpeg',
    ], $file, 0, true);
    fg_wp_assert(!is_wp_error($id) && (int)$id>0, 'wp_insert_attachment ' . $kind);
    $ids[$kind]=(int)$id;
    // wp_insert_attachment($file) may already have stored this metadata;
    // a no-op update returns false, even though the canonical file is valid.
    update_attached_file($id, $file);
    fg_wp_assert(realpath(get_attached_file($id)) === realpath($file),
        'attached_file ' . $kind);
    $meta = [
        'file' => basename($file),
        'sizes' => ['missing'=>[
            'file' => $missing_derivatives[$kind],
            'width' => 1, 'height' => 1, 'mime-type' => 'image/jpeg',
        ]],
        'width' => 2, 'height' => 2,
    ];
    fg_wp_assert((bool)wp_update_attachment_metadata($id,$meta),
        'wp_update_attachment_metadata ' . $kind);
}
fg_wp_assert((bool)update_post_meta($ids['private'], ESP_Media_Index::PROTECTION_META, $path_id),
    'protection metadata');

fg_wp_assert(ESP_Media_Index::install_schema(), 'real MySQL index install');
for ($i = 0; $i < 12 && !ESP_Media_Index::ready(); ++$i) {
    ESP_Media_Index::run_batch();
}
fg_wp_assert(ESP_Media_Index::ready(), 'real MySQL media index coverage never READY');
update_option(ESP_Media_Index::ENABLE_OPTION, '1', false);
foreach (['public','private'] as $kind) {
    $resolved = ESP_Media_Index::resolve($ids[$kind] === $ids['public']
        ? $public_file : $private_file);
    fg_wp_assert(($resolved['status'] ?? '') === 'resolved' &&
        ($resolved['protected_path_id'] ?? null) === ($kind==='public'?'':$path_id),
        'real MySQL initial owner/policy ' . $kind);
}
fg_wp_stage('REAL_MYSQL_MEDIA_INDEX_READY');

fg_wp_assert(ESP_Fast_Gate_WP::provision(), 'keys.php and SQLite provisioning');
fg_wp_assert(ESP_Fast_Gate_WP::build_catalog(), 'full catalog from live WordPress metadata');
$ledger = ESP_Fast_Gate_WP::ledger(true);
fg_wp_assert($ledger && $ledger->schema_ok() && $ledger->ready() &&
    !$ledger->serving(), 'catalog must be READY but not serving');
fg_wp_assert($ledger->get_media($public_name)['policy'] === 'public',
    'initial public SQLite policy');
fg_wp_assert($ledger->get_media($private_name)['policy'] === 'protected',
    'initial protected SQLite policy');
foreach ($missing_derivatives as $kind => $name) {
    fg_wp_assert(ESP_Media_Index::resolve($uploads['basedir'].'/'.$name)['status'] === 'error',
        'resolver must never authorize a physically missing derivative');
    $entry = $ledger->get_media($name);
    fg_wp_assert($entry !== null && $entry['policy'] === 'deny' &&
        $entry['path_id'] === '',
        'initial catalog must explicitly deny missing derivative: ' . $kind);
}
fg_wp_stage('MISSING_WORDPRESS_DERIVATIVES_DENIED_DURING_CATALOG_BUILD');
fg_wp_stage('REAL_SQLITE_CATALOG_BUILT_INACTIVE');

// The production active-option remains prohibited. ONLY the current PHP
// process overrides get_option for exercising the installed WordPress hooks;
// no actual active option is changed and no Fast Gate Rewrite is installed.
$active_filter = static function ($value) { return '1'; };
add_filter('pre_option_' . ESP_Fast_Gate_WP::ACTIVE_OPTION, $active_filter);
fg_wp_assert($ledger->rotate_ticket_signing_key(), 'test-only activation signing epoch');
fg_wp_assert($ledger->set_serving(true), 'test-only SQLite serving flag');
require_once ESP_PATH . 'gate/class-esp-fast-gate-server.php';
$keys = require ESP_Fast_Gate_WP::private_dir() . '/keys.php';
$prefix = $keys['prefix'];
$site = $keys['site'];
$before_queries = $wpdb->num_queries;
$allowed = ESP_Fast_Gate_Server::authorize($prefix.$public_name,[], $site,
    $keys['root'],$prefix,$keys['sig'],$keys['id'],$ledger);
$denied = ESP_Fast_Gate_Server::authorize($prefix.$private_name,[], $site,
    $keys['root'],$prefix,$keys['sig'],$keys['id'],$ledger);
fg_wp_assert($allowed['status']===200 && $denied['status']===403,
    'standalone public/protected policies');
fg_wp_assert($wpdb->num_queries===$before_queries,
    'standalone media authorization invoked MySQL');
// A missing derivative could reappear through FTP or a delayed thumbnail
// worker, bypassing WordPress metadata hooks. The deny entry must NOT become
// an authorization just because the file now exists.
$reborn = $uploads['basedir'] . '/' . $missing_derivatives['public'];
fg_wp_assert(file_put_contents($reborn, $jpeg) === strlen($jpeg),
    'materialize missing derivative outside WordPress metadata hooks');
$reborn_status = ESP_Fast_Gate_Server::authorize(
    $prefix.$missing_derivatives['public'], [], $site,
    $keys['root'], $prefix, $keys['sig'], $keys['id'], $ledger);
fg_wp_assert($reborn_status['status'] === 403,
    'newly materialized previously missing derivative must remain denied');
fg_wp_assert(unlink($reborn), 'remove disposable reborn-derivative fixture');
fg_wp_stage('MISSING_DERIVATIVE_REAPPEARANCE_REMAINS_DENIED');
fg_wp_stage('STANDALONE_GATE_ZERO_MYSQL');

fg_wp_assert((bool)update_post_meta($ids['public'], ESP_Media_Index::PROTECTION_META, $path_id),
    'real WordPress public -> protected change');
fg_wp_assert($ledger->get_media($public_name)===null,
    'SQLite must deny old public immediately after WordPress update');
ESP_Media_Index::finish_noop_writes();
fg_wp_assert(ESP_Media_Index::reindex_attachment($ids['public']),
    'real MySQL index publish public->protected');
fg_wp_assert($ledger->get_media($public_name)['policy']==='protected',
    'SQLite did not receive live WordPress protection update');
fg_wp_stage('WP_META_PUBLIC_TO_PROTECTED_FENCED_AND_PUBLISHED');

// Restore public and then claim the same real media path as a *new* derivative
// of the protected owner. The old public owner must be denied immediately.
fg_wp_assert((bool)update_post_meta($ids['public'], ESP_Media_Index::PROTECTION_META, ''),
    'real WordPress protected -> public change');
ESP_Media_Index::finish_noop_writes();
fg_wp_assert(ESP_Media_Index::reindex_attachment($ids['public']),
    're-publish public owner');
fg_wp_assert($ledger->get_media($public_name)['policy']==='public',
    'public owner restoration');
$collision = [
    'file'=>$private_name, 'width'=>2, 'height'=>2,
    'sizes'=>['collision'=>[
        'file'=>$public_name,'width'=>2,'height'=>2,'mime-type'=>'image/jpeg',
    ]],
];
fg_wp_assert((bool)wp_update_attachment_metadata($ids['private'], $collision),
    'WordPress metadata changed to claim shared file');
fg_wp_assert($ledger->get_media($public_name)===null,
    'new protected claimant must fence old public media');
ESP_Media_Index::finish_noop_writes();
fg_wp_assert(ESP_Media_Index::reindex_attachment($ids['private']),
    'rebuild protected shared owner');
$shared = $ledger->get_media($public_name);
fg_wp_assert($shared && $shared['policy']==='deny',
    'ambiguous protected/public path cannot be served');
fg_wp_stage('REAL_WORDPRESS_CROSS_OWNER_COLLISION_DENIED');

// R04: use ACTUAL $wpdb, remember table, ESP_Cookie and ESP_Auth.
$table = $wpdb->prefix . ESP_Config::DB_TABLES['remember'];
$actual_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
fg_wp_assert($actual_table===$table, 'real Remember Me schema missing');
$remember_user='fg-ci-remember-1';
$remember_token=str_repeat('q',64);
$remember_hash=hash_hmac('sha256',$remember_token,AUTH_SALT);
fg_wp_assert($wpdb->insert($table, [
    'path'=>$protected['path'],
    'path_id'=>$path_id,
    'password_version'=>1,
    'token'=>$remember_hash,
    'user_id'=>$remember_user,
    'created'=>current_time('mysql'),
    'expires'=>date('Y-m-d H:i:s',time()+DAY_IN_SECONDS),
],['%s','%s','%d','%s','%s','%s','%s'])===1,
    'insert real Remember Me credential');
$_COOKIE['esp_remember_id_'.$path_id]=$remember_user;
$_COOKIE['esp_remember_token_'.$path_id]=$remember_token;
$auth=new ESP_Auth();
fg_wp_assert($auth->is_logged_in($protected)===true,
    'real MySQL Remember Me valid session');
$cookie=ESP_Cookie::get_instance();
$pending_property=(new ReflectionClass('ESP_Cookie'))->getProperty('pending_cookies');
$pending=$pending_property->getValue($cookie);
$ticket=$pending['esp_gate_'.$path_id]['value']??null;
fg_wp_assert(is_string($ticket) && strlen($ticket)>40,'valid Remember issued gate ticket');
$live_signing_key=$ledger->ticket_signing_key_strict($keys['sig']);
fg_wp_assert(is_string($live_signing_key), 'fixture has live activation key');
fg_wp_assert(ESP_Fast_Gate_Token::verify($ticket,$site,$path_id,$_COOKIE,1,
    $live_signing_key,$keys['id'])!==null,'real WordPress-issued gate ticket verified');
fg_wp_stage('REAL_REMEMBER_AUTH_ISSUED_VERIFIED_GATE_TICKET');

// R04 concurrent deletion between real WordPress SELECT and UPDATE.
// A separate MySQL connection deletes the row from WordPress's query filter
// just before WordPress executes its conditional UPDATE.
$wpdb->query($wpdb->prepare("DELETE FROM $table WHERE user_id=%s",$remember_user));
$deleted_user='fg-ci-remember-2';
$deleted_token=str_repeat('r',64);
fg_wp_assert($wpdb->insert($table,[
    'path'=>$protected['path'],'path_id'=>$path_id,
    'password_version'=>1,
    'token'=>hash_hmac('sha256',$deleted_token,AUTH_SALT),
    'user_id'=>$deleted_user,
    'created'=>current_time('mysql'),
    'expires'=>date('Y-m-d H:i:s',time()+DAY_IN_SECONDS),
],['%s','%s','%d','%s','%s','%s','%s'])===1,'insert concurrent remember credential');
$_COOKIE['esp_remember_id_'.$path_id]=$deleted_user;
$_COOKIE['esp_remember_token_'.$path_id]=$deleted_token;
$other = new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);
fg_wp_assert(!$other->connect_errno,'secondary MySQL connection failed');
$injected=false;
$injection = static function ($sql) use (&$injected,$other,$table,$deleted_user) {
    if (!$injected && strpos($sql,"UPDATE $table SET expires=")!==false) {
        $injected=true;
        $user=$other->real_escape_string($deleted_user);
        if (!$other->query("DELETE FROM $table WHERE user_id='$user'")) {
            throw new RuntimeException('Concurrent MySQL delete failed');
        }
    }
    return $sql;
};
$old_ticket=$pending['esp_gate_'.$path_id]['value'];
add_filter('query',$injection,5,1);
$after_logout_auth=$auth->is_logged_in($protected);
remove_filter('query',$injection,5);
$other->close();
$after_pending=$pending_property->getValue($cookie);
fg_wp_assert($injected,'real MySQL race hook never ran');
fg_wp_assert($after_logout_auth===false,'deleted Remember credential reauthorized');
fg_wp_assert(($after_pending['esp_gate_'.$path_id]['value']??null)===$old_ticket,
    'deleted Remember credential issued a NEW gate ticket');
fg_wp_stage('REAL_MYSQL_REMEMBER_CONCURRENT_DELETE_DENIED');

// The override and serving flag are temporary test-only instrumentation.
remove_filter('pre_option_'.ESP_Fast_Gate_WP::ACTIVE_OPTION,$active_filter);
fg_wp_assert(!ESP_Fast_Gate_WP::active(),'production active option must stay OFF');
fg_wp_assert($ledger->set_serving(false) && !$ledger->serving(),
    'real ledger remains stopped after test');
echo "PASS: Fast Gate v2 real WordPress/MySQL/SQLite integration\n";
