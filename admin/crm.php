<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/phone.php';

$adminId = (int)$_SESSION['admin_id'];
$STAGES = ['new' => ['#64748b', 10], 'qualified' => ['#2547b8', 30], 'proposal' => ['#c39a3e', 55], 'negotiation' => ['#0e7490', 75], 'won' => ['#12805c', 100], 'lost' => ['#c2413a', 0]];
$LEAD_ST = ['new' => '#c39a3e', 'contacted' => '#2547b8', 'closed' => '#12805c'];
$ENT = ['lead', 'client', 'company', 'opportunity'];
$clean = fn($k, $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max, 'UTF-8');
$tablesReady = true;
try { $pdo->query('SELECT 1 FROM crm_opportunities LIMIT 1'); $pdo->query('SELECT 1 FROM crm_tasks LIMIT 1'); } catch (Throwable $e) { $tablesReady = false; }

function crm_json(array $d): void { header('Content-Type: application/json; charset=UTF-8'); echo json_encode($d); exit; }
function crm_activity(PDO $pdo, string $type, int $id, string $kind, string $summary, int $adminId): void {
    $pdo->prepare('INSERT INTO crm_activities (entity_type,entity_id,activity_type,summary,admin_id) VALUES (?,?,?,?,?)')->execute([$type, $id, $kind, mb_substr($summary, 0, 500, 'UTF-8'), $adminId]);
}

