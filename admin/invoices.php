<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/audit_log.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_paid') {
    $invId = (int)($_POST['invoice_id'] ?? 0);
    $paidUpdate = $pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW(), payment_method='admin_confirmed' WHERE id=? AND status='unpaid' AND is_draft=0");
    $paidUpdate->execute([$invId]);
    $inv = null;
    if ($paidUpdate->rowCount()) { $inv = $pdo->prepare('SELECT lawyer_id, user_id, description FROM invoices WHERE id=? AND is_draft=0'); $inv->execute([$invId]); $inv = $inv->fetch(); }
    if ($inv) {
        notify($pdo, 'lawyer', $inv['lawyer_id'], is_rtl() ? 'تم تأكيد دفع فاتورة' : 'Invoice payment confirmed', $inv['description'], '/lawyer/invoices.php');
        log_action($pdo, 'تأكيد دفع فاتورة #' . $invId, 'invoice', $invId);
    }
    header('Location: /admin/invoices.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_invoice') {
    $invId = (int)($_POST['invoice_id'] ?? 0);
    $pdo->prepare("UPDATE invoices SET status='cancelled' WHERE id=? AND status='unpaid' AND is_draft=0")->execute([$invId]);
    log_action($pdo, 'إلغاء فاتورة #' . $invId, 'invoice', $invId);
    header('Location: /admin/invoices.php'); exit;
}

$activeAdmin = 'invoices';
require_once __DIR__ . '/includes/admin_header.php';

