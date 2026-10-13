<?php
/**
 * Phase 5 Knowledge Base access-control test.
 *
 * The Knowledge Base enforces access through knowledge_sources.scope_type/scope_id
 * (global | firm | case | private), applied identically by knowledge_retrieve() and
 * knowledge_semantic_search(). Per-source grants via knowledge_source_permissions are an
 * additive ACL layer that can only *narrow* that scope model — see
 * tests/phase5_knowledge_acl_test.php. With the ACL inactive (no grant rows) retrieval
 * behaves exactly as the plain scope model.
 *
 * These are negative security tests: they assert that an actor *cannot* reach a source
 * it does not own. Every assertion drives the real retrieval function against real rows
 * in a rolled-back transaction, so nothing is mocked and no residue is left behind.
 *
 * Run: php tests/phase5_knowledge_scope_test.php
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
    $mkUser = function (string $name, string $email) use ($pdo, $pwd): int {
        $pdo->prepare("INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'active',1)")
            ->execute([$name, $email, $pwd]);
        return (int)$pdo->lastInsertId();
    };

    $ownerA  = $mkLawyer("KB Owner A $suffix", "kb-owner-a-$suffix@test.local");
    $memberA = $mkLawyer("KB Member A $suffix", "kb-member-a-$suffix@test.local");
    $soloA   = $mkLawyer("KB Solo A $suffix", "kb-solo-a-$suffix@test.local");
    $ownerB  = $mkLawyer("KB Owner B $suffix", "kb-owner-b-$suffix@test.local");

    $clientA = $mkUser("KB Client A $suffix", "kb-client-a-$suffix@test.local");
    $clientB = $mkUser("KB Client B $suffix", "kb-client-b-$suffix@test.local");

    $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute(["KB Firm A $suffix", $ownerA]);
    $firmA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute(["KB Firm B $suffix", $ownerB]);
    $firmB = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'owner','active')")->execute([$firmA, $ownerA]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'lawyer','active')")->execute([$firmA, $memberA]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'owner','active')")->execute([$firmB, $ownerB]);

    $pdo->prepare('INSERT INTO cases (lawyer_id,firm_id,user_id,title,status) VALUES (?,?,?,?,?)')
        ->execute([$ownerA, $firmA, $clientA, "KB Case A $suffix", 'open']);
    $caseA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO cases (lawyer_id,firm_id,user_id,title,status) VALUES (?,?,?,?,?)')
        ->execute([$ownerB, $firmB, $clientB, "KB Case B $suffix", 'open']);
    $caseB = (int)$pdo->lastInsertId();

    // Each source carries a unique token so a retrieval can only match the intended row.
    $tok = fn(string $k): string => 'zq' . $suffix . $k;
    $make = function (string $scopeType, ?int $scopeId, string $createdByType, int $createdBy, string $name, string $token, string $status = 'active') use ($pdo, $tok): int {
        $pdo->prepare("INSERT INTO knowledge_sources
            (tenant_id,workspace_id,module_id,source_type,source_name,content,language,jurisdiction,category,
             status,verification_status,scope_type,scope_id,processing_status,indexing_status,created_by_type,created_by)
            VALUES (NULL,NULL,'*','manual',?,?,?,?,? ,?,?,?,?,'queued','pending',?,?)")
            ->execute([$name, $token, 'ar', 'SA', 'test', $status, 'verified', $scopeType, $scopeId, $createdByType, $createdBy]);
        $id = (int)$pdo->lastInsertId();
        knowledge_index_text($pdo, $id, $token);
        return $id;
    };

    $srcGlobal = $make('global',   null,   'admin',  0,        "Global $suffix",   $tok('g'));
    $srcFirmA  = $make('firm',     $firmA,  'lawyer', $ownerA,  "Firm A $suffix",   $tok('fa'));
    $srcFirmB  = $make('firm',     $firmB,  'lawyer', $ownerB,  "Firm B $suffix",   $tok('fb'));
    $srcCaseA  = $make('case',     $caseA,  'lawyer', $ownerA,  "Case A $suffix",   $tok('ca'));
    $srcCaseB  = $make('case',     $caseB,  'lawyer', $ownerB,  "Case B $suffix",   $tok('cb'));
    $srcPrivA  = $make('private',  null,    'lawyer', $ownerA,  "Priv A $suffix",   $tok('pa'));
    $srcPrivB  = $make('private',  null,    'lawyer', $ownerB,  "Priv B $suffix",   $tok('pb'));
    $srcArch   = $make('global',   null,    'admin',  0,        "Archived $suffix", $tok('x'), 'archived');

    /** Retrieve with moduleId='*' (matches the '*' module used by the fixtures). */
    $retrieve = function (string $actorType, int $actorId, ?int $caseId, string $token) use ($pdo): array {
        $rows = knowledge_retrieve($pdo, $token, '*', $actorType, $actorId, $caseId, false, 20);
        return array_map(fn($r) => (int)$r['id'], $rows);
    };

    echo "== lawyer: firm membership grants firm scope ==\n";
    $r = $retrieve('lawyer', $ownerA, null, $tok('fa'));
    check('firm owner reaches own firm source', in_array($srcFirmA, $r, true));
    $r = $retrieve('lawyer', $memberA, null, $tok('fa'));
    check('firm member reaches own firm source', in_array($srcFirmA, $r, true));
    $r = $retrieve('lawyer', $ownerA, null, $tok('fb'));
    check('firm A lawyer cannot reach firm B source', !in_array($srcFirmB, $r, true));
    $r = $retrieve('lawyer', $ownerB, null, $tok('fb'));
    check('firm B lawyer reaches own firm source', in_array($srcFirmB, $r, true));

    echo "== solo lawyer: no firm membership means no firm scope ==\n";
    $r = $retrieve('lawyer', $soloA, null, $tok('fa'));
    check('solo lawyer cannot reach firm A source', !in_array($srcFirmA, $r, true));

    echo "== private scope is per-actor ==\n";
    $r = $retrieve('lawyer', $ownerA, null, $tok('pa'));
    check('owner reaches own private source', in_array($srcPrivA, $r, true));
    $r = $retrieve('lawyer', $memberA, null, $tok('pa'));
    check('firm member cannot reach another lawyer\'s private source', !in_array($srcPrivA, $r, true));
    $r = $retrieve('lawyer', $ownerA, null, $tok('pb'));
    check('owner A cannot reach owner B private source', !in_array($srcPrivB, $r, true));
    $r = $retrieve('client', $clientA, null, $tok('pa'));
    check('client cannot reach a lawyer private source', !in_array($srcPrivA, $r, true));

    echo "== case scope requires the actor to own the case ==\n";
    $r = $retrieve('lawyer', $ownerA, $caseA, $tok('ca'));
    check('lawyer reaches case source for own case', in_array($srcCaseA, $r, true));
    $r = $retrieve('lawyer', $ownerA, $caseA, $tok('cb'));
    check('lawyer cannot reach another case\'s source', !in_array($srcCaseB, $r, true));
    $r = $retrieve('lawyer', $ownerA, $caseB, $tok('cb'));
    check('foreign case id grants nothing (caseAllowed=false)', !in_array($srcCaseB, $r, true));
    $r = $retrieve('client', $clientA, $caseA, $tok('ca'));
    check('client reaches case source for own case', in_array($srcCaseA, $r, true));
    $r = $retrieve('client', $clientA, $caseA, $tok('cb'));
    check('client cannot reach another client\'s case source', !in_array($srcCaseB, $r, true));
    $r = $retrieve('client', $clientB, $caseB, $tok('ca'));
    check('client B cannot reach client A case source', !in_array($srcCaseA, $r, true));

    echo "== client never inherits firm or private scope ==\n";
    $r = $retrieve('client', $clientA, null, $tok('fa'));
    check('client cannot reach firm-scoped source', !in_array($srcFirmA, $r, true));
    $r = $retrieve('client', $clientA, $caseA, $tok('fa'));
    check('client still cannot reach firm source even with own case id', !in_array($srcFirmA, $r, true));

    echo "== global scope is visible to everyone ==\n";
    check('lawyer reaches global source', in_array($srcGlobal, $retrieve('lawyer', $ownerA, null, $tok('g')), true));
    check('client reaches global source', in_array($srcGlobal, $retrieve('client', $clientA, null, $tok('g')), true));

    echo "== lifecycle and actor guards ==\n";
    check('archived source is never returned', !in_array($srcArch, $retrieve('lawyer', $ownerA, null, $tok('x')), true));
    check('unknown actor type returns nothing', $retrieve('admin', 1, null, $tok('g')) === []);
    check('empty query returns nothing', knowledge_retrieve($pdo, '', '*', 'lawyer', $ownerA) === []);

    echo "== a grant alone never widens scope (scope model is authoritative) ==\n";
    // A grant on a source the scope model hides must not expose it, whether or not the ACL
    // layer has been activated. (Whether the ACL is active is irrelevant to this invariant.)
    $pdo->prepare('INSERT INTO knowledge_source_permissions (source_id,subject_type,subject_id,permission) VALUES (?,?,?,?)')
        ->execute([$srcFirmB, 'lawyer', $ownerA, 'read']);
    $r = $retrieve('lawyer', $ownerA, null, $tok('fb'));
    check('a grant row does not widen access (scope model is authoritative)', !in_array($srcFirmB, $r, true));

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "  FAIL: unexpected exception — " . $e->getMessage() . "\n";
    $failures++;
}

echo "\nPhase 5 Knowledge scope: $checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
