# Mawatheq — Migration Verification Runbook

Operational reference for the database verification tooling added in Execution 29.1.
For the defect analysis behind it, see `MAWATHEQ_EXECUTION_29_1_MIGRATION_REPORT.md`.
For the Arabic deployment walkthrough, see `MAWATHEQ_EXECUTION_29_1_DEPLOYMENT_AR.md`.

---

## 1. The four commands

All four run from the repository root and are CLI-only (they refuse to run over HTTP).

| Command | Purpose | Exit codes |
|---|---|---|
| `php database/repair/00_preflight_check.php` | Is this database ready to repair? | 0 clean, 1 blockers, 2 cannot run |
| `php database/repair/00_preflight_check.php --postflight` | Is the migration complete enough to deploy? | 0 clean, 1 blockers, 2 cannot run |
| `php database/repair/01_repair_reset_columns.sql` | Add the critical missing columns | 0 ok, non-zero + `SIGNAL` on failure |
| `php database/repair/02_migration_ledger.php` | Verify every migration and record the result | 0 all complete, 1 any incomplete |

Useful flags:

- `--json` — machine-readable output (preflight, ledger). Suppresses prose.
- `--database=<name>` — target a database other than the one `config/secrets.php` selects.
  The name is validated against `information_schema`; the connection switches with `USE`.
  Useful for verifying a staging copy or a restored backup without editing the secrets file.
- `--verify` (ledger) — report only; writes nothing to `schema_migrations`.

---

## 2. Reading the preflight output

The preflight reports four sections, then a verdict.

```
الصلاحيات / privileges
  ALTER privilege          yes
  INDEX privilege          yes
  REFERENCES privilege     yes
  CREATE privilege         yes

الأعمدة الحرجة / critical columns
  users                    ok
  lawyers                  ok
  admins                   missing: session_invalidated_at
  api_tokens               ok

سجل الترحيلات / migration ledger
  schema_migrations        present
  ledger format            extended

اكتمال الترحيلات / migration completeness
  file                 state    objects present
  upgrade_v35.sql      partial  6/9
  upgrade_v42.sql      partial  38/41

  verified: 52 | partial: 2 | missing: 0 | unknown: 0
```

Each migration is measured against the **live schema**, not against the ledger — the
ledger can be wrong, the schema cannot. `partial  38/41` means 38 of the 41 objects
`upgrade_v42.sql` declares are present.

### Blockers versus warnings

| Level | Examples | Effect |
|---|---|---|
| **blocker** | no `ALTER` grant; a critical column missing; a table missing; a partial or missing migration; ledger absent | exit 1 — do not proceed |
| **warning** | ledger not extended (pre-29.1); a migration file edited after it was recorded; `SHOW GRANTS` unreadable | exit 0 — noted, not fatal |

The distinction is deliberate. A missing column stops the deployment, because proceeding
leaves the reset flow broken while everything looks fine. An unrecorded-but-verified
migration does not — the schema is correct, only the bookkeeping is behind, and the ledger
command fixes that with no schema change.

---

## 3. Repairing a partial migration

A partial migration is repaired by **re-running the migration file**. Every statement is
guarded per column, index and constraint, so a re-run adds only what is absent and touches
nothing else.

```bash
# See exactly what is missing
php database/repair/00_preflight_check.php --json | \
  python3 -c 'import json,sys; d=json.load(sys.stdin);
for m in d["migrations"]:
    if m["state"]!="verified": print(m["filename"], m["state"], m["missing"])'

# Complete it
mariadb -u USERNAME -p DBNAME < database/upgrade_v42.sql

# Confirm
php database/repair/00_preflight_check.php
```

Do **not** hand-edit the schema. A manual `ALTER` that differs from the file — a different
type, a missing index — will read as verified to no one, and the next re-run will find the
object present and skip it, leaving the divergence permanently.

