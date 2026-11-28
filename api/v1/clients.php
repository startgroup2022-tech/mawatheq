<?php
/**
 * GET /api/v1/clients.php              → موكلو المحامي/المكتب مع عدد القضايا والاستشارات
 * GET /api/v1/clients.php?id=7         → موكل واحد مع قضاياه وفواتيره
 *
 * The client list is *derived*, never a hand-kept table: a user is "my client" because a
 * case, a consultation or an invoice ties them to this tenant. That is the same derivation
 * `tenant_client_ids_subquery()` performs for the web page, so the app and the dashboard
 * cannot disagree about who a client is.
 *
 * The subquery is rebuilt here with the lawyer id passed in rather than read from
 * `$_SESSION`: the REST API authenticates with a Bearer token and has no session, and a
 * version that silently fell back to the session would return an empty list for every app
 * request instead of failing.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/tenant.php';

$auth = api_authenticate($pdo);
if (($auth['type'] ?? '') !== 'lawyer') {
    api_error('هذا العنصر متاح للمحامين فقط.', 403);
}
$lawyerId = (int)$auth['id'];
$ctx = tenant_context_for_lawyer($pdo, $lawyerId);

if (!empty($ctx['firm_id'])) {
    $scopeSql = 'SELECT user_id FROM cases WHERE firm_id=? UNION SELECT user_id FROM consultations WHERE firm_id=? UNION SELECT user_id FROM invoices WHERE firm_id=?';
    $scopeParams = [(int)$ctx['firm_id'], (int)$ctx['firm_id'], (int)$ctx['firm_id']];
} else {
    $scopeSql = 'SELECT user_id FROM cases WHERE lawyer_id=? UNION SELECT user_id FROM consultations WHERE lawyer_id=? UNION SELECT user_id FROM invoices WHERE lawyer_id=?';
    $scopeParams = [$lawyerId, $lawyerId, $lawyerId];
}
// A bare UNION is not valid inside `IN (...)` once an extra predicate is attached, so it is
// wrapped as a derived table. lawyer/clients.php hit exactly this as a MariaDB 1064.
$scopeIn = 'SELECT user_id FROM (' . $scopeSql . ') AS scope_clients';

$clientId = (int)($_GET['id'] ?? 0);

if ($clientId > 0) {
    $stmt = $pdo->prepare(
        "SELECT u.id, u.full_name, u.email, u.phone, u.created_at
           FROM users u
          WHERE u.id = ? AND u.id IN ({$scopeIn})"
    );
    $stmt->execute(array_merge([$clientId], $scopeParams));
    $client = $stmt->fetch();
    if (!$client) api_error('الموكل غير موجود.', 404);

    if (!empty($ctx['firm_id'])) {
        $caseStmt = $pdo->prepare('SELECT id, title, case_type, status, created_at FROM cases WHERE user_id=? AND firm_id=? ORDER BY created_at DESC');
        $caseStmt->execute([$clientId, (int)$ctx['firm_id']]);
        $invStmt = $pdo->prepare('SELECT id, invoice_number, amount, amount_paid, status, currency FROM invoices WHERE user_id=? AND firm_id=? ORDER BY created_at DESC');
        $invStmt->execute([$clientId, (int)$ctx['firm_id']]);
    } else {
        $caseStmt = $pdo->prepare('SELECT id, title, case_type, status, created_at FROM cases WHERE user_id=? AND lawyer_id=? ORDER BY created_at DESC');
        $caseStmt->execute([$clientId, $lawyerId]);
        $invStmt = $pdo->prepare('SELECT id, invoice_number, amount, amount_paid, status, currency FROM invoices WHERE user_id=? AND lawyer_id=? ORDER BY created_at DESC');
        $invStmt->execute([$clientId, $lawyerId]);
    }

    $client['id'] = (int)$client['id'];
    api_json(['ok' => true, 'client' => $client, 'cases' => $caseStmt->fetchAll(), 'invoices' => $invStmt->fetchAll()]);
}

$q = trim((string)($_GET['q'] ?? ''));
$where = "u.id IN ({$scopeIn})";
$params = $scopeParams;
if ($q !== '') {
    $where .= ' AND (u.full_name LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$sql = "SELECT u.id, u.full_name, u.email, u.phone,
               (SELECT COUNT(*) FROM cases c WHERE c.user_id = u.id AND c.user_id IN ({$scopeIn})) AS cases_count,
               (SELECT COUNT(*) FROM consultations k WHERE k.user_id = u.id AND k.user_id IN ({$scopeIn})) AS consultations_count
          FROM users u
         WHERE {$where}
         ORDER BY u.full_name
         LIMIT 200";
$stmt = $pdo->prepare($sql);
// The scope subquery appears three times, so its params are repeated in that order, then the
// search terms.
$stmt->execute(array_merge($scopeParams, $scopeParams, $scopeParams, array_slice($params, count($scopeParams))));
$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['cases_count'] = (int)$r['cases_count'];
    $r['consultations_count'] = (int)$r['consultations_count'];
}
unset($r);

api_json(['ok' => true, 'clients' => $rows, 'total' => count($rows)]);
