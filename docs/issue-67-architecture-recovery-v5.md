# Issue #67 — hybrid方式の追加検証 v5：Core再接続・ロック逆転・添付作成

**2026-11-04。検証専用Draft PR #77の成果。製品本体・main未変更、strict本番未承認、#67 Open。**

本書は [v4](issue-67-architecture-recovery-v4.md) に続く追加検証。対象は使い捨て実WordPress 7.1.3、MySQL 8.0、PHP/Apache。試験専用の本人ID gate表とDBトリガーを用いており、製品コードには未統合。MySQL上のトリガー試験成功をWordPressの全書込み経路／MariaDB／一般ホストの安全保証とみなさない。

## 結論

**local-first + DB同一TXでのglobal dirty fallbackを第一候補として維持する。ただし新規添付の多段SQL、global 503の長期化、state/postmeta間の逆順ロックがP0の設計制約として具体化した。**

失効IDのSQLをすべてhard-denyする必要はないことを再確認した。SQL自体がCOMMITする場合も、同一TXで認可coverageを失効させれば、その後の匿名Resolverは古いREADYを信用しない。ただし、これを全操作で保証するトリガーと復旧プロトコルは未完成。

## 実WordPressの証跡と判定

| Actions | 結果 | 実測 |
| --- | --- | --- |
| [#41755057453](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41755057453) | **8/8成功** | WordPressのwpdbセッションを実際にKILL。再接続でactorセッション値を喪失したCore postmeta UPDATEがCOMMITする一方、hybrid AFTER triggerが同一TXでglobal dirtyを確定。匿名HTTP503→チケット回収・正本復旧・再監査後HTTP200 |
| [#41755315428](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41755315428) | **HTTPジョブ失敗** | ロック実験の同期ファイルをホストからtouchしてPermission denied。実験前のCI設定不備なので設計上の失敗とはしない |
| [#41755727917](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41755727917) | **HTTPジョブ失敗、ロック試験そのものはPASS** | reaperがstate0→postmeta、Core＋AFTER triggerがpostmeta→state0を取ることでInnoDBエラー1213のデッドロックを実際に発生。今回のvictimはreaperでロールバック、CoreはCOMMIT＋global dirty、匿名HTTP503→監査後READY。CI全体は後続CRUDの想定誤りで失敗 |
| [#41756202185](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41756202185) | **HTTPジョブ失敗、SQL順序の証拠は取得** | Core添付INSERT後、APIがPHPへ戻る前に新IDを使ったwp_posts UPDATEが実行された。INSERTはglobal actor有効、UPDATEはlocal actor未登録なのでglobal dirtyへfallback。ファイル／メタデータ未設定の新規添付を再監査してもREADYにならずretry |
| [#41756673654](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41756673654) | **8/8成功** | 実ファイルを用意し正本メタデータを設定すると新規添付のglobal dirtyから監査READYに回復。失効actorによるpostmeta UPDATE/DELETE、by-midキー変更、posts UPDATE、post→attachment昇格、wp_delete_attachment削除を実Coreで許可し、同一TXでglobal dirty確定。Resolverがerrorで旧索引を拒否し、監査後READY。valid actorのメタ操作・降格はglobal READY維持 |

### 確認された重要な反例・制約

1. **WordPress新規attachmentのID伝播**：SQLトレースで POST INSERT（owner=0, actor=ACTOR_A, fallback=0）→POST UPDATE（owner=new_id, actor=ACTOR_A, fallback=1）を確認。wp_insert_post()戻り後にPHPが局所IDを登録する設計では、そのAPI内の2本目のCore SQLに間に合わない。作成操作全体にglobal mutation IDを維持するか、DB側で新IDを有効な本人IDへ引き継ぐ仕組みが必要。
2. **メタデータ未完成時のglobal 503**：ファイルなしの新添付では監査retryとなり、global dirtyが継続。正本に実ファイル／メタデータが揃えばREADYへ復帰することは実証したが、通常アップロード中断・PHP死亡・メタ生成失敗・Cron停止時に無期限503を起こさない方式は未実証。単純な期限付きREADY化は認可を破るので不採用。
3. **ロック順序**：AFTER triggerはCore SQLがpostmeta行を保持した後にstateを取得するが、現行想定のreaperはstateを先に取得する。この逆順はInnoDB deadlock 1213を実測。今回reaperがvictimでCoreはglobal dirtyと共に成功したが、常に同じvictimになる保証も再試行安全性の証明もない。
4. **hard-denyの副作用**：v4で、失効actorによるwp_delete_attachment()のhard-denyは一部メタのみ削除してwp_posts行を残す部分変更を実証。今回のhybridでは限定fixtureのCore削除が成功しglobal dirtyから復旧した。ただしファイル削除・中間COMMIT・全フックの原子性まで保証していない。
5. **正常valid actorの局所性**：既存attachmentのメタ更新、by-midのvalid rename、attachment→post降格はglobal READY維持を確認。新規attachmentは例外で、操作全体のグローバル更新保留が現実的に必要となる。

## 製品の採用ゲート

- **P0 原子的認可フェンス**：保護対象の正本SQLがvalidな本人IDと先行予約を持たないなら、そのSQLと同一InnoDB TXでglobal dirty＋revision更新を確定。デッドロック／ROLLBACK時に部分的なREADYが発生しない。global READY公開CASとResolverはpending=0・更新ID集合空・revision一致を要求する。
- **P0 Core多段更新の追跡**：新規添付作成・post_type昇降格・wp_posts削除・postmeta INSERT/UPDATE/DELETE、旧／新key・旧／新owner・by-mid・複数添付・一括SQL・重複行をすべて網羅。新規IDが未確定な操作では先行global IDを維持する。
- **P0 安全な自動復旧**：PHP SIGKILL／DB再接続／TCP後着SQL／SQL実行中SQL／global作成中断とメタ欠落を組み合わせ、通常操作で全体503が無期限に残らないこと。Cron・影響HTTPでのオンデマンド修復、復旧時間の測定。
- **P0 デッドロック対策**：state/postmetaの単一ロック順序または有限・冪等なreaper/更新側のデッドロック再試行。Core側とreaper側の双方をvictimにした独立試験。SQL再送・不明COMMITを含む。
- **P0 DB導入互換性**：MySQL/MariaDBのトリガーSQL、CREATE TRIGGER権限、既存トリガー、collation、prefix・マルチサイト、DB proxy、複数PHPホスト、移行とロールバック。満たさない環境でstrictを黙ってONにしない。
- **P1 性能・可用性**：通常更新のglobal invalidation率、更新SQL p95/p99、state競合、global/局所503継続、再構築実時間、正常HTTP照会コストを実測。

## 次の優先試験

**新規添付の作成途中にSIGKILLしても、global更新IDを安全に回収し、メタデータ未完成で全サイトが503に固定されない方式を比較すること。** これは今回の新しい最大の採用条件。併せて逆順ロック回避策と、MariaDB/既存トリガー環境での対応を検証する。

検証ファイル：tests/wordpress-issue-67-flock-auto-recovery-ci.php、tests/wordpress-issue-67-hybrid-deadlock-ci.php、tests/wordpress-issue-67-hybrid-crud-ci.php、.github/scripts/test-wordpress-apache-http.sh。

PR #77は検証専用Draft、#67はOpen、製品本体・PR #62・mainに変更なし。
