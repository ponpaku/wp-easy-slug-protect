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
// Failure in the very first DB inspection is diagnostic only. With no schema,
// settings or verified runtime, a later invocation must still install cleanly.
reset_fixture();
$first_inspection_failures = 0;
$reject_first_inspection = static function ($sql) use (&$first_inspection_failures) {
    if ($first_inspection_failures === 0 &&
        strpos($sql, 'SELECT ENGINE FROM information_schema.TABLES') === 0) {
        $first_inspection_failures++;
        return 'SELECT invalid_column FROM esp_missing_initial_inspection_table';
    }
    return $sql;
};
add_filter('query', $reject_first_inspection);
t(!run_migration() && $first_inspection_failures === 1 &&
    ESP_Migrator::read_option(ESP_Migrator::STATE_OPTION) === null &&
    ESP_Migrator::read_option('esp_settings') === null,
    'transient initial inspection failure does not create false installation evidence');
remove_filter('query', $reject_first_inspection);
t(run_migration() && (int) ESP_Migrator::read_option('esp_db_version') === 6,
    'empty site installs normally after the transient initial SQL error clears');

// Older PR builds wrote an unclassified failed/inspect marker before any
// schema changes. Accept only its narrow shape when all actual evidence is
// absent, never an arbitrary failed migration record.
reset_fixture();
$preclassification = ['version' => ESP_VERSION, 'step' => 'inspect',
    'status' => 'failed', 'reason' => 'schema_read_failed', 'updated_at' => time()];
option_db(ESP_Migrator::STATE_OPTION, $preclassification);
t(run_migration() && (int) ESP_Migrator::read_option('esp_db_version') === 6,
    'old preclassification read-error marker does not permanently poison clean install');
reset_fixture();
$preclassification['reason'] = 'path_mapping_ambiguous';
option_db(ESP_Migrator::STATE_OPTION, $preclassification);
t(!run_migration() && ESP_Migrator::read_option('esp_settings') === null,
    'unrelated failed migration marker cannot masquerade as clean install');

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
// Previously removed /members/ path with a different ID must not prevent a
// normal v6 update. Its remember cookie must remain unusable for the new ID.
create_current(); $s = settings_fixture(); seed_auth_rows();
$old_user = str_repeat('o', 32); $old_cookie = str_repeat('o', 64);
$new_user = str_repeat('n', 32); $new_cookie = str_repeat('n', 64);
$wpdb->insert(table_name('brute'), ['ip_address' => '127.0.0.1', 'path' => '/members/',
    'path_id' => 'path_old', 'time' => gmdate('Y-m-d H:i:s')]);
foreach (['path_old' => [$old_user, $old_cookie], 'path_kept' => [$new_user, $new_cookie]] as $path_id => $auth) {
    $inserted = $wpdb->insert(table_name('remember'), ['path' => '/members/', 'path_id' => $path_id,
        'password_version' => 7, 'user_id' => $auth[0],
        'token' => hash_hmac('sha256', $auth[1], AUTH_SALT),
        'created' => '2026-01-01 00:00:00', 'expires' => '2027-01-01 00:00:00'],
        ['%s', '%s', '%d', '%s', '%s', '%s', '%s']);
    t($inserted === 1, 'fixture inserts ' . $path_id . ' remember token');
}
$stale_before = $wpdb->get_results('SELECT * FROM ' . table_name('remember') . ' ORDER BY id', ARRAY_A);
$brute_before = $wpdb->get_results('SELECT * FROM ' . table_name('brute') . ' ORDER BY id', ARRAY_A);
t(run_migration() && $wpdb->get_results('SELECT * FROM ' . table_name('remember') . ' ORDER BY id', ARRAY_A) === $stale_before &&
    $wpdb->get_results('SELECT * FROM ' . table_name('brute') . ' ORDER BY id', ARRAY_A) === $brute_before,
    'v6 migration preserves detached brute and remember history without rebinding');
$_COOKIE['esp_remember_id_path_kept'] = $old_user;
$_COOKIE['esp_remember_token_path_kept'] = $old_cookie;
t(!(new ESP_Auth())->is_logged_in($s['path']['path_kept']),
    'detached old remember token cannot authenticate the new path ID');
