<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/ngenius.php';
header('Content-Type: application/json; charset=utf-8');

$settings = ngenius_settings($pdo);

if (empty($settings['ngenius_outlet_ref']) || empty($settings['ngenius_api_key'])) {
    echo json_encode(['ok' => false, 'message' => 'أدخل Outlet Reference ومفتاح API واحفظهما أولاً.']);
    exit;
}

$result = ngenius_get_access_token(
    $settings['ngenius_environment'] ?? 'sandbox',
    $settings['ngenius_api_key'],
    $settings['ngenius_realm_name'] ?: 'ni'
);

echo json_encode($result['ok']
    ? ['ok' => true, 'message' => 'الاتصال ناجح — تم الحصول على رمز دخول من بوابة N-Genius بنجاح.']
    : ['ok' => false, 'message' => $result['error']]
);
