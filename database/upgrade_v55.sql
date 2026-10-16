-- Execution 29.1 — strengthen the migration ledger so it can record *how much* of a
-- migration is present, not merely that its file exists.
--
-- Why: the v53 ledger stored one checksum and a statement count. Its proof of application
-- was a single column lookup, so `upgrade_v42.sql` — which adds 21 columns, 9 indexes and
-- 9 foreign keys — was recorded as applied as soon as `audit_logs.firm_id` existed, even
-- if the other 38 objects were missing. A ledger that can only say "applied" cannot
-- represent the partially applied state that production was actually in.
--
-- Additive only. No existing column is dropped, renamed or narrowed, and no recorded
-- checksum is rewritten: `checksum` stays the checksum observed when the migration was
-- first verified, and is never overwritten afterwards. A later edit to the file is
-- recorded separately in `current_checksum`, so history is preserved rather than replaced.
--
-- Idempotent: every statement is guarded by an information_schema check, and no positional
-- AFTER clause is used anywhere (see database/repair/01_repair_reset_columns.sql for why).
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 0) The ledger table itself, in case v53 has not run yet.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(191) NOT NULL,
    checksum CHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    statements_ok INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_schema_migration (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 1) How complete the migration is.
--    enumeration:
--      verified  every requirement the file declares is present
--      partial   some present, some absent — MySQL abandoned the file partway
--      missing   none present
--      unknown   the file declares nothing observable, so no claim is made
--    'verified' is the only value that means "safe to treat as applied".
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='state');
SET @s := IF(@c=0, "ALTER TABLE schema_migrations ADD COLUMN state VARCHAR(16) NOT NULL DEFAULT 'unknown'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2) Requirement counts, so the ledger carries its own evidence: a row that says
--    verified with 39/39 is auditable, one that says verified with 0/0 is not.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='requirements_total');
SET @s := IF(@c=0, 'ALTER TABLE schema_migrations ADD COLUMN requirements_total INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='requirements_present');
SET @s := IF(@c=0, 'ALTER TABLE schema_migrations ADD COLUMN requirements_present INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3) Drift record. `checksum` is written once, when the migration is first verified, and
--    is never modified afterwards. When the file on disk later changes, the new hash is
--    written here instead of overwriting the historical one, so an operator can see both
--    what was applied and what is now on disk.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='current_checksum');
SET @s := IF(@c=0, 'ALTER TABLE schema_migrations ADD COLUMN current_checksum CHAR(64) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='verified_at');
SET @s := IF(@c=0, 'ALTER TABLE schema_migrations ADD COLUMN verified_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='checked_at');
SET @s := IF(@c=0, 'ALTER TABLE schema_migrations ADD COLUMN checked_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 4) A partial application discovered after a verified row exists must be visible without
--    deleting that history. `first_seen_at` on the partial report is that record.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='partial_seen_at');
SET @s := IF(@c=0, 'ALTER TABLE schema_migrations ADD COLUMN partial_seen_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: existing rows predate the state column, so they are 'unknown' — we did not
-- verify their completeness when they were written and must not claim we did now.
UPDATE schema_migrations SET checked_at = applied_at WHERE checked_at IS NULL;
