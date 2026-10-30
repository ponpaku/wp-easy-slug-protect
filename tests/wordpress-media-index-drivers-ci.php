<?php
/**
 * Real WordPress/Apache CI driver exercises initial schema bootstrap,
 * authenticated AJAX slices, invalid requests, and Cron lock contention.
 *
 * Each invocation runs in a fresh PHP worker. Disposable esp_* DB only.
 * ESP_INDEX_DRIVER_PHASE=bootstrap|ajax-allowed|ajax-denied|ajax-bad-nonce|cron-lock|db-unsupported|ajax-unsupported|repair-seed|repair-check|repair-verify
 */
$phase = getenv('ESP_INDEX_DRIVER_PHASE');
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' ||
    !getenv('ESP_WP_ROOT') ||
    !in_array($phase, ['bootstrap', 'ajax-allowed', 'ajax-denied', 'ajax-bad-nonce', 'cron-lock',
        'db-unsupported', 'ajax-unsupported', 'repair-seed', 'repair-check',
        'repair-verify'], true)) {
    exit(1);
}
define('WP_ADMIN', true);
define('DOING_AJAX', true);
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
if (strpos(DB_NAME, 'esp_') !== 0 ||
    !class_exists('ESP_Media_Index') ||
    !has_action('wp_ajax_esp_media_index_build_slice')) {
    throw new RuntimeException('Unexpected WordPress media-index CI environment');
}

function esp_index_driver_assert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
wp_set_current_user(1);

// On a real WordPress+MySQL fixture, spoof only SELECT VERSION() to exercise
// old-server rejection without running an end-of-life MySQL daemon in CI.
// Separate PHP workers prevent the request-local capability cache leaking.
if ($phase === 'db-unsupported' || $phase === 'ajax-unsupported') {
    add_filter('query', static function ($sql) {
        return trim($sql) === 'SELECT VERSION()' ? "SELECT '5.6.51'" : $sql;
    });
}
if ($phase === 'db-unsupported') {
    $before = ESP_Media_Index::build_status(false);
    esp_index_driver_assert($before['installed'] && !$before['database_supported'] &&
        !$before['ready'] && $before['error'] &&
        $before['database_version'] === '5.6.51',
        'unsupported DB falsely reported safe index coverage');
    esp_index_driver_assert(ESP_Media_Index::install_schema() === false,
        'unsupported DB unexpectedly installed or upgraded index schema');
    global $wpdb;
    $table = ESP_Media_Index::state_table();
    $control_before = $wpdb->get_row("SELECT audit_cursor,revision,status FROM $table WHERE attachment_id=0", ARRAY_A);
    $queries = [];
    $observer = static function ($sql) use (&$queries) {
        $queries[] = $sql;
        return $sql;
    };
    add_filter('query', $observer, 99);
    ESP_Media_Index::run_batch();
    remove_filter('query', $observer, 99);
    esp_index_driver_assert(!array_filter($queries, static function ($sql) {
        return strpos($sql, 'GET_LOCK(') !== false;
    }), 'unsupported DB attempted to acquire named lock');
    $control_after = $wpdb->get_row("SELECT audit_cursor,revision,status FROM $table WHERE attachment_id=0", ARRAY_A);
    esp_index_driver_assert($control_before === $control_after,
        'unsupported DB unexpectedly advanced index cursor');

    // Even if strict indexing had been enabled before a DB downgrade, it
    // must reject index authorization and must not fall back to legacy.
    $old_option = get_option(ESP_Media_Index::ENABLE_OPTION, '0');
    try {
        update_option(ESP_Media_Index::ENABLE_OPTION, '1', false);
        esp_index_driver_assert(
            ESP_Media_Index::resolve(wp_upload_dir()['basedir'] .
                '/esp31-protected-150x150.jpg')['status'] === 'error',
            'strict index authorized protected media on unsupported DB');
    } finally {
        update_option(ESP_Media_Index::ENABLE_OPTION, $old_option, false);
    }
    echo "PASS: simulated legacy MySQL rejects index setup/scan/resolve and preserves strict fail-closed\n";
    exit(0);
}

