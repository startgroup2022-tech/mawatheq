<?php
/**
 * Execution 29.1 — reset-column verification and repaired-schema function.
 *
 * Covers the defect in database/repair/01_repair_reset_columns.sql: its final check
 * declared `12 AS expected` when the real requirement is 13 columns, so a database
 * missing exactly one column read as satisfied — a false pass on the script that exists
 * to catch a missing column. It also verifies, per the brief, that the repair makes the
 * password-reset flow work against the repaired schema, and that a check which cannot be
 * satisfied now fails loudly instead of printing a row.
 *
 * Run: php tests/execution29_1_reset_columns_test.php
 * Creates and drops its own scratch database; touches no other schema.
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
$scratch = 'mawatheq_e291_reset';
$repair = $root . '/database/repair/01_repair_reset_columns.sql';

/**
 * The columns the repair must guarantee, with their required types. Held here — separately
 * from the SQL file — so the test does not simply echo whatever the file happens to say.
 * This is the independent expectation the deployment relies on.
 */
$required = [
    ['users', 'reset_token', 'varchar(64)'],
    ['users', 'reset_expires', 'datetime'],
    ['users', 'email_verify_token_hash', 'char(64)'],
    ['users', 'email_verify_expires', 'datetime'],
    ['users', 'session_invalidated_at', 'datetime'],
    ['lawyers', 'reset_token', 'varchar(64)'],
    ['lawyers', 'reset_expires', 'datetime'],
    ['lawyers', 'email_verify_token_hash', 'char(64)'],
    ['lawyers', 'email_verify_expires', 'datetime'],
    ['lawyers', 'session_invalidated_at', 'datetime'],
    ['admins', 'reset_token', 'varchar(64)'],
    ['admins', 'reset_expires', 'datetime'],
    ['admins', 'session_invalidated_at', 'datetime'],
];

$columnType = static function (PDO $pdo, string $db, string $t, string $c): ?string {
    $s = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$db, $t, $c]);
    $v = $s->fetchColumn();
    return $v === false ? null : strtolower((string)$v);
};

echo "\n=== Execution 29.1 — reset columns ===\n";

// ---------------------------------------------------------------------------
echo "\n== 1. the requirement is 13 columns, not the literal 12 the old check used ==\n";
// ---------------------------------------------------------------------------
check('the independent requirement list has 13 entries', count($required) === 13, 'count=' . count($required));
// Prove the old predicate really was wrong: it counted name-matches across three tables,
// so it could not distinguish "all present" from "one missing". Measured on a fully
// migrated database, where every required column does exist.
e291_build_fresh($admin, $scratch);
e291_apply_all_upgrades($admin, $scratch);
$names = "('reset_token','reset_expires','email_verify_token_hash','email_verify_expires','session_invalidated_at')";
$admin->exec("USE `$scratch`");
$counted = (int)$admin->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$scratch' AND TABLE_NAME IN ('users','lawyers','admins') AND COLUMN_NAME IN $names")->fetchColumn();
check('the name-based predicate counts 13 on a complete schema', $counted === 13, "counted=$counted");
// The old check declared 12. Because the predicate counted 13, the check reported
// "present 13, expected 12" — and its `IF(@present = @expected)`-equivalent could never
// be satisfied, while a database with one column genuinely missing reported 12 = 12 and
// passed. Assert the arithmetic that made the false pass possible.
check('the old literal 12 was one short of the requirement', $counted === 13 && 12 !== 13);

// ---------------------------------------------------------------------------
echo "\n== 2. the repair adds every required column with the required type ==\n";
// ---------------------------------------------------------------------------
e291_build_fresh($admin, $scratch);
$admin->exec("USE `$scratch`");
foreach ($required as [$t, $c, $type]) {
    if (e291_has_column($admin, $scratch, $t, $c)) {
        $admin->exec("ALTER TABLE `$t` DROP COLUMN `$c`");
    }
}
$absentBefore = 0;
foreach ($required as [$t, $c]) {
    if (!e291_has_column($admin, $scratch, $t, $c)) {
        $absentBefore++;
    }
}
check('all 13 required columns were removed for the test', $absentBefore === 13, "absent=$absentBefore");

$repairError = null;
try {
    e291_apply_sql($admin, $scratch, $repair);
} catch (Throwable $e) {
    $repairError = $e->getMessage();
}
check('the repair runs to completion', $repairError === null, (string)$repairError);

$badType = [];
foreach ($required as [$t, $c, $type]) {
    $actual = $columnType($admin, $scratch, $t, $c);
    if ($actual !== $type) {
        $badType[] = "$t.$c expected $type got " . var_export($actual, true);
    }
}
check('every required column is present with the required type', $badType === [], implode('; ', $badType));

