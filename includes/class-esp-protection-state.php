<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Request-scoped authorization gate. An unsuccessful upgrade or unreadable
 * protection configuration must not fall back to public WordPress responses.
 *
 * This is the P0 boot gate; persistent migration checkpoints belong to
 * ESP_Migrator in the subsequent #44 implementation.
 */
final class ESP_Protection_State {
    public static function boot($setup) {
        try {
            $migration_ok = $setup->check_plugin_version();

            // Do not use ESP_Option defaults here: absent/corrupt settings
            // cannot be mistaken for an intentionally unprotected site.
            $settings = get_option(ESP_Config::OPTION_KEY, null);
            $ready = $migration_ok === true &&
                is_array($settings) &&
                isset($settings['path']) && is_array($settings['path']);
            if ($ready) {
                foreach ($settings['path'] as $path) {
                    if (!is_array($path) || !isset($path['path']) || !is_string($path['path'])) {
                        $ready = false;
                        break;
                    }
                }
            }
            if ($ready) {
                // WordPress may return option-cache values even after a DB
                // outage. A live read distinguishes availability from defaults.
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

    private static function register_failure_hooks() {
        $message = __('Easy Slug Protectの保護設定または更新状態を検証できません。管理者による復旧が必要です。', ESP_Config::TEXT_DOMAIN);

        // REST is served before template_redirect, including unauthenticated
        // collection/single/media endpoints.
        add_filter('rest_pre_dispatch', static function ($result) use ($message) {
            return new WP_Error('esp_protection_unavailable', $message, ['status' => 503]);
        }, -1000, 1);

        // WordPress feeds, sitemaps and direct PHP media routing must not
        // render while protection membership is unknown.
        add_action('template_redirect', static function () use ($message) {
            nocache_headers();
            status_header(503);
            wp_die($message, '', ['response' => 503]);
            exit;
        }, -1000);

        // Block public admin-ajax endpoints; keep privileged repair actions.
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
