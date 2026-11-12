-- ============================================================================
-- Mawatheq Platform — multiple legal specializations per lawyer (Execution 69)
-- upgrade_v74.sql
-- ============================================================================
--
-- WHAT THIS ADDS
--
--   lawyers.specializations — a lawyer may practise more than one area of law, so a
--   lawyer now selects several specializations instead of one. The column stores the
--   chosen values joined by " - " (the same separator the legacy single column used),
--   in the canonical order of `specialization_options()`.
--
--   The existing `lawyers.specialization` column is KEPT and left as-is. It is the
--   fallback every reader still uses when the new column is empty, so a row written
--   before this migration keeps its single specialization and stays discoverable.
--   Nothing is rewritten, dropped or re-typed.
--
--   The customer-journey matching (`includes/legal_journey.php`) already reads the
--   link table `lawyer_specialties` and falls back to a declared value; it now also
--   consults `specializations`, so a lawyer with several declared areas is matched
--   under each of them without any extra operator step.
--
-- SAFE TO REPLAY: guarded ADD COLUMN, additive only, no FK, no data rewrite. The guard
-- is on the column itself, so a partially-applied run completes on the next attempt
-- instead of being skipped. No positional AFTER clause (Execution 28 failure mode).
-- ----------------------------------------------------------------------------

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lawyers' AND COLUMN_NAME = 'specializations'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE lawyers ADD COLUMN specializations VARCHAR(500) NULL DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- Rollback (manual):
--   ALTER TABLE lawyers DROP COLUMN specializations;
-- ----------------------------------------------------------------------------
