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
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/gateway/bootstrap.php';
require_once __DIR__ . '/../includes/wallet.php';

$id = (int)($_GET['id'] ?? 0);
$refundError = null;
$refundSuccess = null;

// Refund action. CSRF is validated centrally in config.php for every POST. The refund goes
// through the gateway adapter (never a hand-written HTTP call), and for a consultation
// payment the lawyer's wallet is reversed in the same step so the ledger matches the money.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refund') {
    $txId = (int)($_POST['transaction_id'] ?? 0);
    $amountInput = trim((string)($_POST['refund_amount'] ?? ''));
    $txStmt = $pdo->prepare('SELECT * FROM payment_transactions WHERE id=? LIMIT 1');
    $txStmt->execute([$txId]);
    $refundTx = $txStmt->fetch();

    if (!$refundTx) {
        $refundError = is_rtl() ? 'العملية غير موجودة.' : 'Transaction not found.';
    } elseif (($refundTx['status'] ?? '') !== 'PAID' && !gateway_is_paid_state($refundTx['status'] ?? '')) {
        $refundError = is_rtl() ? 'لا يمكن استرداد عملية غير مدفوعة.' : 'Only a paid transaction can be refunded.';
    } else {
        $adapter = gateway_get((string)$refundTx['gateway'], $pdo);
        if (!$adapter || !$adapter->supportsRefund()) {
            $refundError = is_rtl() ? 'هذه البوابة لا تدعم الاسترداد.' : 'This gateway does not support refunds.';
        } else {
            $amount = $amountInput === '' ? null : (float)$amountInput;
            if ($amount !== null && $amount <= 0) {
                $refundError = is_rtl() ? 'أدخل مبلغاً صحيحاً للاسترداد.' : 'Enter a valid refund amount.';
            } else {
                $refund = $adapter->refundPayment([
                    'reference' => (string)$refundTx['gateway_order_ref'],
                    'currency' => (string)$refundTx['currency'],
                    'amount' => $refundTx['amount'],
                ], $amount);
                if (!empty($refund['ok'])) {
                    try {
                        $pdo->prepare('INSERT INTO payment_transactions
                            (invoice_id, consultation_id, gateway, gateway_order_ref, event_type, amount, currency, status, environment, response_message, verified_at)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                            ->execute([
                                $refundTx['invoice_id'], $refundTx['consultation_id'], $refundTx['gateway'],
                                $refundTx['gateway_order_ref'], 'REFUND', $refund['amount'] ?? $amount,
                                $refundTx['currency'], 'REFUNDED', $refundTx['environment'],
                                'admin refund', date('Y-m-d H:i:s'),
                            ]);
                    } catch (Throwable $e) { error_log('refund log failed: ' . $e->getMessage()); }

                    // A consultation payment credited the lawyer's wallet; reverse it so the
                    // ledger reflects the money that was actually returned.
                    if (!empty($refundTx['consultation_id'])) {
                        $pdo->prepare("UPDATE consultations SET payment_status='refunded' WHERE id=? AND payment_status='paid'")
                            ->execute([(int)$refundTx['consultation_id']]);
                        try { wallet_reverse_consultation_payment($pdo, (int)$refundTx['consultation_id'], $refund['amount'] ?? null); }
                        catch (Throwable $e) { error_log('refund wallet reversal failed: ' . $e->getMessage()); }
                    }
                    log_action($pdo, 'استرداد عملية دفع #' . $txId . ' (' . $refundTx['gateway'] . ')', 'payment_transaction', $txId);
                    $refundSuccess = is_rtl() ? 'تم تنفيذ الاسترداد بنجاح.' : 'Refund completed successfully.';
                } else {
                    $refundError = is_rtl() ? 'تعذّر تنفيذ الاسترداد: ' : 'Refund failed: ';
                    $refundError .= htmlspecialchars((string)($refund['error'] ?? 'unknown'));
                }
            }
        }
    }
}

$pageTitle = is_rtl() ? 'تفاصيل عملية دفع' : 'Gateway transaction detail';
require_once __DIR__ . '/includes/admin_header.php';
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
    <?php if ($refundError): ?><div class="form-alert error"><?= $refundError ?></div><?php endif; ?>
    <?php if ($refundSuccess): ?><div class="form-alert success"><?= $refundSuccess ?></div><?php endif; ?>
    <?php
    $isPaid = gateway_is_paid_state($tx['status'] ?? '');
    $adapter = gateway_get((string)$tx['gateway'], $pdo);
    $canRefund = $isPaid && $adapter && $adapter->supportsRefund();
    ?>
    <?php if ($canRefund): ?>
      <div class="dash-card">
        <h3 style="margin-bottom:10px"><?= is_rtl() ? 'استرداد المبلغ' : 'Refund' ?></h3>
        <p style="color:var(--muted);margin-bottom:12px"><?= is_rtl()
          ? 'اترك المبلغ فارغاً لاسترداد كامل المبلغ المدفوع، أو أدخل مبلغاً جزئياً.'
          : 'Leave the amount empty to refund the full captured amount, or enter a partial amount.' ?></p>
        <form method="post" onsubmit="return confirm('<?= is_rtl() ? 'تأكيد تنفيذ الاسترداد؟' : 'Confirm the refund?' ?>');">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="action" value="refund">
          <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
          <div class="field" style="max-width:220px">
            <label><?= is_rtl() ? 'المبلغ (اتركه فارغاً للاسترداد الكامل)' : 'Amount (empty = full refund)' ?></label>
            <input type="number" name="refund_amount" step="0.01" min="0" dir="ltr" inputmode="decimal">
          </div>
          <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'تنفيذ الاسترداد' : 'Process refund' ?></button>
        </form>
      </div>
    <?php endif; ?>
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
