<?php
/**
 * LiteSpeedサーバー向けに保護ファイルを転送するドライバー。
 *
 * @package EasySlugProtect
 */

/** gate.php を読み込み、配信許可情報を取得する */
$context = require __DIR__ . '/gate.php';
if (!defined('ESP_GATE_VERIFIED') || ESP_GATE_VERIFIED !== true) {
    return;
}

$context = esp_gate_validate_deriver_context($context);
if ($context === null) {
    // gate.phpの検証に失敗した場合は異常終了
    return;
}

if (!isset($esp_gate_config) || !is_array($esp_gate_config)) {
    // 設定が読めない場合はサーバーエラーを返す
    http_response_code(500);
    return;
}

$litespeed_key = isset($esp_gate_config['litespeed_access_key']) ? $esp_gate_config['litespeed_access_key'] : '';
$query_key = isset($esp_gate_config['litespeed_query_key']) ? $esp_gate_config['litespeed_query_key'] : '';

if ($litespeed_key === '' || $query_key === '') {
    // 必要なキーが揃っていなければ配信できない
    http_response_code(500);
    return;
}

$internal_path = esp_gate_litespeed_internal_path($context['file_path'], $esp_gate_config);
if ($internal_path === '') {
    // 内部転送先を導けない場合は異常とする
    http_response_code(500);
    return;
}

$redirect_path = esp_gate_build_litespeed_redirect($internal_path, $query_key, $litespeed_key);
if ($redirect_path === '') {
    // リダイレクト先の構築に失敗した場合
    http_response_code(500);
    return;
}

esp_gate_clear_delivery_headers();
header('X-LiteSpeed-Location: ' . $redirect_path);
exit;

/**
 * LiteSpeed内部パスを推測する。
 *
 * @param string $file_path 配信対象の実ファイルパス。
 * @param array  $config    ゲート設定。
 * @return string 内部転送に利用するパス。
 */
function esp_gate_litespeed_internal_path($file_path, $config)
{
    $normalized = esp_gate_normalize_path($file_path);
    $document_root = isset($config['document_root']) ? $config['document_root'] : '';
    if ($document_root !== '') {
        $root = rtrim(esp_gate_normalize_path($document_root), '/');
        if ($root !== '' && strpos($normalized, $root) === 0) {
            // ドキュメントルート配下であれば絶対パスを相対パスへ変換する
            $relative = substr($normalized, strlen($root));
            return '/' . ltrim($relative, '/');
        }
    }

    $abs_path = isset($config['abs_path']) ? $config['abs_path'] : '';
    if ($abs_path !== '') {
        $abs = rtrim(esp_gate_normalize_path($abs_path), '/');
        if ($abs !== '' && strpos($normalized, $abs) === 0) {
            // WordPressのABSPATH配下であればホームパスに合わせて組み立てる
            $relative = substr($normalized, strlen($abs));
            $home_path = isset($config['home_path']) ? $config['home_path'] : '/';
            if (!is_string($home_path) || $home_path === '') {
                $home_path = '/';
            }
            $base_path = rtrim($home_path, '/');
            return ($base_path === '' ? '' : $base_path) . '/' . ltrim($relative, '/');
        }
    }

    return '';
}

/**
 * LiteSpeed向けのリダイレクトURLを生成する。
 *
 * @param string $path      内部転送先パス。
 * @param string $query_key アクセスキーのクエリパラメータ名。
 * @param string $key       アクセスキー。
 * @return string 内部転送用URL。
 */
function esp_gate_build_litespeed_redirect($path, $query_key, $key)
{
    if ($path === '' || $query_key === '' || $key === '') {
        return '';
    }

    $separator = strpos($path, '?') === false ? '?' : '&';
    return $path . $separator . $query_key . '=' . rawurlencode($key);
}

/**
 * パス区切り文字を正規化する。
 *
 * @param string $path 対象のパス。
 * @return string 正規化されたパス。
 */
function esp_gate_normalize_path($path)
{
    return str_replace('\\', '/', $path);
}
