<?php
$activePage = 'invoices';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/invoice_time_items.php';
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare('
    SELECT i.*, l.full_name AS lawyer_name, l.phone AS lawyer_phone, c.title AS case_title
    FROM invoices i
    JOIN lawyers l ON l.id = i.lawyer_id
    LEFT JOIN cases c ON c.id = i.case_id
    WHERE i.user_id = ? AND i.is_draft=0
    ORDER BY i.created_at DESC
');
$stmt->execute([$userId]);
$invoices = $stmt->fetchAll();
$timeItemsByInvoice=invoice_time_items($pdo,array_column($invoices,'id'));

$totalUnpaid = 0;
foreach ($invoices as $inv) { if ($inv['status'] !== 'paid' && $inv['status'] !== 'cancelled') $totalUnpaid += $inv['amount']; }
?>
<div class="app-content">
  <h2><?= is_rtl() ? 'فواتيري' : 'My Invoices' ?></h2>

  <?php if ($totalUnpaid > 0): ?>
    <div class="form-alert error" style="font-weight:600">
      <?= is_rtl() ? 'لديك مبلغ مستحق قدره' : 'You have an outstanding balance of' ?> <?= number_format($totalUnpaid, 3) ?> <?= is_rtl() ? 'د.ب' : 'BHD' ?>
    </div>
  <?php endif; ?>

  <?php foreach ($invoices as $inv):
    $isOverdue = $inv['status'] === 'unpaid' && $inv['due_date'] && strtotime($inv['due_date']) < time();
    $displayStatus = $isOverdue ? 'overdue' : $inv['status'];
    $statusLabels = ['unpaid' => (is_rtl()?'غير مدفوعة':'Unpaid'), 'paid' => (is_rtl()?'مدفوعة':'Paid'), 'overdue' => (is_rtl()?'متأخرة':'Overdue'), 'cancelled' => (is_rtl()?'ملغاة':'Cancelled')];
  ?>
    <div class="dash-card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
        <div>
          <h3 style="margin-bottom:4px"><?= htmlspecialchars($inv['description']) ?></h3>
          <p style="color:var(--text-muted);font-size:.85rem;margin:0">
            <?= is_rtl() ? 'المحامي' : 'Lawyer' ?>: <?= htmlspecialchars($inv['lawyer_name']) ?>
            <?php if ($inv['case_title']): ?> · <?= is_rtl() ? 'القضية' : 'Case' ?>: <?= htmlspecialchars($inv['case_title']) ?><?php endif; ?>
          </p>
        </div>
        <span class="status-pill status-<?= $displayStatus === 'paid' ? 'confirmed' : ($displayStatus === 'overdue' ? 'cancelled' : 'pending') ?>"><?= $statusLabels[$displayStatus] ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
        <span style="font-size:1.3rem;font-weight:800;color:var(--navy-dark)"><?= number_format($inv['amount'], 3) ?> <?= is_rtl() ? 'د.ب' : 'BHD' ?></span>
        <span style="font-size:.82rem;color:var(--text-muted)"><?= $inv['due_date'] ? (is_rtl() ? 'الاستحقاق: ' : 'Due: ') . date('Y-m-d', strtotime($inv['due_date'])) : '' ?></span>
      </div>
      <?php if (!empty($timeItemsByInvoice[(int)$inv['id']])): ?><details style="margin-top:12px"><summary style="cursor:pointer;color:var(--teal);font-weight:600"><?= is_rtl()?'تفصيل الوقت المفوتر':'Billable time breakdown' ?> · <?= count($timeItemsByInvoice[(int)$inv['id']]) ?></summary><div class="table-wrap" style="margin-top:8px"><table class="admin-table"><thead><tr><th><?= is_rtl()?'التاريخ':'Work date' ?></th><th><?= is_rtl()?'المدة':'Duration' ?></th><th><?= is_rtl()?'السعر بالساعة':'Hourly rate' ?></th><th><?= is_rtl()?'المبلغ':'Amount' ?></th></tr></thead><tbody><?php foreach($timeItemsByInvoice[(int)$inv['id']] as $line): ?><tr><td><?= htmlspecialchars($line['work_date']) ?></td><td><?= round((int)$line['minutes']/60,2) ?> <?= is_rtl()?'ساعة':'hours' ?></td><td><?= number_format((float)$line['hourly_rate'],3) ?> <?= is_rtl()?'د.ب':'BHD' ?></td><td><?= number_format((float)$line['line_amount'],3) ?> <?= is_rtl()?'د.ب':'BHD' ?></td></tr><?php endforeach; ?></tbody></table></div><small class="field-hint"><?= is_rtl()?'يُعرض تفصيل الوقت دون الملاحظات الداخلية لسجل العمل.':'Work-log notes are kept private; only billing details are shown.' ?></small></details><?php endif; ?>
      <?php if ($inv['status'] === 'unpaid'): ?>
        <a href="/pay-invoice.php?id=<?= $inv['id'] ?>" class="btn btn-primary btn-block" style="margin-top:12px">💳 <?= is_rtl() ? 'ادفع الآن (مدى / فيزا / ماستركارد)' : 'Pay now (mada / Visa / Mastercard)' ?></a>
        <p class="field-hint" style="margin-top:10px"><?= is_rtl() ? 'أو تواصل مع المحامي مباشرة لطرق دفع بديلة.' : 'Or contact the lawyer directly for alternative payment methods.' ?></p>
      <?php elseif ($inv['status'] === 'paid'): ?>
        <p class="field-hint" style="margin-top:10px;color:var(--success)"><?= is_rtl() ? 'تم الدفع بتاريخ' : 'Paid on' ?> <?= date('Y-m-d', strtotime($inv['paid_at'])) ?></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (empty($invoices)): ?>
    <div class="dash-card" style="text-align:center;color:var(--text-muted)"><?= is_rtl() ? 'لا توجد فواتير حالياً.' : 'No invoices yet.' ?></div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
