<?php
/** Disposable HTTP Core upgrade fixture. Never shipped in the plugin. */
if (!defined('ABSPATH') || getenv('ESP_FG_CI_HTTP_AUTH') !== '1' ||
    !defined('DB_NAME') || DB_NAME !== 'esp_fg_lifecycle') { return; }

function esp_fg_upgrade_snapshot() {
    $uploads = wp_upload_dir(null, false);
    $rules = file_get_contents($uploads['basedir'] . '/.htaccess');
    $db = ESP_Fast_Gate_WP::ledger(false);
    $state = ESP_Migrator::read_option(ESP_Protection_State::RUNTIME_STATE_OPTION);
    $update = ESP_Migrator::read_option(ESP_Updater::STATE_OPTION, []);
    preg_match('/^# ESP Rewrite Writer: ([0-9.]+)$/m', $rules, $writer);
    return [
        'loaded' => ESP_VERSION,
        'disk' => get_plugin_data(ESP_PATH.'easy-slug-protect.php')['Version'],
        'installed' => ESP_Migrator::read_option(ESP_Config::VERSION_OPTION_KEY),
        'runtime' => $state['state'] ?? null,
        'verified' => $state['version'] ?? null,
        'update' => $update['status'] ?? null,
        'writer' => $writer[1] ?? null,
        'route' => ESP_Fast_Gate_Deployment::route_fast() ? 'fast' : 'legacy',
        'active' => ESP_Fast_Gate_WP::active(),
        'serving' => $db && $db->serving(),
        'legacy_rule' => strpos($rules, 'index.php?esp-media=') !== false,
        'fast_rule' => strpos($rules, '# Fast Gate: no static-file existence shortcut') !== false,
        'audit' => ESP_Media_Protection::audit_rewrite_policy(),
        'plugin_active' => is_plugin_active(ESP_Updater::basename()),
    ];
}

function esp_fg_upgrade_identity() {
    global $wpdb;
    $v = [ESP_Migrator::read_option(ESP_Config::OPTION_KEY)];
    foreach (['session','remember'] as $kind) {
        $rows = $wpdb->get_results("SELECT * FROM " .
            $wpdb->prefix . ESP_Config::DB_TABLES[$kind] . " ORDER BY id", ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== '') {
            throw new RuntimeException('credential_snapshot_failed');
        }
        $v[] = $rows;
    }
    // Compare only inside the fixture. Never return this value or auth rows.
    return hash('sha256', serialize($v));
}

