-- ============================================================================
-- مواثيق — دفع الاستشارات عبر N-Genius (Execution 62)
-- MAWATHEQ — N-Genius payment for consultations
-- upgrade_v69.sql
-- ============================================================================
--
-- WHAT THIS ADDS
--
--   A consultation can now be paid for before it is confirmed. Invoices already
--   have a payment path (pay-invoice.php → gateway → payment-return.php →
--   finance_record_payment); a consultation is not an invoice, so it needs its
--   own order reference and its own payment state.
--
--     1) consultations.payment_status  ENUM('unpaid','pending','paid','refunded','failed')
--        A consultation is only confirmed after the gateway independently
--        confirms the capture. 'unpaid' is the default so every existing row —
--        and every free booking — keeps working unchanged.
--     2) consultations.payment_reference VARCHAR(100)
--        The gateway order reference. Unique when present, so one order can never
--        confirm two consultations and a replayed callback cannot confirm twice.
--     3) consultations.payment_amount  DECIMAL(12,3)
--        The amount actually charged, kept separately from `price` (the lawyer's
--        listed rate) so a discount or partial payment is visible.
--     4) consultations.payment_currency VARCHAR(3)
--        The currency the order was created in. An invoice carries its own
--        currency for the same reason: an old order must stay readable after the
--        site currency changes.
--     5) consultations.payment_gateway VARCHAR(30)
--        Which provider settled it, matching payment_transactions.gateway.
--     6) payment_transactions.consultation_id
--        Lets one transaction log hold both invoice and consultation payments,
--        so the admin history and reconciliation reports are a single view.
--
-- WHY NOT REUSE `price`
--
--   `price` is the lawyer's advertised rate and may be edited after the fact.
--   `payment_amount` is the money that actually moved. Overloading one column
--   would make a later rate change silently rewrite a settled payment.
--
-- BACKWARD COMPATIBILITY
--
--   Every column is nullable (or has a safe default) and no existing row is
--   rewritten: `payment_status` defaults to 'unpaid', which is exactly the state
--   of every consultation booked before this migration. The free-booking path
--   (a lawyer with no rate) is untouched. No production value is lost.
--
-- IDEMPOTENT AND ADDITIVE
--
--   Every statement is guarded on information_schema, so replaying the file is a
--   no-op. No positional `AFTER` clause is used: a missing sibling column would
--   abort the rest of the file and make a partial run look applied (see the
--   Execution 29.1 note in AGENTS.md).
-- ============================================================================

SET NAMES utf8mb4;

-- --- consultations.payment_status -------------------------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND COLUMN_NAME='payment_status') = 0,
  "ALTER TABLE `consultations` ADD COLUMN `payment_status` ENUM('unpaid','pending','paid','refunded','failed') NOT NULL DEFAULT 'unpaid'",
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- consultations.payment_reference ----------------------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND COLUMN_NAME='payment_reference') = 0,
  'ALTER TABLE `consultations` ADD COLUMN `payment_reference` VARCHAR(100) NULL DEFAULT NULL',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- consultations.payment_amount -------------------------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND COLUMN_NAME='payment_amount') = 0,
  'ALTER TABLE `consultations` ADD COLUMN `payment_amount` DECIMAL(12,3) NULL DEFAULT NULL',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- consultations.payment_currency -----------------------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND COLUMN_NAME='payment_currency') = 0,
  'ALTER TABLE `consultations` ADD COLUMN `payment_currency` VARCHAR(3) NULL DEFAULT NULL',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- consultations.payment_gateway ------------------------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND COLUMN_NAME='payment_gateway') = 0,
  'ALTER TABLE `consultations` ADD COLUMN `payment_gateway` VARCHAR(30) NULL DEFAULT NULL',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- unique index on the order reference ------------------------------------
-- Guarded on itself. A NULL reference (every unpaid consultation) does not
-- collide, so the index is safe to add over existing data.
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND INDEX_NAME='uniq_consultation_payment_ref') = 0,
  'ALTER TABLE `consultations` ADD UNIQUE KEY `uniq_consultation_payment_ref` (`payment_reference`)',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- payment_transactions.consultation_id -----------------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='consultation_id') = 0,
  'ALTER TABLE `payment_transactions` ADD COLUMN `consultation_id` INT UNSIGNED NULL DEFAULT NULL',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- index for the consultation payment history -----------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND INDEX_NAME='idx_payment_tx_consultation') = 0,
  'ALTER TABLE `payment_transactions` ADD INDEX `idx_payment_tx_consultation` (`consultation_id`)',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- wallet_transactions.consultation_id ------------------------------------
-- The wallet ledger already traces an invoice credit to its invoice_id; a
-- consultation credit needs the same provenance or it cannot be reconciled.
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wallet_transactions' AND COLUMN_NAME='consultation_id') = 0,
  'ALTER TABLE `wallet_transactions` ADD COLUMN `consultation_id` INT UNSIGNED NULL DEFAULT NULL',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wallet_transactions' AND INDEX_NAME='idx_wallet_tx_consultation') = 0,
  'ALTER TABLE `wallet_transactions` ADD INDEX `idx_wallet_tx_consultation` (`consultation_id`)',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- backfill: a consultation that already has a price is still 'unpaid' -----
-- Nothing to do — the DEFAULT 'unpaid' is the correct state for every existing
-- row, and inferring "paid" from the presence of a price would be a guess. This
-- statement is intentionally a no-op so a reader does not wonder whether a
-- backfill was forgotten.
SELECT 1;
