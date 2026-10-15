-- v42: Multi-tenant SaaS foundation — firm profile, member roles/permissions,
-- and a reliable firm_id tenant relationship on every firm-owned business entity.
-- Idempotent: safe to run repeatedly and on databases that already have some columns.

-- ------------------------------------------------------------
-- 1) Firm profile fields (backward compatible — `name` is preserved).
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firms' AND COLUMN_NAME='legal_name');
SET @s := IF(@c=0, "ALTER TABLE law_firms
    ADD COLUMN legal_name VARCHAR(200) DEFAULT NULL,
    ADD COLUMN display_name VARCHAR(180) DEFAULT NULL,
    ADD COLUMN email VARCHAR(150) DEFAULT NULL,
    ADD COLUMN phone VARCHAR(30) DEFAULT NULL,
    ADD COLUMN address VARCHAR(255) DEFAULT NULL,
    ADD COLUMN city VARCHAR(100) DEFAULT NULL,
    ADD COLUMN country VARCHAR(100) DEFAULT NULL,
    ADD COLUMN commercial_register VARCHAR(80) DEFAULT NULL,
    ADD COLUMN license_number VARCHAR(80) DEFAULT NULL,
    ADD COLUMN logo_path VARCHAR(255) DEFAULT NULL,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ------------------------------------------------------------
-- 2) Firm member status + per-member permission overrides.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firm_members' AND COLUMN_NAME='status');
SET @s := IF(@c=0, "ALTER TABLE law_firm_members
    ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    ADD COLUMN permissions_json TEXT DEFAULT NULL,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ------------------------------------------------------------
-- 3) Tenant column on firm-owned entities (nullable, ON DELETE SET NULL
--    so removing a firm never destroys business history).
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cases' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE cases ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_cases_firm (firm_id), ADD CONSTRAINT fk_cases_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE consultations ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_consultations_firm (firm_id), ADD CONSTRAINT fk_consultations_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_invoices_firm (firm_id), ADD CONSTRAINT fk_invoices_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE draft_documents ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_draft_documents_firm (firm_id), ADD CONSTRAINT fk_draft_documents_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_evidence' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE case_evidence ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_case_evidence_firm (firm_id), ADD CONSTRAINT fk_case_evidence_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_notes' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE case_notes ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_case_notes_firm (firm_id), ADD CONSTRAINT fk_case_notes_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_time_entries' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE case_time_entries ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_case_time_entries_firm (firm_id), ADD CONSTRAINT fk_case_time_entries_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legal_calendar_events' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE legal_calendar_events ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_legal_calendar_firm (firm_id), ADD CONSTRAINT fk_legal_calendar_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE audit_logs ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_audit_logs_firm (firm_id), ADD CONSTRAINT fk_audit_logs_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ------------------------------------------------------------
-- 4) Backfill firm_id from existing firm membership so pre-v42 data
--    becomes tenant-correct without any destructive change.
-- ------------------------------------------------------------
UPDATE cases c JOIN law_firm_members m ON m.lawyer_id = c.lawyer_id SET c.firm_id = m.firm_id WHERE c.firm_id IS NULL;
UPDATE consultations co JOIN law_firm_members m ON m.lawyer_id = co.lawyer_id SET co.firm_id = m.firm_id WHERE co.firm_id IS NULL;
UPDATE invoices i JOIN law_firm_members m ON m.lawyer_id = i.lawyer_id SET i.firm_id = m.firm_id WHERE i.firm_id IS NULL;
UPDATE draft_documents d JOIN law_firm_members m ON m.lawyer_id = d.lawyer_id SET d.firm_id = m.firm_id WHERE d.firm_id IS NULL;
UPDATE case_time_entries t JOIN law_firm_members m ON m.lawyer_id = t.lawyer_id SET t.firm_id = m.firm_id WHERE t.firm_id IS NULL;
UPDATE legal_calendar_events e JOIN law_firm_members m ON m.lawyer_id = e.lawyer_id SET e.firm_id = m.firm_id WHERE e.firm_id IS NULL;
UPDATE case_evidence e JOIN cases c ON c.id = e.case_id SET e.firm_id = c.firm_id WHERE e.firm_id IS NULL AND c.firm_id IS NOT NULL;
UPDATE case_notes n JOIN cases c ON c.id = n.case_id SET n.firm_id = c.firm_id WHERE n.firm_id IS NULL AND c.firm_id IS NOT NULL;

-- ------------------------------------------------------------
-- 5) Keep firm display name in sync with the original `name` column.
-- ------------------------------------------------------------
UPDATE law_firms SET display_name = name WHERE display_name IS NULL OR display_name = '';
