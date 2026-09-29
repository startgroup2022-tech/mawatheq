<?php
/**
 * Phase 2 multi-tenant isolation test.
 *
 * Exercises the real tenant helpers (includes/tenant.php) against the configured database,
 * seeding two firms and a solo lawyer, and asserting that every scope rule isolates tenants.
 * Run: php tests/tenant_isolation_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/tenant.php';

$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; }
    else echo "  ok:   $label\n";
}

$pdo->beginTransaction();

try {
    $suffix = bin2hex(random_bytes(4));
    $pwd = password_hash('Test-Password-123', PASSWORD_DEFAULT);

    $pdo->prepare('INSERT INTO lawyers (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,\'approved\',1)')
        ->execute(["Owner A $suffix", "owner-a-$suffix@test.local", $pwd]); $ownerA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO lawyers (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,\'approved\',1)')
        ->execute(["Lawyer A2 $suffix", "lawyer-a2-$suffix@test.local", $pwd]); $lawyerA2 = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO lawyers (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,\'approved\',1)')
        ->execute(["Owner B $suffix", "owner-b-$suffix@test.local", $pwd]); $ownerB = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO lawyers (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,\'approved\',1)')
        ->execute(["Solo $suffix", "solo-$suffix@test.local", $pwd]); $solo = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,\'active\',1)')
        ->execute(["Client A $suffix", "client-a-$suffix@test.local", $pwd]); $clientA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,\'active\',1)')
        ->execute(["Client B $suffix", "client-b-$suffix@test.local", $pwd]); $clientB = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute(["Firm A $suffix", $ownerA]); $firmA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute(["Firm B $suffix", $ownerB]); $firmB = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role) VALUES (?,?,'owner')")->execute([$firmA, $ownerA]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role) VALUES (?,?,'lawyer')")->execute([$firmA, $lawyerA2]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role) VALUES (?,?,'owner')")->execute([$firmB, $ownerB]);

    $pdo->prepare('INSERT INTO cases (lawyer_id,firm_id,user_id,title) VALUES (?,?,?,?)')->execute([$ownerA, $firmA, $clientA, "Case A (by owner A)"]);
    $caseA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO cases (lawyer_id,firm_id,user_id,title) VALUES (?,?,?,?)')->execute([$lawyerA2, $firmA, $clientA, "Case A2 (by lawyer A2)"]);
    $caseA2 = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO cases (lawyer_id,firm_id,user_id,title) VALUES (?,?,?,?)')->execute([$ownerB, $firmB, $clientB, "Case B (by owner B)"]);
    $caseB = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO cases (lawyer_id,firm_id,user_id,title) VALUES (?,?,?,?)')->execute([$solo, null, $clientA, "Solo case"]);
    $caseSolo = (int)$pdo->lastInsertId();

    echo "== tenant resolution ==\n";
    $ctxA = tenant_context_for_lawyer($pdo, $ownerA);
    check('firm lawyer resolves to a firm', $ctxA['is_firm'] && $ctxA['firm_id'] === $firmA);
    check('firm lawyer role is owner', $ctxA['role'] === 'owner');
    $ctxSolo = tenant_context_for_lawyer($pdo, $solo);
    check('solo lawyer has no firm', !$ctxSolo['is_firm'] && $ctxSolo['firm_id'] === null);

    echo "== cases scope (explicit lawyer / API path) ==\n";
    $scopeA = tenant_owner_where_for_lawyer($pdo, $ownerA, 'c');
    check('firm A scope is firm_id based', $scopeA['sql'] === 'c.firm_id = ?' && $scopeA['params'] === [$firmA]);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM cases c WHERE ' . $scopeA['sql']); $stmt->execute($scopeA['params']);
    check('firm A sees both firm-A cases', (int)$stmt->fetchColumn() === 2);

    $scopeB = tenant_owner_where_for_lawyer($pdo, $ownerB, 'c');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM cases c WHERE ' . $scopeB['sql']); $stmt->execute($scopeB['params']);
    check('firm B sees only its own case', (int)$stmt->fetchColumn() === 1);

    $stmt = $pdo->prepare('SELECT c.id FROM cases c WHERE c.id=? AND ' . $scopeB['sql']);
    $stmt->execute(array_merge([$caseA], $scopeB['params']));
    check('firm B cannot read a firm-A case by id (IDOR)', $stmt->fetch() === false);

    $scopeSolo = tenant_owner_where_for_lawyer($pdo, $solo, 'c');
    check('solo scope is lawyer_id based', $scopeSolo['sql'] === 'c.lawyer_id = ?');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM cases c WHERE ' . $scopeSolo['sql']); $stmt->execute($scopeSolo['params']);
    check('solo lawyer sees only their own case', (int)$stmt->fetchColumn() === 1);

    $stmt = $pdo->prepare('SELECT c.id FROM cases c WHERE c.id=? AND ' . $scopeA['sql']);
    $stmt->execute(array_merge([$caseB], $scopeA['params']));
    check('firm A cannot read a firm-B case by id (IDOR)', $stmt->fetch() === false);
    $stmt->execute(array_merge([$caseA2], $scopeA['params']));
    check('firm A owner can read a firm-A colleague case', $stmt->fetch() !== false);

    echo "== capability map ==\n";
    check('owner manages members', in_array('firm.manage_members', tenant_firm_capabilities('owner'), true));
    check('lawyer cannot manage members', !in_array('firm.manage_members', tenant_firm_capabilities('lawyer'), true));
    check('staff cannot create cases', !in_array('cases.create', tenant_firm_capabilities('staff'), true));
    check('staff cannot record payments', !in_array('finance.record_payments', tenant_firm_capabilities('staff'), true));
    check('lawyer can create cases', in_array('cases.create', tenant_firm_capabilities('lawyer'), true));
    check('lawyer can review drafts', in_array('drafting.review', tenant_firm_capabilities('lawyer'), true));
    check('partner cannot view finance reports', !in_array('finance.view_reports', tenant_firm_capabilities('partner'), true));

    echo "== solo lawyer treated as owner (session path) ==\n";
    $_SESSION['lawyer_id'] = $solo;
    $sessionScope = tenant_owner_where($pdo, 'c');
    check('session scope for solo is lawyer_id', $sessionScope['sql'] === 'c.lawyer_id = ?' && $sessionScope['params'] === [$solo]);
    check('solo holds full capabilities', tenant_can($pdo, 'firm.manage_members') && tenant_can($pdo, 'cases.create'));

    echo "== session tenant context (firm lawyer) ==\n";
    $_SESSION['lawyer_id'] = $ownerA;
    $synced = tenant_sync_session($pdo);
    check('session sync stores firm id', ($_SESSION['tenant_firm_id'] ?? null) === $firmA);
    check('session sync stores role', ($_SESSION['tenant_role'] ?? null) === 'owner');
    check('session sync marks firm membership', ($_SESSION['tenant_is_firm'] ?? null) === true);

    echo "== data adoption on firm join ==\n";
    // A solo lawyer joining a firm should have their records adopted so nothing becomes invisible.
    $pdo->prepare('INSERT INTO cases (lawyer_id,firm_id,user_id,title) VALUES (?,NULL,?,?)')->execute([$solo, $clientB, 'Solo pre-join case']);
    tenant_adopt_lawyer_data($pdo, $firmA, $solo);
    $adopted = $pdo->prepare('SELECT COUNT(*) FROM cases WHERE lawyer_id=? AND firm_id=?');
    $adopted->execute([$solo, $firmA]);
    check('solo cases are adopted into the firm', (int)$adopted->fetchColumn() >= 1);
} finally {
    $pdo->rollBack();
    $_SESSION = [];
}

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
