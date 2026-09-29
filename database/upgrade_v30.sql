-- v30: source verification and AI answer review audit trail.
SET NAMES utf8mb4;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_articles' AND COLUMN_NAME='verification_status');
SET @s := IF(@c=0,"ALTER TABLE law_articles ADD COLUMN verification_status ENUM('unverified','verified') NOT NULL DEFAULT 'verified' AFTER embedding_model",'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
CREATE TABLE IF NOT EXISTS ai_answer_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_type ENUM('client','lawyer','admin','anonymous') NOT NULL DEFAULT 'anonymous',
 actor_id INT UNSIGNED DEFAULT NULL, module_id VARCHAR(60) NOT NULL DEFAULT 'legal_core',
 question_hash CHAR(64) NOT NULL, question_excerpt VARCHAR(500) DEFAULT NULL,
 retrieval_method ENUM('none','keyword','semantic','hybrid') NOT NULL DEFAULT 'none',
 sources_json TEXT DEFAULT NULL,
 review_status ENUM('reviewed','source_only','unverified','no_source') NOT NULL,
 hallucination_risk ENUM('low','medium','high','n_a') NOT NULL DEFAULT 'n_a',
 unsupported_claims TEXT DEFAULT NULL, citation_integrity_ok TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_answer_review_actor(actor_type,actor_id,created_at),
 INDEX idx_answer_review_status(review_status,hallucination_risk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
