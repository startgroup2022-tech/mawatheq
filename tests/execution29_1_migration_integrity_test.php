<?php
/**
 * Execution 29.1 — migration ledger integrity, v42 guards and tenant ownership.
 *
 * The regression suite for the defects fixed in this execution. It reproduces each
 * failure state on a scratch database built from the real schema, then asserts the
 * corrected behaviour, so a future edit that reintroduces a defect fails here.
 *
 * Scenarios covered (the ten required by the execution brief, plus two the brief
 * names elsewhere):
 *   1. fresh database
 *   2. fully migrated database
 *   3. partially applied v42
 *   4. missing reset columns
 *   5. only one of the two reset columns present
 *   6. missing indexes or constraints
 *   7. incorrect migration ledger entries
 *   8. multi-firm lawyer ownership ambiguity
 *   9. migration interrupted halfway
 *  10. repeated migration execution
 *   + ledger false-positive: a single column must not record a whole migration
 *   + no migration uses a positional AFTER naming an absent column
 *
 * Run: php tests/execution29_1_migration_integrity_test.php
 * Creates and drops its own scratch databases; touches no other schema.
 */

require_once __DIR__ . '/lib/migration_harness.php';

$failures = 0;
$checks = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        echo "  FAIL: $label\n";
        if ($detail !== '') {
            echo "        $detail\n";
        }
    } else {
        echo "  ok:   $label\n";
    }
}

$admin = e291_admin();
$root = e291_root();
$scratch = 'mawatheq_e291_regress';
$v42 = $root . '/database/upgrade_v42.sql';

echo "\n=== Execution 29.1 — migration integrity regression ===\n";

// ---------------------------------------------------------------------------
echo "\n== 1. fresh database ==\n";
// ---------------------------------------------------------------------------
// schema.sql is the consolidated current shape, so it already contains v42's objects.
// "Fresh database" here means a database where v42 has NOT been applied: load schema.sql,
// then remove what v42 declares. That is the state a real pre-v42 production database is in.
e291_build_without($admin, $scratch, $v42);
$m = e291_measure($admin, $scratch, $v42);
check('a fresh, pre-v42 database does not verify v42 yet', $m['state'] === 'missing', "state={$m['state']} {$m['present']}/{$m['total']}");
e291_apply_sql($admin, $scratch, $v42);
$m = e291_measure($admin, $scratch, $v42);
check('v42 verifies on a fresh database once applied', $m['state'] === 'verified', "state={$m['state']} {$m['present']}/{$m['total']}");
check('v42 added law_firms.legal_name to a fresh database', e291_has_column($admin, $scratch, 'law_firms', 'legal_name'));
check('v42 added cases.firm_id to a fresh database', e291_has_column($admin, $scratch, 'cases', 'firm_id'));

// ---------------------------------------------------------------------------
echo "\n== 2. fully migrated database ==\n";
// ---------------------------------------------------------------------------
e291_build_fresh($admin, $scratch);
$upgradeError = null;
try {
    e291_apply_all_upgrades($admin, $scratch);
} catch (Throwable $e) {
    $upgradeError = $e->getMessage();
}
check('every upgrade file applies without error', $upgradeError === null, (string)$upgradeError);

$allVerified = true;
$notVerified = [];
foreach (e291_upgrade_files() as $f) {
    $r = e291_measure($admin, $scratch, $f);
    if ($r['state'] !== 'verified') {
        $allVerified = false;
        $notVerified[] = basename($f) . "={$r['state']}({$r['present']}/{$r['total']})";
    }
}
check('every migration verifies on a fully migrated database', $allVerified, implode(', ', $notVerified));

// Ledger records all of them, and exits 0.
$run = e291_run_script('database/repair/02_migration_ledger.php', $scratch);
check('the ledger records the fully migrated database with exit 0', $run['exit'] === 0, $run['output']);
check('the ledger report says every migration is verified',
    str_contains($run['output'], 'partial: 0') && str_contains($run['output'], 'missing: 0'),
    $run['output']);

$admin->exec("USE `$scratch`");
$recordedVerified = (int)$admin->query("SELECT COUNT(*) FROM schema_migrations WHERE state='verified'")->fetchColumn();
$totalRecorded = (int)$admin->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
check('every recorded migration is in state verified', $recordedVerified === $totalRecorded && $totalRecorded > 0,
    "verified=$recordedVerified total=$totalRecorded");

