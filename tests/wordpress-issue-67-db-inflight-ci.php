<?php
/**
 * #67: Does killing a PHP process guarantee its in-flight MySQL UPDATE has
 * already completed/rolled back? Test with a real server-side SLEEP inside
 * UPDATE. This probe is disposable and does NOT touch production tables.
 */
declare(strict_types=1);
$phase=getenv('ESP67_INFLIGHT_PHASE');
if(getenv('ESP_ALLOW_DISPOSABLE_DB')!=='1'||!getenv('ESP_WP_ROOT')||
   !in_array($phase,['setup','writer','observe','final','cleanup'],true))exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require rtrim(getenv('ESP_WP_ROOT'),'/').'/wp-load.php';
if(strpos(DB_NAME,'esp_')!==0)throw new RuntimeException('requires disposable DB');
global $wpdb;
$table=$wpdb->prefix.'esp67_inflight_probe';
$root=wp_upload_dir()['basedir'].'/.esp67-inflight';
wp_mkdir_p($root);
$lockfile="$root/lock";
$started="$root/started";
function if67_check(bool $yes,string $why):void {
    if(!$yes)throw new RuntimeException('ESP67 INFLIGHT: '.$why);
    echo "PASS ESP67 INFLIGHT: $why\n";
}
function if67_db():mysqli {return new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);}
if($phase==='setup') {
    @unlink($started);
    $db=if67_db();
    $db->query("DROP TABLE IF EXISTS $table");
    $db->query("CREATE TABLE $table (id BIGINT PRIMARY KEY, value INT NOT NULL)
        ENGINE=InnoDB");
    $db->query("INSERT INTO $table VALUES(1,0)");
    $db->close();
    $fh=fopen($lockfile,'c+');if67_check(is_resource($fh),'lifetime lockfile exists');fclose($fh);
    echo "ESP67_INFLIGHT_SETUP\n";exit(0);
}
if($phase==='cleanup') {
    $db=if67_db();$db->query("DROP TABLE IF EXISTS $table");$db->close();
    echo "ESP67_INFLIGHT_CLEANUP\n";exit(0);
}
if($phase==='writer') {
    $fh=fopen($lockfile,'c+');
    if67_check(is_resource($fh)&&flock($fh,LOCK_SH),'writer owns PHP-process lifetime shared flock');
    $db=if67_db();
    file_put_contents($started,(string)getmypid());
    // This is a single autocommit SQL on the MySQL server. The driver is
    // deliberately synchronous, as WordPress Core wpdb::query normally is.
    $result=$db->query("UPDATE $table SET value=IF(SLEEP(5)=0,1,0)
        WHERE id=1 /* ESP67_SERVER_INFLIGHT */");
    if67_check($result!==false,'server-side delayed update completed');
    echo "ESP67_INFLIGHT_SQL_FINISHED\n";exit(0);
}
$db=if67_db();
if($phase==='observe') {
    $found=false;
    for($i=0;$i<60;$i++){
        $list=$db->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC);
        foreach($list as $p){
            if(str_contains((string)($p['Info']??''),'ESP67_SERVER_INFLIGHT') &&
                !str_contains((string)($p['Info']??''),'SHOW FULL PROCESSLIST')){
                $found=true;break;
            }
        }
        if($found)break;
        usleep(100000);
    }
    if67_check($found,'real MySQL server is executing delayed UPDATE');
    echo "ESP67_INFLIGHT_SQL_ACTIVE\n";
    $db->close();exit(0);
}
$lock=fopen($lockfile,'c+');
if67_check(is_resource($lock)&&flock($lock,LOCK_EX|LOCK_NB),
    'PHP lock is released after SIGKILL');
$before=$db->query("SELECT value FROM $table WHERE id=1")->fetch_assoc();
if67_check(is_array($before),'reaper can read current source snapshot');
echo "ESP67_INFLIGHT_POST_KILL_READ ".(int)$before['value']."\n";
// Test both timing and InnoDB row-lock serialization; a SELECT FOR UPDATE
// must not overtake an UPDATE that already owns the target row.
$db->begin_transaction();
$locked=$db->query("SELECT value FROM $table WHERE id=1 FOR UPDATE")->fetch_assoc();
if67_check(is_array($locked),'canonical-row lock acquired by recovery');
$db->commit();
echo "ESP67_INFLIGHT_POST_KILL_LOCKED_READ ".(int)$locked['value']."\n";
flock($lock,LOCK_UN);fclose($lock);$db->close();
