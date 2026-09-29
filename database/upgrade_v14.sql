-- ============================================================
-- ترقية قاعدة بيانات مواثيق — الإصدار 14
-- شغّل هذا الملف مرة واحدة فقط بعد upgrade_v2..v13
-- يضيف جدول رموز الدخول (API Tokens) لواجهة برمجية REST حقيقية
-- يستخدمها تطبيق الجوال (Flutter) بدلاً من صفحات HTML
-- دون حذف أي بيانات
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS api_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    user_type ENUM('client','lawyer') NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    device_label VARCHAR(150) DEFAULT NULL,
    last_used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME DEFAULT NULL,
    INDEX idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
