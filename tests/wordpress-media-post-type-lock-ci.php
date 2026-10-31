<?php
/**
 * Regression for compatibility post-type transitions when the owner GET_LOCK
 * is held by another real MySQL connection. Disposable WordPress/Apache CI.
 */
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT')) { exit(1); }
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
if (strpos(DB_NAME, 'esp_') !== 0 || !class_exists('ESP_Media_Index')) {
    throw new RuntimeException('R02 requires disposable real WordPress');
}
global $wpdb;
$state = ESP_Media_Index::state_table();
$files = ESP_Media_Index::files_table();
function esp_type_lock_assert($ok, $reason) {
    if (!$ok) { throw new RuntimeException('R02 post-type lock: ' . $reason); }
}
esp_type_lock_assert(ESP_Media_Index::ready(), 'initial index must be READY');
$index_control = $wpdb->get_var("SELECT status FROM $state WHERE attachment_id=0");
esp_type_lock_assert($index_control === 'ready', 'initial SQL control must be READY');

// A normal post may already have canonical attachment metadata. Promotion
// without a reservation must revoke global coverage before Core changes type.
$id = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'draft',
    'post_title' => 'ESP_R02_compat_owner_lock',
], true);
esp_type_lock_assert(!is_wp_error($id) && $id > 0, 'cannot seed normal post');
$id = (int)$id;
esp_type_lock_assert(add_post_meta($id, '_wp_attached_file',
    'esp31-public-150x150.jpg', true) !== false, 'cannot seed attached path');
esp_type_lock_assert(add_post_meta($id, ESP_Media_Index::PROTECTION_META,
    'path_http', true) !== false, 'cannot seed protected owner');

