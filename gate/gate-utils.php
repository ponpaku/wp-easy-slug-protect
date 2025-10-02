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
 * クエリストリングにoriginal指定が含まれる場合は画像変換を無効化する。
 *
 * .htaccessの `RewriteCond %{QUERY_STRING} original$` に相当する判定をゲート側で再現する。
 *
 * @return bool trueの場合は変換をスキップする。
 */
function esp_gate_should_skip_alternative_delivery()
{
    $query_string = (string) esp_gate_read_server_env('QUERY_STRING');
    if ($query_string === '') {
        return false;
    }

    return preg_match('/original$/', $query_string) === 1;
}

/**
 * Acceptヘッダーに指定されたMIMEタイプをサポートするか判定する。
 *
 * @param string $accept Acceptヘッダーの値。
 * @param string $mime   判定対象のMIMEタイプ。
 * @return bool 対応している場合はtrue。
 */
function esp_gate_accepts_mime($accept, $mime)
{
    $accept = strtolower(trim((string) $accept));
    $mime = strtolower(trim((string) $mime));

    if ($accept === '' || $mime === '') {
        return false;
    }

    $parts = explode(',', $accept);
    foreach ($parts as $part) {
        $item = trim($part);
        if ($item === '') {
            continue;
        }

        $segments = explode(';', $item);
        $type = trim(array_shift($segments));
        if ($type !== $mime) {
            continue;
        }

        $quality = 1.0;
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $pair = explode('=', $segment, 2);
            if (count($pair) !== 2) {
                continue;
            }

            if (trim($pair[0]) === 'q') {
                $quality = (float) trim($pair[1]);
            }
        }

        if ($quality <= 0) {
            continue;
        }

        return true;
    }

    return false;
}

/**
 * 変換画像の相対パスを生成する。
 *
 * .htaccess での書き換えと同じく、対象ファイルの拡張子を小文字へ
 * 正規化した上で変換フォーマットのサフィックスを連結する。
 *
 * @param string $relative_path 元の相対パス。
 * @param string $extension     元ファイルの拡張子（小文字）。
 * @param string $suffix        変換後ファイルのサフィックス（例: `.webp`）。
 * @return string 生成した相対パス。生成できない場合は空文字。
 */
function esp_gate_build_variant_relative_path($relative_path, $extension, $suffix)
{
    $relative_path = trim(str_replace('\\', '/', (string) $relative_path), '/');
    if ($relative_path === '') {
        return '';
    }

    $extension = strtolower((string) $extension);
    $suffix = (string) $suffix;

    if ($extension === '' || $suffix === '') {
        return '';
    }

    $normalized = preg_replace('/\.[^.]+$/', '.' . $extension, $relative_path, 1, $count);
    if (!is_string($normalized) || $normalized === '') {
        return '';
    }

    if ($count === 0) {
        $normalized .= '.' . $extension;
    }

    return str_replace('\\', '/', $normalized . $suffix);
}

/**
 * クライアントのAcceptヘッダーに応じて配信するファイルを決定する。
 *
 * .htaccessのWebP/AVIF書き換えルールと同等の判定をゲート環境で行い、
 * 利用可能な変換画像が存在する場合はそちらを優先的に返す。
 *
 * @param string $absolute_path      元のファイルの絶対パス。
 * @param string $relative_path      元のファイルの相対パス。
 * @param array  $options            追加オプション。
 * @return array 配信対象ファイルの情報。`nginx_relative` には変換済みファイルを `/protected-uploads-webpc` などの
 *               内部プレフィックスに連結するための相対パスが入る。
 */
