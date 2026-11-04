<?php
/**
 * #67 disposable WordPress experiment: can SQL-time triggers cover wp_posts
 * creation/promotion/update/delete and by-mid meta-key changes?
 *
 * This is NOT a production trigger implementation. The gate table and
 * identity are intentionally test-only; do not deploy this on a live site.
 */
declare(strict_types=1);
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT')) exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
if (strpos(DB_NAME, 'esp_') !== 0 || !class_exists('ESP_Media_Index')
    || !ESP_Media_Index::enabled()) {
    throw new RuntimeException('ESP67 coverage experiment requires disposable strict WordPress');
}
global $wpdb;
$previous = $wpdb->suppress_errors(true);
$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$gate = $wpdb->prefix . 'esp67_write_coverage_gate_probe';
$names = [];
$createdPosts = [];
$marker = 'ESP67 coverage ' . str_replace('.', '', (string)microtime(true));

function cov67_check($ok, string $message): void {
    if (!$ok) throw new RuntimeException('ESP67 COVERAGE: ' . $message);
    echo "PASS ESP67 COVERAGE: $message\n";
}
function cov67_actor($wpdb, ?string $actor): void {
    $q = $actor === null ? 'SET @esp67_coverage_actor=NULL'
        : $wpdb->prepare('SET @esp67_coverage_actor=%s', $actor);
    cov67_check($wpdb->query($q) !== false, 'session actor assigned');
}
function cov67_title(mysqli $db, string $posts, int $id): ?string {
    $result = $db->query("SELECT post_title FROM $posts WHERE ID=$id");
    $row = $result ? $result->fetch_assoc() : null;
    return $row === null ? null : (string)$row['post_title'];
}
function cov67_meta(mysqli $db, string $postmeta, int $mid): ?array {
    $result = $db->query("SELECT meta_key,meta_value FROM $postmeta WHERE meta_id=$mid");
    return $result ? $result->fetch_assoc() : null;
}
function cov67_sql(mysqli $db, string $sql, string $message): void {
    cov67_check($db->query($sql) !== false, $message . ': ' . $db->error);
}

