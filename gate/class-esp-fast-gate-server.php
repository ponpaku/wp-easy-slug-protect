<?php
/**
 * WordPress-independent Fast Gate v2 authorization engine.
 * Does not discover public files from disk; only explicit verified ledger
 * entries may be served. The web server must route uploads BEFORE static files.
 */
final class ESP_Fast_Gate_Server {
    private const EXT = [
        'jpg','jpeg','png','gif','webp','avif','svg','ico','bmp',
        'pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp',
        'mp4','avi','mov','wmv','flv','mkv','webm',
        'mp3','wav','ogg','wma','aac','flac',
        'zip','rar','7z','tar','gz','txt','csv','xml','json'
    ];

    private static function relative_path($uri, $url_prefix) {
        if (!is_string($uri) || strlen($uri) > 8192 ||
            !is_string($url_prefix) || $url_prefix === '' ||
            $url_prefix[0] !== '/' || substr($url_prefix, -1) !== '/') {
            return null;
        }
        $raw = parse_url($uri, PHP_URL_PATH);
        if (!is_string($raw) || strncmp($raw, $url_prefix,
                strlen($url_prefix)) !== 0) {
            return null;
        }
        $path = substr($raw, strlen($url_prefix));
        // Reject encoded separators and double-decode tricks, not just "..".
        if ($path === '' || preg_match('/%(?:2f|5c|25|00)/i', $path)) {
            return null;
        }
        $decoded = rawurldecode($path);
        if ($decoded === '' || $decoded[0] === '/' ||
            strpos($decoded, '\\') !== false ||
            preg_match('/[\x00-\x1f\x7f]/', $decoded)) {
            return null;
        }
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '' || $segment[0] === '.' ||
                strpos($segment, '%') !== false) {
                return null;
            }
        }
        $extension = strtolower(pathinfo($decoded, PATHINFO_EXTENSION));
        return in_array($extension, self::EXT, true) ? $decoded : null;
    }

    public static function authorize($uri, $cookies, $site, $root,
                                     $prefix, $sig, $idkey, $store, $now = null) {
        $relative = self::relative_path($uri, $prefix);
        if ($relative === null) { return ['status' => 403]; }
        if (!is_array($cookies) || !is_string($root) ||
            !is_dir($root) || !$store || !$store->serving()) {
            return ['status' => 503];
        }
        $entry = $store->get_media($relative);
        if ($entry === null) { return ['status' => 403]; }
        if ($entry['policy'] === 'deny') { return ['status' => 403]; }

        $protected = $entry['policy'] === 'protected';
        if ($protected) {
            $path = $entry['path_id'];
            $state = $store->get_path($path);
            if (!$state || $state['blocked']) { return ['status' => 403]; }
            $ticket = $cookies['esp_gate_' . $path] ?? null;
            $data = ESP_Fast_Gate_Token::verify(
                $ticket, $site, $path, $cookies, $state['version'],
                $sig, $idkey, $now
            );
            if (!$data) { return ['status' => 403]; }
            if ($store->is_revoked($data['ref']) !== false) {
                return ['status' => 403];
            }
        }

        // The uploads root may be a configured symlink; descendants may not.
        $logical = rtrim($root, '/');
        foreach (explode('/', $relative) as $part) {
            $logical .= '/' . $part;
            if (is_link($logical)) { return ['status' => 403]; }
        }
        $canonical_root = realpath($root);
        $file = realpath($logical);
        $allowed_prefix = rtrim((string)$canonical_root, '/') . '/';
        if ($canonical_root === false || $file === false ||
            strncmp($file, $allowed_prefix, strlen($allowed_prefix)) !== 0 ||
            !is_file($file) || !is_readable($file)) {
            return ['status' => 404];
        }
        return ['status' => 200, 'file' => $file,
            'protected' => $protected, 'relative' => $relative];
    }

    public static function content_type($filename) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $map = [
            'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
            'gif'=>'image/gif','webp'=>'image/webp','avif'=>'image/avif',
            'svg'=>'image/svg+xml','ico'=>'image/x-icon','bmp'=>'image/bmp',
            'pdf'=>'application/pdf','mp4'=>'video/mp4','webm'=>'video/webm',
            'mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg',
            'txt'=>'text/plain','csv'=>'text/csv','json'=>'application/json',
            'xml'=>'application/xml','zip'=>'application/zip',
        ];
        return $map[$extension] ?? 'application/octet-stream';
    }
}
