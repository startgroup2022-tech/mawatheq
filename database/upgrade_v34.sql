-- v34: save user-owned AI comparisons of previously analyzed contracts.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS contract_comparisons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    first_document_id INT UNSIGNED NOT NULL,
    second_document_id INT UNSIGNED NOT NULL,
    comparison_result MEDIUMTEXT DEFAULT NULL,
    comparison_status ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
    model VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_contract_comparison_user (user_id,created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (first_document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (second_document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
