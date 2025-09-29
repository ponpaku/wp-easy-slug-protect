<?php
/**
 * 高速ゲートで保護ファイルを判別し配信情報を提供するメインロジック。
 *
 * @package EasySlugProtect
 */

// 環境変数取得や設定ファイル読込の共通関数を利用する
require_once __DIR__ . '/gate-utils.php';

if (!defined('ESP_GATE_CONFIG_ALLOWED')) {
    define('ESP_GATE_CONFIG_ALLOWED', true);
}

// サイト識別子から対象設定ファイルを推測する
$site_identifier = esp_gate_read_server_env('ESP_MEDIA_SITE_ID');
$site_token = esp_gate_normalize_site_token($site_identifier);
$esp_gate_config = esp_gate_load_config_for_site($site_token);
if (!is_array($esp_gate_config)) {
    $esp_gate_config = array();
}

// 直アクセス防止フラグをguard.phpで検証する
require __DIR__ . '/guard.php';
if (!defined('ESP_GATE_ENV_PASSED') || ESP_GATE_ENV_PASSED !== true) {
    http_response_code(403);
    return esp_gate_build_response(403, false);
}

// 環境変数のゲートキーと設定ファイルのキーを比較する
$env_key = esp_gate_read_server_env('ESP_MEDIA_GATE_KEY');
$config_key = isset($esp_gate_config['media_gate_key']) ? $esp_gate_config['media_gate_key'] : '';
if (!is_string($config_key) || $config_key === '' || !is_string($env_key) || $env_key === '') {
    http_response_code(403);
    return esp_gate_build_response(403, false);
}

if ($env_key !== $config_key) {
    http_response_code(403);
    return esp_gate_build_response(403, false);
}

// サイトIDが指定されている場合、設定ファイル側のサイト情報とも照合する
if ($site_token !== '') {
    $config_token = '';
    if (isset($esp_gate_config['site_slug'])) {
        $config_token = esp_gate_normalize_site_token($esp_gate_config['site_slug']);
    } elseif (isset($esp_gate_config['site_id'])) {
        $config_token = esp_gate_normalize_site_token($esp_gate_config['site_id']);
    }

    if ($config_token !== '' && $config_token !== $site_token) {
        http_response_code(403);
        return esp_gate_build_response(403, false);
    }
}

// ここまで通過したらゲートが有効と判断する
define('ESP_GATE_VERIFIED', true);

$upload_base = isset($esp_gate_config['upload_base']) ? $esp_gate_config['upload_base'] : '';
$protected_list_file = isset($esp_gate_config['protected_list_file']) ? $esp_gate_config['protected_list_file'] : '';
// 設定に保存されているサイト識別子を抽出する
$site_slug = '';
if (isset($esp_gate_config['site_slug'])) {
    $site_slug = $esp_gate_config['site_slug'];
} elseif (isset($esp_gate_config['site_id'])) {
    $site_slug = $esp_gate_config['site_id'];
}

if ($protected_list_file === '') {
    $normalized_slug = esp_gate_normalize_site_token($site_slug);
    if ($normalized_slug !== '') {
        // サイト識別子に紐づく保護リストを最初に候補へ加える
        $candidate = __DIR__ . '/protected-files-' . $normalized_slug . '.json';
        if (is_readable($candidate)) {
            $protected_list_file = $candidate;
        }
    }
}

// サイト専用ファイルが無い場合は共通リストを参照する
if ($protected_list_file === '' && is_readable(__DIR__ . '/protected-files.json')) {
    $protected_list_file = __DIR__ . '/protected-files.json';
}

// deriverから渡された相対パスを安全な形に整形する
$request = isset($_GET['file']) ? $_GET['file'] : '';
$request = is_string($request) ? $request : '';
$request = str_replace("\0", '', $request);

for ($i = 0; $i < 3; $i++) {
    $decoded = rawurldecode($request);
    if ($decoded === $request) {
        break;
    }
    $request = $decoded;
}

$request = ltrim($request, '/');
if ($request === '') {
    return esp_gate_build_response(400, false);
}

// バックスラッシュを含む場合でもスラッシュ区切りへ正規化する
$request = preg_replace('#\\\\+#', '/', $request);
$segments = array();
foreach (explode('/', $request) as $segment) {
    if ($segment === '' || $segment === '.' || $segment === '..') {
        continue;
    }
    $segments[] = $segment;
}
$normalized_relative = implode('/', $segments);

