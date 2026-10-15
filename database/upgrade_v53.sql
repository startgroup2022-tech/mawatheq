-- Execution 29 — password reset hardening and migration ledger.
--
-- Idempotent: every statement is guarded, so replaying the file is harmless. No
-- statement uses a positional AFTER clause naming a column that may be absent,
-- because MySQL stops at the first error and would silently skip the rest of the
-- file — the failure mode that made the earlier repair script look applied while
-- the column it was meant to add was still missing.
SET NAMES utf8mb4;

-- 1. Session invalidation stamp.
--    A password reset must end the browser sessions that were opened before it, not
--    only the API tokens. The route guards compare this against the session's start
--    time; the code fails open when the column is absent, so this is additive.
SET @t := 'users';
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='session_invalidated_at');
SET @s := IF(@c=0, 'ALTER TABLE users ADD COLUMN session_invalidated_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='session_invalidated_at');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN session_invalidated_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='session_invalidated_at');
SET @s := IF(@c=0, 'ALTER TABLE admins ADD COLUMN session_invalidated_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Reset tokens are now stored as their SHA-256 (64 hex chars). The columns already
--    hold VARCHAR(64)/CHAR(64), so no width change is needed — only the values change.
--    Any token issued before this migration no longer matches, because lookups hash the
--    presented value. Pending links therefore expire early, which is the safe direction.

-- 3. Migration ledger.
--    Records which upgrade files actually completed, so a partially applied migration
--    can be detected instead of assumed. `checksum` is the SHA-256 of the file as
--    applied; a later mismatch means the file was edited after it ran.
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(191) NOT NULL,
    checksum CHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    statements_ok INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_schema_migration (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
