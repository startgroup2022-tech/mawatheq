<?php
/**
 * Admin → Payments → Gateway transactions.
 * Unified, read-only view over payment_transactions for every gateway (SADAD + N-Genius),
 * with the filters requested: gateway, environment, status, and date range.
 */
$activeAdmin = 'gateway-transactions';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
$pageTitle = is_rtl() ? 'عمليات بوابات الدفع' : 'Gateway transactions';
require_once __DIR__ . '/includes/admin_header.php';
require_once __DIR__ . '/../includes/gateway/bootstrap.php';

$fGateway = trim((string)($_GET['gateway'] ?? ''));
$fEnv = trim((string)($_GET['environment'] ?? ''));
$fStatus = trim((string)($_GET['status'] ?? ''));
$fFrom = trim((string)($_GET['from'] ?? ''));
$fTo = trim((string)($_GET['to'] ?? ''));

$where = []; $params = [];
if (in_array($fGateway, ['sadad', 'ngenius'], true)) { $where[] = 't.gateway = ?'; $params[] = $fGateway; }
if (in_array($fEnv, ['sandbox', 'live'], true)) { $where[] = 't.environment = ?'; $params[] = $fEnv; }
if ($fStatus !== '') { $where[] = 't.status = ?'; $params[] = $fStatus; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) { $where[] = 't.created_at >= ?'; $params[] = $fFrom . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo)) { $where[] = 't.created_at <= ?'; $params[] = $fTo . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
try {
    $stmt = $pdo->prepare("SELECT t.*, i.invoice_number, i.currency AS invoice_currency, u.full_name AS customer_name
        FROM payment_transactions t
        LEFT JOIN invoices i ON i.id = t.invoice_id
        LEFT JOIN users u ON u.id = i.user_id
        {$whereSql}
        ORDER BY t.id DESC LIMIT 300");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Gateway transactions query failed: ' . $e->getMessage());
}

$statusOptions = ['INITIATED','PENDING','REDIRECTED','AUTHORIZED','PAID','FAILED','CANCELLED','EXPIRED','REFUNDED'];
?>
<div class="app-content">
  <div class="pg-head"><div>
    <h1><?= is_rtl() ? 'عمليات بوابات الدفع' : 'Gateway transactions' ?></h1>
    <p><?= is_rtl() ? 'سجل موحّد لكل عمليات SADAD و N-Genius.' : 'Unified log of all SADAD and N-Genius transactions.' ?></p>
  </div></div>

  <form class="dash-card" method="get" style="padding:16px 18px">
    <div class="grid-2">
      <div class="field"><label><?= is_rtl() ? 'البوابة' : 'Gateway' ?></label>
        <div class="select-wrap"><select name="gateway" class="pro-select">
          <option value=""><?= is_rtl() ? 'الكل' : 'All' ?></option>
          <option value="sadad" <?= $fGateway === 'sadad' ? 'selected' : '' ?>>SADAD</option>
          <option value="ngenius" <?= $fGateway === 'ngenius' ? 'selected' : '' ?>>N-Genius</option>
        </select></div>
      </div>
      <div class="field"><label><?= is_rtl() ? 'البيئة' : 'Environment' ?></label>
        <div class="select-wrap"><select name="environment" class="pro-select">
          <option value=""><?= is_rtl() ? 'الكل' : 'All' ?></option>
          <option value="sandbox" <?= $fEnv === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
          <option value="live" <?= $fEnv === 'live' ? 'selected' : '' ?>>Live</option>
        </select></div>
      </div>
      <div class="field"><label><?= is_rtl() ? 'الحالة' : 'Status' ?></label>
        <div class="select-wrap"><select name="status" class="pro-select">
          <option value=""><?= is_rtl() ? 'الكل' : 'All' ?></option>
          <?php foreach ($statusOptions as $s): ?><option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
        </select></div>
      </div>
      <div class="field"><label><?= is_rtl() ? 'من تاريخ' : 'From' ?></label><input type="date" name="from" value="<?= htmlspecialchars($fFrom) ?>"></div>
      <div class="field"><label><?= is_rtl() ? 'إلى تاريخ' : 'To' ?></label><input type="date" name="to" value="<?= htmlspecialchars($fTo) ?>"></div>
    </div>
    <div style="display:flex;gap:10px"><button class="btn btn-primary" type="submit"><?= is_rtl() ? 'تصفية' : 'Filter' ?></button><a class="btn btn-outline" href="/admin/gateway-transactions.php"><?= is_rtl() ? 'مسح' : 'Clear' ?></a></div>
  </form>

  <div class="dash-card">
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr>
          <th><?= is_rtl() ? 'الفاتورة' : 'Invoice #' ?></th>
          <th><?= is_rtl() ? 'العميل' : 'Customer' ?></th>
          <th><?= is_rtl() ? 'البوابة' : 'Gateway' ?></th>
          <th><?= is_rtl() ? 'البيئة' : 'Environment' ?></th>
          <th><?= is_rtl() ? 'المبلغ' : 'Amount' ?></th>
          <th><?= is_rtl() ? 'العملة' : 'Currency' ?></th>
          <th><?= is_rtl() ? 'الحالة' : 'Status' ?></th>
          <th><?= is_rtl() ? 'رقم العملية' : 'Transaction ID' ?></th>
          <th><?= is_rtl() ? 'التاريخ' : 'Date' ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" style="text-align:center;color:var(--muted)"><?= is_rtl() ? 'لا توجد عمليات مطابقة.' : 'No matching transactions.' ?></td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?php if ($r['invoice_id']): ?><a href="/admin/gateway-transaction.php?id=<?= (int)$r['id'] ?>"><?= htmlspecialchars((string)($r['invoice_number'] ?? ('#' . $r['invoice_id']))) ?></a><?php else: ?>—<?php endif; ?></td>
            <td><?= htmlspecialchars((string)($r['customer_name'] ?? '—')) ?></td>
            <td><b><?= htmlspecialchars((string)$r['gateway']) ?></b></td>
            <td><?= htmlspecialchars((string)($r['environment'] ?? '—')) ?></td>
            <td><?= htmlspecialchars((string)($r['amount'] ?? '—')) ?></td>
            <td><?= htmlspecialchars((string)($r['currency'] ?? $r['invoice_currency'] ?? '—')) ?></td>
            <td><span class="status-pill status-<?= (($r['status'] ?? '') === 'PAID') ? 'confirmed' : 'pending' ?>"><?= htmlspecialchars((string)($r['status'] ?? '—')) ?></span></td>
            <td><?= htmlspecialchars((string)($r['gateway_transaction_id'] ?? $r['gateway_order_ref'] ?? '—')) ?></td>
            <td><?= htmlspecialchars((string)$r['created_at']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
