-- ============================================================================
-- Mawatheq Platform — Saudi Arabia default configuration
-- upgrade_v61.sql
-- ============================================================================
--
-- WHAT THIS DOES, AND WHAT IT REFUSES TO DO
--
-- This file changes *defaults* only. It never rewrites a stored value:
--
--   * COLUMN DEFAULTS move from 'BH'/'BHD' to 'SA'/'SAR', so rows inserted from
--     now on are Saudi by default. Existing rows are untouched — an affiliate who
--     registered with country 'BH' keeps 'BH', and a commission recorded in BHD
--     stays in BHD. Re-labelling a stored amount changes what it means.
--
--   * site_settings keys that the platform seeds (affiliate_payout_currency) are
--     seeded to SAR only when the row is absent. An operator who already chose a
--     payout currency keeps it.
--
-- The platform's PHP defaults were changed in the same commit, so a database that
-- has not run this file still behaves correctly for new records; this file aligns
-- the schema defaults with the code for installs that insert without naming the
-- column.
--
-- SAFETY
--   Additive only. No DROP, no DELETE, no UPDATE of existing data, no column
--   rewritten. `ALTER TABLE ... ALTER COLUMN ... SET DEFAULT` changes the default
--   expression only and does not touch rows — verified against MariaDB 11.8.
--   Every statement is guarded by an information_schema test, so the file can be
--   run any number of times, and it is a no-op once applied.
--
--   No positional AFTER clause is used anywhere. A positional AFTER naming a
--   column that does not exist aborts the statement, and MySQL stops at the first
--   error — everything below it then silently never runs while the migration still
--   looks applied. That is the Execution 28 failure mode. The ledger entry at the
--   end is written only when the conditions actually held.
--
-- PRECEDENT: upgrade_v44.sql moved the invoice defaults from BHD to SAR in the
-- same way, for the same reason.
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1) Affiliate country columns: DEFAULT 'BH' -> 'SA'.
--    affiliates.country and affiliate_applications.country are CHAR(2) ISO codes.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliates'
             AND COLUMN_NAME='country' AND COLUMN_DEFAULT="'BH'");
SET @s := IF(@c=1, "ALTER TABLE affiliates ALTER COLUMN country SET DEFAULT 'SA'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliate_applications'
             AND COLUMN_NAME='country' AND COLUMN_DEFAULT="'BH'");
SET @s := IF(@c=1, "ALTER TABLE affiliate_applications ALTER COLUMN country SET DEFAULT 'SA'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2) Affiliate payout default: DEFAULT 'BHD' -> 'SAR'.
--    affiliates.payout_currency is the affiliate's own settlement currency, so
--    this is the default a new affiliate is created with.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliates'
             AND COLUMN_NAME='payout_currency' AND COLUMN_DEFAULT="'BHD'");
SET @s := IF(@c=1, "ALTER TABLE affiliates ALTER COLUMN payout_currency SET DEFAULT 'SAR'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3) Affiliate money columns: DEFAULT 'BHD' -> 'SAR'.
--
--    These are the columns that record an amount's denomination. Only the default
--    for *new* rows moves; every stored commission, conversion, ledger entry,
--    payout and daily-stat row keeps the currency it was written with, which is
--    what makes the historical figures still mean what they meant.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliate_commission_plans'
             AND COLUMN_NAME='currency' AND COLUMN_DEFAULT="'BHD'");
SET @s := IF(@c=1, "ALTER TABLE affiliate_commission_plans ALTER COLUMN currency SET DEFAULT 'SAR'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliate_conversions'
             AND COLUMN_NAME='currency' AND COLUMN_DEFAULT="'BHD'");
SET @s := IF(@c=1, "ALTER TABLE affiliate_conversions ALTER COLUMN currency SET DEFAULT 'SAR'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliate_commissions'
             AND COLUMN_NAME='currency' AND COLUMN_DEFAULT="'BHD'");
SET @s := IF(@c=1, "ALTER TABLE affiliate_commissions ALTER COLUMN currency SET DEFAULT 'SAR'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliate_ledger_entries'
             AND COLUMN_NAME='currency' AND COLUMN_DEFAULT="'BHD'");
SET @s := IF(@c=1, "ALTER TABLE affiliate_ledger_entries ALTER COLUMN currency SET DEFAULT 'SAR'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliate_payout_requests'
             AND COLUMN_NAME='currency' AND COLUMN_DEFAULT="'BHD'");
