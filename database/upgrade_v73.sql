-- ============================================================================
-- مواثيق — فاتورة الاستشارة المدفوعة (Execution 68)
-- MAWATHEQ — a paid consultation issues a real invoice
-- upgrade_v73.sql
-- ============================================================================
--
-- WHY
--
-- A paid booking (consultation) collected money but produced no invoice. The client
-- could see the charge in the gateway and in their consultation, yet /invoices.php —
-- the client's own financial record — was empty, so there was nothing to download,
-- print, or reconcile. A "professional booking system" (the Execution 68 brief) must
-- issue a document for every captured payment.
--
-- This migration adds the optional link that lets a consultation invoice be created
-- and found without a second lookup:
--   invoices.consultation_id — the booking a generated invoice settles (NULL for every
--   existing invoice, which are all lawyer-issued and unrelated to a booking).
--
-- SAFE TO REPLAY: guarded ADD COLUMN + guarded ADD INDEX, additive only, no FK. The
-- guard is on the column itself (not a sibling), so a partially-applied run completes
-- on the next attempt instead of being skipped.
-- ----------------------------------------------------------------------------

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'consultation_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE invoices ADD COLUMN consultation_id INT UNSIGNED NULL DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND INDEX_NAME = 'idx_invoices_consultation'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE invoices ADD INDEX idx_invoices_consultation (consultation_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
