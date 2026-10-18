<?php
/**
 * Payout request queue (Execution 31).
 *
 * The finance queue: every request, ordered so the ones waiting on a decision come
 * first. Read-only — the transitions happen on the single-request page, where the
 * approver can see the evidence and the fraud flags for the one request they are
 * about to move.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/affiliate.php';
require_once __DIR__ . '/../includes/affiliate_payout.php';

$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$queue = affiliate_payout_queue($pdo, $statusFilter !== '' ? ['status' => $statusFilter] : []);
$summary = affiliate_payout_summary($pdo);
$settled = affiliate_settled_payouts($pdo, 50);

$statusLabels = [
    'requested' => 'بانتظار المراجعة', 'under_review' => 'تحت المراجعة', 'approved' => 'معتمد',
    'processing' => 'قيد التنفيذ', 'paid' => 'مدفوع', 'rejected' => 'مرفوض',
    'failed' => 'فشل', 'cancelled' => 'ملغى',
];

$activeAdmin = 'affiliate-payouts';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar">
    <h2>طلبات الصرف <span class="count-badge"><?= number_format(count($queue)) ?></span></h2>
  </div>

  <div class="grid g-4" style="margin-bottom:18px">
    <div class="stat-card"><span class="label">مطلوب (معلّق)</span><div class="num" style="font-size:20px"><?= htmlspecialchars((string)$summary['requested']) ?></div></div>
    <div class="stat-card"><span class="label">معتمد (غير مدفوع)</span><div class="num" style="font-size:20px"><?= htmlspecialchars((string)$summary['approved']) ?></div></div>
    <div class="stat-card"><span class="label">مدفوع</span><div class="num" style="font-size:20px"><?= htmlspecialchars((string)$summary['paid']) ?></div></div>
    <div class="stat-card"><span class="label">طلبات مفتوحة</span><div class="num"><?= number_format((int)$summary['open_count']) ?></div></div>
    <div class="stat-card"><span class="label">العملة</span><div class="num" style="font-size:20px"><?= htmlspecialchars((string)$summary['currency']) ?></div></div>
  </div>

  <form method="get" class="dash-card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <select name="status">
      <option value="">كل الحالات</option>
      <?php foreach ($statusLabels as $k => $label): ?>
        <option value="<?= htmlspecialchars($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">تصفية</button>
    <a class="btn btn-outline" href="/admin/affiliate-payouts.php">إعادة تعيين</a>
  </form>

  <div class="table-wrap table-cards">
    <table class="admin-table">
      <thead><tr>
        <th>المرجع</th><th>المسوّق</th><th>المبلغ</th><th>الحالة</th>
        <th>اعتماد أول</th><th>علامات</th><th>طُلب</th><th></th>
      </tr></thead>
      <tbody>
        <?php if (!$queue): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">لا توجد طلبات صرف.</td></tr>
        <?php else: foreach ($queue as $r): ?>
          <tr>
            <td data-label="المرجع" style="font-family:monospace"><?= htmlspecialchars((string)$r['reference']) ?></td>
            <td data-label="المسوّق"><?= htmlspecialchars((string)$r['full_name']) ?><br><span style="font-family:monospace;font-size:11px;color:var(--muted)"><?= htmlspecialchars((string)$r['code']) ?></span></td>
            <td data-label="المبلغ" style="font-family:monospace"><?= htmlspecialchars((string)$r['amount']) ?> <?= htmlspecialchars((string)$r['currency']) ?></td>
            <td data-label="الحالة"><span class="status-pill <?= in_array((string)$r['status'], ['paid'], true) ? 'status-confirmed' : (in_array((string)$r['status'], ['rejected','failed','cancelled'], true) ? 'status-cancelled' : 'status-pending') ?>"><?= htmlspecialchars($statusLabels[(string)$r['status']] ?? (string)$r['status']) ?></span></td>
            <td data-label="اعتماد أول"><?= !empty($r['approved_by']) ? '#' . (int)$r['approved_by'] : '—' ?></td>
            <td data-label="علامات"><?= (int)$r['open_flags'] > 0 ? '<span class="status-pill status-cancelled">' . (int)$r['open_flags'] . '</span>' : '—' ?></td>
            <td data-label="طُلب"><?= htmlspecialchars((string)$r['created_at']) ?></td>
            <td><a class="btn btn-outline btn-sm" href="/admin/affiliate-payout.php?id=<?= (int)$r['id'] ?>">مراجعة</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="dash-card">
    <h3>مدفوعات مُسوّاة</h3>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr><th>#</th><th>المسوّق</th><th>المبلغ</th><th>العملة</th><th>المرجع</th><th>نُفّذ</th></tr></thead>
        <tbody>
          <?php if (!$settled): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--muted)">لا توجد مدفوعات مُسوّاة.</td></tr>
          <?php else: foreach ($settled as $p): ?>
            <tr>
              <td><?= (int)$p['id'] ?></td>
              <td><?= htmlspecialchars((string)$p['full_name']) ?></td>
              <td style="font-family:monospace"><?= htmlspecialchars((string)$p['amount']) ?></td>
              <td><?= htmlspecialchars((string)$p['currency']) ?></td>
              <td style="font-family:monospace"><?= htmlspecialchars((string)($p['evidence_reference'] ?? '—')) ?></td>
              <td><?= htmlspecialchars((string)($p['paid_at'] ?? $p['created_at'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
