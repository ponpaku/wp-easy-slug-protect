<?php
/**
 * #67 design probe: one extra TEXT column in REAL ESP media state table,
 * actual WordPress postmeta pre-hooks, two independent PHP/wpdb workers.
 * No production PHP class is replaced. CI disposable only.
 *
 * Runs after F01 restored a single protected metadata row.
 */
declare(strict_types=1);
$phase = getenv('ESP67_PHASE');
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' ||
    !getenv('ESP_WP_ROOT') ||
    !in_array($phase, ['seed','hold-b','update-a','restore'], true)) { exit(1); }
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
if (strpos(DB_NAME, 'esp_') !== 0 || !class_exists('ESP_Media_Index') ||
    !ESP_Media_Index::enabled() || !ESP_Media_Index::ready()) {
    throw new RuntimeException('ESP67 real WP hook probe needs disposable READY strict site');
}
global $wpdb;
$table = ESP_Media_Index::state_table();
$id = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_title=%s LIMIT 1",
    'ESP31 protected JPEG'
));
$key = ESP_Media_Index::PROTECTION_META;
if ($id <= 0 || get_post_meta($id, $key, true) !== 'path_http') {
    throw new RuntimeException('ESP67 original protected fixture unavailable');
}
$root = wp_upload_dir()['basedir'] . '/.esp67-hook-probe';
wp_mkdir_p($root);
$ready = "$root/b-registered";
$resume = "$root/b-resume";
function esp67_assert_wp(bool $ok, string $why): void {
    if (!$ok) { throw new RuntimeException('ESP67 WP: ' . $why); }
    echo "PASS ESP67 WP: $why\n";
}
function esp67_snapshot_wp(int $id, string $table): array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT revision,status,pending_count,esp67_active_mutations
         FROM $table WHERE attachment_id=%d", $id), ARRAY_A);
    if (!is_array($row) || $wpdb->last_error !== '') {
        throw new RuntimeException('ESP67 WP: cannot read mutation state');
    }
    $ids = json_decode((string)$row['esp67_active_mutations'], true);
    if (!is_array($ids) || !array_is_list($ids) ||
        count($ids) !== count(array_unique($ids)) ||
        count($ids) !== count(array_filter($ids, 'is_string'))) {
        throw new RuntimeException('ESP67 WP: invalid mutation ID list');
    }
    $row['ids'] = $ids;
    return $row;
}
/** Each call submits a prepared SQL transition twice (ACK-loss replay model).
 * The second execution must be a no-op. WPDB is a real WordPress Core object.
 */
