-- ============================================================
-- ترقية قاعدة بيانات مواثيق — الإصدار 13
-- شغّل هذا الملف مرة واحدة فقط بعد upgrade_v2..v12
-- يضيف ميزة "الصياغة القانونية بالذكاء الاصطناعي" (Legal Drafting)
-- دون حذف أي بيانات
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS draft_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED DEFAULT NULL,
    doc_type VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    party_a VARCHAR(255) DEFAULT NULL,
    party_b VARCHAR(255) DEFAULT NULL,
    details TEXT DEFAULT NULL,
    generated_content MEDIUMTEXT DEFAULT NULL,
    language ENUM('ar','en') NOT NULL DEFAULT 'ar',
    status ENUM('generated','failed') NOT NULL DEFAULT 'generated',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
