-- ============================================================================
-- مواثيق — أساس مساحة العمل القانونية وإدارة مكاتب المحاماة (Execution 69)
-- MAWATHEQ — AI legal workspace and law-firm management foundation
-- upgrade_v81.sql
-- ============================================================================
--
-- Additive and idempotent: safe to replay. No statement uses a positional AFTER
-- clause (a missing sibling column would abort the rest of the file — the lesson
-- of Execution 29). Every guard is on information_schema.
--
-- WHY
--   The brief is firm-shaped (firm -> clients -> cases -> sessions -> fees) while
--   the platform has been consultation-shaped (client -> lawyer). Most of the data
--   model for the firm shape already exists; what is missing is the *client*
--   record (there is no `clients` table at all — cases hang off `users`, which is
--   the login account, and a firm also represents people who never sign in), the
--   template library, the firm chatbot, and the plan-limit columns the pricing
--   page needs to state honestly.
--
--   This migration creates ONLY what has no existing equivalent. Explicitly NOT
--   duplicated, and reused as-is:
--     law_firms, law_firm_members, firm_branches, hr_employees,
--     case_parties, case_risks, case_time_entries, case_tasks, case_evidence,
--     case_timeline, legal_calendar_events, invoices, invoice_items,
--     firm_knowledge, knowledge_sources, knowledge_chunks, draft_documents,
--     draft_document_versions, ai_conversations, ai_messages, plans,
--     plan_features, subscriptions, package_orders.
--
--   `clients` is deliberately separate from `users`: a client may be a company,
--   may never log in, and one user (a sponsor) may be the contact for several
--   client entities. `clients.user_id` is the optional link to the login account.
-- ============================================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. الموكلون — the firm's client register
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clients` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `firm_id` INT UNSIGNED NULL DEFAULT NULL,
  `lawyer_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'solo practitioner owner',
  `user_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'optional link to the login account',
  `branch_id` INT UNSIGNED NULL DEFAULT NULL,
  `client_type` ENUM('individual','company') NOT NULL DEFAULT 'individual',
  `display_name` VARCHAR(255) NOT NULL,
  `company_name` VARCHAR(255) NULL DEFAULT NULL,
  `commercial_register` VARCHAR(60) NULL DEFAULT NULL,
  `tax_number` VARCHAR(60) NULL DEFAULT NULL,
  `national_id` VARCHAR(30) NULL DEFAULT NULL,
  `email` VARCHAR(190) NULL DEFAULT NULL,
  `phone` VARCHAR(40) NULL DEFAULT NULL,
  `address` TEXT NULL DEFAULT NULL,
  `city` VARCHAR(120) NULL DEFAULT NULL,
  `country_code` CHAR(2) NOT NULL DEFAULT 'SA',
  `notes` TEXT NULL DEFAULT NULL COMMENT 'internal to the firm, never shown to the client',
  `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
  `created_by_lawyer_id` INT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_clients_firm` (`firm_id`, `status`),
  KEY `idx_clients_lawyer` (`lawyer_id`, `status`),
  KEY `idx_clients_user` (`user_id`),
  KEY `idx_clients_name` (`display_name`),
  KEY `idx_clients_phone` (`phone`),
  CONSTRAINT `fk_clients_firm` FOREIGN KEY (`firm_id`) REFERENCES `law_firms`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_clients_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_clients_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A client record belongs to exactly one tenant: a firm OR a solo lawyer.
-- Both NULL is a row with no owner, which no scoping query could ever reach —
-- refuse it at the schema level rather than relying on every caller.
SET @has_ck := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients'
    AND CONSTRAINT_NAME = 'ck_clients_tenant');
SET @sql := IF(@has_ck = 0,
  'ALTER TABLE `clients` ADD CONSTRAINT `ck_clients_tenant` CHECK (`firm_id` IS NOT NULL OR `lawyer_id` IS NOT NULL)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- cases.client_id — the case now points at a client record. Nullable because
-- every existing case was created against a `users` row and the backfill below
-- only fills what it can prove.
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'client_id');
SET @sql := IF(@has_col = 0,
  'ALTER TABLE `cases` ADD COLUMN `client_id` INT UNSIGNED NULL DEFAULT NULL, ADD KEY `idx_cases_client` (`client_id`)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cases' AND CONSTRAINT_NAME = 'fk_cases_client');
SET @sql := IF(@has_fk = 0,
  'ALTER TABLE `cases` ADD CONSTRAINT `fk_cases_client` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 2. مكتبة القوالب — template categories and templates
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `template_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(80) NOT NULL,
  `name_ar` VARCHAR(160) NOT NULL,
  `name_en` VARCHAR(160) NOT NULL,
  `description_ar` TEXT NULL DEFAULT NULL,
  `description_en` TEXT NULL DEFAULT NULL,
  `icon_key` VARCHAR(40) NULL DEFAULT NULL,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template_categories_slug` (`slug`),
  KEY `idx_template_categories_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NULL DEFAULT NULL,
  `firm_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = platform-wide template',
  `slug` VARCHAR(120) NOT NULL,
  `title_ar` VARCHAR(255) NOT NULL,
  `title_en` VARCHAR(255) NOT NULL,
  `description_ar` TEXT NULL DEFAULT NULL,
  `description_en` TEXT NULL DEFAULT NULL,
  `body_ar` LONGTEXT NULL DEFAULT NULL COMMENT 'placeholders as {{key}}',
  `body_en` LONGTEXT NULL DEFAULT NULL,
  `doc_type` VARCHAR(80) NOT NULL DEFAULT 'legal_memo',
  `fields_json` TEXT NULL DEFAULT NULL COMMENT 'dynamic field definitions',
  `plan_tier` ENUM('free','paid','corporate') NOT NULL DEFAULT 'free' COMMENT 'minimum plan classification',
  `language` VARCHAR(5) NOT NULL DEFAULT 'ar',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_lawyer_id` INT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_templates_scope_slug` (`firm_id`, `slug`),
  KEY `idx_document_templates_category` (`category_id`, `is_active`),
  KEY `idx_document_templates_type` (`doc_type`),
  CONSTRAINT `fk_document_templates_category` FOREIGN KEY (`category_id`) REFERENCES `template_categories`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_document_templates_firm` FOREIGN KEY (`firm_id`) REFERENCES `law_firms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A platform template and a firm template may share a slug, but a UNIQUE key
-- containing a NULL column does not constrain in MySQL (several NULLs coexist),
-- so the platform-wide row would be unguarded. MariaDB has no expression
-- indexes, so the guard is a stored generated column that equals the slug only
-- for platform-wide rows and is NULL otherwise.
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_templates' AND COLUMN_NAME = 'global_slug');
SET @sql := IF(@has_col = 0,
  "ALTER TABLE `document_templates`
     ADD COLUMN `global_slug` VARCHAR(120) AS (IF(`firm_id` IS NULL, `slug`, NULL)) STORED,
     ADD UNIQUE KEY `uq_document_templates_global_slug` (`global_slug`)",
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 3. الشات بوت الخاص بالمكتب
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `firm_chatbots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `firm_id` INT UNSIGNED NULL DEFAULT NULL,
  `lawyer_id` INT UNSIGNED NULL DEFAULT NULL,
  `public_key` CHAR(32) NOT NULL COMMENT 'standalone widget URL key',
  `display_name_ar` VARCHAR(160) NULL DEFAULT NULL,
  `display_name_en` VARCHAR(160) NULL DEFAULT NULL,
  `greeting_ar` TEXT NULL DEFAULT NULL,
  `greeting_en` TEXT NULL DEFAULT NULL,
  `knowledge_scope` ENUM('firm_only','firm_and_public','public_only') NOT NULL DEFAULT 'firm_and_public',
  `allow_booking` TINYINT(1) NOT NULL DEFAULT 1,
  `allow_handoff` TINYINT(1) NOT NULL DEFAULT 1,
  `whatsapp_number` VARCHAR(40) NULL DEFAULT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_firm_chatbots_key` (`public_key`),
  UNIQUE KEY `uq_firm_chatbots_firm` (`firm_id`),
  UNIQUE KEY `uq_firm_chatbots_lawyer` (`lawyer_id`),
  CONSTRAINT `fk_firm_chatbots_firm` FOREIGN KEY (`firm_id`) REFERENCES `law_firms`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_firm_chatbots_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chatbot_conversations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chatbot_id` INT UNSIGNED NOT NULL,
  `visitor_key` CHAR(32) NOT NULL COMMENT 'anonymous visitor cookie; never an IP',
  `visitor_name` VARCHAR(160) NULL DEFAULT NULL,
  `visitor_phone` VARCHAR(40) NULL DEFAULT NULL,
  `visitor_email` VARCHAR(190) NULL DEFAULT NULL,
  `status` ENUM('open','handoff_requested','converted','closed') NOT NULL DEFAULT 'open',
  `assigned_lawyer_id` INT UNSIGNED NULL DEFAULT NULL,
  `lead_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'contact_leads row when converted',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chatbot_conversations_bot` (`chatbot_id`, `status`),
  KEY `idx_chatbot_conversations_visitor` (`visitor_key`),
  CONSTRAINT `fk_chatbot_conversations_bot` FOREIGN KEY (`chatbot_id`) REFERENCES `firm_chatbots`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chatbot_messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` INT UNSIGNED NOT NULL,
  `sender` ENUM('visitor','assistant','lawyer','system') NOT NULL,
  `message` TEXT NOT NULL,
  `citations_json` TEXT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chatbot_messages_conversation` (`conversation_id`, `id`),
  CONSTRAINT `fk_chatbot_messages_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `chatbot_conversations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. حدود الباقات — ten limits, no price change
