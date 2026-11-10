<?php
/**
 * Package checkout (client) — Execution 63.
 *
 * Turns a chosen plan into a package_order, then a gateway payment, then (only after
 * the gateway independently confirms the capture) an active subscription. The POST
 * handlers run BEFORE the app shell is included, because the shell prints <head> and
 * there is no output buffering — a header() redirect after it would be dropped.
 *
 * Nothing here trusts the browser for a price or a plan id beyond what the database
 * resolves: the amount is read from `plans` by package_order_create().
 */
require_once __DIR__ . '/config/config.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request');
}
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/redirect.php';
require_once __DIR__ . '/includes/package_payment.php';

$userId = (int)$_SESSION['user_id'];
$rtl = is_rtl();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $cycle = (string)($_POST['cycle'] ?? 'monthly');
    if (!in_array($cycle, ['monthly', 'yearly'], true)) $cycle = 'monthly';

    if ($action === 'checkout') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE id=? AND status='active'");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();
        if (!$plan) {
            $error = $rtl ? 'الباقة غير متاحة.' : 'This package is not available.';
        } elseif (package_plan_is_free($plan, $cycle)) {
            // A free plan needs no gateway order; activate and return to the catalogue.
            $result = package_activate_free($pdo, $userId, $planId, $cycle);
            if (!empty($result['ok'])) {
                header('Location: /packages.php?activated=1');
                exit;
            }
            $error = $rtl ? 'تعذّر تفعيل الباقة.' : 'Could not activate the package.';
        } else {
            $created = package_order_create($pdo, $userId, $planId, $cycle);
            if (!empty($created['ok'])) {
                header('Location: /checkout.php?order_id=' . (int)$created['order_id']);
                exit;
            }
            $error = $rtl ? 'تعذّر إنشاء طلب الشراء.' : 'Could not create the order.';
        }
    } elseif ($action === 'pay') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $order = null;
        if (package_payment_schema_ready($pdo)) {
            $os = $pdo->prepare('SELECT * FROM package_orders WHERE id=? AND user_id=?');
            $os->execute([$orderId, $userId]);
            $order = $os->fetch();
        }
        if (!$order) {
            $error = $rtl ? 'الطلب غير موجود.' : 'Order not found.';
        } elseif ($order['status'] === 'paid') {
            header('Location: /package-payment-return.php?order_id=' . $orderId);
            exit;
        } else {
            // The gateway requires a payer email; the session does not carry one (login stores
            // only user_id and user_name), so read it from the account — otherwise the order is
            // created with an empty emailAddress and rejected.
            $cust = $pdo->prepare('SELECT full_name, email FROM users WHERE id=?');
            $cust->execute([$userId]);
            $customer = $cust->fetch() ?: [];
            $started = package_payment_start($pdo, $order, [
                'customer_email' => (string)($customer['email'] ?? ''),
                'customer_name'  => (string)($customer['full_name'] ?? ($_SESSION['user_name'] ?? '')),
                'language'       => current_lang(),
            ]);
            if (!empty($started['ok'])) {
                header('Location: ' . $started['redirect_url']);
                exit;
            }
            // The gateway is not reachable or not configured. The order stays pending and
            // the client sees an honest message with a retry, never a false success. The
            // correlation id lets support find the exact gateway failure without guessing.
            $corr = (string)($started['correlation_id'] ?? '');
            $error = $rtl
                ? 'تعذّر بدء عملية الدفع الإلكتروني حالياً. لم يتم خصم أي مبلغ. يمكنك المحاولة مجدداً أو التواصل معنا.'
                : 'Online payment could not be started. No amount was charged. You can retry or contact us.';
            if ($corr !== '') {
                $error .= ' ' . ($rtl ? 'الرقم المرجعي:' : 'Reference:') . ' ' . $corr;
            }
        }
    }
}

// GET (or a failed POST): show the order summary and a Pay Now button.
$orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$order = null;
$plan = null;
if ($orderId > 0 && package_payment_schema_ready($pdo)) {
    $os = $pdo->prepare('SELECT * FROM package_orders WHERE id=? AND user_id=?');
    $os->execute([$orderId, $userId]);
    $order = $os->fetch() ?: null;
    if ($order) {
        $ps = $pdo->prepare('SELECT * FROM plans WHERE id=?');
        $ps->execute([(int)$order['plan_id']]);
        $plan = $ps->fetch() ?: null;
    }
}

