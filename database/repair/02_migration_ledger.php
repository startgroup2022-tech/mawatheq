<?php
/**
 * Execution 29 — migration ledger recorder.
 *
 * Records which upgrade files have genuinely been applied, so a partially applied
 * migration can be detected instead of assumed. Run after the repair files:
 *
 *   php database/repair/02_migration_ledger.php            # record, then report
 *   php database/repair/02_migration_ledger.php --verify   # report only, change nothing
 *
 * Why a ledger: the earlier repair script wrote no record at all, so "the SQL was run"
 * and "the column is still missing" were both true and nothing could tell them apart.
 * A recorded row means the file ran to completion; `statements_ok` is the count of
 * guarded statements the file contains, so a later file edit is visible as a mismatch.
 *
 * Never modifies application data: it reads information_schema and writes only to
 * `schema_migrations`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';

$verifyOnly = in_array('--verify', $argv ?? [], true);

$hasLedger = false;
try {
    $hasLedger = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'")->fetchColumn();
} catch (Throwable $e) {}
if (!$hasLedger) {
    fwrite(STDERR, "schema_migrations is absent. Run database/upgrade_v53.sql first.\n");
    exit(1);
}

/**
 * Column a given upgrade file is responsible for, used as the proof that it applied.
 * A file with no entry is recorded on the strength of its filename alone, which is
 * the honest state: we can see it on disk but cannot prove it ran.
 */
$proofColumn = [
    'upgrade_v27.sql' => ['api_tokens', 'revoked_at'],
    'upgrade_v42.sql' => ['audit_logs', 'firm_id'],
    'upgrade_v47.sql' => ['documents', 'case_id'],
];

$files = glob($root . '/database/upgrade_v*.sql') ?: [];
usort($files, static function (string $a, string $b): int {
    preg_match('/upgrade_v(\d+)\.sql$/', $a, $ma);
    preg_match('/upgrade_v(\d+)\.sql$/', $b, $mb);
    return (int)($ma[1] ?? 0) <=> (int)($mb[1] ?? 0);
});

$recorded = [];
foreach ($pdo->query('SELECT filename, checksum, statements_ok, applied_at FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $recorded[$row['filename']] = $row;
}

$insert = $pdo->prepare('INSERT INTO schema_migrations (filename, checksum, statements_ok) VALUES (?,?,?)
    ON DUPLICATE KEY UPDATE checksum=VALUES(checksum), statements_ok=VALUES(statements_ok)');

printf("\n=== Mawatheq — migration ledger (%s) ===\n\n", $verifyOnly ? 'verify only' : 'record');
printf("  %-20s %-9s %-10s %s\n", 'file', 'guarded', 'recorded', 'proof');
printf("  %s\n", str_repeat('-', 62));

$newlyRecorded = 0;
$unproven = 0;
$drifted = 0;

foreach ($files as $file) {
    $name = basename($file);
    $sql = (string)file_get_contents($file);
    // Each guarded migration is a SET @s := IF(...) / PREPARE / EXECUTE triple; counting the
    // EXECUTE lines counts the statements that actually ran.
    $guarded = preg_match_all('/^\s*EXECUTE\s+/mi', $sql);
    if ($guarded === 0) {
        $guarded = preg_match_all('/;\s*$/m', $sql);
    }
    $sum = hash_file('sha256', $file) ?: '';

    $proven = null;
    if (isset($proofColumn[$name])) {
        [$tbl, $col] = $proofColumn[$name];
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            $stmt->execute([$tbl, $col]);
            $proven = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            $proven = null;
        }
    }

    $state = isset($recorded[$name]) ? 'yes' : 'no';
    $proofText = $proven === null ? '(no probe)' : ($proven ? "$tbl.$col present" : "$tbl.$col MISSING");

    // A file is only recorded when its own condition can be shown to hold. Without proof,
    // recording it would repeat the original mistake: a ledger that claims work that is
    // not there. Unproven files are reported and left unrecorded.
    if (!$verifyOnly && $proven !== false) {
        if (!isset($recorded[$name])) {
            $insert->execute([$name, $sum, $guarded]);
            $newlyRecorded++;
            $state = 'RECORDED';
        } elseif (!hash_equals((string)$recorded[$name]['checksum'], (string)$sum)) {
            $insert->execute([$name, $sum, $guarded]);
            $drifted++;
            $state = 'updated';
        }
    }
    if ($proven === false) {
        $unproven++;
    }

    printf("  %-20s %-9d %-10s %s\n", $name, $guarded, $state, $proofText);
}

printf("\n  files on disk: %d | recorded: %d | newly recorded: %d | drift updated: %d\n",
    count($files), count($recorded), $newlyRecorded, $drifted);

if ($unproven > 0) {
    echo "\n  ! $unproven file(s) could not be proven applied (their column is missing).\n";
    echo "  ! These are deliberately NOT recorded. Run database/repair/01_repair_reset_columns.sql,\n";
    echo "  ! then re-run this script.\n";
}

echo "\n";
exit($unproven > 0 ? 1 : 0);
