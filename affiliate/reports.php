<?php
/**
 * Reports (Execution 31).
 *
 * A date-ranged performance report, with CSV export. The export writes through the
 * same query as the screen, so a downloaded report and the page it came from cannot
 * disagree.
 *
 * CSV injection is a real hazard here: a customer-controlled value such as a UTM
 * campaign name that begins with `=`, `+`, `-` or `@` is executed as a formula when
 * the file is opened in Excel or Sheets. Every text cell is therefore prefixed with
 * a tab when it starts with one of those characters, which neutralises the formula
 * while leaving the value readable.
 */
// The shell is included after the export branch, not before it: the header writes
// HTML, and HTML written before a CSV payload corrupts the download. Only the
// dependency includes run first here; affiliate_header.php still performs the
// session guard for the rendered page.
$activeAffiliate = 'reports';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/affiliate.php';
require_once __DIR__ . '/../includes/affiliate_conversion.php';

$affiliate = affiliate_current($pdo);
if (!$affiliate || !affiliate_can_login($affiliate)) {
    header('Location: /affiliate/login.php');
    exit;
}

$affiliateId = (int)$affiliate['id'];
$currency = (string)$affiliate['payout_currency'];

$from = is_string($_GET['from'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to = is_string($_GET['to'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']) ? $_GET['to'] : date('Y-m-d');

$rows = affiliate_report_range($pdo, $from, $to, ['affiliate_id' => $affiliateId]);

// Daily series for the table and the totals, built from the same rows so the totals
// are provably the sum of what is displayed.
$byDay = [];
$totals = ['gross' => 0, 'eligible' => 0, 'commission' => 0, 'conversions' => 0, 'qualified' => 0];
foreach ($rows as $row) {
    $day = date('Y-m-d', strtotime((string)$row['converted_at']));
    $byDay[$day] ??= ['gross' => 0, 'eligible' => 0, 'commission' => 0, 'conversions' => 0, 'qualified' => 0];
    $gross = affiliate_to_minor($row['gross_amount'], (string)$row['currency']);
    $eligible = affiliate_to_minor($row['eligible_amount'], (string)$row['currency']);
    $commission = $row['commission_amount'] !== null ? affiliate_to_minor($row['commission_amount'], (string)$row['currency']) : 0;
    $byDay[$day]['gross'] += $gross;
    $byDay[$day]['eligible'] += $eligible;
    $byDay[$day]['commission'] += $commission;
    $byDay[$day]['conversions']++;
    if ((int)$row['is_eligible'] === 1) $byDay[$day]['qualified']++;

    $totals['gross'] += $gross;
    $totals['eligible'] += $eligible;
    $totals['commission'] += $commission;
    $totals['conversions']++;
    if ((int)$row['is_eligible'] === 1) $totals['qualified']++;
}
ksort($byDay);

// CSV export. Same rows, same arithmetic.
if (is_string($_GET['export'] ?? null) && $_GET['export'] === 'csv') {
    $safe = static function ($value): string {
        $value = (string)$value;
        // A leading =, +, -, @ (or tab/CR) is a formula in a spreadsheet.
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "\t" . $value;
        }
        return $value;
    };
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="affiliate-report-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    // A BOM so Excel reads the Arabic columns as UTF-8 rather than as mojibake.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        is_rtl() ? 'التاريخ' : 'Date',
        is_rtl() ? 'التحويلات' : 'Conversions',
        is_rtl() ? 'المؤهلة' : 'Qualified',
        is_rtl() ? 'الإجمالي' : 'Gross',
        is_rtl() ? 'المؤهل' : 'Eligible',
        is_rtl() ? 'العمولة' : 'Commission',
        is_rtl() ? 'العملة' : 'Currency',
    ]);
    foreach ($byDay as $day => $agg) {
        fputcsv($out, [
            $safe($day), $agg['conversions'], $agg['qualified'],
            affiliate_from_minor($agg['gross'], $currency),
            affiliate_from_minor($agg['eligible'], $currency),
            affiliate_from_minor($agg['commission'], $currency),
            $safe($currency),
        ]);
    }
    fputcsv($out, [
        $safe(is_rtl() ? 'الإجمالي' : 'Total'), $totals['conversions'], $totals['qualified'],
        affiliate_from_minor($totals['gross'], $currency),
        affiliate_from_minor($totals['eligible'], $currency),
        affiliate_from_minor($totals['commission'], $currency),
        $safe($currency),
    ]);
    fclose($out);
    exit;
}

// Header last, so the CSV branch above can exit before any HTML is written.
require_once __DIR__ . '/../includes/affiliate_header.php';
?>
<div class="app-page">
  <div class="app-page-head">
    <div>
      <h1><?= ui_e(is_rtl() ? 'التقارير' : 'Reports') ?></h1>
      <p class="muted"><?= ui_e(is_rtl() ? 'أداء إحالاتك خلال فترة محدّدة.' : 'Your referral performance over a chosen period.') ?></p>
    </div>
  </div>

  <section class="app-card">
    <form method="get" class="aff-filter-bar">
      <label class="field"><span><?= ui_e(is_rtl() ? 'من' : 'From') ?></span><input type="date" name="from" value="<?= ui_e($from) ?>"></label>
      <label class="field"><span><?= ui_e(is_rtl() ? 'إلى' : 'To') ?></span><input type="date" name="to" value="<?= ui_e($to) ?>"></label>
      <button class="btn btn-primary" type="submit"><?= ui_e(is_rtl() ? 'تطبيق' : 'Apply') ?></button>
      <a class="btn btn-outline" href="?<?= ui_e(http_build_query(['from' => $from, 'to' => $to, 'export' => 'csv'])) ?>"><?= ui_e(is_rtl() ? 'تنزيل CSV' : 'Download CSV') ?></a>
    </form>
  </section>

  <div class="aff-stat-grid">
    <div class="aff-stat"><span class="aff-stat-label"><?= ui_e(is_rtl() ? 'التحويلات' : 'Conversions') ?></span><span class="aff-stat-value"><?= number_format($totals['conversions']) ?></span></div>
    <div class="aff-stat"><span class="aff-stat-label"><?= ui_e(is_rtl() ? 'المؤهلة' : 'Qualified') ?></span><span class="aff-stat-value"><?= number_format($totals['qualified']) ?></span></div>
    <div class="aff-stat"><span class="aff-stat-label"><?= ui_e(is_rtl() ? 'الإيراد المؤهل' : 'Eligible revenue') ?></span><span class="aff-stat-value"><?= ui_e(affiliate_format_money(affiliate_from_minor($totals['eligible'], $currency), $currency)) ?></span></div>
    <div class="aff-stat"><span class="aff-stat-label"><?= ui_e(is_rtl() ? 'العمولة' : 'Commission') ?></span><span class="aff-stat-value"><?= ui_e(affiliate_format_money(affiliate_from_minor($totals['commission'], $currency), $currency)) ?></span></div>
  </div>

  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'التفصيل اليومي' : 'Daily breakdown') ?></h2></div>
    <?php if (!$byDay): ?>
      <?= ui_empty_state(is_rtl() ? 'لا توجد بيانات في هذه الفترة' : 'No data in this period', '', 'chart') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr>
            <th><?= ui_e(is_rtl() ? 'التاريخ' : 'Date') ?></th>
            <th><?= ui_e(is_rtl() ? 'التحويلات' : 'Conversions') ?></th>
            <th><?= ui_e(is_rtl() ? 'المؤهلة' : 'Qualified') ?></th>
            <th><?= ui_e(is_rtl() ? 'الإجمالي' : 'Gross') ?></th>
            <th><?= ui_e(is_rtl() ? 'المؤهل' : 'Eligible') ?></th>
            <th><?= ui_e(is_rtl() ? 'العمولة' : 'Commission') ?></th>
          </tr></thead>
          <tbody>
            <?php foreach ($byDay as $day => $agg): ?>
              <tr>
                <td><?= ui_e($day) ?></td>
                <td><?= (int)$agg['conversions'] ?></td>
                <td><?= (int)$agg['qualified'] ?></td>
                <td class="aff-mono"><?= ui_e(affiliate_format_money(affiliate_from_minor($agg['gross'], $currency), $currency)) ?></td>
                <td class="aff-mono"><?= ui_e(affiliate_format_money(affiliate_from_minor($agg['eligible'], $currency), $currency)) ?></td>
                <td class="aff-mono"><?= ui_e(affiliate_format_money(affiliate_from_minor($agg['commission'], $currency), $currency)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th><?= ui_e(is_rtl() ? 'الإجمالي' : 'Total') ?></th>
              <th><?= (int)$totals['conversions'] ?></th>
              <th><?= (int)$totals['qualified'] ?></th>
              <th class="aff-mono"><?= ui_e(affiliate_format_money(affiliate_from_minor($totals['gross'], $currency), $currency)) ?></th>
              <th class="aff-mono"><?= ui_e(affiliate_format_money(affiliate_from_minor($totals['eligible'], $currency), $currency)) ?></th>
              <th class="aff-mono"><?= ui_e(affiliate_format_money(affiliate_from_minor($totals['commission'], $currency), $currency)) ?></th>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
