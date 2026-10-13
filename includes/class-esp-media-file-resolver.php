<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress uploads file ownership. No authorization decisions live here.
 * Failures and ambiguous matches must never be mistaken for public files.
 *
 * Candidate bounds protect against an unexpectedly large or corrupt metadata
 * set; this is not a full-directory or full-attachment scan.
 */
final class ESP_Media_File_Resolver {
    private const MAX_CANDIDATES = 64;
    private const PARENT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'heic', 'heif', 'pdf'];

    public static function validate_request_path($requested) {
        if (!self::valid_relative($requested)) {
            return null;
        }
        $uploads = wp_upload_dir();
        $root = !empty($uploads['basedir']) ? realpath($uploads['basedir']) : false;
        if (!$root || !is_dir($root)) {
            return null;
        }
        $root = rtrim($root, '/\\');
        // The uploads root itself may be a symlink. Reject nested symlinks:
        // realpath() alone would conceal their presence.
        $cursor = $root;
        foreach (explode('/', $requested) as $component) {
            $cursor .= DIRECTORY_SEPARATOR . $component;
            if (is_link($cursor)) {
                return null;
            }
        }
        $absolute = realpath($cursor);
        if ($absolute === false || !is_file($absolute) ||
            strncmp($absolute, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) {
            return null;
        }
        return ['absolute' => $absolute, 'relative' => substr($absolute, strlen($root) + 1)];
    }

    public static function relative_from_absolute($absolute) {
        $uploads = wp_upload_dir();
        $root = !empty($uploads['basedir']) ? realpath($uploads['basedir']) : false;
        if (!$root || !is_string($absolute)) {
            return null;
        }
        $root = rtrim($root, '/\\');
        if (strncmp($absolute, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) {
            return null;
        }
        $relative = substr($absolute, strlen($root) + 1);
        $validated = self::validate_request_path(str_replace(DIRECTORY_SEPARATOR, '/', $relative));
        return $validated && $validated['absolute'] === $absolute ? $validated['relative'] : null;
    }

    private static function valid_relative($path) {
        if (!is_string($path) || $path === '' || strlen($path) > 2048 ||
            $path[0] === '/' || strpos($path, '\\') !== false ||
            strpos($path, '%') !== false || strpos($path, '?') !== false ||
            strpos($path, '#') !== false || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                return false;
            }
        }
        return true;
    }

    private static function candidates($relative) {
        $directory = dirname($relative);
        $prefix = $directory === '.' ? '' : $directory . '/';
        $filename = basename($relative);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $stem = substr($filename, 0, strlen($filename) - strlen($extension) - 1);
        if ($stem === '' || $extension === '') {
            return [[$relative], []];
        }
        $stems = [$stem => true];
        // Remove recognized suffixes only; names nominate candidates but
        // cannot prove ownership without metadata comparison.
        for ($round = 0; $round < 4; $round++) {
            foreach (array_keys($stems) as $part) {
                $short = preg_replace('/(?:-[0-9]{1,5}x[0-9]{1,5}|-scaled|-rotated|-e[0-9]{8,14}|-pdf|-preview)$/i', '', $part);
                if ($short !== $part && $short !== '') {
                    $stems[$short] = true;
                }
            }
        }
        $paths = [$relative => true];
        $like = [];
        foreach (array_keys($stems) as $part) {
            foreach (self::PARENT_EXTENSIONS as $ext) {
                $paths[$prefix . $part . '.' . $ext] = true;
                $paths[$prefix . $part . '-scaled.' . $ext] = true;
                $paths[$prefix . $part . '-rotated.' . $ext] = true;
                if (count($paths) > 120) {
                    return [[], []];
                }
            }
            // Edited originals may carry unknown -e1234567890 suffixes.
            // Bounded LIKE results are verified against attachment metadata.
            $like[] = $prefix . $part . '-e';
        }
        return [array_keys($paths), array_slice(array_unique($like), 0, 8)];
    }

    private static function matches_metadata($metadata, $relative) {
        if (!is_array($metadata) || !isset($metadata['file']) ||
            !self::valid_relative($metadata['file'])) {
            return false;
        }
        $base = dirname($metadata['file']);
        $prefix = $base === '.' ? '' : $base . '/';
        if ($metadata['file'] === $relative) {
            return true;
        }
        $fields = ['original_image', 'source_image', 'animated_video', 'animated_video_poster'];
        foreach ($fields as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key]) &&
                strpos($metadata[$key], '/') === false &&
                self::valid_relative($metadata[$key]) &&
                $prefix . $metadata[$key] === $relative) {
                return true;
            }
        }
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                if (is_array($size) && isset($size['file']) &&
                    is_string($size['file']) && strpos($size['file'], '/') === false &&
                    self::valid_relative($size['file']) &&
                    $prefix . $size['file'] === $relative) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return array{state:string,attachment_id?:int}
     * States: resolved, unresolved, ambiguous, error.
     */
    public static function resolve($relative) {
        if (!self::valid_relative($relative)) {
            return ['state' => 'error'];
        }
        [$paths, $prefixes] = self::candidates($relative);
        if (!$paths || count($paths) > 120) {
            return ['state' => 'error'];
        }
        global $wpdb;
        if (!is_object($wpdb) || empty($wpdb->postmeta) || empty($wpdb->posts)) {
            return ['state' => 'error'];
        }
        $placeholders = implode(',', array_fill(0, count($paths), '%s'));
        $clauses = ["pm.meta_value IN ($placeholders)"];
        $params = array_merge(['_wp_attached_file'], $paths);
        foreach ($prefixes as $prefix) {
            $clauses[] = 'pm.meta_value LIKE %s';
            $escaped = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($prefix) : addcslashes($prefix, '_%\\');
            $params[] = $escaped . '%';
        }
        $sql = "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm " .
            "INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id " .
            "WHERE pm.meta_key = %s AND p.post_type = 'attachment' AND (" .
            implode(' OR ', $clauses) . ') LIMIT ' . (self::MAX_CANDIDATES + 1);
        $query = $wpdb->prepare($sql, $params);
        if (!is_string($query)) {
            return ['state' => 'error'];
        }
        $rows = $wpdb->get_results($query, ARRAY_A);
        if (!is_array($rows) || ($wpdb->last_error ?? '') !== '' ||
            count($rows) > self::MAX_CANDIDATES) {
            return ['state' => 'error'];
        }

        $found = [];
        $checked = [];
        foreach ($rows as $row) {
            if (!isset($row['post_id'], $row['meta_value']) ||
                !is_string($row['meta_value'])) {
                return ['state' => 'error'];
            }
            $id = (int) $row['post_id'];
            if ($id <= 0) {
                return ['state' => 'error'];
            }
            $exact = $row['meta_value'] === $relative;
            if ($exact) {
                $found[$id] = true;
            } elseif (!isset($checked[$id])) {
                $checked[$id] = true;
                // The row and current unfiltered metadata must agree before
                // a derivative filename can be attributed to the attachment.
                $metadata = wp_get_attachment_metadata($id, true);
                if (($wpdb->last_error ?? '') !== '') {
                    return ['state' => 'error'];
                }
                $consistent = is_array($metadata) &&
                    isset($metadata['file']) &&
                    $metadata['file'] === $row['meta_value'];
                if ($consistent && self::matches_metadata($metadata, $relative)) {
                    $found[$id] = true;
                }
            }
            if (count($found) > 1) {
                return ['state' => 'ambiguous'];
            }
        }
        return $found
            ? ['state' => 'resolved', 'attachment_id' => (int) array_key_first($found)]
            : ['state' => 'unresolved'];
    }
}