function esp67_change_wp(int $id, string $table, string $ticket, bool $add): void {
    global $wpdb;
    for ($n=0;$n<12;$n++) {
        $old = esp67_snapshot_wp($id, $table);
        $list = $old['ids'];
        $present = in_array($ticket, $list, true);
        if ($present === $add) { return; }
        if ($add) { $list[] = $ticket; }
        else { $list = array_values(array_diff($list, [$ticket])); }
        sort($list, SORT_STRING);
        $encoded = wp_json_encode($list);
        $sql = $wpdb->prepare(
            "UPDATE $table SET esp67_active_mutations=%s,revision=revision+1,status='dirty'
             WHERE attachment_id=%d AND revision=%d
               AND BINARY esp67_active_mutations=BINARY %s",
            $encoded, $id, (int)$old['revision'], (string)$old['esp67_active_mutations']
        );
        $first = $wpdb->query($sql);
        if ($first === false || $wpdb->last_error !== '') {
            throw new RuntimeException('ESP67 WP: candidate CAS failed: '.$wpdb->last_error);
        }
        if ($first === 1) {
            $replay = $wpdb->query($sql);
            if ($replay !== 0 || $wpdb->last_error !== '') {
                throw new RuntimeException('ESP67 WP: same CAS SQL applied twice');
            }
            return;
        }
    }
    throw new RuntimeException('ESP67 WP: CAS retries exceeded');
}
if ($phase === 'seed') {
    @unlink($ready);
    @unlink($resume);
    $initial = $wpdb->get_row($wpdb->prepare(
        "SELECT status,pending_count FROM $table WHERE attachment_id=%d", $id), ARRAY_A);
    esp67_assert_wp(is_array($initial) && $initial['status']==='ready' &&
        (int)$initial['pending_count']===0, 'fixture initially READY with no writers');
    if ($wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM $table LIKE %s", 'esp67_active_mutations'
    )) !== null) {
        throw new RuntimeException('ESP67 WP: test column already exists');
    }
    esp67_assert_wp($wpdb->query(
        "ALTER TABLE $table ADD COLUMN esp67_active_mutations LONGTEXT NULL"
    ) !== false, 'one candidate column added to disposable real state');
    esp67_assert_wp($wpdb->query($wpdb->prepare(
        "UPDATE $table SET esp67_active_mutations='[]' WHERE attachment_id=%d", $id
    ))===1, 'mutation set initialized in real state row');
    exit(0);
}
if ($phase === 'restore') {
    esp67_assert_wp(file_exists($resume), 'B resumed');
    $s = esp67_snapshot_wp($id, $table);
    esp67_assert_wp($s['ids']===[] && (int)$s['pending_count']===0,
        'both WP workers completed, mutation set and existing pending empty');
    esp67_assert_wp(ESP_Media_Index::reindex_attachment($id),
        'production index can rebuild from committed canonical metadata');
    $res = ESP_Media_Index::resolve(wp_upload_dir()['basedir'] . '/esp31-protected.jpg');
    esp67_assert_wp($res['status']==='resolved' &&
        ($res['protected_path_id'] ?? null)==='path_http',
        'strict index resolves protected WordPress attachment after rebuild');
    esp67_assert_wp($wpdb->query(
        "ALTER TABLE $table DROP COLUMN esp67_active_mutations"
    )!==false, 'candidate column removed from disposable state');
    exit(0);
}
$ticket = $phase === 'hold-b' ? 'B' : 'A';
$pre = static function($check,$post_id,$meta_key) use($ticket,$id,$key,$table,$phase,$ready,$resume) {
    if ((int)$post_id !== $id || $meta_key !== $key) { return $check; }
    $before = esp67_snapshot_wp($id, $table);
    esp67_assert_wp($before['status']==='dirty' &&
        (int)$before['pending_count']>=1, 'production priority-5 guard ran before candidate');
    esp67_change_wp($id,$table,$ticket,true);
    if ($phase === 'hold-b') {
        file_put_contents($ready, 'ok');
        $until = microtime(true)+40;
        while (!file_exists($resume) && microtime(true)<$until) { usleep(100000); }
        esp67_assert_wp(file_exists($resume), 'B barrier released');
    }
    return $check;
};
add_filter('update_post_metadata',$pre,7,5);
if ($phase === 'hold-b') {
    $changed = update_post_meta($id, $key, 'path_http');
    esp67_assert_wp($changed !== false, 'B committed its WordPress canonical write');
} else {
    esp67_assert_wp(file_exists($ready), 'B reached pre-write barrier');
    $changed = update_post_meta($id, $key, '');
    esp67_assert_wp($changed !== false, 'A committed its WordPress canonical write');
}
remove_filter('update_post_metadata',$pre,7);
esp67_change_wp($id,$table,$ticket,false);
ESP_Media_Index::finish_noop_writes();
$s = esp67_snapshot_wp($id,$table);
if ($phase === 'update-a') {
    esp67_assert_wp($s['ids']===['B'] && (int)$s['pending_count']===1,
        'A completion (including same SQL replay) cannot remove B');
    esp67_assert_wp(!ESP_Media_Index::reindex_attachment($id),
        'production reindex refuses to publish READY during B write');
    esp67_assert_wp(ESP_Media_Index::resolve(
        wp_upload_dir()['basedir'].'/esp31-protected.jpg')['status']==='error',
        'strict owner resolution denies during concurrent WordPress update');
    exit(0);
}
esp67_assert_wp($s['ids']===[] && (int)$s['pending_count']===0,
    'B completion clears only B after A already completed');
