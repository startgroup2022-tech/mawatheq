<?php
/**
 * Fraud review console (Execution 31).
 *
 * The flags the programme raised on its own — duplicate accounts, self-referral,
 * payout-detail changes, refund-after-payout — with the affiliate's risk scan beside
 * them. A reviewer moves a flag to cleared or confirmed; neither verdict moves money
 * on its own, which is why the action is safe to offer without a second approver.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/affiliate.php';
require_once __DIR__ . '/../includes/affiliate_fraud.php';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $flagId = (int)($_POST['flag_id'] ?? 0);
    $note = is_string($_POST['note'] ?? null) ? trim($_POST['note']) : '';

    if ($action === 'review' && $flagId > 0) {
        $to = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        if (affiliate_flag_review($pdo, $flagId, $to, $note !== '' ? $note : null)) {
            log_action($pdo, 'مراجعة علامة احتيال #' . $flagId . ' → ' . $to, 'affiliate_fraud', $flagId);
            $success = 'تم تحديث حالة العلامة.';
        } else {
            $error = 'تعذّر تحديث العلامة.';
        }
    } elseif ($action === 'scan') {
        $affiliateId = (int)($_POST['affiliate_id'] ?? 0);
        $result = affiliate_scan_signals($pdo, $affiliateId);
        $created = 0;
        foreach (($result['signals'] ?? []) as $signal) {
            if (affiliate_flag($pdo, [
                'affiliate_id' => $affiliateId,
                'flag_type' => (string)($signal['type'] ?? 'signal'),
                'severity' => (string)($signal['severity'] ?? 'medium'),
                'description' => (string)($signal['detail'] ?? ''),
                'signal_data' => json_encode($signal, JSON_UNESCAPED_UNICODE),
            ])) $created++;
        }
        log_action($pdo, 'فحص إشارات المسوّق #' . $affiliateId . ' — ' . $created . ' علامة', 'affiliate_fraud', $affiliateId);
        $success = $created > 0 ? ('تم إنشاء ' . $created . ' علامة من الفحص.') : 'لم تُرصد إشارات جديدة.';
    }
}

$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'open';
$flags = affiliate_flags($pdo, $statusFilter !== '' ? ['status' => $statusFilter] : []);
$counts = affiliate_flag_counts($pdo);
$summary = affiliate_fraud_summary($pdo);

$flagLabels = [
    'duplicate_account' => 'حساب مكرر', 'self_referral' => 'إحالة ذاتية', 'payout_change' => 'تغيير بيانات الصرف',
    'refund_after_payout' => 'استرداد بعد الصرف', 'velocity' => 'وتيرة غير طبيعية', 'shared_device' => 'جهاز مشترك',
    'manual' => 'يدوي',
];

$activeAdmin = 'affiliate-fraud';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar">
    <h2>مراجعة الاحتيال <span class="count-badge"><?= number_format(count($flags)) ?></span></h2>
  </div>

  <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <div class="grid g-4" style="margin-bottom:18px">
    <div class="stat-card"><span class="label">مفتوحة</span><div class="num"><?= number_format((int)$summary['open']) ?></div></div>
    <div class="stat-card"><span class="label">تحت المراجعة</span><div class="num"><?= number_format((int)($counts['reviewing'] ?? 0)) ?></div></div>
    <div class="stat-card"><span class="label">مؤكدة</span><div class="num"><?= number_format((int)$summary['confirmed']) ?></div></div>
    <div class="stat-card"><span class="label">مُستبعدة</span><div class="num"><?= number_format((int)$summary['cleared']) ?></div></div>
    <div class="stat-card"><span class="label">مسوّقون مُعلَّمون</span><div class="num"><?= number_format((int)$summary['affiliates_flagged']) ?></div></div>
  </div>

  <form method="get" class="dash-card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <select name="status">
      <option value="">كل الحالات</option>
      <?php foreach (['open' => 'مفتوحة', 'reviewing' => 'تحت المراجعة', 'confirmed' => 'مؤكدة', 'cleared' => 'مُستبعدة', 'dismissed' => 'مُستبعدة نهائياً'] as $k => $label): ?>
        <option value="<?= htmlspecialchars($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">تصفية</button>
    <a class="btn btn-outline" href="/admin/affiliate-fraud.php">إعادة تعيين</a>
  </form>

  <div class="table-wrap table-cards">
    <table class="admin-table">
      <thead><tr>
        <th>#</th><th>المسوّق</th><th>النوع</th><th>الخطورة</th><th>الحالة</th><th>الوصف</th><th>أُنشئت</th><th>إجراء</th>
      </tr></thead>
      <tbody>
        <?php if (!$flags): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">لا توجد علامات مطابقة.</td></tr>
        <?php else: foreach ($flags as $f): ?>
          <tr>
            <td data-label="#"><?= (int)$f['id'] ?></td>
            <td data-label="المسوّق"><a href="/admin/affiliate-detail.php?id=<?= (int)$f['affiliate_id'] ?>">#<?= (int)$f['affiliate_id'] ?></a></td>
            <td data-label="النوع"><?= htmlspecialchars($flagLabels[(string)$f['flag_type']] ?? (string)$f['flag_type']) ?></td>
            <td data-label="الخطورة"><span class="status-pill <?= (string)$f['severity'] === 'high' ? 'status-cancelled' : 'status-pending' ?>"><?= htmlspecialchars((string)$f['severity']) ?></span></td>
            <td data-label="الحالة"><?= htmlspecialchars((string)$f['status']) ?></td>
            <td data-label="الوصف"><?= htmlspecialchars((string)($f['description'] ?? '')) ?></td>
            <td data-label="أُنشئت"><?= htmlspecialchars((string)$f['created_at']) ?></td>
            <td data-label="إجراء">
              <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="flag_id" value="<?= (int)$f['id'] ?>">
                <input type="hidden" name="action" value="review">
                <select name="status">
                  <?php foreach (['reviewing' => 'تحت المراجعة', 'cleared' => 'مُستبعدة', 'confirmed' => 'مؤكدة', 'dismissed' => 'مُستبعدة نهائياً'] as $k => $label): ?>
                    <option value="<?= htmlspecialchars($k) ?>" <?= (string)$f['status'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-outline btn-sm" type="submit">حفظ</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="dash-card">
    <h3>فحص إشارات مسوّق</h3>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="scan">
      <input type="number" name="affiliate_id" placeholder="معرّف المسوّق" min="1" required>
      <button class="btn btn-primary" type="submit">تشغيل الفحص</button>
      <span style="font-size:12.5px;color:var(--muted)">يبحث في الحسابات المكررة والإحالة الذاتية وتغييرات بيانات الصرف.</span>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
