-- Optional AI analysis results for evidence files.
SET NAMES utf8mb4;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_evidence' AND COLUMN_NAME='ai_summary');
SET @s := IF(@c=0, 'ALTER TABLE case_evidence ADD COLUMN ai_summary TEXT DEFAULT NULL, ADD COLUMN ai_findings TEXT DEFAULT NULL, ADD COLUMN ai_analyzed_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
