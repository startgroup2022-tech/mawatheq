<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';

$activeAdmin = 'firms';
require_once __DIR__ . '/includes/admin_header.php';

$loadError = false;
try {
    $firms = $pdo->query("
        SELECT f.id, f.name, f.legal_name, f.city, f.country, f.created_at,
               o.full_name AS owner_name, o.email AS owner_email,
               (SELECT COUNT(*) FROM law_firm_members m WHERE m.firm_id=f.id AND m.status='active') AS member_count,
               (SELECT COUNT(*) FROM cases c WHERE c.firm_id=f.id) AS case_count,
               (SELECT COUNT(*) FROM consultations co WHERE co.firm_id=f.id) AS consultation_count,
               (SELECT COUNT(*) FROM invoices i WHERE i.firm_id=f.id) AS invoice_count,
               (SELECT COALESCE(SUM(i.amount),0) FROM invoices i WHERE i.firm_id=f.id AND i.status='paid') AS paid_revenue
        FROM law_firms f
        LEFT JOIN lawyers o ON o.id=f.owner_lawyer_id
        ORDER BY f.created_at DESC
    ")->fetchAll();
    $memberRows = $pdo->query("
        SELECT m.firm_id, m.member_role, l.full_name, l.email
        FROM law_firm_members m JOIN lawyers l ON l.id=m.lawyer_id
        WHERE m.status='active'
        ORDER BY FIELD(m.member_role,'owner','partner','lawyer','staff'), l.full_name
    ")->fetchAll();
} catch (PDOException $e) {
    error_log('Admin firms page failed: ' . $e->getMessage());
    $firms = []; $memberRows = []; $loadError = true;
}
$membersByFirm = [];
foreach ($memberRows as $row) $membersByFirm[(int)$row['firm_id']][] = $row;
?>
<div class="app-content">
  <div class="pg-head"><div><h1><?= h(adm('المكاتب القانونية', 'Law firms')) ?> <span class="count-badge"><?= count($firms) ?></span></h1>
    <p><?= h(adm('كل مكتب هو مساحة مستأجرة مستقلة. تظهر هنا العضويات وحجم العمل والإيرادات المحصّلة لكل مكتب.', 'Each firm is an independent tenant. Memberships, workload, and collected revenue are shown per firm.')) ?></p></div></div>
  <?php if ($loadError): ?><div class="form-alert error"><?= h(adm('جداول المكاتب غير متاحة. شغّل ترقية database/upgrade_v42.sql من phpMyAdmin ثم حدّث الصفحة.', 'Firm tables are unavailable. Run database/upgrade_v42.sql via phpMyAdmin, then reload.')) ?></div><?php endif; ?>

  <div class="table-wrap"><table class="admin-table">
    <thead><tr>
      <th><?= h(adm('المكتب', 'Firm')) ?></th>
      <th><?= h(adm('المالك', 'Owner')) ?></th>
      <th><?= h(adm('الأعضاء', 'Members')) ?></th>
      <th><?= h(adm('القضايا', 'Cases')) ?></th>
      <th><?= h(adm('الاستشارات', 'Consultations')) ?></th>
      <th><?= h(adm('الفواتير', 'Invoices')) ?></th>
      <th><?= h(adm('الإيرادات المحصّلة', 'Collected')) ?></th>
      <th><?= h(adm('أُنشئ', 'Created')) ?></th>
    </tr></thead>
    <tbody>
      <?php foreach ($firms as $f): $fid = (int)$f['id']; ?>
      <tr>
        <td><strong><?= h($f['name']) ?></strong><?php if (!empty($f['legal_name'])): ?><br><small style="color:var(--muted)"><?= h($f['legal_name']) ?></small><?php endif; ?><?php if (!empty($f['city']) || !empty($f['country'])): ?><br><small style="color:var(--muted)"><?= h(trim(($f['city'] ?? '') . ' ' . ($f['country'] ?? ''))) ?></small><?php endif; ?></td>
        <td><?= h($f['owner_name'] ?? '—') ?><?php if (!empty($f['owner_email'])): ?><br><small style="color:var(--muted)"><?= h($f['owner_email']) ?></small><?php endif; ?></td>
        <td>
          <span class="count-badge"><?= (int)$f['member_count'] ?></span>
          <?php if (!empty($membersByFirm[$fid])): ?><br><small style="color:var(--muted)"><?= h(implode('، ', array_map(fn($m) => $m['full_name'] . ' (' . $m['member_role'] . ')', $membersByFirm[$fid]))) ?></small><?php endif; ?>
        </td>
        <td><?= (int)$f['case_count'] ?></td>
        <td><?= (int)$f['consultation_count'] ?></td>
        <td><?= (int)$f['invoice_count'] ?></td>
        <td><?= h(number_format((float)$f['paid_revenue'], 2)) ?></td>
        <td><small style="color:var(--muted)"><?= h(date('Y-m-d', strtotime($f['created_at']))) ?></small></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$firms): ?><tr><td colspan="8" class="empty"><?= h(adm('لا توجد مكاتب بعد', 'No firms yet')) ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
