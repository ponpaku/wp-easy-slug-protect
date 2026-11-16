<?php
/**
 * Isolated Fast Gate HTTP E2E fixture. Installed as a MUST-USE plugin only
 * inside the disposable Actions WordPress image, never in the release ZIP.
 *
 * Requests are permitted exclusively when the Docker container has the
 * explicit ESP_FG_CI_HTTP_AUTH=1 flag and uses the disposable database.
 */
if (!defined('ABSPATH')) { exit; }

// The temporary benchmark uses a deliberately non-production serving flag.
// Only the handoff callback needs the corresponding WordPress active marker.
// This is scoped to the disposable DB and container flag, not to other URLs.
if (getenv('ESP_FG_CI_HTTP_AUTH') === '1' &&
    defined('DB_NAME') && DB_NAME === 'esp_fg_lifecycle' &&
    isset($_GET['esp_gate_bridge'])) {
    add_filter('pre_option_esp_fast_gate_v2_active',
        static function () { return '1'; }, 1);
}

// Faults are request-local and execute before the actual product bridge.
// A real SQL error is injected only at the exact credential SELECT, never
// into ownership queries or the authorization decision itself.
add_action('template_redirect', static function () {
    if (getenv('ESP_FG_CI_HTTP_AUTH') !== '1' ||
        !defined('DB_NAME') || DB_NAME !== 'esp_fg_lifecycle' ||
        !isset($_GET['esp_gate_bridge'])) { return; }
    $fault = $_SERVER['HTTP_X_ESP_CI_BRIDGE_FAULT'] ?? '';
    if (in_array($fault, ['mysql-session', 'mysql-remember'], true)) {
        global $wpdb;
        $kind = $fault === 'mysql-session' ? 'session' : 'remember';
        $table = $wpdb->prefix . ESP_Config::DB_TABLES[$kind];
        $wpdb->suppress_errors(true);
        add_filter('query', static function ($sql) use ($table, $fault) {
            if (preg_match('/^\s*SELECT\b/i', $sql) &&
                strpos($sql, 'UNIX_TIMESTAMP(expires) AS expires_ts') !== false &&
                preg_match('/\bFROM\s+\x60?' . preg_quote($table, '/') .
                    '\x60?\s+WHERE\b/i', $sql)) {
                header('X-ESP-CI-Fault-Applied: ' . $fault);
                return 'SELECT id FROM esp_fg_ci_nonexistent_table';
            }
            return $sql;
        }, 1);
    } elseif ($fault === 'headers-sent') {
        // Intentionally commit an HTTP status and a non-media marker before
        // the product runs. Already-sent headers cannot be changed to 503;
        // success means no additional bytes, redirect, or Gate Set-Cookie.
        while (ob_get_level() > 0 && @ob_end_clean()) {}
        header('Content-Type: text/plain');
        header('Cache-Control: no-store');
        header('X-ESP-CI-Fault-Applied: headers-sent');
        status_header(200);
        echo "CI_HEADERS_SENT\n";
        flush();
        if (!headers_sent()) {
            throw new RuntimeException('CI headers-sent injection did not commit');
        }
    }
}, -100);

