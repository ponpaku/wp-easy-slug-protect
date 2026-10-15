<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * リクエスト開始時に保護状態を検証する認可ゲート。
 * 更新失敗や保護設定の読み取り不能を、公開レスポンスに置き換えない。
 *
 * ここでは起動時の拒否制御を担当し、永続的な移行状態の管理は
 * #44の後続実装（ESP_Migrator）で扱う。
 */
final class ESP_Protection_State {
    /**
     * 通常リクエストの軽量ゲート。更新・DB版・Rewriteの検査は行わない。
     * READYはあくまで既知のセットアップ状態であり、個々の認可を代替しない。
     */
    const RUNTIME_STATE_OPTION = 'esp_runtime_state';
    const AUDIT_OPTION = 'esp_integrity_last_audit';

    /** 同じPHPリクエストでの再入検証も防ぐ。 */
    private static $verification_in_progress = false;

    public static function boot($setup) {
        $state = get_option(self::RUNTIME_STATE_OPTION, null);
        // READYの通常アクセスでは一切の更新・DB版・Rewrite検査を実行しない。
        // 新しいコードが初めて起動した時だけ、管理者アクセスを待たずに検証する。
        if (in_array($state, [null, 'PENDING', 'VERIFY_REQUIRED'], true)) {
            self::verify_once($setup);
            $state = get_option(self::RUNTIME_STATE_OPTION, null);
        }
        $settings = get_option(ESP_Config::OPTION_KEY, null);
        if ($state === 'READY' && self::valid_settings($settings)) {
            return true;
        }
        self::register_failure_hooks($settings);
        return false;
    }

    /** WordPress更新開始・完了、単発Cron、管理者復旧を登録する。 */
    public static function register_runtime_hooks($setup) {
        add_filter('upgrader_pre_install', [self::class, 'before_plugin_upgrade'], 10, 2);
        add_filter('upgrader_post_install', [self::class, 'after_plugin_upgrade'], 10, 3);
        add_filter('upgrader_install_package_result', [self::class, 'after_install_package_result'], 10, 2);
        add_action('upgrader_process_complete', [self::class, 'upgrade_process_complete'], 10, 2);
        add_action('esp_verify_runtime_once', static function () use ($setup) {
            self::verify_once($setup);
        });
        add_action('admin_notices', [self::class, 'render_audit_notice']);
        add_action('admin_post_esp_verify_runtime', static function () use ($setup) {
            if (!current_user_can('manage_options')) {
                wp_die(__('操作権限がありません。', ESP_Config::TEXT_DOMAIN), '', ['response' => 403]);
            }
            check_admin_referer('esp_verify_runtime');
            self::verify_and_commit($setup);
            wp_safe_redirect(admin_url('admin.php?page=esp-settings'));
            exit;
        });
    }

    /**
     * 初回導入・検証待ちに限り一度だけ厳密検証する。
     * 排他が取れない別リクエストは一時的に拒否し、未検証で通過させない。
     * FAILED/UPDATINGを自動解除しない。復旧用の管理画面は利用できる。
     */
    private static function verify_once($setup) {
        $state = get_option(self::RUNTIME_STATE_OPTION, null);
        if (!in_array($state, [null, 'PENDING', 'VERIFY_REQUIRED'], true)) {
            return $state === 'READY';
        }
        // WP-CLIのサーバー種別不明はRewriteの検証失敗を意味しない。
        // 検証待ちを維持し、Webサーバー情報のある最初のHTTPアクセスへ委ねる。
        // CLIの一時CronがFAILEDを永続化して自動復帰を妨げないようにする。
        if (defined('WP_CLI') && WP_CLI &&
            trim((string) ($_SERVER['SERVER_SOFTWARE'] ?? '')) === '') {
            return false;
        }
        return self::verify_and_commit($setup, true);
    }

    /**
     * 対象プラグインの識別。単体/一括の install_package() には
     * plugin のみが渡ることがあるため、type/action は存在する場合だけ検証する。
     */
    private static function is_esp_install($extra) {
        if (!is_array($extra) ||
            (isset($extra['type']) && $extra['type'] !== 'plugin') ||
            (isset($extra['action']) && $extra['action'] !== 'update')) {
            return false;
        }
        return isset($extra['plugin']) &&
            $extra['plugin'] === plugin_basename(ESP_PATH . 'easy-slug-protect.php');
    }

