# Issue #67 — 設計選定のための比較判断 v6：作成中断と「所有パスなし」復旧

**2026-11-04／調査設計用。製品本体への実装判断・strict有効化ではない。**

[v5](issue-67-architecture-recovery-v5.md)で見つかった「新規添付の作成途中でPHPが異常終了すると、メタデータ未完成のためglobal dirtyが解除できず、全メディアの503が続く」問題について、実WordPress/MySQL/Apacheで障害注入し、異なる復旧ルールを比較した。

## 今回の結論

**「全添付が完全なファイル・メタデータを持つまでglobal READYを拒否する」ことは、安全な唯一の設計ではない。** 

正本にまだ所有パスの根拠が一切ない添付を、監査時点で「ゼロ件の所有パスを持つ添付（empty owner）」と分類してよい可能性がある。ただし、それは**すべての後続の所有パス追加・変更・削除をDB側が正本書込みと同一InnoDBトランザクションでglobal dirtyへ変更できる**場合に限る。READY復帰は試験専用SQLで模擬しており、本番用監査アルゴリズムの正しさを証明したものではない。

### E2E証拠

- [Actions #41768721844](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41768721844)：**8/8ジョブ成功**。新規シナリオと既存の後着SQL・hybrid CRUD・InnoDBロック試験を実行。
- 実WordPressでpost_type=attachment行を作った直後、PHPをSIGKILL。ファイル所有権に関係するメタデータは0件、索引の所有パスも0件であることを独立DB接続で確認。
- 現行の「添付情報は完全でなければならない」監査を実行するとglobal dirtyが続き、無関係な公開メディアの匿名HTTPは**503**。
- この1件を試験用に「所有パス0件の監査済みREADY世代」とみなして、global READYを**手動の模擬SQL**で公開すると、無関係な公開ファイルは匿名HTTP **200**。
- その後、実WordPressのadd_post_meta()が既存の公開画像パスを新添付の _wp_attached_file に追加。DBのAFTER INSERTトリガーが**同一TXでglobal dirty**を確定し、匿名HTTPは直ちに**503**（古いpublic索引からは返さない）。
- 不足していた画像メタデータと保護所有者を揃え、実監査を実行するとglobal READYに戻る。既存公開所有者と新保護所有者の競合を検知して匿名HTTP **403**。
- 試験添付をWordPress APIで非添付化・削除し、監査で復旧すると元の公開ファイルはHTTP **200**。試験トリガーは削除。

証拠マーカー：ESP67_EMPTY_OWNER_EXISTING_POLICY_GLOBAL_STUCK、ESP67_EMPTY_OWNER_SNAPSHOT_READY、ESP67_EMPTY_OWNER_LATER_CLAIM_FENCED、ESP67_EMPTY_OWNER_COMPLETED_CLAIM_DENIED、ESP67_EMPTY_OWNER_RESTORED。

**重要な区別**：途中の「empty-owner READY」は実製品の監査処理ではなく、条件成立性を確かめるための合成スナップショットである。現行のverify_ready_attachment()はメタ不足を常に失敗扱いするので、そのままではこの設計を実装できない。

## 候補の比較

| 候補 | 安全性に関する実測 | 採用判断上の問題 |
| --- | --- | --- |
| A. 失効IDの正本SQLを常にhard-deny | 遅延SQLは防止できた | wp_delete_attachment()で一部メタだけ削除された部分変更を実証。Core操作全体をアトミックにはできない |
| B. すべての所有権関連SQLで無条件global dirty、完全監査だけでREADY | 後着SQLの匿名HTTP200を阻止 | 正常更新でもglobal停止。メタ不足で全体503が長期継続し得る |
| C. 既存所有者には有効ID＋局所フェンス、失効時だけglobal dirty（v5 hybrid）、完全監査だけでREADY | 再接続、主要CRUD、後着SQLを実証 | 作成中断で未完成添付が残ると全体503を解除できない |
| **D. Cに「所有パスなしの監査済み状態」を追加し、新規作成時もDB側でglobal無効化** | **今回の障害注入で200→503→403→200を確認**。後からの所有権発生を同一TXで検知できた | 本物の監査CAS・同時実行・部分メタ・ファイルI/Oの保証が未完成 |

**暫定第一候補はD**。目的はglobal503の時間を微調整することではなく、**安全な自動復旧が完了可能な状態機械にすること**。A〜Cには今回の要件上の既知の不利がある。ただしDを製品仕様として承認する段階ではない。

## Dを採用するために必要な条件（設計検証のみ）

1. **no-claimの厳密な判定**：少なくとも今回の実験では、attachment行が存在し、_wp_attached_file、_wp_attachment_metadata、保護IDのいずれの正本メタも存在せず、索引にその添付の所有パスが0件。部分的・不正・重複メタまで安易にno-claimとしない。どのフィールドをcanonical ownership sourceと定義するか確定させる。
2. **監査と所有権SQLの競合**：global revision CAS付きの監査がno-claimを公開しようとする最中に、別ワーカーがpostmetaを書き込む障害を注入する。COMMIT済みの新所有者が存在する時点で旧public READYが生きないことを確認する。今回の手動スナップショットでは未実証。
3. **すべての所有権変更にDBフェンス**：wp_posts INSERT/UPDATE/DELETE、wp_postmeta INSERT/UPDATE/DELETE、OLD/NEW owner/key、by-mid、post_type昇降格、複数添付、直接SQL。DBトリガーを通らない更新・DDL・TRUNCATE・プラグイン外ファイル操作は保証範囲を明示して区別する。
4. **部分メタでのクラッシュ**：_wp_attached_fileだけ、_wp_attachment_metadataだけ、保護IDだけ、形式不正／重複などの途中状態でPHP死亡。安全性を保ちつつ永久global503とならないか、分類・隔離・回復ルールを選定する。すべてを空所有者扱いにするのは不可。
5. **ファイルシステムの先行書込み**：DBメタより前に物理ファイルが新設・置換された場合、DBトリガーだけでは捕捉できない。strictの脅威境界にどう含めるかを先に決める。
6. **既存の証明条件との整合**：state/postmetaのロック逆順によるInnoDB 1213は既に実証済み。局所チケット回収、rollback、後着SQL、DB再接続、DBトリガー権限、MariaDB/既存trigger共存について従来v4/v5の採用条件を継承する。

上記は**最終的な方式選定を左右する設計上の可否判定**。p95/p99改善、チューニング、移行実装の整備、管理UIなどは設計の可否が決まるまで実施しない。

## 試験コードとCI留保

試験用：tests/wordpress-issue-67-empty-owner-abort-ci.php と .github/scripts/test-wordpress-apache-http.sh。
試験ではWordPressのPHP側保護メタ追加がfalseを返しDBエラーがなかったため、最終保護メタだけは使い捨てDBへ直接INSERTしてDBフェンスを検証した。WordPress API全体の互換性保証ではない。

途中の失敗runは、追加試験のWordPress PHPメタ拒否・HTTP 403を503と期待した誤判定・後続TCP relayの固定ポート競合・起動確認スクリプトの誤りによるもの。#41768721844で修正後、8ジョブ成功を確認した。失敗runの情報は各Actionsログに保持されている。

**今回変更したのは検証用コード・CIのみ。PR #77はDraft、#67はOpen、製品本体・PR #62・mainは未変更。**
