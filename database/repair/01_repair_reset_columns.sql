-- ============================================================
-- إصلاح الأعمدة الحرجة — يُنفَّذ أولاً
-- Execution 29 — critical column repair, runs FIRST
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
-- Idempotent: every statement is guarded by an information_schema check, so
-- replaying the file is harmless. Additive only: no column is dropped, renamed or
-- narrowed, and no row of application data is read or written.
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) Password-reset columns. These are the ones the HTTP 500 depends on:
--    reset-password.php reads reset_token/reset_expires, and a missing column makes
--    the page die with an unexplained 500 when display_errors is off.
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

-- Final confirmation: every critical column must now be present. This SELECT is the
-- last statement in the file, so it only runs if nothing above aborted.
SELECT 'critical columns' AS check_name,
       SUM(COLUMN_NAME IN ('reset_token','reset_expires','email_verify_token_hash','email_verify_expires','session_invalidated_at')) AS present,
       12 AS expected
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('users','lawyers','admins');
