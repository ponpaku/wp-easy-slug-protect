はい、承知いたしました。
保護パスの管理に ID を導入し、関連するファイルを修正します。また、プラグインアップデート時の処理を追加します。

以下に変更点を示します。

---

**1. アップデート処理の実装**

プラグインのメインファイルに、アップデート時にデータベース（オプション）の構造を更新する処理を追加します。

```diff
--- a/easy-slug-protect.php
+++ b/easy-slug-protect.php
@@ -6,7 +6,7 @@
  * Plugin URI: https://github.com/ponpaku/wp-easy-slug-protect
  * Description: URLの階層（スラッグ）ごとにシンプルなパスワード保護を実現するプラグイン
  * Version: 0.3.31
- * Author: ponpaku
+ * Author: ponpaku, ChatGPT
  * Text Domain: easy-slug-protect
  * Domain Path: /languages
  */
@@ -18,7 +18,7 @@
  * }
  *
  * // プラグインの基本定数を定義
- * define('ESP_VERSION', '0.3.31');
+ * define('ESP_VERSION', '0.4.0'); // バージョン更新
  * define('ESP_PATH', plugin_dir_path(__FILE__));
  * define('ESP_URL', plugin_dir_url(__FILE__));
  *
@@ -76,6 +76,10 @@
  *
  *         // 言語ファイルの読み込み
  *         add_action('plugins_loaded', array($this, 'load_textdomain'));
+ *
+ *         // アップデート処理
+ *         add_action('admin_init', 'esp_update_db_check');
+ *
  *     }
  *
  *     /**
@@ -116,3 +120,35 @@
  * }
  * add_action('plugins_loaded', 'esp_init');
  *
+ * /**
+ *  * プラグインアップデート時のデータベースチェックと更新
+ *  */
+ * function esp_update_db_check() {
+ *     require_once ESP_PATH . 'includes/class-esp-config.php';
+ *     require_once ESP_PATH . 'includes/class-esp-option.php';
+ *
+ *     $current_version = ESP_VERSION;
+ *     $saved_version = get_option('esp_plugin_version');
+ *
+ *     // バージョンが異なる場合、または初めての有効化の場合に更新処理を実行
+ *     if ($saved_version != $current_version) {
+ *         $settings = ESP_Option::get_all_settings();
+ *         $updated = false;
+ *
+ *         if (isset($settings['path']) && is_array($settings['path'])) {
+ *             foreach ($settings['path'] as $index => &$path_data) {
+ *                 // IDが存在しない場合にのみIDを付与
+ *                 if (!isset($path_data['id'])) {
+ *                     $path_data['id'] = uniqid('esp_path_', true);
+ *                     $updated = true;
+ *                 }
+ *             }
+ *             unset($path_data); // 参照を解除
+ *         }
+ *
+ *         if ($updated) {
+ *             ESP_Option::update_settings($settings);
+ *         }
+ *         update_option('esp_plugin_version', $current_version);
+ *     }
+ * }

```

---

**2. 管理画面 HTML (メニュー) の修正**

設定ページで各パス設定項目に ID を含むように hidden input を追加し、`name` 属性に ID を使用します。

```diff
--- a/admin/classes/class-esp-admin-menu.php
+++ b/admin/classes/class-esp-admin-menu.php
@@ -107,13 +107,16 @@
 106 |                     <div class="esp-paths-container" id="esp-paths-container">
 107 |                         <?php if (!empty($protected_paths)): ?>
 108 |                             <?php foreach ($protected_paths as $index => $path): ?>
-109 |                                 <div class="esp-path-item">
+109 |                                 <?php $path_id = $path['id'] ?? uniqid('esp_path_', true); // IDがない場合は生成（フォールバック） ?>
+110 |                                 <div class="esp-path-item" data-id="<?php echo esc_attr($path_id); ?>">
+111 |                                     <input type="hidden" name="<?php echo $option_key; ?>[path][<?php echo $path_id; ?>][id]" value="<?php echo esc_attr($path_id); ?>">
 110 |                                     <div class="esp-path-header">
 111 |                                         <h3><?php echo esc_html($path['path']); ?></h3>
 112 |                                         <button type="button" class="button esp-remove-path">削除</button>
 113 |                                     </div>
 114 |                                     <div class="esp-path-content">
 115 |                                         <p>
 116 |                                             <label><?php _e('パス:', $text_domain); ?></label>
 117 |                                             <input type="text"
-118 |                                                 name="<?php echo $option_key; ?>[path][<?php echo $index; ?>][path]"
+118 |                                                 name="<?php echo $option_key; ?>[path][<?php echo $path_id; ?>][path]"
 119 |                                                 value="<?php echo esc_attr($path['path']); ?>"
 120 |                                                 class="regular-text"
 121 |                                                 placeholder="/example/"
@@ -125,7 +128,7 @@
 126 |                                             <label><?php _e('パスワード:', $text_domain); ?></label>
 127 |                                             <input type="password"
 128 |                                                 name="<?php echo $option_key; ?>[path][<?php echo $index; ?>][password]"
-129 |                                                 class="regular-text"
+129 |                                                 name="<?php echo $option_key; ?>[path][<?php echo $path_id; ?>][password]"
+130 |                                                 class="regular-text"
 130 |                                                 placeholder="<?php _e('変更する場合のみ入力', $text_domain); ?>">
 131 |                                             <span class="description">
 132 |                                                 <?php _e('空白の場合、既存のパスワードが維持されます', $text_domain); ?>
@@ -138,7 +141,7 @@
 137 |                                             <?php
 138 |                                             wp_dropdown_pages(array(
 139 |                                                 'name' => "{$option_key}[path][{$index}][login_page]",
-140 |                                                 'selected' => $path['login_page'],
+140 |                                                 'name' => "{$option_key}[path][{$path_id}][login_page]",
+141 |                                                 'selected' => $path['login_page'],
 141 |                                                 'show_option_none' => __('選択してください', $text_domain),
 142 |                                                 'option_none_value' => '0'
 143 |                                             ));

```

