-- ============================================================================
-- Mawatheq Platform — customer consultation journey (Execution 37)
-- upgrade_v63.sql
-- ============================================================================
--
-- WHAT THIS ADDS
--
--   1) lawyer_specialties — which legal specialties a lawyer actually provides.
--      The customer journey must show only approved lawyers who really provide the
--      selected specialty, and until now there was no relationship to answer that
--      with: lawyers.specialization is a free-text label on the lawyer row, not a
--      link to the classification the customer picks from. This table is that link.
--
--      It is deliberately EMPTY on creation. Populating it is an operator action
--      (admin console / lawyer profile), because inventing a lawyer's expertise is
--      exactly what the brief forbids. A lawyer with no row here simply does not
--      appear as a match for any specialty, and the journey offers the customer a
--      clear alternative instead of showing an unrelated lawyer.
--
--   2) Two customer-facing presentation columns on najiz_categories:
--        customer_desc_ar  a plain-language sentence describing the domain
--        customer_icon_key a short icon identifier for the specialty card
--      The internal category name is the legal taxonomy term. A customer picking
--      "what is your problem?" needs a sentence they understand, and the card needs
--      a visual. These are presentation fields; they are never the classification
--      itself and the journey works (falling back to the name) when they are NULL.
--
--   3) consultations.specialty_category_id — the specialty the customer chose in
--      the journey, stored on the consultation so the lawyer and the admin console
--      can see how the request was routed. NULL for every existing consultation,
--      which is the truth: no specialty was ever chosen for them.
--
-- WHAT THIS DELIBERATELY DOES NOT DO
--
--   * No data is rewritten, dropped or re-typed. Every statement is additive.
--   * No lawyer is assigned a specialty. No consultation is re-classified.
--   * No positional AFTER clause names a column. A positional AFTER naming a
--     column that does not exist aborts the statement, and MySQL stops at the
--     first error — everything below silently never runs while the migration
--     still looks applied. (Execution 28 failure mode.) Column order is cosmetic.
--   * The customer descriptions are filled ONLY where the column is still NULL, so
--     an operator's own wording is never overwritten on a re-run.
--
-- SAFETY
--   Additive only, guarded by information_schema tests, so the file can be run any
--   number of times and is a no-op once applied. Where a statement needs the
--   classification tables (foreign keys, the customer columns), it is guarded on
--   those tables existing, so a database that never received upgrade_v59.sql is
--   left untouched rather than aborted partway. The ledger entry is written only
--   when every requirement actually held.
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1) lawyer_specialties — the lawyer ↔ specialty relationship.
--
--    Guarded on najiz_categories existing: the foreign key cannot be created
--    without it, and creating the table without its keys would be worse than not
--    creating it, because the journey's "does this lawyer provide this specialty?"
--    query relies on the constraint to stay meaningful.
-- ---------------------------------------------------------------------------
SET @has_najiz := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='najiz_categories');

SET @s := IF(@has_najiz=1, "
CREATE TABLE IF NOT EXISTS lawyer_specialties (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lawyer_specialty (lawyer_id, category_id),
    KEY idx_lawyer_specialty_cat (category_id),
    CONSTRAINT fk_lawyer_specialty_lawyer FOREIGN KEY (lawyer_id)
        REFERENCES lawyers(id) ON DELETE CASCADE,
    CONSTRAINT fk_lawyer_specialty_cat FOREIGN KEY (category_id)
        REFERENCES najiz_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2) najiz_categories — customer-facing presentation.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='najiz_categories'
             AND COLUMN_NAME='customer_desc_ar');
SET @s := IF(@c=0, "ALTER TABLE najiz_categories
    ADD COLUMN customer_desc_ar VARCHAR(500) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='najiz_categories'
             AND COLUMN_NAME='customer_icon_key');
SET @s := IF(@c=0, "ALTER TABLE najiz_categories
    ADD COLUMN customer_icon_key VARCHAR(40) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3) consultations.specialty_category_id — the chosen specialty.
--
--    The column is added unconditionally: it is nullable and carries no meaning
--    on its own, so it is safe even where the classification tables are absent.
--    The foreign key and the index are guarded, because they reference
--    najiz_categories.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'
             AND COLUMN_NAME='specialty_category_id');
SET @s := IF(@c=0, "ALTER TABLE consultations
    ADD COLUMN specialty_category_id INT UNSIGNED DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'
             AND INDEX_NAME='idx_consultations_specialty');
