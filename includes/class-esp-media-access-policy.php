<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ファイルの添付ID判定とアクセス許可の判断を分離する。
 *
 * 添付IDを一意に特定できないファイルは、公開と確認できた添付ファイルとは異なる。
 */
final class ESP_Media_Access_Policy {
    public static function decide($resolution, $protected_path_id, $paths, $verified_zero_media = false) {
        if (!is_array($resolution) || !isset($resolution['state'])) {
            return ['action' => 'deny'];
        }
        // DBで保護対象0件と確認できた場合だけ、管理外ファイルの通常配信を維持する。
        // 保護対象があるサイトで所有者不明を許可する一般ルールではない。
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
