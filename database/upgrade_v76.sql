-- ============================================================================
-- Mawatheq Platform — customer engagement features (Execution 72)
-- upgrade_v76.sql
-- ============================================================================
--
-- WHAT THIS ADDS
--
--   Four customer-facing features, each backed by real data rather than a button:
--
--   1) lawyer_favorites            — the customer's saved consultants, per user.
--   2) lawyer_reviews              — verified ratings, one per completed consultation.
--   3) lawyer_availability_alerts  — "notify me when a slot opens", deduped per pair.
--   4) lawyer_contact_requests     — the contact form on a lawyer's profile.
--
--   Plus the columns the new surfaces need:
--
--   * lawyers.languages / offers_remote / offers_in_office / office_address — the
--     consultation modes and the office address the profile shows. `offers_remote`
--     defaults to 1 and `offers_in_office` to 0, so an existing lawyer keeps working
--     remotely (the previous behaviour) and nothing is hidden by the migration.
--   * notification_preferences.channel gains 'push', so the mobile push toggle is an
--     independent channel beside in_app and email.
--
-- SAFE TO REPLAY: every statement is guarded on information_schema (columns) or is
-- CREATE TABLE IF NOT EXISTS. Additive only — no column is dropped, re-typed or
-- re-positioned, and no existing row is rewritten. No FK and no positional AFTER
-- clause (the Execution 28 failure mode).
-- ----------------------------------------------------------------------------

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1) lawyer_favorites — one row per (client, lawyer). The UNIQUE key is what makes
--    "add to favourites" idempotent, so a double tap cannot create a duplicate.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lawyer_favorites` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` INT UNSIGNED NOT NULL,
    `lawyer_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lawyer_favorite` (`client_id`, `lawyer_id`),
    KEY `idx_favorite_lawyer` (`lawyer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2) lawyer_reviews — a verified rating. The UNIQUE key on consultation_id is the
--    anti-fraud rule: a consultation can be rated exactly once, and only a
--    completed consultation is ever inserted here (enforced in the service layer).
--    `status` lets the console hide or remove an abusive review without deleting it.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lawyer_reviews` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lawyer_id` INT UNSIGNED NOT NULL,
    `client_id` INT UNSIGNED NOT NULL,
    `consultation_id` INT UNSIGNED NOT NULL,
    `rating` TINYINT UNSIGNED NOT NULL,
    `comment` TEXT NULL,
    `status` ENUM('published','pending','hidden','removed') NOT NULL DEFAULT 'published',
    `report_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `admin_note` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_review_consultation` (`consultation_id`),
    KEY `idx_review_lawyer` (`lawyer_id`, `status`),
    KEY `idx_review_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3) lawyer_availability_alerts — "notify me when a slot opens". The UNIQUE key on
--    (client, lawyer) is the dedupe rule; cancelling flips the row to 'cancelled'
--    rather than deleting it, and re-subscribing re-activates the same row, so the
--    pair can never hold two rows and no duplicate notification can be sent.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lawyer_availability_alerts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` INT UNSIGNED NOT NULL,
    `lawyer_id` INT UNSIGNED NOT NULL,
    `status` ENUM('active','cancelled','fulfilled') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notified_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_availability_alert` (`client_id`, `lawyer_id`),
    KEY `idx_alert_lawyer` (`lawyer_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4) lawyer_contact_requests — the profile's "contact" button. A request is a
--    stored, authorisation-checked record; the platform never leaks the lawyer's
--    personal email or phone.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lawyer_contact_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` INT UNSIGNED NOT NULL,
    `lawyer_id` INT UNSIGNED NOT NULL,
    `subject` VARCHAR(150) NULL,
    `message` TEXT NOT NULL,
    `status` ENUM('new','read','replied','closed') NOT NULL DEFAULT 'new',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_contact_lawyer` (`lawyer_id`, `status`),
    KEY `idx_contact_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4b) device_tokens — the mobile app registers its push token here. One row per
