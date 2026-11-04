<?php
/**
 * #67: the actual PRODUCT triggers on MySQL 8 and MariaDB 10.11.
 *
 * The older one-column PoC runs a different set of triggers and is not a
 * substitute for testing ESP_Media_Mutation_State::install_triggers().
 * Only runs against disposable esp_* CI databases.
 */
declare(strict_types=1);
$database = getenv('ESP_TEST_DB_NAME') ?: 'esp_test';
if (!preg_match('/^esp_[a-zA-Z0-9_]+$/D', $database)) {
    throw new RuntimeException('Refusing a non-disposable database');
}
define('ABSPATH', __DIR__ . '/');
define('DB_NAME', $database);
define('ARRAY_A', 'ARRAY_A');
final class ESP67_Product_WPDB {
    public $prefix='esp67_product_';
    public $posts='esp67_product_posts';
    public $postmeta='esp67_product_postmeta';
    public $last_error='';
    private $db;
    public function __construct(mysqli $db) { $this->db=$db; }
    public function prepare($sql,...$args) {
        if (count($args)===1 && is_array($args[0])) $args=$args[0];
        $at=0;
        $result=preg_replace_callback('/%[ds]/',function($m)use($args,&$at){
            if (!array_key_exists($at,$args)) throw new RuntimeException('Missing SQL arg');
            $v=$args[$at++];
            return $m[0]==='%d' ? (string)(int)$v :
                "'" . $this->db->real_escape_string((string)$v) . "'";
        },$sql);
        if ($at!==count($args)) throw new RuntimeException('Extra SQL args');
        return $result;
    }
    public function query($sql) {
        $this->last_error='';
        try {
            $r=$this->db->query($sql);
            return $r instanceof mysqli_result ? $r : $this->db->affected_rows;
        } catch(Throwable $e) { $this->last_error=$e->getMessage(); return false; }
    }
    public function get_results($sql,$mode=ARRAY_A) {
        $this->last_error='';
        try {
            $r=$this->db->query($sql);
            return $r instanceof mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : [];
        } catch(Throwable $e) { $this->last_error=$e->getMessage(); return null; }
    }
    public function get_row($sql,$mode=ARRAY_A) {
        $rows=$this->get_results($sql,$mode);
        return is_array($rows)&&$rows ? $rows[0] : null;
    }
    public function get_var($sql) {
        $row=$this->get_row($sql);
        return is_array($row) ? reset($row) : null;
    }
    public function get_col($sql) {
        $rows=$this->get_results($sql);
        return is_array($rows) ? array_map('reset',$rows) : null;
    }
}
final class ESP_Media_Index {
    public static function state_table() { return 'esp67_product_state'; }
}
function wp_json_encode($v) { return json_encode($v); }
function maybe_serialize($v) { return is_array($v)||is_object($v) ? serialize($v) : $v; }
require dirname(__DIR__) . '/includes/class-esp-media-mutation-state.php';
function check67($ok,$note) {
    if (!$ok) throw new RuntimeException('PRODUCT TRIGGERS: '.$note);
    echo "PASS PRODUCT TRIGGERS: $note\n";
}
$host=getenv('ESP_TEST_DB_HOST') ?: '127.0.0.1';
$user=getenv('ESP_TEST_DB_USER') ?: 'root';
$password=getenv('ESP_TEST_DB_PASSWORD') ?: '';
$db=new mysqli($host,$user,$password,$database);
$db->set_charset('utf8mb4');
$db->query("SET SESSION sql_mode='STRICT_ALL_TABLES'");
$wpdb=new ESP67_Product_WPDB($db);
$GLOBALS['wpdb']=$wpdb;
$posts=$wpdb->posts;
$meta=$wpdb->postmeta;
$state=ESP_Media_Index::state_table();
function sql67($sql) {
    global $wpdb;
    $r=$wpdb->query($sql);
    if ($r===false || $wpdb->last_error!=='') {
        throw new RuntimeException($wpdb->last_error.' SQL: '.$sql);
    }
    return $r;
}
function state67($id) {
    global $wpdb;
    $s=ESP_Media_Index::state_table();
    return $wpdb->get_row("SELECT status,revision,pending_count,active_mutation_ids
             FROM $s WHERE attachment_id=".(int)$id);
}
function reset_global67() {
    $s=ESP_Media_Index::state_table();
    sql67("UPDATE $s SET status='ready',revision=revision+1,
        audit_cursor=0,pending_count=0,active_mutation_ids='[]'
        WHERE attachment_id=0");
}
try {
    // There are no additional durable product tables: only the existing
    // state and WordPress Core posts/postmeta fixture tables.
    foreach([$meta,$posts,$state] as $table) {
        sql67("DROP TABLE IF EXISTS $table");
    }
    sql67("CREATE TABLE $posts (ID BIGINT UNSIGNED PRIMARY KEY,
        post_type VARCHAR(20) NOT NULL) ENGINE=InnoDB");
    sql67("CREATE TABLE $meta (
        meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id BIGINT UNSIGNED NOT NULL,
        meta_key VARCHAR(191) NOT NULL,
        meta_value LONGTEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    sql67("CREATE TABLE $state (
        attachment_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        status VARCHAR(16) NOT NULL DEFAULT 'dirty',
        revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
        active_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        pending_count INT UNSIGNED NOT NULL DEFAULT 0,
        changed_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        retry_after BIGINT UNSIGNED NOT NULL DEFAULT 0,
        failure_count INT UNSIGNED NOT NULL DEFAULT 0,
        audit_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
        active_mutation_ids LONGTEXT NULL
    ) ENGINE=InnoDB");
    sql67("INSERT INTO $state(attachment_id,status,revision,active_mutation_ids)
         VALUES(0,'ready',1,'[]'),(7,'ready',1,'[]')");
    sql67("INSERT INTO $posts(ID,post_type) VALUES (7,'attachment'),(8,'post')");
    sql67("INSERT INTO $meta(post_id,meta_key,meta_value) VALUES
       (7,'_wp_attached_file','public.jpg'),
       (7,'_esp_media_protected_path_id','private')");

    // Existing third-party AFTER UPDATE triggers must remain intact.
    sql67("CREATE TRIGGER other_product_meta_after AFTER UPDATE ON $meta
      FOR EACH ROW SET @esp67_other_trigger_seen=1");
    check67(ESP_Media_Mutation_State::install_triggers(),
        'six product triggers coexist with a pre-existing Core trigger');
    $installed=$wpdb->get_results('SHOW TRIGGERS',ARRAY_A);
    $ours=array_filter($installed,static function($row)use($posts,$meta){
        return strpos($row['Trigger']??'','esp67_')===0 &&
            in_array($row['Table']??'',[$posts,$meta],true);
    });
    check67(count($ours)===6,'exactly six Core trigger events are installed');

    // Positive: a legitimate PHP-owned protection-only mutation stays local.
    check67(ESP_Media_Mutation_State::begin(7),'owner token registered');
    sql67("UPDATE $meta SET meta_value='new_secret'
        WHERE post_id=7 AND meta_key='_esp_media_protected_path_id'");
    check67(state67(0)['status']==='ready' &&
        (string)$wpdb->get_var('SELECT @esp67_other_trigger_seen')==='1',
        'local valid token and third-party trigger both execute');
    check67(ESP_Media_Mutation_State::finish(7),'owner token retired');

    // Same session, now-stale actor: the canonical UPDATE must commit global
    // DIRTY in the very same transaction.
    sql67("UPDATE $meta SET meta_value='retired_secret'
        WHERE post_id=7 AND meta_key='_esp_media_protected_path_id'");
    check67(state67(0)['status']==='dirty',
        'retired identity co-commits global DIRTY');
    reset_global67();

    // Active actor is still insufficient for an unreserved ownership path.
    check67(ESP_Media_Mutation_State::begin(7),'new active local token');
    sql67("UPDATE $meta SET meta_value='collision.jpg'
        WHERE post_id=7 AND meta_key='_wp_attached_file'");
    check67(state67(0)['status']==='dirty',
        'unreserved direct path SQL cannot borrow a valid actor');
    check67(ESP_Media_Mutation_State::finish(7),'owner token retired after fallback');
    reset_global67();

    // Positive: exact pre-hook value proof permits SQL after path reservation.
    check67(ESP_Media_Mutation_State::begin(7),'reserved path actor registered');
    check67(ESP_Media_Mutation_State::expect_path_meta(
        7,'_wp_attached_file','reserved.jpg'),'exact expected value sent to SQL session');
    sql67("UPDATE $meta SET meta_value='reserved.jpg'
        WHERE post_id=7 AND meta_key='_wp_attached_file'");
    check67(state67(0)['status']==='ready',
        'exact reserved value allows local SQL without global invalidation');
    sql67("UPDATE $meta SET meta_value='unreserved_other.jpg'
        WHERE post_id=7 AND meta_key='_wp_attached_file'");
    check67(state67(0)['status']==='dirty',
        'reusing proof for another value forces global DIRTY');
    check67(ESP_Media_Mutation_State::finish(7),'reserved path token retired');
    reset_global67();

    // A post->attachment promotion has no postmeta operation to intercept.
    sql67("UPDATE $posts SET post_type='attachment' WHERE ID=8");
    check67(state67(0)['status']==='dirty',
        'untracked attachment promotion is globally fenced');
    reset_global67();

    // New owner before PHP obtains its ID: global ticket spans post INSERT.
    check67(ESP_Media_Mutation_State::begin(0),'global creation token reserved');
    sql67("INSERT INTO $posts(ID,post_type) VALUES(9,'attachment')");
    check67(state67(0)['status']==='dirty' &&
        (int)state67(0)['pending_count']===1,
        'new attachment created under global DIRTY ticket');
    check67(ESP_Media_Mutation_State::finish(0),
        'global creation ticket retired, awaiting audit');
    reset_global67();

    // A valid actor's canonical SQL must lock its state row until commit.
    // If SELECT COUNT(*) ... FOR UPDATE inside the trigger were only a
    // snapshot read, a concurrent reaper could retire the ticket between
    // validation and COMMIT, allowing a stale publication race.
    check67(ESP_Media_Mutation_State::begin(7),
        'valid owner actor for state-lock race');
    sql67('START TRANSACTION');
    sql67("UPDATE $meta SET meta_value='lock_probe'
        WHERE post_id=7 AND meta_key='_esp_media_protected_path_id'");
    $lockPeer=new mysqli($host,$user,$password,$database);
    $lockPeer->query('SET SESSION innodb_lock_wait_timeout=1');
    $stateBlocked=false;
    try {
        $lockPeer->query("UPDATE $state SET revision=revision+1
            WHERE attachment_id=7");
    } catch(mysqli_sql_exception $e) {
        $stateBlocked=(int)$e->getCode()===1205;
    }
    check67($stateBlocked,
        'real trigger holds owner state row lock until canonical SQL COMMIT');
    sql67('COMMIT');
    check67($lockPeer->query("UPDATE $state SET revision=revision+1
        WHERE attachment_id=7")===true,
        'state CAS writer proceeds after canonical TX commits');
    $lockPeer->close();
    check67(ESP_Media_Mutation_State::finish(7),
        'state-lock experiment token retires correctly');

    // InnoDB publication: trigger update and canonical update cannot be
    // observed in different committed states by a second DB connection.
    $peer=new mysqli($host,$user,$password,$database);
    $peer->set_charset('utf8mb4');
    sql67('SET @esp_media_mutation_actor=NULL');
    sql67('START TRANSACTION');
    sql67("UPDATE $meta SET meta_value='tx_new'
        WHERE post_id=7 AND meta_key='_esp_media_protected_path_id'");
    $peerBefore=$peer->query("SELECT status FROM $state
        WHERE attachment_id=0")->fetch_assoc()['status'];
    check67($peerBefore==='ready','uncommitted Core SQL has not exposed global DIRTY');
    sql67('COMMIT');
    $peerAfter=$peer->query("SELECT status FROM $state
        WHERE attachment_id=0")->fetch_assoc()['status'];
    $peerMeta=$peer->query("SELECT meta_value FROM $meta
        WHERE post_id=7 AND meta_key='_esp_media_protected_path_id'")->fetch_assoc()['meta_value'];
    check67($peerAfter==='dirty' && $peerMeta==='tx_new',
        'canonical meta and global DIRTY commit atomically');
    reset_global67();
    sql67('START TRANSACTION');
    sql67("UPDATE $meta SET meta_value='tx_rollback'
        WHERE post_id=7 AND meta_key='_esp_media_protected_path_id'");
    sql67('ROLLBACK');
    $peerState=$peer->query("SELECT status FROM $state
        WHERE attachment_id=0")->fetch_assoc()['status'];
    $peerMeta2=$peer->query("SELECT meta_value FROM $meta
        WHERE post_id=7 AND meta_key='_esp_media_protected_path_id'")->fetch_assoc()['meta_value'];
    check67($peerState==='ready' && $peerMeta2==='tx_new',
        'rolled-back Core SQL also rolls back global invalidation');
    $peer->close();

    // No global state row => trigger raises SQLSTATE 45000. Never allow an
    // owner-bearing SQL COMMIT with no durable coverage marker.
    sql67("DELETE FROM $state WHERE attachment_id=0");
    $failed=$wpdb->query("UPDATE $meta SET meta_value='must_not_commit'
        WHERE post_id=7 AND meta_key='_esp_media_protected_path_id'");
    $persist=$wpdb->get_var("SELECT meta_value FROM $meta
        WHERE post_id=7 AND meta_key='_esp_media_protected_path_id'");
    check67($failed===false && $persist==='tx_new',
        'missing global control row causes SQL hard failure, not unsafe COMMIT');

    echo "ESP67_PRODUCT_TRIGGERS_MYSQL_MARIADB_VERIFIED\n";
} finally {
    // MySQL drops the table's triggers with each disposable table.
    foreach([$meta,$posts,$state] as $table) {
        try { $db->query("DROP TABLE IF EXISTS $table"); } catch(Throwable $e) {}
    }
    $db->close();
}
