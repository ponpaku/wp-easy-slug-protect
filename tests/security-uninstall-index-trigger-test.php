<?php
/** #67 uninstall: Core triggers must not outlive plugin or lose their state. */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/../');
define('WP_UNINSTALL_PLUGIN',true);
define('DB_NAME','esp_uninstall_test');
$root=sys_get_temp_dir().'/esp67-uninstall-'.bin2hex(random_bytes(4));
mkdir($root,0700);
class ESP67_Uninstall_DB {
    public $prefix='wp_';
    public $postmeta='wp_postmeta';
    public $queries=[];
    public $fail_trigger=false;
    public $last_error='';
    public function query($sql) {
        $this->queries[]=$sql;
        if ($this->fail_trigger && strpos($sql,'DROP TRIGGER ')===0) return false;
        return 1;
    }
    public function delete($table,$args) { return 1; }
}
$wpdb=new ESP67_Uninstall_DB();
function plugin_dir_path($file) {return dirname($file).'/';}
function wp_upload_dir() {return ['basedir'=>$GLOBALS['root'],'error'=>''];}
function delete_option($key) {return true;}
function delete_site_transient($key) {return true;}
function delete_transient($key) {return true;}
function wp_clear_scheduled_hook($key) {return true;}
function ok67($ok,$note) {
    if(!$ok)throw new RuntimeException('ESP67 UNINSTALL: '.$note);
    echo "PASS ESP67 UNINSTALL: $note\n";
}
$GLOBALS['root']=$root;
try {
    require dirname(__DIR__).'/uninstall.php';
    $base='esp67_'.substr(hash('sha256',DB_NAME."\0".$wpdb->prefix),0,16);
    $triggerDrops=[];
    $indexDrops=[];
    foreach($wpdb->queries as $sql) {
        if(strpos($sql,'DROP TRIGGER')===0) $triggerDrops[]=$sql;
        if(strpos($sql,'DROP TABLE')===0 &&
            strpos($sql,'esp_media_')!==false) $indexDrops[]=$sql;
    }
    ok67(count($triggerDrops)===6,
        'exactly six ESP triggers are removed, not other Core triggers');
    foreach(['pi','pu','pd','mi','mu','md'] as $suffix) {
        ok67(in_array('DROP TRIGGER IF EXISTS '.$base.'_'.$suffix,
            $triggerDrops,true),'deterministic trigger drop '.$suffix);
    }
    ok67(count($indexDrops)===4,
        'two current and two legacy index table names are cleaned');
    $wpdb->queries=[];
    $wpdb->fail_trigger=true;
    ok67(!esp_uninstall_cleanup_media_index() &&
        count($wpdb->queries)===1 &&
        strpos($wpdb->queries[0],'DROP TRIGGER')===0,
        'failed trigger removal never drops state needed by survivors');
    echo "ESP67_UNINSTALL_TRIGGER_CLEANUP_VERIFIED\n";
} finally {
    @rmdir($root);
}
