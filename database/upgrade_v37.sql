-- v37: Bahraini dinar (BHD) monetary precision (three decimal places).
-- Existing numeric amounts are preserved; this changes precision only, not values.
SET NAMES utf8mb4;

-- Repair tables used by the lawyer workspace when an older upgrade stopped
-- part-way through. These CREATE statements are safe to repeat.
CREATE TABLE IF NOT EXISTS lawyer_availability (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    slot_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS case_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    due_date DATE DEFAULT NULL,
    status ENUM('pending','done') NOT NULL DEFAULT 'pending',
    client_visible TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS law_firms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    owner_lawyer_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    UNIQUE KEY uq_law_firm_owner (owner_lawyer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS law_firm_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    member_role ENUM('owner','partner','lawyer','staff') NOT NULL DEFAULT 'lawyer',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_law_firm_member_lawyer (lawyer_id),
    INDEX idx_law_firm_members_firm (firm_id,member_role),
    FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE CASCADE,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS firm_knowledge (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    author_lawyer_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(100) DEFAULT NULL,
    content LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_firm_knowledge_firm (firm_id,updated_at),
    FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE CASCADE,
    FOREIGN KEY (author_lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS legal_calendar_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED DEFAULT NULL,
    event_type ENUM('court_session','appeal_deadline','contract_expiry','contract_renewal','government_deadline','other') NOT NULL DEFAULT 'other',
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    event_at DATETIME NOT NULL,
    status ENUM('upcoming','completed','cancelled') NOT NULL DEFAULT 'upcoming',
    reminder_sent_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_legal_calendar_lawyer_date (lawyer_id,event_at,status),
    INDEX idx_legal_calendar_case (case_id,event_at),
    CONSTRAINT fk_legal_calendar_lawyer FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    CONSTRAINT fk_legal_calendar_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS draft_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED DEFAULT NULL,
    doc_type VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    party_a VARCHAR(255) DEFAULT NULL,
    party_b VARCHAR(255) DEFAULT NULL,
    details TEXT DEFAULT NULL,
    generated_content MEDIUMTEXT DEFAULT NULL,
    language ENUM('ar','en') NOT NULL DEFAULT 'ar',
    status ENUM('generated','failed') NOT NULL DEFAULT 'generated',
    approval_status ENUM('draft','submitted','approved','changes_requested') NOT NULL DEFAULT 'draft',
    approval_stage ENUM('draft','lawyer_review','partner_review','approved','rejected','changes_requested') NOT NULL DEFAULT 'draft',
    approval_firm_id INT UNSIGNED DEFAULT NULL,
    approval_version INT UNSIGNED NOT NULL DEFAULT 1,
    internal_review_started_at DATETIME DEFAULT NULL,
    client_visible TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_draft_approval_queue (approval_firm_id,approval_stage,status),
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND COLUMN_NAME='approval_status');
SET @s := IF(@c=0, "ALTER TABLE draft_documents ADD COLUMN approval_status ENUM('draft','submitted','approved','changes_requested') NOT NULL DEFAULT 'draft', ADD COLUMN client_visible TINYINT(1) NOT NULL DEFAULT 0", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND COLUMN_NAME='approval_stage');
SET @s := IF(@c=0, "ALTER TABLE draft_documents ADD COLUMN approval_stage ENUM('draft','lawyer_review','partner_review','approved','rejected','changes_requested') NOT NULL DEFAULT 'draft' AFTER approval_status", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND COLUMN_NAME='approval_firm_id');
SET @s := IF(@c=0, 'ALTER TABLE draft_documents ADD COLUMN approval_firm_id INT UNSIGNED DEFAULT NULL AFTER approval_stage', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND COLUMN_NAME='approval_version');
SET @s := IF(@c=0, 'ALTER TABLE draft_documents ADD COLUMN approval_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER approval_firm_id', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND COLUMN_NAME='internal_review_started_at');
SET @s := IF(@c=0, 'ALTER TABLE draft_documents ADD COLUMN internal_review_started_at DATETIME DEFAULT NULL AFTER approval_version', 'SELECT 1');
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
    INDEX idx_draft_reviews_document (draft_document_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

ALTER TABLE lawyers MODIFY hourly_rate DECIMAL(12,3) DEFAULT NULL;
ALTER TABLE plans MODIFY price_monthly DECIMAL(12,3) NOT NULL DEFAULT 0;
ALTER TABLE plans MODIFY price_yearly DECIMAL(12,3) DEFAULT NULL;
ALTER TABLE consultations MODIFY price DECIMAL(12,3) DEFAULT NULL;
ALTER TABLE case_time_entries MODIFY hourly_rate DECIMAL(12,3) NOT NULL DEFAULT 0;
ALTER TABLE invoices MODIFY amount DECIMAL(12,3) NOT NULL;
ALTER TABLE payment_transactions MODIFY amount DECIMAL(12,3) DEFAULT NULL;
