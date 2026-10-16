<?php
/**
 * Execution 29.1 — migration ledger recorder and verifier.
 *
 * Records which upgrade files have genuinely been applied, and how completely, so a
 * partially applied migration is detected rather than assumed. Run after the repair files:
 *
 *   php database/repair/02_migration_ledger.php              # verify + record, then report
 *   php database/repair/02_migration_ledger.php --verify     # report only, change nothing
 *   php database/repair/02_migration_ledger.php --json       # machine-readable report
 *
 * What changed from the v53 script, and why
 * -----------------------------------------
 * The v53 script proved a migration applied by looking up a single "proof column" named in
 * a hand-written map, and recorded the file on that basis. One column is not a migration:
 * `upgrade_v42.sql` adds 21 columns, 9 indexes and 9 foreign keys, so a database with only
 * `audit_logs.firm_id` present satisfied the old probe while 38 other objects were absent,
 * and v42 was recorded as applied. That is the false-positive completion this rewrite
 * removes.
 *
 * The ledger now:
 *   - derives every requirement from the SQL file itself (see migration_verify.php), so
 *     the manifest cannot drift from the file;
 *   - records a file only when every requirement is present (`verified`), and never on the
 *     strength of its filename existing;
 *   - distinguishes verified / partial / missing / unknown, and reports partial explicitly,
 *     because that is precisely the production state the old script mislabelled;
 *   - never overwrites a historical checksum. The hash recorded at verification time stays;
 *     a later change to the file is recorded beside it as drift;
 *   - exits non-zero when any migration is not proven complete, so a caller cannot mistake
 *     a partial application for success.
 *
 * Modifies no application data: it reads information_schema and writes only to
 * `schema_migrations`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';
require_once __DIR__ . '/migration_verify.php';

$verifyOnly = in_array('--verify', $argv ?? [], true);
$asJson = in_array('--json', $argv ?? [], true);

/**
 * Optional `--database=<name>`: verify a database other than the one config.php selects,
 * without editing config/secrets.php. The name is validated against information_schema and
 * the connection switches with USE, so DATABASE() — which every check below relies on —
 * resolves to the requested database. Absent the flag, behaviour is unchanged.
 */
$dbOverride = null;
foreach (($argv ?? []) as $arg) {
    if (str_starts_with($arg, '--database=')) {
        $dbOverride = substr($arg, strlen('--database='));
    }
}
if ($dbOverride !== null) {
    if (!preg_match('/^\w+$/', $dbOverride)) {
        fwrite(STDERR, "--database must be a plain identifier.\n");
        exit(2);
    }
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
    $exists->execute([$dbOverride]);
    if (!(int)$exists->fetchColumn()) {
        fwrite(STDERR, "Database '$dbOverride' does not exist.\n");
        exit(2);
    }
    $pdo->exec('USE `' . $dbOverride . '`');
}

/** Collect output so --json can emit one clean document instead of interleaved prose. */
$report = [
    'database' => null,
    'ledger_present' => false,
    'files' => [],
    'summary' => ['total' => 0, 'verified' => 0, 'partial' => 0, 'missing' => 0, 'unknown' => 0],
    'actions' => ['newly_recorded' => 0, 'drift_noted' => 0, 'unchanged' => 0],
    'blocking' => [],
];

function out(string $s, bool $json): void
{
    if (!$json) {
        echo $s;
    }
}

try {
    $report['database'] = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot reach the database: " . $e->getMessage() . "\n");
    exit(2);
}

// ---- Ledger table presence and columns --------------------------------------
$report['ledger_present'] = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'")->fetchColumn();

if (!$report['ledger_present']) {
    fwrite(STDERR, "schema_migrations is absent. Run database/upgrade_v55.sql first.\n");
    exit(1);
}

/** Columns the extended ledger needs, so a database still on v53 is detected rather than
 *  silently written to with a column that does not exist. */
$requiredLedgerColumns = ['state', 'requirements_total', 'requirements_present', 'current_checksum'];
$ledgerColumns = [];
foreach ($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'")->fetchAll(PDO::FETCH_COLUMN) as $c) {
    $ledgerColumns[strtolower($c)] = true;
}
$missingLedger = array_values(array_filter($requiredLedgerColumns, fn($c) => !isset($ledgerColumns[$c])));
if ($missingLedger) {
    fwrite(STDERR, "schema_migrations is missing: " . implode(', ', $missingLedger) . "\n");
    fwrite(STDERR, "Run database/upgrade_v55.sql (it is idempotent and additive).\n");
    exit(1);
}

// ---- Load files and existing rows -------------------------------------------
$files = glob($root . '/database/upgrade_v*.sql') ?: [];
usort($files, static function (string $a, string $b): int {
    preg_match('/upgrade_v(\d+)\.sql$/', $a, $ma);
    preg_match('/upgrade_v(\d+)\.sql$/', $b, $mb);
    return (int)($ma[1] ?? 0) <=> (int)($mb[1] ?? 0);
});

