<?php
/**
 * Apache(X-Sendfile)経由で保護ファイルを配信するドライバー。
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

esp_gate_clear_delivery_headers();
header('X-Sendfile: ' . $context['file_path']);
exit;
