<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/ngenius.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/finance.php';

$invoiceId = (int)($_GET['invoice_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM invoices WHERE id=? AND user_id=? AND is_draft=0');
$stmt->execute([$invoiceId, $_SESSION['user_id']]);
$invoice = $stmt->fetch();

$resultState = null; $resultError = null;

if (!$invoice) {
    $resultError = is_rtl() ? 'الفاتورة غير موجودة.' : 'Invoice not found.';
} elseif ($invoice['status'] === 'paid' && finance_invoice_balance($invoice) <= 0) {
    $resultState = 'CAPTURED'; // already settled — nothing left to reconcile
} elseif (!$invoice['gateway_order_ref']) {
    $resultError = is_rtl() ? 'لا يوجد طلب دفع مرتبط بهذه الفاتورة.' : 'No linked payment order for this invoice.';
} else {
    // لا نثق بمعاملات الرابط فقط — نستعلم عن الحالة الحقيقية مباشرة من بوابة الدفع
    $settings = ngenius_settings($pdo);
    $tokenResult = ngenius_get_access_token($settings['ngenius_environment'], $settings['ngenius_api_key'], $settings['ngenius_realm_name'] ?: 'ni');

    if (!$tokenResult['ok']) {
        $resultError = $tokenResult['error'];
    } else {
        $status = ngenius_get_order_status($settings['ngenius_environment'], $settings['ngenius_outlet_ref'], $tokenResult['token'], $invoice['gateway_order_ref']);
        if (!$status['ok']) {
            $resultError = $status['error'];
        } else {
            $resultState = $status['state'];
            if (ngenius_is_paid_state($resultState)) {
                // Reconcile against the amount actually charged for this order (not the invoice
                // total), so a partially-paid invoice is settled for the correct remainder.
                $amtStmt = $pdo->prepare("SELECT amount FROM payment_transactions WHERE gateway_order_ref=? AND event_type='ORDER_CREATED' ORDER BY id DESC LIMIT 1");
                $amtStmt->execute([$invoice['gateway_order_ref']]);
                $orderAmount = $amtStmt->fetchColumn();
                $amount = ($orderAmount !== false && $orderAmount !== null) ? (float)$orderAmount : finance_invoice_balance($invoice);
                $recorded = finance_record_payment($pdo, $invoiceId, $amount, [
                    'method' => 'ngenius', 'reference' => $invoice['gateway_order_ref'],
                    'idempotency_key' => 'ngenius:' . $invoice['gateway_order_ref'],
                    'recorded_by_type' => 'system', 'notes' => 'N-Genius return: ' . $resultState,
                ]);
                if (!empty($recorded['ok']) && empty($recorded['duplicate'])) {
                    notify($pdo, 'lawyer', $invoice['lawyer_id'], is_rtl() ? 'تم استلام دفعة إلكترونية' : 'Online payment received', $invoice['description'], '/lawyer/invoices.php');
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/app_header.php';
$paid = ngenius_is_paid_state($resultState);
?>
<div class="app-content">
  <div class="dash-card" style="text-align:center;padding:48px 24px">
    <?php if ($paid): ?>
      <div style="font-size:2.4rem;margin-bottom:10px">✅</div>
      <h3><?= is_rtl() ? 'تم الدفع بنجاح' : 'Payment successful' ?></h3>
      <p><?= is_rtl() ? 'تم تأكيد استلام دفعتك وتحديث حالة الفاتورة.' : 'Your payment was confirmed and the invoice status updated.' ?></p>
    <?php elseif ($resultError): ?>
      <div style="font-size:2.4rem;margin-bottom:10px">⚠️</div>
      <h3><?= is_rtl() ? 'تعذّر التأكد من حالة الدفع' : 'Could not confirm payment status' ?></h3>
      <p><?= htmlspecialchars($resultError) ?></p>
    <?php else: ?>
      <div style="font-size:2.4rem;margin-bottom:10px">⏳</div>
      <h3><?= is_rtl() ? 'الدفع قيد المعالجة أو لم يكتمل' : 'Payment pending or incomplete' ?></h3>
      <p><?= is_rtl() ? 'حالة الطلب الحالية: ' : 'Current order state: ' ?><?= htmlspecialchars($resultState ?? '—') ?></p>
    <?php endif; ?>
    <a href="/invoices.php" class="btn btn-primary" style="margin-top:16px"><?= is_rtl() ? 'الذهاب لفواتيري' : 'Go to my invoices' ?></a>
  </div>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
