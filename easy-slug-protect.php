<?php
/**
 * Plugin Name: Easy Slug Protect
 * Plugin URI: https://github.com/ponpaku/wp-easy-slug-protect
 * Description: URLの階層（スラッグ）ごとにシンプルなパスワード保護を実現するプラグイン
 * Version: 0.7.31
 * Author: ponpaku
 * Text Domain: easy-slug-protect
 * Domain Path: /languages
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

// プラグインの基本定数を定義
define('ESP_VERSION', '0.7.31');
define('ESP_PATH', plugin_dir_path(__FILE__));
define('ESP_URL', plugin_dir_url(__FILE__));

/**
 * プラグインのメインクラス
 */
class Easy_Slug_Protect {

    /**
     * @var ESP_Setup セットアップクラスのインスタンス
     */
    private $setup;

    /**
     * プラグインの初期化
     */
    public function __construct() {
        // 必要なファイルを読み込む
        $this->load_dependencies();

        // セットアップクラスのインスタンス化
        $this->setup = new ESP_Setup();

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
        require_once ESP_PATH . 'includes/class-esp-message.php';
        require_once ESP_PATH . 'includes/class-esp-mail.php';
        require_once ESP_PATH . 'includes/class-esp-config.php';
        require_once ESP_PATH . 'includes/class-esp-option.php';
        require_once ESP_PATH . 'includes/class-esp-filter.php';
        require_once ESP_PATH . 'includes/class-esp-path-matcher.php';
        require_once ESP_PATH . 'includes/class-esp-media-deriver.php';
        require_once ESP_PATH . 'includes/class-esp-media-protection.php';

        // 管理画面クラスの読み込み
        if (is_admin()) {
            require_once ESP_PATH . 'admin/classes/class-esp-admin-core.php';
        }
    }

    /**
     * プラグインの初期化
     */
    private function init() {
        // バージョンチェックと更新は常に実行
        $this->setup->check_plugin_version();

        if (is_admin()) {
            // 管理画面の初期化
            new ESP_Admin_page();
        } else {
            // コア機能の初期化
            $core = new ESP_Core();
            $core->init();
        }

        // 言語ファイルの読み込み(plugins_loadedは優先度下げる)
        add_action('plugins_loaded', [$this, 'load_textdomain'], 20);

        // パスマッチャーの初期化
        add_action('init', function() {
            if (!is_admin()) {
                // フロントエンドでのみパスマッチャーを初期化
                new ESP_Path_Matcher();
            }
        }, 5); // 優先度を高めに設定
        
        // クリーンアップタスクの実行（Setupで登録したcronから呼び出される）
        add_action(ESP_Config::DAILY_CLEANUP_HOOK, ['ESP_Setup', 'run_cleanup_tasks']);

        // AJAXアクションのフック
        add_action('wp_loaded', function() {
            if (is_admin()) { // 管理画面からのリクエストのみ
                $esp_filter = new ESP_Filter(); // インスタンス取得
                add_action('wp_ajax_esp_regenerate_permalink_paths_batch', [$esp_filter, 'ajax_regenerate_permalink_paths_batch']);
                add_action('wp_ajax_esp_clear_protection_cache', [$esp_filter, 'ajax_clear_protection_cache']);
            }
        });

        if (class_exists('ESP_Filter')) { // ESP_Filter がロードされていることを確認
            add_action(ESP_Config::INTEGRITY_CHECK_HOOK, ['ESP_Filter', 'cron_check_and_fix_permalink_paths']);
        }

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