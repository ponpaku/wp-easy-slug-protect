# #67 自動復旧を含む設計決定 v2 — 実WordPress障害注入の結論

状態: **製品実装前の検証ベース推奨。strict 本番採用は未承認。** 2026-11-02。

## 結論：単なる時間切れチケット解放は安全性と可用性を両立できない

最も重要な実験は、[Actions #41420646509](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41420646509)（8/8成功、WordPress 7.1.3＋MySQL 8.0）。現実のWordPress Core `wp_postmeta` UPDATEパケットをTCP中継で**MySQLへ送信する前に停止**し、PHPをSIGKILL、ファイルロックの解除とWordPress正本行ロックの確認を経て更新チケットを回収・索引をREADYへ戻す。そのあと保留したSQLを初めてMySQLへ配送すると、**本当にUPDATEが確定し、新たな保護所有者が索引から欠落したまま匿名200**になる。

同じ実験で**WordPress Core UPDATE自体にDB内の更新ID存在条件を付ける**と、回収後に配送したUPDATEは書込0件で、正本と索引が一致する。

したがって、通常の無期限503をなくすには次のいずれかが必要：

1. **DB書込み時のフェンシング**：回収済み更新IDの書込みはMySQL実行時に拒否する。DB側で撤回可能性を保証する。新ID登録／回収と実正本の書込みを適切に直列化する。
2. **例外的な対象パスの永続保護フェンス＋厳密な読取り時検証**：失効IDを単純削除せず、遅延書込みが後から確定しても不適切に公開しないよう、その所有者候補パスを保護し続ける。無関係な画像は通常どおり配信できるが、対象パスの回復条件や実時間認可方法は別途設計・負荷検証が必要。
3. **確認済みのDB接続が今後絶対に正本へ書き込めないよう切断・排水**：単一primary直結・追跡可能セッション等の環境制約下でのみ候補。プール／プロキシ・再接続・マルチサーバーには一般化しない。

`flock`＋`SELECT FOR UPDATE`は普通のPHPクラッシュや**既にMySQL上で走っている**SQLに対して有用だが、ネットワーク上に留まったSQLは検出できない。**これだけを普遍的な自動復旧の完成形とは呼ばない。**

## 実測の区別

| ケース | 実測 | 根拠 |
| --- | --- | --- |
| `pending_count++/--`の重複再送 | 二重減算する | #67従来再現、[ADR](issue-67-architecture-decision.md) |
| 更新ID集合への冪等CAS | SQL再送で他者のIDを消さない | [#41370951448](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41370951448) |
| SQL事前に独立COMMITする候補パス | 外部TX切断でも候補所有者が残り503／403 | [#41370951448](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41370951448) |
| global dirtyだけ／global pending=1だけ | CronがREADY再公開→匿名200 | [#41373652544](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41373652544) |
| global READYのCASにID空を要求 | MySQL8＋MariaDB10.11で競合防止 | [#41375237511](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41375237511) |
| MySQL名前付きロック消失をクラッシュ扱い | 生きているPHPがあとから正本更新→匿名200 | [#41411625109](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41411625109) |
| PHP実行期間`flock` | SIGKILLのあと自動回復。DBのみ切断は誤回収しない | [#41412148967](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41412148967) |
| PHP死亡後もMySQLでUPDATEが進行 | 正本行ロック待ちのあと確定する | [#41412763232](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41412763232) |
| `flock`＋正本行ロック＋リトライ | MySQL上で実行中のCore SQL確定後に安全に回復 | [#41417106979](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41417106979) |
| 匿名HTTPの復旧トリガー | 単一ホストで管理者操作なしの再構築→HTTP200 | [#41420646509](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41420646509) |
| **まだMySQLに届かない遅延UPDATE** | `flock`＋行ロックの回収**後**に確定、索引漏れ | **[#41420646509](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41420646509)** |
| 同じ遅延UPDATEをDB実行時更新IDで条件付け | ID回収後の更新は0件で安全 | **[#41420646509](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41420646509)** |
| WordPress Coreの`update_post_meta()`でSQLゲート | 正常チケットは成功、回収後の古いSQLは0件 | [#41416617064](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41416617064) |
| `flock`ロックファイルを削除・再作成 | 古いinodeが生きていても新inodeの排他ロック獲得可能 | [#41413017428](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41413017428) |

## DB実行時フェンシングを選ぶ場合の具体契約

**書込みプロトコル**：

1. 現行`esp_media_file_state_v1`に`active_mutations`を追加する。既存`pending_count`は集合長の派生値。更新IDは高エントロピー一意値。登録・解除はrevision付き冪等CASで管理。
2. 元のWP正本を更新するより前に、**独立接続・同一primary InnoDB TX**で`state=dirty`、更新ID登録、想定される全所有者候補パス予約をCOMMITする。未確定なら正本の書込みを拒否。
3. 正本INSERT/UPDATE/DELETEには、**MySQL側で未回収の更新ID・対象owner・revisionを検査する書込みゲート**を付ける。ゲートの整合性はstate更新と同じDBトランザクション／行ロックで確保し、PHPだけの事前確認に依存しない。
4. 正常完了後はcommit確認と重複フック処理を調整してID解除し、監査・再構築を行う。
5. 失踪した更新を回収する場合は、DB側でIDを取り消す。**古いSQLは遅れて到着しても0行変更**になることが採用条件。既に実行中のSQLは行ロックで先行するか、失効CASより後なら拒否される。
6. 索引とglobalのREADY公開は`revision`＋`pending_count=0`＋更新ID空集合のCAS。Resolverも同じ条件を検査。監査時に不整合なら503／403。
7. チケット回収・回復のために、通常時は更新ID心拍で期限更新し、期限超過はreaperが安全なDB側ゲートの成立を確認した場合にのみ撤回。**単純なTTL削除だけは不可**。
8. 匿名HTTP経由の軽量repairまたは短周期Cronで自動復旧。ただしHTTPの正常経路では不要なDBセッション制御をしない。回復中に対象だけ503、無関係な所有者は配信可能。

**最大の障壁**：WordPress Coreに一般的な「実際のSQLを書き込む瞬間の承認ゲート」はない。実験で成功したのは`update_post_meta()`の特定UPDATE構文への`query`フィルター変換に限られる。Coreの`INSERT`、`DELETE`、`update_metadata_by_mid`、`wp_posts`の追加・削除・post_type変更、直接SQL、複数添付、SQLプロキシ中での再接続を対象外にしたまま「製品完成」とはできない。**WordPressのSQL文字列全般を書き換える危険を無視して汎用フィルターを配ることも不採用。**

## 実用性を優先する製品向け判断ルール

- 全メディアが503になるglobalフェンスを通常変更では使わない。候補パスを列挙できるならowner局所フェンスが優先。
- **保証のない環境で自動的にチケットを解放し、匿名200を返すのは禁止。**
- サーバー側ゲートを整備できる対応経路は、期限を設けた自動復旧を採用可能。健全な処理を過剰に撤回しないためリトライ・猶予・再取得を実装する。
- SQL実行時ゲートが未導入の経路は、`flock`＋DB行ロックを最適化として使えても、TCPに遅延SQLが存在する障害を排除できない。**残余リスクの受容基準・read-throughモード等の別設計が必要**。
- 複数Webサーバー、DBプロキシ、読み取りレプリカ、古いMySQL/MariaDBでの各機能の成立可否を起動時・更新前に検出し、サポート不能ならstrictモードは無効／明示的に拒否。
- 一度登録したowner候補の行を失効時に削除する前に、後から正本が更新され得ないことを保証する。単純に「期限切れで再構築」は上記の匿名200反例になる。

## 現時点の「完成」判定

**方式の根幹を比較し、間違った方式を除外する実証は済んだが、全WordPress更新経路に実装された汎用プロダクション・プロトコルとしては未完成。** まずCoreの書込み経路別にSQL形状／pre-hook／post-hook／失効条件／失敗時の正本変化有無を監査し、対応できる経路からサーバー側ゲートを統合し、残る経路はglobal denyまたは別方式の根拠を用意する。

参考：[最初のADR](issue-67-architecture-decision.md)／[自動復旧比較メモ](issue-67-automatic-recovery-options.md)。これらの単一ホストfilelock推奨は本書の遅延SQL反例によって**無条件の安全保証としては上書きされる**。
