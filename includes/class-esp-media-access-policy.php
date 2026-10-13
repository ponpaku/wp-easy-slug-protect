<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Separate file ownership from authorization.
 *
 * A file that cannot be associated with a unique attachment is NOT the
 * same thing as an attachment explicitly confirmed to be public.
 */
final class ESP_Media_Access_Policy {
    public static function decide($resolution, $protected_path_id, $paths, $verified_zero_media = false) {
        if (!is_array($resolution) || !isset($resolution['state'])) {
            return ['action' => 'deny'];
        }
        // A site with verified zero protected records has no ESP media
        // memberships to leak. Preserve ordinary unmanaged uploads there.
        // This is NOT a default allow for unknown ownership on protected sites.
        if ($resolution['state'] === 'unresolved' && $verified_zero_media === true &&
            is_array($paths)) {
            return ['action' => 'allow'];
        }
        if ($resolution['state'] !== 'resolved' ||
            !isset($resolution['attachment_id']) ||
            !is_int($resolution['attachment_id']) ||
            $resolution['attachment_id'] < 1) {
            return ['action' => 'deny'];
        }
        if (!is_array($paths)) {
            return ['action' => 'deny'];
        }
        if ($protected_path_id === '' || $protected_path_id === null) {
            return ['action' => 'allow'];
        }
        if (!is_scalar($protected_path_id) ||
            !isset($paths[(string) $protected_path_id]) ||
            !is_array($paths[(string) $protected_path_id])) {
            return ['action' => 'deny'];
        }
        return ['action' => 'authenticate', 'settings' => $paths[(string) $protected_path_id]];
    }
}
