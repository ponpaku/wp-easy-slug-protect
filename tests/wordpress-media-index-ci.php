<?php
/**
 * Disposable WordPress 7.1.3 integration smoke test for #63.
 * Enabled ONLY in isolated GitHub Actions after the existing media fixture.
 * It must not run on a real WordPress installation.
 */
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT')) { exit(1); }
define('WP_INSTALLING', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
wp_installing(false);
// WP_INSTALLING intentionally skips normal active-plugin boot in this fixture.
// Load the plugin dependencies explicitly, like wordpress-http-prepare.php.
require WP_PLUGIN_DIR . '/esp44-custom/easy-slug-protect.php';
Easy_Slug_Protect::load_dependencies();
// The fixture uses WP_INSTALLING; register the production metadata hooks here.
ESP_Media_Index::register();
if (strpos(DB_NAME, 'esp_') !== 0 ||
    !class_exists('ESP_Media_Index') || !class_exists('ESP_Protection_State')) {
    throw new RuntimeException('Unsafe or missing WP/ESP environment');
}
if (!ESP_Media_Index::install_schema()) {
    throw new RuntimeException('dbDelta failed to create dedicated index schema');
}
ESP_Media_Index::run_batch();
ESP_Media_Index::run_batch();
// Display-only COUNT(*) must not run on every admin/AJAX polling request.
// The authoritative READY/cursor state is always freshly fetched.
$cache_key = 'esp_media_index_progress_counts_v1';
delete_transient($cache_key);
$count_sql_calls = 0;
$watch_count_queries = function ($sql) use (&$count_sql_calls) {
    if (preg_match('/^\s*SELECT COUNT\(\*\)/i', $sql)) { ++$count_sql_calls; }
    return $sql;
};
add_filter('query', $watch_count_queries, 99);
ESP_Media_Index::build_status();
$first_count = $count_sql_calls;
ESP_Media_Index::build_status();
$second_count = $count_sql_calls;
delete_transient($cache_key);
ESP_Media_Index::build_status(false);
$fast_count = $count_sql_calls;
remove_filter('query', $watch_count_queries, 99);
if ($first_count < 2 || $second_count !== $first_count ||
    $fast_count !== $second_count) {
    throw new RuntimeException('Progress polling still reruns expensive count queries');
}
echo "PASS: progress counts cached and CLI lightweight state skips COUNT(*)\n";

if (!ESP_Media_Index::ready()) {
    throw new RuntimeException('index batch did not publish verified initial scan');
}
$uploads = wp_upload_dir();
$path = $uploads['basedir'] . '/esp31-protected-150x150.jpg';
$public = $uploads['basedir'] . '/esp31-public-150x150.jpg';
$index = ESP_Media_Index::resolve($path);
if ($index['status'] !== 'resolved' ||
    ($index['protected_path_id'] ?? null) !== 'path_http') {
    throw new RuntimeException('protected JPEG thumbnail index mismatch: ' . json_encode($index));
}
$public_index = ESP_Media_Index::resolve($public);
if ($public_index['status'] !== 'resolved' ||
    ($public_index['protected_path_id'] ?? null) !== '') {
    throw new RuntimeException('public JPEG thumbnail index mismatch: ' . json_encode($public_index));
}
$manifest = json_decode(file_get_contents($uploads['basedir'] . '/.esp42-pdf-fixture.json'), true);
if (!is_array($manifest)) { throw new RuntimeException('Missing PDF manifest'); }
foreach (['pdf_full','pdf_thumb','image_unique','gif_poster_unique'] as $variant) {
    $result = ESP_Media_Index::resolve($uploads['basedir'] . '/' . $manifest[$variant]);
    if ($result['status'] !== 'resolved' ||
        ($result['protected_path_id'] ?? null) !== 'path_http') {
        throw new RuntimeException($variant . ' ownership/authorization mismatch: ' . json_encode($result));
    }
}
$unknown = $uploads['basedir'] . '/esp63-unregistered.jpg';
file_put_contents($unknown, 'ESP63_UNREGISTERED_BUT_REAL_FILE');

// WordPress native metadata API must invalidate + republish the derived rows.
$id = (int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'
    AND post_title='ESP31 protected JPEG' LIMIT 1");
if ($id <= 0 || !update_post_meta($id, ESP_Media_Index::PROTECTION_META, '')) {
    throw new RuntimeException('Cannot unprotect fixture via normal WP API');
}
if (ESP_Media_Index::resolve($path)['status'] !== 'error' ||
    !ESP_Media_Index::reindex_attachment($id)) {
    throw new RuntimeException('committed WP unprotect requires independent rebuild');
}
$unprotected = ESP_Media_Index::resolve($path);
if ($unprotected['status'] !== 'resolved' || $unprotected['protected_path_id'] !== '') {
    throw new RuntimeException('WP metadata OFF transition not indexed');
}
if (!update_post_meta($id, ESP_Media_Index::PROTECTION_META, 'path_http')) {
    throw new RuntimeException('Cannot reprotect fixture via WP API');
}
if (ESP_Media_Index::resolve($path)['status'] !== 'error' ||
    !ESP_Media_Index::reindex_attachment($id)) {
    throw new RuntimeException('committed WP reprotect requires independent rebuild');
}
$restored = ESP_Media_Index::resolve($path);
if ($restored['status'] !== 'resolved' || $restored['protected_path_id'] !== 'path_http') {
    throw new RuntimeException('WP metadata ON transition not indexed');
}
// A no-op update has a pre-hook but may not have a WordPress after-hook.
update_post_meta($id, ESP_Media_Index::PROTECTION_META, 'path_http');
ESP_Media_Index::finish_noop_writes();
if (!ESP_Media_Index::reindex_attachment($id) ||
    ESP_Media_Index::resolve($path)['status'] !== 'resolved') {
    throw new RuntimeException('No-op metadata update stranded pending state');
}
$meta = wp_get_attachment_metadata($id, true);
$new_name = 'esp63-added-175x175.jpg';
copy($uploads['basedir'] . '/esp31-protected.jpg', $uploads['basedir'] . '/' . $new_name);
$meta['sizes']['esp63_extra'] = ['file'=>$new_name, 'width'=>175,
    'height'=>175, 'mime-type'=>'image/jpeg'];
wp_update_attachment_metadata($id, $meta);
if (!ESP_Media_Index::reindex_attachment($id)) {
    throw new RuntimeException('Cannot rebuild committed subsize index');
}
$new_owner = ESP_Media_Index::resolve($uploads['basedir'] . '/' . $new_name);
if ($new_owner['status'] !== 'resolved' || $new_owner['protected_path_id'] !== 'path_http') {
    throw new RuntimeException('new WP subsize was not indexed on metadata update');
}
// Newly proposed protected subsize must fence a previously public owner
// BEFORE the independent worker publishes the new ownership generation.
$collision = $meta;
$collision['sizes']['esp63_colliding'] = [
    'file'=>basename($public), 'width'=>150, 'height'=>150,
    'mime-type'=>'image/jpeg'
];
if (!wp_update_attachment_metadata($id, $collision) ||
    ESP_Media_Index::resolve($public)['status'] !== 'error') {
    throw new RuntimeException('new protected competing owner was not pre-reserved');
}
if (!ESP_Media_Index::reindex_attachment($id) ||
    ESP_Media_Index::resolve($public)['status'] !== 'ambiguous') {
    throw new RuntimeException('competing protected/public file must be ambiguous');
}
if (!wp_update_attachment_metadata($id, $meta) ||
    !ESP_Media_Index::reindex_attachment($id) ||
    ESP_Media_Index::resolve($public)['status'] !== 'resolved') {
    throw new RuntimeException('failed to restore noncolliding metadata');
}

// Emulate an unrelated plugin doing a no-op AFTER ESP's former shutdown
// priority=1 callback. The final shutdown sweep must clear the ticket.
$late_noop = function () use ($id) {
    update_post_meta($id, ESP_Media_Index::PROTECTION_META, 'path_http');
};
add_action('shutdown', $late_noop, 10);
do_action('shutdown');
remove_action('shutdown', $late_noop, 10);
$late_pending = (int)$wpdb->get_var($wpdb->prepare(
    'SELECT pending_count FROM ' . ESP_Media_Index::state_table() .
    ' WHERE attachment_id=%d', $id));
if ($late_pending !== 0 || !ESP_Media_Index::reindex_attachment($id)) {
    throw new RuntimeException('late shutdown no-op stranded pending completion');
}

// R01: real WordPress API, unique=true must NOT be treated as delete_all.
$public_id = (int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts}
    WHERE post_type='attachment' AND post_title='ESP31 public JPEG' LIMIT 1");
if (!$public_id) { throw new RuntimeException('Missing public attachment for unique hook'); }
foreach (['1', '0'] as $mode) {
    update_option(ESP_Media_Index::ENABLE_OPTION, $mode, false);
    if (!add_post_meta($public_id, ESP_Media_Index::PROTECTION_META, '1', true)) {
        throw new RuntimeException('add_post_meta unique=true rejected for mode ' . $mode);
    }
    if (ESP_Media_Index::ready() !== true) {
        throw new RuntimeException('unique=true incorrectly reset global READY');
    }
    if (!ESP_Media_Index::reindex_attachment($public_id)) {
        throw new RuntimeException('unique=true committed add cannot rebuild');
    }
    if (!update_post_meta($public_id, ESP_Media_Index::PROTECTION_META, 'path_http', true)) {
        throw new RuntimeException('update_post_meta prev_value=true was rejected');
    }
    if (ESP_Media_Index::ready() !== true ||
        !ESP_Media_Index::reindex_attachment($public_id)) {
        throw new RuntimeException('prev_value=true corrupted global coverage');
    }
    if (!delete_post_meta($public_id, ESP_Media_Index::PROTECTION_META) ||
        !ESP_Media_Index::reindex_attachment($public_id)) {
        throw new RuntimeException('failed to restore public attachment');
    }
}

// Review R02: by-mid must not fence ordinary post metadata, even when
// the meta_key resembles WordPress attachment metadata. Verify no overall
// audit epoch or state rows are touched and both update/delete succeed.
$ordinary_id = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'draft',
    'post_title' => 'ESP63 disposable non-attachment by-mid test'
], true);
if (is_wp_error($ordinary_id) || (int)$ordinary_id < 1) {
    throw new RuntimeException('Could not create non-attachment by-mid fixture');
}
$ordinary_id = (int)$ordinary_id;
$control_table = ESP_Media_Index::state_table();
$control_before = $wpdb->get_row("SELECT status,revision,audit_cursor
    FROM $control_table WHERE attachment_id=0", ARRAY_A);
foreach (['_wp_attached_file','_wp_attachment_metadata'] as $ordinary_key) {
    $first = $ordinary_key === '_wp_attached_file'
        ? '2026/10/post-file.txt'
        : ['file'=>'2026/10/post-file.txt'];
    $next = $ordinary_key === '_wp_attached_file'
        ? '2026/11/post-file.txt'
        : ['file'=>'2026/11/post-file.txt'];
    if (!add_post_meta($ordinary_id, $ordinary_key, $first)) {
        throw new RuntimeException('Could not create ordinary post meta');
    }
    $ordinary_mid = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d
         AND meta_key=%s LIMIT 1", $ordinary_id, $ordinary_key));
    if ($ordinary_mid < 1 ||
        !update_metadata_by_mid('post', $ordinary_mid, $next) ||
        get_post_meta($ordinary_id, $ordinary_key, true) !== $next ||
        !delete_metadata_by_mid('post', $ordinary_mid)) {
        throw new RuntimeException('by-mid incorrectly guarded ordinary post: ' . $ordinary_key);
    }
}
$control_after = $wpdb->get_row("SELECT status,revision,audit_cursor
    FROM $control_table WHERE attachment_id=0", ARRAY_A);
$ordinary_state = $wpdb->get_var($wpdb->prepare(
    "SELECT attachment_id FROM $control_table WHERE attachment_id=%d", $ordinary_id));
if ($control_before !== $control_after || $ordinary_state !== null ||
    ESP_Media_Index::resolve($public)['status'] !== 'resolved') {
    throw new RuntimeException('Non-attachment by-mid operation modified media index');
}
wp_delete_post($ordinary_id, true);
echo "PASS: non-attachment by-mid update/delete leave index and READY unchanged\n";

// R02: WP API called under an external transaction may not cause an
// implicit COMMIT. The pre-fence rolls back with the metadata itself.
$probe_table = $wpdb->prefix . 'esp63_transaction_probe';
$wpdb->query("CREATE TABLE $probe_table (id INT PRIMARY KEY) ENGINE=InnoDB");
$wpdb->query('START TRANSACTION');
$wpdb->query("INSERT INTO $probe_table (id) VALUES (1)");
if (!update_post_meta($id, ESP_Media_Index::PROTECTION_META, 'transaction_test')) {
    throw new RuntimeException('external transaction WordPress metadata update failed');
}
if (!ESP_Media_Index::reindex_attachment($id)) {
    throw new RuntimeException('rebuild inside external transaction failed');
}
$wpdb->query('ROLLBACK');
wp_cache_delete($id, 'post_meta');
if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $probe_table") !== 0 ||
    ESP_Media_Index::resolve($path)['status'] !== 'resolved') {
    throw new RuntimeException('external transaction rollback was not atomic');
}
$wpdb->query('START TRANSACTION');
$wpdb->query("INSERT INTO $probe_table (id) VALUES (2)");
if (!update_post_meta($id, ESP_Media_Index::PROTECTION_META, 'transaction_committed')) {
    throw new RuntimeException('external transaction commit fixture failed');
}
$wpdb->query('COMMIT');
if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $probe_table") !== 1 ||
    ESP_Media_Index::resolve($path)['status'] !== 'error' ||
    !ESP_Media_Index::reindex_attachment($id)) {
    throw new RuntimeException('dirty marker not persisted atomically on COMMIT');
}
if (!update_post_meta($id, ESP_Media_Index::PROTECTION_META, 'path_http') ||
    !ESP_Media_Index::reindex_attachment($id)) {
    throw new RuntimeException('failed to restore HTTP protected fixture');
}
$wpdb->query("DROP TABLE $probe_table");
update_option(ESP_Media_Index::ENABLE_OPTION, '1', false);
// Keep strict mode on for the following real Apache curl checks.
update_option(ESP_Media_Index::ENABLE_OPTION, '1', false);

