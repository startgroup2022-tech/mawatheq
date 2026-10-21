-- ============================================================================
-- MAWATHEQ — DB VERIFY
-- MAWATHEQ_DB_VERIFY.sql
-- ============================================================================
--
-- READ-ONLY. This file writes nothing: no INSERT, no UPDATE, no DELETE, no
-- ALTER, no CREATE. It is safe to run on production at any time, including
-- while the site is serving traffic.
--
-- HOW TO RUN
--   phpMyAdmin: select the database, open the SQL tab, paste this file, press Go.
--   Shell:      mysql -u USER -p DBNAME < MAWATHEQ_DB_VERIFY.sql
--
-- HOW TO READ THE OUTPUT
--   Every row is (check_name, result, expected). `result` is 'OK' when the
--   database is in the state the migration should have left it in. Anything else
--   is a real finding and the row says which one. Run this after
--   MAWATHEQ_DB_SCHEMA_FIX.sql and MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1) The defect this release fixes: is the ledger column present at all?
--    Before the fix this returns MISSING, which is the cause of
--    #1054 Unknown column 'statements_ok' in 'INSERT INTO'.
-- ----------------------------------------------------------------------------
SELECT '1. schema_migrations.statements_ok exists' AS check_name,
       IF(COUNT(*)=1, 'OK', 'MISSING — run MAWATHEQ_DB_SCHEMA_FIX.sql') AS result,
       'present' AS expected
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'
   AND COLUMN_NAME='statements_ok';

-- ----------------------------------------------------------------------------
-- 2) Are all seven classification tables present?
-- ----------------------------------------------------------------------------
SELECT '2. najiz_* tables present' AS check_name,
       CONCAT(COUNT(*), ' of 7') AS result,
       '7 of 7' AS expected
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
       ('najiz_categories','najiz_subcategories','najiz_case_types',
        'najiz_judicial_requests','najiz_search_synonyms','najiz_import_runs','najiz_audit_log');

-- ----------------------------------------------------------------------------
-- 3) Row counts against the counts the source document declares.
--    These are the numbers the import must produce. A lower number means the
--    import did not finish; re-running it is safe and will complete the rest.
-- ----------------------------------------------------------------------------
SELECT '3. categories imported' AS check_name,
       CAST(COUNT(*) AS CHAR) AS result, '7' AS expected FROM najiz_categories
UNION ALL
SELECT '3. subcategories imported', CAST(COUNT(*) AS CHAR), '38' FROM najiz_subcategories
UNION ALL
SELECT '3. lawsuit types imported', CAST(COUNT(*) AS CHAR), '234' FROM najiz_case_types
UNION ALL
SELECT '3. judicial requests imported', CAST(COUNT(*) AS CHAR), '640' FROM najiz_judicial_requests;

-- ----------------------------------------------------------------------------
-- 4) Referential integrity. An orphan here means a parent row is missing, which
--    is what a partially applied import used to leave behind.
-- ----------------------------------------------------------------------------
SELECT '4. subcategories with a valid category' AS check_name,
       IF(COUNT(*)=0, 'OK', CONCAT(COUNT(*), ' orphans')) AS result,
       '0 orphans' AS expected
  FROM najiz_subcategories s
  LEFT JOIN najiz_categories c ON c.id = s.category_id
 WHERE c.id IS NULL
UNION ALL
SELECT '4. case types with a valid subcategory',
       IF(COUNT(*)=0, 'OK', CONCAT(COUNT(*), ' orphans')),
       '0 orphans'
  FROM najiz_case_types t
  LEFT JOIN najiz_subcategories s ON s.id = t.subcategory_id
 WHERE s.id IS NULL
UNION ALL
SELECT '4. judicial requests with a valid case type',
       IF(COUNT(*)=0, 'OK', CONCAT(COUNT(*), ' orphans')),
       '0 orphans'
  FROM najiz_judicial_requests r
  LEFT JOIN najiz_case_types t ON t.id = r.case_type_id
 WHERE t.id IS NULL;

-- ----------------------------------------------------------------------------
-- 5) Duplicate detection. Every row is addressed by a deterministic key, so a
--    duplicate key here means the unique index is missing rather than that the
--    data is wrong.
-- ----------------------------------------------------------------------------
SELECT '5. duplicate subcategory source_key' AS check_name,
       IF(COUNT(*)=0, 'OK', CONCAT(COUNT(*), ' duplicated keys')) AS result,
       '0' AS expected
  FROM (SELECT source_key FROM najiz_subcategories GROUP BY source_key HAVING COUNT(*)>1) d
