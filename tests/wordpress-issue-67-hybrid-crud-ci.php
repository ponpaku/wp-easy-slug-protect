<?php
/**
 * #67 disposable real WordPress hybrid trigger coverage.
 * SQL-time valid actor = local-only; missing actor = atomic global dirty.
 * This does not implement product path reservations or prove all WP APIs.
 */
declare(strict_types=1);
if(getenv('ESP_ALLOW_DISPOSABLE_DB')!=='1' || !getenv('ESP_WP_ROOT')) exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require rtrim(getenv('ESP_WP_ROOT'),'/').'/wp-load.php';
if(strpos(DB_NAME,'esp_')!==0 || !class_exists('ESP_Media_Index')
   || !ESP_Media_Index::enabled()) throw new RuntimeException('disposable strict WordPress only');
global $wpdb;
$previous=$wpdb->suppress_errors(true);
$db=new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);
$state=ESP_Media_Index::state_table();
$posts=$wpdb->posts;$meta=$wpdb->postmeta;
$gate=$wpdb->prefix.'esp67_hybrid_crud_gate_probe';
$names=[];$ids=[];
$key='_esp67_hybrid_crud_probe';
$marker='ESP67 hybrid crud '.str_replace('.','',(string)microtime(true));
function hc67_check(bool $ok,string $msg):void {
 if(!$ok)throw new RuntimeException('ESP67 HYBRID CRUD: '.$msg);
 echo "PASS ESP67 HYBRID CRUD: $msg\n";
}
function hc67_actor($wpdb,?string $actor):void {
 $sql=$actor===null?'SET @esp67_hybrid_crud_actor=NULL':
     $wpdb->prepare('SET @esp67_hybrid_crud_actor=%s',$actor);
 hc67_check($wpdb->query($sql)!==false,'wpdb session actor bound');
}
function hc67_sql(mysqli $db,string $sql,string $label):void {
 $ok=$db->query($sql);
 hc67_check($ok!==false,$label.' '.$db->error);
}
function hc67_global(mysqli $db,string $state):string {
 $r=$db->query("SELECT status FROM $state WHERE attachment_id=0")->fetch_assoc();
 return (string)($r['status']??'missing');
}
function hc67_audit(mysqli $db,string $state):void {
 for($i=0;$i<120;$i++){
  ESP_Media_Index::run_batch();
  if(hc67_global($db,$state)==='ready')break;
 }
 hc67_check(hc67_global($db,$state)==='ready','real global WordPress audit returned READY');
}
function hc67_expect_dirty(mysqli $db,string $state,string $label):void {
 hc67_check(hc67_global($db,$state)==='dirty',$label.' co-committed global dirty');
 $path=wp_upload_dir()['basedir'].'/esp31-public-150x150.jpg';
 hc67_check(ESP_Media_Index::resolve($path)['status']==='error',
    $label.' denies stale index lookups');
}
try {
 hc67_check(hc67_global($db,$state)==='ready','baseline global READY');
 hc67_sql($db,"CREATE TABLE $gate(owner_id BIGINT UNSIGNED PRIMARY KEY,
 actor VARBINARY(80) NOT NULL) ENGINE=InnoDB",'created disposable gate');
 hc67_sql($db,"INSERT INTO $gate VALUES(0,'ACTOR_A')",'global actor ready');
 // Disable only the existing index hooks in this disposable PHP process,
 // allowing the SQL-time trigger to be tested without a prior PHP veto.
 remove_action('add_attachment',['ESP_Media_Index','on_attachment_created'],20);
 remove_action('pre_post_update',['ESP_Media_Index','guard_post_type_transition'],5);
 remove_action('delete_attachment',['ESP_Media_Index','on_attachment_deleting'],1);
 remove_action('deleted_post',['ESP_Media_Index','on_post_deleted'],20);
 foreach(['INSERT','UPDATE','DELETE'] as $event){
  $name=$wpdb->prefix.'esp67_hc_posts_'.strtolower($event);
  $names[]=$name;
  if($event==='INSERT'){
   $condition="NEW.post_type='attachment'";$owner='0';
  }elseif($event==='DELETE'){
   $condition="OLD.post_type='attachment'";$owner='OLD.ID';
  }else{
   $condition="OLD.post_type='attachment' OR NEW.post_type='attachment'";
   $owner="IF(OLD.post_type='attachment',OLD.ID,0)";
  }
  $ddl="CREATE TRIGGER $name AFTER $event ON $posts FOR EACH ROW BEGIN
    DECLARE n INT DEFAULT 0;
    IF $condition THEN
      SELECT COUNT(*) INTO n FROM $gate WHERE owner_id=$owner
        AND actor=CAST(@esp67_hybrid_crud_actor AS BINARY) FOR UPDATE;
      IF n<>1 THEN
        UPDATE $state SET status='dirty',revision=revision+1,
          audit_cursor=0 WHERE attachment_id=0;
      END IF;
    END IF;
   END";
  hc67_sql($db,$ddl,"installed $event wp_posts hybrid fallback");
 }
 $keys="('_wp_attached_file','_wp_attachment_metadata',
    '_esp_media_protected_path_id','_esp67_hybrid_crud_probe')";
 foreach(['INSERT','UPDATE','DELETE'] as $event){
  $name=$wpdb->prefix.'esp67_hc_meta_'.strtolower($event);
  $names[]=$name;
  $refs=$event==='UPDATE'?['OLD','NEW']:[$event==='DELETE'?'OLD':'NEW'];
  $checks='';
  foreach($refs as $ref){
   $checks.="IF $ref.meta_key IN $keys AND EXISTS
     (SELECT 1 FROM $posts WHERE ID=$ref.post_id AND post_type='attachment') THEN
      SELECT COUNT(*) INTO n FROM $gate WHERE owner_id=$ref.post_id
        AND actor=CAST(@esp67_hybrid_crud_actor AS BINARY) FOR UPDATE;
      IF n<>1 THEN
        UPDATE $state SET status='dirty',revision=revision+1,
          audit_cursor=0 WHERE attachment_id=0;
      END IF;
    END IF;";
  }
  hc67_sql($db,"CREATE TRIGGER $name AFTER $event ON $meta
    FOR EACH ROW BEGIN DECLARE n INT DEFAULT 0; $checks END",
    "installed $event wp_postmeta OLD/NEW hybrid fallback");
 }
 hc67_actor($wpdb,'ACTOR_A');
 $attached=wp_insert_post(['post_type'=>'attachment','post_title'=>$marker.' attachment',
   'post_status'=>'inherit','post_mime_type'=>'image/jpeg'],true);
 hc67_check(!is_wp_error($attached) && (int)$attached>0,
   'Core attachment INSERT with actor succeeds');
 $attached=(int)$attached;$ids[]=$attached;
 hc67_sql($db,"INSERT INTO $gate VALUES($attached,'ACTOR_A')",
   'local actor available for newly created attachment');
 hc67_check(hc67_global($db,$state)==='ready',
   'valid attachment INSERT preserves global READY');
 $mid=add_post_meta($attached,$key,'before',true);
 hc67_check(is_numeric($mid) && (int)$mid>0,'valid Core metadata INSERT succeeds');
 $mid=(int)$mid;
 hc67_check(hc67_global($db,$state)==='ready',
   'valid protected-key INSERT preserves global READY');
 hc67_actor($wpdb,null);
 hc67_check(update_post_meta($attached,$key,'after')!==false,
   'missing actor Core UPDATE succeeds without hard-deny');
 hc67_expect_dirty($db,$state,'missing actor postmeta UPDATE');
 hc67_audit($db,$state);
 hc67_actor($wpdb,'ACTOR_A');
 hc67_check(update_metadata_by_mid('post',$mid,'moved',
   '_esp67_hybrid_crud_untracked')!==false,'by-mid valid rename succeeds');
 hc67_check(hc67_global($db,$state)==='ready',
   'by-mid valid OLD protected key preserves global READY');
 hc67_actor($wpdb,null);
 hc67_check(update_metadata_by_mid('post',$mid,'again',$key)!==false,
   'by-mid invalid rename INTO guarded key succeeds with fallback');
 hc67_expect_dirty($db,$state,'invalid by-mid rename into protected key');
 hc67_audit($db,$state);
 hc67_check(delete_metadata_by_mid('post',$mid)!==false,
   'missing actor Core meta DELETE succeeds with fallback');
 hc67_expect_dirty($db,$state,'invalid postmeta DELETE');
 hc67_audit($db,$state);
 hc67_check((int)wp_update_post(['ID'=>$attached,
   'post_title'=>$marker.' changed'],true)===$attached,
   'missing actor Core wp_posts UPDATE succeeds');
 hc67_expect_dirty($db,$state,'invalid wp_posts UPDATE');
 hc67_audit($db,$state);

 $post=wp_insert_post(['post_type'=>'post','post_title'=>$marker.' ordinary',
   'post_status'=>'draft'],true);
 hc67_check(!is_wp_error($post)&&(int)$post>0,'ordinary post created');
 $post=(int)$post;$ids[]=$post;
 hc67_check((int)wp_update_post(['ID'=>$post,'post_type'=>'attachment',
   'post_mime_type'=>'image/jpeg'],true)===$post,
   'missing actor post-to-attachment promotion succeeds');
 hc67_expect_dirty($db,$state,'invalid post-type promotion');
 hc67_audit($db,$state);
 hc67_sql($db,"INSERT INTO $gate VALUES($post,'ACTOR_A')",
   'local actor registered after test promotion');
 hc67_actor($wpdb,'ACTOR_A');
 hc67_check((int)wp_update_post(['ID'=>$post,'post_type'=>'post'],true)===$post,
   'valid actor attachment-to-post demotion succeeds');
 hc67_check(hc67_global($db,$state)==='ready',
   'valid demotion preserves global READY');

 hc67_actor($wpdb,null);
 $deleted=wp_delete_attachment($attached,true);
 hc67_check($deleted!==false,'missing actor Core attachment DELETE succeeds');
 $found=$db->query("SELECT ID FROM $posts WHERE ID=$attached")->fetch_assoc();
 hc67_check($found===null,'canonical attachment row deleted');
 hc67_expect_dirty($db,$state,'invalid wp_posts DELETE / Core multi-statement');
 hc67_audit($db,$state);
 echo "ESP67_HYBRID_FULL_CRUD_GLOBAL_FALLBACK_VERIFIED\n";
} finally {
 foreach(array_reverse($names) as $n)$db->query("DROP TRIGGER IF EXISTS $n");
 $db->query("DROP TABLE IF EXISTS $gate");
 $wpdb->suppress_errors($previous);
 foreach(array_reverse($ids) as $id) {
   if(get_post($id))wp_delete_post($id,true);
 }
 for($i=0;$i<120;$i++){
  ESP_Media_Index::run_batch();
  if(hc67_global($db,$state)==='ready')break;
 }
 $db->close();
}