$_COOKIE['esp_remember_id_path_kept'] = $new_user;
$_COOKIE['esp_remember_token_path_kept'] = $new_cookie;
$matched = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . table_name('remember') .
    ' WHERE user_id = %s AND token = %s AND path_id = %s AND expires > NOW()',
    $new_user, hash_hmac('sha256', $new_cookie, AUTH_SALT), 'path_kept'));
t($matched !== null, 'current remember token is queryable by authentication conditions');
t((new ESP_Auth())->is_logged_in($s['path']['path_kept']),
    'current path ID remember token continues authenticating');
unset($_COOKIE['esp_remember_id_path_kept'], $_COOKIE['esp_remember_token_path_kept']);

// An ID assigned to another *active* path is not detached history.
create_current(); $s = settings_fixture();
$s['path']['path_other'] = ['id' => 'path_other', 'path' => '/other/',
    'password' => 'other-hash', 'password_version' => 0];
option_db('esp_settings', $s);
$wpdb->insert(table_name('remember'), ['path' => '/members/', 'path_id' => 'path_other',
    'password_version' => 0, 'user_id' => str_repeat('a', 32), 'token' => str_repeat('a', 64),
    'created' => '2026-01-01 00:00:00', 'expires' => '2027-01-01 00:00:00']);
t(!run_migration() && get_option(ESP_Migrator::STATE_OPTION)['reason'] === 'path_mapping_ambiguous',
    'cross-linked active path ID is rejected even when retired history is allowed');

// Scaling regression: path validation should scan records in primary-key
// batches, not generate one IN(other active IDs...) statement per path.
create_current(); $many = settings_fixture();
for ($i = 0; $i < 140; $i++) {
    $key = 'path_bulk_' . $i;
    $many['path'][$key] = ['id' => $key, 'path' => '/bulk/' . $i . '/',
        'password' => 'fixture-hash', 'password_version' => 0];
}
option_db('esp_settings', $many);
for ($i = 0; $i < 425; $i++) {
    $n = $i % 140;
    $path = '/bulk/' . $n . '/';
    $row_id = ($i % 13 === 0) ? 'former_' . $n : 'path_bulk_' . $n;
    $wpdb->insert(table_name('remember'), ['path' => $path, 'path_id' => $row_id,
        'password_version' => 0, 'user_id' => str_pad((string) $i, 32, 'x'),
        'token' => hash('sha256', 'bulk-' . $i),
        'created' => '2026-01-01 00:00:00', 'expires' => '2027-01-01 00:00:00'],
        ['%s', '%s', '%d', '%s', '%s', '%s', '%s']);
    $wpdb->insert(table_name('brute'), ['ip_address' => '127.0.0.1',
        'path' => $path, 'path_id' => $row_id, 'time' => '2026-01-01 00:00:00'],
        ['%s', '%s', '%s', '%s']);
}
$scan_queries = 0;
$count_scan = static function ($sql) use (&$scan_queries) {
    if (strpos($sql, 'SELECT id, path, path_id FROM ') === 0) { $scan_queries++; }
    return $sql;
};
add_filter('query', $count_scan);
$many_ok = run_migration();
remove_filter('query', $count_scan);
t($many_ok && $scan_queries <= 20 &&
    (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . table_name('remember')) === 425 &&
    (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . table_name('brute')) === 425,
    '140 paths and 850 history rows validate using bounded primary-key queries (scans=' . $scan_queries . ')');
$old_bulk = $wpdb->get_var($wpdb->prepare('SELECT path_id FROM ' . table_name('remember') .
    ' WHERE BINARY path = BINARY %s ORDER BY id LIMIT 1', '/bulk/0/'));
t($old_bulk === 'former_0', 'batched validation preserves detached history IDs');
$wpdb->insert(table_name('remember'), ['path' => '/bulk/0/', 'path_id' => 'path_bulk_1',
    'password_version' => 0, 'user_id' => str_repeat('z', 32),
    'token' => str_repeat('z', 64), 'created' => '2026-01-01 00:00:00',
    'expires' => '2027-01-01 00:00:00'], ['%s', '%s', '%d', '%s', '%s', '%s', '%s']);
t(!run_migration() && get_option(ESP_Migrator::STATE_OPTION)['reason'] === 'path_mapping_ambiguous',
    'batched validation rejects wrong active IDs after processing multiple batches');

