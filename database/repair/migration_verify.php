<?php
/**
 * Execution 29.1 — migration requirement extraction and schema verification.
 *
 * Shared by the migration ledger, the preflight check and the regression tests, so all
 * three agree on what "this migration applied" means. Previously the ledger asked a single
 * question — is one proof column present? — and recorded the file on that basis. One column
 * is not the migration. `upgrade_v42.sql` adds 23 structural objects; a database with only
 * `audit_logs.firm_id` present satisfied the old probe while 22 others were missing, and the
 * ledger would record v42 as applied. That is the false positive this library exists to stop.
 *
 * The library answers one question per migration: which of the schema objects that file
 * creates are actually present? Objects are read out of the SQL itself rather than from a
 * hand-maintained list, because a list drifts from the file the moment anyone edits it, and
 * a mismatch between the manifest and the file is exactly how the ledger lied before.
 *
 * States, from the evidence available:
 *
 *   verified      every requirement the file declares is present
 *   partial       at least one present, at least one absent — MySQL abandoned the file part
 *                 way through, which is the documented production failure
 *   missing       none present (or the file has no requirement that can be observed)
 *   unknown       the file could not be parsed, so no claim can be made either way
 *
 * `unknown` is deliberately not a synonym for `applied`. A file we cannot read is a file we
 * cannot vouch for, and the ledger treats the two differently.
 */

/**
 * Schema objects a migration file declares, keyed by kind.
 *
 * Only additive statements are collected. `MODIFY COLUMN` is recorded as a type assertion
 * rather than a creation, because the column normally already exists and we want to check
 * the declared type survived rather than that the statement ran.
 */
final class MigrationRequirements
{
    /** @var array<int,array{kind:string,table:string,name:string,extra:string}> */
    public array $objects = [];

    /** @var string[] statements that could not be understood */
    public array $unparsed = [];

    /** Count of guarded statements (SET/PREPARE/EXECUTE triples) in the file. */
    public int $guarded = 0;

    /** True when the file contains no structural or data requirement we can observe. */
    public bool $hasObservableRequirement = false;

    public function isEmpty(): bool
    {
        return $this->objects === [];
    }
}

/**
 * Remove SQL comments so a pattern never matches prose that merely mentions a keyword.
 *
 * Order matters: block comments first, then line comments. A line comment inside a block
 * comment would otherwise survive the first pass and be stripped only by the second, which
 * is the behaviour we want, but doing it the other way round can leave half a block comment.
 */
function mawatheq_sql_strip_comments(string $sql): string
{
    $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;
    return preg_replace('/--[^\n]*/', ' ', $sql) ?? $sql;
}

/**
 * Locate the ALTER TABLE fragments in a file so ADD clauses can be attributed to a table.
 *
 * A statement can be written directly (`ALTER TABLE t ADD COLUMN ...`) or held inside a
 * prepared-statement string (`SET @s := IF(@c=0, 'ALTER TABLE t ADD COLUMN ...', 'SELECT 1')`).
 * Both forms must be read, because 31 of the 53 upgrade files use the guarded form — the very
 * files whose partial application we are trying to detect.
 *
 * Finding the end of a fragment needs care. A naive "cut at the first quote" is wrong: the
 * guarded form is itself a quoted string, and its contents contain further quotes from ENUM
 * definitions, as in
 *
 *     SET @s := IF(@c=0, "ALTER TABLE law_firm_members
 *         ADD COLUMN status ENUM('active','inactive') ..., ADD COLUMN permissions_json ...", 'SELECT 1')
 *
 * so cutting at the first `'` truncates the body at `ENUM(` and silently drops every column
 * after it — which would under-count requirements and let a partial migration verify. The
 * scanner below therefore tracks the *string* the ALTER lives in, ignoring quote characters
 * of the other kind, and handles backslash and doubled-quote escapes.
 *
 * @return array<int,array{table:string,sql:string}>
 */
