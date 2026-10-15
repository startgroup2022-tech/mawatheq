<?php
/**
 * Execution 29 — database repair preflight.
 *
 * Read-only. Run this on the server BEFORE any repair file, so you learn whether the
 * repair can succeed while the site is still untouched.
 *
 *   php database/repair/00_preflight_check.php
 *
 * It answers the three questions that decide whether a repair will work:
 *   1. Does the configured database user hold ALTER? Without it the column repairs
 *      degrade to "unavailable" and reset stays broken — silently, unless you look.
 *   2. Which critical columns are actually missing right now?
 *   3. Which upgrade files are recorded as applied, and do their checksums still match?
 *
 * Exits 0 when nothing needs repairing, 1 when a repair is required.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';

$problems = 0;
$line = static fn(string $label, string $value): string => sprintf("  %-46s %s\n", $label, $value);

echo "\n=== مواثيق — فحص قاعدة البيانات قبل الإصلاح ===\n";
echo "=== Mawatheq — database preflight ===\n\n";

echo "الاتصال / connection\n";
try {
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    echo $line('database', $db !== '' ? $db : '(none selected)');
    echo $line('server', (string)$pdo->query('SELECT VERSION()')->fetchColumn());
} catch (Throwable $e) {
    echo $line('connection', 'FAILED: ' . $e->getMessage());
    exit(1);
}

// ---- 1. Privileges ---------------------------------------------------------
echo "\nالصلاحيات / privileges\n";
$canAlter = false;
try {
    $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    $all = strtoupper(implode(' ', $grants));
    $canAlter = str_contains($all, 'ALL PRIVILEGES') || str_contains($all, 'ALTER');
    echo $line('ALTER privilege', $canAlter ? 'yes' : 'NO — repairs cannot add columns');
} catch (Throwable $e) {
    echo $line('ALTER privilege', 'could not read SHOW GRANTS: ' . $e->getMessage());
}
if (!$canAlter) {
    $problems++;
    echo "  ! بدون صلاحية ALTER لن يستطيع الإصلاح إضافة الأعمدة. اطلب من مزوّد الاستضافة منحها.\n";
    echo "  ! Without ALTER the repair cannot add the columns. Ask the host to grant it.\n";
}

// ---- 2. Critical columns ---------------------------------------------------
echo "\nالأعمدة الحرجة / critical columns\n";
$critical = [
    'users'      => ['reset_token', 'reset_expires', 'email_verify_token_hash', 'email_verify_expires', 'session_invalidated_at'],
    'lawyers'    => ['reset_token', 'reset_expires', 'email_verify_token_hash', 'email_verify_expires', 'session_invalidated_at'],
    'admins'     => ['reset_token', 'reset_expires', 'session_invalidated_at'],
    'api_tokens' => ['revoked_at'],
];
$missing = [];
foreach ($critical as $table => $columns) {
    try {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        $present = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$present) {
            $problems++;
            echo $line($table, 'TABLE MISSING');
            continue;
        }
        $absent = array_values(array_diff(array_map('strtolower', $columns), $present));
        if ($absent) {
            $problems += count($absent);
            $missing[$table] = $absent;
            echo $line($table, 'missing: ' . implode(', ', $absent));
        } else {
            echo $line($table, 'ok');
        }
    } catch (Throwable $e) {
        echo $line($table, 'check failed: ' . $e->getMessage());
    }
}

// ---- 3. Migration ledger ---------------------------------------------------
echo "\nسجل الترحيلات / migration ledger\n";
$hasLedger = false;
try {
    $hasLedger = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'")->fetchColumn();
} catch (Throwable $e) {}
if (!$hasLedger) {
    echo $line('schema_migrations', 'absent — run database/upgrade_v53.sql first');
    $problems++;
} else {
    $files = glob($root . '/database/upgrade_v*.sql') ?: [];
    usort($files, static function (string $a, string $b): int {
        preg_match('/upgrade_v(\d+)\.sql$/', $a, $ma);
        preg_match('/upgrade_v(\d+)\.sql$/', $b, $mb);
        return (int)($ma[1] ?? 0) <=> (int)($mb[1] ?? 0);
    });
    $recorded = [];
    foreach ($pdo->query('SELECT filename, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recorded[$row['filename']] = $row['checksum'];
    }
    $unrecorded = 0;
    $drifted = 0;
    foreach ($files as $file) {
        $name = basename($file);
        $sum = hash_file('sha256', $file);
        if (!isset($recorded[$name])) {
            $unrecorded++;
        } elseif (!hash_equals($recorded[$name], (string)$sum)) {
            $drifted++;
            echo $line($name, 'checksum DRIFTED since it was recorded');
        }
    }
    echo $line('upgrade files on disk', (string)count($files));
    echo $line('recorded as applied', (string)count($recorded));
    echo $line('not recorded', (string)$unrecorded);
    echo $line('checksum drift', (string)$drifted);
    if ($unrecorded > 0) {
        echo "  ! ملفات لم تُسجّل كمنفَّذة — قد تكون مطبَّقة جزئياً.\n";
        echo "  ! Files not recorded may be partially applied. Run database/repair/02_migration_ledger.php.\n";
    }
    if ($drifted > 0) {
        $problems++;
    }
}

echo "\n------------------------------------------------------------\n";
if ($problems === 0) {
    echo "النتيجة: لا حاجة لإصلاح. / Nothing to repair.\n\n";
    exit(0);
}
echo "النتيجة: $problems مشكلة تحتاج إصلاحاً. شغّل database/repair/01_repair_reset_columns.sql\n";
echo "Result: $problems issue(s). Run database/repair/01_repair_reset_columns.sql\n\n";
exit(1);