$recorded = [];
foreach ($pdo->query('SELECT filename, checksum, state, requirements_total, requirements_present, current_checksum, applied_at
        FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $recorded[$row['filename']] = $row;
}

/**
 * Record a verified migration.
 *
 * `checksum` is written on first verification and never touched again — a later edit to the
 * file must not erase what was actually applied. The file's current hash goes to
 * `current_checksum` instead, which is what makes drift visible rather than silent.
 */
$insertNew = $pdo->prepare('INSERT INTO schema_migrations
    (filename, checksum, statements_ok, state, requirements_total, requirements_present, current_checksum, verified_at, checked_at)
    VALUES (?,?,?,?,?,?,?,NOW(),NOW())');
$updateVerified = $pdo->prepare('UPDATE schema_migrations
    SET state=?, requirements_total=?, requirements_present=?, current_checksum=?, verified_at=COALESCE(verified_at, NOW()), checked_at=NOW()
    WHERE filename=?');
$updateIncomplete = $pdo->prepare('UPDATE schema_migrations
    SET state=?, requirements_total=?, requirements_present=?, current_checksum=?, checked_at=NOW(),
        partial_seen_at=COALESCE(partial_seen_at, NOW())
    WHERE filename=?');

out(sprintf("\n=== Mawatheq — migration ledger (%s) ===\n\n", $verifyOnly ? 'verify only' : 'record'), $asJson);
out(sprintf("  database: %s\n\n", $report['database']), $asJson);
out(sprintf("  %-20s %-9s %-10s %-10s %s\n", 'file', 'objects', 'state', 'recorded', 'evidence'), $asJson);
out('  ' . str_repeat('-', 78) . "\n", $asJson);

foreach ($files as $file) {
    $name = basename($file);
    $sql = (string)file_get_contents($file);
    $req = mawatheq_migration_requirements($sql);
    $res = mawatheq_verify_requirements($pdo, $req, (string)$report['database']);
    $diskSum = hash_file('sha256', $file) ?: '';

    $report['summary']['total']++;
    $report['summary'][$res['state']]++;

    $wasRecorded = isset($recorded[$name]);
    $drift = false;
    if ($wasRecorded && $recorded[$name]['current_checksum'] !== null
        && !hash_equals((string)$recorded[$name]['current_checksum'], $diskSum)) {
        $drift = true;
    }

    // Record only what can be proven. `unknown` is not recorded either: a file whose
    // requirements cannot be observed is a file we cannot vouch for, and writing it would
    // repeat the original mistake in a quieter form.
    $action = 'none';
    if (!$verifyOnly) {
        if ($res['state'] === 'verified') {
            if (!$wasRecorded) {
                $insertNew->execute([$name, $diskSum, $req->guarded, 'verified', $res['total'], $res['present'], $diskSum]);
                $report['actions']['newly_recorded']++;
                $action = 'RECORDED';
            } else {
                $updateVerified->execute(['verified', $res['total'], $res['present'], $diskSum, $name]);
                if ($drift) {
                    $report['actions']['drift_noted']++;
                    $action = 'drift';
                } else {
                    $report['actions']['unchanged']++;
                    $action = 'ok';
                }
            }
        } elseif ($wasRecorded) {
            // A migration that was verified before is no longer complete. That is a
            // regression and must be visible without deleting the original record.
            $updateIncomplete->execute([$res['state'], $res['total'], $res['present'], $diskSum, $name]);
        }
    }

    $evidence = $res['total'] === 0
        ? '(no observable requirement)'
        : sprintf('%d/%d objects', $res['present'], $res['total']);
    if ($res['missing']) {
        $first = $res['missing'][0];
        $evidence .= sprintf(' first absent: %s.%s (%s)', $first['table'], $first['name'], $first['kind']);
    }
    if ($drift) {
        $evidence .= ' [DRIFT]';
    }

    out(sprintf("  %-20s %-9d %-10s %-10s %s\n", $name, $res['total'],
        mawatheq_migration_state_label($res['state']),
        $wasRecorded ? ($action === 'RECORDED' ? 'no->yes' : 'yes') : 'no',
        $evidence), $asJson);

    $report['files'][] = [
        'filename' => $name,
        'state' => $res['state'],
        'requirements_total' => $res['total'],
        'requirements_present' => $res['present'],
        'missing' => $res['missing'],
        'drift' => $drift,
        'recorded' => $wasRecorded,
        'action' => $action,
    ];
}

$s = $report['summary'];
out(sprintf("\n  files on disk: %d | verified: %d | partial: %d | missing: %d | unknown: %d\n",
    $s['total'], $s['verified'], $s['partial'], $s['missing'], $s['unknown']), $asJson);
out(sprintf("  newly recorded: %d | drift noted: %d | already current: %d\n",
    $report['actions']['newly_recorded'], $report['actions']['drift_noted'], $report['actions']['unchanged']), $asJson);

// Blocking conditions. A partial or missing migration means the schema is not what the
// application expects; unknown means we could not tell. All three are non-zero exits so a
// deployment script stops rather than proceeding on a guess.
if ($s['partial'] > 0) {
    $report['blocking'][] = "{$s['partial']} migration(s) partially applied";
}
if ($s['missing'] > 0) {
    $report['blocking'][] = "{$s['missing']} migration(s) not applied";
}
if ($s['unknown'] > 0) {
    $report['blocking'][] = "{$s['unknown']} migration(s) could not be verified";
}

if ($report['blocking']) {
    foreach ($report['blocking'] as $b) {
        out("\n  ! $b\n", $asJson);
    }
    out("  ! Incomplete migrations are NOT recorded as applied. Repair the schema and re-run.\n", $asJson);
    out("  ! See database/repair/00_preflight_check.php for the missing objects.\n", $asJson);
}

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "\n";
}

exit($report['blocking'] ? 1 : 0);

