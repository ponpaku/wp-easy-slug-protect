# Issue #67 — 実用性と安全性を両立する設計判断 v3（条件付き仕様）

**2026-11-02／状態：設計案の比較確定、WordPress全書込み経路への製品実装と本番承認は未完了。**  
本書は [v2](issue-67-recovery-decision-v2.md) と [ADR](issue-67-architecture-decision.md) に続く採否判断。テスト用 Draft PR #77 の証拠だけを前提とする。

## 結論

**更新IDを既存state表に1列追加するだけでは、正本の遅延書込みを無効化できない。** 「クラッシュ後は無期限503も許さない」「古いSQLの後着で匿名200を返さない」の両立には、**WordPress正本のSQL実行時に取消済み更新IDを拒否できる仕組み**が不可欠。

WordPressプラグイン向けに残る実装候補は次の3種類。

| 対応モード | 通常利用 | 自動回復 | 遅延SQLの安全性 | 位置付け |
|---|---|---|---|---|
| DBトリガー付き strict | 対象ownerのみ更新中503 | 失踪IDをCAS撤回→再索引。正常/異常終了に対応可能 | **DB側で拒否可能**。対象イベント全体へのフック実装・試験が必要 | **strict採用候補。権限/互換性ゲート必須** |
| Core SQLへの更新ID条件付与 strict | 局所503 | 同上 | **DB側で拒否可能**。SQLごとの安全な変形が必要 | 特定更新経路で実証。ただし汎用SQL書換えは保守性上原則不採用 |
| DB実行時ゲートのない WordPress 互換モード | 通常の既存WP操作 | 普通のPHPクラッシュ後の復旧は試験済み。しかし任意の後着SQL保証なし | **匿名200反例あり** | 「完全なstrict」を名乗れない。一般ホストの従来互換性を保つ既定モード |

**製品の現実的な選択**：全ホスト一律にstrictを有効にしない。DB実行時ゲートを安全に設置・照合できる環境のみstrictを有効化し、未対応ホストは従来互換モードを維持する。ホスト環境を検出するまでは勝手に認可保証を主張しない。ファイルロックは補助的なreaper選出・PHP生存確認には有用だが、**SQL実行時ゲートの代わりにはならない**。

## 保護不変条件

1. 正本が新しい保護所有者の書込みをCOMMITできる時点より前に、その所有者候補パスを独立COMMITした索引へ登録するか、globalフェンスで認可を止める。更新IDは**どのワーカーの書込みか**まで識別する。
2. 失効IDは再使用禁止。再送された登録・解除は冪等CAS。既存 `pending_count` はID集合長の派生値とし、`++/--`単独SQLを禁止。
3. DBの各保護対象正本書込みは、実行時に**自分の**IDを参照し、state行ロックで失効CASと直列化する。PHP上の「チェックした」やセッション所有者が生きていたという理由では許可しない。
4. reaperは心拍期限切れを「GC対象候補」として扱うだけで、DB側失効が完了した時点に初めて後着SQLを無効化できる。先にDB行ロックを取った書込みは完了を待ち、期限後に届くSQLは拒否。
5. Resolverと再構築のREADY公開CASはlocal/globalの未完了ID集合が空・pending=0・revision一致を確認する。索引不整合・保護候補所有者の衝突は403/503。全体失効は通常操作に使わず限定例外だけ。
6. 失踪した1 ownerの修復によって**他のメディアを503にしない**。Cronに加え、影響pathへのHTTPで短時間・単一勝者の遅延修復を起動可能とする。

## WordPress対応面

**DBトリガー条件の設置候補（製品への実装・検証は未完）**

- `wp_postmeta` の `BEFORE INSERT/UPDATE/DELETE`：`_wp_attached_file`、`_wp_attachment_metadata`、`_esp_media_protected_path_id` の新旧値・新旧ownerを扱う。
- `wp_posts` の `BEFORE INSERT/UPDATE/DELETE`：添付の新規作成／削除、`post_type`昇格・降格を扱う。自動増分IDが作成前に不明な場合にはglobal更新IDへの先行登録など専用の順序が必要。
- WordPress Coreの `update_post_meta()`、`update_metadata_by_mid()`、`add_post_meta()`、`delete_post_meta()`、`wp_insert_post()`、`wp_update_post()`、`wp_delete_attachment()`、メタ一括削除を**それぞれ独立に**試験。
- 同じPostMetaキーが複数行・違うキーへ変更・添付以外からの昇格・複数添付の一括SQLでは片方だけのチケットを認可とみなさない。
- セッション変数によるactor-ID伝播は、**WordPress再接続で失われる**。この場合トリガーは安全側で操作を拒否し、更新リクエストは失敗と判定される。自己再試行はCoreの再接続・応答欠落・外部TXの複雑さを監査するまで行わない。失敗時チケットは安全に自動GCする。
- `CREATE TRIGGER`権限、既存トリガーとの競合、DBエンジン・MySQL/MariaDB差、複数サイト、ホスティングの運用制約をインストール前に検出。導入時は書込み停止・バックフィル・監査・原子的切替・ロールバック手順が必要。
- 未対応・直接SQL・TRUNCATE・権限を越えたDDL・サーバーが追跡できないSQLプロキシはstrict保証範囲を明記し、無保証のまま有効化しない。

