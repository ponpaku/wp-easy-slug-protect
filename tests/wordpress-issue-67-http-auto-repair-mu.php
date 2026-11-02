<?php
/**
 * Test-only MU plugin. Disposed with the WordPress CI container.
 *
 * Try on-demand repairing the SINGLE fixture attachment when the media
 * HTTP request is about to pass through the existing ESP resolver. This is
 * NOT production code and must not be installed on any real WordPress site.
 */
defined('ABSPATH') || exit;
add_action('init', static function (): void {
    error_log('ESP67_HTTP_MU_INIT uri='.($_SERVER['REQUEST_URI']??'<none>'));
    if (strpos(DB_NAME,'esp_')!==0) return;
    if (!str_contains((string)($_SERVER['REQUEST_URI']??''),'esp31-public-150x150.jpg')) return;
    if (!class_exists('ESP_Media_Index')||!ESP_Media_Index::enabled()){
        error_log('ESP67_HTTP_MU_NO_INDEX_CLASS_OR_ENABLED');
        return;
    }
    global $wpdb;
    $id=(int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts}
        WHERE post_type='attachment' AND post_title=%s LIMIT 1",'ESP31 protected JPEG'));
    if($id<=0)return;
    $state=ESP_Media_Index::state_table();
    $r=$wpdb->get_row($wpdb->prepare("SELECT status,pending_count FROM $state
        WHERE attachment_id=%d",$id),ARRAY_A);
    if(!is_array($r)||$r['status']!=='dirty'||(int)$r['pending_count']!==1){
        error_log('ESP67_HTTP_MU_STATE '.wp_json_encode($r));
        return;
    }
    // setup() has to place this opt-in marker; no other environment may run it.
    $root=wp_upload_dir()['basedir'].'/.esp67-flock';
    if(!is_file("$root/enable-http-repair")){
        error_log('ESP67_HTTP_MU_MARKER_NOT_FOUND '.$root);
        return;
    }
    error_log('ESP67_HTTP_MU_ATTEMPT_REPAIR');
    $file="$root/".hash('sha256',DB_NAME."\0".$wpdb->prefix."\0".$id).'.lock';
    $lock=@fopen($file,'c+');
    if(!is_resource($lock)){
        error_log('ESP67_HTTP_MU_LOCK_OPEN_FAILED path='.$file);
        return;
    }
    if(!flock($lock,LOCK_EX|LOCK_NB)){
        error_log('ESP67_HTTP_MU_WRITER_STILL_ACTIVE');
        fclose($lock);return;
    }
    // The real product needs stable inode identity in state and must reject
    // replacement. The test fixture never replaces the lockfile.
    $db=new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);
    $db->query('SET SESSION innodb_lock_wait_timeout=1');
    $db->begin_transaction();
    try {
        $t=$db->query("SELECT pending_count,revision,esp67_flock_ids
            FROM $state WHERE attachment_id=$id FOR UPDATE")->fetch_assoc();
        if(!is_array($t)||$t['esp67_flock_ids']!=='["FLOCK"]'||
            (int)$t['pending_count']!==1)throw new RuntimeException('bad orphan ticket');
        $a=$db->query("SELECT ID FROM {$wpdb->posts} WHERE ID=$id FOR UPDATE");
        $b=$db->query("SELECT meta_id FROM {$wpdb->postmeta}
            WHERE post_id=$id AND meta_key IN ('_wp_attached_file',
            '_wp_attachment_metadata','_esp_protection') FOR UPDATE");
        if($a===false||$b===false)throw new RuntimeException('canonical lock blocked');
        $q=$db->query("UPDATE $state SET pending_count=0,esp67_flock_ids='[]',
            revision=revision+1,status='dirty' WHERE attachment_id=$id
            AND revision=".(int)$t['revision']);
        if($q===false||$db->affected_rows!==1)throw new RuntimeException('ticket CAS failed');
        $db->commit();
        if(!ESP_Media_Index::reindex_attachment($id))
            throw new RuntimeException('canonical reindex refused');
        error_log('ESP67_HTTP_AUTO_RECOVERY_COMPLETED');
    }catch(Throwable $e){
        $db->rollback();
        error_log('ESP67_HTTP_AUTO_RECOVERY_DEFERRED: '.$e->getMessage());
    }finally{
        $db->close();
        flock($lock,LOCK_UN);
        fclose($lock);
    }
},1);
