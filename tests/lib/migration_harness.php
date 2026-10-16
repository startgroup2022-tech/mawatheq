<?php
/**
 * Execution 29.1 — shared harness for the migration-integrity regression tests.
 *
 * Provides the scratch-database plumbing the migration tests need: build a database from
 * the real schema, apply upgrade files in numeric order, build deliberately drifted
 * variants, and run the CLI scripts under test while capturing their exit code.
 *
 * Every helper works on a scratch database it created and dropped itself. Nothing here
 * touches config/secrets.php's configured database, and no helper writes to a database it
 * did not create.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/database/repair/migration_verify.php';

/**
 * Admin connection with no schema selected, so a scratch database can be created.
 * Connects to the server config.php points at — never to a different host.
 */
function e291_admin(): PDO
{
    return new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function e291_root(): string
{
    return dirname(__DIR__, 2);
}

/** Create a scratch database from the real schema.sql. */
function e291_build_fresh(PDO $admin, string $db): void
{
    $admin->exec("DROP DATABASE IF EXISTS `$db`");
    $admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $admin->exec("USE `$db`");
    $admin->exec((string)file_get_contents(e291_root() . '/database/schema.sql'));
}

/** Every upgrade file, in numeric order (a lexical sort would put v10 before v2). */
function e291_upgrade_files(): array
{
    $files = glob(e291_root() . '/database/upgrade_v*.sql') ?: [];
    usort($files, static function (string $a, string $b): int {
        preg_match('/upgrade_v(\d+)\.sql$/', $a, $ma);
        preg_match('/upgrade_v(\d+)\.sql$/', $b, $mb);
        return (int)($ma[1] ?? 0) <=> (int)($mb[1] ?? 0);
    });
    return $files;
}

/** Apply every upgrade file, stopping at the first error (as a deployment would). */
function e291_apply_all_upgrades(PDO $admin, string $db): void
{
    foreach (e291_upgrade_files() as $f) {
        e291_apply_sql($admin, $db, $f);
    }
}

/**
 * Build a database that genuinely predates a given migration.
 *
 * schema.sql is the *consolidated current* shape, so it already contains everything
 * upgrade_v42 adds. Loading schema.sql and then measuring v42 therefore reports "verified"
 * without v42 having done anything — which is true, but useless for testing what happens
 * when v42 has *not* run.
 *
 * This helper loads schema.sql and then removes every object the file declares, so the
 * migration is genuinely unapplied and a test can observe it being applied from scratch.
 * Without it, both the "interrupted" and "unapplied" scenarios would silently test the
 * opposite of what they claim — a false pass of the same kind this execution is fixing.
 */
function e291_build_without(PDO $admin, string $db, string $file): void
{
    e291_build_fresh($admin, $db);
    e291_drop_declared_objects($admin, $db, $file);
}

/** Remove every column, index and foreign key a migration file declares. */
function e291_drop_declared_objects(PDO $admin, string $db, string $file): void
{
    $req = mawatheq_migration_requirements((string)file_get_contents($file));
    $admin->exec("USE `$db`");

    // Constraints first, then indexes, then columns — the reverse of creation order,
    // because a column cannot be dropped while something still depends on it.
    foreach ($req->objects as $o) {
        if ($o['kind'] === 'constraint' && e291_has_fk($admin, $db, $o['table'], $o['name'])) {
            $admin->exec("ALTER TABLE `{$o['table']}` DROP FOREIGN KEY `{$o['name']}`");
        }
    }
    foreach ($req->objects as $o) {
        if ($o['kind'] === 'index' && e291_has_index($admin, $db, $o['table'], $o['name'])) {
            $admin->exec("ALTER TABLE `{$o['table']}` DROP INDEX `{$o['name']}`");
        }
    }
    foreach ($req->objects as $o) {
        if ($o['kind'] !== 'column' || !e291_has_column($admin, $db, $o['table'], $o['name'])) {
            continue;
        }
        // schema.sql may index this column under a different name than the migration does.
        // Any index still touching it must go first, or the DROP COLUMN is rejected.
        $idx = $admin->prepare('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? AND INDEX_NAME<>"PRIMARY"');
        $idx->execute([$db, $o['table'], $o['name']]);
        foreach ($idx->fetchAll(PDO::FETCH_COLUMN) as $indexName) {
            try {
                $admin->exec("ALTER TABLE `{$o['table']}` DROP INDEX `$indexName`");
            } catch (Throwable $e) {
                // An index backing a foreign key cannot be dropped on its own; the FK is
                // handled above, so reaching here means another constraint owns it.
            }
        }
        $fk = $admin->prepare('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
        $fk->execute([$db, $o['table'], $o['name']]);
        foreach ($fk->fetchAll(PDO::FETCH_COLUMN) as $constraint) {
            try {
                $admin->exec("ALTER TABLE `{$o['table']}` DROP FOREIGN KEY `$constraint`");
            } catch (Throwable $e) {
            }
        }
        $admin->exec("ALTER TABLE `{$o['table']}` DROP COLUMN `{$o['name']}`");
    }
}

/**
 * Run one .sql file against a database.
 *
 * MySQL aborts a script at the first error, so a thrown exception here is the same signal
 * a deployment would see; callers that want to inspect it catch it.
 */
function e291_apply_sql(PDO $admin, string $db, string $path): void
{
    $admin->exec("USE `$db`");
    $admin->exec((string)file_get_contents($path));
}

/** Run only the first $n statements of a file, to simulate an interrupted migration. */
function e291_apply_sql_truncated(PDO $admin, string $db, string $path, int $statements): void
{
    $sql = mawatheq_sql_strip_comments((string)file_get_contents($path));
    $parts = array_values(array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== ''));
    $admin->exec("USE `$db`");
    foreach (array_slice($parts, 0, $statements) as $part) {
        $admin->exec($part);
    }
}

function e291_has_table(PDO $pdo, string $db, string $table): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    $s->execute([$db, $table]);
    return (int)$s->fetchColumn() > 0;
}

