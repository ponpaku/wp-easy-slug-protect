<?php
/**
 * Real released v0.7.41 -> PR #81 lifecycle test driver.
 * Each phase is a fresh real WordPress/MySQL/SQLite PHP process.
 * This intentionally operates on a disposable GitHub Actions DB only.
 */
$phase = getenv('ESP_FG_PHASE');
$allowed = [
    'seed-release','after-upgrade','index-status','catalog-status',
    'manual-build','interrupt-build','cron-seed','cron-check','sqlite-crash',
    'sqlite-check','http-prepare','stop-serving',
];
if (getenv('CI') !== 'true' || getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' ||
    getenv('ESP_WP_ROOT') === false ||
    !in_array($phase, $allowed, true)) { exit(1); }
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.62';
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
if (DB_NAME !== 'esp_fg_lifecycle' ||
    !is_plugin_active('easy-slug-protect/easy-slug-protect.php')) {
    throw new RuntimeException('Not the disposable activated WordPress');
}
function fg_life_check($ok, $name) {
    if (!$ok) {
        throw new RuntimeException('FG LIFECYCLE: ' . $name .
            ', database=' . ($GLOBALS['wpdb']->last_error ?? ''));
    }
}
function fg_life_emit($stage, $details = []) {
    echo 'FG_LIFECYCLE ' . wp_json_encode(
        ['stage'=>$stage, 'time'=>gmdate('c')] + $details,
        JSON_UNESCAPED_SLASHES) . "\n";
}
function fg_life_db_state() {
    global $wpdb;
    $tables = [];
    foreach (ESP_Config::DB_TABLES as $name) {
        $table = $wpdb->prefix . $name;
        $tables[$name] = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
        fg_life_check($wpdb->last_error === '', 'MySQL table ' . $table);
    }
    return $tables;
}
function fg_life_resolve_ids() {
    global $wpdb;
    $ids = [];
    foreach (['public','protected'] as $kind) {
        $title='FG lifecycle ' . $kind;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'
             AND post_title=%s ORDER BY ID DESC LIMIT 1", $title));
        fg_life_check((int)$id>0, 'missing ' . $kind . ' attachment');
        $ids[$kind]=(int)$id;
    }
    return $ids;
}
function fg_life_gate() {
    fg_life_check(class_exists('ESP_Fast_Gate_WP') &&
        class_exists('SQLite3'), 'Fast Gate classes or SQLite3 missing');
    $db = ESP_Fast_Gate_WP::ledger(true);
    fg_life_check($db !== null && $db->schema_ok(),
        'SQLite gate ledger schema not available');
    return $db;
}
function fg_life_settings() {
    return ['id'=>'fg_path','path'=>'/fg-test-secret/',
        'password'=>wp_hash_password('disposable-ci-password'),
        'password_version'=>1,'login_page'=>0];
}