function esp_gate_resolve_media_variant($absolute_path, $relative_path, array $options = array())
{
    $result = array(
        'path' => $absolute_path,
        'relative' => $relative_path,
        'content_type' => null,
        'nginx_relative' => null,
    );

    if (!is_string($absolute_path) || $absolute_path === '') {
        return $result;
    }

    if (esp_gate_should_skip_alternative_delivery()) {
        return $result;
    }

    $accept = isset($options['accept']) ? $options['accept'] : esp_gate_read_server_env('HTTP_ACCEPT');
    if (!is_string($accept) || $accept === '') {
        return $result;
    }

    $extension = strtolower((string) pathinfo($absolute_path, PATHINFO_EXTENSION));
    if ($extension === '') {
        return $result;
    }

    $document_root = isset($options['document_root']) ? $options['document_root'] : esp_gate_read_server_env('DOCUMENT_ROOT');
    $document_root = rtrim((string) $document_root, DIRECTORY_SEPARATOR);

    $upload_base = isset($options['upload_base']) ? $options['upload_base'] : '';
    $upload_base = rtrim((string) $upload_base, DIRECTORY_SEPARATOR);

    $uploads_webpc_base = isset($options['uploads_webpc_base']) ? $options['uploads_webpc_base'] : '';
    $uploads_webpc_base = rtrim((string) $uploads_webpc_base, DIRECTORY_SEPARATOR);

    $relative_from_upload_base = '';
    if ($upload_base !== '' && strpos($absolute_path, $upload_base) === 0) {
        $relative_from_upload_base = ltrim(substr($absolute_path, strlen($upload_base)), DIRECTORY_SEPARATOR);
    }

    $normalized_relative = '';
    if (is_string($relative_path) && $relative_path !== '') {
        $normalized_relative = trim(str_replace('\\', '/', $relative_path), '/');
    }

    if ($normalized_relative === '' && $relative_from_upload_base !== '') {
        $normalized_relative = trim(str_replace('\\', '/', $relative_from_upload_base), '/');
    }

    if ($normalized_relative === '' && $document_root !== '' && strpos($absolute_path, $document_root) === 0) {
        $relative_from_root = ltrim(substr($absolute_path, strlen($document_root)), DIRECTORY_SEPARATOR);
        $relative_from_root = trim(str_replace('\\', '/', $relative_from_root), '/');

        if ($relative_from_root === 'wp-content/uploads') {
            $relative_from_root = '';
        } elseif (strpos($relative_from_root, 'wp-content/uploads/') === 0) {
            $relative_from_root = substr($relative_from_root, strlen('wp-content/uploads/'));
        }

        if ($relative_from_root !== '') {
            $normalized_relative = $relative_from_root;
        }
    }

    $variants = array(
        array(
            'mime' => 'image/avif',
            'suffix' => '.avif',
            'extensions' => array('jpg', 'jpeg', 'png', 'webp'),
        ),
        array(
            'mime' => 'image/webp',
            'suffix' => '.webp',
            'extensions' => array('jpg', 'jpeg', 'png'),
        ),
    );

    foreach ($variants as $variant) {
        if (!in_array($extension, $variant['extensions'], true)) {
            continue;
        }

        if (!esp_gate_accepts_mime($accept, $variant['mime'])) {
            continue;
        }

        $candidate_paths = array();
        $variant_relative = '';
        if ($normalized_relative !== '') {
            $variant_relative = esp_gate_build_variant_relative_path(
                $normalized_relative,
                $extension,
                $variant['suffix']
            );
        }

        if ($variant_relative !== '') {
            if ($uploads_webpc_base !== '') {
                $absolute = $uploads_webpc_base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $variant_relative);
                $candidate_paths[] = array(
                    'absolute' => $absolute,
                    'nginx_relative' => $variant_relative,
                );
            }

            if ($document_root !== '') {
                $absolute = $document_root . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'uploads-webpc' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $variant_relative);
                $candidate_paths[] = array(
                    'absolute' => $absolute,
                );
            }
        }

        $visited = array();
        foreach ($candidate_paths as $candidate) {
            $absolute_candidate = isset($candidate['absolute']) ? $candidate['absolute'] : '';
            if (!is_string($absolute_candidate) || $absolute_candidate === '') {
                continue;
            }

            if (isset($visited[$absolute_candidate])) {
                continue;
            }
            $visited[$absolute_candidate] = true;

            if (!is_file($absolute_candidate)) {
                continue;
            }

            $result['path'] = $absolute_candidate;
            if (isset($candidate['nginx_relative'])) {
                $result['nginx_relative'] = str_replace('\\', '/', $candidate['nginx_relative']);
            } elseif ($variant_relative !== '') {
                $result['nginx_relative'] = str_replace('\\', '/', $variant_relative);
            }
            $result['content_type'] = $variant['mime'];

            return $result;
        }
    }

    return $result;
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