SET @s := IF(@c=0, "ALTER TABLE consultations
    ADD KEY idx_consultations_specialty (specialty_category_id)", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'
             AND CONSTRAINT_NAME='fk_consultations_specialty');
SET @s := IF(@c=0 AND @has_najiz=1, "ALTER TABLE consultations
    ADD CONSTRAINT fk_consultations_specialty FOREIGN KEY (specialty_category_id)
        REFERENCES najiz_categories(id) ON DELETE SET NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4) Customer-facing descriptions for the specialties that exist.
--
--    Written only where customer_desc_ar IS NULL, so re-running the migration
--    after an operator has reworded a card never overwrites their wording. The
--    match is on the category's own name, so this touches exactly the real rows
--    the importer created and invents no category.
-- ---------------------------------------------------------------------------
SET @s := IF(@has_najiz=1, "
UPDATE najiz_categories SET customer_desc_ar = CASE name_ar
    WHEN 'الأحوال الشخصية' THEN 'شؤون الأسرة: الزواج والطلاق والنفقة والحضانة والميراث.'
    WHEN 'الدعاوى التجارية' THEN 'النزاعات التجارية والشركات والعقود والأوراق التجارية والإفلاس.'
    WHEN 'الدعاوى العمالية' THEN 'نزاعات العمل: حقوق وواجبات العامل وصاحب العمل ومستحقات نهاية الخدمة.'
    WHEN 'الدعاوى الجزائية' THEN 'القضايا الجزائية والمطالبات المالية الناشئة عن الجرائم.'
    WHEN 'الدعاوى العامة' THEN 'الدعاوى المدنية والعقارية والعقود والتحكيم.'
    WHEN 'دعاوى التنفيذ' THEN 'تنفيذ الأحكام والسندات التنفيذية والمنازعات المتعلقة بها.'
    WHEN 'الدعاوى المتعلقة بالإنهاءات' THEN 'إنهاءات الأوقاف والمواريث والصكوك والولايات.'
    ELSE customer_desc_ar END
 WHERE customer_desc_ar IS NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := IF(@has_najiz=1, "
UPDATE najiz_categories SET customer_icon_key = CASE name_ar
    WHEN 'الأحوال الشخصية' THEN 'family'
    WHEN 'الدعاوى التجارية' THEN 'commercial'
    WHEN 'الدعاوى العمالية' THEN 'labor'
    WHEN 'الدعاوى الجزائية' THEN 'criminal'
    WHEN 'الدعاوى العامة' THEN 'general'
    WHEN 'دعاوى التنفيذ' THEN 'enforcement'
    WHEN 'الدعاوى المتعلقة بالإنهاءات' THEN 'endowments'
    ELSE customer_icon_key END
 WHERE customer_icon_key IS NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Ledger: written only when every condition actually held.
-- ---------------------------------------------------------------------------
SET @ok :=
      (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lawyer_specialties')
    + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lawyer_specialties'
          AND INDEX_NAME IN ('uq_lawyer_specialty','idx_lawyer_specialty_cat'))
    + (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lawyer_specialties'
          AND CONSTRAINT_NAME IN ('fk_lawyer_specialty_lawyer','fk_lawyer_specialty_cat'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='najiz_categories'
          AND COLUMN_NAME IN ('customer_desc_ar','customer_icon_key'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'
          AND COLUMN_NAME='specialty_category_id')
    + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'
          AND INDEX_NAME='idx_consultations_specialty')
    + (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'
          AND CONSTRAINT_NAME='fk_consultations_specialty');

SET @s := IF(@ok = 10,
    "INSERT INTO schema_migrations (filename, checksum, state, requirements_total, requirements_present, statements_ok)
     VALUES ('upgrade_v63.sql', REPEAT('0',64), 'verified', 10, @ok, 10)
     ON DUPLICATE KEY UPDATE state='verified', requirements_total=10, requirements_present=@ok, statements_ok=10",
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- ROLLBACK (manual, if ever needed). All of it is additive, so dropping it loses
-- only the specialty links and the routing label — never a lawyer, a consultation
-- or a classification row:
--   ALTER TABLE consultations DROP FOREIGN KEY fk_consultations_specialty;
--   ALTER TABLE consultations DROP INDEX idx_consultations_specialty;
--   ALTER TABLE consultations DROP COLUMN specialty_category_id;
--   ALTER TABLE najiz_categories DROP COLUMN customer_icon_key;
--   ALTER TABLE najiz_categories DROP COLUMN customer_desc_ar;
--   DROP TABLE lawyer_specialties;
-- ------------------------------------------------------------
