-- Case evidence register. Safe to run once on databases upgraded through v16.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS case_evidence (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    added_by_lawyer_id INT UNSIGNED NOT NULL,
    related_claim_id INT UNSIGNED DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    evidence_type ENUM('document','contract','correspondence','photo','other') NOT NULL DEFAULT 'document',
    description TEXT DEFAULT NULL,
    original_name VARCHAR(255) DEFAULT NULL,
    stored_name VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by_lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    FOREIGN KEY (related_claim_id) REFERENCES case_claims(id) ON DELETE SET NULL,
    INDEX idx_case_evidence_case (case_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS case_analyses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    analysis LONGTEXT NOT NULL,
    model VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    INDEX idx_case_analyses_case (case_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
