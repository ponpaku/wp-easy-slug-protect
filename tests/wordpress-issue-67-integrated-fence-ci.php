<?php
/**
 * #67 experimental integrated protocol; disposable WordPress only.
 *
 * Replaces ONLY the production update_post_metadata guard in this PHP process
 * with:
 *  - independent InnoDB commit of mutation-ID set + dirty + candidate
 *  - caller connection SELECT ... FOR UPDATE lock to delay ticket retirement
 *  - independent, idempotent ticket retirement after caller completion
 * Tests both Core reconnect after KILL and a caller-owned uncommitted TX.
 * Not ready for production: other WP mutation APIs/DBs/crash recovery untested.
 */
declare(strict_types=1);
$phase=getenv('ESP67_INTEGRATED_PHASE');
if (getenv('ESP_ALLOW_DISPOSABLE_DB')!=='1'||!getenv('ESP_WP_ROOT')||
    !in_array($phase,['setup','disconnect','txhold','restore'],true))exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require rtrim(getenv('ESP_WP_ROOT'),'/').'/wp-load.php';
$_SERVER['SERVER_SOFTWARE']='Apache/2.4';
if (strpos(DB_NAME,'esp_')!==0||!class_exists('ESP_Media_Index')||
    !ESP_Media_Index::enabled()||!ESP_Media_Index::ready()) {
    throw new RuntimeException('#67 integrated probe requires strict disposable WP');
}
global $wpdb;
$id=(int)$wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'
     AND post_title=%s LIMIT 1",'ESP31 protected JPEG'));
