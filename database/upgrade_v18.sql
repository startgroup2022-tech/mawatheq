-- Client review and approval workflow for lawyer-generated drafts.
SET NAMES utf8mb4;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND COLUMN_NAME='approval_status');
SET @s := IF(@c=0, "ALTER TABLE draft_documents ADD COLUMN approval_status ENUM('draft','submitted','approved','changes_requested') NOT NULL DEFAULT 'draft', ADD COLUMN client_visible TINYINT(1) NOT NULL DEFAULT 0", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
CREATE TABLE IF NOT EXISTS draft_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    draft_document_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    decision ENUM('approved','changes_requested') NOT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (draft_document_id) REFERENCES draft_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_draft_reviews_document (draft_document_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
