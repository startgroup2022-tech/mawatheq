-- ============================================================
-- ترقية قاعدة بيانات مواثيق — الإصدار 15
-- شغّل هذا الملف مرة واحدة فقط بعد upgrade_v2..v14
-- يضيف ربط فعلي ببوابة الدفع السعودية N-Genius (سداد/مدى/فيزا/ماستركارد)
-- دون حذف أي بيانات
-- ============================================================

SET NAMES utf8mb4;

-- تتبّع طلب الدفع في بوابة N-Genius لكل فاتورة
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='gateway_order_ref');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN gateway_order_ref VARCHAR(100) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='gateway');
SET @s := IF(@c=0, "ALTER TABLE invoices ADD COLUMN gateway VARCHAR(30) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- سجل كل عمليات/أحداث بوابة الدفع (لتتبّع الأخطاء والمطابقة، وليس فقط للثقة بالتحويل التلقائي)
CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED DEFAULT NULL,
    gateway VARCHAR(30) NOT NULL DEFAULT 'ngenius',
    gateway_order_ref VARCHAR(100) DEFAULT NULL,
    event_type VARCHAR(50) DEFAULT NULL,
    amount DECIMAL(10,2) DEFAULT NULL,
    raw_payload TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- إعدادات بوابة الدفع الافتراضية (فارغة، تُعبّأ من لوحة التحكم)
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
('ngenius_enabled', '0'),
('ngenius_environment', 'sandbox'),
('ngenius_outlet_ref', ''),
('ngenius_api_key', ''),
('ngenius_realm_name', 'ni'),
('ngenius_webhook_secret', '');
