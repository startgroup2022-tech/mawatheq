<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/gateway/bootstrap.php';
require_once __DIR__ . '/includes/gateway/payment_service.php';
require_once __DIR__ . '/includes/finance.php';

$invoiceId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT i.*, u.full_name, u.email FROM invoices i JOIN users u ON u.id = i.user_id WHERE i.id=? AND i.user_id=? AND i.is_draft=0");
$stmt->execute([$invoiceId, $_SESSION['user_id']]);
$invoice = $stmt->fetch();

$pageError = null;

if (!$invoice) {
    $pageError = is_rtl() ? 'الفاتورة غير موجودة.' : 'Invoice not found.';
} elseif ($invoice['status'] === 'paid' || finance_invoice_balance($invoice) <= 0) {
    header('Location: /invoices.php'); exit;
} elseif (!in_array($invoice['status'], ['unpaid','partially_paid','overdue'], true)) {
    $pageError = is_rtl() ? 'هذه الفاتورة غير متاحة للدفع.' : 'This invoice is not payable.';
} else {
    // Resolve whichever gateway is enabled for this invoice's tenant and supports its currency.
    // The invoice/finance core is untouched: this only chooses where to send the customer.
    $gateway = gateway_for_new_payment($pdo, $invoice);
    if (!$gateway) {
        $pageError = is_rtl() ? 'الدفع الإلكتروني غير مفعّل حالياً. تواصل مع الإدارة أو المحامي لطرق دفع بديلة.' : 'Online payment is not enabled yet. Contact support or the lawyer for alternative payment methods.';
    } else {
        $started = gateway_start_invoice_payment($pdo, $invoice, [
            'customer_name' => $invoice['full_name'],
            'customer_email' => $invoice['email'],
            'language' => current_lang(),
        ]);
        if (!empty($started['ok']) && !empty($started['redirect_url'])) {
            header('Location: ' . $started['redirect_url']);
            exit;
        }
        if (($started['error'] ?? '') === 'unsupported_currency') {
            $pageError = is_rtl()
                ? 'الدفع الإلكتروني غير متاح لهذه العملة. تواصل مع المحامي لطرق دفع بديلة.'
                : 'Online payment is not available for this invoice currency. Contact the lawyer for alternative payment methods.';
        } else {
            $pageError = is_rtl() ? 'تعذّر بدء عملية الدفع. حاول لاحقاً أو تواصل مع المحامي.' : 'Could not start the payment. Try again later or contact the lawyer.';
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
