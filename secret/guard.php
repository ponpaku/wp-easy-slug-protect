<?php
/**
 * 高速ゲートの直アクセスを防止するガード。
 *
 * @package EasySlugProtect
 */

require_once __DIR__ . '/gate-utils.php';

if (defined('ESP_GATE_ENV_PASSED')) {
    return;
}

$env_key = esp_gate_read_server_env('ESP_MEDIA_GATE_KEY');
if (!is_string($env_key) || $env_key === '') {
    http_response_code(403);
    return;
}

define('ESP_GATE_ENV_PASSED', true);
