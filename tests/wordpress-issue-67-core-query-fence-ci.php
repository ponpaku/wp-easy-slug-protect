<?php
/**
 * #67: disposable actual WordPress wpdb query filter + server-side SQL token.
 * Only one verified Core UPDATE SQL shape is transformed; not production.
 */
declare(strict_types=1);
$phase=getenv('ESP67_QUERY_GATE_PHASE');
$case=getenv('ESP67_QUERY_GATE_CASE');
if(getenv('ESP_ALLOW_DISPOSABLE_DB')!=='1'||!getenv('ESP_WP_ROOT')||
 !in_array($phase,['setup','writer','reap','verify','restore'],true)||
 !in_array($case,['stale','normal'],true))exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require rtrim(getenv('ESP_WP_ROOT'),'/').'/wp-load.php';
$_SERVER['SERVER_SOFTWARE']='Apache/2.4';
if(strpos(DB_NAME,'esp_')!==0||!class_exists('ESP_Media_Index')||
 !ESP_Media_Index::enabled()||!ESP_Media_Index::ready())
 throw new RuntimeException('ESP67 SQL gate needs disposable strict-ready WordPress');
global $wpdb;
$id=(int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE
 post_type='attachment' AND post_title=%s LIMIT 1",'ESP31 protected JPEG'));
if(!$id)throw new RuntimeException('missing protected fixture');
$state=ESP_Media_Index::state_table();
$files=ESP_Media_Index::files_table();
$public='esp31-public-150x150.jpg';
$path=wp_upload_dir()['basedir'].'/'.$public;
$root=wp_upload_dir()['basedir'].'/.esp67-query-gate';
wp_mkdir_p($root);
$started="$root/started";$resume="$root/resume";$completed="$root/completed";
function qg67_check(bool $ok,string $msg):void {
 if(!$ok)throw new RuntimeException('ESP67 WP query gate: '.$msg);
 echo "PASS ESP67 WP query gate: $msg\n";
}
function qg67_db():mysqli{return new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);}
function qg67_status(string $state,int $id):array {
 $db=qg67_db();
 $r=$db->query("SELECT status,revision,pending_count,esp67_sql_gate FROM $state
 WHERE attachment_id=$id")->fetch_assoc();$db->close();
 if(!$r)throw new RuntimeException('missing state');
 return $r;
}
if($phase==='setup') {
 foreach([$started,$resume,$completed] as $f)@unlink($f);
 $m=wp_get_attachment_metadata($id,true);
 qg67_check(is_array($m)&&!isset($m['sizes']['esp67_sql_gate']),
  'original canonical owner has no new derivative');
 qg67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
  'original public image is indexed');
 $db=qg67_db();
 $db->query("ALTER TABLE $state ADD COLUMN esp67_sql_gate LONGTEXT NULL");
 $db->query("UPDATE $state SET esp67_sql_gate='[]' WHERE attachment_id=$id");
 $db->close();echo "ESP67_SQL_GATE_SETUP\n";exit(0);
}
if($phase==='reap') {
 qg67_check(file_exists($started),'real wpdb query filter paused before Core UPDATE');
 $db=qg67_db();$db->begin_transaction();
 $r=$db->query("SELECT revision,pending_count,esp67_sql_gate FROM $state
 WHERE attachment_id=$id FOR UPDATE")->fetch_assoc();
 qg67_check($r['esp67_sql_gate']==='["GATED"]' &&
  (int)$r['pending_count']===1,'pre-write token independently committed');
 $db->query("UPDATE $state SET revision=revision+1,status='dirty',
 pending_count=0,esp67_sql_gate='[]' WHERE attachment_id=$id");
 $db->commit();$db->close();
 qg67_check(ESP_Media_Index::reindex_attachment($id),
  'reaper rebuilt still-old canonical metadata');
 qg67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
  'old public owner available before late writer resumes');
 echo "ESP67_SQL_GATE_TOKEN_REAPED\n";exit(0);
}
if($phase==='verify') {
 qg67_check(file_exists($completed),'actual Core SQL was dispatched');
 $db=qg67_db();
 $row=$db->query("SELECT meta_value FROM {$wpdb->postmeta} WHERE
 post_id=$id AND meta_key='_wp_attachment_metadata'
 ORDER BY meta_id LIMIT 1")->fetch_assoc();$db->close();
 $m=$row?maybe_unserialize($row['meta_value']):null;
 $has=($m['sizes']['esp67_sql_gate']['file']??null)===$public;
 $s=qg67_status($state,$id);
 if($case==='stale') {
  qg67_check(!$has,'server-side token predicate denied resumed stale Core SQL');
  qg67_check($s['status']==='ready'&&(int)$s['pending_count']===0,
   'READY index consistent with unchanged canonical data');
  qg67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
   'public path remains available after token retirement');
  echo "ESP67_SQL_GATE_STALE_REPLAY_BLOCKED\n";
 }else{
  qg67_check($has,'valid token allowed normal Core UPDATE');
  qg67_check($s['status']==='dirty'&&(int)$s['pending_count']===1,
   'valid token remains until retirement');
  echo "ESP67_SQL_GATE_NORMAL_WRITE_OK\n";
 }
 exit(0);
}
if($phase==='restore') {
 $m=wp_get_attachment_metadata($id,true);
 if(isset($m['sizes']['esp67_sql_gate'])){
  unset($m['sizes']['esp67_sql_gate']);
  qg67_check(wp_update_attachment_metadata($id,$m)!==false,
   'restore original canonical metadata through unmodified WP API');
 }
 ESP_Media_Index::finish_noop_writes();
 $db=qg67_db();$r=qg67_status($state,$id);
 if($r['esp67_sql_gate']==='["GATED"]')
  $db->query("UPDATE $state SET status='dirty',revision=revision+1,
   pending_count=0,esp67_sql_gate='[]' WHERE attachment_id=$id");
 $db->close();
 qg67_check(ESP_Media_Index::reindex_attachment($id),
  'rebuild updated canonical media index');
 $db=qg67_db();$db->query("ALTER TABLE $state DROP COLUMN esp67_sql_gate");$db->close();
 qg67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
  'original public owner restored');
 echo "ESP67_SQL_GATE_RESTORED\n";exit(0);
}
$m=wp_get_attachment_metadata($id,true);
$m['sizes']['esp67_sql_gate']=[
 'file'=>$public,'width'=>150,'height'=>150,'mime-type'=>'image/jpeg'];
remove_filter('update_post_metadata',['ESP_Media_Index','guard_update_meta'],5);
remove_action('updated_post_meta',['ESP_Media_Index','on_meta_saved'],20);
$pre=static function($check,$post_id,$meta_key)use($id,$state,$files,$public){
 if((int)$post_id!==$id||$meta_key!=='_wp_attachment_metadata')return $check;
 $db=qg67_db();$db->begin_transaction();
 try{
  $r=$db->query("SELECT revision,pending_count,esp67_sql_gate FROM $state
   WHERE attachment_id=$id FOR UPDATE")->fetch_assoc();
  if($r['esp67_sql_gate']!=='[]')throw new RuntimeException('not clean');
  $rev=(int)$r['revision']+1;
  $db->query("UPDATE $state SET status='dirty',revision=$rev,pending_count=1,
   esp67_sql_gate='[\"GATED\"]' WHERE attachment_id=$id");
  $hash=hash('sha256',$public);$escaped=$db->real_escape_string($public);
  $db->query("INSERT INTO $files
   (path_hash,file_path,attachment_id,generation,protected_path_id)
   VALUES('$hash','$escaped',$id,$rev,'')");
  $db->commit();
 }catch(Throwable $e){$db->rollback();throw $e;}
 finally{$db->close();}
 return $check;
};
add_filter('update_post_metadata',$pre,3,5);
$rewrite=0;
$query_filter=static function($sql)use($wpdb,$state,$id,$case,$started,$resume,&$rewrite){
 $table=preg_quote($wpdb->postmeta,'/');
 if(!preg_match('/^UPDATE\s+(?:\x60)?'.$table.'(?:\x60)?\s+SET\s+(.+?)\s+WHERE\s+(.+)$/is',
  trim($sql),$hit))return $sql;
 if(!str_contains($hit[2],'meta_id'))return $sql;
 $rewrite++;
 // Same SQL must enforce ticket at MySQL commit, not just PHP precheck.
 $gated="UPDATE {$wpdb->postmeta} AS m
  INNER JOIN $state AS s ON s.attachment_id=$id
  SET ".$hit[1]." WHERE (".$hit[2].")
  AND s.status='dirty' AND s.pending_count=1
  AND BINARY s.esp67_sql_gate=BINARY '[\"GATED\"]'";
 if($case==='stale'){
  file_put_contents($started,'before-sql');
  $until=microtime(true)+50;
  while(!file_exists($resume)&&microtime(true)<$until)usleep(100000);
  qg67_check(file_exists($resume),'resumed Core query after token retirement');
 }
 echo "ESP67_SQL_GATE_REWROTE_CORE_UPDATE\n";
 return $gated;
};
add_filter('query',$query_filter,PHP_INT_MAX,1);
try{$ok=wp_update_attachment_metadata($id,$m);}
finally{
 remove_filter('query',$query_filter,PHP_INT_MAX);
 remove_filter('update_post_metadata',$pre,3);
}
qg67_check($rewrite>=1,'actual Core wpdb UPDATE passed through SQL gate');
echo 'ESP67_SQL_GATE_CORE_RETURN '.json_encode($ok)."\n";
file_put_contents($completed,'done');
echo "ESP67_SQL_GATE_WORKER_DONE\n";
