-- v32: persist structured contract intelligence results per uploaded document.
SET NAMES utf8mb4;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='document_type');
SET @s := IF(@c=0,"ALTER TABLE documents ADD COLUMN document_type ENUM('general','contract') NOT NULL DEFAULT 'general' AFTER file_type",'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='contract_analysis_json');
SET @s := IF(@c=0,'ALTER TABLE documents ADD COLUMN contract_analysis_json MEDIUMTEXT DEFAULT NULL AFTER summary','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
