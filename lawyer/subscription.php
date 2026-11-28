<?php
/**
 * Lawyer subscription — the plan that governs this lawyer or firm, what it includes,
 * and how much of each limit is used.
 *
 * Every number comes from includes/plan_features.php: plan_for_firm() decides the plan,
 * plan_usage() measures usage against the same catalog plan_check() enforces with. This
 * page therefore cannot advertise a ceiling the server does not enforce.
 *
 * Read-only: changing a plan is a purchase, and that lives in checkout.php.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/plan_features.php';
require_once __DIR__ . '/../includes/currency.php';

$rtl = is_rtl();
$lawyerId = (int)$_SESSION['lawyer_id'];
$ctx = tenant_current($pdo);
$firmId = (int)($ctx['firm_id'] ?? 0);

$plan = plan_for_firm($pdo, $firmId, $lawyerId);
$scope = ['firm_id' => $firmId, 'lawyer_id' => $lawyerId];
$usage = plan_usage($pdo, $plan, $scope);

$activeSub = null;
try {
    if ($firmId > 0) {
        $stmt = $pdo->prepare(
            "SELECT s.*, p.name_ar, p.name_en, p.price_monthly, p.price_yearly
               FROM subscriptions s JOIN plans p ON p.id = s.plan_id
              WHERE s.user_id = ? AND s.status = 'active' AND (s.ends_at IS NULL OR s.ends_at >= CURDATE())
              ORDER BY s.id DESC LIMIT 1"
        );
        $stmt->execute([$lawyerId]);
        $activeSub = $stmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    // Subscriptions table absent on an unmigrated install; the plan page still renders.
    $activeSub = null;
}

$features = [];
if ($plan) {
    try {
        $stmt = $pdo->prepare('SELECT feature_ar, feature_en FROM plan_features WHERE plan_id = ? ORDER BY sort_order, id');
        $stmt->execute([(int)$plan['id']]);
        $features = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $features = [];
    }
}

$pageTitle = $rtl ? 'الاشتراك والباقة' : 'Subscription & plan';
require_once __DIR__ . '/includes/lawyer_header.php';
?>
<div class="ws-content">
  <div class="ws-group">
    <h2><?= $rtl ? 'الباقة الحالية' : 'Current plan' ?></h2>
    <?php if (!$plan): ?>
      <div class="ws-empty-inline"><?= $rtl
          ? 'لا توجد باقة مرتبطة بحسابك. اختر باقة من صفحة الأسعار لتفعيل حدود الاستخدام.'
          : 'No plan is attached to your account. Choose one from the pricing page to activate limits.' ?></div>
      <p><a class="btn btn-primary" href="/pricing.php"><?= $rtl ? 'عرض الباقات' : 'View plans' ?></a></p>
    <?php else: ?>
      <p style="font-size:1.15rem;font-weight:700"><?= ui_e($rtl ? $plan['name_ar'] : $plan['name_en']) ?></p>
      <p style="color:var(--text-muted)">
        <?= $rtl ? 'السعر الشهري' : 'Monthly' ?>: <?= money((float)$plan['price_monthly']) ?>
        <?php if (!empty($plan['price_yearly'])): ?>
          · <?= $rtl ? 'السنوي' : 'Yearly' ?>: <?= money((float)$plan['price_yearly']) ?>
        <?php endif; ?>
      </p>
      <?php if ($activeSub): ?>
        <p style="color:var(--text-muted)">
          <?= $rtl ? 'دورة الفوترة' : 'Billing cycle' ?>: <?= ui_e($activeSub['billing_cycle']) ?>
          · <?= $rtl ? 'ينتهي' : 'Ends' ?>: <?= ui_e((string)$activeSub['ends_at']) ?>
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($plan): ?>
  <div class="ws-group">
    <h2><?= $rtl ? 'حدود الاستخدام' : 'Usage limits' ?></h2>
    <div class="table-wrap table-cards">
      <table class="admin-table">
        <thead><tr>
          <th><?= $rtl ? 'البند' : 'Item' ?></th>
          <th><?= $rtl ? 'الحد' : 'Limit' ?></th>
          <th><?= $rtl ? 'المستخدم' : 'Used' ?></th>
          <th><?= $rtl ? 'المتبقي' : 'Remaining' ?></th>
        </tr></thead>
        <tbody>
        <?php foreach (plan_limit_catalog() as $key => $meta):
            $u = $usage[$key] ?? null;
            if (!$u) continue;
            $limit = $u['limit'];
        ?>
          <tr>
            <td data-label="<?= $rtl ? 'البند' : 'Item' ?>"><?= ui_e($rtl ? $meta['label_ar'] : $meta['label_en']) ?></td>
            <td data-label="<?= $rtl ? 'الحد' : 'Limit' ?>">
              <?= $limit === null ? ($rtl ? 'غير محدود' : 'Unlimited') : (int)$limit ?>
            </td>
            <td data-label="<?= $rtl ? 'المستخدم' : 'Used' ?>">
              <?= $u['used'] === null ? '—' : (int)$u['used'] ?>
            </td>
            <td data-label="<?= $rtl ? 'المتبقي' : 'Remaining' ?>">
              <?php if ($u['remaining'] === null): ?>—<?php else: ?>
                <span class="status-pill status-<?= $u['exhausted'] ? 'pending' : 'confirmed' ?>"><?= (int)$u['remaining'] ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($features): ?>
  <div class="ws-group">
    <h2><?= $rtl ? 'ما تشمله الباقة' : 'What the plan includes' ?></h2>
    <ul style="line-height:1.9;padding-inline-start:20px">
      <?php foreach ($features as $f): ?>
      <li><?= ui_e($rtl ? $f['feature_ar'] : $f['feature_en']) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
