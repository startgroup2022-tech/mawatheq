<?php
/**
 * Commissions ledger view (Execution 31).
 *
 * Shows the affiliate every commission with its base, rate and current state, and
 * the ledger movements behind the balance. Showing the movements is deliberate: an
 * affiliate who can see why a figure is what it is does not have to ask.
 */
$activeAffiliate = 'earnings';
require_once __DIR__ . '/../includes/affiliate_header.php';
require_once __DIR__ . '/../includes/affiliate_conversion.php';
require_once __DIR__ . '/../includes/affiliate_ledger.php';


$affiliateId = (int)$affiliate['id'];
$currency = (string)$affiliate['payout_currency'];
$summary = affiliate_earnings_summary($pdo, $affiliateId, ['currency' => $currency]);
$filterStatus = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';

$where = ['m.affiliate_id = ?'];
$params = [$affiliateId];
if ($filterStatus !== '' && in_array($filterStatus, affiliate_states(), true)) {
    $where[] = 'm.status = ?';
    $params[] = $filterStatus;
}

$commissions = [];
try {
    $stmt = $pdo->prepare('SELECT m.*, c.service_key, c.converted_at, c.subject_ref
        FROM affiliate_commissions m JOIN affiliate_conversions c ON c.id = m.conversion_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY m.id DESC LIMIT 200');
    $stmt->execute($params);
    $commissions = $stmt->fetchAll();
} catch (Throwable $e) { /* pre-v57 database */ }

$ledger = affiliate_ledger_entries($pdo, $affiliateId, 100);
?>
<div class="app-page">
  <div class="app-page-head">
    <div>
      <h1><?= ui_e(is_rtl() ? 'العمولات' : 'Commissions') ?></h1>
      <p class="muted"><?= ui_e(is_rtl() ? 'كل عمولة سجّلتها إحالاتك، مع الأساس والحالة.' : 'Every commission your referrals have produced, with its base and state.') ?></p>
    </div>
  </div>

  <div class="aff-money-grid">
    <div class="aff-money aff-money-primary">
      <span class="aff-money-label"><?= ui_e(is_rtl() ? 'متاح للصرف' : 'Available to request') ?></span>
      <span class="aff-money-value"><?= ui_e(affiliate_format_money($summary['available'], $currency)) ?></span>
    </div>
    <div class="aff-money">
      <span class="aff-money-label"><?= ui_e(is_rtl() ? 'قيد الانتظار' : 'In hold period') ?></span>
      <span class="aff-money-value"><?= ui_e(affiliate_format_money($summary['holding'], $currency)) ?></span>
    </div>
    <div class="aff-money">
      <span class="aff-money-label"><?= ui_e(is_rtl() ? 'مصروف' : 'Paid out') ?></span>
      <span class="aff-money-value"><?= ui_e(affiliate_format_money($summary['paid_out'], $currency)) ?></span>
    </div>
    <div class="aff-money">
      <span class="aff-money-label"><?= ui_e(is_rtl() ? 'معكوس (مرتجعات)' : 'Reversed (refunds)') ?></span>
      <span class="aff-money-value"><?= ui_e(affiliate_format_money($summary['reversed'], $currency)) ?></span>
    </div>
  </div>

  <section class="app-card">
    <div class="app-card-head">
      <h2><?= ui_e(is_rtl() ? 'سجل العمولات' : 'Commission history') ?></h2>
      <form method="get" class="inline-form">
        <select name="status" onchange="this.form.submit()" aria-label="<?= ui_e(is_rtl() ? 'تصفية بالحالة' : 'Filter by state') ?>">
          <option value=""><?= ui_e(is_rtl() ? 'كل الحالات' : 'All states') ?></option>
          <?php foreach (affiliate_states() as $state): ?>
            <option value="<?= ui_e($state) ?>" <?= $filterStatus === $state ? 'selected' : '' ?>><?= ui_e(affiliate_status_label($state)) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php if (!$commissions): ?>
      <?= ui_empty_state(is_rtl() ? 'لا توجد عمولات' : 'No commissions', is_rtl() ? 'ستظهر العمولات هنا فور تسجيل أول تحويل مؤهل.' : 'Commissions appear here once a qualified conversion is recorded.', 'wallet') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr>
            <th>#</th>
            <th><?= ui_e(is_rtl() ? 'الخدمة' : 'Service') ?></th>
            <th><?= ui_e(is_rtl() ? 'الأساس المؤهل' : 'Eligible base') ?></th>
            <th><?= ui_e(is_rtl() ? 'العمولة' : 'Commission') ?></th>
            <th><?= ui_e(is_rtl() ? 'المعكوس' : 'Reversed') ?></th>
            <th><?= ui_e(is_rtl() ? 'الحالة' : 'State') ?></th>
            <th><?= ui_e(is_rtl() ? 'ينتهي الانتظار' : 'Hold until') ?></th>
          </tr></thead>
          <tbody>
            <?php foreach ($commissions as $row): ?>
              <tr>
                <td class="aff-mono"><?= (int)$row['id'] ?></td>
                <td><?= ui_e(affiliate_service_label(affiliate_service_find($pdo, (string)$row['service_key']), (string)$row['service_key'])) ?></td>
                <td class="aff-mono"><?= ui_e(affiliate_format_money($row['base_amount'], (string)$row['currency'])) ?></td>
                <td class="aff-mono"><strong><?= ui_e(affiliate_format_money($row['commission_amount'], (string)$row['currency'])) ?></strong></td>
                <td class="aff-mono"><?= ui_e(affiliate_format_money($row['reversed_amount'], (string)$row['currency'])) ?></td>
                <td><span class="status-pill <?= ui_e(affiliate_status_class((string)$row['status'])) ?>"><?= ui_e(affiliate_status_label((string)$row['status'])) ?></span></td>
                <td><?= ui_e((string)($row['hold_until'] ?: '—')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'حركات الحساب' : 'Account movements') ?></h2></div>
    <p class="muted"><?= ui_e(is_rtl()
      ? 'سجل كامل لكل حركة على رصيدك. الرصيد المتاح هو ناتج هذه الحركات.'
      : 'A complete record of every movement on your balance. The available figure is the result of these movements.') ?></p>
    <?php if (!$ledger): ?>
      <?= ui_empty_state(is_rtl() ? 'لا توجد حركات' : 'No movements yet', '', 'receipt') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr>
            <th><?= ui_e(is_rtl() ? 'الحساب' : 'Account') ?></th>
            <th><?= ui_e(is_rtl() ? 'الاتجاه' : 'Direction') ?></th>
            <th><?= ui_e(is_rtl() ? 'المبلغ' : 'Amount') ?></th>
            <th><?= ui_e(is_rtl() ? 'الوصف' : 'Description') ?></th>
            <th><?= ui_e(is_rtl() ? 'التاريخ' : 'Date') ?></th>
          </tr></thead>
          <tbody>
            <?php foreach ($ledger as $entry): ?>
              <tr>
                <td><?= ui_e(affiliate_ledger_account_label((string)$entry['account'])) ?></td>
                <td><span class="status-pill <?= $entry['direction'] === 'credit' ? 'status-confirmed' : 'status-pending' ?>"><?= ui_e($entry['direction'] === 'credit' ? (is_rtl() ? 'دائن' : 'Credit') : (is_rtl() ? 'مدين' : 'Debit')) ?></span></td>
                <td class="aff-mono"><?= ui_e(affiliate_format_money($entry['amount'], (string)$entry['currency'])) ?></td>
                <td><?= ui_e((string)($entry['description'] ?: '—')) ?></td>
                <td><?= ui_e(date('Y-m-d', strtotime((string)$entry['created_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
