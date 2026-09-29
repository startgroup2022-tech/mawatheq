<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/phone.php';
require_once __DIR__ . '/../includes/audit_log.php';
$lawyerId = $_SESSION['lawyer_id'];

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='change_status') {
    $allowed = ['confirmed','completed','cancelled'];
    $status=$_POST['status'] ?? ''; $id=(int)($_POST['consultation_id'] ?? 0);
    if (in_array($status, $allowed,true) && $id>0) {
        $pdo->prepare('UPDATE consultations SET status=? WHERE id=? AND lawyer_id=?')->execute([$status,$id,$lawyerId]);
        log_action($pdo,'تغيير حالة استشارة المحامي إلى '.$status,'consultation',$id);
    }
    header('Location: /lawyer/consultations.php'); exit;
}

$activeLawyer = 'consultations';
require_once __DIR__ . '/includes/lawyer_header.php';

$rows = $pdo->prepare('SELECT c.*, u.full_name AS client_name, u.phone AS client_phone FROM consultations c JOIN users u ON u.id=c.user_id WHERE c.lawyer_id=? ORDER BY c.scheduled_at DESC');
$rows->execute([$lawyerId]);
$rows = $rows->fetchAll();
?>
<div class="app-content">
  <div class="toolbar"><h2><?= is_rtl() ? 'استشاراتي' : 'My consultations' ?></h2></div>
  <div class="table-wrap">
    <table class="admin-table">
      <thead><tr><th><?= is_rtl() ? 'العميل' : 'Client' ?></th><th><?= is_rtl() ? 'الموعد' : 'Date' ?></th><th><?= is_rtl() ? 'النوع' : 'Type' ?></th><th><?= is_rtl() ? 'الموضوع' : 'Topic' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['client_name']) ?><br><span style="color:var(--text-muted);font-size:.8rem"><?= display_phone($r['client_phone']) ?></span></td>
          <td><?= date('Y-m-d H:i', strtotime($r['scheduled_at'])) ?></td>
          <td><?= htmlspecialchars($r['consultation_type']) ?></td>
          <td><?= htmlspecialchars($r['topic']) ?></td>
          <td><span class="status-pill status-<?= $r['status'] ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td>
            <?php if ($r['status'] === 'pending'): ?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="change_status"><input type="hidden" name="consultation_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="confirmed"><button class="icon-btn" type="submit"><?= is_rtl() ? 'تأكيد' : 'Confirm' ?></button></form><?php endif; ?>
            <?php if ($r['status'] === 'confirmed'): ?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="change_status"><input type="hidden" name="consultation_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="completed"><button class="icon-btn" type="submit"><?= is_rtl() ? 'إتمام' : 'Complete' ?></button></form><?php endif; ?>
            <?php if (in_array($r['status'], ['pending','confirmed'])): ?><a class="icon-btn" href="/lawyer/consultation-room.php?id=<?= $r['id'] ?>" style="color:var(--teal);font-weight:700"><?= is_rtl() ? 'دخول' : 'Join' ?></a><?php endif; ?>
            <?php if (!in_array($r['status'], ['completed','cancelled'],true)): ?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="change_status"><input type="hidden" name="consultation_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="cancelled"><button class="icon-btn danger" type="submit"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button></form><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-muted)"><?= is_rtl() ? 'لا توجد استشارات بعد' : 'No consultations yet' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
