-- ============================================================
-- Mawatheq upgrade v46 — audit actor 'client' + document audit support
-- Non-destructive. Safe to re-run.
-- ============================================================

-- Client portal actions (login/logout, document upload) are now auditable, so the
-- audit_logs.actor_type enum must accept 'client' alongside admin/lawyer/system.
ALTER TABLE audit_logs
    MODIFY COLUMN actor_type ENUM('admin','lawyer','client','system') NOT NULL DEFAULT 'admin';

-- Audit queries are ordered by created_at; an index keeps the console snappy as the log grows.
-- (Added only if missing so re-running the migration is harmless.)
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND INDEX_NAME = 'idx_audit_created');
SET @sql := IF(@idx = 0, 'CREATE INDEX idx_audit_created ON audit_logs (created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