add_action('wp_loaded', static function () {
    $action = $_GET['esp_fg_ci_upgrade'] ?? '';
    if (!in_array($action, ['perform','status'], true)) { return; }
    require_once ABSPATH.'wp-admin/includes/admin.php';
    require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
    header('Cache-Control: no-store');
    header('Content-Type: application/json');
    try {
        if ($action === 'status') {
            $r = get_option('esp_fg_ci_version_upgrade', []);
            $identity = $r['identity'] ?? null;
            unset($r['identity']);
            $r['current'] = esp_fg_upgrade_snapshot();
            $r['identity_preserved'] = is_string($identity) &&
                hash_equals($identity, esp_fg_upgrade_identity());
            echo wp_json_encode($r);
            exit;
        }
        $before = esp_fg_upgrade_snapshot();
        if ($before['runtime'] !== 'READY' || !$before['active'] ||
            !$before['serving'] || !$before['fast_rule'] ||
            get_option('esp_fg_ci_version_upgrade', false) !== false) {
            throw new RuntimeException('invalid_upgrade_fixture_state');
        }
        $zip = '/tmp/esp-fg-ci-next.zip';
        if (!is_file($zip)) { throw new RuntimeException('missing_fixture_zip'); }
        $parts = explode('.', ESP_VERSION);
        $parts[2] = (string)((int)$parts[2] + 1);
        $next = implode('.', $parts);
        $tag = 'v'.$next;
        $url = ESP_Updater::URI.'/releases/download/'.$tag.'/easy-slug-protect-'.$next.'.zip';
        $record = ['tag_name'=>$tag,'draft'=>false,'prerelease'=>false,
            'html_url'=>ESP_Updater::URI.'/releases/tag/'.$tag,
            'published_at'=>'2026-11-16T00:00:00Z','body'=>'Disposable CI fixture',
            'assets'=>[['name'=>'easy-slug-protect-'.$next.'.zip','state'=>'uploaded',
                'size'=>filesize($zip),'digest'=>'sha256:'.hash_file('sha256',$zip),
                'browser_download_url'=>$url]]];
        $report = ['before'=>$before, 'next'=>$next,
            'identity'=>esp_fg_upgrade_identity(), 'preinstall'=>null];
        update_option('esp_fg_ci_version_upgrade',$report,false);
        // Only upstream release metadata and binary download are substituted.
        add_filter('pre_http_request', static function ($pre,$args,$target) use ($record,$url,$zip) {
            if ($target === ESP_Updater::API) {
                return ['response'=>['code'=>200,'message'=>'OK'],'headers'=>[],
                    'body'=>wp_json_encode($record),'cookies'=>[]];
            }
            if ($target === $url && !empty($args['filename'])) {
                if (!copy($zip,$args['filename'])) {
                    return new WP_Error('esp_ci_zip_copy_failed');
                }
                return ['response'=>['code'=>200,'message'=>'OK'],
                    'headers'=>[],'body'=>'','cookies'=>[]];
            }
            if (strpos($target, 'api.wordpress.org/plugins/update-check/') !== false) {
                return ['response'=>['code'=>200,'message'=>'OK'],'headers'=>[],
                    'body'=>'{"plugins":{},"no_update":{},"translations":[]}', 'cookies'=>[]];
            }
            return $pre;
        },10,3);
        wp_set_current_user(1);
        delete_site_transient(ESP_Updater::CACHE);
        wp_clean_plugins_cache();
        wp_update_plugins();
        $plugin = ESP_Updater::basename();
        $updates = get_site_transient('update_plugins');
        if (($updates->response[$plugin]->new_version ?? null) !== $next) {
            throw new RuntimeException('core_update_not_discovered');
        }
        // Observe production pre-install after both Gate stop (5) and
        // runtime UPDATING (10), before Core writes the replacement files.
        add_filter('upgrader_pre_install', static function ($response,$extra) use (&$report,$plugin) {
            if (($extra['plugin'] ?? '') !== $plugin || is_wp_error($response)) {
                return $response;
            }
            $v = esp_fg_upgrade_snapshot();
            $report['preinstall'] = $v;
            if ($v['route'] !== 'legacy' || $v['active'] || $v['serving'] ||
                !$v['legacy_rule'] || $v['fast_rule'] || !$v['audit'] ||
                $v['runtime'] !== 'UPDATING' || $v['disk'] !== $v['loaded']) {
                return new WP_Error('esp_ci_unsafe_preinstall');
            }
            return $response;
        },20,2);
        // The actual Core bulk upgrader does filesystem/maintenance/backup
        // work and calls the real registered ESP hooks.
        ob_start();
        try {
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $result = $upgrader->bulk_upgrade([$plugin]);
        } finally { ob_end_clean(); }
        $entry = is_array($result) ? ($result[$plugin] ?? null) : $result;
        $report['core_success'] = is_array($entry);
        $report['core_error'] = is_wp_error($entry) ? $entry->get_error_code() : null;
        $report['old_after'] = esp_fg_upgrade_snapshot();
        $report['old_identity_preserved'] =
            hash_equals($report['identity'], esp_fg_upgrade_identity());
        update_option('esp_fg_ci_version_upgrade',$report,false);
        // Production shutdown hook at 200 is responsible for VERIFY_REQUIRED.
        // Observe it afterwards, do not call that hook manually.
        add_action('shutdown', static function () {
            $r = get_option('esp_fg_ci_version_upgrade', []);
            $r['old_shutdown'] = esp_fg_upgrade_snapshot();
            $r['verify_scheduled'] = (bool)wp_next_scheduled('esp_verify_runtime_once');
            update_option('esp_fg_ci_version_upgrade',$r,false);
        },300);
        unset($report['identity']);
        echo wp_json_encode($report);
    } catch (Throwable $e) {
        status_header(500);
        // Do not disclose exception messages, paths, cookie or auth rows.
        echo wp_json_encode(['error'=>get_class($e)]);
    }
    exit;
},100);