require_once __DIR__ . '/includes/app_header.php';
?>
<div class="app-content">
  <?= ui_page_head(
      $rtl ? 'إتمام شراء الباقة' : 'Complete your package purchase',
      $rtl ? 'راجع تفاصيل الطلب ثم أكمل الدفع عبر البوابة الآمنة.' : 'Review your order, then pay through the secure gateway.'
  ) ?>

  <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>

  <?php if (!$order || !$plan): ?>
    <div class="dash-card" style="text-align:center;padding:40px 24px">
      <div style="font-size:2.2rem;margin-bottom:8px">🧾</div>
      <h3 style="margin:0 0 6px"><?= $rtl ? 'لا يوجد طلب لعرضه' : 'No order to show' ?></h3>
      <p><?= $rtl ? 'اختر باقة من صفحة الباقات للبدء.' : 'Choose a package from the packages page to begin.' ?></p>
      <a href="/packages.php" class="btn btn-primary" style="margin-top:14px"><?= $rtl ? 'عرض الباقات' : 'View packages' ?></a>
    </div>
  <?php elseif ($order['status'] === 'paid'): ?>
    <div class="dash-card" style="text-align:center;padding:40px 24px">
      <div style="font-size:2.2rem;margin-bottom:8px">✅</div>
      <h3 style="margin:0 0 6px"><?= $rtl ? 'تم تفعيل الباقة' : 'Package activated' ?></h3>
      <p><?= ui_e($rtl ? $plan['name_ar'] : $plan['name_en']) ?></p>
      <a href="/subscription.php" class="btn btn-primary" style="margin-top:14px"><?= $rtl ? 'عرض باقتي' : 'View my plan' ?></a>
    </div>
  <?php else: ?>
    <div class="dash-card" style="max-width:560px">
      <h3 style="margin-top:0"><?= ui_e($rtl ? $plan['name_ar'] : $plan['name_en']) ?></h3>
      <table style="width:100%;border-collapse:collapse">
        <tr><td style="padding:8px 0;color:var(--text-muted)"><?= $rtl ? 'الباقة' : 'Package' ?></td><td style="padding:8px 0;font-weight:700"><?= ui_e($rtl ? $plan['name_ar'] : $plan['name_en']) ?></td></tr>
        <tr><td style="padding:8px 0;color:var(--text-muted)"><?= $rtl ? 'دورة الفاتورة' : 'Billing cycle' ?></td><td style="padding:8px 0;font-weight:700"><?= $order['billing_cycle'] === 'yearly' ? ($rtl ? 'سنوي' : 'Yearly') : ($rtl ? 'شهري' : 'Monthly') ?></td></tr>
        <tr><td style="padding:8px 0;color:var(--text-muted)"><?= $rtl ? 'المبلغ' : 'Amount' ?></td><td style="padding:8px 0;font-weight:800;font-size:1.15rem;color:var(--navy-dark)"><?= money((float)$order['amount'], $order['currency']) ?></td></tr>
        <tr><td style="padding:8px 0;color:var(--text-muted)"><?= $rtl ? 'الحالة' : 'Status' ?></td><td style="padding:8px 0"><?= $rtl ? 'بانتظار الدفع' : 'Awaiting payment' ?></td></tr>
      </table>

      <form method="post" action="/checkout.php" style="margin-top:16px">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="pay">
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
        <button class="btn btn-primary btn-block" type="submit" data-loading-text="<?= $rtl ? 'جارٍ التحويل للدفع…' : 'Redirecting to payment…' ?>">
          <?= $rtl ? 'ادفع الآن' : 'Pay now' ?>
        </button>
      </form>
      <p class="muted-sm" style="margin-top:12px;text-align:center">
        <?= $rtl ? 'ستُنقل إلى صفحة دفع آمنة (مدى / فيزا / ماستركارد). تُفعّل الباقة تلقائياً بعد تأكيد الدفع.' : 'You will be redirected to a secure payment page (Mada / Visa / Mastercard). The package activates automatically after payment is confirmed.' ?>
      </p>
      <a href="/packages.php" class="btn btn-outline btn-block" style="margin-top:8px"><?= $rtl ? 'تغيير الباقة' : 'Change package' ?></a>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
