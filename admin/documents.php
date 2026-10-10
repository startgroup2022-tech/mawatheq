<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
$activeAdmin = 'documents';
require_once __DIR__ . '/includes/admin_header.php';

$rows = $pdo->query('SELECT d.*, u.full_name AS user_name, u.id AS user_id, c.title AS case_title FROM documents d JOIN users u ON u.id=d.user_id LEFT JOIN cases c ON c.id=d.case_id ORDER BY d.id DESC')->fetchAll();
$statusLbl = ['completed' => adm('مكتمل', 'Completed'), 'processing' => adm('قيد المعالجة', 'Processing'), 'failed' => adm('فشل', 'Failed'), 'pending' => adm('بانتظار', 'Pending')];
$n = ['completed' => 0, 'failed' => 0]; foreach ($rows as $r) if (isset($n[$r['status']])) $n[$r['status']]++;
?>
<div class="app-content">
  <div class="pg-head"><div><h1><?= h(adm('المستندات المرفوعة', 'Uploaded documents')) ?></h1><p><?= count($rows) ?> <?= h(adm('مستند', 'documents')) ?> · <?= $n['completed'] ?> <?= h(adm('مكتمل', 'completed')) ?> · <?= $n['failed'] ?> <?= h(adm('فشل', 'failed')) ?></p></div></div>
  <div class="tbl-tools"><input type="search" placeholder="<?= h(adm('بحث بالعميل أو اسم الملف…', 'Search client or file name…')) ?>" data-filter="#docTable"></div>
  <div class="table-wrap"><table class="admin-table" id="docTable">
    <thead><tr><th><?= h(adm('العميل', 'Client')) ?></th><th><?= h(adm('اسم الملف', 'File name')) ?></th><th><?= h(adm('القضية', 'Case')) ?></th><th><?= h(adm('النوع', 'Type')) ?></th><th><?= h(adm('التاريخ', 'Date')) ?></th><th><?= h(adm('الحالة', 'Status')) ?></th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $d): ?>
      <tr><td><a href="/admin/client-detail.php?id=<?= (int)$d['user_id'] ?>"><?= h($d['user_name']) ?></a></td><td><?= h($d['original_name']) ?></td><td><?= $d['case_title'] ? h($d['case_title']) : '<span class="pill gray">' . h(adm('بدون قضية', 'No case')) . '</span>' ?></td><td><span class="pill gray"><?= h(strtoupper($d['file_type'])) ?></span></td><td><?= h(date('Y-m-d H:i', strtotime($d['created_at']))) ?></td><td><span class="status-pill status-<?= h($d['status']) ?>"><?= h($statusLbl[$d['status']] ?? $d['status']) ?></span></td><td><a class="icon-btn" href="/download-document.php?id=<?= (int)$d['id'] ?>"><?= h(adm('تنزيل', 'Download')) ?></a></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="empty"><?= h(adm('لا توجد مستندات بعد', 'No documents yet')) ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
