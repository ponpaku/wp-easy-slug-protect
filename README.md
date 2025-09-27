# Easy Slug Protect

## 概要
Easy Slug Protect は、WordPress サイトの任意のスラッグ（URL パス）を簡単なパスワード認証で保護するプラグインです。会員限定ページやキャンペーン用ランディングページなど、ユーザー個々で認証を管理するほどではないケースでも、URL を共有するだけで統一的な保護を実現できます。特定の固定ページ単位ではなく、URL 階層ごとにアクセス制御を設定できるため、複数ページにまたがるセクションもまとめて保護可能です。URL ベースなのでページごとに保護設定を行う必要がありません。

## 主な機能
- パス（URL 階層）ベースでのパスワード保護
- ログイン試行回数の制限と一時ブロック（ブルートフォース対策）
- 画像などのメディアファイル保護
- 管理画面ダッシュボードでのログイン状況ウィジェット
- `[esp_login_form]` と `[esp_logout_button]` ショートコードによるフォーム設置

## 動作環境
- WordPress 最新安定版（5.9 以降を推奨）
- PHP 7.4 以上
- Apache もしくは Apache 互換サーバーを推奨

> **ヒント:** Nginx 利用時は、管理画面で案内されるリライトルールを適用するとメディア保護が有効になります。

## インストール
1. 本リポジトリをダウンロードし、`wp-easy-slug-protect` ディレクトリを ZIP 化する。
2. WordPress 管理画面の「プラグイン > 新規追加 > プラグインのアップロード」で ZIP をアップロードして有効化する。
   - もしくは、リポジトリを `wp-content/plugins/` 配下に配置してから有効化する。
3. 有効化時に必要なデータベーステーブルと定期実行イベントが自動で作成される。

## 基本的な使い方
1. ログインページとして利用する固定ページを作成し、本文に `[esp_login_form]` ショートコードを挿入する。
2. 「設定 > Easy Slug Protect」メニューを開き、「保護パスを追加」から保護したいパスとパスワードを登録する。
3. 必要に応じて以下の設定を調整する。
   - **ブルートフォース対策:** 試行回数の閾値・カウント対象時間・ブロック時間、IP ホワイトリスト
   - **ログイン保持:** ログイン状態を維持する日数と Cookie プレフィックス
   - **メール通知:** 保護パス追加やパスワード変更、ブルートフォース検出時の通知
   - **メディア保護:** メディアファイル配信方法（通常 / 署名付き URL）
4. ログアウトリンクを設置したいページには `[esp_logout_button text="ログアウト" redirect_to="/" ]` のようにショートコードを挿入する。

### ショートコードオプション
| ショートコード | オプション | 説明 |
| --- | --- | --- |
| `[esp_login_form]` | `path` | ログインフォームが属する保護パスを明示したい場合に指定する。 |
|  | `place_holder` | パスワード入力フィールドのプレースホルダーテキスト。 |
| `[esp_logout_button]` | `text` | ボタンに表示するテキスト。 |
|  | `redirect_to` | ログアウト後に移動する URL。 |
|  | `class` | ボタンに追加する任意の CSS クラス。 |
|  | `path` | ログアウト対象の保護パスを指定。未設定時はボタン設置ページのパスが対象。 |

## ディレクトリ構成
```
wp-easy-slug-protect/
├── easy-slug-protect.php   # プラグイン本体のエントリーポイント
├── includes/               # コアロジック
├── admin/                  # 管理画面 UI と設定ページ
├── front/                  # フロント側で使用するスタイルシート
└── uninstall.php           # アンインストール処理
```

## 開発者向けメモ
### includes 配下
- `includes/class-esp-core.php`: プラグインのブートストラップと共通フックの登録を担当。
- `includes/class-esp-config.php`: 設定値の読み込みと定数化を行う。
- `includes/class-esp-auth.php`: パスワード認証とログインセッションの検証を担当。
- `includes/class-esp-cookie.php`: 保護状態を維持するための Cookie 操作をまとめる。
- `includes/class-esp-logout.php`: ログアウト要求の処理と Cookie 破棄を担当。
- `includes/class-esp-path-matcher.php`: リクエスト URL と保護パスの突き合わせロジックを提供。
- `includes/class-esp-option.php`: プラグイン設定値の CRUD とキャッシュ層を管理。
- `includes/class-esp-filter.php`: コンテンツフィルターやフック経由の差し替え処理を集約。
- `includes/class-esp-security.php`: ブルートフォース対策やアクセス制限ポリシーを管理。
- `includes/class-esp-media-protection.php`: メディア保護のエントリーポイントと署名付き URL 生成を制御。
- `includes/class-esp-media-deriver.php`: メディアダウンロード時の署名検証とレスポンス生成を担当。
- `includes/class-esp-message.php`: 管理・フロントで使うメッセージ文言を一元管理。
- `includes/class-esp-mail.php`: 通知メールの組み立てと送信処理を司る。
- `includes/class-esp-setup.php`: 有効化・無効化・アンインストール時のセットアップ処理を管理。

### admin/classes 配下
- `admin/classes/class-esp-admin-core.php`: 管理画面での初期化処理と共通フックを束ねる。
- `admin/classes/class-esp-admin-menu.php`: 設定ページやサブメニューの登録を担当。
- `admin/classes/class-esp-admin-setting.php`: 設定画面のレンダリングと入力値保存を処理。
- `admin/classes/class-esp-admin-sanitize.php`: 管理画面で受け取る値の検証・サニタイズを行う。
- `admin/classes/class-esp-admin-assets.php`: 管理画面向けのスクリプト・スタイル読み込みを制御。

