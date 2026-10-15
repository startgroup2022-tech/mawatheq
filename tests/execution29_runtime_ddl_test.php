<?php
/**
 * Execution 29 — runtime schema mutation probe.
 *
 * Ordinary HTTP requests used to repair a missing column as a side effect
 * (schema_ensure_column on config.php and api/v1/bootstrap.php). That means the schema
 * could be changed by whichever request arrived first, concurrently, with no ordering
 * against the migration files and no record of what was applied.
 *
 * This test removes `documents.case_id`, drives real requests, and asserts the column is
 * still absent afterwards — i.e. serving a page no longer mutates the schema. It then
 * restores the column so the rest of the suite is unaffected, and confirms the opt-in
 * escape hatch still works when explicitly enabled.
 *
 * Requires the app running on 127.0.0.1:8080.
 * Run: php tests/execution29_runtime_ddl_test.php
 */

require_once __DIR__ . '/../config/config.php';

$base = 'http://127.0.0.1:8080';
$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else { echo "  ok:   $label\n"; }
}

$hit = function (string $path) use ($base): int {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
};

$hasColumn = function (string $table, string $column) use ($pdo): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$table, $column]);
    return (int)$s->fetchColumn() > 0;
};

// documents.case_id and lawyers.jurisdiction are the columns the guard self-heals.
// documents.case_id carries a foreign key and an index, so removing it for the test
// means capturing and restoring those too — a bare DROP COLUMN is rejected by InnoDB.
$targets = [
    ['documents', 'case_id', 'INT UNSIGNED DEFAULT NULL'],
    ['lawyers', 'jurisdiction', "VARCHAR(100) NOT NULL DEFAULT 'السعودية'"],
];

