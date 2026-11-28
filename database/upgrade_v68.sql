-- ============================================================================
-- مواثيق — دولة ومدينة المحامي (Execution 60)
-- MAWATHEQ — lawyer country + city, and the booking filters that read them
-- upgrade_v68.sql
-- ============================================================================
--
-- WHAT THIS ADDS
--
--   1) lawyers.country_code  CHAR(2)      — the ISO country the lawyer practises in
--   2) lawyers.city          VARCHAR(100) — the city inside that country
--
-- WHY TWO COLUMNS AND NOT jurisdiction ALONE
--
--   `lawyers.jurisdiction` (upgrade_v12.sql) is a localised country *name*
--   ('السعودية'), used for display and for the legacy journey filter. A city cannot
--   be validated against a name-only column, and a name is not a stable key: the
--   city list is keyed by ISO code, so the same value must be stored in both
--   columns. `country_code` is the key the filters join on; `jurisdiction` stays
--   as the display value so every existing reader keeps working. Nothing here
--   changes or drops `jurisdiction`.
--
-- BACKWARD COMPATIBILITY
--
--   Both columns are nullable and the backfill only fills `country_code` from the
--   jurisdiction the row already declares. A lawyer whose jurisdiction is not one
--   of the known names keeps `country_code = NULL` — an honest "unknown" rather
--   than a guessed country. `city` stays NULL until an operator (or the lawyer)
--   sets it, so no existing record is rewritten and no production value is lost.
--
-- IDEMPOTENT AND ADDITIVE
--
--   Every statement is guarded on information_schema, so replaying the file is a
--   no-op. No positional `AFTER` clause is used: a missing sibling column would
--   abort the rest of the file and make a partial run look applied (see the
--   Execution 29.1 note in AGENTS.md).
-- ============================================================================

SET NAMES utf8mb4;

-- --- lawyers.country_code ----------------------------------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='country_code') = 0,
  'ALTER TABLE `lawyers` ADD COLUMN `country_code` CHAR(2) NULL DEFAULT NULL',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- lawyers.city ------------------------------------------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='city') = 0,
  'ALTER TABLE `lawyers` ADD COLUMN `city` VARCHAR(100) NULL DEFAULT NULL',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- backfill country_code from the jurisdiction the row already declares ----
-- Only rows that still have no country are touched, so an operator's explicit
-- choice is never overwritten by a re-run.
UPDATE `lawyers`
   SET `country_code` = CASE `jurisdiction`
        WHEN 'السعودية' THEN 'SA'
        WHEN 'البحرين'  THEN 'BH'
        WHEN 'الإمارات' THEN 'AE'
        WHEN 'الكويت'   THEN 'KW'
        WHEN 'قطر'      THEN 'QA'
        WHEN 'عُمان'    THEN 'OM'
        WHEN 'مصر'      THEN 'EG'
        WHEN 'الأردن'   THEN 'JO'
        ELSE NULL END
 WHERE `country_code` IS NULL
   AND `jurisdiction` IN ('السعودية','البحرين','الإمارات','الكويت','قطر','عُمان','مصر','الأردن');
