# Issue #42: Media File Resolver — 設計・境界・計測

## 責務

`ESP_Media_File_Resolver::resolve($absolute_path)` はWordPress標準uploads内の実在ファイルの帰属を検証する。認可Cookie、保護パス設定、レスポンスの公開可否、Rewrite、CDNは変更しない。

| status | 意味 | 添付ID |
| --- | --- | --- |
| resolved | 候補範囲内で現在のメタデータによる所有者が1件 | 正整数 |
| unresolved | 検索が正常完了したが裏付けなし | なし |
| ambiguous | 裏付けのある異なる所有者が複数件 | なし |
| error | 入力・ファイル/DB条件不正、例外、検索・照合上限超過 | なし |

返り値は常に連想配列。メディア名や拡張子だけで保護/公開を確定しない。

## 設計

1. `wp_upload_dir()['basedir']` と対象ファイルを実体パスへ正規化し、ディレクトリ区切りを含めて境界を検証。uploadsルート自体のsymlinkは許容。配信入口`validate_file_path()`は3回以内でURLデコードした後、不正パスを「削って直す」代わりに拒否し、ルート以下にsymlinkコンポーネントがあれば拒否する。
2. WordPress命名規則の限定的な接尾辞（`-幅x高さ`、`-scaled`、`-rotated`、`-e数字`、`-pdf`、`-poster`）を外した親候補を生成。媒体間変換に対応する候補拡張子リストはResolver内1か所に限定。名前は候補選別のみ。
3. `_wp_attached_file` と`wp_posts.post_type='attachment'`をJOINして一括検索。`LIMIT 65`で64レコード超をエラーとする。候補パスは最大160件、接尾辞剥離は最大4回、`sizes`照合は128項目まで。
4. DB照合順序が大小文字を同一視しても、取得した`meta_value`をPHP側で厳密比較。`wp_get_attachment_metadata($id, true)` の現行`file`、`sizes[*].file`、`original_image`、`source_image`、`animated_video`、`animated_video_poster`だけを照合。旧バックアップ・`_wp_sideloaded_file`は所有証拠としない。
5. 単一所有者ならresolved、2件以上ならambiguous、DB・上限の問題ならerror。永続索引や専用DBテーブルを導入しない。

## #43までの配信互換性

現行配信呼出し`get_attachment_id_from_path()`は互換アダプターとして残す。新APIの`resolved`のみID、`unresolved`のみ従来の未登録`false`、`ambiguous`と`error`は`null`に対応させて既存503経路へ送る。**未登録を通常配信する挙動は残り、そのリスクを#43で扱う。** Resolverを導入しただけでfail-closed保証済みとは宣言しない。

## 試験

- `php tests/security-media-file-resolver-test.php`：4状態、通常派生、変換、scaled、rotated、PDF、編集、HEIC source、GIF動画/ポスター、重複、SQL障害、照合上限、シンボリックリンク、境界等。
- `php tests/security-avif-test.php`：既存AVIFの帰属・Rewrite回帰。
- `php tests/security-rewrite-failclosed-test.php`：添付DB照会失敗、未登録公開、Rewrite・パス検証回帰。
- `wordpress-apache-http` CI：現行添付メタデータに記録した保護JPEGの標準サムネイルについて、匿名拒否、認証済み配信、ログアウト後拒否をHTTP本文まで検証。

## 限界・追加計測

- SQLの`wp_postmeta.meta_value`には通常B-tree検索用索引がない。照合は最大64行に制限してもDB読み込みの費用を抑制しない。メディア数1万・10万、同時負荷、p95、EXPLAINは実データを用いた測定が必要。測定前に「高速」を保証しない。
- 候補生成に一致しない非標準派生の検出と、全添付ファイルを横断した任意の衝突の完全検出は保証しない。第三者プラグイン独自のファイル/RewriteはESP保証対象外。
- `_wp_sideloaded_file`しか存在しない未確定ファイルはunresolved。#43の安全な移行ポリシーが適用されるまでは既存の未登録公開例外を維持する。
- WordPress 7.1系で追加される特殊な`source_image`/GIF変換メタデータは実コアによる生成試験が必要。単体では想定されたメタデータ構造のみを検証している。
- ApacheのHTTP結合試験はLiteSpeed・Nginx・CDN実機試験の代替ではない。これらのHTTP検証と保護切替・状態同期は#31/#43でも扱う。

Refs #42, #43, #31.
