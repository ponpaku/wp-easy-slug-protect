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
    /** @var array<string,bool> Persistに失敗した認可情報を同リクエスト中に利用しない */
    private static $unsafe_scopes = [];
    /**
     * by-mid APIで保護メタのキーを別名に変える場合、Coreの更新後フックは
     * 新しいキーしか渡さない。失効対象の旧キーを同一リクエスト内で保持する。
     * WordPress Coreの変更前ガードと更新後フックの両方が通る場合だけ使用。
     */
    private static $pending_by_mid_rekeys = [];

    /**
     * WordPress postmetaの変更前に、対象の認可キャッシュを失効させる。
     * 永続化できない場合はWPのadd/update/delete_post_metadataをfalseで中止する。
     * 直接SQLから行う変更は対象外。
     */
    public static function guard_postmeta_write($check, $post_id, $meta_key, $meta_value, $extra = null) {
        if ($check !== null) {
            return $check;
        }
        if ($meta_key === ESP_Config::PERMALINK_PATH_META_KEY) {
            return self::invalidate('post', ESP_Filter::CACHE_KEY) ? null : false;
        }
        if ($meta_key === ESP_Media_Protection::META_KEY_PROTECTED_PATH) {
            // OFF中に保護メタを保存すると、静的ファイルがESPを迂回して公開される。
            // WordPress標準のadd/updateは安全確認まで拒否する（deleteは別フック）。
            if (!ESP_Media_Protection::can_assign_protected_media()) {
                return false;
            }
            return self::invalidate('media', ESP_Media_Protection::MEDIA_CACHE_KEY) ? null : false;
        }
        return null;
    }

    /** メタ削除は保護を解除する操作なのでOFF中でも妨げない。 */
    public static function guard_postmeta_delete($check, $post_id, $meta_key, $meta_value, $delete_all = false) {
        if ($check !== null) {
            return $check;
        }
        if ($meta_key === ESP_Config::PERMALINK_PATH_META_KEY) {
            return self::invalidate('post', ESP_Filter::CACHE_KEY) ? null : false;
        }
        if ($meta_key === ESP_Media_Protection::META_KEY_PROTECTED_PATH) {
            return self::invalidate('media', ESP_Media_Protection::MEDIA_CACHE_KEY) ? null : false;
        }
        return null;
    }

    /**
     * update_metadata_by_mid() は update_post_metadata フィルターを通らない。
     * 変更後のキーだけでなく、キー変更前の保護状態も失効させる。
     */
    public static function guard_postmeta_update_by_mid($check, $meta_id, $meta_value, $meta_key = false) {
        if ($check !== null) {
            return $check;
        }
        $existing = get_metadata_by_mid('post', $meta_id);
        if (!is_object($existing) || !isset($existing->meta_key)) {
            return null; // WordPress本体が不存在・不正なmeta IDを拒否する。
        }
        $old_key = $existing->meta_key;
        $new_key = $meta_key === false ? $old_key : $meta_key;
        if (!is_string($new_key)) {
            return null; // WordPress本体が不正なmeta_keyを拒否する。
        }
        if ($new_key === ESP_Media_Protection::META_KEY_PROTECTED_PATH &&
            !ESP_Media_Protection::can_assign_protected_media()) {
            return false;
        }
        if (($old_key === ESP_Config::PERMALINK_PATH_META_KEY ||
            $new_key === ESP_Config::PERMALINK_PATH_META_KEY) &&
            !self::invalidate('post', ESP_Filter::CACHE_KEY)) {
            return false;
        }
        if (($old_key === ESP_Media_Protection::META_KEY_PROTECTED_PATH ||
            $new_key === ESP_Media_Protection::META_KEY_PROTECTED_PATH) &&
            !self::invalidate('media', ESP_Media_Protection::MEDIA_CACHE_KEY)) {
            return false;
        }
        // 更新前失効とDB書込みの隙間に古い状態で再構築されても、
        // 更新後に「旧キー」の世代をもう一度進める。通常のpostmeta
        // 更新後フックには新キーしか渡らないため、ここで記録する。
        if ($old_key !== $new_key &&
            ($old_key === ESP_Config::PERMALINK_PATH_META_KEY ||
             $old_key === ESP_Media_Protection::META_KEY_PROTECTED_PATH)) {
            self::$pending_by_mid_rekeys[(int) $meta_id] = [
                'old_key' => $old_key,
                'new_key' => $new_key,
            ];
        } else {
            unset(self::$pending_by_mid_rekeys[(int) $meta_id]);
        }
        return null;
    }

    /**
     * WordPress Coreのupdated_post_metaから呼ぶ。キー変更成功後に旧スコープ
     * の失効を完了する（通常のメタ値更新は既存のpost-updateフックが処理）。
     */
    public static function invalidate_after_meta_key_change($meta_id, $post_id, $meta_key, $meta_value) {
        $id = (int) $meta_id;
        $pending = self::$pending_by_mid_rekeys[$id] ?? null;
        if ($pending === null || $pending['new_key'] !== $meta_key) {
            return;
        }
        unset(self::$pending_by_mid_rekeys[$id]);
        if ($pending['old_key'] === ESP_Config::PERMALINK_PATH_META_KEY) {
            self::invalidate('post', ESP_Filter::CACHE_KEY);
        }
        if ($pending['old_key'] === ESP_Media_Protection::META_KEY_PROTECTED_PATH) {
            self::invalidate('media', ESP_Media_Protection::MEDIA_CACHE_KEY);
        }
    }

    /** delete_metadata_by_mid() も通常の delete_post_metadata を通らない。 */
    public static function guard_postmeta_delete_by_mid($check, $meta_id) {
        if ($check !== null) {
            return $check;
        }
        $existing = get_metadata_by_mid('post', $meta_id);
        if (!is_object($existing) || !isset($existing->meta_key)) {
            return null;
        }
        return self::guard_postmeta_delete(null, $existing->post_id ?? 0,
            $existing->meta_key, $existing->meta_value ?? null);
    }

    public static function signature($scope) {
        if (!empty(self::$unsafe_scopes[$scope])) {
            return null; // 当該リクエスト内の古い認可スナップショットを拒否。
        }
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
        // メタデータ変更前に世代を進める。既存キャッシュの削除が失敗しても
        // 世代が確実に更新されれば旧スナップショットは採用できない。
        try {
            $epoch = bin2hex(random_bytes(16));
            if (!update_option('esp_' . $scope . '_auth_epoch', $epoch, false) &&
                get_option('esp_' . $scope . '_auth_epoch', null) !== $epoch) {
                delete_transient($key);
                self::$unsafe_scopes[$scope] = true;
                error_log('ESP: authorization cache invalidation failed for ' . $scope);
                return false;
            }
            delete_transient($key);
            return true;
        } catch (\Throwable $error) {
            self::$unsafe_scopes[$scope] = true;
            error_log('ESP: authorization cache invalidation raised an error for ' . $scope);
            return false;
        }
    }
}