    /** upgrader_process_complete はtype/actionを持つ別形式のイベント。 */
    private static function is_esp_completed_update($extra) {
        if (!is_array($extra) || ($extra['type'] ?? '') !== 'plugin' ||
            ($extra['action'] ?? '') !== 'update') {
            return false;
        }
        $plugin = plugin_basename(ESP_PATH . 'easy-slug-protect.php');
        return ($extra['plugin'] ?? null) === $plugin ||
            (isset($extra['plugins']) && is_array($extra['plugins']) &&
                in_array($plugin, $extra['plugins'], true));
    }

    /** インストール開始前に、更新中フラグの永続化に成功した場合のみ続行。 */
    public static function before_plugin_upgrade($response, $hook_extra) {
        if (is_wp_error($response) || !self::is_esp_install($hook_extra)) {
            return $response;
        }
        if (!self::set_runtime_state('UPDATING')) {
            return new WP_Error('esp_upgrade_pending_unavailable',
                __('保護状態を更新中として記録できないため、ESPの更新を中止しました。', ESP_Config::TEXT_DOMAIN));
        }
        return $response;
    }

    /**
     * 置換完了の判定は旧PHPコードで行われ得る。
     * 新コードによる一度限りの検証を予約し、ここではREADYにしない。
     */
    public static function after_plugin_upgrade($response, $hook_extra, $result) {
        if (is_wp_error($response) || $response === false ||
            !self::is_esp_install($hook_extra)) {
            return $response;
        }
        // 他のpost_installフィルターによる失敗やWPのロールバックも
        // 考慮し、最終状態への遷移はリクエスト終了時に行う。
        self::schedule_verification_after_shutdown();
        return $response;
    }

    /**
     * install_package()が失敗した場合、WPのバックアップ復元はshutdownで
     * 行われ得る。復元完了後に検証待ちへ遷移させる。
     */
    public static function after_install_package_result($result, $hook_extra) {
        if (self::is_esp_install($hook_extra) &&
            (is_wp_error($result) || $result === false) &&
            get_option(self::RUNTIME_STATE_OPTION, null) === 'UPDATING') {
            self::schedule_verification_after_shutdown();
        }
        return $result;
    }

    public static function upgrade_process_complete($upgrader, $hook_extra) {
        if (!self::is_esp_completed_update($hook_extra) ||
            get_option(self::RUNTIME_STATE_OPTION, null) !== 'UPDATING') {
            return;
        }
        // 一括更新の一部失敗も扱う。成功時はpost_installですでに遷移済み。
        self::schedule_verification_after_shutdown();
    }

    private static function schedule_verification_after_shutdown() {
        // WP_Upgraderのshutdown時ロールバック（優先度10）より後で処理する。
        add_action('shutdown', [self::class, 'mark_verification_required'], 200);
    }

    public static function mark_verification_required() {
        if (get_option(self::RUNTIME_STATE_OPTION, null) === 'UPDATING' &&
            self::set_runtime_state('VERIFY_REQUIRED') &&
            !wp_next_scheduled('esp_verify_runtime_once')) {
            wp_schedule_single_event(time(), 'esp_verify_runtime_once');
        }
    }

    /** 記録した状態だけを参照し、コード/DB版の検査は行わない。 */
    private static function set_runtime_state($state) {
        if (!in_array($state, ['READY', 'PENDING', 'FAILED', 'UNKNOWN', 'UPDATING', 'VERIFY_REQUIRED'], true)) {
            return false;
        }
        update_option(self::RUNTIME_STATE_OPTION, $state, true);
        return get_option(self::RUNTIME_STATE_OPTION, null) === $state;
    }