if ($tablesReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $back = '/admin/crm.php';
    if ($action === 'move') { // AJAX: drag & drop opportunity between stages
        $id = (int)($_POST['id'] ?? 0); $stage = $_POST['stage'] ?? '';
        if (!$id || !isset($STAGES[$stage])) crm_json(['ok' => false]);
        $old = $pdo->prepare('SELECT stage,title FROM crm_opportunities WHERE id=?'); $old->execute([$id]); $o = $old->fetch();
        if (!$o) crm_json(['ok' => false]);
        $pdo->prepare('UPDATE crm_opportunities SET stage=?, probability=? WHERE id=?')->execute([$stage, $STAGES[$stage][1], $id]);
        crm_activity($pdo, 'opportunity', $id, 'stage_change', $o['stage'] . ' → ' . $stage, $adminId);
        log_action($pdo, 'نقل فرصة CRM: ' . $o['title'] . ' إلى ' . $stage, 'opportunity', $id);
        crm_json(['ok' => true]);
    }
    if ($action === 'move_lead') { // AJAX: lead status board
        $id = (int)($_POST['id'] ?? 0); $st = $_POST['stage'] ?? '';
        if (!$id || !isset($LEAD_ST[$st])) crm_json(['ok' => false]);
        $pdo->prepare('UPDATE contact_leads SET status=? WHERE id=?')->execute([$st, $id]);
        crm_activity($pdo, 'lead', $id, 'stage_change', '→ ' . $st, $adminId); log_action($pdo, 'تغيير حالة طلب وارد إلى ' . $st, 'lead', $id);
        crm_json(['ok' => true]);
    }
    if ($action === 'save_opportunity') {
        $id = (int)($_POST['id'] ?? 0); $title = $clean('title', 190); $stage = isset($STAGES[$_POST['stage'] ?? '']) ? $_POST['stage'] : 'new';
        $value = max(0, (float)($_POST['value'] ?? 0)); $close = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['expected_close'] ?? '') ? $_POST['expected_close'] : null;
        $uid = (int)($_POST['user_id'] ?? 0) ?: null; $cid = (int)($_POST['company_id'] ?? 0) ?: null; $lid = (int)($_POST['lead_id'] ?? 0) ?: null;
        if ($title !== '') {
            if ($id) { $pdo->prepare('UPDATE crm_opportunities SET title=?,stage=?,value=?,probability=?,expected_close=?,user_id=?,company_id=? WHERE id=?')->execute([$title, $stage, $value, $STAGES[$stage][1], $close, $uid, $cid, $id]); }
            else { $pdo->prepare('INSERT INTO crm_opportunities (title,stage,value,probability,expected_close,user_id,company_id,lead_id,owner_admin_id) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$title, $stage, $value, $STAGES[$stage][1], $close, $uid, $cid, $lid, $adminId]); $id = (int)$pdo->lastInsertId(); crm_activity($pdo, 'opportunity', $id, 'note', 'Created', $adminId); }
            log_action($pdo, 'حفظ فرصة CRM: ' . $title, 'opportunity', $id);
        }
        header('Location: ' . $back . '#opportunities'); exit;
    }
    if ($action === 'delete_opportunity') { $id = (int)($_POST['id'] ?? 0); $pdo->prepare('DELETE FROM crm_opportunities WHERE id=?')->execute([$id]); log_action($pdo, 'حذف فرصة CRM', 'opportunity', $id); header('Location: ' . $back . '#opportunities'); exit; }
    if ($action === 'convert_lead') {
        $lid = (int)($_POST['lead_id'] ?? 0); $l = $pdo->prepare('SELECT * FROM contact_leads WHERE id=?'); $l->execute([$lid]); $lead = $l->fetch();
        if ($lead) {
            $pdo->prepare('INSERT INTO crm_opportunities (title,stage,probability,lead_id,owner_admin_id) VALUES (?,?,?,?,?)')->execute([mb_substr($lead['full_name'], 0, 190, 'UTF-8'), 'new', 10, $lid, $adminId]);
            $oid = (int)$pdo->lastInsertId(); $pdo->prepare("UPDATE contact_leads SET status='contacted' WHERE id=? AND status='new'")->execute([$lid]);
            crm_activity($pdo, 'lead', $lid, 'note', 'Converted to opportunity #' . $oid, $adminId); log_action($pdo, 'تحويل طلب وارد إلى فرصة', 'lead', $lid);
        }
        header('Location: ' . $back . '#opportunities'); exit;
    }
    if ($action === 'save_company') {
        $name = $clean('name', 190);
        if ($name !== '') { $pdo->prepare('INSERT INTO crm_companies (name,industry,website,phone,email,address,notes,created_by_admin_id) VALUES (?,?,?,?,?,?,?,?)')->execute([$name, $clean('industry', 120), $clean('website', 190), $clean('phone', 40), $clean('email', 150), $clean('address'), $clean('notes', 2000), $adminId]); log_action($pdo, 'إضافة شركة CRM: ' . $name, 'company', (int)$pdo->lastInsertId()); }
        header('Location: ' . $back . '#companies'); exit;
    }
    if ($action === 'add_activity') {
        $et = in_array($_POST['entity_type'] ?? '', $ENT, true) ? $_POST['entity_type'] : ''; $eid = (int)($_POST['entity_id'] ?? 0);
        $kind = in_array($_POST['activity_type'] ?? '', ['call', 'email', 'meeting', 'whatsapp', 'note'], true) ? $_POST['activity_type'] : 'note'; $sum = $clean('summary', 500);
        if ($et && $eid && $sum !== '') crm_activity($pdo, $et, $eid, $kind, $sum, $adminId);
        $ret = (string)($_POST['return'] ?? ''); header('Location: ' . (str_starts_with($ret, '/admin/') ? $ret : $back)); exit;
    }
    if ($action === 'save_task') {
        $title = $clean('title'); $type = ($_POST['task_type'] ?? '') === 'follow_up' ? 'follow_up' : 'task';
        $due = preg_match('/^\d{4}-\d{2}-\d{2}(T| )\d{2}:\d{2}$/', $_POST['due_at'] ?? '') ? str_replace('T', ' ', $_POST['due_at']) . ':00' : null;
        $et = in_array($_POST['entity_type'] ?? '', $ENT, true) ? $_POST['entity_type'] : null; $eid = $et ? ((int)($_POST['entity_id'] ?? 0) ?: null) : null;
        if ($title !== '') $pdo->prepare('INSERT INTO crm_tasks (title,task_type,due_at,entity_type,entity_id,assigned_admin_id) VALUES (?,?,?,?,?,?)')->execute([$title, $type, $due, $et, $eid, $adminId]);
        header('Location: ' . (str_starts_with($_POST['return'] ?? '', '/admin/') ? $_POST['return'] : $back . '#tasks')); exit;
    }
    if ($action === 'toggle_task') {
        $id = (int)($_POST['id'] ?? 0); $pdo->prepare("UPDATE crm_tasks SET status=IF(status='open','done','open'), completed_at=IF(status='open',NOW(),NULL) WHERE id=?")->execute([$id]);
        header('Location: ' . $back . '#tasks'); exit;
    }
}

$activeAdmin = 'crm';
require_once __DIR__ . '/includes/admin_header.php';

if (!$tablesReady) {
    echo '<div class="app-content"><div class="card"><h3>' . h(adm('وحدة CRM تحتاج ترقية قاعدة البيانات', 'CRM needs a database upgrade')) . '</h3><p>' . h(adm('شغّل الملف database/upgrade_v40.sql على قاعدة البيانات (phpMyAdmin ← Import) ثم أعد تحميل الصفحة. لا يتم حذف أو تعديل أي بيانات حالية.', 'Run database/upgrade_v40.sql on your database (phpMyAdmin → Import) and reload. No existing data is modified or removed.')) . '</p></div></div>';
    require __DIR__ . '/includes/admin_footer.php'; exit;
}

$stageLbl = ['new' => adm('جديدة', 'New'), 'qualified' => adm('مؤهَّلة', 'Qualified'), 'proposal' => adm('عرض سعر', 'Proposal'), 'negotiation' => adm('تفاوض', 'Negotiation'), 'won' => adm('مكسوبة', 'Won'), 'lost' => adm('خاسرة', 'Lost')];
$leadLbl = ['new' => adm('جديد', 'New'), 'contacted' => adm('تم التواصل', 'Contacted'), 'closed' => adm('مغلق', 'Closed')];
$actLbl = ['call' => adm('اتصال', 'Call'), 'email' => adm('بريد', 'Email'), 'meeting' => adm('اجتماع', 'Meeting'), 'whatsapp' => 'WhatsApp', 'note' => adm('ملاحظة', 'Note'), 'stage_change' => adm('تغيير مرحلة', 'Stage change')];
$actIcon = ['call' => 'phone', 'email' => 'mail', 'meeting' => 'calendar', 'whatsapp' => 'message', 'note' => 'edit', 'stage_change' => 'target'];

$opps = $pdo->query("SELECT o.*, u.full_name uname, c.name cname FROM crm_opportunities o LEFT JOIN users u ON u.id=o.user_id LEFT JOIN crm_companies c ON c.id=o.company_id ORDER BY o.updated_at DESC LIMIT 500")->fetchAll();
$byStage = array_fill_keys(array_keys($STAGES), []); foreach ($opps as $o) $byStage[$o['stage']][] = $o;
$leads = $pdo->query('SELECT * FROM contact_leads ORDER BY id DESC LIMIT 300')->fetchAll();
$leadBy = array_fill_keys(array_keys($LEAD_ST), []); foreach ($leads as $l) $leadBy[$l['status']][] = $l;
$users = $pdo->query('SELECT id,full_name,email,phone,account_type,company_name,status,created_at FROM users ORDER BY id DESC LIMIT 500')->fetchAll();
$companies = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM crm_opportunities o WHERE o.company_id=c.id) opp_n FROM crm_companies c ORDER BY c.id DESC LIMIT 300')->fetchAll();
$clientCompanies = $pdo->query("SELECT company_name, COUNT(*) n FROM users WHERE account_type='company' AND company_name IS NOT NULL AND company_name<>'' GROUP BY company_name ORDER BY n DESC LIMIT 100")->fetchAll();
$acts = $pdo->query("SELECT a.*, ad.full_name aname, CASE a.entity_type WHEN 'client' THEN (SELECT full_name FROM users WHERE id=a.entity_id) WHEN 'lead' THEN (SELECT full_name FROM contact_leads WHERE id=a.entity_id) WHEN 'opportunity' THEN (SELECT title FROM crm_opportunities WHERE id=a.entity_id) WHEN 'company' THEN (SELECT name FROM crm_companies WHERE id=a.entity_id) END ename FROM crm_activities a LEFT JOIN admins ad ON ad.id=a.admin_id ORDER BY a.id DESC LIMIT 60")->fetchAll();
$tasks = $pdo->query("SELECT t.*, CASE t.entity_type WHEN 'client' THEN (SELECT full_name FROM users WHERE id=t.entity_id) WHEN 'lead' THEN (SELECT full_name FROM contact_leads WHERE id=t.entity_id) WHEN 'opportunity' THEN (SELECT title FROM crm_opportunities WHERE id=t.entity_id) WHEN 'company' THEN (SELECT name FROM crm_companies WHERE id=t.entity_id) END ename FROM crm_tasks t ORDER BY t.status='done', t.due_at IS NULL, t.due_at LIMIT 200")->fetchAll();

