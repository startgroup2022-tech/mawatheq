<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/ngenius.php';
require_once __DIR__ . '/includes/notifications.php';

$invoiceId = (int)($_GET['invoice_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM invoices WHERE id=? AND user_id=? AND is_draft=0');
$stmt->execute([$invoiceId, $_SESSION['user_id']]);
$invoice = $stmt->fetch();

$resultState = null; $resultError = null;

if ($invoice && $invoice['status'] === 'paid') {
    $resultState = 'CAPTURED';
} elseif ($invoice && $invoice['gateway_order_ref']) {
    if ($invoice['status'] !== 'unpaid') {
        $resultError = is_rtl() ? 'حالة هذه الفاتورة لا تسمح بتحديث دفعها.' : 'This invoice status does not allow payment processing.';
    } else {
    // لا نثق بمعاملات الرابط فقط — نستعلم عن الحالة الحقيقية مباشرة من بوابة الدفع
    $settings = ngenius_settings($pdo);
    $tokenResult = ngenius_get_access_token($settings['ngenius_environment'], $settings['ngenius_api_key'], $settings['ngenius_realm_name'] ?: 'ni');

    if ($tokenResult['ok']) {
        $status = ngenius_get_order_status($settings['ngenius_environment'], $settings['ngenius_outlet_ref'], $tokenResult['token'], $invoice['gateway_order_ref']);
        if ($status['ok']) {
            $resultState = $status['state'];
            if (ngenius_is_paid_state($resultState) && $invoice['status'] !== 'paid') {
                $pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW(), payment_method='ngenius' WHERE id=? AND status='unpaid' AND is_draft=0")->execute([$invoiceId]);
                $pdo->prepare('INSERT INTO payment_transactions (invoice_id, gateway, gateway_order_ref, event_type, amount) VALUES (?,?,?,?,?)')
                    ->execute([$invoiceId, 'ngenius', $invoice['gateway_order_ref'], $resultState, $invoice['amount']]);
                notify($pdo, 'lawyer', $invoice['lawyer_id'], is_rtl() ? 'تم استلام دفعة إلكترونية' : 'Online payment received', $invoice['description'], '/lawyer/invoices.php');
            }
        } else {
            $resultError = $status['error'];
        }
    } else {
        $resultError = $tokenResult['error'];
    }
    }
} else {
    $resultError = is_rtl() ? 'الفاتورة غير موجودة أو لا يوجد طلب دفع مرتبط بها.' : 'Invoice not found or no linked payment order.';
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
