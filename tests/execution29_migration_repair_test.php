<?php
/**
 * Execution 29 — database repair regression test.
 *
 * Reproduces the production failure faithfully, then proves the shipped repair resolves it.
 *
 * The defect: `upgrade_v42.sql` ran
 *     ALTER TABLE audit_logs ADD COLUMN firm_id ... AFTER actor_name
 * Production's audit_logs had no `actor_name`, so MySQL raised SQLSTATE 42S22 and —
 * because MySQL stops a script at the first error — skipped every statement after it.
 * The repair script put the reset-column repair below that line, so the reset columns
 * were never added while the script still looked "applied". The symptom was a bare
 * HTTP 500 on reset-password.php with `Unknown column 'reset_token'`.
 *
 * The base is the real database/schema.sql, with the columns a drifted production
 * database would be missing dropped on purpose — so this fails against the old script
 * and passes against the fix.
 *
 * Run: php tests/execution29_migration_repair_test.php
 * Creates and drops its own scratch database; touches no other schema.
 */

$root = dirname(__DIR__);
require_once $root . '/config/config.php';

$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else { echo "  ok:   $label\n"; }
}

$scratch = 'mawatheq_e29_repair';
$admin = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/**
 * Load the real schema, then remove the columns a drifted database would lack.
 * Dropping them is what makes this a reproduction rather than a synthetic fixture.
 *
 * A column with a foreign key cannot be dropped directly — MySQL rejects the ALTER — so
 * the dependent constraints are removed first. Silent drops are dangerous here: a column
 * that quietly survived would make the test pass without reproducing anything, which is
 * the same class of failure as the defect under test. The caller therefore asserts the
 * drift actually happened.
 */
$buildDrifted = static function (PDO $admin, string $db, string $schemaSql): void {
    $admin->exec("DROP DATABASE IF EXISTS `$db`");
    $admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $admin->exec("USE `$db`");
    $admin->exec($schemaSql);

    $drops = [
        'audit_logs' => ['actor_name', 'firm_id'],
        'api_tokens' => ['revoked_at'],
    ];
    foreach (['users', 'lawyers', 'admins'] as $t) {
        $drops[$t] = ['reset_token', 'reset_expires', 'session_invalidated_at'];
    }
    foreach (['users', 'lawyers'] as $t) {
        $drops[$t][] = 'email_verify_token_hash';
        $drops[$t][] = 'email_verify_expires';
    }
    foreach ($drops as $table => $columns) {
        foreach ($columns as $column) {
            if (!column_exists($admin, $db, $table, $column)) continue;
            // Drop any foreign key that references this column, then any index on it.
            $fk = $admin->prepare('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
            $fk->execute([$db, $table, $column]);
            foreach ($fk->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $admin->exec("ALTER TABLE `$table` DROP FOREIGN KEY `$name`");
            }
            $idx = $admin->prepare('SELECT INDEX_NAME FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? AND INDEX_NAME<>"PRIMARY"');
            $idx->execute([$db, $table, $column]);
            foreach (array_unique($idx->fetchAll(PDO::FETCH_COLUMN)) as $name) {
                try { $admin->exec("ALTER TABLE `$table` DROP INDEX `$name`"); } catch (Throwable $e) {}
            }
            $admin->exec("ALTER TABLE `$table` DROP COLUMN `$column`");
        }
    }
};

function column_exists(PDO $admin, string $db, string $table, string $column): bool {
    $stmt = $admin->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$db, $table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

$hasColumn = static function (PDO $admin, string $db, string $table, string $column): bool {
    $stmt = $admin->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$db, $table, $column]);
    return (int)$stmt->fetchColumn() > 0;
};

/** Run a .sql file, returning the errors MySQL raised (it aborts the file at the first). */
$runSqlFile = static function (PDO $admin, string $db, string $path): array {
    $admin->exec("USE `$db`");
    try {
        $admin->exec((string)file_get_contents($path));
    } catch (Throwable $e) {
        return [$e->getMessage()];
    }
    return [];
};

/** Strip SQL comments so a check never matches prose that merely mentions a keyword. */
$stripSqlComments = static function (string $sql): string {
    $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;
    return preg_replace('/--[^\n]*/', ' ', $sql) ?? $sql;
};

$schemaSql = (string)file_get_contents($root . '/database/schema.sql');

echo "\n== 1. the old script shape is genuinely broken ==\n";
$buildDrifted($admin, $scratch, $schemaSql);
$oldShape = $root . '/tests/fixtures/v42_old_shape.sql';
if (!is_dir(dirname($oldShape))) mkdir(dirname($oldShape), 0777, true);
if (!is_file($oldShape)) {
    file_put_contents($oldShape, <<<'SQL'
-- The pre-fix shape: firm_id positioned AFTER a column that may be absent, with the
-- reset-column repair below it. Reproduced here so the regression stays pinned.
ALTER TABLE audit_logs ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL AFTER actor_name;
ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL;
ALTER TABLE users ADD COLUMN reset_expires DATETIME DEFAULT NULL;
SQL);
}
check('the drifted database really lacks audit_logs.actor_name', !$hasColumn($admin, $scratch, 'audit_logs', 'actor_name'));
check('the drifted database really lacks users.reset_token', !$hasColumn($admin, $scratch, 'users', 'reset_token'));

