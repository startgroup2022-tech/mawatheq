-- Security telemetry for grounded knowledge access.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS knowledge_access_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id BIGINT UNSIGNED NOT NULL,
    actor_type ENUM('client','lawyer') NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    module_id VARCHAR(80) NOT NULL,
    case_id INT UNSIGNED DEFAULT NULL,
    query_hash CHAR(64) NOT NULL,
    accessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_knowledge_access_source (source_id,accessed_at),
    INDEX idx_knowledge_access_actor (actor_type,actor_id,accessed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