-- ------------------------------------------------------------
-- Each column is guarded on itself. `NULL` keeps the established meaning the
-- gating code already relies on: no limit enforced. The pricing page must read
-- these rather than hardcoding a claim.
-- Written out one column per block: a WHILE loop is only legal inside a stored
-- program, so a loop here is a syntax error, not a shortcut.

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='max_staff');
SET @sql := IF(@has=0,
  'ALTER TABLE `plans` ADD COLUMN `max_staff` INT NULL DEFAULT NULL COMMENT ''maximum employees, NULL = unlimited''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='max_clients');
SET @sql := IF(@has=0,
  'ALTER TABLE `plans` ADD COLUMN `max_clients` INT NULL DEFAULT NULL COMMENT ''maximum client records, NULL = unlimited''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='max_active_cases');
SET @sql := IF(@has=0,
  'ALTER TABLE `plans` ADD COLUMN `max_active_cases` INT NULL DEFAULT NULL COMMENT ''concurrent open cases, NULL = unlimited''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='storage_mb');
SET @sql := IF(@has=0,
  'ALTER TABLE `plans` ADD COLUMN `storage_mb` INT NULL DEFAULT NULL COMMENT ''storage quota in MB, NULL = unlimited''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='max_templates');
SET @sql := IF(@has=0,
  'ALTER TABLE `plans` ADD COLUMN `max_templates` INT NULL DEFAULT NULL COMMENT ''own template library size, NULL = unlimited''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='feature_calendar');
SET @sql := IF(@has=0,
  'ALTER TABLE `plans` ADD COLUMN `feature_calendar` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''calendar + hearings''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='feature_finance');
SET @sql := IF(@has=0,
  'ALTER TABLE `plans` ADD COLUMN `feature_finance` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''invoices, quotes, expenses''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='feature_reports');
SET @sql := IF(@has=0,
  'ALTER TABLE `plans` ADD COLUMN `feature_reports` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''financial and admin reports''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='feature_chatbot');
SET @sql := IF(@has=0,
  'ALTER TABLE `plans` ADD COLUMN `feature_chatbot` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''firm public chatbot''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='feature_branding');
SET @sql := IF(@has=0,
  'ALTER TABLE `plans` ADD COLUMN `feature_branding` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''firm letterhead and logo on documents''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='feature_whatsapp');
SET @sql := IF(@has=0,
  'ALTER TABLE `plans` ADD COLUMN `feature_whatsapp` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''WhatsApp Business handoff''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 5. أدوار العضوية الثمانية
-- ------------------------------------------------------------
-- The column is an ENUM today with four values. Appending keeps every existing
-- value at its current index (the documented rule: never re-order an ENUM list).
SET @has_role := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'law_firm_members' AND COLUMN_NAME = 'member_role');
SET @sql := IF(@has_role IS NOT NULL AND LOCATE('paralegal', @has_role) = 0,
  "ALTER TABLE `law_firm_members` MODIFY COLUMN `member_role`
     ENUM('owner','partner','lawyer','staff','associate','paralegal','secretary','accountant')
     NOT NULL DEFAULT 'lawyer'",
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 6. وصول إدارة المنصة الاستثنائي — default deny, logged, ticket-bound
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_content_access_grants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NOT NULL,
  `target_type` ENUM('firm','client','case','document','consultation','invoice','conversation') NOT NULL,
  `target_id` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(500) NOT NULL COMMENT 'why access is necessary - required',
  `ticket_reference` VARCHAR(80) NOT NULL COMMENT 'support ticket - required',
  `granted_by_admin_id` INT UNSIGNED NULL DEFAULT NULL,
  `expires_at` DATETIME NOT NULL COMMENT 'no perpetual grant',
  `revoked_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acag_lookup` (`admin_id`, `target_type`, `target_id`, `expires_at`),
  KEY `idx_acag_target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_content_access_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NOT NULL,
  `grant_id` INT UNSIGNED NULL DEFAULT NULL,
  `target_type` VARCHAR(30) NOT NULL,
  `target_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(60) NOT NULL,
  `reason` VARCHAR(500) NULL DEFAULT NULL,
  `ticket_reference` VARCHAR(80) NULL DEFAULT NULL,
  `ip_hash` CHAR(64) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acal_admin` (`admin_id`, `created_at`),
  KEY `idx_acal_target` (`target_type`, `target_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. ربط المحادثة بقضية وموكل — A4 in the gap analysis
-- ------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_conversations' AND COLUMN_NAME='case_id');
SET @sql := IF(@has=0,
  'ALTER TABLE `ai_conversations` ADD COLUMN `case_id` INT UNSIGNED NULL DEFAULT NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_conversations' AND COLUMN_NAME='client_id');
SET @sql := IF(@has=0,
  'ALTER TABLE `ai_conversations` ADD COLUMN `client_id` INT UNSIGNED NULL DEFAULT NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_conversations' AND COLUMN_NAME='firm_id');
SET @sql := IF(@has=0,
  'ALTER TABLE `ai_conversations` ADD COLUMN `firm_id` INT UNSIGNED NULL DEFAULT NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_conversations' AND COLUMN_NAME='lawyer_id');
SET @sql := IF(@has=0,
  'ALTER TABLE `ai_conversations` ADD COLUMN `lawyer_id` INT UNSIGNED NULL DEFAULT NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_conversations' AND INDEX_NAME = 'idx_ai_conversations_scope');
SET @sql := IF(@has_idx = 0,
  'ALTER TABLE `ai_conversations` ADD KEY `idx_ai_conversations_scope` (`firm_id`, `case_id`, `client_id`)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 8. أنواع المستندات المطلوبة — صياغة تستخدم القائمة الموسّعة
-- ------------------------------------------------------------
-- Document the required set in the template catalogue rather than in a PHP
-- array that a page can drift from. The 18 categories the brief names, minus
-- the ones already covered by lawyer/drafting.php's own list.
INSERT INTO `template_categories` (`slug`,`name_ar`,`name_en`,`icon_key`,`sort_order`) VALUES
  ('lawsuit','لوائح الدعاوى','Statements of claim','gavel',1),
  ('memo','المذكرات الجوابية والدفاع','Pleadings & defence','file-text',2),
  ('contract','العقود والاتفاقيات','Contracts & agreements','file-signature',3),
  ('demand','خطابات المطالبة','Demand letters','mail',4),
  ('notice','الإنذارات والإشعارات','Notices','alert',5),
  ('poa','التوكيلات','Powers of attorney','stamp',6),
  ('appeal','الاعتراضات والاستئناف','Objections & appeals','scale',7),
  ('settlement','التسويات والمصالحات','Settlements','handshake',8),
  ('acknowledgment','الإقرارات','Acknowledgments','check-circle',9),
  ('corporate','القرارات والوثائق التجارية','Corporate documents','building',10)
ON DUPLICATE KEY UPDATE `name_ar` = VALUES(`name_ar`), `name_en` = VALUES(`name_en`);

-- ------------------------------------------------------------
-- 9. قيم الحدود للباقات القائمة — الأسعار لا تُمسّ
-- ------------------------------------------------------------
-- Keyed on `name_en` so this works on any install regardless of id order. Only
-- rows whose limits are still at the column default are written, so an operator
-- who has already tuned a plan keeps their values on replay.
UPDATE `plans` SET
  `max_staff` = 1, `max_clients` = 10, `max_active_cases` = 5, `storage_mb` = 1024,
  `max_templates` = 5, `feature_calendar` = 1, `feature_finance` = 0,
  `feature_reports` = 0, `feature_chatbot` = 0, `feature_branding` = 0,
  `feature_whatsapp` = 0
WHERE `name_en` = 'Basic'
  AND `max_active_cases` IS NULL AND `storage_mb` IS NULL AND `max_clients` IS NULL;

UPDATE `plans` SET
  `max_staff` = 5, `max_clients` = 100, `max_active_cases` = 50, `storage_mb` = 25600,
  `max_templates` = 100, `feature_calendar` = 1, `feature_finance` = 1,
  `feature_reports` = 0, `feature_chatbot` = 1, `feature_branding` = 1,
  `feature_whatsapp` = 0
WHERE `name_en` = 'Professional'
  AND `max_active_cases` IS NULL AND `storage_mb` IS NULL AND `max_clients` IS NULL;

UPDATE `plans` SET
  `max_staff` = NULL, `max_clients` = NULL, `max_active_cases` = NULL, `storage_mb` = 102400,
  `max_templates` = NULL, `feature_calendar` = 1, `feature_finance` = 1,
  `feature_reports` = 1, `feature_chatbot` = 1, `feature_branding` = 1,
  `feature_whatsapp` = 1
WHERE `name_en` = 'Corporate'
  AND `storage_mb` IS NULL;
