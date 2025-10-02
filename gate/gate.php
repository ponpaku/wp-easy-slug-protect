<?php
/**
 * 高速ゲートで保護ファイルを判別し配信情報を提供するメインロジック。
 * WordPress本体を起動させずに認証と配信可否判定を完結させる。
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

$config_key = isset($esp_gate_config['media_gate_key']) ? $esp_gate_config['media_gate_key'] : '';
if (!defined('ESP_GATE_EXPECTED_KEY')) {
    define('ESP_GATE_EXPECTED_KEY', is_string($config_key) ? $config_key : '');
}

// 直アクセス防止フラグをguard.phpで検証する
require __DIR__ . '/guard.php';
if (!defined('ESP_GATE_ENV_PASSED') || ESP_GATE_ENV_PASSED !== true) {
    http_response_code(403);
    return esp_gate_build_response(403, false);
}

// 環境変数のゲートキーと設定ファイルのキーを比較する
$env_key = esp_gate_read_server_env('ESP_MEDIA_GATE_KEY');
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
$uploads_webpc_base = isset($esp_gate_config['uploads_webpc_base']) ? $esp_gate_config['uploads_webpc_base'] : '';
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
        $candidate = __DIR__ . '/protected-files-' . $normalized_slug . '.php';
        if (is_readable($candidate)) {
            $protected_list_file = $candidate;
        }
    }
}

// サイト専用ファイルが無い場合は共通リストを参照する
if ($protected_list_file === '' && is_readable(__DIR__ . '/protected-files.php')) {
    $protected_list_file = __DIR__ . '/protected-files.php';
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
if ($protected_map === null) {
    http_response_code(403);
    return esp_gate_build_response(403, false, $absolute_path, $normalized_relative, true);
}

$path_id = isset($protected_map[$normalized_relative]) ? $protected_map[$normalized_relative] : null;
$protected = $path_id !== null && $path_id !== '';

$delivery_variant = esp_gate_resolve_media_variant(
    $absolute_path,
    $normalized_relative,
    array(
        'upload_base' => $upload_base,
        'uploads_webpc_base' => $uploads_webpc_base,
    )
);

$delivery_path = isset($delivery_variant['path']) ? $delivery_variant['path'] : $absolute_path;
$delivery_relative = isset($delivery_variant['relative']) ? $delivery_variant['relative'] : $normalized_relative;
$delivery_content_type = isset($delivery_variant['content_type']) ? $delivery_variant['content_type'] : null;
$delivery_nginx_relative = isset($delivery_variant['nginx_relative']) ? $delivery_variant['nginx_relative'] : null;

// 保護対象でなければそのまま配信を許可する
if (!$protected) {
    return esp_gate_build_response(200, true, $delivery_path, $delivery_relative, false, null, array(
        'requested_file_path' => $absolute_path,
        'requested_relative_path' => $normalized_relative,
        'content_type' => $delivery_content_type,
        'nginx_relative_path' => $delivery_nginx_relative,
    ));
}

// cookieプレフィックスを取得
$session_cookie_prefix = isset($esp_gate_config['session_cookie_prefix']) ? $esp_gate_config['session_cookie_prefix'] : 'esp_auth_';
$remember_id_prefix = isset($esp_gate_config['remember_id_cookie_prefix']) ? $esp_gate_config['remember_id_cookie_prefix'] : 'esp_remember_id_';
$remember_token_prefix = isset($esp_gate_config['remember_token_cookie_prefix']) ? $esp_gate_config['remember_token_cookie_prefix'] : 'esp_remember_token_';
$gate_cookie_prefix = isset($esp_gate_config['gate_cookie_prefix']) ? $esp_gate_config['gate_cookie_prefix'] : 'esp_gate_';

$authorized = esp_gate_check_cookie_authorization(
    $path_id,
    array(
        'session' => $session_cookie_prefix,
        'remember_id' => $remember_id_prefix,
        'remember_token' => $remember_token_prefix,
        'gate' => $gate_cookie_prefix,
    ),
    $config_key
);

// 対象Cookieが存在すれば閲覧を許可する
if ($authorized) {
    return esp_gate_build_response(200, true, $delivery_path, $delivery_relative, true, $path_id, array(
        'requested_file_path' => $absolute_path,
        'requested_relative_path' => $normalized_relative,
        'content_type' => $delivery_content_type,
        'nginx_relative_path' => $delivery_nginx_relative,
    ));
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
function esp_gate_build_response($status, $authorized, $file_path = null, $relative_path = null, $protected = false, $path_id = null, array $delivery = array())
{
    $delivery_path = isset($delivery['path']) ? $delivery['path'] : null;
    if (!is_string($delivery_path) || $delivery_path === '') {
        $delivery_path = $file_path;
    }

    $delivery_relative = isset($delivery['relative']) ? $delivery['relative'] : null;
    if (!is_string($delivery_relative) || $delivery_relative === '') {
        $delivery_relative = $relative_path;
    }

    $requested_file_path = isset($delivery['requested_file_path']) ? $delivery['requested_file_path'] : $file_path;
    $requested_relative_path = isset($delivery['requested_relative_path']) ? $delivery['requested_relative_path'] : $relative_path;
    $content_type = isset($delivery['content_type']) ? $delivery['content_type'] : null;
    $nginx_relative_path = isset($delivery['nginx_relative_path']) ? $delivery['nginx_relative_path'] : null;

    return array(
        'status' => (int) $status,
        'authorized' => (bool) $authorized,
        'file_path' => $delivery_path,
        'relative_path' => $delivery_relative,
        'protected' => (bool) $protected,
        'path_id' => $path_id,
        'requested_file_path' => $requested_file_path,
        'requested_relative_path' => $requested_relative_path,
        'delivery_content_type' => $content_type,
        'nginx_relative_path' => $nginx_relative_path,
    );
}

/**
 * 保護リストファイルを読み込む。
 *
 * @param string $protected_list_file 保護リストのパス。
 * @return array|null 保護対象のマップ。読み込めない場合は null。
 */