// The retired_ prefix cannot be assigned to an active path, even by
// manually edited settings; otherwise a past credential could be revived.
create_current(); $s = settings_fixture();
$s['path'] = ['retired_manual' => ['id' => 'retired_manual', 'path' => '/deleted/',
    'password' => 'new-hash', 'password_version' => 0]];
option_db('esp_settings', $s);
t(!run_migration() && get_option(ESP_Migrator::STATE_OPTION)['reason'] === 'path_mapping_ambiguous',
    'retired-history identity namespace cannot be used by active settings');

// Tests that deliberately fail migration must not leave the subsequent
// historical-version matrix running against corrupted fixture data.
create_current(); settings_fixture(); seed_auth_rows();
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

create_current(); settings_fixture(); seed_auth_rows();
option_db('esp_db_version', 2);
$wpdb->query('ALTER TABLE ' . table_name('remember') . ' DROP COLUMN password_version');
$remember_before_unknown_generation = $wpdb->get_results('SELECT * FROM ' . table_name('remember'), ARRAY_A);
t(!run_migration() && get_option(ESP_Migrator::STATE_OPTION)['reason'] === 'password_version_ambiguous' &&
    $wpdb->get_results('SELECT * FROM ' . table_name('remember'), ARRAY_A) === $remember_before_unknown_generation &&
    (int) get_option('esp_db_version') === 2,
    'missing token password generation with nonzero current generation stops without guessing or changing tokens');
// A partial V1 row with path_id='' must not evade the generation preflight
// just because its path_id column is present.
create_current(); settings_fixture(); seed_auth_rows();
option_db('esp_db_version', 2);
$wpdb->query('UPDATE ' . table_name('remember') . " SET path_id = ''");
$wpdb->query('ALTER TABLE ' . table_name('remember') . ' DROP COLUMN password_version');
$partial_before = $wpdb->get_results('SELECT * FROM ' . table_name('remember'), ARRAY_A);
t(!run_migration() && get_option(ESP_Migrator::STATE_OPTION)['reason'] === 'password_version_ambiguous' &&
    $wpdb->get_results('SELECT * FROM ' . table_name('remember'), ARRAY_A) === $partial_before &&
    (int) get_option('esp_db_version') === 2,
    'blank path ID with missing generation fails before DDL and preserves tokens');


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

// Ambiguity anywhere in the input must stop before a preceding row is modified.
create_current(); settings_fixture(); seed_auth_rows();
$wpdb->query('UPDATE ' . table_name('remember') . " SET path_id = ''");
$wpdb->insert(table_name('remember'), ['path' => '/members/', 'path_id' => 'path_ambiguous', 'password_version' => 7,
    'user_id' => str_repeat('x', 32), 'token' => str_repeat('x', 64), 'created' => '2026-01-01 00:00:00', 'expires' => '2027-01-01 00:00:00']);
$ambiguous_settings = ESP_Config::OPTION_DEFAULTS;
$ambiguous_settings['path'] = [['path' => '/members/', 'password' => 'preserved-legacy-hash']];
option_db('esp_settings', $ambiguous_settings); option_db('esp_db_version', 0);
$ambiguous_rows = $wpdb->get_results('SELECT * FROM ' . table_name('remember'), ARRAY_A);
t(!run_migration(true) && get_option('esp_settings') === $ambiguous_settings &&
    $wpdb->get_results('SELECT * FROM ' . table_name('remember'), ARRAY_A) === $ambiguous_rows,
    'preflight detects ambiguous partial V1 links before changing any preceding row or settings');

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
$deleted_cookie = str_repeat('d', 64);
$deleted_user = str_repeat('d', 32);
$deleted_token = hash_hmac('sha256', $deleted_cookie, AUTH_SALT);
$wpdb->insert(table_name('remember'), ['path' => '/deleted/', 'user_id' => $deleted_user,
    'token' => $deleted_token, 'created' => '2026-01-01 00:00:00', 'expires' => '2027-01-01 00:00:00'],
    ['%s', '%s', '%s', '%s', '%s']);
$wpdb->insert(table_name('brute'), ['path' => '/deleted/', 'ip_address' => '127.0.0.1',
    'time' => gmdate('Y-m-d H:i:s')]);

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
$retired_id = 'retired_' . substr(hash('sha256', '/deleted/'), 0, 40);
$deleted_remember = $wpdb->get_row($wpdb->prepare('SELECT path_id, token FROM ' .
    table_name('remember') . ' WHERE BINARY path = BINARY %s', '/deleted/'), ARRAY_A);
