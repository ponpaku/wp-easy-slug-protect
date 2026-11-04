# Issue #67 — メディア所有権索引の安全な更新：設計・動作仕様（統合案）

**2026-11-04／製品実装PR #78の設計正本。現行コードは実装済み、CI全件通過・製品採用判断は未完了。**

## 0. 決定事項と現在地

**基本設計**：既存のファイル所有権索引と状態管理の**2テーブルだけ**を維持する。新規のgate、ticket、event、mutation専用テーブルは追加しない。更新処理の識別子は既存のstateテーブルに**1列追加**して保持する。WordPress正本変更をDB側のトリガーでも監視する。通常は添付単位の局所フェンス、正規性の確認できない更新だけ全体の索引を無効化する「hybrid local-first + atomic global fallback」を採用候補とする。

**テーブル名**（製品コードに反映済み、旧名称からの移行は検証中）：
- `{$wpdb->prefix}esp_media_files`（旧：`{$wpdb->prefix}esp_media_files_v1`）
- `{$wpdb->prefix}esp_media_file_state`（旧：`{$wpdb->prefix}esp_media_file_state_v1`）

`_v1`はテーブル名に含めない。スキーマバージョンはWordPress optionで独立管理する。命名変更は既存データの移行・再監査を伴う。

**実証済み範囲**：使い捨て環境のstateにJSON更新ID集合用の列を1つ追加し、wp_posts／wp_postmetaへの計6本の試験用トリガーで有効ID、失効ID、actor消失、新規添付ID未確定時の操作を確認。[CI #41785870856](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41785870856)は全8ジョブ成功。**本番コード統合・本番での完全な安全性保証ではない。**

## 1. 目的と原則

**目的**：メディアの公開／保護判断に用いる派生索引が、WordPress正本の更新途中・異常終了・後着SQLによって古くなっても、保護ファイルを誤って公開しない。安全が確認できれば通常配信へ自動復旧する。

**不変条件**：
1. **正本はWordPressのposts／postmeta**。索引と更新IDは派生データであり、正本より優先しない。対象となるメタキーは `_wp_attached_file`、`_wp_attachment_metadata`、`_esp_media_protected_path_id`、および添付の作成・削除・post_type変更。
2. **不確実な状態では配信しない**（fail-closed）。期限超過だけでREADYに戻したり、更新IDを単純に減算／除去したりしない。
3. **先行フェンス**。添付の所有権や保護設定を書き換える前に、関係する既存パス／新規パスを安全にカバーするフェンスを永続化する。影響パスが全て確定できない場合は、局所フェンスと称して既存の公開所有者を放置せず、**書込み前にglobal dirty**へ切り替える。
4. **正本とglobal失効の原子性**。DB側で有効な更新IDと十分な先行フェンスを確認できない対象SQLは、正本SQLと**同じInnoDBトランザクション**でglobal statusをDIRTY、revisionを加算する。SQLを無条件拒否する方式は基本にしない。
5. **監査付きの再公開**。WordPress正本から索引を確認／再構築し、revision CAS・更新ID集合が空・必要なcoverage検証に成功した場合に限ってREADYを公開する。

## 2. DBデータモデル

| テーブル | 役割 | 主な情報 |
| --- | --- | --- |
| `esp_media_files` | ファイルパスと添付の所有関係・保護IDを世代付きで記録する | `path_hash`、`file_path`、`attachment_id`、`generation`、`protected_path_id` |
| `esp_media_file_state` | 添付ごとの更新状態と全体coverageを管理する | `attachment_id`、`status`、`revision`、`active_generation`、`pending_count`、`retry_after`、`failure_count`、`audit_cursor`、**新規：`active_mutation_ids`** |

- 上記のテーブル名にWordPressのサイトprefixを付ける。列名 `active_mutation_ids` は製品仕様案。PoCでの試験列名は `esp67_proto_actor_ids` であり、製品列とは区別する。
- `active_mutation_ids` は更新操作単位の一意なID集合を `LONGTEXT` にJSON配列として保存する。製品コードで実装済み。DBの特殊JSON型を要求しない。
- `attachment_id > 0` の行は特定添付の状態。**`attachment_id=0` は全体coverage行**であり、新規添付作成のようなID未確定操作のglobal更新IDも保持する。
- `pending_count` を残す場合、その値は有効な更新ID集合と整合させる。更新IDの登録・解除を**集合の冪等操作**として実施し、同じ登録／解除SQLの再送で他の処理の件数を増減させない。従来の単純な `pending_count±1` を安全性の唯一の根拠にしない。
- `revision` と `active_generation` は、過去の監査結果や古いファイル所有世代が再公開されないための制御に用いる。更新ID集合の中身は匿名認可結果としてキャッシュしない。

## 3. 状態と外部動作

| 状態 | 意味 | HTTP側の原則 |
| --- | --- | --- |
| 全体READY＋該当添付READY | 公開できると確認された所有者 | 公開ファイルは200、保護ファイルは403 |
| 全体READY＋該当添付DIRTY | 特定添付の更新／再索引中 | その添付に関係する既存・候補パスは安全側に停止。他の無関係なREADY添付は配信を継続 |
| 全体DIRTY | 正本の変化を完全には追跡できない／監査が必要 | 不確実なメディア配信を503で拒否し、再監査する |
| 複数所有者・索引と正本の不一致 | 安全な単一所有者を確定できない | 保護競合は403、不整合・検証不能は503。安全な公開判断を行わない |
| 所有パス0件の監査済み添付 | 正本に所有パスの根拠がない未完成添付 | **条件付き設計案**。他の画像は復旧可とする。ただし後から所有パスが登録された瞬間、同一TXでglobal dirtyへ切替えることが前提 |

具体的なHTTP 403／503へのマッピングは既存のresolver／配信コードに合わせて維持する。認可の対象外となるリクエストや直接ファイル配信は別途保証境界を定義する。

## 4. 正常な既存添付の更新

1. PHPが操作固有の更新IDを発行し、対象添付のstateをDIRTYにし、影響する既存／新規候補パスを**先に**フェンスする。更新IDはDB正本変更より前に永続化する。
2. WordPress Coreのposts／postmeta操作でDBトリガーが当該更新IDを照合する。先行フェンスが十分な正規操作では全体READYを維持する。
3. Coreの多段操作・メタ行ごとの後フックが終わっただけではチケットを二重消費しない。処理ID単位で実際の完了を確認する。
4. 正本SQLの完了確定後、更新IDの**冪等な解除**と局所再索引を行う。更新ID集合が空で、revisionが予期値と一致する場合に局所READYを公開する。
5. 同じ添付を複数のPHPワーカーが同時更新しても、別のワーカーのIDを誤解除しない。複数IDの追加・削除はDB行単位で競合安全に処理する。

**局所フェンスが不十分な更新を正常な局所操作として扱わない。** 特に保護される予定の新しいファイルパスが既存の公開ファイルパスと重なる場合、当該パスを安全に停止できなければglobal dirtyを先行確定させる。

## 5. DB異常・遅延SQL・本人ID消失

1. DBトリガーは、関連するWordPress正本SQLについて、セッションの更新IDがstateの有効集合に存在し、対応する先行フェンスが成立しているかを確認する。
2. IDなし／失効済み／DB再接続でセッション値が消失／不一致なら、同じDBトランザクションで全体stateをDIRTYにし、revisionを加算する。
3. 対象のWordPress SQLは原則COMMITを許可。単独SQLのhard-denyは `wp_delete_attachment()` の一部削除を招いたため、基本方式としない。
4. DBトランザクションがROLLBACKされた場合は正本更新とglobal無効化も同時にROLLBACKされる。デッドロック1213等は、正本COMMITと認可フェンスの原子性を保持したうえでリトライ／復旧する。
5. 全体DIRTYになったら、正本を再確認するまで古いREADY索引から保護対象を配信しない。

## 6. 新規添付・削除・post_type変更

- **新規添付**：PHPがまだ添付IDを知らない段階からCore SQLが発行される。開始前にstate行0へglobal作成IDを登録して全体DIRTYにする。INSERT・Core内部UPDATE・ファイルメタの生成までIDを維持し、終了後に冪等解放して全体監査READYへ戻す。
- **削除**：postmeta各行削除、posts削除、post_type変更が複数SQLになることを前提にする。必要な先行フェンスを維持し、完了後に旧所有者・旧世代を回収する。失効IDを理由に途中のSQLのみ拒否しない。
- **キー変更・別所有者への付替え**：postmetaのOLD/NEW keyとowner、by-mid更新、複数行を監視。旧パスと新パスのどちらも未保護にならないようにする。

## 7. クラッシュ後の復旧・監査

1. PHPが死亡してもstateの更新IDは残り、索引はDIRTYのまま。**経過時間だけでIDを削除しない**。
2. 同一DBのセッション単位の `GET_LOCK` と `IS_USED_LOCK` で更新IDの生存を確認し、持ち主がいないIDだけを冪等に回収する。DB接続が切れてPHPだけが存続していても、遅延SQLは実行時トリガーの失効判定でglobal DIRTYにする。DB proxyや異なるDBクラスタでは別途受入試験が必要。
3. 遅延SQLはID回収後に届いてもDBトリガーがglobal dirtyへ落とす。SQLが実行中・未確定の場合は、誤ってREADYを公開しない。
4. 正本のposts／postmetaとfilesの所有関係を再構築・照合し、更新ID無し・必要な所有者coverage完備・監査revision一致を条件にREADYをCAS公開する。監査途中の新しい正本変更が勝てば古いREADY公開は失敗する必要がある。
5. **所有パスなし（no-claim）**：添付行は存在するが、`_wp_attached_file`、`_wp_attachment_metadata`、`_esp_media_protected_path_id`の所有根拠がなく、対応する所有パスも0件と厳密に確認できる場合のみ、ゼロ所有世代として監査済み扱いにする案。後から正本に所有パスが発生すれば、DBトリガーが同一TXで全体DIRTYへ切替える。
6. **部分的なメタデータ**：いずれか一部だけある添付、重複や壊れたメタデータを**無条件にno-claim扱いしない**。安全に分類できない間はfail-closed＋診断／修復対象とする。

**注意**：所有パスなしの監査はPR #78製品コードに組込済みで個別実WordPressテスト成功。並行監査・更新競合の全条件での安全性は未検証。

## 8. スキーマ命名・更新・非対応環境

- テーブル名は `esp_media_files`、`esp_media_file_state` に統一し、名前にバージョン番号を付けない。
- バージョン番号はWordPress optionで管理する。既存の `_v1` 付きテーブルを利用中の環境に対しては、一度限りの安全なリネーム／移行と再監査を設ける。**テーブル名を変えただけで古いREADYを引き継がない**。
- 移行中・部分失敗中・トリガー設置失敗中はfail-closed。旧索引が破棄されたのに新索引がREADYでない状態から、暗黙の公開フォールバックを行わない。
- DBトリガーの導入には権限と互換性を要求する。対応できない環境では**strictが保護可能だと誤表示して自動有効化しない**。既存トリガーとの共存、MySQL/MariaDB差、テーブルprefix・マルチサイトを要確認。
- DBトリガーはDBの正本変更しか検知できない。FTP等によるファイル単体の差替え、PHPを迂回する直配信／CDN、DDL／TRUNCATEを無条件の保護範囲とはしない。対応可否は製品の脅威境界として明記する。

## 9. 検証結果と未確定事項（実装受入条件）

| 項目 | 現状 |
| --- | --- |
| state 1列、追加テーブル0のPoC | **実WordPressで成功**（#41785870856） |
| 有効な局所ID・失効ID・actor消失 | **限定したCore操作で成功** |
| 新規添付ID未確定時の全体更新ID | **実WordPressで成功** |
| PHP死亡、遅延SQL、実wpdb再接続、逆順デッドロック | **個別の先行障害実験で観測・検証**（v4、v5） |
| 所有パスなし添付からの復旧 | **手動READY模擬と後着所有パス再失効を確認**（v6）。完成監査への統合なし |
| 同じ添付への並行複数ID、冪等な登録／解放とACK消失 | **state-only完成形では未検証** |
| 監査CASと新規所有パスSQLの同時競合 | **未検証** |
| 部分メタデータからの安全な自動復旧 | **未確定** |
| 変更前にすべての旧／新パスをフェンスできるか | **全経路では未検証** |
| 新PoCのMariaDB、権限不足、複数PHPホスト、移行 | **未検証／要製品仕様確定** |
| `_v1`なしのテーブル名へのコード変更・移行 | **製品コード実装済み**。旧スキーマからの移行テストは修正後CI未確認 |

**安全性の受入条件**：変更後に古い公開索引から保護対象を誤配信しないこと、他ワーカーの更新IDを削除しないこと、正常なREADY公開に正本再監査・更新ID空・revision一致が必要なこと、未対応環境でstrictを誤って有効化しないこと。

**範囲外（現段階）**：パフォーマンスチューニング、管理画面、PRマージ。製品コードはPR #78に実装済み。最終CIと安全性検証は残る。

## 10. 根拠・成果物

- [v7：state 1列・追加テーブル0の仮実装（検証専用PR #77）](https://github.com/ponpaku/wp-easy-slug-protect/blob/test/issue-67-one-column-action-validation/docs/issue-67-state-only-prototype-v7.md)
- [v6：新規添付の作成中断とno-claim（検証専用PR #77）](https://github.com/ponpaku/wp-easy-slug-protect/blob/test/issue-67-one-column-action-validation/docs/issue-67-architecture-recovery-v6.md)
- [v5：Core再接続、デッドロック、新規添付多段SQL（検証専用PR #77）](https://github.com/ponpaku/wp-easy-slug-protect/blob/test/issue-67-one-column-action-validation/docs/issue-67-architecture-recovery-v5.md)
- [v4：hard-denyとatomic global fallbackの比較（検証専用PR #77）](https://github.com/ponpaku/wp-easy-slug-protect/blob/test/issue-67-one-column-action-validation/docs/issue-67-architecture-recovery-v4.md)
- [検証用Draft PR #77](https://github.com/ponpaku/wp-easy-slug-protect/pull/77)
- [Issue #67](https://github.com/ponpaku/wp-easy-slug-protect/issues/67)

**この文書は設計の統合版であり、PR #78の製品コードは実装済み。マージや本番採用の承認ではない。** main、PR #62、PR #77は変更していない。