$pipeVal = 0; $wonVal = 0; foreach ($opps as $o) { if (!in_array($o['stage'], ['won', 'lost'], true)) $pipeVal += $o['value']; if ($o['stage'] === 'won') $wonVal += $o['value']; }
$weighted = 0; foreach ($opps as $o) if (!in_array($o['stage'], ['won', 'lost'], true)) $weighted += $o['value'] * $o['probability'] / 100;
$closedN = count($byStage['won']) + count($byStage['lost']); $winRate = $closedN ? round(count($byStage['won']) / $closedN * 100) : 0;
$openTasks = count(array_filter($tasks, fn($t) => $t['status'] === 'open'));
$tok = csrf_token(); $newParam = $_GET['new'] ?? ''; $preET = in_array($_GET['entity_type'] ?? '', $ENT, true) ? $_GET['entity_type'] : ''; $preEI = (int)($_GET['entity_id'] ?? 0);
$csrf = '<input type="hidden" name="csrf_token" value="' . h($tok) . '">';
?>
<div class="app-content">
  <div class="pg-head"><div><h1>CRM</h1><p><?= h(adm('العملاء المحتملون، الفرص، الشركات، الأنشطة والمتابعات في مكان واحد', 'Leads, opportunities, companies, activities and follow-ups in one place')) ?></p></div>
    <div class="pg-tools"><button class="btn btn-outline" onclick="openModal('mTask')"><?= adm_icon('check') ?><?= h(adm('مهمة / متابعة', 'Task / follow-up')) ?></button><button class="btn btn-outline" onclick="openModal('mCompany')"><?= adm_icon('building') ?><?= h(adm('شركة', 'Company')) ?></button><button class="btn btn-primary" onclick="openModal('mOpp')"><?= adm_icon('plus') ?><?= h(adm('فرصة جديدة', 'New opportunity')) ?></button></div></div>

  <div class="grid g-4" style="margin-bottom:18px">
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic gold"><?= adm_icon('target') ?></span></div><div class="kpi-val"><?= money($pipeVal) ?></div><div class="kpi-lbl"><?= h(adm('قيمة الفرص المفتوحة', 'Open pipeline')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic"><?= adm_icon('chart') ?></span></div><div class="kpi-val"><?= money($weighted) ?></div><div class="kpi-lbl"><?= h(adm('القيمة المرجّحة بالاحتمالية', 'Weighted forecast')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic green"><?= adm_icon('check') ?></span></div><div class="kpi-val"><?= $winRate ?>%</div><div class="kpi-lbl"><?= h(adm('معدل الإغلاق بالكسب', 'Win rate')) ?> · <?= money($wonVal) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic teal"><?= adm_icon('clock') ?></span></div><div class="kpi-val"><?= $openTasks ?></div><div class="kpi-lbl"><?= h(adm('مهام ومتابعات مفتوحة', 'Open tasks & follow-ups')) ?></div></div>
  </div>

  <div data-tabs class="tabs">
    <button data-tab="pipeline"><?= adm_icon('target') ?><?= h(adm('Pipeline', 'Pipeline')) ?></button>
    <button data-tab="leadsboard"><?= adm_icon('inbox') ?>Leads <span class="n"><?= count($leads) ?></span></button>
    <button data-tab="opportunities"><?= adm_icon('briefcase') ?><?= h(adm('الفرص', 'Opportunities')) ?> <span class="n"><?= count($opps) ?></span></button>
    <button data-tab="contacts"><?= adm_icon('users') ?>Contacts <span class="n"><?= count($users) ?></span></button>
    <button data-tab="companies"><?= adm_icon('building') ?><?= h(adm('الشركات', 'Companies')) ?> <span class="n"><?= count($companies) + count($clientCompanies) ?></span></button>
    <button data-tab="activities"><?= adm_icon('clock') ?><?= h(adm('الأنشطة', 'Activities')) ?></button>
    <button data-tab="tasks"><?= adm_icon('check') ?><?= h(adm('المهام والمتابعات', 'Tasks & follow-ups')) ?> <span class="n"><?= $openTasks ?></span></button>
  </div>

  <div>
  <div class="tab-pane" id="pipeline">
    <p class="field-hint" style="margin:-6px 0 12px"><?= h(adm('اسحب البطاقة وأفلتها في العمود المطلوب لتغيير المرحلة (على الجوال استخدم القائمة داخل البطاقة).', 'Drag a card into another column to change its stage (on touch devices use the selector inside the card).')) ?></p>
    <div class="kanban" data-url="/admin/crm.php" data-token="<?= h($tok) ?>" data-ok="<?= h(adm('تم تحديث المرحلة', 'Stage updated')) ?>" data-fail="<?= h(adm('تعذر التحديث', 'Update failed')) ?>">
      <?php foreach ($STAGES as $sk => $meta): ?>
        <div class="kcol" data-stage="<?= $sk ?>"><div class="kcol-h"><div><span class="dotc" style="background:<?= $meta[0] ?>"></span><?= h($stageLbl[$sk]) ?> <span class="count-badge" data-count>0</span><small><span data-sum>0</span> <?= currency_symbol() ?></small></div></div>
          <div class="kbody"><?php foreach ($byStage[$sk] as $o): ?>
            <div class="kcard" draggable="true" data-id="<?= (int)$o['id'] ?>" data-value="<?= h($o['value']) ?>"><b><?= h($o['title']) ?></b><small><?= h($o['uname'] ?: ($o['cname'] ?: '—')) ?></small>
              <div class="meta"><span class="val"><?= money($o['value']) ?></span><span class="pill gray"><?= (int)$o['probability'] ?>%</span></div>
              <?php if ($o['expected_close']): ?><small style="display:block;margin-top:6px"><?= adm_icon('calendar') ?> <?= h($o['expected_close']) ?></small><?php endif; ?>
              <select aria-label="stage"><?php foreach ($STAGES as $k2 => $_): ?><option value="<?= $k2 ?>" <?= $k2 === $sk ? 'selected' : '' ?>><?= h($stageLbl[$k2]) ?></option><?php endforeach; ?></select></div>
          <?php endforeach; ?><div class="k-empty"><?= h(adm('لا توجد فرص', 'No opportunities')) ?></div></div></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="tab-pane" id="leadsboard">
    <div class="kanban" data-url="/admin/crm.php" data-token="<?= h($tok) ?>" data-ok="<?= h(adm('تم تحديث الحالة', 'Status updated')) ?>" data-fail="<?= h(adm('تعذر التحديث', 'Update failed')) ?>" id="leadKanban" data-action="move_lead">
      <?php foreach ($LEAD_ST as $sk => $colr): ?>
        <div class="kcol" data-stage="<?= $sk ?>"><div class="kcol-h"><div><span class="dotc" style="background:<?= $colr ?>"></span><?= h($leadLbl[$sk]) ?> <span class="count-badge" data-count>0</span><small><span data-sum style="display:none">0</span></small></div></div>
          <div class="kbody"><?php foreach ($leadBy[$sk] as $l): ?>
            <div class="kcard" draggable="true" data-id="<?= (int)$l['id'] ?>" data-value="0"><b><?= h($l['full_name']) ?></b><small><?= h($l['email'] ?: display_phone($l['phone'])) ?></small>
              <small style="display:block;margin-top:6px"><?= h(mb_substr((string)$l['message'], 0, 70, 'UTF-8')) ?></small>
              <div class="meta"><span class="pill gray"><?= h($l['lead_type']) ?></span>
                <form method="post" style="margin:0"><?= $csrf ?><input type="hidden" name="action" value="convert_lead"><input type="hidden" name="lead_id" value="<?= (int)$l['id'] ?>"><button class="btn btn-outline btn-sm" type="submit"><?= h(adm('تحويل لفرصة', 'Convert')) ?></button></form></div>
              <select aria-label="status"><?php foreach ($LEAD_ST as $k2 => $_): ?><option value="<?= $k2 ?>" <?= $k2 === $sk ? 'selected' : '' ?>><?= h($leadLbl[$k2]) ?></option><?php endforeach; ?></select></div>
          <?php endforeach; ?><div class="k-empty"><?= h(adm('لا توجد طلبات', 'No leads')) ?></div></div></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="tab-pane" id="opportunities">
    <div class="tbl-tools"><input type="search" placeholder="<?= h(adm('بحث في الفرص…', 'Search opportunities…')) ?>" data-filter="#oppTable"><div class="chips"><a href="#" class="chip on" data-status-filter="#oppTable" data-value=""><?= h(adm('الكل', 'All')) ?></a><?php foreach ($stageLbl as $k => $lb): ?><a href="#" class="chip" data-status-filter="#oppTable" data-value="<?= $k ?>"><?= h($lb) ?></a><?php endforeach; ?></div></div>
    <div class="table-wrap"><table class="admin-table" id="oppTable"><thead><tr><th><?= h(adm('الفرصة', 'Opportunity')) ?></th><th><?= h(adm('العميل / الشركة', 'Client / Company')) ?></th><th><?= h(adm('القيمة', 'Value')) ?></th><th><?= h(adm('المرحلة', 'Stage')) ?></th><th><?= h(adm('الإغلاق المتوقع', 'Expected close')) ?></th><th></th></tr></thead><tbody>
      <?php foreach ($opps as $o): ?><tr data-status="<?= h($o['stage']) ?>"><td><b><?= h($o['title']) ?></b></td><td><?= $o['user_id'] ? '<a href="/admin/client-detail.php?id=' . (int)$o['user_id'] . '">' . h($o['uname']) . '</a>' : h($o['cname'] ?: '—') ?></td><td><?= money($o['value']) ?></td><td><span class="pill blue"><?= h($stageLbl[$o['stage']]) ?></span></td><td><?= h($o['expected_close'] ?: '—') ?></td>
        <td><form method="post" onsubmit="return confirm('<?= h(adm('حذف هذه الفرصة؟', 'Delete this opportunity?')) ?>')"><?= $csrf ?><input type="hidden" name="action" value="delete_opportunity"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="icon-btn danger" type="submit"><?= h(adm('حذف', 'Delete')) ?></button></form></td></tr><?php endforeach; ?>
      <?php if (!$opps): ?><tr><td colspan="6" class="empty"><?= h(adm('لا توجد فرص بعد — أنشئ أول فرصة أو حوّل طلباً وارداً.', 'No opportunities yet — create one or convert an inbound lead.')) ?></td></tr><?php endif; ?></tbody></table></div>
  </div>

  <div class="tab-pane" id="contacts">
    <div class="tbl-tools"><input type="search" placeholder="<?= h(adm('بحث بالاسم أو البريد أو الجوال…', 'Search name, email or phone…')) ?>" data-filter="#conTable"><a class="btn btn-outline btn-sm" href="/admin/users.php"><?= h(adm('إدارة العملاء', 'Manage clients')) ?></a></div>
    <div class="table-wrap"><table class="admin-table" id="conTable"><thead><tr><th><?= h(adm('العميل', 'Client')) ?></th><th><?= h(adm('الجوال', 'Phone')) ?></th><th><?= h(adm('النوع', 'Type')) ?></th><th><?= h(adm('الحالة', 'Status')) ?></th><th></th></tr></thead><tbody>
      <?php foreach ($users as $u): ?><tr><td><div class="cell-user"><span class="av"><?= h(mb_substr($u['full_name'], 0, 1, 'UTF-8')) ?></span><div><b><?= h($u['full_name']) ?></b><small><?= h($u['email']) ?></small></div></div></td><td><?= display_phone($u['phone']) ?></td><td><?= $u['account_type'] === 'company' ? h($u['company_name'] ?: adm('شركة', 'Company')) : h(adm('فرد', 'Individual')) ?></td><td><span class="status-pill status-<?= h($u['status']) ?>"><?= h($u['status']) ?></span></td><td><a class="btn btn-outline btn-sm" href="/admin/client-detail.php?id=<?= (int)$u['id'] ?>">Client 360</a></td></tr><?php endforeach; ?></tbody></table></div>
  </div>

  <div class="tab-pane" id="companies">
    <div class="grid g-2">
      <div><div class="card-h"><h3><?= h(adm('شركات CRM', 'CRM companies')) ?></h3></div><div class="table-wrap"><table class="admin-table"><thead><tr><th><?= h(adm('الشركة', 'Company')) ?></th><th><?= h(adm('المجال', 'Industry')) ?></th><th><?= h(adm('التواصل', 'Contact')) ?></th><th><?= h(adm('الفرص', 'Opps')) ?></th></tr></thead><tbody>
        <?php foreach ($companies as $c): ?><tr><td><b><?= h($c['name']) ?></b><br><small style="color:var(--muted)"><?= h($c['website']) ?></small></td><td><?= h($c['industry'] ?: '—') ?></td><td><?= h($c['email'] ?: display_phone($c['phone'])) ?></td><td><span class="pill blue"><?= (int)$c['opp_n'] ?></span></td></tr><?php endforeach; ?>
        <?php if (!$companies): ?><tr><td colspan="4" class="empty"><?= h(adm('لم تُضف شركات بعد', 'No companies added yet')) ?></td></tr><?php endif; ?></tbody></table></div></div>
      <div><div class="card-h"><h3><?= h(adm('شركات من حسابات العملاء', 'Companies from client accounts')) ?></h3></div><div class="table-wrap"><table class="admin-table"><thead><tr><th><?= h(adm('الشركة', 'Company')) ?></th><th><?= h(adm('حسابات', 'Accounts')) ?></th></tr></thead><tbody>
        <?php foreach ($clientCompanies as $c): ?><tr><td><?= h($c['company_name']) ?></td><td><span class="pill gray"><?= (int)$c['n'] ?></span></td></tr><?php endforeach; ?>
        <?php if (!$clientCompanies): ?><tr><td colspan="2" class="empty">—</td></tr><?php endif; ?></tbody></table></div></div>
    </div>
  </div>

  <div class="tab-pane" id="activities">
    <div class="card"><div class="card-h"><h3><?= h(adm('سجل الأنشطة', 'Activity log')) ?></h3><button class="btn btn-outline btn-sm" onclick="openModal('mAct')"><?= adm_icon('plus') ?><?= h(adm('تسجيل نشاط', 'Log activity')) ?></button></div>
      <ul class="tl"><?php foreach ($acts as $a): ?><li><span class="dot"><?= adm_icon($actIcon[$a['activity_type']] ?? 'edit') ?></span><b><?= h($actLbl[$a['activity_type']] ?? $a['activity_type']) ?> · <?= h($a['ename'] ?: $a['entity_type'] . ' #' . $a['entity_id']) ?></b><span><?= h($a['summary']) ?></span><br><small><?= h($a['aname'] ?: 'system') ?> · <?= h(date('Y-m-d H:i', strtotime($a['created_at']))) ?></small></li><?php endforeach; ?>
        <?php if (!$acts): ?><li class="empty" style="padding-inline-start:0"><?= h(adm('لا توجد أنشطة مسجلة بعد', 'No activities yet')) ?></li><?php endif; ?></ul></div>
  </div>

  <div class="tab-pane" id="tasks">
    <div class="table-wrap"><table class="admin-table"><thead><tr><th></th><th><?= h(adm('المهمة', 'Task')) ?></th><th><?= h(adm('النوع', 'Type')) ?></th><th><?= h(adm('مرتبطة بـ', 'Related to')) ?></th><th><?= h(adm('الاستحقاق', 'Due')) ?></th></tr></thead><tbody>
      <?php foreach ($tasks as $t): $late = $t['status'] === 'open' && $t['due_at'] && strtotime($t['due_at']) < time(); ?><tr style="<?= $t['status'] === 'done' ? 'opacity:.55' : '' ?>">
        <td><form method="post" style="margin:0"><?= $csrf ?><input type="hidden" name="action" value="toggle_task"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="icon-btn" type="submit" title="toggle"><?= $t['status'] === 'done' ? '✔' : '○' ?></button></form></td>
        <td><b style="<?= $t['status'] === 'done' ? 'text-decoration:line-through' : '' ?>"><?= h($t['title']) ?></b></td><td><span class="pill <?= $t['task_type'] === 'follow_up' ? 'gold' : 'blue' ?>"><?= h($t['task_type'] === 'follow_up' ? adm('متابعة', 'Follow-up') : adm('مهمة', 'Task')) ?></span></td>
        <td><?= h($t['ename'] ?: '—') ?></td><td><?= $t['due_at'] ? '<span class="pill ' . ($late ? 'red' : 'gray') . '">' . h(date('Y-m-d H:i', strtotime($t['due_at']))) . '</span>' : '—' ?></td></tr><?php endforeach; ?>
      <?php if (!$tasks): ?><tr><td colspan="5" class="empty"><?= h(adm('لا توجد مهام بعد', 'No tasks yet')) ?></td></tr><?php endif; ?></tbody></table></div>
  </div>
  </div>
</div>

<!-- Modals -->
<div class="modal-backdrop" id="mOpp"><form class="modal-form" method="post"><?= $csrf ?><button type="button" class="modal-close" onclick="closeModal('mOpp')">✕</button><input type="hidden" name="action" value="save_opportunity">
  <h3><?= h(adm('فرصة جديدة', 'New opportunity')) ?></h3>
  <div class="field"><label><?= h(adm('عنوان الفرصة', 'Title')) ?></label><input name="title" required maxlength="190"></div>
  <div class="grid-2"><div class="field"><label><?= h(adm('القيمة المتوقعة', 'Expected value')) ?> (<?= currency_symbol() ?>)</label><input type="number" step="<?= currency_step() ?>" min="0" name="value" value="0"></div>
    <div class="field"><label><?= h(adm('المرحلة', 'Stage')) ?></label><select name="stage"><?php foreach ($stageLbl as $k => $lb): ?><option value="<?= $k ?>"><?= h($lb) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= h(adm('العميل', 'Client')) ?></label><select name="user_id"><option value="">—</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $preET === 'client' && $preEI === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['full_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= h(adm('الشركة', 'Company')) ?></label><select name="company_id"><option value="">—</option><?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= h(adm('الإغلاق المتوقع', 'Expected close')) ?></label><input type="date" name="expected_close"></div></div>
  <button class="btn btn-primary" type="submit"><?= h(adm('حفظ', 'Save')) ?></button></form></div>

<div class="modal-backdrop" id="mCompany"><form class="modal-form" method="post"><?= $csrf ?><button type="button" class="modal-close" onclick="closeModal('mCompany')">✕</button><input type="hidden" name="action" value="save_company">
  <h3><?= h(adm('شركة جديدة', 'New company')) ?></h3>
  <div class="grid-2"><div class="field"><label><?= h(adm('اسم الشركة', 'Name')) ?></label><input name="name" required maxlength="190"></div><div class="field"><label><?= h(adm('المجال', 'Industry')) ?></label><input name="industry" maxlength="120"></div>
    <div class="field"><label>Email</label><input type="email" name="email" maxlength="150"></div><div class="field"><label><?= h(adm('الهاتف', 'Phone')) ?></label><input name="phone" maxlength="40"></div>
    <div class="field"><label><?= h(adm('الموقع', 'Website')) ?></label><input name="website" maxlength="190"></div><div class="field"><label><?= h(adm('العنوان', 'Address')) ?></label><input name="address" maxlength="255"></div></div>
  <div class="field"><label><?= h(adm('ملاحظات', 'Notes')) ?></label><textarea name="notes" rows="2" maxlength="2000"></textarea></div><button class="btn btn-primary" type="submit"><?= h(adm('حفظ', 'Save')) ?></button></form></div>

<div class="modal-backdrop" id="mTask"><form class="modal-form" method="post"><?= $csrf ?><button type="button" class="modal-close" onclick="closeModal('mTask')">✕</button><input type="hidden" name="action" value="save_task">
  <input type="hidden" name="entity_type" value="<?= h($preET) ?>"><input type="hidden" name="entity_id" value="<?= $preEI ?: '' ?>"><input type="hidden" name="return" value="<?= h($_GET['return'] ?? '') ?>">
  <h3><?= h(adm('مهمة / متابعة', 'Task / follow-up')) ?></h3>
  <div class="field"><label><?= h(adm('العنوان', 'Title')) ?></label><input name="title" required maxlength="255"></div>
  <div class="grid-2"><div class="field"><label><?= h(adm('النوع', 'Type')) ?></label><select name="task_type"><option value="task"><?= h(adm('مهمة', 'Task')) ?></option><option value="follow_up"><?= h(adm('متابعة', 'Follow-up')) ?></option></select></div>
    <div class="field"><label><?= h(adm('الاستحقاق', 'Due')) ?></label><input type="datetime-local" name="due_at"></div></div>
  <?php if ($preET): ?><p class="field-hint"><?= h(adm('ستُربط بـ ', 'Linked to ')) . h($preET) ?> #<?= $preEI ?></p><?php endif; ?>
  <button class="btn btn-primary" type="submit"><?= h(adm('حفظ', 'Save')) ?></button></form></div>

<div class="modal-backdrop" id="mAct"><form class="modal-form" method="post"><?= $csrf ?><button type="button" class="modal-close" onclick="closeModal('mAct')">✕</button><input type="hidden" name="action" value="add_activity">
  <h3><?= h(adm('تسجيل نشاط', 'Log activity')) ?></h3>
  <div class="grid-2"><div class="field"><label><?= h(adm('مرتبط بـ', 'Related to')) ?></label><select name="entity_type"><option value="client"><?= h(adm('عميل', 'Client')) ?></option><option value="lead">Lead</option><option value="opportunity"><?= h(adm('فرصة', 'Opportunity')) ?></option><option value="company"><?= h(adm('شركة', 'Company')) ?></option></select></div>
    <div class="field"><label>ID</label><input type="number" name="entity_id" min="1" required></div>
    <div class="field"><label><?= h(adm('النوع', 'Type')) ?></label><select name="activity_type"><?php foreach (['call', 'email', 'meeting', 'whatsapp', 'note'] as $k): ?><option value="<?= $k ?>"><?= h($actLbl[$k]) ?></option><?php endforeach; ?></select></div></div>
  <div class="field"><label><?= h(adm('الملخص', 'Summary')) ?></label><textarea name="summary" rows="3" required maxlength="500"></textarea></div><button class="btn btn-primary" type="submit"><?= h(adm('حفظ', 'Save')) ?></button></form></div>

<script>(function(){var n=<?= json_encode($newParam) ?>,m={opportunity:'mOpp',task:'mTask',company:'mCompany',activity:'mAct'};if(m[n])openModal(m[n]);})();</script>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
