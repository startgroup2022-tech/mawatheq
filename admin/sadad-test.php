<?php
/**
 * Admin SADAD test endpoint.
 *   GET  → connection probe (no payment created).
 *   POST → sandbox/config validation for a test payment. On Live it NEVER creates a real
 *          payment: it validates the configuration and returns a clear warning instead.
 * Returns JSON. Secrets are never included in the response.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/gateway/bootstrap.php';
require_once __DIR__ . '/includes/gateway_ui.php';
require_once __DIR__ . '/../includes/audit_log.php';
header('Content-Type: application/json; charset=utf-8');

$scopeRaw = $_REQUEST['tenant'] ?? 'platform';
[$tenantType, $tenantId] = gateway_ui_tenant_scope($scopeRaw, $pdo);
$scopeValue = $tenantType === 'platform' ? 'platform' : $tenantType . ':' . $tenantId;

$config = sadad_settings($pdo, $tenantType, $tenantId);
$gateway = new SadadGateway($config);

// ---- connection test (GET) ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (sadad_missing_keys($config)) {
        echo json_encode(['ok' => false, 'message' => 'الإعدادات غير مكتملة — أكمل الحقول الناقصة أولاً.']);
        exit;
    }
    $health = $gateway->healthCheck();
    // Persist the last-check outcome on the tenant's settings row (non-secret).
    $store = $config;
    $store['sadad_last_connection_ok'] = !empty($health['connected']) ? '1' : '0';
    $store['sadad_last_connection_at'] = date('Y-m-d H:i:s');
    sadad_save_settings($pdo, $store, $tenantType, $tenantId);
    log_action($pdo, 'اختبار اتصال SADAD (' . (!empty($health['connected']) ? 'ناجح' : 'فاشل') . ') — ' . ($health['code'] ?? 'unknown'), 'gateway', 0);

    $message = !empty($health['connected'])
        ? 'الاتصال ناجح.'
        : ($health['code'] === 'configured_no_probe'
            ? 'الإعدادات مكتملة؛ لا يوجد endpoint تحقق رسمي لاختبار الاتصال.'
            : ('فشل الاتصال: ' . ($health['error'] ?? 'خطأ غير معروف')));
    echo json_encode(['ok' => !empty($health['connected']), 'message' => $message, 'code' => $health['code'] ?? null]);
    exit;
}

// ---- test payment (POST) ----
if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token']); exit; }

if (sadad_missing_keys($config)) {
    echo json_encode(['ok' => false, 'message' => 'الإعدادات غير مكتملة — لا يمكن بدء اختبار الدفع.']);
    exit;
}

$amount = (float)($_POST['amount'] ?? 0);
$currency = strtoupper((string)($config['sadad_currency'] ?? 'SAR'));
if (!is_finite($amount) || $amount <= 0) { echo json_encode(['ok' => false, 'message' => 'مبلغ غير صالح.']); exit; }

if (($config['sadad_environment'] ?? 'sandbox') === 'live') {
    // Guardrail: never auto-charge real money from the admin test button.
    log_action($pdo, 'اختبار دفع SADAD مرفوض (بيئة إنتاج)', 'gateway', 0);
    echo json_encode(['ok' => false, 'message' => 'أنت على بيئة الإنتاج. اختبار الدفع التلقائي معطّل — لن تُنفّذ عملية حقيقية. بدّل إلى Sandbox للاختبار.']);
    exit;
}

// Sandbox: create a real sandbox order through the configured endpoint (no real money).
$intent = [
    'amount' => $amount, 'currency' => $currency,
    'reference' => 'SADAD-TEST-' . strtoupper(bin2hex(random_bytes(4))),
    'customer' => [
        'name' => trim((string)($_POST['name'] ?? 'Test Customer')),
        'email' => trim((string)($_POST['email'] ?? 'test@example.com')),
        'phone' => trim((string)($_POST['phone'] ?? '')),
    ],
    'urls' => [
        'return' => sadad_return_url(),
        'cancel' => sadad_cancel_url(),
        'webhook' => sadad_webhook_url(),
    ],
    'language' => current_lang(),
];
$result = $gateway->createPayment($intent);
log_action($pdo, 'اختبار دفع SADAD (تجريبي) — ' . (!empty($result['ok']) ? 'ناجح' : 'فاشل'), 'gateway', 0);

if (empty($result['ok'])) {
    echo json_encode(['ok' => false, 'message' => 'فشل اختبار الدفع: ' . ($result['error'] ?? 'خطأ غير معروف')]);
    exit;
}
echo json_encode([
    'ok' => true,
    'message' => 'تم إنشاء عملية دفع تجريبية بنجاح.',
    'reference' => $result['reference'] ?? null,
    'redirect_url' => $result['redirect_url'] ?? null,
]);
