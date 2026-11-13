<?php
/**
 * Fast Gate v2: WP-independent, authenticated and token-bound gate tickets.
 *
 * A ticket is a proof that WordPress previously authenticated the session.
 * It is NOT evidence that a MySQL session is still present: the local
 * revocation ledger and current password_version must also be checked.
 */
final class ESP_Fast_Gate_Token {
    public const MAX_AGE = 86400; // Bounded tickets make revocation GC possible.
    private const DOMAIN = "ESP_FAST_GATE_TICKET_V2\0";
    private const ID_DOMAIN = "ESP_FAST_GATE_SESSION_V2\0";

    private static function b64($bytes) {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode64($value) {
        if (!is_string($value) || $value === '' ||
            !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            return false;
        }
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);
        if ($bytes === false || self::b64($bytes) !== $value) {
            return false;
        }
        return $bytes;
    }

    private static function identity_part($value) {
        return pack('N', strlen($value)) . $value;
    }

    private static function valid_context($site, $path, $kind, $user, $token) {
        return is_string($site) && preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $site) &&
            is_string($path) && preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $path) &&
            in_array($kind, ['session', 'remember'], true) &&
            is_string($user) && strlen($user) <= 191 &&
            ($kind === 'session' ? $user === '' : $user !== '') &&
            is_string($token) && strlen($token) >= 32 && strlen($token) <= 256;
    }

    /** Opaque revocation identifier: never the WP token or its DB hash. */
    public static function session_ref($site, $path, $kind, $user, $token, $id_key) {
        if (!self::valid_context($site, $path, $kind, $user, $token) ||
            !is_string($id_key) || strlen($id_key) < 32) {
            return null;
        }
        return hash_hmac('sha256', self::ID_DOMAIN .
            self::identity_part($site) . self::identity_part($path) .
            self::identity_part($kind) . self::identity_part($user) .
            self::identity_part($token), $id_key);
    }

    /**
     * Return revocation IDs for ALL plausible presented credentials.
     *
     * MySQL might have deleted one credential already while another remains
     * valid. Do not omit that deleted/revoked credential from the final
     * SQLite check merely because it no longer has a MySQL row.
     * These references are NOT positive proof of authentication.
     */
    public static function present_refs($site, $path, $cookies, $id_key) {
        if (!is_array($cookies)) { return []; }
        $refs = [];
        $session = $cookies['esp_auth_' . $path] ?? null;
        if (is_string($session) && strlen($session) >= 32 &&
            strlen($session) <= 256) {
            $ref = self::session_ref($site, $path, 'session', '',
                $session, $id_key);
            if ($ref !== null) { $refs[] = $ref; }
        }
        $user = $cookies['esp_remember_id_' . $path] ?? null;
        $remember = $cookies['esp_remember_token_' . $path] ?? null;
        if (is_string($user) && strlen($user) >= 1 && strlen($user) <= 191 &&
            is_string($remember) && strlen($remember) >= 32 &&
            strlen($remember) <= 256) {
            $ref = self::session_ref($site, $path, 'remember', $user,
                $remember, $id_key);
            if ($ref !== null) { $refs[] = $ref; }
        }
        return array_values(array_unique($refs));
    }

    /**
     * @return string|null base64url(payload).base64url(HMAC)
     * $expires is the server-verified MySQL session expiration timestamp.
     */
    public static function issue($site, $path, $kind, $user, $token,
                                 $version, $expires, $sig_key, $id_key, $now = null) {
        $now = $now === null ? time() : $now;
        if (!is_int($now) || $now < 1 || !is_int($version) || $version < 0 ||
            !is_int($expires) || $expires <= $now ||
            !is_string($sig_key) || strlen($sig_key) < 32) {
            return null;
        }
        $ref = self::session_ref($site, $path, $kind, $user, $token, $id_key);
        if ($ref === null) { return null; }
        $payload = json_encode([
            'v' => 2, 'site' => $site, 'path' => $path,
            'kind' => $kind, 'version' => $version,
            'iat' => $now, 'exp' => min($expires, $now + self::MAX_AGE),
            'ref' => $ref,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) { return null; }
        $data = self::b64($payload);
        $mac = hash_hmac('sha256', self::DOMAIN . $data, $sig_key, true);
        return $data . '.' . self::b64($mac);
    }

    /**
     * All fields are strictly typed and authenticated. Fail closed on unknown
     * versions, overlong payloads, wrong path/site, expiry or token mismatch.
     *
     * @return array|null Signed payload (including ref) or null.
     */
    public static function verify($ticket, $site, $path, $cookies,
                                  $current_version, $sig_key, $id_key, $now = null) {
        $now = $now === null ? time() : $now;
        if (!is_string($ticket) || strlen($ticket) > 2048 ||
            !is_array($cookies) || !is_int($now) ||
            !is_int($current_version) || $current_version < 0 ||
            !is_string($sig_key) || strlen($sig_key) < 32 ||
            !is_string($id_key) || strlen($id_key) < 32) {
            return null;
        }
        $pieces = explode('.', $ticket);
        if (count($pieces) !== 2) { return null; }
        $data = self::decode64($pieces[0]);
        $mac = self::decode64($pieces[1]);
        if ($data === false || $mac === false || strlen($mac) !== 32) {
            return null;
        }
        $expected = hash_hmac('sha256', self::DOMAIN . $pieces[0], $sig_key, true);
        if (!hash_equals($expected, $mac)) { return null; }
        $p = json_decode($data, true);
        if (!is_array($p) || count($p) !== 8 ||
            ($p['v'] ?? null) !== 2 ||
            !isset($p['site'], $p['path'], $p['kind'], $p['ref']) ||
            !is_string($p['site']) || !is_string($p['path']) ||
            !is_string($p['kind']) || !is_string($p['ref']) ||
            !is_int($p['version'] ?? null) || !is_int($p['iat'] ?? null) ||
            !is_int($p['exp'] ?? null) ||
            $p['site'] !== $site || $p['path'] !== $path ||
            $p['version'] !== $current_version ||
            $p['iat'] > $now || $p['iat'] < 1 ||
            $p['exp'] <= $now || $p['exp'] <= $p['iat'] ||
            $p['exp'] - $p['iat'] > self::MAX_AGE ||
            !preg_match('/^[a-f0-9]{64}$/D', $p['ref'])) {
            return null;
        }
        $kind = $p['kind'];
        if ($kind === 'session') {
            $token = $cookies['esp_auth_' . $path] ?? null;
            $user = '';
        } elseif ($kind === 'remember') {
            $token = $cookies['esp_remember_token_' . $path] ?? null;
            $user = $cookies['esp_remember_id_' . $path] ?? null;
        } else {
            return null;
        }
        $ref = self::session_ref($site, $path, $kind, $user, $token, $id_key);
        if ($ref === null || !hash_equals($p['ref'], $ref)) { return null; }
        return $p;
    }
}
