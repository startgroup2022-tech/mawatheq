-- ============================================================
-- ترقية قاعدة بيانات مواثيق — الإصدار 7
-- شغّل هذا الملف مرة واحدة فقط بعد upgrade_v2..v6
-- يضيف أعمدة "نسيت كلمة المرور" لكل من العملاء، المحامين، والإدارة
-- ============================================================

SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='reset_token');
SET @s := IF(@c=0, 'ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL, ADD COLUMN reset_expires DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='reset_token');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL, ADD COLUMN reset_expires DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='reset_token');
SET @s := IF(@c=0, 'ALTER TABLE admins ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL, ADD COLUMN reset_expires DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
