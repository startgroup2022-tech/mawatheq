-- ============================================================
-- ترقية قاعدة بيانات مواثيق — الإصدار 4
-- شغّل هذا الملف مرة واحدة فقط بعد schema.sql و upgrade_v2.sql و upgrade_v3.sql
-- يضيف حقول اكتمال ملف المحامي (المؤهلات + وسائل التواصل) دون حذف أي بيانات
-- ============================================================

SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='qualifications');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN qualifications TEXT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='social_linkedin');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN social_linkedin VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='social_twitter');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN social_twitter VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='social_website');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN social_website VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
