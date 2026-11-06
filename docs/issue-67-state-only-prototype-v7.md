# Issue #67 — stateの1列だけで行うhybrid方式：動作PoC（v7）

**2026-11-04／設計選定のための使い捨て仮実装。製品本体への統合ではない。**

## 結論

**既存のfiles＋stateという2テーブル構成を保ち、追加テーブル0、stateへの追加列1つで、局所本人ID照合・失効時global dirty・新規添付作成を実WordPressで動かせた。**

[Actions #41785870856](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41785870856)：8/8ジョブ成功。新規仮実装の全アサーション成功。

## 仮実装の構成

- **既存テーブル**：wp_esp_media_files_v1 はそのまま。wp_esp_media_file_state_v1 に、試験中のみ **esp67_proto_actor_ids LONGTEXT** を追加。処理IDをJSON配列として保持する。
- **追加テーブル：0**。これまでの実験で使ったgate表やevent表は一切作らない。
- **試験用DBトリガー：6本**。wp_posts／wp_postmeta それぞれINSERT、UPDATE、DELETEを監視。本人IDをDBセッション変数 @esp67_proto_actor と stateのJSON集合で照合する。
- **正常更新**：当該添付のstate行を先にdirty、pending=1、本人ID登録。DB実行時に有効ならglobal READYを維持。終了後にID解放し局所索引を更新。
- **失効・不明な更新**：本人IDの照合に失敗したSQLは正本をCOMMITできるが、同一InnoDBトランザクションでstateの全体行（attachment_id=0）をdirty、revisionを更新。Resolverは古いREADYを拒否。
- **新規添付**：最初は添付IDを知らないので、作成開始前にstateの全体行0へglobal作成用IDを予約する。この間はglobal dirty。Core内のINSERT→内部UPDATE→メタデータ追加をカバーし、作成完了後にIDを解放・監査READYへ戻す。
- **試験後**：仮添付を削除し、トリガーと追加列も撤去。製品PHPには変更を加えない。

## 検証済みの事実

| 具体的操作 | 観測 |
| --- | --- |
| 有効な局所IDを持つWordPress CoreのメタINSERT、by-mid UPDATE、posts UPDATE | 成功。全体READY維持 |
| 局所IDを解放し、添付をREADYへ戻した後に古いセッションIDでCore UPDATE | 正本SQLは成功し、そのSQLと同時にglobal dirty確定。旧公開索引はerror、再監査で復旧 |
| セッションIDをNULLにした状態のCore UPDATE | 同じくglobal dirtyを確定し、再監査で復旧 |
| 新規添付のCore INSERT＋内部UPDATE＋メタINSERT | state行0のglobal作成IDで保護。不要な失効fallbackなし。完成後に監査READY |
| 新規添付の実ファイルと既存の公開画像 | 完成後に両方とも正常解決 |
| 試験専用のgate/event/mutationテーブル | **一切作成していない** |

ログ識別子：ESP67_STATE_ONLY_VALID_ACTOR_LOCAL_READY、ESP67_STATE_ONLY_EXPIRED_ACTOR_ATOMIC_DIRTY、ESP67_STATE_ONLY_MISSING_ACTOR_ATOMIC_DIRTY、ESP67_STATE_ONLY_NEW_ATTACHMENT_GLOBAL_TOKEN_RECOVERED、ESP67_STATE_ONLY_ONE_COLUMN_NO_NEW_TABLES_VERIFIED。

実装： [tests/wordpress-issue-67-state-only-prototype-ci.php](../tests/wordpress-issue-67-state-only-prototype-ci.php)。CI：[.github/scripts/test-wordpress-apache-http.sh](../.github/scripts/test-wordpress-apache-http.sh)。

## 判断と境界

**データモデルとして「既存2表＋stateの更新ID集合1列」は成立する候補になった。別のgateテーブルは技術上の必須条件ではない。**

ただし本PoCは1添付につき同時1更新IDの簡略試験であり、JSON集合の複数並行ID、冪等解放、ACK消失時の再送・reaper、監査と更新の競合はこのPoCで証明していない。以前の[v6](issue-67-architecture-recovery-v6.md)の「所有パスなし」復旧も今回のstate-only仮実装には未統合（別の模擬READY試験で検証済み）。

この新PoCのDBトリガーをMariaDBで直接実行した検証でもない（CIのmariadb-issue67ジョブ成功は別シナリオ）。DBトリガー権限、MySQL/Postmetaロックのデッドロック、全更新経路、ファイルシステムの先行操作も製品採用時の条件として残る。

**設計選定の結論**：新規テーブルを増やさず、stateの1列で本人IDを持つhybrid方式を第一候補とする根拠が得られた。製品統合、最終的な本番保証、性能チューニングは行っていない。

PR #77は検証専用Draft、Issue #67はOpen。main、PR #62、製品本体は未変更。
