-- ============================================================
-- ترقية قاعدة بيانات مواثيق — الإصدار 2
-- شغّل هذا الملف مرة واحدة فقط إذا كنت استوردت schema.sql سابقاً
-- (يضيف الجداول والأعمدة الجديدة دون حذف أي بيانات موجودة)
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS consultation_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consultation_id INT UNSIGNED NOT NULL,
    sender_type ENUM('client','lawyer') NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED DEFAULT NULL,
    note TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- أضف عمود room_code فقط إن لم يكن موجوداً (متوافق مع MySQL 5.7+)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultations' AND COLUMN_NAME = 'room_code'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE consultations ADD COLUMN room_code VARCHAR(64) DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- إضافة إعدادات مزوّد الذكاء الاصطناعي إن لم تكن موجودة
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
('ai_provider', ''),
('ai_api_key', ''),
('ai_model', '');
