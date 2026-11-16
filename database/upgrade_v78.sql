-- ============================================================================
-- مواثيق — المكتبة القانونية والقضائية (Execution 74) — upgrade_v78.sql
-- MAWATHEQ — legal & judicial library
-- ============================================================================
--
-- Additive and idempotent: safe to replay, and safe on a database that already has
-- the tables. Every table uses CREATE TABLE IF NOT EXISTS and the one index that
-- could be missing is guarded on information_schema. No statement uses a positional
-- AFTER clause (a missing sibling column would abort the rest of the file — the
-- lesson of Execution 29).
--
-- WHY
--   The platform ingests public legal content from official sources: the Saudi
--   Ministry of Justice legal portal (judicial decisions + legislations) exposes a
--   real, open, JSON gateway. Each item keeps its source, its official URL and the
--   redistribution decision for that source, so the UI can always link back to the
--   authority that published it and never re-host a file whose terms forbid it.
--
--   `redistribution` is the load-bearing column: 'link_only' means Mawatheq stores
--   the metadata and sends the reader to the source; 'hosted' means the source's
--   terms permit a safe copy inside Mawatheq; 'unknown' means neither is assumed.
-- ============================================================================

SET NAMES utf8mb4;

-- --- legal_library_sources ---------------------------------------------------
-- One row per upstream source. The operator turns a source on/off here and the
-- sync + UI follow it; a disabled source is never imported and never shown.
CREATE TABLE IF NOT EXISTS `legal_library_sources` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_key` VARCHAR(40) NOT NULL,
  `name_ar` VARCHAR(160) NOT NULL,
  `name_en` VARCHAR(160) NOT NULL,
  `publisher_ar` VARCHAR(160) NOT NULL DEFAULT '',
  `publisher_en` VARCHAR(160) NOT NULL DEFAULT '',
  `base_url` VARCHAR(500) NOT NULL DEFAULT '',
  `access_method` ENUM('moj_api','public_api','public_list','manual') NOT NULL DEFAULT 'manual',
  `content_types` VARCHAR(160) NOT NULL DEFAULT '',
  `redistribution` ENUM('link_only','hosted','unknown') NOT NULL DEFAULT 'link_only',
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `last_sync_at` DATETIME NULL DEFAULT NULL,
  `last_sync_status` VARCHAR(20) NULL DEFAULT NULL,
  `item_count` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `notes_ar` TEXT NULL DEFAULT NULL,
  `notes_en` TEXT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lls_source_key` (`source_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- legal_library_items -----------------------------------------------------
-- The unified catalogue. `external_id` is the upstream id, so a re-sync updates in
-- place instead of duplicating. `body` holds only text the source itself publishes
-- for reading (e.g. the MOJ decision text); it is stored only when the source's
-- redistribution allows it, and `source_url` is always kept so the reader can reach
-- the official record.
CREATE TABLE IF NOT EXISTS `legal_library_items` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_id` INT(10) UNSIGNED NOT NULL,
  `source_key` VARCHAR(40) NOT NULL,
  `external_id` VARCHAR(191) NOT NULL DEFAULT '',
  `item_type` ENUM('judgment','legislation','book','statistics','article') NOT NULL DEFAULT 'judgment',
  `title` VARCHAR(400) NOT NULL,
  `summary` TEXT NULL DEFAULT NULL,
  `court_name` VARCHAR(160) NULL DEFAULT NULL,
  `city` VARCHAR(120) NULL DEFAULT NULL,
  `court_type` TINYINT NULL DEFAULT NULL,
  `case_number` VARCHAR(80) NULL DEFAULT NULL,
  `decision_number` VARCHAR(80) NULL DEFAULT NULL,
  `hijri_year` SMALLINT NULL DEFAULT NULL,
  `gregorian_year` SMALLINT NULL DEFAULT NULL,
  `decision_date` DATE NULL DEFAULT NULL,
  `category` VARCHAR(160) NULL DEFAULT NULL,
  `body` LONGTEXT NULL DEFAULT NULL,
  `source_url` VARCHAR(500) NOT NULL DEFAULT '',
  `file_url` VARCHAR(500) NULL DEFAULT NULL,
  `file_type` VARCHAR(16) NULL DEFAULT NULL,
  `file_size_kb` INT(10) UNSIGNED NULL DEFAULT NULL,
  `page_count` INT(10) UNSIGNED NULL DEFAULT NULL,
  `publisher` VARCHAR(200) NULL DEFAULT NULL,
  `published_at` DATE NULL DEFAULT NULL,
  `source_updated_at` DATETIME NULL DEFAULT NULL,
  `lang` VARCHAR(5) NOT NULL DEFAULT 'ar',
  `redistribution` ENUM('link_only','hosted','unknown') NOT NULL DEFAULT 'link_only',
  `hosted_path` VARCHAR(500) NULL DEFAULT NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lli_source_external` (`source_key`, `external_id`),
  KEY `idx_lli_type` (`item_type`),
  KEY `idx_lli_source` (`source_id`),
  KEY `idx_lli_court` (`court_name`),
  KEY `idx_lli_city` (`city`),
  KEY `idx_lli_year` (`hijri_year`),
  KEY `idx_lli_date` (`decision_date`),
  KEY `idx_lli_category` (`category`),
  KEY `idx_lli_published` (`is_published`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Full-text index for relevance-ranked search, guarded on itself so a database
-- that already has the table (without the index) gains it, and a re-run is a no-op.
SET @ft_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_library_items'
    AND INDEX_NAME = 'ft_lli_text'
);
SET @ft_sql := IF(@ft_exists = 0,
  'ALTER TABLE `legal_library_items` ADD FULLTEXT KEY `ft_lli_text` (`title`, `summary`, `body`)',
  'SELECT 1');
PREPARE ft_stmt FROM @ft_sql;
EXECUTE ft_stmt;
DEALLOCATE PREPARE ft_stmt;

-- --- legal_library_sync_runs -------------------------------------------------
-- One row per sync execution, with counts and any error. This is the audit trail
-- the admin console reads, and the reason a failed import is visible instead of a
-- silent gap in the catalogue.
CREATE TABLE IF NOT EXISTS `legal_library_sync_runs` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_key` VARCHAR(40) NOT NULL,
  `status` ENUM('running','ok','partial','error') NOT NULL DEFAULT 'running',
  `items_seen` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `items_new` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `items_updated` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `items_skipped` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `error_message` TEXT NULL DEFAULT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_llsr_source` (`source_key`),
  KEY `idx_llsr_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- legal_library_favorites -------------------------------------------------
-- A client's saved items ("حفظ في مكتبتي"). Unique per (user, item) so a double
-- tap cannot create a duplicate, exactly like lawyer_favorites.
CREATE TABLE IF NOT EXISTS `legal_library_favorites` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `item_id` INT(10) UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_llf_user_item` (`user_id`, `item_id`),
  KEY `idx_llf_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- seed the source registry ------------------------------------------------
-- INSERT IGNORE so an operator's later change (rename, disable, redistribution
-- decision) survives a re-run of this migration. Only the two sources that expose
-- a real, open, machine-readable gateway ship enabled; the rest are registered and
-- disabled until the operator reviews their terms (they are link-out references).
INSERT IGNORE INTO `legal_library_sources`
  (`source_key`, `name_ar`, `name_en`, `publisher_ar`, `publisher_en`, `base_url`, `access_method`, `content_types`, `redistribution`, `is_enabled`, `notes_ar`, `notes_en`)