// Admin guidance must also be visible when global READY remains true but
// individual owners await a Cron rebuild. This is a transient/no-op fixture;
// a persistent inconsistent attachment uses the same dirty status indicator.
if (!ESP_Media_Index::ready()) {
    throw new RuntimeException('Cannot test dirty admin notice without READY coverage');
}
wp_set_current_user(1);
if (!current_user_can('manage_options')) {
    throw new RuntimeException('Disposable WP test requires admin user 1');
}
if (update_post_meta($id, ESP_Media_Index::PROTECTION_META, 'path_http') !== false) {
    throw new RuntimeException('Admin notice test expected no-op mutation');
}
$dirty_notice_status = ESP_Media_Index::build_status();
ob_start();
ESP_Media_Index::render_bootstrap_notice();
$dirty_notice_html = ob_get_clean();
if ($dirty_notice_status['dirty'] < 1 ||
    strpos($dirty_notice_html, '再構築待ち') === false) {
    throw new RuntimeException('Admin did not receive media index rebuild notice');
}
ESP_Media_Index::finish_noop_writes();
ESP_Media_Index::run_batch();
if (ESP_Media_Index::resolve($path)['status'] !== 'resolved') {
    throw new RuntimeException('Cron failed to recover admin notice fixture');
}
wp_set_current_user(0);
echo "PASS: admin notice shows unresolved media index work and Cron restores it\\n";