if ($phase === 'seed-release') {
    fg_life_check(ESP_VERSION === '0.7.41' &&
        !class_exists('ESP_Fast_Gate_WP', false), 'not genuine v0.7.41 release');
    $settings=get_option(ESP_Config::OPTION_KEY);
    fg_life_check(is_array($settings), 'release settings missing');
    $settings['path']=['fg_path'=>fg_life_settings()];
    fg_life_check(update_option(ESP_Config::OPTION_KEY,$settings),
        'release settings failed to save');
    fg_life_check(ESP_Updater::during_activation(static function() {
        return ESP_Protection_State::verify_and_commit(new ESP_Setup());
    }) === true, 'released plugin verified rewrite/setup');

    $uploads=wp_upload_dir();
    fg_life_check(empty($uploads['error']), 'released uploads failed');
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAsICAoIBwsKCQoNDAsNERwSEQ8PESIZGhQcKSQrKigkJyctMkA3LTA9MCcnOEw5PUNFSElIKzZPVU5GVEBHSEX/wAARCAACAAIDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAABAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCegKgT/9k=',true);
    fg_life_check(is_string($jpeg) && strlen($jpeg)>100, 'fixture JPEG bytes');
    $start=microtime(true);
    // BATCH_SIZE=200; 245 owners make interruption/resume observable.
    for($i=0;$i<245;++$i) {
        $kind=$i===0?'public':($i===1?'protected':'filler');
        $name=$i<2?'fg-life-'.$kind.'.jpg':'fg-life-'.sprintf('%04d',$i).'.jpg';
        $file=$uploads['basedir'].'/'.$name;
        fg_life_check(file_put_contents($file,$jpeg)===strlen($jpeg),
            'write fixture media '.$i);
        $title=$i<2?'FG lifecycle '.$kind:'FG lifecycle owner '.$i;
        $id=wp_insert_attachment(['post_title'=>$title,'post_mime_type'=>'image/jpeg',
            'post_status'=>'inherit'],$file,0,true);
        fg_life_check(!is_wp_error($id) && (int)$id>0,'seed attachment '.$i);
        update_attached_file($id,$file);
        fg_life_check(realpath(get_attached_file($id))===realpath($file),
            'attached file '.$i);
        if($i===1 || ($i>=2 && $i%3===0)) {
            fg_life_check((bool)update_post_meta($id,'_esp_media_protected_path_id',
                'fg_path'),'protection metadata '.$i);
        }
    }
    $ids=fg_life_resolve_ids();
    $table=$wpdb->prefix . ESP_Config::DB_TABLES['session'];
    $token=str_repeat('x',64);
    $hash=hash_hmac('sha256',$token,AUTH_SALT);
    fg_life_check($wpdb->insert($table,[
        'path_id'=>'fg_path','password_version'=>1,'token'=>$hash,
        'created'=>current_time('mysql'),
        'expires'=>gmdate('Y-m-d H:i:s',time()+86400)
    ])===1,'released session row');
    // Unchanged options, credential and files must survive the real upgrade.
    update_option('esp_fg_lifecycle_snapshot',[
        'owner_count'=>245,'ids'=>$ids,
        'settings_path'=>['fg_path'=>get_option(ESP_Config::OPTION_KEY)['path']['fg_path']],
        'session_hash'=>$hash,
        'db_version'=>get_option('esp_db_version'),
    ],false);
    fg_life_emit('RELEASE_SEEDED',['elapsed_ms'=>round((microtime(true)-$start)*1000,1),
        'tables'=>fg_life_db_state(),'db_version'=>get_option('esp_db_version'),
        'owners'=>245]);
    exit;
}

fg_life_check(class_exists('ESP_Fast_Gate_WP') &&
    ESP_Fast_Gate_Store::SCHEMA==='3','updated code or schema not present');
$snapshot=get_option('esp_fg_lifecycle_snapshot');
fg_life_check(is_array($snapshot) && $snapshot['owner_count']===245,
    'saved release snapshot missing');
