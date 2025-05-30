<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

class ESP_Admin_Menu {
    /**
     * @var ESP_Settings 設定操作クラスのインスタンス
     */
    private $settings;

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        $this->settings = ESP_Settings::get_instance();
    }

    public function add_admin_menu() {
        add_menu_page(
            'Easy Slug Protect',
            'Easy Slug Protect',
            'manage_options',
            'esp-settings',
            array($this, 'render_settings_page'),
            'dashicons-lock',
            80
        );
    }

    public function render_settings_page() {

        // 現在の設定値を取得
        $protected_paths = ESP_Option::get_current_setting('path');
        $bruteforce_settings = ESP_Option::get_current_setting('brute');
        $remember_settings = ESP_Option::get_current_setting('remember');
        $mail_settings =  ESP_Option::get_current_setting('mail');

        $text_domain = ESP_Config::TEXT_DOMAIN;
        $option_key = ESP_Config::OPTION_KEY;

        ?>
        <div class="wrap">
            <h1><?php _e('Easy Slug Protect 設定', $text_domain); ?></h1>

            <!-- 使い方説明 -->
            <div class="esp-section">
                <h2><?php _e('使い方', $text_domain); ?></h2>
                <ol>
                    <li>
                        <?php _e('ログインページに使用する固定ページを作成し、[esp_login_form] を設置してください。', $text_domain); ?>
                        <br><?php _e('ログインページは保護したいスラッグ配下でなくても大丈夫です。', $text_domain); ?>
                    </li>
                    <li>
                        <?php _e('「保護パスを追加」して、設定を入力してください。', $text_domain); ?>
                    </li>
                    <li>
                        <?php _e('その他設定を適宜行ってください', $text_domain); ?>
                    </li>
                    <li>
                        <?php _e('[esp_logout_button]を任意のページに配置することでログアウトボタンを追加出来ます。', $text_domain); ?>
                    </li>
                </ol>
                <h2><?php _e('ショートコード内で行える設定', $text_domain); ?></h2>
                <h3><?php _e('[esp_login_form]', $text_domain); ?></h3>
                <ul>
                    <li>
                        <?php _e('path="" : ログインページに設定していないページにログインフォームを設置する際には、このオプションでどのパスに対するフォームなのかを指定してください', $text_domain); ?>
                    </li>
                    <li>
                        <?php _e('place_holder="" : パスワード入力ボックスのプレースホルダーを指定できます。', $text_domain); ?>
                    </li>
                </ul>
                <h3><?php _e('[esp_logout_button]', $text_domain); ?></h3>
                <ul>
                    <li>
                        <?php _e('redirect_to="" : ログアウト後のリダイレクト先を指定できます。', $text_domain); ?>
                    </li>
                    <li>
                        <?php _e('text="" : ボタンのテキストを指定します。（設定推奨）', $text_domain); ?>
                    </li>
                    <li>
                        <?php _e('class="" : ボタンに任意のクラスを追加します。', $text_domain); ?>
                    </li>
                    <li>
                        <?php _e('path="" : ログアウトするパスを指定できます。（未設定の場合ボタンが配置されているパス）', $text_domain); ?>
                    </li>
                </ul>
            </div>

            <form method="post" action="options.php" id="esp-settings-form">
                <?php settings_fields('esp_settings_group'); ?>
                <input type="hidden" name="option_page" value="esp_settings_group">
                <input type="hidden" name="action" value="update">
                <?php wp_nonce_field('esp_settings_group-options', '_wpnonce', false); ?>
                <input type="hidden" name="<?php echo $option_key; ?>[initialized]" value="1">
                
                <!-- パスが存在しない場合でもPOSTされるように空の配列を示す hidden フィールドを追加 -->
                <input type="hidden" name="<?php echo $option_key; ?>[path]" value="">

                <!-- 保護パスの設定セクション -->
                <div class="esp-section">
                    <h2><?php _e('保護するパスの設定', $text_domain); ?></h2>
                    <button type="button" class="button" id="esp-add-path">
                        <?php _e('保護パスを追加', $text_domain); ?>
                    </button>
                    <div class="esp-paths-container" id="esp-paths-container">
                        <?php if (!empty($protected_paths)): ?>
                            <?php foreach ($protected_paths as $path_id => $path): ?>
                                <div class="esp-path-item" data-path-id="<?php echo esc_attr($path_id); ?>">
                                    <div class="esp-path-header">
                                        <h3><?php echo esc_html($path['path']); ?></h3>
                                        <button type="button" class="button esp-remove-path">削除</button>
                                    </div>
                                    <div class="esp-path-content">
                                        <input type="hidden" 
                                            name="<?php echo $option_key; ?>[path][<?php echo $path_id; ?>][id]" 
                                            value="<?php echo esc_attr($path_id); ?>">
                                        <p>
                                            <label><?php _e('パス:', $text_domain); ?></label>
                                            <input type="text" 
                                                name="<?php echo $option_key; ?>[path][<?php echo $path_id; ?>][path]" 
                                                value="<?php echo esc_attr($path['path']); ?>"
                                                class="regular-text"
                                                placeholder="/example/"
                                                data-input-lock="true"
                                                required>
                                        </p>
                                        <p>
                                            <label><?php _e('パスワード:', $text_domain); ?></label>
                                            <input type="password" 
                                                name="<?php echo $option_key; ?>[path][<?php echo $path_id; ?>][password]" 
                                                class="regular-text"
                                                placeholder="<?php _e('変更する場合のみ入力', $text_domain); ?>">
                                            <span class="description">
                                                <?php _e('空白の場合、既存のパスワードが維持されます', $text_domain); ?>
                                            </span>
                                        </p>
                                        <p>
                                            <label><?php _e('ログインページ:', $text_domain); ?></label>
                                            <?php 
                                            wp_dropdown_pages(array(
                                                'name' => "{$option_key}[path][{$path_id}][login_page]",
                                                'selected' => $path['login_page'],
                                                'show_option_none' => __('選択してください', $text_domain),
                                                'option_none_value' => '0'
                                            )); 
                                            ?>
                                            <span class="description">
                                                <?php _e('選択したページに [esp_login_form] ショートコードを配置してください', $text_domain); ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ブルートフォース対策の設定セクション -->
                <div class="esp-section">
                    <h2><?php _e('ブルートフォース対策設定', $text_domain); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="esp-attempts-threshold">
                                    <?php _e('試行回数の上限', $text_domain); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" 
                                    id="esp-attempts-threshold"
                                    name="<?php echo $option_key; ?>[brute][attempts_threshold]"
                                    value="<?php echo esc_attr($bruteforce_settings['attempts_threshold']); ?>"
                                    min="1"
                                    required>
                                <p class="description">
                                    <?php _e('この回数を超えるとアクセスがブロックされます', $text_domain); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="esp-time-frame">
                                    <?php _e('試行回数のカウント期間', $text_domain); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" 
                                    id="esp-time-frame"
                                    name="<?php echo $option_key; ?>[brute][time_frame]"
                                    value="<?php echo esc_attr($bruteforce_settings['time_frame']); ?>"
                                    min="1"
                                    required>
                                <?php _e('分', $text_domain); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="esp-block-time-frame">
                                    <?php _e('ブロック時間', $text_domain); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" 
                                    id="esp-block-time-frame"
                                    name="<?php echo $option_key; ?>[brute][block_time_frame]"
                                    value="<?php echo esc_attr($bruteforce_settings['block_time_frame']); ?>"
                                    min="1"
                                    required>
                                <?php _e('分', $text_domain); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="esp-whitelist-ips">
                                    <?php _e('ホワイトリストIPアドレス', $text_domain); ?>
                                </label>
                            </th>
                            <td>
                                <textarea id="esp-whitelist-ips"
                                    name="<?php echo $option_key; ?>[brute][whitelist_ips]"
                                    rows="5"
                                    cols="50"
                                    class="large-text"
                                    placeholder="<?php _e('例: 192.168.1.1, 203.0.113.10, 2001:db8::1', $text_domain); ?>"
                                ><?php echo esc_textarea(isset($bruteforce_settings['whitelist_ips']) ? $bruteforce_settings['whitelist_ips'] : ''); ?></textarea>
                                <p class="description">
                                    <?php _e('カンマ区切りでIPアドレスまたはCIDR表記のIPアドレス範囲を入力してください。これらのIPアドレスはブルートフォースチェックの対象外となります。', $text_domain); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ログイン保持の設定セクション -->
                <div class="esp-section">
                    <h2><?php _e('ログイン保持設定', $text_domain); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="esp-remember-time">
                                    <?php _e('ログイン保持期間', $text_domain); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" 
                                    id="esp-remember-time"
                                    name="<?php echo $option_key; ?>[remember][time_frame]"
                                    value="<?php echo esc_attr($remember_settings['time_frame']); ?>"
                                    min="1"
                                    required>
                                <?php _e('日', $text_domain); ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="esp-section">
                    <h2><?php _e('メール通知設定', $text_domain); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="esp-enable-notifications">
                                    <?php _e('メール通知', $text_domain); ?>
                                </label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                        id="esp-enable-notifications"
                                        name="<?php echo $option_key; ?>[mail][enable_notifications]"
                                        value="1"
                                        <?php checked($mail_settings['enable_notifications']); ?>>
                                    <?php _e('メール通知を有効にする', $text_domain); ?>
                                </label>
                                <p class="description">
                                    <?php _e('通知メールは管理者権限を持つすべてのユーザーに送信されます。', $text_domain); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?php _e('通知する項目', $text_domain); ?>
                            </th>
                            <td>
                                <fieldset class="esp-notification-items">
                                    <!-- デフォルトで通知項目配列が存在することを保証 -->
                                    <?php $notifications = isset($mail_settings['notifications']) ? $mail_settings['notifications'] : array(); ?>
                                    
                                    <label <?php echo !$mail_settings['enable_notifications'] ? 'class="esp-disabled"' : ''; ?>>
                                        <input type="checkbox" 
                                            name="<?php echo $option_key; ?>[mail][notifications][new_path]"
                                            value="1"
                                            <?php checked(isset($notifications['new_path']) && $notifications['new_path']); ?>
                                            <?php disabled(!$mail_settings['enable_notifications']); ?>>
                                        <?php _e('新しい保護パスの追加', $text_domain); ?>
                                    </label>
                                    <br>
                                    
                                    <label <?php echo !$mail_settings['enable_notifications'] ? 'class="esp-disabled"' : ''; ?>>
                                        <input type="checkbox" 
                                            name="<?php echo $option_key; ?>[mail][notifications][password_change]"
                                            value="1"
                                            <?php checked(isset($notifications['password_change']) && $notifications['password_change']); ?>
                                            <?php disabled(!$mail_settings['enable_notifications']); ?>>
                                        <?php _e('パスワードの変更', $text_domain); ?>
                                    </label>
                                    <br>
                                    
                                    <label <?php echo !$mail_settings['enable_notifications'] ? 'class="esp-disabled"' : ''; ?>>
                                        <input type="checkbox" 
                                            name="<?php echo $option_key; ?>[mail][notifications][path_remove]"
                                            value="1"
                                            <?php checked(isset($notifications['path_remove']) && $notifications['path_remove']); ?>
                                            <?php disabled(!$mail_settings['enable_notifications']); ?>>
                                        <?php _e('保護パスの削除', $text_domain); ?>
                                    </label>
                                    <br>

                                    <label <?php echo !$mail_settings['enable_notifications'] ? 'class="esp-disabled"' : ''; ?>>
                                        <input type="checkbox" 
                                            name="<?php echo $option_key; ?>[mail][notifications][brute_force]"
                                            value="1"
                                            <?php checked(isset($notifications['brute_force']) && $notifications['brute_force']); ?>
                                            <?php disabled(!$mail_settings['enable_notifications']); ?>>
                                        <?php _e('ブルートフォースブロック発生時', $text_domain); ?>
                                    </label>
                                    <br>
                                    
                                    <label <?php echo !$mail_settings['enable_notifications'] ? 'class="esp-disabled"' : ''; ?>>
                                        <input type="checkbox" 
                                            name="<?php echo $option_key; ?>[mail][notifications][critical_error]"
                                            value="1"
                                            <?php checked(isset($notifications['critical_error']) && $notifications['critical_error']); ?>
                                            <?php disabled(!$mail_settings['enable_notifications']); ?>>
                                        <?php _e('重大なエラーの発生', $text_domain); ?>
                                    </label>
                                </fieldset>
                                <p class="description">
                                    <?php _e('通知メールは管理者権限を持つすべてのユーザーに送信されます。', $text_domain); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>


                <div class="esp-section">
                    <h2><?php _e('ツール', $text_domain); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('パーマリンクパス情報', $text_domain); ?></th>
                            <td>
                                <button type="button" class="button button-secondary" id="esp-regenerate-permalink-paths">
                                    <?php _e('全投稿のパーマリンクパス情報を再生成する', $text_domain); ?>
                                </button>
                                <p class="description">
                                    <?php _e('投稿のパーマリンクと、プラグインが内部で保持しているパス情報との間に不整合が生じた可能性がある場合に実行してください。投稿数が多い場合は時間がかかることがあります。', $text_domain); ?>
                                </p>
                                <div id="esp-regenerate-progress-bar-container" style="display:none; margin-top: 10px; width: 100%; background-color: #f3f3f3; border: 1px solid #ccc;">
                                    <div id="esp-regenerate-progress-bar" style="width: 0%; height: 20px; background-color: #4caf50; text-align: center; line-height: 20px; color: white;">0%</div>
                                </div>
                                <div id="esp-regenerate-status" style="margin-top: 5px;"></div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('保護キャッシュクリア', $text_domain); ?></th>
                            <td>
                                <button type="button" class="button button-secondary" id="esp-clear-protection-cache">
                                    <?php _e('保護キャッシュをクリアする', $text_domain); ?>
                                </button>
                                <p class="description">
                                    <?php _e('保護対象の投稿リストのキャッシュを強制的にクリアし、再生成を促します。通常は設定変更時や投稿更新時に自動で行われます。', $text_domain); ?>
                                </p>
                                <div id="esp-clear-cache-status" style="margin-top: 5px;"></div>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}