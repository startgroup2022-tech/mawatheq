<?php
/**
 * invoice-print.php — فاتورة قابلة للطباعة (عميل / محامٍ)
 *
 * The receipt the mobile app opens in the browser (see api/v1/invoice.php's
 * `receipt_url`). It is deliberately a plain, print-friendly page with no shell
 * chrome: the customer prints it or saves it as PDF from the browser's own dialog,
 * which is why the platform needs no server-side PDF library.
 *
 * Ownership is enforced here exactly as in the API: a client may only print their
 * OWN invoice, a lawyer only an invoice in their tenant. A guessed id returns 404.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/finance.php';

$invoiceId = (int)($_GET['id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$lawyerId = (int)($_SESSION['lawyer_id'] ?? 0);

if ($invoiceId <= 0 || ($userId <= 0 && $lawyerId <= 0)) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><p>الفاتورة غير موجودة.</p>';
    exit;
}

if ($userId > 0) {
    $stmt = $pdo->prepare('SELECT i.*, l.full_name AS lawyer_name, c.title AS case_title
        FROM invoices i
        JOIN lawyers l ON l.id = i.lawyer_id
        LEFT JOIN cases c ON c.id = i.case_id
        WHERE i.id = ? AND i.user_id = ? AND i.is_draft = 0');
    $stmt->execute([$invoiceId, $userId]);
} else {
    require_once __DIR__ . '/includes/tenant.php';
    $scope = tenant_owner_where_for_lawyer($pdo, $lawyerId, 'i');
    $stmt = $pdo->prepare('SELECT i.*, u.full_name AS client_name, c.title AS case_title
        FROM invoices i
        JOIN users u ON u.id = i.user_id
        LEFT JOIN cases c ON c.id = i.case_id
        WHERE i.id = ? AND ' . $scope['sql']);
    $stmt->execute(array_merge([$invoiceId], $scope['params']));
}

$invoice = $stmt->fetch();
if (!$invoice) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><p>الفاتورة غير موجودة.</p>';
    exit;
}

$items = $pdo->prepare('SELECT description, quantity, unit_price, total FROM invoice_items WHERE invoice_id = ? ORDER BY id');
$items->execute([$invoiceId]);
$items = $items->fetchAll();

$payments = $pdo->prepare('SELECT amount, currency, method, paid_at FROM invoice_payments WHERE invoice_id = ? ORDER BY paid_at');
$payments->execute([$invoiceId]);
$payments = $payments->fetchAll();

$rtl = is_rtl();
$dir = $rtl ? 'rtl' : 'ltr';
$currency = $invoice['currency'] ?: (function_exists('currency_code') ? currency_code() : 'SAR');
$balance = finance_invoice_balance($invoice);
$party = $userId > 0 ? ($invoice['lawyer_name'] ?? '') : ($invoice['client_name'] ?? '');
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $rtl ? 'ar' : 'en' ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($invoice['invoice_number'] ?: ('#' . $invoiceId)) ?> — <?= $h(SITE_NAME_AR) ?></title>
<style>
  :root { --navy:#1E3A5F; --line:#e2e8f0; --muted:#64748b; }
  * { box-sizing: border-box; }
  body { font-family: "IBM Plex Sans Arabic", "Segoe UI", Tahoma, sans-serif; color:#0f172a; margin:0; background:#f8fafc; }
  .sheet { max-width:820px; margin:24px auto; background:#fff; border:1px solid var(--line); border-radius:12px; padding:32px; }
  h1 { color: var(--navy); font-size:22px; margin:0 0 4px; }
  .muted { color: var(--muted); font-size:13px; }
  .row { display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-top:18px; }
  table { width:100%; border-collapse:collapse; margin-top:20px; }
  th, td { padding:10px 8px; border-bottom:1px solid var(--line); text-align:start; font-size:14px; }
  th { color: var(--muted); font-weight:600; }
  .totals { margin-top:18px; width:100%; max-width:320px; margin-inline-start:auto; }
  .totals div { display:flex; justify-content:space-between; padding:6px 0; }
  .totals .grand { font-weight:700; color: var(--navy); border-top:2px solid var(--navy); }
  .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:600; }
  .badge.paid { background:#dcfce7; color:#166534; }
  .badge.unpaid { background:#fee2e2; color:#991b1b; }
  .badge.other { background:#e2e8f0; color:#334155; }
  .actions { margin: 18px auto; max-width:820px; text-align:end; }
  button { background:var(--navy); color:#fff; border:0; border-radius:8px; padding:10px 18px; font-size:14px; cursor:pointer; }
  @media print { .actions { display:none; } body { background:#fff; } .sheet { border:0; margin:0; } }
</style>
</head>
<body>
<div class="actions"><button onclick="window.print()"><?= $rtl ? 'طباعة / حفظ PDF' : 'Print / Save PDF' ?></button></div>
<div class="sheet">
  <div class="row">
    <div>
      <h1><?= $h(SITE_NAME_AR) ?></h1>
      <div class="muted"><?= $rtl ? 'فاتورة' : 'Invoice' ?> <?= $h($invoice['invoice_number'] ?: ('#' . $invoiceId)) ?></div>
    </div>
    <div style="text-align:end">
      <?php
        $display = finance_display_status($invoice);
        $cls = $display === 'paid' ? 'paid' : (in_array($display, ['unpaid', 'overdue'], true) ? 'unpaid' : 'other');
        $labels = ['paid' => $rtl ? 'مدفوعة' : 'Paid', 'unpaid' => $rtl ? 'غير مدفوعة' : 'Unpaid',
                   'partially_paid' => $rtl ? 'مدفوعة جزئياً' : 'Partially paid', 'overdue' => $rtl ? 'متأخرة' : 'Overdue',
                   'cancelled' => $rtl ? 'ملغاة' : 'Cancelled'];
      ?>
      <span class="badge <?= $cls ?>"><?= $h($labels[$display] ?? $display) ?></span>
      <div class="muted" style="margin-top:6px"><?= $h($invoice['issue_date'] ?: $invoice['created_at']) ?></div>
    </div>
  </div>

  <div class="row">
    <div>
      <div class="muted"><?= $userId > 0 ? ($rtl ? 'المحامي' : 'Lawyer') : ($rtl ? 'العميل' : 'Client') ?></div>
      <div><?= $h($party) ?></div>
      <?php if (!empty($invoice['case_title'])): ?>
        <div class="muted"><?= $rtl ? 'القضية' : 'Case' ?>: <?= $h($invoice['case_title']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <table>
    <thead><tr>
      <th><?= $rtl ? 'البند' : 'Description' ?></th>
      <th><?= $rtl ? 'الكمية' : 'Qty' ?></th>
      <th><?= $rtl ? 'السعر' : 'Unit price' ?></th>
      <th><?= $rtl ? 'الإجمالي' : 'Total' ?></th>
    </tr></thead>
    <tbody>
      <?php if ($items): foreach ($items as $it): ?>
        <tr>
          <td><?= $h($it['description']) ?></td>
          <td><?= $h($it['quantity']) ?></td>
          <td><?= money_text($it['unit_price'], $currency) ?></td>
          <td><?= money_text($it['total'], $currency) ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr>
          <td><?= $h($invoice['description'] ?: ($rtl ? 'فاتورة' : 'Invoice')) ?></td>
          <td>1</td>
          <td><?= money_text($invoice['amount'], $currency) ?></td>
          <td><?= money_text($invoice['amount'], $currency) ?></td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="totals">
    <?php if ((float)$invoice['discount_total'] > 0): ?>
      <div><span><?= $rtl ? 'الخصم' : 'Discount' ?></span><span>-<?= money_text($invoice['discount_total'], $currency) ?></span></div>
    <?php endif; ?>
    <?php if ((float)$invoice['tax_total'] > 0): ?>
      <div><span><?= $rtl ? 'الضريبة' : 'Tax' ?></span><span><?= money_text($invoice['tax_total'], $currency) ?></span></div>
    <?php endif; ?>
    <div><span><?= $rtl ? 'الإجمالي' : 'Total' ?></span><span><?= money_text($invoice['amount'], $currency) ?></span></div>
    <div><span><?= $rtl ? 'المدفوع' : 'Paid' ?></span><span><?= money_text($invoice['amount_paid'], $currency) ?></span></div>
    <div class="grand"><span><?= $rtl ? 'المتبقي' : 'Balance' ?></span><span><?= money_text($balance, $currency) ?></span></div>
  </div>

  <?php if ($payments): ?>
    <h3 style="margin-top:26px;color:var(--navy);font-size:16px"><?= $rtl ? 'الدفعات' : 'Payments' ?></h3>
    <table>
      <thead><tr>
        <th><?= $rtl ? 'التاريخ' : 'Date' ?></th>
        <th><?= $rtl ? 'الطريقة' : 'Method' ?></th>
        <th><?= $rtl ? 'المبلغ' : 'Amount' ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td><?= $h($p['paid_at']) ?></td>
            <td><?= $h($p['method']) ?></td>
            <td><?= money_text($p['amount'], $p['currency'] ?: $currency) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</body>
</html>
