<?php
/**
 * Execution 29.1 — database preflight and postflight.
 *
 * Read-only. Run this on the server BEFORE any repair file, so you learn whether the
 * repair can succeed while the site is still untouched:
 *
 *   php database/repair/00_preflight_check.php              # ready to repair?
 *   php database/repair/00_preflight_check.php --postflight # did the migration land?
 *   php database/repair/00_preflight_check.php --json       # machine-readable
 *   php database/repair/00_preflight_check.php --database=name
 *
 * It answers the questions that decide whether a deployment will work:
 *   1. Does the configured database user hold the privileges the repair needs?
 *   2. Which critical columns are actually missing right now?
 *   3. Which upgrade files are recorded as applied, and is that record still true?
 *   4. Which migrations are only partially applied?
 *
 * Exit codes (CLI):
 *   0  no blockers — warnings may still be present
 *   1  blockers present — do not proceed
 *   2  the check could not run at all (no connection, no ledger)
 *
 * Blockers versus warnings is a deliberate distinction. A missing `ALTER` grant or a
 * missing critical column stops the deployment: proceeding would leave the reset flow
 * broken while looking applied. An unrecorded but verified migration is a warning: the
 * schema is right, only the bookkeeping is behind, and `02_migration_ledger.php` fixes
 * that without any schema change.
 *
 * Auto-upgrade is covered here as well: the per-column ALTER guards mean an upgrade file
 * can be re-run safely on a database that already has some of its columns, so a
 * partially applied migration is repaired by re-running the file, not by hand-editing
 * the schema. Section 4 reports exactly which objects are missing so the re-run is
 * verifiable rather than hopeful.
 *
 * Never modifies production data: it reads information_schema and, in preflight, the
 * ledger table. No DDL, no DML, no writes of any kind.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';
require_once __DIR__ . '/migration_verify.php';

$postflight = in_array('--postflight', $argv ?? [], true);
$asJson = in_array('--json', $argv ?? [], true);

$dbOverride = null;
foreach (($argv ?? []) as $arg) {
    if (str_starts_with($arg, '--database=')) {
        $dbOverride = substr($arg, strlen('--database='));
    }
}

/** @var array<int,array{level:string,message:string}> */
$issues = [];
$blockers = 0;
$warnings = 0;

function issue(string $level, string $message): void
{
    global $issues, $blockers, $warnings;
    $issues[] = ['level' => $level, 'message' => $message];
    if ($level === 'blocker') {
        $blockers++;
    } else {
        $warnings++;
    }
}

/** Emit a line in human mode; suppressed in JSON mode so the document stays clean. */
function say(string $s, bool $json): void
{
    if (!$json) {
        echo $s;
    }
}

$line = static fn(string $label, string $value): string => sprintf("  %-46s %s\n", $label, $value);

say("\n=== مواثيق — فحص قاعدة البيانات " . ($postflight ? 'بعد الترقية' : 'قبل الإصلاح') . " ===\n", $asJson);
say("=== Mawatheq — database " . ($postflight ? 'postflight' : 'preflight') . " ===\n\n", $asJson);

// ---- Connection -------------------------------------------------------------
say("الاتصال / connection\n", $asJson);
$report = [
    'mode' => $postflight ? 'postflight' : 'preflight',
    'database' => null,
    'server' => null,
    'privileges' => [],
    'critical_columns' => [],
    'ledger' => [],
    'migrations' => [],
    'issues' => [],
    'counts' => ['blockers' => 0, 'warnings' => 0],
];
try {
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
    $report['database'] = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $report['server'] = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    say($line('database', $report['database'] !== '' ? $report['database'] : '(none selected)'), $asJson);
    say($line('server', $report['server']), $asJson);
} catch (Throwable $e) {
    say($line('connection', 'FAILED: ' . $e->getMessage()), $asJson);
    exit(2);
}

$db = (string)$report['database'];

// ---- 1. Privileges ----------------------------------------------------------
say("\nالصلاحيات / privileges\n", $asJson);
// ALTER is what the repair cannot work without. REFERENCES and INDEX are needed for the
// foreign keys and indexes upgrade_v42 adds; INFO_SCHEMA read is needed for every check
// here to mean anything.
$needed = ['ALTER', 'INDEX', 'REFERENCES', 'CREATE'];
$granted = [];
$grantsReadable = true;
try {
    $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    $flat = strtoupper(implode(' ', $grants));
    foreach ($needed as $priv) {
        $granted[$priv] = str_contains($flat, 'ALL PRIVILEGES') || str_contains($flat, $priv);
    }
} catch (Throwable $e) {
    $grantsReadable = false;
    say($line('SHOW GRANTS', 'could not read: ' . $e->getMessage()), $asJson);
    issue('warning', 'Could not read SHOW GRANTS; privilege checks are inconclusive.');
}
$report['privileges'] = $granted;

