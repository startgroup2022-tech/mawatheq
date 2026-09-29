<?php
/**
 * GET /api/v1/payment-status.php?invoice_id=12
 * يستعلم عن الحالة الحقيقية من بوابة الدفع (وليس فقط ما هو محفوظ عندنا)
 * ويحدّث الفاتورة تلقائياً إن تأكد نجاح الدفع.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/ngenius.php';
require_once __DIR__ . '/../../includes/notifications.php';

$auth = api_authenticate($pdo);
if ($auth['type'] !== 'client') api_error('only clients can check invoice payment status', 403);

$invoiceId = (int)($_GET['invoice_id'] ?? 0);
if (!$invoiceId) api_error('invoice_id is required');

$stmt = $pdo->prepare('SELECT * FROM invoices WHERE id=? AND user_id=? AND is_draft=0');
$stmt->execute([$invoiceId, $auth['id']]);
$invoice = $stmt->fetch();
if (!$invoice) api_error('invoice not found', 404);

if ($invoice['status'] === 'paid') {
    api_json(['ok' => true, 'status' => 'paid']);
}
if (!$invoice['gateway_order_ref']) {
    api_json(['ok' => true, 'status' => $invoice['status']]);
}
if ($invoice['status'] !== 'unpaid') api_json(['ok'=>true,'status'=>$invoice['status']]);

$settings = ngenius_settings($pdo);
$tokenResult = ngenius_get_access_token($settings['ngenius_environment'], $settings['ngenius_api_key'], $settings['ngenius_realm_name'] ?: 'ni');
if (!$tokenResult['ok']) api_error($tokenResult['error'], 502);

$statusResult = ngenius_get_order_status($settings['ngenius_environment'], $settings['ngenius_outlet_ref'], $tokenResult['token'], $invoice['gateway_order_ref']);
if (!$statusResult['ok']) api_error($statusResult['error'], 502);

if (ngenius_is_paid_state($statusResult['state'])) {
    $pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW(), payment_method='ngenius' WHERE id=? AND status='unpaid' AND is_draft=0")->execute([$invoiceId]);
    notify($pdo, 'lawyer', $invoice['lawyer_id'], 'تم استلام دفعة إلكترونية', $invoice['description'], '/lawyer/invoices.php');
    api_json(['ok' => true, 'status' => 'paid', 'gateway_state' => $statusResult['state']]);
}

api_json(['ok' => true, 'status' => $invoice['status'], 'gateway_state' => $statusResult['state']]);
