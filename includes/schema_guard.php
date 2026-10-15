<?php
/**
 * Schema-drift guard.
 *
 * A cPanel install can be running current code against a database that was built
 * from an older base and never received every `database/upgrade_v*.sql`. When a
 * page or REST endpoint then references a column that does not exist, PDO throws,
 * `display_errors` is off in production, and the user gets a bare HTTP 500 with an
 * empty body — impossible to diagnose from the browser.
 *
 * This guard makes a known, additive column self-healing: if the column is missing
 * it is created with the exact definition the corresponding migration uses, so the
 * query works and the incident does not recur. It never drops, renames or alters an
 * existing column, and it only ever runs a query that the migration itself runs.
 *
 * Self-healing is OPT-IN. The callers gate it on MAWATHEQ_ALLOW_RUNTIME_SCHEMA_DDL=1,
 * because running DDL from a page request means the schema is changed by whichever HTTP
 * request arrives first, with no ordering against the migration files and no record of
 * what was applied. Left on by default that turns browsing into a schema mutation, which
 * is exactly the kind of invisible change that makes an incident hard to diagnose. It is
 * kept for an operator who explicitly needs a self-healing deploy; the supported path is
 * to apply database/upgrade_v*.sql (see database/repair/00_preflight_check.php).
 *
 * The check is one cached information_schema lookup per request (the result is held
 * in a static and in the session), so it costs nothing on the hot path.
 */

if (!function_exists('schema_ensure_column')) {
    /**
     * Ensure `$table.$column` exists, creating it when absent.
     *
     * @param PDO    $pdo       Live connection (ERRMODE_EXCEPTION).
     * @param string $table     Table name; interpolated, so callers pass literals.
     * @param string $column    Column name; interpolated, so callers pass literals.
     * @param string $definition DDL fragment, e.g. "VARCHAR(100) NOT NULL DEFAULT 'x'".
     * @return bool  True when the column exists after the call.
     */
    function schema_ensure_column(PDO $pdo, string $table, string $column, string $definition): bool
    {
        // Only plain identifiers reach the DDL below; anything else is refused rather
        // than interpolated, so this helper can never become an injection point.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            return false;
        }

        static $verified = [];
        $key = $table . '.' . $column;
        if (isset($verified[$key])) {
            return $verified[$key];
        }

        // A prior request on this session already confirmed (or repaired) it.
        if (!empty($_SESSION['_schema_ok'][$key])) {
            return $verified[$key] = true;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            if ((int)$stmt->fetchColumn() > 0) {
                $_SESSION['_schema_ok'][$key] = 1;
                return $verified[$key] = true;
            }

            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            $_SESSION['_schema_ok'][$key] = 1;
            error_log("schema_guard: added missing column {$key} ({$definition})");
            return $verified[$key] = true;
        } catch (Throwable $e) {
            // Read-only database user, or the table itself is missing: report the real
            // condition instead of letting the page die with an unexplained 500.
            error_log("schema_guard: could not ensure {$key}: " . $e->getMessage());
            return $verified[$key] = false;
        }
    }
}

if (!function_exists('schema_ensure_columns')) {
    /**
     * Ensure several columns on one table. Each entry is [column, definition].
     *
     * @param array<int,array{0:string,1:string}> $columns
     */
    function schema_ensure_columns(PDO $pdo, string $table, array $columns): void
    {
        foreach ($columns as $spec) {
            if (isset($spec[0], $spec[1])) {
                schema_ensure_column($pdo, $table, $spec[0], $spec[1]);
            }
        }
    }
}
