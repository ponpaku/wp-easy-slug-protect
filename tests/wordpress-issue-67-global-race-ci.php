<?php
/**
 * #67 counterexample: independent globally committed dirty alone, or even
 * pending_count=1 on attachment_id=0, does NOT stop the existing Cron from
 * republishing global READY before an in-flight WordPress canonical write.
 * Disposable actual WordPress/Core/Apache only. Reproducer MUST fail as a
 * security guarantee: it emits EXPECTED_GAP on reproducing the unsafe state.
 */
declare(strict_types=1);
$phase=getenv('ESP67_GLOBAL_RACE_PHASE');
$mode=getenv('ESP67_GLOBAL_RACE_MODE');
if(getenv('ESP_ALLOW_DISPOSABLE_DB')!=='1'||!getenv('ESP_WP_ROOT')||
    !in_array($phase,['hold','audit','restore'],true)||
    !in_array($mode,['dirty','pending'],true))exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require rtrim(getenv('ESP_WP_ROOT'),'/').'/wp-load.php';
$_SERVER['SERVER_SOFTWARE']='Apache/2.4';
if(strpos(DB_NAME,'esp_')!==0||!class_exists('ESP_Media_Index')||
    !ESP_Media_Index::enabled()||!ESP_Media_Index::ready())
    throw new RuntimeException('Global ticket counterexample needs strict disposable WP');
global $wpdb;
$state=ESP_Media_Index::state_table();
$files=ESP_Media_Index::files_table();
$id=(int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts}
    WHERE post_type='attachment' AND post_title=%s LIMIT 1",'ESP31 protected JPEG'));
$public='esp31-public-150x150.jpg';
$path=wp_upload_dir()['basedir'].'/'.$public;
$key='_wp_attachment_metadata';
$root=wp_upload_dir()['basedir'].'/.esp67-global-race';
wp_mkdir_p($root);
$guarded="$root/guarded";$resume="$root/resume";
$updated="$root/updated";$httpresume="$root/httpresume";
function g67_check($yes,string $message):void {
    if(!$yes)throw new RuntimeException('ESP67 GLOBAL RACE: '.$message);
    echo "PASS ESP67 GLOBAL RACE: $message\n";
}
function g67_db():mysqli {return new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);}
if($phase==='audit') {
    g67_check(file_exists($guarded),'worker independently committed global fence');
    $ready=false;
    // Audit is intentionally allowed to run before the originating PHP
    // worker proceeds past its pre-write hook.
    for($i=0;$i<150;$i++){
        ESP_Media_Index::run_batch();
        $db=g67_db();
        $c=$db->query("SELECT status,revision,pending_count FROM $state
            WHERE attachment_id=0")->fetch_assoc();
        $db->close();
        if($c['status']==='ready'){$ready=true;break;}
    }
    g67_check($ready,'CURRENT Cron republished READY while writer is paused BEFORE Core SQL');
    g67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
        'old public owner is again authorized before new canonical write');
    echo "ESP67_GLOBAL_RACE_CRON_REPUBLISHED mode=$mode pending=".$c['pending_count']."\n";
    file_put_contents($resume,'go');
    exit(0);
}
if($phase==='restore') {
    g67_check(file_exists($httpresume),'HTTP witness complete');
    $meta=wp_get_attachment_metadata($id,true);
    g67_check(is_array($meta),'canonical protected metadata present');
    if(isset($meta['sizes']['esp67_global_race'])){
        unset($meta['sizes']['esp67_global_race']);
        g67_check(wp_update_attachment_metadata($id,$meta)!==false,
            'restore original WordPress derivative set');
    }
    ESP_Media_Index::finish_noop_writes();
    $db=g67_db();
    if($mode==='pending'){
        // Test-only cleanup is safe because the originating worker is
        // guaranteed stopped by the coordinator. Never TTL-clear in product.
        $db->query("UPDATE $state SET pending_count=0 WHERE attachment_id=0");
    }
    $db->close();
    g67_check(ESP_Media_Index::reindex_attachment($id),
        'rebuild original protected owner after stopped worker');
    g67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
        'public path restored and globally authorized after reset');
    echo "ESP67_GLOBAL_RACE_RESTORED mode=$mode\n";exit(0);
}
foreach([$guarded,$resume,$updated,$httpresume] as $m)@unlink($m);
g67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
    'initial public attachment indexed and globally READY');
$meta=wp_get_attachment_metadata($id,true);
g67_check(is_array($meta)&&!isset($meta['sizes']['esp67_global_race']),
    'protected owner originally lacks shared public path');
$meta['sizes']['esp67_global_race']=[
    'file'=>$public,'width'=>150,'height'=>150,'mime-type'=>'image/jpeg'];
