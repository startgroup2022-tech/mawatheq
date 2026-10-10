<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
$activeAdmin = 'reports';
require_once __DIR__ . '/includes/admin_header.php';

$pageWarnings = [];
$metrics = [
    'cases' => ['total' => 0, 'open_cases' => 0, 'reviewing' => 0, 'closed_cases' => 0, 'avg_probability' => null],
    'money' => ['paid_count' => 0, 'paid_amount' => 0, 'outstanding' => 0, 'overdue_count' => 0],
    'documents' => ['total' => 0, 'completed' => 0, 'failed' => 0],
    'consultations' => ['total' => 0, 'completed' => 0, 'cancelled' => 0],
    'ai' => 0,
    'time' => ['minutes' => 0, 'value' => 0],
];
$reportQueries = [
    'cases' => "SELECT COUNT(*) total,COALESCE(SUM(status='open'),0) open_cases,COALESCE(SUM(status='in_review'),0) reviewing,COALESCE(SUM(status LIKE 'closed%'),0) closed_cases,AVG(win_probability) avg_probability FROM cases",
    'money' => "SELECT COALESCE(SUM(status='paid'),0) paid_count,COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) paid_amount,COALESCE(SUM(CASE WHEN status IN ('unpaid','overdue') THEN amount ELSE 0 END),0) outstanding,COALESCE(SUM(status='overdue' OR (status='unpaid' AND due_date<CURDATE())),0) overdue_count FROM invoices WHERE is_draft=0",
    'documents' => "SELECT COUNT(*) total,COALESCE(SUM(status='completed'),0) completed,COALESCE(SUM(status='failed'),0) failed FROM documents",
    'consultations' => "SELECT COUNT(*) total,COALESCE(SUM(status='completed'),0) completed,COALESCE(SUM(status='cancelled'),0) cancelled FROM consultations",
];
foreach ($reportQueries as $key => $sql) {
    try { $metrics[$key] = $pdo->query($sql)->fetch() ?: $metrics[$key]; }
    catch (PDOException $e) { error_log('Admin report ' . $key . ' query failed: ' . $e->getMessage()); $pageWarnings[] = $key; }
}
try { $metrics['ai'] = (int)$pdo->query("SELECT COUNT(*) FROM ai_messages WHERE sender='user' AND created_at>=DATE_FORMAT(NOW(),'%Y-%m-01')")->fetchColumn(); }
catch (PDOException $e) { error_log('Admin report AI query failed: ' . $e->getMessage()); $pageWarnings[] = 'ai'; }
try {
    $hasTimeTable = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_time_entries'")->fetchColumn();
    if ($hasTimeTable) $metrics['time'] = $pdo->query('SELECT COALESCE(SUM(minutes),0) minutes,COALESCE(SUM(CASE WHEN billable=1 THEN ROUND(minutes*hourly_rate/60,3) ELSE 0 END),0) value FROM case_time_entries')->fetch() ?: $metrics['time'];
} catch (PDOException $e) { error_log('Admin report time query failed: ' . $e->getMessage()); $pageWarnings[] = 'time'; }
try { $lawyerPerformance = $pdo->query("SELECT l.id,l.full_name,l.specialization,
    (SELECT COUNT(*) FROM cases c WHERE c.lawyer_id=l.id) cases_count,
    (SELECT COUNT(*) FROM consultations co WHERE co.lawyer_id=l.id) consultation_count,
    (SELECT COALESCE(SUM(i.amount),0) FROM invoices i WHERE i.lawyer_id=l.id AND i.status='paid' AND i.is_draft=0) paid_invoices
    FROM lawyers l WHERE l.status='approved'
    ORDER BY consultation_count DESC,cases_count DESC LIMIT 10")->fetchAll(); }
catch (PDOException $e) { error_log('Admin lawyer report query failed: ' . $e->getMessage()); $lawyerPerformance = []; $pageWarnings[] = 'lawyers'; }

// 12-month trend for revenue + new cases (safe defaults if a query fails)
$months = []; $mLabels = [];
for ($i = 11; $i >= 0; $i--) { $t = strtotime("first day of -$i month"); $months[date('Y-m', $t)] = 0; $mLabels[] = date(is_rtl() ? 'm/y' : 'M', $t); }
$series = function (string $sql) use ($pdo, $months): array {
    $m = $months;
    try { foreach ($pdo->query($sql)->fetchAll() as $r) if (isset($m[$r['ym']])) $m[$r['ym']] = (float)$r['v']; } catch (PDOException $e) {}
    return array_values($m);
};
$revTrend = $series("SELECT DATE_FORMAT(COALESCE(paid_at,created_at),'%Y-%m') ym, SUM(amount) v FROM invoices WHERE status='paid' AND is_draft=0 AND COALESCE(paid_at,created_at)>=DATE_SUB(DATE_FORMAT(NOW(),'%Y-%m-01'),INTERVAL 11 MONTH) GROUP BY ym");
$caseTrend = $series("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) v FROM cases WHERE created_at>=DATE_SUB(DATE_FORMAT(NOW(),'%Y-%m-01'),INTERVAL 11 MONTH) GROUP BY ym");
$j = fn($a) => h(json_encode($a, JSON_UNESCAPED_UNICODE));
?>
<div class="app-content">
  <div class="pg-head"><div><h1>📈 <?= h(adm('التقارير والتحليلات', 'Reports & analytics')) ?></h1><p><?= h(adm('أداء المنصة خلال آخر 12 شهراً', 'Platform performance over the last 12 months')) ?></p></div></div>
  <?php if ($pageWarnings): ?><div class="form-alert error"><?= h(adm('بعض مؤشرات التقرير غير متاحة حالياً. راجع سجل أخطاء PHP في cPanel، وتأكد من تشغيل ترقيات قاعدة البيانات.', 'Some report metrics are unavailable. Check the PHP error log in cPanel and confirm database upgrades have run.')) ?></div><?php endif; ?>

  <div class="stat-grid">
    <div class="stat-card"><div class="num"><?= (int)$metrics['cases']['total'] ?></div><div class="label"><?= h(adm('القضايا', 'Cases')) ?> · <?= h(adm('مفتوحة', 'open')) ?> <?= (int)$metrics['cases']['open_cases'] ?> · <?= h(adm('مغلقة', 'closed')) ?> <?= (int)$metrics['cases']['closed_cases'] ?></div></div>
    <div class="stat-card"><div class="num"><?= money($metrics['money']['paid_amount']) ?></div><div class="label"><?= h(adm('فواتير محصّلة', 'Collected invoices')) ?> · <?= (int)$metrics['money']['paid_count'] ?></div></div>
    <div class="stat-card"><div class="num"><?= money($metrics['money']['outstanding']) ?></div><div class="label"><?= h(adm('مبالغ غير محصّلة', 'Outstanding')) ?> · <?= h(adm('متأخرة', 'overdue')) ?> <?= (int)$metrics['money']['overdue_count'] ?></div></div>
    <div class="stat-card"><div class="num"><?= (int)$metrics['consultations']['completed'] ?>/<?= (int)$metrics['consultations']['total'] ?></div><div class="label"><?= h(adm('استشارات مكتملة / إجمالي', 'Consultations completed / total')) ?></div></div>
    <div class="stat-card"><div class="num"><?= (int)$metrics['documents']['completed'] ?>/<?= (int)$metrics['documents']['total'] ?></div><div class="label"><?= h(adm('مستندات محلَّلة / إجمالي', 'Documents analyzed / total')) ?> · <?= h(adm('فشل', 'failed')) ?> <?= (int)$metrics['documents']['failed'] ?></div></div>
    <div class="stat-card"><div class="num"><?= (int)$metrics['ai'] ?></div><div class="label"><?= h(adm('استفسارات AI هذا الشهر', 'AI queries this month')) ?></div></div>
    <div class="stat-card"><div class="num"><?= round((int)$metrics['time']['minutes'] / 60, 1) ?> <?= h(adm('ساعة', 'hrs')) ?></div><div class="label"><?= h(adm('وقت مسجَّل · قيمة قابلة للفوترة', 'Logged time · billable value')) ?> <?= money_text($metrics['time']['value']) ?></div></div>
    <div class="stat-card"><div class="num"><?= $metrics['cases']['avg_probability'] !== null ? round((float)$metrics['cases']['avg_probability'], 1) . '%' : '—' ?></div><div class="label"><?= h(adm('متوسط تقدير احتمالية الفوز (يدوي)', 'Average win-probability estimate (manual)')) ?></div></div>
  </div>

  <div class="grid g-2" style="margin-bottom:18px">
    <div class="card"><div class="card-h"><h3><?= h(adm('اتجاه الإيرادات', 'Revenue trend')) ?></h3><small><?= currency_symbol() ?></small></div>
      <div class="chart" data-chart="<?= $j(['type' => 'area', 'labels' => $mLabels, 'fmt' => 'money', 'series' => [['data' => $revTrend, 'color' => '#2547b8']]]) ?>"></div></div>
    <div class="card"><div class="card-h"><h3><?= h(adm('القضايا الجديدة شهرياً', 'New cases per month')) ?></h3></div>
      <div class="chart" data-chart="<?= $j(['type' => 'bar', 'labels' => $mLabels, 'series' => [['data' => $caseTrend, 'color' => '#c39a3e']]]) ?>"></div></div>
  </div>

  <div class="card"><div class="card-h"><h3><?= h(adm('أداء المحامين', 'Lawyer performance')) ?></h3></div>
    <div class="table-wrap table-cards"><table class="admin-table"><thead><tr><th><?= h(adm('المحامي', 'Lawyer')) ?></th><th><?= h(adm('التخصص', 'Specialization')) ?></th><th><?= h(adm('القضايا', 'Cases')) ?></th><th><?= h(adm('الاستشارات', 'Consultations')) ?></th><th><?= h(adm('الفواتير المحصَّلة', 'Collected invoices')) ?></th></tr></thead><tbody>
      <?php foreach ($lawyerPerformance as $lawyer): ?><tr><td data-label="<?= h(adm('المحامي', 'Lawyer')) ?>"><b><?= h($lawyer['full_name']) ?></b></td><td data-label="<?= h(adm('التخصص', 'Specialization')) ?>"><?= h($lawyer['specialization'] ?? '') ?></td><td data-label="<?= h(adm('القضايا', 'Cases')) ?>"><?= (int)$lawyer['cases_count'] ?></td><td data-label="<?= h(adm('الاستشارات', 'Consultations')) ?>"><?= (int)$lawyer['consultation_count'] ?></td><td data-label="<?= h(adm('الفواتير المحصَّلة', 'Collected invoices')) ?>"><?= money($lawyer['paid_invoices']) ?></td></tr><?php endforeach; ?>
      <?php if (!$lawyerPerformance): ?><tr><td colspan="5" class="empty"><?= h(adm('لا توجد بيانات كافية بعد', 'Not enough data yet')) ?></td></tr><?php endif; ?>
    </tbody></table></div></div>
  <p class="field-hint" style="margin-top:16px"><?= h(adm('الأرقام إجمالية من السجلات الحالية؛ قيمة الوقت تقديرية حسب سعر الساعة المسجل لكل إدخال ولا تعني أنها فواتير مدفوعة.', 'Figures are totals from current records; time value is estimated from each entry\'s hourly rate and does not imply invoices were paid.')) ?></p>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
