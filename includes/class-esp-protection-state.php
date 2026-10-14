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
                        trim($path['path'], '/') === '') {
                        $ready = false;
                        break;
                    }
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