    /** 管理者による検証、プラグイン有効化、更新後の復旧でのみ実行する。 */
    /**
     * MySQL/MariaDB の接続単位ロックを WordPress の $wpdb 経由で取得する。
     * タイムアウトで「所有中のロックを削除」する実装はしない。
     * DB接続終了時にはサーバーが自動解放する。
     */
    private static function acquire_verification_lock() {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->prefix)) {
            return null;
        }
        $name = 'esp_verify_' . md5((defined('DB_NAME') ? DB_NAME : '') . ':' . $wpdb->prefix);
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
        if ((string) $result !== '1') {
            return null;
        }
        $connection = $wpdb->get_var('SELECT CONNECTION_ID()');
        if (!is_numeric($connection)) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            return null;
        }
        return ['name' => $name, 'connection' => (string) $connection];
    }

    /**
     * wpdb が途中で自動再接続した場合はロックを失ったものと扱う。
     * 他の検証が獲得した新しいロックを解放したりREADY確定したりしない。
     */
    private static function owns_verification_lock($lock) {
        global $wpdb;
        $owner = $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $lock['name']));
        $connection = $wpdb->get_var('SELECT CONNECTION_ID()');
        return is_numeric($owner) && is_numeric($connection) &&
            (string) $owner === $lock['connection'] &&
            (string) $connection === $lock['connection'];
    }

    private static function release_verification_lock($lock) {
        global $wpdb;
        if (self::owns_verification_lock($lock)) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock['name']));
        }
    }

    /** 管理者による検証、プラグイン有効化、更新後の復旧でのみ実行する。 */
    public static function verify_and_commit($setup, $only_if_pending = false) {
        if (self::$verification_in_progress) {
            return false;
        }
        $lock = self::acquire_verification_lock();
        if ($lock === null) {
            return false; // 他リクエストが検証中 / DBロック利用不可
        }
        self::$verification_in_progress = true;
        try {
            // GET_LOCK取得待ちの間に別プロセスがREADYへ遷移したなら、
            // 初回アクセスの自動検証をもう一度実行しない。
            if ($only_if_pending) {
                $state = get_option(self::RUNTIME_STATE_OPTION, null);
                if (!in_array($state, [null, 'PENDING', 'VERIFY_REQUIRED'], true)) {
                    return $state === 'READY';
                }
            }
            return self::verify_and_commit_unlocked($setup, $lock);
        } finally {
            self::release_verification_lock($lock);
            self::$verification_in_progress = false;
        }
    }

    private static function verify_and_commit_unlocked($setup, $lock) {
        if (!self::owns_verification_lock($lock) ||
            !self::set_runtime_state('PENDING')) {
            return false;
        }
        $ready = false;
        try {
            $ready = $setup->check_plugin_version() === true;
            // バージョン移行の途中でDBが再接続されていれば以後は触らない。
            if (!self::owns_verification_lock($lock)) {
                return false;
            }
            $settings = get_option(ESP_Config::OPTION_KEY, null);
            $ready = $ready && self::valid_settings($settings);
            if ($ready) {
                $stored = get_option('esp_db_version', null);
                $required = (int) ESP_Config::OPTION_DEFAULTS['db_version'];
                $known = is_int($stored) || (is_string($stored) && ctype_digit($stored));
                $ready = (!$known || (int) $stored <= $required) &&
                    self::verify_legacy_security_schema();
            }
            if (!self::owns_verification_lock($lock)) {
                return false;
            }
            if ($ready && class_exists('ESP_Media_Protection')) {
                $ready = ESP_Media_Protection::ensure_rewrite_policy() === true;
            }
        } catch (\Throwable $error) {
            error_log('ESP: explicit runtime verification failed');
            $ready = false;
        }
        // ロックを失った場合はREADYにもFAILEDにも変更しない。
        if (!self::owns_verification_lock($lock)) {
            return false;
        }
        if (!$ready) {
            error_log('ESP: runtime verification failed; ESP protection requires administrator review');
            self::set_runtime_state('FAILED');
            return false;
        }
        return self::set_runtime_state('READY');
    }

    /**
     * 日次WP-Cronからの読取専用監査。Rewriteは修復せず、異常コードだけ保存。
     * 新規保護の直前検査は別途必須とし、Cron遅延へ安全性を依存させない。
     */
    public static function audit() {
        $errors = [];
        try {
            $settings = get_option(ESP_Config::OPTION_KEY, null);
            if (!self::valid_settings($settings)) {
                $errors[] = 'settings_invalid';
            }
            $stored = get_option('esp_db_version', null);
            $required = (int) ESP_Config::OPTION_DEFAULTS['db_version'];
            if ((is_int($stored) || (is_string($stored) && ctype_digit($stored))) &&
                (int) $stored > $required) {
                $errors[] = 'future_schema';
            }
            if (!self::verify_legacy_security_schema()) {
                $errors[] = 'schema_invalid';
            }
            if (class_exists('ESP_Media_Protection') &&
                !ESP_Media_Protection::audit_rewrite_policy()) {
                $errors[] = 'rewrite_mismatch';
            }
            $installed_version = get_option(ESP_Config::VERSION_OPTION_KEY, null);
            if ($installed_version !== ESP_VERSION) {
                $errors[] = 'version_unverified';
            }
            if (get_option(self::RUNTIME_STATE_OPTION, null) !== 'READY') {
                $errors[] = 'runtime_not_ready';
            }
        } catch (\Throwable $error) {
            $errors[] = 'audit_exception';
        }
        update_option(self::AUDIT_OPTION, [
            'checked_at' => time(), 'errors' => array_values(array_unique($errors)),
        ], false);
        // 管理者による明示的な復旧まで、外部ファイルを勝手に変更しない。
        return $errors === [];
    }

    /** WP-Cronの点検異常は管理者へ表示し、毎アクセスで再検査しない。 */
    public static function render_audit_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $audit = get_option(self::AUDIT_OPTION, null);
        if (!is_array($audit) || empty($audit['errors'])) {
            return;
        }
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('Easy Slug Protect: 定期点検で不整合を検出しました。保護設定とRewriteを確認し、必要ならESPの復旧画面から再検証してください。', ESP_Config::TEXT_DOMAIN)
            . '</p></div>';
    }

    /** 設定欠損・形状異常だけは軽い認可前チェックで拒否する。 */
    private static function valid_settings($settings) {
        if (!is_array($settings) || !isset($settings['path']) ||
            !is_array($settings['path'])) {
            return false;
        }
        foreach ($settings['path'] as $path) {
            if (!is_array($path) || !isset($path['path']) ||
                !is_string($path['path']) || !$path['path'] ||
                trim($path['path'], " /\t\n\r\0\x0B") === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * 旧版でDB版印だけが無い／古いサイトのread-only互換検証。
     * 一度もDDLを実行せず、必要テーブルの列と原子的な試行制限索引を確認する。
     * #44のMigratorが版印と全テーブルの検証を正式管理するまでの橋渡し。
     */
    public static function verify_legacy_security_schema() {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->prefix)) {
            return false;
        }
        $required = [
            // 既存認証処理が実際に読む・書く列だけを最低限確認する。
            // 完全なスキーマ履歴・移行ステップ管理は#44のMigratorで扱う。
            'brute' => ['id', 'ip_address', 'path', 'path_id', 'time'],
            'remember' => ['id', 'path', 'path_id', 'password_version', 'user_id',
                'token', 'created', 'expires'],
            'session' => ['id', 'path_id', 'password_version', 'token', 'created', 'expires'],
            'limit' => ['ip_address', 'path_id', 'window_started', 'attempts',
                'blocked_until', 'updated_at', 'attempt_times', 'last_attempt_token'],
        ];
        try {
            foreach ($required as $kind => $columns) {
                $table = str_replace('`', '``', $wpdb->prefix . ESP_Config::DB_TABLES[$kind]);
                $available = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`");
                if (!is_array($available) || array_diff($columns, $available) ||
                    $wpdb->last_error !== '') {
                    return false;
                }
            }
            $limit_table = $wpdb->prefix . ESP_Config::DB_TABLES['limit'];
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $limit_table
            ));
            if (strcasecmp((string) $engine, 'InnoDB') !== 0 || $wpdb->last_error !== '') {
                return false;
            }
            $quoted = str_replace('`', '``', $limit_table);
            $rows = $wpdb->get_results("SHOW INDEX FROM `{$quoted}` WHERE Key_name = 'PRIMARY'", ARRAY_A);
            if (!is_array($rows) || $wpdb->last_error !== '') {
                return false;
            }
            $primary = [];
            foreach ($rows as $row) {
                if (!isset($row['Seq_in_index'], $row['Column_name'])) {
                    return false;
                }
                $primary[(int) $row['Seq_in_index']] = $row['Column_name'];
            }
            ksort($primary);
            return array_values($primary) === ['ip_address', 'path_id'];
        } catch (\Throwable $error) {
            return false;
        }
    }

    /**
     * 通常の設定フォームを読み込めない場合の診断用ページ。
     * 設定を上書きする操作は提供しない。
     */
    public static function register_recovery_admin() {
        add_action('admin_menu', static function () {
            add_menu_page(
                __('Easy Slug Protect 復旧', ESP_Config::TEXT_DOMAIN),
                'Easy Slug Protect',
                'manage_options',
                'esp-settings',
                [self::class, 'render_recovery_admin'],
                'dashicons-warning',
                80
            );
        });
    }

    public static function render_recovery_admin() {
        if (!current_user_can('manage_options')) {
            wp_die(__('操作権限がありません。', ESP_Config::TEXT_DOMAIN), '', ['response' => 403]);
        }
        echo '<div class="wrap"><h1>' .
            esc_html__('Easy Slug Protect — 保護状態を確認できません', ESP_Config::TEXT_DOMAIN) .
            '</h1><p>' .
            esc_html__('保護設定または更新状態が未確認です。通常アクセス時には詳細検証や自動修復を実行しません。', ESP_Config::TEXT_DOMAIN) .
            '</p><p>' .
            esc_html__('DBとuploads/.htaccessを確認してから、必要なら管理者として再検証してください。静的ファイルの直接公開はPHP側では停止できません。', ESP_Config::TEXT_DOMAIN) .
            '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('esp_verify_runtime');
        echo '<input type="hidden" name="action" value="esp_verify_runtime" />'
            . '<p><button type="submit" class="button button-primary">'
            . esc_html__('保護状態を再検証する', ESP_Config::TEXT_DOMAIN)
            . '</button></p></form></div>';
    }

    /** 起動失敗中は通常の認可フックを登録しないため、公開経路を限定して守る。 */
    private static function register_failure_hooks($settings) {
        $message = __('Easy Slug Protectの保護設定または更新状態を検証できません。管理者による復旧が必要です。', ESP_Config::TEXT_DOMAIN);

        // 設定そのものを信頼できない場合に限り、保護範囲を推測せず広く拒否。
        if (!self::valid_settings($settings)) {
            self::log_configuration_failure();
            add_filter('rest_pre_dispatch', static function ($result) use ($message) {
                return new WP_Error('esp_protection_unavailable', $message, ['status' => 503]);
            }, -1000, 1);
            add_action('template_redirect', static function () use ($message) {
                self::deny_public_content($message);
            }, -1000);
            add_action('admin_init', static function () use ($message) {
                if (function_exists('wp_doing_ajax') && wp_doing_ajax() &&
                    !current_user_can('manage_options')) {
                    self::deny_public_content($message);
                }
            }, -1000);
        } else {
            $paths = $settings['path'];
            // enabled が確実にOFFである場合以外はメディア配信を保護対象とする。
            $media_enabled = !isset($settings['media']) ||
                !is_array($settings['media']) ||
                !array_key_exists('enabled', $settings['media']) ||
                (bool) $settings['media']['enabled'];

            if ($paths || $media_enabled) {
                // ESP_Coreを初期化しない場合にも、既存Rewriteが送るquery varを認識する。
                add_filter('query_vars', static function ($vars) {
                    if (!in_array(ESP_Media_Protection::REWRITE_ENDPOINT, $vars, true)) {
                        $vars[] = ESP_Media_Protection::REWRITE_ENDPOINT;
                    }
                    return $vars;
                });

                add_action('template_redirect', static function () use ($paths, $media_enabled, $message) {
                    if (($media_enabled && self::is_media_php_request()) ||
                        ($paths && self::must_block_unverified_page($paths))) {
                        self::deny_public_content($message);
                    }
                }, -1000);

                // RESTの投稿一覧等はテンプレートフックを通らず保護情報を含み得る。
                add_filter('rest_pre_dispatch', static function ($result, $server = null, $request = null) use ($paths, $media_enabled, $message) {
                    if ($result !== null || current_user_can('manage_options')) {
                        return $result;
                    }
                    // 保護パスあり・認可フック未登録中は匿名RESTで内容を公開しない。
                    if ($paths) {
                        return new WP_Error('esp_protection_unavailable', $message, ['status' => 503]);
                    }
                    // パス保護なしならメディアAPIだけ限定拒否。無関係なRESTは維持。
                    if ($media_enabled && is_object($request) && method_exists($request, 'get_route') &&
                        preg_match('#^/wp/v2/media(?:/|$)#', (string) $request->get_route())) {
                        return new WP_Error('esp_media_unavailable', $message, ['status' => 503]);
                    }
                    return $result;
                }, -1000, 3);

                // 保護パスがあるときだけ、不明な公開AJAX応答の安全性を推測しない。
                if ($paths) {
                    add_action('admin_init', static function () use ($message) {
                        if (function_exists('wp_doing_ajax') && wp_doing_ajax() &&
                            !current_user_can('manage_options')) {
                            self::deny_public_content($message);
                        }
                    }, -1000);
                }
            }
        }

        add_action('admin_notices', static function () use ($message) {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            }
        });
    }

    /** 同じ設定破損で毎アクセス大量ログを吐くことを避ける（記録が不能なら通知優先）。 */
    private static function log_configuration_failure() {
        $key = 'esp_protection_settings_failure_logged';
        if (function_exists('get_transient') && get_transient($key)) {
            return;
        }
        error_log('ESP: protection settings unavailable or invalid; protected content cannot be classified');
        if (function_exists('set_transient')) {
            set_transient($key, 1, 300);
        }
    }

    private static function deny_public_content($message) {
        nocache_headers();
        status_header(503);
        wp_die($message, '', ['response' => 503]);
        exit;
    }

    /** 現存RewriteのPHP入口・直接クエリ入口を、非READY中に配信させない。 */
    private static function is_media_php_request() {
        if (array_key_exists(ESP_Media_Protection::REWRITE_ENDPOINT, $_GET)) {
            return true;
        }
        if (function_exists('get_query_var') &&
            get_query_var(ESP_Media_Protection::REWRITE_ENDPOINT, null) !== null) {
            return true;
        }
        global $wp;
        if (is_object($wp) && isset($wp->query_vars) && is_array($wp->query_vars) &&
            array_key_exists(ESP_Media_Protection::REWRITE_ENDPOINT, $wp->query_vars)) {
            return true;
        }
        $uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return is_string($uri) && strpos($uri, '/wp-content/uploads/') !== false;
    }

    /**
     * 保護ページの通常フックがない時は、既知の保護URLと集約表示を拒否。
     * 集約表示（トップの投稿一覧、検索、フィード、404等）は保護情報を含み得る。
     * 単体の公開投稿もリクエストパスと正規パーマリンクの両方を確認する。
     */
    private static function must_block_unverified_page($paths) {
        global $wp;
        if (!is_object($wp) || !isset($wp->request) ||
            self::matches_unverified_path($paths, $wp->request)) {
            return true;
        }
        if (!function_exists('is_singular') || !is_singular() ||
            (function_exists('is_attachment') && is_attachment())) {
            return true;
        }
        if (!function_exists('get_queried_object_id') || !function_exists('get_permalink') ||
            !function_exists('home_url')) {
            return true;
        }
        $id = (int) get_queried_object_id();
        if ($id <= 0) {
            return true;
        }
        $url = get_permalink($id);
        $home = parse_url(home_url('/'), PHP_URL_PATH);
        $url_path = is_string($url) ? parse_url($url, PHP_URL_PATH) : false;
        if (!is_string($home) || !is_string($url_path)) {
            return true;
        }
        $base = trim($home, '/');
        $relative = trim($url_path, '/');
        if ($base !== '') {
            if ($relative === $base) {
                $relative = '';
            } elseif (strpos($relative, $base . '/') === 0) {
                $relative = substr($relative, strlen($base) + 1);
            } else {
                return true;
            }
        }
        return self::matches_unverified_path($paths, $relative);
    }

    private static function matches_unverified_path($paths, $path) {
        if (!is_string($path)) {
            return true;
        }
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($path);
            if ($decoded === $path) {
                break;
            }
            $path = $decoded;
        }
        // 正規化不能・不審なパスは非保護と断定しない。
        if (strpos($path, '%') !== false || strpos($path, '\\') !== false ||
            preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return true;
        }
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '.' || $part === '..') {
                return true;
            }
        }
        $path = strtolower(trim($path, '/'));
        foreach ($paths as $item) {
            $protected = strtolower(trim($item['path'], '/'));
            if ($path === $protected || strpos($path, $protected . '/') === 0) {
                return true;
            }
        }
        return false;
    }
}