// Observe the actual updater retry callback in a real wp-cron.php worker.
// Record only fixed status/reason fields, independently of the HTTP caller.
function esp_fg_ci_retry_snapshot() {
    $out = [];
    foreach (['runtime'=>ESP_Protection_State::RUNTIME_STATE_OPTION,
              'migration'=>ESP_Migrator::STATE_OPTION,
              'update'=>ESP_Updater::STATE_OPTION] as $kind=>$option) {
        $raw = ESP_Migrator::read_option($option, []);
        $out[$kind] = [];
        foreach (['state','status','step','reason','version'] as $field) {
            $value = is_array($raw) ? ($raw[$field] ?? null) : null;
            if (is_string($value) && preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $value)) {
                $out[$kind][$field] = $value;
            }
        }
    }
    $reason = ESP_Migrator::last_failure_reason();
    $out['migration_reason'] = is_string($reason) &&
        preg_match('/^[a-z_]{1,80}$/D', $reason) ? $reason : null;
    $db = ESP_Fast_Gate_WP::ledger(false);
    $out['route_fast'] = ESP_Fast_Gate_Deployment::route_fast();
    $out['active'] = ESP_Fast_Gate_WP::active();
    $out['serving'] = $db && $db->serving();
    return $out;
}
foreach ([-100=>'before', 100=>'after'] as $priority=>$phase) {
    add_action('esp_update_retry', static function () use ($phase) {
        if (getenv('ESP_FG_CI_HTTP_AUTH') !== '1' ||
            !defined('DB_NAME') || DB_NAME !== 'esp_fg_lifecycle' ||
            !defined('DOING_CRON') || !DOING_CRON) { return; }
        $report = get_option('esp_fg_ci_retry_report', []);
        if (!is_array($report) ||
            ($report['phase'] ?? '') !== ($phase === 'before' ? 'armed' : 'before')) {
            return;
        }
        try {
            $report[$phase] = esp_fg_ci_retry_snapshot();
            $report['phase'] = $phase;
            update_option('esp_fg_ci_retry_report', $report, false);
            error_log('FG_CI_RUNTIME_RETRY ' . wp_json_encode($report));
        } catch (\Throwable $e) {
            // Never record exception payloads; report the failed observation.
            error_log('FG_CI_RUNTIME_RETRY observation_failed');
        }
    }, $priority);
}

