<?php
/**
 * #67 DESIGN experiment only: crash during attachment creation.
 *
 * Two possible coverage policies for an attachment with NO path-bearing
 * metadata are compared:
 * (A) existing audit demands a full canonical attachment => global dirty;
 * (B) synthetic zero-owner snapshot => global READY is permitted ONLY while
 *     SQL-time triggers atomically invalidate on the first path-bearing write.
 *
 * B's READY publication is test-only; this is NOT a complete product auditor.
 */
declare(strict_types=1);
$phase=getenv('ESP67_EMPTY_OWNER_PHASE');
if(getenv('ESP_ALLOW_DISPOSABLE_DB')!=='1'||!getenv('ESP_WP_ROOT')||
 !in_array($phase,['install','writer','inspect','snapshot','claim','complete','cleanup'],true))exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require rtrim(getenv('ESP_WP_ROOT'),'/').'/wp-load.php';
if(strpos(DB_NAME,'esp_')!==0||!class_exists('ESP_Media_Index')
 || !ESP_Media_Index::enabled()||!ESP_Media_Index::ready())
 throw new RuntimeException('ESP67 empty-owner requires disposable strict READY WP');
global $wpdb;
$state=ESP_Media_Index::state_table();
$files=ESP_Media_Index::files_table();
$root=wp_upload_dir()['basedir'].'/.esp67-empty-owner';
wp_mkdir_p($root);
$idfile=$root.'/id';$pidfile=$root.'/pid';$ready=$root.'/created';
$trPosts=$wpdb->prefix.'esp67_empty_posts_insert';
$trPostUpdate=$wpdb->prefix.'esp67_empty_posts_update';
$trMeta=$wpdb->prefix.'esp67_empty_meta_insert';
$trMetaUpdate=$wpdb->prefix.'esp67_empty_meta_update';
$trMetaDelete=$wpdb->prefix.'esp67_empty_meta_delete';
$public='esp31-public-150x150.jpg';
$path=wp_upload_dir()['basedir'].'/'.$public;
function eo67_assert($ok,string $desc):void {
 if(!$ok)throw new RuntimeException('ESP67 EMPTY OWNER: '.$desc);
 echo "PASS ESP67 EMPTY OWNER: $desc\n";
}
function eo67_query(mysqli $db,string $sql,string $desc):void {
 $ok=$db->query($sql);
 eo67_assert($ok!==false,$desc.': '.$db->error);
}
function eo67_db():mysqli {
 return new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);
}
function eo67_disable_production_hooks():void {
 foreach(['add_post_metadata'=>'guard_add_meta',
    'update_post_metadata'=>'guard_update_meta',
    'delete_post_metadata'=>'guard_delete_meta'] as $hook=>$fn) {
    remove_filter($hook,['ESP_Media_Index',$fn],5);
 }
 foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook){
    remove_action($hook,['ESP_Media_Index','on_meta_saved'],20);
 }
 remove_action('add_attachment',['ESP_Media_Index','on_attachment_created'],20);
 remove_action('pre_post_update',['ESP_Media_Index','guard_post_type_transition'],5);
 remove_action('delete_attachment',['ESP_Media_Index','on_attachment_deleting'],1);
 remove_action('deleted_post',['ESP_Media_Index','on_post_deleted'],20);
}
function eo67_global(string $state):string {
 global $wpdb;
 return (string)$wpdb->get_var("SELECT status FROM $state WHERE attachment_id=0");
}
function eo67_audit(string $state):void {
 for($i=0;$i<120;$i++){
    ESP_Media_Index::run_batch();
    if(eo67_global($state)==='ready')break;
 }
}
eo67_disable_production_hooks();
$id=is_file($idfile)?(int)file_get_contents($idfile):0;
if($phase==='install'){
 eo67_assert(ESP_Media_Index::resolve($path)['status']==='resolved','baseline public owner resolves');
 foreach([$idfile,$pidfile,$ready] as $f)@unlink($f);
 $db=eo67_db();
 $create=function(string $name,string $event,string $table,string $test)use($db,$state):void {
   $ddl="CREATE TRIGGER $name AFTER $event ON $table FOR EACH ROW BEGIN
      IF $test THEN
        UPDATE $state SET status='dirty',revision=revision+1,audit_cursor=0
          WHERE attachment_id=0;
      END IF;
    END";
   eo67_query($db,$ddl,'test-only trigger '.$name);
 };
 $create($trPosts,'INSERT',$wpdb->posts,"NEW.post_type='attachment'");
 $create($trPostUpdate,'UPDATE',$wpdb->posts,
   "OLD.post_type='attachment' OR NEW.post_type='attachment'");
 $key= $db->real_escape_string(ESP_Media_Index::PROTECTION_META);
 $k="('_wp_attached_file','_wp_attachment_metadata','$key')";
 $create($trMeta,'INSERT',$wpdb->postmeta,
   "NEW.meta_key IN $k");
 $create($trMetaUpdate,'UPDATE',$wpdb->postmeta,
   "OLD.meta_key IN $k OR NEW.meta_key IN $k");
 $create($trMetaDelete,'DELETE',$wpdb->postmeta,
   "OLD.meta_key IN $k");
 $db->close();
 echo "ESP67_EMPTY_OWNER_INSTALLED\n";exit(0);
}
if($phase==='writer'){
 eo67_assert($id===0,'no previous incomplete fixture');
 $insert=wp_insert_post(['post_type'=>'attachment','post_status'=>'inherit',
    'post_mime_type'=>'image/jpeg','post_title'=>'ESP67 empty-owner interrupted attachment'],true);
 eo67_assert(!is_wp_error($insert)&&(int)$insert>0,'Core inserted incomplete attachment');
 file_put_contents($idfile,(string)(int)$insert);
 file_put_contents($pidfile,(string)getmypid());
 file_put_contents($ready,'created');
 // External CI coordinator kills PHP with SIGKILL. No shutdown hook or
 // subsequent WordPress metadata call may run.
 while(true)usleep(100000);
}
eo67_assert($id>0&&get_post_type($id)==='attachment','incomplete attachment survived PHP death');
$db=eo67_db();
$metaCount=(int)$db->query("SELECT COUNT(*) AS n FROM {$wpdb->postmeta}
 WHERE post_id=$id AND meta_key IN
 ('_wp_attached_file','_wp_attachment_metadata','".
 $db->real_escape_string(ESP_Media_Index::PROTECTION_META)."')")->fetch_assoc()['n'];
$indexed=(int)$db->query("SELECT COUNT(*) AS n FROM $files
 WHERE attachment_id=$id")->fetch_assoc()['n'];
if($phase==='inspect'){
 eo67_assert($metaCount===0&&$indexed===0,
   'aborted attachment has no canonical path-bearing meta or index file claims');
 eo67_assert(eo67_global($state)==='dirty',
   'post INSERT trigger globally fences crashed incomplete attachment');
 eo67_audit($state);
 eo67_assert(eo67_global($state)==='dirty',
   'current full-attachment audit cannot publish READY without required metadata');
 eo67_assert(ESP_Media_Index::resolve($path)['status']==='error',
   'existing all-attachment policy keeps unrelated public image fail-closed');
 echo "ESP67_EMPTY_OWNER_EXISTING_POLICY_GLOBAL_STUCK\n";
 $db->close();exit(0);
}
if($phase==='snapshot'){
 // Simulate an alternative source-of-truth audit rule, NOT a product change:
 // a canonical attachment with zero path-bearing meta has zero owner claims.
 // This is safe only if EVERY subsequent ownership-carrying SQL is fenced
 // by DB triggers, and READY publication is revision-CAS protected.
 eo67_assert($metaCount===0&&$indexed===0,'zero-owner attachment classification established');
 $db->begin_transaction();
 try{
  $global=$db->query("SELECT revision,status FROM $state
    WHERE attachment_id=0 FOR UPDATE")->fetch_assoc();
  eo67_assert($global['status']==='dirty','global currently invalidated');
  $local=$db->query("SELECT revision,pending_count FROM $state
    WHERE attachment_id=$id FOR UPDATE")->fetch_assoc();
  if($local){
   eo67_assert((int)$local['pending_count']===0,'no incomplete owner ticket remains');
   eo67_query($db,"UPDATE $state SET status='ready',revision=revision+1,
     active_generation=revision+1,failure_count=0,retry_after=0
     WHERE attachment_id=$id",'test-only publish empty owner generation');
  }else{
   eo67_query($db,"INSERT INTO $state
      (attachment_id,status,revision,active_generation,pending_count,changed_at)
     VALUES($id,'ready',1,1,0,UNIX_TIMESTAMP())",
     'test-only publish empty owner generation');
  }
  eo67_query($db,"UPDATE $state SET status='ready',revision=revision+1,
   audit_cursor=0 WHERE attachment_id=0",
   'test-only global READY after zero-owner snapshot');
  $db->commit();
 }catch(Throwable $e){$db->rollback();throw $e;}
 eo67_assert(ESP_Media_Index::resolve($path)['status']==='resolved',
   'unrelated public image can be authorized after empty-owner snapshot');
 echo "ESP67_EMPTY_OWNER_SNAPSHOT_READY\n";
 $db->close();exit(0);
}
if($phase==='claim'){
 eo67_assert(eo67_global($state)==='ready','prior empty-owner snapshot is READY');
 eo67_assert(add_post_meta($id,'_wp_attached_file',$public,true)!==false,
   'later genuine WordPress Core metadata write introduces path claim');
 eo67_assert(eo67_global($state)==='dirty',
   'first path claim atomically invalidates global READY in AFTER trigger');
 eo67_assert(ESP_Media_Index::resolve($path)['status']==='error',
   'public image denied immediately after new competing path claim');
 echo "ESP67_EMPTY_OWNER_LATER_CLAIM_FENCED\n";
 $db->close();exit(0);
}
if($phase==='complete'){
 eo67_assert($metaCount===1,'only attached file claim exists before completion');
 eo67_assert(add_post_meta($id,'_wp_attachment_metadata',[
  'file'=>$public,'width'=>150,'height'=>150,'sizes'=>[]
 ],true)!==false,'completes canonical image metadata');
 $protectedKey=ESP_Media_Index::PROTECTION_META;
 $beforeProtection=$wpdb->get_results($wpdb->prepare(
   "SELECT meta_id,meta_value FROM {$wpdb->postmeta}
     WHERE post_id=%d AND meta_key=%s",$id,$protectedKey),ARRAY_A);
 $storedProtection=add_post_meta($id,$protectedKey,'path_http',true);
 if($storedProtection===false){
   echo 'ESP67_EMPTY_OWNER_PROTECTION_DIAG '.wp_json_encode([
     'key'=>$protectedKey,'existing'=>$beforeProtection,
     'last_error'=>$wpdb->last_error,
     'wpdb_insert_id'=>$wpdb->insert_id,
     'guard_add_meta'=>has_filter('add_post_metadata',
       ['ESP_Media_Index','guard_add_meta']),
     'hooks'=>array_keys($GLOBALS['wp_filter']??[])
   ])."\n";
 }
 eo67_assert($storedProtection!==false,'completes protected owner identity');
 eo67_audit($state);
 eo67_assert(eo67_global($state)==='ready','global returns READY after complete conflicting owner');
 eo67_assert(ESP_Media_Index::resolve($path)['status']==='ambiguous',
    'completed protected/public collision is denied rather than public 200');
 echo "ESP67_EMPTY_OWNER_COMPLETED_CLAIM_DENIED\n";
 $db->close();exit(0);
}
eo67_assert($phase==='cleanup','cleanup phase');
eo67_assert((int)wp_update_post(['ID'=>$id,'post_type'=>'post'],true)===$id,
 'demote synthetic owner before delete to preserve shared public file');
eo67_assert((bool)wp_delete_post($id,true),'remove synthetic owner with WordPress API');
eo67_audit($state);
eo67_assert(eo67_global($state)==='ready','global READY after orphan removal');
eo67_assert(ESP_Media_Index::resolve($path)['status']==='resolved',
 'original public owner restored');
foreach([$trMetaDelete,$trMetaUpdate,$trMeta,$trPostUpdate,$trPosts] as $trigger){
 eo67_query($db,"DROP TRIGGER IF EXISTS $trigger",'drop test-only trigger '.$trigger);
}
$db->close();
foreach([$idfile,$pidfile,$ready] as $f)@unlink($f);
echo "ESP67_EMPTY_OWNER_RESTORED\n";
