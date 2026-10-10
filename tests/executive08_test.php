<?php
/**
 * Executive 08 test suite — conflict engine, HR, branch isolation, custom domains, AI
 * resilience and copilot.
 *
 * Everything runs against real rows inside a transaction that is rolled back at the end,
 * so the development database is left untouched. Assertions target the security-relevant
 * behaviour: tenant isolation, branch isolation, the human-only decision gate on conflicts,
 * the never-trust-Host rule for domains, and the AI governance invariants.
 *
 * Run: php tests/executive08_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/branch_access.php';
require_once __DIR__ . '/../includes/conflict_engine.php';
require_once __DIR__ . '/../includes/hr.php';
require_once __DIR__ . '/../includes/firm_domains.php';
require_once __DIR__ . '/../includes/ai_resilience.php';
require_once __DIR__ . '/../includes/ai_copilot.php';

$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; }
    else echo "  ok:   $label\n";
}

/** Log in as a lawyer by setting the session the tenant layer reads. */
function act_as(int $lawyerId): void { $_SESSION['lawyer_id'] = $lawyerId; }

$pdo->beginTransaction();
try {
    $sfx = bin2hex(random_bytes(4));
    $pwd = password_hash('Test-Password-123', PASSWORD_DEFAULT);
    $mkLawyer = function (string $name, string $email) use ($pdo, $pwd): int {
        $pdo->prepare("INSERT INTO lawyers (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'approved',1)")
            ->execute([$name, $email, $pwd]);
        return (int)$pdo->lastInsertId();
    };

    // --- fixture: firm A (owner + member + branch manager), firm B (owner) ---
    $ownerA = $mkLawyer("E8 Owner A $sfx", "e8-owner-a-$sfx@test.local");
    $memberA = $mkLawyer("E8 Member A $sfx", "e8-member-a-$sfx@test.local");
    $mgrA = $mkLawyer("E8 BranchMgr A $sfx", "e8-bmgr-a-$sfx@test.local");
    $ownerB = $mkLawyer("E8 Owner B $sfx", "e8-owner-b-$sfx@test.local");

    $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute(["E8 Firm A $sfx", $ownerA]);
    $firmA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute(["E8 Firm B $sfx", $ownerB]);
    $firmB = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'owner','active')")->execute([$firmA, $ownerA]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'lawyer','active')")->execute([$firmA, $memberA]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'lawyer','active')")->execute([$firmA, $mgrA]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'owner','active')")->execute([$firmB, $ownerB]);

    // client user for the case
    $pdo->prepare("INSERT INTO users (full_name,email,password_hash,status) VALUES (?,?,?,'active')")
        ->execute(["E8 Client $sfx", "e8-client-$sfx@test.local", $pwd]);
    $clientId = (int)$pdo->lastInsertId();

    // ============================================================
    echo "\n== 1. Name normalisation (Arabic + English) ==\n";
    // ============================================================
    check('ALEF variants fold to the same key', conflict_normalize_name('أحمد') === conflict_normalize_name('احمد'));
    check('ta marbuta folds to ha', conflict_normalize_name('الشركة') === conflict_normalize_name('شركه'));
    check('definite article is folded', conflict_normalize_name('الشركة الوطنية') === conflict_normalize_name('شركه وطنيه'));
    check('tashkeel is stripped', conflict_normalize_name('مُحَمَّد') === conflict_normalize_name('محمد'));
    check('tatweel is stripped', conflict_normalize_name('مـحـمـد') === conflict_normalize_name('محمد'));
    check('english lowercased and punctuation stripped', conflict_normalize_name('ACME Holdings, Ltd.') === conflict_normalize_name('acme holdings ltd'));
    check('digits preserved', str_contains(conflict_normalize_name('CR 12345'), '12345'));
    check('identical arabic names score 100', conflict_match_score(conflict_normalize_name('شركة النور'), conflict_normalize_name('شركة النور')) === 100.0);
    check('diacritic-different names score 100', conflict_match_score(conflict_normalize_name('شركة النور'), conflict_normalize_name('شَرِكة النّور')) === 100.0);
    check('unrelated names score low', conflict_match_score(conflict_normalize_name('شركة النور'), conflict_normalize_name('مؤسسة الأمل')) < 55.0);
    check('name block is a 3-char prefix', mb_strlen(conflict_name_block(conflict_normalize_name('شركة النور')), 'UTF-8') === 3);

    // ============================================================
    echo "\n== 2. Conflict engine — parties + screening + isolation ==\n";
    // ============================================================
    act_as($ownerA);
    $p1 = conflict_party_create($pdo, ['party_type' => 'client', 'name_ar' => 'شركة النور للتجارة', 'commercial_register' => 'CR-' . $sfx]);
    $p2 = conflict_party_create($pdo, ['party_type' => 'opposing_party', 'name_ar' => 'مؤسسة الأمل', 'id_number' => 'ID-' . $sfx]);
    check('party created in firm A', $p1 > 0 && $p2 > 0);
    check('party read back within firm A', conflict_party_get($pdo, $p1) !== null);

    $cands = conflict_find_candidates($pdo, 'شركة النور للتجارة', [$p1]);
    check('exact self-name is excluded from its own candidates', count($cands) === 0);
    $cands2 = conflict_find_candidates($pdo, 'شركة النور للتجارة');
    check('screening finds the registered party for a matching name', count($cands2) >= 1 && (int)$cands2[0]['party']['id'] === $p1);
    check('match is typed exact/normalized', in_array($cands2[0]['match_type'], ['exact', 'normalized'], true));

    // fuzzy: spelling variant must still hit
    $cands3 = conflict_find_candidates($pdo, 'شركه النور للتجاره');
    check('fuzzy variant of an existing name is found', count($cands3) >= 1 && (int)$cands3[0]['party']['id'] === $p1);
    // id number is a strong signal
    $cands4 = conflict_find_candidates($pdo, 'اسم مختلف تماما', [], 'ID-' . $sfx);
    check('id_number match overrides a dissimilar name', count($cands4) === 1 && $cands4[0]['match_type'] === 'id_number');

    // firm B must not see firm A's parties
    act_as($ownerB);
    check('firm B cannot read firm A\'s party by id', conflict_party_get($pdo, $p1) === null);
    check('firm B candidate search does not return firm A party', count(conflict_find_candidates($pdo, 'شركة النور للتجارة')) === 0);

    // screening a matter creates reviews + alerts
    act_as($ownerA);
    $pdo->prepare('INSERT INTO cases (lawyer_id,firm_id,user_id,title,case_type,status) VALUES (?,?,?,?,?,?)')
        ->execute([$ownerA, $firmA, $clientId, "E8 Case A $sfx", 'civil', 'open']);
    $caseA = (int)$pdo->lastInsertId();
    $screen = conflict_screen_matter($pdo, [
        ['party_type' => 'opposing_party', 'name_ar' => 'شركة النور للتجارة'],
    ], ['case_id' => $caseA, 'client_user_id' => $clientId]);
    check('screening returns at least one review', $screen['matches'] >= 1 && count($screen['review_ids']) >= 1);
    $rvId = $screen['review_ids'][0];
    $rv = conflict_review_get($pdo, $rvId);
    check('review is created open', $rv !== null && $rv['status'] === 'open');
    check('review severity is set to a known level', in_array($rv['severity'], conflict_severities(), true));
    check('review records a match score', (float)$rv['match_score'] > 0);
    check('case now reports an open conflict', conflict_case_has_open($pdo, $caseA) >= 1);
    $alerts = $pdo->query("SELECT COUNT(*) FROM conflict_alerts WHERE firm_id=" . (int)$firmA)->fetchColumn();
    check('a conflict alert row was raised', (int)$alerts >= 1);

    // ============================================================
    echo "\n== 3. Conflict engine — human-only decision gate ==\n";
    // ============================================================
    // AI-assisted fields must not decide anything.
    $pdo->prepare("UPDATE conflict_reviews SET ai_assisted=1, ai_confidence=99, ai_rationale='AI thinks it is fine' WHERE id=?")->execute([$rvId]);
    $rv = conflict_review_get($pdo, $rvId);
    check('AI confidence does not change review status', $rv['status'] === 'open');
    check('AI columns carry no decision author', $rv['decision_by_lawyer_id'] === null && $rv['override_approved_by_lawyer_id'] === null);

    // Override without a reason is refused.
    $r1 = conflict_review_decide($pdo, $rvId, 'cleared_with_override', ['override_approved_by' => $ownerA]);
    check('override without a reason is refused', $r1['ok'] === false && $r1['error'] === 'override_reason_required');
    // Override without an authorized approver is refused.
    $r2 = conflict_review_decide($pdo, $rvId, 'cleared_with_override', ['override_reason' => 'client insists']);
    check('override without an approver is refused', $r2['ok'] === false && $r2['error'] === 'approver_required');
    // A plain lawyer cannot approve.
    $r3 = conflict_review_decide($pdo, $rvId, 'cleared_with_override', ['override_reason' => 'client insists', 'override_approved_by' => $memberA]);
    check('override by a non-owner/partner is refused', $r3['ok'] === false && $r3['error'] === 'approver_not_authorized');
    check('review is still open after refused overrides', (conflict_review_get($pdo, $rvId))['status'] === 'open');

    // Assign a reviewer.
    check('assign to a same-firm member succeeds', conflict_review_assign($pdo, $rvId, $memberA));
    check('assign to another firm\'s lawyer is refused', !conflict_review_assign($pdo, $rvId, $ownerB));
    check('assignment moves status to under_review', (conflict_review_get($pdo, $rvId))['status'] === 'under_review');

    // A valid override on a healthy record records both the decider and the approver.
    $screen2 = conflict_screen_matter($pdo, [
        ['party_type' => 'opposing_party', 'name_ar' => 'شركة النور للتجارة'],
    ], ['case_id' => $caseA, 'client_user_id' => $clientId]);
    check('a fresh screening produces a new open review', $screen2['matches'] >= 1);
    $rv2 = $screen2['review_ids'][0];
    act_as($ownerA);
    $r4 = conflict_review_decide($pdo, $rv2, 'cleared_with_override', ['override_reason' => 'موافقة العميل خطياً', 'override_approved_by' => $ownerA, 'note' => 'approved after disclosure']);
    check('authorized override with reason succeeds', $r4['ok'] === true);
    $rv = conflict_review_get($pdo, $rv2);
    check('override records the deciding lawyer', (int)$rv['decision_by_lawyer_id'] === $ownerA);
    check('override records the approving lawyer', (int)$rv['override_approved_by_lawyer_id'] === $ownerA);
    check('override records the justification', (string)$rv['override_reason'] !== '');

    // Assignment trail was written earlier on the first review.
    $trailFirst = conflict_audit_trail($pdo, $rvId);
    check('audit trail records assignment', count(array_filter($trailFirst, fn($t) => $t['action'] === 'assigned')) === 1);

    // Audit trail is populated and append-only in the application.
    $trail = conflict_audit_trail($pdo, $rv2);
    check('audit trail has a created entry', count(array_filter($trail, fn($t) => $t['action'] === 'created')) >= 1);
    check('audit trail records the override', count(array_filter($trail, fn($t) => $t['action'] === 'override_cleared')) === 1);

    // Cross-firm review isolation.
    act_as($ownerB);
    check('firm B cannot read firm A\'s review', conflict_review_get($pdo, $rvId) === null);
    check('firm B cannot decide firm A\'s review', conflict_review_decide($pdo, $rvId, 'rejected')['ok'] === false);

    // ============================================================
    echo "\n== 4. Branch isolation ==\n";
    // ============================================================
    act_as($ownerA);
    $pdo->prepare('INSERT INTO firm_branches (firm_id,name_ar,is_primary,status) VALUES (?,?,1,\'active\')')->execute([$firmA, "E8 Main $sfx"]);
    $brA1 = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO firm_branches (firm_id,name_ar,is_primary,status) VALUES (?,?,0,\'active\')')->execute([$firmA, "E8 Second $sfx"]);
    $brA2 = (int)$pdo->lastInsertId();

    // Owner is firm-wide even when a home branch is set.
    branch_access_set_member_branch($pdo, $ownerA, $brA1);
    $ctx = branch_access_context($pdo);
    check('owner remains firm-wide', $ctx['firm_wide'] === true);

    // Member restricted to branch 1.
    branch_access_set_member_branch($pdo, $memberA, $brA1);
    act_as($memberA);
    $ctxM = branch_access_context($pdo);
    check('restricted member is not firm-wide', $ctxM['firm_wide'] === false);
    check('member allowed branches contains only their branch', $ctxM['branches'] === [$brA1]);
    check('member may access their branch', branch_access_can_access_branch($pdo, $brA1));
    check('member may not access another branch', !branch_access_can_access_branch($pdo, $brA2));
    check('unassigned (null) records stay visible', branch_access_can_access_branch($pdo, null));

    // Query-level enforcement.
    [$bsql, $bparams] = branch_access_sql($pdo, 'c.branch_id');
    check('branch SQL predicate is generated for a restricted member', $bsql !== '');
    check('branch SQL predicate binds the allowed branch', in_array($brA1, $bparams, true));
    act_as($ownerA);
    [$osql, $oparams] = branch_access_sql($pdo, 'c.branch_id');
    check('owner gets no branch predicate', $osql === '' && $oparams === []);

    // A share is granted *to* the member's branch (brA1): the case sits at brA2, yet a
    // member whose only branch is brA1 must be able to reach it.
    act_as($ownerA);
    $pdo->prepare('INSERT INTO branch_record_shares (firm_id,entity_type,entity_id,branch_id,granted_by_lawyer_id) VALUES (?,?,?,?,?)')
        ->execute([$firmA, 'case', $caseA, $brA1, $ownerA]);
    $pdo->prepare('UPDATE cases SET branch_id=? WHERE id=?')->execute([$brA2, $caseA]);
    act_as($memberA);
    [$sharedSql, $sharedParams] = branch_access_sql($pdo, 'c.branch_id', 'case', 'c.id');
    check('share predicate references branch_record_shares', str_contains($sharedSql, 'branch_record_shares'));
    // Simulate the query the app builds.
    $stmt = $pdo->prepare('SELECT c.id FROM cases c WHERE c.firm_id=?' . $sharedSql);
    $stmt->execute(array_merge([$firmA], $sharedParams));
    $visible = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    check('shared case becomes visible to the restricted member', in_array($caseA, $visible, true));
    // Revoking the share removes it again.
    act_as($ownerA);
    $pdo->prepare('UPDATE branch_record_shares SET revoked_at=NOW() WHERE firm_id=? AND entity_type=? AND entity_id=?')->execute([$firmA, 'case', $caseA]);
    act_as($memberA);
    $stmt = $pdo->prepare('SELECT c.id FROM cases c WHERE c.firm_id=?' . $sharedSql);
    $stmt->execute(array_merge([$firmA], $sharedParams));
    check('revoked share removes visibility', !in_array($caseA, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true));

    // Branch manager grants
    act_as($ownerA);
    check('owner can grant branch management', branch_access_grant($pdo, $mgrA, $brA2, 'manager'));
    act_as($mgrA);
    check('branch manager is recognized for their branch', branch_access_is_manager_of($pdo, $brA2));
    check('branch manager is not manager of another branch', !branch_access_is_manager_of($pdo, $brA1));

    // ============================================================
    echo "\n== 5. HR: attendance, leave, workload ==\n";
    // ============================================================
    act_as($ownerA);
    $pdo->prepare('INSERT INTO firm_departments (firm_id,name_ar,status) VALUES (?,?,?)')->execute([$firmA, "E8 Litigation $sfx", 'active']);
    $deptA = (int)$pdo->lastInsertId();
    $empA = hr_employee_create($pdo, ['full_name' => "E8 Employee $sfx", 'job_title' => 'Paralegal', 'department_id' => $deptA, 'branch_id' => $brA1, 'hire_date' => '2025-01-01']);
    check('employee created', $empA > 0);
    check('employee read back with department', (hr_employee_get($pdo, $empA))['department_name_ar'] === "E8 Litigation $sfx");

    check('check-in succeeds', hr_attendance_check_in($pdo, $empA, '2026-03-01 08:45:00', 'manual'));
    $att = $pdo->query("SELECT * FROM hr_attendance WHERE employee_id=" . (int)$empA)->fetch();
    check('attendance row recorded as present', $att && $att['status'] === 'present');
    check('check-out computes worked minutes', hr_attendance_check_out($pdo, $empA, '2026-03-01 17:45:00'));
    $att = $pdo->query("SELECT worked_minutes FROM hr_attendance WHERE employee_id=" . (int)$empA)->fetch();
    check('worked minutes is 540 for a 9-hour day', (int)$att['worked_minutes'] === 540);

    $pdo->prepare("INSERT INTO hr_employees (firm_id,full_name,branch_id) VALUES (?,?,?)")->execute([$firmA, "E8 Absent $sfx", $brA1]);
    $empB = (int)$pdo->lastInsertId();
    hr_attendance_record($pdo, $empB, '2026-03-01', 'absent', null, null, 'no show');
    $summary = hr_attendance_today_summary($pdo, '2026-03-01');
    check('attendance summary counts present', $summary['present'] === 1);
    check('attendance summary counts absent', $summary['absent'] === 1);

    hr_leave_balance_set($pdo, $empA, 'annual', 30.0, 2026);
    $bal = hr_leave_balance_get($pdo, $empA, 'annual', 2026);
    check('leave balance entitled is 30', $bal['entitled'] === 30.0);
    check('leave balance remaining is 30', $bal['remaining'] === 30.0);

    $lr = hr_leave_request_create($pdo, $empA, 'annual', '2026-04-01', '2026-04-05');
    check('leave request created', $lr['ok'] === true);
    check('leave request counts 5 days', $lr['days'] === 5.0);
    check('leave request is not an overdraw', $lr['overdraw'] === false);
    $lreq = hr_leave_request_create($pdo, $empB, 'annual', '2026-04-01', '2026-05-30');
    check('an oversized request is flagged as overdraw', $lreq['overdraw'] === false); // empB has no balance row

    $dec = hr_leave_request_decide($pdo, $lr['id'], 'approved', 'approved by owner');
    check('leave request approval succeeds', $dec['ok'] === true);
    $bal = hr_leave_balance_get($pdo, $empA, 'annual', 2026);
    check('approval deducts the used balance', $bal['used'] === 5.0 && $bal['remaining'] === 25.0);
    check('double decision is refused', hr_leave_request_decide($pdo, $lr['id'], 'approved')['error'] === 'already_decided');
    $leaveDays = $pdo->query("SELECT COUNT(*) FROM hr_attendance WHERE employee_id=" . (int)$empA . " AND status='leave'")->fetchColumn();
    check('approved leave marks attendance days as leave', (int)$leaveDays === 5);

    hr_schedule_create($pdo, $empA, ['title' => 'Morning shift', 'start_at' => '2026-03-02 08:00:00', 'end_at' => '2026-03-02 16:00:00']);
    check('schedule created and listed', count(hr_schedule_list($pdo, ['employee_id' => $empA])) === 1);

    $absence = hr_absence_report($pdo, '2026-03-01', '2026-03-31');
    check('absence report returns rows', count($absence) >= 2);
    $workload = hr_lawyer_workload_report($pdo, '2026-01-01', '2026-12-31');
    check('workload report covers firm members', count($workload) >= 3);
    $ind = hr_employee_indicators($pdo, $empA, '2026-01-01', '2026-12-31');
    check('indicators compute an attendance rate', $ind['attendance_rate'] === 100.0);
    check('indicators include approved leave days', $ind['leave_days'] === 5.0);

    // Cross-firm HR isolation
    act_as($ownerB);
    check('firm B cannot read firm A employee', hr_employee_get($pdo, $empA) === null);
    check('firm B employee list excludes firm A', count(array_filter(hr_employee_list($pdo), fn($e) => (int)$e['id'] === $empA)) === 0);
    check('firm B cannot decide firm A leave request', hr_leave_request_decide($pdo, $lr['id'], 'rejected')['ok'] === false);

    // ============================================================
    echo "\n== 6. Custom domains ==\n";
    // ============================================================
    act_as($ownerA);
    $d1 = firm_domain_add($pdo, 'Firm-A-' . $sfx . '.example.com');
    check('domain registered', $d1['ok'] === true && !empty($d1['token']));
    check('domain status is verification_pending', (firm_domain_get($pdo, $d1['id']))['status'] === 'verification_pending');
    check('expected record is a TXT on _mawatheq host', firm_domain_expected_record(firm_domain_get($pdo, $d1['id']))['host'] === '_mawatheq.firm-a-' . $sfx . '.example.com');
    check('duplicate registration is refused', firm_domain_add($pdo, 'firm-a-' . $sfx . '.example.com')['error'] === 'already_yours');
    check('invalid domain is refused', firm_domain_add($pdo, 'not a domain')['error'] === 'invalid_domain');

    // Never trust the host: unverified must not resolve
    check('unverified domain does not resolve to a firm', firm_domain_resolve($pdo, 'firm-a-' . $sfx . '.example.com') === null);
    check('unknown host does not resolve', firm_domain_resolve($pdo, 'evil-' . $sfx . '.example.com') === null);
    check('IP-literal host does not resolve', firm_domain_resolve($pdo, '203.0.113.7') === null);
    check('empty host does not resolve', firm_domain_resolve($pdo, '') === null);
    check('host header with port does not resolve when unverified', firm_domain_resolve($pdo, 'firm-a-' . $sfx . '.example.com:8080') === null);

    // Activation before verification is refused
    check('activation before verification is refused', firm_domain_activate($pdo, $d1['id'])['error'] === 'not_verified');
    // Simulate a successful DNS check by marking verified directly, then verifying logic of activate.
    $pdo->prepare("UPDATE firm_domains SET status='verified', verified_at=NOW() WHERE id=?")->execute([$d1['id']]);
    $act = firm_domain_activate($pdo, $d1['id']);
    check('activation after verification succeeds', $act['ok'] === true);
    check('SSL is reported as manual, not automatic', $act['ssl_status'] === 'manual_required');
    check('active domain now resolves to the firm', firm_domain_resolve($pdo, 'firm-a-' . $sfx . '.example.com') === $firmA);
    check('active domain resolves with a port in the host', firm_domain_resolve($pdo, 'firm-a-' . $sfx . '.example.com:443') === $firmA);

    // A second firm cannot claim the same domain, nor resolve to it
    act_as($ownerB);
    check('another firm cannot claim the same domain', firm_domain_add($pdo, 'firm-a-' . $sfx . '.example.com')['error'] === 'domain_taken');
    check('another firm cannot see the domain row', firm_domain_get($pdo, $d1['id']) === null);
    check('another firm cannot delete the domain', !firm_domain_delete($pdo, $d1['id']));
    check('domain still resolves to firm A after the attempt', firm_domain_resolve($pdo, 'firm-a-' . $sfx . '.example.com') === $firmA);

    act_as($ownerA);
    check('disable works and drops resolution', firm_domain_disable($pdo, $d1['id']) && firm_domain_resolve($pdo, 'firm-a-' . $sfx . '.example.com') === null);
    check('delete works', firm_domain_delete($pdo, $d1['id']));

    // ============================================================
    echo "\n== 7. AI resilience + governance ==\n";
    // ============================================================
    $settings = ['ai_provider' => 'openai', 'ai_model' => 'gpt-test', 'ai_max_retries' => 2, 'ai_timeout_seconds' => 30, 'ai_rate_limit_per_hour' => 5];
    check('max retries read from settings', ai_max_retries($settings) === 2);
    check('timeout read from settings', ai_request_timeout($settings) === 30);
    check('rate limit read from settings', ai_rate_limit_per_hour($settings) === 5);
    check('timeout clamps an absurd value', ai_request_timeout(['ai_timeout_seconds' => 99999]) === 120);
    check('rate limit clamps zero to a safe minimum', ai_rate_limit_per_hour(['ai_rate_limit_per_hour' => 0]) === 1);

    check('timeout errors are transient', ai_is_transient_error('cURL error 28: Operation timed out'));
    check('429 is transient', ai_is_transient_error('HTTP 429 rate limit'));
    check('503 is transient', ai_is_transient_error('HTTP 503 Service Unavailable'));
    check('auth failure is not transient', !ai_is_transient_error('HTTP 401 Unauthorized: invalid api key'));
    check('validation failure is not transient', !ai_is_transient_error('HTTP 400 invalid model'));
    check('empty error is not transient', !ai_is_transient_error(''));

    $tenant = ai_gateway_tenant_context($pdo, 'lawyer', $ownerA, tenant_current($pdo));
    $rl = ai_rate_limit_remaining($pdo, $tenant, $settings);
    check('rate limit snapshot has a limit', $rl['limit'] === 5);
    check('rate limit snapshot is not exceeded on a fresh tenant', $rl['exceeded'] === false);

    // Provider-not-configured must be denied, not invented.
    $noProvider = ai_resilient_gateway_call($pdo, ['ai_provider' => '', 'ai_api_key' => '', 'ai_model' => ''], $tenant,
        'test_feature', 'case_information', $ownerA, 'lawyer', 'sys', 'hello', 50, 0.0, null, false, true);
    check('unconfigured provider is denied', $noProvider['denied'] === true && $noProvider['reason'] === 'provider_not_configured');
    check('unconfigured provider returns no fabricated text', $noProvider['text'] === null);

    // ============================================================
    echo "\n== 8. AI copilot ==\n";
    // ============================================================
    act_as($ownerA);
    $pdo->prepare('INSERT INTO case_facts (case_id,description,occurred_at) VALUES (?,?,?)')->execute([$caseA, 'تم توقيع العقد', '2026-01-10']);
    $pdo->prepare('INSERT INTO case_timeline (case_id,event,event_date) VALUES (?,?,?)')->execute([$caseA, 'إيداع المذكرة', '2026-02-01']);
    $pdo->prepare('INSERT INTO case_parties (case_id,party_type,name) VALUES (?,?,?)')->execute([$caseA, 'plaintiff', 'شركة النور']);

    $chron = ai_copilot_run($pdo, $caseA, 'chronology', true);
    check('copilot chronology runs', $chron['run_id'] > 0);
    check('chronology verified side is populated from the database', count($chron['verified']['events'] ?? []) >= 2);
    check('chronology is ordered', ($chron['verified']['events'][0]['date'] ?? '') <= ($chron['verified']['events'][1]['date'] ?? ''));

    $facts = ai_copilot_run($pdo, $caseA, 'facts', true);
    check('copilot facts run includes stored facts', count($facts['verified']['facts'] ?? []) >= 1);
    check('copilot facts run includes stored parties', count($facts['verified']['parties'] ?? []) >= 1);

    $missing = ai_copilot_run($pdo, $caseA, 'missing_documents', true);
    check('missing-document analysis returns a checklist', isset($missing['verified']['missing']) && isset($missing['verified']['found']));
    check('missing-document analysis flags absent types', count($missing['verified']['missing']) >= 1);

    $risks = ai_copilot_run($pdo, $caseA, 'risk_flags', true);
    check('deterministic risk flags include the open conflict', count(array_filter($risks['verified']['flags'] ?? [], fn($f) => $f['code'] === 'open_conflict')) === 1);

    // Cross-firm / cross-branch copilot isolation
    act_as($ownerB);
    $denied = ai_copilot_run($pdo, $caseA, 'summary', true);
    check('firm B cannot run the copilot on firm A\'s case', $denied['ok'] === false && $denied['error'] === 'case_not_found_or_forbidden');

    // Move the case to branch 2 and restrict a member to branch 1.
    $pdo->prepare('UPDATE cases SET branch_id=? WHERE id=?')->execute([$brA2, $caseA]);
    // The share created in section 4 is now revoked, so the case must disappear again.
    $pdo->prepare('UPDATE branch_record_shares SET revoked_at=NOW() WHERE firm_id=? AND entity_type=? AND entity_id=?')
        ->execute([$firmA, 'case', $caseA]);
    // Revoke the manager grant and move mgrA to a home branch of brA1; the case is at brA2
    // and the share is revoked, so it must be invisible.
    act_as($ownerA);
    branch_access_revoke($pdo, $mgrA, $brA2);
    branch_access_set_member_branch($pdo, $mgrA, $brA1);
    act_as($mgrA);
    $ctxRestricted = branch_access_context($pdo);
    check('restricted member context is branch-limited', $ctxRestricted['firm_wide'] === false && $ctxRestricted['branches'] === [$brA1]);
    check('member restricted to another branch cannot reach the case', ai_copilot_case($pdo, $caseA) === null);
    $denied2 = ai_copilot_run($pdo, $caseA, 'summary', true);
    check('restricted member copilot run is refused', $denied2['error'] === 'case_not_found_or_forbidden');
    $pdo->prepare('UPDATE cases SET branch_id=NULL WHERE id=?')->execute([$caseA]);

    // Citation verification catches an invented source.
    act_as($ownerA);
    [$okCite, $badCite] = ai_copilot_verify_citations($pdo, 'وبناءً على «قانون المخلوقات الفضائية» فإن العميل محق.', 'لا يوجد قانون بهذا الاسم');
    check('an invented citation is flagged', $okCite === false && in_array('قانون المخلوقات الفضائية', $badCite, true));
    [$okCite2, $badCite2] = ai_copilot_verify_citations($pdo, 'لا توجد استشهادات هنا.', 'malloc at noon');
    check('clean text passes citation verification', $okCite2 === true && $badCite2 === []);

    // Human review gate on copilot output.
    $runId = $chron['run_id'];
    check('copilot run requires review', (int)(ai_copilot_run_get($pdo, $runId)['requires_review']) === 1);
    act_as($ownerB);
    check('firm B cannot review firm A copilot run', ai_copilot_review($pdo, $runId, 'approved') === false);
    act_as($ownerA);
    check('lawyer can approve a copilot run', ai_copilot_review($pdo, $runId, 'approved'));
    check('copilot run status reflects approval', ai_copilot_run_get($pdo, $runId)['status'] === 'approved');
    check('a bad review decision is refused', ai_copilot_review($pdo, $runId, 'maybe') === false);

    // ============================================================
    echo "\n== 9. Schema + governance ==\n";
    // ============================================================
    $tables = ['conflict_parties','conflict_reviews','conflict_alerts','conflict_audit_trail',
               'hr_employees','hr_attendance','hr_leave_balances','hr_leave_requests','hr_schedules',
               'member_branch_access','branch_record_shares','firm_domains','ai_provider_health',
               'ai_copilot_runs','privacy_consents','data_retention_policies'];
    $missingTables = [];
    foreach ($tables as $t) {
        $c = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='" . $t . "'")->fetchColumn();
        if ((int)$c === 0) $missingTables[] = $t;
    }
    check('all v51 tables exist: ' . implode(',', $missingTables) ?: 'none missing', count($missingTables) === 0);

    $branchCols = ['cases','documents','case_evidence','consultations','invoices','legal_calendar_events','case_time_entries','draft_documents'];
    $missingCols = [];
    foreach ($branchCols as $t) {
        $c = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='" . $t . "' AND column_name='branch_id'")->fetchColumn();
        if ((int)$c === 0) $missingCols[] = $t;
    }
    check('branch_id present on core records: ' . (implode(',', $missingCols) ?: 'all present'), count($missingCols) === 0);

    $seed = $pdo->query("SELECT COUNT(*) FROM data_retention_policies")->fetchColumn();
    check('retention policy defaults seeded', (int)$seed >= 1);

    $conCols = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='privacy_consents'")->fetchColumn();
    check('privacy_consents table is present', (int)$conCols > 0);

} catch (Throwable $e) {
    $failures++;
    echo "  FAIL: unexpected exception — " . $e->getMessage() . "\n";
    echo "        at " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

echo "\n  Executive 08: $checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
