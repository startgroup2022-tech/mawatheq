<?php
/**
 * ============================================================
 * /api/v1/lawyer-dashboard.php — لوحة تحكم المحامي في التطبيق (Execution 57)
 * MAWATHEQ — the lawyer's dashboard figures, for the mobile app
 *
 * GET /api/v1/lawyer-dashboard.php   (lawyer token only)
 *
 * The web dashboard (`lawyer/dashboard.php`) and this endpoint read the same
 * tables, so the app and the console can never disagree about a count. Every
 * figure is a live query against the lawyer's own tenant scope — the same
 * `tenant_owner_where_for_lawyer()` predicate the console uses, so a firm lawyer
 * sees the firm's work and a solo lawyer sees only their own.
 *
 * The scope is built once per alias. It returns an alias-qualified predicate, so
 * asking for a different alias is how a query scopes a second table — substituting
 * a table name inside the returned SQL would silently leave the alias untouched.
 * ============================================================
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/tenant.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/wallet.php';

$auth = api_authenticate($pdo);
if ($auth['type'] !== 'lawyer') api_error('this endpoint is for lawyers', 403);

$lawyerId = (int)$auth['id'];

// One scope per alias actually used below. `consultations` and `cases` both carry
// lawyer_id + firm_id, so the same helper scopes both.
$scopeCons = tenant_owner_where_for_lawyer($pdo, $lawyerId, 'c');
$scopeCase = tenant_owner_where_for_lawyer($pdo, $lawyerId, 'cs');

/** Run a scoped scalar query. */
function lawyer_dash_count(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

// Consultations by status. One query, pivoted in PHP, so the four figures are read
// from the same instant rather than four round-trips that could disagree.
$consultations = $pdo->prepare("SELECT c.status, COUNT(*) AS n FROM consultations c WHERE {$scopeCons['sql']} GROUP BY c.status");
$consultations->execute($scopeCons['params']);
$byStatus = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($consultations->fetchAll() as $row) {
    $byStatus[$row['status']] = (int)$row['n'];
}

// Upcoming appointments: confirmed, scheduled from now on, soonest first.
$upcoming = $pdo->prepare(
    "SELECT c.id, c.topic, c.consultation_type, c.scheduled_at, c.status, u.full_name AS client_name
       FROM consultations c JOIN users u ON u.id=c.user_id
      WHERE {$scopeCons['sql']} AND c.status='confirmed' AND c.scheduled_at >= NOW()
      ORDER BY c.scheduled_at ASC LIMIT 5"
);
$upcoming->execute($scopeCons['params']);
$upcomingRows = $upcoming->fetchAll();

// Active cases: not closed/archived.
$activeCases = lawyer_dash_count(
    $pdo,
    "SELECT COUNT(*) FROM cases cs WHERE {$scopeCase['sql']} AND cs.status NOT IN ('closed','archived')",
    $scopeCase['params']
);

// Clients: distinct clients across this lawyer's/firm's consultations and cases.
$clientCount = lawyer_dash_count(
    $pdo,
    "SELECT COUNT(DISTINCT uid) FROM (
        SELECT c.user_id AS uid FROM consultations c WHERE {$scopeCons['sql']}
        UNION SELECT cs.user_id AS uid FROM cases cs WHERE {$scopeCase['sql']}
     ) t",
    array_merge($scopeCons['params'], $scopeCase['params'])
);

// Open tasks. Tasks live on cases (`case_tasks`), which inherit their tenant from the
// case, so they are counted through the case join — counting by lawyer_id would show a
// firm lawyer only their own and understate the firm's queue.
$openTasks = lawyer_dash_count(
    $pdo,
    "SELECT COUNT(*) FROM case_tasks t JOIN cases cs ON cs.id=t.case_id WHERE {$scopeCase['sql']} AND t.status='pending'",
    $scopeCase['params']
);

// Unread notifications.
$unread = unread_notifications_count($pdo, 'lawyer', $lawyerId);

// Wallet summary from the ledger.
$wallet = wallet_ensure($pdo, $lawyerId);
$balances = wallet_balances($pdo, (int)$wallet['id'], (string)$wallet['currency']);
$totals = wallet_totals($pdo, (int)$wallet['id']);

// Recent activity: the newest few rows from each source, merged and sorted.
$activity = [];
$actCons = $pdo->prepare("SELECT c.id, c.topic, c.status, c.created_at FROM consultations c WHERE {$scopeCons['sql']} ORDER BY c.id DESC LIMIT 5");
$actCons->execute($scopeCons['params']);
foreach ($actCons->fetchAll() as $r) {
    $activity[] = ['kind' => 'consultation', 'id' => (int)$r['id'], 'title' => $r['topic'] ?: 'استشارة', 'status' => $r['status'], 'at' => $r['created_at']];
}
$actCases = $pdo->prepare("SELECT cs.id, cs.title, cs.status, cs.created_at FROM cases cs WHERE {$scopeCase['sql']} ORDER BY cs.id DESC LIMIT 5");
$actCases->execute($scopeCase['params']);
foreach ($actCases->fetchAll() as $r) {
    $activity[] = ['kind' => 'case', 'id' => (int)$r['id'], 'title' => $r['title'], 'status' => $r['status'], 'at' => $r['created_at']];
}
usort($activity, static fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
$activity = array_slice($activity, 0, 8);

$ctx = tenant_context_for_lawyer($pdo, $lawyerId);

api_json([
    'ok' => true,
    'lawyer' => [
        'id' => $lawyerId,
        'role' => $ctx['is_firm'] ? ($ctx['role'] ?? 'lawyer') : 'solo',
        'firm_id' => $ctx['firm_id'],
        'is_firm' => (bool)$ctx['is_firm'],
    ],
    'consultations' => [
        'pending' => $byStatus['pending'],
        'confirmed' => $byStatus['confirmed'],
        'completed' => $byStatus['completed'],
        'cancelled' => $byStatus['cancelled'],
        'total' => array_sum($byStatus),
    ],
    'upcoming_appointments' => $upcomingRows,
    'cases_active' => $activeCases,
    'clients' => $clientCount,
    'tasks_open' => $openTasks,
    'notifications_unread' => $unread,
    'wallet' => [
        'currency' => $balances['currency'],
        'available_minor' => $balances['available_minor'],
        'pending_minor' => $balances['pending_minor'],
        'available' => wallet_from_minor($balances['available_minor'], $balances['currency']),
        'pending' => wallet_from_minor($balances['pending_minor'], $balances['currency']),
        'gross_earned' => wallet_from_minor($totals['gross_earned_minor'], $balances['currency']),
        'commission' => wallet_from_minor($totals['commission_minor'], $balances['currency']),
        'withdrawn' => wallet_from_minor($totals['withdrawn_minor'], $balances['currency']),
    ],
    'recent_activity' => $activity,
]);