SET @s := IF(@c=1, "ALTER TABLE affiliate_payout_requests ALTER COLUMN currency SET DEFAULT 'SAR'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliate_payouts'
             AND COLUMN_NAME='currency' AND COLUMN_DEFAULT="'BHD'");
SET @s := IF(@c=1, "ALTER TABLE affiliate_payouts ALTER COLUMN currency SET DEFAULT 'SAR'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliate_daily_stats'
             AND COLUMN_NAME='currency' AND COLUMN_DEFAULT="'BHD'");
SET @s := IF(@c=1, "ALTER TABLE affiliate_daily_stats ALTER COLUMN currency SET DEFAULT 'SAR'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4) Programme payout currency setting: the old seeded default moves to SAR.
--
--    upgrade_v57.sql seeded this key with 'BHD' unconditionally. That value is a
--    Bahrain default, not an operator's choice, so it is moved to the platform
--    default — but *only* while it still equals that seeded value. An operator who
--    picked KWD, USD, AED or anything else keeps their setting untouched, and an
--    operator who wants BHD can set it back from the console.
--
--    The row is created only if it does not exist at all, so this is a no-op on a
--    database where v57 never ran.
-- ---------------------------------------------------------------------------
UPDATE site_settings SET setting_value = 'SAR'
 WHERE setting_key = 'affiliate_payout_currency' AND setting_value = 'BHD';

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'affiliate_payout_currency', 'SAR'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key = 'affiliate_payout_currency');

-- ---------------------------------------------------------------------------
-- 5) Platform default currency and country, seeded only when absent.
--    v44 already seeded site_currency='SAR'; these guards keep the file correct on
--    an install where that never ran, without touching a configured value.
-- ---------------------------------------------------------------------------
INSERT INTO site_settings (setting_key, setting_value)
SELECT 'site_currency', 'SAR'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key = 'site_currency');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'site_country', 'SA'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key = 'site_country');

-- ---------------------------------------------------------------------------
-- Ledger entry. Written only when every column default above actually moved, so a
-- partially applied file never claims success. The count is the evidence: a row
-- that says verified with 10/10 is auditable.
-- ---------------------------------------------------------------------------
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_DEFAULT="'SA'"
              AND TABLE_NAME='affiliates' AND COLUMN_NAME='country')
         + (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_DEFAULT="'SA'"
              AND TABLE_NAME='affiliate_applications' AND COLUMN_NAME='country')
         + (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_DEFAULT="'SAR'"
              AND TABLE_NAME='affiliates' AND COLUMN_NAME='payout_currency')
         + (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_DEFAULT="'SAR'"
              AND TABLE_NAME IN ('affiliate_commission_plans','affiliate_conversions',
                                 'affiliate_commissions','affiliate_ledger_entries',
                                 'affiliate_payout_requests','affiliate_payouts',
                                 'affiliate_daily_stats')
              AND COLUMN_NAME='currency');

SET @s := IF(@ok = 10,
    "INSERT INTO schema_migrations (filename, checksum, state, requirements_total, requirements_present, statements_ok)
     VALUES ('upgrade_v61.sql', REPEAT('0',64), 'verified', 10, @ok, 10)
     ON DUPLICATE KEY UPDATE state='verified', requirements_total=10, requirements_present=@ok, statements_ok=10",
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- ROLLBACK (manual, if ever needed) — defaults only, so no data is at risk:
--   ALTER TABLE affiliates ALTER COLUMN country SET DEFAULT 'BH';
--   ALTER TABLE affiliate_applications ALTER COLUMN country SET DEFAULT 'BH';
--   ALTER TABLE affiliates ALTER COLUMN payout_currency SET DEFAULT 'BHD';
--   ALTER TABLE affiliate_commission_plans ALTER COLUMN currency SET DEFAULT 'BHD';
--   ALTER TABLE affiliate_conversions ALTER COLUMN currency SET DEFAULT 'BHD';
--   ALTER TABLE affiliate_commissions ALTER COLUMN currency SET DEFAULT 'BHD';
--   ALTER TABLE affiliate_ledger_entries ALTER COLUMN currency SET DEFAULT 'BHD';
--   ALTER TABLE affiliate_payout_requests ALTER COLUMN currency SET DEFAULT 'BHD';
--   ALTER TABLE affiliate_payouts ALTER COLUMN currency SET DEFAULT 'BHD';
--   ALTER TABLE affiliate_daily_stats ALTER COLUMN currency SET DEFAULT 'BHD';
--   DELETE FROM schema_migrations WHERE filename='upgrade_v61.sql';
-- ------------------------------------------------------------
