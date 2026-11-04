# Issue #67 — 書込み時DBゲートの再評価 v4：hard-deny と transactional invalidation

**2026-11-04／状態：検証用Draft PR #77の追加実験。製品未実装・本番未承認。**

この記録は [v3](issue-67-architecture-recovery-v3.md) の「失効した本人IDによる遅延SQLは必ずDB側で拒否する必要がある」という必要条件を改訂する。実WordPressの障害注入から、DBが正本変更と**同じInnoDBトランザクションでglobal coverageをdirtyにする**方式も後着SQLの匿名HTTP200反例を阻止できた。すべてのWordPress経路で安全と認定したわけではない。

## 結論：拒否ではなく、COMMIT時の認可整合性が必要

保護対象のWordPress正本SQLがCOMMITされた後、新しい保護所有者を見落とした古い索引をREADYとして配信しないことが本質的な必要条件。

- **hard-deny**：本人の未失効更新IDがなければ正本SQLをSIGNAL等で拒否する。
- **transactional invalidation**：本人IDが失効・消失した保護対象SQLに対し、WordPress正本更新と同一TXでglobal status=dirtyとrevision更新を確定し、SQL自体はCOMMITを許す。Resolverはglobal dirtyを503として拒否する。修復監査・CAS後のみREADYに戻す。

後者では遅延SQL自体は成功する。それでも、書込み後に古いREADYを残さないため、今回の匿名HTTP200反例を防止できた。

## 検証済みの実測（各Actions全8ジョブ成功）

| Actions | 実WordPress検証 | 留保 |
| --- | --- | --- |
| [#41749242213](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41749242213) | wp_posts INSERT/UPDATE/DELETE、post→attachment昇降格、by-midのmeta_key変更を、試験用本人IDトリガーで許可・拒否 | 特定フィクスチャ・試験専用gate table。全Core経路への製品実装ではない |
| [#41749854059](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41749854059) | 失効actorでwp_delete_attachment()するとCore戻り値false・wp_posts行残存・guardedメタ残存だが、unguardedメタは削除済み（2行→1行） | hard-denyだけではWordPress操作全体の原子性を保証できない。認可漏洩自体の実証ではない |
| [#41749453793](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41749453793) | PHP SIGKILL→旧チケットGC・local READY→保留SQL後送。AFTER UPDATEトリガーがglobal dirtyを正本と同一TXでCOMMIT、匿名HTTP503。正本を戻して再監査後READY | 無条件global invalidationの成立点。正常更新のたび全体503になる可用性コストは未評価 |
| [#41750162384](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41750162384) | **hybrid**：有効actor＋IDでglobal READY維持・局所フェンス。失効IDを持つ後着SQLはCOMMITしつつ同一TXでglobal dirtyに切替、匿名HTTP503→再構築成功 | 実証はwp_postmetaの特定UPDATE。トリガーの全経路網羅と製品統合は未完 |

再現ログ：ESP67_COVERAGE_PARTIAL_DELETE_SIDE_EFFECT_OBSERVED、ESP67_NETWORK_DELAY_ATOMIC_INVALIDATION_SAFE、ESP67_HYBRID_FAST_PATH_NO_GLOBAL_INVALIDATION、ESP67_NETWORK_DELAY_HYBRID_FALLBACK_SAFE。

## 現時点の優先候補：hybrid local-first + global fallback

既存メディア2テーブルを維持し、stateへのactive_mutations集合1列追加を基本案とする。ただし今回の実験は使い捨ての仮スキーマであり、製品の列1つだけで完成するとは断定しない。

**正常経路**

1. WordPress正本の変更前に、PHPが本人の一意更新ID・局所dirty・全候補パスを独立COMMITする。多段WordPress APIでは完了までチケットを保持。
2. Core SQL実行時、DBトリガーはその本人IDが未失効である場合、既に作成した局所フェンスを維持し、global READYには触れない。
3. 正本のCOMMIT確定後に冪等CASでID解除、再索引、revision一致・pending/ID空のREADY公開CASを行う。

**失効ID・欠落actor・PHP死亡後に届くSQL**

1. 正本SQLのDBトリガーが本人ID未確認を検出すると、正本SQLと同一InnoDB TXでglobal dirty＋revision更新。SQLを機械的に拒否する必要はない。
2. global dirtyを見た匿名Resolverは503。reaper・監査後、未完了ID無し／監査revision一致を確認してREADY再公開。
3. PHP生存中の過早GCを防ぐため、livenessとPHP側ロックの安全性は別途必要。MySQL上の実行中SQLも考慮し、局所reaperが無条件TTL削除しない。
4. Core再接続でセッションactorを失っても、成功した正本SQLがglobalをdirtyにするなら、無保証のREADY継続を回避できる可能性がある。実際のCore再接続・多段操作は未試験。

**推奨の変化**：v3では「DBが古いSQLを拒否する」ことを必須としたが、v4では「DB実行時に不確実なSQLを**拒否するか、正本と不可分に認可coverageを無効化する**」ことを安全性条件とする。後者のhybrid方式を実用候補として優先検証する。

## 未解決・採用前のP0検証

1. **全更新経路とOLD/NEW所有者**：wp_postmeta INSERT/UPDATE/DELETE、既存・新規のmeta_keyとpost_id、重複行、by-mid、一括DELETE、wp_posts作成・削除・post_type昇降格（INSERT時の未知ID）、複数添付。すべてでvalid actor局所／invalid actor globalを分けて検証する。
2. **WordPress多段操作の原子性**：hard-denyで部分削除が再現された。hybridでも先行フェンス・Coreの中間COMMIT・最終復旧の全時間帯で不正READYが存在しないことを実HTTPで確認する。
3. **ロック順序とデッドロック**：AFTER postmeta triggerは正本行を更新後にstateをロックし得る一方、監査やreaperはstate→postmetaの順を使う。逆順デッドロックとInnoDB victim選択、リトライ後の認可・復旧を障害注入する。
4. **本物のwpdb再接続／ACK消失**：actorセッション消失、再送、複数ワーカー、外部トランザクション下で「正本COMMIT＋global dirty」または「正本不変更」のどちらかが保証されること。
5. **運用制約**：CREATE TRIGGER権限、既存トリガー、MySQL/MariaDB、collation、DB proxy、マルチホスト、prefix・マルチサイト、移行・ロールバック。未対応環境へstrictを黙って有効化しない。
6. **可用性・性能**：正常valid actorのglobal失効発生率、更新SQLのp95、stateロック競合、global 503時間、局所503範囲、HTTP正常時クエリ数、Cron/HTTP修復の実測SLO。
7. **保証境界**：DDL/TRUNCATE、PHP迂回CDN、FTP等のファイル変更、未監視の直接SQL、DBトリガーを作れないホストは無条件に保証しない。

## 方針

- **優先探索**：hybrid（通常は局所、異常SQLはglobal dirtyを正本と同一TXで確定）。
- **代替候補**：無条件global transactional invalidation。後着SQLへの防護は実証したが通常アクセス停止の範囲が課題。
- **単独一般解としては不採用寄り**：hard-denyのみ。wp_delete_attachment()が一部成功・一部拒否になった実WordPress反例がある。
- **未承認事項**：製品コード統合、全経路保証、strict自動有効化、Issue #67クローズ、PR #77マージ、PR #62/main変更。

検証用コード：tests/wordpress-issue-67-write-coverage-trigger-ci.php、tests/wordpress-issue-67-flock-auto-recovery-ci.php、.github/scripts/test-wordpress-apache-http.sh。
