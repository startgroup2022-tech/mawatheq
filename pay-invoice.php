<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/ngenius.php';

$invoiceId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT i.*, u.full_name, u.email FROM invoices i JOIN users u ON u.id = i.user_id WHERE i.id=? AND i.user_id=? AND i.is_draft=0");
$stmt->execute([$invoiceId, $_SESSION['user_id']]);
$invoice = $stmt->fetch();

$pageError = null;

if (!$invoice) {
    $pageError = is_rtl() ? 'الفاتورة غير موجودة.' : 'Invoice not found.';
} elseif ($invoice['status'] === 'paid') {
    header('Location: /invoices.php'); exit;
} elseif ($invoice['status'] !== 'unpaid') {
    $pageError = is_rtl() ? 'هذه الفاتورة غير متاحة للدفع.' : 'This invoice is not payable.';
} else {
    $settings = ngenius_settings($pdo);

    if (!ngenius_is_enabled($settings)) {
        $pageError = is_rtl() ? 'الدفع الإلكتروني غير مفعّل حالياً. تواصل مع الإدارة أو المحامي لطرق دفع بديلة.' : 'Online payment is not enabled yet. Contact support or the lawyer for alternative payment methods.';
    } else {
        $tokenResult = ngenius_get_access_token($settings['ngenius_environment'], $settings['ngenius_api_key'], $settings['ngenius_realm_name'] ?: 'ni');

        if (!$tokenResult['ok']) {
            $pageError = $tokenResult['error'];
        } else {
            $redirectUrl = rtrim(SITE_URL, '/') . '/payment-return.php?invoice_id=' . $invoiceId;
            $cancelUrl = rtrim(SITE_URL, '/') . '/invoices.php';

            $order = ngenius_create_order(
                $settings['ngenius_environment'], $settings['ngenius_outlet_ref'], $tokenResult['token'],
                (float)$invoice['amount'], $invoice['email'], $invoice['full_name'],
                $redirectUrl, $cancelUrl, 'INV-' . $invoiceId, current_lang()
            );

            if (!$order['ok']) {
                $pageError = $order['error'];
            } else {
                $pdo->prepare('UPDATE invoices SET gateway=?, gateway_order_ref=? WHERE id=?')->execute(['ngenius', $order['order_reference'], $invoiceId]);
                $pdo->prepare('INSERT INTO payment_transactions (invoice_id, gateway, gateway_order_ref, event_type, amount) VALUES (?,?,?,?,?)')
                    ->execute([$invoiceId, 'ngenius', $order['order_reference'], 'ORDER_CREATED', $invoice['amount']]);

                header('Location: ' . $order['payment_url']);
                exit;
            }
        }
    }
}

// نصل هنا فقط إن حدث خطأ يحتاج عرضه للعميل
require_once __DIR__ . '/includes/app_header.php';
?>
<div class="app-content">
  <div class="form-alert error"><?= htmlspecialchars($pageError) ?></div>
  <a href="/invoices.php" class="btn btn-outline"><?= is_rtl() ? 'رجوع للفواتير' : 'Back to invoices' ?></a>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
