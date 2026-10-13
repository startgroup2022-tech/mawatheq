<?php
/**
 * GET /api/v1/invoices.php → قائمة فواتيري (عميل أو محامٍ حسب نوع الحساب)
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/invoice_time_items.php';
require_once __DIR__ . '/../../includes/tenant.php';
require_once __DIR__ . '/../../includes/finance.php';
$auth = api_authenticate($pdo);
if (($auth['type'] ?? '') === 'integration') {
    $firmId = $auth['firm_id'];
    $firmClause = $firmId === null ? '1=1' : 'i.firm_id = ?';
    $stmt = $pdo->prepare('
        SELECT i.*, u.full_name AS client_name, c.title AS case_title
        FROM invoices i
        JOIN users u ON u.id = i.user_id
        LEFT JOIN cases c ON c.id = i.case_id
        WHERE ' . $firmClause . ' AND i.is_draft=0
        ORDER BY i.created_at DESC
    ');
    $stmt->execute($firmId === null ? [] : [$firmId]);
    $invoices = $stmt->fetchAll();
    foreach ($invoices as &$inv) {
        $inv['balance'] = finance_invoice_balance($inv);
        $inv['is_overdue'] = finance_display_status($inv) === 'overdue';
    }
    unset($inv);
    api_json(['ok' => true, 'invoices' => $invoices]);
}

if ($auth['type'] === 'client') {
    $stmt = $pdo->prepare('
        SELECT i.*, l.full_name AS lawyer_name, c.title AS case_title
        FROM invoices i
        JOIN lawyers l ON l.id = i.lawyer_id
        LEFT JOIN cases c ON c.id = i.case_id
        WHERE i.user_id = ? AND i.is_draft=0
        ORDER BY i.created_at DESC
    ');
    $stmt->execute([$auth['id']]);
} else {
    $invScope = tenant_owner_where_for_lawyer($pdo, $auth['id'], 'i');
    $stmt = $pdo->prepare('
        SELECT i.*, u.full_name AS client_name, c.title AS case_title
        FROM invoices i
        JOIN users u ON u.id = i.user_id
        LEFT JOIN cases c ON c.id = i.case_id
        WHERE ' . $invScope['sql'] . '
        ORDER BY i.created_at DESC
    ');
    $stmt->execute($invScope['params']);
}
$invoices = $stmt->fetchAll();
$timeItemsByInvoice=invoice_time_items($pdo,array_column($invoices,'id'));
foreach($invoices as &$invoice){$invoice['time_items']=$timeItemsByInvoice[(int)$invoice['id']]??[];}
unset($invoice);

// نُلحق حالة "متأخرة" محسوبة لحظياً (نفس منطق الموقع)، دون تعديل الحالة المخزّنة
foreach ($invoices as &$inv) {
    $inv['balance'] = finance_invoice_balance($inv);
    $inv['is_overdue'] = finance_display_status($inv) === 'overdue';
}

// A lawyer also gets a tenant financial summary so the mobile app can show totals without extra calls.
$summary = null;
if ($auth['type'] === 'lawyer') {
    $summary = finance_summary($pdo, tenant_owner_where_for_lawyer($pdo, $auth['id'], 'i'));
}

api_json(['ok' => true, 'invoices' => $invoices, 'summary' => $summary]);
