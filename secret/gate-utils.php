<?php
/**
 * 高速ゲートで共通利用するユーティリティ。
 *
 * @package EasySlugProtect
 */

/**
 * サーバー環境変数を取得する。
 *
 * @param string $key 取得するキー。
 * @return string 取得した値。存在しない場合は空文字列。
 */
function esp_gate_read_server_env($key)
{
    if (isset($_SERVER[$key])) {
        return $_SERVER[$key];
    }

    $redirect_key = 'REDIRECT_' . $key;
    if (isset($_SERVER[$redirect_key])) {
        return $_SERVER[$redirect_key];
    }

    $value = getenv($key);
    return $value !== false ? $value : '';
}

/**
 * サイト識別子をトークン化する。
 *
 * @param string $value サイト識別子。
 * @return string トークン化された識別子。
 */
function esp_gate_normalize_site_token($value)
{
    $value = strtolower((string) $value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim((string) $value, '-');
}

/**
 * LiteSpeedやNginx等へ制御ヘッダーを出力する前に不要なヘッダーを除去する。
 *
 * WordPress本体を起動しないゲート環境では、既に送出済みのヘッダーが残っていると
 * X系ヘッダーによる転送指示と競合する恐れがあるため、事前に関連ヘッダーを削除する。
 */
function esp_gate_clear_delivery_headers()
{
    if (function_exists('header_remove')) {
        $headers = array(
            'Content-Type',
            'Content-Length',
            'Content-Encoding',
            'Content-Range',
            'Accept-Ranges',
            'Content-Disposition',
            'Cache-Control',
            'Expires',
            'Pragma',
            'Last-Modified',
            'ETag',
        );

        foreach ($headers as $header) {
            header_remove($header);
        }
    }

    if (function_exists('ini_set')) {
        // 既定のContent-Type付与を抑止
        ini_set('default_mimetype', '');
    }
}

/**
 * サイトに対応する設定ファイルを読み込む。
 *
 * @param string $site_token サイト識別子のトークン。
 * @return array 読み込んだ設定。
 */
function esp_gate_load_config_for_site($site_token)
{
    $site_token = (string) $site_token;
    $candidates = esp_gate_collect_config_candidates($site_token);
    $host = $site_token === '' ? esp_gate_detect_request_host() : '';

    $fallback = array();
    foreach ($candidates as $path) {
        $config = esp_gate_require_config($path);
        if ($config === null) {
            continue;
        }

        // トークン指定がある場合は完全一致した設定を優先する
        if ($site_token !== '' && esp_gate_config_matches_token($config, $site_token)) {
            return $config;
        }

        // トークン未指定の場合はホスト名一致を優先する
        if ($site_token === '' && $host !== '' && esp_gate_config_matches_host($config, $host)) {
            return $config;
        }

        // 最初に読めた設定を最終フォールバックとして保持する
        if (empty($fallback)) {
            $fallback = $config;
        }
    }

    return $fallback;
}

/**
 * サイト識別子に基づき設定ファイル候補を列挙する。
 *
 * @param string $site_token サイト識別子のトークン。
 * @return array 候補パスの一覧。
 */
function esp_gate_collect_config_candidates($site_token)
{
    $candidates = array();

    if ($site_token !== '') {
        // 指定サイト向け設定を最優先で読み取る
        $candidates[] = __DIR__ . '/config-' . $site_token . '.php';
    }

    $candidates[] = __DIR__ . '/config.php';

    if ($site_token === '') {
        $site_configs = glob(__DIR__ . '/config-*.php');
        if (is_array($site_configs)) {
            sort($site_configs);
            foreach ($site_configs as $path) {
                if (!in_array($path, $candidates, true)) {
                    // 明示トークンが無い場合はサイト専用設定を順番に試す
                    $candidates[] = $path;
                }
            }
        }
    }

    return $candidates;
}

/**
 * 現在のリクエストからホスト名を取得する。
 *
 * @return string 検出したホスト名。
 */
function esp_gate_detect_request_host()
{
    if (isset($_SERVER['HTTP_HOST'])) {
        return strtolower((string) $_SERVER['HTTP_HOST']);
    }

    return '';
}

/**
 * 設定ファイルを読み込み、配列以外は無視する。
 *
 * @param string $path 読み込むパス。
 * @return array|null 設定配列。読めない場合は null。
 */
function esp_gate_require_config($path)
{
    if (!is_string($path) || $path === '' || !is_readable($path)) {
        return null;
    }

    $config = include $path;
    return is_array($config) ? $config : null;
}

/**
 * 設定内の識別子がトークンと一致するか確認する。
 *
 * @param array  $config     設定配列。
 * @param string $site_token トークン。
 * @return bool 一致した場合は true。
 */
function esp_gate_config_matches_token(array $config, $site_token)
{
    $config_token = '';
    if (isset($config['site_slug'])) {
        // 設定保存時のスラッグ情報を比較用トークンに整形
        $config_token = esp_gate_normalize_site_token($config['site_slug']);
    } elseif (isset($config['site_id'])) {
        // サイトIDを保管している場合も同様にトークン化
        $config_token = esp_gate_normalize_site_token($config['site_id']);
    }

    if ($config_token === '') {
        return false;
    }

    return $config_token === $site_token;
}

/**
 * 設定内のサイトURLがホスト名と一致するか確認する。
 *
 * @param array  $config 設定配列。
 * @param string $host   比較するホスト名。
 * @return bool 一致した場合は true。
 */
function esp_gate_config_matches_host(array $config, $host)
{
    if (!isset($config['site_url'])) {
        return false;
    }

    $parsed = parse_url($config['site_url'], PHP_URL_HOST);
    if (!is_string($parsed) || $parsed === '') {
        return false;
    }

    return strtolower($parsed) === $host;
}