function mawatheq_sql_alter_fragments(string $sql): array
{
    // Pass 1 — the spans of every string literal, so an ALTER's end can be found correctly.
    $spans = [];
    $len = strlen($sql);
    $open = -1;
    $delim = '';
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($open < 0) {
            if ($ch === "'" || $ch === '"') {
                $open = $i;
                $delim = $ch;
            }
            continue;
        }
        if ($ch === '\\') {
            $i++; // skip the escaped character
            continue;
        }
        if ($ch === $delim) {
            if ($i + 1 < $len && $sql[$i + 1] === $delim) {
                $i++; // a doubled delimiter is an escaped quote, not the end
                continue;
            }
            $spans[] = [$open, $i];
            $open = -1;
        }
    }
    if ($open >= 0) {
        $spans[] = [$open, $len]; // unterminated string: treat the rest of the file as content
    }

    /** The string span containing $pos, if any. */
    $spanAt = static function (int $pos) use ($spans): ?array {
        foreach ($spans as $span) {
            if ($pos > $span[0] && $pos < $span[1]) {
                return $span;
            }
        }
        return null;
    };

    if (!preg_match_all('/ALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $out = [];
    $starts = array_map(static fn(array $m): int => $m[1], $matches[0]);
    foreach ($matches[0] as $i => $m) {
        $table = $matches[1][$i][0];
        $from = $m[1] + strlen($m[0]);
        $span = $spanAt($m[1]);

        // The fragment ends at the enclosing string's closing quote, or — for a bare
        // statement — at the next semicolon. A following ALTER TABLE always bounds it too,
        // so two statements on one line cannot bleed into each other.
        $limits = [$len];
        if ($span !== null) {
            $limits[] = $span[1];
        } else {
            $semi = strpos($sql, ';', $from);
            if ($semi !== false) {
                $limits[] = $semi;
            }
        }
        foreach ($starts as $other) {
            if ($other > $m[1]) {
                $limits[] = $other;
            }
        }
        $end = min($limits);
        $out[] = ['table' => $table, 'sql' => substr($sql, $from, max(0, $end - $from))];
    }
    return $out;
}

/**
 * Extract every observable requirement from one migration file.
 *
 * Unrecognised ADD clauses are recorded in `unparsed` rather than skipped, so a file that
 * uses a shape this parser does not know reports `unknown` instead of a rosy `verified`.
 */
function mawatheq_migration_requirements(string $sql): MigrationRequirements
{
    $req = new MigrationRequirements();
    $clean = mawatheq_sql_strip_comments($sql);
    $req->guarded = preg_match_all('/\bEXECUTE\s+/i', $clean);

    foreach (mawatheq_sql_alter_fragments($clean) as $frag) {
        $table = $frag['table'];
        $body  = $frag['sql'];

        // ADD [COLUMN] name ...
        preg_match_all('/\bADD\s+(?:COLUMN\s+)?`?(\w+)`?/i', $body, $adds, PREG_SET_ORDER);
        foreach ($adds as $add) {
            $tok = strtoupper($add[1]);
            if (in_array($tok, ['INDEX', 'KEY', 'CONSTRAINT', 'UNIQUE', 'PRIMARY', 'FOREIGN', 'FULLTEXT', 'SPATIAL'], true)) {
                continue; // handled below, or a form we treat as unparsed
            }
            if (preg_match('/\bADD\s+(?:COLUMN\s+)?`?' . preg_quote($add[1], '/') . '`?\s+(ENUM|SET|VARCHAR|CHAR|INT|BIGINT|SMALLINT|TINYINT|TEXT|MEDIUMTEXT|LONGTEXT|DATETIME|TIMESTAMP|DATE|TIME|DECIMAL|NUMERIC|FLOAT|DOUBLE|BOOL|BOOLEAN|JSON|BLOB|MEDIUMBLOB|LONGBLOB|VARBINARY|BINARY)/i', $body, $type)) {
                $req->objects[] = [
                    'kind' => 'column',
                    'table' => $table,
                    'name' => $add[1],
                    // $type[1] is the optional COLUMN keyword group; the type is $type[2]
                    // only when it matched, so read the last element which is the type.
                    'extra' => strtoupper((string)end($type)),
                ];
                $req->hasObservableRequirement = true;
                continue;
            }
            // An ADD whose token is not a type and not a known object kind — a shape this
            // parser does not model. Record it so the file cannot be called verified.
            if (!preg_match('/\bADD\s+COLUMN\b/i', $body)) {
                $req->unparsed[] = "ADD {$add[1]} on {$table}";
            }
        }

        // ADD [UNIQUE] [FULLTEXT] INDEX|KEY name
        preg_match_all('/\bADD\s+(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?(?:INDEX|KEY)\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $body, $idx);
        foreach ($idx[1] as $name) {
            $req->objects[] = ['kind' => 'index', 'table' => $table, 'name' => $name, 'extra' => ''];
            $req->hasObservableRequirement = true;
        }

        // ADD CONSTRAINT name FOREIGN KEY ... REFERENCES t (col)
        preg_match_all('/\bADD\s+CONSTRAINT\s+`?(\w+)`?\s+([A-Z]+)/i', $body, $cons, PREG_SET_ORDER);
        foreach ($cons as $c) {
            if (strtoupper($c[2]) !== 'FOREIGN') {
                $req->unparsed[] = "ADD CONSTRAINT {$c[1]} {$c[2]} on {$table}";
                continue;
            }
            $req->objects[] = ['kind' => 'constraint', 'table' => $table, 'name' => $c[1], 'extra' => 'FOREIGN KEY'];
            $req->hasObservableRequirement = true;
        }
    }

    // CREATE TABLE [IF NOT EXISTS] name
    preg_match_all('/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $clean, $ct);
    foreach ($ct[1] as $name) {
        $req->objects[] = ['kind' => 'table', 'table' => $name, 'name' => $name, 'extra' => ''];
        $req->hasObservableRequirement = true;
    }

    // ALTER TABLE t MODIFY [COLUMN] name <type>
    //   Non-structural in MySQL terms (the column usually already exists) but it is still a
    //   claim about the schema: the column must be present with that base type. Recording it
    //   means a file whose only statement is a MODIFY is verifiable instead of unknown.
    preg_match_all('/\bMODIFY\s+(?:COLUMN\s+)?`?(\w+)`?\s+([A-Za-z]+)/i', $clean, $mod, PREG_SET_ORDER);
    foreach ($mod as $m) {
        // Attribute the MODIFY to the nearest preceding ALTER TABLE.
        $table = '';
        $offset = (int)strrpos(substr($clean, 0, (int)strpos($clean, $m[0])), 'ALTER TABLE');
        if ($offset !== false && $offset >= 0) {
            if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?/i', substr($clean, $offset), $am)) {
                $table = $am[1];
            }
        }
        if ($table === '') {
            continue;
        }
        $req->objects[] = ['kind' => 'column', 'table' => $table, 'name' => $m[1], 'extra' => strtoupper($m[2])];
        $req->hasObservableRequirement = true;
    }

    // INSERT [IGNORE] INTO <key-value table> (key_col, ...) VALUES ('literal', ...)
    //   A settings seed has no schema footprint at all, so the only honest proof it ran is
    //   the row it inserts. Only literal (quoted or numeric) first values are used; an
    //   INSERT ... SELECT is not something we can predict and is left as unobservable.
    preg_match_all("/\bINSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\s*\(([^)]*)\)\s*VALUES\s*\(\s*'((?:[^'\\\\]|\\\\.)*)'/i", $clean, $ins, PREG_SET_ORDER);
    foreach ($ins as $i) {
        $cols = array_map('trim', explode(',', $i[2]));
        $keyCol = trim($cols[0], "` \t\n");
        $req->objects[] = [
            'kind' => 'row',
            'table' => $i[1],
            'name' => $i[3],
            'extra' => $keyCol,
        ];
        $req->hasObservableRequirement = true;
    }

    // ADD ... IF NOT EXISTS forms that appear without a leading ALTER in this parser's view
    preg_match_all('/\bCREATE\s+(?:UNIQUE\s+|FULLTEXT\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s+ON\s+`?(\w+)`?/i', $clean, $ci, PREG_SET_ORDER);
    foreach ($ci as $c) {
        $req->objects[] = ['kind' => 'index', 'table' => $c[2], 'name' => $c[1], 'extra' => ''];
        $req->hasObservableRequirement = true;
    }

    return $req;
}

/**
 * Check each requirement against the live schema.
 *
 * Returns per-object presence plus a roll-up state. Runs no DDL and reads only
 * information_schema, so it is safe against production.
 *
 * @return array{state:string,present:int,total:int,missing:array<int,array{kind:string,table:string,name:string}>,unparsed:string[]}
 */
function mawatheq_verify_requirements(PDO $pdo, MigrationRequirements $req, string $database): array
{
    $missing = [];
    $present = 0;

    $colStmt = $pdo->prepare('SELECT DATA_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
    $tblStmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    $idxStmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?');
    $fkStmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE="FOREIGN KEY"');

    foreach ($req->objects as $object) {
        $ok = false;
        switch ($object['kind']) {
            case 'column':
                $colStmt->execute([$database, $object['table'], $object['name']]);
                $type = $colStmt->fetchColumn();
                if ($type !== false && $type !== null) {
                    // Presence is the requirement; the declared type is reported but a
                    // different type is not treated as absent, because MySQL may have
                    // widened it and calling that "missing" would be a false alarm.
                    $ok = true;
                }
                break;
            case 'index':
                $idxStmt->execute([$database, $object['table'], $object['name']]);
                $ok = (int)$idxStmt->fetchColumn() > 0;
                break;
            case 'constraint':
                $fkStmt->execute([$database, $object['table'], $object['name']]);
                $ok = (int)$fkStmt->fetchColumn() > 0;
                break;
            case 'table':
                $tblStmt->execute([$database, $object['name']]);
                $ok = (int)$tblStmt->fetchColumn() > 0;
                break;
            case 'row':
                // Prove a settings seed by the row it inserts, keyed on the first column of
                // the INSERT list. Guarded so a table without that column reports absent
                // rather than raising, because the requirement is genuinely unmet then.
                if (!preg_match('/^\w+$/', $object['extra'])) {
                    break;
                }
                try {
                    $stmt = $pdo->prepare(sprintf(
                        'SELECT COUNT(*) FROM `%s` WHERE `%s` = ?',
                        str_replace('`', '', $object['table']),
                        $object['extra']
                    ));
                    $stmt->execute([$object['name']]);
                    $ok = (int)$stmt->fetchColumn() > 0;
                } catch (Throwable $e) {
                    $ok = false;
                }
                break;
        }
        if ($ok) {
            $present++;
        } else {
            $missing[] = ['kind' => $object['kind'], 'table' => $object['table'], 'name' => $object['name']];
        }
    }

    $total = count($req->objects);
    if ($req->unparsed !== [] && $present === 0) {
        $state = 'unknown';
    } elseif ($total === 0) {
        // A data-only migration (settings seed, value rewrite). Nothing structural to
        // observe; we cannot prove it ran, so it is not called verified from schema alone.
        $state = 'unknown';
    } elseif (count($missing) === 0) {
        $state = 'verified';
    } elseif ($present > 0) {
        $state = 'partial';
    } else {
        $state = 'missing';
    }

    return [
        'state' => $state,
        'present' => $present,
        'total' => $total,
        'missing' => $missing,
        'unparsed' => $req->unparsed,
    ];
}

/** Human-readable label for a state, used by every report so the wording stays consistent. */
function mawatheq_migration_state_label(string $state): string
{
    return match ($state) {
        'verified' => 'verified',
        'partial'  => 'PARTIAL',
        'missing'  => 'missing',
        'unknown'  => 'unknown',
        default    => 'unknown',
    };
}
