<?php
/**
 * Package payment return page (Execution 63).
 *
 * The customer lands here after the N-Genius hosted payment page. The URL is not trusted:
 * the payment is re-verified against the gateway server-side and the subscription is
 * activated only when the gateway itself reports a capture. Coming back here is not proof
 * of payment.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/package_payment.php';

$userId = (int)$_SESSION['user_id'];
$rtl = is_rtl();
$orderId = (int)($_GET['order_id'] ?? 0);

$order = null;
if (package_payment_schema_ready($pdo)) {
    $stmt = $pdo->prepare('SELECT * FROM package_orders WHERE id=? AND user_id=?');
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch() ?: null;
}

$state = null;
$error = null;
$activated = false;

if (!$order) {
    $error = $rtl ? 'الطلب غير موجود.' : 'Order not found.';
} else {
    $result = package_payment_verify_and_confirm($pdo, $orderId);
    $state = $result['state'] ?? null;
    $activated = !empty($result['activated']);
    if (empty($result['ok'])) $error = $result['error'] ?? 'verify_failed';

    // Re-read the order so the message reflects the activation that just happened.
    $stmt = $pdo->prepare('SELECT * FROM package_orders WHERE id=?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch() ?: $order;
}

require_once __DIR__ . '/includes/app_header.php';
$paid = $activated || ($order && $order['status'] === 'paid') || ($state !== null && gateway_is_paid_state($state));
?>
<div class="app-content">
  <div class="dash-card" style="text-align:center;padding:48px 24px">
    <?php if ($paid): ?>
      <div style="font-size:2.4rem;margin-bottom:10px">✅</div>
      <h1 style="font-size:1.3rem;margin:0 0 8px"><?= $rtl ? 'تم الدفع وتفعيل الباقة' : 'Payment received and package activated' ?></h1>
      <p><?= $rtl ? 'تم تفعيل باقتك ويمكنك الآن استخدام كل مزاياها.' : 'Your package is active and all its features are now available.' ?></p>
      <a href="/subscription.php" class="btn btn-primary" style="margin-top:16px"><?= $rtl ? 'عرض باقتي' : 'View my plan' ?></a>
    <?php elseif ($error): ?>
      <div style="font-size:2.4rem;margin-bottom:10px">⚠️</div>
      <h1 style="font-size:1.3rem;margin:0 0 8px"><?= $rtl ? 'تعذّر تأكيد حالة الدفع' : 'Could not confirm payment status' ?></h1>
      <p><?= ui_e($error) ?></p>
      <?php if ($order && $order['status'] !== 'paid'): ?>
        <a href="/checkout.php?order_id=<?= (int)$orderId ?>" class="btn btn-primary" style="margin-top:16px"><?= $rtl ? 'إعادة المحاولة' : 'Retry payment' ?></a>
      <?php endif; ?>
      <a href="/packages.php" class="btn btn-outline" style="margin-top:16px"><?= $rtl ? 'الباقات' : 'Packages' ?></a>
    <?php else: ?>
      <div style="font-size:2.4rem;margin-bottom:10px">⏳</div>
      <h1 style="font-size:1.3rem;margin:0 0 8px"><?= $rtl ? 'الدفع قيد المعالجة أو لم يكتمل' : 'Payment pending or incomplete' ?></h1>
      <p><?= $rtl ? 'لم يتم تفعيل الباقة بعد. إن خُصم المبلغ فستُفعّل تلقائياً خلال دقائق.' : 'The package is not active yet. If the amount was charged, it will activate automatically within minutes.' ?></p>
      <?php if ($order && $order['status'] !== 'paid'): ?>
        <a href="/checkout.php?order_id=<?= (int)$orderId ?>" class="btn btn-primary" style="margin-top:16px"><?= $rtl ? 'إكمال الدفع' : 'Complete payment' ?></a>
      <?php endif; ?>
      <a href="/packages.php" class="btn btn-outline" style="margin-top:16px"><?= $rtl ? 'رجوع للباقات' : 'Back to packages' ?></a>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
