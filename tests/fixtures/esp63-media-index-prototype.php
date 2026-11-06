<?php
/**
 * Issue #63 experimental index. NOT loaded by the production plugin.
 *
 * WordPress attachment metadata is authoritative; this table is a derived index.
 * IMPORTANT: this prototype has NO completeness/epoch/synchronization guarantee.
 * Do not route HTTP authorization decisions through it before #43/#37 are solved.
 */
if (!defined('ABSPATH')) { exit; }

final class ESP63_Media_Index_Prototype {
    public const MAX_SIZES = 128;

    public static function valid_path($path) {
        if (!is_string($path) || $path === '' || $path[0] === '/' ||
            strpos($path, '\\') !== false || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part[0] === '.') { return false; }
        }
        return true;
    }

    /** Enumerate current originals and WordPress-known derivatives without guessing names. */
    public static function enumerate($attached, $metadata) {
        if (!self::valid_path($attached)) { return null; }
        $paths = [$attached => true];
        if ($metadata === null || $metadata === false || $metadata === []) {
            return array_keys($paths);
        }
        if (!is_array($metadata)) { return null; }
        $base = $attached;
        if (isset($metadata['file'])) {
            if (!self::valid_path($metadata['file']) ||
                dirname($metadata['file']) !== dirname($attached)) { return null; }
            $base = $metadata['file'];
            $paths[$base] = true;
        }
        $dir = dirname($base);
        $prefix = $dir === '.' ? '' : $dir . '/';
        $sizes = $metadata['sizes'] ?? [];
        if (!is_array($sizes) || count($sizes) > self::MAX_SIZES) { return null; }
        foreach ($sizes as $size) {
            if (!is_array($size) || !isset($size['file'])) { continue; }
            $name = $size['file'];
            if (!self::valid_name($name)) { return null; }
            $paths[$prefix . $name] = true;
        }
        // PDF previews may have sizes but no top-level metadata.file.
        foreach (['original_image', 'source_image', 'animated_video',
                  'animated_video_poster'] as $field) {
            if (!isset($metadata[$field])) { continue; }
            if (!isset($metadata['file'])) { continue; } // parity with PR #62
            $name = $metadata[$field];
            if (is_array($name)) { $name = $name['file'] ?? null; }
            if (!self::valid_name($name)) { return null; }
            $paths[$prefix . $name] = true;
        }
        return array_keys($paths);
    }

    private static function valid_name($name) {
        return is_string($name) && $name !== '' && $name[0] !== '.' &&
            strpos($name, '/') === false && strpos($name, '\\') === false &&
            !preg_match('/[\x00-\x1f\x7f]/', $name);
    }

    /** Use a binary SHA-256 comparison key; compare original path bytes in PHP too. */
    public static function hash_path($path) {
        return hash('sha256', $path);
    }

    public static function schema_sql($table) {
        self::assert_table($table);
        return "CREATE TABLE IF NOT EXISTS `$table` (
            path_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            file_path TEXT NOT NULL,
            attachment_id BIGINT UNSIGNED NOT NULL,
            protected_path_id VARCHAR(191) NOT NULL DEFAULT '',
            PRIMARY KEY (path_hash, attachment_id),
            KEY attachment_id (attachment_id),
            KEY protected_path_id (protected_path_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin";
    }

    private static function assert_table($table) {
        if (!is_string($table) || !preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
            throw new InvalidArgumentException('Invalid table name');
        }
    }

    /**
     * This is only a lookup prototype. An empty result is NOT proof of public
     * access, and a matching row is NOT certified fresh. Callers must enforce
     * generation/coverage validity before using any result for authorization.
     */
    public static function lookup($wpdb, $table, $relative) {
        self::assert_table($table);
        if (!self::valid_path($relative)) { return ['status' => 'error']; }
        $sql = $wpdb->prepare("SELECT file_path, attachment_id, protected_path_id
            FROM `$table` WHERE path_hash = %s", self::hash_path($relative));
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== '') { return ['status' => 'error']; }
        $owners = [];
        foreach ($rows as $row) {
            if (!isset($row['file_path'], $row['attachment_id'], $row['protected_path_id']) ||
                !hash_equals((string)$row['file_path'], $relative) ||
                !ctype_digit((string)$row['attachment_id']) || (int)$row['attachment_id'] < 1 ||
                !is_string($row['protected_path_id'])) {
                return ['status' => 'error']; // hash collision/corruption: never permit public
            }
            $owners[(int)$row['attachment_id']] = $row['protected_path_id'];
        }
        if (count($owners) > 1) { return ['status' => 'ambiguous']; }
        if (!$owners) { return ['status' => 'unresolved']; }
        $id = (int)array_key_first($owners);
        return ['status' => 'resolved', 'attachment_id' => $id,
                'protected_path_id' => $owners[$id]];
    }

    /**
     * Transactionally replaces this attachment's derived rows only.
     * NOT atomic with WordPress core metadata writes. Test-only backfill helper.
     */
    public static function replace_attachment($wpdb, $table, $id, $attached, $metadata, $path_id) {
        self::assert_table($table);
        $paths = self::enumerate($attached, $metadata);
        if (!$paths || $id < 1 || !is_string($path_id) || strlen($path_id) > 191) {
            return false;
        }
        if ($wpdb->query('START TRANSACTION') === false) { return false; }
        try {
            if ($wpdb->query($wpdb->prepare("DELETE FROM `$table` WHERE attachment_id = %d", $id)) === false) {
                throw new RuntimeException('delete failed');
            }
            foreach ($paths as $path) {
                $sql = $wpdb->prepare("INSERT INTO `$table`
                    (path_hash, file_path, attachment_id, protected_path_id)
                    VALUES (%s, %s, %d, %s)",
                    self::hash_path($path), $path, $id, $path_id);
                if ($wpdb->query($sql) === false) { throw new RuntimeException('insert failed'); }
            }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('commit failed'); }
            return true;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }
}
