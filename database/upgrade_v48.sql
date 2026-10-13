-- ============================================================
-- Mawatheq upgrade v48 — legal-aware chunk provenance
-- Non-destructive. Safe to re-run.
-- ============================================================

-- Legal-aware chunking records where a chunk came from so an answer can cite
-- the article/clause and page, not just the document title. Every column is
-- nullable: when the source has no detectable structure the value stays NULL
-- rather than being guessed, so a citation never points at an invented article.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND COLUMN_NAME = 'heading_path');
SET @s := IF(@c = 0, 'ALTER TABLE knowledge_chunks ADD COLUMN heading_path VARCHAR(500) DEFAULT NULL AFTER chunk_text', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND COLUMN_NAME = 'section_label');
SET @s := IF(@c = 0, 'ALTER TABLE knowledge_chunks ADD COLUMN section_label VARCHAR(255) DEFAULT NULL AFTER heading_path', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND COLUMN_NAME = 'article_label');
SET @s := IF(@c = 0, 'ALTER TABLE knowledge_chunks ADD COLUMN article_label VARCHAR(255) DEFAULT NULL AFTER section_label', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND COLUMN_NAME = 'clause_label');
SET @s := IF(@c = 0, 'ALTER TABLE knowledge_chunks ADD COLUMN clause_label VARCHAR(255) DEFAULT NULL AFTER article_label', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND COLUMN_NAME = 'page_no');
SET @s := IF(@c = 0, 'ALTER TABLE knowledge_chunks ADD COLUMN page_no INT UNSIGNED DEFAULT NULL AFTER clause_label', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND COLUMN_NAME = 'chunk_kind');
SET @s := IF(@c = 0, 'ALTER TABLE knowledge_chunks ADD COLUMN chunk_kind VARCHAR(20) DEFAULT NULL AFTER page_no', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND INDEX_NAME = 'idx_knowledge_chunk_article');
SET @s := IF(@idx = 0, 'CREATE INDEX idx_knowledge_chunk_article ON knowledge_chunks (article_label)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
