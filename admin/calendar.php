<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
$activeAdmin = 'calendar';
require_once __DIR__ . '/includes/admin_header.php';

$ym = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$first = strtotime($ym . '-01'); if (!$first) { $first = strtotime(date('Y-m-01')); $ym = date('Y-m'); }
$daysIn = (int)date('t', $first); $startDow = (int)date('w', $first); // 0=Sun
$from = date('Y-m-01 00:00:00', $first); $to = date('Y-m-t 23:59:59', $first);
$prev = date('Y-m', strtotime('-1 month', $first)); $next = date('Y-m', strtotime('+1 month', $first));
$rows = function (string $sql, array $p) use ($pdo): array { try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (Throwable $e) { return []; } };
$ev = []; // day => list
$add = function (string $when, string $cls, string $title, string $link = '#', string $kind = '') use (&$ev) { $d = (int)date('j', strtotime($when)); $ev[$d][] = ['t' => $when, 'c' => $cls, 'title' => $title, 'l' => $link, 'k' => $kind]; };
foreach ($rows("SELECT c.id,c.scheduled_at t,u.full_name a,l.full_name b FROM consultations c JOIN users u ON u.id=c.user_id JOIN lawyers l ON l.id=c.lawyer_id WHERE c.scheduled_at BETWEEN ? AND ? AND c.status<>'cancelled'", [$from, $to]) as $r)
    $add($r['t'], 'c-cons', date('H:i', strtotime($r['t'])) . ' ' . $r['a'] . ' ↔ ' . $r['b'], '/admin/consultations.php', 'cons');
foreach ($rows("SELECT title,event_at t,event_type FROM legal_calendar_events WHERE event_at BETWEEN ? AND ? AND status<>'cancelled'", [$from, $to]) as $r)
    $add($r['t'], 'c-court', date('H:i', strtotime($r['t'])) . ' ' . $r['title'], '#', 'court');
foreach ($rows("SELECT t.title,t.due_date d,c.title ct FROM case_tasks t JOIN cases c ON c.id=t.case_id WHERE t.status='pending' AND t.due_date BETWEEN ? AND ?", [substr($from, 0, 10), substr($to, 0, 10)]) as $r)
    $add($r['d'] . ' 09:00:00', 'c-task', $r['title'] . ' · ' . $r['ct'], '#', 'task');
foreach ($rows("SELECT title,due_at t,task_type FROM crm_tasks WHERE status='open' AND due_at BETWEEN ? AND ?", [$from, $to]) as $r)
    $add($r['t'], 'c-crm', date('H:i', strtotime($r['t'])) . ' ' . $r['title'], '/admin/crm.php#tasks', 'crm');
$dows = is_rtl() ? ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'] : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$monthName = is_rtl() ? ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'][(int)date('n', $first) - 1] : date('F', $first);
$today = date('Y-m-d');
$total = array_sum(array_map('count', $ev));
?>
<div class="app-content">
  <div class="pg-head"><div><h1><?= h($monthName . ' ' . date('Y', $first)) ?></h1><p><?= $total ?> <?= h(adm('حدثاً هذا الشهر', 'events this month')) ?></p></div>
    <div class="pg-tools"><a class="adm-iconbtn" href="?m=<?= $prev ?>" aria-label="prev"><?= is_rtl() ? '›' : '‹' ?></a><a class="btn btn-outline" href="?m=<?= date('Y-m') ?>"><?= h(adm('اليوم', 'Today')) ?></a><a class="adm-iconbtn" href="?m=<?= $next ?>" aria-label="next"><?= is_rtl() ? '‹' : '›' ?></a>
      <a class="btn btn-primary" href="/admin/crm.php?new=task#tasks"><?= adm_icon('plus') ?><?= h(adm('مهمة / متابعة', 'Task / follow-up')) ?></a></div></div>
  <div class="legend" style="margin:-6px 0 14px"><span><i style="background:var(--success)"></i><?= h(adm('استشارات', 'Consultations')) ?></span><span><i style="background:var(--danger)"></i><?= h(adm('جلسات ومواعيد قانونية', 'Court & legal deadlines')) ?></span><span><i style="background:var(--warn)"></i><?= h(adm('مهام القضايا', 'Case tasks')) ?></span><span><i style="background:var(--gold)"></i><?= h(adm('مهام CRM', 'CRM tasks')) ?></span></div>
  <div class="cal">
    <?php foreach ($dows as $d): ?><div class="dow"><?= h($d) ?></div><?php endforeach; ?>
    <?php for ($i = 0; $i < $startDow; $i++): ?><div class="day out"></div><?php endfor; ?>
    <?php for ($d = 1; $d <= $daysIn; $d++): $isToday = sprintf('%s-%02d', $ym, $d) === $today; $list = $ev[$d] ?? []; usort($list, fn($a, $b) => strcmp($a['t'], $b['t'])); ?>
      <div class="day <?= $isToday ? 'today' : '' ?>"><span class="n"><?= $d ?></span>
        <?php foreach (array_slice($list, 0, 3) as $e): ?><a class="ev <?= $e['c'] ?>" href="<?= h($e['l']) ?>" title="<?= h($e['title']) ?>"><?= h($e['title']) ?></a><?php endforeach; ?>
        <?php if (count($list) > 3): ?><small style="color:var(--muted)">+<?= count($list) - 3 ?></small><?php endif; ?></div>
    <?php endfor; ?>
    <?php $tail = (7 - (($startDow + $daysIn) % 7)) % 7; for ($i = 0; $i < $tail; $i++): ?><div class="day out"></div><?php endfor; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
