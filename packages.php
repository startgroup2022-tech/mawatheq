<?php
/**
 * Package catalogue (client) — Execution 63.
 *
 * The one place a client buys a package. Before this page, every "choose plan"
 * button led to /register.php (which ignores the plan) or a generic contact form,
 * so a package could not actually be purchased. Each plan now offers the action its
 * classification requires:
 *
 *   free      → activate immediately, no payment
 *   paid      → checkout → N-Genius hosted page → verified activation
 *   corporate → request a quote (a bespoke price is agreed, not charged online)
 *
 * The prices shown are read from `plans`, never hard-coded, so the catalogue and
 * the database cannot disagree.
 */
$activePage = 'subscription';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/package_payment.php';
require_once __DIR__ . '/includes/ai_services.php';

$userId = (int)$_SESSION['user_id'];
$rtl = is_rtl();
$currency = currency_code();

$usage = ai_plan_usage($pdo, $userId);
$currentPlan = $usage['plan'];

$plans = $pdo->query("SELECT * FROM plans WHERE status='active' ORDER BY sort_order, price_monthly")->fetchAll();
$featStmt = $pdo->prepare('SELECT * FROM plan_features WHERE plan_id=? ORDER BY sort_order, id');

// Pending orders the client can still pay for, so an abandoned checkout is recoverable.
$pendingOrders = [];
if (package_payment_schema_ready($pdo)) {
    $po = $pdo->prepare("SELECT po.*, p.name_ar, p.name_en FROM package_orders po
        JOIN plans p ON p.id = po.plan_id
        WHERE po.user_id=? AND po.status='pending' ORDER BY po.id DESC");
    $po->execute([$userId]);
    $pendingOrders = $po->fetchAll();
}

$classLabel = static function (string $c) use ($rtl): string {
    return match ($c) {
        'free' => $rtl ? 'مجانية' : 'Free',
        'corporate' => $rtl ? 'مؤسسية' : 'Corporate',
        default => $rtl ? 'مدفوعة' : 'Paid',
    };
};
?>
<div class="app-content">

  <?= ui_page_head(
      $rtl ? 'الباقات والاشتراك' : 'Packages & subscription',
      $rtl ? 'اختر الباقة المناسبة لك. تُفعّل الباقة تلقائياً بعد تأكيد الدفع من البوابة.' : 'Choose the package that fits you. A package activates automatically once the gateway confirms payment.'
  ) ?>

  <?php if ($currentPlan): ?>
    <div class="dash-card" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
      <div>
        <div style="font-size:.78rem;color:var(--text-muted);font-weight:700"><?= $rtl ? 'باقتك الحالية' : 'Your current plan' ?></div>
        <strong style="font-size:1.1rem;color:var(--navy-dark)"><?= ui_e($rtl ? $currentPlan['name_ar'] : $currentPlan['name_en']) ?></strong>
      </div>
      <a href="/subscription.php" class="btn btn-outline btn-sm"><?= $rtl ? 'الاستخدام والتفاصيل' : 'Usage & details' ?></a>
    </div>
  <?php endif; ?>

  <?php if ($pendingOrders): ?>
    <div class="form-alert warning" style="margin-top:12px">
      <strong><?= $rtl ? 'لديك طلب باقة غير مكتمل' : 'You have an unfinished package order' ?></strong>
      <ul style="margin:8px 0 0;padding-inline-start:18px">
        <?php foreach ($pendingOrders as $o): ?>
          <li>
            <?= ui_e($rtl ? $o['name_ar'] : $o['name_en']) ?> —
            <?= money((float)$o['amount'], $o['currency']) ?>
            <form method="post" action="/checkout.php" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
              <input type="hidden" name="action" value="pay">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <button class="btn btn-primary btn-sm" type="submit"><?= $rtl ? 'إكمال الدفع' : 'Complete payment' ?></button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="pricing-grid" style="margin-top:18px">
    <?php foreach ($plans as $plan):
      $name = $rtl ? $plan['name_ar'] : $plan['name_en'];
      $desc = $rtl ? ($plan['description_ar'] ?? '') : ($plan['description_en'] ?? '');
      $featStmt->execute([$plan['id']]);
      $feats = $featStmt->fetchAll();
      $class = in_array($plan['classification'] ?? '', ['free', 'paid', 'corporate'], true) ? $plan['classification'] : 'paid';
      $cycles = package_plan_cycles($plan);
      $monthly = package_plan_price($plan, 'monthly');
      $yearly = package_plan_price($plan, 'yearly');
      $isCurrent = $currentPlan && (int)$currentPlan['id'] === (int)$plan['id'];
    ?>
    <div class="price-card <?= $plan['is_featured'] ? 'featured' : '' ?>" id="plan-<?= (int)$plan['id'] ?>">
      <?php if ($plan['is_featured']): ?><span class="pop-tag"><?= $rtl ? 'الأكثر اختياراً' : 'Most popular' ?></span><?php endif; ?>
      <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
        <h3 style="margin:0"><?= ui_e($name) ?></h3>
        <span class="badge badge-neutral"><?= $classLabel($class) ?></span>
      </div>
      <?php if ($desc !== ''): ?><p style="font-size:.88rem"><?= ui_e($desc) ?></p><?php endif; ?>

      <div class="price-amount">
        <?php if ($monthly !== null): ?>
          <?= $monthly > 0 ? money($monthly) : ($rtl ? 'مجاناً' : 'Free') ?>
          <?php if ($monthly > 0): ?><span><?= $rtl ? 'شهرياً' : '/month' ?></span><?php endif; ?>
        <?php elseif ($yearly !== null): ?>
          <?= money($yearly) ?><span><?= $rtl ? 'سنوياً' : '/year' ?></span>
        <?php else: ?>
          <?= $rtl ? 'حسب الطلب' : 'On request' ?>
        <?php endif; ?>
      </div>

      <?php if ($yearly !== null && $monthly !== null): ?>
        <p class="muted-sm" style="margin:-6px 0 6px"><?= $rtl ? 'أو' : 'or' ?> <?= money($yearly) ?> <?= $rtl ? 'سنوياً' : '/year' ?></p>
      <?php endif; ?>

      <ul class="price-feats">
        <?php foreach ($feats as $f): ?><li><?= ui_e($rtl ? $f['feature_ar'] : $f['feature_en']) ?></li><?php endforeach; ?>
      </ul>

      <?php if ($class === 'corporate'): ?>
        <a href="/contact.php?topic=plan&amp;plan=<?= (int)$plan['id'] ?>" class="btn btn-outline btn-block"><?= $rtl ? 'اطلب عرض سعر' : 'Request a quote' ?></a>
      <?php elseif ($isCurrent): ?>
        <button class="btn btn-outline btn-block" type="button" disabled><?= $rtl ? 'باقتك الحالية' : 'Your current plan' ?></button>
      <?php elseif ($class === 'free' || ($monthly !== null && $monthly <= 0 && $yearly === null)): ?>
        <form method="post" action="/checkout.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="action" value="checkout">
          <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
          <input type="hidden" name="cycle" value="monthly">
          <button class="btn <?= $plan['is_featured'] ? 'btn-primary' : 'btn-outline' ?> btn-block" type="submit"><?= $rtl ? 'تفعيل مجاناً' : 'Activate free' ?></button>
        </form>
      <?php else: ?>
        <form method="post" action="/checkout.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="action" value="checkout">
          <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
          <?php if (count($cycles) > 1): ?>
            <div class="field" style="margin-bottom:10px">
              <label for="cycle_<?= (int)$plan['id'] ?>" style="font-size:.8rem"><?= $rtl ? 'دورة الفاتورة' : 'Billing cycle' ?></label>
              <select name="cycle" id="cycle_<?= (int)$plan['id'] ?>">
                <?php if (in_array('monthly', $cycles, true)): ?><option value="monthly"><?= $rtl ? 'شهري' : 'Monthly' ?></option><?php endif; ?>
                <?php if (in_array('yearly', $cycles, true)): ?><option value="yearly"><?= $rtl ? 'سنوي' : 'Yearly' ?></option><?php endif; ?>
              </select>
            </div>
          <?php else: ?>
            <input type="hidden" name="cycle" value="<?= ui_e($cycles[0]) ?>">
          <?php endif; ?>
          <button class="btn <?= $plan['is_featured'] ? 'btn-primary' : 'btn-outline' ?> btn-block" type="submit"><?= $rtl ? 'اشترك وادفع' : 'Subscribe & pay' ?></button>
        </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <p class="l2-price-note" style="text-align:center;margin-top:18px">
    <?= $rtl ? 'جميع المبالغ بعملة ' . ui_e($currency) . '. تُفعّل الباقة فقط بعد تأكيد البوابة للدفع.' : 'All amounts are in ' . ui_e($currency) . '. A package activates only after the gateway confirms payment.' ?>
  </p>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