foreach ($needed as $priv) {
    if (!$grantsReadable) {
        continue;
    }
    say($line($priv . ' privilege', $granted[$priv] ? 'yes' : 'NO'), $asJson);
}
if ($grantsReadable && empty($granted['ALTER'])) {
    issue('blocker', 'Without ALTER the repair cannot add the columns. '
        . 'بدون صلاحية ALTER لن يستطيع الإصلاح إضافة الأعمدة. اطلب من مزوّد الاستضافة منحها.');
}
if ($grantsReadable && (empty($granted['INDEX']) || empty($granted['REFERENCES']))) {
    issue('warning', 'INDEX/REFERENCES not both granted: upgrade_v42 may not be able to '
        . 'create every index and foreign key.');
}

// ---- 2. Critical columns ----------------------------------------------------
say("\nالأعمدة الحرجة / critical columns\n", $asJson);
$critical = [
    'users'      => ['reset_token', 'reset_expires', 'email_verify_token_hash', 'email_verify_expires', 'session_invalidated_at'],
    'lawyers'    => ['reset_token', 'reset_expires', 'email_verify_token_hash', 'email_verify_expires', 'session_invalidated_at'],
    'admins'     => ['reset_token', 'reset_expires', 'session_invalidated_at'],
    'api_tokens' => ['revoked_at'],
];
$missingTotal = 0;
foreach ($critical as $table => $columns) {
    try {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
        $stmt->execute([$db, $table]);
        $present = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$present) {
            say($line($table, 'TABLE MISSING'), $asJson);
            $report['critical_columns'][$table] = ['table_missing' => true, 'missing' => $columns];
            $missingTotal += count($columns);
            issue('blocker', "Table `$table` is missing entirely.");
            continue;
        }
        $absent = array_values(array_diff(array_map('strtolower', $columns), $present));
        $report['critical_columns'][$table] = ['table_missing' => false, 'missing' => $absent];
        if ($absent) {
            $missingTotal += count($absent);
            say($line($table, 'missing: ' . implode(', ', $absent)), $asJson);
            issue('blocker', "$table is missing critical column(s): " . implode(', ', $absent)
                . '. Run database/repair/01_repair_reset_columns.sql.');
        } else {
            say($line($table, 'ok'), $asJson);
        }
    } catch (Throwable $e) {
        say($line($table, 'check failed: ' . $e->getMessage()), $asJson);
        issue('warning', "Could not inspect `$table`: " . $e->getMessage());
    }
}
$report['counts']['critical_columns_missing'] = $missingTotal;

// ---- 3. Migration ledger ----------------------------------------------------
say("\nسجل الترحيلات / migration ledger\n", $asJson);
$hasLedgerStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME='schema_migrations'");
$hasLedgerStmt->execute([$db]);
$hasLedger = (bool)$hasLedgerStmt->fetchColumn();
$report['ledger']['present'] = $hasLedger;

