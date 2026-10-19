<?php
/**
 * Commissions console (Execution 31).
 *
 * Every commission the programme has written, with the state it is in and the plan
 * that produced it. Read-only: a commission moves because a payment landed, a refund
 * arrived or a payout was made, not because an operator clicked a button. The one
 * manual lever — forcing a held commission past its hold date — is deliberately not
 * offered here, because doing it by hand is how a ledger ends up disagreeing with
 * the rule that was supposed to govern it.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/affiliate.php';

$filters = [];
if (is_string($_GET['status'] ?? null) && $_GET['status'] !== '') $filters['status'] = $_GET['status'];
if (is_string($_GET['currency'] ?? null) && $_GET['currency'] !== '') $filters['currency'] = $_GET['currency'];
$limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));

$where = [];
$params = [];
if (!empty($filters['status'])) { $where[] = 'c.status = ?'; $params[] = $filters['status']; }
if (!empty($filters['currency'])) { $where[] = 'c.currency = ?'; $params[] = $filters['currency']; }

$rows = [];
$totals = [];
try {
    $stmt = $pdo->prepare('SELECT c.*, a.code, a.full_name,
            (SELECT COUNT(*) FROM affiliate_fraud_flags f WHERE f.conversion_id = c.conversion_id AND f.status IN (\'open\',\'reviewing\')) AS open_flags
        FROM affiliate_commissions c
        JOIN affiliates a ON a.id = c.affiliate_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY c.id DESC LIMIT ' . $limit);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $stmt = $pdo->query('SELECT status, currency, COUNT(*) n, COALESCE(SUM(commission_amount),0) total FROM affiliate_commissions GROUP BY status, currency ORDER BY status');
    $totals = $stmt->fetchAll();
} catch (Throwable $e) { /* pre-v57 database */ }

$activeAdmin = 'affiliate-commissions';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar">
    <h2>العمولات <span class="count-badge"><?= number_format(count($rows)) ?></span></h2>
  </div>

  <div class="dash-card">
    <h3>الإجماليات حسب الحالة</h3>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr><th>الحالة</th><th>العملة</th><th>العدد</th><th>الإجمالي</th></tr></thead>
        <tbody>
          <?php if (!$totals): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--muted)">لا توجد بيانات.</td></tr>
          <?php else: foreach ($totals as $t): ?>
            <tr>
              <td><span class="status-pill <?= htmlspecialchars(affiliate_status_class((string)$t['status'])) ?>"><?= htmlspecialchars((string)$t['status']) ?></span></td>
              <td><?= htmlspecialchars((string)$t['currency']) ?></td>
              <td><?= number_format((int)$t['n']) ?></td>
              <td style="font-family:monospace"><?= htmlspecialchars((string)$t['total']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <form method="get" class="dash-card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <select name="status">
      <option value="">كل الحالات</option>
      <?php foreach (affiliate_states() as $s): ?>
        <option value="<?= htmlspecialchars($s) ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="currency">
      <option value="">كل العملات</option>
      <?php foreach (['SAR', 'AED', 'KWD', 'QAR', 'OMR', 'BHD', 'USD'] as $cur): ?>
        <option value="<?= htmlspecialchars($cur) ?>" <?= ($filters['currency'] ?? '') === $cur ? 'selected' : '' ?>><?= htmlspecialchars($cur) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">تصفية</button>
    <a class="btn btn-outline" href="/admin/affiliate-commissions.php">إعادة تعيين</a>
  </form>

  <div class="table-wrap table-cards">
    <table class="admin-table">
      <thead><tr>
        <th>#</th><th>المسوّق</th><th>الحالة</th><th>الأساس</th><th>العمولة</th>
        <th>العملة</th><th>الخطة</th><th>ينتهي الحجز</th><th>علامات</th>
      </tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:28px">لا توجد عمولات مطابقة.</td></tr>
        <?php else: foreach ($rows as $c): ?>
          <tr>
            <td data-label="#"><?= (int)$c['id'] ?></td>
            <td data-label="المسوّق"><a href="/admin/affiliate-detail.php?id=<?= (int)$c['affiliate_id'] ?>"><?= htmlspecialchars((string)$c['full_name']) ?></a><br><span style="font-family:monospace;font-size:11px;color:var(--muted)"><?= htmlspecialchars((string)$c['code']) ?></span></td>
            <td data-label="الحالة"><span class="status-pill <?= htmlspecialchars(affiliate_status_class((string)$c['status'])) ?>"><?= htmlspecialchars((string)$c['status']) ?></span></td>
            <td data-label="الأساس" style="font-family:monospace"><?= htmlspecialchars((string)$c['base_amount']) ?></td>
            <td data-label="العمولة" style="font-family:monospace"><?= htmlspecialchars((string)$c['commission_amount']) ?></td>
            <td data-label="العملة"><?= htmlspecialchars((string)$c['currency']) ?></td>
            <td data-label="الخطة">#<?= (int)($c['plan_id'] ?? 0) ?> v<?= htmlspecialchars((string)($c['plan_version'] ?? '')) ?></td>
            <td data-label="ينتهي الحجز"><?= htmlspecialchars((string)($c['hold_until'] ?? '—')) ?></td>
            <td data-label="علامات"><?= (int)$c['open_flags'] > 0 ? '<span class="status-pill status-cancelled">' . (int)$c['open_flags'] . '</span>' : '—' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
