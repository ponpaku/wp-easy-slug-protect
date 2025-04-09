<?php
/**
 * Plugin Name: Easy Slug Protect
 * Plugin URI: https://github.com/ponpaku/wp-easy-slug-protect
 * Description: URLの階層（スラッグ）ごとにシンプルなパスワード保護を実現するプラグイン
 * Version: 0.4.00
 * Author: ponpaku
 * Text Domain: easy-slug-protect
 * Domain Path: /languages
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}


// プラグインの基本定数を定義
define('ESP_VERSION', '0.4.00');
define('ESP_PATH', plugin_dir_path(__FILE__));
define('ESP_URL', plugin_dir_url(__FILE__));

/**
 * プラグインのメインクラス
 */
class Easy_Slug_Protect {
    /**
     * プラグインの初期化
     */
    public function __construct() {
        // 必要なファイルを読み込む
        $this->load_dependencies();

        // プラグインの初期化
        $this->init();
    }

    /**
     * 依存ファイルの読み込み
     */
    private function load_dependencies() {
        // コアクラスの読み込み
        require_once ESP_PATH . 'includes/class-esp-core.php';
        require_once ESP_PATH . 'includes/class-esp-setup.php';
        require_once ESP_PATH . 'includes/class-esp-auth.php';
        require_once ESP_PATH . 'includes/class-esp-cookie.php';
        require_once ESP_PATH . 'includes/class-esp-logout.php';
        require_once ESP_PATH . 'includes/class-esp-security.php';
        require_once ESP_PATH . 'includes/class-esp-session.php';
        require_once ESP_PATH . 'includes/class-esp-mail.php';
        require_once ESP_PATH . 'includes/class-esp-config.php';
        require_once ESP_PATH . 'includes/class-esp-option.php';
        require_once ESP_PATH . 'includes/class-esp-filter.php';

        // 管理画面クラスの読み込み
        if (is_admin()) {
            require_once ESP_PATH . 'admin/classes/class-esp-admin-core.php';
        }
    }

    /**
     * プラグインの初期化
     */
    private function init() {
        if (is_admin()) {
            // 管理画面の初期化
            new ESP_Admin_page();

            // バージョンチェックと更新
            add_action('admin_init', [$this, 'version_check']);
        } else {
            // セッション管理の初期化
            ESP_Session::get_instance();
            // コア機能の初期化
            $core = new ESP_Core();
            $core->init();
        }

        // 言語ファイルの読み込み(plugins_loadedは優先度下げる)
        add_action('plugins_loaded', [$this, 'load_textdomain'], 20);
    }

    /**
     * 言語ファイルの読み込み
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'easy-slug-protect',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * バージョンチェックと更新処理
     */
    public function version_check() {
        require_once ESP_PATH . 'includes/class-esp-setup.php';
        $setup = new ESP_Setup();
        $setup->update_check();
    }
}

/**
 * プラグインの有効化時の処理
 */
function esp_activate() {
    require_once ESP_PATH . 'includes/class-esp-setup.php';
    $setup = new ESP_Setup();
    $setup->activate();
}
register_activation_hook(__FILE__, 'esp_activate');

/**
 * プラグインの無効化時の処理
 */
function esp_deactivate() {
    require_once ESP_PATH . 'includes/class-esp-setup.php';
    $setup = new ESP_Setup();
    $setup->deactivate();
}
register_deactivation_hook(__FILE__, 'esp_deactivate');

// プラグインの初期化
function esp_init() {
    new Easy_Slug_Protect();
}
add_action('plugins_loaded', 'esp_init');
