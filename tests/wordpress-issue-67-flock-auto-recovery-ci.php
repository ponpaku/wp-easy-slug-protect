<?php
/**
 * #67 proof-of-concept crash-safe AUTOMATIC GC with per-attachment POSIX flock.
 *
 * Disposable single-host WordPress only: a PHP request holds LOCK_SH over its
 * *whole* canonical write lifetime; a reaper may retire orphan tickets only
 * while it holds LOCK_EX on the SAME persistent inode.
 *
 * WARNING: Not portable to multi-host WP unless shared flock semantics can be
 * established on every node. Not yet a production recommendation.
 */
declare(strict_types=1);
$phase=getenv('ESP67_FLOCK_PHASE');
$scenario=getenv('ESP67_FLOCK_SCENARIO');
if(getenv('ESP_ALLOW_DISPOSABLE_DB')!=='1'||!getenv('ESP_WP_ROOT')||
    !in_array($phase,['inode','setup','writer','recover','verify','kill_db','sql_observe','http_verify','network_verify','kill_backend','restore'],true)||
    !in_array($scenario,['crash','db-loss','inflight','network-unsafe','network-gated','network-killed','network-trigger','trigger-normal','trigger-reconnect'],true))exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require rtrim(getenv('ESP_WP_ROOT'),'/').'/wp-load.php';
$_SERVER['SERVER_SOFTWARE']='Apache/2.4';
if(strpos(DB_NAME,'esp_')!==0||!class_exists('ESP_Media_Index')||
    !ESP_Media_Index::enabled()||!ESP_Media_Index::ready())
    throw new RuntimeException('ESP67 flock probe requires disposable strict READY WP');
