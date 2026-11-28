<?php
/**
 * GET /api/v1/invoice.php — تفاصيل فاتورة واحدة (يحتاج توكن)
 *
 * The account screen and the transaction history both link here. Ownership is the
 * whole point: a client may only ever read their OWN invoice, and the WHERE clause
 * carries the user id, so a guessed id returns 404 rather than another customer's
 * invoice. A lawyer reads their tenant's invoice through the same endpoint with the
 * tenant scope (the same predicate the invoice list uses).
 *
 *   ?id=123
 *
 * The response carries the invoice header, its line items, its recorded payments and
 * a printable receipt URL. It never contains another party's private data.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/invoice_time_items.php';
require_once __DIR__ . '/../../includes/finance.php';
require_once __DIR__ . '/../../includes/tenant.php';

$auth = api_authenticate($pdo);
$invoiceId = (int)($_GET['id'] ?? 0);
if ($invoiceId <= 0) api_error('id is required', 400);

if ($auth['type'] === 'client') {
    $stmt = $pdo->prepare('SELECT i.*, l.full_name AS lawyer_name, c.title AS case_title
        FROM invoices i
        JOIN lawyers l ON l.id = i.lawyer_id
        LEFT JOIN cases c ON c.id = i.case_id
        WHERE i.id = ? AND i.user_id = ? AND i.is_draft = 0');
    $stmt->execute([$invoiceId, (int)$auth['id']]);
} else {
    $scope = tenant_owner_where_for_lawyer($pdo, (int)$auth['id'], 'i');
    $stmt = $pdo->prepare('SELECT i.*, u.full_name AS client_name, c.title AS case_title
        FROM invoices i
        JOIN users u ON u.id = i.user_id
        LEFT JOIN cases c ON c.id = i.case_id
        WHERE i.id = ? AND ' . $scope['sql']);
    $stmt->execute(array_merge([$invoiceId], $scope['params']));
}

$invoice = $stmt->fetch();
if (!$invoice) api_error('الفاتورة غير موجودة.', 404);

$items = $pdo->prepare('SELECT id, description, quantity, unit_price, total FROM invoice_items WHERE invoice_id = ? ORDER BY id');
$items->execute([$invoiceId]);

$payments = $pdo->prepare('SELECT id, amount, currency, method, status, reference, paid_at FROM invoice_payments WHERE invoice_id = ? ORDER BY paid_at DESC');
$payments->execute([$invoiceId]);

$invoice['balance'] = finance_invoice_balance($invoice);
$invoice['display_status'] = finance_display_status($invoice);

api_json([
    'ok'       => true,
    'invoice'  => $invoice,
    'items'    => $items->fetchAll(),
    'payments' => $payments->fetchAll(),
    // The website renders a printable invoice; the app opens it in the browser rather
    // than re-implementing a PDF generator it does not have.
    'receipt_url' => rtrim(defined('SITE_URL') ? SITE_URL : 'https://mawatheq.com', '/')
        . '/invoice-print.php?id=' . $invoiceId,
]);
