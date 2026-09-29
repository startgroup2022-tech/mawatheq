<?php
/**
 * نقطة استقبال إشعارات N-Genius (Webhooks) — تحدّث حالة الفاتورة فوراً
 * عند نجاح الدفع، دون انتظار عودة العميل لصفحة payment-return.php.
 * يجب تسجيل هذا الرابط في بوابة N-Genius: Settings → Integrations → Webhooks
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/ngenius.php';
require_once __DIR__ . '/includes/notifications.php';
header('Content-Type: application/json');

$settings = ngenius_settings($pdo);

// تحقق من الترويسة السرية إن كانت مُعدّة (حماية من إشعارات مزوّرة - البوابة لا توقّع الحمولة رقمياً)
$expectedSecret = $settings['ngenius_webhook_secret'] ?? '';
if ($expectedSecret !== '') {
    $received = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '';
    if (!hash_equals($expectedSecret, $received)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid webhook secret']);
        exit;
    }
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || empty($payload['event']) || empty($payload['order']['reference'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid payload']);
    exit;
}

$event = $payload['event'];
$orderRef = $payload['order']['reference'];

$stmt = $pdo->prepare('SELECT * FROM invoices WHERE gateway_order_ref = ? AND is_draft=0');
$stmt->execute([$orderRef]);
$invoice = $stmt->fetch();

if ($invoice) {
    $amountValue = isset($payload['order']['amount']['value']) ? $payload['order']['amount']['value'] / 1000 : null;
    $pdo->prepare('INSERT INTO payment_transactions (invoice_id, gateway, gateway_order_ref, event_type, amount, raw_payload) VALUES (?,?,?,?,?,?)')
        ->execute([$invoice['id'], 'ngenius', $orderRef, $event, $amountValue, $raw]);

    if (ngenius_is_paid_state($event) && $invoice['status'] === 'unpaid') {
        $pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW(), payment_method='ngenius' WHERE id=? AND status='unpaid' AND is_draft=0")->execute([$invoice['id']]);
        notify($pdo, 'lawyer', $invoice['lawyer_id'], 'تم استلام دفعة إلكترونية', $invoice['description'], '/lawyer/invoices.php');
    } elseif (in_array($event, ['DECLINED','PURCHASE_DECLINED','PURCHASE_FAILED','AUTHORISATION_FAILED'], true) && $invoice['status'] === 'unpaid') {
        // نُبقي الفاتورة "غير مدفوعة" فقط، دون تغيير الحالة تلقائياً لـ "ملغاة" — القرار يبقى للمحامي/الإدارة
    }
} else {
    // لا يوجد ربط بفاتورة معروفة — نسجّل الحدث فقط لأغراض التتبع دون ربط
    $pdo->prepare('INSERT INTO payment_transactions (invoice_id, gateway, gateway_order_ref, event_type, raw_payload) VALUES (NULL,?,?,?,?)')
        ->execute(['ngenius', $orderRef, $event, $raw]);
}

// يجب الرد بـ 200 خلال 15 ثانية لتأكيد الاستلام حسب توثيق N-Genius
http_response_code(200);
echo json_encode(['ok' => true]);
