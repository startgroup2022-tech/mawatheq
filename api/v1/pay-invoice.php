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
require_once __DIR__ . '/../../includes/gateway/bootstrap.php';
require_once __DIR__ . '/../../includes/gateway/payment_service.php';
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

$gateway = gateway_for_new_payment($pdo, $invoice);
if (!$gateway) api_error('online payment is not enabled yet', 503);
if (!$gateway->supportsCurrency($invoice['currency'] ?? null)) api_error('this invoice currency is not supported by the payment gateway', 409);

$started = gateway_start_invoice_payment($pdo, $invoice, [
    'customer_name' => $invoice['full_name'],
    'customer_email' => $invoice['email'],
    'language' => 'ar',
]);
if (empty($started['ok'])) api_error($started['error'] ?? 'gateway_error', 502);

api_json(['ok' => true, 'payment_url' => $started['redirect_url'], 'order_reference' => $started['reference'], 'gateway' => $started['gateway']]);