// Q01 diagnostic (disposable CI only): exercise real WordPress wpdb and MySQL.
// No production index states are changed, only a dedicated disposable probe table.
$q01_table = $wpdb->prefix . 'esp_q01_fault_probe';
if (!preg_match('/^[a-zA-Z0-9_]+$/D', $q01_table) ||
    strpos(DB_NAME, 'esp_') !== 0 || getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1') {
    throw new RuntimeException('Q01 fault probe refuses non-disposable WordPress');
}
$q01_procedure = $wpdb->prefix . 'esp_q01_after_write_error';
$q01_old_suppression = $wpdb->suppress_errors(true);
try {
    if ($wpdb->query("CREATE TABLE IF NOT EXISTS $q01_table (
            id int NOT NULL PRIMARY KEY, pending_count int NOT NULL
        ) ENGINE=InnoDB") === false ||
        $wpdb->query("REPLACE INTO $q01_table (id,pending_count) VALUES (1,0)") === false) {
        throw new RuntimeException('Q01 cannot initialize MySQL probe');
    }

    // Kill the existing connection BEFORE sending UPDATE. Core may reconnect
    // and execute the SQL on MySQL error 2006. This is not lost-after-commit ACK.
    $q01_connection = (int)$wpdb->get_var('SELECT CONNECTION_ID()');
    $q01_killer = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($q01_connection <= 0 || !$q01_killer->query('KILL CONNECTION ' . $q01_connection)) {
        throw new RuntimeException('Q01 cannot kill initial MySQL session');
    }
    $q01_killer->close();
    $q01_retry_result = $wpdb->query("UPDATE $q01_table SET pending_count=pending_count+1 WHERE id=1");
    $q01_retry_value = $wpdb->get_var("SELECT pending_count FROM $q01_table WHERE id=1");
    if ($wpdb->last_error !== '' || !in_array((int)$q01_retry_value, [0, 1], true)) {
        throw new RuntimeException('Q01 broken-connection probe has invalid DB result');
    }
    echo 'Q01_PRE_SEND_CONNECTION_KILL ' . wp_json_encode([
        'wpdb_update_return' => $q01_retry_result,
        'stored_pending' => (int)$q01_retry_value,
        'reconnected' => (int)$wpdb->get_var('SELECT CONNECTION_ID()') !== $q01_connection,
    ]) . "\n";

    // This is an actual MySQL autocommit UPDATE followed by SIGNAL. WordPress
    // sees CALL as failed even though the preceding UPDATE was committed.
    // This reproduces ambiguous observation, NOT TCP ACK-loss/2006/2013.
    if ($wpdb->query("REPLACE INTO $q01_table (id,pending_count) VALUES (1,2)") === false ||
        $wpdb->query("DROP PROCEDURE IF EXISTS $q01_procedure") === false ||
        $wpdb->query("CREATE PROCEDURE $q01_procedure()
            BEGIN
                UPDATE $q01_table SET pending_count=pending_count-1
                WHERE id=1 AND pending_count>0;
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Q01 after-write diagnostic error';
            END") === false) {
        throw new RuntimeException('Q01 cannot install MySQL after-write probe');
    }
    $q01_ambiguous_result = $wpdb->query("CALL $q01_procedure()");
    $q01_ambiguous_error = $wpdb->last_error;
    $q01_actual_pending = (int)$wpdb->get_var(
        "SELECT pending_count FROM $q01_table WHERE id=1");
    if ($q01_ambiguous_result !== false || $q01_ambiguous_error === '' ||
        $wpdb->last_error !== '' || $q01_actual_pending !== 1) {
        throw new RuntimeException('Q01 after-write error did not preserve the committed decrement');
    }
    echo 'Q01_POST_WRITE_ERROR ' . wp_json_encode([
        'wpdb_reported_failure' => true,
        'stored_pending' => $q01_actual_pending,
        'error_class' => 'SQLSTATE 45000 (not network ACK loss)',
    ]) . "\n";
    // The optional packet relay forwards an UPDATE to real MySQL, receives
    // the server's successful OK response, then discards it and closes the
    // client socket. Observe actual persisted state through an independent
    // direct wpdb connection. This exercises the real Core reconnect path.
    $q01_proxy_host = getenv('ESP_Q01_PROXY_HOST');
    if ($q01_proxy_host) {
        if ($wpdb->query("REPLACE INTO $q01_table (id,pending_count) VALUES (1,2)") === false) {
            throw new RuntimeException('Q01 cannot reset proxy fixture');
        }
        // mysqli permits a short read timeout; real wpdb's retry behavior
        // after this exact socket fault remains separately unverified.
        [$q01_proxy_name, $q01_proxy_port] = explode(':', $q01_proxy_host, 2);
        $q01_proxy_db = mysqli_init();
        $q01_proxy_db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        $q01_proxy_db->options(MYSQLI_OPT_READ_TIMEOUT, 5);
        if (!$q01_proxy_db->real_connect(
            $q01_proxy_name, DB_USER, DB_PASSWORD, DB_NAME, (int)$q01_proxy_port
        ) || !$q01_proxy_db->query('SELECT 1')) {
            throw new RuntimeException('Q01 MySQL packet proxy handshake failed');
        }
        $q01_tcp_result = false;
        $q01_tcp_error = '';
        $q01_tcp_errno = 0;
        try {
            $q01_tcp_result = $q01_proxy_db->query(
                "UPDATE $q01_table SET pending_count=pending_count-1
                 WHERE id=1 AND pending_count>0 /* ESP_Q01_DROP_ACK */");
            $q01_tcp_error = $q01_proxy_db->error;
            $q01_tcp_errno = $q01_proxy_db->errno;
        } catch (Throwable $q01_exc) {
            $q01_tcp_error = $q01_exc->getMessage();
            $q01_tcp_errno = (int)$q01_exc->getCode();
        }
        $q01_tcp_pending = (int)$wpdb->get_var("SELECT pending_count FROM $q01_table WHERE id=1");
        if ($wpdb->last_error !== '' || $q01_tcp_pending !== 1 ||
            $q01_tcp_result !== false && $q01_tcp_result !== null) {
            throw new RuntimeException('Q01 TCP ACK-loss result inconsistent with one committed UPDATE');
        }
        echo 'Q01_TCP_ACK_LOSS ' . wp_json_encode([
            'client' => 'mysqli (not wpdb)',
            'errno' => $q01_tcp_errno,
            'error' => $q01_tcp_error,
            'stored_pending' => $q01_tcp_pending,
            'double_decrement_observed' => false,
        ]) . "\n";
        // No explicit retry is sent: this tests the actual lost server OK,
        // not WordPress's potential reconnect/re-execute of the same UPDATE.

        // Run the same after-commit TCP ACK-loss against actual WordPress wpdb.
        // Inject a real mysqli transport with a five-second read timeout;
        // on reconnection Core still uses its own standard db_connect path.
        $q01_wpdb_proxy = getenv('ESP_Q01_WPDB_PROXY_HOST');
        if ($q01_wpdb_proxy) {
            if ($wpdb->query("REPLACE INTO $q01_table (id,pending_count) VALUES (1,2)") === false) {
                throw new RuntimeException('Q01 cannot reset wpdb proxy fixture');
            }
            [$q01_wpdb_name, $q01_wpdb_port] = explode(':', $q01_wpdb_proxy, 2);
            $q01_wpdb_test = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, $q01_wpdb_proxy);
            $q01_wpdb_test->suppress_errors(true);
            if (!($q01_wpdb_test->dbh instanceof mysqli)) {
                throw new RuntimeException('Q01 test wpdb did not connect');
            }
            $q01_wpdb_test->dbh->close();
            $q01_timed_link = mysqli_init();
            $q01_timed_link->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            $q01_timed_link->options(MYSQLI_OPT_READ_TIMEOUT, 5);
            if (!$q01_timed_link->real_connect(
                $q01_wpdb_name, DB_USER, DB_PASSWORD, DB_NAME, (int)$q01_wpdb_port
            )) {
                throw new RuntimeException('Q01 timed wpdb transport failed');
            }
            $q01_wpdb_test->dbh = $q01_timed_link;
            if ((int)$q01_wpdb_test->get_var('SELECT 1') !== 1) {
                throw new RuntimeException('Q01 timed wpdb preliminary query failed');
            }
            $q01_wpdb_result = $q01_wpdb_test->query(
                "UPDATE $q01_table SET pending_count=pending_count-1
                 WHERE id=1 AND pending_count>0 /* ESP_Q01_DROP_ACK */");
            $q01_wpdb_error = $q01_wpdb_test->last_error;
            $q01_wpdb_count = (int)$wpdb->get_var("SELECT pending_count FROM $q01_table WHERE id=1");
            if ($wpdb->last_error !== '' || !in_array($q01_wpdb_count, [0, 1], true)) {
                throw new RuntimeException('Q01 wpdb TCP ACK-loss returned an invalid persisted state');
            }
            echo 'Q01_WPDB_ACK_LOSS ' . wp_json_encode([
                'wpdb_return' => $q01_wpdb_result,
                'wpdb_error' => $q01_wpdb_error,
                'stored_pending' => $q01_wpdb_count,
                'double_decrement_observed' => $q01_wpdb_count === 0,
            ]) . "\n";
        }
        // 0 documents a real double decrement, and is deliberately an
        // observational result rather than making diagnostic CI artificially
        // green by hiding the security-relevant value.
    }
    echo "PASS: Q01 real WordPress wpdb / MySQL diagnostic (Core retry, post-write failure)\n";
} finally {
    $wpdb->query("DROP PROCEDURE IF EXISTS $q01_procedure");
    $wpdb->query("DROP TABLE IF EXISTS $q01_table");
    $wpdb->suppress_errors($q01_old_suppression);
}

// Review R02: PHP shutdown callbacks registered AFTER ESP's own shutdown
// finalizer can still perform no-op meta writes. The newly rearmed finalizer
// must run after each late callback, even when the callback chain grows.
register_shutdown_function(function () use ($id, $path) {
    global $wpdb;
    if (update_post_meta($id, ESP_Media_Index::PROTECTION_META, 'path_http') !== false) {
        throw new RuntimeException('R02 expected a no-op in late PHP shutdown A');
    }
    register_shutdown_function(function () use ($id, $path) {
        global $wpdb;
        if (update_post_meta($id, ESP_Media_Index::PROTECTION_META, 'path_http') !== false) {
            throw new RuntimeException('R02 expected a no-op in late PHP shutdown B');
        }
        register_shutdown_function(function () use ($id, $path) {
            global $wpdb;
            $pending = (int)$wpdb->get_var($wpdb->prepare(
                'SELECT pending_count FROM ' . ESP_Media_Index::state_table() .
                ' WHERE attachment_id=%d', $id));
            if ($wpdb->last_error !== '' || $pending !== 0) {
                throw new RuntimeException('R02 later PHP shutdown callback stranded pending=' . $pending);
            }
            // A late no-op was fenced before WordPress returned false:
            // once pending is safely retired, an independent worker must
            // rebuild the DIRTY owner before HTTP access resumes.
            ESP_Media_Index::run_batch();
            $indexed = ESP_Media_Index::resolve($path);
            if ($indexed['status'] !== 'resolved' ||
                ($indexed['protected_path_id'] ?? null) !== 'path_http') {
                throw new RuntimeException('R02 later PHP shutdown no-op did not recover on worker batch');
            }
            echo "PASS: R02 late PHP shutdown no-op rearmed finalizers and Cron recovery\n";
        });
    });
});

echo "PASS: dbDelta, batched WordPress metadata backfill, original+derivative protection and strict opt-in\n";
