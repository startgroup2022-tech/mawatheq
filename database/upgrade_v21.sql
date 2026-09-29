-- Manual time tracking and billable value per case.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS case_time_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    description VARCHAR(255) NOT NULL,
    minutes SMALLINT UNSIGNED NOT NULL,
    hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
    billable TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    INDEX idx_case_time_case (case_id,work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
