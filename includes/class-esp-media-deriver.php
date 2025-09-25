<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * メディアファイルの配信処理を担当するクラス
 */
class ESP_Media_Deriver {
    /**
     * メディアファイルを配信
     *
     * @param string $file_path ファイルパス
     * @return bool 成功時true、失敗時false
     */
    public function deliver($file_path) {
        try {
            if (!is_string($file_path) || $file_path === '') {
                // 無効なパスは処理しない
                return false;
            }

            if (!file_exists($file_path) || !is_readable($file_path)) {
                // ファイルが存在しない場合はログのみ出力
                error_log('ESP: Protected media not found or unreadable: ' . $file_path);
                return false;
            }

            // 既存のバッファ状態を記録
            $initial_ob_level = ob_get_level();
            while (ob_get_level() > max(0, $initial_ob_level - 1)) {
                ob_end_clean();
            }

            if (headers_sent($file, $line)) {
                error_log("ESP: Headers already sent in $file on line $line");
                return false;
            }

            $file_size = filesize($file_path);
            if ($file_size === false) {
                return false;
            }

            $delivery_method = $this->determine_delivery_handler($file_path);

            // Webサーバー配信が可能な場合はヘッダー委譲のみで終了
            if ($delivery_method['type'] !== 'php') {
                if ($this->attempt_web_server_delivery($delivery_method, $file_path)) {
                    exit;
                }
            }

            $file_type = wp_check_filetype($file_path);
            $mime_type = $file_type['type'] ?: 'application/octet-stream';

            $range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : '';
            if ($range) {
                // Rangeヘッダーがある場合は分割配信
                if (!$this->deliver_with_range($file_path, $file_size, $mime_type, $range)) {
                    return false;
                }
                exit;
            }

            header('Content-Type: ' . $mime_type);
            header('Content-Length: ' . $file_size);
            header('Accept-Ranges: bytes');

            $disposition = $this->should_inline($mime_type) ? 'inline' : 'attachment';
            header('Content-Disposition: ' . $disposition . '; filename="' . basename($file_path) . '"');

            $this->set_cache_headers($mime_type);

            if (!$this->readfile_chunked($file_path)) {
                return false;
            }

            exit;
        } catch (Exception $e) {
            error_log('ESP: Exception in media deliver: ' . $e->getMessage());
            wp_die(
                __('ファイルの配信中に予期しないエラーが発生しました。', ESP_Config::TEXT_DOMAIN),
                __('システムエラー', ESP_Config::TEXT_DOMAIN),
                array('response' => 500)
            );
        }

        return true;
    }

    /**
     * 配信方法を判定
     *
     * @param string $file_path ファイルパス
     * @return array{type:string,path?:string}
     */
    private function determine_delivery_handler($file_path) {
        $preferred_method = $this->get_configured_delivery_method();

        if ($preferred_method !== 'auto') {
            // 管理画面で強制指定された方法を優先
            return $this->determine_forced_delivery_handler($file_path, $preferred_method);
        }

        if ($this->is_x_sendfile_available()) {
            // Apache系で利用できる場合
            return [
                'type' => 'apache',
            ];
        }

        $litespeed_path = $this->get_litespeed_internal_path($file_path);
        if ($litespeed_path !== false && $this->get_litespeed_access_key() !== '') {
            // LiteSpeedに渡す内部パスが判明した場合
            return [
                'type' => 'litespeed',
                'path' => $litespeed_path,
            ];
        }

        if ($this->is_x_accel_redirect_available()) {
            $internal_path = $this->get_nginx_internal_path($file_path);
            if ($internal_path !== false) {
                // Nginxの内部パスが利用可能な場合
                return [
                    'type' => 'nginx',
                    'path' => $internal_path,
                ];
            }
        }

        // いずれにも該当しない場合はPHPで配信
        return [
            'type' => 'php',
        ];
    }

