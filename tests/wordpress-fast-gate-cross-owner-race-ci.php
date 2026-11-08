<?php
/**
 * R01 cross-owner race with TWO independent PHP/WordPress/MySQL/SQLite workers.
 * Test-only, not part of the distribution ZIP.
 *
 * A stale MySQL REPEATABLE READ snapshot deliberately retains public policy
 * while a second process commits a real WordPress metadata mutation and
 * refreshes the shared SQLite file. No production hooks are added.
 */
if (PHP_SAPI !== 'cli' ||
    getenv('CI') !== 'true' ||
    getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' ||
    getenv('ESP_FG_CI_RACE') !== '1' ||
    !is_string(getenv('ESP_WP_ROOT')) ||
    !is_string(getenv('ESP_FG_RACE_SYNC')) ||
    !in_array(getenv('ESP_FG_RACE_PHASE'),
        ['setup','stale-b','protect-a','verify'], true)) {
    fwrite(STDERR, "Refusing non-disposable R01 two-worker test\n");
    exit(1);
}
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
$_SERVER['SERVER_SOFTWARE']='Apache/2.4';
require rtrim(getenv('ESP_WP_ROOT'),'/').'/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
if (DB_NAME !== 'esp_fg_lifecycle' ||
    !is_plugin_active('easy-slug-protect/easy-slug-protect.php') ||
    !class_exists('ESP_Fast_Gate_WP')) {
    throw new RuntimeException('R01 requires real disposable WordPress');
}
$phase=getenv('ESP_FG_RACE_PHASE');
$sync=getenv('ESP_FG_RACE_SYNC');
$uploads=wp_upload_dir();
$p='fg-life-public.jpg';
$b_file='fg-life-0002.jpg';
$p_abs=$uploads['basedir'].'/'.$p;
$b_abs=$uploads['basedir'].'/'.$b_file;
$owner_a=(int)$wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_title=%s
     AND post_type='attachment' LIMIT 1", 'FG lifecycle public'));
$owner_b=(int)$wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_title=%s
     AND post_type='attachment' LIMIT 1", 'FG lifecycle owner 2'));
function fg_race_assert($value, $why) {
    if (!$value) {
        throw new RuntimeException('R01 real worker: '.$why.
            '; mysql='.($GLOBALS['wpdb']->last_error??''));
    }
}
function fg_race_log($step,$data=[]) {
    echo 'FG_R01_PROCESS '.wp_json_encode(
        ['phase'=>$step,'pid'=>getmypid()]+$data)."\n";
    flush();
}
fg_race_assert(is_dir($sync) && is_writable($sync),
    'synchronization directory unavailable');
fg_race_assert($owner_a>0 && $owner_b>0 &&
    is_file($p_abs) && is_file($b_abs),'real attachments missing');
$db=ESP_Fast_Gate_WP::ledger(true);
fg_race_assert($db!==null && $db->schema_ok(),'SQLite ledger unavailable');
$active=static function(){return '1';};

