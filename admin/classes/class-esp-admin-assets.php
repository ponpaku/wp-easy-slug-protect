<?php
if (!defined('ABSPATH')) {
    exit;
}

class ESP_Admin_Assets {
    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public static function localize_data(){
        $plugin_name = ESP_Config::TEXT_DOMAIN;
        return array(
            'confirmDelete' => __('この保護パスを削除してもよろしいですか？', $plugin_name),
            'confirmSave' => __('設定を保存してもよろしいですか？', $plugin_name),
            'unsavedChanges' => __('未保存の変更があります。このページを離れてもよろしいですか？', $plugin_name),
            'duplicatePath' => __('このパスは既に使用されています', $plugin_name),
            'show' => __('表示', $plugin_name),
            'hide' => __('非表示', $plugin_name),
            'selectPage' => __('選択してください', $plugin_name),
            'newProtectedPath' => __('新しい保護パス', $plugin_name),
            'delete' => __('削除', $plugin_name),
            'path' => __('パス:', $plugin_name),
            'password' => __('パスワード:', $plugin_name),
            'loginPage' => __('ログインページ:', $plugin_name),
            'shortcodeNotice' => __('[esp_login_form] ショートコードを配置してください', $plugin_name),
            'alertCantChengePath' => __('パスの変更は出来ません。変更したい場合は新たに追加してください', $plugin_name),
            'alertDuplicatePath' => __('パスが重複しています。各パスは一意でなければなりません。', $plugin_name),
            'alertDuplicateLoginPage' => __('同じログインページが複数のパスに設定されています。それぞれ異なるページを指定してください。', $plugin_name),
            'confirmRegeneratePermalinks' => __('全ての投稿のパーマリンクパス情報を再生成します。投稿数が多い場合、時間がかかることがあります。よろしいですか？', $plugin_name),
            'regenerating' => __('再生成中...', $plugin_name),
            'regeneratePermalinksButton' => __('全投稿のパーマリンクパス情報を再生成する', $plugin_name),
            'regenerateError' => __('エラーが発生しました。詳細はコンソールを確認してください。', $plugin_name),
            'regenerateCompleteNoItems' => __('処理対象の投稿がありませんでした。または全ての処理が完了しました。', $plugin_name),
            'confirmClearCache' => __('保護キャッシュをクリアします。よろしいですか？', $plugin_name),
            'clearingCache' => __('クリア中...', $plugin_name),
            'clearCacheButton' => __('保護キャッシュをクリアする', $plugin_name),
            'clearCacheError' => __('キャッシュのクリア中にエラーが発生しました。', $plugin_name),
            'ajaxError' => __('AJAXリクエストに失敗しました:', $plugin_name),
        );
    }

    public function enqueue_admin_assets($hook) {
        if ('toplevel_page_esp-settings' !== $hook) {
            return;
        }

        // CSSの読み込み
        wp_enqueue_style(
            'esp-admin-styles',
            ESP_URL . 'admin/esp-admin.css',
            array(),
            ESP_VERSION
        );

        // JavaScriptの読み込み
        wp_enqueue_script(
            'esp-admin-scripts',
            ESP_URL . 'admin/esp-admin.js',
            array('jquery'),
            ESP_VERSION,
            true
        );

        $pages_list = $this->get_all_pages();

        // 現在の設定値を取得
        $current_settings = [
            'path' => ESP_Option::get_current_setting('path'),
            'brute' => ESP_Option::get_current_setting('brute'),
            'remember' => ESP_Option::get_current_setting('remember'),
            'mail' => ESP_Option::get_current_setting('mail')
        ];

        // JavaScriptに渡すデータ
        wp_localize_script(
            'esp-admin-scripts',
            'espAdminData',
            array(
                'optionKey' => ESP_Config::OPTION_KEY,
                'pages_list' => $pages_list,
                'currentSettings' => $current_settings,
                'settingsNonce' => wp_create_nonce('esp_settings_group-options'),
                'regenerateNonce' => wp_create_nonce('esp_regenerate_permalinks_nonce'), // メタデータ再生成用Nonce
                'clearCacheNonce' => wp_create_nonce('esp_clear_cache_nonce'),         // キャッシュクリア用Nonce
                'i18n' => $this::localize_data()
            )
        );
    }

    /**
     * ページリストを取得する
     * 
     * @return array ページリスト
     */
    private function get_all_pages(){
        // ページ一覧の取得
        $pages = get_pages();
        $pages_list = '';
        foreach ($pages as $page) {
            $pages_list .= sprintf(
                '<option value="%d">%s</option>',
                $page->ID,
                esc_html($page->post_title)
            );
        }

        return $pages_list;
    }
}

