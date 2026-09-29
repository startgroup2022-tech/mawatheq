-- ============================================================
-- ترقية قاعدة بيانات مواثيق — الإصدار 5
-- شغّل هذا الملف مرة واحدة فقط بعد upgrade_v2/v3/v4
-- يضيف نظام فوترة حقيقي (فواتير) دون حذف أي بيانات
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED DEFAULT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('unpaid','paid','overdue','cancelled') NOT NULL DEFAULT 'unpaid',
    due_date DATE DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    payment_method VARCHAR(50) DEFAULT NULL,
    payment_note VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
