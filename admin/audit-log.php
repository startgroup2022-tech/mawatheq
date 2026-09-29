<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';

$activeAdmin = 'audit-log';
require_once __DIR__ . '/includes/admin_header.php';

$loadError = false;
try { $logs = $pdo->query('SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 200')->fetchAll(); }
catch (PDOException $e) { error_log('Admin audit log page failed: ' . $e->getMessage()); $logs = []; $loadError = true; }
$actorLabels = ['admin' => '👤 ' . adm('إدارة', 'Admin'), 'lawyer' => '⚖️ ' . adm('محامٍ', 'Lawyer'), 'system' => '⚙️ ' . adm('النظام', 'System')];
?>
<div class="app-content">
  <div class="pg-head"><div><h1><?= h(adm('سجل التدقيق', 'Audit log')) ?> <span class="count-badge"><?= count($logs) ?></span></h1>
    <p><?= h(adm('آخر 200 عملية حساسة على المنصة. لا يُسجَّل محتوى المعرفة أو قيم حساسة مثل مفاتيح API.', 'The last 200 sensitive platform actions. Knowledge content and secrets such as API keys are never logged.')) ?></p></div></div>
  <?php if ($loadError): ?><div class="form-alert error"><?= h(adm('جدول سجل التدقيق غير متاح. شغّل ترقية database/upgrade_v24.sql من phpMyAdmin، ثم حدّث الصفحة.', 'The audit log table is unavailable. Run database/upgrade_v24.sql via phpMyAdmin, then reload.')) ?></div><?php endif; ?>

  <div class="tbl-tools"><input type="search" placeholder="<?= h(adm('بحث في الإجراءات…', 'Search actions…')) ?>" data-filter="#logTable"></div>
  <div class="table-wrap"><table class="admin-table" id="logTable">
    <thead><tr><th><?= h(adm('الوقت', 'Time')) ?></th><th><?= h(adm('المُنفّذ', 'Actor')) ?></th><th><?= h(adm('الإجراء', 'Action')) ?></th><th><?= h(adm('الهدف', 'Target')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($logs as $l): ?>
      <tr><td style="white-space:nowrap"><small style="color:var(--muted)"><?= h(date('Y-m-d H:i', strtotime($l['created_at']))) ?></small></td><td><?= $actorLabels[$l['actor_type']] ?? h($l['actor_type']) ?> <?= h($l['actor_name'] ?? '') ?></td><td><?= h($l['action']) ?></td><td><small style="color:var(--muted)"><?= $l['target_type'] ? h($l['target_type']) . ' #' . (int)$l['target_id'] : '—' ?></small></td></tr>
      <?php endforeach; ?>
      <?php if (!$logs): ?><tr><td colspan="4" class="empty"><?= h(adm('لا توجد سجلات بعد', 'No log entries yet')) ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
