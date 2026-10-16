# Mawatheq — Execution 29.1 Migration Report

**Scope:** database migration correctness. No application behaviour, no UI, no schema
feature was added or removed.

**Verified on:** PHP 8.4, MariaDB 11.8.6, local test environment
(`bash tests/setup_test_env.sh`). 27/27 suites pass.

---

## 1. Summary

Five defects were fixed. All five share one shape: **a check that could not fail reported
success.** A guard that covered eleven columns while testing one, a ledger that recorded a
migration on the strength of a single column, a count that declared 12 when 13 were
required, a backfill that could pick an arbitrary tenant, and a preflight that could not
express "cannot proceed". Each one produced a green result on a database that was in fact
broken, which is why the failures survived so long: nothing was lying, but nothing was
proving anything either.

| # | Defect | Effect in production | Fix |
|---|---|---|---|
| 1 | Multi-column `ALTER` guarded on one column | A half-applied migration was never completed | One guard per column, index and constraint |
| 2 | Ledger recorded a migration from one proof column | Partially applied migrations recorded as applied | Requirements derived from the SQL file; recorded only when every object is present |
| 3 | Reset-column check declared `expected = 12` | A database missing one column read as satisfied | Expectation derived from the requirement list (13); per-table, per-name, per-type; `SIGNAL` on failure |
| 4 | Backfill could assign a record to the wrong firm | Cross-tenant data exposure | Assign only where ownership is provably unambiguous; report the rest |
| 5 | Preflight could not block a deployment | A repair that cannot succeed looked fine | Blockers vs warnings, exit codes, `--postflight` gate |

---

## 2. Defect 1 — single-column guards around multi-column operations

### What was wrong

`upgrade_v42.sql` guarded a whole statement on one column:

```sql
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firms'
             AND COLUMN_NAME='legal_name');
SET @s := IF(@c=0, "ALTER TABLE law_firms
    ADD COLUMN legal_name ..., ADD COLUMN display_name ..., ADD COLUMN email ..., ...", 'SELECT 1');
```

MySQL stops a script at the first statement that errors. If a previous run aborted after
`legal_name` was added but before `display_name` was, the guard found `legal_name` present
and skipped the entire statement — so the remaining ten columns could never be added by any
subsequent run. The same pattern covered every firm-owned table, where a single guard
wrapped `ADD COLUMN firm_id`, `ADD INDEX idx_..._firm` and `ADD CONSTRAINT fk_..._firm`
together. A table with `firm_id` present but no index and no foreign key was skipped
entirely, permanently.

This is the shape that made the original production failure so confusing: the file looked
applied, because the guard it depended on had already been satisfied by a *different* part
of the same statement.

### What changed

Every column, index and constraint is now guarded on itself:

