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
				// 新しいツールボタン用のイベントも初期化時にバインド
				this.bindToolButtons();
			},

			// イベントのバインド
			bindEvents: function () {
				$("#esp-add-path").on("click", this.addNewPath.bind(this));
				$(document).on("click", ".esp-remove-path", this.removePath.bind(this));
				this.setupPasswordToggle();
				this.setupMailNotifications();
			},

			// ツールボタン用のイベントバインド
			bindToolButtons: function () {
				$("#esp-regenerate-permalink-paths").on(
					"click",
					this.handleRegeneratePermalinks.bind(this)
				);
				$("#esp-clear-protection-cache").on(
					"click",
					this.handleClearProtectionCache.bind(this)
				);
			},

			/**
			 * 新しい保護パスの追加
			 * @param {Event} e イベントオブジェクト
			 */
			addNewPath: function (e) {
				e.preventDefault();
				// 既存の addNewPath のコードはそのまま
				const pathId = "new";
				const template = `
                <div class="esp-path-item" style="display: none;" data-path-id="${pathId}">
                    <div class="esp-path-header">
                        <h3>${espAdminData.i18n.newProtectedPath}</h3>
                        <button type="button" class="button esp-remove-path">
                            ${espAdminData.i18n.delete}
                        </button>
                    </div>
                    <div class="esp-path-content">
                        <input type="hidden" 
                            name="${
															espAdminData.optionKey
														}[path][${pathId}][id]" 
                            value="${pathId}">
                        <p>
                            <label>${espAdminData.i18n.path}</label>
                            <input type="text" 
                                name="${
																	espAdminData.optionKey
																}[path][${pathId}][path]" 
                                class="esp-path-input regular-text"
                                placeholder="/example/"
                                required>
                            <span class="description">
                                ${wp.i18n.__(
																	"例: /members/ または /private/docs/",
																	"easy-slug-protect"
																)}
                            </span>
                        </p>
                        <p>
                            <label>${espAdminData.i18n.password}</label>
                            <div class="esp-password-field">
                                <input type="password" 
                                    name="${
																			espAdminData.optionKey
																		}[path][${pathId}][password]" 
                                    class="regular-text"
                                    required>
                                <button type="button" class="button esp-toggle-password">
                                    ${espAdminData.i18n.show}
                                </button>
                            </div>
                        </p>
                        <p>
                            <label>${espAdminData.i18n.loginPage}</label>
                            ${this.getPageSelectHTML(pathId)}
                            <span class="description">
                                ${espAdminData.i18n.shortcodeNotice}
                            </span>
                        </p>
                    </div>
                </div>`; // 閉じタグ修正

				const $newPath = $(template);
				$("#esp-paths-container").append($newPath);
				$newPath.slideDown(300);
				this.markFormAsUnsaved();
			},

			/**
			 * ページ選択のセレクトボックスHTML生成
			 * @param {string} pathId パスID
			 * @returns {string} セレクトボックスのHTML
			 */
			getPageSelectHTML: function (pathId) {
				// 既存の getPageSelectHTML のコードはそのまま
				return `<select name="${espAdminData.optionKey}[path][${pathId}][login_page]" required>
                <option value="">${espAdminData.i18n.selectPage}</option>
                ${espAdminData.pages_list}
                </select>`; // 閉じタグ修正
			},

			/**
			 * 既存のパスを変更できないようにする
			 */
			lockExistingPaths: function () {
				// 既存の lockExistingPaths のコードはそのまま
				$(".esp-path-item").each(function () {
					const $pathInput = $(this).find('input[data-input-lock="true"]');
					const currentValue = $pathInput.val();
					if ($pathInput.val()) {
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
				// 既存の removePath のコードはそのまま
				e.preventDefault();
				const $pathItem = $(e.target).closest(".esp-path-item");
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
				// 既存の setupPasswordToggle のコードはそのまま
				$(document).on("click", ".esp-toggle-password", function (e) {
					const $button = $(this);
					const $input = $button.siblings("input");
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
			 */
			setupMailNotifications: function () {
				// 既存の setupMailNotifications のコードはそのまま
				const $enableNotifications = $("#esp-enable-notifications");
				const $notificationItems = $(".esp-notification-items");
				function toggleNotificationOptions() {
					if ($enableNotifications.is(":checked")) {
						$notificationItems
							.removeClass("esp-notifications-disabled")
							.find('input[type="checkbox"]')
							.prop("disabled", false);
					} else {
						$notificationItems
							.addClass("esp-notifications-disabled")
							.find('input[type="checkbox"]')
							.prop("disabled", true);
					}
				}
				$enableNotifications.on("change", function () {
					toggleNotificationOptions();
					ESP_Admin.markFormAsUnsaved();
				});
				toggleNotificationOptions();
			},

			/**
			 * フォームバリデーションの設定
			 */
			setupFormValidation: function () {
				// 既存の setupFormValidation のコードはそのまま
				$("#esp-settings-form").on("submit", function (e) {
					const $form = $(this);
					let isValid = true;
					if (!this.checkValidity()) {
						e.preventDefault();
						return false;
					}
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
					const paths = new Map();
					const loginPages = new Map();
					let hasPathDuplicate = false;
					$(".esp-path-input").each(function () {
						let $input = $(this);
						let path = $input.val().trim();
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
					$(document).on("input change", ".esp-error-input", function () {
						$(this).removeClass("esp-error-input");
					});
					if (!isValid) {
						e.preventDefault();
						return false;
					}
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
				// 既存の setupUnsavedChangesWarning のコードはそのまま
				let hasUnsavedChanges = false;
				$("#esp-settings-form").on("change", "input, select", function () {
					hasUnsavedChanges = true;
					$(".esp-unsaved").slideDown(300);
				});
				$("#esp-settings-form").on("submit", function () {
					hasUnsavedChanges = false;
					$(".esp-unsaved").slideUp(300);
				});
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
				// 既存の markFormAsUnsaved のコードはそのまま
				$("#esp-settings-form").trigger("change");
			},

			// --- ここから新しいメソッド ---

			/**
			 * 「パーマリンクパス情報 再生成」ボタンのハンドラ
			 */
			handleRegeneratePermalinks: function () {
				const $button = $("#esp-regenerate-permalink-paths");
				const $statusBar = $("#esp-regenerate-progress-bar");
				const $statusContainer = $("#esp-regenerate-status");
				const $progressBarContainer = $(
					"#esp-regenerate-progress-bar-container"
				);

				if ($button.prop("disabled")) {
					return;
				}

				const confirmRegenerate = confirm(
					espAdminData.i18n.confirmRegeneratePermalinks ||
						"全ての投稿のパーマリンクパス情報を再生成します。投稿数が多い場合、時間がかかることがあります。よろしいですか？"
				);
				if (!confirmRegenerate) {
					return;
				}

				$button
					.prop("disabled", true)
					.text(espAdminData.i18n.regenerating || "再生成中...");
				$statusContainer
					.html("")
					.removeClass("notice-success notice-error notice-warning"); // notice-warningもクリア
				$progressBarContainer.show();
				$statusBar
					.css("width", "0%")
					.text("0%")
					.removeClass("green yellow red"); // 色クラスもリセット

				let offset = 0;
				const limit = 50; // PHP側のデフォルトと合わせるか、ここで指定
				let totalPosts = 0; // 初回レスポンスで取得予定
				let G_i18n = espAdminData.i18n; // i18n文字列へのショートカット

				function processBatch() {
					$.ajax({
						url: ajaxurl,
						type: "POST",
						data: {
							action: "esp_regenerate_permalink_paths_batch",
							nonce: espAdminData.regenerateNonce,
							offset: offset,
							limit: limit,
						},
						success: function (response) {
							if (response.success) {
								$statusContainer.html(
									'<p class="notice notice-alt notice-info inline"><span class="spinner is-active" style="float:left; margin-top:0; margin-right: 5px;"></span>' +
										response.data.message +
										"</p>"
								);

								if (response.data.total && totalPosts === 0) {
									// 初回のみtotalPostsを設定
									totalPosts = parseInt(response.data.total, 10);
								}

								if (totalPosts > 0) {
									let currentProcessed = response.data.offset || offset; // offsetは次の開始位置なので、ここまでの処理済みを使う
									if (response.data.status === "completed") {
										// 完了時はoffsetが次のバッチ開始位置ではなく総数になっている可能性
										currentProcessed = totalPosts;
									}
									const progress = Math.min(
										100,
										Math.round((currentProcessed / totalPosts) * 100)
									);
									$statusBar
										.css("width", progress + "%")
										.text(progress + "%")
										.addClass("green");
								} else if (response.data.status === "completed") {
									$statusBar
										.css("width", "100%")
										.text("100%")
										.addClass("green");
								}

								if (
									response.data.status === "processing" &&
									response.data.processed > 0
								) {
									offset = response.data.offset;
									setTimeout(processBatch, 300); // 少し間隔を空けて次のバッチを処理
								} else if (response.data.status === "completed") {
									$button
										.prop("disabled", false)
										.text(
											G_i18n.regeneratePermalinksButton ||
												"全投稿のパーマリンクパス情報を再生成する"
										);
									$statusContainer.html(
										'<p class="notice notice-success is-dismissible">' +
											response.data.message +
											"</p>"
									);
									setTimeout(function () {
										$progressBarContainer.hide();
									}, 3000);
								} else if (
									response.data.status === "processing" &&
									response.data.processed === 0 &&
									offset >= totalPosts &&
									totalPosts > 0
								) {
									// 全件処理したが、最後のバッチで処理対象が0だった場合
									$button
										.prop("disabled", false)
										.text(
											G_i18n.regeneratePermalinksButton ||
												"全投稿のパーマリンクパス情報を再生成する"
										);
									$statusContainer.html(
										'<p class="notice notice-success is-dismissible">' +
											(G_i18n.regenerateCompleteNoItems ||
												"全ての処理が完了しました。") +
											"</p>"
									);
									$statusBar
										.css("width", "100%")
										.text("100%")
										.addClass("green");
									setTimeout(function () {
										$progressBarContainer.hide();
									}, 3000);
								} else if (
									response.data.status === "processing" &&
									response.data.processed === 0 &&
									totalPosts === 0 &&
									offset === 0
								) {
									// 初回から処理対象がなかった場合
									$button
										.prop("disabled", false)
										.text(
											G_i18n.regeneratePermalinksButton ||
												"全投稿のパーマリンクパス情報を再生成する"
										);
									$statusContainer.html(
										'<p class="notice notice-warning is-dismissible">' +
											(G_i18n.regenerateCompleteNoItems ||
												"処理対象の投稿がありませんでした。") +
											"</p>"
									);
									$statusBar.css("width", "0%").text("0%");
									setTimeout(function () {
										$progressBarContainer.hide();
									}, 3000);
								}
							} else {
								// response.success が false の場合
								$button
									.prop("disabled", false)
									.text(
										G_i18n.regeneratePermalinksButton ||
											"全投稿のパーマリンクパス情報を再生成する"
									);
								const errorMessage =
									response.data && response.data.message
										? response.data.message
										: G_i18n.regenerateError || "エラーが発生しました。";
								$statusContainer.html(
									'<p class="notice notice-error is-dismissible">' +
										errorMessage +
										"</p>"
								);
								$progressBarContainer.hide();
								$statusBar.addClass("red");
							}
						},
						error: function (jqXHR, textStatus, errorThrown) {
							$button
								.prop("disabled", false)
								.text(
									G_i18n.regeneratePermalinksButton ||
										"全投稿のパーマリンクパス情報を再生成する"
								);
							$statusContainer.html(
								'<p class="notice notice-error is-dismissible">' +
									(G_i18n.ajaxError || "AJAXリクエストに失敗しました: ") +
									textStatus +
									" - " +
									errorThrown +
									"</p>"
							);
							$progressBarContainer.hide();
							$statusBar.addClass("red");
						},
					});
				}
				processBatch(); // 最初のバッチ処理を開始
			},

			/**
			 * 「保護キャッシュクリア」ボタンのハンドラ
			 */
			handleClearProtectionCache: function () {
				const $button = $("#esp-clear-protection-cache");
				const $statusContainer = $("#esp-clear-cache-status");
				let G_i18n = espAdminData.i18n; // i18n文字列へのショートカット

				if ($button.prop("disabled")) {
					return;
				}

				const confirmClear = confirm(
					G_i18n.confirmClearCache ||
						"保護キャッシュをクリアします。よろしいですか？"
				);
				if (!confirmClear) return;

				$button
					.prop("disabled", true)
					.text(G_i18n.clearingCache || "クリア中...");
				$statusContainer
					.html("")
					.removeClass("notice-success notice-error notice-warning");

				$.ajax({
					url: ajaxurl,
					type: "POST",
					data: {
						action: "esp_clear_protection_cache",
						nonce: espAdminData.clearCacheNonce,
					},
					success: function (response) {
						$button
							.prop("disabled", false)
							.text(G_i18n.clearCacheButton || "保護キャッシュをクリアする");
						if (response.success) {
							$statusContainer.html(
								'<p class="notice notice-success is-dismissible">' +
									response.data.message +
									"</p>"
							);
						} else {
							const errorMessage =
								response.data && response.data.message
									? response.data.message
									: G_i18n.clearCacheError || "エラーが発生しました。";
							$statusContainer.html(
								'<p class="notice notice-error is-dismissible">' +
									errorMessage +
									"</p>"
							);
						}
					},
					error: function (jqXHR, textStatus, errorThrown) {
						$button
							.prop("disabled", false)
							.text(G_i18n.clearCacheButton || "保護キャッシュをクリアする");
						$statusContainer.html(
							'<p class="notice notice-error is-dismissible">' +
								(G_i18n.ajaxError || "AJAXリクエストに失敗しました: ") +
								textStatus +
								" - " +
								errorThrown +
								"</p>"
						);
					},
				});
			},
		}; // ESP_Admin オブジェクトここまで

		// 初期化実行
		ESP_Admin.init();
	});
})(jQuery);