$deleted_brute_id = $wpdb->get_var($wpdb->prepare('SELECT path_id FROM ' .
    table_name('brute') . ' WHERE BINARY path = BINARY %s', '/deleted/'));
t($deleted_remember['path_id'] === $retired_id && $deleted_remember['token'] === $deleted_token &&
    $deleted_brute_id === $retired_id,
    'V1 removed-path history is retained under an inert ID across batch resume');
$readded = get_option('esp_settings');
$readded['path']['path_new'] = ['id' => 'path_new', 'path' => '/deleted/',
    'password' => 'new-hash', 'password_version' => 0];
option_db('esp_settings', $readded);
t(run_migration() && $wpdb->get_var($wpdb->prepare('SELECT path_id FROM ' .
    table_name('remember') . ' WHERE BINARY path = BINARY %s', '/deleted/')) === $retired_id,
    'later re-registration cannot rebind the deleted-path remember token');
$_COOKIE['esp_remember_id_path_new'] = $deleted_user;
$_COOKIE['esp_remember_token_path_new'] = $deleted_cookie;
t(!(new ESP_Auth())->is_logged_in($readded['path']['path_new']),
    'deleted V1 path cookie is rejected after registration under a new ID');
unset($_COOKIE['esp_remember_id_path_new'], $_COOKIE['esp_remember_token_path_new']);


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
wp_clear_scheduled_hook('esp_update_retry');
wp_clear_scheduled_hook(ESP_Config::DAILY_CLEANUP_HOOK); add_filter('pre_schedule_event', $prevent_cron);
$lock = ESP_Protection_State::acquire_verification_lock();
t(ESP_Updater::finish_update($setup, $lock), 'optional Cron failure retains PHP usability');
ESP_Protection_State::release_verification_lock($lock);
$retry_state = get_option(ESP_Updater::STATE_OPTION);
t($retry_state['status'] === 'degraded' && (int) $retry_state['attempts'] === 0 &&
    !wp_next_scheduled('esp_update_retry'),
    'rejected retry scheduling leaves no event and does not spend the budget');
remove_filter('pre_schedule_event', $prevent_cron);
wp_set_current_user(1);
t(current_user_can('manage_options'), 'fixture administrator may re-arm missing retries');
ESP_Updater::recover_missing_retry();
$retry_state = get_option(ESP_Updater::STATE_OPTION);
t(wp_next_scheduled('esp_update_retry') !== false && (int) $retry_state['attempts'] === 1,
    'admin access re-arms lost retry and counts only a successful reservation');
ESP_Updater::recover_missing_retry();
t((int) get_option(ESP_Updater::STATE_OPTION)['attempts'] === 1,
    'existing retry event is never double-counted');
wp_clear_scheduled_hook('esp_update_retry');
t(!wp_next_scheduled('esp_update_retry'), 'first retry reservation can be removed for failure injection');

// Also verify the WP_Error scheduling failure path and the five-attempt cap.
$retry_state['attempts'] = 4;
option_db(ESP_Updater::STATE_OPTION, $retry_state);
$reject_retry = static function () { return new WP_Error('fixture_schedule_denied'); };
add_filter('pre_schedule_event', $reject_retry);
ESP_Updater::recover_missing_retry();
t(!wp_next_scheduled('esp_update_retry') &&
    (int) get_option(ESP_Updater::STATE_OPTION)['attempts'] === 4,
    'WP_Error during scheduling also leaves retry budget intact');
remove_filter('pre_schedule_event', $reject_retry);
ESP_Updater::recover_missing_retry();
t(wp_next_scheduled('esp_update_retry') !== false &&
    (int) get_option(ESP_Updater::STATE_OPTION)['attempts'] === 5,
    'recovered last retry consumes the fifth budget unit only after scheduling');
wp_clear_scheduled_hook('esp_update_retry');
ESP_Updater::recover_missing_retry();
t(!wp_next_scheduled('esp_update_retry') &&
    (int) get_option(ESP_Updater::STATE_OPTION)['attempts'] === 5,
    'automatic retries stop at the budget cap');
wp_set_current_user(0);
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