/** Capture the FK (with its delete/update rules) and indexes so they restore verbatim. */
$capture = function (string $table, string $column) use ($pdo): array {
    $fk = $pdo->prepare('SELECT k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
            r.DELETE_RULE, r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA=k.TABLE_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME
        WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.COLUMN_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL');
    $fk->execute([$table, $column]);
    $idx = $pdo->prepare('SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND INDEX_NAME<>"PRIMARY"');
    $idx->execute([$table, $column]);
    return ['fk' => $fk->fetchAll(), 'idx' => array_unique($idx->fetchAll(), SORT_REGULAR)];
};

/** Remove the column along with anything that depends on it. */
$dropColumn = function (string $table, string $column, array $meta) use ($pdo): void {
    foreach ($meta['fk'] as $f) {
        $pdo->exec("ALTER TABLE `$table` DROP FOREIGN KEY `{$f['CONSTRAINT_NAME']}`");
    }
    foreach ($meta['idx'] as $i) {
        $pdo->exec("ALTER TABLE `$table` DROP INDEX `{$i['INDEX_NAME']}`");
    }
    $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$column`");
};

/** Put the column back, then its index, then its foreign key. Idempotent. */
$restoreColumn = function (string $table, string $column, string $definition, array $meta) use ($pdo, $hasColumn): void {
    if (!$hasColumn($table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
    $existingIdx = $pdo->prepare('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    foreach ($meta['idx'] as $i) {
        $existingIdx->execute([$table, $i['INDEX_NAME']]);
        if (!$existingIdx->fetchColumn()) {
            $pdo->exec("ALTER TABLE `$table` ADD " . ((int)$i['NON_UNIQUE'] === 0 ? 'UNIQUE ' : '') . "INDEX `{$i['INDEX_NAME']}` (`$column`)");
        }
    }
    $existingFk = $pdo->prepare('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
    foreach ($meta['fk'] as $f) {
        $existingFk->execute([$table, $f['CONSTRAINT_NAME']]);
        if (!$existingFk->fetchColumn()) {
            $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `{$f['CONSTRAINT_NAME']}` FOREIGN KEY (`$column`) REFERENCES `{$f['REFERENCED_TABLE_NAME']}` (`{$f['REFERENCED_COLUMN_NAME']}`) ON DELETE {$f['DELETE_RULE']} ON UPDATE {$f['UPDATE_RULE']}");
        }
    }
};

echo "\n== setup ==\n";
// Establish a known baseline. An earlier crashed run of this test can leave the column
// present but its foreign key and index missing, so the baseline is asserted and, if
// needed, repaired before anything is measured.
$baselineFk = 'fk_documents_case';
$baselineIdx = 'idx_documents_case';
// The migration defines this constraint as ON DELETE SET NULL; assert the rule, not just
// the name, so a half-restored constraint from an interrupted run is corrected rather
// than silently accepted.
$fkRule = function () use ($pdo, $baselineFk): ?string {
    $s = $pdo->prepare('SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME=?');
    $s->execute([$baselineFk]);
    $r = $s->fetchColumn();
    return $r === false ? null : (string)$r;
};
if (!$hasColumn('documents', 'case_id')) {
    $pdo->exec('ALTER TABLE documents ADD COLUMN case_id INT UNSIGNED DEFAULT NULL');
}
if ($fkRule() !== null && $fkRule() !== 'SET NULL') {
    echo "  note: correcting the documents.case_id foreign key rule to ON DELETE SET NULL\n";
    $pdo->exec("ALTER TABLE documents DROP FOREIGN KEY `$baselineFk`");
}
if ($fkRule() === null) {
    echo "  note: restoring the documents.case_id foreign key to the migration baseline\n";
    $idx = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="documents" AND INDEX_NAME=?');
    $idx->execute([$baselineIdx]);
    if ((int)$idx->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE documents ADD INDEX `$baselineIdx` (case_id)");
    }
    $pdo->exec("ALTER TABLE documents ADD CONSTRAINT `$baselineFk` FOREIGN KEY (case_id) REFERENCES cases (id) ON DELETE SET NULL");
}
check('the documents.case_id foreign key baseline is present with its migration rule', $fkRule() === 'SET NULL');
foreach ($targets as [$table, $column]) {
    check("$table.$column exists before the test", $hasColumn($table, $column));
}

echo "\n== 1. removing a column, then serving requests, does not re-add it ==\n";
$removed = [];
try {
    foreach ($targets as [$table, $column, $definition]) {
        $meta = $capture($table, $column);
        $dropColumn($table, $column, $meta);
        $removed[] = [$table, $column, $definition, $meta];
        check("$table.$column was dropped for the test", !$hasColumn($table, $column));
    }

    // Drive the routes that used to self-heal: the site root (config.php) and the API
    // (bootstrap.php). Neither may touch the schema.
    $hit('/');
    $hit('/api/v1/plans.php');
    $hit('/api/v1/lawyers.php');
    $hit('/admin/login.php');

    foreach ($targets as [$table, $column]) {
        check("serving a request did NOT re-add $table.$column", !$hasColumn($table, $column));
    }
} finally {
    echo "\n== restore ==\n";
    foreach ($removed as [$table, $column, $definition, $meta]) {
        if (!$hasColumn($table, $column)) {
            $restoreColumn($table, $column, $definition, $meta);
        }
    }
    foreach ($targets as [$table, $column]) {
        check("$table.$column was restored", $hasColumn($table, $column));
    }
    // The foreign key must survive the round trip with its migration rule, or a later
    // suite would see a schema that no longer matches the migration files.
    $fkBack = $capture('documents', 'case_id')['fk'];
    check('the documents.case_id foreign key was restored', count($fkBack) === 1);
    check('the restored foreign key keeps ON DELETE SET NULL', ($fkBack[0]['DELETE_RULE'] ?? '') === 'SET NULL');
}

echo "\n== 2. the opt-in escape hatch still repairs when explicitly enabled ==\n";
// The guard itself must remain functional for an operator who opts in; only the default
// changed. Exercised directly rather than through a request, since enabling the env var
// would require restarting the web server.
require_once __DIR__ . '/../includes/schema_guard.php';
$caseMeta = $capture('documents', 'case_id');
$dropColumn('documents', 'case_id', $caseMeta);
check('documents.case_id is absent again', !$hasColumn('documents', 'case_id'));
$ok = schema_ensure_column($pdo, 'documents', 'case_id', 'INT UNSIGNED DEFAULT NULL');
check('schema_ensure_column reports success when allowed', $ok === true);
check('the column is present again after an explicit repair', $hasColumn('documents', 'case_id'));
$restoreColumn('documents', 'case_id', 'INT UNSIGNED DEFAULT NULL', $caseMeta);
check('the documents.case_id foreign key is intact after the escape-hatch test', count($capture('documents', 'case_id')['fk']) === 1);

echo "\n== 3. the guard refuses a non-identifier rather than interpolating it ==\n";
check('a table name with a quote is refused', schema_ensure_column($pdo, 'documents; DROP TABLE users', 'x', 'INT') === false);
check('a column name with a space is refused', schema_ensure_column($pdo, 'documents', 'a b', 'INT') === false);
check('the users table still exists', $hasColumn('users', 'email'));

echo "\n== 4. an idempotent call does not error on an existing column ==\n";
check('ensuring an existing column returns true', schema_ensure_column($pdo, 'documents', 'case_id', 'INT UNSIGNED DEFAULT NULL') === true);

echo "\n  Execution 29 runtime schema: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
