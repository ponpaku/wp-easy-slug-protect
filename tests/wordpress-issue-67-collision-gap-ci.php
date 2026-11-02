<?php
/**
 * #67 disposable real WordPress collision after caller-owned transaction loss.
 *
 * The already-protected attachment gains a derivative path that belongs to a
 * public attachment. ESP's pre-hook marks dirty and reserves a candidate,
 * but both are inside the caller's uncommitted transaction. Kill that session
 * AFTER the pre-hook, BEFORE the WordPress canonical UPDATE; wpdb reconnects
 * and writes only wp_postmeta. A separate HTTP request then tests if the old
 * public owner is incorrectly authorized.
 *
 * Modes:
 *  vulnerable: existing production code, no out-of-band fence
 *  fenced:     an INDEPENDENT committed state dirty marker and candidate
 *              reservation (test only), created BEFORE the main session kill.
 *  restore:    restore metadata and reindex using ordinary WP APIs
 *
 * This is a diagnostic, not a production fix; no permanent schema changes.
 */
declare(strict_types=1);
$mode = getenv('ESP67_COLLISION_PHASE');
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT') ||
    !in_array($mode, ['vulnerable','fenced','restore'], true) ||
    !defined('STDOUT')) { exit(1); }
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
$_SERVER['SERVER_SOFTWARE']='Apache/2.4';
if (strpos(DB_NAME,'esp_')!==0 || !class_exists('ESP_Media_Index') ||
    !ESP_Media_Index::enabled() || !ESP_Media_Index::ready()) {
    throw new RuntimeException('ESP67 collision requires disposable strict READY WordPress');
}
global $wpdb;
$id=(int)$wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_title=%s LIMIT 1",
    'ESP31 protected JPEG'));
if ($id<1 || get_post_meta($id,ESP_Media_Index::PROTECTION_META,true)!=='path_http') {
    throw new RuntimeException('Missing protected owner');
}
$state=ESP_Media_Index::state_table();
$files=ESP_Media_Index::files_table();
$public='esp31-public-150x150.jpg';
$root=wp_upload_dir()['basedir'].'/.esp67-collision';
wp_mkdir_p($root);
$barrier=$root.'/injected';
$resume=$root.'/resume';
$path=wp_upload_dir()['basedir'].'/'.$public;
function esp67_require($yes,$reason) {
    if (!$yes) { throw new RuntimeException('ESP67 COLLISION: '.$reason); }
    echo "PASS ESP67 COLLISION: $reason\n";
}
function esp67_direct_conn(): mysqli {
    return new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);
}
if ($mode==='restore') {
    esp67_require(file_exists($resume),'HTTP witness signaled');
    $meta=wp_get_attachment_metadata($id,true);
    esp67_require(is_array($meta),'restoration has canonical metadata');
    if (isset($meta['sizes']['esp67_collision'])) {
        unset($meta['sizes']['esp67_collision']);
        esp67_require(wp_update_attachment_metadata($id,$meta)!==false,
            'restore canonical metadata via WordPress API');
    }
    ESP_Media_Index::finish_noop_writes();
    esp67_require(ESP_Media_Index::reindex_attachment($id),
        'rebuild canonical protected owner');
    $resolved=ESP_Media_Index::resolve($path);
    esp67_require($resolved['status']==='resolved' &&
        ($resolved['protected_path_id']??null)==='',
        'restoration leaves independent public owner');
    echo "ESP67_COLLISION_RESTORED\n";
    exit(0);
}
@unlink($barrier);
@unlink($resume);
$meta=wp_get_attachment_metadata($id,true);
esp67_require(is_array($meta)&&!isset($meta['sizes']['esp67_collision']),
    'protected owner starts without public-file candidate');
$original=ESP_Media_Index::resolve($path);
esp67_require($original['status']==='resolved' &&
    ($original['protected_path_id']??null)==='',
    'baseline public image is indexed as public');