$loadError = false;
try {
    $stmt = $pdo->query('
        SELECT i.*, u.full_name AS client_name, u.id AS client_id, l.full_name AS lawyer_name
        FROM invoices i
        LEFT JOIN users u ON u.id = i.user_id
        LEFT JOIN lawyers l ON l.id = i.lawyer_id
        WHERE i.is_draft = 0
        ORDER BY i.created_at DESC
    ');
    $invoices = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Admin invoices page failed: ' . $e->getMessage());
    $invoices = []; $loadError = true;
}

$totalPaid = 0; $totalUnpaid = 0; $totalOverdue = 0; $overdueCount = 0;
foreach ($invoices as $inv) {
    if ($inv['status'] === 'paid') $totalPaid += $inv['amount'];
    elseif ($inv['status'] === 'unpaid') {
        $totalUnpaid += $inv['amount'];
        if ($inv['due_date'] && strtotime($inv['due_date']) < time()) { $totalOverdue += $inv['amount']; $overdueCount++; }
    }
}
$statusLabels = ['unpaid' => adm('غير مدفوعة', 'Unpaid'), 'paid' => adm('مدفوعة', 'Paid'), 'overdue' => adm('متأخرة', 'Overdue'), 'cancelled' => adm('ملغاة', 'Cancelled')];
$csrf = '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
?>
<div class="app-content">
  <div class="pg-head"><div><h1><?= h(adm('الفواتير والإيرادات', 'Invoices & revenue')) ?></h1><p><?= h(adm('كل فواتير المنصة بين العملاء والمحامين', 'All platform invoices between clients and lawyers')) ?></p></div></div>

  <?php if ($loadError): ?><div class="form-alert error"><?= h(adm('تعذر تحميل الفواتير. تأكد من وجود جدول invoices في قاعدة البيانات، ثم راجع سجل أخطاء PHP في cPanel.', 'Could not load invoices. Confirm the invoices table exists, then check the PHP error log in cPanel.')) ?></div><?php endif; ?>

  <div class="grid g-4" style="margin-bottom:18px">
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic green"><?= adm_icon('receipt') ?></span></div><div class="kpi-val"><?= money($totalPaid) ?></div><div class="kpi-lbl"><?= h(adm('إيرادات محصّلة', 'Collected revenue')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic gold"><?= adm_icon('clock') ?></span></div><div class="kpi-val"><?= money($totalUnpaid) ?></div><div class="kpi-lbl"><?= h(adm('مستحق', 'Outstanding')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic red"><?= adm_icon('shield') ?></span></div><div class="kpi-val"><?= money($totalOverdue) ?></div><div class="kpi-lbl"><?= $overdueCount ?> <?= h(adm('فاتورة متأخرة', 'overdue invoices')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic"><?= adm_icon('file') ?></span></div><div class="kpi-val"><?= count($invoices) ?></div><div class="kpi-lbl"><?= h(adm('إجمالي الفواتير', 'Total invoices')) ?></div></div>
  </div>

  <div class="tbl-tools"><input type="search" placeholder="<?= h(adm('بحث بالعميل أو المحامي أو الوصف…', 'Search client, lawyer or description…')) ?>" data-filter="#invTable">
    <div class="chips"><a href="#" class="chip on" data-status-filter="#invTable" data-value=""><?= h(adm('الكل', 'All')) ?></a><?php foreach (['paid', 'unpaid', 'overdue', 'cancelled'] as $k): ?><a href="#" class="chip" data-status-filter="#invTable" data-value="<?= $k ?>"><?= h($statusLabels[$k]) ?></a><?php endforeach; ?></div></div>
  <div class="table-wrap table-cards"><table class="admin-table" id="invTable">
    <thead><tr><th><?= h(adm('العميل', 'Client')) ?></th><th><?= h(adm('المحامي', 'Lawyer')) ?></th><th><?= h(adm('الوصف', 'Description')) ?></th><th><?= h(adm('المبلغ', 'Amount')) ?></th><th><?= h(adm('الاستحقاق', 'Due')) ?></th><th><?= h(adm('الحالة', 'Status')) ?></th><th></th></tr></thead>
    <tbody>
      <?php foreach ($invoices as $inv):
          $isOverdue = $inv['status'] === 'unpaid' && $inv['due_date'] && strtotime($inv['due_date']) < time();
          $displayStatus = $isOverdue ? 'overdue' : $inv['status'];
      ?>
      <tr data-status="<?= h($displayStatus) ?>">
        <td data-label="<?= h(adm('العميل', 'Client')) ?>"><?= $inv['client_id'] ? '<a href="/admin/client-detail.php?id=' . (int)$inv['client_id'] . '">' . h($inv['client_name']) . '</a>' : h($inv['client_name'] ?: '—') ?></td>
        <td data-label="<?= h(adm('المحامي', 'Lawyer')) ?>"><?= h($inv['lawyer_name'] ?: '—') ?></td>
        <td data-label="<?= h(adm('الوصف', 'Description')) ?>"><?= h($inv['description']) ?></td>
        <td data-label="<?= h(adm('المبلغ', 'Amount')) ?>"><b><?= money($inv['amount']) ?></b></td>
        <td data-label="<?= h(adm('الاستحقاق', 'Due')) ?>"><?= $inv['due_date'] ? h(date('Y-m-d', strtotime($inv['due_date']))) : '—' ?></td>
        <td data-label="<?= h(adm('الحالة', 'Status')) ?>"><span class="status-pill status-<?= $displayStatus === 'paid' ? 'confirmed' : ($displayStatus === 'overdue' ? 'cancelled' : 'pending') ?>"><?= h($statusLabels[$displayStatus]) ?></span></td>
        <td data-label="">
          <?php if ($inv['status'] === 'unpaid'): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('<?= h(adm('تأكيد استلام الدفعة؟', 'Confirm payment received?')) ?>')"><?= $csrf ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>"><button class="icon-btn" type="submit"><?= h(adm('تحديد كمدفوعة', 'Mark paid')) ?></button></form>
            <form method="post" style="display:inline" onsubmit="return confirm('<?= h(adm('إلغاء الفاتورة؟', 'Cancel invoice?')) ?>')"><?= $csrf ?><input type="hidden" name="action" value="cancel_invoice"><input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>"><button class="icon-btn danger" type="submit"><?= h(adm('إلغاء', 'Cancel')) ?></button></form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$invoices): ?><tr><td colspan="7" class="empty"><?= h(adm('لا توجد فواتير بعد', 'No invoices yet')) ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
