<?php
/**
 * 高速ゲートの直アクセスを防止するガード。
 *
 * @package EasySlugProtect
 */

if (defined('ESP_GATE_ENV_PASSED')) {
    return;
}

$expected_key = defined('ESP_GATE_EXPECTED_KEY') ? (string) ESP_GATE_EXPECTED_KEY : '';
if ($expected_key === '') {
    http_response_code(403);
    return;
}

$env_key = '';
if (isset($_SERVER['ESP_MEDIA_GATE_KEY'])) {
    $env_key = (string) $_SERVER['ESP_MEDIA_GATE_KEY'];
} elseif (isset($_SERVER['REDIRECT_ESP_MEDIA_GATE_KEY'])) {
    $env_key = (string) $_SERVER['REDIRECT_ESP_MEDIA_GATE_KEY'];
} else {
    $fetched = getenv('ESP_MEDIA_GATE_KEY');
    if ($fetched !== false) {
        $env_key = (string) $fetched;
    }
}

if ($env_key === '' || !hash_equals($expected_key, $env_key)) {
    http_response_code(403);
    return;
}

define('ESP_GATE_ENV_PASSED', true);