$absolute_path = '';
// アップロード基準パス配下に実ファイルがあるか確認する
$base_real = ($upload_base !== '') ? realpath($upload_base) : false;
if ($upload_base !== '' && $base_real !== false) {
    $candidate = $upload_base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized_relative);
    $resolved = realpath($candidate);
    if ($resolved !== false && strpos($resolved, $base_real) === 0) {
        $absolute_path = $resolved;
    }
}

// 実ファイルが存在しない場合は404として扱う
if ($absolute_path === '' || !is_file($absolute_path)) {
    return esp_gate_build_response(404, false, $absolute_path, $normalized_relative);
}

// 保護対象リストを読み込みファイルごとのIDを取得する
$protected_map = esp_gate_read_protected_map($protected_list_file);
$path_id = isset($protected_map[$normalized_relative]) ? $protected_map[$normalized_relative] : null;
$protected = $path_id !== null && $path_id !== '';

// 保護対象でなければそのまま配信を許可する
if (!$protected) {
    return esp_gate_build_response(200, true, $absolute_path, $normalized_relative, false);
}

// cookieプレフィックスを取得
$session_cookie_prefix = isset($esp_gate_config['session_cookie_prefix']) ? $esp_gate_config['session_cookie_prefix'] : 'esp_auth_';
$remember_id_prefix = isset($esp_gate_config['remember_id_cookie_prefix']) ? $esp_gate_config['remember_id_cookie_prefix'] : 'esp_remember_id_';
$remember_token_prefix = isset($esp_gate_config['remember_token_cookie_prefix']) ? $esp_gate_config['remember_token_cookie_prefix'] : 'esp_remember_token_';

$authorized = esp_gate_check_cookie_authorization($path_id, array(
    $session_cookie_prefix,
    $remember_id_prefix,
    $remember_token_prefix,
));

// 対象Cookieが存在すれば閲覧を許可する
if ($authorized) {
    return esp_gate_build_response(200, true, $absolute_path, $normalized_relative, true, $path_id);
}

http_response_code(403);
return esp_gate_build_response(403, false, $absolute_path, $normalized_relative, true, $path_id);

/**
 * gate.phpが返す標準レスポンスを構築する。
 *
 * @param int         $status          ステータスコード。
 * @param bool        $authorized      認可済みかどうか。
 * @param string|null $file_path       実ファイルパス。
 * @param string|null $relative_path   相対パス。
 * @param bool        $protected       保護対象かどうか。
 * @param string|null $path_id         保護パスID。
 * @return array レスポンス配列。
 */
function esp_gate_build_response($status, $authorized, $file_path = null, $relative_path = null, $protected = false, $path_id = null)
{
    return array(
        'status' => (int) $status,
        'authorized' => (bool) $authorized,
        'file_path' => $file_path,
        'relative_path' => $relative_path,
        'protected' => (bool) $protected,
        'path_id' => $path_id,
    );
}

/**
 * 保護リストファイルを読み込む。
 *
 * @param string $protected_list_file 保護リストのパス。
 * @return array 保護対象のマップ。
 */
function esp_gate_read_protected_map($protected_list_file)
{
    if ($protected_list_file === '' || !is_readable($protected_list_file)) {
        return array();
    }

    $json = file_get_contents($protected_list_file);
    if (!is_string($json) || $json === '') {
        return array();
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return array();
    }

    if (isset($decoded['items']) && is_array($decoded['items'])) {
        return $decoded['items'];
    }

    return $decoded;
}

/**
 * deriverへ返却するレスポンスの妥当性を確認する。
 *
 * @param mixed $context gate.phpの戻り値。
 * @return array|null 正常時はコンテキスト配列、異常時は null。
 */
function esp_gate_validate_deriver_context($context)
{
    if (!is_array($context)) {
        http_response_code(500);
        return null;
    }

    if (empty($context['file_path']) || !is_file($context['file_path'])) {
        http_response_code(isset($context['status']) ? (int) $context['status'] : 404);
        return null;
    }

    if (empty($context['authorized'])) {
        http_response_code(isset($context['status']) ? (int) $context['status'] : 403);
        return null;
    }

    return $context;
}

/**
 * Cookieを参照し保護対象へのアクセスを許可するか判定する。
 *
 * @param string $path_id   保護パスID。
 * @param array  $prefixes  確認するCookie接頭辞。
 * @return bool 判定結果。
 */
function esp_gate_check_cookie_authorization($path_id, array $prefixes)
{
    $suffix = (string) $path_id;
    foreach ($prefixes as $prefix) {
        if ($prefix === '') {
            continue;
        }

        $name = $prefix . $suffix;
        if (isset($_COOKIE[$name]) && $_COOKIE[$name] !== '') {
            return true;
        }
    }

    return false;
}
