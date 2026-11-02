<?php
/**
 * #67 proof-of-concept crash-safe AUTOMATIC GC with per-attachment POSIX flock.
 *
 * Disposable single-host WordPress only: a PHP request holds LOCK_SH over its
 * *whole* canonical write lifetime; a reaper may retire orphan tickets only
 * while it holds LOCK_EX on the SAME persistent inode.
 *
 * WARNING: Not portable to multi-host WP unless shared flock semantics can be
 * established on every node. Not yet a production recommendation.
 */
declare(strict_types=1);
$phase=getenv('ESP67_FLOCK_PHASE');
$scenario=getenv('ESP67_FLOCK_SCENARIO');
if(getenv('ESP_ALLOW_DISPOSABLE_DB')!=='1'||!getenv('ESP_WP_ROOT')||
    !in_array($phase,['setup','writer','recover','verify','kill_db','restore'],true)||
    !in_array($scenario,['crash','db-loss'],true))exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require rtrim(getenv('ESP_WP_ROOT'),'/').'/wp-load.php';
$_SERVER['SERVER_SOFTWARE']='Apache/2.4';
if(strpos(DB_NAME,'esp_')!==0||!class_exists('ESP_Media_Index')||
    !ESP_Media_Index::enabled()||!ESP_Media_Index::ready())
    throw new RuntimeException('ESP67 flock probe requires disposable strict READY WP');