function esp_type_lock_transition($id, $to_type, $strict, $failure = '') {
    global $wpdb, $state;
    $from_type = get_post_type($id);
    esp_type_lock_assert($from_type !== $to_type, 'type transition fixture is invalid');
    esp_type_lock_assert(ESP_Media_Index::ready() &&
        $wpdb->get_var("SELECT status FROM $state WHERE attachment_id=0") === 'ready',
        'each transition must start from global READY');
    update_option(ESP_Media_Index::ENABLE_OPTION, $strict ? '1' : '0', false);
    $namespace = $wpdb->dbname . "\0" . $wpdb->prefix;
    $lock_name = 'esp63_' . substr(hash('sha256', $namespace . "\0" . $id), 0, 40);
    $peer = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $held = $peer->query("SELECT GET_LOCK('" . $peer->real_escape_string($lock_name) . "',0)");
    esp_type_lock_assert($held && (int)$held->fetch_row()[0] === 1,
        'independent connection did not acquire owner lock');
    $saw_prewrite_fence = false;
    $after_guard = static function ($post_id, $data) use (
        $id, $from_type, $to_type, &$saw_prewrite_fence, $state, $wpdb
    ) {
        if ((int)$post_id !== $id || ($data['post_type'] ?? '') !== $to_type) { return; }
        $saw_prewrite_fence = true;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status,pending_count FROM $state WHERE attachment_id=%d", $id), ARRAY_A);
        esp_type_lock_assert($row && $row['status'] === 'dirty' &&
            (int)$row['pending_count'] === 1, 'pending fence missing before Core UPDATE');
        esp_type_lock_assert(get_post_type($id) === $from_type,
            'Core changed post_type before guard completed');
        esp_type_lock_assert($wpdb->get_var(
            "SELECT status FROM $state WHERE attachment_id=0") === 'dirty',
            'global coverage still READY before Core UPDATE');
        // Cron must not republish global READY while the original post_type
        // is still in effect and this request owns a pending ticket.
        ESP_Media_Index::run_batch();
        esp_type_lock_assert($wpdb->get_var(
            "SELECT status FROM $state WHERE attachment_id=0") === 'dirty' &&
            (int)$wpdb->get_var($wpdb->prepare(
                "SELECT pending_count FROM $state WHERE attachment_id=%d", $id)) === 1,
            'Cron republished or consumed an in-flight transition');
    };
    if (!$strict && $failure === '') {
        add_action('pre_post_update', $after_guard, 20, 2);
    }
    $blocked_writes = 0;
    $inject = static function ($sql) use ($failure, $state, &$blocked_writes) {
        if ($failure === 'global' &&
            strpos($sql, "UPDATE $state SET status='dirty',revision=revision+1") !== false &&
            strpos($sql, 'audit_cursor=0 WHERE attachment_id=0') !== false) {
            ++$blocked_writes;
            return 'R02_INJECT_FAILED_GLOBAL_INVALIDATION';
        }
        if ($failure === 'pending' &&
            strpos($sql, "INSERT INTO $state") !== false &&
            strpos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
            ++$blocked_writes;
            return 'R02_INJECT_FAILED_PENDING_FENCE';
        }
        return $sql;
    };
    if ($failure !== '') { add_filter('query', $inject, 1); }
    $old_suppression = $wpdb->suppress_errors(true);
    $exception = null;
    $result = null;
    try {
        $result = wp_update_post([
            'ID' => $id,
            'post_type' => $to_type,
            'post_status' => $to_type === 'attachment' ? 'inherit' : 'draft',
            'post_mime_type' => $to_type === 'attachment' ? 'image/jpeg' : '',
        ], true);
    } catch (RuntimeException $e) {
        $exception = $e;
    } finally {
        $wpdb->suppress_errors($old_suppression);
        if ($failure !== '') { remove_filter('query', $inject, 1); }
        remove_action('pre_post_update', $after_guard, 20);
        $peer->query("SELECT RELEASE_LOCK('" . $peer->real_escape_string($lock_name) . "')");
        $peer->close();
    }
    if (!$strict && $failure === '') {
        esp_type_lock_assert($exception === null && !is_wp_error($result) &&
            (int)$result === $id && $saw_prewrite_fence &&
            get_post_type($id) === $to_type,
            'compatibility transition did not finish under lock contention');
        esp_type_lock_assert(get_option(ESP_Media_Index::READY_OPTION) === '0',
            'compatible fallback did not persist global invalidation');
    } else {
        esp_type_lock_assert($exception instanceof RuntimeException &&
            !$saw_prewrite_fence && get_post_type($id) === $from_type,
            'strict or failed fallback must stop before Core UPDATE');
        if ($failure !== '') {
            esp_type_lock_assert($blocked_writes === 1,
                'fault injection did not hit the intended fence');
        }
        esp_type_lock_assert($wpdb->get_var(
            "SELECT status FROM $state WHERE attachment_id=0") === 'ready',
            'rejected transition must not change global READY');
    }
    ESP_Media_Index::finish_noop_writes();
    $pending = $wpdb->get_var($wpdb->prepare(
        "SELECT pending_count FROM $state WHERE attachment_id=%d", $id));
    esp_type_lock_assert($pending === null || (int)$pending === 0,
        'request finalizer left an unexpected pending ticket');
    echo 'PASS: R02 owner-lock contention ' .
        ($strict ? 'strict' : 'compat') . ' ' . $from_type . '->' . $to_type .
        ($failure !== '' ? ' fault=' . $failure : '') . "\n";
}

function esp_type_lock_recover() {
    global $wpdb, $state;
    for ($i = 0; $i < 12; ++$i) {
        ESP_Media_Index::run_batch();
        if (ESP_Media_Index::ready() &&
            $wpdb->get_var("SELECT status FROM $state WHERE attachment_id=0") === 'ready') {
            return;
        }
    }
    throw new RuntimeException('R02 global coverage did not recover after completed transition');
}

try {
    esp_type_lock_transition($id, 'attachment', true);
    esp_type_lock_transition($id, 'attachment', false);
    esp_type_lock_recover();
    esp_type_lock_transition($id, 'post', true);
    esp_type_lock_transition($id, 'post', false);
    esp_type_lock_recover();
    esp_type_lock_transition($id, 'attachment', false, 'global');
    esp_type_lock_transition($id, 'attachment', false, 'pending');
    // A failed pre-hook may leave a dirty orphan after its local ticket ends.
    // Cleanup is safe only after the request has finalized.
    ESP_Media_Index::run_batch();
    echo "PASS: R02 compatibility owner-lock fallback covers both directions and write failures\n";
} finally {
    update_option(ESP_Media_Index::ENABLE_OPTION, '1', false);
    wp_delete_post($id, true);
}
