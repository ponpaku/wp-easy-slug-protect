<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 世代付きの認可スナップショットを管理する。
 * キャッシュ未取得と、保護対象が0件だと確認済みの状態は区別する。
 *
 * 無効化より後に旧世代の書き込みが完了しても、設定の指紋と更新世代が
 * 現在値に一致しない限り、そのスナップショットは採用しない。
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
        // 一時キャッシュの保存中に保護設定が変更されていないか再確認する。
        // 読み取り側でも同じ指紋を照合し、古いキャッシュの採用を防ぐ。
        return self::signature($scope) === $expected;
    }

    public static function invalidate($scope, $key) {
        // 再構築を始める前に更新世代を進め、旧キャッシュを先に無効化する。
        // 旧世代の再構築が最後に完了しても、読み取り側で破棄する。
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
