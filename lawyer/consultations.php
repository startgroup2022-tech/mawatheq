<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/phone.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/tenant.php';
$lawyerId = $_SESSION['lawyer_id'];
$firmId = tenant_write_firm_id($pdo);

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='change_status') {
    tenant_require($pdo, 'consultations.manage');
    $allowed = ['confirmed','completed','cancelled'];
    $status=$_POST['status'] ?? ''; $id=(int)($_POST['consultation_id'] ?? 0);
    $scope = tenant_owner_where($pdo, 'consultations');
    if (in_array($status, $allowed,true) && $id>0) {
        $upd = $pdo->prepare('UPDATE consultations SET status=? WHERE id=? AND ' . $scope['sql']);
        $upd->execute(array_merge([$status,$id], $scope['params']));
        if ($upd->rowCount()) log_action($pdo,'تغيير حالة استشارة المحامي إلى '.$status,'consultation',$id);
    }
    header('Location: /lawyer/consultations.php'); exit;
}

$activeLawyer = 'consultations';
require_once __DIR__ . '/includes/lawyer_header.php';

$scope = tenant_owner_where($pdo, 'c');
// The internal classification is shown here on purpose: this is the lawyer's own view,
// and the classification is the internal reference that helps the lawyer prepare. It is
// never shown to the customer.
$rows = $pdo->prepare('SELECT c.*, u.full_name AS client_name, u.phone AS client_phone,
                              nct.name_ar AS internal_type, nsub.name_ar AS internal_subcategory,
                              ncat.name_ar AS internal_category
                         FROM consultations c
                         JOIN users u ON u.id=c.user_id
                         LEFT JOIN najiz_case_types nct ON nct.id=c.najiz_case_type_id
                         LEFT JOIN najiz_subcategories nsub ON nsub.id=nct.subcategory_id
                         LEFT JOIN najiz_categories ncat ON ncat.id=nsub.category_id
                        WHERE ' . $scope['sql'] . ' ORDER BY c.scheduled_at DESC');
$rows->execute($scope['params']);
$rows = $rows->fetchAll();
?>
<div class="app-content">
  <div class="toolbar"><h2><?= is_rtl() ? 'استشاراتي' : 'My consultations' ?></h2></div>
  <div class="table-wrap">
    <table class="admin-table">
      <thead><tr><th><?= is_rtl() ? 'العميل' : 'Client' ?></th><th><?= is_rtl() ? 'الموعد' : 'Date' ?></th><th><?= is_rtl() ? 'النوع' : 'Type' ?></th><th><?= is_rtl() ? 'الموضوع' : 'Topic' ?></th><th><?= is_rtl() ? 'التصنيف القانوني' : 'Legal classification' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['client_name']) ?><br><span style="color:var(--text-muted);font-size:.8rem"><?= display_phone($r['client_phone']) ?></span></td>
          <td><?= date('Y-m-d H:i', strtotime($r['scheduled_at'])) ?></td>
          <td><?= htmlspecialchars($r['consultation_type']) ?></td>
          <td><?= htmlspecialchars($r['topic']) ?></td>
          <td>
            <?php if (!empty($r['internal_type'])): ?>
              <span style="font-weight:700;color:var(--navy-dark)"><?= htmlspecialchars($r['internal_type']) ?></span>
              <br><span style="color:var(--text-muted);font-size:.78rem"><?= htmlspecialchars($r['internal_category']) ?> › <?= htmlspecialchars($r['internal_subcategory']) ?></span>
            <?php else: ?>
              <span style="color:var(--text-muted)"><?= is_rtl() ? 'غير مصنّف' : 'Unclassified' ?></span>
            <?php endif; ?>
          </td>
          <td><span class="status-pill status-<?= $r['status'] ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td>
            <?php if ($r['status'] === 'pending'): ?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="change_status"><input type="hidden" name="consultation_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="confirmed"><button class="icon-btn" type="submit"><?= is_rtl() ? 'تأكيد' : 'Confirm' ?></button></form><?php endif; ?>
            <?php if ($r['status'] === 'confirmed'): ?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="change_status"><input type="hidden" name="consultation_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="completed"><button class="icon-btn" type="submit"><?= is_rtl() ? 'إتمام' : 'Complete' ?></button></form><?php endif; ?>
            <?php if (in_array($r['status'], ['pending','confirmed'])): ?><a class="icon-btn" href="/lawyer/consultation-room.php?id=<?= $r['id'] ?>" style="color:var(--teal);font-weight:700"><?= is_rtl() ? 'دخول' : 'Join' ?></a><?php endif; ?>
            <?php if (!in_array($r['status'], ['completed','cancelled'],true)): ?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="change_status"><input type="hidden" name="consultation_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="cancelled"><button class="icon-btn danger" type="submit"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button></form><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-muted)"><?= is_rtl() ? 'لا توجد استشارات بعد' : 'No consultations yet' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
