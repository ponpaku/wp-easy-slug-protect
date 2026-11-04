<?php
/**
 * #67 production schema v3 -> v4 migration, executed against disposable
 * WordPress + MySQL with a SEPARATE table prefix. The live fixture and its
 * schema/options must be fully restored even if this smoke test fails.
 */
declare(strict_types=1);
if (getenv('ESP_ALLOW_DISPOSABLE_DB')!=='1' || !getenv('ESP_WP_ROOT')) exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require rtrim(getenv('ESP_WP_ROOT'),'/').'/wp-load.php';
if (strpos(DB_NAME,'esp_')!==0 || !class_exists('ESP_Media_Index') ||
    !ESP_Media_Index::ready()) {
    throw new RuntimeException('Migration probe needs disposable READY WordPress');
}
global $wpdb;
$oldPrefix=$wpdb->prefix;
$oldPosts=$wpdb->posts;
$oldMeta=$wpdb->postmeta;
$opts=[
    ESP_Media_Index::SCHEMA_OPTION=>get_option(ESP_Media_Index::SCHEMA_OPTION),
    ESP_Media_Index::READY_OPTION=>get_option(ESP_Media_Index::READY_OPTION),
    ESP_Media_Index::CURSOR_OPTION=>get_option(ESP_Media_Index::CURSOR_OPTION),
];
$prefix='esp67mig_';
$legacyFiles=$prefix.'esp_media_files_v1';
$legacyState=$prefix.'esp_media_file_state_v1';
$newFiles=$prefix.'esp_media_files';
$newState=$prefix.'esp_media_file_state';
$posts=$prefix.'posts';
$meta=$prefix.'postmeta';
$all=[$posts,$meta,$legacyFiles,$legacyState,$newFiles,$newState];
$path='esp67-migration-probe.jpg';
$absolute=wp_upload_dir()['basedir'].'/'.$path;
function mig67_ok($ok,$msg) {
    if (!$ok) throw new RuntimeException('ESP67 MIGRATION: '.$msg);
    echo "PASS ESP67 MIGRATION: $msg\n";
}
function mig67_sql($sql) {
    global $wpdb;
    $r=$wpdb->query($sql);
    if ($r===false||$wpdb->last_error!=='') {
        throw new RuntimeException('ESP67 MIGRATION SQL: '.$wpdb->last_error);
    }
    return $r;
}
try {
    foreach($all as $table) mig67_sql("DROP TABLE IF EXISTS $table");
    mig67_sql("CREATE TABLE $legacyFiles LIKE {$oldPrefix}esp_media_files");
    mig67_sql("CREATE TABLE $legacyState LIKE {$oldPrefix}esp_media_file_state");
    mig67_sql("ALTER TABLE $legacyState DROP COLUMN active_mutation_ids");
    mig67_sql("CREATE TABLE $posts
        (ID bigint(20) unsigned NOT NULL PRIMARY KEY,
         post_type varchar(20) NOT NULL) ENGINE=InnoDB");
    mig67_sql("CREATE TABLE $meta
        (meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
         post_id bigint(20) unsigned NOT NULL,
         meta_key varchar(191) NOT NULL,
         meta_value longtext NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mig67_sql("INSERT INTO $posts(ID,post_type) VALUES(7,'attachment')");
    mig67_sql("INSERT INTO $meta(post_id,meta_key,meta_value)
        VALUES(7,'_wp_attached_file','$path'),
              (7,'_esp_media_protected_path_id','secret_before')");
    mig67_sql("INSERT INTO $legacyState
      (attachment_id,status,revision,active_generation,pending_count,audit_cursor)
      VALUES(0,'ready',4,0,0,0),(7,'ready',5,5,0,0)");
    $hash=hash('sha256',$path);
    mig67_sql("INSERT INTO $legacyFiles
      (path_hash,file_path,attachment_id,generation,protected_path_id)
      VALUES('$hash','$path',7,5,'secret_before')");
    file_put_contents($absolute,'CI only');
    // Simulate the previous installed schema and a trusted but stale READY.
    $wpdb->prefix=$prefix;
    $wpdb->posts=$posts;
    $wpdb->postmeta=$meta;
    update_option(ESP_Media_Index::SCHEMA_OPTION,'3',false);
    update_option(ESP_Media_Index::READY_OPTION,'1',false);
    mig67_ok(ESP_Media_Index::install_schema(),
        'real dbDelta upgrades existing two legacy tables with no extra index table');
    mig67_ok(get_option(ESP_Media_Index::SCHEMA_OPTION)==='4' &&
        get_option(ESP_Media_Index::READY_OPTION)==='0',
        'schema revision 4 activates only with old READY revoked');
    $found=$wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM $newState LIKE %s",'active_mutation_ids'));
    mig67_ok($found==='active_mutation_ids',
        'migration adds exactly one state mutation ID column');
    $oldExists=$wpdb->get_var($wpdb->prepare(
        'SHOW TABLES LIKE %s',$wpdb->esc_like($legacyState)));
    $oldFiles=$wpdb->get_var($wpdb->prepare(
        'SHOW TABLES LIKE %s',$wpdb->esc_like($legacyFiles)));
    mig67_ok($oldExists===null&&$oldFiles===null,
        'legacy physical table names are renamed, not duplicated');
    $rows=$wpdb->get_row("SELECT status,revision FROM $newState
        WHERE attachment_id=0",ARRAY_A);
    mig67_ok($rows['status']==='dirty' && (int)$rows['revision']>4 &&
        (int)$wpdb->get_var("SELECT COUNT(*) FROM $newFiles
            WHERE attachment_id=7")===1,
        'legacy rows survive rename but old audit epoch is invalidated');
    // The installed production AFTER trigger now handles canonical writes
    // to this migrated prefix before the first new audit is published.
    $before=(int)$rows['revision'];
    mig67_sql("UPDATE $meta SET meta_value='secret_after'
        WHERE post_id=7 AND meta_key='_esp_media_protected_path_id'");
    $after=(int)$wpdb->get_var("SELECT revision FROM $newState
        WHERE attachment_id=0");
    mig67_ok($after>$before,
        'migrated Core table is covered by the newly installed SQL trigger');
    for($attempt=0;$attempt<35;$attempt++) {
        ESP_Media_Index::run_batch();
        $global=$wpdb->get_var("SELECT status FROM $newState WHERE attachment_id=0");
        if($global==='ready')break;
    }
    mig67_ok($global==='ready' &&
        ESP_Media_Index::resolve($absolute)['protected_path_id']==='secret_after',
        'fresh canonical audit publishes migrated protection after DB write');
    echo "ESP67_PRODUCT_LEGACY_TABLE_MIGRATION_VERIFIED\n";
} finally {
    $wpdb->prefix=$oldPrefix;
    $wpdb->posts=$oldPosts;
    $wpdb->postmeta=$oldMeta;
    foreach($all as $table) {
        try {$wpdb->query("DROP TABLE IF EXISTS $table");}catch(Throwable $e){}
    }
    @unlink($absolute);
    foreach($opts as $key=>$value)update_option($key,$value,false);
}
