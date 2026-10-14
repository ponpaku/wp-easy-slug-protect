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

    public static function boot($setup) {
        $state = get_option(self::RUNTIME_STATE_OPTION, null);
        $settings = get_option(ESP_Config::OPTION_KEY, null);
        if ($state === 'READY' && self::valid_settings($settings)) {
            return true;
        }
        self::register_failure_hooks();
        return false;
    }

    /** WordPress更新と復旧のフックはREADYでない場合も登録する。 */
    public static function register_runtime_hooks($setup) {
        add_filter('upgrader_pre_install', [self::class, 'before_plugin_upgrade'], 10, 2);
        // 未導入の状態印や標準更新によるPENDINGを、最初の管理者アクセスで検証する。
        // FAILEDは毎回再試行せず、明示的な復旧操作を要求する。
        add_action('admin_init', static function () use ($setup) {
            if (!current_user_can('manage_options')) {
                return;
            }
            $state = get_option(self::RUNTIME_STATE_OPTION, null);
            if ($state === null || $state === 'PENDING' || $state === 'UNKNOWN') {
                self::verify_and_commit($setup);
            }
        }, 1);
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

    /** 記録した状態だけを参照し、コード/DB版の検査は行わない。 */
    private static function set_runtime_state($state) {
        if (!in_array($state, ['READY', 'PENDING', 'FAILED', 'UNKNOWN'], true)) {
            return false;
        }
        update_option(self::RUNTIME_STATE_OPTION, $state, true);
        return get_option(self::RUNTIME_STATE_OPTION, null) === $state;
    }

    /**
     * 対応するWordPress標準プラグイン更新の直前だけPENDINGを確定する。
     * updater_post_installは古いコードで動作し得るためREADYにしない。
     */
    public static function before_plugin_upgrade($response, $hook_extra) {
        if (is_wp_error($response) || !is_array($hook_extra) ||
            ($hook_extra['type'] ?? '') !== 'plugin' ||
            ($hook_extra['action'] ?? '') !== 'update') {
            return $response;
        }
        $self = plugin_basename(ESP_PATH . 'easy-slug-protect.php');
        $targets = [];
        if (isset($hook_extra['plugin'])) {
            $targets[] = $hook_extra['plugin'];
        }
        if (isset($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
            $targets = array_merge($targets, $hook_extra['plugins']);
        }
        if (!in_array($self, $targets, true)) {
            return $response;
        }
        if (!self::set_runtime_state('PENDING')) {
            return new WP_Error('esp_upgrade_pending_unavailable',
                __('保護状態を更新中として記録できないため、ESPの更新を中止しました。', ESP_Config::TEXT_DOMAIN));
        }
        return $response;
    }

    /** 管理者による検証、プラグイン有効化、更新後の復旧でのみ実行する。 */
    public static function verify_and_commit($setup) {
        if (!self::set_runtime_state('PENDING')) {
            return false;
        }
        $ready = false;
        try {
            $ready = $setup->check_plugin_version() === true;
            $settings = get_option(ESP_Config::OPTION_KEY, null);
            $ready = $ready && self::valid_settings($settings);
            if ($ready) {
                $stored = get_option('esp_db_version', null);
                $required = (int) ESP_Config::OPTION_DEFAULTS['db_version'];
                $known = is_int($stored) || (is_string($stored) && ctype_digit($stored));
                $ready = (!$known || (int) $stored <= $required) &&
                    self::verify_legacy_security_schema();
            }
            if ($ready && class_exists('ESP_Media_Protection')) {
                $ready = ESP_Media_Protection::ensure_rewrite_policy() === true;
            }
        } catch (\Throwable $error) {
            error_log('ESP: explicit runtime verification failed');
            $ready = false;
        }
        if (!$ready) {
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
    private static function verify_legacy_security_schema() {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->prefix)) {
            return false;
        }
        $required = [
            'brute' => ['ip_address', 'path', 'path_id', 'time'],
            'remember' => ['path', 'path_id', 'password_version', 'user_id', 'token', 'expires'],
            'session' => ['path_id', 'password_version', 'token', 'expires'],
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

    private static function register_failure_hooks() {
        $message = __('Easy Slug Protectの保護設定または更新状態を検証できません。管理者による復旧が必要です。', ESP_Config::TEXT_DOMAIN);

        // RESTはtemplate_redirectより先に処理されるため、
        // 未認証の一覧・単体・メディアAPIもここで制御する。
        add_filter('rest_pre_dispatch', static function ($result) use ($message) {
            return new WP_Error('esp_protection_unavailable', $message, ['status' => 503]);
        }, -1000, 1);

        // 保護対象の帰属が未確定なら、フィード・サイトマップ・PHP直接配信を止める。
        add_action('template_redirect', static function () use ($message) {
            nocache_headers();
            status_header(503);
            wp_die($message, '', ['response' => 503]);
            exit;
        }, -1000);

        // 公開admin-ajaxは遮断するが、権限のある管理者の修復操作は残す。
        add_action('admin_init', static function () use ($message) {
            if (function_exists('wp_doing_ajax') && wp_doing_ajax() &&
                !current_user_can('manage_options')) {
                nocache_headers();
                wp_die($message, '', ['response' => 503]);
                exit;
            }
        }, -1000);

        add_action('admin_notices', static function () use ($message) {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            }
        });
    }
}
