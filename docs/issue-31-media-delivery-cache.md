# Issue #31 — ESP管理下メディアの直接URL・共有キャッシュ

## 範囲と判断

- 対象はWordPress標準メディアと、ESPのRewrite・認可・配信経路を通るファイル。
- 他の最適化プラグインが独自生成・独自配信するWebP/AVIF等は保護・高速配信の保証対象外（#40のCFM専用互換実装は行わない）。
- 本PRは既存の認可済みメディアのキャッシュヘッダーを修正する。#42（帰属判定）と#43（帰属不明時の拒否）は先取りしない。
- #31の実サーバー・共有キャッシュ経路の監査完了を意味しない。ステージングで検証した後にCloseを判断する。

## 修正する原因

従来のESP_Media_DeriverはMIMEタイプだけでCache-Controlを決定し、認証済み保護画像も public, max-age=31536000 を付けていた。PHP経由のRangeでも同様で、X-Sendfile / X-Accel-Redirect / X-LiteSpeed-Locationの委譲ではこの設定箇所を通らなかった。

## 実装上の契約

- ESP_Media_Protectionだけが既存認証・保護メタの判定を担当し、配信クラスへ保護レスポンスかどうかをboolで渡す。配信クラスは認証・DB照会を行わない。
- 保護対象（認証成功）にはHTTPのGET/HEAD/206/416およびWebサーバー委譲に no-store, private を付与。LiteSpeed専用の X-LiteSpeed-Cache-Control: no-store とNginx用 X-Accel-Expires: 0 も設定する。
- 早期に許可されるWordPress管理者・upload_files権限者はDBによる添付の公開判定を省略しているため、保守的にno-store扱い。
- 公開が確認されたメディアと保護OFFの配信は従来のMIME別キャッシュポリシーを維持する。
- 認証不足によるログインリダイレクトはWordPressの nocache_headers() を利用する。
- これはレスポンスのキャッシュ指示であり、入口でPHPに到達しない静的配信や、ヘッダーを無視するCDNを強制的に制御するものではない。

## 自動テスト

1. php tests/security-media-cache-policy-test.php — PHPローカルHTTPサーバーで保護/公開のGET/HEAD/Range 206/416とX-Sendfile委譲ヘッダーを検証。
2. php tests/security-head-delivery-test.php — HEADの本文不在、GETとRangeの内容。
3. php tests/security-rewrite-failclosed-test.php — Rewrite更新時のfail-closed・共存。
4. .github/scripts/test-wordpress-apache-http.sh — 独立したGitHub Actions `wordpress-apache-http`ジョブで使い捨てMySQL/Apache/WordPressを起動し、`wordpress-http-prepare.php`でTXT/JPEGを作成後、`wordpress-http-check.py`で認証前・認証後・ログアウト後のGET/HEAD/Range、ヘッダーと本文を検証。

## 残る実環境の検証（PRだけで完了扱いにしない）

| 経路 | 確認内容 |
| --- | --- |
| Apache/LiteSpeed Rewrite | 匿名・許可済み・ログアウト後、元ファイル・WordPress標準派生画像についてHTTPステータスだけでなく本文バイトが漏れないこと。LiteSpeedでRewriteが実際に適用されること。 |
| PHP 200/206/HEAD/416 | Cache-Control、Expires、Pragma、X-LiteSpeed-Cache-Control、X-Accel-Expiresの最終応答とRange本文一致。 |
| X-Sendfile / X-Accel-Redirect / X-LiteSpeed-Location | そのサーバーに実際に内部配信させ、最終的なヘッダーと認可済み・匿名レスポンス本文を確認。PHP単体テストは委譲前のヘッダーまでしか保証しない。 |
| CDN / LSCache / 共有Proxy | 認証済み要求の後、Cookie無し別クライアントから同一URLを取得し、キャッシュHITや本文の漏洩がないこと。既存の古い公開キャッシュは明示的にパージする。 |
| Nginx | 外部のlocation設定が静的バイパスを許さないこと（.htaccessは効かない）。 |
| 保護追加/解除・設定更新 | Rewrite、認可状態、CDN、ファイルの変更・失効・リライト更新後も安全なこと。 |

## 既知の非対象・リスク

- #42のResolver未実装により、一部WordPress標準派生ファイルは帰属を識別できない。帰属未解決時の既存通常配信は#43で是正する。#31全体を修正完了と扱わない。
- ローカルApache実HTTP試験はCDN・共有キャッシュ・LiteSpeed・Nginxの代用ではない。PHP未到達の静的配信や、既に共有キャッシュに存在する過去レスポンスは、コードのCache-Control変更だけでは保護できない。
- CFM等の第三者独自配信は仕様上保証しない。サイト側でその経路を使う場合の影響は運用者が引き受ける。
