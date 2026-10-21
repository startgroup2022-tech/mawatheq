-- ============================================================================
-- MAWATHEQ — DB SCHEMA FIX
-- MAWATHEQ_DB_SCHEMA_FIX.sql
-- ============================================================================
--
-- PURPOSE
--   Repairs the schema defect behind
--       #1054 - Unknown column 'statements_ok' in 'INSERT INTO'
--   and makes the `najiz_*` classification tables exist. Safe to run on
--   production: additive only, no DROP, no TRUNCATE, no DELETE, and no change to
--   any customer, lawyer, case or consultation row.
--
-- WHEN TO RUN
--   Before MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql.
--   From phpMyAdmin: select the database, then Import -> choose this file -> Go.
--   From the shell:  mysql -u USER -p DBNAME < MAWATHEQ_DB_SCHEMA_FIX.sql
--
-- RE-RUNNABLE
--   Yes. Every statement is guarded, so running it twice changes nothing the
--   second time.
--
-- ============================================================================
-- WHAT IS ACTUALLY WRONG (the real cause, not a symptom)
-- ============================================================================
--
-- upgrade_v53.sql created `schema_migrations`. Some installs — including the
-- production database — created it from an EARLIER revision of that file which
-- did not yet have the `statements_ok` column:
--
--     id, filename, checksum, applied_at
--
-- upgrade_v55.sql then tried to repair it with:
--
--     CREATE TABLE IF NOT EXISTS schema_migrations ( ... , statements_ok ... );
--
-- That statement is a NO-OP when the table already exists. MySQL does not compare
-- or extend the column list, so `statements_ok` was never added.
--
-- The failure then surfaces far from its cause. upgrade_v59.sql, v61, v62 and v63
-- each END with a guarded INSERT that names `statements_ok`:
--
--     SET @s := IF(<requirements met>,
--         "INSERT INTO schema_migrations
--            (filename, checksum, state, requirements_total, requirements_present, statements_ok)
--          VALUES (...) ON DUPLICATE KEY UPDATE ... statements_ok=...",
--         'SELECT 1');
--
-- MySQL stops at the FIRST error and abandons the rest of the file. Because this
-- is the LAST statement, the damage is well hidden:
--
--   * every CREATE TABLE before it already succeeded — so `najiz_categories`,
--     `najiz_case_types` and the rest DO exist, and look correct;
--   * the ledger row is never written — so the migration reports as unapplied and
--     looks safe to re-run;
--   * re-running re-executes the guarded CREATEs (no-ops) and fails again at the
--     same final statement.
--
-- The observable result is a database whose classification tables exist and are
-- permanently empty, which is exactly the reported state.
--
-- THE FIX
--   Add the missing column explicitly, guarded by information_schema, instead of
--   relying on CREATE TABLE IF NOT EXISTS to do something it cannot do.
--   database/upgrade_v55.sql now carries the same guard, so a fresh install is
--   correct without this file; this file exists for installs already past v55.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1) The ledger table itself.
--    On the affected install it exists (created by an older v53) and this is a
--    no-op. On an install that never ran v53/v55 at all it is created complete,
--    so the guarded ALTERs below have something to act on.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(191) NOT NULL,
    checksum CHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    statements_ok INT UNSIGNED NOT NULL DEFAULT 0,
    state VARCHAR(16) NOT NULL DEFAULT 'unknown',
    requirements_total INT UNSIGNED NOT NULL DEFAULT 0,
    requirements_present INT UNSIGNED NOT NULL DEFAULT 0,
    current_checksum CHAR(64) DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    checked_at DATETIME DEFAULT NULL,
    partial_seen_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_schema_migration (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 2) The ledger column. This is the actual fix.
-- ----------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'
             AND COLUMN_NAME='statements_ok');
