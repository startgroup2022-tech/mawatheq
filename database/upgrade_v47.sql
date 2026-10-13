-- ============================================================
-- Mawatheq upgrade v47 — link client documents to a case
-- Non-destructive. Safe to re-run.
-- ============================================================

-- The client document library (`documents`) and the lawyer evidence register
-- (`case_evidence`) were disconnected: a document a client uploaded could not be
-- associated with the case it belongs to. `case_id` closes that gap so the same
-- document surfaces inside the case file. It is nullable — a general library
-- document that belongs to no particular case stays valid.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'case_id');
SET @s := IF(@c = 0, 'ALTER TABLE documents ADD COLUMN case_id INT UNSIGNED DEFAULT NULL AFTER user_id', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND INDEX_NAME = 'idx_documents_case');
SET @s := IF(@idx = 0, 'CREATE INDEX idx_documents_case ON documents (case_id)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND CONSTRAINT_NAME = 'fk_documents_case');
SET @s := IF(@fk = 0, 'ALTER TABLE documents ADD CONSTRAINT fk_documents_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
