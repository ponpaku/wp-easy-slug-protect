<?php
/** Real Core update API/upgrader; only GitHub HTTP transport is replaced by fixtures. */
if (getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' || !getenv('ESP_WP_ROOT')) { exit(1); }
register_shutdown_function(static function () { if (($e = error_get_last()) && in_array($e['type'], [E_ERROR, E_PARSE], true)) { fwrite(STDERR, print_r($e, true)); } });
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
define('WP_DEBUG', true);
define('WP_INSTALLING', true); // Bootstrap without loading a previous test's plugin copy.
define('FS_METHOD', 'direct');
define('WP_ADMIN', true);
$automatic = getenv('ESP_TEST_AUTOMATIC') === '1';
if ($automatic) { define('DOING_CRON', true); }
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
wp_installing(false);
if (strpos(DB_NAME, 'esp_') !== 0) { exit(1); }
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
function check_release($ok, $label) { if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } echo "PASS: $label\n"; }
function copy_runtime($from, $to) {
    wp_mkdir_p($to);
    foreach (['easy-slug-protect.php', 'uninstall.php', 'includes', 'admin', 'front', 'languages'] as $part) {
        if (is_file("$from/$part")) { copy("$from/$part", "$to/$part"); continue; }
        if (!is_dir("$from/$part")) { continue; }
        wp_mkdir_p("$to/$part");
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$from/$part", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $f) {
            $relative = substr($f->getPathname(), strlen($from) + 1);
            if ($f->isDir()) { wp_mkdir_p("$to/$relative"); } else { copy($f->getPathname(), "$to/$relative"); }
        }
    }
}
$folder = getenv('ESP_TEST_FOLDER') ?: 'esp44-custom';
if (!in_array($folder, ['esp44-custom', 'easy-slug-protect'], true)) { exit(1); }
$installed = WP_PLUGIN_DIR . '/' . $folder;
WP_Filesystem();
if (is_link($installed)) { unlink($installed); } elseif (is_dir($installed)) { $wp_filesystem->delete($installed, true); }
copy_runtime(dirname(__DIR__), $installed);
require "$installed/easy-slug-protect.php";
Easy_Slug_Protect::load_dependencies();
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
wp_set_current_user(1);
$setup = new ESP_Setup();
ESP_Updater::register($setup); ESP_Protection_State::register_runtime_hooks($setup);
// Reset plugin-owned fixture state and schema from previous run.
foreach (ESP_Config::DB_TABLES as $name) { $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}$name`"); }
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'esp\\_%'");
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_esp_media_protected_path_id', '_esp_permalink_path')");
wp_cache_flush();
$uploads = wp_upload_dir(); wp_mkdir_p($uploads['basedir']);
file_put_contents($uploads['basedir'] . '/.htaccess', "# ESP44 fixture third-party text\n");
check_release((new ESP_Migrator())->run(), 'manual initial installation creates current schema');
check_release(ESP_Protection_State::verify_and_commit($setup), 'initial installed code verifies before release update');
$plugin = ESP_Updater::basename();
update_option('active_plugins', [$plugin]);
$settings = get_option('esp_settings');
$settings['path']['path_keep'] = ['id' => 'path_keep', 'path' => '/members/', 'password' => 'unchanged-hash', 'password_version' => 2];
update_option('esp_settings', $settings);
$wpdb->insert($wpdb->prefix . ESP_Config::DB_TABLES['session'], ['path_id' => 'path_keep', 'password_version' => 2,
    'token' => str_repeat('s', 64), 'created' => '2026-01-01 00:00:00', 'expires' => '2027-01-01 00:00:00']);
$session = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . ESP_Config::DB_TABLES['session'], ARRAY_A);
$package_root = sys_get_temp_dir() . '/esp44-release-' . getmypid();
wp_mkdir_p($package_root); copy_runtime(dirname(__DIR__), "$package_root/easy-slug-protect");
$main = "$package_root/easy-slug-protect/easy-slug-protect.php";
file_put_contents($main, str_replace(['Version: 0.7.40', "ESP_VERSION', '0.7.40"], ['Version: 0.7.41', "ESP_VERSION', '0.7.41"], file_get_contents($main)));
$zip = "$package_root/easy-slug-protect-0.7.41.zip";
$archive = new PclZip($zip);
check_release($archive->create("$package_root/easy-slug-protect", PCLZIP_OPT_REMOVE_PATH, $package_root) !== 0, 'build synthetic next-release ZIP');
$record = ['tag_name' => 'v0.7.41', 'draft' => false, 'prerelease' => false,
    'html_url' => ESP_Updater::URI . '/releases/tag/v0.7.41', 'published_at' => '2026-10-24T00:00:00Z', 'body' => '<script>unsafe()</script> release notes',
    'assets' => [['name' => 'easy-slug-protect-0.7.41.zip', 'state' => 'uploaded', 'size' => filesize($zip),
        'digest' => 'sha256:' . hash_file('sha256', $zip),
        'browser_download_url' => ESP_Updater::URI . '/releases/download/v0.7.41/easy-slug-protect-0.7.41.zip']]];
check_release(ESP_Updater::parse_release($record)['version'] === '0.7.41', 'public stable release with dedicated digest-bearing asset accepted');
$bad_records = [];
foreach (['draft', 'prerelease'] as $field) { $b = $record; $b[$field] = true; $bad_records[] = $b; }
foreach (['v0.7.41-rc.1', 'v01.2.3', 'latest', '../0.7.41'] as $tag) { $b = $record; $b['tag_name'] = $tag; $bad_records[] = $b; }
$b = $record; $b['assets'] = []; $bad_records[] = $b;
$b = $record; unset($b['assets'][0]['digest']); $bad_records[] = $b;
$b = $record; $b['assets'][0]['browser_download_url'] = 'https://evil.example/asset.zip'; $bad_records[] = $b;
foreach ($bad_records as $bad) { check_release(ESP_Updater::parse_release($bad) === null, 'invalid/incomplete release excluded'); }
foreach (['http://github.com/ponpaku/wp-easy-slug-protect/releases/download/x/a.zip',
    'https://github.com.evil.example/asset.zip', 'https://user@release-assets.githubusercontent.com/a',
    'https://release-assets.githubusercontent.com:444/a', 'https://evil.example/a'] as $url) {
    check_release(!ESP_Updater::allowed_download_url($url), 'unexpected redirect/credentials/port rejected');
}
$fail_api = false; $corrupt = false;
add_filter('pre_http_request', static function ($reply, $args, $url) use (&$record, $zip, &$fail_api, &$corrupt) {
    if ($url === ESP_Updater::API) {
        return $fail_api ? new WP_Error('fixture_rate_limit', 'Fixture API failure') :
            ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => wp_json_encode($record), 'cookies' => []];
    }
    if ($url === $record['assets'][0]['browser_download_url']) {
        file_put_contents($args['filename'], $corrupt ? 'broken ZIP' : file_get_contents($zip));
        return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => '', 'cookies' => []];
    }
    // Core update check must succeed before it consults Update URI hooks.
    if (strpos($url, 'api.wordpress.org/plugins/update-check/') !== false) {
        return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [],
            'body' => '{"plugins":{},"no_update":{},"translations":[]}', 'cookies' => []];
    }
    if (strpos($url, 'api.wordpress.org/themes/update-check/') !== false) {
        return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => '{"themes":{},"no_update":{},"translations":[]}', 'cookies' => []];
    }
    if (strpos($url, home_url()) === 0) {
        return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => '<html>fixture loopback</html>', 'cookies' => []];
    }
    return new WP_Error('fixture_no_network', 'Unmocked network request');
}, 10, 3);
delete_site_transient(ESP_Updater::CACHE);
$fail_api = true;
check_release(ESP_Updater::release() === null && get_option('esp_settings') === $settings, 'GitHub outage preserves existing settings and operation');
$headers = get_plugin_data($installed . '/easy-slug-protect.php');
$offline = ESP_Updater::update_metadata(false, $headers, $plugin);
check_release(is_array($offline) && $offline['version'] === $headers['Version'] &&
    $offline['package'] === '' && $offline['slug'] === ESP_Updater::slug(),
    'GitHub outage offers current-version metadata without an unverified package');
wp_clean_plugins_cache(); wp_update_plugins();
$offline_updates = get_site_transient('update_plugins');
check_release(!isset($offline_updates->response[$plugin]) &&
    isset($offline_updates->no_update[$plugin]) &&
    $offline_updates->no_update[$plugin]->new_version === $headers['Version'] &&
    $offline_updates->no_update[$plugin]->package === '',
    'WordPress retains GitHub update support and auto-update UI during API outage');
$fail_api = false; delete_site_transient(ESP_Updater::CACHE);
$sentinel = (object) ['untouched' => true];
check_release(ESP_Updater::plugin_information($sentinel, 'plugin_information', (object) ['slug' => 'other']) === $sentinel,
    'plugin details handler leaves unrelated plugins untouched');
$info = plugins_api('plugin_information', (object) ['slug' => ESP_Updater::slug(), 'plugin' => $plugin]);
check_release($info->version === '0.7.41' && strpos($info->sections['changelog'], '<script>') === false, 'native plugin details expose escaped release notes');
$headers = get_plugin_data($installed . '/easy-slug-protect.php');
check_release(ESP_Updater::update_metadata('sentinel', $headers, 'other/main.php') === 'sentinel', 'shared GitHub-host hook targets exact basename');
$older = $record; $older['tag_name'] = 'v0.7.39'; $older['html_url'] = ESP_Updater::URI . '/releases/tag/v0.7.39';
$older['assets'][0]['name'] = 'easy-slug-protect-0.7.39.zip';
$older['assets'][0]['browser_download_url'] = ESP_Updater::URI . '/releases/download/v0.7.39/easy-slug-protect-0.7.39.zip';
set_site_transient(ESP_Updater::CACHE, ['release' => $older], 60);
$no_update = ESP_Updater::update_metadata(false, $headers, $plugin);
check_release($no_update['version'] === $headers['Version'] && $no_update['package'] === '', 'older stable release yields only installed-version no-update metadata');
wp_clean_plugins_cache(); wp_update_plugins();
$not_new = get_site_transient('update_plugins');
check_release(!isset($not_new->response[$plugin]) && isset($not_new->no_update[$plugin]),
    'Core retains update support and auto-update setting without offering a downgrade');
delete_site_transient(ESP_Updater::CACHE);
$corrupt = true;
$bad = ESP_Updater::download_package(false, $record['assets'][0]['browser_download_url'], null, ['plugin' => $plugin]);
check_release(is_wp_error($bad) && $bad->get_error_code() === 'esp_release_digest_mismatch', 'corrupt ZIP is rejected before installation');
$corrupt = false;
$verified_file = ESP_Updater::download_package(false, $record['assets'][0]['browser_download_url'], null, ['plugin' => $plugin]);
check_release(is_string($verified_file), 'valid dedicated ZIP passes download verification');
unlink($verified_file);
$main_before_requirement = file_get_contents($main);
file_put_contents($main, str_replace(' * Version:', " * Requires PHP: 999.0\n * Version:", $main_before_requirement));
$incompatible = ESP_Updater::validate_source(dirname($main) . '/', $package_root . '/', null, ['plugin' => $plugin]);
check_release(is_wp_error($incompatible) && $incompatible->get_error_code() === 'esp_release_requirements',
    'declared incompatible PHP requirement is rejected before source installation');
file_put_contents($main, str_replace(' * Version:', " * Requires at least: 999.0\n * Version:", $main_before_requirement));
$incompatible = ESP_Updater::validate_source(dirname($main) . '/', $package_root . '/', null, ['plugin' => $plugin]);
check_release(is_wp_error($incompatible) && $incompatible->get_error_code() === 'esp_release_requirements',
    'declared incompatible WordPress requirement is rejected before source installation');
file_put_contents($main, $main_before_requirement);
wp_clean_plugins_cache(); wp_update_plugins();
$updates = get_site_transient('update_plugins');
check_release(isset($updates->response[$plugin]) && $updates->response[$plugin]->new_version === '0.7.41', 'Core discovers the release via Update URI');
$item = $updates->response[$plugin];
// Core's non-Cron single upgrade registers a silent-deactivation callback
// without maintenance. Reject it BEFORE the download/filename replacement.
$single = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
$single_result = $single->upgrade($plugin);
if (!is_wp_error($single_result) ||
    $single_result->get_error_code() !== 'esp_unsafe_single_upgrade') {
    echo 'Single upgrade diagnostic: ' . (is_wp_error($single_result)
        ? $single_result->get_error_code() : var_export($single_result, true)) . "\n";
    echo 'Active after single: ' . (is_plugin_active($plugin) ? 'yes' : 'no') . "\n";
    echo 'Version after single: ' . get_plugin_data($installed . '/easy-slug-protect.php')['Version'] . "\n";
}
check_release(is_wp_error($single_result) &&
    $single_result->get_error_code() === 'esp_unsafe_single_upgrade' &&
    is_plugin_active($plugin) &&
    get_plugin_data($installed . '/easy-slug-protect.php')['Version'] === '0.7.40' &&
    get_option(ESP_Protection_State::RUNTIME_STATE_OPTION)['state'] === 'READY',
    'non-Cron single upgrader refuses silent deactivation before changing ESP');
$rollback = getenv('ESP_TEST_FATAL_ROLLBACK') === '1';
class FatalFixtureAutomaticUpdater extends WP_Automatic_Updater {
    protected function has_fatal_error() { return true; }
}
$auto = $rollback ? new FatalFixtureAutomaticUpdater() : new WP_Automatic_Updater();
update_site_option('auto_update_plugins', []);
check_release(!$auto->should_update('plugin', $item, WP_PLUGIN_DIR), 'Core respects administrator auto-update OFF');
update_site_option('auto_update_plugins', [$plugin]);
check_release($auto->should_update('plugin', $item, WP_PLUGIN_DIR), 'Core respects administrator auto-update ON');
if ($automatic) {
    $result = $auto->update('plugin', $item);
    if ($rollback) {
        check_release(is_wp_error($result) && in_array('plugin_update_fatal_error_rollback_successful', $result->get_error_codes(), true),
            'Core restores the actual backup after a simulated fatal-error decision');
    } else { check_release($result === true, 'Core automatic updater installs verified release'); }
} else {
    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result = $upgrader->bulk_upgrade([$plugin]);
    check_release(is_array($result) && !is_wp_error($result[$plugin]) && $result[$plugin] !== false, 'Core manual bulk/AJAX updater installs verified release');
}
check_release(is_plugin_active($plugin) && is_file($installed . '/easy-slug-protect.php') &&
    get_plugin_data($installed . '/easy-slug-protect.php')['Version'] === ($rollback ? '0.7.40' : '0.7.41'), 'update preserves folder, basename and active status');
check_release(get_option('esp_settings') === $settings &&
    $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . ESP_Config::DB_TABLES['session'], ARRAY_A) === $session,
    'file update preserves settings, path passwords and session bytes');
check_release(get_option('esp_plugin_version') === '0.7.40', 'old PHP never records new-code post-processing as complete');
ESP_Protection_State::mark_verification_required();
check_release(get_option('esp_runtime_state')['state'] === 'VERIFY_REQUIRED', 'old PHP only schedules new-code verification');
$wp_filesystem->delete($package_root, true);
echo 'Verified ' . ($automatic ? 'automatic' : 'manual') . " update with folder $folder; start a fresh PHP process for new-code checks.\n";
