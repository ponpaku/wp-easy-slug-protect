(function ($) {
	"use strict";

	// DOM読み込み完了時の処理
	$(document).ready(function () {
		const ESP_Admin = {
			// 初期化
			init: function () {
				this.pathCount = $(".esp-path-item").length;
				this.bindEvents();
				this.setupFormValidation();
				this.setupUnsavedChangesWarning();
				this.lockExistingPaths();
			},

			// イベントのバインド
			bindEvents: function () {
				$("#esp-add-path").on("click", this.addNewPath.bind(this));
				$(document).on("click", ".esp-remove-path", this.removePath.bind(this));
				this.setupPasswordToggle();
				this.setupMailNotifications();
			},

			/**
			 * 新しい保護パスの追加
			 * @param {Event} e イベントオブジェクト
			 */
			addNewPath: function (e) {
				e.preventDefault();
				const template = `
					<div class="esp-path-item" style="display: none;">
						<div class="esp-path-header">
							<h3>${espAdminData.i18n.newProtectedPath}</h3>
							<button type="button" class="button esp-remove-path">
								${espAdminData.i18n.delete}
							</button>
						</div>
						<div class="esp-path-content">
							<p>
								<label>${espAdminData.i18n.path}</label>
								<input type="text" 
									name="${espAdminData.optionKey}[path][${this.pathCount}][path]" 
									class="esp-path-input regular-text"
									placeholder="/example/"
									required>
								<span class="description">
									${wp.i18n.__("例: /members/ または /private/docs/", "easy-slug-protect")}
								</span>
							</p>
							<p>
								<label>${espAdminData.i18n.password}</label>
								<div class="esp-password-field">
									<input type="password" 
										name="${espAdminData.optionKey}[path][${this.pathCount}][password]" 
										class="regular-text"
										required>
									<button type="button" class="button esp-toggle-password">
										${espAdminData.i18n.show}
									</button>
								</div>
							</p>
							<p>
								<label>${espAdminData.i18n.loginPage}</label>
								${this.getPageSelectHTML(this.pathCount)}
								<span class="description">
									${espAdminData.i18n.shortcodeNotice}
								</span>
							</p>
						</div>
					</div>
				`;

				const $newPath = $(template);
				$("#esp-paths-container").append($newPath);
				$newPath.slideDown(300);
				this.pathCount++;
				this.markFormAsUnsaved();
			},

			/**
			 * ページ選択のセレクトボックスHTML生成
			 * @param {number} index インデックス
			 * @returns {string} セレクトボックスのHTML
			 */
			getPageSelectHTML: function (index) {
				return `<select name="${espAdminData.optionKey}[path][${index}][login_page]" required>
					<option value="">${espAdminData.i18n.selectPage}</option>
					${espAdminData.pages_list}
				</select>`;
			},
			/**
			 * 既存のパスを変更できないようにする
			 */
			lockExistingPaths: function () {
				$(".esp-path-item").each(function () {
					const $pathInput = $(this).find('input[data-input-lock="true"]');
					const currentValue = $pathInput.val();
					if ($pathInput.val()) {
						// パス入力フィールドを読み取り専用に
						$pathInput
							.prop("readonly", true)
							.addClass("esp-locked-input")
							.attr("data-original-value", currentValue);
					}
				});
			},

			/**
			 * 保護パスの削除
			 * @param {Event} e イベントオブジェクト
			 */
			removePath: function (e) {
				e.preventDefault();
				const $pathItem = $(e.target).closest(".esp-path-item");

				// 確認ダイアログ
				if (confirm(espAdminData.i18n.confirmDelete)) {
					$pathItem.addClass("removing").slideUp(300, function () {
						$(this).remove();
					});
					this.markFormAsUnsaved();
				}
			},

			/**
			 * パスワード表示切り替えの設定
			 */
			setupPasswordToggle: function () {
				$(document).on("click", ".esp-toggle-password", function (e) {
					const $button = $(this);
					const $input = $button.siblings("input");

					// パスワード表示トグル
					if ($input.attr("type") === "password") {
						$input.attr("type", "text");
						$button.text(espAdminData.i18n.hide);
					} else {
						$input.attr("type", "password");
						$button.text(espAdminData.i18n.show);
					}
				});
			},

			/**
			 * メール通知設定の制御
			 * 通知項目の有効/無効を切り替える
			 */
			setupMailNotifications: function () {
				const $enableNotifications = $("#esp-enable-notifications");
				const $notificationItems = $(".esp-notification-items");

				function toggleNotificationOptions() {
					if ($enableNotifications.is(":checked")) {
						// 通知が有効な場合、各通知項目を有効化
						$notificationItems
							.removeClass("esp-notifications-disabled")
							.find('input[type="checkbox"]')
							.prop("disabled", false);
					} else {
						// 通知が無効な場合、各通知項目を無効化
						$notificationItems
							.addClass("esp-notifications-disabled")
							.find('input[type="checkbox"]')
							.prop("disabled", true);
					}
				}

				// 通知設定変更時の処理
				$enableNotifications.on("change", function () {
					toggleNotificationOptions();
					ESP_Admin.markFormAsUnsaved();
				});

				// 初期状態の設定
				toggleNotificationOptions();
			},

			/**
			 * フォームバリデーションの設定
			 */
			setupFormValidation: function () {
				$("#esp-settings-form").on("submit", function (e) {
					const $form = $(this);
					let isValid = true;

					// HTML5バリデーション
					if (!this.checkValidity()) {
						e.preventDefault();
						return false;
					}

					// 数値の範囲チェック
					const numericalInputs = {
						attempts_threshold: { min: 1, max: 100 },
						time_frame: { min: 1, max: 1440 },
						block_time_frame: { min: 1, max: 10080 },
						remember_time: { min: 1, max: 365 },
					};

					$.each(numericalInputs, function (name, range) {
						const $input = $form.find(`[name*="${name}"]`);
						const value = parseInt($input.val(), 10);

						if (value < range.min || value > range.max) {
							isValid = false;
							alert(
								wp.i18n.__(
									`${name}は${range.min}から${range.max}の間で設定してください`,
									"easy-slug-protect"
								)
							);
							$input.focus();
							return false;
						}
					});

					if (!isValid) {
						e.preventDefault();
						return false;
					}

					// 既存のパスが変更されていないかチェック
					let hasPathModification = false;
					$(".esp-locked-input").each(function () {
						const $input = $(this);
						if ($input.data("original-value") !== $input.val()) {
							hasPathModification = true;
							$input.addClass("esp-error-input");
						}
					});

					if (hasPathModification) {
						alert(espAdminData.i18n.alertCantChengePath);
						e.preventDefault();
						return false;
					}

					// パスの重複チェック
					const paths = new Map(); // パスと入力要素の対応を保存
					const loginPages = new Map(); // ログインページと選択要素の対応を保存

					// パスの重複チェック
					let hasPathDuplicate = false;
					$(".esp-path-input").each(function () {
						let $input = $(this);
						let path = $input.val().trim();

						// パスの形式を正規化
						if (path && !path.startsWith("/")) {
							path = "/" + path;
						}
						if (path && !path.endsWith("/")) {
							path += "/";
						}
						$input.val(path);

						if (paths.has(path)) {
							hasPathDuplicate = true;
							isValid = false;
							const firstInput = paths.get(path);
							firstInput.addClass("esp-error-input");
							$input.addClass("esp-error-input");
						}
						paths.set(path, $input);
					});

					if (hasPathDuplicate) {
						alert(espAdminData.i18n.alertDuplicatePath);
						e.preventDefault();
						return false;
					}

					// ログインページの重複チェック
					let hasLoginPageDuplicate = false;
					$('.esp-path-content select[name*="[login_page]"]').each(function () {
						const $select = $(this);
						const loginPage = $select.val();
						if (loginPage && loginPages.has(loginPage)) {
							hasLoginPageDuplicate = true;
							isValid = false;
							const firstSelect = loginPages.get(loginPage);
							firstSelect.addClass("esp-error-input");
							$select.addClass("esp-error-input");
						}
						loginPages.set(loginPage, $select);
					});

					if (hasLoginPageDuplicate) {
						alert(espAdminData.i18n.alertDuplicateLoginPage);
						e.preventDefault();
						return false;
					}

					// エラー表示のクリア
					$(document).on("input change", ".esp-error-input", function () {
						$(this).removeClass("esp-error-input");
					});

					if (!isValid) {
						e.preventDefault();
						return false;
					}

					// 保存前の確認
					if (!confirm(espAdminData.i18n.confirmSave)) {
						e.preventDefault();
						return false;
					}
				});
			},

			/**
			 * 未保存の変更がある場合の警告設定
			 */
			setupUnsavedChangesWarning: function () {
				let hasUnsavedChanges = false;

				// フォームの変更を検知
				$("#esp-settings-form").on("change", "input, select", function () {
					hasUnsavedChanges = true;
					$(".esp-unsaved").slideDown(300);
				});

				// フォーム送信時にフラグをリセット
				$("#esp-settings-form").on("submit", function () {
					hasUnsavedChanges = false;
					$(".esp-unsaved").slideUp(300);
				});

				// ページ離脱時の警告
				$(window).on("beforeunload", function () {
					if (hasUnsavedChanges) {
						return espAdminData.i18n.unsavedChanges;
					}
				});
			},

			/**
			 * フォームを未保存状態としてマーク
			 */
			markFormAsUnsaved: function () {
				$("#esp-settings-form").trigger("change");
			},
		};

		// 初期化実行
		ESP_Admin.init();
	});
})(jQuery);
