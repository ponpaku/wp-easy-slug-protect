<?php
/**
 * #67 automatic ticket recovery counterexample on disposable real WP:
 * - worker independently commits a mutation-ID + reserved candidate path
 * - worker holds an OPTIONAL MySQL GET_LOCK as a proposed liveness witness
 * - worker is paused in the real update_post_metadata pre-SQL hook
 * - an independent reaper KILLs only the liveness connection (not PHP/WordPress)
 * - named lock is now ACQUIRABLE even though the PHP writer is ALIVE
 * - unsafe reaper discards its ticket and reindexes old canonical metadata
 * - PHP continues Core's metadata SQL; new protected owner is missing
 *
 * This deliberately UNSAFE test is for design rejection, never production.
 */
declare(strict_types=1);
$phase=getenv('ESP67_GC_PHASE');
if (getenv('ESP_ALLOW_DISPOSABLE_DB')!=='1'||!getenv('ESP_WP_ROOT')||
    !in_array($phase,['setup','writer','reap','verify','restore'],true))exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require rtrim(getenv('ESP_WP_ROOT'),'/').'/wp-load.php';
$_SERVER['SERVER_SOFTWARE']='Apache/2.4';
if (strpos(DB_NAME,'esp_')!==0||!class_exists('ESP_Media_Index')||
    !ESP_Media_Index::enabled()||!ESP_Media_Index::ready())
    throw new RuntimeException('#67 GC counterexample requires disposable strict READY WP');
global $wpdb;
$id=(int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts}
    WHERE post_type='attachment' AND post_title=%s LIMIT 1",'ESP31 protected JPEG'));