VALUES
  ('moj_decisions', 'القرارات والأحكام القضائية — وزارة العدل', 'Judicial decisions — Ministry of Justice',
   'وزارة العدل السعودية', 'Saudi Ministry of Justice',
   'https://laws.moj.gov.sa/ar/JudicialDecisionsList/0', 'moj_api', 'judgment',
   'hosted', 1,
   'بوابة قانونية مفتوحة تتيح قراءة نص الحكم عبر خدمتها الرسمية. تُخزَّن البيانات الوصفية ونص الحكم المُعلن، ويُربط كل عنصر بمصدره الرسمي.',
   'Open legal portal. Metadata and the published ruling text are stored; every item links back to its official record.'),
  ('moj_legislation', 'الأنظمة والتشريعات — وزارة العدل', 'Legislations & regulations — Ministry of Justice',
   'وزارة العدل السعودية', 'Saudi Ministry of Justice',
   'https://laws.moj.gov.sa/ar/legislations-regulations', 'moj_api', 'legislation',
   'hosted', 1,
   'الأنظمة واللوائح والمراسيم المتاحة عبر البوابة القانونية. تُخزَّن البيانات الوصفية والملخص، ويُربط كل نظام بصفحته الرسمية.',
   'Statutes, regulations and royal orders from the legal portal. Metadata and summary are stored; each links to its official page.'),
  ('mashortich_books', 'مجموعات الأحكام القضائية (PDF)', 'Judicial rulings collections (PDF)',
   'مشورتك — مصدر ثالث', 'Mashortich (third-party)',
   'https://www.mashortich.com/', 'public_list', 'book',
   'link_only', 1,
   'أربع عشرة مجموعة PDF منشورة علناً. لم تُنسخ الملفات إلى مواثيق؛ تُعرض البيانات الوصفية وروابط التنزيل الرسمية فقط، ويُتحقّق من استجابة الرابط قبل عرضه.',
   'Fourteen publicly published PDF collections. Files are NOT copied to Mawatheq; only metadata and the official download link are shown, and the link is verified before it is displayed.'),
  ('bog_open_data', 'البيانات المفتوحة — ديوان المظالم', 'Open data — Board of Grievances',
   'ديوان المظالم', 'Board of Grievances',
   'https://www.bog.gov.sa/EServices/OpenData/Pages/default.aspx', 'public_api', 'statistics',
   'unknown', 0,
   'مصدر حكومي للبيانات المفتوحة وواجهة API. معطّل افتراضياً: يتطلب مراجعة شروط إعادة النشر قبل التفعيل، ولم يكن متاحاً للفحص من بيئة التطوير.',
   'Government open-data source with an API. Disabled by default: requires a terms review before enabling, and was not reachable from the build environment.'),
  ('bog_almusaid', 'المساعد القضائي — ديوان المظالم', 'Judicial assistant — Board of Grievances',
   'ديوان المظالم', 'Board of Grievances',
   'https://almusaid.bog.gov.sa/', 'public_api', 'judgment',
   'unknown', 0,
   'خدمة بحث قضائي تتطلب تسجيل دخول موحّد (SSO). معطّلة افتراضياً لعدم وجود وصول برمجي عام مصرّح به.',
   'Judicial search service gated behind SSO login. Disabled by default — no authorized public programmatic access.');