global $wpdb;
$id=(int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE
    post_type='attachment' AND post_title=%s LIMIT 1",'ESP31 protected JPEG'));
if($id<=0)throw new RuntimeException('missing protected owner');
$state=ESP_Media_Index::state_table();
$files=ESP_Media_Index::files_table();
$public='esp31-public-150x150.jpg';
$path=wp_upload_dir()['basedir'].'/'.$public;
$root=wp_upload_dir()['basedir'].'/.esp67-flock';
wp_mkdir_p($root);
$lockfile="$root/".hash('sha256',DB_NAME."\0".$wpdb->prefix."\0".$id).'.lock';
$ready="$root/ready";$resume="$root/resume";$pidfile="$root/pid";
$sessionfile="$root/liveness";$written="$root/written";
$ticket='FLOCK';
function fl67_check(bool $yes,string $msg):void {
    if(!$yes)throw new RuntimeException('ESP67 flock: '.$msg);
    echo "PASS ESP67 flock: $msg\n";
}
function fl67_db():mysqli {return new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);}
function fl67_get(string $state,int $id):array {
    $db=fl67_db();
    $r=$db->query("SELECT status,revision,pending_count,esp67_flock_ids FROM $state
        WHERE attachment_id=$id")->fetch_assoc();
    $db->close();
    if(!is_array($r))throw new RuntimeException('no state');
    return $r;
}
if($phase==='setup'){
    foreach([$ready,$resume,$pidfile,$sessionfile,$written] as $f)@unlink($f);
    fl67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
        'baseline public path resolves without protected collision');
    $m=wp_get_attachment_metadata($id,true);
    fl67_check(is_array($m)&&!isset($m['sizes']['esp67_flock']),
        'baseline protected owner has no collision');
    $fh=fopen($lockfile,'c+');
    fl67_check(is_resource($fh),'stable lockfile created');
    fclose($fh);
    $db=fl67_db();
    fl67_check($db->query("ALTER TABLE $state ADD COLUMN esp67_flock_ids LONGTEXT NULL")!==false,
        'test-only mutation ID column added');
    $db->query("UPDATE $state SET esp67_flock_ids='[]' WHERE attachment_id=$id");
    $db->close();
    echo "ESP67_FLOCK_SETUP\n";exit(0);
}
if($phase==='restore'){
    $m=wp_get_attachment_metadata($id,true);
    fl67_check(is_array($m),'canonical metadata exists');
    if(isset($m['sizes']['esp67_flock'])){
        unset($m['sizes']['esp67_flock']);
        fl67_check(wp_update_attachment_metadata($id,$m)!==false,
            'production WordPress API restored canonical owner');
    }
    ESP_Media_Index::finish_noop_writes();
    fl67_check(ESP_Media_Index::reindex_attachment($id),'final reindex passed');
    $db=fl67_db();
    fl67_check(fl67_get($state,$id)['esp67_flock_ids']==='[]','mutation set cleared');
    $db->query("ALTER TABLE $state DROP COLUMN esp67_flock_ids");
    $db->close();
    fl67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
        'public path restored after recovery');
    echo "ESP67_FLOCK_RESTORED\n";exit(0);
}
if($phase==='kill_db'){
    fl67_check(file_exists($ready),'PHP writer still blocked in actual pre-SQL hook');
    $session=(int)file_get_contents($sessionfile);
    fl67_check($session>0,'optional liveness connection ID recorded');
    $killer=fl67_db();$killer->query('KILL CONNECTION '.$session);
    $killer->close();
    // KILL above does NOT kill the PHP request or its stable local flock.
    echo "ESP67_FLOCK_MYSQL_LIVENESS_LOST\n";exit(0);
}
if($phase==='recover'){
    $lock=fopen($lockfile,'c+');
    fl67_check(is_resource($lock),'recovery opened persistent lock inode');
    if(!flock($lock,LOCK_EX|LOCK_NB)){
        $s=fl67_get($state,$id);
        fl67_check($s['esp67_flock_ids']==='["FLOCK"]' &&
            (int)$s['pending_count']===1 && $s['status']==='dirty',
            'active PHP shared flock keeps mutation ticket and dirty state');
        fl67_check(ESP_Media_Index::resolve($path)['status']==='error',
            'active PHP update keeps HTTP path fail closed');
        echo "ESP67_FLOCK_RECOVERY_DEFERRED_ACTIVE\n";
        fclose($lock);exit(0);
    }
    // Invariant: all legitimate writers held LOCK_SH before registration and
    // continue holding it to request termination; exclusive lock excludes
    // any late writer after this point. Future writers must acquire SH first.
    $db=fl67_db();$db->begin_transaction();
    try {
        $r=$db->query("SELECT revision,pending_count,esp67_flock_ids
            FROM $state WHERE attachment_id=$id FOR UPDATE")->fetch_assoc();
        fl67_check($r['esp67_flock_ids']==='["FLOCK"]' &&
            (int)$r['pending_count']===1,'unowned ticket exists after all PHP locks released');
        $db->query("UPDATE $state SET status='dirty',revision=revision+1,
             pending_count=0,esp67_flock_ids='[]'
             WHERE attachment_id=$id AND revision=".(int)$r['revision']);
        fl67_check($db->affected_rows===1,'conditional ticket retirement committed once');
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    finally{$db->close();}
    fl67_check(ESP_Media_Index::reindex_attachment($id),
        'rebuild under exclusive filesystem lock from canonical WordPress meta');
    $r=ESP_Media_Index::resolve($path);
    if($scenario==='crash'){
        fl67_check($r['status']==='resolved'&&
            ($r['protected_path_id']??null)==='',
            'crashed pre-write worker restored old public path');
        echo "ESP67_FLOCK_CRASH_AUTO_RECOVERED\n";
    }else{
        fl67_check($r['status']==='ambiguous',
            'finished worker new protected owner produces deny-on-collision');
        echo "ESP67_FLOCK_DBLOSS_AUTO_RECOVERED\n";
    }
    flock($lock,LOCK_UN);fclose($lock);exit(0);
}
if($phase==='verify'){
    fl67_check(file_exists($written),'writer finished the actual WP write');
    $db=fl67_db();
    $row=$db->query("SELECT meta_value FROM {$wpdb->postmeta} WHERE
        post_id=$id AND meta_key='_wp_attachment_metadata' LIMIT 1")->fetch_assoc();
    $db->close();
    $meta=$row?maybe_unserialize($row['meta_value']):null;
    fl67_check(($meta['sizes']['esp67_flock']['file']??null)===$public,
        'real Core canonical write committed after liveness DB session loss');
    $s=fl67_get($state,$id);
    fl67_check($s['esp67_flock_ids']==='["FLOCK"]'&&$s['status']==='dirty',
        'ticket remains until explicit safe auto-recovery');
    echo "ESP67_FLOCK_LATE_WRITER_FINISHED\n";exit(0);
}
fl67_check($phase==='writer','PHP worker launched');
$fh=fopen($lockfile,'c+');
fl67_check(is_resource($fh)&&flock($fh,LOCK_SH),'PHP writer acquired shared lifetime lock');
file_put_contents($pidfile,(string)getmypid());
$liveness=fl67_db();
fl67_check((int)$liveness->query("SELECT GET_LOCK('esp67_fl_live',0) AS got")
    ->fetch_assoc()['got']===1,'optional MySQL liveness acquired');
file_put_contents($sessionfile,(string)$liveness->thread_id);
$m=wp_get_attachment_metadata($id,true);
fl67_check(is_array($m)&&!isset($m['sizes']['esp67_flock']),
    'before update, canonical protected owner has no new derivative');
$m['sizes']['esp67_flock']=['file'=>$public,'width'=>150,'height'=>150,
    'mime-type'=>'image/jpeg'];
remove_filter('update_post_metadata',['ESP_Media_Index','guard_update_meta'],5);
remove_action('updated_post_meta',['ESP_Media_Index','on_meta_saved'],20);
$pre=static function($check,$post_id,$meta_key)use(
    $id,$state,$files,$public,$ready,$resume
){
    if((int)$post_id!==$id||$meta_key!=='_wp_attachment_metadata')return $check;
    $db=fl67_db();$db->begin_transaction();
    try {
        $r=$db->query("SELECT revision,pending_count,esp67_flock_ids
            FROM $state WHERE attachment_id=$id FOR UPDATE")->fetch_assoc();
        fl67_check((int)$r['pending_count']===0&&$r['esp67_flock_ids']==='[]',
            'new writer starts with clean mutation ticket set');
        $rev=(int)$r['revision']+1;
        $db->query("UPDATE $state SET status='dirty',revision=$rev,pending_count=1,
            esp67_flock_ids='[\"FLOCK\"]' WHERE attachment_id=$id");
        $hash=hash('sha256',$public);$escaped=$db->real_escape_string($public);
        $db->query("INSERT INTO $files
             (path_hash,file_path,attachment_id,generation,protected_path_id)
             VALUES('$hash','$escaped',$id,$rev,'')");
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    finally{$db->close();}
    file_put_contents($ready,'ready');
    $until=microtime(true)+60;
    while(!file_exists($resume)&&microtime(true)<$until)usleep(100000);
    fl67_check(file_exists($resume),'writer resumed after supervisor approval');
    return $check;
};
add_filter('update_post_metadata',$pre,3,5);
try{$ok=wp_update_attachment_metadata($id,$m);}
finally{
    remove_filter('update_post_metadata',$pre,3);
    @$liveness->close();
}
fl67_check($ok!==false,'WordPress completed canonical postmeta write');
file_put_contents($written,'done');
// The shared flock is deliberately NOT manually released early; the PHP
// request must terminate before exclusive recovery can proceed.
echo "ESP67_FLOCK_WORKER_FINISHED\n";
