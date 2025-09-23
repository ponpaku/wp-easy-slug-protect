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

            $file_type = wp_check_filetype($file_path);
            $mime_type = $file_type['type'] ?: 'application/octet-stream';

            $file_size = filesize($file_path);
            if ($file_size === false) {
                return false;
            }

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

            if ($this->is_x_sendfile_available()) {
                // サーバー側の高速転送機能が使える場合
                header('X-Sendfile: ' . $file_path);
                exit;
            }

            if ($this->is_x_accel_redirect_available()) {
                $internal_path = $this->get_nginx_internal_path($file_path);
                if ($internal_path) {
                    // Nginxの内部リダイレクトで処理を委譲
                    header('X-Accel-Redirect: ' . $internal_path);
                    exit;
                }
            }

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
}
