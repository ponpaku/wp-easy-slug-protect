<?php
/**
 * Nginx向けに保護ファイルを内部リダイレクトするドライバー。
 *
 * @package EasySlugProtect
 */

/** gate.php を読み込み、配信許可情報を取得する */
$context = require __DIR__ . '/gate.php';
if (!defined('ESP_GATE_VERIFIED') || ESP_GATE_VERIFIED !== true) {
    return;
}

$context = esp_gate_validate_deriver_context($context);
if ($context === null) {
    return;
}

if (!isset($esp_gate_config) || !is_array($esp_gate_config)) {
    http_response_code(500);
    return;
}

$prefix = isset($esp_gate_config['nginx_internal_prefix']) ? $esp_gate_config['nginx_internal_prefix'] : '/protected-uploads';
// 内部リダイレクト先のプレフィックスを余分なスラッシュ無しで扱う
$prefix = rtrim($prefix, '/');
$relative = isset($context['relative_path']) ? $context['relative_path'] : '';
if ($relative === '') {
    http_response_code(500);
    return;
}

$internal_path = $prefix . '/' . ltrim(str_replace('\\', '/', $relative), '/');
header('X-Accel-Redirect: ' . $internal_path);
exit;
