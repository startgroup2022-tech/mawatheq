-- ============================================================
-- v42: Multi-tenant SaaS foundation — firm profile, member roles/permissions,
-- and a reliable firm_id tenant relationship on every firm-owned business entity.
--
-- Execution 29.1 rewrite. Two defects are fixed here.
--
-- DEFECT 1 — single-column guards around multi-column operations.
--   This file used to guard a whole multi-column ALTER on one column:
--       IF @c=0 (legal_name absent) THEN
--           ALTER TABLE law_firms ADD legal_name, display_name, email, ... (11 columns)
--   If a previous run aborted halfway — which MySQL does at the first error — a table
--   could hold legal_name but not display_name. The guard then saw legal_name present
--   and skipped the statement, so the other ten columns stayed missing forever while
--   the migration looked applied. The same shape appeared on every firm-owned table,
--   where one guard covered ADD COLUMN + ADD INDEX + ADD CONSTRAINT together: a table
--   with firm_id present but no index or foreign key was skipped entirely.
--
--   Every column, index and constraint is now guarded on itself. A re-run therefore
--   completes whatever a previous run left unfinished, which is what makes the file
--   safe on a fresh database, a partially migrated one and a fully migrated one alike.
--
-- DEFECT 2 — backfills could assign records to the wrong firm.
--   The old backfill was a plain UPDATE ... JOIN law_firm_members. `law_firm_members`
--   has UNIQUE(lawyer_id), so today one lawyer maps to one firm and the join is
--   unambiguous — but the join has no defence if that uniqueness is ever relaxed, and
--   a naive fix (temporarily dropping the unique key) would make the same UPDATE pick
--   an arbitrary row from several and silently leak one tenant's records into another.
--   Section 4 now assigns only where the ownership is provably unambiguous, and reports
--   the rest for explicit reconciliation instead of guessing.
--
-- Additive and idempotent throughout: no column is dropped, renamed or narrowed, no
-- existing firm_id is overwritten, and no positional AFTER clause is used anywhere (a
-- missing sibling column would abort the file — see database/repair/01_repair_reset_columns.sql).
SET NAMES utf8mb4;