$meta['sizes']['esp67_collision']=[
    'file'=>$public,'width'=>150,'height'=>150,'mime-type'=>'image/jpeg'
];
$pre_seen=false;$killed=false;$out_of_band=false;
if ($mode==='fenced') {
    // Proof of a separately COMMITTED fence and candidate reservation before
    // the normal ESP priority-5 guard obtains a state row lock inside the
    // caller-owned transaction. Doing this at priority 7 is TOO LATE and
    // deadlocks; do not translate this diagnostic directly into production.
    $independent_guard=static function($check,$post_id,$meta_key) use (
        $id,$state,$files,$public,&$out_of_band
    ) {
        if ((int)$post_id!==$id || $meta_key!=='_wp_attachment_metadata')return $check;
        $conn=esp67_direct_conn();
        $conn->begin_transaction();
        try {
            $row=$conn->query("SELECT revision FROM $state WHERE attachment_id=".(int)$id.
                ' FOR UPDATE')->fetch_assoc();
            if (!$row)throw new RuntimeException('No state row for durable fence');
            $revision=(int)$row['revision']+1;
            $conn->query("UPDATE $state SET status='dirty',
                revision=$revision,pending_count=pending_count+1 WHERE attachment_id=".(int)$id);
            $hash=hash('sha256',$public);
            $st=$conn->prepare("INSERT INTO $files
                (path_hash,file_path,attachment_id,generation,protected_path_id)
                VALUES(?,?,?,?,'')");
            $st->bind_param('ssii',$hash,$public,$id,$revision);
            $st->execute();
            $conn->commit();
            $out_of_band=true;
        } catch(Throwable $e) {
            $conn->rollback();
            throw $e;
        } finally { $conn->close(); }
        return $check;
    };
    add_filter('update_post_metadata',$independent_guard,3,5);
}
$probe=static function($check,$post_id,$meta_key) use(
    $id,$state,$files,$public,$mode,&$pre_seen,&$killed,&$out_of_band,$wpdb
) {
    if ((int)$post_id!==$id||$meta_key!=='_wp_attachment_metadata')return $check;
    $s=$wpdb->get_row($wpdb->prepare(
        "SELECT status,pending_count,revision FROM $state WHERE attachment_id=%d",
        $id),ARRAY_A);
    $candidate=$wpdb->get_var($wpdb->prepare(
        "SELECT attachment_id FROM $files WHERE attachment_id=%d AND path_hash=%s
         ORDER BY generation DESC LIMIT 1", $id,hash('sha256',$public)));
    if (!is_array($s)||$s['status']!=='dirty'||(int)$s['pending_count']<1||
        (int)$candidate!==$id){
        throw new RuntimeException('pre-hook did not stage collision under external transaction');
    }
    $pre_seen=true;
    $session=(int)$wpdb->get_var('SELECT CONNECTION_ID()');
    $killer=esp67_direct_conn();
    $killer->query('KILL CONNECTION '.$session);
    $killer->close();
    $killed=true;
    return $check;
};
esp67_require($wpdb->query('START TRANSACTION')!==false,
    'external transaction started');
add_filter('update_post_metadata',$probe,7,5);
try { $updated=wp_update_attachment_metadata($id,$meta); }
finally {
    remove_filter('update_post_metadata',$probe,7);
    if ($mode==='fenced')remove_filter('update_post_metadata',$independent_guard,3);
}
esp67_require($pre_seen&&$killed&&$updated!==false,
    'actual Core write returned success after forced reconnection');
$independent=esp67_direct_conn();
$meta_sql=$wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d
     AND meta_key='_wp_attachment_metadata' ORDER BY meta_id LIMIT 1",$id);
$canonical=$independent->query($meta_sql)->fetch_assoc();
$state_sql=$wpdb->prepare(
    "SELECT status,revision,pending_count FROM $state WHERE attachment_id=%d",$id);
$status=$independent->query($state_sql)->fetch_assoc();
$absence_sql=$wpdb->prepare("SELECT COUNT(*) AS n FROM $files
    WHERE attachment_id=%d AND path_hash=%s",$id,hash('sha256',$public));
$presence=$independent->query($absence_sql)->fetch_assoc();
$independent->close();
$actual=is_array($canonical)?maybe_unserialize($canonical['meta_value']):null;
$has_path=is_array($actual)&&
    ($actual['sizes']['esp67_collision']['file']??null)===$public;
esp67_require($has_path,'protected collision persisted in canonical WP metadata');
echo 'ESP67_COLLISION_STATE '.wp_json_encode([
    'mode'=>$mode,'state'=>$status,'protected_candidate_index_count'=>(int)$presence['n'],
    'resolver'=>ESP_Media_Index::resolve($path),
    'out_of_band_fence_committed'=>$out_of_band
])."\n";
file_put_contents($barrier,'ready');
$end=microtime(true)+30;
while(!file_exists($resume)&&microtime(true)<$end)usleep(100000);
esp67_require(file_exists($resume),'HTTP witness completed while injecting worker alive');
echo "ESP67_COLLISION_WITNESS_COMPLETE\n";