$guard=static function($check,$post_id,$meta_key)use($id,$key,$state,$mode,$guarded){
    if((int)$post_id!==$id||$meta_key!==$key)return $check;
    // Simulate independent global coverage invalidation PRIOR to Core update.
    // This deliberately has no global mutation-set publication CAS.
    $db=g67_db();$db->begin_transaction();
    try {
        $value=$mode==='pending'?'pending_count=pending_count+1,':'';
        $db->query("UPDATE $state SET $value status='dirty',revision=revision+1,
            audit_cursor=0 WHERE attachment_id=0");
        g67_check($db->affected_rows===1,'independent global dirty committed');
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    finally{$db->close();}
    file_put_contents($guarded,'dirty');
    $until=microtime(true)+35;
    while(!file_exists("$guarded.done")&&microtime(true)<$until)usleep(100000);
    // The audit worker will create resume, not guarded.done. Keep no
    // asynchronous marker dependency beyond the explicit resume below.
    return $check;
};
$pause=static function($check,$post_id,$meta_key)use($id,$key,$guarded,$resume){
    if((int)$post_id!==$id||$meta_key!==$key)return $check;
    g67_check(file_exists($guarded),'prewrite independently committed fence visible');
    $until=microtime(true)+35;
    while(!file_exists($resume)&&microtime(true)<$until)usleep(100000);
    g67_check(file_exists($resume),'Cron audit completed before WordPress canonical write');
    return $check;
};
$kill=static function($check,$post_id,$meta_key)use($id,$key){
    if((int)$post_id!==$id||$meta_key!==$key)return $check;
    global $wpdb;
    $conn=(int)$wpdb->get_var('SELECT CONNECTION_ID()');
    g67_check($conn>0,'caller session can be KILLed');
    $other=g67_db();
    $other->query('KILL CONNECTION '.$conn);$other->close();
    return $check;
};
// Remove guard's unnecessary wait: priority 3 must set marker and return
// promptly so priority 4 can pause while Cron scans.
$fast_guard=static function($check,$post_id,$meta_key)use($id,$key,$state,$mode,$guarded){
    if((int)$post_id!==$id||$meta_key!==$key)return $check;
    $db=g67_db();$db->begin_transaction();
    try {
        $count_sql=$mode==='pending'?'pending_count=pending_count+1,':'';
        $db->query("UPDATE $state SET $count_sql status='dirty',
             revision=revision+1,audit_cursor=0 WHERE attachment_id=0");
        g67_check($db->affected_rows===1,'independent global fence COMMIT before Core SQL');
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    finally{$db->close();}
    file_put_contents($guarded,'dirty');
    return $check;
};
g67_check($wpdb->query('START TRANSACTION')!==false,'caller external transaction started');
add_filter('update_post_metadata',$fast_guard,3,5);
add_filter('update_post_metadata',$pause,4,5);
add_filter('update_post_metadata',$kill,7,5);
try{$ok=wp_update_attachment_metadata($id,$meta);}
finally{
    remove_filter('update_post_metadata',$fast_guard,3);
    remove_filter('update_post_metadata',$pause,4);
    remove_filter('update_post_metadata',$kill,7);
}
g67_check($ok!==false,'WordPress committed canonical new protected owner after audit');
$db=g67_db();
$canonical=$db->query("SELECT meta_value FROM {$wpdb->postmeta} WHERE
     post_id=$id AND meta_key='_wp_attachment_metadata' ORDER BY meta_id LIMIT 1")->fetch_assoc();
$c=$db->query("SELECT status,revision,pending_count FROM $state WHERE
     attachment_id=0")->fetch_assoc();
$n=$db->query("SELECT COUNT(*) AS n FROM $files WHERE attachment_id=$id
     AND path_hash='".hash('sha256',$public)."'")->fetch_assoc();
$db->close();
$actual=$canonical?maybe_unserialize($canonical['meta_value']):null;
g67_check(($actual['sizes']['esp67_global_race']['file']??null)===$public,
    'canonical protected owner now shares public path');
g67_check($c['status']==='ready' && (int)$n['n']===0,
    'GLOBAL GAP: Cron republished READY; candidate rolled back on KILL');
echo 'ESP67_GLOBAL_RACE_EXPECTED_GAP '.wp_json_encode([
    'mode'=>$mode,'global'=>$c,'protected_owner_index_rows'=>(int)$n['n'],
    'public_resolver'=>ESP_Media_Index::resolve($path)
])."\n";
file_put_contents($updated,'updated');
$until=microtime(true)+35;
while(!file_exists($httpresume)&&microtime(true)<$until)usleep(100000);
g67_check(file_exists($httpresume),'anonymous HTTP witnessed post-audit canonical gap');
