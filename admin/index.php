<?php
$activeAdmin = 'dashboard';
require_once __DIR__ . '/includes/admin_header.php';

/** Safe query helpers: a missing optional table must never break the dashboard. */
$rows = function (string $sql, array $p = []) use ($pdo): array { try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (Throwable $e) { return []; } };
$val = function (string $sql, array $p = [], $def = 0) use ($pdo) { try { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchColumn(); return $v === false || $v === null ? $def : $v; } catch (Throwable $e) { return $def; } };
$delta = function ($cur, $prev): array {
    if ($prev == 0) return [$cur > 0 ? '+100%' : '0%', $cur > 0 ? 'up' : 'flat'];
    $d = round((($cur - $prev) / $prev) * 100); return [($d > 0 ? '+' : '') . $d . '%', $d > 0 ? 'up' : ($d < 0 ? 'down' : 'flat')];
};

// 12-month axis
$months = []; $mLabels = [];
for ($i = 11; $i >= 0; $i--) { $t = strtotime("first day of -$i month"); $months[date('Y-m', $t)] = 0; $mLabels[] = date(is_rtl() ? 'm/y' : 'M', $t); }
$series = function (string $sql) use ($rows, $months): array { $m = $months; foreach ($rows($sql) as $r) if (isset($m[$r['ym']])) $m[$r['ym']] = (float)$r['v']; return array_values($m); };

$revenueSeries = $series("SELECT DATE_FORMAT(COALESCE(paid_at,created_at),'%Y-%m') ym, SUM(amount) v FROM invoices WHERE status='paid' AND is_draft=0 AND COALESCE(paid_at,created_at)>=DATE_SUB(DATE_FORMAT(NOW(),'%Y-%m-01'),INTERVAL 11 MONTH) GROUP BY ym");
$consultSeries = $series("SELECT DATE_FORMAT(scheduled_at,'%Y-%m') ym, COUNT(*) v FROM consultations WHERE scheduled_at>=DATE_SUB(DATE_FORMAT(NOW(),'%Y-%m-01'),INTERVAL 11 MONTH) GROUP BY ym");
$clientSeries = $series("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) v FROM users WHERE created_at>=DATE_SUB(DATE_FORMAT(NOW(),'%Y-%m-01'),INTERVAL 11 MONTH) GROUP BY ym");
$caseSeries = $series("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) v FROM cases WHERE created_at>=DATE_SUB(DATE_FORMAT(NOW(),'%Y-%m-01'),INTERVAL 11 MONTH) GROUP BY ym");

$tot = [
    'clients' => (int)$val('SELECT COUNT(*) FROM users'),
    'lawyers' => (int)$val("SELECT COUNT(*) FROM lawyers WHERE status='approved'"),
    'cases' => (int)$val('SELECT COUNT(*) FROM cases'),
    'cases_open' => (int)$val("SELECT COUNT(*) FROM cases WHERE status IN ('open','in_review')"),
    'consult' => (int)$val('SELECT COUNT(*) FROM consultations'),
    'revenue' => (float)$val("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='paid' AND is_draft=0"),
    'outstanding' => (float)$val("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status IN ('unpaid','overdue') AND is_draft=0"),
    'overdue' => (int)$val("SELECT COUNT(*) FROM invoices WHERE status='overdue' AND is_draft=0"),
    'leads_new' => (int)$val("SELECT COUNT(*) FROM contact_leads WHERE status='new'"),
    'opp_open' => (int)$val("SELECT COUNT(*) FROM crm_opportunities WHERE stage NOT IN ('won','lost')"),
    'opp_value' => (float)$val("SELECT COALESCE(SUM(value),0) FROM crm_opportunities WHERE stage NOT IN ('won','lost')"),
    'tasks_due' => (int)$val("SELECT COUNT(*) FROM crm_tasks WHERE status='open' AND due_at IS NOT NULL AND due_at<=DATE_ADD(CURDATE(),INTERVAL 1 DAY)") + (int)$val("SELECT COUNT(*) FROM case_tasks WHERE status='pending' AND due_date IS NOT NULL AND due_date<=CURDATE()"),
    'pending_lawyers' => (int)$val("SELECT COUNT(*) FROM lawyers WHERE status='pending'"),
];
[$dRev, $cRev] = $delta(end($revenueSeries), $revenueSeries[10]);
[$dCons, $cCons] = $delta(end($consultSeries), $consultSeries[10]);
[$dCli, $cCli] = $delta(end($clientSeries), $clientSeries[10]);
[$dCase, $cCase] = $delta(end($caseSeries), $caseSeries[10]);

