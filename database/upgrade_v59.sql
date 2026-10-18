-- ============================================================================
-- Execution 33 — Saudi Najiz lawsuit classification hierarchy
-- upgrade_v59.sql
-- ============================================================================
--
-- ADDITIVE ONLY. Nothing here drops, renames or rewrites an existing table, and no
-- existing row is modified. Every CREATE is `IF NOT EXISTS`; every ALTER is guarded
-- by an information_schema test, so the file can be run any number of times.
--
-- No positional AFTER clause is used anywhere. A positional AFTER naming a column
-- that does not exist aborts the statement, and MySQL stops at the first error — the
-- failure mode that made the Execution 28 repair script appear applied while leaving
-- the fault in place. Column placement is left to the server.
--
-- ---------------------------------------------------------------------------
-- WHAT THIS BUILDS
--
-- The source document (database/sources/Pasted%20text%283%29.txt) is a flattened
-- four-level hierarchy:
--
--   Level 1  main category        e.g. الدعاوى التجارية
--   Level 2  subcategory          e.g. الملكية الفكرية
--   Level 3  lawsuit type         e.g. العلامات التجارية
--   Level 4  judicial requests    e.g. وقف التعدي على علامة
--
-- Each type also carries its source definition. The tables below mirror that shape.
--
-- ---------------------------------------------------------------------------
-- WHY source_key EXISTS, AND WHY IT IS NOT JUST THE NAME
--
-- The import must be idempotent: running it twice must not duplicate records. The
-- obvious key — the name — is wrong, because the source contains the SAME name more
-- than once under the same parent and those are genuinely distinct records:
--
--   الأحوال الشخصية / النفقة                     'النفقة'    x2
--   الأحوال الشخصية / الحضانة                    'الحضانة'   x2
--   الأحوال الشخصية / الولاية                    'ولي'       x3
--   دعاوى التنفيذ / دعوى الإعسار أو الملاءمة     'دعوى الإعسار أو الملاءمة' x2
--   دعاوى التنفيذ / منازعات شكلية                'منازعات شكلية' x2
--
-- Nine further names legitimately recur under DIFFERENT parents ('الحضانة' under
-- three, 'أتعاب محاماة أو وكلاء' under four, and so on). A UNIQUE key on the name
-- alone would collapse both cases and silently lose records, which the brief
-- forbids. `source_key` is therefore a SHA-1 over the full path plus the ordinal
-- occurrence of that name within its parent, so every source record maps to exactly
-- one row and a re-import is a no-op.
--
-- ---------------------------------------------------------------------------
-- DELETION POLICY
--
-- Classifications are retired with `is_active = 0`, never deleted. The foreign keys
-- from cases and consultations use ON DELETE RESTRICT, so a classification that is
-- referenced by real casework cannot be removed by accident. Nothing in this file
-- touches cases, consultations, users, accounting, affiliate or authentication data
-- beyond adding one nullable column to two tables.
-- ============================================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) Main categories (level 1)
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- 2) Subcategories (level 2)
--    `declared_count` is the type count the source itself prints next to the
--    subcategory heading. It is kept so the reconciliation can compare what the
--    source claims against what was actually imported, and so a mismatch is
--    visible in the database rather than only in a log.
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- 3) Lawsuit types (level 3)
--    `search_norm` is the Arabic-normalised form of the name (alef/hamza/ya/ta
--    marbuta folded, diacritics and tatweel stripped). The server's collation does
--    not fold those, so searching 'نفقه' would not find 'النفقة' without it. The
--    column is written by the importer; the application never writes it directly.
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- 4) Judicial requests (level 4)
--    Each request belongs to exactly one type, which is how the source presents
--    them. A type may hold many requests; a type with none is represented by
--    `requests_declared_absent` on the type rather than by a placeholder row.
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- 5) Arabic spelling variants
--    Normalisation handles most variation deterministically (أ/إ/آ → ا, ى → ي,
--    ة → ه, diacritics removed). It cannot handle a genuinely different word, so
--    this table lets an operator add a synonym that maps to a real type or request.
--    Search works without it; this only widens recall.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS najiz_search_synonyms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    term_norm VARCHAR(191) NOT NULL,
    target_kind ENUM('category','subcategory','case_type','judicial_request') NOT NULL,
    target_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_najiz_synonym (term_norm, target_kind, target_id),
    KEY idx_najiz_synonym_term (term_norm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 6) Import runs — one row per importer execution, carrying the source checksum and
