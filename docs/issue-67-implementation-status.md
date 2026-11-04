# Issue #67 — state-only実装：差分・検証状況・残存受入条件

**2026-11-04／Draft PR #78。製品コード変更あり、未マージ、strict本番承認前。**

設計正本：[Issue #67 統合仕様](issue-67-design-spec.md)。先行設計実験：[v7 state-only PoC](issue-67-state-only-prototype-v7.md)。

## 実装済み

**追加テーブルはゼロ、既存2表のみ。** 物理名を `esp_media_files` / `esp_media_file_state`（接頭辞はWordPress prefix）へ変更。旧 `_v1` テーブルがある場合には旧表をrenameするschema v4導入処理と、再監査まで古いREADYを信用しない移行ガードを入れた。WordPress option名 `esp_media_index_v1_*` は今回変更していない。

既存stateに `active_mutation_ids LONGTEXT` を**1列**追加。各PHP操作の独立16-byteランダムIDをJSON配列に保存し、`pending_count` を集合の件数として更新する。`begin()`、`finish()` はrevision CASを使い、SQL ACK消失／再送でも他操作のIDを誤解放しない。別テーブルでgateやeventを保持しない。

MySQL接続単位の `GET_LOCK` を各更新IDの生存確認に使う。orphan回収は `IS_USED_LOCK` と再取得可能性を確認してからCASで当該IDだけ解除する。遅延SQLがさらに到着した場合はDBトリガー側の検証によりglobal dirtyへ切り替える。複数ホストの分散トランザクション／コネクション多重化の互換性は別途保証が必要。

`wp_posts` と `wp_postmeta` のINSERT/UPDATE/DELETEで**6本のDBトリガー**を導入。セッションの本人ID `@esp_media_mutation_actor` を既存stateと照合し、不明・失効なら**正本SQLと同じInnoDB TX**でglobal `status='dirty'`、`revision+1`。単一SQLをhard-denyする方式は基本にしない。

`_wp_attached_file`・`_wp_attachment_metadata`の書込みでは、**有効な本人IDだけでなく、WordPress pre-hookで事前予約された正確なメタ値のSHA-256**もセッション変数で照合。トリガーは事前予約されない新しいパスへの直接SQLが有効actorを借りてglobal READYを維持しないよう、global dirtyへ切り替える。これは追加のDB列なしで行う。

新規添付では、WordPressがIDをPHPに返す前から発行するCore多段SQL全体をstate行0のglobal作成IDでカバー。グローバル作成処理はDIRTYを維持し、完了・ID解除後に監査でREADYへ戻す。パスの正本メタが**全くない**添付は所有パス0件の世代として監査可能にした。部分的・不正なメタを安易にゼロ所有者扱いしない。

Resolverはglobal READYに加え、global pending=0、active_mutation_idsが空であることを照合。局所READY公開・global READY公開にもpending/更新ID空とrevision整合を要求する。

## 製品コードと回帰テスト

- `includes/class-esp-media-index.php`
- `includes/class-esp-media-mutation-state.php`（新規）
- `tests/wordpress-issue-67-production-state-ci.php`（新規、実WordPress＋実DBトリガー）
- `tests/security-media-index-mysql.php`（stateのみの2トークン／DB応答消失によるCAS再送）
- `tests/wordpress-media-index-cli-ci.php`、`tests/wordpress-media-index-f01-ci.php`、`tests/wordpress-issue-67-tx-loss-ci.php`、`tests/issue-63-production-benchmark.php`、`tests/fixtures/esp63-schema-failure-mu.php`、`.github/scripts/test-wordpress-apache-http.sh`（スキーマv4と新global fallbackを反映）

## 既存PoCと製品CIの分離

旧PR #77の実験には「安全装置を意図的に外し、匿名HTTP200による反例が再現すること」を成功条件にしたシナリオがある。製品用DBトリガーが導入された環境でこれらを同時に動かすと、**安全なHTTP503が返ることで逆に失敗**する。旧PoCは検証専用PR #77のCI証拠として保存し、製品スキーマv4環境のApache実装CIからのみ当該実験群を条件付き除外する。製品の本物の2更新ID・失効actor・欠落actor・未予約パスSQL・新規添付E2Eと、旧F01実2ワーカー、旧Q01実wpdb再接続/ACK消失は引き続き実行する。

この切り分けは**旧PoCの実験成功＝製品の安全性保証**という誤認を避けるためでもある。新しい製品コードのクラッシュ・同時監査・遅延SQLに関する専用検証は別の受入条件として残る。

## WordPressライフサイクル

DBトリガーはWordPress Coreの posts/postmeta に付くため、PHPプラグインをアンインストールしても自動では消えない。uninstall.php は本プラグインの6本を厳密な名前で先にDROPし、成功したときだけ索引2表と旧 _v1 表をDROPするよう変更した。DROP TRIGGERに失敗した場合にはstateを残してCore SQLが欠落stateで失敗する事態を避け、手動復旧が必要であることをログに記録する。他プラグインのトリガーは対象にしない。

tests/security-uninstall-index-trigger-test.php とPHP security CI項目を追加。最新版のCI結果はまだ取得できていないため、ここは未実証。無効化だけの運用ではトリガーを維持しており、再有効化時の既存stateを監査する設計である。

## 追加の受入試験と最新の未確定事項

- tests/issue-67-production-triggers-mysql.php をMySQL8とMariaDB10.11の両方で実行し、製品トリガー6本を確認（CI #41808834596、両DBジョブ成功）。
- tests/wordpress-issue-67-production-state-ci.php で、WordPress Coreの正規メタ変更は局所READY維持、未予約パス直接SQLはglobal DIRTYを確認（CI #41809259944、当該個別テスト成功）。
- 実WordPressの旧 _v1 から新規名称への隔離移行でrename・データ保持・stateの列追加・旧READY失効まで成功。ただし照合順序の混在でトリガー比較に失敗したため、製品コードをバイト単位の比較に変更。修正後の移行完走は未検証。
- 旧v3の匿名pending_countが残る恒久停滞を避けるため、6トリガー設置後に限りIDのない旧pendingを回収する処理と試験を追加。最終CI未検証。
- 製品トリガーのSELECT COUNT(*) FOR UPDATEが正本SQLのCOMMITまでstate行を施錠することを確認する2接続試験をMySQL/MariaDBに追加。実行結果は未取得。
- Apache全回帰は未成功。旧PoCの脆弱なHTTP200反例は製品CIと分離済み。PR #78はDraftで維持し、性能調整・マージを行わない。

## 検証済み／未完了の区別

実WordPress製品テストで、stateへの2つの本人ID、片方のみの冪等解放、有効actorでの局所READY維持、失効actor・消失actorの同一TX global DIRTY、**有効actorでも未予約のパスSQLはglobal DIRTY**、新規添付のID未確定状態と所有パス0件のREADY監査を確認した。

ただし、全レガシー障害注入スイートと新実装を結合した全CI成功は**最終確定前**。従来は未検知だった直接SQLが新しいトリガーで全体失効するため、古いテストは局所再索引のみでなく全体監査も必要になる。**製品版6トリガーはMySQL 8／MariaDB 10.11両方のCIで実行成功**（actor照合、未予約パスの全体失効、同一TXでのdirtyとROLLBACK、新規添付、global欠落時のSQL拒否）。旧PoCだけの確認ではない。

**本番採用に残す重要条件**：新しい製品実装でのPHP SIGKILL／実tcp遅延SQL／actor再接続／複数ワーカー競合／監査CAS並行書込みの連結試験、旧 `_v1` 表からの実マイグレーション試験、MySQL/MariaDB CREATE TRIGGER権限・既存trigger共存確認、デッドロック時の安全な再試行、部分メタデータの復旧上限とDB外ファイル更新の脅威境界。

性能チューニング、PRマージ、Issueクローズはこの実装作業では行わない。PR #62、PR #77、mainは変更しない。
