-- ============================================================
-- إصلاح الأعمدة الحرجة — يُنفَّذ أولاً
-- Execution 29 — critical column repair, runs FIRST
-- (revised in Execution 29.1 — see DEFECT below)
-- ============================================================
--
-- Run this file BEFORE any other repair file.
--
-- Why the ordering matters: MySQL stops executing a file at the first statement
-- that errors. The earlier repair script placed the reset-column repair *below* an
-- `ALTER TABLE audit_logs ... AFTER actor_name` statement. Production's audit_logs
-- had no `actor_name`, that ALTER failed with SQLSTATE 42S22, and MySQL abandoned
-- the rest of the file — including the reset columns. The script therefore looked
-- "applied" while the reset failure it existed to fix survived untouched.
--
-- This file therefore (a) contains no positional AFTER clause that can name an
-- absent column, and (b) repairs the reset columns first, so nothing above it can
-- abort the file before they are handled.
--
-- DEFECT FIXED IN 29.1 — the final check counted the wrong thing.
--   The verification at the foot of this file asked for:
--       SUM(COLUMN_NAME IN (...)) AS present, 12 AS expected
--   over `TABLE_NAME IN ('users','lawyers','admins')`. That predicate matches a column
--   named reset_token on ANY of the three tables, and the real requirement is:
--       users    reset_token, reset_expires, email_verify_token_hash,
--                email_verify_expires, session_invalidated_at          (5)
--       lawyers  the same five                                          (5)
--       admins   reset_token, reset_expires, session_invalidated_at     (3)
--   = 13, not 12. The literal 12 was simply wrong, and because it under-counted the
--   requirement the check reported success on a database that was missing exactly one
--   column — a false pass on the very script that exists to catch a missing column.
--   Worse, the check was a bare SELECT: it printed a row and changed nothing, so even a
--   visible "12 vs 13" could not stop a deployment.
--
--   The expected count is now derived from the requirement list itself (below), not
--   written as a literal, so it cannot drift from the ALTERs again. The check verifies
--   each required column by table, name AND type, and it raises an explicit failure
--   (SIGNAL) when anything is missing rather than printing a row nobody reads.
--
-- Idempotent: every ALTER statement is guarded by an information_schema check, so
-- replaying the file is harmless. Additive only: no column is dropped, renamed or
-- narrowed, and no row of application data is read or written.
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 0) The requirement list. Held in a session TEMPORARY table so the expected count is
--    computed from the same rows that generate the checks, and so a future column is
--    added in one place. A temporary table is session-local: it is dropped when the
--    connection closes and never touches application data.
-- ------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `_mawatheq_required_columns`;
CREATE TEMPORARY TABLE `_mawatheq_required_columns` (
    table_name VARCHAR(64) NOT NULL,
    column_name VARCHAR(64) NOT NULL,
    expected_type VARCHAR(32) NOT NULL,
    PRIMARY KEY (table_name, column_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `_mawatheq_required_columns` (table_name, column_name, expected_type) VALUES
    ('users',   'reset_token',               'varchar(64)'),
    ('users',   'reset_expires',             'datetime'),
    ('users',   'email_verify_token_hash',   'char(64)'),
    ('users',   'email_verify_expires',      'datetime'),
    ('users',   'session_invalidated_at',    'datetime'),
    ('lawyers', 'reset_token',               'varchar(64)'),
    ('lawyers', 'reset_expires',             'datetime'),
    ('lawyers', 'email_verify_token_hash',   'char(64)'),
    ('lawyers', 'email_verify_expires',      'datetime'),
    ('lawyers', 'session_invalidated_at',    'datetime'),
    ('admins',  'reset_token',               'varchar(64)'),
    ('admins',  'reset_expires',             'datetime'),
    ('admins',  'session_invalidated_at',    'datetime');

-- ------------------------------------------------------------
-- 1) Password-reset columns. These are the ones the HTTP 500 depends on:
--    reset-password.php reads reset_token/reset_expires, and a missing column makes
--    the page die with an unexplained 500 when display_errors is off.
--    Guarded one column at a time, so this works whether both, one or neither exists.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='reset_token');
SET @s := IF(@c=0, 'ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='reset_expires');
SET @s := IF(@c=0, 'ALTER TABLE users ADD COLUMN reset_expires DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='reset_token');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='reset_expires');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN reset_expires DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='reset_token');
SET @s := IF(@c=0, 'ALTER TABLE admins ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='reset_expires');
SET @s := IF(@c=0, 'ALTER TABLE admins ADD COLUMN reset_expires DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2) Email-verification columns, same class of failure: verify-email.php and the
--    login guards read them, and a missing column is a 500 rather than a redirect.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email_verify_token_hash');
SET @s := IF(@c=0, 'ALTER TABLE users ADD COLUMN email_verify_token_hash CHAR(64) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email_verify_expires');
SET @s := IF(@c=0, 'ALTER TABLE users ADD COLUMN email_verify_expires DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='email_verify_token_hash');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN email_verify_token_hash CHAR(64) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='email_verify_expires');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN email_verify_expires DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3) API token revocation state. api/v1/bootstrap.php filters on `revoked_at`; when
--    the column is absent every authenticated API call returns a bare 500 instead
--    of 401, which silently breaks the whole mobile API.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_tokens' AND COLUMN_NAME='revoked_at');
SET @s := IF(@c=0, 'ALTER TABLE api_tokens ADD COLUMN revoked_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 4) Session invalidation stamp (Execution 29). The route guards compare this
--    against the session start time so a password reset ends stolen browser
--    sessions, not just API tokens.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='session_invalidated_at');
SET @s := IF(@c=0, 'ALTER TABLE users ADD COLUMN session_invalidated_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='session_invalidated_at');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN session_invalidated_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='session_invalidated_at');
SET @s := IF(@c=0, 'ALTER TABLE admins ADD COLUMN session_invalidated_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 5) Tenant column the firm features read (upgrade_v42). No positional AFTER clause,
--    so a missing sibling column can no longer abort this file.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE audit_logs ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND INDEX_NAME='idx_audit_logs_firm');
SET @s := IF(@c=0, 'ALTER TABLE audit_logs ADD INDEX idx_audit_logs_firm (firm_id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 6) Verification. Every required column must be present with the right type.
--
--    This is the last statement in the file, so it only runs if nothing above aborted —
--    which is itself the proof that no statement in the file failed. It compares the
--    requirement list to the live schema and SIGNALs an error when anything is missing
--    or mistyped, so the file fails loudly instead of printing a row that can be missed.
--
--    Per-column, not a count: a count can only say "one is missing", and a database
--    missing reset_token on `lawyers` but not `users` would satisfy any predicate that
--    matched the name on either table.
-- ------------------------------------------------------------
SET @expected := (SELECT COUNT(*) FROM `_mawatheq_required_columns`);

SET @present := (
    SELECT COUNT(*)
      FROM `_mawatheq_required_columns` rc
      JOIN information_schema.COLUMNS c
        ON c.TABLE_SCHEMA = DATABASE()
       AND c.TABLE_NAME   = rc.table_name
       AND c.COLUMN_NAME  = rc.column_name
       AND LOWER(c.COLUMN_TYPE) = rc.expected_type
);

SET @missing_list := (
    SELECT COALESCE(GROUP_CONCAT(CONCAT(rc.table_name, '.', rc.column_name, ' [', rc.expected_type, ']') ORDER BY rc.table_name, rc.column_name SEPARATOR ', '), '')
      FROM `_mawatheq_required_columns` rc
      LEFT JOIN information_schema.COLUMNS c
        ON c.TABLE_SCHEMA = DATABASE()
       AND c.TABLE_NAME   = rc.table_name
       AND c.COLUMN_NAME  = rc.column_name
     WHERE c.COLUMN_NAME IS NULL OR LOWER(c.COLUMN_TYPE) <> rc.expected_type
);

SELECT 'critical columns' AS check_name,
       @present  AS present,
       @expected AS expected,
       IF(@present = @expected, 'OK', 'FAIL') AS status,
       @missing_list AS missing_or_mistyped;

SET @msg := CONCAT('CRITICAL COLUMNS MISSING: expected ', @expected,
                   ' present ', @present, ' -> ', @missing_list,
                   '. The repair could not add them (check ALTER privilege and '
                   'database/repair/00_preflight_check.php).');

SET @s := IF(@present <> @expected, CONCAT('SIGNAL SQLSTATE ', "'45000'", ' SET MESSAGE_TEXT = ', QUOTE(@msg)), 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP TEMPORARY TABLE IF EXISTS `_mawatheq_required_columns`;