if($phase==='setup'){
    fg_race_assert($db->ready() && !$db->serving(),'gate must be stopped for setup');
    fg_race_assert(get_attached_file($owner_b)===$b_abs,
        'B original path is not separate');
    fg_race_assert(!ESP_Fast_Gate_WP::active(),'production active option was modified');
    // Two owners initially claim the same physical public path. Build the
    // baseline catalog with BOTH, then detach B via a real metadata update.
    fg_race_assert(update_attached_file($owner_b,$p_abs),
        'B cannot claim shared path through WordPress');
    ESP_Media_Index::finish_noop_writes();
    $state_table=ESP_Media_Index::state_table();
    $pre_index=$wpdb->get_row($wpdb->prepare(
        "SELECT status,pending_count,revision FROM $state_table
         WHERE attachment_id=%d", $owner_b),ARRAY_A);
    fg_race_assert((int)($pre_index['pending_count']??-1)===0,
        'B metadata update left unfinished pending writers: '.
        wp_json_encode($pre_index));
    $indexed=ESP_Media_Index::reindex_attachment($owner_b);
    $index_state=$wpdb->get_row($wpdb->prepare(
        "SELECT status,pending_count,revision FROM $state_table
         WHERE attachment_id=%d",$owner_b),ARRAY_A);
    fg_race_assert($indexed,
        'B index did not publish shared owner: '.wp_json_encode($index_state));
    $ambiguous=ESP_Media_Index::resolve($p_abs);
    fg_race_assert($ambiguous['status']==='ambiguous',
        'real MySQL shared ownership was not ambiguous');
    fg_race_assert(ESP_Fast_Gate_WP::build_catalog(),
        'cannot build shared-owner SQLite catalog');
    fg_race_assert($db->get_media($p)['policy']==='deny',
        'shared owner must initially be denied');

    // Simulate the precise, legitimate interruption point after B's MySQL
    // index publication but before B's SQLite refresh (a worker can die
    // here). The original owner_media(P) and pre-write fence stay durable.
    add_filter('pre_option_'.ESP_Fast_Gate_WP::ACTIVE_OPTION,$active);
    remove_action('esp_media_index_published',
        [ESP_Fast_Gate_WP::class,'refresh_owner'],10);
    fg_race_assert(update_attached_file($owner_b,$b_abs),
        'B real metadata detachment failed');
    ESP_Media_Index::finish_noop_writes();
    fg_race_assert(ESP_Media_Index::reindex_attachment($owner_b),
        'B MySQL index detachment failed');
    $only_a=ESP_Media_Index::resolve($p_abs);
    fg_race_assert($only_a['status']==='resolved' &&
        (int)$only_a['attachment_id']===$owner_a &&
        $only_a['protected_path_id']==='',
        'A is not the sole public owner in current MySQL');
    fg_race_assert(in_array($owner_b,$db->pending_owners(),true) &&
        in_array($p,$db->owner_paths($owner_b),true) &&
        $db->get_media($p)===null,
        'B durable SQLite fence must remain after MySQL update');
    remove_filter('pre_option_'.ESP_Fast_Gate_WP::ACTIVE_OPTION,$active);
    // No Rewrite is installed by the product. Serving is test-only here,
    // and the pending fence ensures the shared file remains denied.
    fg_race_assert($db->set_serving(true),'test-only serving not enabled');
    fg_race_log('B_MYSQL_PUBLISHED_SQLITE_FENCED',
        ['owner_a'=>$owner_a,'owner_b'=>$owner_b]);
    exit;
}

add_filter('pre_option_'.ESP_Fast_Gate_WP::ACTIVE_OPTION,$active);
fg_race_assert($db->ready() && $db->serving(),
    'test-only gate unexpectedly stopped');

if($phase==='stale-b'){
    // Deliberately preserve an OLD, internally consistent MySQL snapshot.
    // It must remain public after A commits protected in ANOTHER process.
    fg_race_assert($wpdb->query(
        'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')!==false,
        'cannot configure MySQL snapshot');
    fg_race_assert($wpdb->query(
        'START TRANSACTION WITH CONSISTENT SNAPSHOT')!==false,
        'cannot start MySQL snapshot');
    $before=ESP_Media_Index::resolve($p_abs);
    fg_race_assert($before['status']==='resolved' &&
        $before['protected_path_id']==='' &&
        (int)$before['attachment_id']===$owner_a,
        'B did not read the OLD public canonical MySQL policy');
    $before_epoch=$db->path_epochs([$p])[$p] ?? null;
    fg_race_assert(is_int($before_epoch),'cannot snapshot shared path epoch');
    $paused=false;
    $pause=static function($query) use (&$paused,$sync) {
        if (!$paused && strpos($query,'c.status AS coverage_status')!==false &&
            strpos($query,'WHERE f.path_hash=')!==false) {
            $paused=true;
            if(file_put_contents($sync.'/b-ready',"old-public\n")===false) {
                throw new RuntimeException('R01 rendezvous signal failed');
            }
            $begin=microtime(true);
            while(!is_file($sync.'/a-done')) {
                if(microtime(true)-$begin>45) {
                    throw new RuntimeException('R01 A writer timed out');
                }
                usleep(50000);
            }
        }
        return $query;
    };
    add_filter('query',$pause,1);
    // Real adapter: owner epoch + shared path epoch captured BEFORE pause;
    // after A writes, this must fail CAS without removing B's fence.
    $published=ESP_Fast_Gate_WP::refresh_owner($owner_b);
    remove_filter('query',$pause,1);
    fg_race_assert($paused,'B never reached actual resolver SQL');
    fg_race_assert($published===false,
        'stale B refresh overwrote new protected policy');
    fg_race_assert(($db->path_epochs([$p])[$p] ?? -1)>$before_epoch,
        'shared path generation did not advance while B was paused');
    $old=ESP_Media_Index::resolve($p_abs);
    fg_race_assert($old['status']==='resolved' &&
        $old['protected_path_id']==='',
        'B did not retain stale public MySQL snapshot');
    fg_race_assert($wpdb->query('ROLLBACK')!==false,
        'cannot leave old MySQL snapshot');
    fg_race_assert(in_array($owner_b,$db->pending_owners(),true) &&
        $db->get_media($p)===null,
        'failed B stale publish retired its deny fence');
    fg_race_log('STALE_PUBLIC_PUBLISH_REJECTED_FENCE_RETAINED');
    exit;
}

if($phase==='protect-a'){
    $changed=update_post_meta($owner_a,
        ESP_Media_Index::PROTECTION_META,'fg_path');
    fg_race_assert((bool)$changed,'A WordPress public-to-protected update failed');
    ESP_Media_Index::finish_noop_writes();
    fg_race_assert(ESP_Media_Index::reindex_attachment($owner_a),
        'A real index publish failed');
    $canonical=ESP_Media_Index::resolve($p_abs);
    fg_race_assert($canonical['status']==='resolved' &&
        $canonical['protected_path_id']==='fg_path',
        'MySQL canonical owner A did not become protected');
    $raw=new SQLite3(ESP_Fast_Gate_WP::private_dir().'/gate-ledger.sqlite');
    $policy=$raw->querySingle("SELECT policy FROM media WHERE path_hash='".
        hash('sha256',$p)."'");
    fg_race_assert($policy==='protected',
        'A new protected policy missing from SQLite');
    fg_race_assert($db->get_media($p)===null,
        'B pending fence should deny the otherwise protected media');
    fg_race_log('OWNER_A_PROTECTED_MYSQL_AND_SQLITE_COMMITTED');
    exit;
}
if($phase==='verify'){
    $raw=new SQLite3(ESP_Fast_Gate_WP::private_dir().'/gate-ledger.sqlite');
    $policy=$raw->querySingle("SELECT policy FROM media WHERE path_hash='".
        hash('sha256',$p)."'");
    fg_race_assert($policy==='protected' &&
        in_array($owner_b,$db->pending_owners(),true) &&
        $db->get_media($p)===null,
        'B stale worker changed A policy or lost fence');
    fg_race_assert(ESP_Fast_Gate_WP::refresh_owner($owner_b),
        'B cannot retry using FRESH canonical snapshot');
    fg_race_assert($db->get_media($p)['policy']==='protected',
        'fresh B publish did not preserve A protected policy');
    fg_race_assert((bool)update_post_meta($owner_a,
        ESP_Media_Index::PROTECTION_META,''),
        'cannot restore A public after race');
    ESP_Media_Index::finish_noop_writes();
    fg_race_assert(ESP_Media_Index::reindex_attachment($owner_a),
        'cannot publish restored A media index');
    fg_race_assert($db->get_media($p)['policy']==='public',
        'restore failed or B retained a stale denial');
    fg_race_assert($db->set_serving(false),
        'cannot stop test-only gate after race');
    $rebuilt=ESP_Fast_Gate_WP::build_catalog();
    if (!$rebuilt) {
        $state_table=ESP_Media_Index::state_table();
        $dirty=$wpdb->get_results(
            "SELECT attachment_id,status,revision,pending_count
             FROM $state_table WHERE attachment_id>0
             AND (status<>'ready' OR pending_count<>0) LIMIT 6",ARRAY_A);
        $state=ESP_Media_Index::build_status(false);
        $b_resolved=ESP_Media_Index::resolve($b_abs);
        fg_race_log('FINAL_CATALOG_DIAGNOSTICS',[
            'index_ready'=>ESP_Media_Index::ready(),
            'index_state'=>$state,
            'dirty'=>$dirty,
            'gate_ready'=>$db->ready(),
            'gate_counts'=>$db->counts(),
            'b_resolve'=>$b_resolved,
            'mysql_error'=>$wpdb->last_error
        ]);
    }
    fg_race_assert($rebuilt,'cannot rebuild clean catalog after race');
    fg_race_assert($db->ready() && !$db->serving() &&
        $db->counts()['media']===245 &&
        $db->pending_owners()===[],
        'final catalog incomplete or started serving');
    fg_race_log('R01_REAL_MULTIPROCESS_COMPLETED_AND_RESTORED');
    exit;
}
