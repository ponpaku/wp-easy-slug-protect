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

$default_prefix = isset($esp_gate_config['nginx_internal_prefix']) ? $esp_gate_config['nginx_internal_prefix'] : '/protected-uploads';
$default_prefix = rtrim($default_prefix, '/');
$variant_prefix = isset($esp_gate_config['nginx_variants_prefix']) ? $esp_gate_config['nginx_variants_prefix'] : '';
$variant_prefix = rtrim($variant_prefix, '/');

$relative = isset($context['relative_path']) ? $context['relative_path'] : '';
if ($relative === '') {
    http_response_code(500);
    return;
}

$variant_relative = isset($context['nginx_relative_path']) ? $context['nginx_relative_path'] : '';

$selected_prefix = $default_prefix;
$selected_relative = $relative;

if ($variant_relative !== '' && $variant_prefix !== '') {
    $selected_prefix = $variant_prefix;
    $selected_relative = $variant_relative;
}

$internal_path = $selected_prefix . '/' . ltrim(str_replace('\\', '/', $selected_relative), '/');
esp_gate_clear_delivery_headers();
if (!empty($context['delivery_content_type'])) {
    header('Content-Type: ' . $context['delivery_content_type']);
}
header('X-Accel-Redirect: ' . $internal_path);
exit;
