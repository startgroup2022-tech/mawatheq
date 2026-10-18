<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/phone.php';
require_once __DIR__ . '/../includes/lead_source.php';

$clientId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();

if ($client && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_note' && trim($_POST['note'] ?? '') !== '') {
    $pdo->prepare('INSERT INTO crm_notes (user_id, admin_id, note) VALUES (?,?,?)')->execute([$clientId, $_SESSION['admin_id'], trim($_POST['note'])]);
    header('Location: /admin/client-detail.php?id=' . $clientId . '#notes'); exit;
}

$activeAdmin = 'users';
$pageTitle = 'Client 360';
require_once __DIR__ . '/includes/admin_header.php';

if (!$client) { echo '<div class="app-content"><div class="card empty">' . h(adm('العميل غير موجود.', 'Client not found.')) . '</div></div>'; require __DIR__ . '/includes/admin_footer.php'; exit; }

$fetch = function (string $sql, array $p = []) use ($pdo): array { try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (Throwable $e) { return []; } };
$consultations = $fetch('SELECT c.*, l.full_name lawyer_name FROM consultations c JOIN lawyers l ON l.id=c.lawyer_id WHERE c.user_id=? ORDER BY c.scheduled_at DESC', [$clientId]);
$documents = $fetch('SELECT * FROM documents WHERE user_id=? ORDER BY id DESC', [$clientId]);
$cases = $fetch('SELECT c.*, l.full_name lawyer_name FROM cases c JOIN lawyers l ON l.id=c.lawyer_id WHERE c.user_id=? ORDER BY c.updated_at DESC', [$clientId]);
$invoices = $fetch('SELECT i.*, l.full_name lawyer_name FROM invoices i LEFT JOIN lawyers l ON l.id=i.lawyer_id WHERE i.user_id=? AND i.is_draft=0 ORDER BY i.id DESC', [$clientId]);
$notes = $fetch('SELECT n.*, a.full_name admin_name FROM crm_notes n LEFT JOIN admins a ON a.id=n.admin_id WHERE n.user_id=? ORDER BY n.id DESC', [$clientId]);
$opps = $fetch('SELECT * FROM crm_opportunities WHERE user_id=? ORDER BY id DESC', [$clientId]);
$tasks = $fetch("SELECT * FROM crm_tasks WHERE entity_type='client' AND entity_id=? ORDER BY status='done', due_at", [$clientId]);
$crmActs = $fetch("SELECT a.*, ad.full_name aname FROM crm_activities a LEFT JOIN admins ad ON ad.id=a.admin_id WHERE a.entity_type='client' AND a.entity_id=? ORDER BY a.id DESC LIMIT 30", [$clientId]);
$plan = null; if (!empty($client['plan_id'])) { $pq = $fetch('SELECT * FROM plans WHERE id=?', [(int)$client['plan_id']]); $plan = $pq[0] ?? null; }

$contracts = array_values(array_filter($documents, fn($d) => ($d['document_type'] ?? '') === 'contract'));
$paid = 0; $unpaid = 0; foreach ($invoices as $i) { if ($i['status'] === 'paid') $paid += $i['amount']; elseif (in_array($i['status'], ['unpaid', 'overdue'], true)) $unpaid += $i['amount']; }
$openCases = count(array_filter($cases, fn($c) => !str_starts_with($c['status'], 'closed')));
$oppValue = 0; foreach ($opps as $o) if (!in_array($o['stage'], ['won', 'lost'], true)) $oppValue += $o['value'];

// unified timeline
$tl = [];
foreach ($consultations as $c) $tl[] = ['t' => $c['scheduled_at'], 'i' => 'message', 'b' => adm('استشارة مع ', 'Consultation with ') . $c['lawyer_name'], 's' => $c['status']];
foreach ($cases as $c) $tl[] = ['t' => $c['created_at'], 'i' => 'briefcase', 'b' => adm('قضية: ', 'Case: ') . $c['title'], 's' => $c['lawyer_name']];
foreach ($documents as $d) $tl[] = ['t' => $d['created_at'], 'i' => 'file', 'b' => adm('مستند: ', 'Document: ') . $d['original_name'], 's' => $d['status']];
foreach ($invoices as $i) $tl[] = ['t' => $i['paid_at'] ?: $i['created_at'], 'i' => 'receipt', 'b' => adm('فاتورة: ', 'Invoice: ') . $i['description'] . ' — ' . money_text($i['amount']), 's' => $i['status']];
foreach ($notes as $n) $tl[] = ['t' => $n['created_at'], 'i' => 'edit', 'b' => adm('ملاحظة داخلية', 'Internal note'), 's' => mb_substr($n['note'], 0, 90, 'UTF-8')];
foreach ($crmActs as $a) $tl[] = ['t' => $a['created_at'], 'i' => 'phone', 'b' => 'CRM · ' . $a['activity_type'], 's' => $a['summary']];
$tl[] = ['t' => $client['created_at'], 'i' => 'user', 'b' => adm('انضم إلى المنصة', 'Joined the platform'), 's' => ''];
usort($tl, fn($a, $b) => strcmp($b['t'], $a['t'])); $tl = array_slice($tl, 0, 30);