```sql
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE ... TABLE_NAME='law_firms' AND COLUMN_NAME='display_name');
SET @s := IF(@c=0, 'ALTER TABLE law_firms ADD COLUMN display_name VARCHAR(180) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

A re-run therefore completes whatever a previous run left unfinished. This is what makes the
file safe on a fresh database, a partially migrated one, and a fully migrated one alike —
and it is the mechanism that lets an operator repair a partial migration by re-running the
file rather than hand-editing the schema.

`upgrade_v42.sql` now declares **23 columns, 9 indexes, 9 foreign keys**, and contains no
positional `AFTER` clause anywhere. `AFTER <column>` is the other way this file could abort:
naming a sibling column that does not exist is `SQLSTATE 42S22`, and MySQL abandons the rest
of the file. Column position has no functional effect, so the clause is simply gone.

---

## 3. Defect 2 — the ledger recorded unproven work

### What was wrong

The v53 ledger proved a migration had been applied by looking up a single "proof column"
from a hand-written map, then recorded the file. One column is not a migration:
`upgrade_v42.sql` declares 41 objects, so a database with only `audit_logs.firm_id` present
satisfied the probe while 40 objects were absent, and v42 was recorded as applied. That is
the false positive this execution removes.

### What changed

`database/repair/migration_verify.php` reads each SQL file and extracts what it actually
declares — columns, indexes, constraints, tables, data rows and `MODIFY` statements —
using a quote-aware scanner, because `ENUM('active')` and `'...'` string bodies otherwise
truncate naive splitting.

Measured across all 54 migration files: **328 objects, 0 unparsed, 0 files with no
recognisable objects.** A file the scanner cannot understand is reported as `unknown`
rather than assumed fine.

The ledger then records a file only when every declared object is present, and
distinguishes four states:

| State | Meaning |
|---|---|
| `verified` | every declared object is present |
| `partial` | some are present, some are not — the production state the old script mislabelled |
| `missing` | none are present |
| `unknown` | the file could not be parsed; needs a human |

Two properties matter operationally:

- **A historical checksum is never overwritten.** The hash recorded when the migration was
  first verified stays in `checksum`; a later edit to the file is recorded beside it as
  `current_checksum`, so drift is visible without destroying the record of what was applied.
- **It exits non-zero** when any migration is not proven complete, so a caller cannot
  mistake a partial application for success. A bare `SELECT` that prints a row nobody reads
  is not a check.

`database/upgrade_v55.sql` extends the ledger table with `state`, `requirements_total`,
`requirements_present` and `current_checksum`. It is additive; a v53-era ledger keeps
working and the scripts report that it cannot express partial states.

---

## 4. Defect 3 — the reset-column check counted the wrong thing

### What was wrong

The final statement of `database/repair/01_repair_reset_columns.sql` was:

```sql
SELECT 'critical columns' AS check_name,
       SUM(COLUMN_NAME IN ('reset_token','reset_expires','email_verify_token_hash',
                           'email_verify_expires','session_invalidated_at')) AS present,
       12 AS expected
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','lawyers','admins');
```

The real requirement is **13**, not 12:

| Table | Required | Count |
|---|---|---|
| `users` | `reset_token`, `reset_expires`, `email_verify_token_hash`, `email_verify_expires`, `session_invalidated_at` | 5 |
| `lawyers` | the same five | 5 |
| `admins` | `reset_token`, `reset_expires`, `session_invalidated_at` | 3 |

Two independent faults:

1. **The number was wrong.** The predicate matches a column *name* on *any* of the three
   tables, so it counts 13 on a complete schema. A database missing exactly one column
   counts 12 — which equals the declared expectation, so the check reported success on the
   very database it existed to catch.
2. **It could not fail.** It was a bare `SELECT`. It printed a row and changed nothing, so
   even a visibly mismatched count could not stop a deployment.

### What changed

The requirement list is now data, held in a session temporary table, and the expectation is
computed from it:

```sql
SET @expected := (SELECT COUNT(*) FROM `_mawatheq_required_columns`);
```

The check verifies each required column **by table, by name and by type** — because a
count can only ever say "one is missing", never *which* one, and a database missing
`lawyers.reset_token` but not `users.reset_token` would satisfy any name-based predicate.
When anything is missing or mistyped it raises `SIGNAL SQLSTATE '45000'`, so the file fails
loudly. Being the last statement in the file, it also only runs if nothing above it aborted
— which is itself the proof that no earlier statement failed.

Demonstrated, on a database where `users.email_verify_token_hash` is present but mistyped as
`varchar(32)`:

```
SQLSTATE[45000]: 1644 CRITICAL COLUMNS MISSING: expected 13 present 12 ->
users.email_verify_token_hash [char(64)]. The repair could not add them (check ALTER
privilege and database/repair/00_preflight_check.php).
```

That is precisely the case the old literal `12` reported as a pass.

---

## 5. Defect 4 — a backfill could assign a record to the wrong firm

### What was wrong

The v42 backfill joined `law_firm_members` directly:

```sql
UPDATE cases c JOIN law_firm_members m ON m.lawyer_id = c.lawyer_id
   SET c.firm_id = m.firm_id WHERE c.firm_id IS NULL;
```

`law_firm_members` carries `UNIQUE(lawyer_id)`, so today one lawyer maps to one firm and the
join is unambiguous. But the join has no defence if that uniqueness is ever relaxed — and
relaxing it is exactly what a multi-firm feature would do. At that point the same `UPDATE`
picks an arbitrary row from several and silently moves one tenant's records into another.
The backfill's correctness depended entirely on a constraint in a different table that
nothing in the statement referenced.

### What changed

The join is against a derived table that keeps only lawyers with exactly one firm:

```sql
UPDATE cases c
  JOIN (SELECT lawyer_id, MIN(firm_id) AS firm_id
          FROM law_firm_members
         GROUP BY lawyer_id
        HAVING COUNT(DISTINCT firm_id) = 1) m ON m.lawyer_id = c.lawyer_id
   SET c.firm_id = m.firm_id
 WHERE c.firm_id IS NULL;
