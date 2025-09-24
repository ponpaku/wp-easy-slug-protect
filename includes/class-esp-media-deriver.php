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
     * 配信方法のラベルを取得
     *
     * @return array<string, string>
     */
    public static function get_delivery_method_labels() {
        $text_domain = ESP_Config::TEXT_DOMAIN;

        return array(
            'auto' => __('自動判定', $text_domain),
            'x-sendfile' => __('X-Sendfile (Apache/mod_xsendfile)', $text_domain),
            'x-litespeed-location' => __('X-LiteSpeed-Location (LiteSpeed)', $text_domain),
            'x-accel-redirect' => __('X-Accel-Redirect (Nginx)', $text_domain),
            'php' => __('PHPによるストリーミング', $text_domain),
        );
    }

    /**
     * 現在の配信設定を取得
     *
     * @return string
     */
    private function get_configured_delivery_method() {
        $settings = ESP_Option::get_current_setting('media');
        $method = isset($settings['delivery_method']) ? $settings['delivery_method'] : 'auto';
        $allowed = array('auto', 'x-sendfile', 'x-litespeed-location', 'x-accel-redirect');

        // 許可された配信方法以外が設定されている場合は自動判定に戻す
        if (!in_array($method, $allowed, true)) {
            $method = 'auto';
        }

        return $method;
    }

    /**
     * 配信設定の診断結果を返す
     *
     * @return array<string, mixed>|WP_Error
     */
    public function run_delivery_diagnostics() {
        $test_artifact = $this->create_test_file();
        // テストファイルが用意できない場合は診断を中断
        if ($test_artifact === false) {
            return new WP_Error(
                'esp_media_delivery_test_failed',
                __('テスト用の一時ファイルを作成できませんでした。', ESP_Config::TEXT_DOMAIN)
            );
        }

        list($test_file, $test_dir) = $test_artifact;

        $available_methods = array(
            'x-sendfile' => $this->is_x_sendfile_available(),
            'x-litespeed-location' => $this->get_litespeed_internal_path($test_file) !== false,
            'x-accel-redirect' => $this->is_x_accel_redirect_available() && $this->get_nginx_internal_path($test_file) !== false,
        );

        try {
            $handler = $this->determine_delivery_handler($test_file);
        } finally {
            if (file_exists($test_file)) {
                @unlink($test_file);
            }
            $this->remove_test_directory($test_dir);
        }

        $result = array(
            'configured_method' => $this->get_configured_delivery_method(),
            'available_methods' => $available_methods,
            'resolved_handler' => $handler['type'],
        );

        // 取得したハンドラー情報に応じて追加情報を格納
        if (!empty($handler['path'])) {
            $result['resolved_path'] = $handler['path'];
        }
        if (!empty($handler['forced'])) {
            $result['forced'] = true;
        }
        if (!empty($handler['warning'])) {
            $result['warning'] = $handler['warning'];
        }

        return $result;
    }

    /**
     * テスト用の一時ファイルを作成
     *
     * @return array{0:string,1:string}|false
     */
    private function create_test_file() {
        $upload_dir = wp_upload_dir();
        // アップロードディレクトリが特定できない場合は失敗扱い
        if (!isset($upload_dir['basedir']) || $upload_dir['basedir'] === '') {
            return false;
        }

        $test_dir = trailingslashit($upload_dir['basedir']) . 'esp-temp';
        // テストディレクトリの作成に失敗した場合は中断
        if (!wp_mkdir_p($test_dir)) {
            return false;
        }

        $file_name = $test_dir . '/delivery-test-' . wp_generate_password(12, false) . '.tmp';
        // テストファイルの書き込みに失敗した場合は中断
        if (file_put_contents($file_name, 'test') === false) {
            $this->remove_test_directory($test_dir);
            return false;
        }

        return array($file_name, $test_dir);
    }

    /**
     * テスト用ディレクトリを削除
     *
     * @param string $test_dir ディレクトリパス
     * @return void
     */
    private function remove_test_directory($test_dir) {
        if (!is_string($test_dir) || $test_dir === '') {
            return;
        }

        if (!is_dir($test_dir)) {
            return;
        }

        $entries = @scandir($test_dir);
        if ($entries === false) {
            return;
        }

        // ディレクトリが空の場合のみ削除
        if (count(array_diff($entries, array('.', '..'))) === 0) {
            @rmdir($test_dir);
        }
    }

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

            // 実ファイルが存在しない場合はログのみ出力
            if (!file_exists($file_path) || !is_readable($file_path)) {
                error_log('ESP: Protected media not found or unreadable: ' . $file_path);
                return false;
            }

            // 既存のバッファ状態を記録
            $initial_ob_level = ob_get_level();
            while (ob_get_level() > max(0, $initial_ob_level - 1)) {
                ob_end_clean();
            }

            // 既にヘッダーが送信されている場合は安全のため終了
            if (headers_sent($file, $line)) {
                error_log("ESP: Headers already sent in $file on line $line");
                return false;
            }

            $file_size = filesize($file_path);
            // ファイルサイズが取得できない場合は続行できない
            if ($file_size === false) {
                return false;
            }

            $delivery_method = $this->determine_delivery_handler($file_path);

            // 配信時の警告がある場合はログに残す
            if (!empty($delivery_method['warning'])) {
                error_log('ESP: Media delivery warning (' . $delivery_method['warning'] . ') for ' . $file_path);
            }

            // Webサーバー配信が可能な場合はヘッダー委譲のみで終了
            if ($delivery_method['type'] !== 'php') {
                switch ($delivery_method['type']) {
                    case 'apache':
                        header('X-Sendfile: ' . $file_path);
                        break;
                    case 'litespeed':
                        if (empty($delivery_method['path'])) {
                            // 強制設定でも内部パスが無い場合は処理を中止
                            error_log('ESP: LiteSpeed internal path unavailable for ' . $file_path);
                            return false;
                        }
                        header('X-LiteSpeed-Location: ' . $delivery_method['path']);
                        break;
                    case 'nginx':
                        if (empty($delivery_method['path'])) {
                            // 内部パス不明時は安全のため中断
                            error_log('ESP: Nginx internal path unavailable for ' . $file_path);
                            return false;
                        }
                        header('X-Accel-Redirect: ' . $delivery_method['path']);
                        break;
                }
                exit;
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

            // チャンク配信が失敗した場合は false を返す
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
     * @return array{type:string,path?:string,forced?:bool,warning?:string}
     */
    private function determine_delivery_handler($file_path) {
        $configured = $this->get_configured_delivery_method();

        // 管理画面でX-Sendfileが強制されている場合
        if ($configured === 'x-sendfile') {
            $result = array(
                'type' => 'apache',
                'forced' => true,
            );

            // サーバー側で未対応の場合は警告を出す
            if (!$this->is_x_sendfile_available()) {
                $result['warning'] = 'x_sendfile_unavailable';
            }

            return $result;
        }

        // 管理画面でLiteSpeed連携が強制されている場合
        if ($configured === 'x-litespeed-location') {
            $path = $this->get_litespeed_internal_path($file_path, true);
            $result = array(
                'type' => 'litespeed',
                'forced' => true,
                'path' => $path !== false ? $path : '',
            );

            if ($path === false) {
                $result['warning'] = 'litespeed_path_unavailable';
            }

            return $result;
        }

        // 管理画面でNginx連携が強制されている場合
        if ($configured === 'x-accel-redirect') {
            $path = $this->get_nginx_internal_path($file_path);
            $result = array(
                'type' => 'nginx',
                'forced' => true,
                'path' => $path !== false ? $path : '',
            );

            if ($path === false) {
                $result['warning'] = 'nginx_path_unavailable';
            }

            return $result;
        }

        // 自動判定ではX-Sendfileが利用可能なら最優先
        if ($this->is_x_sendfile_available()) {
            return array(
                'type' => 'apache',
            );
        }

        $litespeed_path = $this->get_litespeed_internal_path($file_path);
        // LiteSpeedの内部パスが計算できる場合はヘッダー委譲
        if ($litespeed_path !== false) {
            return array(
                'type' => 'litespeed',
                'path' => $litespeed_path,
            );
        }

        // NginxでのX-Accel-Redirectが利用可能か確認
        if ($this->is_x_accel_redirect_available()) {
            $internal_path = $this->get_nginx_internal_path($file_path);
            if ($internal_path !== false) {
                return array(
                    'type' => 'nginx',
                    'path' => $internal_path,
                );
            }
        }

        return array(
            'type' => 'php',
        );
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

        // 範囲値が存在しない場合は不正要求として拒否
        if (!is_string($range_orig) || $range_orig === '') {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            if (function_exists('http_response_code')) {
                http_response_code(416);
            }
            header("Content-Range: bytes */$file_size");
            return false;
        }

        // bytes単位以外の要求はサポートしない
        if ($size_unit !== 'bytes') {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            if (function_exists('http_response_code')) {
                http_response_code(416);
            }
            header("Content-Range: bytes */$file_size");
            return false;
        }

        // 複数範囲の指定は未対応のため拒否
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
                // 末尾バイト数が未指定または数値でない場合はエラー
                if ($end_part === '' || !is_numeric($end_part)) {
                    header('HTTP/1.1 416 Requested Range Not Satisfiable');
                    if (function_exists('http_response_code')) {
                        http_response_code(416);
                    }
                    header("Content-Range: bytes */$file_size");
                    return false;
                }

                $suffix_length = (int) $end_part;
                // 要求サイズが0以下の場合は成立しない
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
                // 範囲開始が数値でない場合はエラー
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
                    // 範囲終了が数値でない場合はエラー
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

        // 計算結果の長さが正の値でなければ範囲を満たせない
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
        // ファイルを開けない場合は配信できない
        if ($fp === false) {
            return false;
        }

        try {
            fseek($fp, $c_start);

            $bytes_send = 0;
            while (!feof($fp) && !connection_aborted() && ($bytes_send < $length)) {
                $buffer = fread($fp, min(1024 * 16, $length - $bytes_send));
                // 読み込みに失敗した場合は中断
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
        // ファイルを開けない場合は false を返す
        if ($handle === false) {
            return false;
        }

        try {
            while (!feof($handle)) {
                $buffer = @fread($handle, $chunk_size);
                // 読み込みに失敗した場合は処理を中止
                if ($buffer === false) {
                    return false;
                }
                // チャンクごとにブラウザへ出力
                echo $buffer;

                // 出力バッファが存在する場合はフラッシュ
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                // クライアント切断時はループを抜ける
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
     * @param bool   $force     サーバー種別に関係なく相対パスを試算するか
     * @return string|false
     */
    private function get_litespeed_internal_path($file_path, $force = false) {
        // LiteSpeed以外のサーバーでは強制要求がない限り処理しない
        if (!$force && !$this->is_litespeed_server()) {
            return false;
        }

        $normalized_path = $this->normalize_path($file_path);

        $document_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
        // DOCUMENT_ROOT が判明している場合はそこから相対パスを算出
        if ($document_root !== '') {
            $normalized_root = rtrim($this->normalize_path($document_root), '/');
            if ($normalized_root !== '' && strpos($normalized_path, $normalized_root) === 0) {
                $relative = substr($normalized_path, strlen($normalized_root));
                return '/' . ltrim($relative, '/');
            }
        }

        $abs_path = rtrim($this->normalize_path(ABSPATH), '/');
        // ABSPATH を基準に内部パスを導出
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
     * X-Sendfileが利用可能か判定
     *
     * @return bool
     */
    private function is_x_sendfile_available() {
        // Apacheモジュール一覧からmod_xsendfileの有無を確認
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
        // アップロードディレクトリ配下のファイルかを確認
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
