-- ============================================================================
-- Mawatheq Platform — support center + customer transaction history (Execution 65)
-- upgrade_v71.sql
-- ============================================================================
--
-- WHAT THIS ADDS
--
--   1) support_ticket_categories / support_tickets / support_ticket_messages /
--      support_ticket_attachments — the support ticket workflow (create, reply,
--      status, assignment, attachments). Attachments are validated with the same
--      hardened sniffing the consultation attachments use (includes/consultation_attachments.php)
--      but stored under their own root, so a ticket file can never be fetched
--      through a consultation route or vice versa.
--
--   2) support_appointment_slots / support_appointments — the "book a support
--      appointment" channel. Availability is operator-managed (an operator opens
--      slots); a customer requests one slot; an operator confirms or cancels.
--
--   3) site_settings keys for the support channels (email, WhatsApp, working
--      hours, per-channel enable/disable). The email and WhatsApp keys are seeded
--      from the values the platform ALREADY ships (contact_email, whatsapp_number)
--      so no new address or number is invented here — this migration never
--      fabricates a contact detail.
--
-- WHAT THIS DELIBERATELY DOES NOT DO
--
--   * No existing table, column, row or index is dropped, renamed or re-typed.
--   * No positional `AFTER` clause names a column. A positional AFTER naming a
--     column that does not exist aborts the statement and MySQL stops at the
--     first error, so everything below it silently never runs while the migration
--     still looks applied. (Execution 28/29 failure mode.) Column order is cosmetic.
--   * No support email, phone number or WhatsApp number is invented. The seed
--     copies what already exists; when the existing value is absent the key is
--     created empty and the channel reports itself unconfigured.
--
-- Idempotent: every CREATE is IF NOT EXISTS and every seed is INSERT ... WHERE NOT
-- EXISTS, so a re-run is a no-op. This matters because a deployment may legitimately
-- replay the file (see AGENTS.md).
-- ============================================================================

