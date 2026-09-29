-- ============================================================
-- ترقية قاعدة بيانات مواثيق — الإصدار 9
-- شغّل هذا الملف مرة واحدة فقط بعد upgrade_v2..v8
-- يضيف جدول أوقات دوام المحامي الأسبوعية لنظام حجز مواعيد منظّم
-- (يمنع الحجز خارج أوقات الدوام وتعارض المواعيد) دون حذف أي بيانات
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS lawyer_availability (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL, -- 0=الأحد ... 6=السبت
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    slot_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
