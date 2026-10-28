<?php
/**
 * Plugin Name: Easy Slug Protect
 * Plugin URI: https://github.com/ponpaku/wp-easy-slug-protect
 * Description: URLの階層（スラッグ）ごとにシンプルなパスワード保護を実現するプラグイン
 * Version: 0.7.41
 * Update URI: https://github.com/ponpaku/wp-easy-slug-protect
 * Author: ponpaku
 * Text Domain: easy-slug-protect
 * Domain Path: /languages
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

// プラグインの基本定数を定義
define('ESP_VERSION', '0.7.41');
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
        self::load_dependencies();

        // セットアップクラスのインスタンス化
        $this->setup = new ESP_Setup();

        // プラグインの初期化
        $this->init();
    }

    /**
     * 依存ファイルの読み込み
     */
    public static function load_dependencies() {
        // コアクラスの読み込み
        require_once ESP_PATH . 'includes/class-esp-core.php';
        require_once ESP_PATH . 'includes/class-esp-setup.php';
        require_once ESP_PATH . 'includes/class-esp-migrator.php';
        require_once ESP_PATH . 'includes/class-esp-updater.php';
        require_once ESP_PATH . 'includes/class-esp-protection-state.php';
        require_once ESP_PATH . 'includes/class-esp-authorization-cache.php';
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
        require_once ESP_PATH . 'includes/class-esp-media-file-resolver.php';
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
        // WordPressの通常のメタデータ更新は、まず失効できるか確認する。
        // 失効できないときに更新してしまうと次リクエストに旧認可が残り得る。
        foreach (['add_post_metadata', 'update_post_metadata'] as $hook) {
            add_filter($hook, ['ESP_Authorization_Cache', 'guard_postmeta_write'], 1, 5);
        }
        add_filter('delete_post_metadata', ['ESP_Authorization_Cache', 'guard_postmeta_delete'], 1, 5);
        // Coreのby-mid APIは通常のpostmeta事前フィルターを呼ばない。
        add_filter('update_post_metadata_by_mid', ['ESP_Authorization_Cache', 'guard_postmeta_update_by_mid'], 1, 4);
        add_filter('delete_post_metadata_by_mid', ['ESP_Authorization_Cache', 'guard_postmeta_delete_by_mid'], 1, 2);
        // by-midで保護メタのキー自体が変わった場合、Coreの事後フックに
        // 旧キーが含まれないため、事前に記録した旧スコープも失効させる。
        add_action('updated_post_meta', ['ESP_Authorization_Cache', 'invalidate_after_meta_key_change'], 5, 4);
        // 管理画面・公開側の両方で、投稿メタ更新時の認可キャッシュ無効化を登録する。
        // ESP_Filter::init()は公開側のみで、一部の更新処理には間に合わない。
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, ['ESP_Filter', 'on_permalink_meta_mutation'], 10, 4);
            // 管理画面や他プラグインによる保護メタ変更も必ず失効させる。
            add_action($hook, ['ESP_Media_Protection', 'on_protection_meta_mutation'], 10, 4);
        }
        if (is_admin()) {
            add_action('admin_notices', ['ESP_Media_Protection', 'render_rewrite_admin_notice']);
        }
        // 更新イベントと明示的な復旧経路は、PENDING状態でも登録する。
        // 通常のページ表示では、バージョン／DB／Rewriteの厳密検査をしない。
        ESP_Updater::register($this->setup);
        ESP_Protection_State::register_runtime_hooks($this->setup);
        $ready = ESP_Protection_State::boot($this->setup);

        // 保守フックの登録は保護ゲートとは独立に行う。失敗時でも
        // セッション期限切れの削除等、公開権限を拡げないクリーンアップは継続する。
        add_action(ESP_Config::DAILY_CLEANUP_HOOK, ['ESP_Setup', 'run_cleanup_tasks']);
        // 保護情報の再生成・整合性修復はREADY時だけ実行する。
        add_action('esp_media_cache_refresh', static function () use ($ready) {
            if ($ready) { ESP_Setup::run_media_cache_refresh(); }
        });
        add_action('esp_all_cache_refresh', static function () use ($ready) {
            if ($ready) { ESP_Setup::run_all_cache_refresh(); }
        });
        add_action(ESP_Config::INTEGRITY_CHECK_HOOK, static function () use ($ready) {
            if ($ready) { ESP_Filter::cron_check_and_fix_permalink_paths(); }
        });

        if (!$ready) {
            // 壊れた設定を上書きしかねない通常フォームは表示しない。
            // 管理者の診断ページは登録して復旧経路を維持する。
            if (is_admin()) {
                ESP_Protection_State::register_recovery_admin();
            }
            return;
        }

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
        
        // AJAXアクションのフック
        add_action('wp_loaded', function() {
            if (is_admin()) { // 管理画面からのリクエストのみ
                $esp_filter = new ESP_Filter(); // インスタンス取得
                add_action('wp_ajax_esp_regenerate_permalink_paths_batch', [$esp_filter, 'ajax_regenerate_permalink_paths_batch']);
                add_action('wp_ajax_esp_clear_protection_cache', [$esp_filter, 'ajax_clear_protection_cache']);
            }
        });

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
    // WordPressの初回有効化ではplugins_loadedが再実行されない。
    // 通常起動と同じ依存クラスを明示的にロードしてから厳密検証する。
    Easy_Slug_Protect::load_dependencies();
    $setup = new ESP_Setup();
    if (!ESP_Updater::during_activation(static function () use ($setup) { return $setup->activate(); })) {
        wp_die(
            __('Easy Slug Protect: 保護状態の初期検証に失敗しました。有効化を中止し、DBとuploads/.htaccessを確認してください。', ESP_Config::TEXT_DOMAIN),
            '',
            ['response' => 500]
        );
    }
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
