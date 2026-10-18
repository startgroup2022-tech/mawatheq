<?php
/**
 * GET /api/v1/ai-status.php          → هل المساعد الذكي مهيّأ ومتاح؟
 * GET /api/v1/ai-status.php?probe=1  → يفحص الوصول الفعلي للمزوّد (أبطأ)
 *
 * يحتاجه تطبيق الجوال ليعرض حالة المساعد الحقيقية بدل مؤشر ثابت. يعيد أعلاماً
 * فقط — لا مفتاح، ولا عنوان مزوّد، ولا أي سر. التفاصيل الحساسة تبقى في لوحة
 * الإدارة عبر ai_diagnostics().
 *
 * يتطلب توكن مستخدم؛ رموز التكامل مرفوضة تلقائياً لأن نقطة الدخول غير مدرجة في
 * api_integration_endpoint_scopes().
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/ai_provider.php';

$auth = api_authenticate($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('method not allowed', 405);
}

$settings = ai_load_settings($pdo);
$probe = isset($_GET['probe']) && $_GET['probe'] === '1';

// probe=false keeps the home screen fast; the deep check is opt-in.
$diag = ai_diagnostics($pdo, $settings, $probe);

api_json([
    'ok' => true,
    'available' => !empty($diag['configured']) && ($probe ? !empty($diag['reachable']) : true),
    'configured' => !empty($diag['configured']),
    'reachable' => !empty($diag['reachable']),
    'blocking' => $diag['blocking'] ?? null,
    'provider_supported' => !empty($diag['provider_supported']),
]);