// ---------------------------------------------------------------------------
echo "\n== 3. partially applied v42 ==\n";
// ---------------------------------------------------------------------------
// The production shape: a multi-column ALTER was guarded on one column, so a later run
// skipped it. Reproduce by applying v42, then dropping columns from the middle of what
// the old single-guard statements covered.
e291_build_without($admin, $scratch, $v42);
e291_apply_sql($admin, $scratch, $v42);
$admin->exec("USE `$scratch`");
// Drop columns the FIRST multi-column ALTER used to add together.
foreach (['display_name', 'email', 'phone'] as $c) {
    $admin->exec("ALTER TABLE law_firms DROP COLUMN `$c`");
}
$m = e291_measure($admin, $scratch, $v42);
check('v42 is reported PARTIAL, not verified, when columns are missing',
    $m['state'] === 'partial', "state={$m['state']} {$m['present']}/{$m['total']}");

// Re-running v42 must complete them, because each column is now guarded on itself.
// This is the property the old single-guard version lacked: it would see legal_name
// present and skip the whole statement again.
e291_apply_sql($admin, $scratch, $v42);
check('re-running v42 restores law_firms.display_name', e291_has_column($admin, $scratch, 'law_firms', 'display_name'));
check('re-running v42 restores law_firms.email', e291_has_column($admin, $scratch, 'law_firms', 'email'));
check('re-running v42 restores law_firms.phone', e291_has_column($admin, $scratch, 'law_firms', 'phone'));
$m = e291_measure($admin, $scratch, $v42);
check('v42 verifies again after the re-run completes it', $m['state'] === 'verified', "state={$m['state']} {$m['present']}/{$m['total']}");

// ---------------------------------------------------------------------------
echo "\n== 4. missing reset columns ==\n";
// ---------------------------------------------------------------------------
e291_build_fresh($admin, $scratch);
$admin->exec("USE `$scratch`");
foreach ([['users', 'reset_token'], ['users', 'reset_expires'], ['lawyers', 'session_invalidated_at'], ['api_tokens', 'revoked_at']] as [$t, $c]) {
    if (e291_has_column($admin, $scratch, $t, $c)) {
        $admin->exec("ALTER TABLE `$t` DROP COLUMN `$c`");
    }
}
check('the drift really happened — users.reset_token absent', !e291_has_column($admin, $scratch, 'users', 'reset_token'));
$pre = e291_run_script('database/repair/00_preflight_check.php', $scratch);
check('preflight exits 1 on a drifted database', $pre['exit'] === 1, "exit={$pre['exit']}");
check('preflight names the missing reset_token', str_contains($pre['output'], 'reset_token'), $pre['output']);

e291_apply_sql($admin, $scratch, $root . '/database/repair/01_repair_reset_columns.sql');
foreach ([['users', 'reset_token'], ['users', 'reset_expires'], ['lawyers', 'session_invalidated_at'], ['api_tokens', 'revoked_at']] as [$t, $c]) {
    check("the repair restored $t.$c", e291_has_column($admin, $scratch, $t, $c));
}

// ---------------------------------------------------------------------------
echo "\n== 5. only one of the two reset columns present ==\n";
// ---------------------------------------------------------------------------
e291_build_fresh($admin, $scratch);
$admin->exec("USE `$scratch`");
$admin->exec('ALTER TABLE users DROP COLUMN reset_token');
$admin->exec('ALTER TABLE users DROP COLUMN reset_expires');
$admin->exec('ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL'); // only one back
check('exactly one reset column is present before the repair',
    e291_has_column($admin, $scratch, 'users', 'reset_token') && !e291_has_column($admin, $scratch, 'users', 'reset_expires'));
e291_apply_sql($admin, $scratch, $root . '/database/repair/01_repair_reset_columns.sql');
check('the repair adds the missing sibling without touching the present one',
    e291_has_column($admin, $scratch, 'users', 'reset_token') && e291_has_column($admin, $scratch, 'users', 'reset_expires'));

