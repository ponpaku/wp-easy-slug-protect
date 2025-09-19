<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

class ESP_Option {
    /**
     * 設定全体を取得
     */
    public static function get_all_settings() {
        return get_option(ESP_Config::OPTION_KEY, ESP_Config::OPTION_DEFAULTS);
    }

    /**
     * 特定のセクションの設定を取得
     */
    public static function get_current_setting($section) {
        $settings = self::get_all_settings();
        $defaults = isset(ESP_Config::OPTION_DEFAULTS[$section]) ? ESP_Config::OPTION_DEFAULTS[$section] : array();
        if (!isset($settings[$section])) {
            return $defaults;
        }

        $current = $settings[$section];

        if (is_array($defaults)) {
            if (!is_array($current)) {
                $current = array();
            }

            return self::parse_args_deep($current, $defaults);
        }

        return $current;
    }

    /**
     * 設定全体を更新
     */
    public static function update_settings($settings) {
        return update_option(ESP_Config::OPTION_KEY, $settings);
    }

    /**
     * 特定のセクションの設定を更新
     */
    public static function update_section($section, $value) {
        $settings = self::get_all_settings();
        $settings[$section] = $value;
        return self::update_settings($settings);
    }

    /**
     * 配列を再帰的にマージし、デフォルト値を補完する
     *
     * @param array $args 現在の設定値
     * @param array $defaults デフォルト値
     * @return array マージ後の設定値
     */
    private static function parse_args_deep($args, $defaults) {
        $args = (array) $args;
        $defaults = (array) $defaults;

        $result = $defaults;

        foreach ($args as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])) {
                $result[$key] = self::parse_args_deep($value, $defaults[$key]);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

}
