<?php
/**
 * #67: promotion from ordinary post to attachment is a new owner WITHOUT a
 * postmeta update. Test caller transaction rollback/WordPress reconnect and
 * compare per-owner legacy pre-hook against independently committed GLOBAL
 * dirty coverage. Disposable WordPress/HTTP only.
 */
declare(strict_types=1);
$phase=getenv('ESP67_PROMOTION_PHASE');
if(getenv('ESP_ALLOW_DISPOSABLE_DB')!=='1'||!getenv('ESP_WP_ROOT')||
    !in_array($phase,['prepare','vulnerable','global','inspect','restore'],true))exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require rtrim(getenv('ESP_WP_ROOT'),'/').'/wp-load.php';
$_SERVER['SERVER_SOFTWARE']='Apache/2.4';
if(strpos(DB_NAME,'esp_')!==0 || !class_exists('ESP_Media_Index')||
    !ESP_Media_Index::enabled()||!ESP_Media_Index::ready()) {
    // Restoring a global-dirty site is deliberately permitted.
    if(!in_array($phase,['inspect','restore'],true)||
        !ESP_Media_Index::enabled()) {
        throw new RuntimeException('ESP67 promotion requires disposable strict WP');
    }
}
global $wpdb;
$root=wp_upload_dir()['basedir'].'/.esp67-promotion';
wp_mkdir_p($root);
$idfile="$root/id";
$ready="$root/ready";
$resume="$root/resume";
$public='esp31-public-150x150.jpg';
$path=wp_upload_dir()['basedir'].'/'.$public;
$state=ESP_Media_Index::state_table();
$files=ESP_Media_Index::files_table();
function p67_check($result,string $message):void {
    if(!$result)throw new RuntimeException("ESP67 PROMOTION: $message");
    echo "PASS ESP67 PROMOTION: $message\n";
}
function p67_db():mysqli {
    return new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);
}
if($phase==='prepare'){
    @unlink($ready);@unlink($resume);
    p67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
        'public indexed owner exists before promotion');
    $id=wp_insert_post(['post_type'=>'post','post_title'=>'ESP67 ordinary promotion',
        'post_status'=>'publish','post_content'=>'Test-only owner']);
    p67_check(is_int($id)&&$id>0,'ordinary non-attachment post created');
    p67_check(add_post_meta($id,'_wp_attached_file',$public,true),
        'ordinary post has attachment filename metadata');
    p67_check(add_post_meta($id,'_wp_attachment_metadata',[
        'file'=>$public,'width'=>150,'height'=>150,'sizes'=>[]
    ],true),'ordinary post has derivative metadata');
    p67_check(add_post_meta($id,ESP_Media_Index::PROTECTION_META,
        'path_http',true),'ordinary post is marked protected but not an attachment');
    p67_check(get_post_type($id)==='post','not indexed as attachment yet');
    file_put_contents($idfile,(string)$id);
    echo "ESP67_PROMOTION_PREPARED $id\n";exit(0);
}
$id=is_file($idfile)?(int)file_get_contents($idfile):0;
p67_check($id>0,'test owner ID recorded');
if($phase==='inspect'){
    $db=p67_db();
    $type=$db->query("SELECT post_type FROM {$wpdb->posts} WHERE ID=$id")->fetch_assoc();
    $ctl=$db->query("SELECT status,revision FROM $state WHERE attachment_id=0")->fetch_assoc();
    $candidate=$db->query("SELECT COUNT(*) AS n FROM $files WHERE attachment_id=$id
        AND path_hash='".hash('sha256',$public)."'")->fetch_assoc();
    $db->close();
    echo 'ESP67_PROMOTION_INSPECT '.wp_json_encode([
        'type'=>$type,'control'=>$ctl,'candidate_count'=>(int)$candidate['n'],
        'public_owner_resolver'=>ESP_Media_Index::resolve($path)
    ])."\n";exit(0);
}
if($phase==='restore'){
    p67_check(file_exists($resume),'anonymous HTTP witness completed');
    $type=get_post_type($id);
    if($type==='attachment') {
        $restored=wp_update_post(['ID'=>$id,'post_type'=>'post']);
        p67_check((int)$restored===$id,'promotion reverted using real WP API');
    }
    ESP_Media_Index::finish_noop_writes();
    p67_check((bool)wp_delete_post($id,true),'temporary post deleted');
    // The per-owner candidate can remain until the batch orphan purge.
    $covered=false;
    for($i=0;$i<120;$i++) {
        ESP_Media_Index::run_batch();
        $ctl=$wpdb->get_row("SELECT status FROM $state WHERE attachment_id=0",ARRAY_A);
        if(is_array($ctl)&&$ctl['status']==='ready') {$covered=true;break;}
    }
    p67_check($covered,'global coverage READY after full WordPress audit');
    p67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
        'original public owner recovered after promotion rollback');
    @unlink($idfile);
    echo "ESP67_PROMOTION_RESTORED\n";exit(0);
}
@unlink($ready);@unlink($resume);
p67_check(get_post_type($id)==='post','promotion begins with ordinary post');
if($phase==='global') {
    $global=static function($id_param,$data)use($state,$id){
        if((int)$id_param!==$id||($data['post_type']??null)!=='attachment')return;
        $db=p67_db();$db->begin_transaction();
        try {
            $db->query("UPDATE $state SET status='dirty',revision=revision+1,
                audit_cursor=0 WHERE attachment_id=0");
            if($db->affected_rows!==1)
                throw new RuntimeException('independent global coverage fence failed');
            $db->commit();
        }catch(Throwable $e){$db->rollback();throw $e;}
        finally{$db->close();}
    };
    add_action('pre_post_update',$global,3,2);
}
$killed=false;
$kill=static function($target,$data)use($id,&$killed){
    if((int)$target!==$id||($data['post_type']??null)!=='attachment')return;
    global $wpdb;
    $connection=(int)$wpdb->get_var('SELECT CONNECTION_ID()');
    if($connection<=0)throw new RuntimeException('promotion caller connection missing');
    $db=p67_db();$db->query('KILL CONNECTION '.$connection);
    $db->close();$killed=true;
};
p67_check($wpdb->query('START TRANSACTION')!==false,
    'caller-owned transaction opened before promotion');
add_action('pre_post_update',$kill,7,2);
try {
    $res=wp_update_post(['ID'=>$id,'post_type'=>'attachment',
        'post_mime_type'=>'image/jpeg']);
} finally {
    remove_action('pre_post_update',$kill,7);
    if($phase==='global')remove_action('pre_post_update',$global,3);
}
p67_check($killed&&(int)$res===$id,'Core completed promotion after DB session kill');
$db=p67_db();
$type=$db->query("SELECT post_type FROM {$wpdb->posts} WHERE ID=$id")->fetch_assoc();
$ct=$db->query("SELECT status FROM $state WHERE attachment_id=0")->fetch_assoc();
$files_count=$db->query("SELECT COUNT(*) AS n FROM $files WHERE attachment_id=$id
    AND path_hash='".hash('sha256',$public)."'")->fetch_assoc();
$db->close();
p67_check($type['post_type']==='attachment',
    'canonical WP posts row contains newly promoted protected attachment');
echo 'ESP67_PROMOTION_STATE '.wp_json_encode([
    'mode'=>$phase,'control'=>$ct,'candidate_count'=>(int)$files_count['n'],
    'resolver'=>ESP_Media_Index::resolve($path)
])."\n";
file_put_contents($ready,'ready');
$until=microtime(true)+35;
while(!file_exists($resume)&&microtime(true)<$until)usleep(100000);
p67_check(file_exists($resume),'HTTP witness completed');
echo "ESP67_PROMOTION_HTTP_WITNESSED\n";
