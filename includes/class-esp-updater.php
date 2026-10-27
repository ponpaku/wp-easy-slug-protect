<?php
if (!defined('ABSPATH')) { exit; }

/** GitHub public stable releases, WordPress updater integration and post-update work. */
final class ESP_Updater {
    const URI = 'https://github.com/ponpaku/wp-easy-slug-protect';
    const API = 'https://api.github.com/repos/ponpaku/wp-easy-slug-protect/releases/latest';
    const SLUG = 'easy-slug-protect';
    const CACHE = 'esp_stable_release_v1';
    const STATE_OPTION = 'esp_update_state';
    private static $activation = false;
    private static $download_release = null;

    public static function basename() { return plugin_basename(ESP_PATH . 'easy-slug-protect.php'); }
    // Core also uses the update slug to find its automatic-update backup folder.
    public static function slug() { return dirname(self::basename()); }

    public static function register($setup) {
        // Also registered when the protection gate is pending/failed.
        add_filter('update_plugins_github.com', [self::class, 'update_metadata'], 10, 4);
        add_filter('plugins_api', [self::class, 'plugin_information'], 10, 3);
        // Single non-Cron Plugin_Upgrader::upgrade() silently deactivates
        // active plugins before replacing files and does not enable Core
        // maintenance mode. Refuse only that path before any download.
        add_filter('upgrader_pre_download', [self::class, 'refuse_unsafe_single_upgrade'], 1, 4);
        add_filter('upgrader_pre_download', [self::class, 'download_package'], 10, 4);
        add_filter('upgrader_source_selection', [self::class, 'validate_source'], 10, 4);
        add_action('esp_update_retry', static function () use ($setup) {
            ESP_Protection_State::verify_and_commit($setup);
        });
        // Admin-only, lightweight recovery if WP-Cron rejected the last
        // one-shot retry. No migration or mandatory post-update work here.
        add_action('admin_init', [self::class, 'recover_missing_retry']);
        add_action('admin_notices', [self::class, 'notice']);
        add_action('admin_post_esp_retry_update', static function () use ($setup) {
            if (!current_user_can('manage_options')) { wp_die('Forbidden', '', ['response' => 403]); }
            check_admin_referer('esp_retry_update');
            // No force-unlock or blind restart. Stuck file updates use
            // #48's separate stopped-job confirmation. No V1 data conversion.
            $lock = ESP_Protection_State::acquire_verification_lock();
            if ($lock !== null) {
                try { (new ESP_Migrator())->run($lock); }
                finally { ESP_Protection_State::release_verification_lock($lock); }
            }
            self::reset_retry_budget();
            ESP_Protection_State::verify_and_commit($setup);
            wp_safe_redirect(admin_url('plugins.php')); exit;
        });
    }

    public static function can_finish_now() {
        // is_admin() also covers anonymous admin-ajax.php/admin-post.php
        // requests. Only a capable administrator, trusted activation, Cron
        // or WP-CLI may begin database migration/post-update verification.
        return self::$activation || (defined('DOING_CRON') && DOING_CRON) ||
            (defined('WP_CLI') && WP_CLI) ||
            (is_admin() && current_user_can('manage_options'));
    }

    public static function during_activation($callback) {
        self::$activation = true;
        try { return $callback(); } finally { self::$activation = false; }
    }