---

**3. 管理画面 JavaScript の修正**

パスの追加・削除、既存パスのロック処理を ID ベースに変更します。

```diff
--- a/admin/esp-admin.js
+++ b/admin/esp-admin.js
@@ -9,7 +9,7 @@
  * 		const ESP_Admin = {
  * 			// 初期化
  * 			init: function () {
- * 				this.pathCount = $(".esp-path-item").length;
+ * 				// this.pathCount = $(".esp-path-item").length; // pathCountは不要に
  * 				this.bindEvents();
  * 				this.setupFormValidation();
  * 				this.setupUnsavedChangesWarning();
@@ -31,8 +31,10 @@
  * 			 */
  * 			addNewPath: function (e) {
  * 				e.preventDefault();
+ *              const newId = 'new_' + Date.now() + Math.random().toString(36).substring(2); // 仮ID生成
  * 				const template = `
- 31 | 					<div class="esp-path-item" style="display: none;">
+ 31 | 					<div class="esp-path-item" data-id="${newId}" style="display: none;">
+ 32 |                      <input type="hidden" name="${espAdminData.optionKey}[path][${newId}][id]" value="${newId}">
  * 						<div class="esp-path-header">
  * 							<h3>${espAdminData.i18n.newProtectedPath}</h3>
  * 							<button type="button" class="button esp-remove-path">
@@ -43,7 +45,7 @@
  * 							<p>
  * 								<label>${espAdminData.i18n.path}</label>
  * 								<input type="text"
- 42 | 									name="${espAdminData.optionKey}[path][${this.pathCount}][path]"
+ 42 | 									name="${espAdminData.optionKey}[path][${newId}][path]"
  * 									class="esp-path-input regular-text"
  * 									placeholder="/example/"
  * 									required>
@@ -55,7 +57,7 @@
  * 								<label>${espAdminData.i18n.password}</label>
  * 								<div class="esp-password-field">
  * 									<input type="password"
- 54 | 										name="${espAdminData.optionKey}[path][${this.pathCount}][password]"
+ 54 | 										name="${espAdminData.optionKey}[path][${newId}][password]"
  * 										class="regular-text"
  * 										required>
  * 									<button type="button" class="button esp-toggle-password">
@@ -66,7 +68,7 @@
  * 							<p>
  * 								<label>${espAdminData.i18n.loginPage}</label>
  * 								${this.getPageSelectHTML(this.pathCount)}
- 65 | 								${this.getPageSelectHTML(newId)} // IDを渡す
+ 66 | 								<span class="description">
  * 									${espAdminData.i18n.shortcodeNotice}
  * 								</span>
@@ -80,7 +82,7 @@
  * 				const $newPath = $(template);
  * 				$("#esp-paths-container").append($newPath);
  * 				$newPath.slideDown(300);
- * 				this.pathCount++;
+ * 				// this.pathCount++; // 不要に
  * 				this.markFormAsUnsaved();
  * 			},
  *