```

Three properties, in order of importance:

1. **An existing valid `firm_id` is never overwritten** — every statement is
   `WHERE <t>.firm_id IS NULL`. Ownership already recorded wins.
2. **A row is assigned only when ownership is provably unambiguous.** With today's
   `UNIQUE(lawyer_id)` the set is all members, so normal data backfills exactly as before.
   If that uniqueness is ever relaxed, ambiguous lawyers drop out of the backfill
   automatically instead of leaking a record.
3. **Nothing is deleted or reassigned.** Unresolvable rows keep `firm_id = NULL`, which is
   the fail-closed direction for tenant scoping.

A read-only reconciliation report then surfaces what could not be assigned
(`ambiguous_lawyers`, `unassigned_*`) for explicit human resolution. It reports; it does not
guess.

---

## 6. Defect 5 — preflight could not block a deployment

`database/repair/00_preflight_check.php` is now a preflight **and** a postflight:

```
php database/repair/00_preflight_check.php                 # ready to repair?
php database/repair/00_preflight_check.php --postflight    # did the migration land?
php database/repair/00_preflight_check.php --json
php database/repair/00_preflight_check.php --database=name
```

It reports privileges (and which are missing), the critical columns present or absent, the
ledger format, and — the part that turns "the SQL was run" into "the schema is actually
there" — **every migration measured against the live schema**, with the specific objects
missing from each partial file.

Exit codes:

| Code | Meaning |
|---|---|
| 0 | no blockers (warnings may be present) |
| 1 | blockers present — do not proceed |
| 2 | the check could not run (no connection, unknown database) |

**Blockers versus warnings is a deliberate distinction.** A missing `ALTER` grant or a
missing critical column stops the deployment: proceeding would leave the reset flow broken
while looking applied. An unrecorded-but-verified migration is only a warning — the schema
is right, the bookkeeping is behind, and `02_migration_ledger.php` fixes that without any
schema change.

This directly addresses the operational note from the previous execution: if the cPanel user
lacks `ALTER`, the preflight now says so *before* anything is attempted, as a blocker,
rather than after a repair silently declines to add columns.

---

## 7. Verification

Every fix has a regression test that reproduces the original failure state on a scratch
database built from the real `schema.sql`.

```
php tests/execution29_1_migration_integrity_test.php   # 52 checks, 0 failures
php tests/execution29_1_reset_columns_test.php         # 16 checks, 0 failures
php tests/execution29_migration_repair_test.php        # 36 checks, 0 failures
```

The ten scenarios required by the brief, plus two named elsewhere in it:

| # | Scenario | What is asserted |
|---|---|---|
| 1 | Fresh database | A pre-v42 database measures `missing`; after v42 it measures `verified` |
| 2 | Fully migrated database | Every one of 54 files verifies; ledger exits 0 |
| 3 | Partially applied v42 | Measures `partial`; re-running v42 completes the missing columns |
| 4 | Missing reset columns | Preflight exits 1 and names them; the repair restores each |
| 5 | One of two reset columns present | The missing sibling is added; the present one is untouched |
| 6 | Missing indexes or constraints | `firm_id` present with no index/FK measures `partial`; re-run restores both |
| 7 | Incorrect ledger entries | A falsified row is re-measured, downgraded to `partial`, and the historical checksum is preserved |
| 8 | Multi-firm ownership ambiguity | Ambiguous records stay `NULL`; unambiguous ones are assigned; a pre-existing `firm_id` is not overwritten; nothing leaks |
| 9 | Migration interrupted halfway | Measures `partial`; completing the run makes it verify |
| 10 | Repeated execution | Three runs leave the schema byte-identical |
| + | Ledger false positive | A lone proof column does **not** satisfy v42 (measures `partial`, 1 of 41) |
| + | No positional `AFTER` | No migration names `actor_name`; v42 has no `AFTER` at all |

Two details worth noting, because both were themselves false passes discovered while
writing the tests:

- `schema.sql` is the **consolidated current** schema, so it already contains everything
  v42 adds. Testing "v42 has not been applied" against it silently tested the opposite. The
  harness now builds a genuinely pre-v42 database by removing every object v42 declares.
- The interrupted-migration test initially truncated to 4 statements, which execute nothing
  at all — a `missing` state masquerading as a successful partial test. It now truncates
  mid-file, where some objects have landed and others have not.

Full suite: **27/27 pass, 0 failures**, including the Playwright and HTTP probe suites.

---

## 8. Files

| File | Change |
|---|---|
| `database/upgrade_v42.sql` | Rewritten: per-object guards; no positional `AFTER`; ownership-safe backfill; reconciliation report |
| `database/upgrade_v55.sql` | Ledger schema extension (`state`, `requirements_total`, `requirements_present`, `current_checksum`) |
| `database/repair/migration_verify.php` | New: quote-aware requirement extractor and verifier |
| `database/repair/01_repair_reset_columns.sql` | Data-driven requirement list (13); per-table/name/type check; `SIGNAL` on failure |
| `database/repair/02_migration_ledger.php` | Rewritten: derives requirements from the files; records only proven work; preserves historical checksums; non-zero exit on incomplete |
| `database/repair/00_preflight_check.php` | Extended: postflight mode, blocker/warning split, exit codes, JSON, `--database` |
| `tests/lib/migration_harness.php` | New: scratch-database harness |
| `tests/execution29_1_migration_integrity_test.php` | New: 52 checks |
| `tests/execution29_1_reset_columns_test.php` | New: 16 checks |
| `tests/execution29_migration_repair_test.php` | Strengthened: asserts `PREPARE`/`DEALLOCATE` parity and the presence of the `SIGNAL` |

---

## 9. Deployment order

The migration package is applied **before** the site files.

1. `php database/repair/00_preflight_check.php` — must exit 0. If it reports a blocker,
   resolve it before continuing.
2. `database/upgrade_v55.sql` — extends the ledger (additive; safe on any version).
3. `database/repair/01_repair_reset_columns.sql` — repairs the critical columns.
4. `database/repair/02_migration_ledger.php` — verify and record.
5. `php database/repair/00_preflight_check.php --postflight` — must exit 0 before the site
   files go up.
6. Upload the site files.

**Rollback needs no migration reversal.** Every upgrade in this execution is additive: no
column is dropped, renamed or narrowed, no existing `firm_id` is overwritten, and no row of
application data is modified by any repair script. Restoring the previous site files is a
complete rollback; the added columns are inert to the older code.
