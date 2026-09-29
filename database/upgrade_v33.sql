-- v33: firm-level staged review and approval history for legal drafts.
SET NAMES utf8mb4;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND COLUMN_NAME='approval_stage');
SET @s := IF(@c=0,"ALTER TABLE draft_documents ADD COLUMN approval_stage ENUM('draft','lawyer_review','partner_review','approved','rejected','changes_requested') NOT NULL DEFAULT 'draft' AFTER approval_status",'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND COLUMN_NAME='approval_firm_id');
SET @s := IF(@c=0,'ALTER TABLE draft_documents ADD COLUMN approval_firm_id INT UNSIGNED DEFAULT NULL AFTER approval_stage','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND INDEX_NAME='idx_draft_approval_queue');
SET @s := IF(@c=0,'ALTER TABLE draft_documents ADD INDEX idx_draft_approval_queue (approval_firm_id,approval_stage,status)','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND COLUMN_NAME='approval_version');
SET @s := IF(@c=0,'ALTER TABLE draft_documents ADD COLUMN approval_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER approval_stage','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND COLUMN_NAME='internal_review_started_at');
SET @s := IF(@c=0,'ALTER TABLE draft_documents ADD COLUMN internal_review_started_at DATETIME DEFAULT NULL AFTER approval_version','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
CREATE TABLE IF NOT EXISTS draft_workflow_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    draft_document_id INT UNSIGNED NOT NULL,
    reviewer_lawyer_id INT UNSIGNED NOT NULL,
    stage ENUM('lawyer_review','partner_review') NOT NULL,
    decision ENUM('approved','changes_requested','rejected') NOT NULL,
    notes TEXT DEFAULT NULL,
    version_number INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_draft_workflow_history (draft_document_id,version_number,created_at),
    FOREIGN KEY (draft_document_id) REFERENCES draft_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS draft_document_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    draft_document_id INT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    content MEDIUMTEXT NOT NULL,
    saved_by_lawyer_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_draft_version (draft_document_id,version_number),
    FOREIGN KEY (draft_document_id) REFERENCES draft_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (saved_by_lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO draft_document_versions (draft_document_id,version_number,content,saved_by_lawyer_id,created_at)
SELECT id,approval_version,generated_content,lawyer_id,created_at FROM draft_documents
WHERE status='generated' AND generated_content IS NOT NULL AND generated_content<>'';
