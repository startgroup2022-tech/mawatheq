-- ============================================================
-- ترقية قاعدة بيانات مواثيق — الإصدار 6
-- شغّل هذا الملف مرة واحدة فقط بعد upgrade_v2..v5
-- يحسّن بحث مكتبة الأنظمة (فهرس بحث نصي كامل) ويضيف رابط استشهاد
-- قابل للنقر لردود المساعد الذكي — دون حذف أي بيانات
-- ============================================================

SET NAMES utf8mb4;

-- فهرس بحث نصي كامل (FULLTEXT) على مكتبة الأنظمة لتحسين جودة نتائج البحث
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='law_articles' AND INDEX_NAME='ft_law_search'
);
SET @s := IF(@idx=0, 'ALTER TABLE law_articles ADD FULLTEXT INDEX ft_law_search (law_name_ar, content_ar, content_en)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- عمود لربط رد المساعد الذكي بمادة نظامية محددة (لعرض استشهاد قابل للنقر يؤدي لنص المادة الكامل)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='ai_messages' AND COLUMN_NAME='reference_article_id');
SET @s := IF(@c=0, 'ALTER TABLE ai_messages ADD COLUMN reference_article_id INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
