# ADR (検証案): #67 メディア索引の更新・認可フェンス

- 更新日: 2026-11-02
- 状態: **推奨候補、製品実装は未着手／未承認**
- 対象: PR #62 branch（未マージ）の strict media index
- 検証コード: 検証専用 Draft PR #77、使い捨て WordPress 7.1.3 / MySQL 8.0 / MariaDB 10.11 / Apache
- 設計方針: **既存 \`esp_media_files_v1\` と \`esp_media_file_state_v1\` の2表を維持。stateに更新ID集合を1列追加。**
- 一次資料: [検証記録](issue-67-fence-protocol-validation.md)、[Issue #67](https://github.com/ponpaku/wp-easy-slug-protect/issues/67)

## 結論（推奨方式）

**基本経路：独立コミット済みの「添付単位の更新ID＋dirty＋候補パス」→呼出元接続のstate行ロック→WordPress正本更新→確定確認後の更新ID解除→再構築**。

**例外経路：候補パスを正本書込み前に確定できない場合、更新自体を拒否する。拒否できないCore action／仕様上許容したい更新のみ、独立コミット済みの「全体失効＋全体未完了更新ID」で認可を停止し、書込み終了後の完全監査までREADYを公開しない。**

これを「1列追加するだけ」とは呼ばない。**1列は冪等化に必要だが、主たるセキュリティ契約は失効情報の可視性・候補所有者の先行予約・完了までのREADY禁止・Cron公開CAS**である。テーブル追加は不要と見込む。

## 認可上の不変条件

**P0**:
1. WordPressの正本が新しい保護所有者をCOMMIT可能になる前に、その所有者の候補パスが配信Resolverから見える **か**、全体coverageが失効済みである。失効のCOMMITを確認できない書込みは通さない。
2. 他ワーカーや未確定WordPress TXの書込みがあり得る間、その添付をREADYとして匿名配信しない。代替の全体失効に未完了IDがある間、Cronはglobal READYを再公開しない。
3. SQL ACK消失・同じSQL再送・複数WordPress後フックで同じ更新を二度解放しない。各IDの登録と解放は冪等かつ原子的。
4. 独立フェンスは呼出元TXのROLLBACKでは消えない。正常Core書込み後の独立解放は、外部TXのCOMMIT/ROLLBACKより前に実行できない。
5. 保護対象の既存・将来の所有者競合を、既存の公開所有者だけで判定しない。証拠が不足する場合は403/503。
6. 異常終了を時計だけで生存しないと判断しない。復旧時は書込み停止を保証してから正本監査・ID解放を行う。

## 保存構造

\`esp_media_file_state_v1\`へ \`active_mutations\`（TEXT/LONGTEXT、順序正規化した更新ID配列）を1列追加する案。

- \`attachment_id>0\`: 当該添付の未完了更新ID。
- \`attachment_id=0\`: **全体未完了更新ID**（global coverage失効の代替経路に使用）。
- \`revision\`: 更新開始／終了のCAS世代。ACK喪失で同じSQLが再実行されても1回のみ遷移。
- \`pending_count\`: ID集合長から同じSQLで導出する派生数。独立した\`++/--\`を禁止。既存検索／Cronインデックスに利用可能。
- \`status\`: 残IDがある間dirty。ゼロになってもただちにREADYへ変更しない。必ず正本再構築／全体監査でREADY公開。
- IDは高エントロピー・一度解放したら再使用禁止。集合長／シリアライズ長を上限規制し、破損はfail-closed。
- \`files\`候補行は更新ID登録と**同一独立InnoDB TX**で確定する。競合する他所有者の存在をResolverから見落とさない。
- \`state0\` global READY公開CASとResolverは、**status=ready かつ pending_count=0 かつ active_mutations空**のみ認める。現行コードにはこのglobal pendingガードがなく、実WordPressで失敗を再現済み。追加すべき明示的な変更。

## WordPress経路別プロトコル

| WordPress操作 | 正本書込み前に可能な証拠 | 推奨 |
| --- | --- | --- |
| 通常の \`add/update/delete_post_metadata\` | 正本の添付IDと候補ファイル名、または既存パス | 添付単位の独立永続フェンス→候補予約→caller行ロック→Core→完了 |
| \`update_post_metadata_by_mid\` | prefilter段階では**最終サニタイズ値が未確定** | 早期に添付IDで更新IDだけ独立登録→\`update_postmeta\`（サニタイズ済、Core SQL前）で候補を独立予約→その後にcaller行ロック→Core→完了 |
| \`pre_post_update\`のpost→attachment昇格 | attachmentでない過去の投稿メタから推定可能な場合があるが、所有者自体は\`wp_posts\`更新時に発生 | 正確にすべて予約できるなら添付単位。そうでなければ**書込み拒否または全体更新ID付き失効**。旧TXに入れたdirtyだけでは失敗する |
| 添付削除／脱attachment、複数投稿にまたがるdelete_all | Core actionの一部は戻り値による取消不可 | 変更前に添付を特定できれば局所フェンス。それが不可能なら**例外を投げる／独立global失効**。削除後の孤立行除去と監査の同期が必要 |
| メタを伴わない新規添付／別プラグインの直接SQL | 先行フックがない場合がある | 未保証。Core側の書込み前フックを網羅、直接SQL等はスコープ外で明示する。要件によりDBトリガーなど別方式が必要 |

なお、通常のmetadata guardを途中で既存実装と併存させるだけでは、旧pending_countの過剰加減算が残るため移行完了とならない。製品変更では一元的なプロトコルへ切り替える。

## 「別接続だけでよい」が誤りである証拠

現行 \`wpdb\` の外部\`START TRANSACTION\`にstate dirty／候補を参加させ、ESP事前フック後に\`KILL CONNECTION\`すると、state書込みはROLLBACKされるがCoreはWordPress正本のSQLを再送・COMMIT。保護済み添付Bが公開添付Aのパスを所有し始めると、Bの索引は欠落し、匿名Apache GETはHTTP200でJPEG本文を返す。

[Actions #41368674214](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41368674214) は、同じ障害に対して、**別接続で先行COMMITした局所フェンス＋候補予約ならHTTP503**となる比較を実証。

しかし、独立コミットした**global dirtyだけ**では、WordPress正本書込み前にCronが全体監査を完了しREADYに戻せる。さらにcontrol row \`pending_count=1\`でも、現行Cronのglobal READY CAS／Resolverがglobal pendingを検証しないため**再公開後HTTP200となった**。

[Actions #41373652544](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41373652544) はこの2反例を独立Apacheの実HTTPで再現。

→ **global fallbackも、単なるepoch invalidationではなく、全体更新IDの存在をREADY公開の禁止条件にする必要がある。**

## 確認済みの成立点

- 実MySQL 8とMariaDB 10.11で、同一CAS SQLの2回適用、別接続CAS競合、別ワーカーID保全、行ロック待ちを確認。[Actions #41372161660](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41372161660)
- 実\`wpdb\`のMySQL COMMIT済みOK応答をTCP中継で破棄し、Coreが同じCAS SQLを再送しても、他者IDは保持。二重減算を回避。
- 実\`state\`に試験用1列追加、独立2ワーカー、実WordPressフック、匿名HTTP503／復旧302。
- **一体化試験**：局所独立コミット＋caller行ロック、外部TX COMMIT/ROLLBACK／KILL再接続、異常終了ID残留・明示回収、匿名HTTP503（進行中）→403（所有者衝突）→200（元の状態へ修復）。[Actions #41370951448](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41370951448)
- \`no-op\`・自動コミット・先行フェンスのロック競合拒否。 [Actions #41372161660](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41372161660)
- 実\`by-mid\`: prefilterが未サニタイズ、\`update_postmeta\`がサニタイズ後・正本SQL前であることをCoreと実試験で確認。サニタイズが候補ファイル名を変更するケースと外部TX切断後の候補保持にも成功。[Actions #41372841893](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41372841893)
- post→attachmentへの昇格で、旧TXフェンスが失われ、保護所有者が索引から欠落し、匿名HTTP200になる新たな経路を確認。独立global dirtyではCron競合がない限りHTTP503、全体監査後200へ復旧。[Actions #41373380964](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41373380964)

**注意**：試験コードは必要なWordPress metadata guardを一時差し替えて構造の原理を実証したもの。全mutation hookへ導入済みの製品実装・性能評価・サポート対象の下限MySQL/MariaDB検証ではない。

## 比較と設計選択

| 案 | 正本・索引の安全性 | 通常HTTPの可用性 | コスト／複雑さ | 判断 |
| --- | --- | --- | --- | --- |
| 現行\`pending_count++/--\` | SQL再送に対して失敗 | 平常時は高い | 低め | **却下** |
| 同じ\`wpdb\` TX内で更新ID集合CAS | ダブル解除は解消するが独立フェンスが消える | 高い | 低め | **却下** |
| 独立したper-attachment ID+候補予約+caller行ロック | 検証した通常/by-mid/TXケースを保護 | 他画像は平常どおり | 更新時に独立DB接続、2段階予約、復旧必要 | **第一推奨** |
| 独立global dirtyのみ | Cronに先行READYを再公開される | サイト全体が停止 | 単純だが不安全 | **却下** |
| 独立global pendingだけ、現行Cron | READYゲート未実装で200再現 | サイト全体停止でも漏れる | 小 | **却下** |
| 独立global更新ID + Cron READY CASガード + Resolver検証 | 原理上、書込み前競合に対する保護が期待できる | 全indexed media 503、完全監査が必要 | 構造は簡素だが稼働停止大 | **例外時の代替策候補（製品未実装）** |
| 毎匿名HTTPでWordPress全所有者を検索 | 強い整合チェック余地 | 遅い・スケールしない | 検索コスト大 | **不採用** |
| WordPress正本テーブルにDB trigger | トランザクション原子性の余地 | 全体監査と権限問題 | 他プラグイン/ホスティングに強く依存 | **一般的なWPプラグインでは不推奨** |

## 製品化のための残件（すべて合格までstrict切替不可）

1. 上記すべてのWordPress書込みフック、複数添付の更新、クロスディレクトリ移動、メタの重複、post type昇格／削除、no-op、遅いPHP shutdownで同じ安全条件を実装・試験。
2. 添付単位とglobal更新IDの**完了契約**：外部TX中は先行解除しない。COMMIT/ROLLBACKの検証手段なしに自動解除しない。php-fpm強制停止などでは手順化された安全な復旧のみ。
3. 外部DB接続の**同一primary確約**、ホスティングDB認証／Unix socket／ProxySQL／RDS／リードレプリカ／接続プール・上限確認。接続先不一致は厳格モードの書込み拒否。
4. SQL ACK消失時のフェンスCOMMIT自体の結果判別。更新IDの再使用禁止、CASリトライ上限、state集合容量と添付単位の同時更新上限。
5. \`state\` v3→v4移行：旧\`pending_count>0\`を安易に空集合へ変換しない。既存strict利用者の更新停止／退避／完全監査とロールバックを設計する。
6. index生成・削除と同時更新のSQLロック順序、複数WPワーカー、MySQL/MariaDB下限（検証済みはMySQL8/MariaDB10.11）、デッドロック注入とfail-closed。
7. 最終更新PHPの実装を使ったE2Eテスト、匿名HTTPクエリ回数・再構築速度・更新時追加接続の負荷計測。テスト用guardをプロダクションと誤認しない。

## 残余リスク・運用判断

WordPress正本を**他プラグインが直接SQLで編集する**場合、WordPressフック由来の先行フェンスを絶対に保証できない。この経路は要件上「対象外」と明記するか、WordPressテーブル側のDBトリガー等さらに侵襲的な仕組みを検討する必要がある。

また、PHP処理がクラッシュしたとき更新IDが残って対象画像が503になるのは安全側の挙動だが、可用性の障害。**タイムアウトのみのID解除は認めない**。管理画面に対象ID・残留時間を表示し、対象書込みを停止してから検証・再構築する運用を実装すべき。

実験的裏付けは「主要な防護条件を満たす方式を見つけた」という結論であり、**#67のクローズ・PR #62のmainマージ・strict自動有効化の承認とは異なる**。


## 全体更新IDのREADY公開ゲート：最終比較の実証

[GitHub Actions #41374325149](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/41374325149) **全8ジョブ成功**（WordPress 7.1.3 + MySQL 8.0.46 + Apache）。テスト \`tests/wordpress-issue-67-global-race-ci.php\` は、global prehookの直後・Core SQLの直前でPHPを停止し、別プロセスのWordPress Cronが全監査を試みる同じ障害順序を比較した。

| 実際のglobal制御行 | Cronの再公開 | 匿名HTTP | 結果 |
| --- | --- | --- | --- |
| 独立COMMITした\`status=dirty\`、pending=0 | **READYへ戻る** | **200・JPEG本文一致** | **不合格** |
| 独立COMMITした\`status=dirty,pending_count=1\`、現行Cron/Resolverは件数無視 | **READYへ戻る** | **200・JPEG本文一致** | **不合格** |
| 独立COMMITした\`status=dirty,pending_count=1\`、**READY公開を未完了IDの存在で禁止** | READYへ戻らない | **503・本文非配信** | **この競合に対する防護は成立** |
| 最後のID解除→完全監査 | READYへ戻れる | **200・正常復旧** | PASS |

最後の実証では**使い捨てのMySQL BEFORE UPDATEトリガー**で\`state0\`の\`pending_count>0\`中の\`status=ready\`への変更を拒否した。これは**試験専用の公開禁止条件の代用**であって、製品にDBトリガーを導入する案ではない。製品案は、\`run_batch()\`のglobal READY公開SQLに\`WHERE pending_count=0 AND active_mutations='[]'\`を追加し、既存\`revision\`/audit cursor CASと組み合わせること。また\`resolve()\`の既存1回のSQL内でも\`state0\`のpending/ID整合を検証して、READY破損状態からの公開を拒否する。

この実験は**「公開条件の不変条件は効く」ことを示すが、製品コードに公開CASを実装して全経路で検証したことを示さない**。

### 最終の設計採否

- **第一推奨**：attachment単位の独立先行COMMIT済みID集合＋dirty＋全候補予約、caller行ロック、WordPress正本確定後に自分のIDだけ解除、添付単位の再構築。匿名HTTPの停止範囲が小さい。
- **制限付き代替**：変更対象パスが事前に安全に列挙できず、そのWordPress更新を禁止できない場合、同じ\`state\`行ID=0に**独立COMMIT済みglobal更新ID**を登録し、Cron公開CAS/Resolverの双方でそのIDが残る限りREADYを禁止。完全監査が終わるまで全対象メディアを503にするため、最小限の例外に限る。
- **排除**：数値\`pending_count++/--\`、呼出元TX内だけのID、global dirtyだけ、global pendingを保存するだけで現行Cronを使う案。
- **重要な未完了条件**：全フックへの製品実装、process crash時の安全な復旧、混合バージョン移行、同一primaryへの独立接続、lock-orderや同時多数ワーカー、MariaDB旧バージョン、更新時性能。#67はOpen、strict本番自動有効化は不可。
