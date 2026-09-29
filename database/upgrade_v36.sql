-- v36: draft invoices from billable case time, with one-time billing links.
SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='is_draft');
SET @s := IF(@c=0,'ALTER TABLE invoices ADD COLUMN is_draft TINYINT(1) NOT NULL DEFAULT 0 AFTER status','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_time_entries' AND COLUMN_NAME='invoice_id');
SET @s := IF(@c=0,'ALTER TABLE case_time_entries ADD COLUMN invoice_id INT UNSIGNED DEFAULT NULL AFTER billable','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_time_entries' AND INDEX_NAME='idx_case_time_invoice');
SET @s := IF(@c=0,'ALTER TABLE case_time_entries ADD INDEX idx_case_time_invoice (invoice_id)','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='case_time_entries' AND CONSTRAINT_NAME='fk_case_time_invoice');
SET @s := IF(@c=0,'ALTER TABLE case_time_entries ADD CONSTRAINT fk_case_time_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
