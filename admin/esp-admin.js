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
				// メディアキャッシュクリアボタンを追加
                                $("#esp-clear-media-cache").on(
                                        "click",
                                        this.handleClearMediaCache.bind(this)
                                );
                                $("#esp-reset-htaccess-rules").on(
                                        "click",
                                        this.handleResetHtaccessRules.bind(this)
                                );
			},

			/**
			 * 新しい保護パスの追加
			 * @param {Event} e イベントオブジェクト
			 */
			addNewPath: function (e) {
				e.preventDefault();
				const pathId = "path_" + Date.now();
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
                                例: /members/ または /private/docs/
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
                </div>`;

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
				return `<select name="${espAdminData.optionKey}[path][${pathId}][login_page]" required>
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
								`${name}は${range.min}から${range.max}の間で設定してください`
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
				$("#esp-settings-form").trigger("change");
			},

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
					.removeClass("notice-success notice-error notice-warning");
				$progressBarContainer.show();
				$statusBar
					.css("width", "0%")
					.text("0%")
					.removeClass("green yellow red");

				let offset = 0;
				const limit = 50;
				let totalPosts = 0;
				let G_i18n = espAdminData.i18n;

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
									totalPosts = parseInt(response.data.total, 10);
								}

								if (totalPosts > 0) {
									let currentProcessed = response.data.offset || offset;
									if (response.data.status === "completed") {
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
									setTimeout(processBatch, 300);
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
								}
							} else {
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
				processBatch();
			},

			/**
			 * 「保護キャッシュクリア」ボタンのハンドラ
			 */
			handleClearProtectionCache: function () {
				const $button = $("#esp-clear-protection-cache");
				const $statusContainer = $("#esp-clear-cache-status");
				let G_i18n = espAdminData.i18n;

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

			/**
			 * 「メディア保護キャッシュクリア」ボタンのハンドラ（新規追加）
			 */
                        handleClearMediaCache: function () {
                                const $button = $("#esp-clear-media-cache");
                                const $statusContainer = $("#esp-clear-media-cache-status");
                                let G_i18n = espAdminData.i18n;

				if ($button.prop("disabled")) {
					return;
				}

				const confirmClear = confirm(
					G_i18n.confirmClearMediaCache ||
						"メディア保護キャッシュをクリアします。よろしいですか？"
				);
				if (!confirmClear) return;

				$button
					.prop("disabled", true)
					.text(G_i18n.clearingMediaCache || "メディアキャッシュをクリア中...");
				$statusContainer
					.html(
						'<span class="spinner is-active" style="float: none; margin: 0;"></span>'
					)
					.removeClass("notice-success notice-error notice-warning");

				$.ajax({
					url: ajaxurl,
					type: "POST",
					data: {
						action: "esp_clear_media_cache",
						nonce: espAdminData.clearMediaCacheNonce,
					},
					success: function (response) {
						$button
							.prop("disabled", false)
							.text(
								G_i18n.clearMediaCacheButton ||
									"メディア保護キャッシュをクリアする"
							);
						if (response.success) {
							$statusContainer.html(
								'<div class="notice notice-success inline" style="margin: 5px 0; padding: 10px;">' +
									"<p>" +
									response.data.message +
									"</p>" +
									"</div>"
							);
							// 3秒後にメッセージを消す
							setTimeout(function () {
								$statusContainer.fadeOut("slow", function () {
									$statusContainer.empty().show();
								});
							}, 3000);
						} else {
							const errorMessage =
								response.data && response.data.message
									? response.data.message
									: G_i18n.clearMediaCacheError ||
									  "メディアキャッシュのクリア中にエラーが発生しました。";
							$statusContainer.html(
								'<div class="notice notice-error inline" style="margin: 5px 0; padding: 10px;">' +
									"<p>" +
									errorMessage +
									"</p>" +
									"</div>"
							);
						}
					},
					error: function (jqXHR, textStatus, errorThrown) {
						$button
							.prop("disabled", false)
							.text(
								G_i18n.clearMediaCacheButton ||
									"メディア保護キャッシュをクリアする"
							);
						console.error("Media cache clear error:", textStatus, errorThrown);
						$statusContainer.html(
							'<div class="notice notice-error inline" style="margin: 5px 0; padding: 10px;">' +
								"<p>" +
								(G_i18n.ajaxError || "AJAXリクエストに失敗しました: ") +
								textStatus +
								"</p>" +
								"</div>"
						);
					},
                                });
                        },

                        /**
                         * 「.htaccessルール再設定」ボタンのハンドラ
                         */
                        handleResetHtaccessRules: function () {
                                const $button = $("#esp-reset-htaccess-rules");
                                const $statusContainer = $("#esp-reset-htaccess-status");
                                const i18n = espAdminData.i18n;

                                if ($button.prop("disabled")) {
                                        return;
                                }

                                const confirmReset = window.confirm(
                                        i18n.confirmResetHtaccess ||
                                                ".htaccessルールを再設定します。よろしいですか？"
                                );
                                if (!confirmReset) return;

                                const defaultLabel = i18n.resetHtaccessButton || ".htaccessのルールを再設定する";
                                const renderNotice = function (type, message) {
                                        // 管理画面通知スタイルを活用
                                        $statusContainer.html(
                                                '<div class="notice ' +
                                                        type +
                                                        ' inline" style="margin: 5px 0; padding: 10px;">' +
                                                        "<p>" +
                                                        message +
                                                        "</p>" +
                                                        "</div>"
                                        );
                                };

                                // スピナー表示で処理中を明示
                                $button.prop("disabled", true).text(i18n.resettingHtaccess || "再設定中...");
                                $statusContainer
                                        .html(
                                                '<span class="spinner is-active" style="float: none; margin: 0;"></span>'
                                        )
                                        .removeClass("notice-success notice-error notice-warning");

                                $.ajax({
                                        url: ajaxurl,
                                        type: "POST",
                                        data: {
                                                action: "esp_reset_htaccess_rules",
                                                nonce: espAdminData.resetHtaccessNonce,
                                        },
                                        success: function (response) {
                                                $button.prop("disabled", false).text(defaultLabel);

                                                if (response.success) {
                                                        renderNotice(
                                                                "notice-success",
                                                                response.data && response.data.message
                                                                        ? response.data.message
                                                                        : i18n.resetHtaccessSuccess ||
                                                                                  ".htaccessのルールを再設定しました。"
                                                        );

                                                        setTimeout(function () {
                                                                $statusContainer.fadeOut("slow", function () {
                                                                        $statusContainer.empty().show();
                                                                });
                                                        }, 3000);
                                                        return;
                                                }

                                                const errorMessage =
                                                        response.data && response.data.message
                                                                ? response.data.message
                                                                : i18n.resetHtaccessError ||
                                                                  ".htaccessの再設定中にエラーが発生しました。";
                                                renderNotice("notice-error", errorMessage);
                                        },
                                        error: function (jqXHR, textStatus, errorThrown) {
                                                $button.prop("disabled", false).text(defaultLabel);
                                                console.error(
                                                        ".htaccess reset error:",
                                                        textStatus,
                                                        errorThrown
                                                );
                                                renderNotice(
                                                        "notice-error",
                                                        (i18n.ajaxError || "AJAXリクエストに失敗しました: ") + textStatus
                                                );
                                        },
                                });
                        },
                }; // ESP_Admin オブジェクトここまで

		// 初期化実行
		ESP_Admin.init();
	});
})(jQuery);
