<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
$activeAdmin = 'dashboard'; $pageTitle = '';
require_once __DIR__ . '/includes/admin_header.php';
$q = trim((string)($_GET['q'] ?? '')); $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
$run = function (string $sql, int $n) use ($pdo, $like): array { try { $s = $pdo->prepare($sql); $s->execute(array_fill(0, $n, $like)); return $s->fetchAll(); } catch (Throwable $e) { return []; } };
$res = ['clients' => [], 'lawyers' => [], 'cases' => [], 'invoices' => [], 'leads' => [], 'opps' => []];
if (mb_strlen($q, 'UTF-8') >= 2) {
    $res['clients'] = $run('SELECT id,full_name,email,phone FROM users WHERE full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR company_name LIKE ? LIMIT 8', 4);
    $res['lawyers'] = $run('SELECT id,full_name,specialization,status FROM lawyers WHERE full_name LIKE ? OR specialization LIKE ? LIMIT 8', 2);
    $res['cases'] = $run('SELECT c.id,c.title,c.status,c.user_id,u.full_name uname FROM cases c JOIN users u ON u.id=c.user_id WHERE c.title LIKE ? OR u.full_name LIKE ? LIMIT 8', 2);
    $res['invoices'] = $run('SELECT i.id,i.description,i.amount,i.status,i.user_id FROM invoices i WHERE i.description LIKE ? LIMIT 8', 1);
    $res['leads'] = $run('SELECT id,full_name,email,status FROM contact_leads WHERE full_name LIKE ? OR email LIKE ? OR phone LIKE ? LIMIT 8', 3);
    $res['opps'] = $run('SELECT id,title,stage,value FROM crm_opportunities WHERE title LIKE ? LIMIT 8', 1);
}
$n = array_sum(array_map('count', $res));
$sections = [
    'clients' => [adm('العملاء', 'Clients'), 'users', fn($r) => ['/admin/client-detail.php?id=' . $r['id'], $r['full_name'], $r['email']]],
    'lawyers' => [adm('المحامون', 'Lawyers'), 'scale', fn($r) => ['/admin/lawyers.php', $r['full_name'], $r['specialization'] . ' · ' . $r['status']]],
    'cases' => [adm('القضايا', 'Cases'), 'briefcase', fn($r) => ['/admin/client-detail.php?id=' . $r['user_id'] . '#cases', $r['title'], $r['uname'] . ' · ' . $r['status']]],
    'invoices' => [adm('الفواتير', 'Invoices'), 'receipt', fn($r) => ['/admin/client-detail.php?id=' . $r['user_id'] . '#billing', $r['description'], money_text($r['amount']) . ' · ' . $r['status']]],
    'leads' => [adm('الطلبات الواردة', 'Leads'), 'inbox', fn($r) => ['/admin/leads.php', $r['full_name'], $r['email'] . ' · ' . $r['status']]],
    'opps' => [adm('الفرص', 'Opportunities'), 'target', fn($r) => ['/admin/crm.php#opportunities', $r['title'], $r['stage'] . ' · ' . money_text($r['value'])]],
];
?>
<div class="app-content">
  <div class="pg-head"><div><h1><?= h(adm('نتائج البحث', 'Search results')) ?></h1><p><?= $q === '' ? h(adm('اكتب كلمتين على الأقل للبحث', 'Type at least two characters')) : h('“' . $q . '” — ' . $n . ' ' . adm('نتيجة', 'results')) ?></p></div></div>
  <div class="grid g-2">
  <?php foreach ($sections as $k => [$label, $ic, $map]): if (!$res[$k]) continue; ?>
    <div class="card"><div class="card-h"><h3><?= h($label) ?></h3><span class="count-badge"><?= count($res[$k]) ?></span></div>
      <ul class="rowlist"><?php foreach ($res[$k] as $r): [$href, $t, $sub] = $map($r); ?><li><span class="kpi-ic"><?= adm_icon($ic) ?></span><div class="grow"><b><a href="<?= h($href) ?>"><?= h($t) ?></a></b><small><?= h($sub) ?></small></div></li><?php endforeach; ?></ul></div>
  <?php endforeach; ?></div>
  <?php if ($q !== '' && !$n): ?><div class="card empty"><?= h(adm('لا توجد نتائج مطابقة', 'No matching results')) ?></div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