For the critical reset columns specifically, `01_repair_reset_columns.sql` is the shorter
path: it declares its own requirement list, adds what is missing, and verifies by table,
name and type at the end.

---

## 4. Reading the ledger output

```
  file                 state     present
  upgrade_v35.sql      partial   6/9
  upgrade_v42.sql      partial   38/41

  verified: 52 | partial: 2 | missing: 0 | unknown: 0
```

States:

| State | Meaning | Action |
|---|---|---|
| `verified` | every declared object is present | none |
| `partial` | some present, some not | re-run the file |
| `missing` | none present | run the file in numeric order |
| `unknown` | file could not be parsed | needs a human; do not assume it is fine |

A file is recorded **only** when it is `verified`. `partial`, `missing` and `unknown` are
reported and never recorded as applied.

### Historical checksums

The ledger keeps two hashes:

- `checksum` — the hash recorded when the migration was first verified. **Never
  overwritten.** It is the record of what was actually applied.
- `current_checksum` — the hash of the file as it is on disk now.

When these differ, the file was edited after it was applied. The ledger reports drift and
leaves the historical value intact, so an edit is visible without erasing the evidence of
what ran.

---

## 5. Interpreting a false pass

If a migration is recorded as applied but the schema disagrees, the ledger will say so on
the next run — it re-measures the schema rather than trusting the row. The falsified row is
downgraded to its true state and the ledger exits non-zero.

This is the specific failure the Execution 29.1 ledger rewrite removes: the previous script
recorded `upgrade_v42.sql` on the strength of `audit_logs.firm_id` alone, so a database with
1 of its 41 objects present was recorded as applied.

To see the current truth regardless of what is recorded:

```bash
php database/repair/00_preflight_check.php --json | \
  python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["counts"])'
```

---

## 6. Testing the tooling itself

```bash
php tests/execution29_1_migration_integrity_test.php   # 52 checks
php tests/execution29_1_reset_columns_test.php         # 16 checks
php tests/execution29_migration_repair_test.php        # 36 checks
bash tests/run_all_tests.sh                            # everything
```

The migration tests create and drop their own scratch databases (`mawatheq_e291_*`). They
never touch the database named in `config/secrets.php`.

One thing to understand about the tests, because it is easy to get wrong: `schema.sql` is
the **consolidated current** schema, so it already contains everything the migrations add.
Measuring a migration against a database freshly loaded from `schema.sql` reports `verified`
without the migration having done anything. `tests/lib/migration_harness.php` provides
`e291_build_without()`, which loads `schema.sql` and then removes every object a migration
declares, producing a database that genuinely predates it. Use it for any test about a
migration being unapplied.

---

## 7. Failure modes and what they mean

| Symptom | Cause | Fix |
|---|---|---|
| `CRITICAL COLUMNS MISSING: expected 13 present N` | the columns could not be added | check `ALTER` (preflight, section 1) |
| `Access denied ... to database` | insufficient grants | grant `ALTER`/`INDEX`/`REFERENCES` |
| preflight exit 2 | no connection, or `--database` names a database that does not exist | check `config/secrets.php` |
| ledger exit 1 with `partial` | an aborted run left a migration half-applied | re-run that file |
| ledger exit 1 with `unknown` | a migration file could not be parsed | inspect the file; do not assume it applied |
| `a file was edited after it was recorded` | checksum drift | review the edit before re-running the file |

---

## 8. What these scripts never do

- They never modify application data. `01_repair_reset_columns.sql` performs DDL only
  (additive `ALTER TABLE ... ADD COLUMN` / `ADD INDEX`); `upgrade_v42.sql` also backfills
  `firm_id`, and only where it is `NULL`. The preflight, ledger and `--verify` are read-only
  apart from `schema_migrations`.
- They never drop, rename or narrow a column.
- They never overwrite an existing `firm_id`.
- They never overwrite a historical checksum.
- They never guess a tenant. Ambiguous ownership is reported for human resolution.
