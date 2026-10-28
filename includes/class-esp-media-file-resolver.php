<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves an uploads file to a WordPress attachment without making an access decision.
 *
 * The results are always ['status' => resolved|unresolved|ambiguous|error] and,
 * only for resolved, ['attachment_id' => int]. Authorization belongs to #43.
 */
final class ESP_Media_File_Resolver {
    private const MAX_CANDIDATE_PATHS = 160;
    private const MAX_CANDIDATE_ROWS = 64;
    private const MAX_STEMS = 5;
    private const MAX_METADATA_SIZES = 128;

    // Parent search is format-agnostic: no special AVIF or thumbnail code path.
    // Unlisted formats can be added here only when WordPress produces them.
    private const PARENT_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp',
        'heic', 'heif', 'pdf', 'mp4', 'mov', 'webm',
    ];

    /**
     * @param string $file_path Validated absolute path (canonical path is accepted).
     * @return array{status:string,attachment_id?:int}
     */
    public function resolve($file_path) {
        global $wpdb;

        $relative = self::uploads_relative_path($file_path);
        if ($relative === null || !is_object($wpdb) ||
            empty($wpdb->postmeta) || empty($wpdb->posts) ||
            !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            return self::result('error');
        }

        $lookup = self::candidate_paths($relative);
        if ($lookup === null) {
            return self::result('error');
        }
        $paths = $lookup['paths'];
        $likes = $lookup['likes'];

        $in = implode(', ', array_fill(0, count($paths), '%s'));
        $like = implode(' OR ', array_fill(0, count($likes), 'pm.meta_value LIKE %s'));
        $predicate = "pm.meta_value IN ($in)";
        if ($like !== '') {
            $predicate .= " OR $like";
        }

        // A single bounded query catches duplicate originals AND competing
        // attachments whose metadata references a file as a derivative.
        $sql = "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                AND p.post_type = 'attachment'
            WHERE pm.meta_key = %s AND ($predicate)
            LIMIT " . (self::MAX_CANDIDATE_ROWS + 1);
        try {
            $args = array_merge(['_wp_attached_file'], $paths, $likes);
            $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
            if (!is_array($rows) || $wpdb->last_error !== '' ||
                count($rows) > self::MAX_CANDIDATE_ROWS) {
                return self::result('error');
            }

            $owners = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['post_id'], $row['meta_value']) ||
                    !ctype_digit((string) $row['post_id']) ||
                    (int) $row['post_id'] <= 0 || !is_string($row['meta_value'])) {
                    return self::result('error');
                }
                $attached = $row['meta_value'];
                if (!self::valid_relative_path($attached)) {
                    continue;
                }

                // SQL collations can be case-insensitive. Filename similarity
                // only selects candidates; it never proves ownership.
                if (!in_array($attached, $paths, true) &&
                    !self::matches_edit_candidate($attached, $lookup['edit_prefixes'])) {
                    continue;
                }
                $id = (int) $row['post_id'];
                if (isset($owners[$id])) {
                    continue;
                }

                if ($attached === $relative) {
                    $owners[$id] = true;
                    continue;
                }

                $metadata = wp_get_attachment_metadata($id, true);
                if (!is_array($metadata)) {
                    continue;
                }
                $matched = self::matches_metadata($metadata, $attached, $relative);
                if ($matched === null) {
                    return self::result('error');
                }
                if ($matched) {
                    $owners[$id] = true;
                }
            }

            if (count($owners) > 1) {
                return self::result('ambiguous');
            }
            if (count($owners) === 1) {
                return ['status' => 'resolved', 'attachment_id' => (int) key($owners)];
            }
            return self::result('unresolved');
        } catch (\Throwable $error) {
            return self::result('error');
        }
    }

    private static function result($status) {
        return ['status' => $status];
    }

    /**
     * Root symlinks are allowed; resolved files must remain inside the same
     * canonical root, with a slash boundary (never uploads-backup).
     */
    private static function uploads_relative_path($file_path) {
        if (!is_string($file_path) || $file_path === '' ||
            strpos($file_path, "\0") !== false ||
            strpos($file_path, '\\') !== false) {
            return null;
        }
        $uploads = wp_upload_dir();
        if (!is_array($uploads) || !empty($uploads['error']) ||
            empty($uploads['basedir']) || !is_string($uploads['basedir'])) {
            return null;
        }
        $root = realpath($uploads['basedir']);
        $file = realpath($file_path);
        if ($root === false || $file === false || !is_file($file)) {
            return null;
        }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp($file, $prefix, strlen($prefix)) !== 0) {
            return null;
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($prefix)));
        return self::valid_relative_path($relative) ? $relative : null;
    }

    private static function valid_relative_path($path) {
        if (!is_string($path) || $path === '' || $path[0] === '/' ||
            strpos($path, '\\') !== false ||
            preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment[0] === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * Strip only WordPress filename suffixes. Never infer ownership from them.
     * A bounded extension list makes SQL fixed-size even for hostile filenames.
     */
    private static function candidate_paths($relative) {
        $directory = dirname($relative);
        $directory = $directory === '.' ? '' : $directory . '/';
        $name = basename($relative);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if ($ext === '') {
            return null;
        }
        $stem = substr($name, 0, -strlen($ext) - 1);
        $stems = [$stem];
        while (count($stems) < self::MAX_STEMS) {
            $last = $stems[count($stems) - 1];
            $next = preg_replace(
                '/(?:-[1-9][0-9]*x[1-9][0-9]*|-scaled|-rotated|-e[0-9]{8,16}|-pdf)$/i',
                '', $last, 1, $replaced
            );
            if ($replaced !== 1 || $next === '') {
                break;
            }
            $stems[] = $next;
        }
        $paths = [$relative];
        $edit_prefixes = [];
        foreach ($stems as $candidate_stem) {
            foreach (self::PARENT_EXTENSIONS as $extension) {
                $paths[] = $directory . $candidate_stem . '.' . $extension;
            }
            $edit_prefixes[] = $directory . $candidate_stem . '-e';
        }
        // Core can replace the attached file with a scaled/rotated/PDF preview.
        // The unmodified original is still represented by metadata.original_image.
        foreach (array_unique([$stem, end($stems)]) as $source_stem) {
            foreach (['-scaled', '-rotated', '-pdf'] as $suffix) {
                foreach (self::PARENT_EXTENSIONS as $extension) {
                    $paths[] = $directory . $source_stem . $suffix . '.' . $extension;
                }
            }
        }
        $paths = array_values(array_unique($paths));
        if (count($paths) > self::MAX_CANDIDATE_PATHS) {
            return null;
        }
        $likes = [];
        foreach (array_unique($edit_prefixes) as $prefix) {
            // wpdb::esc_like escapes literal %, _ and backslashes.
            global $wpdb;
            $likes[] = $wpdb->esc_like($prefix) . '%';
        }
        return ['paths' => $paths, 'likes' => $likes,
            'edit_prefixes' => array_values(array_unique($edit_prefixes))];
    }

    private static function matches_edit_candidate($attached, $prefixes) {
        foreach ($prefixes as $prefix) {
            if (strncmp($attached, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $tail = substr($attached, strlen($prefix));
            if (preg_match('/^[0-9]{8,16}\.[a-z0-9]+$/i', $tail)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Require exact path evidence from current WordPress metadata.
     * Backup sizes and _wp_sideloaded_file are intentionally not authoritative.
     * null means the bounded check could not finish safely.
     */
    private static function matches_metadata($metadata, $attached, $requested) {
        $directory = dirname($attached);
        $directory = $directory === '.' ? '' : $directory . '/';
        if (isset($metadata['file']) && $metadata['file'] === $requested) {
            return true;
        }

        if (isset($metadata['sizes'])) {
            if (!is_array($metadata['sizes']) ||
                count($metadata['sizes']) > self::MAX_METADATA_SIZES) {
                return null;
            }
            foreach ($metadata['sizes'] as $size) {
                if (is_array($size) && isset($size['file']) &&
                    self::matches_filename($size['file'], $directory, $requested)) {
                    return true;
                }
            }
        }

        foreach (['original_image', 'source_image', 'animated_video',
                  'animated_video_poster'] as $key) {
            if (!isset($metadata[$key])) {
                continue;
            }
            $name = $metadata[$key];
            if (is_array($name)) {
                $name = $name['file'] ?? null;
            }
            if (self::matches_filename($name, $directory, $requested)) {
                return true;
            }
        }
        return false;
    }

    private static function matches_filename($name, $directory, $requested) {
        // Subdir and traversal metadata are not evidence of ownership.
        return is_string($name) && $name !== '' &&
            $name[0] !== '.' &&
            strpos($name, '/') === false && strpos($name, '\\') === false &&
            strpos($name, "\0") === false &&
            $directory . $name === $requested;
    }
}
