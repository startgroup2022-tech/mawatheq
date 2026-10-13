<?php
/**
 * Phase 3-expansion Knowledge Base per-source ACL test.
 *
 * knowledge_source_permissions is an additive layer over the scope_type/scope_id model:
 *   - scope model decides reach;
 *   - the ACL can only NARROW that reach, never widen it;
 *   - activation is opt-in (knowledge_acl_initialize()) and behavior-preserving;
 *   - a grant row alone never exposes a source the scope model hides.
 *
 * These assertions drive the real knowledge_retrieve() against real rows inside a
 * rolled-back transaction — nothing mocked, no residue.
 *
 * Run: php tests/phase5_knowledge_acl_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/knowledge_center.php';

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

    $mkLawyer = function (string $name, string $email) use ($pdo, $pwd): int {
        $pdo->prepare("INSERT INTO lawyers (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'approved',1)")
            ->execute([$name, $email, $pwd]);
        return (int)$pdo->lastInsertId();
    };

    $ownerA  = $mkLawyer("ACL Owner $suffix", "acl-owner-$suffix@test.local");
    $memberA = $mkLawyer("ACL Member $suffix", "acl-member-$suffix@test.local");
    $ownerB  = $mkLawyer("ACL Foreign $suffix", "acl-foreign-$suffix@test.local");

    $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute(["ACL Firm $suffix", $ownerA]);
    $firmA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute(["ACL Firm B $suffix", $ownerB]);
    $firmB = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'owner','active')")->execute([$firmA, $ownerA]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'lawyer','active')")->execute([$firmA, $memberA]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'owner','active')")->execute([$firmB, $ownerB]);

    $tok = fn(string $k): string => 'aq' . $suffix . $k;
    $make = function (string $scopeType, ?int $scopeId, string $createdByType, int $createdBy, string $name, string $token) use ($pdo): int {
        $pdo->prepare("INSERT INTO knowledge_sources
            (tenant_id,workspace_id,module_id,source_type,source_name,content,language,jurisdiction,category,
             status,verification_status,scope_type,scope_id,processing_status,indexing_status,created_by_type,created_by)
            VALUES (NULL,NULL,'*','manual',?,?,?,?,?,'active','verified',?,?,'queued','pending',?,?)")
            ->execute([$name, $token, 'ar', 'SA', 'test', $scopeType, $scopeId, $createdByType, $createdBy]);
        $id = (int)$pdo->lastInsertId();
        knowledge_index_text($pdo, $id, $token);
        return $id;
    };

    $srcGlobal = $make('global',  null,  'admin',  0,       "ACL Global $suffix", $tok('g'));
    $srcFirmA  = $make('firm',    $firmA, 'lawyer', $ownerA, "ACL Firm $suffix",   $tok('fa'));
    $srcFirmB  = $make('firm',    $firmB, 'lawyer', $ownerB, "ACL FirmB $suffix",  $tok('fb'));
    $srcPrivA  = $make('private', null,  'lawyer', $ownerA, "ACL Priv $suffix",   $tok('pa'));

    $retrieve = function (string $actorType, int $actorId, string $token) use ($pdo): array {
        $rows = knowledge_retrieve($pdo, $token, '*', $actorType, $actorId, null, false, 20);
        return array_map(fn($r) => (int)$r['id'], $rows);
    };

    echo "== ACL inactive: behavior is the plain scope model ==\n";
    check('ACL reports inactive before any grant row', !knowledge_permissions_initialized($pdo));
    check('permission SQL is empty while inactive', knowledge_permission_sql($pdo, 'lawyer', $ownerA) === '');
    check('permission params are empty while inactive', knowledge_permission_params($pdo, 'lawyer', $ownerA) === []);
    check('owner reaches own firm source', in_array($srcFirmA, $retrieve('lawyer', $ownerA, $tok('fa')), true));
    check('member reaches firm source', in_array($srcFirmA, $retrieve('lawyer', $memberA, $tok('fa')), true));
    check('owner cannot reach foreign firm source', !in_array($srcFirmB, $retrieve('lawyer', $ownerA, $tok('fb')), true));

    echo "== activation is behavior-preserving ==\n";
    $inserted = knowledge_acl_initialize($pdo);
    check('initialize inserts at least one grant', $inserted > 0);
    check('ACL reports active after initialize', knowledge_permissions_initialized($pdo));
    check('initialize is idempotent (second run inserts nothing)', knowledge_acl_initialize($pdo) === 0);
    check('owner still reaches own firm source after activation', in_array($srcFirmA, $retrieve('lawyer', $ownerA, $tok('fa')), true));
    check('member still reaches firm source after activation', in_array($srcFirmA, $retrieve('lawyer', $memberA, $tok('fa')), true));
    check('owner still reaches global source after activation', in_array($srcGlobal, $retrieve('lawyer', $ownerA, $tok('g')), true));
    check('owner still reaches own private source after activation', in_array($srcPrivA, $retrieve('lawyer', $ownerA, $tok('pa')), true));
    check('member still cannot reach another lawyer private source', !in_array($srcPrivA, $retrieve('lawyer', $memberA, $tok('pa')), true));
    check('owner still cannot reach foreign firm source', !in_array($srcFirmB, $retrieve('lawyer', $ownerA, $tok('fb')), true));

    echo "== ACL can narrow: removing one lawyer's grant denies only that lawyer ==\n";
    // On a global source no firm-wide grant applies, so removing the individual lawyer grant
    // is the only thing that can deny. The other lawyer keeps their own grant.
    $pdo->prepare("DELETE FROM knowledge_source_permissions WHERE source_id=? AND subject_type='lawyer' AND subject_id=? AND permission='read'")
        ->execute([$srcGlobal, $ownerA]);
    check('owner loses access after grant removal', !in_array($srcGlobal, $retrieve('lawyer', $ownerA, $tok('g')), true));
    check('member keeps access (grant intact)', in_array($srcGlobal, $retrieve('lawyer', $memberA, $tok('g')), true));

    echo "== ACL never widens: a grant cannot expose a scope-hidden source ==\n";
    $pdo->prepare('INSERT INTO knowledge_source_permissions (source_id,subject_type,subject_id,permission) VALUES (?,?,?,?)')
        ->execute([$srcFirmB, 'lawyer', $ownerA, 'read']);
    check('grant on foreign firm source does not expose it to owner', !in_array($srcFirmB, $retrieve('lawyer', $ownerA, $tok('fb')), true));

    echo "== a source with no grants yet stays governed by scope (not implicitly denied) ==\n";
    $srcNew = $make('global', null, 'admin', 0, "ACL New $suffix", $tok('n'));
    check('brand-new source with no grant rows remains reachable', in_array($srcNew, $retrieve('lawyer', $ownerA, $tok('n')), true));

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "  FAIL: unexpected exception — " . $e->getMessage() . "\n";
    $failures++;
}

echo "\nPhase 3-expansion Knowledge ACL: $checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