    /**
     * Webサーバーにヘッダーを引き渡して配信させる
     *
     * @param array{type:string,path?:string} $delivery_method 判定済みの配信方法
     * @param string $file_path 実ファイルパス
     * @return bool ヘッダー送信に成功したらtrue
     */
    private function attempt_web_server_delivery($delivery_method, $file_path) {
        switch ($delivery_method['type']) {
            case 'apache':
                // X-Sendfileヘッダーを付与
                $this->clear_header();
                header('X-Sendfile: ' . $file_path);
                return true;

            case 'nginx':
                if (isset($delivery_method['path'])) {
                    // X-Accel-Redirectで内部リダイレクト
                    $this->clear_header();
                    header('X-Accel-Redirect: ' . $delivery_method['path']);
                    return true;
                }
                return false;

            case 'litespeed':
                // LiteSpeedでは内部リダイレクト先に認証キー付きのパスを指定する
                $access_key = $this->get_litespeed_access_key();
                if ($access_key === '') {
                    error_log('ESP: LiteSpeed access key unavailable. Falling back to PHP delivery.');
                    return false;
                }

                if (!isset($delivery_method['path'])) {
                    error_log('ESP: LiteSpeed redirect path missing. Falling back to PHP delivery.');
                    return false;
                }

                $redirect_path = $this->build_litespeed_redirect_path($delivery_method['path'], $access_key);
                if ($redirect_path === '') {
                    error_log('ESP: LiteSpeed redirect path unavailable. Falling back to PHP delivery.');
                    return false;
                }

                $this->clear_header();
                header('X-LiteSpeed-Location: ' . $redirect_path);
                return true;
        }

        return false;
    }

    /**
     * LiteSpeed等へ委譲する前に競合しうるヘッダーを削除
     */
    private function clear_header() {
        if (!function_exists('header_remove')) {
            return;
        }

        $headers = [
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
        ];

        foreach ($headers as $header) {
            header_remove($header);
        }

        if (function_exists('ini_set')) {
            // 既定のContent-Type付与を抑止
            ini_set('default_mimetype', '');
        }
    }

    /**
     * 設定で指定された配信方法を優先的に適用
     *
     * @param string $file_path ファイルパス
     * @param string $preferred_method 設定された配信方法
     * @return array{type:string,path?:string}
     */
    private function determine_forced_delivery_handler($file_path, $preferred_method) {
        switch ($preferred_method) {
            case 'x_sendfile':
                // Apache系サーバーでの配信を強制
                return [
                    'type' => 'apache',
                ];
            case 'litespeed':
                $litespeed_path = $this->get_litespeed_internal_path($file_path);
                if ($litespeed_path !== false && $this->get_litespeed_access_key() !== '') {
                    // LiteSpeedの内部リダイレクトパスが取得できた場合
                    return [
                        'type' => 'litespeed',
                        'path' => $litespeed_path,
                    ];
                }
                error_log('ESP: LiteSpeed delivery path unavailable. Falling back to PHP delivery.');
                break;
            case 'x_accel_redirect':
                $internal_path = $this->get_nginx_internal_path($file_path);
                if ($internal_path !== false) {
                    // Nginxの内部リダイレクトパスが取得できた場合
                    return [
                        'type' => 'nginx',
                        'path' => $internal_path,
                    ];
                }
                error_log('ESP: Nginx delivery path unavailable. Falling back to PHP delivery.');
                break;
            case 'php':
                // PHP配信を明示的に指定
                return [
                    'type' => 'php',
                ];
        }

        // 条件を満たさない場合はPHPにフォールバック
        return [
            'type' => 'php',
        ];
    }

    /**
     * メディア設定で選択された配信方法を取得
     *
     * @return string
     */
    private function get_configured_delivery_method() {
        $media_settings = ESP_Option::get_current_setting('media');
        if (is_array($media_settings) && isset($media_settings['delivery_method'])) {
            // 保存された配信方法を利用
            return $media_settings['delivery_method'];
        }

        // 保存が無い場合は自動判定
        return 'auto';
    }

    /**
     * Range対応の配信処理
     *
     * @param string $file_path ファイルパス
     * @param int    $file_size ファイルサイズ
     * @param string $mime_type MIMEタイプ
     * @param string $range     Rangeヘッダー
     * @return bool 成功時true
     */
    private function deliver_with_range($file_path, $file_size, $mime_type, $range) {
        // Rangeヘッダーを単位と値に分割
        list($size_unit, $range_orig) = explode('=', $range, 2);

        if (!is_string($range_orig) || $range_orig === '') {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            if (function_exists('http_response_code')) {
                http_response_code(416);
            }
            header("Content-Range: bytes */$file_size");
            return false;
        }

        if ($size_unit !== 'bytes') {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            if (function_exists('http_response_code')) {
                http_response_code(416);
            }
            header("Content-Range: bytes */$file_size");
            return false;
        }

        if (strpos($range_orig, ',') !== false) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            if (function_exists('http_response_code')) {
                http_response_code(416);
            }
            header("Content-Range: bytes */$file_size");
            return false;
        }