if (in_array($phase, ['repair-seed', 'repair-check', 'repair-verify'], true)) {
    global $wpdb;
    $state = ESP_Media_Index::state_table();
    $id = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type='attachment' AND post_title=%s LIMIT 1",
        'ESP31 public JPEG'));
    esp_index_driver_assert($wpdb->last_error === '' && $id > 0,
        'F02 missing disposable public JPEG attachment');
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT status,pending_count,retry_after,active_generation
         FROM $state WHERE attachment_id=%d", $id), ARRAY_A);
    esp_index_driver_assert(is_array($row), 'F02 missing indexed owner');
    $ready = ESP_Media_Index::build_status(false);
    esp_index_driver_assert($ready['ready'] && $ready['database_supported'],
        'F02 expected global READY and supported DB');
    if ($phase === 'repair-seed') {
        esp_index_driver_assert($row['status'] === 'ready' &&
            (int)$row['pending_count'] === 0, 'F02 expected healthy owner before test');
        // Simulate a single interrupted metadata-change fence while keeping
        // the WordPress canonical metadata unchanged. No production data.
        $written = $wpdb->query($wpdb->prepare(
            "UPDATE $state SET status='dirty', revision=revision+1,
               pending_count=0,retry_after=0,changed_at=%d
             WHERE attachment_id=%d AND status='ready'",
            time(), $id));
        esp_index_driver_assert($written === 1 && $wpdb->last_error === '',
            'F02 cannot seed dirty owner');
        delete_transient('esp_media_index_progress_counts_v1');
        $status = ESP_Media_Index::build_status();
        esp_index_driver_assert($status['ready'] && $status['dirty'] > 0 &&
            $status['repairable_dirty'], 'F02 READY+eligible DIRTY not reported');
        // Backoff prevents early repair even from manual AJAX or Cron.
        $wpdb->query($wpdb->prepare(
            "UPDATE $state SET retry_after=%d WHERE attachment_id=%d", time() + 3600, $id));
        $deferred = ESP_Media_Index::build_status(false);
        esp_index_driver_assert(!$deferred['repairable_dirty'] && $deferred['ready'],
            'F02 future retry_after must not be considered repairable');
        ESP_Media_Index::run_batch();
        $postpone = $wpdb->get_row($wpdb->prepare(
            "SELECT status,retry_after FROM $state WHERE attachment_id=%d", $id), ARRAY_A);
        esp_index_driver_assert($postpone['status'] === 'dirty' &&
            (int)$postpone['retry_after'] > time(),
            'F02 backoff was improperly overridden');
        // An in-flight mutation must likewise remain untouched.
        $wpdb->query($wpdb->prepare(
            "UPDATE $state SET pending_count=1,retry_after=0 WHERE attachment_id=%d", $id));
        $pending = ESP_Media_Index::build_status(false);
        esp_index_driver_assert(!$pending['repairable_dirty'] && $pending['ready'],
            'F02 in-flight mutation must not be considered repairable');
        ESP_Media_Index::run_batch();
        $still_dirty = $wpdb->get_row($wpdb->prepare(
            "SELECT status,pending_count FROM $state WHERE attachment_id=%d", $id), ARRAY_A);
        esp_index_driver_assert($still_dirty['status'] === 'dirty' &&
            (int)$still_dirty['pending_count'] === 1,
            'F02 in-flight mutation was incorrectly completed by scan');
        // Clear ONLY the artificial test pending and retry flags.
        $wpdb->query($wpdb->prepare(
            "UPDATE $state SET pending_count=0,retry_after=0 WHERE attachment_id=%d", $id));
        esp_index_driver_assert(ESP_Media_Index::build_status(false)['repairable_dirty'],
            'F02 due dirty attachment must become repairable');
        delete_transient('esp_media_index_progress_counts_v1');
        echo "PASS: F02 global READY with actionable dirty, backoff and pending guards\n";
        exit(0);
    }
    if ($phase === 'repair-check') {
        delete_transient('esp_media_index_progress_counts_v1');
        $status = ESP_Media_Index::build_status();
        esp_index_driver_assert($status['ready'] && $status['dirty'] > 0 &&
            $status['repairable_dirty'] && $row['status'] === 'dirty',
            'F02 manual repair not available after global READY');
        // Exercise the actual admin HTML, not just a copied button predicate.
        if (!class_exists('ESP_Admin_Menu')) {
            throw new RuntimeException('F02 admin settings menu was not loaded');
        }
        ob_start();
        try {
            (new ESP_Admin_Menu())->render_settings_page();
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        esp_index_driver_assert(
            preg_match('/<button\b[^>]*id="esp-media-index-build"[^>]*>/s', $html, $button) === 1 &&
            !preg_match('/\bdisabled(?:\s|=|>)/', $button[0]),
            'F02 settings page still disables manual repair after READY');
        echo "PASS: F02 WordPress settings HTML enables repair button while global READY\n";
        exit(0);
    }
    esp_index_driver_assert($row['status'] === 'ready' &&
        (int)$row['pending_count'] === 0,
        'F02 authorized AJAX did not restore dirty attachment');
    $status = ESP_Media_Index::build_status(false);
    esp_index_driver_assert($status['ready'] && !$status['repairable_dirty'],
        'F02 repaired owner still appears eligible for rebuilding');
    $generation = (int)$row['active_generation'];
    ESP_Media_Index::run_batch();
    $after = $wpdb->get_row($wpdb->prepare(
        "SELECT status,active_generation FROM $state WHERE attachment_id=%d", $id), ARRAY_A);
    esp_index_driver_assert($after['status'] === 'ready' &&
        (int)$after['active_generation'] === $generation,
        'F02 normal READY audit unnecessarily rewrote healthy attachment');
    echo "PASS: F02 AJAX restored dirty owner; healthy audit remains read-only\n";
    exit(0);
}

