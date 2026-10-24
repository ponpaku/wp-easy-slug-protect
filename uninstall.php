<?php
/**
 * Easy Slug Protect アンインストール処理
 */

// プラグインが直接呼び出された場合は終了
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

// プラグインパスを定義
if (!defined('ESP_PATH')) {
    define('ESP_PATH', plugin_dir_path(__FILE__));
}

// コンフィグファイルを読み込み
if (file_exists(ESP_PATH . 'includes/class-esp-config.php')) {
    require_once ESP_PATH . 'includes/class-esp-config.php';
} else {
    error_log('ESP Uninstall: Configuration file not found.');
    return;
}

/**
 * 置換後検証が失敗したときの条件付き復元。
 * 自分が設置したバイト列がなお残っている場合だけ置き戻す。
 * 他者の同時変更・読取不能・symlinkは上書きしない。
 */
function esp_uninstall_restore_if_unchanged($file, $backup, $installed, $mode) {
    if (is_link($file) || !is_file($backup) ||
        @file_get_contents($file) !== $installed) {
        return false;
    }
    if (!@chmod($backup, $mode) || !@rename($backup, $file)) {
        return false;
    }
    return @fileperms($file) !== false &&
        (@fileperms($file) & 0777) === $mode;
}

/**
 * アンインストール時も既存.htaccessを直接上書きしない。
 * 保護マーカーが不正なら推測で削除しない。失敗しても管理者の
 * WordPress標準アンインストールとDB削除は妨げず、手動復旧を記録する。
 */
function esp_uninstall_remove_rewrite_safely() {
    $dir = wp_upload_dir();
    if (!empty($dir['error']) || empty($dir['basedir']) || !is_dir($dir['basedir'])) {
        return false;
    }
    $file = rtrim($dir['basedir'], '/\\') . '/.htaccess';
    if (is_link($file)) {
        return false;
    }
    if (!file_exists($file)) {
        return true;
    }
    $lock = @fopen($file . '.esp.lock', 'c');
    if (!$lock) {
        return false;
    }
    $temp = null;
    $backup = null;
    $keep_backup = false;
    try {
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            return false;
        }
        $old = @file_get_contents($file);
        if ($old === false) {
            return false;
        }
        $begin = substr_count($old, '# BEGIN ESP Media Protection');
        $end = substr_count($old, '# END ESP Media Protection');
        if ($begin === 0 && $end === 0) {
            return true; // 他者のルールだけなら触らない。
        }
        if ($begin !== 1 || $end !== 1 ||
            strpos($old, '# BEGIN ESP Media Protection') >=
            strpos($old, '# END ESP Media Protection')) {
            return false;
        }
        $pattern = '/^# BEGIN ESP Media Protection[ \\t]*\\r?\\n.*?^# END ESP Media Protection[ \\t]*(?:\\r?\\n|\\z)/ms';
        $removed = 0;
        $new = preg_replace($pattern, '', $old, 1, $removed);
        if ($new === null || $removed !== 1 ||
            strpos($new, '# BEGIN ESP Media Protection') !== false ||
            strpos($new, '# END ESP Media Protection') !== false) {
            return false;
        }
        $real_dir = @realpath($dir['basedir']);
        $mode = @fileperms($file);
        if ($real_dir === false || $mode === false) {
            return false;
        }
        $mode &= 0777;
        $temp = @tempnam($dir['basedir'], '.htaccess.esp-');
        if (!$temp || @realpath(dirname($temp)) !== $real_dir ||
            !@chmod($temp, $mode) ||
            (@fileperms($temp) & 0777) !== $mode ||
            @file_put_contents($temp, $new) !== strlen($new) ||
            @file_get_contents($temp) !== $new) {
            return false;
        }
        $backup = @tempnam($dir['basedir'], '.htaccess.esp-restore-');
        if (!$backup || @realpath(dirname($backup)) !== $real_dir ||
            !@chmod($backup, 0600) ||
            (@fileperms($backup) & 0777) !== 0600 ||
            @file_put_contents($backup, $old) !== strlen($old) ||
            @file_get_contents($backup) !== $old) {
            return false;
        }
        if (!file_exists($file) || is_link($file) ||
            @file_get_contents($file) !== $old || !@rename($temp, $file)) {
            return false;
        }
        $temp = null;
        $readback = @file_get_contents($file);
        $perms = @fileperms($file);
        if ($readback !== $new || $perms === false || ($perms & 0777) !== $mode) {
            // 検証異常時は、再取得した実ファイルが自分の書いた内容と
            // 一致するときに限り旧バイト列を復元。他者の変更は上書きしない。
            if (esp_uninstall_restore_if_unchanged($file, $backup, $new, $mode)) {
                $backup = null;
            } else {
                if (is_file($backup)) {
                    @chmod($backup, 0600);
                }
                $keep_backup = true;
            }
            return false;
        }
        return true;
    } finally {
        if ($temp && is_file($temp)) {
            @unlink($temp);
        }
        if ($backup && !$keep_backup && is_file($backup)) {
            @unlink($backup);
        }
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

// ESP自身のRewriteだけを安全に除去する。他者のルールは決して推測で編集しない。
// 書込権限や他者の同時変更で失敗しても、明示的なアンインストールは阻止しない。
// 残ったESP RewriteはWebサーバー側で手動除去が必要となり得る。
if (!esp_uninstall_remove_rewrite_safely()) {
    error_log('ESP Uninstall: could not remove ESP Rewrite safely; administrator may need to remove the ESP block from uploads/.htaccess manually');
}

// データベースの削除処理
global $wpdb;

// テーブルの削除（存在確認してから削除）
foreach (ESP_Config::DB_TABLES as $table_name) {
    $table = $wpdb->prefix . $table_name;
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

// オプションの削除
delete_option(ESP_Config::OPTION_KEY);
delete_option(ESP_Config::VERSION_OPTION_KEY);
delete_option('esp_db_version');
delete_option('esp_migration_state');
delete_option('esp_update_state');
delete_site_transient('esp_stable_release_v1');
delete_option('esp_post_auth_epoch');
delete_option('esp_media_auth_epoch');
delete_option('esp_rewrite_policy_version');
delete_option('esp_rewrite_last_error');
delete_option('esp_runtime_state');
delete_option('esp_runtime_verify_lock');
delete_option('esp_integrity_last_audit');
delete_option('esp_integrity_check_progress');

// 投稿メタデータの削除
$wpdb->delete($wpdb->postmeta, array('meta_key' => ESP_Config::PERMALINK_PATH_META_KEY));

// アンインストール時は保護クラスがロードされないため、保存済みの固定メタキーを直接削除。
$wpdb->delete($wpdb->postmeta, array('meta_key' => '_esp_media_protected_path_id'));

// トランジェントの削除
delete_transient('esp_protected_posts');
delete_transient('esp_protected_media');
delete_transient('esp_protected_posts_v2');
delete_transient('esp_protected_media_v2');
delete_transient('esp_path_index');
delete_transient('esp_path_index_v2');

// Cronジョブのスケジュール解除
$cron_hooks = [
    ESP_Config::DAILY_CLEANUP_HOOK,
    ESP_Config::INTEGRITY_CHECK_HOOK,
    'esp_media_cache_refresh',
    'esp_all_cache_refresh',
    'esp_verify_runtime_once',
    'esp_update_retry'
];

foreach ($cron_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

// Rewriteブロックは上部で安全に除去を試みた。失敗時はエラーログに手動確認の必要性を記録。
