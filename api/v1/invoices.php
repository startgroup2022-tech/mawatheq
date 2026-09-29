<?php
/**
 * GET /api/v1/invoices.php → قائمة فواتيري (عميل أو محامٍ حسب نوع الحساب)
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/invoice_time_items.php';
require_once __DIR__ . '/../../includes/tenant.php';
$auth = api_authenticate($pdo);

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
    $inv['is_overdue'] = $inv['status'] === 'unpaid' && $inv['due_date'] && strtotime($inv['due_date']) < time();
}

api_json(['ok' => true, 'invoices' => $invoices]);
