-- v35: case fact register and evidence links to facts and verified law articles.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS case_facts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    occurred_at DATE DEFAULT NULL,
    source_note TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    INDEX idx_case_facts_case_date (case_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_evidence' AND COLUMN_NAME='related_fact_id');
SET @s := IF(@c=0,'ALTER TABLE case_evidence ADD COLUMN related_fact_id INT UNSIGNED DEFAULT NULL AFTER related_claim_id','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_evidence' AND COLUMN_NAME='related_law_article_id');
SET @s := IF(@c=0,'ALTER TABLE case_evidence ADD COLUMN related_law_article_id INT UNSIGNED DEFAULT NULL AFTER related_fact_id','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='case_evidence' AND CONSTRAINT_NAME='fk_case_evidence_fact');
SET @s := IF(@c=0,'ALTER TABLE case_evidence ADD CONSTRAINT fk_case_evidence_fact FOREIGN KEY (related_fact_id) REFERENCES case_facts(id) ON DELETE SET NULL','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='case_evidence' AND CONSTRAINT_NAME='fk_case_evidence_law_article');
SET @s := IF(@c=0,'ALTER TABLE case_evidence ADD CONSTRAINT fk_case_evidence_law_article FOREIGN KEY (related_law_article_id) REFERENCES law_articles(id) ON DELETE SET NULL','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
