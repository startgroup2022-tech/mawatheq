-- ============================================================
-- ترقية قاعدة بيانات مواثيق — الإصدار 11
-- شغّل هذا الملف مرة واحدة فقط بعد upgrade_v2..v10
-- يضيف: (أ) نظام إشعارات داخل المنصة (عميل/محامي)
--        (ب) سجل تدقيق (Audit Log) لعمليات الإدارة الحساسة
-- دون حذف أي بيانات
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_type ENUM('client','lawyer','admin') NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    message VARCHAR(500) DEFAULT NULL,
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recipient (recipient_type, recipient_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type ENUM('admin','lawyer','system') NOT NULL DEFAULT 'admin',
    actor_id INT UNSIGNED DEFAULT NULL,
    actor_name VARCHAR(150) DEFAULT NULL,
    action VARCHAR(255) NOT NULL,
    target_type VARCHAR(50) DEFAULT NULL,
    target_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
