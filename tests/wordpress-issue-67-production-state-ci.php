<?php
/** #67: production implementation (not synthetic gate) WordPress E2E. */
declare(strict_types=1);
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT')) exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
if (strpos(DB_NAME,'esp_')!==0 || !ESP_Media_Index::ready()) {
    throw new RuntimeException('Disposable READY WordPress required');
}
global $wpdb;
$state=ESP_Media_Index::state_table();
$files=ESP_Media_Index::files_table();
$meta=$wpdb->postmeta;
$public=wp_upload_dir()['basedir'].'/esp31-public-150x150.jpg';
$id=(int)$wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'
      AND post_title=%s LIMIT 1",'ESP31 protected JPEG'));
function p67($ok,$message) {
    if (!$ok) throw new RuntimeException('ESP67 PRODUCT: '.$message);
    echo "PASS ESP67 PRODUCT: $message\n";
}
function p67row($id) {
    global $wpdb;
    $s=ESP_Media_Index::state_table();
    $r=$wpdb->get_row($wpdb->prepare(
        "SELECT status,revision,pending_count,active_mutation_ids FROM $s
         WHERE attachment_id=%d",$id),ARRAY_A);
    p67(is_array($r)&&$wpdb->last_error==='','state row readable '.$id);
    return $r;
}
function p67audit() {
    global $wpdb;
    $s=ESP_Media_Index::state_table();
    for($i=0;$i<120;$i++) {
        ESP_Media_Index::run_batch();
        if($wpdb->get_var("SELECT status FROM $s WHERE attachment_id=0")==='ready') return;
    }
    throw new RuntimeException('ESP67 PRODUCT: global READY did not recover');
}
p67($id>0&&is_file($public)&&ESP_Media_Index::resolve($public)['status']==='resolved',
    'canonical protected and unrelated public media ready');
p67(strpos($state,'_v1')===false&&strpos($files,'_v1')===false,
    'table names have no version suffix');
p67($wpdb->get_var($wpdb->prepare(
    "SHOW COLUMNS FROM $state LIKE %s",'active_mutation_ids'))==='active_mutation_ids',
    'existing state has one actor ID column');
$all=$wpdb->get_results('SHOW TRIGGERS',ARRAY_A);
$triggers=array_filter(is_array($all)?$all:[],static function($r)use($wpdb){
    return strpos($r['Trigger']??'','esp67_')===0 &&
        in_array($r['Table']??'',[$wpdb->posts,$wpdb->postmeta],true);
});
p67(count($triggers)>=6,'six production SQL-time triggers installed');
$old=$wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM $meta WHERE post_id=%d AND meta_key=%s LIMIT 1",
    $id,'_esp_media_protected_path_id'));