global $wpdb;
$id=(int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE
    post_type='attachment' AND post_title=%s LIMIT 1",'ESP31 protected JPEG'));
if($id<=0)throw new RuntimeException('missing protected owner');
$state=ESP_Media_Index::state_table();
$files=ESP_Media_Index::files_table();
$public='esp31-public-150x150.jpg';
$path=wp_upload_dir()['basedir'].'/'.$public;
$root=wp_upload_dir()['basedir'].'/.esp67-flock';
wp_mkdir_p($root);
$lockfile="$root/".hash('sha256',DB_NAME."\0".$wpdb->prefix."\0".$id).'.lock';
$ready="$root/ready";$resume="$root/resume";$pidfile="$root/pid";
$sessionfile="$root/liveness";$written="$root/written";
$ticket='FLOCK';
function fl67_check(bool $yes,string $msg):void {
    if(!$yes)throw new RuntimeException('ESP67 flock: '.$msg);
    echo "PASS ESP67 flock: $msg\n";
}
function fl67_db():mysqli {return new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);}
function fl67_get(string $state,int $id):array {
    $db=fl67_db();
    $r=$db->query("SELECT status,revision,pending_count,esp67_flock_ids FROM $state
        WHERE attachment_id=$id")->fetch_assoc();
    $db->close();
    if(!is_array($r))throw new RuntimeException('no state');
    return $r;
}
if($phase==='inode'){
    // A lockfile may be unlinked and replaced while the previous PHP owner
    // still holds its original inode. The *path* is not the lock identity.
    // Real implementation must never unlink and must verify persisted inode
    // / generation before permitting automatic orphan reclamation.
    $a="$root/replace-test.lock";
    @unlink($a);
    $older=fopen($a,'c+');
    fl67_check(is_resource($older)&&flock($older,LOCK_SH),
        'first PHP owner holds original lock inode');
    $old=fstat($older);
    fl67_check(unlink($a),'test removes the lock pathname while owner still alive');
    $newer=fopen($a,'c+');
    fl67_check(is_resource($newer)&&flock($newer,LOCK_EX|LOCK_NB),
        'new pathname permits incompatible exclusive lock on different inode');
    $now=fstat($newer);
    fl67_check($old['ino']!==$now['ino'] || $old['dev']!==$now['dev'],
        'fstat detects replaced lock identity and must block ticket recovery');
    echo "ESP67_FLOCK_INODE_REPLACEMENT_UNSAFE_WITHOUT_IDENTITY_CHECK\n";
    flock($newer,LOCK_UN);fclose($newer);
    flock($older,LOCK_UN);fclose($older);@unlink($a);exit(0);
}
if($phase==='setup'){
    foreach([$ready,$resume,$pidfile,$sessionfile,$written,"$root/backend-session"] as $f)@unlink($f);
    fl67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
        'baseline public path resolves without protected collision');
    $m=wp_get_attachment_metadata($id,true);
    fl67_check(is_array($m)&&!isset($m['sizes']['esp67_flock']),
        'baseline protected owner has no collision');
    $fh=fopen($lockfile,'c+');
    fl67_check(is_resource($fh),'stable lockfile created');
    fclose($fh);
    $db=fl67_db();
    fl67_check($db->query("ALTER TABLE $state ADD COLUMN esp67_flock_ids LONGTEXT NULL")!==false,
        'test-only mutation ID column added');
    $db->query("UPDATE $state SET esp67_flock_ids='[]' WHERE attachment_id=$id");
    if(in_array($scenario,['network-trigger','trigger-normal','trigger-reconnect'],true)){
        $trigger=$wpdb->prefix.'esp67_update_ticket_check';
        $db->query("DROP TRIGGER IF EXISTS `$trigger`");
        // Test-only server-side auth fence on genuine Core UPDATE.
        // Unlike query-filter SQL rewriting this runs no matter whether the
        // WordPress SQL arrived before or after PHP died / was replayed.
        $ddl="CREATE TRIGGER `$trigger` BEFORE UPDATE ON {$wpdb->postmeta}
            FOR EACH ROW BEGIN
                DECLARE n INT DEFAULT 0;
                IF NEW.post_id=$id
                    AND NEW.meta_key='_wp_attachment_metadata' THEN
                    SELECT COUNT(*) INTO n FROM $state
                      WHERE attachment_id=$id AND status='dirty'
                        AND pending_count=1
                        AND IFNULL(JSON_CONTAINS(esp67_flock_ids,
                            JSON_QUOTE(@esp67_wp_actor)),0)=1
                      FOR UPDATE;
                    IF n<>1 THEN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT='esp67 expired mutation token';
                    END IF;
                END IF;
            END";
        $created=$db->query($ddl);
        if($created===false)
            echo "ESP67_TRIGGER_DDL_ERROR ".$db->errno.' '.$db->error."\n";
        fl67_check($created!==false,
            'test-only WP postmeta BEFORE UPDATE trigger installed');
    }
    $db->close();
    echo "ESP67_FLOCK_SETUP\n";exit(0);
}
if($phase==='restore'){
    if(in_array($scenario,['network-trigger','trigger-normal'],true)){
        $db=fl67_db();
        $db->query("DROP TRIGGER IF EXISTS `{$wpdb->prefix}esp67_update_ticket_check`");
        $db->close();
    }
    $m=wp_get_attachment_metadata($id,true);
    fl67_check(is_array($m),'canonical metadata exists');
    if(isset($m['sizes']['esp67_flock'])){
        unset($m['sizes']['esp67_flock']);
        fl67_check(wp_update_attachment_metadata($id,$m)!==false,
            'production WordPress API restored canonical owner');
    }
    ESP_Media_Index::finish_noop_writes();
    fl67_check(ESP_Media_Index::reindex_attachment($id),'final reindex passed');
    $db=fl67_db();
    fl67_check(fl67_get($state,$id)['esp67_flock_ids']==='[]','mutation set cleared');
    $db->query("ALTER TABLE $state DROP COLUMN esp67_flock_ids");
    $db->close();
    fl67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
        'public path restored after recovery');
    echo "ESP67_FLOCK_RESTORED\n";exit(0);
}
if($phase==='kill_backend'){
    $sidfile="$root/backend-session";
    fl67_check(is_file($sidfile),'writer persisted MySQL backend session');
    $session=(int)file_get_contents($sidfile);
    fl67_check($session>0,'backend session ID is valid');
    $db=fl67_db();
    fl67_check($db->query('KILL CONNECTION '.$session)!==false,
        'recovery terminates backend session before ticket GC');
    $db->close();
    echo "ESP67_NETWORK_BACKEND_SESSION_KILLED\n";exit(0);
}
if($phase==='network_verify'){
    $db=fl67_db();
    $source=$db->query("SELECT meta_value FROM {$wpdb->postmeta}
        WHERE post_id=$id AND meta_key='_wp_attachment_metadata'
        ORDER BY meta_id LIMIT 1")->fetch_assoc();
    $reserved=$db->query("SELECT COUNT(*) AS n FROM $files WHERE
        attachment_id=$id AND path_hash='".hash('sha256',$public)."'")->fetch_assoc();
    $db->close();
    $actual=$source?maybe_unserialize($source['meta_value']):null;
    $has=($actual['sizes']['esp67_flock']['file']??null)===$public;
    $r=fl67_get($state,$id);
    fl67_check($r['status']==='ready' &&
        $r['esp67_flock_ids']==='[]'&&(int)$r['pending_count']===0,
        'orphan ticket reclaimed before delayed network packet arrived');
    fl67_check((int)$reserved['n']===0,'old index reservation was discarded');
    if($scenario==='network-unsafe'){
        fl67_check($has,'UNSAFE: MySQL committed delayed old WP SQL after local lock GC');
        fl67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
            'UNSAFE: strict Resolver still sees old public owner');
        echo "ESP67_NETWORK_DELAY_UNSAFE_PUBLIC_OWNER_GAP\n";
    }else{
        fl67_check(!$has,'FENCED: server-side ID predicate rejected late packet');
        fl67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
            'FENCED: public authorization agrees with canonical WordPress metadata');
        echo "ESP67_NETWORK_DELAY_SERVER_FENCED_SAFE\n";
    }
    exit(0);
}
if($phase==='http_verify'){
    $s=fl67_get($state,$id);
    fl67_check($s['esp67_flock_ids']==='[]'&&
        (int)$s['pending_count']===0&&$s['status']==='ready',
        'anonymous HTTP already retired orphan ticket and published READY');
    fl67_check(ESP_Media_Index::resolve($path)['status']==='resolved',
        'anonymous request restored public path without operator');
    echo "ESP67_FLOCK_HTTP_LAZY_RECOVERY_COMPLETE\n";exit(0);
}
if($phase==='sql_observe'){
    $db=fl67_db();
    $running=false;
    for($i=0;$i<50;$i++){
        $rows=$db->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC);
        foreach($rows as $row){
            if(str_contains((string)($row['Info']??''),'ESP67_FLOCK_SQL_INFLIGHT')){
                $running=true;break;
            }
        }
        if($running)break;
        usleep(100000);
    }
    $db->close();
    fl67_check($running,'WordPress Core canonical SQL executing on MySQL server');
    echo "ESP67_FLOCK_MYSQL_UPDATE_INFLIGHT\n";exit(0);
}
if($phase==='kill_db'){
    fl67_check(file_exists($ready),'PHP writer still blocked in actual pre-SQL hook');
    $session=(int)file_get_contents($sessionfile);
    fl67_check($session>0,'optional liveness connection ID recorded');
    $killer=fl67_db();$killer->query('KILL CONNECTION '.$session);
    $killer->close();
    // KILL above does NOT kill the PHP request or its stable local flock.
    echo "ESP67_FLOCK_MYSQL_LIVENESS_LOST\n";exit(0);
}
if($phase==='recover'){
    $lock=fopen($lockfile,'c+');
    fl67_check(is_resource($lock),'recovery opened persistent lock inode');
    if(!flock($lock,LOCK_EX|LOCK_NB)){
        $s=fl67_get($state,$id);
        fl67_check($s['esp67_flock_ids']==='["FLOCK"]' &&
            (int)$s['pending_count']===1 && $s['status']==='dirty',
            'active PHP shared flock keeps mutation ticket and dirty state');
        fl67_check(ESP_Media_Index::resolve($path)['status']==='error',
            'active PHP update keeps HTTP path fail closed');
        echo "ESP67_FLOCK_RECOVERY_DEFERRED_ACTIVE\n";
        fclose($lock);exit(0);
    }
    // Invariant: all legitimate writers held LOCK_SH before registration and
    // continue holding it to request termination; exclusive lock excludes
    // any late writer after this point. Future writers must acquire SH first.
    $db=fl67_db();$db->query('SET SESSION innodb_lock_wait_timeout=2');$db->begin_transaction();
    try {
        // Same lock ordering as the guarded writer: state -> post -> meta.
        // State is locked first to avoid an inverse-lock-order deadlock.
        $r=$db->query("SELECT revision,pending_count,esp67_flock_ids
            FROM $state WHERE attachment_id=$id FOR UPDATE")->fetch_assoc();
        // PHP death releases flock, but an already-dispatched Core SQL may
        // still be running on the MySQL server. Wait on canonical InnoDB
        // locks BEFORE clearing the ticket; the separate in-flight test
        // demonstrates a post-SIGKILL commit is possible.
        $post_lock=$db->query("SELECT ID FROM {$GLOBALS['wpdb']->posts}
            WHERE ID=$id FOR UPDATE");
        if($post_lock===false)throw new RuntimeException('canonical post row lock failed');
        $meta_lock=$db->query("SELECT meta_id FROM {$GLOBALS['wpdb']->postmeta}
            WHERE post_id=$id AND meta_key IN ('_wp_attached_file',
            '_wp_attachment_metadata','_esp_protection')
            FOR UPDATE");
        if($meta_lock===false){
            if((int)$db->errno===1205){
                $db->rollback();$db->close();
                flock($lock,LOCK_UN);fclose($lock);
                echo "ESP67_FLOCK_CANONICAL_SQL_STILL_INFLIGHT_RETRY\n";
                exit(0);
            }
            throw new RuntimeException('canonical postmeta row lock failed: '.$db->error);
        }
        fl67_check(true,'recovery acquired canonical post and metadata locks');
        fl67_check($r['esp67_flock_ids']==='["FLOCK"]' &&
            (int)$r['pending_count']===1,'unowned ticket exists after all PHP locks released');
        $db->query("UPDATE $state SET status='dirty',revision=revision+1,
             pending_count=0,esp67_flock_ids='[]'
             WHERE attachment_id=$id AND revision=".(int)$r['revision']);
        fl67_check($db->affected_rows===1,'conditional ticket retirement committed once');
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    finally{$db->close();}
    fl67_check(ESP_Media_Index::reindex_attachment($id),
        'rebuild under exclusive filesystem lock from canonical WordPress meta');
    $r=ESP_Media_Index::resolve($path);
    if(in_array($scenario,['crash','network-unsafe','network-gated','network-killed','network-trigger','trigger-reconnect'],true)){
        fl67_check($r['status']==='resolved'&&
            ($r['protected_path_id']??null)==='',
            'crashed pre-write worker restored old public path');
        echo "ESP67_FLOCK_CRASH_AUTO_RECOVERED\n";
    }else{
        fl67_check($r['status']==='ambiguous',
            'finished worker new protected owner produces deny-on-collision');
        echo "ESP67_FLOCK_DBLOSS_AUTO_RECOVERED\n";
    }
    flock($lock,LOCK_UN);fclose($lock);exit(0);
}
if($phase==='verify'){
    fl67_check(file_exists($written),'writer finished the actual WP write');
    $db=fl67_db();
    $row=$db->query("SELECT meta_value FROM {$wpdb->postmeta} WHERE
        post_id=$id AND meta_key='_wp_attachment_metadata' LIMIT 1")->fetch_assoc();
    $db->close();
    $meta=$row?maybe_unserialize($row['meta_value']):null;
    fl67_check(($meta['sizes']['esp67_flock']['file']??null)===$public,
        'real Core canonical write committed after liveness DB session loss');
    $s=fl67_get($state,$id);
    fl67_check($s['esp67_flock_ids']==='["FLOCK"]'&&$s['status']==='dirty',
        'ticket remains until explicit safe auto-recovery');
    echo "ESP67_FLOCK_LATE_WRITER_FINISHED\n";exit(0);
}
fl67_check($phase==='writer','PHP worker launched');
$fh=fopen($lockfile,'c+');
fl67_check(is_resource($fh)&&flock($fh,LOCK_SH),'PHP writer acquired shared lifetime lock');
file_put_contents($pidfile,(string)getmypid());
$liveness=fl67_db();
fl67_check((int)$liveness->query("SELECT GET_LOCK('esp67_fl_live',0) AS got")
    ->fetch_assoc()['got']===1,'optional MySQL liveness acquired');
file_put_contents($sessionfile,(string)$liveness->thread_id);
$m=wp_get_attachment_metadata($id,true);
fl67_check(is_array($m)&&!isset($m['sizes']['esp67_flock']),
    'before update, canonical protected owner has no new derivative');
$m['sizes']['esp67_flock']=['file'=>$public,'width'=>150,'height'=>150,
    'mime-type'=>'image/jpeg'];
remove_filter('update_post_metadata',['ESP_Media_Index','guard_update_meta'],5);
remove_action('updated_post_meta',['ESP_Media_Index','on_meta_saved'],20);
$pre=static function($check,$post_id,$meta_key)use(
    $id,$state,$files,$public,$ready,$resume,$scenario
){
    if((int)$post_id!==$id||$meta_key!=='_wp_attachment_metadata')return $check;
    $db=fl67_db();$db->begin_transaction();
    try {
        $r=$db->query("SELECT revision,pending_count,esp67_flock_ids
            FROM $state WHERE attachment_id=$id FOR UPDATE")->fetch_assoc();
        fl67_check((int)$r['pending_count']===0&&$r['esp67_flock_ids']==='[]',
            'new writer starts with clean mutation ticket set');
        $rev=(int)$r['revision']+1;
        $db->query("UPDATE $state SET status='dirty',revision=$rev,pending_count=1,
            esp67_flock_ids='[\"FLOCK\"]' WHERE attachment_id=$id");
        $hash=hash('sha256',$public);$escaped=$db->real_escape_string($public);
        $db->query("INSERT INTO $files
             (path_hash,file_path,attachment_id,generation,protected_path_id)
             VALUES('$hash','$escaped',$id,$rev,'')");
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    finally{$db->close();}
    if(in_array($scenario,['trigger-normal','network-trigger','trigger-reconnect'],true)){
        // Bind a per-writer identity on the VERY SAME backend connection
        // that will run Core's SQL. A wpdb reconnect drops this session var,
        // so reconnect replay fails closed unless explicitly re-registered.
        global $wpdb;
        fl67_check($wpdb->query("SET @esp67_wp_actor='FLOCK'")!==false,
            'real WordPress DB session carries actor ID for trigger');
    }
    if($scenario==='trigger-reconnect'){
        // A real WordPress Core reconnect loses MySQL session variables.
        // The trigger must reject the replay; observe user-visible failure.
        $backend=(int)$wpdb->get_var('SELECT CONNECTION_ID()');
        fl67_check($backend>0,'original wpdb connection identified');
        $killer=fl67_db();
        fl67_check($killer->query('KILL CONNECTION '.$backend)!==false,
            'killed original wpdb connection just before Core SQL');
        $killer->close();
        echo "ESP67_TRIGGER_WPDB_SESSION_KILLED\n";
    }
    file_put_contents($ready,'ready');
    $until=microtime(true)+60;
    while(!file_exists($resume)&&microtime(true)<$until)usleep(100000);
    fl67_check(file_exists($resume),'writer resumed after supervisor approval');
    return $check;
};
add_filter('update_post_metadata',$pre,3,5);
if(in_array($scenario,['network-unsafe','network-gated','network-killed','network-trigger'],true)){
    $endpoint=getenv('ESP67_FLOCK_PROXY_HOST');
    if(!is_string($endpoint)||!str_contains($endpoint,':'))
        throw new RuntimeException('missing disposable network relay');
    [$host,$port]=explode(':',$endpoint,2);
    if($wpdb->dbh instanceof mysqli)$wpdb->dbh->close();
    $transport=mysqli_init();
    $transport->options(MYSQLI_OPT_CONNECT_TIMEOUT,5);
    $transport->options(MYSQLI_OPT_READ_TIMEOUT,30);
    if(!$transport->real_connect($host,DB_USER,DB_PASSWORD,DB_NAME,(int)$port))
        throw new RuntimeException('cannot connect to disposable MySQL relay');
    $wpdb->dbh=$transport;
    if($scenario==='network-killed') {
        $backend=(int)$wpdb->get_var('SELECT CONNECTION_ID()');
        if($backend<=0)throw new RuntimeException('missing MySQL backend id');
        file_put_contents("$root/backend-session",(string)$backend);
    }
    $gate=static function($sql)use($wpdb,$state,$id,$scenario){
        if(!str_starts_with(ltrim($sql),'UPDATE ')||
            stripos($sql,$wpdb->postmeta)===false)return $sql;
        if(!preg_match('/^UPDATE\s+.+?\s+SET\s+(.+)\s+WHERE\s+(.+)$/is',
            trim($sql),$matches))return $sql;
        if(!str_contains($matches[2],'post_id')||
            !str_contains($matches[2],"'_wp_attachment_metadata'"))return $sql;
        if($scenario==='network-gated'){
            return "UPDATE {$wpdb->postmeta} AS m
                INNER JOIN $state AS s ON s.attachment_id=$id
                SET ".$matches[1]." WHERE (".$matches[2].")
                AND s.status='dirty' AND s.pending_count=1
                AND BINARY s.esp67_flock_ids=BINARY '[\"FLOCK\"]'
                /* ESP67_DELAY_SQL_PACKET */";
        }
        return $sql.' /* ESP67_DELAY_SQL_PACKET */';
    };
    add_filter('query',$gate,PHP_INT_MAX,1);
}
if($scenario==='inflight'){
    // Actual wpdb::query UPDATE from update_post_meta(). Inject a delay
    // INSIDE the server-side SET expression after InnoDB has the row.
    // An independent coordinator kills PHP after observing this SQL live.
    $slow=static function($sql)use($wpdb){
        if(!str_starts_with(ltrim($sql),'UPDATE ')||
           stripos($sql,$wpdb->postmeta)===false)return $sql;
        $matches=[];
        if(!preg_match('/^(UPDATE\s+.+?\s+SET\s+)(.+?)(\s+WHERE\s+.+)$/is',
            trim($sql),$matches))return $sql;
        // Core SQL uses SET meta_value=<serialized> WHERE...
        if(!preg_match('/^\x60?meta_value\x60?\s*=\s*(.+)$/is',
            $matches[2],$assignment))return $sql;
        return $matches[1]."\x60meta_value\x60 = IF(SLEEP(5)=0, ".
            $assignment[1].", \x60meta_value\x60)".$matches[3].
            ' /* ESP67_FLOCK_SQL_INFLIGHT */';
    };
    add_filter('query',$slow,PHP_INT_MAX,1);
}
try{$ok=wp_update_attachment_metadata($id,$m);}
finally{
    remove_filter('update_post_metadata',$pre,3);
    if($scenario==='inflight')remove_filter('query',$slow,PHP_INT_MAX);
    if(in_array($scenario,['network-unsafe','network-gated','network-killed'],true))
        remove_filter('query',$gate,PHP_INT_MAX);
    @$liveness->close();
}
if($scenario==='trigger-reconnect'){
    // Core must report its retry as failed (no session token after reconnect).
    fl67_check($ok===false,
        'WordPress Core cannot commit after reconnect lost session token');
    $db=fl67_db();
    $canonical=$db->query("SELECT meta_value FROM {$wpdb->postmeta}
        WHERE post_id=$id AND meta_key='_wp_attachment_metadata'
        ORDER BY meta_id LIMIT 1")->fetch_assoc();
    $db->close();
    $actual=$canonical?maybe_unserialize($canonical['meta_value']):null;
    fl67_check(($actual['sizes']['esp67_flock']['file']??null)!==$public,
        'trigger rejects Core replay instead of writing without an actor ID');
    echo "ESP67_TRIGGER_WPDB_RECONNECT_FAIL_CLOSED\n";
}else{
    fl67_check($ok!==false,'WordPress completed canonical postmeta write');
}
file_put_contents($written,'done');
// The shared flock is deliberately NOT manually released early; the PHP
// request must terminate before exclusive recovery can proceed.
echo "ESP67_FLOCK_WORKER_FINISHED\n";
