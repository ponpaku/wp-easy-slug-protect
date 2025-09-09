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
        return isset($settings[$section]) ? $settings[$section] : ESP_Config::OPTION_DEFAULTS[$section];
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

}