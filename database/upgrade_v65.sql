-- ============================================================================
-- مواثيق — حذف الحساب (Execution 52) — upgrade_v65.sql
-- MAWATHEQ — account deletion support
-- ============================================================================
--
-- Additive and idempotent: safe to replay, and safe on a database that already
-- has the columns. Every statement is guarded on information_schema, and no
-- statement uses a positional AFTER clause (a missing sibling column would abort
-- the rest of the file — see the Execution 29 note in AGENTS.md).
--
-- WHY
--   Google Play requires an in-app account-deletion route, and privacy.php
--   promises one. includes/account_deletion.php anonymises the account instead of
--   hard-deleting it, because every foreign key on invoices/cases/consultations is
--   ON DELETE CASCADE and a hard delete would take the other party's records with
--   it. `deleted_at` records when that happened.
-- ============================================================================

SET NAMES utf8mb4;

-- --- users.deleted_at --------------------------------------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='deleted_at') = 0,
  'ALTER TABLE `users` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL',
  'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- --- lawyers.deleted_at ------------------------------------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='deleted_at') = 0,
  'ALTER TABLE `lawyers` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL',
  'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- --- index for the support view: "which accounts have been deleted" ----------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='idx_users_deleted_at') = 0,
  'ALTER TABLE `users` ADD INDEX `idx_users_deleted_at` (`deleted_at`)',
  'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lawyers' AND INDEX_NAME='idx_lawyers_deleted_at') = 0,
  'ALTER TABLE `lawyers` ADD INDEX `idx_lawyers_deleted_at` (`deleted_at`)',
  'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- --- verification: the columns must exist now --------------------------------
SET @n := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE()
    AND ((TABLE_NAME='users' AND COLUMN_NAME='deleted_at')
      OR (TABLE_NAME='lawyers' AND COLUMN_NAME='deleted_at')));
SET @msg := CONCAT('VERIFY FAILED: expected 2 deleted_at columns, found ', @n);
SET @s := IF(@n = 2, 'DO 0', CONCAT('SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''', @msg, ''';'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
