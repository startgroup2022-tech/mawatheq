-- ============================================================================
-- Execution 32 — Affiliate account registration & consent recording
-- upgrade_v58.sql
-- ============================================================================
--
-- Additive only. Every statement is a guarded ALTER or a guarded INSERT that runs
-- only when its condition holds. Running this file twice is a no-op.
--
-- WHY THIS FILE EXISTS — the `accepted_ip` width fault
--   `affiliate_ip_hash()` returns a 64-character SHA-256 digest
--   (includes/affiliate.php). `affiliate_applications.accepted_ip` and
--   `affiliate_agreements.accepted_ip` were declared VARCHAR(45) — the width of a
--   plain IPv6 literal, which is what the column was named for. Under
--   sql_mode=STRICT_TRANS_TABLES (the platform's mode, and the default on a
--   modern server) the insert is refused with
--   SQLSTATE[22001] Data too long for column 'accepted_ip'.
--
--   Three code paths write that value, and all three were therefore broken:
--     1. affiliate_submit_application()  — the public application form failed on
--        EVERY submission. The caller catches the exception and reports
--        "create_failed", so the visitor saw a generic failure and no row was ever
--        created. This is why the public form appeared to do nothing.
--     2. affiliate_register()            — the new account-based registration.
--     3. affiliate_accept_terms()        — recording consent. This one is worse
--        than a failed insert: affiliate_terms.php only activates an approved
--        affiliate when BOTH accept_terms() calls return true, so an approved
--        affiliate could never accept the terms and could therefore never reach
--        `active`. Referral links stayed permanently disabled.
--
--   Widening the column is the correct fix rather than truncating the hash: the
--   column stores a keyed hash, and a truncated hash still identifies the same
--   address, so the stored data keeps its meaning and existing rows are unaffected.
--   The name is retained for compatibility with the columns already deployed.
--
-- WHY THERE IS NO POSITIONAL `AFTER` CLAUSE ANYWHERE IN THIS FILE
--   upgrade_v56.sql shipped with `ADD COLUMN ... AFTER actor_name`, and production's
--   audit_logs had no actor_name column. MySQL aborts a script at the first error,
--   so everything below that statement silently never ran. MODIFY COLUMN does not
--   need positional placement, and nothing here is ordered after something that
--   might be absent.
-- ============================================================================


-- ---------------------------------------------------------------------------
-- 1. Widen affiliate_applications.accepted_ip to hold the hash
-- ---------------------------------------------------------------------------
-- Guarded on the current length so a second run is a no-op and a database that has
-- already been widened is left alone. Widening never truncates, so no row is lost.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliate_applications'
             AND COLUMN_NAME='accepted_ip' AND CHARACTER_MAXIMUM_LENGTH < 64);
SET @s := IF(@c=1,
  "ALTER TABLE affiliate_applications MODIFY COLUMN accepted_ip VARCHAR(64) NULL",
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 2. Widen affiliate_agreements.accepted_ip to hold the hash
-- ---------------------------------------------------------------------------

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliate_agreements'
             AND COLUMN_NAME='accepted_ip' AND CHARACTER_MAXIMUM_LENGTH < 64);
SET @s := IF(@c=1,
  "ALTER TABLE affiliate_agreements MODIFY COLUMN accepted_ip VARCHAR(64) NULL",
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 3. One agreement acceptance per type per affiliate
-- ---------------------------------------------------------------------------
-- affiliate_accept_terms() supersedes the previous acceptance and inserts a new
-- row, so history is kept and no unique key is wanted on (affiliate_id, type).
-- The lookup that decides whether the current version has been accepted is served
-- by an index instead of a table scan.

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliate_agreements'
             AND INDEX_NAME='idx_affiliate_agreements_current');
SET @s := IF(@c=0,
  "ALTER TABLE affiliate_agreements ADD INDEX idx_affiliate_agreements_current (affiliate_id, agreement_type, version, accepted_at)",
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 4. Migration ledger
-- ---------------------------------------------------------------------------
-- Written last, so its presence is evidence the whole file ran rather than
-- evidence that it started.

CREATE TABLE IF NOT EXISTS affiliate_migration_ledger (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration_key VARCHAR(80) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uq_affiliate_migration (migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO affiliate_migration_ledger (migration_key, note)
VALUES ('upgrade_v58_affiliate_account_registration', 'widen accepted_ip for the ip hash; account-based registration and consent recording')
ON DUPLICATE KEY UPDATE applied_at = applied_at;
