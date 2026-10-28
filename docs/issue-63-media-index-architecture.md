# Issue #63 — 専用メディア帰属・保護索引（段階的導入）

## 目的とデータ契約

WordPressの正本を `_wp_attached_file`・`_wp_attachment_metadata`・
`_esp_media_protected_path_id` とする。ESPの専用索引は派生データであり、
保護設定の正本として扱わない。WordPressが管理する添付の元ファイルと
現在の派生ファイルを uploads 相対パスで列挙し、**公開画像も含めて登録**する。

- `{prefix}esp_media_files_v1`：パス SHA-256、相対パス、添付ID、保護パスID。
  `(path_hash, attachment_id)` を複合主キーとし、複数所有者は複数行で保持。
  候補行のパスをPHPの `hash_equals()` で完全一致確認。
- `{prefix}esp_media_file_state_v1`：添付単位の `dirty/ready`、
  `pending_count`、`revision`、更新時刻。更新中の旧索引は拒否する。
- `resolve()`：`resolved(attachment_id, protected_path_id)`／
  `unresolved`／`ambiguous`／`error`。
  `unresolved` は公開可能と同義ではない。
- `ESP_Media_Protection::handle_media_access()`：厳格モード時のみ索引を利用。
  `unresolved/ambiguous` は403、`error`は503。解決成功時は最新の
  ESPパス設定と認証情報を確認する。ユーザーにより許可が変わる
  メディアレスポンスを共有キャッシュしてはならない。
  なお、通常PHP配信では専用索引を検索する**同じSQL文**でWordPressの
  保護メタをLEFT JOINし、コピー済み保護IDと不一致・重複なら503として拒否する。
  （DBへの往復は1回。索引行だけを権限の絶対的正本にはしない。）
- 高速ゲート #37 は索引クラスをHTTPから呼ばない。別途生成・検証する
  認可スナップショットの設計が必要。現段階でゲート自体は変更しない。

## 更新・失効の範囲

WP標準postmetaフックの**変更前**に添付状態をdirtyにしてDBへ永続化する。
メタ書込完了後（同じリクエスト）に正本をDBから再読込して、
ファイル行の差し替えと `ready` の公開を1トランザクションで行う。
並行書込は状態行のロックとpendingカウントで処理する。値が同じため
WordPressが後フックを呼ばない場合はリクエスト終了時に保留処理を終了する。
途中失敗時、古い索引行が残っていても `dirty` なので公開判断に使わない。

**保証範囲の制限：** WordPressフックを通過するメタデータ変更が対象。
直接SQL/FTP/外部配信/CDNは変更前失効を保証しない。フック外の変更に
対して定期Cronは将来の修復に役立つが、更新直後の未認証配信を防ぐ
機構の代用にはならない。また、要求時のチェックと実配信の間の
競合・瞬間的な認証失効を完全に排除するものではない。

実装側で失敗・破損を検知できることと、あらゆる更新方法を含めた
完全な即時整合性を保証することを混同しない。

## Cronバッチ

`esp_media_index_v1_batch` を5分間隔で予約。1回につき
最大200添付を照合して進捗を保存し、最初の全件走査が完了して
未完了の添付がない場合だけreadyマーカーを公開する。
その後も同じカーソルによって分割監査を継続する。
初期化・バックフィルのSQLは配信リクエスト中に実行しない。

長時間残るpendingは自動的に公開せず、管理者の点検を要する。
CronはWP-Cronなので、サイトのアクセス状況によって実行が遅延する。
数十万件規模ではサーバーCronによるWP-Cronの駆動を推奨する。

## 既存サイトでの導入

**自動で新方式に切り替えない。** 既存の未登録ファイルや第三者が生成する
メディアは、厳格モードで403になる可能性があるため、#43の管理外
ファイルの棚卸しと明示例外のポリシーを先に確定する。

管理者が十分な動作確認を行ったあと、次を検証する。

```bash
wp option get esp_media_index_v1_schema
wp option get esp_media_index_v1_ready
wp option get esp_media_index_v1_cursor
```

`schema=1, ready=1` は**初期照合を完了したこと**を意味し、
現在のDB・uploadsの任意変更まで完全に追跡できている証明ではない。

ステージングで厳格モードと既存URLの回帰検証を行う場合：

```bash
wp option update esp_media_index_v1_enabled 1
# 不具合・移行前サイトでは元の配信経路へ戻す：
wp option update esp_media_index_v1_enabled 0
```

ただし互換モードを選んだ場合、PR #62の `unresolved→通常配信` という
既知の#43未解決リスクが残る。戻したから保護が安全になるわけではない。
新テーブルは無効化後も残し、アンインストール時の削除は
データ移行・復旧手順を決めた段階で別途設計する。

## 本番マージ前の残作業

- WordPress標準更新フックの全経路（add/update/delete/by-mid）の実HTTP検証
- no-op/途中停止・トランザクション中断・実際の並行更新で未認証本文が
  流れないこと（#43とセット）
- 保護ON/OFFの既存サイト移行診断・明示的な未登録例外
- 全件バックフィルの負荷・DB使用量・Cron回復・複数サーバー構成
- #37のゲート版・保護設定・password_versionの同期契約
- WP 7.1のsideload/finalize・PDF/動画・異常メタデータの回帰
- 外部HTTP直配信・Rewrite/CDN/キャッシュ迂回（#31/#40）

**このPRはマージ可能な設計候補ではあるが、本番保証が未完成の限りDraftを維持する。**
