<?php
/**
 * #67 one-column CAS with REAL WordPress wpdb transport and a TCP relay that
 * discards the first MySQL OK after a committed UPDATE. The relay then closes
 * the client socket, causing Core to reconnect and replay the same SQL.
 *
 * This is a disposable same-schema SQL design probe, NOT production integration.
 */
declare(strict_types=1);
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' ||
    !getenv('ESP_WP_ROOT') || !getenv('ESP67_WPDB_PROXY_HOST')) { exit(1); }
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
if (strpos(DB_NAME, 'esp_') !== 0 || !class_exists('wpdb')) {
    throw new RuntimeException('ESP67 wpdb ACK-loss probe refuses non-disposable WP');
}
global $wpdb;
$table = $wpdb->prefix . 'esp67_ack_probe';
$client = null;
try {
    if ($wpdb->query("CREATE TABLE $table (
        attachment_id BIGINT UNSIGNED PRIMARY KEY,
        revision BIGINT UNSIGNED NOT NULL,
        pending_count INT UNSIGNED NOT NULL,
        active_mutations LONGTEXT NOT NULL
    ) ENGINE=InnoDB") === false) {
        throw new RuntimeException('ESP67 ACK cannot create disposable probe');
    }
    if ($wpdb->query($wpdb->prepare(
        "INSERT INTO $table VALUES (%d,%d,%d,%s)",123,5,2,'["A","B"]'))!==1) {
        throw new RuntimeException('ESP67 ACK seed failed');
    }
    $proxy = getenv('ESP67_WPDB_PROXY_HOST');
    [$name,$port] = explode(':', $proxy, 2);
    $client = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, $proxy);
    $client->suppress_errors(true);
    if (!($client->dbh instanceof mysqli)) {
        throw new RuntimeException('ESP67 ACK WordPress wpdb connection failed');
    }
    $client->dbh->close();
    $transport = mysqli_init();
    $transport->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $transport->options(MYSQLI_OPT_READ_TIMEOUT, 5);
    if (!$transport->real_connect($name, DB_USER, DB_PASSWORD, DB_NAME, (int)$port)) {
        throw new RuntimeException('ESP67 ACK timed MySQL transport failed');
    }
    $client->dbh = $transport;
    if ((int)$client->get_var('SELECT 1')!==1) {
        throw new RuntimeException('ESP67 ACK wpdb proxy preliminary SQL failed');
    }
    $sql = $client->prepare(
        "UPDATE $table
         SET revision=revision+1,pending_count=1,active_mutations=%s
         WHERE attachment_id=123 AND revision=5
         AND BINARY active_mutations=BINARY %s
         /* ESP_Q01_DROP_ACK */",
         '["B"]','["A","B"]'
    );
    $return = $client->query($sql);
    $wpdb_error = $client->last_error;
    $actual = $wpdb->get_row(
        "SELECT revision,pending_count,active_mutations FROM $table WHERE attachment_id=123",
        ARRAY_A
    );
    if ($wpdb->last_error !== '' || !is_array($actual) ||
        (int)$actual['revision'] !== 6 ||
        (int)$actual['pending_count'] !== 1 ||
        $actual['active_mutations'] !== '["B"]') {
        throw new RuntimeException('ESP67 real wpdb retry duplicated or lost CAS state: ' .
            wp_json_encode($actual));
    }
    echo 'ESP67_WPDB_ACK_LOSS ' . wp_json_encode([
        'wordpress_wpdb' => true,
        'wpdb_return_after_reconnect' => $return,
        'wpdb_error' => $wpdb_error,
        'stored_revision' => (int)$actual['revision'],
        'stored_pending' => (int)$actual['pending_count'],
        'stored_mutations' => json_decode($actual['active_mutations'], true),
        'other_worker_preserved' => true,
    ]) . "\n";
    echo "PASS: real WordPress wpdb retry after lost MySQL OK leaves B active exactly once\n";
} finally {
    $wpdb->query("DROP TABLE IF EXISTS $table");
}
