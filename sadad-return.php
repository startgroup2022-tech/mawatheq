<?php
/**
 * SADAD browser return endpoint.
 *
 * This is the page the customer lands on after paying. It is NOT the source of truth: it calls
 * the gateway's verification operation server-side and only records a payment when SADAD
 * confirms it. The webhook (sadad-webhook.php) performs the same reconciliation independently,
 * so a customer who closes the browser still gets their invoice settled.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/gateway/bootstrap.php';
require_once __DIR__ . '/includes/gateway/payment_service.php';
require_once __DIR__ . '/includes/finance.php';

$invoiceId = (int)($_GET['invoice_id'] ?? 0);
$stmt = $pdo->prepare('SELECT i.*, u.full_name, u.email FROM invoices i JOIN users u ON u.id=i.user_id WHERE i.id=? AND i.user_id=? AND i.is_draft=0');
$stmt->execute([$invoiceId, $_SESSION['user_id']]);
$invoice = $stmt->fetch();

$resultState = null; $resultError = null; $paid = false;

if (!$invoice) {
    $resultError = is_rtl() ? 'الفاتورة غير موجودة.' : 'Invoice not found.';
} elseif (finance_invoice_balance($invoice) <= 0 && $invoice['status'] === 'paid') {
    $paid = true; $resultState = 'PAID';
} elseif (empty($invoice['gateway_order_ref']) || $invoice['gateway'] !== 'sadad') {
    $resultError = is_rtl() ? 'لا يوجد طلب دفع SADAD مرتبط بهذه الفاتورة.' : 'No linked SADAD payment order for this invoice.';
} else {
    $settle = gateway_verify_and_settle($pdo, $invoice, 'sadad', [
        'reference' => $invoice['gateway_order_ref'],
        'source' => 'return',
    ]);
    $resultState = $settle['state'];
    $paid = gateway_is_paid_state($resultState);
    if (!$settle['ok'] && $settle['error']) {
        $resultError = is_rtl() ? 'تعذّر التأكد من حالة الدفع حالياً.' : 'Could not confirm the payment status right now.';
    }
}

require_once __DIR__ . '/includes/app_header.php';
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
