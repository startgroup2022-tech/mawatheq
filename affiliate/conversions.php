<?php
/**
 * Conversions (Execution 31).
 *
 * What an affiliate referred, when, and whether it qualified. Customer identity is
 * shown as a pseudonymous reference only — an affiliate is told that two conversions
 * are the same customer without being told who that customer is.
 */
$activeAffiliate = 'conversions';
require_once __DIR__ . '/../includes/affiliate_header.php';
require_once __DIR__ . '/../includes/affiliate_conversion.php';


$affiliateId = (int)$affiliate['id'];
$currency = (string)$affiliate['payout_currency'];

$filters = ['limit' => 200];
if (is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['tracked', 'qualified', 'holding', 'approved', 'payable', 'paid_out', 'reversed', 'refunded'], true)) {
    $filters['status'] = $_GET['status'];
}
if (is_string($_GET['service'] ?? null) && preg_match('/^[a-z0-9_]{1,60}$/', $_GET['service'])) {
    $filters['service_key'] = $_GET['service'];
}

$conversions = affiliate_conversions($pdo, $affiliateId, $filters);
$ineligibleLabels = [
    'regulated_professional_fee' => is_rtl() ? 'أتعاب مهنية منظّمة' : 'Regulated professional fee',
    'service_not_commissionable' => is_rtl() ? 'خدمة غير مؤهلة' : 'Service not commissionable',
    'no_commissionable_revenue' => is_rtl() ? 'لا يوجد إيراد مؤهل' : 'No commissionable revenue',
    'below_minimum' => is_rtl() ? 'أقل من الحد الأدنى' : 'Below minimum',
    'outside_window' => is_rtl() ? 'خارج نافذة الإحالة' : 'Outside the referral window',
    'first_payment_only' => is_rtl() ? 'أول دفعة فقط' : 'First payment only',
    'affiliate_not_active' => is_rtl() ? 'الحساب غير نشط' : 'Account not active',
    'no_plan' => is_rtl() ? 'لا توجد خطة عمولة' : 'No commission plan',
    'no_eligible_amount' => is_rtl() ? 'لا يوجد مبلغ مؤهل' : 'No eligible amount',
];
?>
<div class="app-page">
  <div class="app-page-head">
    <div>
      <h1><?= ui_e(is_rtl() ? 'التحويلات' : 'Conversions') ?></h1>
      <p class="muted"><?= ui_e(is_rtl() ? 'كل إحالة أدّت إلى تسجيل أو دفعة، مع سبب الأهلية.' : 'Every referral that produced a registration or payment, with the reason it did or did not qualify.') ?></p>
    </div>
  </div>

  <section class="app-card">
    <div class="app-card-head">
      <h2><?= ui_e(is_rtl() ? 'السجل' : 'History') ?></h2>
      <form method="get" class="inline-form aff-filters">
        <select name="status" onchange="this.form.submit()">
          <option value=""><?= ui_e(is_rtl() ? 'كل الحالات' : 'All states') ?></option>
          <?php foreach (['tracked' => is_rtl() ? 'مسجّل' : 'Tracked', 'qualified' => is_rtl() ? 'مؤهل' : 'Qualified', 'holding' => is_rtl() ? 'قيد الانتظار' : 'Holding', 'approved' => is_rtl() ? 'معتمد' : 'Approved', 'payable' => is_rtl() ? 'مستحق' : 'Payable', 'paid_out' => is_rtl() ? 'مصروف' : 'Paid out', 'refunded' => is_rtl() ? 'مرتجع' : 'Refunded'] as $k => $label): ?>
            <option value="<?= ui_e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= ui_e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php if (!$conversions): ?>
      <?= ui_empty_state(is_rtl() ? 'لا توجد تحويلات' : 'No conversions yet', is_rtl() ? 'ستظهر الإحالات هنا عند تسجيل عميل أو دفع فاتورة.' : 'Referrals appear here when a customer registers or pays.', 'paid') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr>
            <th><?= ui_e(is_rtl() ? 'الخدمة' : 'Service') ?></th>
            <th><?= ui_e(is_rtl() ? 'العميل' : 'Customer') ?></th>
            <th><?= ui_e(is_rtl() ? 'الإجمالي' : 'Gross') ?></th>
            <th><?= ui_e(is_rtl() ? 'الضريبة' : 'Tax') ?></th>
            <th><?= ui_e(is_rtl() ? 'المرتجع' : 'Refunded') ?></th>
            <th><?= ui_e(is_rtl() ? 'المؤهل' : 'Eligible') ?></th>
            <th><?= ui_e(is_rtl() ? 'العمولة' : 'Commission') ?></th>
            <th><?= ui_e(is_rtl() ? 'الحالة' : 'State') ?></th>
            <th><?= ui_e(is_rtl() ? 'التاريخ' : 'Date') ?></th>
          </tr></thead>
          <tbody>
            <?php foreach ($conversions as $row): ?>
              <tr>
                <td><?= ui_e(affiliate_service_label(affiliate_service_find($pdo, (string)$row['service_key']), (string)$row['service_key'])) ?></td>
                <td class="aff-mono" title="<?= ui_e(is_rtl() ? 'مرجع مجهول الهوية للعميل' : 'Pseudonymous customer reference') ?>"><?= ui_e(substr((string)$row['subject_ref'], 0, 10)) ?>…</td>
                <td class="aff-mono"><?= ui_e(affiliate_format_money($row['gross_amount'], (string)$row['currency'])) ?></td>
                <td class="aff-mono"><?= ui_e(affiliate_format_money($row['tax_amount'], (string)$row['currency'])) ?></td>
                <td class="aff-mono"><?= ui_e(affiliate_format_money($row['refunded_amount'], (string)$row['currency'])) ?></td>
                <td class="aff-mono"><?= ui_e(affiliate_format_money($row['eligible_amount'], (string)$row['currency'])) ?></td>
                <td class="aff-mono"><?= ui_e($row['commission_amount'] !== null ? affiliate_format_money($row['commission_amount'], (string)$row['currency']) : '—') ?></td>
                <td>
                  <span class="status-pill <?= ui_e(affiliate_status_class((string)$row['status'])) ?>"><?= ui_e(affiliate_status_label((string)$row['status'])) ?></span>
                  <?php if ((int)$row['is_eligible'] === 0 && !empty($row['ineligible_reason'])): ?>
                    <small class="muted aff-reason"><?= ui_e($ineligibleLabels[(string)$row['ineligible_reason']] ?? (string)$row['ineligible_reason']) ?></small>
                  <?php endif; ?>
                </td>
                <td><?= ui_e(date('Y-m-d', strtotime((string)$row['converted_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
