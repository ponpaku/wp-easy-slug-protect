<?php
/**
 * Issue #67 disposable state-only hybrid authorization prototype.
 *
 * Exactly ONE test-only column is added to the EXISTING state table. No gate,
 * mutation, event, lock or other auxiliary SQL table is created.
 * Six DB triggers validate session actor IDs against that column; a missing
 * or revoked actor co-commits global dirty with the canonical WordPress SQL.
 *
 * Exercise real Core local metadata/posts, stale actor, missing actor and
 * INSERT -> UPDATE -> metadata for an attachment whose ID is initially
 * unknown to the PHP caller. This is NOT production integration.
 */
declare(strict_types=1);
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT')) {
    exit(1);
}
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
if (strpos(DB_NAME, 'esp_') !== 0 || !class_exists('ESP_Media_Index') ||
    !ESP_Media_Index::enabled() || !ESP_Media_Index::ready()) {
    throw new RuntimeException('ESP67 state-only prototype requires disposable READY strict WordPress');
}
global $wpdb;
$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$state = ESP_Media_Index::state_table();
$files = ESP_Media_Index::files_table();
$posts = $wpdb->posts;
$meta = $wpdb->postmeta;
$col = 'esp67_proto_actor_ids';
$prefix = $wpdb->prefix . 'esp67_so_';
$key = '_esp67_state_proto_probe';
$created = 0;
$createdFile = null;
$mid = 0;
$names = [];
$columnAdded = false;
$previous = $wpdb->suppress_errors(true);
$base = wp_upload_dir()['basedir'];
$public = $base . '/esp31-public-150x150.jpg';
$protected = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM $posts WHERE post_type='attachment' AND post_title=%s LIMIT 1",
    'ESP31 protected JPEG'
));
function so67_assert(bool $yes, string $message): void {
    if (!$yes) { throw new RuntimeException('ESP67 STATE ONLY: ' . $message); }
    echo "PASS ESP67 STATE ONLY: $message\n";
}
function so67_sql(mysqli $db, string $sql, string $label): void {
    try {
        $worked = $db->query($sql);
    } catch (mysqli_sql_exception $e) {
        throw new RuntimeException('ESP67 STATE ONLY: ' . $label . ' / ' .
            $e->getCode() . ' ' . $e->getMessage(), 0, $e);
    }
    so67_assert($worked !== false, $label . ' ' . $db->error);
}
function so67_state(mysqli $db, string $state, int $id): array {
    $result = $db->query("SELECT status,revision,pending_count,
        esp67_proto_actor_ids AS actors FROM $state WHERE attachment_id=$id");
    $row = $result ? $result->fetch_assoc() : null;
    so67_assert(is_array($row), 'state row found for ' . $id);
    return $row;
}
function so67_actor($wpdb, ?string $token): void {
    $sql = $token === null ? 'SET @esp67_proto_actor=NULL' :
        $wpdb->prepare('SET @esp67_proto_actor=%s', $token);
    so67_assert($wpdb->query($sql) !== false, 'real wpdb actor session set');
}
function so67_reserve(mysqli $db, string $state, int $id, string $token): void {
    $array = $db->real_escape_string(wp_json_encode([$token]));
    so67_sql($db, "UPDATE $state SET status='dirty',
      revision=revision+1,pending_count=1,esp67_proto_actor_ids='$array'
      WHERE attachment_id=$id AND pending_count=0",
      'one state row reserves actor for owner ' . $id);
    so67_assert($db->affected_rows === 1, 'state-only actor reservation is unique');
}
function so67_release(mysqli $db, string $state, int $id): void {
    so67_sql($db, "UPDATE $state SET pending_count=0,esp67_proto_actor_ids='[]'
      WHERE attachment_id=$id AND pending_count=1",
      'one state row retires actor for owner ' . $id);
    so67_assert($db->affected_rows === 1, 'state-only actor retirement is unique');
}
function so67_audit(mysqli $db, string $state): void {
    for ($i=0;$i<130;$i++) {
        ESP_Media_Index::run_batch();
        $row = so67_state($db,$state,0);
        if ($row['status'] === 'ready' && (int)$row['pending_count'] === 0) {
            return;
        }
    }
    throw new RuntimeException('ESP67 STATE ONLY: global audit did not return READY');
}
function so67_disable_prod_hooks(): void {
    foreach (['add_post_metadata'=>'guard_add_meta',
              'update_post_metadata'=>'guard_update_meta',
              'delete_post_metadata'=>'guard_delete_meta',
              'update_post_metadata_by_mid'=>'guard_by_mid',
              'delete_post_metadata_by_mid'=>'guard_delete_by_mid'] as $filter=>$method) {
        remove_filter($filter,['ESP_Media_Index',$method],5);
    }
    remove_action('update_postmeta',['ESP_Media_Index','reserve_sanitized_by_mid'],1);
    foreach (['added_post_meta','updated_post_meta','deleted_post_meta'] as $filter) {
        remove_action($filter,['ESP_Media_Index','on_meta_saved'],20);
    }
    remove_action('add_attachment',['ESP_Media_Index','on_attachment_created'],20);
    remove_action('pre_post_update',['ESP_Media_Index','guard_post_type_transition'],5);
    remove_action('delete_attachment',['ESP_Media_Index','on_attachment_deleting'],1);
    remove_action('deleted_post',['ESP_Media_Index','on_post_deleted'],20);
}
so67_assert($protected > 0, 'existing protected attachment is available');
so67_assert(ESP_Media_Index::resolve($public)['status'] === 'resolved',
    'unrelated public attachment initially resolves');
$originalTitle = get_the_title($protected);
try {
    so67_sql($db, "ALTER TABLE $state ADD COLUMN $col LONGTEXT NULL",
        'disposable ALTER of existing state (no new table)');
    $columnAdded = true;
    so67_sql($db, "UPDATE $state SET $col='[]' WHERE attachment_id IN (0,$protected)",
        'initialize actor sets on existing state rows');

    // A SQL trigger checks a local owner's active token, and optionally a
    // global token for operations whose ID did not exist when PHP reserved.
    // No separate gate table, trigger log table or event queue is consulted.
    $gate = static function(string $owner) use($state,$col): string {
        return "SELECT COUNT(*) INTO n FROM $state
          WHERE attachment_id=$owner AND status='dirty' AND pending_count>0
          AND IFNULL(JSON_CONTAINS(IFNULL($col,'[]'),
            JSON_QUOTE(@esp67_proto_actor)),0)=1 FOR UPDATE;
          IF n=0 AND $owner<>0 THEN
            SELECT COUNT(*) INTO n FROM $state
              WHERE attachment_id=0 AND status='dirty' AND pending_count>0
              AND IFNULL(JSON_CONTAINS(IFNULL($col,'[]'),
                JSON_QUOTE(@esp67_proto_actor)),0)=1 FOR UPDATE;
          END IF;
          IF n=0 THEN
            UPDATE $state SET status='dirty',revision=revision+1,
              audit_cursor=0 WHERE attachment_id=0;
          END IF;";
    };

    foreach (['INSERT','UPDATE','DELETE'] as $event) {
        $name = $prefix . 'posts_' . strtolower($event);
        $names[] = $name;
        $body = 'DECLARE n INT DEFAULT 0;';
        if ($event==='INSERT') {
            $body .= "IF NEW.post_type='attachment' THEN ".$gate('0')." END IF;";
        } elseif ($event==='DELETE') {
            $body .= "IF OLD.post_type='attachment' THEN ".$gate('OLD.ID')." END IF;";
        } else {
            $body .= "IF OLD.post_type='attachment' THEN ".$gate('OLD.ID')." END IF;";
            $body .= "IF OLD.post_type<>'attachment' AND NEW.post_type='attachment'
                THEN ".$gate('0')." END IF;";
        }
        so67_sql($db, "CREATE TRIGGER $name AFTER $event ON $posts
          FOR EACH ROW BEGIN $body END",
          "installed $event wp_posts trigger against state only");
    }
    $keys = "('_wp_attached_file','_wp_attachment_metadata',
      '_esp_media_protected_path_id','_esp67_state_proto_probe')";
    foreach (['INSERT','UPDATE','DELETE'] as $event) {
        $name = $prefix . 'meta_' . strtolower($event);
        $names[] = $name;
        $refs = $event==='UPDATE' ? ['OLD','NEW'] :
            [$event==='DELETE' ? 'OLD' : 'NEW'];
        $body = 'DECLARE n INT DEFAULT 0;';
        foreach ($refs as $ref) {
            $body .= "IF $ref.meta_key IN $keys AND EXISTS
             (SELECT 1 FROM $posts WHERE ID=$ref.post_id
               AND post_type='attachment') THEN ".
               $gate("$ref.post_id").' END IF;';
        }
        so67_sql($db, "CREATE TRIGGER $name AFTER $event ON $meta
          FOR EACH ROW BEGIN $body END",
          "installed $event wp_postmeta OLD/NEW trigger against state only");
    }
    so67_disable_prod_hooks();
    echo "ESP67_STATE_ONLY_NO_AUXILIARY_TABLES\n";

    // 1. Valid local token: WordPress Core writes to protected metadata and
    // wp_posts while global READY remains intact.
    so67_reserve($db,$state,$protected,'ACTOR_LOCAL');
    so67_actor($wpdb,'ACTOR_LOCAL');
    $mid = add_post_meta($protected,$key,'local-1',true);
    so67_assert(is_numeric($mid) && (int)$mid>0,
        'valid actor WordPress Core postmeta INSERT succeeded');
    $mid = (int)$mid;
    so67_assert(update_metadata_by_mid('post',$mid,'local-2') !== false,
        'valid actor Core postmeta UPDATE succeeded');
    $renamed = wp_update_post(['ID'=>$protected,
        'post_title'=>$originalTitle.' state-only probe'],true);
    so67_assert((int)$renamed === $protected,
        'valid actor WordPress Core posts UPDATE succeeded');
    so67_assert((int)wp_update_post(['ID'=>$protected,
        'post_title'=>$originalTitle],true)===$protected,
        'original attachment title restored during local actor reservation');
    so67_assert(so67_state($db,$state,0)['status']==='ready' &&
        ESP_Media_Index::resolve($public)['status']==='resolved',
        'valid local Core writes leave global coverage READY');
    so67_release($db,$state,$protected);
    so67_assert(ESP_Media_Index::reindex_attachment($protected,true),
        'released local actor can rebuild owner from canonical WordPress state');
    echo "ESP67_STATE_ONLY_VALID_ACTOR_LOCAL_READY\n";

    // 2. Stale SQL: a PHP-side ticket was safely retired and reindexed;
    // its old session token must no longer authorize later Core SQL.
    so67_reserve($db,$state,$protected,'ACTOR_EXPIRED');
    so67_actor($wpdb,'ACTOR_EXPIRED');
    so67_release($db,$state,$protected);
    so67_assert(ESP_Media_Index::reindex_attachment($protected,true),
        'old actor ticket retired and owner READY before late SQL');
    so67_assert(update_metadata_by_mid('post',$mid,'expired')!==false,
        'WordPress Core SQL with stale session actor still commits');
    so67_assert(so67_state($db,$state,0)['status']==='dirty' &&
        ESP_Media_Index::resolve($public)['status']==='error',
        'expired state actor co-commits global dirty and stops public HTTP index');
    echo "ESP67_STATE_ONLY_EXPIRED_ACTOR_ATOMIC_DIRTY\n";
    so67_audit($db,$state);
    so67_assert(ESP_Media_Index::resolve($public)['status']==='resolved',
        'full audit recovers public attachment after stale actor fallback');

    // 3. Session actor lost, as on wpdb reconnect: SQL may commit, but a
    // missing actor may not retain global READY.
    so67_actor($wpdb,null);
    so67_assert(update_metadata_by_mid('post',$mid,'missing')!==false,
        'Core postmeta UPDATE commits with absent session actor');
    so67_assert(so67_state($db,$state,0)['status']==='dirty' &&
        ESP_Media_Index::resolve($public)['status']==='error',
        'missing session actor co-commits global dirty');
    echo "ESP67_STATE_ONLY_MISSING_ACTOR_ATOMIC_DIRTY\n";
    so67_audit($db,$state);

    // 4. New attachment ID is unknown before wp_insert_post() returns.
    // Reserve a global actor ON STATE ROW ZERO (not in another table).
    so67_reserve($db,$state,0,'ACTOR_CREATE');
    so67_actor($wpdb,'ACTOR_CREATE');
    $beforeRevision=(int)so67_state($db,$state,0)['revision'];
    $insert=wp_insert_post(['post_type'=>'attachment',
        'post_title'=>'ESP67 state-only prototype attachment',
        'post_status'=>'inherit','post_mime_type'=>'image/jpeg'],true);
    so67_assert(!is_wp_error($insert) && (int)$insert>0,
        'WordPress Core attachment INSERT plus internal UPDATE succeeded');
    $created=(int)$insert;
    $relative='esp67-state-only-'.$created.'.jpg';
    $createdFile=$base.'/'.$relative;
    so67_assert(copy($public,$createdFile),
        'disposable new owner has real image file');
    so67_assert(add_post_meta($created,'_wp_attached_file',$relative,true)!==false,
        'new owner canonical _wp_attached_file Core INSERT succeeds');
    so67_assert(add_post_meta($created,'_wp_attachment_metadata',[
      'file'=>$relative,'width'=>150,'height'=>150,'sizes'=>[]
    ],true)!==false,
        'new owner canonical image metadata Core INSERT succeeds');
    $afterRevision=(int)so67_state($db,$state,0)['revision'];
    so67_assert($afterRevision===$beforeRevision,
        'global state actor covers Core INSERT, internal UPDATE and metadata without fallback');
    so67_assert(so67_state($db,$state,0)['status']==='dirty',
        'global creation token keeps all delivery fail-closed until completion');
    so67_release($db,$state,0);
    so67_audit($db,$state);
    so67_assert(ESP_Media_Index::resolve($createdFile)['status']==='resolved',
        'new attachment can be indexed from canonical metadata with no gate table');
    so67_assert(ESP_Media_Index::resolve($public)['status']==='resolved',
        'global audit republishes unrelated public owner');
    echo "ESP67_STATE_ONLY_NEW_ATTACHMENT_GLOBAL_TOKEN_RECOVERED\n";

} finally {
    // Strictly disposable SQL changes. Test-only triggers/column are
    // removed even if an assertion above fails.
    $wpdb->query('SET @esp67_proto_actor=NULL');
    if ($created > 0) { wp_delete_attachment($created,true); }
    if ($mid > 0) { delete_metadata_by_mid('post',$mid); }
    if ($columnAdded) {
        for ($i=0;$i<130;$i++) {
            ESP_Media_Index::run_batch();
            $status=$db->query("SELECT status FROM $state WHERE attachment_id=0");
            if ($status && $status->fetch_assoc()['status']==='ready') break;
        }
    }
    foreach (array_reverse($names) as $name) {
        $db->query("DROP TRIGGER IF EXISTS $name");
    }
    if ($columnAdded) {
        $db->query("ALTER TABLE $state DROP COLUMN $col");
    }
    $wpdb->suppress_errors($previous);
    if (is_string($createdFile) && is_file($createdFile)) @unlink($createdFile);
    $db->close();
}
so67_assert(ESP_Media_Index::resolve($public)['status']==='resolved',
    'prototype removed SQL test objects and restored public owner');
echo "ESP67_STATE_ONLY_ONE_COLUMN_NO_NEW_TABLES_VERIFIED\n";