$phoneDigits = preg_replace('/\D+/', '', (string)$client['phone']);
$cid = (int)$client['id']; $ret = urlencode('/admin/client-detail.php?id=' . $cid . '#crm');
$caseLbl = ['open' => adm('مفتوحة', 'Open'), 'in_review' => adm('قيد المراجعة', 'In review'), 'closed_won' => adm('كسب', 'Won'), 'closed_lost' => adm('خسارة', 'Lost'), 'closed_settled' => adm('تسوية', 'Settled')];
$stageLbl = ['new' => adm('جديدة', 'New'), 'qualified' => adm('مؤهَّلة', 'Qualified'), 'proposal' => adm('عرض سعر', 'Proposal'), 'negotiation' => adm('تفاوض', 'Negotiation'), 'won' => adm('مكسوبة', 'Won'), 'lost' => adm('خاسرة', 'Lost')];
?>
<div class="app-content">
  <div style="margin-bottom:12px"><a class="btn btn-outline btn-sm" href="/admin/users.php">← <?= h(adm('كل العملاء', 'All clients')) ?></a></div>
  <div class="profile-hero">
    <span class="av"><?= h(mb_substr($client['full_name'], 0, 1, 'UTF-8')) ?></span>
    <div style="flex:1;min-width:220px"><h1><?= h($client['full_name']) ?></h1>
      <p><?= h($client['email']) ?> · <?= display_phone($client['phone']) ?></p>
      <p><?= $client['account_type'] === 'company' ? h(adm('شركة', 'Company') . ($client['company_name'] ? ' — ' . $client['company_name'] : '')) : h(adm('فرد', 'Individual')) ?> · <?= h(adm('عضو منذ ', 'Member since ')) . h(date('Y-m-d', strtotime($client['created_at']))) ?> · <?= h(adm('المصدر: ', 'Source: ')) . h(format_lead_source($client['utm_source'] ?? null, $client['utm_medium'] ?? null, $client['utm_campaign'] ?? null)) ?></p>
      <p style="margin-top:8px"><span class="pill"><?= h($client['status']) ?></span> <?php if ($plan): ?><span class="pill"><?= h(is_rtl() ? $plan['name_ar'] : $plan['name_en']) ?></span><?php endif; ?></p></div>
    <div class="pg-tools">
      <?php if ($client['phone']): ?><a class="btn btn-outline" style="background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.25)" href="tel:<?= h($client['phone']) ?>"><?= adm_icon('phone') ?></a>
      <a class="btn btn-outline" style="background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.25)" target="_blank" rel="noopener" href="https://wa.me/<?= h($phoneDigits) ?>"><?= adm_icon('message') ?>WhatsApp</a><?php endif; ?>
      <a class="btn btn-outline" style="background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.25)" href="mailto:<?= h($client['email']) ?>"><?= adm_icon('mail') ?></a>
      <a class="btn btn-primary" href="/admin/crm.php?new=opportunity&entity_type=client&entity_id=<?= $cid ?>"><?= adm_icon('target') ?><?= h(adm('فرصة', 'Opportunity')) ?></a>
      <a class="btn btn-primary" href="/admin/crm.php?new=task&entity_type=client&entity_id=<?= $cid ?>&return=<?= $ret ?>"><?= adm_icon('check') ?><?= h(adm('متابعة', 'Follow-up')) ?></a>
    </div>
  </div>

  <div class="grid g-4" style="margin-bottom:18px">
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic"><?= adm_icon('briefcase') ?></span></div><div class="kpi-val"><?= $openCases ?> <small style="font-size:14px;color:var(--muted)">/ <?= count($cases) ?></small></div><div class="kpi-lbl"><?= h(adm('القضايا (مفتوحة / الكل)', 'Cases (open / all)')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic teal"><?= adm_icon('message') ?></span></div><div class="kpi-val"><?= count($consultations) ?></div><div class="kpi-lbl"><?= h(adm('الاستشارات', 'Consultations')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic green"><?= adm_icon('receipt') ?></span></div><div class="kpi-val"><?= money($paid) ?></div><div class="kpi-lbl"><?= h(adm('المدفوع', 'Paid')) ?> · <b style="color:var(--danger)"><?= money($unpaid) ?></b> <?= h(adm('مستحق', 'due')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic gold"><?= adm_icon('file') ?></span></div><div class="kpi-val"><?= count($documents) ?></div><div class="kpi-lbl"><?= h(adm('المستندات', 'Documents')) ?> · <?= count($contracts) ?> <?= h(adm('عقود', 'contracts')) ?></div></div>
  </div>

  <div data-tabs class="tabs">
    <button data-tab="overview"><?= h(adm('نظرة عامة', 'Overview')) ?></button>
    <button data-tab="cases"><?= h(adm('القضايا', 'Cases')) ?> <span class="n"><?= count($cases) ?></span></button>
    <button data-tab="consults"><?= h(adm('الاستشارات', 'Consultations')) ?> <span class="n"><?= count($consultations) ?></span></button>
    <button data-tab="docs"><?= h(adm('المستندات والعقود', 'Documents & contracts')) ?> <span class="n"><?= count($documents) ?></span></button>
    <button data-tab="billing"><?= h(adm('الفواتير', 'Invoices')) ?> <span class="n"><?= count($invoices) ?></span></button>
    <button data-tab="crm">CRM <span class="n"><?= count($opps) + count($tasks) ?></span></button>
    <button data-tab="notes"><?= h(adm('الملاحظات', 'Notes')) ?> <span class="n"><?= count($notes) ?></span></button>
  </div>
  <div>
    <div class="tab-pane" id="overview"><div class="grid g-21">
      <div class="card"><div class="card-h"><h3><?= h(adm('الخط الزمني للنشاط', 'Activity timeline')) ?></h3></div>
        <ul class="tl"><?php foreach ($tl as $e): ?><li><span class="dot"><?= adm_icon($e['i']) ?></span><b><?= h($e['b']) ?></b><small><?= h(date('Y-m-d H:i', strtotime($e['t']))) ?><?= $e['s'] ? ' · ' . h($e['s']) : '' ?></small></li><?php endforeach; ?></ul></div>
      <div>
        <div class="card"><div class="card-h"><h3><?= h(adm('بيانات الحساب', 'Account details')) ?></h3></div>
          <ul class="rowlist"><li><div class="grow"><small><?= h(adm('البريد', 'Email')) ?></small><b><?= h($client['email']) ?></b></div><?= $client['email_verified'] ? '<span class="pill green">✔</span>' : '<span class="pill amber">!</span>' ?></li>
            <li><div class="grow"><small><?= h(adm('الجوال', 'Phone')) ?></small><b><?= display_phone($client['phone']) ?></b></div></li>
            <li><div class="grow"><small><?= h(adm('اللغة المفضلة', 'Preferred language')) ?></small><b><?= h(strtoupper($client['preferred_lang'])) ?></b></div></li>
            <?php if (!empty($client['source_page'])): ?><li><div class="grow"><small><?= h(adm('صفحة الدخول', 'Landing page')) ?></small><b style="overflow-wrap:anywhere"><?= h($client['source_page']) ?></b></div></li><?php endif; ?></ul>
          <form method="post" action="/admin/users.php" style="margin-top:12px"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="user_id" value="<?= $cid ?>">
            <button class="btn btn-outline btn-sm <?= $client['status'] === 'active' ? 'btn-danger' : '' ?>" type="submit"><?= h($client['status'] === 'active' ? adm('إيقاف الحساب', 'Suspend account') : adm('تفعيل الحساب', 'Activate account')) ?></button></form></div>
        <div class="card"><div class="card-h"><h3><?= h(adm('المتابعات القادمة', 'Upcoming follow-ups')) ?></h3></div>
          <ul class="rowlist"><?php foreach (array_filter($tasks, fn($t) => $t['status'] === 'open') as $t): ?><li><div class="grow"><b><?= h($t['title']) ?></b><small><?= $t['due_at'] ? h(date('Y-m-d H:i', strtotime($t['due_at']))) : '—' ?></small></div></li><?php endforeach; ?>
            <?php if (!array_filter($tasks, fn($t) => $t['status'] === 'open')): ?><li class="empty"><?= h(adm('لا توجد متابعات', 'No follow-ups')) ?></li><?php endif; ?></ul></div>
      </div></div></div>

    <div class="tab-pane" id="cases"><div class="table-wrap"><table class="admin-table"><thead><tr><th><?= h(adm('القضية', 'Case')) ?></th><th><?= h(adm('المحامي', 'Lawyer')) ?></th><th><?= h(adm('احتمالية الفوز', 'Win probability')) ?></th><th><?= h(adm('الحالة', 'Status')) ?></th><th><?= h(adm('آخر تحديث', 'Updated')) ?></th></tr></thead><tbody>
      <?php foreach ($cases as $c): ?><tr><td><b><?= h($c['title']) ?></b><br><small style="color:var(--muted)"><?= h($c['case_type']) ?></small></td><td><?= h($c['lawyer_name']) ?></td><td><?= $c['win_probability'] !== null ? '<div class="bar-row" style="grid-template-columns:1fr 40px;margin:0"><div class="tr"><i style="width:' . (int)$c['win_probability'] . '%"></i></div><b>' . (int)$c['win_probability'] . '%</b></div>' : '—' ?></td><td><span class="status-pill status-<?= str_starts_with($c['status'], 'closed') ? 'confirmed' : 'pending' ?>"><?= h($caseLbl[$c['status']] ?? $c['status']) ?></span></td><td><?= h(date('Y-m-d', strtotime($c['updated_at']))) ?></td></tr><?php endforeach; ?>
      <?php if (!$cases): ?><tr><td colspan="5" class="empty"><?= h(adm('لا توجد قضايا', 'No cases')) ?></td></tr><?php endif; ?></tbody></table></div></div>

    <div class="tab-pane" id="consults"><div class="table-wrap"><table class="admin-table"><thead><tr><th><?= h(adm('المحامي', 'Lawyer')) ?></th><th><?= h(adm('الموعد', 'Scheduled')) ?></th><th><?= h(adm('النوع', 'Type')) ?></th><th><?= h(adm('الموضوع', 'Topic')) ?></th><th><?= h(adm('الحالة', 'Status')) ?></th></tr></thead><tbody>
      <?php foreach ($consultations as $c): ?><tr><td><?= h($c['lawyer_name']) ?></td><td><?= h(date('Y-m-d H:i', strtotime($c['scheduled_at']))) ?></td><td><?= h($c['consultation_type']) ?></td><td><?= h($c['topic'] ?: '—') ?></td><td><span class="status-pill status-<?= h($c['status']) ?>"><?= h($c['status']) ?></span></td></tr><?php endforeach; ?>
      <?php if (!$consultations): ?><tr><td colspan="5" class="empty"><?= h(adm('لا توجد استشارات', 'No consultations')) ?></td></tr><?php endif; ?></tbody></table></div></div>

    <div class="tab-pane" id="docs"><div class="table-wrap"><table class="admin-table"><thead><tr><th><?= h(adm('الملف', 'File')) ?></th><th><?= h(adm('النوع', 'Type')) ?></th><th><?= h(adm('التاريخ', 'Date')) ?></th><th><?= h(adm('الحالة', 'Status')) ?></th></tr></thead><tbody>
      <?php foreach ($documents as $d): ?><tr><td><b><?= h($d['original_name']) ?></b></td><td><span class="pill <?= ($d['document_type'] ?? '') === 'contract' ? 'gold' : 'gray' ?>"><?= h(($d['document_type'] ?? '') === 'contract' ? adm('عقد', 'Contract') : adm('مستند', 'Document')) ?></span></td><td><?= h(date('Y-m-d', strtotime($d['created_at']))) ?></td><td><span class="status-pill status-<?= h($d['status']) ?>"><?= h($d['status']) ?></span></td></tr><?php endforeach; ?>
      <?php if (!$documents): ?><tr><td colspan="4" class="empty"><?= h(adm('لا توجد مستندات', 'No documents')) ?></td></tr><?php endif; ?></tbody></table></div></div>

    <div class="tab-pane" id="billing"><div class="table-wrap"><table class="admin-table"><thead><tr><th><?= h(adm('الوصف', 'Description')) ?></th><th><?= h(adm('المحامي', 'Lawyer')) ?></th><th><?= h(adm('المبلغ', 'Amount')) ?></th><th><?= h(adm('الاستحقاق', 'Due')) ?></th><th><?= h(adm('الحالة', 'Status')) ?></th></tr></thead><tbody>
      <?php foreach ($invoices as $i): ?><tr><td><?= h($i['description']) ?></td><td><?= h($i['lawyer_name'] ?: '—') ?></td><td><b><?= money($i['amount']) ?></b></td><td><?= h($i['due_date'] ?: '—') ?></td><td><span class="status-pill status-<?= h($i['status']) ?>"><?= h($i['status']) ?></span></td></tr><?php endforeach; ?>
      <?php if (!$invoices): ?><tr><td colspan="5" class="empty"><?= h(adm('لا توجد فواتير', 'No invoices')) ?></td></tr><?php endif; ?></tbody></table></div></div>

    <div class="tab-pane" id="crm"><div class="grid g-2">
      <div class="card"><div class="card-h"><h3><?= h(adm('الفرص', 'Opportunities')) ?></h3><b><?= money($oppValue) ?></b></div>
        <ul class="rowlist"><?php foreach ($opps as $o): ?><li><div class="grow"><b><?= h($o['title']) ?></b><small><?= money($o['value']) ?></small></div><span class="pill blue"><?= h($stageLbl[$o['stage']] ?? $o['stage']) ?></span></li><?php endforeach; ?><?php if (!$opps): ?><li class="empty"><?= h(adm('لا توجد فرص', 'No opportunities')) ?></li><?php endif; ?></ul></div>
      <div class="card"><div class="card-h"><h3><?= h(adm('تسجيل نشاط', 'Log activity')) ?></h3></div>
        <form method="post" action="/admin/crm.php"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add_activity"><input type="hidden" name="entity_type" value="client"><input type="hidden" name="entity_id" value="<?= $cid ?>"><input type="hidden" name="return" value="/admin/client-detail.php?id=<?= $cid ?>#crm">
          <div class="field"><select name="activity_type"><option value="call"><?= h(adm('اتصال', 'Call')) ?></option><option value="email"><?= h(adm('بريد', 'Email')) ?></option><option value="meeting"><?= h(adm('اجتماع', 'Meeting')) ?></option><option value="whatsapp">WhatsApp</option><option value="note"><?= h(adm('ملاحظة', 'Note')) ?></option></select></div>
          <div class="field"><textarea name="summary" rows="2" required maxlength="500" placeholder="<?= h(adm('ماذا حدث؟', 'What happened?')) ?>"></textarea></div><button class="btn btn-primary btn-sm" type="submit"><?= h(adm('حفظ النشاط', 'Save activity')) ?></button></form>
        <ul class="tl" style="margin-top:16px"><?php foreach ($crmActs as $a): ?><li><span class="dot"><?= adm_icon('phone') ?></span><b><?= h($a['activity_type']) ?></b><small><?= h($a['summary']) ?> · <?= h(date('m-d H:i', strtotime($a['created_at']))) ?></small></li><?php endforeach; ?></ul></div></div></div>

    <div class="tab-pane" id="notes"><div class="card"><div class="card-h"><h3><?= h(adm('ملاحظات داخلية (لا يراها العميل)', 'Internal notes (not visible to the client)')) ?></h3></div>
      <form method="post" style="margin-bottom:16px"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add_note"><div class="field"><textarea name="note" rows="2" required placeholder="<?= h(adm('أضف ملاحظة عن هذا العميل…', 'Add a note about this client…')) ?>"></textarea></div><button class="btn btn-primary btn-sm" type="submit"><?= h(adm('إضافة ملاحظة', 'Add note')) ?></button></form>
      <?php foreach ($notes as $n): ?><div class="card" style="background:var(--surface-2);margin-bottom:10px"><p style="margin:0"><?= nl2br(h($n['note'])) ?></p><small style="color:var(--muted)"><?= h($n['admin_name'] ?? adm('الإدارة', 'Admin')) ?> — <?= h(date('Y-m-d H:i', strtotime($n['created_at']))) ?></small></div><?php endforeach; ?>
      <?php if (!$notes): ?><p class="empty"><?= h(adm('لا توجد ملاحظات بعد.', 'No notes yet.')) ?></p><?php endif; ?></div></div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