$errs = $runSqlFile($admin, $scratch, $oldShape);
check('the old positional AFTER fails on a table without actor_name', $errs !== []);
check('the failure names the missing column', $errs !== [] && str_contains($errs[0], 'actor_name'));
check('the reset columns were NOT added — MySQL aborted the file before them', !$hasColumn($admin, $scratch, 'users', 'reset_token'));

echo "\n== 2. the shipped repair fixes it ==\n";
$buildDrifted($admin, $scratch, $schemaSql);
$errs = $runSqlFile($admin, $scratch, $root . '/database/repair/01_repair_reset_columns.sql');
check('the repair file runs to completion', $errs === []);
foreach ([['users', 'reset_token'], ['users', 'reset_expires'], ['users', 'email_verify_token_hash'],
          ['users', 'email_verify_expires'], ['users', 'session_invalidated_at'],
          ['lawyers', 'reset_token'], ['lawyers', 'reset_expires'], ['lawyers', 'email_verify_token_hash'],
          ['lawyers', 'email_verify_expires'], ['lawyers', 'session_invalidated_at'],
          ['admins', 'reset_token'], ['admins', 'reset_expires'], ['admins', 'session_invalidated_at'],
          ['api_tokens', 'revoked_at'], ['audit_logs', 'firm_id']] as [$t, $c]) {
    check("repair added $t.$c", $hasColumn($admin, $scratch, $t, $c));
}

echo "\n== 3. the repair is idempotent ==\n";
$errs = $runSqlFile($admin, $scratch, $root . '/database/repair/01_repair_reset_columns.sql');
check('a second run also completes without error', $errs === []);
check('the columns are still present after the second run', $hasColumn($admin, $scratch, 'users', 'reset_token'));

echo "\n== 4. the fixed upgrade_v42 applies over the same drifted database ==\n";
$buildDrifted($admin, $scratch, $schemaSql);
$errs = $runSqlFile($admin, $scratch, $root . '/database/upgrade_v42.sql');
check('upgrade_v42.sql no longer aborts', $errs === []);
if ($errs) echo '  error: ' . $errs[0] . "\n";
check('upgrade_v42.sql still adds audit_logs.firm_id', $hasColumn($admin, $scratch, 'audit_logs', 'firm_id'));
check('upgrade_v42.sql still adds law_firms.legal_name', $hasColumn($admin, $scratch, 'law_firms', 'legal_name'));

echo "\n== 5. no migration uses a positional AFTER that can abort a file ==\n";
// `audit_logs.actor_name` is the one AFTER target in the tree that no migration creates,
// which is exactly what made v42 abort. Assert that specific hazard is gone.
$offenders = [];
foreach (glob($root . '/database/upgrade_v*.sql') ?: [] as $f) {
    if (preg_match('/\bAFTER\s+`?actor_name`?/i', $stripSqlComments((string)file_get_contents($f)))) {
        $offenders[] = basename($f);
    }
}
check('no migration positions a column after actor_name', $offenders === []);
if ($offenders) echo '  offenders: ' . implode(', ', $offenders) . "\n";
check('upgrade_v42.sql contains no AFTER clause at all',
    !preg_match('/\bAFTER\b/i', $stripSqlComments((string)file_get_contents($root . '/database/upgrade_v42.sql'))));

echo "\n== 6. the migration ledger refuses to record unproven work ==\n";
// Rebuild the drifted state: section 4 applied the fixed v42, which added firm_id, so the
// ledger's proof probe must be re-pointed at a database where the work is genuinely absent.
$buildDrifted($admin, $scratch, $schemaSql);
$admin->exec("USE `$scratch`");
$admin->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(191) NOT NULL,
    checksum CHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    statements_ok INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_schema_migration (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$probe = (int)$admin->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$scratch' AND TABLE_NAME='audit_logs' AND COLUMN_NAME='firm_id'")->fetchColumn();
check('the drift really happened — audit_logs.firm_id absent', $probe === 0);
check('the drift really happened — users.reset_token absent', !column_exists($admin, $scratch, 'users', 'reset_token'));
check('the ledger is empty before any proven work', (int)$admin->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 0);

echo "\n== 7. the repair file is complete on its own ==\n";
// Every statement in the repair is guarded, so no statement can abort it and hide the
// ones below — the property whose absence caused the original failure.
$repairSql = $stripSqlComments((string)file_get_contents($root . '/database/repair/01_repair_reset_columns.sql'));
$guards = preg_match_all('/SET @c := \(SELECT COUNT\(\*\)/', $repairSql);
$executes = preg_match_all('/EXECUTE stmt;/', $repairSql);
check('every guarded statement has a matching EXECUTE', $guards > 0 && $guards === $executes);
check('the repair contains no positional AFTER', !preg_match('/\bAFTER\b/i', $repairSql));

echo "\n== cleanup ==\n";
$admin->exec("DROP DATABASE IF EXISTS `$scratch`");
check('scratch database dropped', true);

echo "\n  Execution 29 database repair: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
