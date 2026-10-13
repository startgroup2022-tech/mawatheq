<?php
/**
 * نقطة استقبال إشعارات N-Genius (Webhooks) — تحدّث حالة الفاتورة فوراً
 * عند نجاح الدفع، دون انتظار عودة العميل لصفحة payment-return.php.
 * يجب تسجيل هذا الرابط في بوابة N-Genius: Settings → Integrations → Webhooks
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/ngenius.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/finance.php';
header('Content-Type: application/json');

$settings = ngenius_settings($pdo);

// تحقق من الترويسة السرية (حماية من إشعارات مزوّرة - البوابة لا توقّع الحمولة رقمياً).
// N-Genius does not sign payloads, so this shared secret is the only way to prove an
// event really came from the gateway. If it is not configured we must not trust inbound
// events: a spoofed "CAPTURED" payload for a known order reference would otherwise mark
// an unpaid invoice as paid. Reject when unset, or when the provided token does not match.
$expectedSecret = (string)($settings['ngenius_webhook_secret'] ?? '');
$received = (string)($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');
if ($expectedSecret === '') {
    error_log('N-Genius webhook rejected: N-Genius is not fully configured (missing webhook secret).');
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'webhook secret is not configured']);
    exit;
}
if (!hash_equals($expectedSecret, $received)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid webhook secret']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || empty($payload['event']) || empty($payload['order']['reference'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid payload']);
    exit;
}

$event = (string)$payload['event'];
$orderRef = (string)$payload['order']['reference'];

// Replay protection: the exact payload is stored once. If it is already present, this is a
// duplicate delivery (or a replayed capture) and must not move money or status again.
$eventHash = hash('sha256', $raw);

$stmt = $pdo->prepare('SELECT * FROM invoices WHERE gateway_order_ref = ? AND is_draft=0');
$stmt->execute([$orderRef]);
$invoice = $stmt->fetch();

// The gateway returns the amount in minor units (halalas for SAR, fils for 3-decimal
// currencies), so divide by the configured currency's minor-unit factor.
$minorUnits = ngenius_minor_units(ngenius_currency($settings));
$amountValue = isset($payload['order']['amount']['value']) ? $payload['order']['amount']['value'] / $minorUnits : null;

try {
    $pdo->prepare('INSERT INTO payment_transactions (invoice_id, firm_id, gateway, gateway_order_ref, event_type, event_hash, amount, raw_payload) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([
            $invoice ? $invoice['id'] : null,
            $invoice ? $invoice['firm_id'] : null,
            'ngenius', $orderRef, $event, $eventHash, $amountValue, $raw,
        ]);
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? 0) === 1062) {
        // Duplicate delivery — acknowledge without reprocessing.
        http_response_code(200);
        echo json_encode(['ok' => true, 'duplicate' => true]);
        exit;
    }
    error_log('N-Genius webhook event log failed: ' . $e->getMessage());
}

if ($invoice) {
    if (ngenius_is_paid_state($event) && in_array($invoice['status'], ['unpaid', 'partially_paid', 'overdue'], true)) {
        // Reconcile a real payment row. The gateway order reference is the idempotency key, so
        // the webhook and the return/status endpoints converge on a single payment record.
        // Prefer the amount the gateway actually charged; fall back to the order-creation row
        // (covers partial payments) and only then to the full invoice total.
        $amount = $amountValue;
        if ($amount === null) {
            $amtStmt = $pdo->prepare("SELECT amount FROM payment_transactions WHERE gateway_order_ref=? AND event_type='ORDER_CREATED' ORDER BY id DESC LIMIT 1");
            $amtStmt->execute([$orderRef]);
            $orderAmount = $amtStmt->fetchColumn();
            $amount = ($orderAmount !== false && $orderAmount !== null) ? (float)$orderAmount : finance_invoice_balance($invoice);
        }
        $recorded = finance_record_payment($pdo, (int)$invoice['id'], $amount, [
            'method' => 'ngenius',
            'reference' => $orderRef,
            'idempotency_key' => 'ngenius:' . $orderRef,
            'recorded_by_type' => 'system',
            'notes' => 'N-Genius event: ' . $event,
        ]);
        if (!empty($recorded['ok']) && empty($recorded['duplicate'])) {
            notify($pdo, 'lawyer', $invoice['lawyer_id'], 'تم استلام دفعة إلكترونية', $invoice['description'], '/lawyer/invoices.php');
        } elseif (empty($recorded['ok']) && ($recorded['error'] ?? '') !== 'invoice_already_settled') {
            error_log('N-Genius webhook payment reconciliation failed for order ' . $orderRef . ': ' . ($recorded['error'] ?? 'unknown'));
        }
    } elseif (in_array($event, ['DECLINED','PURCHASE_DECLINED','PURCHASE_FAILED','AUTHORISATION_FAILED'], true) && $invoice['status'] === 'unpaid') {
        // keep unpaid; decision stays with the lawyer/admin
    }
}

// يجب الرد بـ 200 خلال 15 ثانية لتأكيد الاستلام حسب توثيق N-Genius
http_response_code(200);
echo json_encode(['ok' => true]);