-- ============================================================
-- 1) Firm profile fields (backward compatible — `name` is preserved).
--    One guarded statement per column so a partial run can be completed.
-- ============================================================
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firms' AND COLUMN_NAME='legal_name');
SET @s := IF(@c=0, 'ALTER TABLE law_firms ADD COLUMN legal_name VARCHAR(200) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firms' AND COLUMN_NAME='display_name');
SET @s := IF(@c=0, 'ALTER TABLE law_firms ADD COLUMN display_name VARCHAR(180) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firms' AND COLUMN_NAME='email');
SET @s := IF(@c=0, 'ALTER TABLE law_firms ADD COLUMN email VARCHAR(150) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firms' AND COLUMN_NAME='phone');
SET @s := IF(@c=0, 'ALTER TABLE law_firms ADD COLUMN phone VARCHAR(30) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firms' AND COLUMN_NAME='address');
SET @s := IF(@c=0, 'ALTER TABLE law_firms ADD COLUMN address VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firms' AND COLUMN_NAME='city');
SET @s := IF(@c=0, 'ALTER TABLE law_firms ADD COLUMN city VARCHAR(100) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firms' AND COLUMN_NAME='country');
SET @s := IF(@c=0, 'ALTER TABLE law_firms ADD COLUMN country VARCHAR(100) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firms' AND COLUMN_NAME='commercial_register');
SET @s := IF(@c=0, 'ALTER TABLE law_firms ADD COLUMN commercial_register VARCHAR(80) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firms' AND COLUMN_NAME='license_number');
SET @s := IF(@c=0, 'ALTER TABLE law_firms ADD COLUMN license_number VARCHAR(80) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firms' AND COLUMN_NAME='logo_path');
SET @s := IF(@c=0, 'ALTER TABLE law_firms ADD COLUMN logo_path VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firms' AND COLUMN_NAME='updated_at');
SET @s := IF(@c=0, 'ALTER TABLE law_firms ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 2) Firm member status + per-member permission overrides.
-- ============================================================
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firm_members' AND COLUMN_NAME='status');
SET @s := IF(@c=0, "ALTER TABLE law_firm_members ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firm_members' AND COLUMN_NAME='permissions_json');
SET @s := IF(@c=0, 'ALTER TABLE law_firm_members ADD COLUMN permissions_json TEXT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firm_members' AND COLUMN_NAME='updated_at');
SET @s := IF(@c=0, 'ALTER TABLE law_firm_members ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 3) Tenant column on firm-owned entities.
--    Column, index and foreign key are guarded separately. Previously one guard covered
--    all three, so a table that already had firm_id silently never received its index
--    or its ON DELETE SET NULL constraint.
--    firm_id is nullable and ON DELETE SET NULL so removing a firm never destroys
--    business history.
-- ============================================================

-- cases
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cases' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE cases ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cases' AND INDEX_NAME='idx_cases_firm');
SET @s := IF(@c=0, 'ALTER TABLE cases ADD INDEX idx_cases_firm (firm_id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cases' AND CONSTRAINT_NAME='fk_cases_firm' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @s := IF(@c=0, 'ALTER TABLE cases ADD CONSTRAINT fk_cases_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consultations
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE consultations ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND INDEX_NAME='idx_consultations_firm');
SET @s := IF(@c=0, 'ALTER TABLE consultations ADD INDEX idx_consultations_firm (firm_id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND CONSTRAINT_NAME='fk_consultations_firm' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @s := IF(@c=0, 'ALTER TABLE consultations ADD CONSTRAINT fk_consultations_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- invoices
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_invoices_firm');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD INDEX idx_invoices_firm (firm_id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND CONSTRAINT_NAME='fk_invoices_firm' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD CONSTRAINT fk_invoices_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- draft_documents
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE draft_documents ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND INDEX_NAME='idx_draft_documents_firm');
SET @s := IF(@c=0, 'ALTER TABLE draft_documents ADD INDEX idx_draft_documents_firm (firm_id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND CONSTRAINT_NAME='fk_draft_documents_firm' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @s := IF(@c=0, 'ALTER TABLE draft_documents ADD CONSTRAINT fk_draft_documents_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- case_evidence
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_evidence' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE case_evidence ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_evidence' AND INDEX_NAME='idx_case_evidence_firm');
SET @s := IF(@c=0, 'ALTER TABLE case_evidence ADD INDEX idx_case_evidence_firm (firm_id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_evidence' AND CONSTRAINT_NAME='fk_case_evidence_firm' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @s := IF(@c=0, 'ALTER TABLE case_evidence ADD CONSTRAINT fk_case_evidence_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- case_notes
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_notes' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE case_notes ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_notes' AND INDEX_NAME='idx_case_notes_firm');
SET @s := IF(@c=0, 'ALTER TABLE case_notes ADD INDEX idx_case_notes_firm (firm_id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_notes' AND CONSTRAINT_NAME='fk_case_notes_firm' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @s := IF(@c=0, 'ALTER TABLE case_notes ADD CONSTRAINT fk_case_notes_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- case_time_entries
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_time_entries' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE case_time_entries ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_time_entries' AND INDEX_NAME='idx_case_time_entries_firm');
SET @s := IF(@c=0, 'ALTER TABLE case_time_entries ADD INDEX idx_case_time_entries_firm (firm_id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_time_entries' AND CONSTRAINT_NAME='fk_case_time_entries_firm' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @s := IF(@c=0, 'ALTER TABLE case_time_entries ADD CONSTRAINT fk_case_time_entries_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- legal_calendar_events
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legal_calendar_events' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE legal_calendar_events ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legal_calendar_events' AND INDEX_NAME='idx_legal_calendar_firm');
SET @s := IF(@c=0, 'ALTER TABLE legal_calendar_events ADD INDEX idx_legal_calendar_firm (firm_id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legal_calendar_events' AND CONSTRAINT_NAME='fk_legal_calendar_firm' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @s := IF(@c=0, 'ALTER TABLE legal_calendar_events ADD CONSTRAINT fk_legal_calendar_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- audit_logs
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE audit_logs ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND INDEX_NAME='idx_audit_logs_firm');
SET @s := IF(@c=0, 'ALTER TABLE audit_logs ADD INDEX idx_audit_logs_firm (firm_id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND CONSTRAINT_NAME='fk_audit_logs_firm' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @s := IF(@c=0, 'ALTER TABLE audit_logs ADD CONSTRAINT fk_audit_logs_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 4) Backfill firm_id from existing firm membership.
--
--    Safety properties, in order of importance:
--      a) An existing valid firm_id is never overwritten — every statement is
--         `WHERE <t>.firm_id IS NULL`. Ownership already recorded wins.
--      b) A row is assigned only when its owner's firm is unambiguous. The join is
--         against a derived table that keeps only lawyer_ids with exactly ONE distinct
--         firm, so a lawyer who is (or ever becomes) a member of several firms
--         contributes no rows at all rather than an arbitrary one. With today's
--         UNIQUE(lawyer_id) this set is all members, so normal data backfills exactly
--         as before; if that uniqueness is ever relaxed, the ambiguous lawyers drop out
--         of the backfill automatically instead of leaking a record to the wrong tenant.
--      c) Nothing is deleted or reassigned. Rows that cannot be resolved keep
--         firm_id = NULL, which is the fail-closed direction for tenant scoping.
--
--    Section 6 then reports whatever remains unassigned, so ambiguity is surfaced for
--    explicit reconciliation rather than hidden.
-- ============================================================
UPDATE cases c
  JOIN (SELECT lawyer_id, MIN(firm_id) AS firm_id
          FROM law_firm_members
         GROUP BY lawyer_id
        HAVING COUNT(DISTINCT firm_id) = 1) m ON m.lawyer_id = c.lawyer_id
   SET c.firm_id = m.firm_id
 WHERE c.firm_id IS NULL;

UPDATE consultations co
  JOIN (SELECT lawyer_id, MIN(firm_id) AS firm_id
          FROM law_firm_members
         GROUP BY lawyer_id
        HAVING COUNT(DISTINCT firm_id) = 1) m ON m.lawyer_id = co.lawyer_id
   SET co.firm_id = m.firm_id
 WHERE co.firm_id IS NULL;

UPDATE invoices i
  JOIN (SELECT lawyer_id, MIN(firm_id) AS firm_id
          FROM law_firm_members
         GROUP BY lawyer_id
        HAVING COUNT(DISTINCT firm_id) = 1) m ON m.lawyer_id = i.lawyer_id
   SET i.firm_id = m.firm_id
 WHERE i.firm_id IS NULL;

UPDATE draft_documents d
  JOIN (SELECT lawyer_id, MIN(firm_id) AS firm_id
          FROM law_firm_members
         GROUP BY lawyer_id
        HAVING COUNT(DISTINCT firm_id) = 1) m ON m.lawyer_id = d.lawyer_id
   SET d.firm_id = m.firm_id
 WHERE d.firm_id IS NULL;

UPDATE case_time_entries t
  JOIN (SELECT lawyer_id, MIN(firm_id) AS firm_id
          FROM law_firm_members
         GROUP BY lawyer_id
        HAVING COUNT(DISTINCT firm_id) = 1) m ON m.lawyer_id = t.lawyer_id
   SET t.firm_id = m.firm_id
 WHERE t.firm_id IS NULL;

UPDATE legal_calendar_events e
  JOIN (SELECT lawyer_id, MIN(firm_id) AS firm_id
          FROM law_firm_members
         GROUP BY lawyer_id
        HAVING COUNT(DISTINCT firm_id) = 1) m ON m.lawyer_id = e.lawyer_id
   SET e.firm_id = m.firm_id
 WHERE e.firm_id IS NULL;

-- Child rows inherit their parent case's tenant, but only from a case that has one.
UPDATE case_evidence e
  JOIN cases c ON c.id = e.case_id
   SET e.firm_id = c.firm_id
 WHERE e.firm_id IS NULL AND c.firm_id IS NOT NULL;

UPDATE case_notes n
  JOIN cases c ON c.id = n.case_id
   SET n.firm_id = c.firm_id
 WHERE n.firm_id IS NULL AND c.firm_id IS NOT NULL;

-- ============================================================
-- 5) Keep firm display name in sync with the original `name` column.
-- ============================================================
UPDATE law_firms SET display_name = name WHERE display_name IS NULL OR display_name = '';

-- ============================================================
-- 6) Ownership reconciliation report (read-only).
--    Prints what could not be assigned and why, so an operator can reconcile it. This is
--    a report, not a repair: nothing here guesses at a tenant.
--      ambiguous_lawyers   one lawyer mapped to more than one firm — records untouched
--      unassigned_*        rows still NULL after the backfill above
-- ============================================================
SELECT 'ambiguous_lawyers' AS finding,
       COUNT(*) AS occurrences
  FROM (SELECT lawyer_id FROM law_firm_members GROUP BY lawyer_id HAVING COUNT(DISTINCT firm_id) > 1) a
UNION ALL
SELECT 'unassigned_cases', COUNT(*) FROM cases WHERE firm_id IS NULL
UNION ALL
SELECT 'unassigned_consultations', COUNT(*) FROM consultations WHERE firm_id IS NULL
UNION ALL
SELECT 'unassigned_invoices', COUNT(*) FROM invoices WHERE firm_id IS NULL
UNION ALL
SELECT 'unassigned_draft_documents', COUNT(*) FROM draft_documents WHERE firm_id IS NULL
UNION ALL
SELECT 'unassigned_case_time_entries', COUNT(*) FROM case_time_entries WHERE firm_id IS NULL
UNION ALL
SELECT 'unassigned_legal_calendar_events', COUNT(*) FROM legal_calendar_events WHERE firm_id IS NULL
UNION ALL
SELECT 'unassigned_case_evidence', COUNT(*) FROM case_evidence WHERE firm_id IS NULL
UNION ALL
SELECT 'unassigned_case_notes', COUNT(*) FROM case_notes WHERE firm_id IS NULL;
