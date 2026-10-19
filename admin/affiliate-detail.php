<?php
/**
 * Affiliate detail (Execution 31).
 *
 * One affiliate's whole record: profile, agreements, links, conversions, commissions,
 * payouts, fraud flags and the ledger balance. The actions on this page are the
 * lifecycle decisions an operator makes by hand — approve, activate, suspend, close,
 * and the compliance outcome — each routed through the service layer.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/affiliate.php';
require_once __DIR__ . '/../includes/affiliate_auth.php';
require_once __DIR__ . '/../includes/affiliate_fraud.php';
require_once __DIR__ . '/../includes/affiliate_admin.php';
require_once __DIR__ . '/../includes/affiliate_payout.php';
require_once __DIR__ . '/../includes/affiliate_conversion.php';
require_once __DIR__ . '/../includes/affiliate_ledger.php';

$error = null;
$success = null;
$affiliateId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $affiliateId = (int)($_POST['affiliate_id'] ?? 0);
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $note = is_string($_POST['note'] ?? null) ? trim($_POST['note']) : '';

    if ($affiliateId <= 0) {
        $error = 'معرّف المسوّق غير صالح.';
    } elseif ($action === 'status') {
        $to = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        $result = affiliate_set_status($pdo, $affiliateId, $to, ['actor_id' => (int)$_SESSION['admin_id'], 'note' => $note]);
        if (!empty($result['ok'])) {
            log_action($pdo, 'تغيير حالة مسوّق #' . $affiliateId . ' إلى ' . $to, 'affiliate', $affiliateId);
            $success = 'تم تحديث حالة المسوّق.';
        } else {
            $error = match ((string)($result['error'] ?? '')) {
                'illegal_transition' => 'لا يمكن الانتقال إلى هذه الحالة من الحالة الحالية.',
                'reason_required' => 'يجب كتابة سبب للإيقاف أو الرفض.',
                default => 'تعذّر تحديث الحالة.',
            };
        }
    } elseif ($action === 'compliance') {
        $to = is_string($_POST['compliance_status'] ?? null) ? $_POST['compliance_status'] : '';
        $result = affiliate_set_compliance($pdo, $affiliateId, $to, ['actor_id' => (int)$_SESSION['admin_id'], 'note' => $note]);
        if (!empty($result['ok'])) {
            log_action($pdo, 'تحديث حالة الالتزام لمسوّق #' . $affiliateId . ' إلى ' . $to, 'affiliate', $affiliateId);
            $success = 'تم تحديث حالة الالتزام.';
        } else {
            $error = 'تعذّر تحديث حالة الالتزام.';
        }
    } elseif ($action === 'plan') {
        $planId = (int)($_POST['commission_plan_id'] ?? 0);
        try {
            $pdo->prepare('UPDATE affiliates SET commission_plan_id=? WHERE id=?')->execute([$planId ?: null, $affiliateId]);
            affiliate_audit($pdo, 'affiliate_plan_changed', [
                'affiliate_id' => $affiliateId, 'actor_type' => 'admin', 'actor_id' => (int)$_SESSION['admin_id'],
                'subject_type' => 'affiliate', 'subject_id' => $affiliateId, 'detail' => 'plan_id=' . $planId,
            ]);
            log_action($pdo, 'تعيين خطة عمولة للمسوّق #' . $affiliateId, 'affiliate', $affiliateId);
            $success = 'تم تحديث خطة العمولة.';
        } catch (Throwable $e) {
            $error = 'تعذّر تحديث خطة العمولة.';
        }
    }
}

$data = affiliate_admin_detail($pdo, $affiliateId);
$affiliate = $data['affiliate'];
if (!$affiliate) {
    http_response_code(404);
    $activeAdmin = 'affiliates';
    require_once __DIR__ . '/includes/admin_header.php';
    echo '<div class="app-content"><div class="form-alert error">المسوّق غير موجود.</div></div>';
    require_once __DIR__ . '/includes/admin_footer.php';
    exit;
}

$currency = (string)($affiliate['payout_currency'] ?: CURRENCY_DEFAULT);
$payableMinor = affiliate_payable_minor($pdo, $affiliateId, $currency);
$earnings = affiliate_earnings_summary($pdo, $affiliateId, ['currency' => $currency]);
$ledger = affiliate_ledger_verify($pdo, $affiliateId);
$scan = affiliate_scan_signals($pdo, $affiliateId);

$plans = [];
try { $plans = $pdo->query('SELECT id,name,currency,percentage,plan_type,is_active FROM affiliate_commission_plans ORDER BY is_active DESC, id DESC')->fetchAll(); } catch (Throwable $e) {}

$complianceLabels = [
    'not_reviewed' => 'لم تُراجع', 'in_review' => 'تحت المراجعة', 'approved' => 'معتمدة',
    'rejected' => 'مرفوضة', 'needs_action' => 'تحتاج إجراء',
];

$activeAdmin = 'affiliates';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar">
    <h2><?= htmlspecialchars((string)$affiliate['full_name']) ?>
      <span class="status-pill <?= htmlspecialchars(affiliate_status_class((string)$affiliate['status'])) ?>"><?= htmlspecialchars(affiliate_status_label((string)$affiliate['status'])) ?></span>
    </h2>
    <a class="btn btn-outline" href="/admin/affiliates.php">رجوع إلى القائمة</a>
  </div>

  <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <div class="grid g-4" style="margin-bottom:18px">
    <div class="stat-card"><span class="label">الرصيد المتاح</span><div class="num"><?= htmlspecialchars(affiliate_format_money(affiliate_from_minor($payableMinor, $currency), $currency)) ?></div></div>
    <div class="stat-card"><span class="label">إجمالي العمولات المعتمدة</span><div class="num"><?= htmlspecialchars(affiliate_format_money($earnings['approved'] ?? '0', $currency)) ?></div></div>
    <div class="stat-card"><span class="label">مدفوع</span><div class="num"><?= htmlspecialchars(affiliate_format_money($earnings['paid_out'] ?? '0', $currency)) ?></div></div>
    <div class="stat-card"><span class="label">التحويلات</span><div class="num"><?= number_format((int)($earnings['conversions'] ?? 0)) ?></div></div>
    <div class="stat-card"><span class="label">سلامة الدفتر</span><div class="num" style="font-size:16px"><?= !empty($ledger['ok']) ? 'متوازن' : 'اختلال' ?></div></div>
  </div>

  <div class="grid g-2">
    <div class="dash-card">
      <h3>الملف</h3>
      <table class="admin-table">
        <tbody>
          <tr><th>الكود</th><td style="font-family:monospace"><?= htmlspecialchars((string)$affiliate['code']) ?></td></tr>
          <tr><th>البريد</th><td style="font-family:monospace"><?= htmlspecialchars((string)$affiliate['email']) ?></td></tr>
          <tr><th>النوع</th><td><?= htmlspecialchars((string)$affiliate['affiliate_type']) ?></td></tr>
          <tr><th>الدولة</th><td><?= htmlspecialchars((string)($affiliate['country'] ?? '—')) ?></td></tr>
          <tr><th>الجوال</th><td><?= htmlspecialchars((string)($affiliate['phone'] ?? '—')) ?></td></tr>
          <tr><th>العملة</th><td><?= htmlspecialchars($currency) ?></td></tr>
          <tr><th>طريقة الصرف</th><td><?= htmlspecialchars((string)($affiliate['payout_method'] ?? '—')) ?></td></tr>
          <tr><th>الالتزام</th><td><span class="status-pill <?= (string)$affiliate['compliance_status'] === 'approved' ? 'status-confirmed' : 'status-pending' ?>"><?= htmlspecialchars($complianceLabels[(string)$affiliate['compliance_status']] ?? (string)$affiliate['compliance_status']) ?></span></td></tr>
          <tr><th>أنشئ</th><td><?= htmlspecialchars((string)$affiliate['created_at']) ?></td></tr>
          <tr><th>آخر دخول</th><td><?= htmlspecialchars((string)($affiliate['last_login_at'] ?? '—')) ?></td></tr>
        </tbody>
      </table>
    </div>

    <div class="dash-card">
      <h3>إجراءات الحالة</h3>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="affiliate_id" value="<?= (int)$affiliateId ?>">
        <input type="hidden" name="action" value="status">
        <select name="status">
          <?php foreach (affiliate_statuses() as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars(affiliate_status_label($s)) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="note" placeholder="سبب (مطلوب للإيقاف أو الرفض)" style="flex:1 1 200px">
        <button class="btn btn-primary" type="submit">تغيير الحالة</button>
      </form>

      <h3 style="margin-top:18px">الالتزام</h3>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="affiliate_id" value="<?= (int)$affiliateId ?>">
        <input type="hidden" name="action" value="compliance">
        <select name="compliance_status">
          <?php foreach ($complianceLabels as $k => $label): ?>
            <option value="<?= htmlspecialchars($k) ?>" <?= (string)$affiliate['compliance_status'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit">حفظ</button>
      </form>

      <h3 style="margin-top:18px">خطة العمولة</h3>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="affiliate_id" value="<?= (int)$affiliateId ?>">
        <input type="hidden" name="action" value="plan">
        <select name="commission_plan_id">
          <option value="0">— بدون خطة —</option>
          <?php foreach ($plans as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= (int)$affiliate['commission_plan_id'] === (int)$p['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)$p['name']) ?> (<?= htmlspecialchars((string)$p['percentage']) ?>% <?= htmlspecialchars((string)$p['currency']) ?><?= !$p['is_active'] ? ' — معطّلة' : '' ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit">تعيين</button>
      </form>

      <?php if (!empty($scan['signals'])): ?>
        <h3 style="margin-top:18px">إشارات المراجعة</h3>
        <ul style="margin:0;padding-inline-start:18px;font-size:13px">
          <?php foreach ($scan['signals'] as $signal): ?>
            <li><?= htmlspecialchars((string)($signal['detail'] ?? $signal['type'] ?? '')) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <div class="dash-card">
    <h3>الاتفاقيات</h3>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr><th>النوع</th><th>الإصدار</th><th>قُبلت</th><th>البصمة</th></tr></thead>
        <tbody>
          <?php if (!$data['agreements']): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--muted)">لا توجد اتفاقيات مقبولة.</td></tr>
          <?php else: foreach ($data['agreements'] as $a): ?>
            <tr>
              <td><?= htmlspecialchars((string)$a['agreement_type']) ?></td>
              <td><?= htmlspecialchars((string)$a['version']) ?></td>
              <td><?= htmlspecialchars((string)($a['accepted_at'] ?? '—')) ?></td>
              <td style="font-family:monospace;font-size:11px"><?= htmlspecialchars(substr((string)$a['document_hash'], 0, 16)) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="dash-card">
    <h3>العمولات</h3>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr><th>#</th><th>الحالة</th><th>الأساس</th><th>العمولة</th><th>العملة</th><th>الخطة</th><th>الاستحقاق</th></tr></thead>
        <tbody>
          <?php if (!$data['commissions']): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--muted)">لا توجد عمولات.</td></tr>
          <?php else: foreach ($data['commissions'] as $c): ?>
            <tr>
              <td><?= (int)$c['id'] ?></td>
              <td><span class="status-pill <?= htmlspecialchars(affiliate_status_class((string)$c['status'])) ?>"><?= htmlspecialchars((string)$c['status']) ?></span></td>
              <td style="font-family:monospace"><?= htmlspecialchars((string)$c['base_amount']) ?></td>
              <td style="font-family:monospace"><?= htmlspecialchars((string)$c['commission_amount']) ?></td>
              <td><?= htmlspecialchars((string)$c['currency']) ?></td>
              <td>#<?= (int)($c['plan_id'] ?? 0) ?> v<?= htmlspecialchars((string)($c['plan_version'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string)($c['hold_until'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="dash-card">
    <h3>طلبات الصرف</h3>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr><th>المرجع</th><th>الحالة</th><th>المبلغ</th><th>العملة</th><th>طُلب</th><th>مدفوع</th><th></th></tr></thead>
        <tbody>
          <?php if (!$data['payouts']): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--muted)">لا توجد طلبات صرف.</td></tr>
          <?php else: foreach ($data['payouts'] as $p): ?>
            <tr>
              <td style="font-family:monospace"><?= htmlspecialchars((string)$p['reference']) ?></td>
              <td><span class="status-pill status-pending"><?= htmlspecialchars((string)$p['status']) ?></span></td>
              <td style="font-family:monospace"><?= htmlspecialchars((string)$p['amount']) ?></td>
              <td><?= htmlspecialchars((string)$p['currency']) ?></td>
              <td><?= htmlspecialchars((string)$p['created_at']) ?></td>
              <td><?= htmlspecialchars((string)($p['paid_at'] ?? '—')) ?></td>
              <td><a class="btn btn-outline btn-sm" href="/admin/affiliate-payout.php?id=<?= (int)$p['id'] ?>">مراجعة</a></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="dash-card">
    <h3>علامات الاحتيال</h3>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr><th>#</th><th>النوع</th><th>الخطورة</th><th>الحالة</th><th>الوصف</th><th>أُنشئت</th></tr></thead>
        <tbody>
          <?php if (!$data['flags']): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--muted)">لا توجد علامات.</td></tr>
          <?php else: foreach ($data['flags'] as $f): ?>
            <tr>
              <td><?= (int)$f['id'] ?></td>
              <td><?= htmlspecialchars((string)$f['flag_type']) ?></td>
              <td><span class="status-pill <?= (string)$f['severity'] === 'high' ? 'status-cancelled' : 'status-pending' ?>"><?= htmlspecialchars((string)$f['severity']) ?></span></td>
              <td><?= htmlspecialchars((string)$f['status']) ?></td>
              <td><?= htmlspecialchars((string)($f['description'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string)$f['created_at']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
