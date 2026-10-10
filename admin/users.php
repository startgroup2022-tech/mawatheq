<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/phone.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/lead_source.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    $id = (int)($_POST['user_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT status FROM users WHERE id=?'); $stmt->execute([$id]); $cur = $stmt->fetchColumn();
    $new = $cur === 'active' ? 'suspended' : 'active';
    $pdo->prepare('UPDATE users SET status=? WHERE id=?')->execute([$new, $id]);
    log_action($pdo, 'تغيير حالة العميل إلى ' . $new, 'client', $id);
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin/users.php')); exit;
}

$activeAdmin = 'users';
require_once __DIR__ . '/includes/admin_header.php';

$users = $pdo->query('SELECT * FROM users ORDER BY id DESC')->fetchAll();
$active = 0; foreach ($users as $u) if ($u['status'] === 'active') $active++;
$company = 0; foreach ($users as $u) if ($u['account_type'] === 'company') $company++;
$csrf = '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
?>
<div class="app-content">
  <div class="pg-head"><div><h1><?= h(adm('العملاء', 'Clients')) ?></h1><p><?= h(adm('افتح ملف أي عميل لعرض Client 360 الكامل: قضاياه، استشاراته، مستنداته، فواتيره ونشاطه.', "Open a client's file for the full Client 360 view: cases, consultations, documents, invoices and activity.")) ?></p></div></div>

  <div class="grid g-3" style="margin-bottom:18px">
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic"><?= adm_icon('users') ?></span></div><div class="kpi-val"><?= count($users) ?></div><div class="kpi-lbl"><?= h(adm('إجمالي العملاء', 'Total clients')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic green"><?= adm_icon('check') ?></span></div><div class="kpi-val"><?= $active ?></div><div class="kpi-lbl"><?= h(adm('حسابات نشطة', 'Active accounts')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic gold"><?= adm_icon('building') ?></span></div><div class="kpi-val"><?= $company ?></div><div class="kpi-lbl"><?= h(adm('حسابات شركات', 'Company accounts')) ?></div></div>
  </div>

  <div class="tbl-tools"><input type="search" placeholder="<?= h(adm('بحث بالاسم أو البريد أو الجوال…', 'Search name, email or phone…')) ?>" data-filter="#usersTable"></div>
  <div class="table-wrap table-cards"><table class="admin-table" id="usersTable">
    <thead><tr><th><?= h(adm('العميل', 'Client')) ?></th><th><?= h(adm('الجوال', 'Phone')) ?></th><th><?= h(adm('النوع', 'Type')) ?></th><th><?= h(adm('التسجيل', 'Joined')) ?></th><th><?= h(adm('المصدر', 'Source')) ?></th><th><?= h(adm('الحالة', 'Status')) ?></th><th></th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td data-label="<?= h(adm('العميل', 'Client')) ?>"><div class="cell-user"><span class="av"><?= h(mb_substr($u['full_name'], 0, 1, 'UTF-8')) ?></span><div><b><?= h($u['full_name']) ?></b><small><?= h($u['email']) ?></small></div></div></td>
        <td data-label="<?= h(adm('الجوال', 'Phone')) ?>"><?= display_phone($u['phone'] ?? null) ?></td>
        <td data-label="<?= h(adm('النوع', 'Type')) ?>"><?= $u['account_type'] === 'company' ? h(adm('شركة', 'Company')) : h(adm('فرد', 'Individual')) ?></td>
        <td data-label="<?= h(adm('التسجيل', 'Joined')) ?>"><?= h(date('Y-m-d', strtotime($u['created_at']))) ?></td>
        <td data-label="<?= h(adm('المصدر', 'Source')) ?>"><small style="color:var(--muted)"><?= h(format_lead_source($u['utm_source'] ?? null, $u['utm_medium'] ?? null, $u['utm_campaign'] ?? null)) ?></small></td>
        <td data-label="<?= h(adm('الحالة', 'Status')) ?>"><span class="status-pill status-<?= h($u['status']) ?>"><?= h($u['status']) ?></span></td>
        <td data-label="">
          <a class="icon-btn" href="/admin/client-detail.php?id=<?= (int)$u['id'] ?>"><?= h(adm('فتح الملف', 'Open profile')) ?></a>
          <form method="post" style="display:inline"><?= $csrf ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="icon-btn <?= $u['status'] === 'active' ? 'danger' : '' ?>" type="submit"><?= $u['status'] === 'active' ? h(adm('إيقاف', 'Suspend')) : h(adm('تفعيل', 'Activate')) ?></button></form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?><tr><td colspan="7" class="empty"><?= h(adm('لا يوجد عملاء بعد', 'No clients yet')) ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
