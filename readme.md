# 任意階層パスワードプラグイン

名称: Easy Slug Protect
略称: esp

## タスク

- ショートコードの整理
- 管理画面説明実装
- 翻訳関連の修正

## プラグインファイル構造

```
easy-slug-protect/
├── easy-slug-protect.php        # メインファイル
├── uninstall.php               # アンインストール処理
├── readme.txt                  # プラグイン説明
│
├── includes/                  # コアファイル
│   ├── class-esp-core.php     # コアクラス
│   ├── class-esp-config      # 定数等
│   ├── class-esp-option      # 主にフロントでのオプション操作
│   ├── class-esp-auth.php     # 認証処理
│   ├── class-esp-cookie.php     # cookie操作
│   ├── class-esp-logout.php     # ログアウト操作
│   ├── class-esp-security      # 対攻撃処理
│   ├── class-esp-session.php  # セッション管理
│   └── class-esp-setup.php    # セットアップ
│
├── admin/                      # 管理画面
│   ├── classes/
│   │   ├── class-esp-admin-core.php      　# 管理画面コアクラス
│   │   ├── class-esp-admin-assets.php      # 管理画面用アセット管理クラス
│   │   ├── class-esp-admin-menu.php        # 管理画面作成クラス
│   │   ├── class-esp-admin-sanitize.php    # オプションサニタイズクラス
│   │   └── class-esp-admin-setting.php     #設定管理クラス
│   ├── esp-admin.js       # 管理画面JS
│   └── esp-admin.css      # 管理画面CSS
│
└── languages/                  # 翻訳ファイル（未実装）
    ├── easy-slug-protect-ja.po
    └── easy-slug-protect-ja.mo
```