    /** Canonical SemVer stable only; no PHP version_compare acceptance of odd tags. */
    public static function tag_version($tag) {
        return is_string($tag) && preg_match('/^v?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $tag)
            ? ltrim($tag, 'v') : null;
    }

    /** Validate the complete, public GitHub API record before caching it. */
    public static function parse_release($r) {
        if (!is_array($r) || ($r['draft'] ?? null) !== false || ($r['prerelease'] ?? null) !== false ||
            !isset($r['tag_name'], $r['html_url'], $r['published_at'], $r['assets']) ||
            !is_string($r['published_at']) || strtotime($r['published_at']) === false || !is_array($r['assets'])) { return null; }
        $version = self::tag_version($r['tag_name']);
        if ($version === null || $r['html_url'] !== self::URI . '/releases/tag/' . $r['tag_name']) { return null; }
        $name = self::SLUG . '-' . $version . '.zip';
        $found = [];
        foreach ($r['assets'] as $asset) {
            if (is_array($asset) && ($asset['name'] ?? '') === $name) { $found[] = $asset; }
        }
        if (count($found) !== 1) { return null; }
        $a = $found[0];
        $package = self::URI . '/releases/download/' . $r['tag_name'] . '/' . $name;
        if (($a['state'] ?? '') !== 'uploaded' || ($a['browser_download_url'] ?? '') !== $package ||
            !isset($a['size'], $a['digest']) || !is_int($a['size']) || $a['size'] <= 0 || $a['size'] > 20 * 1024 * 1024 ||
            !is_string($a['digest']) || !preg_match('/^sha256:[a-f0-9]{64}$/D', $a['digest'])) { return null; }
        return ['version' => $version, 'tag' => $r['tag_name'], 'url' => $r['html_url'],
            'package' => $package, 'sha256' => substr($a['digest'], 7), 'size' => $a['size'],
            'published_at' => $r['published_at'], 'notes' => is_string($r['body'] ?? null) ? $r['body'] : ''];
    }

    public static function release() {
        // Never use stale successful metadata after a failed refresh. Network failure is
        // an update-discovery failure; it does not alter protection/migration state.
        $cached = get_site_transient(self::CACHE);
        if (is_array($cached) && array_key_exists('release', $cached)) { return $cached['release'] === null ? null : self::parse_release($cached['release']); }
        $reply = wp_safe_remote_get(self::API, [
            'timeout' => 5, 'redirection' => 0, 'limit_response_size' => 1024 * 1024,
            'headers' => ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'],
        ]);
        $release = null; $raw = null;
        if (!is_wp_error($reply) && wp_remote_retrieve_response_code($reply) === 200) {
            $raw = json_decode(wp_remote_retrieve_body($reply), true);
            $release = self::parse_release($raw);
        }
        set_site_transient(self::CACHE, ['release' => $release === null ? null : $raw], $release === null ? 15 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS);
        return $release;
    }

    public static function update_metadata($update, $plugin_data, $plugin_file, $locales = []) {
        if ($plugin_file !== self::basename() || ($plugin_data['UpdateURI'] ?? '') !== self::URI || is_multisite()) { return $update; }
        $r = self::release();
        if ($r === null || !version_compare($r['version'], $plugin_data['Version'], '>')) {
            // Core puts this into no_update. That metadata is also how its plugin
            // list recognizes update support and shows the auto-update setting.
            return ['id' => self::URI, 'slug' => self::slug(), 'version' => $plugin_data['Version'],
                'url' => self::URI, 'package' => ''];
        }
        // Do not set autoupdate=true: the administrator's WordPress setting is authoritative.
        return ['id' => self::URI, 'slug' => self::slug(), 'version' => $r['version'],
            'url' => $r['url'], 'package' => $r['package']];
    }

    public static function plugin_information($result, $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) ||
            ($args->slug ?? '') !== self::slug() || (isset($args->plugin) && $args->plugin !== self::basename())) { return $result; }
        $r = self::release();
        if ($r === null) { return $result; }
        return (object) ['name' => 'Easy Slug Protect', 'slug' => self::slug(), 'version' => $r['version'],
            'author' => 'ponpaku', 'homepage' => self::URI, 'download_link' => $r['package'],
            'last_updated' => $r['published_at'], 'sections' => [
                'description' => esc_html__('URLの階層ごとにパスワード保護を行います。更新はGitHubの公開Stable Releaseから取得します。', ESP_Config::TEXT_DOMAIN),
                'changelog' => '<pre>' . esc_html($r['notes']) . '</pre>',
            ]];
    }

    /**
     * The usual AJAX bulk updater and Cron updater keep their Core
     * maintenance handling. Only non-Cron Plugin_Upgrader::upgrade() installs
     * a silent-deactivation pre-install callback without maintenance mode.
     * Check that *actual registered callback* rather than guessing from a
     * screen, filename or PHP class: bulk and single share that class.
     */
    public static function refuse_unsafe_single_upgrade($reply, $package, $upgrader, $extra = []) {
        if (is_wp_error($reply) ||
            ($extra['plugin'] ?? null) !== self::basename() ||
            (isset($extra['action']) && $extra['action'] !== 'update') ||
            (function_exists('wp_doing_cron') ? wp_doing_cron() :
                (defined('DOING_CRON') && DOING_CRON))) {
            return $reply;
        }
        if ($upgrader instanceof Plugin_Upgrader &&
            has_filter('upgrader_pre_install', [$upgrader, 'deactivate_plugin_before_upgrade']) !== false) {
            return new WP_Error('esp_unsafe_single_upgrade',
                'ESP: this single-plugin update would temporarily disable protection; use the standard AJAX/bulk updater or external maintenance.');
        }
        return $reply;
    }

    public static function allowed_download_url($url) {
        $p = wp_parse_url($url);
        return is_array($p) && ($p['scheme'] ?? '') === 'https' && !isset($p['user']) && !isset($p['pass']) && !isset($p['fragment']) &&
            (!isset($p['port']) || $p['port'] === 443) &&
            (($p['host'] ?? '') === 'release-assets.githubusercontent.com' ||
                (($p['host'] ?? '') === 'github.com' && strpos($p['path'] ?? '', '/ponpaku/wp-easy-slug-protect/releases/download/') === 0));
    }

    public static function download_package($reply, $package, $upgrader, $extra = []) {
        if (($extra['plugin'] ?? null) !== self::basename() || (isset($extra['action']) && $extra['action'] !== 'update') || is_wp_error($reply)) { return $reply; }
        // A URL in an old update transient is untrusted until matched to a release.
        $r = self::release();
        if ($r === null || $package !== $r['package'] || !version_compare($r['version'], ESP_VERSION, '>')) {
            return new WP_Error('esp_release_unavailable', 'ESP: verified stable release unavailable.');
        }
        // Require our verified downloader even when another pre-download filter returns
        // a filename. It cannot bypass host/digest validation for this plugin.
        $guard = static function ($location) {
            if (!self::allowed_download_url($location)) {
                // WP 6.2+ namespaces Requests; WP 5.8 used its original class name.
                $class = class_exists('WpOrg\\Requests\\Exception') ? 'WpOrg\\Requests\\Exception' : 'Requests_Exception';
                throw new $class('ESP: unexpected release redirect.', 'esp_release_redirect');
            }
        };
        $limits = static function ($args, $url) use ($package) {
            if ($url === $package) {
                $args['limit_response_size'] = 20 * 1024 * 1024 + 1;
                $args['redirection'] = 3;
                $args['reject_unsafe_urls'] = true;
            }
            return $args;
        };
        add_action('requests-requests.before_redirect', $guard, 1, 1);
        add_filter('http_request_args', $limits, 10, 2);
        try {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $file = download_url($package, 30);
        } finally {
            remove_action('requests-requests.before_redirect', $guard, 1);
            remove_filter('http_request_args', $limits, 10);
        }
        if (is_wp_error($file)) { return $file; }
        if (!is_file($file) || filesize($file) !== $r['size'] ||
            !hash_equals($r['sha256'], (string) hash_file('sha256', $file))) {
            @unlink($file);
            return new WP_Error('esp_release_digest_mismatch', 'ESP: release ZIP verification failed.');
        }
        self::$download_release = $r;
        return $file;
    }

    /** Core extracts and installs; check metadata and keep the current plugin folder. */
    public static function validate_source($source, $remote_source, $upgrader, $extra = []) {
        if (($extra['plugin'] ?? null) !== self::basename() || (isset($extra['action']) && $extra['action'] !== 'update') || is_wp_error($source)) { return $source; }
        // Manual ZIP uploads have no verified-download record and are intentionally
        // separate from this standard update path.
        $r = self::$download_release;
        if ($r === null) { return new WP_Error('esp_release_not_verified', 'ESP: update package was not verified.'); }
        global $wp_filesystem, $wp_version;
        $main = $wp_filesystem->get_contents(trailingslashit($source) . 'easy-slug-protect.php');
        if (!is_string($main) || !preg_match('/^ \* Version: ([0-9.]+)$/m', $main, $header) ||
            !preg_match("/define\('ESP_VERSION', '([0-9.]+)'\);/", $main, $constant) ||
            $header[1] !== $r['version'] || $constant[1] !== $r['version']) {
            return new WP_Error('esp_release_version_mismatch', 'ESP: ZIP version mismatch.');
        }
        // Core's single-plugin upgrade path does not run the same package checks
        // as install()/bulk_upgrade(). Enforce declared requirements before copying.
        foreach (['Requires PHP' => PHP_VERSION, 'Requires at least' => $wp_version] as $field => $running) {
            if (preg_match('/^[ \t\/*#@]*' . preg_quote($field, '/') . ':(.*)$/mi', $main, $match)) {
                $required = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]));
                if ($required !== '' && (!preg_match('/^[0-9]+(?:\.[0-9]+){0,2}$/D', $required) ||
                    version_compare($running, $required, '<'))) {
                    return new WP_Error('esp_release_requirements', 'ESP: incompatible WordPress/PHP requirements.');
                }
            }
        }
        foreach (['includes/class-esp-migrator.php', 'includes/class-esp-updater.php', 'includes/class-esp-protection-state.php', 'uninstall.php'] as $required) {
            if (!$wp_filesystem->is_file(trailingslashit($source) . $required)) {
                return new WP_Error('esp_release_incomplete', 'ESP: incomplete ZIP.');
            }
        }
        $folder = dirname(self::basename());
        if ($folder === '.' || !preg_match('/^[A-Za-z0-9_.-]+$/D', $folder)) {
            return new WP_Error('esp_release_folder', 'ESP: unsupported plugin folder.');
        }
        $target = trailingslashit($remote_source) . $folder . '/';
        if (untrailingslashit($source) !== untrailingslashit($target)) {
            if ($wp_filesystem->exists($target) || !$wp_filesystem->move($source, $target)) {
                return new WP_Error('esp_release_folder', 'ESP: cannot preserve plugin folder.');
            }
        }
        return $target;
    }

    /** Returns PHP/schema usability, independently of unfinished non-DB work. */
    public static function finish_update($setup, $lock = null) {
        if (!self::can_finish_now()) { self::schedule_retry(); return false; }
        if ($lock === null) {
            $lock = ESP_Protection_State::acquire_verification_lock();
            if ($lock === null) { self::schedule_retry(); return false; }
            try { return self::finish_update($setup, $lock); }
            finally { ESP_Protection_State::release_verification_lock($lock); }
        }
        if (!ESP_Protection_State::owns_verification_lock($lock)) { return false; }
        if (!$setup->update_check($lock)) {
            // Only an explicitly classified transient DB read/option failure
            // may be retried. V1, incompatible schema, unknown failures and
            // lost locks remain diagnostic-only. No second GET_LOCK on this
            // already-owned connection.
            $reason = ESP_Migrator::last_failure_reason();
            if (in_array($reason, ['option_read_failed', 'option_write_failed',
                'schema_read_failed'], true) &&
                ESP_Protection_State::owns_verification_lock($lock)) {
                try {
                    self::save_status('retryable_migration_failure', $reason);
                    self::schedule_retry(false, $lock);
                } catch (Throwable $ignored) {
                    // When the database cannot persist state, fail closed and
                    // leave administrator recovery rather than a blind retry.
                }
            }
            return false;
        }
        try {
            $old = ESP_Migrator::read_option(ESP_Config::VERSION_OPTION_KEY);
            if (is_string($old) && version_compare($old, ESP_VERSION, '>')) { return false; }
            // Mandatory rewrite verification is independent of #48 runtime READY.
            $settings = ESP_Migrator::read_option(ESP_Config::OPTION_KEY);
            $media_enabled = !isset($settings['media']['enabled']) || (bool) $settings['media']['enabled'];
            $rewrite = ESP_Media_Protection::ensure_rewrite_policy() === true &&
                ESP_Media_Protection::audit_rewrite_policy() === true &&
                (!$media_enabled || preg_match('/apache|litespeed/i', $_SERVER['SERVER_SOFTWARE'] ?? ''));
            if (!$rewrite) {
                self::save_status('pending', 'rewrite_unverified');
                // Permission/configuration conflicts require manual repair; no polling.
                return true;
            }
            if ($old !== ESP_VERSION && !did_action('wp_loaded')) {
                self::save_status('pending', 'wordpress_rewrite_pending');
                add_action('wp_loaded', static function () use ($setup) {
                    ESP_Protection_State::verify_and_commit($setup);
                }, 200);
                return true;
            }
            $setup->schedule_cron_jobs();
            if ($old !== ESP_VERSION) {
                if (!ESP_Authorization_Cache::invalidate('post', ESP_Filter::CACHE_KEY) ||
                    !ESP_Authorization_Cache::invalidate('media', ESP_Media_Protection::MEDIA_CACHE_KEY)) {
                    self::save_status('failed', 'authorization_cache_invalidation_failed');
                    return false;
                }
                delete_transient('esp_path_index');
                delete_transient('esp_path_index_v2');
                // On a recovery/activation request the normal core may not have been
                // initialized. Register ESP rules explicitly before a single soft flush.
                (new ESP_Media_Protection())->add_rewrite_rules();
                flush_rewrite_rules(false);
                global $wp_rewrite;
                if (ESP_Migrator::read_option('rewrite_rules') !== $wp_rewrite->rules) {
                    self::save_status('failed', 'wordpress_rewrite_unverified');
                    return true;
                }
            }
            $cron = true;
            foreach ([ESP_Config::DAILY_CLEANUP_HOOK, ESP_Config::INTEGRITY_CHECK_HOOK, 'esp_media_cache_refresh', 'esp_all_cache_refresh'] as $hook) {
                $cron = $cron && (bool) wp_next_scheduled($hook);
            }
            // Cron maintenance is recoverable and never disables site protection.
            ESP_Migrator::write_option(ESP_Config::VERSION_OPTION_KEY, ESP_VERSION);
            self::save_status($cron ? 'complete' : 'degraded', $cron ? '' : 'cron_unscheduled');
            if (!$cron) { self::schedule_retry(); }
            return true;
        } catch (Throwable $error) {
            try { self::save_status('failed', 'post_update_failed'); } catch (Throwable $ignored) {}
            return false;
        }
    }

    private static function save_status($status, $reason) {
        $old = ESP_Migrator::read_option(self::STATE_OPTION, []);
        if (!is_array($old)) { $old = []; }
        ESP_Migrator::write_option(self::STATE_OPTION, [
            'version' => ESP_VERSION, 'status' => $status, 'reason' => $reason,
            // A new transient-migration incident gets its own capped budget;
            // stale Cron-maintenance attempts must not silently exhaust it.
            'attempts' => $status === 'retryable_migration_failure' &&
                ($old['status'] ?? '') !== 'retryable_migration_failure'
                ? 0 : (($old['version'] ?? '') === ESP_VERSION ? (int) ($old['attempts'] ?? 0) : 0),
        ]);
    }

    /**
     * A rejected scheduling request must not consume an automatic attempt.
     * The DB lock serializes all reservation/budget changes across workers.
     */
    public static function schedule_retry($progress = false, $held_lock = null) {
        $lock = $held_lock ?? ESP_Protection_State::acquire_verification_lock();
        if ($lock === null) { return; }
        try {
            if (!ESP_Protection_State::owns_verification_lock($lock)) { return; }
            if (wp_next_scheduled('esp_update_retry')) { return; }
            $state = ESP_Migrator::read_option(self::STATE_OPTION, []);
            if (!is_array($state)) { return; }
            if (isset($state['version']) && version_compare($state['version'], ESP_VERSION, '>')) { return; }
            if (($state['version'] ?? '') !== ESP_VERSION) { $state = ['version' => ESP_VERSION]; }
            $attempts = (int) ($state['attempts'] ?? 0);
            if (!$progress && $attempts >= 5) { return; }

            $when = time() + ($progress ? 1 : 60 * (1 << $attempts));
            // Request an explicit WP_Error on failure, rather than assuming an
            // event was persisted. Both false and WP_Error mean no budget use.
            if (wp_schedule_single_event($when, 'esp_update_retry', [], true) !== true) {
                return;
            }
            $state['attempts'] = $progress ? $attempts : $attempts + 1;
            try {
                ESP_Migrator::write_option(self::STATE_OPTION, $state);
            } catch (Throwable $error) {
                // Compensate for the separate cron/option writes if the budget
                // cannot be persisted; never run unaccounted scheduled retries.
                wp_unschedule_event($when, 'esp_update_retry');
                throw $error;
            }
        } catch (Throwable $ignored) { /* DB failure cannot be converted into READY. */ }
        finally {
            if ($held_lock === null) { ESP_Protection_State::release_verification_lock($lock); }
        }
    }

    /**
     * An unscheduled retry cannot wake itself back up. An administrator's next
     * request may reserve it again without running any heavy migration.
     * Diagnostic-only rewrite/schema failures and exhausted budgets stay manual.
     */
    public static function recover_missing_retry() {
        if (!current_user_can('manage_options') || wp_next_scheduled('esp_update_retry')) { return; }
        try {
            $state = ESP_Migrator::read_option(self::STATE_OPTION, []);
            if (!is_array($state) || ($state['version'] ?? '') !== ESP_VERSION) { return; }
            $cron_degraded = ($state['status'] ?? '') === 'degraded' &&
                ($state['reason'] ?? '') === 'cron_unscheduled';
            $retryable_failure = ($state['status'] ?? '') === 'retryable_migration_failure' &&
                in_array($state['reason'] ?? '', ['option_read_failed',
                    'option_write_failed', 'schema_read_failed'], true);
            if ($cron_degraded || $retryable_failure) {
                self::schedule_retry();
            }
        } catch (Throwable $ignored) { /* Leave diagnostic state for manual repair. */ }
    }

    private static function reset_retry_budget() {
        $lock = ESP_Protection_State::acquire_verification_lock();
        if ($lock === null) { return; }
        try {
            $s = ESP_Migrator::read_option(self::STATE_OPTION, []);
            if (!is_array($s) || (isset($s['version']) && version_compare($s['version'], ESP_VERSION, '>'))) { return; }
            $s['version'] = ESP_VERSION; $s['attempts'] = 0;
            ESP_Migrator::write_option(self::STATE_OPTION, $s);
            wp_clear_scheduled_hook('esp_update_retry');
        } finally { ESP_Protection_State::release_verification_lock($lock); }
    }

    public static function notice() {
        if (!current_user_can('manage_options')) { return; }
        if (is_multisite()) {
            echo '<div class="notice notice-warning"><p>' .
                esc_html__('Easy Slug Protect: この更新基盤の保証対象は通常のシングルサイトです。Multisiteの自動移行・更新検出は実行しません。', ESP_Config::TEXT_DOMAIN) .
                '</p></div>';
            return;
        }
        $m = get_option(ESP_Migrator::STATE_OPTION, []);
        $u = get_option(self::STATE_OPTION, []);
        if (($m['status'] ?? '') === 'complete' && ($u['status'] ?? '') === 'complete' &&
            ($m['version'] ?? '') === ESP_VERSION && ($u['version'] ?? '') === ESP_VERSION &&
            get_option(ESP_Config::VERSION_OPTION_KEY) === ESP_VERSION) { return; }
        $v1 = is_array($m) && ($m['reason'] ?? '') === 'v1_migration_required';
        echo '<div class="notice notice-warning"><p>' .
            ($v1
                ? esc_html__('Easy Slug Protect: v1形式のデータベースが検出されました。v1固有のpath_id変換は通常更新では実行しません。', ESP_Config::TEXT_DOMAIN)
                : esc_html__('Easy Slug Protect: 更新後の検証が完了していません。DB・Rewrite・Cronを確認してください。', ESP_Config::TEXT_DOMAIN))
            . '</p>';
        if ($v1) {
            echo '<p>' . esc_html__('バックアップを取得し、管理者がv1専用の移行対応を行ってください。自動的にID・パスワード・認証トークンを書き換えることはありません。', ESP_Config::TEXT_DOMAIN) . '</p>';
        }
        echo '<p>' . esc_html(sprintf('Last completed DB: %s / plugin: %s',
            (string) get_option('esp_db_version', 'unknown'),
            (string) get_option(ESP_Config::VERSION_OPTION_KEY, 'unknown'))) . '</p>';
        foreach (['migration' => $m, 'update' => $u] as $kind => $state) {
            if (is_array($state)) {
                echo '<p>' . esc_html($kind . ': ' . ($state['status'] ?? 'pending') . ' / ' . ($state['step'] ?? '') . ' / ' . ($state['reason'] ?? '')) . '</p>';
            }
        }
        if (!$v1) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('esp_retry_update');
            echo '<input type="hidden" name="action" value="esp_retry_update">';
            echo '<p><button class="button">' . esc_html__('更新後処理を再検証・再試行', ESP_Config::TEXT_DOMAIN) . '</button></p></form>';
        }
        echo '</div>';
    }
}
