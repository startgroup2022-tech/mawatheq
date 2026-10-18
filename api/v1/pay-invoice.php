<?php
/**
 * POST /api/v1/pay-invoice.php
 * body: { "invoice_id": 12 }
 * response: { ok, payment_url }  — افتح payment_url داخل WebView بالتطبيق
 *
 * بعد إتمام الدفع، N-Genius يعيد توجيه المستخدم لصفحة الموقع payment-return.php
 * التي تتحقق من الحالة الحقيقية عبر الـAPI وتُحدّث الفاتورة تلقائياً (ونفس الشيء
 * يحدث فوراً عبر ngenius-webhook.php بدون انتظار عودة المستخدم للتطبيق).
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/ngenius.php';
require_once __DIR__ . '/../../includes/finance.php';

$auth = api_authenticate($pdo);
if ($auth['type'] !== 'client') api_error('only clients can pay invoices', 403);

$in = api_input();
$invoiceId = (int)($in['invoice_id'] ?? 0);
if (!$invoiceId) api_error('invoice_id is required');

$stmt = $pdo->prepare('SELECT i.*, u.full_name, u.email FROM invoices i JOIN users u ON u.id = i.user_id WHERE i.id=? AND i.user_id=? AND i.is_draft=0');
$stmt->execute([$invoiceId, $auth['id']]);
$invoice = $stmt->fetch();

if (!$invoice) api_error('invoice not found', 404);
if (!in_array($invoice['status'], ['unpaid','partially_paid','overdue'], true) || finance_invoice_balance($invoice) <= 0) api_error('invoice is not payable', 409);

$settings = ngenius_settings($pdo);
if (!ngenius_is_enabled($settings)) api_error('online payment is not enabled yet', 503);

$tokenResult = ngenius_get_access_token($settings['ngenius_environment'], $settings['ngenius_api_key'], $settings['ngenius_realm_name'] ?: 'ni');
if (!$tokenResult['ok']) api_error($tokenResult['error'], 502);

$redirectUrl = rtrim(SITE_URL, '/') . '/payment-return.php?invoice_id=' . $invoiceId;
$cancelUrl = rtrim(SITE_URL, '/') . '/invoices.php';

$order = ngenius_create_order(
    $settings['ngenius_environment'], $settings['ngenius_outlet_ref'], $tokenResult['token'],
    finance_invoice_balance($invoice), $invoice['email'], $invoice['full_name'],
    $redirectUrl, $cancelUrl, 'INV-' . $invoiceId, 'ar'
);
if (!$order['ok']) api_error($order['error'], 502);

$pdo->prepare('UPDATE invoices SET gateway=?, gateway_order_ref=? WHERE id=?')->execute(['ngenius', $order['order_reference'], $invoiceId]);
$pdo->prepare('INSERT INTO payment_transactions (invoice_id, gateway, gateway_order_ref, event_type, amount) VALUES (?,?,?,?,?)')
    ->execute([$invoiceId, 'ngenius', $order['order_reference'], 'ORDER_CREATED', finance_invoice_balance($invoice)]);

api_json(['ok' => true, 'payment_url' => $order['payment_url'], 'order_reference' => $order['order_reference']]);
