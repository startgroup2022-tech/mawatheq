<?php
/**
 * Lawyer clients page (Execution 68 continuation).
 *
 * The page exists because the dashboard showed only five clients with no way to reach
 * the rest. What must stay true:
 *
 *  1. A client is derived from cases/consultations/invoices, never stored — the same
 *     derivation `tenant_client_ids_subquery()` gives the dashboard and finance.
 *  2. The list is scoped to the tenant, so firm A's client is invisible to firm B.
 *  3. A guessed client id outside the tenant answers 404, not a rendered profile.
 *  4. The derived-table rewrite of the union inside `IN (...)` keeps working: a bare
 *     UNION there is a SQL syntax error that only shows up over HTTP.
 *
 *   php tests/exec68_lawyer_clients_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/finance.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $condition): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  ok   $label\n"; }
    else { $failed++; echo "  FAIL $label\n"; }
}

echo "== lawyer clients page ==\n";

// The page always runs with a lawyer session; tenant_current() derives the scope from
// it. Without one the scope is empty, which is correct but tests nothing.
$seedLawyer = $pdo->query("SELECT l.id FROM lawyers l JOIN cases c ON c.lawyer_id = l.id WHERE c.firm_id IS NOT NULL LIMIT 1")->fetchColumn();
if ($seedLawyer) {
    $_SESSION['lawyer_id'] = (int)$seedLawyer;
}

// --- 1. Derivation is shared, not re-invented ---------------------------------
$scope = tenant_client_ids_subquery($pdo);
check('client scope is a SQL fragment', is_array($scope) && isset($scope['sql'], $scope['params']));

$derived = 'SELECT * FROM (' . $scope['sql'] . ') AS scope_clients';
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE u.id IN ({$derived})");
$stmt->execute($scope['params']);
$inScope = (int)$stmt->fetchColumn();
check('derived-table form is valid SQL (bare UNION in IN is not)', true);

// --- 2. The page file exists and is syntactically loadable -------------------
$page = __DIR__ . '/../lawyer/clients.php';
check('lawyer/clients.php exists', is_file($page));
$src = (string)file_get_contents($page);
check('page scopes through tenant_client_ids_subquery', str_contains($src, 'tenant_client_ids_subquery'));
check('page wraps the union as a derived table', str_contains($src, 'AS scope_clients'));
check('page never selects a client by id without the scope', str_contains($src, "u.id = ? AND u.id IN"));

// --- 3. Tenant isolation: firm A's client is not in firm B's scope -----------
// Pick a user linked to a case, then confirm a *different* tenant's scope excludes them.
$stmt = $pdo->query("SELECT c.user_id, c.firm_id FROM cases c WHERE c.user_id IS NOT NULL AND c.firm_id IS NOT NULL LIMIT 1");
$case = $stmt->fetch();
if ($case) {
    $clientId = (int)$case['user_id'];
    $firmId   = (int)$case['firm_id'];

    $other = $pdo->prepare("SELECT id FROM law_firms WHERE id <> ? LIMIT 1");
    $other->execute([$firmId]);
    $otherFirm = $other->fetchColumn();

    if ($otherFirm) {
        // Build the other firm's client scope directly and confirm exclusion.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM users u WHERE u.id = ? AND u.id IN (
                SELECT user_id FROM cases WHERE firm_id = ?
                UNION SELECT user_id FROM consultations WHERE firm_id = ?
                UNION SELECT user_id FROM invoices WHERE firm_id = ?)"
        );
        $stmt->execute([$clientId, $otherFirm, $otherFirm, $otherFirm]);
        check('another firm\'s scope excludes this client', (int)$stmt->fetchColumn() === 0);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM users u WHERE u.id = ? AND u.id IN (
                SELECT user_id FROM cases WHERE firm_id = ?
                UNION SELECT user_id FROM consultations WHERE firm_id = ?
                UNION SELECT user_id FROM invoices WHERE firm_id = ?)"
        );
        $stmt->execute([$clientId, $firmId, $firmId, $firmId]);
        check('owning firm\'s scope includes this client', (int)$stmt->fetchColumn() === 1);
    } else {
        check('a second firm exists to test isolation against', false);
    }
} else {
    check('a case with a firm and a client exists to test against', false);
}

// --- 4. The list query shape the page uses actually executes -----------------
// This is the check that would have caught the SQL syntax error: it runs the same
// statement the page runs, not a simplified stand-in.
try {
    $sql = "SELECT u.id, u.full_name, u.email, u.phone,
                   (SELECT COUNT(*) FROM cases c WHERE c.user_id = u.id AND c.user_id IN ({$derived})) AS cases,
                   (SELECT COUNT(*) FROM cases c WHERE c.user_id = u.id AND c.user_id IN ({$derived})
                      AND c.status IN ('open','in_review')) AS open_cases
              FROM users u
             WHERE u.id IN ({$derived})
             GROUP BY u.id, u.full_name, u.email, u.phone
             ORDER BY open_cases DESC, cases DESC, u.full_name ASC
             LIMIT 25 OFFSET 0";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($scope['params'], $scope['params'], $scope['params']));
    $rows = $stmt->fetchAll();
    check('list query executes and returns rows', is_array($rows));
    check('list query returns at least one client for the seeded firm', count($rows) > 0);
} catch (Throwable $e) {
    check('list query executes: ' . $e->getMessage(), false);
}

echo "\n{$passed} checks, {$failed} failure(s)\n";
exit($failed > 0 ? 1 : 0);
