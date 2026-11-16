-- ============================================================================
-- مواثيق — سياسة إعادة نشر المكتبة القانونية (Execution 68) — upgrade_v79.sql
-- MAWATHEQ — legal-library redistribution policy
-- ============================================================================
--
-- Additive and idempotent: safe to replay. No statement uses a positional AFTER
-- clause (a missing sibling column would abort the rest of the file — the lesson
-- of Execution 29). Every guard is on information_schema.
--
-- WHY
--   Execution 74 classified the two Ministry of Justice sources as 'hosted',
--   meaning Mawatheq would store the ruling/statute text and serve it from its own
--   domain. The MOJ portal does publish judgments and statutes for public reading,
--   but "publicly readable" is NOT the same as "licensed for re-publication".
--   No explicit re-use licence could be confirmed for the MOJ portal or its gateway
--   from the build environment, so the honest default is link_only: keep the
--   metadata and the official URL, and send the reader to the Ministry of Justice
--   record. This is reversible: an operator who has confirmed the terms in writing
--   can switch a source back to 'hosted' in /admin/legal-library.php and set
--   MAWATHEQ_LL_CAPTURE_BODY=1 to let the importer store the text again.
--
--   A source whose redistribution is downgraded must also drop any full text and
--   hosted file already stored, so this migration clears `body` and `hosted_path`
--   for every item of a downgraded source. Metadata, the official link and every
--   other column are untouched.
-- ============================================================================

SET NAMES utf8mb4;

-- A resumable cursor for the MOJ decisions catalogue. The gateway is behind a WAF
-- that refuses a request burst, so the full ~55k catalogue is walked over many cron
-- runs: each run fetches a bounded number of pages from the current Hijri-year
-- partition and saves where it stopped here. Guarded on information_schema so the
-- file is safe to replay.
SET @has_cursor := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_sources' AND COLUMN_NAME = 'sync_cursor'
);
SET @sql_cursor := IF(@has_cursor = 0,
  'ALTER TABLE `legal_library_sources` ADD COLUMN `sync_cursor` VARCHAR(120) NULL DEFAULT NULL',
  'DO 0');
PREPARE stmt_cursor FROM @sql_cursor; EXECUTE stmt_cursor; DEALLOCATE PREPARE stmt_cursor;

-- Downgrade the MOJ sources to link_only while they still claim 'hosted'.
UPDATE `legal_library_sources`
   SET `redistribution` = 'link_only',
       `updated_at` = NOW()
 WHERE `source_key` IN ('moj_decisions', 'moj_legislation')
   AND `redistribution` = 'hosted';

-- Clear stored full text / hosted file for the downgraded sources, so the
-- catalogue cannot keep serving a copy the source's terms do not allow.
UPDATE `legal_library_items`
   SET `body` = NULL,
       `hosted_path` = NULL,
       `redistribution` = 'link_only'
 WHERE `source_key` IN ('moj_decisions', 'moj_legislation')
   AND (`body` IS NOT NULL OR `hosted_path` IS NOT NULL OR `redistribution` = 'hosted');