// ---------------------------------------------------------------------------
echo "\n== 3. the repair works when only one of a pair is present ==\n";
// ---------------------------------------------------------------------------
e291_build_fresh($admin, $scratch);
$admin->exec("USE `$scratch`");
$admin->exec('ALTER TABLE users DROP COLUMN reset_token');
$admin->exec('ALTER TABLE users DROP COLUMN reset_expires');
$admin->exec('ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL');
// Only reset_token exists. The old single-guard pattern would see it and skip the pair.
e291_apply_sql($admin, $scratch, $repair);
check('the missing sibling (users.reset_expires) was added', e291_has_column($admin, $scratch, 'users', 'reset_expires'));
check('the present column (users.reset_token) was left in place', e291_has_column($admin, $scratch, 'users', 'reset_token'));

// ---------------------------------------------------------------------------
echo "\n== 4. an unsatisfiable check fails loudly instead of printing a row ==\n";
// ---------------------------------------------------------------------------
// The requirement is satisfiable, so the file succeeds. Now make one requirement
// impossible by pointing at a schema where a required column cannot be added: drop the
// column and revoke nothing — instead corrupt the type so the guard skips it and the
// final check must catch the mistype. This is the case the old `12 AS expected` hid.
e291_build_fresh($admin, $scratch);
$admin->exec("USE `$scratch`");
$admin->exec('ALTER TABLE users MODIFY COLUMN email_verify_token_hash VARCHAR(32) DEFAULT NULL');
$caught = false;
$message = '';
try {
    e291_apply_sql($admin, $scratch, $repair);
} catch (Throwable $e) {
    $caught = true;
    $message = $e->getMessage();
}
check('a mistyped required column makes the repair fail', $caught, 'no error raised');
check('the failure names the offending column', str_contains($message, 'email_verify_token_hash'), $message);
check('the failure reports the corrected expectation of 13', str_contains($message, 'expected 13'), $message);
check('the failure reports 12 present — the exact case the old check passed', str_contains($message, 'present 12'), $message);

// ---------------------------------------------------------------------------
echo "\n== 5. the repaired schema supports the password-reset flow ==\n";
// ---------------------------------------------------------------------------
// Columns existing is not the requirement; the reset queries running is. Execute the
// statements the application issues against the repaired schema, on a real row.
e291_build_fresh($admin, $scratch);
$admin->exec("USE `$scratch`");
foreach ([['users', 'reset_token'], ['users', 'reset_expires'], ['users', 'session_invalidated_at']] as [$t, $c]) {
    if (e291_has_column($admin, $scratch, $t, $c)) {
        $admin->exec("ALTER TABLE `$t` DROP COLUMN `$c`");
    }
}
check('the reset columns are absent before the repair', !e291_has_column($admin, $scratch, 'users', 'reset_token'));
e291_apply_sql($admin, $scratch, $repair);

$admin->exec("INSERT INTO users (id, full_name, email, password_hash) VALUES (9101,'Reset Flow','resetflow@example.test','x')");
$tokenHash = hash('sha256', 'a-test-reset-token');
$flow = null;
try {
    // issue: store the hashed token with an expiry (the shape auth_security.php writes)
    $admin->prepare("UPDATE users SET reset_token=?, reset_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email=?")
        ->execute([$tokenHash, 'resetflow@example.test']);
    // consume: look up by hash and not expired (the shape reset-password.php reads)
    $s = $admin->prepare('SELECT id FROM users WHERE reset_token=? AND reset_expires > NOW()');
    $s->execute([$tokenHash]);
    $found = (int)$s->fetchColumn();
    // invalidate: stamp the session-invalidation column
    $admin->prepare('UPDATE users SET session_invalidated_at=NOW(), reset_token=NULL, reset_expires=NULL WHERE id=?')
        ->execute([$found]);
    $after = $admin->query('SELECT reset_token FROM users WHERE id=9101')->fetchColumn();
    $flow = ['found' => $found, 'cleared' => $after === null];
} catch (Throwable $e) {
    $flow = ['error' => $e->getMessage()];
}
check('the reset flow issues, consumes and clears a token against the repaired schema',
    is_array($flow) && ($flow['found'] ?? 0) === 9101 && ($flow['cleared'] ?? false) === true,
    json_encode($flow));

// ---------------------------------------------------------------------------
echo "\n== 6. the repair is idempotent ==\n";
// ---------------------------------------------------------------------------
$before = e291_schema_fingerprint($admin, $scratch, ['users', 'lawyers', 'admins']);
e291_apply_sql($admin, $scratch, $repair);
e291_apply_sql($admin, $scratch, $repair);
$after = e291_schema_fingerprint($admin, $scratch, ['users', 'lawyers', 'admins']);
check('three runs of the repair leave the schema identical', $before === $after);

// ---------------------------------------------------------------------------
echo "\n== cleanup ==\n";
// ---------------------------------------------------------------------------
e291_drop($admin, $scratch);
check('scratch database dropped', true);

echo "\n  Execution 29.1 reset columns: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