        if ($range_orig === '-') {
            // "-"のみの要求は末尾1バイトを指す
            $c_start = $file_size - 1;
            $c_end = $file_size - 1;
        } else {
            // 範囲指定の開始・終了を抽出
            $range_parts = explode('-', $range_orig, 2);
            $start_part = isset($range_parts[0]) ? $range_parts[0] : '';
            $end_part = isset($range_parts[1]) ? $range_parts[1] : '';

            if ($start_part === '') {
                if ($end_part === '' || !is_numeric($end_part)) {
                    header('HTTP/1.1 416 Requested Range Not Satisfiable');
                    if (function_exists('http_response_code')) {
                        http_response_code(416);
                    }
                    header("Content-Range: bytes */$file_size");
                    return false;
                }

                $suffix_length = (int) $end_part;
                if ($suffix_length < 1) {
                    header('HTTP/1.1 416 Requested Range Not Satisfiable');
                    if (function_exists('http_response_code')) {
                        http_response_code(416);
                    }
                    header("Content-Range: bytes */$file_size");
                    return false;
                }

                // 要求バイト数がファイルサイズを超えないよう調整
                $suffix_length = min($suffix_length, $file_size);
                if ($file_size === 0 || $suffix_length === 0) {
                    // ゼロバイトは満たせない
                    header('HTTP/1.1 416 Requested Range Not Satisfiable');
                    if (function_exists('http_response_code')) {
                        http_response_code(416);
                    }
                    header("Content-Range: bytes */$file_size");
                    return false;
                }
                $c_start = $file_size - $suffix_length;
                $c_end = $file_size - 1;
            } else {
                if (!is_numeric($start_part)) {
                    header('HTTP/1.1 416 Requested Range Not Satisfiable');
                    if (function_exists('http_response_code')) {
                        http_response_code(416);
                    }
                    header("Content-Range: bytes */$file_size");
                    return false;
                }

                $c_start = (int) $start_part;
                if ($end_part !== '') {
                    if (!is_numeric($end_part)) {
                        header('HTTP/1.1 416 Requested Range Not Satisfiable');
                        if (function_exists('http_response_code')) {
                            http_response_code(416);
                        }
                        header("Content-Range: bytes */$file_size");
                        return false;
                    }
                    $c_end = (int) $end_part;
                } else {
                    $c_end = $file_size - 1;
                }
            }
        }

        // 範囲がファイルサイズの内側に収まるよう補正
        $c_start = max(0, min($c_start, $file_size - 1));
        $c_end = max($c_start, min($c_end, $file_size - 1));
        $length = $c_end - $c_start + 1;