add_action('init', static function () {
    // Test-only fault injection for an actual administrator form submission.
    // Scoped to the disposable DB, explicitly enabled fixture and one request.
    // It never intercepts normal media traffic or production deployments.
    if (getenv('ESP_FG_CI_HTTP_AUTH') === '1' &&
        defined('DB_NAME') && DB_NAME === 'esp_fg_lifecycle' &&
        ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&
        ($_POST['action'] ?? '') === 'esp_fast_gate_enable' &&
        ($_SERVER['HTTP_X_ESP_CI_FAIL_PROBE'] ?? '') === '1') {
        add_filter('pre_http_request',
            static function ($pre, $args, $url) {
                if (strpos($url, '/esp-fast-gate-route-') !== false) {
                    return new WP_Error('esp_ci_ui_probe_forced_failure');
                }
                return $pre;
            }, 10, 3);
    }
    if (!isset($_GET['esp_fg_ci_auth']) &&
        !isset($_GET['esp_fg_ci_redirect_landed'])) { return; }
    if (getenv('ESP_FG_CI_HTTP_AUTH') !== '1' ||
        !defined('DB_NAME') || DB_NAME !== 'esp_fg_lifecycle' ||
        !class_exists('ESP_Fast_Gate_WP') ||
        !class_exists('ESP_Auth')) {
        status_header(404);
        exit;
    }
    if (isset($_GET['esp_fg_ci_redirect_landed'])) {
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode([
            'landed'=>true,
            'source_cookie_seen'=>isset($_COOKIE['esp_auth_fg_path']) ||
                isset($_COOKIE['esp_remember_token_fg_path']),
            'signed_cookie_seen'=>isset($_COOKIE['esp_gate_fg_path']),
        ]);
        exit;
    }
    $action = (string)$_GET['esp_fg_ci_auth'];
    if (in_array($action, [
        'deploy-prepare','deploy-enable-fail-probe','deploy-enable','deploy-disable',
        'deploy-enable-private-404','deploy-enable-private-exposed',
        'deploy-status','deploy-legacy-logout','deploy-materialize-unknown',
        'deploy-inject-stale-lock','deploy-recover',
        'deploy-upgrade-preflight','deploy-upgrade-no-server',
        'deploy-arm-runtime-retry','deploy-runtime-retry-status'
    ], true)) {
        // Deployment endpoint is a disposable fixture only. Unlike the auth
        // fixture it never forces the active option or serving flag.
        $state = static function () {
            $db = ESP_Fast_Gate_WP::ledger(false);
            return ['active'=>ESP_Fast_Gate_WP::active(),
                'route'=>ESP_Fast_Gate_Deployment::route_fast()?'fast':'legacy',
                'serving'=>$db && $db->serving(),
                'prepared'=>ESP_Fast_Gate_Deployment::prepared()];
        };
        $result = true;
        if ($action === 'deploy-prepare') {
            $result=ESP_Fast_Gate_Deployment::prepare();
        } elseif ($action === 'deploy-arm-runtime-retry') {
            // The physical fault phase and the real Cron phase are explicitly
            // serialized. Stale retries can legitimately still be queued on a
            // READY site after post-update work finished in an admin request.
            $before = esp_fg_ci_retry_snapshot();
            $result = false;
            if (($before['runtime']['state'] ?? '') === 'READY' &&
                $before['route_fast'] && $before['active'] && $before['serving']) {
                wp_clear_scheduled_hook('esp_update_retry');
                delete_transient('doing_cron');
                update_option('esp_fg_ci_retry_report', ['phase'=>'armed'], false);
                $result = wp_schedule_single_event(time() - 1, 'esp_update_retry', [], true);
            }
        } elseif ($action === 'deploy-runtime-retry-status') {
            $result = true;
        } elseif ($action === 'deploy-inject-stale-lock') {
            $db=ESP_Fast_Gate_WP::ledger(true);
            $result=false;
            if (!ESP_Fast_Gate_Deployment::route_fast() &&
                !ESP_Fast_Gate_WP::active() && $db && !$db->serving() &&
                add_option('esp_fast_gate_v2_deploy_lock',
                    (time()-3600).':'.wp_generate_uuid4(), '', false)) {
                $set_route=new ReflectionMethod(
                    'ESP_Fast_Gate_Deployment','write_route');
                $rewrite=new ReflectionMethod(
                    'ESP_Fast_Gate_Deployment','rewrite');
                // Simulate a crashed controller AFTER installing its 503
                // route, BEFORE signing or serving were enabled.
                $result=$set_route->invoke(null,'fast') === true &&
                    $rewrite->invoke(null,true) === true;
            }
        } elseif ($action === 'deploy-recover') {
            $result=ESP_Fast_Gate_Deployment::recover();
        } elseif (in_array($action,
            ['deploy-upgrade-preflight','deploy-upgrade-no-server'], true)) {
            // Run the product pre-install callback without installing files.
            // One case simulates a WP-Cron/CLI update lacking server context.
            $previous=$_SERVER['SERVER_SOFTWARE'] ?? null;
            if ($action === 'deploy-upgrade-no-server') {
                unset($_SERVER['SERVER_SOFTWARE']);
            }
            try {
                $result=ESP_Fast_Gate_Deployment::before_plugin_upgrade(
                    true, ['type'=>'plugin','action'=>'update','plugin'=>
                        plugin_basename(ESP_PATH.'easy-slug-protect.php')]);
            } finally {
                if ($previous === null) { unset($_SERVER['SERVER_SOFTWARE']); }
                else { $_SERVER['SERVER_SOFTWARE']=$previous; }
            }
        } elseif ($action === 'deploy-enable-fail-probe') {
            $failure = static function ($pre,$args,$url) {
                if (strpos($url,'/esp-fast-gate-route-') !== false) {
                    return new WP_Error('esp_ci_http_probe_forced_failure');
                }
                return $pre;
            };
            add_filter('pre_http_request',$failure,10,3);
            try { $result=ESP_Fast_Gate_Deployment::enable(); }
            finally { remove_filter('pre_http_request',$failure,10); }
        } elseif (in_array($action, [
            'deploy-enable-private-404','deploy-enable-private-exposed',
        ], true)) {
            // In the real Apache fixture the private store is 403. Simulate a
            // host that hides restricted files as 404, or an unsafe host that
            // exposes them as 200. Only the actual private URL family is
            // intercepted; the signed direct gate and uploads rewrite still
            // have to pass real HTTP checks.
            $code = $action === 'deploy-enable-private-404' ? 404 : 200;
            $mask = static function ($pre,$args,$url) use ($code) {
                if (strpos($url, '/esp-fast-gate-v2/') === false) {
                    return $pre;
                }
                return [
                    'headers'=>[], 'body'=>'',
                    'response'=>['code'=>$code,
                        'message'=>$code===404?'Not Found':'OK'],
                    'cookies'=>[],
                ];
            };
            add_filter('pre_http_request',$mask,10,3);
            try { $result=ESP_Fast_Gate_Deployment::enable(); }
            finally { remove_filter('pre_http_request',$mask,10); }
        } elseif ($action === 'deploy-enable') {
            $result=ESP_Fast_Gate_Deployment::enable();
        } elseif ($action === 'deploy-disable') {
            $result=ESP_Fast_Gate_Deployment::disable();
        } elseif ($action === 'deploy-materialize-unknown') {
            // Fixed, non-secret filename in the disposable CI site only.
            // Deliberately bypass WordPress metadata so this path is NOT
            // added to the catalog. The same URL was tested as absent first.
            $result = false;
            $uploads = wp_upload_dir(null, false);
            $root = realpath($uploads['basedir'] ?? '');
            $ledger = ESP_Fast_Gate_WP::ledger(false);
            $name = 'esp-unknown-probe.jpg';
            if ($root && $ledger && $ledger->serving() &&
                ESP_Fast_Gate_WP::active() &&
                ESP_Fast_Gate_Deployment::route_fast() &&
                $ledger->get_media($name) === null) {
                $file = $root . '/' . $name;
                $bytes = @file_get_contents($root . '/fg-life-public.jpg');
                if (is_string($bytes) && strlen($bytes) > 100) {
                    $handle = @fopen($file, 'x');
                    if ($handle !== false) {
                        $written = @fwrite($handle, $bytes) === strlen($bytes) &&
                            @fflush($handle);
                        $closed = @fclose($handle);
                        $result = $written && $closed;
                        if (!$result) { @unlink($file); }
                    }
                }
            }
        } elseif ($action === 'deploy-legacy-logout') {
            if (ESP_Fast_Gate_WP::active() ||
                ESP_Fast_Gate_Deployment::route_fast()) {
                $result=new WP_Error('esp_ci_legacy_logout_requires_off');
            } else {
                $path=ESP_Option::get_current_setting('path')['fg_path'] ?? null;
                $logout=new ESP_Logout();
                $method=new ReflectionMethod('ESP_Logout','logout_from_path');
                $result=$path && $method->invoke($logout,$path) === true;
                ESP_Cookie::get_instance()->set_pending_cookies();
            }
        }
        $response=['action'=>$action,'success'=>$result===true,
            'error'=>$result instanceof WP_Error?$result->get_error_code():null]
            +$state();
        if ($action === 'deploy-runtime-retry-status') {
            $response['retry'] = get_option('esp_fg_ci_retry_report', []);
        }
        status_header($result===true?200:409);
        header('Cache-Control: no-store');
        header('Content-Type: application/json');
        echo wp_json_encode($response);
        exit;
    }
    $supported = ['session', 'remember', 'redirect-session',
        'redirect-remember', 'refresh', 'logout',
        'logout-fail-session', 'logout-fail-remember', 'rotate',
        'login-page-setup', 'login-page-teardown',
        'mint-expired-proof', 'expire-remember-source'];
    if (!in_array($action, $supported, true)) {
        status_header(404);
        exit;
    }
    // This override exists only for the controlled HTTP endpoint. Production
    // option updates must still reject activation until Rewrite is shipped.
    add_filter('pre_option_' . ESP_Fast_Gate_WP::ACTIVE_OPTION,
        static function () { return '1'; }, 1);
    $all_paths = ESP_Option::get_current_setting('path');
    $path = $all_paths['fg_path'] ?? null;
    $ledger = ESP_Fast_Gate_WP::ledger(true);
    if (!$path || !$ledger || !$ledger->serving()) {
        status_header(503);
        exit;
    }
    $ok = false;
    $result = ['action' => $action];
    if ($action === 'login-page-setup') {
        // Create the login page only after the legacy anonymous benchmark
        // completed. Changing login_page in seed-release would invalidate
        // the old-mode 403 baseline and mask regressions in other scenarios.
        $login_id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'FG CI Login',
            'post_name' => 'fg-ci-login',
            'post_content' => '[esp_login_form path_id="fg_path"]',
        ], true);
        $settings = get_option(ESP_Config::OPTION_KEY);
        if (!is_wp_error($login_id) && (int)$login_id > 0 &&
            is_array($settings) &&
            isset($settings['path']['fg_path'])) {
            $settings['path']['fg_path']['login_page'] = (int)$login_id;
            $ok = update_option(ESP_Config::OPTION_KEY, $settings);
            $result['login_page_id'] = (int)$login_id;
        }
        $result['login_page_configured'] = $ok;
    } elseif ($action === 'login-page-teardown') {
        // Do not leak this test-only login_page setting into the separate
        // activation/rollback E2E, whose legacy anonymous baseline is 403.
        $settings = get_option(ESP_Config::OPTION_KEY);
        if (is_array($settings) &&
            isset($settings['path']['fg_path']['login_page']) &&
            (int)$settings['path']['fg_path']['login_page'] > 0) {
            $settings['path']['fg_path']['login_page'] = 0;
            $ok = update_option(ESP_Config::OPTION_KEY, $settings);
        }
        $result['login_page_restored_to_zero'] = $ok;
    } elseif (in_array($action,
        ['session','remember','redirect-session','redirect-remember'], true)) {
        if (in_array($action, ['remember','redirect-remember'], true)) {
            $_POST['esp_remember'] = '1';
        } else { unset($_POST['esp_remember']); }
        $auth = new ESP_Auth();
        $ok = $auth->process_login($path, 'disposable-ci-password');
        if ($ok && strpos($action, 'redirect-') === 0) {
            // This invokes the actual shipped cookie + redirect method.
            // The client must receive Set-Cookie on the HTTP 302 and send
            // both original and signed cookies on the following GET.
            ESP_Cookie::get_instance()->do_redirect(
                '/?esp_fg_ci_redirect_landed=1');
        }
        if ($ok) { ESP_Cookie::get_instance()->set_pending_cookies(); }
        $result['authenticated'] = $ok;
    } elseif ($action === 'mint-expired-proof') {
        // Use the real signer and a backdated issuance time: not a forged
        // signature, and no clock change or 24h sleep. Scope to an actually
        // valid Remember credential. Keep the client cookie alive so it
        // really transmits the cryptographically expired proof.
        $auth = new ESP_Auth();
        $credentials = $auth->fast_gate_bridge_candidates($path);
        $current = is_array($credentials) && count($credentials) === 1
            ? $credentials[0] : null;
        $keys = require ESP_Fast_Gate_WP::private_dir() . '/keys.php';
        $key = $ledger->ticket_signing_key_strict($keys['sig']);
        if ($current && $current['kind'] === 'remember' && $key !== null) {
            $now = time();
            $issued = $now - ESP_Fast_Gate_Token::MAX_AGE - 10;
            $which = (string)$path['id'];
            $version = (int)$path['password_version'];
            $ticket = ESP_Fast_Gate_Token::issue($keys['site'], $which,
                'remember', $current['user'], $current['token'],
                $version, $current['expires'], $key, $keys['id'], $issued);
            $handoff = ESP_Fast_Gate_Handoff::issue($keys['site'], $which,
                'fg-life-protected.jpg', 'GET', $key,
                $now - ESP_Fast_Gate_Handoff::MAX_AGE - 10);
            if ($ticket && $handoff &&
                ESP_Fast_Gate_Token::verify($ticket, $keys['site'], $which,
                    $_COOKIE, $version, $key, $keys['id'], $issued) !== null &&
                ESP_Fast_Gate_Token::verify($ticket, $keys['site'], $which,
                    $_COOKIE, $version, $key, $keys['id'], $now) === null &&
                ESP_Fast_Gate_Handoff::verify($handoff, $keys['site'], 'GET',
                    $key, $now - ESP_Fast_Gate_Handoff::MAX_AGE - 10) !== null &&
                ESP_Fast_Gate_Handoff::verify($handoff, $keys['site'], 'GET',
                    $key, $now) === null) {
                $cookies = ESP_Cookie::get_instance();
                $cookies->queue_gate_ticket($which, $ticket, $now + 3600);
                $ok = $cookies->set_pending_cookies();
                // Private fixture response only; never print the value.
                $result['expired_handoff'] = $handoff;
                $result['expired_signed_ticket'] = $ok;
            }
        }
    } elseif ($action === 'expire-remember-source') {
        // Expire only this disposable source row, without revoking it.
        // Test the bridge with the ALREADY EXPIRED Gate proof, not the
        // still-valid proof of another client (direct SQL is not a supported
        // instantaneous revocation mechanism).
        global $wpdb;
        $remember = ESP_Cookie::get_instance()->get_remember_cookies_for_path($path);
        if (is_array($remember) && isset($remember['id'], $remember['token'])) {
            $table = $wpdb->prefix . ESP_Config::DB_TABLES['remember'];
            $where = $wpdb->prepare(" WHERE user_id=%s AND token=%s AND path_id=%s",
                $remember['id'], hash_hmac('sha256', $remember['token'], AUTH_SALT),
                $path['id']);
            $changed = $wpdb->query("UPDATE $table SET expires=DATE_SUB(NOW(), INTERVAL 10 SECOND)"
                . $where . " AND expires>NOW()");
            $ok = $changed === 1 && (int)$wpdb->get_var(
                "SELECT COUNT(*) FROM $table" . $where . " AND expires<=NOW()") === 1;
        }
        $result['source_expired'] = $ok;
    } elseif ($action === 'refresh') {
        $auth = new ESP_Auth();
        $ok = $auth->is_logged_in($path);
        if ($ok) { ESP_Cookie::get_instance()->set_pending_cookies(); }
        $result['authenticated'] = $ok;
    } elseif (in_array($action, [
        'logout', 'logout-fail-session', 'logout-fail-remember'
    ], true)) {
        // A deterministic real-MySQL fault: substitute only the targeted
        // DELETE with a statement against a nonexistent disposable table.
        // No SQL wrapper mock, and the other login-kind delete is unaffected.
        // The test expects the SQLite ref to be revoked BEFORE either DELETE.
        $kind = null;
        if ($action === 'logout-fail-session') { $kind = 'session'; }
        if ($action === 'logout-fail-remember') { $kind = 'remember'; }
        global $wpdb;
        $attempted = 0;
        $inject = null;
        if ($kind !== null) {
            $table = $wpdb->prefix . ESP_Config::DB_TABLES[$kind];
            $inject = static function ($query) use ($table, &$attempted) {
                if (strpos($query, 'DELETE FROM `' . $table . '` ') !== false ||
                    strpos($query, 'DELETE FROM ' . $table . ' ') !== false) {
                    $attempted++;
                    return 'DELETE FROM esp_fg_ci_nonexistent_table WHERE id=1';
                }
                return $query;
            };
            add_filter('query', $inject, 1);
        }
        try {
            $logout = new ESP_Logout();
            $method = new ReflectionMethod('ESP_Logout', 'logout_from_path');
            $ok = $method->invoke($logout, $path) === true;
        } finally {
            if ($inject !== null) { remove_filter('query', $inject, 1); }
        }
        ESP_Cookie::get_instance()->set_pending_cookies();
        $result['logged_out'] = $ok;
        if ($kind !== null) {
            $result['injected_delete_failed'] = $attempted === 1;
            $c = ESP_Cookie::get_instance();
            $token = $kind === 'session'
                ? $c->get_session_cookie('fg_path')
                : ($c->get_remember_cookies_for_path($path)['token'] ?? null);
            // Test that the failed delete left the exact original credential
            // in MySQL, not just a different login record.
            $target_table = $wpdb->prefix . ESP_Config::DB_TABLES[$kind];
            $result['target_row_retained'] =
                is_string($token) && (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $target_table
                     WHERE token=%s AND path_id=%s",
                    hash_hmac('sha256', $token, AUTH_SALT), 'fg_path'
                )) === 1;
        }
    } elseif ($action === 'rotate') {
        $settings = get_option(ESP_Config::OPTION_KEY);
        if (is_array($settings) &&
            isset($settings['path']['fg_path']['password_version'])) {
            $settings['path']['fg_path']['password_version']++;
            $ok = update_option(ESP_Config::OPTION_KEY, $settings);
            $result['password_version'] =
                (int)get_option(ESP_Config::OPTION_KEY)['path']['fg_path']['password_version'];
        }
        $result['rotated'] = $ok;
    }
    $result['serving'] = $ledger->serving();
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=utf-8');
    if (!$ok) { status_header(409); }
    echo wp_json_encode($result);
    exit;
}, PHP_INT_MAX);
