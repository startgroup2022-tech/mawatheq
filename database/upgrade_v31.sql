-- v31: lawyer-managed court, appeal, contract, and regulatory calendar events.
SET NAMES utf8mb4;
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
