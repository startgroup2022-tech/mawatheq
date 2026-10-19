-- ============================================================================
-- Execution 34 — Restricted administrative staff accounts
-- upgrade_v60.sql
-- ============================================================================
--
-- ADDITIVE ONLY. Nothing is dropped, renamed or rewritten, and no existing row is
-- modified. Every CREATE is `IF NOT EXISTS`; every ALTER is guarded by an
-- information_schema test, so the file can be run any number of times.
--
-- No positional AFTER clause is used anywhere. A positional AFTER naming a column
-- that does not exist aborts the statement, and MySQL stops at the first error — so
-- everything below it silently never runs while the migration still looks applied.
-- That is the Execution 28 failure mode. Column placement is left to the server.
--
-- ---------------------------------------------------------------------------
-- WHAT THIS BUILDS, AND WHAT IT DELIBERATELY DOES NOT
--
-- The console already has role-based access control: `admin_route_roles()` in
-- includes/admin_rbac.php maps a script name to the roles allowed to open it. That is
-- a good model for three fixed roles, but it cannot express "this person may read the
-- blog and nothing else", because a role is a single value and the granularity lives
-- in the code.
--
-- This migration adds a fourth role, `staff`, whose access is decided per module by
-- rows in `admin_staff_permissions` rather than by a hardcoded list. The existing
-- roles are untouched: `super_admin` keeps full access, and `editor` / `support`
-- continue to resolve through the same route map they always did. A database that
-- never creates a staff account behaves exactly as it did before this file ran.
--
-- The `staff` role is added to the ENUM by appending it, never by replacing the list.
-- Appending preserves every existing value and its position, so no row's role is
-- reinterpreted.
--
-- ---------------------------------------------------------------------------
-- DENY BY DEFAULT
--
-- A staff account with no rows in `admin_staff_permissions` can reach NOTHING except
-- its own dashboard, which shows only a "no modules assigned" notice. Access is
-- granted by inserting rows, never by the absence of a restriction. The permission
-- check in includes/admin_permissions.php treats an unknown module, an unknown route
-- and an empty grant set identically: denied.
--
-- ---------------------------------------------------------------------------
-- WHY SOME MODULES ARE NOT GRANTABLE AT ALL
--
-- The brief requires that restricted staff have no access to confidential case
-- material, legal documents, privileged communications, client personal data,
-- accounting, payment credentials, API keys, server configuration, security settings,
-- backups, role administration or password hashes — and that dashboard access must
-- never imply any of that.
--
-- Those areas are not merely un-assigned; they are marked `grantable = 0` in
-- `admin_staff_modules` and are refused by the permission library even if a row for
-- them is inserted by hand. Keeping the classification in the database means the
-- console, the guard and any future endpoint read the same answer, and a mistake in
-- one screen cannot open a sensitive area.
--
-- ---------------------------------------------------------------------------
-- SESSIONS AND AUDIT
--
-- `admins.session_invalidated_at` already exists (added by an earlier execution and
-- used by account_session_is_stale()). Revoking a staff member's sessions updates that
-- column; the guard already refuses a session older than it, so no new mechanism is
-- introduced. Every staff action is written to `audit_logs`, which the console can
-- read and the staff member cannot.
-- ============================================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. The `staff` role.
--
-- MODIFY COLUMN with the full ENUM is required to extend an ENUM; there is no ADD
-- VALUE for a column definition in this form. The list below is the existing set with
-- `staff` appended, so every current row keeps its exact value.
-- ------------------------------------------------------------
SET @s := (SELECT IF(
    LOCATE("'staff'", COLUMN_TYPE) > 0,
    'SELECT 1',
    "ALTER TABLE admins MODIFY COLUMN role ENUM('super_admin','editor','support','affiliate_manager','finance_manager','compliance_reviewer','staff') NOT NULL DEFAULT 'editor'"
) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'role');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2. Account state on `admins`.
--
-- `is_active` lets the main administrator switch an employee off without deleting the
-- row, so the audit trail keeps naming the person who did the work. Existing rows are
-- active by default, so no current administrator loses access.
--
-- `created_by` records which administrator created the account, so an employee cannot
-- quietly create peers and the console can show provenance. It is nullable: accounts
-- that predate this file have no creator.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='is_active');
SET @s := IF(@c=0,
    'ALTER TABLE admins ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='created_by');
SET @s := IF(@c=0,
    'ALTER TABLE admins ADD COLUMN created_by INT UNSIGNED DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='last_login_at');
SET @s := IF(@c=0,
    'ALTER TABLE admins ADD COLUMN last_login_at DATETIME DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3. The module catalogue.
--
-- One row per administrative area that can be granted or withheld. `grantable = 0`
-- marks an area the brief forbids granting to restricted staff; the permission library
-- refuses those unconditionally.
--
-- This table is a catalogue, not a source of truth about what exists: a module only
-- has effect if the permission library also maps routes to it. It is seeded so the
-- console can render a complete, explained list rather than only the modules some
-- account happens to hold.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_staff_modules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_key VARCHAR(64) NOT NULL,
    label_ar VARCHAR(150) NOT NULL,
    label_en VARCHAR(150) NOT NULL,
    description_ar VARCHAR(255) DEFAULT NULL,
    description_en VARCHAR(255) DEFAULT NULL,
    -- 0 = must never be granted to a restricted employee. See the header note.
    grantable TINYINT(1) NOT NULL DEFAULT 1,
    -- 1 = the area exposes client/legal/financial material even at view level.
    is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_staff_module_key (module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO admin_staff_modules
    (module_key, label_ar, label_en, description_ar, description_en, grantable, is_sensitive, sort_order)
VALUES
    ('dashboard',        'لوحة التحكم',            'Dashboard',              'ملخص تشغيلي للوحدات المصرّح بها فقط.', 'Operational summary of authorized modules only.', 1, 0, 10),
    ('blog',             'المدونة والإعلانات',     'Blog & announcements',   'المقالات والإعلانات المنشورة.', 'Published articles and announcements.', 1, 0, 30),
    ('laws',             'الأنظمة والتشريعات',     'Laws & regulations',     'قاعدة الأنظمة واللوائح.', 'The statute and regulation library.', 1, 0, 40),
    ('classification',   'التصنيف القضائي',        'Judicial classification','تصنيف ناجز وطلباته القضائية.', 'The Najiz classification and its requests.', 1, 0, 50),
    ('leads',            'طلبات التواصل',          'Contact inquiries',      'الاستفسارات الواردة من الموقع.', 'Inquiries submitted through the site.', 1, 0, 60),
    ('crm',              'إدارة العلاقات',         'CRM',                    'العملاء المحتملون والمهام والمتابعات.', 'Leads, tasks and follow-ups.', 1, 0, 70),
    ('marketing',        'المحتوى التسويقي',       'Marketing content',      'الحملات والمواد التسويقية.', 'Campaigns and marketing material.', 1, 0, 80),
    ('affiliate-applications', 'طلبات المسوّقين',  'Affiliate applications', 'مراجعة طلبات الانضمام لبرنامج الشركاء.', 'Review affiliate programme applications.', 1, 0, 90),
    ('reports',          'التقارير والتحليلات',    'Reports & analytics',    'تقارير تشغيلية غير مالية.', 'Operational, non-financial reports.', 1, 0, 120),
    ('notifications',    'الإشعارات',              'Notifications',          'إشعارات النظام للموظف.', 'System notifications for the employee.', 1, 0, 140),
    ('calendar',         'التقويم',                'Calendar',               'المواعيد والجلسات المجدولة.', 'Appointments and scheduled sessions.', 1, 0, 150),
    -- ---- never grantable to a restricted employee -------------------------
    ('cases',            'ملفات القضايا',          'Case files',             'تفاصيل القضايا والوثائق القانونية.', 'Case detail and legal documents.', 0, 1, 900),
    ('clients',          'بيانات العملاء',         'Client records',         'البيانات الشخصية للعملاء.', 'Client personal information.', 0, 1, 910),
    ('consultations',    'الاستشارات',             'Consultations',          'المراسلات بين المحامي والعميل.', 'Lawyer-client communications.', 0, 1, 920),
    ('finance',          'المالية والمحاسبة',      'Finance & accounting',   'الفواتير والقيود والحسابات البنكية.', 'Invoices, journals and bank details.', 0, 1, 930),
    ('payments',         'المدفوعات والبوابات',    'Payments & gateways',    'بيانات الدفع وبوابات الدفع.', 'Payment data and gateways.', 0, 1, 940),
    ('affiliate-finance','عمولات المسوّقين',       'Affiliate finance',      'العمولات والمدفوعات المالية للمسوّقين.', 'Affiliate commissions and payouts.', 0, 1, 950),
    ('ai',               'الذكاء الاصطناعي',       'AI providers',           'مفاتيح مزوّدي الذكاء الاصطناعي وإعداداتهم.', 'AI provider keys and configuration.', 0, 1, 960),
    ('system-settings',  'إعدادات النظام',         'System settings',        'إعدادات المنصة العامة.', 'Platform-wide settings.', 0, 1, 970),
    ('security',         'الأمان والمصادقة',       'Security & auth',        'المصادقة الثنائية وسياسات الدخول.', 'Two-factor and login policy.', 0, 1, 980),
    ('staff',            'الموظفون والصلاحيات',    'Staff & permissions',    'إنشاء الموظفين وتعديل صلاحياتهم.', 'Creating staff and editing their permissions.', 0, 1, 990),
    ('integrations',     'التكاملات',              'Integrations',           'التكاملات الخارجية ومفاتيحها.', 'External integrations and their keys.', 0, 1, 1000),
    ('backups',          'النسخ الاحتياطي',        'Backups',                'النسخ الاحتياطي الكامل لقاعدة البيانات.', 'Full database backups.', 0, 1, 1010),
    ('audit',            'سجل التدقيق',            'Audit log',              'سجل الأحداث الأمنية والإدارية.', 'Security and administrative event log.', 0, 1, 1020)
ON DUPLICATE KEY UPDATE
    label_ar = VALUES(label_ar),
    label_en = VALUES(label_en),
    description_ar = VALUES(description_ar),
    description_en = VALUES(description_en),
    grantable = VALUES(grantable),
    is_sensitive = VALUES(is_sensitive),
    sort_order = VALUES(sort_order);

-- ------------------------------------------------------------
-- 4. Per-account grants.
--
-- One row = "this administrator may perform this action on this module". The action
-- set is deliberately small and closed: view, create, edit, delete, approve, export.
-- Delete and export are never granted implicitly by view or edit.
--
-- The foreign key to `admins` is ON DELETE CASCADE because these rows are meaningless
-- without the account. Note that deleting an administrator is NOT how an employee is
-- terminated — `is_active = 0` is — so this cascade is a safety net for a deliberate
-- account removal, not the normal path.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_staff_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL,
    module_key VARCHAR(64) NOT NULL,
    action_key VARCHAR(16) NOT NULL,
    granted_by INT UNSIGNED DEFAULT NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_staff_permission (admin_id, module_key, action_key),
    KEY idx_staff_permission_admin (admin_id),
    KEY idx_staff_permission_module (module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The cascade needs the parent key; both sides are INT UNSIGNED.
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admin_staff_permissions'
             AND CONSTRAINT_NAME='fk_staff_permission_admin');
SET @s := IF(@c=0,
    'ALTER TABLE admin_staff_permissions ADD CONSTRAINT fk_staff_permission_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 5. A unique index on the email is already present (`email` is UNIQUE in schema.sql),
-- so duplicate staff emails are rejected by the database and not only by the form.
-- Nothing to add here; noted so a reader does not go looking.
-- ------------------------------------------------------------

-- ------------------------------------------------------------
-- 6. Backfill: mark every existing administrator active.
--
-- The ADD COLUMN above already defaults new and existing rows to 1, but a database
-- where the column pre-existed with NULLs would leave accounts unable to log in. This
-- is a no-op on a clean run.
-- ------------------------------------------------------------
UPDATE admins SET is_active = 1 WHERE is_active IS NULL;