**DBトリガーなしの汎用WordPress環境で、①安全な自動GC ②任意のSQL後着阻止 ③全書込みAPI互換 ④既存表に1列追加のみ、の4条件を同時に保証できる設計は現時点では見つかっていない。**

## 復旧・可用性の運用契約

- **正常更新**：ID登録→候補予約→正本書込み→安全なCOMMIT後ID解除→owner再索引。正常HTTPは既存高速索引のまま。
- **PHP強制終了**：期限切れIDをreaperがDBで無効化。後着SQLは書込み時のID検査で拒否。正本を再読込→索引READY。Cronを待たず、次の対象HTTP要求からの限定復旧も可。
- **MySQL実行中の書込み**：state行ロック待ちを短時間で諦めて再試行。ロック待ち中に無理にID削除しない。
- **WordPress DB再接続**：セッションID喪失ならそのCore更新は失敗することを明確に返す。メディア全体を503にしない。残留IDは別プロセスが期限後に安全に回収。
- **ホストのDBが一時停止**：確定結果不明時は対象pathを拒否し、復帰後に照合。生存が不明なPHPの状態を時計だけで安全とみなさない。対応不能は管理者へ詳細理由と復旧手順を表示。
- **無関係な画像**：他ownerに保護候補がなければ従来どおり高速配信。
- **監視**：残留ID、局所503継続時間、reaperのCAS失敗、DBロックタイムアウト、SQL実行拒否の回数を収集。期限値と短時間リトライ上限は負荷試験後に決定し、未実測のSLOを宣言しない。

## 検証記録

- [#41420646509](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41420646509)：WordPress CoreのTCP遅延SQLがPHP死亡・ticket GC後に実行→索引欠落で匿名200。SQL実行時ID検査で防止。
- [#41425662890](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41425662890)：同じ反例を再検証し、WordPress `wp_postmeta` トリガー＋**per-writer actor ID**で遅延SQL拒否を実証（8/8ジョブ成功）。
- [#41413844313](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41413844313)：MySQL8/MariaDB10.11のJOINとトリガーによるSQL実行時ゲート。
- [#41416617064](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41416617064)：実WordPressの通常UPDATEと失効後のSQL再送をJOIN条件で比較。
- [#41417106979](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41417106979)：PHPのSIGKILL後にも実行中のMySQL UPDATEを検出してGC延期・再試行。
- [#41423057798](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41423057798)：実WordPress `wpdb`再接続でMySQLバックエンドIDが変わる。元接続KILLだけでは全書込みを保証できない。
- [#41425662890](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41425662890)：匿名HTTP要求から管理者操作なしの局所修復が成立（単一ホスト試験。DB実行時ゲートと合わせた製品統合は未完）。

## 本番の完了判定

全対象のWordPress APIに対する**DB側書込み拒否の保証、同一主系DB、トリガー導入／削除権限、schema migration／rollback、クラッシュ・再接続・SQL遅延・複数ワーカー、局所HTTP自動復旧、負荷試験**のCI受入条件が全件PASSして初めてstrict製品化できる。

**今回選んだ「設計候補」はテーブル2つ＋state列1つを維持する。ただし追加トリガー・WordPressフック再設計・復旧プロトコルは不可欠で、「列1つだけで修正完了」とはしない。**

### DB文字列比較の互換性（追加検証）

[Actions #41438463842](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41438463842) の実WordPress＋MySQL 8検証で、試験用トリガーは正常に作成されたが、actor IDを単純な`VARCHAR`で照合すると **`utf8mb4_0900_ai_ci` とWordPress側 `utf8mb4_unicode_520_ci` のcollation不一致**により正当な`add_post_meta()`まで失敗した。これを「安全だから問題ない」とみなすのは可用性要件に反する。

本番のactor-IDは **バイナリ固定長（例：ランダム32バイト）**とし、MySQLセッション変数を`CAST(... AS BINARY)`で比較することで照合規則への依存を排除する方針。修正版の全CRUDテスト結果がPASSするまでは、この改善は**候補**であり実証済みとは呼ばない。