$caseStatus = ['open' => adm('مفتوحة', 'Open'), 'in_review' => adm('قيد المراجعة', 'In review'), 'closed_won' => adm('مغلقة (كسب)', 'Won'), 'closed_settled' => adm('تسوية', 'Settled'), 'closed_lost' => adm('مغلقة (خسارة)', 'Lost')];
$cs = array_fill_keys(array_keys($caseStatus), 0); foreach ($rows('SELECT status, COUNT(*) c FROM cases GROUP BY status') as $r) if (isset($cs[$r['status']])) $cs[$r['status']] = (int)$r['c'];

$stageLabels = ['new' => adm('جديدة', 'New'), 'qualified' => adm('مؤهَّلة', 'Qualified'), 'proposal' => adm('عرض سعر', 'Proposal'), 'negotiation' => adm('تفاوض', 'Negotiation'), 'won' => adm('مكسوبة', 'Won'), 'lost' => adm('خاسرة', 'Lost')];
$stageCnt = array_fill_keys(array_keys($stageLabels), 0); foreach ($rows('SELECT stage, COUNT(*) c FROM crm_opportunities GROUP BY stage') as $r) $stageCnt[$r['stage']] = (int)$r['c'];
$stageMax = max(1, max($stageCnt));

$activity = $rows("SELECT actor_name, action, target_type, created_at FROM audit_logs ORDER BY id DESC LIMIT 8");
$agenda = [];
foreach ($rows("SELECT c.scheduled_at t, u.full_name a, l.full_name b FROM consultations c JOIN users u ON u.id=c.user_id JOIN lawyers l ON l.id=c.lawyer_id WHERE c.scheduled_at>=NOW() AND c.status IN ('pending','confirmed') ORDER BY c.scheduled_at LIMIT 5") as $r)
    $agenda[] = ['t' => $r['t'], 'title' => adm('استشارة: ', 'Consultation: ') . $r['a'], 'sub' => $r['b'], 'type' => 'cons'];
foreach ($rows("SELECT event_at t, title, event_type k FROM legal_calendar_events WHERE event_at>=NOW() AND status='upcoming' ORDER BY event_at LIMIT 5") as $r)
    $agenda[] = ['t' => $r['t'], 'title' => $r['title'], 'sub' => str_replace('_', ' ', $r['k']), 'type' => 'court'];
foreach ($rows("SELECT due_at t, title FROM crm_tasks WHERE status='open' AND due_at IS NOT NULL ORDER BY due_at LIMIT 5") as $r)
    $agenda[] = ['t' => $r['t'], 'title' => $r['title'], 'sub' => 'CRM', 'type' => 'crm'];
usort($agenda, fn($a, $b) => strcmp($a['t'], $b['t'])); $agenda = array_slice($agenda, 0, 7);