UNION ALL
SELECT '5. duplicate case type source_key',
       IF(COUNT(*)=0, 'OK', CONCAT(COUNT(*), ' duplicated keys')),
       '0'
  FROM (SELECT source_key FROM najiz_case_types GROUP BY source_key HAVING COUNT(*)>1) d
UNION ALL
SELECT '5. duplicate request source_key',
       IF(COUNT(*)=0, 'OK', CONCAT(COUNT(*), ' duplicated keys')),
       '0'
  FROM (SELECT source_key FROM najiz_judicial_requests GROUP BY source_key HAVING COUNT(*)>1) d
UNION ALL
SELECT '5. duplicate category name',
       IF(COUNT(*)=0, 'OK', CONCAT(COUNT(*), ' duplicated names')),
       '0'
  FROM (SELECT name_ar FROM najiz_categories GROUP BY name_ar HAVING COUNT(*)>1) d;

-- ----------------------------------------------------------------------------
-- 6) Journey readiness. The customer app lists specialties from
--    legal_journey_specialties(), which reads customer_desc_ar and
--    customer_icon_key. A null here is a card that renders without a
--    description or an icon.
-- ----------------------------------------------------------------------------
SELECT '6. categories with a customer description' AS check_name,
       CONCAT(SUM(customer_desc_ar IS NOT NULL AND customer_desc_ar <> ''), ' of ', COUNT(*)) AS result,
       '7 of 7' AS expected
  FROM najiz_categories
UNION ALL
SELECT '6. categories with a customer icon',
       CONCAT(SUM(customer_icon_key IS NOT NULL AND customer_icon_key <> ''), ' of ', COUNT(*)),
       '7 of 7'
  FROM najiz_categories;

-- ----------------------------------------------------------------------------
-- 7) Consultations can reference a specialty (added by upgrade_v63.sql).
-- ----------------------------------------------------------------------------
SELECT '7. consultations.specialty_category_id exists' AS check_name,
       IF(COUNT(*)=1, 'OK', 'MISSING — run MAWATHEQ_DB_SCHEMA_FIX.sql') AS result,
       'present' AS expected
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'
   AND COLUMN_NAME='specialty_category_id';

-- ----------------------------------------------------------------------------
-- 8) Migration ledger: did the files that failed before now record as applied?
--    `statements_ok` must equal `requirements_total` for a genuinely complete
--    file. A row where it does not is a file that stopped partway.
-- ----------------------------------------------------------------------------
SELECT CONCAT('8. ', filename, ' recorded') AS check_name,
       CONCAT('state=', state, ' present=', requirements_present, '/', requirements_total,
              ' statements_ok=', statements_ok) AS result,
       'state=verified present=total' AS expected
  FROM schema_migrations
 WHERE filename IN ('upgrade_v55.sql','upgrade_v59.sql','upgrade_v61.sql',
                    'upgrade_v62.sql','upgrade_v63.sql')
 ORDER BY filename;

-- ----------------------------------------------------------------------------
-- 9) Data preservation. These are the tables that hold customer work. The fix
--    and the import never write to them; these rows are here so the operator can
--    see that for themselves, before and after. Counts are informational — there
--    is no expected value, because it depends on live traffic.
-- ----------------------------------------------------------------------------
SELECT '9. consultations (customer data, informational)' AS check_name,
       CAST(COUNT(*) AS CHAR) AS result, 'unchanged by this fix' AS expected FROM consultations
UNION ALL
SELECT '9. users (customer data, informational)', CAST(COUNT(*) AS CHAR), 'unchanged by this fix' FROM users
UNION ALL
SELECT '9. cases (customer data, informational)', CAST(COUNT(*) AS CHAR), 'unchanged by this fix' FROM cases;

-- ============================================================================
-- INTERPRETING THE RESULT
--   All rows OK / 7 of 7 / 0 orphans  -> the database is repaired and the
--   classification hierarchy is complete.
--   Any MISSING                      -> run MAWATHEQ_DB_SCHEMA_FIX.sql.
--   Counts below expected            -> re-run
--                                       MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql;
--                                       it is additive and safe to repeat.
-- ============================================================================