if (!$hasLedger) {
    say($line('schema_migrations', 'absent — run database/upgrade_v55.sql first'), $asJson);
    issue('blocker', 'schema_migrations is absent; the ledger cannot record or verify anything. '
        . 'Run database/upgrade_v55.sql.');
} else {
    // Which extended columns exist, so a v53-era ledger is reported rather than assumed.
    $cols = [];
    $cs = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='schema_migrations'");
    $cs->execute([$db]);
    foreach ($cs->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $cols[strtolower($c)] = true;
    }
    $extended = isset($cols['state'], $cols['requirements_total'], $cols['current_checksum']);
    $report['ledger']['extended'] = $extended;
    if (!$extended) {
        say($line('ledger format', 'pre-29.1 — run database/upgrade_v55.sql'), $asJson);
        issue('warning', 'The ledger predates Execution 29.1 and cannot report partial migrations. '
            . 'Run database/upgrade_v55.sql (additive).');
    }

    $recorded = [];
    try {
        foreach ($pdo->query('SELECT filename, checksum, current_checksum FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recorded[$row['filename']] = $row;
        }
    } catch (Throwable $e) {
        issue('warning', 'Could not read the ledger: ' . $e->getMessage());
    }
}

// ---- 4. Migration completeness ---------------------------------------------
//   Every upgrade file is measured against the live schema. This is what turns
//   "the SQL was run" into "the schema it declares is actually present".
say("\nاكتمال الترحيلات / migration completeness\n", $asJson);
$files = glob($root . '/database/upgrade_v*.sql') ?: [];
usort($files, static function (string $a, string $b): int {
    preg_match('/upgrade_v(\d+)\.sql$/', $a, $ma);
    preg_match('/upgrade_v(\d+)\.sql$/', $b, $mb);
    return (int)($ma[1] ?? 0) <=> (int)($mb[1] ?? 0);
});

$states = ['verified' => 0, 'partial' => 0, 'missing' => 0, 'unknown' => 0];
$incomplete = [];
foreach ($files as $file) {
    $name = basename($file);
    $req = mawatheq_migration_requirements((string)file_get_contents($file));
    $res = mawatheq_verify_requirements($pdo, $req, $db);
    $states[$res['state']]++;
    $drifted = false;
    if (isset($recorded[$name]) && !empty($recorded[$name]['current_checksum'])) {
        $disk = hash_file('sha256', $file) ?: '';
        $drifted = !hash_equals((string)$recorded[$name]['current_checksum'], $disk);
    }
    $entry = [
        'filename' => $name,
        'state' => $res['state'],
        'present' => $res['present'],
        'total' => $res['total'],
        'missing' => $res['missing'],
        'recorded' => isset($recorded[$name]),
        'checksum_drift' => $drifted,
    ];
    $report['migrations'][] = $entry;

    if ($res['state'] === 'partial' || $res['state'] === 'missing') {
        $incomplete[$name] = $entry;
    }
    if ($drifted) {
        issue('warning', "$name changed on disk after it was recorded. "
            . 'The recorded checksum was preserved; review the edit before re-running it.');
    }
}

$report['counts'] += $states;
say(sprintf("  %-20s %-8s %s\n", 'file', 'state', 'objects present'), $asJson);
say('  ' . str_repeat('-', 60) . "\n", $asJson);
foreach ($report['migrations'] as $m) {
    if ($m['state'] === 'verified') {
        continue;
    }
    say(sprintf("  %-20s %-8s %d/%d\n", $m['filename'], mawatheq_migration_state_label($m['state']),
        $m['present'], $m['total']), $asJson);
}
say(sprintf("\n  verified: %d | partial: %d | missing: %d | unknown: %d\n",
    $states['verified'], $states['partial'], $states['missing'], $states['unknown']), $asJson);

if ($states['partial'] > 0) {
    $names = implode(', ', array_keys(array_filter($incomplete, fn($e) => $e['state'] === 'partial')));
    issue('blocker', "{$states['partial']} migration(s) partially applied: $names. "
        . 'Re-run the file — the guards complete what is missing — or run '
        . 'database/repair/01_repair_reset_columns.sql for the critical columns.');
}
if ($states['missing'] > 0) {
    $names = implode(', ', array_keys(array_filter($incomplete, fn($e) => $e['state'] === 'missing')));
    issue('blocker', "{$states['missing']} migration(s) not applied at all: $names. "
        . 'Run them in numeric order.');
}

// Later migrations depend on earlier ones; a partial file makes everything above it
// suspect, which is worth stating plainly rather than leaving to inference.
if ($incomplete) {
    issue('warning', 'Later migrations were verified against a schema that an earlier '
        . 'incomplete migration may not fully match. Repair the incomplete files and re-run '
        . 'database/repair/02_migration_ledger.php.');
}

// ---- Verdict ----------------------------------------------------------------
$report['issues'] = $issues;
$report['counts']['blockers'] = $blockers;
$report['counts']['warnings'] = $warnings;

say("\n------------------------------------------------------------\n", $asJson);

if ($postflight) {
    // Postflight is the gate before the application is deployed over this schema.
    if ($blockers === 0) {
        say("النتيجة: الترقية مكتملة. / Result: migration complete — safe to deploy the application.\n\n", $asJson);
    } else {
        say("النتيجة: الترقية غير مكتملة — لا تنشر الموقع بعد.\n", $asJson);
        say("Result: migration INCOMPLETE — do not deploy the application yet.\n\n", $asJson);
    }
} else {
    if ($blockers === 0 && $warnings === 0) {
        say("النتيجة: لا حاجة لإصلاح. / Result: nothing to repair.\n\n", $asJson);
    } elseif ($blockers === 0) {
        say("النتيجة: لا عوائق، مع $warnings تنبيه.\n", $asJson);
        say("Result: no blockers, $warnings warning(s).\n\n", $asJson);
    } else {
        say("النتيجة: $blockers عائق و$warnings تنبيه — لا تكمل قبل الإصلاح.\n", $asJson);
        say("Result: $blockers blocker(s), $warnings warning(s) — resolve before proceeding.\n\n", $asJson);
    }
}

if ($warnings > 0) {
    say("التنبيهات / warnings:\n", $asJson);
    foreach ($issues as $i) {
        if ($i['level'] === 'warning') {
            say("  ~ " . $i['message'] . "\n", $asJson);
        }
    }
    say("\n", $asJson);
}
if ($blockers > 0) {
    say("العوائق / blockers:\n", $asJson);
    foreach ($issues as $i) {
        if ($i['level'] === 'blocker') {
            say("  ! " . $i['message'] . "\n", $asJson);
        }
    }
    say("\n", $asJson);
}

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

exit($blockers > 0 ? 1 : 0);
