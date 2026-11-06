<?php
/**
 * #67 MySQL8/real WordPress deterministic lock-order experiment.
 * Isolated test-only AFTER trigger: wp_postmeta(row) -> state(global).
 * Reaper takes state(global) -> wp_postmeta(row), intentionally forming a
 * deadlock. The expected outcome is ONE transaction victim and NO stale READY
 * after any successfully committed metadata SQL.
 *
 * This is a disposable CI fixture, not a production lock protocol.
 */
declare(strict_types=1);
$phase = getenv('ESP67_HYBRID_DL_PHASE');
if (getenv('ESP_ALLOW_DISPOSABLE_DB')!=='1' ||
    !getenv('ESP_WP_ROOT') ||
    !in_array($phase,['setup','reaper','writer','observe','verify','cleanup'],true)) exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require rtrim(getenv('ESP_WP_ROOT'),'/').'/wp-load.php';
if (strpos(DB_NAME,'esp_')!==0 || !class_exists('ESP_Media_Index')
    || !ESP_Media_Index::enabled()) throw new RuntimeException('disposable strict WordPress required');
global $wpdb;
$state = ESP_Media_Index::state_table();
$postmeta = $wpdb->postmeta;
$root = wp_upload_dir()['basedir'].'/.esp67-hybrid-dl';
wp_mkdir_p($root);
$key = '_esp67_hybrid_deadlock_probe';
$tr = $wpdb->prefix.'esp67_hybrid_lock_cycle_probe';
$midFile = "$root/mid";
function dl67_assert(bool $valid, string $msg): void {
    if (!$valid) throw new RuntimeException('ESP67 HYBRID LOCK: '.$msg);
    echo "PASS ESP67 HYBRID LOCK: $msg\n";
}
function dl67_db(): mysqli {
    $db = new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);
    $db->query('SET SESSION innodb_lock_wait_timeout=12');
    return $db;
}
function dl67_wait(string $path, int $maxTicks=250): void {
    for ($i=0;$i<$maxTicks;$i++) {
        if (is_file($path)) return;
        usleep(100000);
    }
    throw new RuntimeException('ESP67 signal timeout: '.$path);
}
function dl67_result(mysqli $db, string $sql): array {
    try {
        $res = $db->query($sql);
        return [$res, $res===false?$db->errno:0, $res===false?$db->error:''];
    } catch (mysqli_sql_exception $e) {
        return [false,$e->getCode(),$e->getMessage()];
    }
}
if ($phase==='setup') {
    foreach (['state-locked','release-reaper','writer-entered','writer-result','reaper-result'] as $f) @unlink("$root/$f");
    dl67_assert(ESP_Media_Index::ready(),'strict fixture READY before lock experiment');
    $id=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'
          AND post_title=%s LIMIT 1",'ESP31 protected JPEG'));
    dl67_assert($id>0,'existing protected owner found');
    delete_post_meta($id,$key);
    $mid=add_post_meta($id,$key,'before',true);
    dl67_assert(is_numeric($mid) && (int)$mid>0,'test metadata inserted using WordPress Core');
    file_put_contents($midFile,$id."\n".(int)$mid);
    $db=dl67_db();
    [$r,$err,$message] = dl67_result($db,
        "CREATE TRIGGER $tr AFTER UPDATE ON $postmeta FOR EACH ROW BEGIN
          IF NEW.meta_id=".(int)$mid." THEN
            UPDATE $state SET status='dirty',revision=revision+1,
              audit_cursor=0 WHERE attachment_id=0;
          END IF;
         END");
    dl67_assert($r!==false,'test-only AFTER UPDATE trigger installed '.$err.' '.$message);
    $db->close();
    echo "ESP67_HYBRID_DL_SETUP\n";
    exit(0);
}
dl67_assert(is_file($midFile),'fixture id exists');
[$id,$mid]=array_map('intval',file($midFile,FILE_IGNORE_NEW_LINES));
dl67_assert($id>0&&$mid>0,'fixture ids valid');
if($phase==='reaper') {
    $db=dl67_db();
    dl67_assert($db->begin_transaction(),'reaper TX starts');
    [$r,$err,$message]=dl67_result($db,
        "SELECT attachment_id FROM $state WHERE attachment_id=0 FOR UPDATE");
    dl67_assert($r!==false,'reaper owns state global row '.$err.' '.$message);
    file_put_contents("$root/state-locked",'yes');
    dl67_wait("$root/release-reaper");
    [$r,$err,$message]=dl67_result($db,
        "SELECT meta_id FROM $postmeta WHERE meta_id=$mid FOR UPDATE");
    if($r===false) {
        $db->rollback();
        dl67_assert((int)$err===1213,'reaper was deadlock victim: '.$err.' '.$message);
        file_put_contents("$root/reaper-result",'DEADLOCK');
        echo "ESP67_HYBRID_DL_REAPER_VICTIM\n";
    } else {
        $db->commit();
        file_put_contents("$root/reaper-result",'COMMIT');
        echo "ESP67_HYBRID_DL_REAPER_COMMIT\n";
    }
    $db->close();
    exit(0);
}
if($phase==='writer') {
    dl67_wait("$root/state-locked");
    $wpdb->suppress_errors(true);
    dl67_assert($wpdb->query('START TRANSACTION')!==false,
        'real wpdb Core TX begins');
    file_put_contents("$root/writer-entered",'yes');
    try {
        $ok=update_post_meta($id,$key,'after');
    } catch(Throwable $e) {
        $ok=false;
        $error=$e->getMessage();
    }
    if($ok===false) {
        $error=$error??$wpdb->last_error;
        $wpdb->query('ROLLBACK');
        dl67_assert(stripos($error,'Deadlock')!==false || strpos($error,'1213')!==false,
            'Core metadata writer was deadlock victim: '.$error);
        file_put_contents("$root/writer-result",'DEADLOCK');
        echo "ESP67_HYBRID_DL_CORE_VICTIM\n";
    } else {
        dl67_assert($wpdb->query('COMMIT')!==false,
            'Core metadata writer COMMIT succeeded');
        file_put_contents("$root/writer-result",'COMMIT');
        echo "ESP67_HYBRID_DL_CORE_COMMIT\n";
    }
    exit(0);
}
if($phase==='observe') {
    $db=dl67_db();
    $db->begin_transaction();
    [$r,$err,$message]=dl67_result($db,
        "SELECT meta_id FROM $postmeta WHERE meta_id=$mid FOR UPDATE SKIP LOCKED");
    $locked=$r!==false && $r->num_rows===0;
    $db->rollback();$db->close();
    if(!$locked) exit(2);
    echo "ESP67_HYBRID_DL_POSTMETA_ROW_LOCKED_BEFORE_REAPER\n";
    exit(0);
}
if($phase==='verify') {
    dl67_wait("$root/writer-result");
    dl67_wait("$root/reaper-result");
    $writer=trim(file_get_contents("$root/writer-result"));
    $reaper=trim(file_get_contents("$root/reaper-result"));
    dl67_assert(($writer==='COMMIT' && $reaper==='DEADLOCK') ||
        ($writer==='DEADLOCK' && $reaper==='COMMIT'),
        'cycle had exactly one deadlock victim and one survivor');
    $db=dl67_db();
    $row=$db->query("SELECT meta_value FROM $postmeta WHERE meta_id=$mid")->fetch_assoc();
    $global=$db->query("SELECT status FROM $state WHERE attachment_id=0")->fetch_assoc();
    $db->close();
    dl67_assert(is_array($row)&&is_array($global),'canonical and global rows readable');
    if($writer==='COMMIT') {
        dl67_assert($row['meta_value']==='after' && $global['status']==='dirty',
            'committed Core SQL co-committed global denial after deadlock');
        echo "ESP67_HYBRID_DL_CORE_COMMITTED_GLOBAL_DIRTY\n";
    } else {
        dl67_assert($row['meta_value']==='before' && $global['status']==='ready',
            'rolled back Core SQL did not corrupt canonical or publish false coverage');
        echo "ESP67_HYBRID_DL_CORE_ROLLED_BACK_GLOBAL_READY\n";
    }
    exit(0);
}
dl67_assert($phase==='cleanup','cleanup phase');
$db=dl67_db();
dl67_assert($db->query("DROP TRIGGER IF EXISTS $tr")!==false,'test-only trigger removed');
$db->close();
delete_post_meta($id,$key);
for($i=0;$i<120;$i++) {
    ESP_Media_Index::run_batch();
    if($wpdb->get_var("SELECT status FROM $state WHERE attachment_id=0")==='ready') break;
}
dl67_assert($wpdb->get_var("SELECT status FROM $state WHERE attachment_id=0")==='ready',
    'global coverage returned READY by real auditor');
$path=wp_upload_dir()['basedir'].'/esp31-public-150x150.jpg';
dl67_assert(ESP_Media_Index::resolve($path)['status']==='resolved',
    'original public image resolves after recovery');
foreach (['state-locked','release-reaper','writer-entered','writer-result','reaper-result','mid'] as $f) @unlink("$root/$f");
echo "ESP67_HYBRID_DL_RESTORED\n";
