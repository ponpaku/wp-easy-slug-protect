<?php
/**
 * 高速ゲートの直アクセスを防止するガード。
 *
 * @package EasySlugProtect
 */

// 既に検証済みなら以降の処理は不要
if (defined('ESP_GATE_ENV_PASSED')) {
    return;
}

$expected_key = defined('ESP_GATE_EXPECTED_KEY') ? (string) ESP_GATE_EXPECTED_KEY : '';
// 想定キーが空なら認証不可
if ($expected_key === '') {
    http_response_code(403);
    return;
}

$env_key = '';
if (isset($_SERVER['ESP_MEDIA_GATE_KEY'])) {
    // 通常のCGI環境で渡されたキー
    $env_key = (string) $_SERVER['ESP_MEDIA_GATE_KEY'];
} elseif (isset($_SERVER['REDIRECT_ESP_MEDIA_GATE_KEY'])) {
    // ApacheのREDIRECT_プレフィックスを考慮
    $env_key = (string) $_SERVER['REDIRECT_ESP_MEDIA_GATE_KEY'];
} else {
    // getenvでの取得も試みる
    $fetched = getenv('ESP_MEDIA_GATE_KEY');
    if ($fetched !== false) {
        $env_key = (string) $fetched;
    }
}

// 取得したキーが一致しなければ403
if ($env_key === '' || $env_key !== $expected_key) {
    http_response_code(403);
    return;
}

// 検証済みフラグを立てる
define('ESP_GATE_ENV_PASSED', true);