--     (account, token); re-registering updates `last_seen_at` instead of duplicating.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `device_tokens` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_type` ENUM('client','lawyer') NOT NULL,
    `subject_id` INT UNSIGNED NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `platform` ENUM('android','ios','web') NOT NULL DEFAULT 'android',
    `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device_token` (`token`),
    KEY `idx_device_subject` (`subject_type`, `subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4c) push_deliveries — the outbound push queue. A row is written only when the
--     account has the push channel enabled AND at least one device token, so the
--     preference genuinely gates delivery. A delivery worker drains this table; with
--     no provider configured the rows stay queued (visible, not lost) rather than
--     being silently dropped.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `push_deliveries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_type` ENUM('client','lawyer') NOT NULL,
    `subject_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `body` VARCHAR(500) NULL,
    `link` VARCHAR(255) NULL,
    `status` ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_push_status` (`status`, `created_at`),
    KEY `idx_push_subject` (`subject_type`, `subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5) lawyers — consultation modes, office address and spoken languages.
--    Each column is guarded on itself, so a partially-applied run completes on the
--    next attempt instead of being skipped.
-- ----------------------------------------------------------------------------

-- languages: a short comma-separated list, matching how `specializations` is stored.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lawyers' AND COLUMN_NAME = 'languages'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE lawyers ADD COLUMN languages VARCHAR(255) NULL DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- offers_remote defaults to 1: every existing lawyer already offers remote
-- consultations, so the migration must not hide them from remote search.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lawyers' AND COLUMN_NAME = 'offers_remote'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE lawyers ADD COLUMN offers_remote TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lawyers' AND COLUMN_NAME = 'offers_in_office'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE lawyers ADD COLUMN offers_in_office TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lawyers' AND COLUMN_NAME = 'office_address'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE lawyers ADD COLUMN office_address VARCHAR(255) NULL DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consultations.consultation_mode — the mode the customer actually chose (remote or
-- office). NULL means "not specified", which the UI shows as remote (the previous
-- behaviour), so existing rows keep working without a data rewrite.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultations' AND COLUMN_NAME = 'consultation_mode'
);
SET @sql := IF(@col_exists = 0,
  "ALTER TABLE consultations ADD COLUMN consultation_mode ENUM('remote','office') NULL DEFAULT NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 6) notification_preferences.channel — add 'push' as an independent channel.
--    MODIFY re-states the same definition on replay (idempotent). The values keep
--    their order, so stored rows are not reinterpreted.
-- ----------------------------------------------------------------------------
ALTER TABLE `notification_preferences`
  MODIFY COLUMN `channel` ENUM('in_app','email','push') NOT NULL DEFAULT 'in_app';

-- ----------------------------------------------------------------------------
-- Verification: the four tables must exist now. A migration that reports success
-- on a database that is still broken is worse than no migration (Execution 29.1),
-- so this file fails loudly if a table is missing.
-- ----------------------------------------------------------------------------
SET @missing := (
  SELECT 6 - COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('lawyer_favorites','lawyer_reviews','lawyer_availability_alerts','lawyer_contact_requests','device_tokens','push_deliveries')
);
SET @sql := IF(@missing = 0, 'SELECT 1',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''upgrade_v76: one or more engagement tables are missing''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- Rollback (manual):
--   DROP TABLE IF EXISTS lawyer_contact_requests;
--   DROP TABLE IF EXISTS lawyer_availability_alerts;
--   DROP TABLE IF EXISTS lawyer_reviews;
--   DROP TABLE IF EXISTS lawyer_favorites;
--   ALTER TABLE lawyers DROP COLUMN languages, DROP COLUMN offers_remote,
--     DROP COLUMN offers_in_office, DROP COLUMN office_address;
--   ALTER TABLE notification_preferences MODIFY COLUMN channel ENUM('in_app','email') NOT NULL DEFAULT 'in_app';
-- ----------------------------------------------------------------------------