function esp_gate_read_protected_map($protected_list_file)
{
    if ($protected_list_file === '' || !is_readable($protected_list_file)) {
        // 読み込める保護リストがなければ失敗扱いとする
        return null;
    }

    if (substr($protected_list_file, -4) === '.php') {
        // guard.phpで認証済みフラグが立っている場合のみリストが返る
        $data = include $protected_list_file;
        if (!is_array($data)) {
            return null;
        }

        if (isset($data['items']) && is_array($data['items'])) {
            return $data['items'];
        }

        return $data;
    }

    $json = file_get_contents($protected_list_file);
    if (!is_string($json) || $json === '') {
        return null;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return null;
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
        // gate.php から配列以外が渡された場合はサーバーエラー扱い
        http_response_code(500);
        return null;
    }

    if (empty($context['file_path']) || !is_file($context['file_path'])) {
        // 実体となるファイルが無ければステータスに応じた404を返す
        http_response_code(isset($context['status']) ? (int) $context['status'] : 404);
        return null;
    }

    if (empty($context['authorized'])) {
        // 未認証の場合はステータスに応じた403を返す
        http_response_code(isset($context['status']) ? (int) $context['status'] : 403);
        return null;
    }

    return $context;
}

/**
 * Cookieを用いたHMAC検証でアクセス可否を判断する。
 *
 * @param string $path_id        保護パスID。
 * @param array  $prefixes       使用するCookie接頭辞。
 * @param string $media_gate_key ゲートキー。
 * @return bool 判定結果。
 */