        if ($length < 1) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            if (function_exists('http_response_code')) {
                http_response_code(416);
            }
            header("Content-Range: bytes */$file_size");
            return false;
        }

        header('HTTP/1.1 206 Partial Content');
        if (function_exists('http_response_code')) {
            http_response_code(206);
        }
        header('Content-Type: ' . $mime_type);
        header('Content-Length: ' . $length);
        header("Content-Range: bytes $c_start-$c_end/$file_size");
        header('Accept-Ranges: bytes');

        $disposition = $this->should_inline($mime_type) ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disposition . '; filename="' . basename($file_path) . '"');

        $this->set_cache_headers($mime_type);

        $fp = @fopen($file_path, 'rb');
        if ($fp === false) {
            return false;
        }

        try {
            fseek($fp, $c_start);

            $bytes_send = 0;
            while (!feof($fp) && !connection_aborted() && ($bytes_send < $length)) {
                $buffer = fread($fp, min(1024 * 16, $length - $bytes_send));
                if ($buffer === false) {
                    return false;
                }
                echo $buffer;
                flush();
                $bytes_send += strlen($buffer);
            }
        } finally {
            fclose($fp);
        }

        return true;
    }

    /**
     * ファイルをチャンク単位で出力
     *
     * @param string $file_path ファイルパス
     * @param int    $chunk_size チャンクサイズ
     * @return bool
     */
    private function readfile_chunked($file_path, $chunk_size = 1048576) {
        $handle = fopen($file_path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            while (!feof($handle)) {
                $buffer = @fread($handle, $chunk_size);
                if ($buffer === false) {
                    return false;
                }
                // チャンクごとにブラウザへ出力
                echo $buffer;

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                if (connection_aborted()) {
                    break;
                }
            }
            return true;
        } finally {
            fclose($handle);
        }
    }

    /**
     * インライン表示判定
     *
     * @param string $mime_type MIMEタイプ
     * @return bool
     */
    private function should_inline($mime_type) {
        $inline_types = [
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
            'text/plain', 'text/html', 'text/css', 'application/javascript',
            'application/pdf', 'video/mp4', 'audio/mpeg', 'audio/mp3'
        ];

        return in_array($mime_type, $inline_types, true);
    }

    /**
     * キャッシュヘッダーを設定
     *
     * @param string $mime_type MIMEタイプ
     */
    private function set_cache_headers($mime_type) {
        if (strpos($mime_type, 'image/') === 0 ||
            strpos($mime_type, 'text/css') === 0 ||
            strpos($mime_type, 'application/javascript') === 0) {
            header('Cache-Control: public, max-age=31536000');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
        } else {
            header('Cache-Control: private, max-age=3600');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
        }
    }

    /**
     * LiteSpeedサーバーか判定
     *
     * @return bool
     */
    private function is_litespeed_server() {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? '';
        return stripos($software, 'litespeed') !== false;
    }

    /**
     * LiteSpeed内部パスを取得
     *
     * @param string $file_path ファイルパス
     * @return string|false
     */
    private function get_litespeed_internal_path($file_path) {
        if (!$this->is_litespeed_server()) {
            return false;
        }

        $normalized_path = $this->normalize_path($file_path);

        $document_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($document_root !== '') {
            $normalized_root = rtrim($this->normalize_path($document_root), '/');
            if ($normalized_root !== '' && strpos($normalized_path, $normalized_root) === 0) {
                $relative = substr($normalized_path, strlen($normalized_root));
                return '/' . ltrim($relative, '/');
            }
        }

        $abs_path = rtrim($this->normalize_path(ABSPATH), '/');
        if ($abs_path !== '' && strpos($normalized_path, $abs_path) === 0) {
            $relative = substr($normalized_path, strlen($abs_path));
            $home_path = parse_url(home_url('/'), PHP_URL_PATH);
            if (!is_string($home_path)) {
                $home_path = '/';
            }
            $base_path = rtrim($home_path, '/');
            $base_path = $base_path === '' ? '' : $base_path;

            return $base_path . '/' . ltrim($relative, '/');
        }

        return false;
    }

    /**
     * LiteSpeed用のアクセスキーを取得
     */
    private function get_litespeed_access_key() {
        if (!class_exists('ESP_Media_Protection')) {
            // クラスが無ければキーは扱えない
            return '';
        }

        // 設定に保存されているキーを取得
        $key = ESP_Media_Protection::get_litespeed_key_value();
        return is_string($key) ? $key : '';
    }

    /**
     * LiteSpeed内部リダイレクト用のパスを生成
     */
    private function build_litespeed_redirect_path($path, $key) {
        if (!is_string($path) || $path === '' || $key === '') {
            // 不正な入力の場合はリダイレクトを行わない
            return '';
        }

        if (function_exists('add_query_arg')) {
            return add_query_arg(
                array(ESP_Config::LITESPEED_QUERY_KEY => $key),
                $path
            );
        }

        $separator = strpos($path, '?') === false ? '?' : '&';
        return $path . $separator . ESP_Config::LITESPEED_QUERY_KEY . '=' . rawurlencode($key);
    }

    /**
     * X-Sendfileが利用可能か判定
     *
     * @return bool
     */
    private function is_x_sendfile_available() {
        if (function_exists('apache_get_modules') && in_array('mod_xsendfile', apache_get_modules())) {
            return true;
        }

        return false;
    }

    /**
     * X-Accel-Redirect対応か判定
     *
     * @return bool
     */
    private function is_x_accel_redirect_available() {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? '';
        return stripos($software, 'nginx') !== false;
    }

    /**
     * Nginx内部パスを取得
     *
     * @param string $file_path ファイルパス
     * @return string|false
     */
    private function get_nginx_internal_path($file_path) {
        $upload_dir = wp_upload_dir();
        if (strpos($file_path, $upload_dir['basedir']) === 0) {
            $relative_path = str_replace($upload_dir['basedir'], '', $file_path);
            return '/protected-uploads' . $relative_path;
        }
        return false;
    }

    /**
     * パスを正規化
     *
     * @param string $path 対象パス
     * @return string
     */
    private function normalize_path($path) {
        if (function_exists('wp_normalize_path')) {
            return wp_normalize_path($path);
        }

        return str_replace('\\', '/', $path);
    }
}
