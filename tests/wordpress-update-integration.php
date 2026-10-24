<?php
/**
 * Real WordPress + disposable MySQL fixtures. Refuses to run without explicit opt-in.
 * ESP_WP_ROOT=/path/to/wordpress ESP_ALLOW_DISPOSABLE_DB=1 php tests/wordpress-update-integration.php
 */
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT')) {
    fwrite(STDERR, "Set ESP_ALLOW_DISPOSABLE_DB=1 and ESP_WP_ROOT (disposable WordPress install).\n"); exit(1);
}
register_shutdown_function(static function () { if (($e = error_get_last()) && in_array($e['type'], [E_ERROR, E_PARSE], true)) { fwrite(STDERR, print_r($e, true)); } });
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
define('WP_CLI', true);
define('WP_INSTALLING', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
wp_installing(false);
if (strpos(DB_NAME, 'esp_') !== 0) { throw new RuntimeException('Use an esp_ disposable database'); }
if (!defined('ESP_PATH')) { require dirname(__DIR__) . '/easy-slug-protect.php'; }
Easy_Slug_Protect::load_dependencies();
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
$wpdb->suppress_errors(true);
function t($ok, $label) { if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } echo "PASS: $label\n"; }
function option_db($name, $value) {
    global $wpdb;
    $wpdb->replace($wpdb->options, ['option_name' => $name, 'option_value' => maybe_serialize($value), 'autoload' => 'no']);
    wp_cache_flush(); // Disposable fixture setup only, never production code.
}
function reset_fixture() {
    global $wpdb;
    foreach (ESP_Config::DB_TABLES as $name) { $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}$name`"); }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'esp\\_%'");
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_esp_media_protected_path_id', '_esp_permalink_path')");
    wp_cache_flush();
    $uploads = wp_upload_dir(); wp_mkdir_p($uploads['basedir']);
    file_put_contents($uploads['basedir'] . '/.htaccess', "# ESP44 fixture third-party text\n");
}
function run_migration($maintenance = false) { return (new ESP_Migrator())->run(null, $maintenance); }
function settings_fixture() {
    $s = ESP_Config::OPTION_DEFAULTS;
    $s['path'] = ['path_kept' => ['id' => 'path_kept', 'path' => '/members/', 'password' => 'existing-password-hash', 'password_version' => 7]];
    option_db('esp_settings', $s); return $s;
}
function create_current() { reset_fixture(); t(run_migration(), 'clean install verifies and records schema 6'); }
function table_name($kind) { global $wpdb; return $wpdb->prefix . ESP_Config::DB_TABLES[$kind]; }
function seed_auth_rows() {
    global $wpdb;
    $wpdb->insert(table_name('remember'), ['path' => '/members/', 'path_id' => 'path_kept', 'password_version' => 7,
        'user_id' => str_repeat('u', 32), 'token' => str_repeat('r', 64), 'created' => '2026-01-01 00:00:00', 'expires' => '2027-01-01 00:00:00']);
    $wpdb->insert(table_name('session'), ['path_id' => 'path_kept', 'password_version' => 7,
        'token' => str_repeat('s', 64), 'created' => '2026-01-01 00:00:00', 'expires' => '2027-01-01 00:00:00']);
}
reset_fixture();
option_db(ESP_Protection_State::RUNTIME_STATE_OPTION, ['state' => 'READY', 'version' => ESP_VERSION]);
t(!run_migration() && get_option('esp_settings', null) === null,
    'leftover verified runtime without settings/tables is partial, never a clean install');
reset_fixture();
option_db(ESP_Migrator::STATE_OPTION, ['version' => ESP_VERSION, 'status' => 'running', 'clean_install' => true]);
t(run_migration(), 'clean-install intent resumes after interruption before the first settings/table write');
create_current();
$s = settings_fixture(); seed_auth_rows();
$before = $wpdb->get_results('SELECT * FROM ' . table_name('remember'), ARRAY_A);
$session_before = $wpdb->get_results('SELECT * FROM ' . table_name('session'), ARRAY_A);
delete_option('esp_db_version');
t(run_migration(), 'missing DB marker on existing installation adopts verified current schema');
t(get_option('esp_settings') === $s && $wpdb->get_results('SELECT * FROM ' . table_name('remember'), ARRAY_A) === $before &&
    $wpdb->get_results('SELECT * FROM ' . table_name('session'), ARRAY_A) === $session_before,
    'missing marker preserves path IDs, passwords, remember rows and sessions');
option_db('esp_db_version', 0);
$wpdb->query('UPDATE ' . table_name('remember') . " SET path_id = ''");
t(run_migration() && $wpdb->get_results('SELECT * FROM ' . table_name('remember'), ARRAY_A) === $before,
    'partial V1 with assigned settings IDs resumes missing row backfill without reissuing IDs');
foreach ([0, 1, 2, 3, 4, 6] as $version) {
    option_db('esp_db_version', $version);
    t(run_migration() && (int) ESP_Migrator::read_option('esp_db_version') === 6,
        "stale v$version marker does not replay path ID generation");
}
option_db('esp_db_version', 99);
$schema = $wpdb->get_results('SHOW COLUMNS FROM ' . table_name('remember'), ARRAY_A);
t(!run_migration() && (int) get_option('esp_db_version') === 99 &&
    $wpdb->get_results('SHOW COLUMNS FROM ' . table_name('remember'), ARRAY_A) === $schema,
    'future DB version refuses automatic downgrade');
option_db('esp_db_version', 6);
option_db(ESP_Migrator::STATE_OPTION, ['version' => '99.0.0', 'status' => 'complete']);
t(!run_migration() && get_option(ESP_Migrator::STATE_OPTION)['version'] === '99.0.0', 'future migration checkpoint is never overwritten');

// Historical v1/2/3/4 schemas. No v5 implementation exists.
foreach ([1, 2, 3, 4] as $version) {
    create_current(); settings_fixture();
    option_db('esp_db_version', $version);
    $wpdb->query('DROP TABLE ' . table_name('limit'));
    if ($version < 2) { $wpdb->query('DROP TABLE ' . table_name('session')); }
    if ($version < 3) { $wpdb->query('ALTER TABLE ' . table_name('remember') . ' DROP COLUMN password_version'); }
    if ($version >= 2 && $version < 4) { $wpdb->query('ALTER TABLE ' . table_name('session') . ' DROP COLUMN password_version'); }
    t(run_migration(), "historical v$version schema reaches verified v6 using only additive steps");
}

// V1 legacy IDs: interrupted DDL, fixed batches, preserved passwords/tokens.
create_current();
$legacy = ESP_Config::OPTION_DEFAULTS;
$legacy['path'] = [['path' => '/legacy/', 'password' => 'legacy-password-hash']];
option_db('esp_settings', $legacy); option_db('esp_db_version', 0);
$wpdb->query('ALTER TABLE ' . table_name('remember') . ' DROP INDEX path_id, DROP COLUMN path_id, DROP COLUMN password_version');
$wpdb->query('ALTER TABLE ' . table_name('brute') . ' DROP INDEX ip_path_id, DROP COLUMN path_id');
$wpdb->query('DROP TABLE ' . table_name('session')); $wpdb->query('DROP TABLE ' . table_name('limit'));
for ($i = 0; $i < 205; $i++) {
    $wpdb->insert(table_name('remember'), ['path' => '/legacy/', 'user_id' => str_pad((string) $i, 32, '0'),
        'token' => str_pad((string) $i, 64, 't'), 'created' => '2026-01-01 00:00:00', 'expires' => '2027-01-01 00:00:00']);
}
t(!run_migration() && get_option(ESP_Migrator::STATE_OPTION)['reason'] === 'maintenance_confirmation_required',
    'legacy V1 data conversion requires explicit worker-drain/backup confirmation');
t(!run_migration(true) && get_option(ESP_Migrator::STATE_OPTION)['status'] === 'pending' && (int) get_option('esp_db_version') === 0,
    'fixed first batch checkpoints without advancing completed DB version');
$id = $wpdb->get_var('SELECT path_id FROM ' . table_name('remember') . ' WHERE id = 1');
t(run_migration(), 'second invocation resumes after DDL and cursor checkpoint');
t(array_key_first(get_option('esp_settings')['path']) === $id && count(get_option('esp_settings')['path']) === 1 &&
    get_option('esp_settings')['path'][$id]['password'] === 'legacy-password-hash' &&
    (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . table_name('remember') . " WHERE path_id = '$id'") === 205,
    'V1 resume preserves the first assigned ID, original password and all remember tokens');
t(run_migration() && array_key_first(get_option('esp_settings')['path']) === $id, 'completed migration is idempotent');

create_current(); settings_fixture(); option_db('esp_db_version', 3);
$wpdb->query('ALTER TABLE ' . table_name('session') . ' DROP INDEX token_unique');
t(run_migration(), 'missing known index is recreated and verified');
$wpdb->query('ALTER TABLE ' . table_name('remember') . ' ALTER COLUMN password_version SET DEFAULT 9');
option_db('esp_db_version', 3);
t(!run_migration() && (int) get_option('esp_db_version') === 3, 'incompatible password-version default cannot be marked complete');
$wpdb->query('ALTER TABLE ' . table_name('remember') . ' ALTER COLUMN password_version SET DEFAULT 0');
$wpdb->query('ALTER TABLE ' . table_name('session') . ' DROP COLUMN created');
option_db('esp_db_version', 3);
t(!run_migration() && (int) get_option('esp_db_version') === 3, 'unknown missing column never advances marker');
create_current(); settings_fixture();
$wpdb->insert($wpdb->postmeta, ['post_id' => 999, 'meta_key' => '_esp_media_protected_path_id', 'meta_value' => 'orphan']);
option_db('esp_db_version', 4);
t(!run_migration() && (int) get_option('esp_db_version') === 4, 'orphan protected attachment ID is diagnostic-only');

create_current(); settings_fixture(); delete_option('esp_db_version');
$fail_read = static function ($sql) { return strpos($sql, 'SELECT option_value') !== false && strpos($sql, "'esp_db_version'") !== false ? 'SELECT missing_column FROM missing_table' : $sql; };
add_filter('query', $fail_read);
t(!run_migration(), 'actual SQL read failure is never classified as a clean installation');
remove_filter('query', $fail_read);
t(ESP_Migrator::read_option('esp_db_version') === null, 'read failure does not manufacture a completed marker');
$fail_write = static function ($sql) { return strpos($sql, 'INSERT INTO') === 0 && strpos($sql, "'esp_db_version'") !== false ? 'INSERT INTO missing_table VALUES (1)' : $sql; };
add_filter('query', $fail_write);
t(!run_migration(), 'actual option write error prevents completion');
remove_filter('query', $fail_write);
t(ESP_Migrator::read_option('esp_db_version') === null && run_migration(), 'manual retry succeeds after DB write recovery');

// Lock contention and stale releaser, using actual WordPress wpdb connections.
$main_db = $wpdb;
$other_db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST); $other_db->set_prefix($main_db->prefix);
$held = ESP_Protection_State::acquire_verification_lock();
$wpdb = $other_db;
t(!run_migration(), 'parallel connection cannot run migrations under another owner');
ESP_Protection_State::release_verification_lock($held);
$wpdb = $main_db;
t(ESP_Protection_State::owns_verification_lock($held), 'stale/nonowner release cannot free current lock');
ESP_Protection_State::release_verification_lock($held);

// DDL permission failure must not advance the completion marker.
create_current(); settings_fixture(); option_db('esp_db_version', 2);
$wpdb->query('ALTER TABLE ' . table_name('remember') . ' DROP COLUMN password_version');
$wpdb->query("CREATE USER IF NOT EXISTS 'esp44_restricted'@'%' IDENTIFIED BY 'disposable-fixture-only'");
$wpdb->query("GRANT SELECT ON `" . DB_NAME . "`.* TO 'esp44_restricted'@'%'");
$wpdb->query("GRANT INSERT, UPDATE, DELETE ON `" . DB_NAME . "`.`{$wpdb->options}` TO 'esp44_restricted'@'%'");
$main_db = $wpdb;
$wpdb = new wpdb('esp44_restricted', 'disposable-fixture-only', DB_NAME, DB_HOST);
$wpdb->set_prefix($main_db->prefix); $wpdb->suppress_errors(true);
t(!run_migration() && (int) ESP_Migrator::read_option('esp_db_version') === 2,
    'actual denied ALTER leaves completed DB version unchanged');
$wpdb = $main_db;
t(run_migration(), 'explicit retry repairs known missing column after permission recovery');
$wpdb->query("DROP USER 'esp44_restricted'@'%'");

// A killed MySQL connection is not a lease that another worker may inherit.
$held = ESP_Protection_State::acquire_verification_lock();
t(is_array($held) && $other_db->query('KILL CONNECTION ' . (int) $held['connection']) !== false, 'server accepts connection-kill fault injection');
for ($probe = 0; $probe < 100; $probe++) {
    if ($other_db->get_var($other_db->prepare('SELECT IS_USED_LOCK(%s)', $held['name'])) === null) { break; }
    usleep(1000);
}
t(!(new ESP_Migrator())->run($held), 'wpdb reconnect after server-side connection loss cannot inherit the old lock');
$next_lock = ESP_Protection_State::acquire_verification_lock();
t(is_array($next_lock), 'new connection may explicitly acquire its own lock after disconnect');
ESP_Protection_State::release_verification_lock($held);
t(ESP_Protection_State::owns_verification_lock($next_lock), 'old disconnected owner cannot release the new lock');
ESP_Protection_State::release_verification_lock($next_lock);

// Updater state and mandatory/optional work are independent of runtime READY.
create_current(); settings_fixture();
$setup = new ESP_Setup();
$lock = ESP_Protection_State::acquire_verification_lock();
t(ESP_Updater::finish_update($setup, $lock), 'new-code post-processing succeeds on actual WP hooks/files');
ESP_Protection_State::release_verification_lock($lock);
t(get_option('esp_plugin_version') === ESP_VERSION, 'plugin marker advances only after required work verification');
$prevent_cron = static function () { return false; };
wp_clear_scheduled_hook(ESP_Config::DAILY_CLEANUP_HOOK); add_filter('pre_schedule_event', $prevent_cron);
$lock = ESP_Protection_State::acquire_verification_lock();
t(ESP_Updater::finish_update($setup, $lock), 'optional Cron failure retains PHP usability');
ESP_Protection_State::release_verification_lock($lock);
remove_filter('pre_schedule_event', $prevent_cron);
t(get_option(ESP_Updater::STATE_OPTION)['status'] === 'degraded', 'Cron failure remains separately visible');
create_current(); settings_fixture();
$_SERVER['SERVER_SOFTWARE'] = 'nginx';
$lock = ESP_Protection_State::acquire_verification_lock();
t(ESP_Updater::finish_update($setup, $lock), 'unverified external Rewrite does not disable PHP protection');
ESP_Protection_State::release_verification_lock($lock);
t(get_option('esp_plugin_version', null) === null && get_option(ESP_Updater::STATE_OPTION)['reason'] === 'rewrite_unverified',
    'external Rewrite uncertainty cannot record a completed update');
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
reset_fixture();
echo "All real WordPress/MySQL update integration fixtures passed.\n";