if ($phase === 'bootstrap') {
    $before = ESP_Media_Index::build_status(false);
    esp_index_driver_assert(!$before['installed'] && !$before['ready'] && !$before['enabled'],
        'index is expected to be absent and opt-in OFF before first verified admin bootstrap');
    esp_index_driver_assert(ESP_Media_Index::resolve('/no-such-media-file.jpg')['status'] === 'error',
        'uninstalled index cannot report a verified authorization');
    esp_index_driver_assert(has_action('admin_init', ['ESP_Media_Index', 'ensure_schema_on_admin']) !== false,
        'schema initializer is registered for admin_init');
    do_action('admin_init');
    $after = ESP_Media_Index::build_status(false);
    esp_index_driver_assert($after['installed'] && !$after['ready'] && !$after['enabled'],
        'admin_init prepares empty schema but must not activate strict or publish coverage');
    global $wpdb;
    $n = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . ESP_Media_Index::state_table() .
        ' WHERE attachment_id>0');
    esp_index_driver_assert($wpdb->last_error === '' && $n === 0,
        'schema bootstrap must not synchronously backfill attachments');
    echo "PASS: initial admin bootstrap creates empty schema, preserves strict OFF and non-READY\n";
    exit(0);
}
if ($phase === 'cron-lock') {
    global $wpdb;
    $lock_name = 'esp63_scan_' .
        substr(hash('sha256', $wpdb->dbname . "\0" . $wpdb->prefix), 0, 32);
    // A separate MySQL connection holds exactly the lock used by Cron/AJAX/CLI.
    $other = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $other->query("SET SESSION lock_wait_timeout = 5");
    $stmt = $other->prepare('SELECT GET_LOCK(?,0)');
    $stmt->bind_param('s', $lock_name);
    $stmt->execute();
    $got = (int)$stmt->get_result()->fetch_row()[0];
    esp_index_driver_assert($got === 1, 'other worker failed to acquire scan lock');
    $stmt->close();
    try {
        $table = ESP_Media_Index::state_table();
        $before = $wpdb->get_row("SELECT audit_cursor,revision,status FROM $table WHERE attachment_id=0", ARRAY_A);
        esp_index_driver_assert(is_array($before), 'missing index control row');
        ESP_Media_Index::run_batch();
        $after = $wpdb->get_row("SELECT audit_cursor,revision,status FROM $table WHERE attachment_id=0", ARRAY_A);
        esp_index_driver_assert($wpdb->last_error === '' && $before === $after,
            'Cron advanced the cursor while another session held the scan lock');
    } finally {
        $other->query('SELECT RELEASE_LOCK(' . "'" . $other->real_escape_string($lock_name) . "'" . ')');
        $other->close();
    }
    ESP_Media_Index::run_batch();
    echo "PASS: separate MySQL session blocks Cron scan; released lock allows normal batch\n";
    exit(0);
}

if ($phase === 'ajax-denied') {
    wp_set_current_user(0);
    $_POST['nonce'] = $_REQUEST['nonce'] = 'unauthorized';
} elseif ($phase === 'ajax-bad-nonce') {
    $_POST['nonce'] = $_REQUEST['nonce'] = 'invalid';
} else {
    $_POST['nonce'] = $_REQUEST['nonce'] = wp_create_nonce('esp_media_index_build');
}
do_action('wp_ajax_esp_media_index_build_slice');
// wp_send_json_* exits. If this returns, the registered AJAX driver did not run.
throw new RuntimeException('Media-index admin AJAX handler did not terminate the request');
