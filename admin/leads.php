<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/phone.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/lead_source.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_status') {
    $allowed = ['new', 'contacted', 'closed'];
    $status = $_POST['status'] ?? ''; $id = (int)($_POST['lead_id'] ?? 0);
    if (in_array($status, $allowed, true) && $id > 0) {
        $pdo->prepare('UPDATE contact_leads SET status=? WHERE id=?')->execute([$status, $id]);
        log_action($pdo, 'تغيير حالة طلب وارد إلى ' . $status, 'lead', $id);
    }
    header('Location: /admin/leads.php'); exit;
}

$activeAdmin = 'leads';
require_once __DIR__ . '/includes/admin_header.php';

$leadTypeLabels = ['contact' => adm('تواصل عام', 'General contact'), 'join_lawyer' => adm('انضمام محامٍ', 'Lawyer application'), 'corporate' => adm('باقة مؤسسية', 'Corporate plan')];
$statusLbl = ['new' => adm('جديد', 'New'), 'contacted' => adm('تم التواصل', 'Contacted'), 'closed' => adm('مغلق', 'Closed')];
$rows = $pdo->query('SELECT * FROM contact_leads ORDER BY id DESC')->fetchAll();
$csrf = '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
$n = ['new' => 0, 'contacted' => 0, 'closed' => 0]; foreach ($rows as $r) if (isset($n[$r['status']])) $n[$r['status']]++;
?>
<div class="app-content">
  <div class="pg-head"><div><h1><?= h(adm('الطلبات الواردة', 'Inbound leads')) ?></h1><p><?= h(adm('طلبات التواصل، الانضمام كمحامٍ، والباقات المؤسسية', 'Contact, lawyer-application, and corporate-plan requests')) ?></p></div>
    <div class="pg-tools"><a class="btn btn-primary" href="/admin/crm.php"><?= adm_icon('target') ?><?= h(adm('عرض في CRM', 'Open in CRM')) ?></a></div></div>

  <div class="grid g-3" style="margin-bottom:18px">
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic gold"><?= adm_icon('inbox') ?></span></div><div class="kpi-val"><?= $n['new'] ?></div><div class="kpi-lbl"><?= h($statusLbl['new']) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic"><?= adm_icon('phone') ?></span></div><div class="kpi-val"><?= $n['contacted'] ?></div><div class="kpi-lbl"><?= h($statusLbl['contacted']) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic green"><?= adm_icon('check') ?></span></div><div class="kpi-val"><?= $n['closed'] ?></div><div class="kpi-lbl"><?= h($statusLbl['closed']) ?></div></div>
  </div>

  <div class="tbl-tools"><input type="search" placeholder="<?= h(adm('بحث بالاسم أو البريد…', 'Search name or email…')) ?>" data-filter="#leadTable"></div>
  <div class="table-wrap table-cards"><table class="admin-table" id="leadTable">
    <thead><tr><th><?= h(adm('النوع', 'Type')) ?></th><th><?= h(adm('الاسم', 'Name')) ?></th><th><?= h(adm('البريد', 'Email')) ?></th><th><?= h(adm('الجوال', 'Phone')) ?></th><th><?= h(adm('الرسالة', 'Message')) ?></th><th><?= h(adm('المصدر', 'Source')) ?></th><th><?= h(adm('الحالة', 'Status')) ?></th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $l): ?>
      <tr>
        <td data-label="<?= h(adm('النوع', 'Type')) ?>"><span class="pill blue"><?= h($leadTypeLabels[$l['lead_type']] ?? $l['lead_type']) ?></span></td>
        <td data-label="<?= h(adm('الاسم', 'Name')) ?>"><b><?= h($l['full_name']) ?></b></td>
        <td data-label="<?= h(adm('البريد', 'Email')) ?>"><?= h($l['email']) ?></td>
        <td data-label="<?= h(adm('الجوال', 'Phone')) ?>"><?= display_phone($l['phone']) ?></td>
        <td data-label="<?= h(adm('الرسالة', 'Message')) ?>" style="max-width:240px;white-space:normal"><?= h(mb_substr($l['message'] ?? '', 0, 80, 'UTF-8')) ?></td>
        <td data-label="<?= h(adm('المصدر', 'Source')) ?>"><small style="color:var(--muted)"><?= h(format_lead_source($l['utm_source'] ?? null, $l['utm_medium'] ?? null, $l['utm_campaign'] ?? null)) ?></small></td>
        <td data-label="<?= h(adm('الحالة', 'Status')) ?>"><span class="status-pill status-<?= $l['status'] === 'new' ? 'pending' : 'confirmed' ?>"><?= h($statusLbl[$l['status']] ?? $l['status']) ?></span></td>
        <td data-label="">
          <?php if ($l['status'] !== 'contacted'): ?><form method="post" style="display:inline"><?= $csrf ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="lead_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="status" value="contacted"><button class="icon-btn" type="submit"><?= h(adm('تم التواصل', 'Contacted')) ?></button></form><?php endif; ?>
          <?php if ($l['status'] !== 'closed'): ?><form method="post" style="display:inline"><?= $csrf ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="lead_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="status" value="closed"><button class="icon-btn" type="submit"><?= h(adm('إغلاق', 'Close')) ?></button></form><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="empty"><?= h(adm('لا توجد طلبات بعد', 'No leads yet')) ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
