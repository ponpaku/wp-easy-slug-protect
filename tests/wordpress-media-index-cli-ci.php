<?php
/**
 * F01: real WordPress / WP-CLI repair behavior with > BATCH_SIZE dirty rows.
 * Only the disposable esp_* database in the Apache integration workflow.
 *
 * ESP_INDEX_CLI_PHASE=seed|verify-deferred|unblock|verify-complete
 * The actual CLI command runs in separate processes between these phases.
 */
$phase = getenv('ESP_INDEX_CLI_PHASE');
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT') ||
    !in_array($phase, ['seed', 'verify-deferred', 'unblock', 'verify-complete'], true)) {
    exit(1);
}
define('WP_ADMIN', true);
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
if (strpos(DB_NAME, 'esp_') !== 0 || !class_exists('ESP_Media_Index')) {
    throw new RuntimeException('F01 test requires disposable WordPress media index');
}

function esp_cli_assert($ok, $message) {
    if (!$ok) { throw new RuntimeException('F01: ' . $message); }
}

global $wpdb;
$state = ESP_Media_Index::state_table();
$prefix = 'ESP_CLI_REPAIR_FIXTURE_';
$status = ESP_Media_Index::build_status(false);
esp_cli_assert(!$status['error'] && $status['ready'], 'requires global READY');

$ids = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'
       AND post_title LIKE %s ORDER BY ID ASC",
    $wpdb->esc_like($prefix) . '%'));
esp_cli_assert(is_array($ids) && $wpdb->last_error === '', 'cannot read fixture attachments');

if ($phase === 'seed') {
    esp_cli_assert(count($ids) === 0 && !$status['repairable_dirty'],
        'must start with no fixture or eligible repairs');
    $healthy = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'
         AND post_title=%s LIMIT 1", 'ESP31 public JPEG'));
    esp_cli_assert($healthy > 0, 'missing previously indexed healthy fixture');
    $healthy_generation = $wpdb->get_var($wpdb->prepare(
        "SELECT active_generation FROM $state WHERE attachment_id=%d", $healthy));
    esp_cli_assert($healthy_generation !== null && $wpdb->last_error === '',
        'missing healthy attachment generation');
    update_option('esp_test_cli_healthy_generation', [(int)$healthy, (int)$healthy_generation], false);

    for ($i = 0; $i < 203; ++$i) {
        $id = wp_insert_attachment([
            'post_title' => $prefix . sprintf('%03d', $i),
            'post_status' => 'inherit',
            'post_mime_type' => 'image/jpeg',
        ], false, 0, true);
        esp_cli_assert(!is_wp_error($id) && (int)$id > 0,
            'failed to create synthetic attachment ' . $i);
        $file = 'esp-cli-fixture/attachment-' . $id . '.jpg';
        esp_cli_assert(add_post_meta((int)$id, '_wp_attached_file', $file, true) !== false,
            'failed to save canonical attachment path');
        ESP_Media_Index::finish_noop_writes(); // Model per-attachment request completion in this long-lived fixture.
        // Only fixture rows, not production. Two are intentionally ineligible.
        if ($i >= 201) {
            $set = $i === 201
                ? "pending_count=1,retry_after=0"
                : $wpdb->prepare("pending_count=0,retry_after=%d", time() + 3600);
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE $state SET $set WHERE attachment_id=%d AND status='dirty'", $id));
            esp_cli_assert($updated === 1 && $wpdb->last_error === '',
                'cannot seed pending/backoff row');
        }
    }
    $eligible = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $state s JOIN {$wpdb->posts} p ON p.ID=s.attachment_id
         WHERE p.post_title LIKE %s AND s.status='dirty'
           AND s.pending_count=0 AND s.retry_after<=%d",
        $wpdb->esc_like($prefix) . '%', time()));
    $status = ESP_Media_Index::build_status(false);
    esp_cli_assert($eligible === 201 && $status['ready'] && $status['repairable_dirty'],
        'expected 201 eligible dirty rows after global READY');
    echo "PASS: F01 seeded 201 eligible + 2 deferred owners with global READY\n";
    exit(0);
}

esp_cli_assert(count($ids) === 203, 'fixture count unexpectedly changed');
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT p.post_title,s.status,s.pending_count,s.retry_after
     FROM $state s JOIN {$wpdb->posts} p ON p.ID=s.attachment_id
     WHERE p.post_title LIKE %s ORDER BY p.post_title ASC",
    $wpdb->esc_like($prefix) . '%'), ARRAY_A);
esp_cli_assert(is_array($rows) && count($rows) === 203 &&
    $wpdb->last_error === '', 'fixture state missing');

if ($phase === 'verify-deferred') {
    for ($i = 0; $i < 203; ++$i) {
        $row = $rows[$i];
        if ($i < 201) {
            esp_cli_assert($row['status'] === 'ready' &&
                (int)$row['pending_count'] === 0,
                'CLI stopped with an eligible DIRTY owner at ' . $i);
        } elseif ($i === 201) {
            esp_cli_assert($row['status'] === 'dirty' &&
                (int)$row['pending_count'] === 1,
                'CLI bypassed pending_count on deferred owner');
        } else {
            esp_cli_assert($row['status'] === 'dirty' &&
                (int)$row['retry_after'] > time(),
                'CLI bypassed retry_after on deferred owner');
        }
    }
    esp_cli_assert($status['ready'] && !$status['repairable_dirty'],
        'CLI left eligible repair work after two or more batches');
    echo "PASS: F01 CLI repaired >200 owners without touching pending/backoff\n";
    exit(0);
}
if ($phase === 'unblock') {
    // Simulate independent writers finishing and retry time arriving.
    $unblocked = $wpdb->query($wpdb->prepare(
        "UPDATE $state s JOIN {$wpdb->posts} p ON p.ID=s.attachment_id
         SET s.pending_count=0,s.retry_after=0
         WHERE p.post_title LIKE %s AND s.status='dirty'",
        $wpdb->esc_like($prefix) . '%'));
    esp_cli_assert($unblocked === 2 && $wpdb->last_error === '',
        'cannot release deferred test owners');
    esp_cli_assert(ESP_Media_Index::build_status(false)['repairable_dirty'],
        'deferred owners did not become eligible');
    echo "PASS: F01 deferred owners became eligible after simulated recovery\n";
    exit(0);
}

foreach ($rows as $row) {
    esp_cli_assert($row['status'] === 'ready' && (int)$row['pending_count'] === 0,
        'CLI failed to finish remaining owners');
}
esp_cli_assert($status['ready'] && !$status['repairable_dirty'],
    'CLI has unfinished eligible owners after final repair');
$healthy = get_option('esp_test_cli_healthy_generation');
esp_cli_assert(is_array($healthy) && count($healthy) === 2,
    'missing healthy baseline generation');
$after = $wpdb->get_var($wpdb->prepare(
    "SELECT active_generation FROM $state WHERE attachment_id=%d", $healthy[0]));
esp_cli_assert($wpdb->last_error === '' && (int)$after === (int)$healthy[1],
    'healthy READY attachment was unnecessarily rebuilt');
delete_option('esp_test_cli_healthy_generation');
echo "PASS: F01 CLI fully repaired all 203 owners; healthy generation unchanged\n";