$ids=fg_life_resolve_ids();
$uploads=wp_upload_dir();
$public='fg-life-public.jpg';
$private='fg-life-protected.jpg';
if($phase==='after-upgrade'){
    fg_life_check($ids===$snapshot['ids'],'released attachment IDs changed');
    fg_life_check(get_option(ESP_Config::OPTION_KEY)['path']===
        $snapshot['settings_path'],'released protected path mutated');
    $table=$wpdb->prefix . ESP_Config::DB_TABLES['session'];
    fg_life_check((int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE token=%s AND path_id=%s",
        $snapshot['session_hash'],'fg_path'))===1,'released session lost');
    fg_life_check((int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_type='attachment' AND post_title LIKE %s",
        'FG lifecycle%'))===245,'released attachment count changed');
    fg_life_check(ESP_Fast_Gate_WP::private_dir()===null,
        'upgrade unexpectedly created a gate key or ledger');
    fg_life_check(ESP_Updater::during_activation(static function(){
        return ESP_Protection_State::verify_and_commit(new ESP_Setup());
    })===true,'upgraded runtime verification');
    fg_life_check(!ESP_Fast_Gate_WP::active(),'unsafe fast gate activation');
    fg_life_emit('RELEASE_TO_PR_UPGRADE_PRESERVED',['tables'=>fg_life_db_state(),
        'db_version'=>get_option('esp_db_version')]);
    exit;
}
if($phase==='index-status'){
    $status=ESP_Media_Index::build_status(false);
    fg_life_emit('INDEX_STATUS',$status);
    exit;
}
if($phase==='catalog-status'){
    $db=fg_life_gate();
    fg_life_emit('GATE_STATUS',['ready'=>$db->ready(),
        'serving'=>$db->serving(),'counts'=>$db->counts()]);
    exit;
}
if($phase==='manual-build'){
    $start=microtime(true);
    fg_life_check(ESP_Fast_Gate_WP::build_catalog(),'manual catalog build failed');
    $db=fg_life_gate();
    fg_life_check($db->ready()&&!$db->serving(),'manual build incorrectly activated');
    $counts=$db->counts();
    fg_life_check($counts['media']===245 && $counts['paths']===1,
        'catalog count incorrect');
    fg_life_check($db->get_media($public)['policy']==='public' &&
        $db->get_media($private)['policy']==='protected',
        'catalog policy incorrect');
    fg_life_emit('MANUAL_BUILD_COMPLETE',['elapsed_ms'=>round((microtime(true)-$start)*1000,1),
        'counts'=>$counts]);
    exit;
}
if($phase==='interrupt-build'){
    $expected=0;
    $query_filter=static function($query) use (&$expected) {
        if(strpos($query,'SELECT f.file_path, f.attachment_id')!==false) {
            fwrite(STDERR,"FG_LIFECYCLE INTENTIONAL_BUILD_ABORT after SQLite ready=0\n");
            exit(78); // process exit: not a recoverable PHP exception.
        }
        return $query;
    };
    add_filter('query',$query_filter,10,1);
    ESP_Fast_Gate_WP::build_catalog();
    throw new RuntimeException('Interrupted build failpoint was not reached');
}
if($phase==='cron-seed'){
    $db=fg_life_gate();
    fg_life_check($db->ready()&&!$db->serving(),'Cron seed requires inactive ready');
    fg_life_check($db->fence_owner($ids['public'],[$public]),
        'durable fence before change');
    fg_life_check((bool)update_post_meta($ids['public'],
        ESP_Media_Index::PROTECTION_META,'fg_path'),
        'WP protection change for cron');
    ESP_Media_Index::finish_noop_writes();
    fg_life_check($db->get_media($public)===null,
        'fenced file was unexpectedly public');
    $ancient=time()-ESP_Fast_Gate_Token::MAX_AGE-900;
    $expired=str_repeat('a',64);
    $unconfirmed=str_repeat('b',64);
    fg_life_check($db->revoke_many([$expired,$unconfirmed],$ancient),
        'insert expired revocations');
    fg_life_check($db->confirm_deleted($expired),'confirm revoked deletion');
    fg_life_check($db->counts()['revoked']===2,
        'pre-cron revocations missing');
    fg_life_emit('CRON_PENDING_SEEDED',['owners'=>$db->pending_owners(),
        'counts'=>$db->counts()]);
    exit;
}
if($phase==='cron-check'){
    $db=fg_life_gate();
    fg_life_check($db->pending_owners()===[],
        'Cron failed to repair pending SQLite owners');
    fg_life_check($db->get_media($public)['policy']==='protected',
        'Cron failed to refresh post-change policy');
    fg_life_check($db->counts()['revoked']===1 &&
        $db->counts()['unconfirmed_revoked']===1,
        'Cron did not GC only confirmed+expired revocation');
    fg_life_emit('CRON_RECONCILE_AND_GC_OK',['counts'=>$db->counts()]);
    // The Cron recovery fixture intentionally protected the public image.
    // Restore it for the *separate* legacy-vs-fast HTTP benchmark. Use the
    // real metadata pre-hook and real per-owner SQLite delta publication;
    // only this PHP process opts into the unfinished gate code path.
    $enable_ci_gate = static function() { return '1'; };
    add_filter('pre_option_' . ESP_Fast_Gate_WP::ACTIVE_OPTION, $enable_ci_gate);
    try {
        fg_life_check((bool)update_post_meta($ids['public'],
            ESP_Media_Index::PROTECTION_META, ''),
            'restore anonymous public image after Cron test');
        ESP_Media_Index::finish_noop_writes();
        fg_life_check(ESP_Media_Index::reindex_attachment($ids['public']),
            'rebuild restored public owner after Cron test');
        fg_life_check($db->get_media($public)['policy']==='public',
            'SQLite public authorization was not restored');
    } finally {
        remove_filter('pre_option_' . ESP_Fast_Gate_WP::ACTIVE_OPTION,
            $enable_ci_gate);
    }
    fg_life_emit('PUBLIC_BASELINE_RESTORED');
    exit;
}
if($phase==='sqlite-crash'){
    $f=ESP_Fast_Gate_WP::private_dir().'/gate-ledger.sqlite';
    $db=new SQLite3($f,SQLITE3_OPEN_READWRITE);
    $db->enableExceptions(true);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('BEGIN IMMEDIATE');
    $db->exec("INSERT INTO meta(k,v) VALUES('CI_UNCOMMITTED','SHOULD_ROLLBACK')");
    fwrite(STDERR,"FG_LIFECYCLE INTENTIONAL_SQLITE_WORKER_CRASH\n");
    exit(79); // OS closes SQLite without COMMIT, exercising WAL rollback.
}
if($phase==='sqlite-check'){
    $f=ESP_Fast_Gate_WP::private_dir().'/gate-ledger.sqlite';
    $db=new SQLite3($f,SQLITE3_OPEN_READWRITE);
    fg_life_check($db->querySingle('PRAGMA integrity_check')==='ok',
        'SQLite integrity_check failed after crash');
    fg_life_check($db->querySingle(
        "SELECT v FROM meta WHERE k='CI_UNCOMMITTED'")===null,
        'uncommitted SQLite write survived process abort');
    $ledger=fg_life_gate();
    fg_life_check($ledger->ready()&&!$ledger->serving(),
        'crash incorrectly activated ledger');
    fg_life_emit('SQLITE_WAL_INTERRUPTED_TX_RECOVERED');
    exit;
}
if($phase==='http-prepare'){
    $db=fg_life_gate();
    fg_life_check($db->ready()&&!$db->serving(),
        'HTTP preparation requires inactive verified catalog');
    $session_table=$wpdb->prefix.ESP_Config::DB_TABLES['session'];
    $token=str_repeat('x',64);
    $expires=time()+86400;
    $wpdb->update($session_table,['expires'=>gmdate('Y-m-d H:i:s',$expires)],
        ['token'=>$snapshot['session_hash']]);
    $keys=require ESP_Fast_Gate_WP::private_dir().'/keys.php';
    fg_life_check(($keys['prefix'] ?? null)==='/wp-content/uploads/',
        'provisioned URL prefix does not match real Apache document root');
    $ticket=ESP_Fast_Gate_Token::issue($keys['site'],'fg_path','session','',
        $token,1,$expires,$keys['sig'],$keys['id']);
    fg_life_check(is_string($ticket),'failed to issue test-only signed ticket');
    $out=getenv('ESP_FG_COOKIE_FILE');
    fg_life_check(is_string($out)&&$out!=='','missing private test cookie path');
    fg_life_check(file_put_contents($out,
        'esp_auth_fg_path='.$token.'; esp_gate_fg_path='.$ticket) > 0,
        'could not write test-only cookie header');
    @chmod($out,0600);
    fg_life_check($db->set_serving(true),
        'cannot test temporary standalone serving');
    fg_life_emit('HTTP_TEST_READY',['counts'=>$db->counts(),
        'serving'=>$db->serving()]);
    exit;
}
if($phase==='stop-serving'){
    $db=fg_life_gate();
    fg_life_check($db->set_serving(false),'could not stop gate after test');
    fg_life_emit('HTTP_TEST_CLOSED',['serving'=>$db->serving()]);
    exit;
}
throw new RuntimeException('Unknown lifecycle phase');
