<?php
/**
 * Consultation payment return page (Execution 62).
 *
 * The customer lands here after the N-Genius hosted payment page. The URL is not trusted:
 * the payment is re-verified against the gateway server-side and the booking is confirmed
 * only when the gateway itself reports a capture. Coming back here is not proof of payment.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/consultation_payment.php';

$consultationId = (int)($_GET['consultation_id'] ?? 0);

// The consultation must belong to the signed-in client — a guessed id must not reveal or
// confirm another customer's booking.
$stmt = $pdo->prepare('SELECT * FROM consultations WHERE id=? AND user_id=?');
$stmt->execute([$consultationId, (int)$_SESSION['user_id']]);
$consultation = $stmt->fetch();

$state = null;
$error = null;
$confirmed = false;

if (!$consultation) {
    $error = is_rtl() ? 'الاستشارة غير موجودة.' : 'Consultation not found.';
} elseif (!consultation_payment_schema_ready($pdo)) {
    $error = is_rtl() ? 'ميزة الدفع الإلكتروني غير مفعّلة بعد.' : 'Online payment is not enabled yet.';
} else {
    $result = consultation_payment_verify_and_confirm($pdo, $consultationId);
    $state = $result['state'] ?? null;
    $confirmed = !empty($result['confirmed']);
    if (empty($result['ok'])) $error = $result['error'] ?? 'verify_failed';
}

require_once __DIR__ . '/includes/app_header.php';
$paid = $confirmed || ($state !== null && gateway_is_paid_state($state));
?>
<div class="app-content">
  <div class="dash-card" style="text-align:center;padding:48px 24px">
    <?php if ($paid): ?>
      <div style="font-size:2.4rem;margin-bottom:10px">✅</div>
      <h1 style="font-size:1.3rem;margin:0 0 8px"><?= is_rtl() ? 'تم الدفع وتأكيد الاستشارة' : 'Payment received and consultation confirmed' ?></h1>
      <p><?= is_rtl() ? 'تم تأكيد حجزك وإشعار المحامي. يمكنك متابعة موعدك من صفحة الاستشارات.' : 'Your booking is confirmed and the lawyer has been notified. Follow your appointment from the consultations page.' ?></p>
      <a href="/consultations.php" class="btn btn-primary" style="margin-top:16px"><?= is_rtl() ? 'الذهاب لاستشاراتي' : 'Go to my consultations' ?></a>
    <?php elseif ($error): ?>
      <div style="font-size:2.4rem;margin-bottom:10px">⚠️</div>
      <h1 style="font-size:1.3rem;margin:0 0 8px"><?= is_rtl() ? 'تعذّر تأكيد حالة الدفع' : 'Could not confirm payment status' ?></h1>
      <p><?= htmlspecialchars($error) ?></p>
      <a href="/consultations.php" class="btn btn-outline" style="margin-top:16px"><?= is_rtl() ? 'رجوع للاستشارات' : 'Back to consultations' ?></a>
    <?php else: ?>
      <div style="font-size:2.4rem;margin-bottom:10px">⏳</div>
      <h1 style="font-size:1.3rem;margin:0 0 8px"><?= is_rtl() ? 'الدفع قيد المعالجة أو لم يكتمل' : 'Payment pending or incomplete' ?></h1>
      <p><?= is_rtl() ? 'لم يتم تأكيد الحجز بعد. إن خصم المبلغ فسيُؤكَّد الحجز تلقائياً خلال دقائق.' : 'The booking is not confirmed yet. If the amount was charged, it will be confirmed automatically within minutes.' ?></p>
      <a href="/consultations.php" class="btn btn-outline" style="margin-top:16px"><?= is_rtl() ? 'رجوع للاستشارات' : 'Back to consultations' ?></a>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