// ---------------------------------------------------------------------------
echo "\n== 6. missing indexes or constraints ==\n";
// ---------------------------------------------------------------------------
e291_build_fresh($admin, $scratch);
e291_apply_sql($admin, $scratch, $v42);
$admin->exec("USE `$scratch`");
// firm_id stays, but its index and foreign key go — the case the old single guard skipped.
$admin->exec('ALTER TABLE cases DROP FOREIGN KEY fk_cases_firm');
$admin->exec('ALTER TABLE cases DROP INDEX idx_cases_firm');
check('cases.firm_id is present but its index and FK are not',
    e291_has_column($admin, $scratch, 'cases', 'firm_id')
    && !e291_has_index($admin, $scratch, 'cases', 'idx_cases_firm')
    && !e291_has_fk($admin, $scratch, 'cases', 'fk_cases_firm'));
$m = e291_measure($admin, $scratch, $v42);
check('v42 is PARTIAL when only the index and constraint are missing', $m['state'] === 'partial', "state={$m['state']}");

e291_apply_sql($admin, $scratch, $v42);
check('re-running v42 restores the index independently of the column', e291_has_index($admin, $scratch, 'cases', 'idx_cases_firm'));
check('re-running v42 restores the foreign key independently of the column', e291_has_fk($admin, $scratch, 'cases', 'fk_cases_firm'));
check('the foreign key keeps ON DELETE SET NULL',
    str_contains((string)$admin->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='$scratch' AND CONSTRAINT_NAME='fk_cases_firm'")->fetchColumn(), 'SET NULL'));

// ---------------------------------------------------------------------------
echo "\n== 7. incorrect migration ledger entries ==\n";
// ---------------------------------------------------------------------------
// A ledger row that claims a migration is applied when its objects are absent. The ledger
// must not trust the row: it re-measures and reports the truth, and must not overwrite the
// historical checksum.
e291_build_fresh($admin, $scratch);
e291_apply_sql($admin, $scratch, $v42);
e291_apply_sql($admin, $scratch, $root . '/database/upgrade_v55.sql');
$admin->exec("USE `$scratch`");
$admin->exec("INSERT INTO schema_migrations (filename, checksum, statements_ok, state, requirements_total, requirements_present, current_checksum)
    VALUES ('upgrade_v42.sql', REPEAT('a',64), 3, 'verified', 41, 41, REPEAT('a',64))");
$admin->exec('ALTER TABLE law_firms DROP COLUMN display_name'); // make the claim false
$m = e291_measure($admin, $scratch, $v42);
check('the claim is false: v42 measures partial despite the ledger row', $m['state'] === 'partial');

$run = e291_run_script('database/repair/02_migration_ledger.php', $scratch);
check('the ledger run exits 1 because a migration is incomplete', $run['exit'] === 1, "exit={$run['exit']}");
$row = $admin->query("SELECT checksum, current_checksum, state FROM schema_migrations WHERE filename='upgrade_v42.sql'")->fetch(PDO::FETCH_ASSOC);
check('the historical checksum was NOT overwritten', $row['checksum'] === str_repeat('a', 64), json_encode($row));
check('the falsified state is downgraded to partial', $row['state'] === 'partial', json_encode($row));

// ---------------------------------------------------------------------------
echo "\n== 8. multi-firm lawyer ownership ambiguity ==\n";
// ---------------------------------------------------------------------------
e291_build_fresh($admin, $scratch);
e291_apply_sql($admin, $scratch, $v42);
$admin->exec("USE `$scratch`");

/**
 * Seed two firms, a lawyer who is a member of both (the ambiguity), another lawyer in one
 * firm, and records owned by each. law_firm_members carries UNIQUE(lawyer_id), so the
 * ambiguous membership requires dropping that key — exactly the change a naive fix would
 * make, and exactly the situation the backfill must survive without leaking.
 */
$admin->exec("INSERT INTO lawyers (id, full_name, email, password_hash) VALUES
    (9001,'Lawyer Shared','shared@example.test','x'),
    (9002,'Lawyer Solo A','soloA@example.test','x'),
    (9003,'Lawyer B','soloB@example.test','x')");
// law_firms.owner_lawyer_id is a real FK to lawyers, so the lawyers must exist first.
$admin->exec("INSERT INTO law_firms (id, name, owner_lawyer_id) VALUES (9001,'Firm A',9001),(9002,'Firm B',9003)");
// The ambiguous membership. UNIQUE(lawyer_id) both prevents the situation and backs the
// lawyer_id foreign key, so relaxing it requires: drop the FK, drop the unique index, add
// a plain index, restore the FK. This is exactly the change a naive fix would make.
$admin->exec('ALTER TABLE law_firm_members DROP FOREIGN KEY law_firm_members_ibfk_2');
$admin->exec('ALTER TABLE law_firm_members DROP INDEX uq_law_firm_member_lawyer');
$admin->exec('ALTER TABLE law_firm_members ADD INDEX idx_lfm_lawyer (lawyer_id)');
$admin->exec('ALTER TABLE law_firm_members ADD CONSTRAINT law_firm_members_ibfk_2 FOREIGN KEY (lawyer_id) REFERENCES lawyers (id) ON DELETE CASCADE');
$admin->exec("INSERT INTO law_firm_members (firm_id, lawyer_id) VALUES (9001,9001),(9002,9001),(9001,9002)");
check('the shared lawyer really is in two firms',
    (int)$admin->query('SELECT COUNT(DISTINCT firm_id) FROM law_firm_members WHERE lawyer_id=9001')->fetchColumn() === 2);

// Records: two cases owned by the shared lawyer (ambiguous), one owned by the solo lawyer.
// cases.user_id is a real FK to users, so those rows must exist first.
$admin->exec("INSERT INTO users (id, full_name, email, password_hash) VALUES
    (9001,'User One','u1@example.test','x'),
    (9002,'User Two','u2@example.test','x')");
$admin->exec("INSERT INTO cases (id, lawyer_id, user_id, title, firm_id) VALUES
    (9001,9001,9001,'Shared case 1',NULL),
    (9002,9001,9001,'Shared case 2',NULL),
    (9003,9002,9002,'Solo case',NULL)");
// One case that ALREADY has a valid firm_id which must never be overwritten.
$admin->exec("INSERT INTO cases (id, lawyer_id, user_id, title, firm_id) VALUES (9004,9002,9002,'Pre-owned case',9002)");

$casesBefore = e291_row_count($admin, $scratch, 'cases');
e291_apply_sql($admin, $scratch, $v42);
$casesAfter = e291_row_count($admin, $scratch, 'cases');
check('the backfill dropped no records', $casesBefore === $casesAfter, "before=$casesBefore after=$casesAfter");

$admin->exec("USE `$scratch`");
$sharedNull = (int)$admin->query('SELECT COUNT(*) FROM cases WHERE id IN (9001,9002) AND firm_id IS NULL')->fetchColumn();
check('the ambiguous lawyer\'s cases were left NULL, not assigned to an arbitrary firm', $sharedNull === 2, "null=$sharedNull");

$soloFirm = $admin->query('SELECT firm_id FROM cases WHERE id=9003')->fetchColumn();
check('the unambiguous case was assigned to its own firm', (int)$soloFirm === 9001, "firm_id=" . var_export($soloFirm, true));

$preOwned = $admin->query('SELECT firm_id FROM cases WHERE id=9004')->fetchColumn();
check('a pre-existing valid firm_id was not overwritten', (int)$preOwned === 9002, "firm_id=" . var_export($preOwned, true));

$leaked = (int)$admin->query('SELECT COUNT(*) FROM cases WHERE lawyer_id=9001 AND firm_id IS NOT NULL')->fetchColumn();
check('no ambiguous record leaked into any firm', $leaked === 0, "leaked=$leaked");

// ---------------------------------------------------------------------------
echo "\n== 9. migration interrupted halfway ==\n";
// ---------------------------------------------------------------------------
// Apply only part of v42 — the shape an interrupted run leaves. 60 statements covers the
// law_firms and law_firm_members column blocks but stops before the firm-owned-entity
// ALTERs, so some objects land and the rest do not. The base must genuinely predate v42,
// or the "interruption" would be invisible.
e291_build_without($admin, $scratch, $v42);
$admin->exec("USE `$scratch`");
e291_apply_sql_truncated($admin, $scratch, $v42, 60);
$m = e291_measure($admin, $scratch, $v42);
check('an interrupted v42 measures partial, not verified', $m['state'] === 'partial', "state={$m['state']} {$m['present']}/{$m['total']}");
check('the interruption is partial rather than wholly missing (some objects landed)', $m['present'] > 0, "present={$m['present']}");
e291_apply_sql($admin, $scratch, $v42);
$m = e291_measure($admin, $scratch, $v42);
check('completing the interrupted run makes v42 verify', $m['state'] === 'verified', "state={$m['state']} {$m['present']}/{$m['total']}");

// ---------------------------------------------------------------------------
echo "\n== 10. repeated migration execution ==\n";
// ---------------------------------------------------------------------------
e291_build_fresh($admin, $scratch);
e291_apply_sql($admin, $scratch, $v42);
$admin->exec("USE `$scratch`");
$tables = ['law_firms', 'law_firm_members', 'cases', 'consultations', 'invoices', 'draft_documents',
    'case_evidence', 'case_notes', 'case_time_entries', 'legal_calendar_events', 'audit_logs'];
$before = e291_schema_fingerprint($admin, $scratch, $tables);
e291_apply_sql($admin, $scratch, $v42);
e291_apply_sql($admin, $scratch, $v42);
$after = e291_schema_fingerprint($admin, $scratch, $tables);
check('running v42 three times leaves the schema byte-identical', $before === $after);
$m = e291_measure($admin, $scratch, $v42);
check('v42 still verifies after repeated runs', $m['state'] === 'verified');

// ---------------------------------------------------------------------------
echo "\n== 11. the ledger cannot record unproven work (false positive) ==\n";
// ---------------------------------------------------------------------------
// v42's objects are entirely absent here: firm_id, legal_name, every index and FK.
// The OLD ledger recorded v42 on the strength of audit_logs.firm_id alone; the new one
// must record nothing, because nothing is present.
e291_build_without($admin, $scratch, $v42);
e291_apply_sql($admin, $scratch, $root . '/database/upgrade_v55.sql');
$admin->exec("USE `$scratch`");
$m = e291_measure($admin, $scratch, $v42);
check('v42 measures missing when none of its objects exist', $m['state'] === 'missing', "state={$m['state']} {$m['present']}/{$m['total']}");
$run = e291_run_script('database/repair/02_migration_ledger.php', $scratch);
check('the ledger exits 1 rather than recording an unapplied migration', $run['exit'] === 1, "exit={$run['exit']}");
$row = $admin->query("SELECT COUNT(*) FROM schema_migrations WHERE filename='upgrade_v42.sql'")->fetchColumn();
check('no ledger row was written for the unapplied migration', (int)$row === 0, "rows=$row");
check('the output states the migration could not be verified', str_contains($run['output'], 'not applied') || str_contains($run['output'], 'could not be verified'), $run['output']);

// The single-column false positive specifically: add ONLY audit_logs.firm_id and assert
// that v42 is still not verified, which is the exact defect the old ledger had.
$admin->exec('ALTER TABLE audit_logs ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL');
$m = e291_measure($admin, $scratch, $v42);
check('a lone proof column does NOT satisfy v42', $m['state'] === 'partial', "state={$m['state']} {$m['present']}/{$m['total']}");
check('the lone column counts as 1 of many, not as complete', $m['present'] === 1 && $m['total'] > 1, "present={$m['present']} total={$m['total']}");

// ---------------------------------------------------------------------------
echo "\n== 12. no migration can abort on a positional AFTER ==\n";
// ---------------------------------------------------------------------------
$offenders = [];
foreach (e291_upgrade_files() as $f) {
    $clean = mawatheq_sql_strip_comments((string)file_get_contents($f));
    if (preg_match('/\bAFTER\s+`?actor_name`?/i', $clean)) {
        $offenders[] = basename($f);
    }
}
check('no migration positions a column after actor_name', $offenders === [], implode(', ', $offenders));
check('upgrade_v42.sql contains no AFTER clause at all',
    !preg_match('/\bAFTER\b/i', mawatheq_sql_strip_comments((string)file_get_contents($v42))));

// ---------------------------------------------------------------------------
echo "\n== cleanup ==\n";
// ---------------------------------------------------------------------------
e291_drop($admin, $scratch);
check('scratch database dropped', true);

echo "\n  Execution 29.1 migration integrity: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