p67(is_string($old)&&$old!=='','protected meta exists');
$write=static function($value)use($wpdb,$meta,$id){
    p67($wpdb->query($wpdb->prepare(
        "UPDATE $meta SET meta_value=%s WHERE post_id=%d AND meta_key=%s",
        $value,$id,'_esp_media_protected_path_id'))!==false,'Core meta SQL committed');
};
try {
    p67(ESP_Media_Mutation_State::begin($id) &&
        ESP_Media_Mutation_State::begin($id),'two concurrent tokens registered');
    $ids=json_decode((string)p67row($id)['active_mutation_ids'],true);
    p67(is_array($ids)&&count($ids)===2&&$ids[0]!==$ids[1],
        'both token identities present in state alone');
    p67(ESP_Media_Mutation_State::finish($id),'retire token A');
    $remaining=json_decode((string)p67row($id)['active_mutation_ids'],true);
    p67(count($remaining)===1&&$remaining[0]===$ids[1] &&
        (int)p67row($id)['pending_count']===1,
        'retirement of A preserves B');
    p67(ESP_Media_Mutation_State::finish($id) &&
        !ESP_Media_Mutation_State::finish($id) &&
        (int)p67row($id)['pending_count']===0,
        'retirement of B is idempotent');
    p67(ESP_Media_Index::reindex_attachment($id,true),'rebuild after two tokens');
    echo "ESP67_PRODUCT_TWO_TOKENS_IDEMPOTENT\n";

    p67(ESP_Media_Mutation_State::begin($id),'active actor reservation');
    $write('esp67_product_changed');
    p67(p67row(0)['status']==='ready' &&
        ESP_Media_Index::resolve($public)['status']==='resolved',
        'valid actor preserves global READY');
    p67(ESP_Media_Mutation_State::finish($id) &&
        ESP_Media_Index::reindex_attachment($id,true),'active token retired');
    echo "ESP67_PRODUCT_LOCAL_ACTOR_READY\n";

    $write($old);
    p67(p67row(0)['status']==='dirty' &&
        ESP_Media_Index::resolve($public)['status']==='error',
        'expired actor SQL atomically revokes global READY');
    echo "ESP67_PRODUCT_EXPIRED_ACTOR_DIRTY\n";
    p67audit();
    p67(ESP_Media_Index::resolve($public)['status']==='resolved',
        'global audit recovers after stale SQL');

    p67($wpdb->query('SET @esp_media_mutation_actor=NULL')!==false,
        'actor session cleared');
    $write('esp67_missing_actor');
    p67(p67row(0)['status']==='dirty','missing actor SQL atomically dirties global');
    echo "ESP67_PRODUCT_MISSING_ACTOR_DIRTY\n";
    $write($old);
    p67audit();

    // Positive Core path change: the real WordPress metadata pre-hook must
    // reserve the complete candidate set and bind the exact sanitized value.
    // A harmless same-directory rename must remain local, not all-site DIRTY.
    $legitimatePath=get_post_meta($id,'_wp_attached_file',true);
    p67(is_string($legitimatePath) && $legitimatePath!=='',
        'original WordPress canonical path for local reservation');
    p67(update_post_meta($id,'_wp_attached_file',
        'esp67-product-local-proof.jpg')!==false,
        'normal WordPress Core path update succeeds under strict index');
    ESP_Media_Index::finish_noop_writes();
    p67(p67row(0)['status']==='ready' &&
        ESP_Media_Index::resolve($public)['status']==='resolved',
        'Core pre-reserved ownership change keeps unrelated public media READY');
    p67(ESP_Media_Index::reindex_attachment($id,true),
        'local Core path change can be independently audited');
    p67(update_post_meta($id,'_wp_attached_file',$legitimatePath)!==false,
        'restore actual original Core attached file');
    ESP_Media_Index::finish_noop_writes();
    p67(ESP_Media_Index::reindex_attachment($id,true) &&
        p67row(0)['status']==='ready',
        'restored Core path remains locally scoped');
    echo "ESP67_PRODUCT_CORE_PATH_PROOF_LOCAL_READY\n";

    // A direct path-changing SQL can execute on the SAME DB session while
    // a legitimate local actor is active. It has not run WordPress's
    // prospective-path reservation hook. Matching actor alone is NOT enough:
    // the trigger must globally invalidate before a colliding public path
    // could be served from the old index.
    $originalPath=$wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM $meta WHERE post_id=%d
         AND meta_key='_wp_attached_file' LIMIT 1",$id));
    p67(is_string($originalPath) && $originalPath!=='',
        'protected canonical attached path available');
    p67(ESP_Media_Mutation_State::begin($id),
        'valid local actor is active during untracked direct SQL');
    $newPath='esp31-public-150x150.jpg';
    p67($wpdb->query($wpdb->prepare(
        "UPDATE $meta SET meta_value=%s WHERE post_id=%d
         AND meta_key='_wp_attached_file'",$newPath,$id))!==false,
        'direct unreserved path change committed');
    p67(p67row(0)['status']==='dirty' &&
        ESP_Media_Index::resolve($public)['status']==='error',
        'valid actor alone cannot bypass prospective path reservation');
    echo "ESP67_PRODUCT_UNRESERVED_ACTOR_GLOBAL_FALLBACK\n";
    p67($wpdb->query($wpdb->prepare(
        "UPDATE $meta SET meta_value=%s WHERE post_id=%d
         AND meta_key='_wp_attached_file'",$originalPath,$id))!==false,
        'restore original attached path');
    p67(ESP_Media_Mutation_State::finish($id),
        'release direct SQL test token');
    p67audit();
    p67(ESP_Media_Index::resolve($public)['status']==='resolved',
        'original published coverage restored after path fallback');

    // Conversely, genuine WordPress metadata APIs run the prospective-path
    // hook and bind the exact sanitized value. Such a normal update must
    // retain the local-first path and not invalidate unrelated global READY.
    $oldMetadata=wp_get_attachment_metadata($id,true);
    p67(is_array($oldMetadata),'canonical image metadata is an array');
    $updatedMetadata=$oldMetadata;
    $updatedMetadata['esp67_payload_binding_probe']='ok';
    p67(wp_update_attachment_metadata($id,$updatedMetadata)!==false,
        'normal WordPress metadata update with prospective path fence succeeded');
    ESP_Media_Index::finish_noop_writes();
    p67(p67row(0)['status']==='ready' &&
        ESP_Media_Index::reindex_attachment($id,true),
        'reserved exact metadata update remains local and can rebuild');
    echo "ESP67_PRODUCT_RESERVED_METADATA_LOCAL_READY\n";
    p67(wp_update_attachment_metadata($id,$oldMetadata)!==false,
        'restore original canonical attachment metadata');
    ESP_Media_Index::finish_noop_writes();
    p67(ESP_Media_Index::reindex_attachment($id,true),
        'original metadata indexed after restoration');

    $new=wp_insert_post(['post_type'=>'attachment',
        'post_title'=>'ESP67 product no-owner probe',
        'post_status'=>'inherit','post_mime_type'=>'image/jpeg'],true);
    p67(!is_wp_error($new)&&(int)$new>0,'real Core attachment INSERT succeeded');
    $new=(int)$new;
    p67(p67row(0)['status']==='dirty',
        'unknown-ID creation is globally reserved');
    ESP_Media_Index::finish_global_creations();
    p67audit();
    p67(p67row($new)['status']==='ready' &&
        ESP_Media_Index::resolve($public)['status']==='resolved',
        'audited zero-claim owner does not strand site');
    echo "ESP67_PRODUCT_EMPTY_OWNER_READY\n";
    wp_delete_attachment($new,true);
    p67audit();
} finally {
    $wpdb->query('SET @esp_media_mutation_actor=NULL');
    $write($old);
    p67audit();
}
echo "ESP67_PRODUCT_STATE_ONLY_IMPLEMENTATION_VERIFIED\n";
