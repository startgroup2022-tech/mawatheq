<?php
/**
 * Admin N-Genius diagnostic endpoint (Execution 67).
 *
 *   GET  → run the staged probe and return exactly where the payment path stops.
 *   POST → same, but may also create a REAL unpaid order (dry_run=0). Creating an order
 *          never charges a card — an order becomes a payment only when a human pays it on
 *          the hosted page — so it is safe on Live, but the default stays a dry run.
 *
 * Why: the customer-facing "could not start the payment" message is identical for a
 * disabled gateway, a rejected key, a wrong environment, a bare 400 on the token call and
 * an outlet-rejected order. This endpoint names the stage and hands back a correlation id
 * so a support ticket can be matched to one diagnostics row. No secret is ever returned.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/ngenius.php';
require_once __DIR__ . '/../includes/gateway_diagnostics.php';
require_once __DIR__ . '/../includes/audit_log.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$dryRun = true;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $dryRun = ($_POST['dry_run'] ?? '1') !== '0';
}

$result = gateway_diag_probe($pdo, [
    'dry_run' => $dryRun,
    'email' => 'diagnostic@mawatheq.com',
    'name' => 'Diagnostic Probe',
]);

log_action($pdo, 'تشخيص بوابة N-Genius — ' . ($result['ok'] ? 'نجح' : ('توقف عند: ' . $result['verdict'])), 'gateway', 0);

$verdictMessages = [
    'config' => 'الإعدادات ناقصة أو البوابة معطّلة (المفتاح أو رقم المنفذ غير محفوظ).',
    'token' => 'فشل الحصول على رمز الدخول من البوابة — تحقق من صحة المفتاح ومن البيئة.',
    'order' => 'فشل إنشاء طلب الدفع لدى البوابة — راجع التفاصيل أدناه.',
    'ok'    => $dryRun ? 'الإعدادات سليمة وتم الحصول على رمز الدخول بنجاح.' : 'تم إنشاء طلب دفع تجريبي بنجاح (لم يُخصم أي مبلغ).',
];
echo json_encode([
    'ok' => $result['ok'],
    'message' => $verdictMessages[$result['verdict']] ?? 'نتيجة غير معروفة.',
    'verdict' => $result['verdict'],
    'correlation_id' => $result['correlation_id'],
    'environment' => $result['environment'],
    'base_url' => $result['base_url'],
    'dry_run' => $result['dry_run'],
    'order_reference' => $result['order_reference'],
    'stages' => $result['stages'],
]);