if ($id<=0)throw new RuntimeException('no protected attachment');
$state=ESP_Media_Index::state_table();
$files=ESP_Media_Index::files_table();
$public='esp31-public-150x150.jpg';
$path=wp_upload_dir()['basedir'].'/'.$public;
$root=wp_upload_dir()['basedir'].'/.esp67-auto-gc';
wp_mkdir_p($root);
$started="$root/started";$resume="$root/resume";$written="$root/written";
$session_file="$root/live-session";
$name='esp67_auto_gc_'.substr(hash('sha256',DB_NAME.$wpdb->prefix),0,24);
function gc67_check(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('ESP67 AUTO GC: '.$why);
    echo "PASS ESP67 AUTO GC: $why\n";
}
function gc67_db():mysqli {
    $db=new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);
    return $db;
}
function gc67_state(string $state,int $id):array {
    $db=gc67_db();
    $r=$db->query("SELECT status,revision,pending_count,esp67_gc_ids
        FROM $state WHERE attachment_id=$id")->fetch_assoc();
    $db->close();
    if(!is_array($r))throw new RuntimeException('missing gc state');
    return $r;
}
if($phase==='setup'){
    foreach([$started,$resume,$written,$session_file] as $f)@unlink($f);
    gc67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
        'public path initially authorized from original owner');
    $m=wp_get_attachment_metadata($id,true);
    gc67_check(is_array($m)&&!isset($m['sizes']['esp67_auto_gc']),
        'protected owner originally does not claim public path');
    $db=gc67_db();
    gc67_check($db->query("ALTER TABLE $state
        ADD COLUMN esp67_gc_ids LONGTEXT NULL")!==false,'test-only ID column added');
    $db->query("UPDATE $state SET esp67_gc_ids='[]' WHERE attachment_id=$id");
    $db->close();
    echo "ESP67_AUTO_GC_SETUP\n";exit(0);
}
if($phase==='restore'){
    $m=wp_get_attachment_metadata($id,true);
    gc67_check(is_array($m),'canonical metadata readable for recovery');
    if(isset($m['sizes']['esp67_auto_gc'])){
        unset($m['sizes']['esp67_auto_gc']);
        gc67_check(wp_update_attachment_metadata($id,$m)!==false,
            'test changes restored via normal WordPress API');
    }
    ESP_Media_Index::finish_noop_writes();
    gc67_check(ESP_Media_Index::reindex_attachment($id),
        'rebuild original protected attachment');
    $db=gc67_db();
    $db->query("ALTER TABLE $state DROP COLUMN esp67_gc_ids");
    $db->close();
    gc67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
        'original public image available after cleanup');
    echo "ESP67_AUTO_GC_RESTORED\n";exit(0);
}
if($phase==='reap'){
    gc67_check(file_exists($started),'writer entered actual WP pre-SQL hook');
    $session=(int)file_get_contents($session_file);
    gc67_check($session>0,'writer liveness DB session recorded');
    $killer=gc67_db();$killer->query('KILL CONNECTION '.$session);
    $killer->close();
    // A different DB connection acquiring the named lock is NOT a reliable
    // proof that the PHP writer cannot resume, as witnessed below.
    $reaper=gc67_db();
    $got=$reaper->query("SELECT GET_LOCK('$name',1) AS got")->fetch_assoc();
    gc67_check((int)$got['got']===1,
        'named lock is free after only liveness connection was KILLed');
    $reaper->begin_transaction();
    $r=$reaper->query("SELECT revision,pending_count,esp67_gc_ids FROM $state
        WHERE attachment_id=$id FOR UPDATE")->fetch_assoc();
    gc67_check($r['esp67_gc_ids']==='["PAUSED"]'&&(int)$r['pending_count']===1,
        'reaper sees apparently abandoned ID before simulated unsafe GC');
    $reaper->query("UPDATE $state SET status='dirty',revision=revision+1,
        pending_count=0,esp67_gc_ids='[]' WHERE attachment_id=$id");
    $reaper->commit();
    $reaper->query("SELECT RELEASE_LOCK('$name')");
    $reaper->close();
    gc67_check(ESP_Media_Index::reindex_attachment($id),
        'unsafe reaper rebuilt index from still-old canonical metadata');
    $s=gc67_state($state,$id);
    gc67_check($s['status']==='ready'&&(int)$s['pending_count']===0,
        'unsafe reaper published READY before live PHP writer resumes');
    echo "ESP67_AUTO_GC_UNSAFE_RECLAIM\n";exit(0);
}
if($phase==='verify'){
    gc67_check(file_exists($written),'worker completed its real WordPress Core write');
    $db=gc67_db();
    $canonical=$db->query("SELECT meta_value FROM {$wpdb->postmeta}
        WHERE post_id=$id AND meta_key='_wp_attachment_metadata'
        ORDER BY meta_id LIMIT 1")->fetch_assoc();
    $candidate=$db->query("SELECT COUNT(*) AS n FROM $files
        WHERE attachment_id=$id AND path_hash='".hash('sha256',$public)."'")->fetch_assoc();
    $db->close();
    $m=$canonical?maybe_unserialize($canonical['meta_value']):null;
    gc67_check(($m['sizes']['esp67_auto_gc']['file']??null)===$public,
        'stale writer eventually committed protected owner to WP canonical');
    gc67_check((int)$candidate['n']===0,
        'unsafe GC reindex discarded owner reservation');
    $s=gc67_state($state,$id);
    gc67_check($s['status']==='ready'&&(int)$s['pending_count']===0,
        'state remains READY despite late canonical protected write');
    $resolved=ESP_Media_Index::resolve($path);
    gc67_check($resolved['status']==='resolved'&&
        ($resolved['protected_path_id']??null)==='',
        'resolver sees only old public owner after unsafe automatic reclamation');
    echo "ESP67_AUTO_GC_REPLAYED_STALE_WRITER\n";exit(0);
}
gc67_check($phase==='writer','valid writer phase');
$m=wp_get_attachment_metadata($id,true);
gc67_check(is_array($m),'canonical initial metadata readable');
$m['sizes']['esp67_auto_gc']=[
    'file'=>$public,'width'=>150,'height'=>150,'mime-type'=>'image/jpeg'];
remove_filter('update_post_metadata',['ESP_Media_Index','guard_update_meta'],5);
remove_action('updated_post_meta',['ESP_Media_Index','on_meta_saved'],20);
$liveness=gc67_db();
$locked=$liveness->query("SELECT GET_LOCK('$name',0) AS got")->fetch_assoc();
gc67_check((int)$locked['got']===1,'writer acquired independent session liveness lock');
file_put_contents($session_file,(string)$liveness->thread_id);
$pre=static function($check,$post_id,$meta_key)use(
    $id,$state,$files,$public,$started,$resume
){
    if((int)$post_id!==$id||$meta_key!=='_wp_attachment_metadata')return $check;
    $db=gc67_db();$db->begin_transaction();
    try {
        $r=$db->query("SELECT revision,pending_count,esp67_gc_ids
            FROM $state WHERE attachment_id=$id FOR UPDATE")->fetch_assoc();
        gc67_check($r['esp67_gc_ids']==='[]'&&(int)$r['pending_count']===0,
            'writer starts without older unfinished IDs');
        $rev=(int)$r['revision']+1;
        $db->query("UPDATE $state SET status='dirty',revision=$rev,
            pending_count=1,esp67_gc_ids='[\"PAUSED\"]' WHERE attachment_id=$id");
        $hash=hash('sha256',$public);
        $name=$db->real_escape_string($public);
        $db->query("INSERT INTO $files
            (path_hash,file_path,attachment_id,generation,protected_path_id)
            VALUES('$hash','$name',$id,$rev,'')");
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    finally{$db->close();}
    file_put_contents($started,'started');
    $limit=microtime(true)+50;
    while(!file_exists($resume)&&microtime(true)<$limit)usleep(100000);
    gc67_check(file_exists($resume),'suspended PHP writer resumed after unsafe recovery');
    // Core's canonical UPDATE happens AFTER this filter returns.
    return $check;
};
add_filter('update_post_metadata',$pre,3,5);
try{$ok=wp_update_attachment_metadata($id,$m);}
finally{
    remove_filter('update_post_metadata',$pre,3);
    // This connection has been forcibly killed, but the PHP request is alive.
    @$liveness->close();
}
gc67_check($ok!==false,'real WordPress metadata write executed after ticket GC');
file_put_contents($written,'written');
echo "ESP67_AUTO_GC_LATE_CORE_WRITE\n";
