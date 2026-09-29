-- v29: semantic-search embedding columns and embedding provider settings.
SET NAMES utf8mb4;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='knowledge_chunks' AND COLUMN_NAME='embedding');
SET @s := IF(@c=0,'ALTER TABLE knowledge_chunks ADD COLUMN embedding MEDIUMTEXT DEFAULT NULL AFTER chunk_text','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='knowledge_chunks' AND COLUMN_NAME='embedding_model');
SET @s := IF(@c=0,'ALTER TABLE knowledge_chunks ADD COLUMN embedding_model VARCHAR(60) DEFAULT NULL AFTER embedding','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_articles' AND COLUMN_NAME='embedding');
SET @s := IF(@c=0,'ALTER TABLE law_articles ADD COLUMN embedding MEDIUMTEXT DEFAULT NULL','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_articles' AND COLUMN_NAME='embedding_model');
SET @s := IF(@c=0,'ALTER TABLE law_articles ADD COLUMN embedding_model VARCHAR(60) DEFAULT NULL','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_articles' AND COLUMN_NAME='created_at');
SET @s := IF(@c=0,'ALTER TABLE law_articles ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
INSERT IGNORE INTO site_settings(setting_key,setting_value) VALUES ('embedding_provider',''),('embedding_api_key',''),('embedding_model','text-embedding-3-small');
CREATE TABLE IF NOT EXISTS embedding_reindex_log (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_by INT UNSIGNED DEFAULT NULL,
 scope ENUM('knowledge','law_articles') NOT NULL, processed INT UNSIGNED NOT NULL DEFAULT 0,
 failed INT UNSIGNED NOT NULL DEFAULT 0, started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