--    the counts that were actually written. This is the reconciliation record: it
--    is what makes "imported N of M" auditable after the fact.
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- 7) Administrative audit trail
--    Every classification write records who changed what, with the previous and new
--    values. `actor_admin_id` is deliberately not a foreign key: an audit row must
--    survive the removal of the account that made the change.
-- ------------------------------------------------------------
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

-- ============================================================================
-- Integration with existing casework — two nullable columns, nothing else.
-- Both are added only when absent, so re-running is safe.
-- ============================================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cases' AND COLUMN_NAME='najiz_case_type_id');
SET @s := IF(@c=0,
    'ALTER TABLE cases ADD COLUMN najiz_case_type_id INT UNSIGNED DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cases' AND INDEX_NAME='idx_cases_najiz_type');
SET @s := IF(@c=0,
    'ALTER TABLE cases ADD KEY idx_cases_najiz_type (najiz_case_type_id)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cases'
             AND CONSTRAINT_NAME='fk_cases_najiz_type');
SET @s := IF(@c=0,
    'ALTER TABLE cases ADD CONSTRAINT fk_cases_najiz_type FOREIGN KEY (najiz_case_type_id) REFERENCES najiz_case_types(id) ON DELETE RESTRICT',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND COLUMN_NAME='najiz_case_type_id');
SET @s := IF(@c=0,
    'ALTER TABLE consultations ADD COLUMN najiz_case_type_id INT UNSIGNED DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND INDEX_NAME='idx_consultations_najiz_type');
SET @s := IF(@c=0,
    'ALTER TABLE consultations ADD KEY idx_consultations_najiz_type (najiz_case_type_id)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'
             AND CONSTRAINT_NAME='fk_consultations_najiz_type');
SET @s := IF(@c=0,
    'ALTER TABLE consultations ADD CONSTRAINT fk_consultations_najiz_type FOREIGN KEY (najiz_case_type_id) REFERENCES najiz_case_types(id) ON DELETE RESTRICT',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- A case may cite several judicial requests, so that side is many-to-many.
CREATE TABLE IF NOT EXISTS case_judicial_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    request_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_case_request (case_id, request_id),
    KEY idx_cjr_request (request_id),
    CONSTRAINT fk_cjr_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_cjr_request FOREIGN KEY (request_id) REFERENCES najiz_judicial_requests(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A consultation may optionally cite requests too. The brief asks that irrelevant
-- judicial-request fields are not forced on consultations, so this is opt-in: the
-- client interface writes here only when the client chooses to.
CREATE TABLE IF NOT EXISTS consultation_judicial_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consultation_id INT UNSIGNED NOT NULL,
    request_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_consult_request (consultation_id, request_id),
    KEY idx_cjr2_request (request_id),
    CONSTRAINT fk_cjr2_consultation FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE,
    CONSTRAINT fk_cjr2_request FOREIGN KEY (request_id) REFERENCES najiz_judicial_requests(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Ledger entry. Recorded only when the tables above actually exist, so a partially
-- applied file never claims success.
-- ------------------------------------------------------------
SET @tables_ok := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
                   ('najiz_categories','najiz_subcategories','najiz_case_types',
                    'najiz_judicial_requests','najiz_import_runs','najiz_audit_log'));

SET @s := IF(@tables_ok = 6,
    "INSERT INTO schema_migrations (filename, checksum, state, requirements_total, requirements_present, statements_ok)
     VALUES ('upgrade_v59.sql', REPEAT('0',64), 'verified', 6, @tables_ok, 6)
     ON DUPLICATE KEY UPDATE state='verified', requirements_total=6, requirements_present=@tables_ok, statements_ok=6",
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