SET @s := IF(@c=0,
    'ALTER TABLE schema_migrations ADD COLUMN statements_ok INT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The remaining ledger columns, in case this ledger predates v55 entirely. Each is
-- guarded the same way; a column that already exists is left exactly as it is.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='state');
SET @s := IF(@c=0, "ALTER TABLE schema_migrations ADD COLUMN state VARCHAR(16) NOT NULL DEFAULT 'unknown'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='requirements_total');
SET @s := IF(@c=0, 'ALTER TABLE schema_migrations ADD COLUMN requirements_total INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='requirements_present');
SET @s := IF(@c=0, 'ALTER TABLE schema_migrations ADD COLUMN requirements_present INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='current_checksum');
SET @s := IF(@c=0, 'ALTER TABLE schema_migrations ADD COLUMN current_checksum CHAR(64) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='verified_at');
SET @s := IF(@c=0, 'ALTER TABLE schema_migrations ADD COLUMN verified_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='checked_at');
SET @s := IF(@c=0, 'ALTER TABLE schema_migrations ADD COLUMN checked_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='partial_seen_at');
SET @s := IF(@c=0, 'ALTER TABLE schema_migrations ADD COLUMN partial_seen_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 3) The classification tables.
--    On the affected install these already exist (the CREATEs ran before the
--    INSERT failed), so these statements are no-ops there. They are repeated here
--    so this file is also sufficient on an install that never ran v59 at all.
--    Definitions are identical to upgrade_v59.sql — do not diverge them.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS najiz_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name_ar VARCHAR(191) NOT NULL,
    name_en VARCHAR(191) DEFAULT NULL,
    description_ar TEXT DEFAULT NULL,
    source_ref VARCHAR(255) DEFAULT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_najiz_category_name (name_ar),
    KEY idx_najiz_category_order (sort_order, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS najiz_subcategories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    name_ar VARCHAR(191) NOT NULL,
    name_en VARCHAR(191) DEFAULT NULL,
    description_ar TEXT DEFAULT NULL,
    declared_count SMALLINT UNSIGNED DEFAULT NULL,
    source_key CHAR(40) NOT NULL,
    source_ref VARCHAR(255) DEFAULT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_najiz_subcat_key (source_key),
    UNIQUE KEY uq_najiz_subcat_name (category_id, name_ar),
    KEY idx_najiz_subcat_category (category_id, sort_order, is_active),
    CONSTRAINT fk_najiz_subcat_category FOREIGN KEY (category_id)
        REFERENCES najiz_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS najiz_case_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subcategory_id INT UNSIGNED NOT NULL,
    name_ar VARCHAR(191) NOT NULL,
    name_en VARCHAR(191) DEFAULT NULL,
    definition TEXT DEFAULT NULL,
    definition_missing TINYINT(1) NOT NULL DEFAULT 0,
    requests_declared_absent TINYINT(1) NOT NULL DEFAULT 0,
    source_key CHAR(40) NOT NULL,
    source_ref VARCHAR(255) DEFAULT NULL,
    search_norm VARCHAR(191) DEFAULT NULL,
    parse_note VARCHAR(255) DEFAULT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_najiz_type_key (source_key),
    KEY idx_najiz_type_subcat (subcategory_id, sort_order, is_active),
    KEY idx_najiz_type_search (search_norm),
    CONSTRAINT fk_najiz_type_subcat FOREIGN KEY (subcategory_id)
        REFERENCES najiz_subcategories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS najiz_judicial_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_type_id INT UNSIGNED NOT NULL,
    name_ar VARCHAR(191) NOT NULL,
    name_en VARCHAR(191) DEFAULT NULL,
    source_key CHAR(40) NOT NULL,
    search_norm VARCHAR(191) DEFAULT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_najiz_request_key (source_key),
    KEY idx_najiz_request_type (case_type_id, sort_order, is_active),
    KEY idx_najiz_request_search (search_norm),
    CONSTRAINT fk_najiz_request_type FOREIGN KEY (case_type_id)
        REFERENCES najiz_case_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS najiz_search_synonyms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    term_norm VARCHAR(191) NOT NULL,
    target_kind ENUM('category','subcategory','case_type','judicial_request') NOT NULL,
    target_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_najiz_synonym (term_norm, target_kind, target_id),
    KEY idx_najiz_synonym_term (term_norm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS najiz_import_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_path VARCHAR(500) NOT NULL,
    source_sha256 CHAR(64) NOT NULL,
    source_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    declared_total_types INT UNSIGNED DEFAULT NULL,
    counts_json TEXT DEFAULT NULL,
    warnings_json TEXT DEFAULT NULL,
    inserted_counts_json TEXT DEFAULT NULL,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    error_text TEXT DEFAULT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME DEFAULT NULL,
    KEY idx_najiz_run_started (started_at),
    KEY idx_najiz_run_sha (source_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS najiz_audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_admin_id INT UNSIGNED DEFAULT NULL,
    actor_label VARCHAR(191) DEFAULT NULL,
    action VARCHAR(64) NOT NULL,
    entity_kind VARCHAR(32) NOT NULL,
    entity_id INT UNSIGNED DEFAULT NULL,
    before_json TEXT DEFAULT NULL,
    after_json TEXT DEFAULT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_najiz_audit_entity (entity_kind, entity_id, created_at),
    KEY idx_najiz_audit_actor (actor_admin_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 4) lawyer_specialties — the lawyer-to-specialty relationship.
--
--    This table GATES the whole consultation journey: legal_journey_ready()
--    probes it, and legal_journey_specialties() joins through it. It is created by
--    upgrade_v63.sql, which on an affected install aborts at its final ledger
--    INSERT — so the table can be missing entirely and the app falls back to "no
--    specialties" rather than showing an error. Creating it here is what makes the
--    specialty cards appear.
--
--    Guarded on najiz_categories existing, exactly as v63 does: the foreign key
--    cannot be created without it, and a table without its keys would be worse than
--    no table. Definition copied verbatim from v63 — do not diverge.
-- ----------------------------------------------------------------------------
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

-- The customer-facing presentation columns used by the journey cards. These come
-- from upgrade_v63.sql, which also fails at its final ledger INSERT on an affected
-- install — so they are added here too. Definitions are copied verbatim from v63
-- (including the VARCHAR widths); guarded, so no-op when already present.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='najiz_categories' AND COLUMN_NAME='customer_desc_ar');
SET @s := IF(@c=0, 'ALTER TABLE najiz_categories ADD COLUMN customer_desc_ar VARCHAR(500) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='najiz_categories' AND COLUMN_NAME='customer_icon_key');
SET @s := IF(@c=0, 'ALTER TABLE najiz_categories ADD COLUMN customer_icon_key VARCHAR(40) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consultations.specialty_category_id — also from v63. Nullable and meaningless on
-- its own, so it is added without a guard on the classification tables existing.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND COLUMN_NAME='specialty_category_id');
SET @s := IF(@c=0, 'ALTER TABLE consultations ADD COLUMN specialty_category_id INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'
             AND INDEX_NAME='idx_consultations_specialty');
SET @s := IF(@c=0, 'ALTER TABLE consultations ADD KEY idx_consultations_specialty (specialty_category_id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 5) Report what this file did, so the operator sees the state rather than
--    assuming it. Read-only.
-- ----------------------------------------------------------------------------
SELECT 'schema_migrations.statements_ok' AS checked,
       IF(COUNT(*)=1, 'present', 'MISSING') AS result
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='statements_ok'
UNION ALL
SELECT 'najiz_* tables',
       CONCAT(COUNT(*), ' of 7')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
       ('najiz_categories','najiz_subcategories','najiz_case_types',
        'najiz_judicial_requests','najiz_search_synonyms','najiz_import_runs','najiz_audit_log');
