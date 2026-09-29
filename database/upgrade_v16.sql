-- Email verification and login throttling. Existing accounts stay verified;
-- registrations made after this migration must verify their email.
SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email_verified');
SET @s := IF(@c=0, 'ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN email_verify_token_hash CHAR(64) DEFAULT NULL, ADD COLUMN email_verify_expires DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='email_verified');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN email_verify_token_hash CHAR(64) DEFAULT NULL, ADD COLUMN email_verify_expires DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
    scope_key CHAR(64) NOT NULL PRIMARY KEY,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    blocked_until DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_auth_rate_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

