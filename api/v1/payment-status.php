<?php
/**
 * GET /api/v1/payment-status.php?invoice_id=12
 * يستعلم عن الحالة الحقيقية من بوابة الدفع (وليس فقط ما هو محفوظ عندنا)
 * ويحدّث الفاتورة تلقائياً إن تأكد نجاح الدفع.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/ngenius.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/finance.php';

$auth = api_authenticate($pdo);
if ($auth['type'] !== 'client') api_error('only clients can check invoice payment status', 403);

$invoiceId = (int)($_GET['invoice_id'] ?? 0);
if (!$invoiceId) api_error('invoice_id is required');

$stmt = $pdo->prepare('SELECT * FROM invoices WHERE id=? AND user_id=? AND is_draft=0');
$stmt->execute([$invoiceId, $auth['id']]);
$invoice = $stmt->fetch();
if (!$invoice) api_error('invoice not found', 404);

if (!in_array($invoice['status'], ['unpaid','partially_paid','overdue'], true) || finance_invoice_balance($invoice) <= 0) {
    api_json(['ok' => true, 'status' => $invoice['status'], 'balance' => finance_invoice_balance($invoice)]);
}
if (!$invoice['gateway_order_ref']) {
    api_json(['ok' => true, 'status' => $invoice['status'], 'balance' => finance_invoice_balance($invoice)]);
}

$settings = ngenius_settings($pdo);
$tokenResult = ngenius_get_access_token($settings['ngenius_environment'], $settings['ngenius_api_key'], $settings['ngenius_realm_name'] ?: 'ni');
if (!$tokenResult['ok']) api_error($tokenResult['error'], 502);

$statusResult = ngenius_get_order_status($settings['ngenius_environment'], $settings['ngenius_outlet_ref'], $tokenResult['token'], $invoice['gateway_order_ref']);
if (!$statusResult['ok']) api_error($statusResult['error'], 502);

if (ngenius_is_paid_state($statusResult['state'])) {
    // Reconcile the amount actually charged for this order, not the full invoice total.
    $amtStmt = $pdo->prepare("SELECT amount FROM payment_transactions WHERE gateway_order_ref=? AND event_type='ORDER_CREATED' ORDER BY id DESC LIMIT 1");
    $amtStmt->execute([$invoice['gateway_order_ref']]);
    $orderAmount = $amtStmt->fetchColumn();
    $amount = ($orderAmount !== false && $orderAmount !== null) ? (float)$orderAmount : finance_invoice_balance($invoice);
    $recorded = finance_record_payment($pdo, $invoiceId, $amount, [
        'method' => 'ngenius', 'reference' => $invoice['gateway_order_ref'],
        'idempotency_key' => 'ngenius:' . $invoice['gateway_order_ref'],
        'recorded_by_type' => 'system', 'notes' => 'N-Genius status: ' . $statusResult['state'],
    ]);
    if (!empty($recorded['ok']) && empty($recorded['duplicate'])) {
        notify($pdo, 'lawyer', $invoice['lawyer_id'], 'تم استلام دفعة إلكترونية', $invoice['description'], '/lawyer/invoices.php');
    }
    api_json(['ok' => true, 'status' => $recorded['status'] ?? 'paid', 'balance' => $recorded['balance'] ?? 0, 'gateway_state' => $statusResult['state']]);
}

api_json(['ok' => true, 'status' => $invoice['status'], 'gateway_state' => $statusResult['state']]);