-- ------------------------------------------------------------
-- فئات تذاكر الدعم
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS support_ticket_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name_ar VARCHAR(120) NOT NULL,
    name_en VARCHAR(120) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- تذاكر الدعم
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS support_tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Human reference shown to the customer, e.g. TKT-2026-000123.
    ticket_number VARCHAR(24) NOT NULL,
    user_type ENUM('client','lawyer') NOT NULL DEFAULT 'client',
    user_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED DEFAULT NULL,
    subject VARCHAR(255) NOT NULL,
    status ENUM('open','pending','answered','closed') NOT NULL DEFAULT 'open',
    priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
    assigned_admin_id INT UNSIGNED DEFAULT NULL,
    -- Which side the last message came from, and who has not read it yet. Kept as
    -- counters rather than derived, so the unread badge is one indexed read.
    last_reply_at DATETIME DEFAULT NULL,
    last_reply_by ENUM('customer','support') DEFAULT NULL,
    unread_for_customer TINYINT(1) NOT NULL DEFAULT 0,
    unread_for_support TINYINT(1) NOT NULL DEFAULT 1,
    closed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_support_ticket_number (ticket_number),
    KEY idx_support_tickets_owner (user_type, user_id, status),
    KEY idx_support_tickets_status (status, updated_at),
    KEY idx_support_tickets_assignee (assigned_admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- رسائل التذكرة (المحادثة)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS support_ticket_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    author_type ENUM('customer','support','system') NOT NULL,
    author_id INT UNSIGNED DEFAULT NULL,
    author_name VARCHAR(191) DEFAULT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_support_messages_ticket (ticket_id, created_at),
    CONSTRAINT fk_support_messages_ticket FOREIGN KEY (ticket_id)
        REFERENCES support_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- مرفقات التذاكر — تُخزَّن تحت جذر خاص بها، ولا تُقدَّم إلا عبر مسار محمي
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS support_ticket_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    message_id INT UNSIGNED DEFAULT NULL,
    uploaded_by_type ENUM('customer','support') NOT NULL DEFAULT 'customer',
    uploaded_by_id INT UNSIGNED DEFAULT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(191) NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(127) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    checksum CHAR(64) DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_support_attachment_stored (stored_name),
    KEY idx_support_attachments_ticket (ticket_id),
    CONSTRAINT fk_support_attachments_ticket FOREIGN KEY (ticket_id)
        REFERENCES support_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- مواعيد الدعم المتاحة (يديرها المشرف)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS support_appointment_slots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_support_slot_start (starts_at),
    KEY idx_support_slots_active (is_active, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- طلبات مواعيد الدعم
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS support_appointments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(24) NOT NULL,
    slot_id INT UNSIGNED NOT NULL,
    user_type ENUM('client','lawyer') NOT NULL DEFAULT 'client',
    user_id INT UNSIGNED NOT NULL,
    subject VARCHAR(255) NOT NULL,
    note TEXT DEFAULT NULL,
    status ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending',
    admin_note TEXT DEFAULT NULL,
    decided_by_admin_id INT UNSIGNED DEFAULT NULL,
    decided_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_support_appt_reference (reference),
    -- One request per customer per slot: the database, not the form, is what
    -- prevents a double booking from a double tap or a replayed request.
    UNIQUE KEY uq_support_appt_slot_user (slot_id, user_type, user_id),
    KEY idx_support_appt_owner (user_type, user_id, status),
    KEY idx_support_appt_slot (slot_id, status),
    CONSTRAINT fk_support_appt_slot FOREIGN KEY (slot_id)
        REFERENCES support_appointment_slots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- موافقات المستخدم (الخصوصية) — تفضيلات اختيارية لكل حساب.
-- لا تُسجَّل موافقة نيابةً عن المستخدم: الجدول يبدأ فارغاً، والغياب يعني
-- "لم يُعبَّر عن تفضيل" وليس "موافق".
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_consents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('client','lawyer') NOT NULL DEFAULT 'client',
    user_id INT UNSIGNED NOT NULL,
    consent_key VARCHAR(64) NOT NULL,
    granted TINYINT(1) NOT NULL DEFAULT 0,
    granted_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_consent (user_type, user_id, consent_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- الفئات الافتراضية — فئات عامة قابلة للتعديل من لوحة الإدارة.
-- لا تُنشأ إن كانت هناك فئة واحدة بالفعل، فإعادة التشغيل لا تُكرّرها.
-- ------------------------------------------------------------
INSERT INTO support_ticket_categories (name_ar, name_en, sort_order)
SELECT 'استفسار عام', 'General enquiry', 10 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM support_ticket_categories);
INSERT INTO support_ticket_categories (name_ar, name_en, sort_order)
SELECT 'مشكلة تقنية', 'Technical issue', 20 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM support_ticket_categories WHERE name_en = 'Technical issue');
INSERT INTO support_ticket_categories (name_ar, name_en, sort_order)
SELECT 'الفواتير والدفع', 'Billing and payments', 30 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM support_ticket_categories WHERE name_en = 'Billing and payments');
INSERT INTO support_ticket_categories (name_ar, name_en, sort_order)
SELECT 'الاستشارات والمواعيد', 'Consultations and appointments', 40 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM support_ticket_categories WHERE name_en = 'Consultations and appointments');

-- ------------------------------------------------------------
-- إعدادات قنوات الدعم — تنسخ القيم الموجودة فعلاً، ولا تخترع واحدة.
-- support_email ينسخ contact_email، و support_whatsapp ينسخ whatsapp_number.
-- ------------------------------------------------------------
INSERT INTO site_settings (setting_key, setting_value)
SELECT 'support_email', COALESCE((SELECT setting_value FROM site_settings WHERE setting_key='contact_email'), '')
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='support_email');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'support_whatsapp', COALESCE((SELECT setting_value FROM site_settings WHERE setting_key='whatsapp_number'), '')
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='support_whatsapp');

-- Channels are enabled only when the operator has configured them; the reader
-- also checks the value is non-empty, so an empty seed reads as "disabled".
INSERT INTO site_settings (setting_key, setting_value)
SELECT 'support_email_enabled', '1'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='support_email_enabled');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'support_whatsapp_enabled', '1'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='support_whatsapp_enabled');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'support_tickets_enabled', '1'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='support_tickets_enabled');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'support_appointments_enabled', '1'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='support_appointments_enabled');

-- Working hours are free text an operator writes; empty means "not stated", and
-- the app then simply omits the line rather than printing an invented schedule.
INSERT INTO site_settings (setting_key, setting_value)
SELECT 'support_hours_ar', 'الأحد – الخميس، 9 صباحاً – 5 مساءً'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='support_hours_ar');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'support_hours_en', 'Sunday – Thursday, 9am – 5pm'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='support_hours_en');
