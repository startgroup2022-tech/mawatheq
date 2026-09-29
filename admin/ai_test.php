<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/ai_provider.php';
header('Content-Type: application/json; charset=utf-8');

$settings = ai_load_settings($pdo);

if (empty($settings['ai_provider'])) {
    echo json_encode(['ok' => false, 'message' => 'لم يتم اختيار مزوّد بعد.']);
    exit;
}

$result = test_ai_connection($settings['ai_provider'], $settings['ai_api_key'] ?? '', $settings['ai_model'] ?? '');
echo json_encode($result);
