# Issue #44: 移行・GitHub Stable Release 更新契約

## 対象と根拠

基点は main `b6315e837e50c4ed8713c5fb60f7f0ea2b87b323` (0.7.39、#49 マージ済み)。Issue 記載時の 0.7.38 に戻したり、旧 #45 を一括採用したりしない。本変更は 0.7.40 の導入基盤。Release の作成・公開、本番デプロイ、#48/#49 の未検証項目の合格宣言は含まない。

以下の WordPress 公式実装と照合した。主な実行検証は WordPress **7.1.3 / PHP 8.3.36 / MySQL 8.0.46 / Apache 2.4.68**。既存 CI の PHP 8.1/8.3/8.4 は維持する。`Update URI` フックは WordPress 5.8 以降の機能だが、5.8 全体での動作を今回認証した意味ではない。根拠なく PHP/WordPress の最低要件ヘッダーを追加・引き上げていない。

- [wp_update_plugins(): Update URI フック、version → new_version、response/no_update の振り分け](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/update.php)。同版・旧版は **package 空・稼働版 version の no_update** だけを返す。これは更新対象ではなく、標準プラグイン一覧の自動更新設定と詳細リンクを維持するメタデータ。
- [WP_Plugins_List_Table](https://github.com/WordPress/WordPress/blob/7.1.3/wp-admin/includes/class-wp-plugins-list-table.php): response/no_update による update-supported と自動更新設定表示。
- [Plugin_Upgrader](https://github.com/WordPress/WordPress/blob/7.1.3/wp-admin/includes/class-plugin-upgrader.php)、[WP_Upgrader](https://github.com/WordPress/WordPress/blob/7.1.3/wp-admin/includes/class-wp-upgrader.php): source selection、配置先、バックアップ、更新フック。ファイル置換後も実行中の PHP クラスは旧版のまま。
- [WP_Automatic_Updater](https://github.com/WordPress/WordPress/blob/7.1.3/wp-admin/includes/class-wp-automatic-updater.php): 利用者の auto_update_plugins 設定、fatal 判定後のバックアップ復元。更新情報の slug はインストール済みフォルダに合わせる必要がある。配布 ZIP のフォルダは引き続き easy-slug-protect。
- [WP_Rewrite::flush_rules()](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/class-wp-rewrite.php): wp_loaded より前の flush は遅延される。予約しただけで完了版を記録しない。
- [wpdb::query()](https://github.com/WordPress/WordPress/blob/7.1.3/wp-includes/class-wpdb.php): 切断時の自動再接続・クエリ再試行。接続ロックの確認だけでは不十分。
- [GitHub latest release API](https://docs.github.com/en/rest/releases/releases#get-the-latest-release)、[Release asset の digest](https://docs.github.com/en/rest/releases/assets#get-a-release-asset)。配布者が正しい Stable を latest に指定する。全タグの最大 SemVer を探索する仕様ではない。

## 設計と別観点からの再検証

責務は Setup (ライフサイクル、既存 Cron 窓口) → Updater (配布・DB 外の更新後処理) → Migrator (DB・保存データ)。保護状態ゲートの既存契約は #49 の ESP_Protection_State を利用し、同じ接続ロックの小さな公開インターフェースを共有する。新しい汎用基盤やメディア帰属アルゴリズムは導入しない。

設計を、要求の字面とは別に **障害モデル・旧 PHP 並走・WordPress 標準 UI/ロールバック** の観点で自己再検証した結果:

| 想定した反例 | 設計への反映 |
| --- | --- |
| 版印なしだが設定・セッションは既存 | DB 直接読取と全テーブルの実体検査。設定、テーブル、版印、保護メタ、既存 runtime をインストール証拠として判別。既知の未完 clean-install intent のみ再開可能。分類前の一時的読取失敗をインストール証拠として記録せず、旧版が残した限定的な分類前診断だけは現物の全証拠が空なら再分類可能 |
| DDL は成功、直後に PHP 停止 | 実在する列・索引を再検査して既知の不足だけ追加。DDL のトランザクションロールバックには依存しない |
| GET_LOCK 獲得後に wpdb が再接続 | DDL/行変換は獲得した mysqli 接続へ直接実行し、自動再試行させない。option 保存は INSERT SELECT の IS_USED_LOCK/CONNECTION_ID 条件でサーバー側でも所有権を検査。保存後は DB から再読込 |
| v1 固有の path_id 未導入やID付与中断 | 通常更新では検出して停止。自動採番・補完は行わず、管理画面に v1 専用の移行対応が必要と通知する |
| 同版で更新応答を一切返さない | Core が自動更新設定を非表示にするため no_update を返す。更新 package は空 |
| ZIP は標準名、導入先は別フォルダ | Core source-selection で既存フォルダへ移し、basename と active_plugins を保持。update slug も既存フォルダに合わせ、Core の自動復元と整合させる |
| Rewrite 修復失敗だけで PHP 認可まで停止 | #49 の runtime READY と配布後処理完了を分離。PHP 認可を維持し、esp_plugin_version を進めず管理者へ診断 |
| flush を予約したが保存前に終了 | wp_loaded 後に実行・保存値照合してから完了版を確定 |
| Cron 停止/重複、非重要 Cron 失敗 | 管理者 POST の再試行経路、同じ DB ロック、別の degraded 状態。非重要 Cron だけでは PHP 保護を停止しない |

これは独立した障害観点による自己レビューであり、第三者レビューや全環境の安全証明を意味しない。

## 版と移行の意味

- `ESP_VERSION`: 実行中のコード。
- `esp_db_version`: 実テーブル・設定・保護メタ参照を検証済みのスキーマ到達点。現行は 6。
- `esp_plugin_version`: その版の必須 DB 外処理まで検証できた到達点。予約・配置成功・runtime READY の代用ではない。
- `esp_migration_state`: 判別、step、status、reason、固定カーソル、進行中の確認状態。秘密の URL/パスワード/セッション値は記録・表示しない。
- `esp_update_state`: 更新後処理の pending/complete/degraded/failed と限定再試行回数。

通常更新では、現行互換DBのテーブル、列の型・NULL・auto_increment、索引、保護設定ID、保護メディア参照を検査し、認証履歴を全件走査・変更しない。DB版印が欠落または古くても、実体が現行互換なら版印を復元する。既存のID・パスワード・session/rememberトークン・password_versionは保持し、認証時にID・token・世代・有効期限の一致を確認する。削除されたパスの履歴は新しいIDへ再紐付けしない。

**v1特殊移行対象** は `path_id` 列未導入、数値キーでIDがないv1パス設定、v1のID付与中断状態などに限定する。これらは通常更新では自動変換せず、`v1_migration_required` で停止し、バックアップとv1専用の移行対応が必要なことを管理者UIで通知する。手動再試行・確認チェックでもv1変換は実行しない。すでにID列が導入されていてもv0/v1の版印と未付与remember IDが混在する場合は、`path_id` 索引による存在照会だけで診断する。過去のpath文字列から現在のIDを推測しない。

v2〜v4の既知の軽微な差分（password_version列や必要な索引の追加など）は従来どおり、検証できる場合のみ自動補修する。password_version列が欠落し、設定で非ゼロの世代が使われている場合は、対象IDのトークンの有無だけを調べ、復元不能なら`password_version_ambiguous`で停止する。不明な型、失われた基礎列、不足した必須テーブル、将来のコード/DB版は推測で修復しない。新規作成は、DB・設定・保護メタがすべて未導入と確認できた場合のみ。

通常更新でbrute/rememberの過去履歴を全件照合する処理と、v1専用の200件カーソル継続・自動変換処理は撤去する。現行互換DBの構造を確認し、必要な追加列・索引と保存済み設定のpassword_version=0補完だけを実施する。v1専用の移行ツールの提供はこのPRの対象外。現役IDの非再利用は、認証時の照合と設定UIのID発行・削除・再登録の契約で維持する必要があるため、変更経路を回帰検証する。
排他は MySQL/MariaDB の接続所有 GET_LOCK。期限で別所有者のロックを削除しない。切断時にサーバーが解放し、新実行者は自分のロックを獲得する。期限切れの古い実行者が新所有者を解除する設計ではない。固定の書込 primary を使う通常の wpdb/mysqli が前提。DB 分散・独自 db.php drop-in・フェイルオーバーは未認証。

移行中/不明/失敗は #49 の起動・認可ゲートへ接続する。通常の匿名フロントや未認証の admin-ajax.php / admin-post.php から重い移行を開始しない。`is_admin()` のみを権限判定とせず `manage_options` のある管理者、明示的な有効化、Cron、WP-CLI の経路に限定する。READY の通常リクエストで Migrator を再走査しない。旧 0.7.38 以前からの初回は、共通の協調契約が存在しないため、通常アクセス/旧 PHP ワーカー/Cron を収束させる手動メンテナンス更新が必要。

option の更新は移行専用の所有権付き SQL と読戻しで行う。通常の Settings API サニタイザーに保存済み password hash を入力して再ハッシュすることを避ける。設定の option cache は失効させ、更新完了時には ESP の投稿/メディア世代・path index だけを失効する。サイト全体の object cache flush は本番処理にない。

## 更新後処理と復旧

旧 PHP は標準アップグレーダーの開始を UPDATING、置換/復元後の shutdown を VERIFY_REQUIRED とする。新コードで Migrator が成功して初めて PHP 認可の READY を判定する。uploads Rewrite は ensure と audit の両方で確認し、WordPress のルールは wp_loaded 後の soft flush と option 実読取を照合する。無関係な root .htaccess を hard flush しない。

Rewrite 未検証時は PHP 認可を維持するが、更新完了版を進めない。既存 #49 は「保護メディア 0 件なら Rewrite 更新失敗でも PHP を使える」という戻り値を持つため、その戻り値だけを必須後処理の成功として採用しない。

Nginx/未知サーバーでメディア保護 ON の場合、外部 Rewrite の適用は PHP から検証できないため必須後処理は pending。管理画面から診断できるが、今回の自動完了の保証は Apache/LiteSpeed の生成ルール検査まで。Nginx の自動完了・外部サーバー認証フローは追加設計を要する。Apache/LiteSpeed でも構文一致は実 HTTP・CDN の秘密保持証明ではない。

非重要な日次 cleanup、整合性点検、定期キャッシュ refresh の Cron 予約失敗は degraded とし、必須処理が完了していれば esp_plugin_version は記録できる。安全な継続/予約の再試行は最大 5 回、1/2/4/8/16 分。予約が失敗した場合（`false`/`WP_Error`）は再試行回数を消費しない。管理者アクセス時のみ、`degraded/cron_unscheduled` の予約が失われた場合に単発イベントを再予約する。v1特殊移行対象や未知のDB状態は自動再試行しない。予約成功後の状態保存に失敗した場合はイベントを取り消す。SQL 構文・権限・未知スキーマ・ID 不整合は自動再試行せず管理者診断。ロックが取れない間は共有状態を上書きしない。

WP-Cron を止めた環境でも管理者通知から状態/失敗ステップ/最後の完了版を確認し、nonce と manage_options を要求する POST で再試行できる。止まったファイル更新の UPDATING 解除は #49 の「ジョブ/ロールバック停止の明示確認」経路を使用し、Migrator の再試行から強制解除しない。

v1特殊移行対象は管理画面で `v1_migration_required` を通知する。更新・再試行からID補完や履歴変換を実行しない。DB・プラグインファイル・Rewriteを揃えたバックアップを取得したうえで、v1専用の移行対応を別途設計・実施する。専用移行機能のボタンや、同意チェックだけで変換を始めるフォームは提供しない。

無効化は保護設定/移行履歴を消さず、再試行 Cron を解除する。アンインストールは設定・到達版・移行/更新状態・Release cache・再試行 Cron を既存の削除契約とともに除去する。再導入で既存テーブル/保護メタだけが残る場合、それを新規の空サイトと推測しない。

## 配布と公開手順

1. DB、プラグインファイル、uploads/.htaccess と第三者ルールをセットでバックアップし、保守環境で復旧を試す。WordPress のファイル一時バックアップは DB 復旧ではない。
2. 0.7.38 以前は旧 PHP/Cron を停止して初回 ZIP を手動導入。0.7.39 には更新検出機能がないため、0.7.40 導入までは手動 ZIP が必要。正常に管理画面検証が完了するまで自動更新の運用へ移さない。
3. main でレビュー・検証後、`python3 tools/build-release.py --tag v0.7.40` でビルド。タグ、ヘッダー、ESP_VERSION、Update URI、必須ファイル、easy-slug-protect/easy-slug-protect.php を検査する。ZIP は追跡済み runtime ファイルのみを含む。tests、docs、CI、.git、ビルドツール、README を含めない。
4. v* タグの workflow は main の祖先であることを確認して **draft** Release と専用 `easy-slug-protect-<version>.zip`/SHA-256 を作る。自動では公開しない。workflow_dispatch/タグ作成は今回実行していない。
5. 配布担当者が内容・バックアップ/復旧・対象環境の実 HTTP・CI を確認後、Stable として公開し latest に指定する。タグ/Release の書込権限を最小化し、GitHub の tag ruleset、保護された main、必須レビューを設定する。設定済みとの主張はしない。
6. 次の Stable へ標準画面で手動更新・利用者設定に従う自動更新を確認する。draft/prerelease や同版・旧版、欠落 asset は更新対象にしない。

GitHub の source 自動生成 ZIP にはフォールバックしない。公開 API はトークン不要、5 秒、成功 6 時間/不成功 15 分の cache。障害は検出不能として扱い、保護/DB 状態を変更しない。専用 ZIP の名前、URL、公開状態、size、GitHub API の sha256 digest が揃った場合のみ対象とする。

標準 download_url とアップグレーダーを使い、独自のファイル置換エンジンは作らない。最初の URL は固定 repository の正規 asset URL。リダイレクトは HTTPS の GitHub 同 repository releases/download と release-assets.githubusercontent.com のみ、3 回まで、認証情報/別 port/fragment を拒否。ZIP は 20 MiB 上限、取得後 size/SHA-256、展開後のヘッダー/ESP_VERSION/必須ファイルを検証する。Requires PHP / Requires at least が宣言されている場合は配置前に実行環境と照合し、不適合・不正な要件を拒否する (Core の単体 upgrade と bulk/install の検査差異を補完)。最低要件がない版に独自の最低値は加えない。

**配布元侵害の限界:** API と asset は同じ GitHub の信頼境界。SHA-256 は破損/取り違え検知であり、repository/Release 権限侵害者が ZIP と digest を同時に差し替える攻撃の独立した署名ではない。独立署名/別経路の attestations は未実装。

途中失敗時は標準アップグレーダーのファイル復元を使い、新コードの DB 移行を旧 PHP の完了フックで走らせない。新コードで DB/完了版が進んだ後の手動ダウングレードは互換性不明として停止する。必要なら保守停止下でファイル・DB・Rewrite の **同じバックアップ時点** を復元し、その版の動作を検証する。版印だけを消す/小さくする復旧手順は採らない。非互換の destructive migration は通常自動更新に載せない。

## 検証と残る公開ゲート

`tests/wordpress-update-integration.php` は opt-in した esp_ で始まる使い捨て DB 上で実行する。新規/既存版印なし、v1特殊移行対象の診断とデータ非変更、v2/v3/v4の既知の追加補修、現行互換DBでの履歴読取0件、未来版、列/索引、SQL read/write 故障、実 ALTER 権限拒否、複数接続/切断/旧所有者を確認する。通常の marker/path/schema テストも新しい責務へ移管し、既存の保護・Rewrite・認可・認証・アンインストール回帰を維持する。

`.github/scripts/test-wordpress-updates.sh` は WordPress 7.1.3 と使い捨て DB で移行試験に加え、標準 Plugin_Upgrader/WP_Automatic_Updater に synthetic 0.7.41 asset を渡す。標準/任意フォルダ × 手動/自動の 4 経路、Core の no_update/利用者設定、破損 ZIP、別 PHP worker による後処理、Core の fatal 判定後の実ファイル復元を確認する。GitHub API/asset と Core の loopback は fixture 応答。実際の fatal 判定自体は注入し、PHP 致命エラーの実 loopback を合格と主張しない。

Apache の実 HTTP fixture は `tests/fixtures/apache.Dockerfile`、`tests/wordpress-http-prepare.php` と `tests/wordpress-http-check.py`。CLI/Apache の両方から同じ使い捨て DB と WP ディレクトリを参照し、Apache は localhost:8094、AllowOverride All/mod_rewrite、uploads の適切な書込権限を設定する。prepare は test 用 esp44-custom を前記 release fixture で配置してから実行する。`ESP_WP_ROOT` と `ESP_ALLOW_DISPOSABLE_DB=1` が必要。prepare は fixture の設定/テーブル/Rewrite を消して作り直すため本番には使わない。

- 実 Apache で公開/保護ページ、公開/保護 TXT の本文、session/remember のログインとログアウト後の同一 URL を確認。
- `wordpress-http-state.php` で FAILED/UPDATING を注入し `wordpress-http-check.py --unready` を実行。保護ページ、直メディア、REST、feed の 503/本文非露出と、既知の公開単体ページの 200 を確認。
- fixture では添付ファイルを一意にする。重複した _wp_attached_file の旧帰属判定、symlink、最適化派生ファイルの問題を今回の Migrator/Updater で解決したとはしない (#42/#43/#40)。

**未検証/公開前に必要:** 実際の公開 GitHub Stable asset のダウンロード/正規リダイレクト/配布、実管理画面での初回 ZIP 上書きと次版の更新操作、対象 LiteSpeed/CDN/共有キャッシュ、Apache の画像派生・特殊文字・HEAD/Range 全組合せ、永続 object cache 下の実 PHP 並走、大規模配信性能とv2-v4追加補修の実DB性能、DB 復旧演習。Multisite は移行・更新検出を対象外として実行しない。外部最適化・Nginx は別設計/環境確認が必要。

#49 のマージ時受入記録にある未完了項目も引き継ぐ。この PR のテスト成功だけで #44 全受入条件、#48、#31/#40/#42/#43 を完了としない。初回 Stable の公開は上記の運用・環境検証が揃うまで保留する。
