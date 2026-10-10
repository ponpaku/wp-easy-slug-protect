<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Versioned authorization snapshots. Missing data and a verified empty
 * snapshot are deliberately different states.
 *
 * A writer can finish after an invalidation, but a reader will never accept
 * an old snapshot because both the settings fingerprint and mutation epoch
 * must match the current state.
 */
final class ESP_Authorization_Cache {
    public static function signature($scope) {
        $settings = get_option(ESP_Config::OPTION_KEY, null);
        if (!is_array($settings) || !array_key_exists('path', $settings) ||
            !is_array($settings['path'])) {
            return null;
        }
        $epoch = get_option('esp_' . $scope . '_auth_epoch', 'initial');
        if (!is_string($epoch)) {
            return null;
        }
        return hash('sha256', serialize([
            $settings['path'],
            $settings['media'] ?? [],
            $epoch,
        ]));
    }

    public static function read($scope, $key) {
        $expected = self::signature($scope);
        if ($expected === null) {
            return null;
        }
        $snapshot = get_transient($key);
        if (!is_array($snapshot) || !isset($snapshot['signature']) ||
            !hash_equals($expected, (string) $snapshot['signature']) ||
            !isset($snapshot['value']) || !is_array($snapshot['value'])) {
            return null;
        }
        return $snapshot['value'];
    }

    public static function publish($scope, $key, $expected, $value, $ttl) {
        if ($expected === null || !is_array($value) ||
            self::signature($scope) !== $expected) {
            return false;
        }
        if (!set_transient($key, ['signature' => $expected, 'value' => $value], $ttl)) {
            return false;
        }
        // Detect a mutation which committed during transient publication.
        // Readers independently enforce the same fingerprint.
        return self::signature($scope) === $expected;
    }

    public static function invalidate($scope, $key) {
        // Mutations must publish a new epoch BEFORE starting a rebuild.
        // Readers reject stale data even if an old producer writes last.
        $epoch = bin2hex(random_bytes(16));
        if (!update_option('esp_' . $scope . '_auth_epoch', $epoch, false) &&
            get_option('esp_' . $scope . '_auth_epoch', null) !== $epoch) {
            delete_transient($key);
            return false;
        }
        delete_transient($key);
        return true;
    }
}