function esp_gate_check_cookie_authorization($path_id, array $prefixes, $media_gate_key)
{
    $path_id = (string) $path_id;
    if ($path_id === '' || !is_string($media_gate_key) || $media_gate_key === '') {
        // 判定に必要なIDやゲートキーが欠落している
        return false;
    }

    $gate_cookie = esp_gate_extract_gate_cookie($path_id, $prefixes);
    if ($gate_cookie === null) {
        // 対象パスのゲートCookieが存在しない
        return false;
    }

    if ($gate_cookie['expires'] < time()) {
        // ゲートCookieの有効期限が切れている
        return false;
    }

    $token = esp_gate_resolve_login_token($path_id, $prefixes);
    if ($token === null) {
        // セッション／リメンバートークンが取得できない
        return false;
    }

    $payload = $path_id . '|' . $token . '|' . $gate_cookie['expires'];
    $expected_mac = hash_hmac('sha256', $payload, $media_gate_key);
    if (!is_string($expected_mac) || $expected_mac === '') {
        // HMAC生成に失敗した場合は認証不可
        return false;
    }

    return hash_equals($expected_mac, $gate_cookie['mac']);
}

/**
 * ゲートCookieを抽出してMACと有効期限を返す。
 *
 * @param string $path_id  保護パスID。
 * @param array  $prefixes Cookie接頭辞。
 * @return array|null macとexpiresを含む配列。
 */
function esp_gate_extract_gate_cookie($path_id, array $prefixes)
{
    if (!isset($prefixes['gate']) || $prefixes['gate'] === '') {
        // ゲートCookieの接頭辞が渡されていない
        return null;
    }

    $cookie_name = $prefixes['gate'] . $path_id;
    if (!isset($_COOKIE[$cookie_name])) {
        // 指定パスのゲートCookieが存在しない
        return null;
    }

    $value = $_COOKIE[$cookie_name];
    if (!is_string($value) || $value === '') {
        // Cookie値が文字列でない、もしくは空の場合
        return null;
    }

    $parts = explode('.', $value, 2);
    if (count($parts) !== 2) {
        // MACと有効期限のペアでない形式は無効
        return null;
    }

    list($mac, $exp) = $parts;
    if ($mac === '' || $exp === '' || preg_match('/[^0-9]/', $exp)) {
        // MACまたは期限が空、もしくは期限が数値でない
        return null;
    }

    if (!preg_match('/^[0-9a-f]{64}$/i', $mac)) {
        // MACがHMAC SHA-256の形式と一致しない
        return null;
    }

    $expires = (int) $exp;
    if ($expires <= 0) {
        // 期限が正の整数でない
        return null;
    }

    return array(
        'mac' => strtolower($mac),
        'expires' => $expires,
    );
}

/**
 * セッション／記憶Cookieから検証用トークンを抽出する。
 *
 * @param string $path_id  保護パスID。
 * @param array  $prefixes Cookie接頭辞。
 * @return string|null トークン値。
 */
function esp_gate_resolve_login_token($path_id, array $prefixes)
{
    if (isset($prefixes['session']) && $prefixes['session'] !== '') {
        $session_name = $prefixes['session'] . $path_id;
        if (isset($_COOKIE[$session_name]) && $_COOKIE[$session_name] !== '') {
            // セッションCookieが存在すればそれを利用する
            return (string) $_COOKIE[$session_name];
        }
    }

    if (isset($prefixes['remember_token']) && $prefixes['remember_token'] !== '') {
        $token_name = $prefixes['remember_token'] . $path_id;
        if (isset($_COOKIE[$token_name]) && $_COOKIE[$token_name] !== '') {
            if (isset($prefixes['remember_id']) && $prefixes['remember_id'] !== '') {
                $id_name = $prefixes['remember_id'] . $path_id;
                if (!isset($_COOKIE[$id_name]) || $_COOKIE[$id_name] === '') {
                    // トークンがあってもIDが欠けていれば破棄する
                    return null;
                }
            }

            // リメンバートークンとIDが揃っていればトークンを返す
            return (string) $_COOKIE[$token_name];
        }
    }

    // いずれのCookieからもトークンを得られなかった
    return null;
}