try {
    cov67_sql($db, "CREATE TABLE $gate (
        owner_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        actor VARBINARY(80) NOT NULL
    ) ENGINE=InnoDB", 'created disposable actor gate');
    cov67_sql($db, "INSERT INTO $gate VALUES (0,'ACTOR_A')", 'global insert/promotion actor registered');

    // Trigger scope is all attachment post rows, independent of PHP hook timing.
    foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
        $name = $wpdb->prefix . 'esp67_cov_posts_' . strtolower($event);
        $names[] = $name;
        if ($event === 'INSERT') {
            $condition = "NEW.post_type='attachment'";
            $owner = '0'; // auto-increment attachment ID is not known beforehand.
        } elseif ($event === 'DELETE') {
            $condition = "OLD.post_type='attachment'";
            $owner = 'OLD.ID';
        } else {
            $condition = "(OLD.post_type='attachment' OR NEW.post_type='attachment')";
            // Promotion requires a global ticket; existing attachment updates
            // and demotions require a per-attachment ticket.
            $owner = "IF(OLD.post_type='attachment',OLD.ID,0)";
        }
        $ddl = "CREATE TRIGGER $name BEFORE $event ON {$wpdb->posts}
            FOR EACH ROW BEGIN
                DECLARE n INT DEFAULT 0;
                IF $condition THEN
                    SELECT COUNT(*) INTO n FROM $gate
                    WHERE owner_id=$owner
                      AND actor=CAST(@esp67_coverage_actor AS BINARY)
                    FOR UPDATE;
                    IF n<>1 THEN SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT='ESP67 posts actor denied';
                    END IF;
                END IF;
            END";
        cov67_sql($db, $ddl, "installed $event posts execution gate");
    }

    // Check OLD and NEW independently, so rename-away / rename-into a
    // guarded metadata key cannot bypass the SQL execution boundary.
    $keys = "('_wp_attached_file','_wp_attachment_metadata',
              '_esp_media_protected_path_id','_esp67_coverage_probe')";
    foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
        $name = $wpdb->prefix . 'esp67_cov_meta_' . strtolower($event);
        $names[] = $name;
        $refs = $event === 'UPDATE' ? ['OLD', 'NEW']
            : [$event === 'DELETE' ? 'OLD' : 'NEW'];
        $checks = '';
        foreach ($refs as $ref) {
            $checks .= "IF $ref.meta_key IN $keys AND EXISTS (
                    SELECT 1 FROM {$wpdb->posts}
                    WHERE ID=$ref.post_id AND post_type='attachment'
                ) THEN
                    SELECT COUNT(*) INTO n FROM $gate
                    WHERE owner_id=$ref.post_id
                      AND actor=CAST(@esp67_coverage_actor AS BINARY)
                    FOR UPDATE;
                    IF n<>1 THEN SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT='ESP67 postmeta actor denied';
                    END IF;
                END IF;";
        }
        $ddl = "CREATE TRIGGER $name BEFORE $event ON {$wpdb->postmeta}
            FOR EACH ROW BEGIN DECLARE n INT DEFAULT 0; $checks END";
        cov67_sql($db, $ddl, "installed $event postmeta OLD/NEW execution gate");
    }

    cov67_actor($wpdb, null);
    $normal = wp_insert_post([
        'post_type' => 'post', 'post_status' => 'draft',
        'post_title' => $marker . ' ordinary'
    ], true);
    cov67_check(!is_wp_error($normal) && (int)$normal>0,
        'non-attachment insert still works without actor');
    $normal = (int)$normal;
    $createdPosts[] = $normal;
    $updated = wp_update_post(['ID'=>$normal,'post_title'=>$marker.' ordinary updated'], true);
    cov67_check(!is_wp_error($updated)
        && cov67_title($db,$wpdb->posts,$normal)===$marker.' ordinary updated',
        'non-attachment update still works without actor');

    // Core should not be allowed to create an attachment with no global ID.
    $attempt = wp_insert_post([
        'post_type'=>'attachment','post_status'=>'inherit',
        'post_mime_type'=>'image/jpeg','post_title'=>$marker.' denied create'
    ], true);
    $denied = $db->query("SELECT COUNT(*) AS n FROM {$wpdb->posts}
        WHERE post_title='".$db->real_escape_string($marker.' denied create')."'")
        ->fetch_assoc();
    cov67_check((int)$denied['n']===0,
        'attachment INSERT without global actor has no canonical row');
    echo 'ESP67_COVERAGE_INSERT_RETURN ' .
        wp_json_encode(is_wp_error($attempt) ? $attempt->get_error_code() : $attempt) . "\n";

    cov67_actor($wpdb, 'ACTOR_A');
    $attachment = wp_insert_post([
        'post_type'=>'attachment','post_status'=>'inherit',
        'post_mime_type'=>'image/jpeg','post_title'=>$marker.' attachment'
    ], true);
    cov67_check(!is_wp_error($attachment) && (int)$attachment>0,
        'attachment INSERT with global actor succeeds');
    $attachment = (int)$attachment;
    $createdPosts[] = $attachment;
    cov67_sql($db, "INSERT INTO $gate VALUES ($attachment,'ACTOR_A')",
        'per-attachment actor registered');

    cov67_actor($wpdb, null);
    wp_update_post(['ID'=>$attachment,'post_title'=>$marker.' denied update'], true);
    cov67_check(cov67_title($db,$wpdb->posts,$attachment)===$marker.' attachment',
        'attachment UPDATE without per-owner actor rejected');
    cov67_actor($wpdb, 'ACTOR_A');
    wp_update_post(['ID'=>$attachment,'post_title'=>$marker.' updated'], true);
    cov67_check(cov67_title($db,$wpdb->posts,$attachment)===$marker.' updated',
        'attachment UPDATE with owner actor succeeds');

    $mid = add_post_meta($attachment,'_esp67_coverage_probe','old',false);
    cov67_check(is_numeric($mid) && (int)$mid>0,
        'Core metadata INSERT with valid owner actor succeeds');
    $mid = (int)$mid;
    cov67_actor($wpdb, null);
    update_metadata_by_mid('post',$mid,'denied','_esp67_coverage_outside');
    $row = cov67_meta($db,$wpdb->postmeta,$mid);
    cov67_check($row!==null && $row['meta_key']==='_esp67_coverage_probe'
        && $row['meta_value']==='old',
        'by-mid rename AWAY from guarded key denied without actor');
    cov67_actor($wpdb,'ACTOR_A');
    update_metadata_by_mid('post',$mid,'outside','_esp67_coverage_outside');
    $row = cov67_meta($db,$wpdb->postmeta,$mid);
    cov67_check($row!==null && $row['meta_key']==='_esp67_coverage_outside'
        && $row['meta_value']==='outside',
        'by-mid rename away from guarded key succeeds with owner actor');
    cov67_actor($wpdb,null);
    update_metadata_by_mid('post',$mid,'denied','_esp67_coverage_probe');
    $row = cov67_meta($db,$wpdb->postmeta,$mid);
    cov67_check($row!==null && $row['meta_key']==='_esp67_coverage_outside'
        && $row['meta_value']==='outside',
        'by-mid rename INTO guarded key denied without actor');
    cov67_actor($wpdb,'ACTOR_A');
    update_metadata_by_mid('post',$mid,'restored','_esp67_coverage_probe');
    $row = cov67_meta($db,$wpdb->postmeta,$mid);
    cov67_check($row!==null && $row['meta_key']==='_esp67_coverage_probe'
        && $row['meta_value']==='restored',
        'by-mid rename back succeeds with owner actor');

    // Ordinary post -> attachment promotion is a wp_posts UPDATE, not a
    // postmeta UPDATE. The global actor is necessary before promotion.
    cov67_actor($wpdb,null);
    wp_update_post(['ID'=>$normal,'post_type'=>'attachment'],true);
    cov67_check($db->query("SELECT post_type FROM {$wpdb->posts} WHERE ID=$normal")
        ->fetch_assoc()['post_type']==='post',
        'post-to-attachment promotion denied without global actor');
    cov67_actor($wpdb,'ACTOR_A');
    wp_update_post(['ID'=>$normal,'post_type'=>'attachment'],true);
    cov67_check($db->query("SELECT post_type FROM {$wpdb->posts} WHERE ID=$normal")
        ->fetch_assoc()['post_type']==='attachment',
        'post-to-attachment promotion succeeds with global actor');
    cov67_sql($db,"INSERT INTO $gate VALUES ($normal,'ACTOR_A')",
        'promoted attachment receives local actor');
    wp_update_post(['ID'=>$normal,'post_type'=>'post'],true);
    cov67_check($db->query("SELECT post_type FROM {$wpdb->posts} WHERE ID=$normal")
        ->fetch_assoc()['post_type']==='post',
        'attachment demotion succeeds with local actor');

    // IMPORTANT: SQL failure alone does not guarantee Core has not already
    // performed other SQL or PHP/FS side effects of wp_delete_attachment().
    cov67_actor($wpdb,null);
    $before=(int)$db->query("SELECT COUNT(*) AS n FROM {$wpdb->postmeta}
        WHERE post_id=$attachment")->fetch_assoc()['n'];
    $deleteReturn=wp_delete_attachment($attachment,true);
    $after=(int)$db->query("SELECT COUNT(*) AS n FROM {$wpdb->postmeta}
        WHERE post_id=$attachment")->fetch_assoc()['n'];
    $stillPresent=cov67_title($db,$wpdb->posts,$attachment)!==null;
    echo 'ESP67_COVERAGE_DENIED_DELETE ' . wp_json_encode([
        'core_return'=>is_object($deleteReturn)?get_class($deleteReturn):$deleteReturn,
        'post_still_present'=>$stillPresent,
        'metadata_rows_before'=>$before,'metadata_rows_after'=>$after,
        'partial_metadata_change'=>$before!==$after
    ]) . "\n";
    cov67_check($stillPresent, 'missing actor prevents wp_posts DELETE');
    if($before!==$after) {
        echo "ESP67_COVERAGE_PARTIAL_DELETE_SIDE_EFFECT_OBSERVED\n";
    } else {
        echo "ESP67_COVERAGE_NO_META_SIDE_EFFECT_IN_THIS_FIXTURE\n";
    }

    cov67_actor($wpdb,'ACTOR_A');
    wp_delete_attachment($attachment,true);
    cov67_check(cov67_title($db,$wpdb->posts,$attachment)===null,
        'valid owner actor permits attachment deletion');
    echo "ESP67_COVERAGE_POSTS_AND_BYMID_PROBE_COMPLETE\n";
} finally {
    foreach(array_reverse($names) as $name) {
        $db->query("DROP TRIGGER IF EXISTS $name");
    }
    $db->query("DROP TABLE IF EXISTS $gate");
    $db->close();
    $wpdb->suppress_errors($previous);
    foreach(array_reverse($createdPosts) as $id) {
        if(get_post($id)) wp_delete_post($id,true);
    }
}
