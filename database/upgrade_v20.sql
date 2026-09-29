-- Per-case legal risk register with likelihood and impact scoring.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS case_risks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    likelihood TINYINT UNSIGNED NOT NULL,
    impact TINYINT UNSIGNED NOT NULL,
    mitigation TEXT DEFAULT NULL,
    status ENUM('open','mitigated') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    INDEX idx_case_risks_case (case_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