if ($id<1)throw new RuntimeException('Missing protected fixture');
$state=ESP_Media_Index::state_table();
$files=ESP_Media_Index::files_table();
$public='esp31-public-150x150.jpg';
$file=wp_upload_dir()['basedir'].'/'.$public;
$root=wp_upload_dir()['basedir'].'/.esp67-integrated';
wp_mkdir_p($root);
$ready="$root/ready";
$resume="$root/resume";
$key='_wp_attachment_metadata';
function int67_check(bool $result,string $message):void {
    if(!$result)throw new RuntimeException('ESP67 INTEGRATED: '.$message);
    echo "PASS ESP67 INTEGRATED: $message\n";
}
function int67_db():mysqli { return new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME); }
function int67_row(mysqli $db,string $state,int $id,bool $lock=false):array {
    $result=$db->query("SELECT status,revision,pending_count,esp67_mutation_ids
      FROM $state WHERE attachment_id=$id".($lock?' FOR UPDATE':''));
    if($result===false)throw new RuntimeException(
        'state query failed errno='.$db->errno.': '.$db->error);
    $r=$result->fetch_assoc();
    if(!$r)throw new RuntimeException('Missing state row');
    $ids=json_decode((string)$r['esp67_mutation_ids'],true,512,JSON_THROW_ON_ERROR);
    if(!is_array($ids)||!array_is_list($ids)||count($ids)!==count(array_unique($ids))||
        count(array_filter($ids,'is_string'))!==count($ids)||
        (int)$r['pending_count']!==count($ids)){
        throw new RuntimeException('mutation set/count corrupt or legacy guard active');
    }
    $r['ids']=$ids;return $r;
}
function int67_register(string $state,string $files,int $id,string $ticket,string $public):void {
    $db=int67_db();$db->begin_transaction();
    try {
        $r=int67_row($db,$state,$id,true);
        if(in_array($ticket,$r['ids'],true)) {
            $db->commit();return;
        }
        $ids=$r['ids'];$ids[]=$ticket;sort($ids,SORT_STRING);
        $encoded=$db->real_escape_string(json_encode($ids,JSON_THROW_ON_ERROR));
        $revision=(int)$r['revision']+1;
        $count=count($ids);
        $db->query("UPDATE $state SET status='dirty',revision=$revision,
            pending_count=$count,esp67_mutation_ids='$encoded'
            WHERE attachment_id=$id AND revision=".(int)$r['revision']);
        int67_check($db->affected_rows===1,'independent state fence persisted');
        $path=$db->real_escape_string($public);
        $hash=hash('sha256',$public);
        // In production, reserve EVERY derived path, not just this single
        // test collision. Existing ready protected_path_id is immaterial while
        // the candidate state remains dirty.
        $db->query("INSERT INTO $files
            (path_hash,file_path,attachment_id,generation,protected_path_id)
            VALUES('$hash','$path',$id,$revision,'')");
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    finally{$db->close();}
}
function int67_retire(string $state,int $id,string $ticket,int $lock_timeout=2):bool {
    $db=int67_db();
    $db->query('SET SESSION innodb_lock_wait_timeout='.(int)$lock_timeout);
    $db->begin_transaction();
    try {
        $r=int67_row($db,$state,$id,true);
        if(!in_array($ticket,$r['ids'],true)){$db->commit();return true;}
        $ids=array_values(array_diff($r['ids'],[$ticket]));
        $encoded=$db->real_escape_string(json_encode($ids,JSON_THROW_ON_ERROR));
        $revision=(int)$r['revision']+1;$count=count($ids);
        $db->query("UPDATE $state SET status='dirty',revision=$revision,
            pending_count=$count,esp67_mutation_ids='$encoded'
            WHERE attachment_id=$id AND revision=".(int)$r['revision']);
        if($db->affected_rows!==1)throw new RuntimeException('retirement CAS mismatch');
        $db->commit();return true;
    }catch(mysqli_sql_exception $e){
        $db->rollback();
        if((int)$e->getCode()===1205)return false;
        throw $e;
    }catch(Throwable $e){
        $errno=(int)$db->errno;
        $db->rollback();
        // WP Core disables mysqli strict reporting, so lock wait can be a
        // mysqli query() false rather than mysqli_sql_exception.
        if($errno===1205)return false;
        throw $e;
    }finally{$db->close();}
}
function int67_remove_legacy_hooks():void {
    remove_filter('update_post_metadata',['ESP_Media_Index','guard_update_meta'],5);
    remove_action('updated_post_meta',['ESP_Media_Index','on_meta_saved'],20);
}
function int67_guard(string $state,string $files,int $id,string $key,string $public,string $ticket):callable {
    return static function($check,$post_id,$meta_key,$value) use($id,$key,$state,$files,$public,$ticket) {
        if((int)$post_id!==$id||$meta_key!==$key||$check!==null)return $check;
        int67_register($state,$files,$id,$ticket,$public);
        // Reuse the ID after an ACK-loss or duplicate invocation: the set
        // remains unchanged. The old caller transaction must hold this row
        // lock until it COMMITs or ROLLBACKs; it is also released on KILL.
        int67_register($state,$files,$id,$ticket,$public);
        global $wpdb;
        $locked=$wpdb->get_var($wpdb->prepare(
            "SELECT revision FROM $state WHERE attachment_id=%d FOR UPDATE",$id));
        if($wpdb->last_error!==''||$locked===null) {
            throw new RuntimeException('caller lock lost after precommit fence');
        }
        return $check;
    };
}
if($phase==='setup') {
    @unlink($ready);@unlink($resume);
    $meta=wp_get_attachment_metadata($id,true);
    int67_check(is_array($meta)&&!isset($meta['sizes']['esp67_integrated'])&&
        get_post_meta($id,ESP_Media_Index::PROTECTION_META,true)==='path_http',
        'baseline protected owner canonical metadata');
    int67_check(ESP_Media_Index::resolve($file)['status']==='resolved',
        'baseline public image resolve');
    $db=int67_db();
    $found=$db->query("SHOW COLUMNS FROM $state LIKE 'esp67_mutation_ids'");
    int67_check($found->num_rows===0,'probe column not present before setup');
    $db->query("ALTER TABLE $state ADD COLUMN esp67_mutation_ids LONGTEXT NULL");
    $db->query("UPDATE $state SET esp67_mutation_ids='[]' WHERE attachment_id=$id");
    int67_check(int67_row($db,$state,$id)['ids']===[],
        'one added column initialized with empty mutation IDs');
    $db->close();
    exit(0);
}
if($phase==='restore') {
    $meta=wp_get_attachment_metadata($id,true);
    int67_check(is_array($meta),'canonical restoration metadata exists');
    if(isset($meta['sizes']['esp67_integrated'])) {
        unset($meta['sizes']['esp67_integrated']);
        int67_check(wp_update_attachment_metadata($id,$meta)!==false,
            'restore WordPress metadata through production hooks');
    }
    ESP_Media_Index::finish_noop_writes();
    int67_check(ESP_Media_Index::reindex_attachment($id),
        'production index rebuilt after test');
    $db=int67_db();
    int67_check(int67_row($db,$state,$id)['ids']===[],
        'all independent mutation IDs retired before cleanup');
    $db->query("ALTER TABLE $state DROP COLUMN esp67_mutation_ids");
    $db->close();
    $r=ESP_Media_Index::resolve($file);
    int67_check($r['status']==='resolved'&&($r['protected_path_id']??null)==='',
        'public owner restored; temp column removed');
    exit(0);
}
$meta=wp_get_attachment_metadata($id,true);
int67_check(is_array($meta)&&!isset($meta['sizes']['esp67_integrated']),
    'clean baseline before test');
$meta['sizes']['esp67_integrated']=[
    'file'=>$public,'width'=>150,'height'=>150,'mime-type'=>'image/jpeg'];
$ticket=$phase==='txhold'?'TX_LOCK':'ACK_LOSS';
int67_remove_legacy_hooks();
$guard=int67_guard($state,$files,$id,$key,$public,$ticket);
add_filter('update_post_metadata',$guard,3,5);
int67_check($wpdb->query('START TRANSACTION')!==false,'external caller transaction started');
if($phase==='disconnect') {
    $killed=false;
    $kill=static function($check,$post_id,$meta_key) use($id,$key,&$killed) {
        if((int)$post_id!==$id||$meta_key!==$key)return $check;
        global $wpdb;
        $original=(int)$wpdb->get_var('SELECT CONNECTION_ID()');
        if($original<1)throw new RuntimeException('Cannot identify caller session');
        $other=int67_db();
        $other->query('KILL CONNECTION '.$original);
        $other->close();$killed=true;
        return $check;
    };
    add_filter('update_post_metadata',$kill,7,5);
}
try {
    $ok=wp_update_attachment_metadata($id,$meta);
} finally {
    remove_filter('update_post_metadata',$guard,3);
    if($phase==='disconnect')remove_filter('update_post_metadata',$kill,7);
}
int67_check($ok!==false,'Core update_post_meta accepted the canonical mutation');
if($phase==='txhold') {
    // While caller TX has uncommitted wp_postmeta, an independent completion
    // MUST wait for its state-row FOR UPDATE lock rather than clearing the ID.
    int67_check(!int67_retire($state,$id,$ticket,1),
        'early independent retirement blocked by caller-owned TX row lock');
    $db=int67_db();
    $r=int67_row($db,$state,$id);
    int67_check($r['ids']===[$ticket] && $r['status']==='dirty',
        'fence remains dirty across caller transaction');
    $db->close();
    int67_check($wpdb->query('ROLLBACK')!==false,'caller transaction rolled back');
    $db=int67_db();
    $canonical=$db->query("SELECT meta_value FROM {$wpdb->postmeta}
        WHERE post_id=$id AND meta_key='_wp_attachment_metadata'
        ORDER BY meta_id LIMIT 1")->fetch_assoc();
    $db->close();
    $actual=$canonical?maybe_unserialize($canonical['meta_value']):null;
    int67_check(is_array($actual)&&!isset($actual['sizes']['esp67_integrated']),
        'uncommitted canonical mutation was rolled back');
    int67_check(int67_retire($state,$id,$ticket),
        'retire succeeds only after caller transaction releases state row');
    int67_check(ESP_Media_Index::reindex_attachment($id),
        'rebuild after rollback uses original canonical metadata');
    int67_check(ESP_Media_Index::resolve($file)['status']==='resolved',
        'public file authorization recovers after rollback');
    echo "ESP67_INTEGRATED_TX_LOCK_PROTECTED\n";exit(0);
}
int67_check($killed,'caller connection was killed inside real WP metadata filter');
$db=int67_db();
$canonical=$db->query("SELECT meta_value FROM {$wpdb->postmeta}
    WHERE post_id=$id AND meta_key='_wp_attachment_metadata'
    ORDER BY meta_id LIMIT 1")->fetch_assoc();
$actual=$canonical?maybe_unserialize($canonical['meta_value']):null;
$r=int67_row($db,$state,$id);
$count=$db->query("SELECT COUNT(*) AS n FROM $files WHERE attachment_id=$id
    AND path_hash='".hash('sha256',$public)."'")->fetch_assoc();
$db->close();
int67_check(is_array($actual)&&
    ($actual['sizes']['esp67_integrated']['file']??null)===$public,
    'Core reconnect committed canonical protected ownership');
int67_check($r['status']==='dirty'&&$r['ids']===[$ticket]&&
    (int)$count['n']>=1,'ID and candidate survived caller transaction loss');
int67_check(ESP_Media_Index::resolve($file)['status']==='error',
    'existing strict resolver denies while independent mutation is active');
file_put_contents($ready,'ready');
$until=microtime(true)+30;
while(!file_exists($resume)&&microtime(true)<$until)usleep(100000);
int67_check(file_exists($resume),'independent anonymous HTTP checked dirty guard');
int67_check(int67_retire($state,$id,$ticket),
    'independent completion retires only own ID once');
int67_check(int67_retire($state,$id,$ticket),'duplicate retirement has no effect');
int67_check(ESP_Media_Index::reindex_attachment($id),
    'index rebuilt from committed new collision after ID retirement');
int67_check(ESP_Media_Index::resolve($file)['status']==='ambiguous',
    'completed protected path collision is still denied');
echo "ESP67_INTEGRATED_DISCONNECT_PROTECTED\n";