function e291_has_column(PDO $pdo, string $db, string $table, string $column): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$db, $table, $column]);
    return (int)$s->fetchColumn() > 0;
}

function e291_has_index(PDO $pdo, string $db, string $table, string $index): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?');
    $s->execute([$db, $table, $index]);
    return (int)$s->fetchColumn() > 0;
}

function e291_has_fk(PDO $pdo, string $db, string $table, string $name): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE="FOREIGN KEY"');
    $s->execute([$db, $table, $name]);
    return (int)$s->fetchColumn() > 0;
}

/**
 * How many of a migration's declared objects are present, and its state.
 *
 * @return array{state:string,present:int,total:int,missing:array}
 */
function e291_measure(PDO $pdo, string $db, string $file): array
{
    $req = mawatheq_migration_requirements((string)file_get_contents($file));
    return mawatheq_verify_requirements($pdo, $req, $db);
}

/**
 * Run one of the CLI scripts against a scratch database and capture its result.
 *
 * The scripts resolve their connection from config.php, so `--database=` is how a scratch
 * database is targeted without editing config/secrets.php.
 *
 * @return array{exit:int,output:string}
 */
function e291_run_script(string $relativePath, string $db, array $args = []): array
{
    $cmd = 'php ' . escapeshellarg(e291_root() . '/' . $relativePath)
        . ' --database=' . escapeshellarg($db);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    $cmd .= ' 2>&1';
    $output = [];
    $exit = 0;
    exec($cmd, $output, $exit);
    return ['exit' => $exit, 'output' => implode("\n", $output)];
}

function e291_drop(PDO $admin, string $db): void
{
    $admin->exec("DROP DATABASE IF EXISTS `$db`");
}

/**
 * A stable fingerprint of a table's structure: every column, its type and nullability.
 * Used to prove a repeated migration run changes nothing.
 */
function e291_schema_fingerprint(PDO $pdo, string $db, array $tables): string
{
    $rows = [];
    foreach ($tables as $t) {
        $s = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY COLUMN_NAME');
        $s->execute([$db, $t]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = $t . '|' . implode('|', array_map(fn($v) => (string)$v, $r));
        }
    }
    return hash('sha256', implode("\n", $rows));
}

/** Count rows in a table, for proving a backfill did not drop anything. */
function e291_row_count(PDO $pdo, string $db, string $table): int
{
    $pdo->exec("USE `$db`");
    return (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}
