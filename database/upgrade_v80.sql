-- ============================================================================
-- مواثيق — حقول تفاصيل الحكم الحقيقية والبحث العربي المُطبَّع (Execution 68)
-- MAWATHEQ — real judgement detail fields + Arabic-normalised search
-- upgrade_v80.sql
-- ============================================================================
--
-- Additive and idempotent: safe to replay. No statement uses a positional AFTER
-- clause (a missing sibling column would abort the rest of the file — the lesson
-- of Execution 29). Every guard is on information_schema.
--
-- WHY
--   The MOJ judgements LIST endpoint carries no title: only judgementNumber,
--   judgementDate, courtName, city, hijriYear. The real title, the facts, the
--   reasons and the ruling text live on the DETAIL endpoint. Execution 68 taught
--   the importer to fetch the detail record, so the catalogue can show the
--   source's own title instead of a derived one. These columns hold that detail.
--
--   `title_is_derived` marks a row whose title is a clear, honest description
--   built from the official fields ("حكم رقم N — Court") because the source
--   published no independent title. It is never presented as the source's title.
--
--   `title_norm` / `summary_norm` hold the Arabic-normalised form (hamza, alef,
--   yaa, taa marbuta, tatweel and diacritics folded) so search matches the way an
--   Arabic reader types, without altering the stored original text.
-- ============================================================================

SET NAMES utf8mb4;

-- --- legal_library_items: real detail fields ---------------------------------

SET @add := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_items' AND COLUMN_NAME = 'decision_title'
);
SET @sql := IF(@add = 0,
  'ALTER TABLE `legal_library_items` ADD COLUMN `decision_title` VARCHAR(400) NULL DEFAULT NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_items' AND COLUMN_NAME = 'title_is_derived'
);
SET @sql := IF(@add = 0,
  'ALTER TABLE `legal_library_items` ADD COLUMN `title_is_derived` TINYINT(1) NOT NULL DEFAULT 0',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_items' AND COLUMN_NAME = 'judgment_facts'
);
SET @sql := IF(@add = 0,
  'ALTER TABLE `legal_library_items` ADD COLUMN `judgment_facts` LONGTEXT NULL DEFAULT NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_items' AND COLUMN_NAME = 'judgment_reasons'
);
SET @sql := IF(@add = 0,
  'ALTER TABLE `legal_library_items` ADD COLUMN `judgment_reasons` LONGTEXT NULL DEFAULT NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_items' AND COLUMN_NAME = 'judgment_ruling'
);
SET @sql = IF(@add = 0,
  'ALTER TABLE `legal_library_items` ADD COLUMN `judgment_ruling` LONGTEXT NULL DEFAULT NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_items' AND COLUMN_NAME = 'judgment_narration'
);
SET @sql = IF(@add = 0,
  'ALTER TABLE `legal_library_items` ADD COLUMN `judgment_narration` LONGTEXT NULL DEFAULT NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_items' AND COLUMN_NAME = 'detail_fetched_at'
);
SET @sql = IF(@add = 0,
  'ALTER TABLE `legal_library_items` ADD COLUMN `detail_fetched_at` DATETIME NULL DEFAULT NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_items' AND COLUMN_NAME = 'imported_at'
);
SET @sql = IF(@add = 0,
  'ALTER TABLE `legal_library_items` ADD COLUMN `imported_at` DATETIME NULL DEFAULT NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_items' AND COLUMN_NAME = 'hijri_date'
);
SET @sql := IF(@add = 0,
  'ALTER TABLE `legal_library_items` ADD COLUMN `hijri_date` VARCHAR(20) NULL DEFAULT NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- --- Arabic-normalised search keys ------------------------------------------

SET @add := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_items' AND COLUMN_NAME = 'title_norm'
);
SET @sql = IF(@add = 0,
  'ALTER TABLE `legal_library_items` ADD COLUMN `title_norm` VARCHAR(400) NULL DEFAULT NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_items' AND COLUMN_NAME = 'summary_norm'
);
SET @sql = IF(@add = 0,
  'ALTER TABLE `legal_library_items` ADD COLUMN `summary_norm` TEXT NULL DEFAULT NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @hasidx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_items' AND INDEX_NAME = 'idx_ll_title_norm'
);
SET @sql := IF(@hasidx = 0,
  'ALTER TABLE `legal_library_items` ADD INDEX `idx_ll_title_norm` (`title_norm`)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- An item whose detail was never fetched is what the enrichment pass picks up.
SET @hasidx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_items' AND INDEX_NAME = 'idx_ll_detail_pending'
);
SET @sql := IF(@hasidx = 0,
  'ALTER TABLE `legal_library_items` ADD INDEX `idx_ll_detail_pending` (`source_key`, `detail_fetched_at`)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill the import stamp for rows that predate this column.
UPDATE `legal_library_items` SET `imported_at` = `created_at` WHERE `imported_at` IS NULL;

-- Backfill the normalised title for existing rows with a MariaDB expression, so a
-- re-run does not depend on the PHP normaliser having run. REGEXP_REPLACE keeps the
-- folding identical for alef/hamza/yaa/taa-marbuta/tatweel; diacritics are removed
-- by the character class.
UPDATE `legal_library_items`
   SET `title_norm` = LOWER(
         REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
         `title`,
         'أ','ا'),'إ','ا'),'آ','ا'),'ٱ','ا'),'ى','ي'),'ئ','ي'),'ة','ه'),'ـ','')
       )
 WHERE `title_norm` IS NULL AND `title` IS NOT NULL;

UPDATE `legal_library_items`
   SET `summary_norm` = LOWER(
         REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
         `summary`,
         'أ','ا'),'إ','ا'),'آ','ا'),'ٱ','ا'),'ى','ي'),'ئ','ي'),'ة','ه'),'ـ','')
       )
 WHERE `summary_norm` IS NULL AND `summary` IS NOT NULL;
