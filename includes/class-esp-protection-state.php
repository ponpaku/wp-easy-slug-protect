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
    public static function boot($setup) {
        try {
            $migration_ok = $setup->check_plugin_version();

            // ESP_Optionの既定値を使わず、設定欠損・破損と意図的な保護無効を区別する。
            $settings = get_option(ESP_Config::OPTION_KEY, null);
            $ready = $migration_ok === true &&
                is_array($settings) &&
                isset($settings['path']) && is_array($settings['path']);
            if ($ready) {
                foreach ($settings['path'] as $path) {
                    if (!is_array($path) || !isset($path['path']) || !is_string($path['path']) ||
                        empty($path['path']) || trim($path['path'], " /\t\n\r\0\x0B") === '') {
                        $ready = false;
                        break;
                    }
                }
            }
            if ($ready) {
                // プラグインの保存バージョンが一致していてもDB移行済みとは限らない。
                // 未設定・旧値・将来値は不明状態として拒否する。
                // ここで旧migration(v1等)を実行しない。詳細検証と安全な修復は#44へ。
                $stored_db_version = get_option('esp_db_version', null);
                $required_db_version = (int) ESP_Config::OPTION_DEFAULTS['db_version'];
                $known_db_version = (is_int($stored_db_version) ||
                    (is_string($stored_db_version) && ctype_digit($stored_db_version)));
                if ($known_db_version && (int) $stored_db_version > $required_db_version) {
                    // 将来版を旧PHPが勝手に正常扱いするダウングレードを禁止。
                    $ready = false;
                } elseif (!$known_db_version || (int) $stored_db_version !== $required_db_version) {
                    // 旧activate()は最新版の実テーブルを作ってもDB版を記録しない。
                    // 認可に必要なスキーマが実在すれば、欠損した版印だけで
                    // 全公開ページを503にしない（版印の勝手な書換えはしない）。
                    $ready = self::verify_legacy_security_schema();
                }
            }
            if ($ready) {
                // DB障害中でもWordPressのオプションキャッシュは値を返し得る。
                // DBの実読み取りで、正常な設定と接続障害を区別する。
                global $wpdb;
                $ready = is_object($wpdb) &&
                    (string) $wpdb->get_var('SELECT 1') === '1' &&
                    $wpdb->last_error === '';
            }
            if ($ready && class_exists('ESP_Media_Protection')) {
                $ready = ESP_Media_Protection::ensure_rewrite_policy() === true;
            }
            if ($ready) {
                return true;
            }
        } catch (\Throwable $error) {
            error_log('ESP: protection boot readiness check failed');
        }

        self::register_failure_hooks();
        return false;
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
            esc_html__('保護設定、DB移行状態、またはサーバーのRewriteを検証できません。保護情報を推測して上書きする操作は停止しています。', ESP_Config::TEXT_DOMAIN) .
            '</p><p>' .
            esc_html__('設定・データベースのバックアップを確認し、DB接続・設定値・uploads/.htaccessとサーバー設定を修復してください。PHPの拒否は静的ファイルの直接公開を防げません。', ESP_Config::TEXT_DOMAIN) .
            '</p></div>';
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
