-- Execution 69 — v82: the columns the plan catalogue actually measures.
--
-- `includes/plan_features.php` enforces `storage_mb`, and it can only do that if a
-- document records how large it is. `documents` never stored a size (the attachment
-- tables did, `documents` did not), so the quota would have been unmeasurable and the
-- gate would have silently passed every upload — the exact shape of "a check that
-- cannot fail".
--
-- Entitlement was also client-only: `plans` was reachable through `users.plan_id`, and
-- nothing on the lawyer side held a plan at all. A firm workspace or a solo lawyer
-- therefore had no tier to enforce, which is why `max_staff`, `max_active_cases` and
-- the `feature_*` flags were unreadable rather than merely unread.
--
-- Every statement is guarded on itself and appended positionally: no positional AFTER
-- (it aborts the whole file on a database missing the named column) and no
-- multi-column ALTER guarded on one column.

SET @db := DATABASE();

-- documents.file_size — bytes, nullable so existing rows stay valid. A default of 0
-- would silently under-count; NULL means "not yet measured" and counts as zero until
-- tools/backfill_document_sizes.php fills it in.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=@db AND TABLE_NAME='documents' AND COLUMN_NAME='file_size');
SET @sql := IF(@has=0,
  'ALTER TABLE `documents` ADD COLUMN `file_size` BIGINT UNSIGNED NULL DEFAULT NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- law_firms.plan_id — the firm's package. No FK: deleting a plan must not cascade a
-- firm's tier away, and the admin console refuses to delete a plan that is in use.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=@db AND TABLE_NAME='law_firms' AND COLUMN_NAME='plan_id');
SET @sql := IF(@has=0,
  'ALTER TABLE `law_firms` ADD COLUMN `plan_id` INT UNSIGNED NULL DEFAULT NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- lawyers.plan_id — a solo lawyer's package, for accounts with no firm.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=@db AND TABLE_NAME='lawyers' AND COLUMN_NAME='plan_id');
SET @sql := IF(@has=0,
  'ALTER TABLE `lawyers` ADD COLUMN `plan_id` INT UNSIGNED NULL DEFAULT NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=@db AND TABLE_NAME='law_firms' AND INDEX_NAME='idx_law_firms_plan');
SET @sql := IF(@has=0,
  'ALTER TABLE `law_firms` ADD INDEX `idx_law_firms_plan` (`plan_id`)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=@db AND TABLE_NAME='lawyers' AND INDEX_NAME='idx_lawyers_plan');
SET @sql := IF(@has=0,
  'ALTER TABLE `lawyers` ADD INDEX `idx_lawyers_plan` (`plan_id`)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- A firm with no plan of its own inherits its owner's plan, so it is not suddenly
-- unlimited after this migration. Existing non-NULL values are never overwritten.
UPDATE `law_firms` f
  JOIN `lawyers` l ON l.id = f.owner_lawyer_id
  SET f.plan_id = l.plan_id
  WHERE f.plan_id IS NULL AND l.plan_id IS NOT NULL;
