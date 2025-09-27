<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

class ESP_Admin_Page {
    /**
     * @var ESP_Settings
     */
    private $settings;

    /**
     * @var ESP_Admin_Menu
     */
    private $menu;

    /**
     * @var ESP_Admin_Assets
     */
    private $assets;

    /**
     * @var ESP_Media_Protection
     */
    private $media_protection;

    public function __construct() {
        $this->load_dependencies();
        $this->initialize_components();
    }

    /**
     * 依存ファイルの読み込み
     */
    private function load_dependencies() {
        require_once ESP_PATH . 'admin/classes/class-esp-admin-menu.php';
        require_once ESP_PATH . 'admin/classes/class-esp-admin-setting.php';
        require_once ESP_PATH . 'admin/classes/class-esp-admin-sanitize.php';
        require_once ESP_PATH . 'admin/classes/class-esp-admin-assets.php';
    }

    /**
     * コンポーネントの初期化
     */
    private function initialize_components() {
        // シングルトンインスタンスを取得して初期化
        $settings = ESP_Settings::get_instance();
        $settings->init();

        $this->menu = new ESP_Admin_Menu();
        $this->assets = new ESP_Admin_Assets();

        if (class_exists('ESP_Media_Protection')) {
            // 管理画面でもメディア保護機能を有効化
            $this->media_protection = new ESP_Media_Protection();
        }
    }
}