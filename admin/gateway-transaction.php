<?php
/**
 * Admin → Payments → Gateway transaction detail.
 *
 * Shows the full record and the sanitised gateway response. Secrets (keys, passwords, webhook
 * secrets, card data) are stripped before display — only the gateway's own non-secret response
 * fields are shown.
 */
$activeAdmin = 'gateway-transactions';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
$pageTitle = is_rtl() ? 'تفاصيل عملية دفع' : 'Gateway transaction detail';
require_once __DIR__ . '/includes/admin_header.php';
require_once __DIR__ . '/../includes/gateway/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT t.*, i.invoice_number, i.description AS invoice_description, i.currency AS invoice_currency,
        u.full_name AS customer_name, u.email AS customer_email
    FROM payment_transactions t
    LEFT JOIN invoices i ON i.id = t.invoice_id
    LEFT JOIN users u ON u.id = i.user_id
    WHERE t.id = ? LIMIT 1");
$stmt->execute([$id]);
$tx = $stmt->fetch();

/** Recursively redact secret-looking keys from a decoded gateway payload before display. */
function gateway_redact(array $data): array {
    $redactKeys = ['api_key','apikey','api_secret','secret','password','passwd','token','access_token',
                   'refresh_token','webhook_secret','signature','secretkey','secret_key','card','cvv','cvc','pan','private_key'];
    $out = [];
    foreach ($data as $k => $v) {
        $lk = strtolower((string)$k);
        $isSecret = false;
        foreach ($redactKeys as $rk) { if (strpos($lk, $rk) !== false) { $isSecret = true; break; } }
        if ($isSecret) { $out[$k] = '••••••'; continue; }
        $out[$k] = is_array($v) ? gateway_redact($v) : $v;
    }
    return $out;
}
?>
<div class="app-content">
  <div class="pg-head"><div>
    <h1><?= is_rtl() ? 'تفاصيل عملية دفع' : 'Gateway transaction detail' ?></h1>
    <p><a href="/admin/gateway-transactions.php">← <?= is_rtl() ? 'رجوع للعمليات' : 'Back to transactions' ?></a></p>
  </div></div>

  <?php if (!$tx): ?>
    <div class="form-alert error"><?= is_rtl() ? 'العملية غير موجودة.' : 'Transaction not found.' ?></div>
  <?php else: ?>
    <div class="dash-card">
      <div class="grid-2">
        <div><small>Payment ID</small><div><b>#<?= (int)$tx['id'] ?></b></div></div>
        <div><small><?= is_rtl() ? 'الفاتورة' : 'Invoice' ?></small><div><b><?= htmlspecialchars((string)($tx['invoice_number'] ?? '—')) ?></b></div></div>
        <div><small><?= is_rtl() ? 'العميل' : 'Customer' ?></small><div><b><?= htmlspecialchars((string)($tx['customer_name'] ?? '—')) ?></b></div></div>
        <div><small><?= is_rtl() ? 'البوابة' : 'Gateway' ?></small><div><b><?= htmlspecialchars((string)$tx['gateway']) ?></b></div></div>
        <div><small><?= is_rtl() ? 'البيئة' : 'Environment' ?></small><div><b><?= htmlspecialchars((string)($tx['environment'] ?? '—')) ?></b></div></div>
        <div><small><?= is_rtl() ? 'المبلغ' : 'Amount' ?></small><div><b><?= htmlspecialchars((string)($tx['amount'] ?? '—')) ?></b></div></div>
        <div><small><?= is_rtl() ? 'العملة' : 'Currency' ?></small><div><b><?= htmlspecialchars((string)($tx['currency'] ?? $tx['invoice_currency'] ?? '—')) ?></b></div></div>
        <div><small><?= is_rtl() ? 'رقم عملية البوابة' : 'Gateway transaction ID' ?></small><div><b><?= htmlspecialchars((string)($tx['gateway_transaction_id'] ?? '—')) ?></b></div></div>
        <div><small><?= is_rtl() ? 'مرجع البوابة' : 'Gateway reference' ?></small><div><b><?= htmlspecialchars((string)($tx['gateway_order_ref'] ?? '—')) ?></b></div></div>
        <div><small><?= is_rtl() ? 'الحالة' : 'Status' ?></small><div><b><?= htmlspecialchars((string)($tx['status'] ?? '—')) ?></b></div></div>
        <div><small><?= is_rtl() ? 'أُنشئت' : 'Created at' ?></small><div><b><?= htmlspecialchars((string)$tx['created_at']) ?></b></div></div>
        <div><small><?= is_rtl() ? 'دُفعت' : 'Paid at' ?></small><div><b><?= htmlspecialchars((string)($tx['paid_at'] ?? '—')) ?></b></div></div>
        <div><small><?= is_rtl() ? 'تم التحقق' : 'Verified at' ?></small><div><b><?= htmlspecialchars((string)($tx['verified_at'] ?? '—')) ?></b></div></div>
        <div><small><?= is_rtl() ? 'استُلم الـCallback' : 'Callback received' ?></small><div><b><?= htmlspecialchars((string)($tx['callback_received_at'] ?? '—')) ?></b></div></div>
      </div>
    </div>

    <div class="dash-card">
      <h3 style="margin-bottom:10px"><?= is_rtl() ? 'استجابة البوابة (بدون بيانات حساسة)' : 'Gateway response (secrets removed)' ?></h3>
      <?php
      $raw = (string)($tx['raw_payload'] ?? '');
      $decoded = $raw !== '' ? json_decode($raw, true) : null;
      if (is_array($decoded)) {
          $pretty = json_encode(gateway_redact($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      } elseif ($raw !== '') {
          $pretty = '(non-JSON payload, ' . strlen($raw) . ' bytes)';
      } else {
          $pretty = '';
      }
      ?>
      <?php if ($pretty === ''): ?>
        <p style="color:var(--muted)"><?= is_rtl() ? 'لا توجد حمولة مخزّنة لهذه العملية.' : 'No stored payload for this transaction.' ?></p>
      <?php else: ?>
        <pre style="white-space:pre-wrap;word-break:break-word;background:var(--surface-2,#f6f7f9);padding:14px;border-radius:8px;font-size:.8rem;max-height:420px;overflow:auto"><?= htmlspecialchars($pretty) ?></pre>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