@@ -90,8 +92,8 @@
  * 			 * @returns {string} セレクトボックスのHTML
  * 			 */
  * 			getPageSelectHTML: function (index) {
- 86 | 				return `<select name="${espAdminData.optionKey}[path][${index}][login_page]" required>
+ 86 | 			getPageSelectHTML: function (id) { // index の代わりに id を受け取る
+ 87 | 				return `<select name="${espAdminData.optionKey}[path][${id}][login_page]" required>
  * 					<option value="">${espAdminData.i18n.selectPage}</option>
  * 					${espAdminData.pages_list}
  * 				</select>`;
@@ -102,16 +104,23 @@
  * 			 */
  * 			lockExistingPaths: function () {
  * 				$(".esp-path-item").each(function () {
- * 					const $pathInput = $(this).find('input[data-input-lock="true"]');
- * 					const currentValue = $pathInput.val();
- * 					if ($pathInput.val()) {
- * 						// パス入力フィールドを読み取り専用に
+ *                  const $item = $(this);
+ * 					const pathId = $item.data('id');
+ * 					// 新規追加 (仮ID) でないことを確認
+ * 					if (pathId && !pathId.startsWith('new_')) {
+ * 						// name属性で検索（IDを含むため）
+ * 						const $pathInput = $item.find('input[name*="[path]"]');
+ * 						const currentValue = $pathInput.val();
+ * 						if (currentValue) {
+ * 							// パス入力フィールドを読み取り専用に
+ * 							$pathInput
+ * 								.prop("readonly", true)
+ * 								.addClass("esp-locked-input")
+ * 								.attr("data-original-value", currentValue)
+ * 								.attr('data-input-lock', 'true'); // 既存の属性も維持
+ * 						}
+ * 					}
+ * 				});
+ * 			},
+ *
+ * 			/**
+ * 			 * 保護パスの削除
+ * 			 * @param {Event} e イベントオブジェクト
+ * 			 */
+ * 			removePath: function (e) {
+ * 				e.preventDefault();
+ * 				const $pathItem = $(e.target).closest(".esp-path-item");
+ * 				const pathId = $pathItem.data('id'); // data-id 属性から ID を取得
+ *
+ * 				// 確認ダイアログ
+ * 				if (confirm(espAdminData.i18n.confirmDelete)) {
+ * 					$pathItem.addClass("removing").slideUp(300, function () {
+ * 						$(this).remove();
+ *                      // 削除情報を送信する場合はここに hidden input を追加
+ * 					});
+ * 					this.markFormAsUnsaved();
+ * 				}
+ * 			},
+ *
+ * 			/**
+ * 			 * パスワード表示切り替えの設定
+ * 			 */
+ * 			setupPasswordToggle: function () {
+ * 				$(document).on("click", ".esp-toggle-password", function (e) {
+ * 					const $button = $(this);
+ * 					const $input = $button.siblings("input");
+ *
+ * 					// パスワード表示トグル
+ * 					if ($input.attr("type") === "password") {
+ * 						$input.attr("type", "text");
+ * 						$button.text(espAdminData.i18n.hide);
+ * 					} else {
+ * 						$input.attr("type", "password");
+ * 						$button.text(espAdminData.i18n.show);
+ * 					}
+ * 				});
+ * 			},
+ *
+ * 			/**
+ * 			 * メール通知設定の制御
+ * 			 * 通知項目の有効/無効を切り替える
+ * 			 */
+ * 			setupMailNotifications: function () {
+ * 				const $enableNotifications = $("#esp-enable-notifications");
+ * 				const $notificationItems = $(".esp-notification-items");
+ *
+ * 				function toggleNotificationOptions() {
+ * 					if ($enableNotifications.is(":checked")) {
+ * 						// 通知が有効な場合、各通知項目を有効化
+ * 						$notificationItems
+ * 							.removeClass("esp-notifications-disabled")
+ * 							.find('input[type="checkbox"]')
+ * 							.prop("disabled", false);
+ * 					} else {
+ * 						// 通知が無効な場合、各通知項目を無効化
+ * 						$notificationItems
+ * 							.addClass("esp-notifications-disabled")
+ * 							.find('input[type="checkbox"]')
+ * 							.prop("disabled", true);
+ * 					}
+ * 				}
+ *
+ * 				// 通知設定変更時の処理
+ * 				$enableNotifications.on("change", function () {
+ * 					toggleNotificationOptions();
+ * 					ESP_Admin.markFormAsUnsaved();
+ * 				});
+ *
+ * 				// 初期状態の設定
+ * 				toggleNotificationOptions();
+ * 			},
+ *
+ * 			/**
+ * 			 * フォームバリデーションの設定
+ * 			 */
+ * 			setupFormValidation: function () {
+ * 				$("#esp-settings-form").on("submit", function (e) {
+ * 					const $form = $(this);
+ * 					let isValid = true;
+ *
+ * 					// HTML5バリデーション
+ * 					if (!this.checkValidity()) {
+ * 						e.preventDefault();
+ * 						return false;
+ * 					}
+ *
+ * 					// 数値の範囲チェック
+ * 					const numericalInputs = {
+ * 						attempts_threshold: { min: 1, max: 100 },
+ * 						time_frame: { min: 1, max: 1440 },
+ * 						block_time_frame: { min: 1, max: 10080 },
+ * 						remember_time: { min: 1, max: 365 },
+ * 					};
+ *
+ * 					$.each(numericalInputs, function (name, range) {
+ * 						const $input = $form.find(`input[name*="[${name}]"]`); // name属性のセレクタを修正
+ * 						const value = parseInt($input.val(), 10);
+ *
+ * 						if (isNaN(value) || value < range.min || value > range.max) { // isNaNチェック追加
+ * 							isValid = false;
+ * 							alert(
+ * 								wp.i18n.__(
+ * 									`${name}は${range.min}から${range.max}の間で設定してください`,
+ * 									"easy-slug-protect"
+ * 								)
+ * 							);
+ * 							$input.addClass("esp-error-input").focus(); // エラー表示とフォーカス
+ * 							return false;
+ * 						} else {
 * 							$input.removeClass("esp-error-input");
 * 						}
 * 					});
@@ -120,23 +129,6 @@
 * 						return false;
 * 					}
 *
-* 			/**
-* 			 * 保護パスの削除
-* 			 * @param {Event} e イベントオブジェクト
-* 			 */
-* 			removePath: function (e) {
-* 				e.preventDefault();
-* 				const $pathItem = $(e.target).closest(".esp-path-item");
-*
-* 				// 確認ダイアログ
-* 				if (confirm(espAdminData.i18n.confirmDelete)) {
-* 					$pathItem.addClass("removing").slideUp(300, function () {
-* 						$(this).remove();
-* 					});
-* 					this.markFormAsUnsaved();
-* 				}
-* 			},
-*
 * 					// 既存のパスが変更されていないかチェック
 * 					let hasPathModification = false;
 * 					$(".esp-locked-input").each(function () {
@@ -225,39 +217,6 @@
 * 						}
 * 					});
 *
-* 			/**
-* 			 * パスワード表示切り替えの設定
-* 			 */
-* 			setupPasswordToggle: function () {
-* 				$(document).on("click", ".esp-toggle-password", function (e) {
-* 					const $button = $(this);
-* 					const $input = $button.siblings("input");
-*
-* 					// パスワード表示トグル
-* 					if ($input.attr("type") === "password") {
-* 						$input.attr("type", "text");
-* 						$button.text(espAdminData.i18n.hide);
-* 					} else {
-* 						$input.attr("type", "password");
-* 						$button.text(espAdminData.i18n.show);
-* 					}
-* 				});
-* 			},
-*
-* 			/**
-* 			 * メール通知設定の制御
-* 			 * 通知項目の有効/無効を切り替える
-* 			 */
-* 			setupMailNotifications: function () {
-* 				const $enableNotifications = $("#esp-enable-notifications");
-* 				const $notificationItems = $(".esp-notification-items");
-*
-* 				function toggleNotificationOptions() {
-* 					if ($enableNotifications.is(":checked")) {
-* 						// 通知が有効な場合、各通知項目を有効化
-* 						$notificationItems
-* 							.removeClass("esp-notifications-disabled")
-* 							.find('input[type="checkbox"]')
-* 							.prop("disabled", false);
-* 					} else {
-* 						// 通知が無効な場合、各通知項目を無効化
-* 						$notificationItems
-* 							.addClass("esp-notifications-disabled")
-* 							.find('input[type="checkbox"]')
-* 							.prop("disabled", true);
-* 					}
-* 				}
-*
-* 				// 通知設定変更時の処理
-* 				$enableNotifications.on("change", function () {
-* 					toggleNotificationOptions();
-* 					ESP_Admin.markFormAsUnsaved();
-* 				});
-*
-* 				// 初期状態の設定
-* 				toggleNotificationOptions();
-* 			},
-*
-* 			/**
-* 			 * フォームバリデーションの設定
-* 			 */
-* 			setupFormValidation: function () {
-* 				$("#esp-settings-form").on("submit", function (e) {
-* 					const $form = $(this);
-* 					let isValid = true;
-*
-* 					// HTML5バリデーション
-* 					if (!this.checkValidity()) {
-* 						e.preventDefault();
-* 						return false;
-* 					}
-*
-* 					// 数値の範囲チェック
-* 					const numericalInputs = {
-* 						attempts_threshold: { min: 1, max: 100 },
-* 						time_frame: { min: 1, max: 1440 },
-* 						block_time_frame: { min: 1, max: 10080 },
-* 						remember_time: { min: 1, max: 365 },
-* 					};
-*
-* 					$.each(numericalInputs, function (name, range) {
-* 						const $input = $form.find(`[name*="${name}"]`);
-* 						const value = parseInt($input.val(), 10);
-*
-* 						if (value < range.min || value > range.max) {
-* 							isValid = false;
-* 							alert(
-* 								wp.i18n.__(
-* 									`${name}は${range.min}から${range.max}の間で設定してください`,
-* 									"easy-slug-protect"
-* 								)
-* 							);
-* 							$input.focus();
-* 							return false;
-* 						}
-* 					});
-*
-* 					if (!isValid) {
-* 						e.preventDefault();
-* 						return false;
-* 					}
-*
-* 					// 既存のパスが変更されていないかチェック
-* 					let hasPathModification = false;
-* 					$(".esp-locked-input").each(function () {
-* 						const $input = $(this);
-* 						if ($input.data("original-value") !== $input.val()) {
-* 							hasPathModification = true;
-* 							$input.addClass("esp-error-input");
-* 						}
-* 					});
-*
-* 					if (hasPathModification) {
-* 						alert(espAdminData.i18n.alertCantChengePath);
-* 						e.preventDefault();
-* 						return false;
-* 					}
-*
 * 					// パスの重複チェック
 * 					const paths = new Map(); // パスと入力要素の対応を保存
 * 					const loginPages = new Map(); // ログインページと選択要素の対応を保存
@@ -265,7 +224,7 @@
 * 					// パスの重複チェック
 * 					let hasPathDuplicate = false;
 * 					$(".esp-path-input").each(function () {
-* 						let $input = $(this);
+* 					$('input[name*="[path]"]').each(function () { // name属性で取得
 * 						let path = $input.val().trim();
 *
 * 						// パスの形式を正規化
@@ -281,7 +240,7 @@
 * 						if (paths.has(path)) {
 * 							hasPathDuplicate = true;
 * 							isValid = false;
-* 							const firstInput = paths.get(path);
+* 							paths.get(path).addClass("esp-error-input"); // 最初に見つかった要素にもエラークラス
 * 							firstInput.addClass("esp-error-input");
 * 							$input.addClass("esp-error-input");
 * 						}
@@ -296,13 +255,13 @@
 *
 * 					// ログインページの重複チェック
 * 					let hasLoginPageDuplicate = false;
-* 					$('.esp-path-content select[name*="[login_page]"]').each(function () {
+* 					$('select[name*="[login_page]"]').each(function () { // name属性で取得
 * 						const $select = $(this);
 * 						const loginPage = $select.val();
 * 						if (loginPage && loginPages.has(loginPage)) {
 * 							hasLoginPageDuplicate = true;
 * 							isValid = false;
-* 							const firstSelect = loginPages.get(loginPage);
+* 							loginPages.get(loginPage).addClass("esp-error-input"); // 最初に見つかった要素にもエラークラス
 * 							firstSelect.addClass("esp-error-input");
 * 							$select.addClass("esp-error-input");
 * 						}

```

---

**4. サニタイズ処理の修正**

設定保存時のサニタイズ処理で ID を扱い、新規パスには ID を生成し、既存パスの ID は維持するようにします。

```diff
--- a/admin/classes/class-esp-admin-sanitize.php
+++ b/admin/classes/class-esp-admin-sanitize.php
@@ -28,12 +28,15 @@
 27 |         $existing_paths = ESP_Option::get_current_setting('path');
 28 |         $unique_paths = array(); // 重複チェック用
 29 |         $login_pages = array();  // login_pageの重複チェック用
- 30 |         $raw_passwords = array(); // 平文パスワード一時保存用
+ 30 |         // $raw_passwords = array(); // 平文パスワード一時保存用 (IDをキーにする)
+ 31 |         $raw_passwords_by_id = array(); // IDをキーにした平文パスワード
 31 |
- 32 |         foreach ($paths as $path) {
- 33 |             if (empty($path['path']) || empty($path['login_page'])) {
+ 32 |         // $paths は [id => [id, path, password, login_page]] の形式で来る想定
+ 33 |         foreach ($paths as $path_id => $path_data) {
+ 34 |             // id が 'new_' で始まる場合は仮IDなので無視するか、ここで正式IDに置換する
+ 35 |             // 必須チェック
+ 36 |             if (empty($path_data['path']) || empty($path_data['login_page'])) {
 34 |                 continue;
 35 |             }
 36 |
 37 |             // パスの正規化と重複チェック
- 38 |             $normalized_path = '/' . trim(sanitize_text_field($path['path']), '/') . '/';
+ 38 |             $normalized_path = '/' . trim(sanitize_text_field($path_data['path']), '/') . '/';
 39 |             if (in_array($normalized_path, $unique_paths, true)) {
 40 |                 continue;
 41 |             }
@@ -42,7 +45,7 @@
 43 |
 44 |             // ログインページの重複チェック
- 45 |             $login_page_id = absint($path['login_page']);
+ 45 |             $login_page_id = absint($path_data['login_page']);
 46 |             if (in_array($login_page_id, $login_pages, true)) {
 47 |                 add_settings_error(
 48 |                     'esp_protected_paths',
@@ -58,32 +61,42 @@
 57 |             $login_pages[] = $login_page_id;
 58 |
 59 |             // パスワードの処理
- 60 |             $hashed_password = '';
- 61 |
- 62 |             if (!empty($path['password'])) {
- 63 |                 if ($this->is_hashed_password($path['password'])) {
+ 60 |             $hashed_password = ''; // 初期化
+ 61 |             $current_raw_password = null; // 平文パスワード保持用
+ 62 |
+ 63 |             // IDの処理 (新規か既存か)
+ 64 |             $id = $path_data['id'] ?? null;
+ 65 |             $is_new = false;
+ 66 |             if (empty($id) || strpos($id, 'new_') === 0) {
+ 67 |                 $id = uniqid('esp_path_', true); // 新しいIDを生成
+ 68 |                 $is_new = true;
+ 69 |             }
+ 70 |
+ 71 |             if (!empty($path_data['password'])) {
+ 72 |                 if ($this->is_hashed_password($path_data['password'])) {
 64 |                     // 既にハッシュ化済みの場合はそのまま使用
- 65 |                     $hashed_password = $path['password'];
+ 65 |                     // (通常はフロントからハッシュ化済みパスワードは送られないはずだが念のため)
+ 66 |                     $hashed_password = $path_data['password'];
 67 |                 } else {
 68 |                     // 新しいパスワードの場合のみハッシュ化
- 69 |                     $raw_passwords[$normalized_path] = $path['password'];
- 70 |                     $hashed_password = wp_hash_password($path['password']);
- 71 |                 }
- 72 |             } else {
- 73 |                 // 既存のパスワードを維持
- 74 |                 foreach ($existing_paths as $existing) {
- 75 |                     if ($existing['path'] === $normalized_path && !empty($existing['password'])) {
+ 69 |                     $current_raw_password = $path_data['password']; // 平文を保持
+ 70 |                     $raw_passwords_by_id[$id] = $current_raw_password; // IDをキーに保存
+ 71 |                     $hashed_password = wp_hash_password($current_raw_password);
+ 72 |                 }
+ 73 |             } else {
+ 74 |                 // パスワード入力がない場合、既存のパスワードを維持 (IDで検索)
+ 75 |                 foreach ($existing_paths as $existing_path_data) {
+ 76 |                     if (isset($existing_path_data['id']) && $existing_path_data['id'] === $id && !empty($existing_path_data['password'])) {
 76 |                         $hashed_password = $existing['password'];
- 77 |                         break;
- 78 |                     }
+ 77 |                         $hashed_password = $existing_path_data['password'];
+ 78 |                         break; // 見つかったらループ終了
 79 |                     }
 80 |                 }
 81 |             }
 82 |
 83 |             // パスワードが設定されていない場合はスキップ
- 84 |             if (empty($hashed_password)) {
+ 85 |             // 新規追加時でパスワードが空の場合はエラーとするか、デフォルトパスワードを設定するか検討 -> ここでは必須とする
+ 86 |             if (empty($hashed_password) && $is_new) {
 83 |                 continue;
 84 |             }
 85 |
 86 |             // サニタイズされたパス情報を準備
 87 |             $sanitized_path = array(
- 88 |                 'path' => $normalized_path,
- 89 |                 'login_page' => $login_page_id,
- 90 |                 'password' => $hashed_password
+ 88 |                 'id'         => $id, // IDを含める
+ 89 |                 'path'       => $normalized_path,
+ 90 |                 'login_page' => $login_page_id,
+ 91 |                 'password'   => $hashed_password
 91 |             );
 92 |
 93 |             $sanitized[] = $sanitized_path;
 94 |         }
 95 |
 96 |         // 平文パスワードを一時的に保存（メール通知用）
- 97 |         if (!empty($raw_passwords)) {
- 98 |             set_transient('esp_raw_passwords', $raw_passwords, 30);
+ 97 |         if (!empty($raw_passwords_by_id)) {
+ 98 |             set_transient('esp_raw_passwords', $raw_passwords_by_id, 30); // IDをキーにしたものを保存
 99 |         }
 100 |
 101 |         return $sanitized;

```

---

**5. 設定更新ハンドラの修正**

設定が更新された際の処理（メール通知など）を ID ベースで行うように変更します。

```diff
--- a/admin/classes/class-esp-admin-setting.php
+++ b/admin/classes/class-esp-admin-setting.php
@@ -88,7 +88,7 @@
  *         // パス設定のサニタイズ
  *         $sanitized['path'] = $this->sanitize->sanitize_protected_paths(
  *             isset($input['path']) ? $input['path'] : ESP_Option::get_current_setting('path')
- 90 |         );
+ 90 |         ); // sanitize_protected_paths が ID を含む配列を返すように修正済み
  *         // error_log('esp: '.  json_encode($sanitized));
  *
  *         // ブルートフォース設定のサニタイズ
@@ -139,48 +139,50 @@
 138 |         }
 139 |
 140 |         $old_paths_map = array();
- 141 |         foreach ($old_value as $old_path) {
- 142 |             if (isset($old_path['path'])) {
- 143 |                 $old_paths_map[$old_path['path']] = $old_path;
- 144 |             }
+ 141 |         // 古い設定をIDをキーにしたマップに変換
+ 142 |         foreach ($old_value as $old_path_data) {
+ 143 |             if (isset($old_path_data['id'])) {
+ 144 |                 $old_paths_map[$old_path_data['id']] = $old_path_data;
+ 145 |             }
 145 |         }
 146 |
 147 |         // メール通知用の一時データを取得 (IDがキーになっている想定)
- 148 |         $raw_passwords = get_transient('esp_raw_passwords');
+ 148 |         $raw_passwords_by_id = get_transient('esp_raw_passwords');
 149 |         delete_transient('esp_raw_passwords'); // 取得後削除
 150 |
- 151 |         if (!is_array($raw_passwords)) {
- 152 |             $raw_passwords = array();
+ 151 |         if (!is_array($raw_passwords_by_id)) {
+ 152 |             $raw_passwords_by_id = array();
 153 |         }
 154 |
- 155 |         $current_paths = array();
+ 155 |         $current_path_ids = array();
 156 |         // 新規追加と更新の処理
- 157 |         foreach ($new_value as $new_path) {
- 158 |
- 159 |             if (!isset($new_path['path'])) {
+ 157 |         // $new_value はサニタイズ後の ID を含む配列
+ 158 |         foreach ($new_value as $new_path_data) {
+ 159 |
+ 160 |             // ID がなければスキップ（ありえないはずだが念のため）
+ 161 |             if (!isset($new_path_data['id'])) {
 160 |                 continue;
 161 |             }
 162 |
- 163 |             $current_paths[] = $new_path['path'];
- 164 |             $path_key = $new_path['path'];
+ 163 |             $id = $new_path_data['id'];
+ 164 |             $current_path_ids[] = $id; // 現在存在するIDを記録
+ 165 |             $path_string = $new_path_data['path']; // 通知用のパス文字列
 165 |
 166 |             // 平文パスワードの取得
- 167 |             if (!isset($raw_passwords[$path_key])) {
+ 167 |             // IDをキーにして平文パスワードを取得
+ 168 |             $raw_password = $raw_passwords_by_id[$id] ?? null;
+ 169 |             if ($raw_password === null) {
 168 |                 continue; // パスワード変更がない場合はスキップ
 169 |             }
 170 |
- 171 |             $raw_password = $raw_passwords[$path_key];
- 172 |
 173 |             // 既存のパスかチェック
- 174 |             if (isset($old_paths_map[$path_key])) {
+ 174 |             if (isset($old_paths_map[$id])) { // IDで既存かチェック
 175 |                 // パスワードが変更された場合のみ通知
 176 |                 $this->mail->notify_password_change(
- 177 |                     $path_key,
+ 177 |                     $path_string, // 通知にはパス文字列を使用
 178 |                     $raw_password
 179 |                 );
+ 180 |                 unset($old_paths_map[$id]); // 処理済みとしてマップから削除
 180 |             } else {
 181 |                 // 新規パスの処理
 182 |                 $this->mail->notify_new_protected_path(
- 183 |                     $path_key,
+ 183 |                     $path_string, // 通知にはパス文字列を使用
 184 |                     $raw_password
 185 |                 );
 186 |             }
 187 |         }
 188 |
- 189 |         // 削除されたパスの検出と通知
- 190 |         foreach ($old_paths_map as $path => $old_path) {
- 191 |             if (!in_array($path, $current_paths, true)) {
- 192 |                 $this->mail->notify_path_removed($path);
- 193 |             }
+ 189 |         // 削除されたパスの通知 ($old_paths_map に残っているものが削除されたもの)
+ 190 |         foreach ($old_paths_map as $id => $old_path_data) {
+ 191 |             $this->mail->notify_path_removed($old_path_data['path']); // 通知にはパス文字列を使用
 194 |         }
 195 |
 196 |         // 保護ページリストの更新

```

---

**6. フィルタークラスの修正**

`ESP_Option::get_current_setting('path')` の返り値の構造が変わるため、パス文字列へのアクセス方法を修正します。

```diff
--- a/includes/class-esp-filter.php
+++ b/includes/class-esp-filter.php
@@ -157,8 +157,8 @@
 156 |
 157 |         foreach ($protected_paths as $path_setting) {
 158 |             $path = $path_setting['path'];
- 159 |             $protected_path = '/' . trim($path, '/') . '/';
+ 159 |             $path_string = $path_setting['path']; // パス文字列を取得
+ 160 |             $protected_path = '/' . trim($path_string, '/') . '/';
 160 |             $post_ids = [];
 161 |
 162 |             foreach ($all_posts as $post_id) {

```

---

**7. ログアウトクラスの修正**

`ESP_Option::get_current_setting('path')` の返り値の構造が変わるため、パス文字列へのアクセス方法を修正します。

```diff
--- a/includes/class-esp-logout.php
+++ b/includes/class-esp-logout.php
@@ -82,7 +82,7 @@
 81 |         $protected_paths = ESP_Option::get_current_setting('path');
 82 |
 83 |         foreach ($protected_paths as $path) {
- 84 |             if ($auth->is_logged_in($path['path'])) {
+ 84 |             if ($auth->is_logged_in($path['path'])) { // パス文字列でチェック
 85 |                 return true;
 86 |             }
 87 |         }
@@ -144,7 +144,7 @@
 142 |     private function logout_from_all_paths() {
 143 |         $protected_paths = ESP_Option::get_current_setting('path');
 144 |         foreach ($protected_paths as $path) {
- 145 |             $this->logout_from_path($path['path']);
+ 145 |             $this->logout_from_path($path['path']); // パス文字列でログアウト
 146 |         }
 147 |     }
 148 |
@@ -155,7 +155,7 @@
 154 |         $protected_paths = ESP_Option::get_current_setting('path');
 155 |         foreach ($protected_paths as $path) {
 156 |             $this->cookie->clear_session_cookie($path['path']);
- 157 |         }
+ 157 |             $this->cookie->clear_remember_cookies_for_path($path['path']); // rememberもパスごとにクリア
+ 158 |         }
 158 |
 159 |         // ログイン保持Cookieのクリア
 160 |         $this->cookie->clear_remember_cookies();

```

---

**8. readme.md の更新**

ファイル構造の説明を現状に合わせます（任意）。

```diff
--- a/readme.md
+++ b/readme.md
@@ -1,6 +1,6 @@
 1 | # 任意階層パスワードプラグイン
 2 |
- 3 | 名称: Easy Slug Protect
+ 3 | 名称: Easy Slug Protect (with ID management)
 4 | 略称: esp
 5 |
 6 | ## タスク

```

---

**変更点の要約:**

1.  **ID 導入**:
    - `esp_settings` オプションの `path` 配列の各要素に `id` フィールドを追加。
    - 管理画面の HTML (`name` 属性、`data-id`) および JavaScript で ID を使用するように変更。
    - 設定保存時のサニタイズ処理 (`ESP_Admin_Sanitize`) で ID を生成・維持。
    - 設定更新ハンドラ (`ESP_Admin_Setting`) で ID を基準に変更を検出し、通知処理を行うように変更。
2.  **アップデート処理**:
    - `easy-slug-protect.php` に `esp_update_db_check` 関数を追加し、`admin_init` フックで実行。
    - 既存の設定データに ID がない場合に自動で付与する処理を追加。
    - プラグインバージョンを `0.4.0` に更新。
3.  **既存機能への影響**:
    - フロントエンドの認証・ログアウト・フィルタリング処理は、引き続き URL パス文字列 (`path`) を基準に行うため、ID 導入による直接的な影響は少ない。ただし、オプションからパス文字列を取得する箇所 (`$path['path']`) を修正。

これらの変更により、保護パスの管理が ID ベースで行われるようになり、将来的な機能拡張（親子関係など）に対応しやすくなります。また、アップデート処理により、既存ユーザーのデータ構造も新しい形式に移行されます。
