-- ============================================================================
-- مواثيق — طلبات حذف الحسابات (Execution 73) — upgrade_v77.sql
-- MAWATHEQ — account deletion requests (Google Play Data safety requirement)
-- ============================================================================
--
-- Additive and idempotent: safe to replay, and safe on a database that already has
-- the tables. Every statement is guarded on information_schema / uses
-- CREATE TABLE IF NOT EXISTS, and no statement uses a positional AFTER clause (a
-- missing sibling column would abort the rest of the file — see Execution 29).
--
-- WHY
--   Google Play requires a *publicly reachable* account-deletion route that works
--   without signing in, and a documented workflow for a request that arrives outside
--   the app (by email or phone). The existing in-app/in-session deletion anonymises
--   the account directly; this adds the request queue behind the public page so a
--   visitor who cannot sign in still has a real, tracked path to deletion.
--
--   No foreign keys are added here on purpose. A request may reference an account
--   that is later anonymised, or none at all (a typo'd address), so a hard FK would
--   either block the deletion or orphan the row. The account link is a plain
--   (account_type, account_id) pair resolved at execution time.
-- ============================================================================

SET NAMES utf8mb4;

-- --- account_deletion_requests ----------------------------------------------
CREATE TABLE IF NOT EXISTS `account_deletion_requests` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference` VARCHAR(16) NOT NULL,
  `account_type` ENUM('client','lawyer') NOT NULL DEFAULT 'client',
  `account_id` INT(10) UNSIGNED NULL DEFAULT NULL,
  `identifier` VARCHAR(190) NOT NULL,
  `identifier_hash` CHAR(64) NOT NULL,
  `identifier_kind` ENUM('email','phone') NOT NULL DEFAULT 'email',
  `notify_email` VARCHAR(190) NULL DEFAULT NULL,
  `status` ENUM('pending','processing','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `verification_status` ENUM('unverified','verified') NOT NULL DEFAULT 'unverified',
  `verification_channel` ENUM('email','phone','manual') NOT NULL DEFAULT 'email',
  `verify_token_hash` CHAR(64) NULL DEFAULT NULL,
  `verify_expires` DATETIME NULL DEFAULT NULL,
  `verified_at` DATETIME NULL DEFAULT NULL,
  `reason` VARCHAR(255) NULL DEFAULT NULL,
  `ip_hash` CHAR(64) NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `retention_summary` TEXT NULL DEFAULT NULL,
  `completion_note` VARCHAR(255) NULL DEFAULT NULL,
  `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME NULL DEFAULT NULL,
  `processed_by_admin_id` INT(10) UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_adr_reference` (`reference`),
  KEY `idx_adr_identifier_hash` (`identifier_hash`),
  KEY `idx_adr_status` (`status`),
  KEY `idx_adr_requested_at` (`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- account_deletion_events (append-only audit trail) -----------------------
CREATE TABLE IF NOT EXISTS `account_deletion_events` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT(10) UNSIGNED NOT NULL,
  `event` VARCHAR(40) NOT NULL,
  `detail` VARCHAR(500) NULL DEFAULT NULL,
  `actor` VARCHAR(40) NOT NULL DEFAULT 'system',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ade_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- verification: the tables must exist now --------------------------------
SET @n := (SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME IN ('account_deletion_requests','account_deletion_events'));
SET @msg := CONCAT('VERIFY FAILED: expected 2 account_deletion tables, found ', @n);
SET @s := IF(@n = 2, 'DO 0', CONCAT('SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''', @msg, ''';'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
