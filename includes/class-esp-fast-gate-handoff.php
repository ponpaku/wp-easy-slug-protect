<?php
/**
 * Confidential, short-lived Fast Gate -> WordPress handoff.
 * Not an authorization token: WordPress rechecks MySQL credentials,
 * media ownership, the current SQLite path and all session revocations.
 */
final class ESP_Fast_Gate_Handoff {
    public const MAX_AGE = 60;
    private const DOMAIN = "ESP_FAST_GATE_HANDOFF_MAC_V1\0";
    private const AAD = "ESP_FAST_GATE_HANDOFF_AEAD_V1\0";

    private static function b64($bytes) {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode($input) {
        if (!is_string($input) || $input === '' ||
            !preg_match('/^[A-Za-z0-9_-]+$/D', $input)) { return null; }
        $bytes = base64_decode(strtr($input, '-_', '+/'), true);
        return $bytes !== false && self::b64($bytes) === $input ? $bytes : null;
    }

    public static function crypto_available() {
        return function_exists('openssl_encrypt') &&
            function_exists('openssl_decrypt') &&
            in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }

    public static function valid_relative($relative) {
        if (!is_string($relative) || $relative === '' || strlen($relative) > 2048 ||
            $relative[0] === '/' || strpos($relative, '\\') !== false ||
            strpos($relative, '%') !== false ||
            preg_match('/[\x00-\x1f\x7f]/', $relative)) { return false; }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment[0] === '.') { return false; }
        }
        return true;
    }

    private static function valid_context($site, $path, $relative, $method, $purpose, $key) {
        return is_string($site) && preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $site) &&
            is_string($path) && preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $path) &&
            self::valid_relative($relative) &&
            in_array($method, ['GET', 'HEAD'], true) &&
            in_array($purpose, ['auth', 'login'], true) &&
            is_string($key) && strlen($key) >= 32;
    }

    private static function mac_key($ticket_key) {
        return hash_hmac('sha256', "ESP_FAST_GATE_HANDOFF_KEY_V1\0", $ticket_key, true);
    }
    private static function encryption_key($ticket_key) {
        return hash_hmac('sha256', "ESP_FAST_GATE_HANDOFF_ENC_V1\0", $ticket_key, true);
    }

    public static function issue($site, $path, $relative, $method, $ticket_key,
                                 $now = null, $purpose = 'auth') {
        $now = $now ?? time();
        if (!self::crypto_available() ||
            !self::valid_context($site, $path, $relative, $method, $purpose, $ticket_key) ||
            !is_int($now) || $now < 1) { return null; }
        $payload = json_encode([
            'v' => 1, 'site' => $site, 'path' => $path,
            'file' => $relative, 'method' => $method, 'purpose' => $purpose,
            'iat' => $now, 'exp' => $now + self::MAX_AGE,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) { return null; }
        try { $nonce = random_bytes(12); }
        catch (\Throwable $e) { return null; }
        $tag = '';
        $cipher = openssl_encrypt($payload, 'aes-256-gcm',
            self::encryption_key($ticket_key), OPENSSL_RAW_DATA,
            $nonce, $tag, self::AAD, 16);
        if (!is_string($cipher) || strlen($tag) !== 16) { return null; }
        // The URL contains only ciphertext, not a decodable protected path.
        $part = self::b64($nonce . $tag . $cipher);
        $signature = hash_hmac('sha256', self::DOMAIN . $part,
            self::mac_key($ticket_key), true);
        return $part . '.' . self::b64($signature);
    }

    public static function verify($value, $site, $method, $ticket_key, $now = null) {
        $now = $now ?? time();
        if (!self::crypto_available() || !is_string($value) ||
            strlen($value) > 4096 || !is_string($ticket_key) ||
            strlen($ticket_key) < 32 || !is_int($now) || $now < 1) {
            return null;
        }
        $parts = explode('.', $value);
        if (count($parts) !== 2) { return null; }
        $bytes = self::decode($parts[0]);
        $signature = self::decode($parts[1]);
        if ($bytes === null || strlen($bytes) < 29 ||
            $signature === null || strlen($signature) !== 32) { return null; }
        $expected = hash_hmac('sha256', self::DOMAIN . $parts[0],
            self::mac_key($ticket_key), true);
        if (!hash_equals($expected, $signature)) { return null; }
        $payload = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm',
            self::encryption_key($ticket_key), OPENSSL_RAW_DATA,
            substr($bytes, 0, 12), substr($bytes, 12, 16), self::AAD);
        if (!is_string($payload)) { return null; }
        $data = json_decode($payload, true);
        if (!is_array($data) || count($data) !== 8 ||
            ($data['v'] ?? null) !== 1 ||
            ($data['site'] ?? null) !== $site ||
            ($data['method'] ?? null) !== $method ||
            !is_int($data['iat'] ?? null) || !is_int($data['exp'] ?? null) ||
            $data['iat'] > $now || $data['exp'] <= $now ||
            $data['iat'] < 1 || $data['exp'] !== $data['iat'] + self::MAX_AGE ||
            !self::valid_context($data['site'], $data['path'],
                $data['file'], $data['method'], $data['purpose'] ?? null,
                $ticket_key)) { return null; }
        return $data;
    }
}
