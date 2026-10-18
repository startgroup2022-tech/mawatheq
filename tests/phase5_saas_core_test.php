<?php
/**
 * Phase 5 SaaS-core test.
 *
 * Covers the completion work done in Phase 5 against the real database:
 *  - evidence download tenant scoping (case_evidence has no lawyer_id; it inherits
 *    its tenant from the parent case),
 *  - firm-member notification fan-out that never notifies the acting lawyer,
 *  - permission catalog + the no-escalation rule when a firm owner edits a member,
 *  - the audit actor extension that now records client-portal actions.
 * Run: php tests/phase5_saas_core_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/notifications.php';

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
    $mk = function (string $name, string $email) use ($pdo, $pwd): int {
        $pdo->prepare('INSERT INTO lawyers (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,\'approved\',1)')
            ->execute([$name, $email, $pwd]);
        return (int)$pdo->lastInsertId();
    };
    $ownerA = $mk("Owner A $suffix", "p5-owner-a-$suffix@test.local");
    $lawyerA2 = $mk("Lawyer A2 $suffix", "p5-lawyer-a2-$suffix@test.local");
    $ownerB = $mk("Owner B $suffix", "p5-owner-b-$suffix@test.local");
    $solo = $mk("Solo $suffix", "p5-solo-$suffix@test.local");

    $pdo->prepare('INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,\'active\',1)')
        ->execute(["Client $suffix", "p5-client-$suffix@test.local", $pwd]);
    $client = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute(["Firm A $suffix", $ownerA]);
    $firmA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute(["Firm B $suffix", $ownerB]);
    $firmB = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role) VALUES (?,?,'owner')")->execute([$firmA, $ownerA]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role) VALUES (?,?,'lawyer')")->execute([$firmA, $lawyerA2]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role) VALUES (?,?,'owner')")->execute([$firmB, $ownerB]);

    $pdo->prepare('INSERT INTO cases (lawyer_id,firm_id,user_id,title) VALUES (?,?,?,?)')->execute([$ownerA, $firmA, $client, "Case A $suffix"]);
    $caseA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO cases (lawyer_id,firm_id,user_id,title) VALUES (?,?,?,?)')->execute([$ownerB, $firmB, $client, "Case B $suffix"]);
    $caseB = (int)$pdo->lastInsertId();

    $storedA = bin2hex(random_bytes(20)) . '.pdf';
    $storedB = bin2hex(random_bytes(20)) . '.pdf';
    $pdo->prepare('INSERT INTO case_evidence (case_id,added_by_lawyer_id,firm_id,title,stored_name,original_name) VALUES (?,?,?,?,?,?)')
        ->execute([$caseA, $ownerA, $firmA, 'Evidence A', $storedA, 'a.pdf']);
    $evA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO case_evidence (case_id,added_by_lawyer_id,firm_id,title,stored_name,original_name) VALUES (?,?,?,?,?,?)')
        ->execute([$caseB, $ownerB, $firmB, 'Evidence B', $storedB, 'b.pdf']);
    $evB = (int)$pdo->lastInsertId();

    echo "== evidence download scoping (fixed) ==\n";
    // Reproduce the exact query used by lawyer/download-evidence.php.
    $scope = tenant_owner_where_for_lawyer($pdo, $ownerA, 'c');
    $stmt = $pdo->prepare('SELECT e.id FROM case_evidence e JOIN cases c ON c.id=e.case_id WHERE e.id=? AND ' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$evA], $scope['params']));
    check('firm A can download its own evidence', (int)$stmt->fetchColumn() === $evA);
    $stmt->execute(array_merge([$evB], $scope['params']));
    check('firm A cannot download firm B evidence', $stmt->fetchColumn() === false);

    // Solo lawyer: only evidence on their own cases.
    $soloScope = tenant_owner_where_for_lawyer($pdo, $solo, 'c');
    $stmt2 = $pdo->prepare('SELECT e.id FROM case_evidence e JOIN cases c ON c.id=e.case_id WHERE e.id=? AND ' . $soloScope['sql'] . ' LIMIT 1');
    $stmt2->execute(array_merge([$evA], $soloScope['params']));
    check('solo lawyer cannot read firm evidence', $stmt2->fetchColumn() === false);

    echo "== firm notification fan-out ==\n";
    notify_tenant_except($pdo, $firmA, $ownerA, $ownerA, 'Case A update', 'msg', '/lawyer/cases.php');
    $cnt = function (int $lawyerId) use ($pdo): int {
        $s = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_type=\'lawyer\' AND recipient_id=? AND title=?');
        $s->execute([$lawyerId, 'Case A update']);
        return (int)$s->fetchColumn();
    };
    check('actor is not notified about own action', $cnt($ownerA) === 0);
    check('other firm member is notified', $cnt($lawyerA2) === 1);

    notify_tenant_except($pdo, null, $solo, 0, 'Solo notice' . $suffix, null, null);
    $s = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_type=\'lawyer\' AND recipient_id=? AND title=?');
    $s->execute([$solo, 'Solo notice' . $suffix]);
    check('solo lawyer is notified directly', (int)$s->fetchColumn() === 1);

    // A firm event must not leak to another firm's members.
    $s = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_type=\'lawyer\' AND recipient_id=? AND title=?');
    $s->execute([$ownerB, 'Case A update']);
    check('other firm is not notified', (int)$s->fetchColumn() === 0);

    echo "== permission catalog ==\n";
    $all = tenant_all_permissions();
    check('catalog is non-empty', count($all) > 20);
    check('no duplicate capabilities', count($all) === count(array_unique($all)));
    check('owner holds every capability', array_diff($all, tenant_firm_capabilities('owner')) === []);
    $lawyerCaps = tenant_firm_capabilities('lawyer');
    check('lawyer cannot manage members by default', !in_array('firm.manage_members', $lawyerCaps, true));
    check('lawyer cannot grant finance reports by default', !in_array('finance.view_reports', $lawyerCaps, true));

    echo "== no-escalation grant rule ==\n";
    // Replicate the filtering performed in lawyer/firm.php when saving permissions.
    $requested = ['cases.view', 'firm.manage_members', 'finance.view_reports', 'cases.view', 'bogus.perm'];
    $valid = tenant_all_permissions();
    $actorHolds = fn(string $p): bool => in_array($p, $lawyerCaps, true); // acting as a 'lawyer' role
    $grantable = [];
    foreach ($requested as $p) if (is_string($p) && in_array($p, $valid, true) && $actorHolds($p)) $grantable[] = $p;
    $grantable = array_values(array_unique($grantable));
    check('actor cannot delegate a capability it lacks', !in_array('firm.manage_members', $grantable, true));
    check('unknown permissions are dropped', !in_array('bogus.perm', $grantable, true));
    check('duplicates collapse to one', count(array_keys($grantable, 'cases.view', true)) === 1);
    check('held capability is grantable', in_array('cases.view', $grantable, true));

    echo "== per-member override stored on membership ==\n";
    $memberStmt = $pdo->prepare("SELECT id FROM law_firm_members WHERE firm_id=? AND lawyer_id=?");
    $memberStmt->execute([$firmA, $lawyerA2]);
    $memberId = (int)$memberStmt->fetchColumn();
    $pdo->prepare('UPDATE law_firm_members SET permissions_json=? WHERE id=?')->execute([json_encode(['cases.view', 'cases.export']), $memberId]);
    $read = $pdo->prepare('SELECT permissions_json FROM law_firm_members WHERE id=?');
    $read->execute([$memberId]);
    check('override round-trips', json_decode((string)$read->fetchColumn(), true) === ['cases.view', 'cases.export']);

    echo "== audit actor enum accepts client ==\n";
    $pdo->prepare('INSERT INTO audit_logs (actor_type,actor_id,actor_name,action,target_type,target_id) VALUES (?,?,?,?,?,?)')
        ->execute(['client', $client, "Client $suffix", 'تسجيل دخول العميل', 'client', $client]);
    $row = $pdo->query("SELECT actor_type FROM audit_logs WHERE action='تسجيل دخول العميل' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('client-portal actions are auditable', $row === 'client');

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "  FAIL: unexpected exception — " . $e->getMessage() . "\n";
    $failures++;
}

echo "\nPhase 5 SaaS-core: $checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
