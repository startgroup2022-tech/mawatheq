-- ============================================================
-- ترقية قاعدة بيانات مواثيق — الإصدار 3
-- شغّل هذا الملف مرة واحدة فقط بعد schema.sql و upgrade_v2.sql
-- يضيف أعمدة تتبع المصدر (حملة / قناة / صفحة الدخول) دون حذف أي بيانات
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- دالة مساعدة: تضيف عموداً فقط إن لم يكن موجوداً (متوافقة مع MySQL 5.7+)
-- ------------------------------------------------------------

-- users.utm_source
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='utm_source');
SET @s := IF(@c=0, 'ALTER TABLE users ADD COLUMN utm_source VARCHAR(100) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.utm_medium
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='utm_medium');
SET @s := IF(@c=0, 'ALTER TABLE users ADD COLUMN utm_medium VARCHAR(100) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.utm_campaign
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='utm_campaign');
SET @s := IF(@c=0, 'ALTER TABLE users ADD COLUMN utm_campaign VARCHAR(150) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.source_page
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='source_page');
SET @s := IF(@c=0, 'ALTER TABLE users ADD COLUMN source_page VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- lawyers.utm_source
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='utm_source');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN utm_source VARCHAR(100) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- lawyers.utm_medium
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='utm_medium');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN utm_medium VARCHAR(100) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- lawyers.utm_campaign
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='utm_campaign');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN utm_campaign VARCHAR(150) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- lawyers.source_page
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='lawyers' AND COLUMN_NAME='source_page');
SET @s := IF(@c=0, 'ALTER TABLE lawyers ADD COLUMN source_page VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- contact_leads.utm_source
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='contact_leads' AND COLUMN_NAME='utm_source');
SET @s := IF(@c=0, 'ALTER TABLE contact_leads ADD COLUMN utm_source VARCHAR(100) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- contact_leads.utm_medium
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='contact_leads' AND COLUMN_NAME='utm_medium');
SET @s := IF(@c=0, 'ALTER TABLE contact_leads ADD COLUMN utm_medium VARCHAR(100) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- contact_leads.utm_campaign
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='contact_leads' AND COLUMN_NAME='utm_campaign');
SET @s := IF(@c=0, 'ALTER TABLE contact_leads ADD COLUMN utm_campaign VARCHAR(150) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- contact_leads.source_page
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='contact_leads' AND COLUMN_NAME='source_page');
SET @s := IF(@c=0, 'ALTER TABLE contact_leads ADD COLUMN source_page VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ملاحظة: عمود "phone" في الجداول الثلاثة يبقى كما هو (VARCHAR)،
-- لكنه الآن يُخزَّن دائماً بصيغة دولية موحّدة تشمل رمز الدولة، مثل: +9665xxxxxxxx
