-- v38: Evidence Intelligence — conflict & missing-evidence AI detection reports.
-- Closes gap-list items under "نواقص Evidence Intelligence": كشف تعارض الأدلة، كشف الأدلة الناقصة، Evidence AI reports.
CREATE TABLE IF NOT EXISTS case_evidence_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    conflicts_json MEDIUMTEXT DEFAULT NULL,
    missing_evidence_json MEDIUMTEXT DEFAULT NULL,
    summary TEXT DEFAULT NULL,
    model VARCHAR(100) DEFAULT NULL,
    evidence_count_at_run INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    INDEX idx_case_evidence_reports_case (case_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
