-- ============================================================
-- Mawatheq Platform — Currency control (Phase 4 follow-up)
-- Upgrade v44
-- ------------------------------------------------------------
-- SAFETY: additive only. Never drops or rewrites a column, and every
-- step is idempotent so it can be re-run safely.
--
-- What it does:
--   1) Seeds site_settings.site_currency = 'SAR' (the platform default).
--   2) Moves the invoice currency DEFAULT from 'BHD' to 'SAR' for NEW
--      invoices only. Existing rows keep their stored currency, because
--      their amounts were entered in that currency — re-labelling them
--      would change what they mean.
--   3) Seeds site_settings.ngenius_currency = 'SAR' (the outlet's settlement
--      currency) without overwriting a value the operator already set.
--
-- Note: invoices and invoice_payments store amounts as DECIMAL(12,3),
-- which already covers 2-decimal currencies. No precision change needed.
-- ============================================================

SET NAMES utf8mb4;

-- 1) Default display currency for the whole site.
INSERT INTO site_settings (setting_key, setting_value)
VALUES ('site_currency', 'SAR')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- 2) New invoices are denominated in the platform default.
ALTER TABLE invoices MODIFY COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'SAR';
ALTER TABLE invoice_payments MODIFY COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'SAR';

-- 3) Payment gateway settlement currency (only if not already configured).
INSERT INTO site_settings (setting_key, setting_value)
VALUES ('ngenius_currency', 'SAR')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- ------------------------------------------------------------
-- ROLLBACK (manual, if ever needed):
--   ALTER TABLE invoices MODIFY COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'BHD';
--   ALTER TABLE invoice_payments MODIFY COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'BHD';
--   DELETE FROM site_settings WHERE setting_key = 'site_currency';
--   DELETE FROM site_settings WHERE setting_key = 'ngenius_currency';
-- ------------------------------------------------------------
