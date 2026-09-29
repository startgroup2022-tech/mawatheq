-- ============================================================
-- ترقية قاعدة بيانات مواثيق — الإصدار 12
-- شغّل هذا الملف مرة واحدة فقط بعد upgrade_v2..v11
-- يضيف حقل "الجهة القضائية/الدولة" للمحامين ومكتبة الأنظمة
-- (تعدد الاختصاص القضائي) دون حذف أي بيانات
-- ============================================================

SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='jurisdiction');
SET @s := IF(@c=0, "ALTER TABLE lawyers ADD COLUMN jurisdiction VARCHAR(100) NOT NULL DEFAULT 'السعودية'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='law_categories' AND COLUMN_NAME='jurisdiction');
SET @s := IF(@c=0, "ALTER TABLE law_categories ADD COLUMN jurisdiction VARCHAR(100) NOT NULL DEFAULT 'السعودية'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
