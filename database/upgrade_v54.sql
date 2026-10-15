-- Execution 29 — API token lifecycle hardening.
--
-- Idempotent and additive. No positional AFTER clause is used anywhere, so a missing
-- sibling column cannot abort the file (see database/repair/01_repair_reset_columns.sql
-- for why that matters).
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) Integration tokens never expired.
--    `integration_api_tokens` had no expiry column at all, so a token issued for a
--    one-off export stayed valid forever unless someone revoked it by hand. The column
--    is nullable so existing rows keep working; the application applies a default
--    lifetime to newly issued tokens and refuses an expired one at authentication.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='integration_api_tokens' AND COLUMN_NAME='expires_at');
SET @s := IF(@c=0, 'ALTER TABLE integration_api_tokens ADD COLUMN expires_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2) Mobile API tokens: an index on the expiry column.
--    api_authenticate() filters on expires_at for every request; without an index that
--    is a full scan of the token table on the hottest path in the mobile API.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_tokens' AND INDEX_NAME='idx_api_tokens_expiry');
SET @s := IF(@c=0, 'ALTER TABLE api_tokens ADD INDEX idx_api_tokens_expiry (expires_at)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3) Mobile API tokens: an index on (user_type, user_id) for revocation sweeps.
--    A password reset runs `UPDATE api_tokens ... WHERE user_type=? AND user_id=?`,
--    which was also unindexed.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_tokens' AND INDEX_NAME='idx_api_tokens_owner');
SET @s := IF(@c=0, 'ALTER TABLE api_tokens ADD INDEX idx_api_tokens_owner (user_type, user_id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