$topLawyers = $rows("SELECT l.id,l.full_name,l.rating,l.specialization, COUNT(c.id) total_c, COALESCE(SUM(c.status='completed'),0) done_c FROM lawyers l LEFT JOIN consultations c ON c.lawyer_id=l.id WHERE l.status='approved' GROUP BY l.id ORDER BY done_c DESC, l.rating DESC LIMIT 5");
$recentLeads = $rows('SELECT * FROM contact_leads ORDER BY id DESC LIMIT 5');
$recentBookings = $rows('SELECT c.*, u.full_name user_name, l.full_name lawyer_name FROM consultations c JOIN users u ON u.id=c.user_id JOIN lawyers l ON l.id=c.lawyer_id ORDER BY c.id DESC LIMIT 5');
$money = fn($v) => money($v);
$j = fn($a) => h(json_encode($a, JSON_UNESCAPED_UNICODE));
$hour = (int)date('G'); $greet = $hour < 12 ? adm('صباح الخير', 'Good morning') : ($hour < 18 ? adm('مساء الخير', 'Good afternoon') : adm('مساء الخير', 'Good evening'));
$leadLabels = ['contact' => adm('تواصل عام', 'General'), 'join_lawyer' => adm('انضمام محامٍ', 'Lawyer application'), 'corporate' => adm('باقة مؤسسية', 'Corporate')];
$stCol = ['open' => '#2547b8', 'in_review' => '#c39a3e', 'closed_won' => '#12805c', 'closed_settled' => '#0e7490', 'closed_lost' => '#c2413a'];
?>
<div class="app-content">
  <div class="pg-head">
    <div><h1><?= h($greet) ?>، <?= h(explode(' ', trim((string)$_SESSION['admin_name']))[0]) ?> 👋</h1>
      <p><?= h(adm('هذه لمحة عن أداء المنصة اليوم — ', 'Here is how the platform is performing today — ') . date('Y-m-d')) ?></p></div>
    <div class="pg-tools">
      <a class="btn btn-outline" href="/admin/calendar.php"><?= adm_icon('calendar') ?><?= h(adm('التقويم', 'Calendar')) ?></a>
      <a class="btn btn-outline" href="/admin/reports.php"><?= adm_icon('chart') ?><?= h(adm('التقارير', 'Reports')) ?></a>
      <a class="btn btn-primary" href="/admin/crm.php?new=opportunity#opportunities"><?= adm_icon('plus') ?><?= h(adm('فرصة جديدة', 'New opportunity')) ?></a>
    </div>
  </div>

  <?php if ($tot['pending_lawyers'] || $tot['overdue'] || $tot['leads_new'] || $tot['tasks_due']): ?>
  <div class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:14px 18px">
    <b style="font-size:13px"><?= h(adm('يحتاج انتباهك:', 'Needs attention:')) ?></b>
    <?php if ($tot['leads_new']): ?><a class="pill amber" href="/admin/leads.php"><?= $tot['leads_new'] ?> <?= h(adm('طلب وارد جديد', 'new inbound leads')) ?></a><?php endif; ?>
    <?php if ($tot['pending_lawyers']): ?><a class="pill blue" href="/admin/lawyers.php"><?= $tot['pending_lawyers'] ?> <?= h(adm('محامٍ بانتظار الاعتماد', 'lawyers awaiting approval')) ?></a><?php endif; ?>
    <?php if ($tot['overdue']): ?><a class="pill red" href="/admin/invoices.php"><?= $tot['overdue'] ?> <?= h(adm('فاتورة متأخرة', 'overdue invoices')) ?></a><?php endif; ?>
    <?php if ($tot['tasks_due']): ?><a class="pill gold" href="/admin/crm.php#tasks"><?= $tot['tasks_due'] ?> <?= h(adm('مهمة مستحقة', 'tasks due')) ?></a><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="grid g-4" style="margin-bottom:18px">
    <a class="kpi" href="/admin/invoices.php"><div class="kpi-top"><span class="kpi-ic green"><?= adm_icon('receipt') ?></span><span class="kpi-delta <?= $cRev ?>"><?= $dRev ?></span></div>
      <div class="kpi-val"><?= $money($tot['revenue']) ?></div><div class="kpi-lbl"><?= h(adm('الإيرادات المحصّلة', 'Collected revenue')) ?> (<?= currency_symbol() ?>)</div>
      <div class="chart" data-chart="<?= $j(['type' => 'spark', 'data' => $revenueSeries, 'color' => '#12805c']) ?>"></div></a>
    <a class="kpi" href="/admin/users.php"><div class="kpi-top"><span class="kpi-ic"><?= adm_icon('users') ?></span><span class="kpi-delta <?= $cCli ?>"><?= $dCli ?></span></div>
      <div class="kpi-val"><?= number_format($tot['clients']) ?></div><div class="kpi-lbl"><?= h(adm('العملاء', 'Clients')) ?></div>
      <div class="chart" data-chart="<?= $j(['type' => 'spark', 'data' => $clientSeries]) ?>"></div></a>
    <a class="kpi" href="/admin/lawyers.php"><div class="kpi-top"><span class="kpi-ic gold"><?= adm_icon('scale') ?></span><span class="kpi-delta flat"><?= $tot['pending_lawyers'] ?> <?= h(adm('بالانتظار', 'pending')) ?></span></div>
      <div class="kpi-val"><?= number_format($tot['lawyers']) ?></div><div class="kpi-lbl"><?= h(adm('محامون معتمدون', 'Approved lawyers')) ?></div>
      <div class="chart" data-chart="<?= $j(['type' => 'spark', 'data' => $caseSeries, 'color' => '#c39a3e']) ?>"></div></a>
    <a class="kpi" href="/admin/consultations.php"><div class="kpi-top"><span class="kpi-ic teal"><?= adm_icon('message') ?></span><span class="kpi-delta <?= $cCons ?>"><?= $dCons ?></span></div>
      <div class="kpi-val"><?= number_format($tot['consult']) ?></div><div class="kpi-lbl"><?= h(adm('الاستشارات', 'Consultations')) ?></div>
      <div class="chart" data-chart="<?= $j(['type' => 'spark', 'data' => $consultSeries, 'color' => '#0e7490']) ?>"></div></a>
    <a class="kpi" href="/admin/reports.php"><div class="kpi-top"><span class="kpi-ic"><?= adm_icon('briefcase') ?></span><span class="kpi-delta <?= $cCase ?>"><?= $dCase ?></span></div>
      <div class="kpi-val"><?= number_format($tot['cases_open']) ?> <small style="font-size:14px;color:var(--muted)">/ <?= number_format($tot['cases']) ?></small></div><div class="kpi-lbl"><?= h(adm('القضايا (مفتوحة / الإجمالي)', 'Cases (open / total)')) ?></div></a>
    <a class="kpi" href="/admin/crm.php"><div class="kpi-top"><span class="kpi-ic gold"><?= adm_icon('target') ?></span><span class="kpi-delta flat"><?= $tot['opp_open'] ?> <?= h(adm('فرصة', 'open')) ?></span></div>
      <div class="kpi-val"><?= money($tot['opp_value']) ?></div><div class="kpi-lbl"><?= h(adm('قيمة الـ Pipeline', 'Pipeline value')) ?></div></a>
    <a class="kpi" href="/admin/invoices.php"><div class="kpi-top"><span class="kpi-ic red"><?= adm_icon('clock') ?></span><span class="kpi-delta <?= $tot['overdue'] ? 'down' : 'flat' ?>"><?= $tot['overdue'] ?> <?= h(adm('متأخرة', 'overdue')) ?></span></div>
      <div class="kpi-val"><?= $money($tot['outstanding']) ?></div><div class="kpi-lbl"><?= h(adm('مستحقات غير محصّلة', 'Outstanding')) ?> (<?= currency_symbol() ?>)</div></a>
    <a class="kpi" href="/admin/crm.php#tasks"><div class="kpi-top"><span class="kpi-ic teal"><?= adm_icon('check') ?></span><span class="kpi-delta <?= $tot['leads_new'] ? 'up' : 'flat' ?>"><?= $tot['leads_new'] ?> <?= h(adm('طلب جديد', 'new leads')) ?></span></div>
      <div class="kpi-val"><?= number_format($tot['tasks_due']) ?></div><div class="kpi-lbl"><?= h(adm('مهام ومتابعات مستحقة', 'Tasks & follow-ups due')) ?></div></a>
  </div>

  <div class="grid g-21" style="margin-bottom:18px">
    <div class="card"><div class="card-h"><h3><?= h(adm('الإيرادات خلال 12 شهراً', 'Revenue — last 12 months')) ?></h3><small><?= currency_symbol() ?></small></div>
      <div class="chart" data-chart="<?= $j(['type' => 'area', 'labels' => $mLabels, 'fmt' => 'money', 'series' => [['name' => adm('الإيرادات', 'Revenue'), 'data' => $revenueSeries, 'color' => '#2547b8']]]) ?>"></div></div>
    <div class="card"><div class="card-h"><h3><?= h(adm('توزيع القضايا', 'Cases by status')) ?></h3></div>
      <div class="donut-wrap"><div class="chart" data-chart="<?= $j(['type' => 'donut', 'labels' => array_values($caseStatus), 'data' => array_values($cs), 'colors' => array_values($stCol), 'center' => adm('قضية', 'cases')]) ?>"></div>
        <div class="legend" style="flex-direction:column;margin:0"><?php foreach ($caseStatus as $k => $lbl): ?><span><i style="background:<?= $stCol[$k] ?>"></i><?= h($lbl) ?> · <b><?= $cs[$k] ?></b></span><?php endforeach; ?></div></div></div>
  </div>

  <div class="grid g-3" style="margin-bottom:18px">
    <div class="card"><div class="card-h"><h3><?= h(adm('الاستشارات والعملاء الجدد', 'Consultations & new clients')) ?></h3></div>
      <div class="chart" data-chart="<?= $j(['type' => 'bar', 'height' => 210, 'labels' => $mLabels, 'series' => [['name' => adm('استشارات', 'Consultations'), 'data' => $consultSeries, 'color' => '#2547b8'], ['name' => adm('عملاء جدد', 'New clients'), 'data' => $clientSeries, 'color' => '#c39a3e']]]) ?>"></div></div>
    <div class="card"><div class="card-h"><h3><?= h(adm('مسار الفرص (Pipeline)', 'Opportunity funnel')) ?></h3><a class="btn btn-outline btn-sm" href="/admin/crm.php"><?= h(adm('فتح', 'Open')) ?></a></div>
      <?php foreach ($stageLabels as $k => $lbl): ?><div class="bar-row"><span><?= h($lbl) ?></span><div class="tr"><i style="width:<?= round($stageCnt[$k] / $stageMax * 100) ?>%"></i></div><b><?= $stageCnt[$k] ?></b></div><?php endforeach; ?></div>
    <div class="card"><div class="card-h"><h3><?= h(adm('إجراءات سريعة', 'Quick actions')) ?></h3></div>
      <div class="quick" style="grid-template-columns:1fr 1fr">
        <a href="/admin/crm.php?new=opportunity#opportunities"><span class="kpi-ic gold"><?= adm_icon('target') ?></span><?= h(adm('فرصة', 'Opportunity')) ?></a>
        <a href="/admin/crm.php?new=task#tasks"><span class="kpi-ic"><?= adm_icon('check') ?></span><?= h(adm('مهمة', 'Task')) ?></a>
        <a href="/admin/lawyers.php"><span class="kpi-ic teal"><?= adm_icon('scale') ?></span><?= h(adm('محامٍ', 'Lawyer')) ?></a>
        <a href="/admin/invoices.php"><span class="kpi-ic green"><?= adm_icon('receipt') ?></span><?= h(adm('الفواتير', 'Invoices')) ?></a>
        <a href="/admin/articles.php"><span class="kpi-ic"><?= adm_icon('book') ?></span><?= h(adm('مقال', 'Article')) ?></a>
        <a href="/admin/ai-knowledge.php"><span class="kpi-ic gold"><?= adm_icon('spark') ?></span><?= h(adm('معرفة AI', 'AI knowledge')) ?></a>
      </div></div>
  </div>

  <div class="grid g-2" style="margin-bottom:18px">
    <div class="card"><div class="card-h"><h3><?= h(adm('جدول الأعمال القادم', 'Upcoming agenda')) ?></h3><a class="btn btn-outline btn-sm" href="/admin/calendar.php"><?= h(adm('التقويم', 'Calendar')) ?></a></div>
      <ul class="rowlist"><?php foreach ($agenda as $a): $cls = ['cons' => 'green', 'court' => 'red', 'crm' => 'gold'][$a['type']]; ?>
        <li><span class="pill <?= $cls ?>" style="min-width:104px;justify-content:center"><?= h(date('m-d H:i', strtotime($a['t']))) ?></span><div class="grow"><b><?= h($a['title']) ?></b><small><?= h($a['sub']) ?></small></div></li>
      <?php endforeach; if (!$agenda): ?><li class="empty"><?= h(adm('لا توجد مواعيد قادمة', 'Nothing scheduled')) ?></li><?php endif; ?></ul></div>
    <div class="card"><div class="card-h"><h3><?= h(adm('آخر النشاطات', 'Recent activity')) ?></h3><a class="btn btn-outline btn-sm" href="/admin/audit-log.php"><?= h(adm('السجل الكامل', 'Full log')) ?></a></div>
      <ul class="tl"><?php foreach ($activity as $a): ?>
        <li><span class="dot"><?= adm_icon('clock') ?></span><b><?= h($a['action']) ?></b><small><?= h($a['actor_name'] ?: 'system') ?> · <?= h(date('m-d H:i', strtotime($a['created_at']))) ?></small></li>
      <?php endforeach; if (!$activity): ?><li class="empty" style="padding-inline-start:0"><?= h(adm('لا يوجد نشاط مسجّل', 'No recorded activity')) ?></li><?php endif; ?></ul></div>
  </div>

  <div class="grid g-3">
    <div class="card"><div class="card-h"><h3><?= h(adm('أفضل المحامين أداءً', 'Top lawyers')) ?></h3><a class="btn btn-outline btn-sm" href="/admin/lawyers.php"><?= h(adm('الكل', 'All')) ?></a></div>
      <ul class="rowlist"><?php foreach ($topLawyers as $lw): ?><li><span class="av"><?= h(mb_substr($lw['full_name'], 0, 1, 'UTF-8')) ?></span><div class="grow"><b><?= h($lw['full_name']) ?></b><small><?= h($lw['specialization']) ?> · <?= (int)$lw['done_c'] ?>/<?= (int)$lw['total_c'] ?></small></div><span class="pill gold">★ <?= h($lw['rating']) ?></span></li>
      <?php endforeach; if (!$topLawyers) echo '<li class="empty">' . h(adm('لا يوجد محامون بعد', 'No lawyers yet')) . '</li>'; ?></ul></div>
    <div class="card"><div class="card-h"><h3><?= h(adm('أحدث الحجوزات', 'Latest bookings')) ?></h3><a class="btn btn-outline btn-sm" href="/admin/consultations.php"><?= h(adm('الكل', 'All')) ?></a></div>
      <ul class="rowlist"><?php foreach ($recentBookings as $b): ?><li><div class="grow"><b><?= h($b['user_name']) ?></b><small><?= h($b['lawyer_name']) ?> · <?= h(date('m-d H:i', strtotime($b['scheduled_at']))) ?></small></div><span class="status-pill status-<?= h($b['status']) ?>"><?= h($b['status']) ?></span></li>
      <?php endforeach; if (!$recentBookings) echo '<li class="empty">' . h(adm('لا توجد حجوزات بعد', 'No bookings yet')) . '</li>'; ?></ul></div>
    <div class="card"><div class="card-h"><h3><?= h(adm('أحدث الطلبات الواردة', 'Latest inbound leads')) ?></h3><a class="btn btn-outline btn-sm" href="/admin/leads.php"><?= h(adm('الكل', 'All')) ?></a></div>
      <ul class="rowlist"><?php foreach ($recentLeads as $l): ?><li><span class="av"><?= h(mb_substr($l['full_name'], 0, 1, 'UTF-8')) ?></span><div class="grow"><b><?= h($l['full_name']) ?></b><small><?= h($leadLabels[$l['lead_type']] ?? $l['lead_type']) ?></small></div><span class="status-pill status-<?= $l['status'] === 'new' ? 'pending' : 'confirmed' ?>"><?= h($l['status']) ?></span></li>
      <?php endforeach; if (!$recentLeads) echo '<li class="empty">' . h(adm('لا توجد طلبات بعد', 'No leads yet')) . '</li>'; ?></ul></div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
