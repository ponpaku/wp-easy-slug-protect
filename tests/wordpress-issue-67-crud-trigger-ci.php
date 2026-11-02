<?php
/**
 * #67: actual WordPress add/update/delete_post_meta() plus MySQL triggers.
 * This disposable TEST-ONLY guard table is not a production schema proposal.
 */
declare(strict_types=1);
if(getenv('ESP_ALLOW_DISPOSABLE_DB')!=='1'||!getenv('ESP_WP_ROOT'))exit(1);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require rtrim(getenv('ESP_WP_ROOT'),'/').'/wp-load.php';
if(strpos(DB_NAME,'esp_')!==0||!class_exists('ESP_Media_Index')||
   !ESP_Media_Index::enabled())throw new RuntimeException('disposable WP only');
global $wpdb;
$id=(int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts}
 WHERE post_type='attachment' AND post_title=%s LIMIT 1",'ESP31 protected JPEG'));
if(!$id)throw new RuntimeException('no attachment fixture');
$gate=$wpdb->prefix.'esp67_crud_gate_probe';
$key='_esp67_crud_probe';
$previous=$wpdb->suppress_errors(true);
$db=new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);
$names=[];
function c67_assert(bool $valid,string $label):void {
 if(!$valid)throw new RuntimeException('ESP67 CRUD gate: '.$label);
 echo "PASS ESP67 CRUD GATE: $label\n";
}
try {
 delete_post_meta($id,$key);
 $db->query("CREATE TABLE $gate(
    owner_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    actor VARCHAR(80) NOT NULL) ENGINE=InnoDB");
 $db->query("INSERT INTO $gate VALUES($id,'ACTOR_A')");
 foreach(['INSERT','UPDATE','DELETE'] as $event){
    $tr=$wpdb->prefix.'esp67_crud_'.strtolower($event);
    $names[]=$tr;
    $target=$event==='DELETE'?'OLD':'NEW';
    $ddl="CREATE TRIGGER $tr BEFORE $event ON {$wpdb->postmeta}
    FOR EACH ROW BEGIN
      DECLARE n INT DEFAULT 0;
      IF $target.post_id=$id AND $target.meta_key='$key' THEN
        SELECT COUNT(*) INTO n FROM $gate
          WHERE owner_id=$id AND actor=@esp67_actor_id FOR UPDATE;
        IF n<>1 THEN
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ESP67 actor denied';
        END IF;
      END IF;
    END";
    c67_assert($db->query($ddl)!==false,
        "installed $event server execution gate: ".$db->error);
 }
 c67_assert($wpdb->query("SET @esp67_actor_id='ACTOR_A'")!==false,
    'WordPress caller sets per-writer identity');
 c67_assert(add_post_meta($id,$key,'created',true)!==false,
    'Core add_post_meta INSERT passes valid actor');
 c67_assert(get_post_meta($id,$key,true)==='created',
    'valid INSERT changed canonical metadata');
 c67_assert(update_post_meta($id,$key,'updated')!==false,
    'Core update_post_meta UPDATE passes valid actor');
 c67_assert(get_post_meta($id,$key,true)==='updated',
    'valid UPDATE changed canonical metadata');
 c67_assert($wpdb->query("SET @esp67_actor_id=NULL")!==false,
    'simulate missing actor after DB reconnect');
 $no_insert=add_post_meta($id,$key,'invalid_insert',false);
 $no_update=update_post_meta($id,$key,'invalid_update');
 $no_delete=delete_post_meta($id,$key);
 wp_cache_delete($id,'post_meta');
 c67_assert($no_insert===false&&$no_update===false&&$no_delete===false,
    'Core INSERT, UPDATE and DELETE refuse missing actor');
 c67_assert(get_post_meta($id,$key,true)==='updated'&&
    count(get_post_meta($id,$key,false))===1,
    'denied operations cannot alter canonical metadata');
 c67_assert($wpdb->query("SET @esp67_actor_id='ACTOR_A'")!==false,
    'rebind actor on healthy WordPress DB connection');
 c67_assert(delete_post_meta($id,$key)!==false,
    'Core delete_post_meta DELETE passes valid actor');
 c67_assert(!metadata_exists('post',$id,$key),
    'valid DELETE removed metadata');
 $db->query("DELETE FROM $gate WHERE owner_id=$id");
 $denied=add_post_meta($id,$key,'revoked',true);
 c67_assert($denied===false,
    'revoked actor cannot INSERT with stale session identity');
 echo "ESP67_CRUD_SERVER_SIDE_FENCE_VERIFIED\n";
} finally {
 foreach(array_reverse($names) as $tr)$db->query("DROP TRIGGER IF EXISTS $tr");
 $db->query("DROP TABLE IF EXISTS $gate");
 $db->close();
 $wpdb->suppress_errors($previous);
 delete_post_meta($id,$key);
}
