<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/phone.php';
require_once __DIR__ . '/../includes/audit_log.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_status') {
    $allowed = ['pending', 'confirmed', 'completed', 'cancelled'];
    $status = $_POST['status'] ?? ''; $id = (int)($_POST['consultation_id'] ?? 0);
    if (in_array($status, $allowed, true) && $id > 0) {
        $pdo->prepare('UPDATE consultations SET status=? WHERE id=?')->execute([$status, $id]);
        log_action($pdo, 'تغيير حالة استشارة إلى ' . $status, 'consultation', $id);
    }
    header('Location: /admin/consultations.php'); exit;
}

$activeAdmin = 'consultations';
require_once __DIR__ . '/includes/admin_header.php';

$rows = $pdo->query('SELECT c.*, u.full_name AS user_name, u.phone AS user_phone, u.id AS user_id, l.full_name AS lawyer_name,
        nct.name_ar AS internal_type, ncat.name_ar AS internal_category
    FROM consultations c JOIN users u ON u.id=c.user_id JOIN lawyers l ON l.id=c.lawyer_id
    LEFT JOIN najiz_case_types nct ON nct.id=c.najiz_case_type_id
    LEFT JOIN najiz_subcategories nsub ON nsub.id=nct.subcategory_id
    LEFT JOIN najiz_categories ncat ON ncat.id=nsub.category_id
    ORDER BY c.scheduled_at DESC')->fetchAll();
$statusLbl = ['pending' => adm('بانتظار التأكيد', 'Pending'), 'confirmed' => adm('مؤكَّدة', 'Confirmed'), 'completed' => adm('مكتملة', 'Completed'), 'cancelled' => adm('ملغاة', 'Cancelled')];
$typeLbl = ['video' => adm('فيديو', 'Video'), 'voice' => adm('صوت', 'Voice'), 'chat' => adm('محادثة', 'Chat'), 'in_person' => adm('حضوري', 'In person')];
$csrf = '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
$n = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0]; foreach ($rows as $r) if (isset($n[$r['status']])) $n[$r['status']]++;
?>
<div class="app-content">
  <div class="pg-head"><div><h1><?= h(adm('الاستشارات', 'Consultations')) ?></h1><p><?= h(adm('كل حجوزات الاستشارات بين العملاء والمحامين', 'All consultation bookings between clients and lawyers')) ?></p></div>
    <div class="pg-tools"><a class="btn btn-outline" href="/admin/calendar.php"><?= adm_icon('calendar') ?><?= h(adm('التقويم', 'Calendar')) ?></a></div></div>

  <div class="grid g-4" style="margin-bottom:18px">
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic gold"><?= adm_icon('clock') ?></span></div><div class="kpi-val"><?= $n['pending'] ?></div><div class="kpi-lbl"><?= h($statusLbl['pending']) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic"><?= adm_icon('check') ?></span></div><div class="kpi-val"><?= $n['confirmed'] ?></div><div class="kpi-lbl"><?= h($statusLbl['confirmed']) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic green"><?= adm_icon('message') ?></span></div><div class="kpi-val"><?= $n['completed'] ?></div><div class="kpi-lbl"><?= h($statusLbl['completed']) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic red"><?= adm_icon('shield') ?></span></div><div class="kpi-val"><?= $n['cancelled'] ?></div><div class="kpi-lbl"><?= h($statusLbl['cancelled']) ?></div></div>
  </div>

  <div class="tbl-tools"><input type="search" placeholder="<?= h(adm('بحث بالعميل أو المحامي…', 'Search client or lawyer…')) ?>" data-filter="#consTable">
    <div class="chips"><a href="#" class="chip on" data-status-filter="#consTable" data-value=""><?= h(adm('الكل', 'All')) ?></a><?php foreach ($statusLbl as $k => $lb): ?><a href="#" class="chip" data-status-filter="#consTable" data-value="<?= $k ?>"><?= h($lb) ?></a><?php endforeach; ?></div></div>
  <div class="table-wrap"><table class="admin-table" id="consTable">
    <thead><tr><th><?= h(adm('العميل', 'Client')) ?></th><th><?= h(adm('المحامي', 'Lawyer')) ?></th><th><?= h(adm('الموعد', 'Scheduled')) ?></th><th><?= h(adm('النوع', 'Type')) ?></th><th><?= h(adm('التصنيف الداخلي', 'Internal classification')) ?></th><th><?= h(adm('الحالة', 'Status')) ?></th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr data-status="<?= h($r['status']) ?>">
        <td><div class="cell-user"><span class="av"><?= h(mb_substr($r['user_name'], 0, 1, 'UTF-8')) ?></span><div><a href="/admin/client-detail.php?id=<?= (int)$r['user_id'] ?>"><?= h($r['user_name']) ?></a><small><?= display_phone($r['user_phone']) ?></small></div></div></td>
        <td><?= h($r['lawyer_name']) ?></td>
        <td><?= h(date('Y-m-d H:i', strtotime($r['scheduled_at']))) ?></td>
        <td><span class="pill gray"><?= h($typeLbl[$r['consultation_type']] ?? $r['consultation_type']) ?></span></td>
        <td>
          <?php if (!empty($r['internal_type'])): ?>
            <span style="font-weight:700"><?= h($r['internal_type']) ?></span><br><small style="color:var(--text-muted)"><?= h($r['internal_category']) ?></small>
          <?php else: ?>
            <span style="color:var(--text-muted)"><?= h(adm('غير مصنّف', 'Unclassified')) ?></span>
          <?php endif; ?>
        </td>
        <td><span class="status-pill status-<?= h($r['status']) ?>"><?= h($statusLbl[$r['status']] ?? $r['status']) ?></span></td>
        <td>
          <?php foreach (['pending' => 'confirmed', 'confirmed' => 'completed'] as $from => $to): if ($r['status'] === $from): ?><form method="post" style="display:inline"><?= $csrf ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="consultation_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="<?= $to ?>"><button class="icon-btn" type="submit"><?= h($to === 'confirmed' ? adm('تأكيد', 'Confirm') : adm('إتمام', 'Complete')) ?></button></form><?php endif; endforeach; ?>
          <?php if (!in_array($r['status'], ['completed', 'cancelled'], true)): ?><form method="post" style="display:inline" onsubmit="return confirm('<?= h(adm('إلغاء هذه الاستشارة؟', 'Cancel this consultation?')) ?>')"><?= $csrf ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="consultation_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="cancelled"><button class="icon-btn danger" type="submit"><?= h(adm('إلغاء', 'Cancel')) ?></button></form><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="empty"><?= h(adm('لا توجد استشارات بعد', 'No consultations yet')) ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
