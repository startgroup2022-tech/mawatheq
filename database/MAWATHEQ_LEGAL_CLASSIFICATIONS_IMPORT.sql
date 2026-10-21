-- ============================================================================
-- MAWATHEQ — LEGAL CLASSIFICATIONS IMPORT
-- MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql
-- ============================================================================
--
-- WHAT THIS IS
--   The complete Saudi Najiz legal classification hierarchy — the seven main
--   categories, their subcategories, every lawsuit type and every judicial
--   request — as plain SQL, ready to import from phpMyAdmin.
--
--   Generated from database/sources/Pasted text(3).txt by
--   tools/generate_legal_import_sql.php, which calls the same parser and the same
--   key/normalisation functions as the admin-console importer. The rows written
--   here are therefore the exact rows that importer would write: the two paths
--   are interchangeable and re-importing through either is a no-op.
--
-- HOW TO RUN (phpMyAdmin)
--   1. Select the database (mawatheq_thaer) in the left column.
--   2. Open the "Import" tab.
--   3. Choose this file, leave the charset as utf-8, press "Go".
--   No admin console, no CLI, no PHP.
--
-- ORDER
--   Run MAWATHEQ_DB_SCHEMA_FIX.sql FIRST. This file checks that and aborts with a
--   plain message if the tables or columns are missing.
--
-- SAFETY
--   * ADDITIVE / IDEMPOTENT. Every row is matched on a deterministic key, so
--     running this file twice inserts nothing the second time and never
--     duplicates a classification.
--   * No DROP, no TRUNCATE, no DELETE. Customer, lawyer, case, consultation,
--     invoice and document data are never read or written by this file.
--   * No hard-coded ids. Parent rows are resolved by their unique key, so the
--     file works on a database whose ids are already in use.
--   * It does not overwrite wording an operator has customised: the
--     customer-facing description and icon are written only where blank.
--   * utf8mb4 throughout; Arabic text is preserved exactly as in the source.
--
-- CONTENTS
--   38 subcategories, 234 lawsuit types, 640 judicial requests
--   across 7 main categories. The source declares 234 types;
--   234 are parsed (227 with a detail block, 7 without).
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- Preconditions.
--   Each check below raises a deliberate error naming the missing prerequisite,
--   which stops the import before any row is written. That is the intended
--   behaviour: a half-applied import is worse than a refused one. If you see
--   "PLEASE RUN MAWATHEQ_DB_SCHEMA_FIX.sql FIRST", run that file and retry.
-- ----------------------------------------------------------------------------
SET @tables := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
                ('najiz_categories','najiz_subcategories','najiz_case_types','najiz_judicial_requests','najiz_search_synonyms','najiz_import_runs','najiz_audit_log'));
SET @s := IF(@tables < 7,
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PLEASE RUN MAWATHEQ_DB_SCHEMA_FIX.sql FIRST''',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cols := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='najiz_categories'
                AND COLUMN_NAME IN ('customer_desc_ar','customer_icon_key'));
SET @s := IF(@cols < 2,
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PLEASE RUN MAWATHEQ_DB_SCHEMA_FIX.sql FIRST''',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 1) Main categories (7)
--    Keyed on the unique name. The customer-facing description and icon are the
--    same ones upgrade_v63.sql backfills, so the journey cards render correctly
--    without a second migration run.
-- ----------------------------------------------------------------------------
INSERT INTO najiz_categories
    (name_ar, name_en, description_ar, source_ref, sort_order, is_active,
     customer_desc_ar, customer_icon_key)
VALUES
    ('الأحوال الشخصية', 'Personal Status', NULL, 'Najiz source', 1, 1, 'شؤون الأسرة: الزواج والطلاق والنفقة والحضانة والميراث.', 'family'),
    ('الدعاوى التجارية', 'Commercial Cases', NULL, 'Najiz source', 2, 1, 'النزاعات التجارية والشركات والعقود والأوراق التجارية والإفلاس.', 'commercial'),
    ('الدعاوى العمالية', 'Labor Cases', NULL, 'Najiz source', 3, 1, 'نزاعات العمل: حقوق وواجبات العامل وصاحب العمل ومستحقات نهاية الخدمة.', 'labor'),
    ('الدعاوى الجزائية', 'Criminal Cases', NULL, 'Najiz source', 4, 1, 'القضايا الجزائية والمطالبات المالية الناشئة عن الجرائم.', 'criminal'),
    ('الدعاوى العامة', 'General Cases', NULL, 'Najiz source', 5, 1, 'الدعاوى المدنية والعقارية والعقود والتحكيم.', 'general'),
    ('دعاوى التنفيذ', 'Enforcement Cases', NULL, 'Najiz source', 6, 1, 'تنفيذ الأحكام والسندات التنفيذية والمنازعات المتعلقة بها.', 'enforcement'),
    ('الدعاوى المتعلقة بالإنهاءات', 'Termination-related Cases', NULL, 'Najiz source', 7, 1, 'إنهاءات الأوقاف والمواريث والصكوك والولايات.', 'endowments')
ON DUPLICATE KEY UPDATE
    name_en            = VALUES(name_en),
    source_ref         = VALUES(source_ref),
    sort_order         = VALUES(sort_order),
    is_active          = 1,
    -- Only fill blanks: an operator's own wording is never overwritten.
    customer_desc_ar   = COALESCE(NULLIF(customer_desc_ar,''), VALUES(customer_desc_ar)),
    customer_icon_key  = COALESCE(NULLIF(customer_icon_key,''), VALUES(customer_icon_key));

-- ----------------------------------------------------------------------------
-- 2) Subcategories (38)
--    category_id is resolved by name, never assumed. source_key is the same
--    SHA-1 the importer computes, so both paths address the same row.
-- ----------------------------------------------------------------------------
INSERT INTO najiz_subcategories
    (category_id, name_ar, declared_count, source_key, source_ref, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_categories WHERE name_ar='الأحوال الشخصية'), 'دعاوى الزواج والفرقة', 6, '0a600a7592ab76f7f48f9191d2c56636a29acc70', 'الأحوال الشخصية', 1, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الأحوال الشخصية'), 'النفقة', 2, '45a42a5eac3106e3aa5df4408355fafd8b04b4a5', 'الأحوال الشخصية', 2, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الأحوال الشخصية'), 'الحضانة', 2, 'e0f82167fe95e9935493f1876150a3eaaada7a17', 'الأحوال الشخصية', 3, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الأحوال الشخصية'), 'الزيارة', 2, '53c4fd568f59c69f3085fc87c439124017b75e96', 'الأحوال الشخصية', 4, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الأحوال الشخصية'), 'الورثة في التركة', 4, '00caaba74a5f38f14854a8275b716599dda80930', 'الأحوال الشخصية', 5, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الأحوال الشخصية'), 'الأوقاف والوصايا', 5, '0338b90704f446caa52cf5719698d798dc0b1482', 'الأحوال الشخصية', 6, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الأحوال الشخصية'), 'الهبة', 2, '78ab291551c7a1c872d38f8e577b48a597059c0e', 'الأحوال الشخصية', 7, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الأحوال الشخصية'), 'التصنيف العام', 7, 'f66372d679fce1b979455556272cd9d27452facf', 'الأحوال الشخصية', 8, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الأحوال الشخصية'), 'الولاية', 6, 'e47e0bd0bf4d99d18da353b29514dcfd1d43feeb', 'الأحوال الشخصية', 9, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى التجارية'), 'الأنظمة التجارية', 12, 'cfa948f1813a39608d29f089b07106616efd39cb', 'الدعاوى التجارية', 1, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى التجارية'), 'الطلبات القضائية', 6, '30c07b97123a64dca268648a953e9f0fde48745b', 'الدعاوى التجارية', 2, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى التجارية'), 'الإفلاس', 8, '6e2bee086979fc0222fe7994f7dc5172117f59d1', 'الدعاوى التجارية', 3, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى التجارية'), 'الملكية الفكرية', 3, '2283e069b05b67c44cb56a33159deaca0883002b', 'الدعاوى التجارية', 4, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى التجارية'), 'العقود التجارية', 10, 'da0cb0a769d1a13478948d173e079039833e4f10', 'الدعاوى التجارية', 5, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى التجارية'), 'الشركات', 4, '4cb6004c5cc0a0b24b81eae32a87b82ba81aa437', 'الدعاوى التجارية', 6, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى التجارية'), 'الدعاوى المستعجلة', 11, '82892e83d5bb1b3605abb0c6e51d62e0e355e24d', 'الدعاوى التجارية', 7, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى العمالية'), 'العمالة العادية – مطالبات صاحب العمل', 3, '26ce3228d5b8300e9ce839327c753492d193ff49', 'الدعاوى العمالية', 1, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى العمالية'), 'العمالة العادية – مطالبات العامل بحقوق وظيفية', 1, '5978dca598205ea9d7423fee68ae30754e230d53', 'الدعاوى العمالية', 2, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى العمالية'), 'العمالة العادية – مطالبات العامل', 4, 'dc270fd2c075e6bd9ccd58249bcc1842007f2a5d', 'الدعاوى العمالية', 3, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى العمالية'), 'العمالة المنزلية', 4, 'bbd6e3bf0974318e1f1371e49b30b776a0f27eab', 'الدعاوى العمالية', 4, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى الجزائية'), 'الطلبات القضائية', 6, '3377a8362582bbc007053aa4d6ec1a5ed23e15a1', 'الدعاوى الجزائية', 1, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى الجزائية'), 'القصاص والحدود', 3, '1a187705ac91a2861a2c722dec9583901859ee23', 'الدعاوى الجزائية', 2, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى الجزائية'), 'مطالبة الحق الخاص', 2, '5b928b340e8f9d724be500677a58be378f2e64ca', 'الدعاوى الجزائية', 3, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى الجزائية'), 'المطالبة المالية', 7, '51b7406646147271cdc5bba4fb4415bb588e9b07', 'الدعاوى الجزائية', 4, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى العامة'), 'الطلبات المالية', 8, '6785602593a99ed20d0a8ad8eedc815f2f202d6e', 'الدعاوى العامة', 1, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى العامة'), 'الطلبات الغير مالية (العقارية)', 8, '5e4f8a3ad9b72e2a62344b49f623d284a10b4f0f', 'الدعاوى العامة', 2, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى العامة'), 'التحكيم', 6, '562c8d05190d306a5950f265ca76ddcd284b4baa', 'الدعاوى العامة', 3, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى العامة'), 'العقود', 48, '9d1954522cf8a39d6a728384323190cc35b66602', 'الدعاوى العامة', 4, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='دعاوى التنفيذ'), 'الامتناع عن قبول السند', 1, 'ae4f049d3708fbd02f251b75bd9af83ec1eb934b', 'دعاوى التنفيذ', 1, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='دعاوى التنفيذ'), 'دعوى الإعسار أو الملاءمة', 2, '1e4cdf86e3d8d55ea5dcc79e47a416e6713b4dd4', 'دعاوى التنفيذ', 2, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='دعاوى التنفيذ'), 'منازعات شكلية', 2, 'e0e3a7fcad2a1d4c88ff0e6d062e8f29b776f8d6', 'دعاوى التنفيذ', 3, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='دعاوى التنفيذ'), 'منازعات غير شكلية', 17, '8ff39d5983703c5b995b5e2278cd5b2daf87a5fd', 'دعاوى التنفيذ', 4, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى المتعلقة بالإنهاءات'), 'تعديلات الصكوك', 1, '685c3b2f4f9426c9c212f41025cb05c093ae2f4d', 'الدعاوى المتعلقة بالإنهاءات', 1, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى المتعلقة بالإنهاءات'), 'الورثة', 2, 'b4408ba06efb6ab7431e5a62dc5fc7dd229a4218', 'الدعاوى المتعلقة بالإنهاءات', 2, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى المتعلقة بالإنهاءات'), 'الولايات', 4, 'f693893f898755ee5bc951e7d948323b420c8689', 'الدعاوى المتعلقة بالإنهاءات', 3, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى المتعلقة بالإنهاءات'), 'إثباتات اجتماعية', 4, '11ba31688b067112f999a34f74c7ff24a04dc8a4', 'الدعاوى المتعلقة بالإنهاءات', 4, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى المتعلقة بالإنهاءات'), 'الأوقاف والوصايا', 6, '6d09facb1deed403a3fd2ce60a5d66645705a625', 'الدعاوى المتعلقة بالإنهاءات', 5, 1),
    ((SELECT id FROM najiz_categories WHERE name_ar='الدعاوى المتعلقة بالإنهاءات'), 'إنهاءات الأوقاف والمواريث في الأحساء والقطيف', 5, '231db64bf74a812407bed38711c0e6621da9b934', 'الدعاوى المتعلقة بالإنهاءات', 6, 1)
ON DUPLICATE KEY UPDATE
    category_id    = VALUES(category_id),
    name_ar        = VALUES(name_ar),
    declared_count = VALUES(declared_count),
    source_ref     = VALUES(source_ref),
    sort_order     = VALUES(sort_order),
    is_active      = 1;

-- ----------------------------------------------------------------------------
-- 3) Lawsuit types (234)
--    subcategory_id is resolved through the subcategory source_key, which is
--    unique, so a name that recurs under two parents still lands correctly.
--    `definition_missing` and `requests_declared_absent` are preserved as the
--    source has them: the source declares 234 types, of which 7
--    carry no detail block. Those are real records and are imported, not dropped.
-- ----------------------------------------------------------------------------
INSERT INTO najiz_case_types
    (subcategory_id, name_ar, definition, definition_missing, requests_declared_absent,
     source_key, source_ref, search_norm, parse_note, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_subcategories WHERE source_key='0a600a7592ab76f7f48f9191d2c56636a29acc70'), 'إثبات عقد الزواج', 'دعوى تقام من أحد الزوجين أو ورثته ضد الآخر، أو من ولي الزوجة القاصر سنًا؛ لإثبات الزواج غير الموثق لدى الجهة الرسمية.', 0, 0, 'c3d43b92b7f2fcb2cc3d427b5c4ebde1fb8861cc', 'الأحوال الشخصية / دعاوى الزواج والفرقة', 'اثبات عقد الزواج', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='0a600a7592ab76f7f48f9191d2c56636a29acc70'), 'إعادة المهر', 'دعوى تقام للمطالبة بالمهر أو جزء منه.', 0, 0, '1e6c81f0bf3f2b72d4bd2f9ffdd8642faf77995e', 'الأحوال الشخصية / دعاوى الزواج والفرقة', 'اعاده المهر', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='0a600a7592ab76f7f48f9191d2c56636a29acc70'), 'فسخ عقد الزواج', 'دعوى تقام من أحد الزوجين ضد الآخر؛ لفسخ عقد الزواج لعيب في الآخر.', 0, 0, '3d73112640a41cd746ea914ae0b4ed27f1961a87', 'الأحوال الشخصية / دعاوى الزواج والفرقة', 'فسخ عقد الزواج', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='0a600a7592ab76f7f48f9191d2c56636a29acc70'), 'إثبات المراجعة', 'دعوى تقام من الزوج أو الزوجة أو ورثتهم؛ لإثبات الرجعة بعد الطلاق.', 0, 0, '537243d08c42665ec7f23e20654ba6d8b280a286', 'الأحوال الشخصية / دعاوى الزواج والفرقة', 'اثبات المراجعه', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='0a600a7592ab76f7f48f9191d2c56636a29acc70'), 'عفش الزوجية', 'دعوى للمطالبة بعفش الزوجية المتنازع عليه.', 0, 0, '5d14d4e0fffb05efef78a147b366c39df405f15c', 'الأحوال الشخصية / دعاوى الزواج والفرقة', 'عفش الزوجيه', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='0a600a7592ab76f7f48f9191d2c56636a29acc70'), 'إثبات الطلاق', NULL, 1, 0, 'd734841cd52867746eb2bb6d31fba87022d195bd', 'الأحوال الشخصية / دعاوى الزواج والفرقة', 'اثبات الطلاق', 'type heading present in source with no detail block', 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='45a42a5eac3106e3aa5df4408355fafd8b04b4a5'), 'النفقة', 'دعوى لطلب زيادة النفقة المحكوم بها أو إنقاصها أو إلغائها.', 0, 0, '171fbf07213f1300043285a2ab92aa5481c06965', 'الأحوال الشخصية / النفقة', 'النفقه', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='45a42a5eac3106e3aa5df4408355fafd8b04b4a5'), 'النفقة', NULL, 1, 0, 'c94e45b4387298a8c6caf5131aea0eb96f59af1d', 'الأحوال الشخصية / النفقة', 'النفقه', 'type heading present in source with no detail block', 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='e0f82167fe95e9935493f1876150a3eaaada7a17'), 'الحضانة', 'دعوى لطلب أجرة الرضاع أو الحضانة المتنازع عليها، إذا طلبت المرضعة أو الحاضنة لنفسها أجرة مقابل إرضاعها أو حضانتها للأولاد.', 0, 0, '850aaaac4dbad9e05535126f4b0cbed8ea5f4f3e', 'الأحوال الشخصية / الحضانة', 'الحضانه', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='e0f82167fe95e9935493f1876150a3eaaada7a17'), 'الحضانة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '5ef57a5728374b78e32b4742d1691423065b5528', 'الأحوال الشخصية / الحضانة', 'الحضانه', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='53c4fd568f59c69f3085fc87c439124017b75e96'), 'رؤية الصغير', 'دعوى لطلب رؤية صغير دون سن 7 سنوات يقيم لدى المدعى عليه أثناء قيام الزوجية أو بعد الفرقة، ويكون الحكم فيها مشمولًا بالنفاذ المعجل.', 0, 0, 'ead7180c298cf1b66f06bbdef796b8fc6f85f1a9', 'الأحوال الشخصية / الزيارة', 'رويه الصغير', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='53c4fd568f59c69f3085fc87c439124017b75e96'), 'الزيارة', NULL, 1, 0, '8d8377f227dd1544f8e6f5d52f2f397e0b743b9f', 'الأحوال الشخصية / الزيارة', 'الزياره', 'type heading present in source with no detail block', 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='00caaba74a5f38f14854a8275b716599dda80930'), 'دعوى قسمة التركة أكثر من 10 مليون', 'دعوى تقام من أحد الورثة أو أكثر ضد الوارث المتصرف في التركة أو المصفي أو الحارس القضائي، بطلب الإفصاح عن التركة أو تسليم نصيبه منها، أو طلب محاسبته لتصرفه بها.', 0, 0, '55526a404250856c6dc62d37a7527013c35a32bf', 'الأحوال الشخصية / الورثة في التركة', 'دعوي قسمه التركه اكثر من 10 مليون', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='00caaba74a5f38f14854a8275b716599dda80930'), 'دعوى قسمة التركة أقل من 10 مليون (مالية)', 'دعوى تقام من أحد الورثة أو أكثر ضد الباقي، بطلب تسليم النصيب الشرعي من تركة المورث المالية: النقد أو الأسهم أو الأثمان أو المنقول، أو طلب الإفصاح عنها.', 0, 0, 'a6e939a8c7002930579edd004e60a0885188ab8e', 'الأحوال الشخصية / الورثة في التركة', 'دعوي قسمه التركه اقل من 10 مليون (ماليه)', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='00caaba74a5f38f14854a8275b716599dda80930'), 'دعوى قسمة التركة أقل من 10 مليون (عقارية)', 'دعوى بذات الوصف السابق تتعلق بعقار من التركة.', 0, 0, '14d0504ffdb5b20cbe0b1617725783b3a1778b31', 'الأحوال الشخصية / الورثة في التركة', 'دعوي قسمه التركه اقل من 10 مليون (عقاريه)', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='00caaba74a5f38f14854a8275b716599dda80930'), 'تعيين محاسب على التركة', 'دعوى تقام من أحد الورثة أو أكثر ضد الوارث المتصرف في التركة أو المصفي أو الحارس القضائي، بطلب الإفصاح عن التصرفات في نصيبه من التركة، أو محاسبته عليها.', 0, 0, '77bed4b4eae6adfd75a43cf16aa3144feeda3a5d', 'الأحوال الشخصية / الورثة في التركة', 'تعيين محاسب علي التركه', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='0338b90704f446caa52cf5719698d798dc0b1482'), 'إبطال وقف أو وصية', 'دعوى تقام من الوارث أو ذي مصلحة ضد الواقف أو ورثته أو ورثة الموصي؛ بشأن إبطال وقف أو وصية.', 0, 0, '67b050ba2c52ddfeedef42bbf7630bd68487cf84', 'الأحوال الشخصية / الأوقاف والوصايا', 'ابطال وقف او وصيه', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='0338b90704f446caa52cf5719698d798dc0b1482'), 'إثبات وقف أو وصية', 'دعوى تقام من الناظر أو الوارث أو المستحق أو ذي مصلحة ضد الواقف أو ورثته أو ورثة الموصي؛ بشأن إثبات وقف أو وصية.', 0, 0, 'd043ee79b59db24ca5a822d27d9c35d42a763d02', 'الأحوال الشخصية / الأوقاف والوصايا', 'اثبات وقف او وصيه', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='0338b90704f446caa52cf5719698d798dc0b1482'), 'استحقاق في وقف أو وصية', 'دعوى تقام من المستحق في الوقف أو الوصية؛ بطلب إثبات استحقاقه فيهما أو تسليم نصيبه منهما.', 0, 0, 'a9bb54c729782cafba0a07ddabf0b633f31db01a', 'الأحوال الشخصية / الأوقاف والوصايا', 'استحقاق في وقف او وصيه', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='0338b90704f446caa52cf5719698d798dc0b1482'), 'ناظر وقف أو وصية', 'دعوى تقام من الوارث أو المستحق ضد الناظر أو الوصي أو المتصرف في الوقف؛ بطلب محاسبته لتقصيره أو إخلاله بالوقف أو الوصية.', 0, 0, '6c8a04f55b9e813161db27b573c96c69fbc1d48b', 'الأحوال الشخصية / الأوقاف والوصايا', 'ناظر وقف او وصيه', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='0338b90704f446caa52cf5719698d798dc0b1482'), 'مناعة في عقد استثمار أو تعمير', NULL, 1, 0, '3c77be69406c389bad08c00a4df671e9d5fb1130', 'الأحوال الشخصية / الأوقاف والوصايا', 'مناعه في عقد استثمار او تعمير', 'type heading present in source with no detail block', 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='78ab291551c7a1c872d38f8e577b48a597059c0e'), 'هبة بين زوجين أو مخطوبين', 'دعوى تقام ضد أحد الزوجين أو المخطوبين أو أحد الورثة؛ لطلب إثبات الهبة أو ردها أو نقضها.', 0, 0, 'e4cb97d610509db7cf7a4015deaff18f7eba3a32', 'الأحوال الشخصية / الهبة', 'هبه بين زوجين او مخطوبين', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='78ab291551c7a1c872d38f8e577b48a597059c0e'), 'هبة أحد أطراف الورثة', 'دعوى تقام ضد أحد الورثة؛ لطلب إثبات الهبة أو ردها أو نقضها.', 0, 0, '65733363f3e3510ae61efe76087a3d446b66060f', 'الأحوال الشخصية / الهبة', 'هبه احد اطراف الورثه', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='f66372d679fce1b979455556272cd9d27452facf'), 'المعارضة على صدور حكم غيابي', 'دعوى للاعتراض على الصكوك الإضافية الصادرة من المحكمة، بطلب تعديلها أو إلغائها.', 0, 0, 'ed3503ffc6ba1c36decf1951c468b7c6f5b79ffe', 'الأحوال الشخصية / التصنيف العام', 'المعارضه علي صدور حكم غيابي', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='f66372d679fce1b979455556272cd9d27452facf'), 'مطالبة مستندات', 'دعوى تقام ضد من بحوزته وثائق رسمية أو خاصة، كمستندات خاصة بالزوجة أو الأولاد أو فاقد الأهلية، أو مستندات خاصة بالتركة أو الأوقاف المتعلقة بالأحوال الشخصية.', 0, 0, '60425d848cc0bcd5a48e2ab1321894e22abc2f80', 'الأحوال الشخصية / التصنيف العام', 'مطالبه مستندات', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='f66372d679fce1b979455556272cd9d27452facf'), 'شهادة', 'دعوى تُقام من صاحب مصلحة -ولو محتملة- لإثبات شهادة يخشى فواتها، ويُحتمل أن تصبح محل نزاع أمام القضاء في الدعاوى التي تختص بها محكمة الأحوال الشخصية.', 0, 0, '6134fca31f11dd3bae36279352d2dac4ca822eda', 'الأحوال الشخصية / التصنيف العام', 'شهاده', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='f66372d679fce1b979455556272cd9d27452facf'), 'حارس قضائي', 'دعوى لطلب إقامة حارس قضائي على أموال تركة أو وقف أو وصية أو أموال فاقد الأهلية أو المحجور عليه.', 0, 0, 'e75f98fa22d7a99fea888c51a5ae01086e9ac932', 'الأحوال الشخصية / التصنيف العام', 'حارس قضايي', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='f66372d679fce1b979455556272cd9d27452facf'), 'المنع من السفر', 'مطالبة بمنع المدعى عليه من السفر إذا قامت أسباب تدعو للظن بأن سفره متوقع، ويعرض حق المدعي للخطر أو يؤخر أداءه، في المنازعات التي تختص بها محاكم الأحوال الشخصية.', 0, 0, '1b946e9a688ed1c9bb9bea6774665d17b684892f', 'الأحوال الشخصية / التصنيف العام', 'المنع من السفر', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='f66372d679fce1b979455556272cd9d27452facf'), 'التعويض عن اضرار التقاضي في قضايا الأحوال الشخصية', 'دعوى لطلب تعويض مادي عن ضرر لحق بأحد الخصوم نتيجة دعوى سابقة منظورة أمام محكمة الأحوال الشخصية.', 0, 0, '2c10b959cad063815a89919fd8db70b040296449', 'الأحوال الشخصية / التصنيف العام', 'التعويض عن اضرار التقاضي في قضايا الاحوال الشخصيه', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='f66372d679fce1b979455556272cd9d27452facf'), 'أتعاب المحامين أو الوكلاء', 'دعوى بشأن المنازعات المتعلقة بأتعاب الترافع بين الموكل والوكيل أو المحامي في قضايا الأحوال الشخصية.', 0, 0, '69bfc5226b6d39b5d0963e9134e87d0efea19247', 'الأحوال الشخصية / التصنيف العام', 'اتعاب المحامين او الوكلاء', NULL, 7, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='e47e0bd0bf4d99d18da353b29514dcfd1d43feeb'), 'عضل', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'ec1039a02c54a95d9d7891c1801177c709ec3330', 'الأحوال الشخصية / الولاية', 'عضل', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='e47e0bd0bf4d99d18da353b29514dcfd1d43feeb'), 'حجر أو رفع الحجر', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'd70c6042253fef1a747319793aac65c8c4bc4689', 'الأحوال الشخصية / الولاية', 'حجر او رفع الحجر', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='e47e0bd0bf4d99d18da353b29514dcfd1d43feeb'), 'ولي', 'دعوى تقام ضد الولي على القاصر سنًا أو عقلًا؛ بطلب عزله عن ولايته لموجب شرعي.', 0, 0, '13399724e745c86d5ce1ebedce3befff61e0a83b', 'الأحوال الشخصية / الولاية', 'ولي', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='e47e0bd0bf4d99d18da353b29514dcfd1d43feeb'), 'ولي', 'دعوى تقام ضد الولي على القاصر سنًا أو عقلًا؛ لطلب محاسبته على التصرفات المالية التي قام بها بموجب صك الولاية.', 0, 0, '6a59f9024942e14dfd6af5853c4180a84e3621c6', 'الأحوال الشخصية / الولاية', 'ولي', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='e47e0bd0bf4d99d18da353b29514dcfd1d43feeb'), 'ولي', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'e98a3e0a64914a08a450fb5df1400eef82bae2ed', 'الأحوال الشخصية / الولاية', 'ولي', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='e47e0bd0bf4d99d18da353b29514dcfd1d43feeb'), 'إثبات نسب أو نفيه', 'دعوى تقام من المدعي؛ لإثبات نسبه، أو ضد من أُلحق به نسبٌ؛ يطلب نفيه لانتفاء موجبه.', 0, 0, '24c5ab3d0fcf6b91121391d492791da0de916007', 'الأحوال الشخصية / الولاية', 'اثبات نسب او نفيه', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='cfa948f1813a39608d29f089b07106616efd39cb'), 'البيانات التجارية', 'الدعاوى والمخالفات الناشئة عن تطبيق أحكام نظام البيانات التجارية.', 0, 0, 'a1b2ff54d2cdff8c774e4199b07436bb763a2051', 'الدعاوى التجارية / الأنظمة التجارية', 'البيانات التجاريه', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='cfa948f1813a39608d29f089b07106616efd39cb'), 'الاستثمار الأجنبي', 'الدعاوى والمخالفات الناشئة عن تطبيق أحكام نظام الاستثمار الأجنبي.', 0, 0, 'd776a24873e36c4283d73cd1a74c9823221d167b', 'الدعاوى التجارية / الأنظمة التجارية', 'الاستثمار الاجنبي', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='cfa948f1813a39608d29f089b07106616efd39cb'), 'التجارة البحرية', 'الدعاوى والمخالفات الناشئة عن تطبيق أحكام النظام التجاري البحري.', 0, 0, '8b39a60812cfdcb419b7e1aa812b16bb1b879576', 'الدعاوى التجارية / الأنظمة التجارية', 'التجاره البحريه', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='cfa948f1813a39608d29f089b07106616efd39cb'), 'الامتياز التجاري', 'الدعاوى والمخالفات الناشئة عن تطبيق أحكام نظام الامتياز التجاري.', 0, 0, '77a9ddde639d8969842b7d95aaddc84d77e0ada1', 'الدعاوى التجارية / الأنظمة التجارية', 'الامتياز التجاري', NULL, 4, 1)
ON DUPLICATE KEY UPDATE
    subcategory_id            = VALUES(subcategory_id),
    name_ar                   = VALUES(name_ar),
    definition                = VALUES(definition),
    definition_missing        = VALUES(definition_missing),
    requests_declared_absent  = VALUES(requests_declared_absent),
    source_ref                = VALUES(source_ref),
    search_norm               = VALUES(search_norm),
    parse_note                = VALUES(parse_note),
    sort_order                = VALUES(sort_order),
    is_active                 = 1;

INSERT INTO najiz_case_types
    (subcategory_id, name_ar, definition, definition_missing, requests_declared_absent,
     source_key, source_ref, search_norm, parse_note, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_subcategories WHERE source_key='cfa948f1813a39608d29f089b07106616efd39cb'), 'الرهن التجاري', 'الدعاوى والمخالفات الناشئة عن تطبيق أحكام نظام الرهن التجاري.', 0, 0, '1be5612085ed476dfffa2f214c97e211b3f4e4c7', 'الدعاوى التجارية / الأنظمة التجارية', 'الرهن التجاري', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='cfa948f1813a39608d29f089b07106616efd39cb'), 'السجل التجاري', 'الدعاوى والمخالفات الناشئة عن تطبيق أحكام نظام السجل التجاري.', 0, 0, '2599cf3e68d9934358c94c460adbf5231478b4b2', 'الدعاوى التجارية / الأنظمة التجارية', 'السجل التجاري', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='cfa948f1813a39608d29f089b07106616efd39cb'), 'مكافحة الغش التجاري', 'الدعاوى والمخالفات الناشئة عن تطبيق أحكام نظام مكافحة الغش التجاري.', 0, 0, '9c4003b418c4116884f6af7b7bf10b174ada1dff', 'الدعاوى التجارية / الأنظمة التجارية', 'مكافحه الغش التجاري', NULL, 7, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='cfa948f1813a39608d29f089b07106616efd39cb'), 'الأوراق التجارية', 'الدعاوى المتعلقة بالحق الخاص الناشئة عن تطبيق أحكام نظام الأوراق التجارية (شيك – كمبيالة – سند لأمر).', 0, 0, 'fc998d8b845ac3358d2f842eddbc140fa5e1ecb9', 'الدعاوى التجارية / الأنظمة التجارية', 'الاوراق التجاريه', NULL, 8, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='cfa948f1813a39608d29f089b07106616efd39cb'), 'المنافسة الغير مشروعة', 'الدعاوى والمخالفات الناشئة عن تطبيق أحكام نظام المنافسة.', 0, 0, 'a9717fc33e7caffa5c738d312bcf94585d7eb9ca', 'الدعاوى التجارية / الأنظمة التجارية', 'المنافسه الغير مشروعه', NULL, 9, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='cfa948f1813a39608d29f089b07106616efd39cb'), 'الوكالات التجارية', 'الدعاوى والمخالفات الناشئة عن تطبيق أحكام نظام الوكالات التجارية.', 0, 0, 'b74c96324138cf4b784e7c8e81f3283e950c86ca', 'الدعاوى التجارية / الأنظمة التجارية', 'الوكالات التجاريه', NULL, 10, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='cfa948f1813a39608d29f089b07106616efd39cb'), 'الأسماء التجارية', 'المنازعات الناشئة عن تطبيق أحكام نظام الأسماء التجارية.', 0, 0, 'ef2dbf721b68921393c3b86248e1cde849c1f9d5', 'الدعاوى التجارية / الأنظمة التجارية', 'الاسماء التجاريه', NULL, 11, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='cfa948f1813a39608d29f089b07106616efd39cb'), 'حماية الأسرار التجارية', 'الدعاوى والمنازعات الناشئة عن تطبيق أحكام لائحة حماية المعلومات التجارية السرية.', 0, 0, 'b180adf7d763863653639e727b766fde86eab53d', 'الدعاوى التجارية / الأنظمة التجارية', 'حمايه الاسرار التجاريه', NULL, 12, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='30c07b97123a64dca268648a953e9f0fde48745b'), 'أتعاب محاماة أو وكلاء', 'المطالبة بأتعاب محاماة أو ترافع أو استرجاعها، تقام من المحامي أو الوكيل ضد الموكل أو العكس، بناءً على اتفاق بين الطرفين قبل تقديم الدعوى أو بعدها.', 0, 0, '98bcdf577b28361b0523729f9290c16e98d27946', 'الدعاوى التجارية / الطلبات القضائية', 'اتعاب محاماه او وكلاء', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='30c07b97123a64dca268648a953e9f0fde48745b'), 'دعوى ضرر بين التجار بسبب المسؤولية التقصيرية', 'الدعاوى المقامة من تاجر ضد تاجر؛ بسبب أعماله التجارية الأصلية أو التبعية الناشئة عن مسؤوليته التقصيرية غير المتعلقة بالعقود المبرمة بينهما.', 0, 0, 'a1ef0c3febb4a432dd0984eadbc8721245eb312c', 'الدعاوى التجارية / الطلبات القضائية', 'دعوي ضرر بين التجار بسبب المسووليه التقصيريه', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='30c07b97123a64dca268648a953e9f0fde48745b'), 'المعينين في القضايا التجارية', 'الدعاوى والطلبات المتعلقة بأعوان القضاة وتعويض المعينين في القضايا التجارية.', 0, 0, 'fa8de4c72a0109f3034b46afe53649d6db29d451', 'الدعاوى التجارية / الطلبات القضائية', 'المعينين في القضايا التجاريه', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='30c07b97123a64dca268648a953e9f0fde48745b'), 'أوامر الأداء', 'طلب يُقدم من الدائن لإصدار أمر أداء دين حالّ الأداء، ثابت بالكتابة، معين المقدار (نقدًا) أو بالنوع والمقدار (منقول معين) أو معين بذاته نافيًا للجهالة.', 0, 0, 'bf3b62303c98e7142b57cfce9288fe59b691c715', 'الدعاوى التجارية / الطلبات القضائية', 'اوامر الاداء', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='30c07b97123a64dca268648a953e9f0fde48745b'), 'التعويض عن مصاريف التقاضي', 'المطالبة بالتعويض عن الأضرار الناشئة عن التقاضي ومصروفات الدعوى، كأتعاب الترافع والخبرة والترجمة والسفر والسكن ونحوها.', 0, 0, 'af9229fb5292b1e0f256f915b1888ee48ef83f1e', 'الدعاوى التجارية / الطلبات القضائية', 'التعويض عن مصاريف التقاضي', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='30c07b97123a64dca268648a953e9f0fde48745b'), 'التظلم على أمر الأداء', 'إجراء قانوني يتيح للمدين أو من صدر ضده أمر الأداء؛ بطلب مراجعته أمام المحكمة المختصة.', 0, 0, '97dc57f39f20a92a70fc765c89cb78a01bc87e41', 'الدعاوى التجارية / الطلبات القضائية', 'التظلم علي امر الاداء', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6e2bee086979fc0222fe7994f7dc5172117f59d1'), 'إعادة تنظيم مالي', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 1, '05b1ebb0c1859f1a62000060db08f8fb8dab57d2', 'الدعاوى التجارية / الإفلاس', 'اعاده تنظيم مالي', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6e2bee086979fc0222fe7994f7dc5172117f59d1'), 'إعادة تنظيم صغار المدينين', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 1, '3bd2709cd0b4e7002ac79b62ecf2b31d0b42e4b4', 'الدعاوى التجارية / الإفلاس', 'اعاده تنظيم صغار المدينين', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6e2bee086979fc0222fe7994f7dc5172117f59d1'), 'تصفية', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 1, '2a1914e5581ce07c33a1b4c1c6a19753ecd6f8cb', 'الدعاوى التجارية / الإفلاس', 'تصفيه', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6e2bee086979fc0222fe7994f7dc5172117f59d1'), 'تسوية وقائية', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 1, '40fe469f03a703b718dc42fc84d423598f543834', 'الدعاوى التجارية / الإفلاس', 'تسويه وقاييه', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6e2bee086979fc0222fe7994f7dc5172117f59d1'), 'تصفية إدارية', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 1, 'e4f7d42a04d844269a0c3195fd10c5c59b13ad55', 'الدعاوى التجارية / الإفلاس', 'تصفيه اداريه', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6e2bee086979fc0222fe7994f7dc5172117f59d1'), 'اعتراض على قرار الجهة المختصة برفض طلب افتتاح الإجراء', 'اعتراض على قرار الجهة المختصة برفض طلب افتتاح إجراء الإفلاس.', 0, 1, '7ca16229790c5974d0013e9b0222928536bd49aa', 'الدعاوى التجارية / الإفلاس', 'اعتراض علي قرار الجهه المختصه برفض طلب افتتاح الاجراء', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6e2bee086979fc0222fe7994f7dc5172117f59d1'), 'طلبات فرعية على إجراء مفتتح', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 1, '00200143917a3265cd20db1ed81269c920d198bb', 'الدعاوى التجارية / الإفلاس', 'طلبات فرعيه علي اجراء مفتتح', NULL, 7, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6e2bee086979fc0222fe7994f7dc5172117f59d1'), 'تصفية صغار المدينين', NULL, 1, 0, '9f60ad63e483f65b896043de37da9d42ee85d7f7', 'الدعاوى التجارية / الإفلاس', 'تصفيه صغار المدينين', 'type heading present in source with no detail block', 8, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='2283e069b05b67c44cb56a33159deaca0883002b'), 'حماية حقوق المؤلف', 'الدعاوى المتعلقة بالمنازعات الناشئة عن تطبيق أحكام نظام حماية حقوق المؤلف.', 0, 0, '56f1e85456abc527bbd11af055899667799ef05a', 'الدعاوى التجارية / الملكية الفكرية', 'حمايه حقوق المولف', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='2283e069b05b67c44cb56a33159deaca0883002b'), 'العلامات التجارية', 'الدعاوى المتعلقة بالمنازعات الناشئة عن تطبيق أحكام نظام العلامات التجارية.', 0, 0, 'fd2376c79607f609807e1aa6fc881234a3755353', 'الدعاوى التجارية / الملكية الفكرية', 'العلامات التجاريه', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='2283e069b05b67c44cb56a33159deaca0883002b'), 'براءات الاختراع', 'الدعاوى المتعلقة بالمنازعات الناشئة عن تطبيق أحكام نظام براءات الاختراع.', 0, 0, '35e681c3a3ef1ee32a235f9a29ed456cd850b2f5', 'الدعاوى التجارية / الملكية الفكرية', 'براءات الاختراع', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='da0cb0a769d1a13478948d173e079039833e4f10'), 'إجارة', 'الدعاوى المقامة بين التجار أو أعمالهم التجارية المتعلقة بالإجارة، أو المقامة على التاجر الناشئة عن عقد إجارة تزيد قيمة المنازعة الأصلية فيه عن 500 ألف (سواء كان عقدًا مكتوبًا أو غير مكتوب).', 0, 0, '91e86e907633e78dbb469db3f56e6d444e86752e', 'الدعاوى التجارية / العقود التجارية', 'اجاره', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='da0cb0a769d1a13478948d173e079039833e4f10'), 'الحوالة التجارية (الغير)', 'الدعاوى المقامة بين التجار الناشئة عن أعمالهم التجارية المتعلقة بعقد الحوالة التجارية الذي بموجبه يُنقل الدين من ذمة شخص لآخر، وتزيد قيمة المنازعة عن مائة ألف.', 0, 0, '31faa7622710f0abe4eb0bd5bf7c2eecd93cb8aa', 'الدعاوى التجارية / العقود التجارية', 'الحواله التجاريه (الغير)', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='da0cb0a769d1a13478948d173e079039833e4f10'), 'الحوالة التجارية (المتعلقة بالعقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '7a158bb8d5267b167401b2364f8bcbec174903e0', 'الدعاوى التجارية / العقود التجارية', 'الحواله التجاريه (المتعلقه بالعقد)', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='da0cb0a769d1a13478948d173e079039833e4f10'), 'الدعاية والإعلان والتسويق', 'الدعاوى المقامة بين التجار الناشئة عن أعمالهم التجارية المتعلقة بالدعاية والإعلان والتسويق، أو المقامة على التاجر عن عقد كذلك، وتزيد قيمة المنازعة عن 500 ألف.', 0, 0, '0f5105a39cf8c7a7c6d5e433619cc680507881b4', 'الدعاوى التجارية / العقود التجارية', 'الدعايه والاعلان والتسويق', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='da0cb0a769d1a13478948d173e079039833e4f10'), 'الكفالة التجارية', 'الدعاوى المقامة بين التجار الناشئة عن كفالة لشخص بسبب أعماله التجارية، أو المقامة على التاجر عن الكفالة التجارية، وتزيد قيمة المنازعة عن 500 ألف.', 0, 0, 'b65556c6c38677fba3b09032f2b4687e88dbd1f0', 'الدعاوى التجارية / العقود التجارية', 'الكفاله التجاريه', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='da0cb0a769d1a13478948d173e079039833e4f10'), 'المقاولات (مستحقات مالية-عقد المقاولات)', 'الدعاوى المقامة بين التجار الناشئة عن أعمالهم بالمقاولات، أو المقامة على التاجر عن عقد مقاولة، وتزيد قيمة المنازعة عن 500 ألف.', 0, 0, 'e2e1c5abdfc3deb0c6c0e5294997991034c7ec75', 'الدعاوى التجارية / العقود التجارية', 'المقاولات (مستحقات ماليه-عقد المقاولات)', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='da0cb0a769d1a13478948d173e079039833e4f10'), 'المكاتب والمحلات التجارية', 'الدعاوى المقامة بين التجار الناشئة عن أنشطة المكاتب والمحلات التجارية، وتزيد قيمة المنازعة عن 500 ألف.', 0, 0, '9114d077e5ac39f924c923b8b4b91af847004d30', 'الدعاوى التجارية / العقود التجارية', 'المكاتب والمحلات التجاريه', NULL, 7, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='da0cb0a769d1a13478948d173e079039833e4f10'), 'النقل', 'الدعاوى المقامة بين التجار الناشئة عن عقود النقل التي يتعهد بمقتضاها الناقل بنقل بضاعة أو أشخاص من نقطة لأخرى برًا أو جوًا أو بحرًا، وتزيد قيمة المنازعة عن 500 ألف.', 0, 0, 'a9d54b3fbd35147b493c46e289675eac6a7e4b17', 'الدعاوى التجارية / العقود التجارية', 'النقل', NULL, 8, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='da0cb0a769d1a13478948d173e079039833e4f10'), 'بيع وتوريد', 'الدعاوى المقامة بين التجار الناشئة عن البيع والتوريد، أو المقامة على التاجر عن عقد بيع أو توريد تجاري، وتزيد قيمة المنازعة عن 500 ألف، سواء لمرة واحدة أو على سبيل التكرار.', 0, 0, 'b28ff82e238480718addacca32861ed5b206e531', 'الدعاوى التجارية / العقود التجارية', 'بيع وتوريد', NULL, 9, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='da0cb0a769d1a13478948d173e079039833e4f10'), 'سمسرة', 'الدعاوى المقامة بين التجار الناشئة عن السمسرة، أو المقامة على التاجر عن عقد سمسرة تجاري، وتزيد قيمة المنازعة عن 500 ألف.', 0, 0, '0261a6d554776848d50bd4422295d3d5bf86c7b2', 'الدعاوى التجارية / العقود التجارية', 'سمسره', NULL, 10, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='4cb6004c5cc0a0b24b81eae32a87b82ba81aa437'), 'شركات المضاربة', 'الدعاوى المقامة في شركة المضاربة التي يكون رأس مالها من طرف والعمل واشتراك الجميع في الربح الحاصل.', 0, 0, '477887429a7bc3070b61eed1cd1212eb8589b24a', 'الدعاوى التجارية / الشركات', 'شركات المضاربه', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='4cb6004c5cc0a0b24b81eae32a87b82ba81aa437'), 'الشركات النظامية', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '333ebfb09ccd9f92be8eceebabc00c0310b0eacb', 'الدعاوى التجارية / الشركات', 'الشركات النظاميه', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='4cb6004c5cc0a0b24b81eae32a87b82ba81aa437'), 'عقد الشركة', 'الدعاوى في عقد الشركة الذي يساهم بمقتضاه شريكان أو أكثر بتقديم حصة من مال أو عمل أو منهما معًا في مشروع؛ لاقتسام ما ينشأ عنه من ربح أو خسارة.', 0, 0, '06f423b185a88c7be012dd2d1280754bb0964bb2', 'الدعاوى التجارية / الشركات', 'عقد الشركه', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='4cb6004c5cc0a0b24b81eae32a87b82ba81aa437'), 'عقد المشاركة في الناتج', 'الدعاوى الناشئة عن عقد المشاركة في الناتج، وهو عقد يسلم رب المال بمقتضاه شيئًا غير قابل للاستهلاك لمن يستغله مقابل جزء شائع من الناتج.', 0, 0, '2bbb848cd221d82dea03c5a2a226765327066a4b', 'الدعاوى التجارية / الشركات', 'عقد المشاركه في الناتج', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='82892e83d5bb1b3605abb0c6e51d62e0e355e24d'), 'الحجز التحفظي', 'دعوى للمطالبة بالحجز التحفظي، وهو إجراء قانوني يتخذ لضمان حق الدائن عند وجود خطر حقيقي يهدد بتحصيل الدين، في المنازعات التي تختص بها المحكمة التجارية.', 0, 0, '3052e50eef34b4c7624c4fe8f9302281b81f63ee', 'الدعاوى التجارية / الدعاوى المستعجلة', 'الحجز التحفظي', NULL, 1, 1)
ON DUPLICATE KEY UPDATE
    subcategory_id            = VALUES(subcategory_id),
    name_ar                   = VALUES(name_ar),
    definition                = VALUES(definition),
    definition_missing        = VALUES(definition_missing),
    requests_declared_absent  = VALUES(requests_declared_absent),
    source_ref                = VALUES(source_ref),
    search_norm               = VALUES(search_norm),
    parse_note                = VALUES(parse_note),
    sort_order                = VALUES(sort_order),
    is_active                 = 1;

INSERT INTO najiz_case_types
    (subcategory_id, name_ar, definition, definition_missing, requests_declared_absent,
     source_key, source_ref, search_norm, parse_note, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_subcategories WHERE source_key='82892e83d5bb1b3605abb0c6e51d62e0e355e24d'), 'دراسة قضائية', 'دعوى للمطالبة بوضع الأموال المتنازع عليها تحت يد من يتكفل بحفظ المال وإدارته.', 0, 0, 'af7f6e9abeca57109ddaada7705f4e45f7032206', 'الدعاوى التجارية / الدعاوى المستعجلة', 'دراسه قضاييه', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='82892e83d5bb1b3605abb0c6e51d62e0e355e24d'), 'منع من السفر', 'دعوى للمطالبة بمنع المدعى عليه من السفر إذا قامت أسباب تدعو للظن بأن سفره متوقع، ويعرض حق المدعي للخطر أو يؤخر أداءه.', 0, 0, 'a2494e4fdbb31fd0ad54989ca50e87c443107207', 'الدعاوى التجارية / الدعاوى المستعجلة', 'منع من السفر', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='82892e83d5bb1b3605abb0c6e51d62e0e355e24d'), 'وقف الأعمال الجديدة', 'دعوى للمطالبة بوقف أعمال جديدة شرع فيها المدعى عليه، من شأنها الإضرار بالمدعي.', 0, 0, '354b230ffa0da2299eb148d7da3c50dd2b49a3a2', 'الدعاوى التجارية / الدعاوى المستعجلة', 'وقف الاعمال الجديده', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='82892e83d5bb1b3605abb0c6e51d62e0e355e24d'), 'معاينة لإثبات حالة', 'دعوى مقامة من صاحب مصلحة ولو محتملة؛ لإثبات معالم واقعة يُحتمل أن تصبح محل نزاع أمام القضاء مستقبلًا.', 0, 0, 'ea0d190ada65fd214b381dfb50cfa793e097ec8e', 'الدعاوى التجارية / الدعاوى المستعجلة', 'معاينه لاثبات حاله', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='82892e83d5bb1b3605abb0c6e51d62e0e355e24d'), 'وقف تنفيذ', 'دعوى للمطالبة بوقف تنفيذ القرارات الصادرة من محاكم التنفيذ.', 0, 0, '572e92645ae797c1877e92a9130dce505cd62a1b', 'الدعاوى التجارية / الدعاوى المستعجلة', 'وقف تنفيذ', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='82892e83d5bb1b3605abb0c6e51d62e0e355e24d'), 'الحصول على عينة من منتج', 'دعوى للمطالبة بالحصول على عينة من منتج؛ لفحصه والتحقق من مطابقته للمواصفات أو تحديد عيوب فيه.', 0, 0, '366cf7b643f34d3ae8908307f8901356047b9e14', 'الدعاوى التجارية / الدعاوى المستعجلة', 'الحصول علي عينه من منتج', NULL, 7, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='82892e83d5bb1b3605abb0c6e51d62e0e355e24d'), 'التحفظ على مستندات معينة', 'دعوى للمطالبة بالتحفظ على مستندات معينة؛ لحمايتها ومنع فقدانها أو العبث بها أو إخفائها لحين البت في القضية.', 0, 0, 'd07b582ed8f0a0f96657c89ef8581426b9e1f02f', 'الدعاوى التجارية / الدعاوى المستعجلة', 'التحفظ علي مستندات معينه', NULL, 8, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='82892e83d5bb1b3605abb0c6e51d62e0e355e24d'), 'المنع والأذن في التصرف', 'دعوى للمطالبة بالمنع من التصرف في مال أو غيره لحين الفصل في النزاع القائم أو إثبات الحقوق المتعلقة بهذا المال.', 0, 0, '0378bc87f22be206c6a97405ff6f88619c5de86a', 'الدعاوى التجارية / الدعاوى المستعجلة', 'المنع والاذن في التصرف', NULL, 9, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='82892e83d5bb1b3605abb0c6e51d62e0e355e24d'), 'إثبات شهادة', 'دعوى إثبات شهادة يخشى فواتها.', 0, 0, 'e62c1d0793b9a537010c9663c7cb7c205e3a0fc7', 'الدعاوى التجارية / الدعاوى المستعجلة', 'اثبات شهاده', NULL, 10, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='82892e83d5bb1b3605abb0c6e51d62e0e355e24d'), 'طلبات عاجلة', 'طلب عاجل بموجب المادة 36 من نظام المحاكم التجارية.', 0, 0, '2924cf45540a458c37368dad43239e337a1fb9b5', 'الدعاوى التجارية / الدعاوى المستعجلة', 'طلبات عاجله', NULL, 11, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='26ce3228d5b8300e9ce839327c753492d193ff49'), 'التعويض', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'f645f2f1b1ed5e19fc96c0b075a3eca00ac71846', 'الدعاوى العمالية / العمالة العادية – مطالبات صاحب العمل', 'التعويض', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='26ce3228d5b8300e9ce839327c753492d193ff49'), 'مطالبات صاحب العمل (غير مالية)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '3665fed1a4a48965f4cddd4225bb33f9f5ae6cb5', 'الدعاوى العمالية / العمالة العادية – مطالبات صاحب العمل', 'مطالبات صاحب العمل (غير ماليه)', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='26ce3228d5b8300e9ce839327c753492d193ff49'), 'مطالبات صاحب العمل (مالية)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'f1ae3729b20767c3cc5342ee2451dc837f203cf4', 'الدعاوى العمالية / العمالة العادية – مطالبات صاحب العمل', 'مطالبات صاحب العمل (ماليه)', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='5978dca598205ea9d7423fee68ae30754e230d53'), 'مطالبات بحقوق وظيفية', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '2026d73d940b5bbd4026fb674d52d39d1c516a57', 'الدعاوى العمالية / العمالة العادية – مطالبات العامل بحقوق وظيفية', 'مطالبات بحقوق وظيفيه', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='dc270fd2c075e6bd9ccd58249bcc1842007f2a5d'), 'التعويض', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'e117cb955a812c7b83a584cb39a08bfa95a1c539', 'الدعاوى العمالية / العمالة العادية – مطالبات العامل', 'التعويض', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='dc270fd2c075e6bd9ccd58249bcc1842007f2a5d'), 'مالي', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '91948095df8f2b93d791bf3942d3eb6a80c16767', 'الدعاوى العمالية / العمالة العادية – مطالبات العامل', 'مالي', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='dc270fd2c075e6bd9ccd58249bcc1842007f2a5d'), 'مالي (في حال انتهاء العلاقة العمالية)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '9d674a8e198c73be86325296ce002d7038be74e2', 'الدعاوى العمالية / العمالة العادية – مطالبات العامل', 'مالي (في حال انتهاء العلاقه العماليه)', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='dc270fd2c075e6bd9ccd58249bcc1842007f2a5d'), 'مطالبات تخص عقد العمل', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'd70505a92b06d5a73ffd55dfb4af80503378a7b4', 'الدعاوى العمالية / العمالة العادية – مطالبات العامل', 'مطالبات تخص عقد العمل', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='bbd6e3bf0974318e1f1371e49b30b776a0f27eab'), 'مطالبات صاحب العمل المنزلي (مالية)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'eae951ff5de8208732d0860a88754be420956ace', 'الدعاوى العمالية / العمالة المنزلية', 'مطالبات صاحب العمل المنزلي (ماليه)', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='bbd6e3bf0974318e1f1371e49b30b776a0f27eab'), 'مطالبات صاحب العمل المنزلي (غير مالية)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'e1dbcde6f91e102ffbfb6ab5e7c2561df4e8c975', 'الدعاوى العمالية / العمالة المنزلية', 'مطالبات صاحب العمل المنزلي (غير ماليه)', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='bbd6e3bf0974318e1f1371e49b30b776a0f27eab'), 'مطالبات العامل المنزلي (مالية)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'eddab8f4beaa58f91dd58241d3f9cc4b9a0d2005', 'الدعاوى العمالية / العمالة المنزلية', 'مطالبات العامل المنزلي (ماليه)', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='bbd6e3bf0974318e1f1371e49b30b776a0f27eab'), 'مطالبات العامل المنزلي (غير مالية)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '1ad33a1188072c7cf7cac22a90c164a6261eba9c', 'الدعاوى العمالية / العمالة المنزلية', 'مطالبات العامل المنزلي (غير ماليه)', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='3377a8362582bbc007053aa4d6ec1a5ed23e15a1'), 'المنع من السفر', 'دعوى مستعجلة للمطالبة بمنع المدعى عليه من السفر إذا قامت أسباب تدعو للظن بأن سفره متوقع، ويعرض حق المدعي للخطر أو يؤخر أداءه.', 0, 0, 'a4a3d377d5bcf722bafc81f00b11568be767df23', 'الدعاوى الجزائية / الطلبات القضائية', 'المنع من السفر', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='3377a8362582bbc007053aa4d6ec1a5ed23e15a1'), 'إثبات تنازل', 'دعوى تتعلق بتنازل المجني عليه أو ورثته عن قصاص أو حد قذف.', 0, 0, 'a5b06bb339758398c56dead175de1d106b883de5', 'الدعاوى الجزائية / الطلبات القضائية', 'اثبات تنازل', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='3377a8362582bbc007053aa4d6ec1a5ed23e15a1'), 'تسليم مضبوطات', 'دعوى بطلب تسليم مضبوطات خاصة محجوزة لدى جهات حكومية (جهات الضبط أو التحقيق).', 0, 0, '3baf89a0fa4ce7124a53179d53212e15d77afc7c', 'الدعاوى الجزائية / الطلبات القضائية', 'تسليم مضبوطات', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='3377a8362582bbc007053aa4d6ec1a5ed23e15a1'), 'المعاينة لإثبات حالة', 'دعوى من صاحب مصلحة ولو محتملة؛ لإثبات حالة يُحتمل أن تصبح محل نزاع أمام القضاء مستقبلًا، في المنازعات التي تختص بها المحكمة الجزائية.', 0, 0, 'ab4fe4b10ddaa1ce5a11d7fec5dbadb3f885a94b', 'الدعاوى الجزائية / الطلبات القضائية', 'المعاينه لاثبات حاله', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='3377a8362582bbc007053aa4d6ec1a5ed23e15a1'), 'إثبات الشهادة', 'دعوى من صاحب مصلحة ولو محتملة؛ لإثبات شهادة يخشى فواتها، في المنازعات التي تختص بها المحكمة الجزائية.', 0, 0, '4fb9ebb6c63b7f79e1db142b65a0caae70b7f101', 'الدعاوى الجزائية / الطلبات القضائية', 'اثبات الشهاده', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='3377a8362582bbc007053aa4d6ec1a5ed23e15a1'), 'التظلم من أمر توقيف', 'التظلم من أمر التوقيف أو تمديده الصادر من جهات التحقيق ضد المتهم؛ خلال سير إجراءات التحقيق والذي لا يزال ساري المفعول.', 0, 0, 'dc84697ee4ce60c5aeb0db23dc9de176f674837d', 'الدعاوى الجزائية / الطلبات القضائية', 'التظلم من امر توقيف', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='1a187705ac91a2861a2c722dec9583901859ee23'), 'نفس', 'دعوى يقدمها أولياء الدم للمطالبة بقتل الجاني قصاصًا.', 0, 0, '9d01bb65396fae03294dcd766df732289458e945', 'الدعاوى الجزائية / القصاص والحدود', 'نفس', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='1a187705ac91a2861a2c722dec9583901859ee23'), 'ما دون النفس', 'المطالبة بإتلاف عضو لدى الجاني على سبيل المماثلة، بقطعه أو إتلافه أو إتلاف منفعته، أو المقاصة بجرحه.', 0, 0, 'a752334a7cbf82951603a6c9212cce48dda9b761', 'الدعاوى الجزائية / القصاص والحدود', 'ما دون النفس', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='1a187705ac91a2861a2c722dec9583901859ee23'), 'القذف', 'دعوى إقامة حد القذف.', 0, 0, 'e0d44fa025aed5d72794ab5962f7c834d3870057', 'الدعاوى الجزائية / القصاص والحدود', 'القذف', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='5b928b340e8f9d724be500677a58be378f2e64ca'), 'حد قذف أو قصاص', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '405c0a6a5e40cfaa1fc691cffb611e9f050cce0b', 'الدعاوى الجزائية / مطالبة الحق الخاص', 'حد قذف او قصاص', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='5b928b340e8f9d724be500677a58be378f2e64ca'), 'التعويض', 'المطالبة بالحق الخاص الناشئ عن ارتكاب جريمة أحدثت ضررًا للمجني عليه.', 0, 0, '57e9a8f5e2fee9bafb211e8dcbb97f0715d5dbb5', 'الدعاوى الجزائية / مطالبة الحق الخاص', 'التعويض', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='51b7406646147271cdc5bba4fb4415bb588e9b07'), 'ارش إصابة', 'المطالبة بعوض عن الجناية غير المقدر شرعًا، قبل انقضاء الدعوى الجزائية العامة.', 0, 0, '123deaff377e93fcca9f4f7227ec3bce967d58c6', 'الدعاوى الجزائية / المطالبة المالية', 'ارش اصابه', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='51b7406646147271cdc5bba4fb4415bb588e9b07'), 'التعويض عن السجن', 'المطالبة بالتعويض عن السجن للمدة الزائدة عن المدة المحكوم بها، في حال وجود دعوى جزائية مرفوعة.', 0, 0, 'f3788bb48681afbfaf82676e7e286642787fa1b1', 'الدعاوى الجزائية / المطالبة المالية', 'التعويض عن السجن', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='51b7406646147271cdc5bba4fb4415bb588e9b07'), 'دية', 'في جرائم إتلاف النفس أو الأعضاء أو المنافع، المطالبة بالتعويض قبل انقضاء الدعوى الجزائية العامة.', 0, 0, '678221c7ebc97197716fecbc49e3756b7184a584', 'الدعاوى الجزائية / المطالبة المالية', 'ديه', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='51b7406646147271cdc5bba4fb4415bb588e9b07'), 'رد العين', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '6489bcacf1ec94b88638dae98cba679b05d49481', 'الدعاوى الجزائية / المطالبة المالية', 'رد العين', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='51b7406646147271cdc5bba4fb4415bb588e9b07'), 'أتعاب محاماة أو وكلاء', 'المطالبة بأتعاب محاماة أو ترافع أو استرجاعها، تقام من المحامي أو الوكيل ضد موكله أو العكس، بناءً على اتفاق بين الطرفين.', 0, 0, '96cfc9263419c62521a4c7917e99e724851779c8', 'الدعاوى الجزائية / المطالبة المالية', 'اتعاب محاماه او وكلاء', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='51b7406646147271cdc5bba4fb4415bb588e9b07'), 'التعويض عن اضرار التقاضي', 'المطالبة بالتعويض عن الأضرار الناشئة عن التقاضي ومصروفات دعوى سابقة منظورة أمام المحكمة الجزائية.', 0, 0, '54f72251bfa5c506c604b99f566881a61faae68f', 'الدعاوى الجزائية / المطالبة المالية', 'التعويض عن اضرار التقاضي', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='51b7406646147271cdc5bba4fb4415bb588e9b07'), 'السرقة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 1, '402e052d630ec996a6d66c848062315dc6ac711d', 'الدعاوى الجزائية / المطالبة المالية', 'السرقه', NULL, 7, 1)
ON DUPLICATE KEY UPDATE
    subcategory_id            = VALUES(subcategory_id),
    name_ar                   = VALUES(name_ar),
    definition                = VALUES(definition),
    definition_missing        = VALUES(definition_missing),
    requests_declared_absent  = VALUES(requests_declared_absent),
    source_ref                = VALUES(source_ref),
    search_norm               = VALUES(search_norm),
    parse_note                = VALUES(parse_note),
    sort_order                = VALUES(sort_order),
    is_active                 = 1;

INSERT INTO najiz_case_types
    (subcategory_id, name_ar, definition, definition_missing, requests_declared_absent,
     source_key, source_ref, search_norm, parse_note, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_subcategories WHERE source_key='6785602593a99ed20d0a8ad8eedc815f2f202d6e'), 'أتعاب محاماة أو وكلاء', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '09271b0b1c6fd410c395885afe7c5ead260fd769', 'الدعاوى العامة / الطلبات المالية', 'اتعاب محاماه او وكلاء', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6785602593a99ed20d0a8ad8eedc815f2f202d6e'), 'شراكة في أملاك غير عقارية', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '9ba89f7c16fe1fe89de32960fd5709d2b3f50d62', 'الدعاوى العامة / الطلبات المالية', 'شراكه في املاك غير عقاريه', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6785602593a99ed20d0a8ad8eedc815f2f202d6e'), 'ارش عصابة أودية في غير حادث مروري', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '7bbe0466fb2c98796558358211debd2b82c35a4b', 'الدعاوى العامة / الطلبات المالية', 'ارش عصابه اوديه في غير حادث مروري', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6785602593a99ed20d0a8ad8eedc815f2f202d6e'), 'حادث مروري', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'c9170746a4ed402cf1b80b5a175a2c02789148d6', 'الدعاوى العامة / الطلبات المالية', 'حادث مروري', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6785602593a99ed20d0a8ad8eedc815f2f202d6e'), 'المساهمة العقارية والتعويض على قرار التسجيل العيني', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '393e0199b442cc099ad6894ea2de27919910dfe1', 'الدعاوى العامة / الطلبات المالية', 'المساهمه العقاريه والتعويض علي قرار التسجيل العيني', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6785602593a99ed20d0a8ad8eedc815f2f202d6e'), 'الفعل الضار', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'ffd30bc3ad70d715630e12e5b2dd2c73b07bb503', 'الدعاوى العامة / الطلبات المالية', 'الفعل الضار', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6785602593a99ed20d0a8ad8eedc815f2f202d6e'), 'قيمة مثل العين', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '5934ace709f1d58e8f8fbc0583b98b383b4efeed', 'الدعاوى العامة / الطلبات المالية', 'قيمه مثل العين', NULL, 7, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6785602593a99ed20d0a8ad8eedc815f2f202d6e'), 'التعويض عن اضرار التقاضي', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'eb270ae31aaa25db6326888ec71e7c235a9c4033', 'الدعاوى العامة / الطلبات المالية', 'التعويض عن اضرار التقاضي', NULL, 8, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='5e4f8a3ad9b72e2a62344b49f623d284a10b4f0f'), 'ملكية العقار', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'f6fb117f865886957803d0d476c477489206136c', 'الدعاوى العامة / الطلبات الغير مالية (العقارية)', 'ملكيه العقار', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='5e4f8a3ad9b72e2a62344b49f623d284a10b4f0f'), 'إخلاء العقار', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '66a45230354cb397b932a1214c10e472db0be754', 'الدعاوى العامة / الطلبات الغير مالية (العقارية)', 'اخلاء العقار', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='5e4f8a3ad9b72e2a62344b49f623d284a10b4f0f'), 'استطراق والتمكين من العبور', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '842dcee6fd306fc1aee3655c6df5df4121ffcd92', 'الدعاوى العامة / الطلبات الغير مالية (العقارية)', 'استطراق والتمكين من العبور', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='5e4f8a3ad9b72e2a62344b49f623d284a10b4f0f'), 'تداخل العقارات', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '003c1ac61dcefd0082076479b6182fa43fa2a3aa', 'الدعاوى العامة / الطلبات الغير مالية (العقارية)', 'تداخل العقارات', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='5e4f8a3ad9b72e2a62344b49f623d284a10b4f0f'), 'حق الشفعة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '2f5eed1cbb6113f0ed571ddd407419e80dddcb22', 'الدعاوى العامة / الطلبات الغير مالية (العقارية)', 'حق الشفعه', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='5e4f8a3ad9b72e2a62344b49f623d284a10b4f0f'), 'عقارات مشتركة وقسمتها', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'c3a42dfee5b45f16316901afd197bc12b8b4e338', 'الدعاوى العامة / الطلبات الغير مالية (العقارية)', 'عقارات مشتركه وقسمتها', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='5e4f8a3ad9b72e2a62344b49f623d284a10b4f0f'), 'رفع اليد', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '035483f355ddc34fafbe17dac633faa26bc5af2e', 'الدعاوى العامة / الطلبات الغير مالية (العقارية)', 'رفع اليد', NULL, 7, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='5e4f8a3ad9b72e2a62344b49f623d284a10b4f0f'), 'مسائل وحمى', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '88898793e90a558774349541bc8281f22de1691c', 'الدعاوى العامة / الطلبات الغير مالية (العقارية)', 'مسايل وحمي', NULL, 8, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='562c8d05190d306a5950f265ca76ddcd284b4baa'), 'أتعاب وتعويض الحكم', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'e7b0bb8a30321e9c7dda9337585ad31793ea3af9', 'الدعاوى العامة / التحكيم', 'اتعاب وتعويض الحكم', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='562c8d05190d306a5950f265ca76ddcd284b4baa'), 'الطلبات القضائية لإجراءات التحكيم', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '3722e285df7a8a22e40ec5b5afc34c8206ae1ad4', 'الدعاوى العامة / التحكيم', 'الطلبات القضاييه لاجراءات التحكيم', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='562c8d05190d306a5950f265ca76ddcd284b4baa'), 'إيداع حكم تحكيمي', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '9bbc300abc940a9f9b9c7a8a8c72ba949320579e', 'الدعاوى العامة / التحكيم', 'ايداع حكم تحكيمي', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='562c8d05190d306a5950f265ca76ddcd284b4baa'), 'الأوامر الوقتية والإجراءات التحفظية', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'df560df2144fcd3c71ea4e82a1dcce1de518fc94', 'الدعاوى العامة / التحكيم', 'الاوامر الوقتيه والاجراءات التحفظيه', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='562c8d05190d306a5950f265ca76ddcd284b4baa'), 'الأمر بتنفيذ حكم التحكيم', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '2f1ce46d37f1512ae9febcdd08cfcf700c948b68', 'الدعاوى العامة / التحكيم', 'الامر بتنفيذ حكم التحكيم', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='562c8d05190d306a5950f265ca76ddcd284b4baa'), 'بطلان حكم التحكيم', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'dca39362010df8e8d61fea3a968c105fe20e06c3', 'الدعاوى العامة / التحكيم', 'بطلان حكم التحكيم', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد إيجار عقار (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'fdd65f243df3b9896248aa64e6017168b0a21dda', 'الدعاوى العامة / العقود', 'عقد ايجار عقار (طلبات تخص العقد)', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد إيجار عقار (طلبات التعويض والتنفيذ بالتعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '009e31ddbb3327581e934e472f2e32d689ae05d7', 'الدعاوى العامة / العقود', 'عقد ايجار عقار (طلبات التعويض والتنفيذ بالتعويض)', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد إيجار عقار (طلبات الأجرة وسداد الفواتير)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '3a882f170eee4d31ce5b77da34681bd4de0d72f3', 'الدعاوى العامة / العقود', 'عقد ايجار عقار (طلبات الاجره وسداد الفواتير)', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد إيجار منقول (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '2bc0eef1dd6bbc4c3accd043f256b3596d1044bd', 'الدعاوى العامة / العقود', 'عقد ايجار منقول (طلبات تخص العقد)', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد إيجار منقول (طلبات الأجرة)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'b0d3c34c7ad589e4026eae4a7cdb23d51b64cfc6', 'الدعاوى العامة / العقود', 'عقد ايجار منقول (طلبات الاجره)', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد إيجار منقول (طلبات التعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'ae508c1bb303d03668970b108b7d445a259b3bb8', 'الدعاوى العامة / العقود', 'عقد ايجار منقول (طلبات التعويض)', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الإيداع (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '447d4b201cf8c4b0cfa63c2a3f1b7eb4a4a8e9d5', 'الدعاوى العامة / العقود', 'عقد الايداع (طلبات تخص العقد)', NULL, 7, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الإيداع (طلبات التعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '543d6204d6f2f5468755952e6f5976e488ede7bc', 'الدعاوى العامة / العقود', 'عقد الايداع (طلبات التعويض)', NULL, 8, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الإيداع (ما يتعلق بالوديعة)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '5fcae6776fa62d7e2b88737277306560edd588c4', 'الدعاوى العامة / العقود', 'عقد الايداع (ما يتعلق بالوديعه)', NULL, 9, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الوكالة (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'd9cce0e3261beb3264c261c31caa7c1745ca2bbf', 'الدعاوى العامة / العقود', 'عقد الوكاله (طلبات تخص العقد)', NULL, 10, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الوكالة (طلبات التعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'f192de08a2db60eb3a73207edd9889b3dc5e305e', 'الدعاوى العامة / العقود', 'عقد الوكاله (طلبات التعويض)', NULL, 11, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'طلبات خاصة بالوكالة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '5967adcd031f0cb522ad1d32e25d2285e091f6c9', 'الدعاوى العامة / العقود', 'طلبات خاصه بالوكاله', NULL, 12, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الحوالة (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'c01d3b86015c4f9ef80826da6f294c8792f142b8', 'الدعاوى العامة / العقود', 'عقد الحواله (طلبات تخص العقد)', NULL, 13, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الحوالة (الطلبات المالية والتعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '373756644897fb4623d02d84a500a569fa0454d0', 'الدعاوى العامة / العقود', 'عقد الحواله (الطلبات الماليه والتعويض)', NULL, 14, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الحوالة (دفع مبلغ الحوالة)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '1366a51ef766b2924298ea2ceb67e76548785296', 'الدعاوى العامة / العقود', 'عقد الحواله (دفع مبلغ الحواله)', NULL, 15, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد القرض (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '2daeb6c4c3ac0d6f8b080b2ef3bc081f0575ce3e', 'الدعاوى العامة / العقود', 'عقد القرض (طلبات تخص العقد)', NULL, 16, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد القرض (طلبات التعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '417003f930712fa8727d7f3d185f06c24fb3279f', 'الدعاوى العامة / العقود', 'عقد القرض (طلبات التعويض)', NULL, 17, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد القرض (رد مثل القرض وتقديم ضمان)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '2b6902de51d8f4af5f1bbf8428aaa14f9719535f', 'الدعاوى العامة / العقود', 'عقد القرض (رد مثل القرض وتقديم ضمان)', NULL, 18, 1)
ON DUPLICATE KEY UPDATE
    subcategory_id            = VALUES(subcategory_id),
    name_ar                   = VALUES(name_ar),
    definition                = VALUES(definition),
    definition_missing        = VALUES(definition_missing),
    requests_declared_absent  = VALUES(requests_declared_absent),
    source_ref                = VALUES(source_ref),
    search_norm               = VALUES(search_norm),
    parse_note                = VALUES(parse_note),
    sort_order                = VALUES(sort_order),
    is_active                 = 1;

INSERT INTO najiz_case_types
    (subcategory_id, name_ar, definition, definition_missing, requests_declared_absent,
     source_key, source_ref, search_norm, parse_note, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الهبة (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '6f161f46b42695a82f680f68fc2842885d9bd4b7', 'الدعاوى العامة / العقود', 'عقد الهبه (طلبات تخص العقد)', NULL, 19, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الهبة (طلبات التعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'cb3fedb445fd03548fa0b0172706d2341aac6754', 'الدعاوى العامة / العقود', 'عقد الهبه (طلبات التعويض)', NULL, 20, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'طلبات خاصة بالهبة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'e14062a3431b9ff67b814bc3521ac175b2fefbf2', 'الدعاوى العامة / العقود', 'طلبات خاصه بالهبه', NULL, 21, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الرهن (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '65d00bdda95bb05df225210c26983cf2e5b0ac30', 'الدعاوى العامة / العقود', 'عقد الرهن (طلبات تخص العقد)', NULL, 22, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الرهن (طلبات التعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '8cc5fd54a2a087d31fc0ed4f69ce5d3143ed1ccb', 'الدعاوى العامة / العقود', 'عقد الرهن (طلبات التعويض)', NULL, 23, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'طلبات خاصة بالرهن', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'bf5e23e9e1470e08e07f6f349f8c1b85ec362743', 'الدعاوى العامة / العقود', 'طلبات خاصه بالرهن', NULL, 24, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الإعارة (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'dcbbe55a61403acd5863ea39735bb5097a7b04a4', 'الدعاوى العامة / العقود', 'عقد الاعاره (طلبات تخص العقد)', NULL, 25, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الإعارة (طلبات التعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '8c5785377cd09040b686702c162737a23252c86f', 'الدعاوى العامة / العقود', 'عقد الاعاره (طلبات التعويض)', NULL, 26, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'طلبات أجرة الشيء المعار ونفقاته', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '01852e75bb0b2c128fd11f52a7816de1629e5145', 'الدعاوى العامة / العقود', 'طلبات اجره الشيء المعار ونفقاته', NULL, 27, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد البيع (طلبات تخص العقد والملكية وتقديم الضمان)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '0e46db24f7e735aa903920710c212139152d9cde', 'الدعاوى العامة / العقود', 'عقد البيع (طلبات تخص العقد والملكيه وتقديم الضمان)', NULL, 28, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد البيع (الطلبات المالية والتعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '7e1d561b6ecc133b50273a71e41ae30e34d840aa', 'الدعاوى العامة / العقود', 'عقد البيع (الطلبات الماليه والتعويض)', NULL, 29, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الكفالة (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '660cc5aeecc28e54bbccb7c8358a91a7bf8e4451', 'الدعاوى العامة / العقود', 'عقد الكفاله (طلبات تخص العقد)', NULL, 30, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الكفالة (الضمان العيني والرجوع على الكفلاء)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'a0da7aa9a81019768a026f438153573493ac0092', 'الدعاوى العامة / العقود', 'عقد الكفاله (الضمان العيني والرجوع علي الكفلاء)', NULL, 31, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الكفالة (طلبات التعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '6bc0c6c7cb7e38e18ab973ffd832665e2aa5c524', 'الدعاوى العامة / العقود', 'عقد الكفاله (طلبات التعويض)', NULL, 32, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الكفالة (الطلبات المالية)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'ad52b12689412986ef69277712be42d00ee63247', 'الدعاوى العامة / العقود', 'عقد الكفاله (الطلبات الماليه)', NULL, 33, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الحراسة (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'e0c8d35c374840fe02793dda6b4c7be0facc7b7c', 'الدعاوى العامة / العقود', 'عقد الحراسه (طلبات تخص العقد)', NULL, 34, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد الحراسة (طلبات التعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '765d07cfa1ed1c28086d5d86bcf000695a93a729', 'الدعاوى العامة / العقود', 'عقد الحراسه (طلبات التعويض)', NULL, 35, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'طلبات الحراسة الخاصة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'c4a5cfc050127ad813d6d947064810345b215544', 'الدعاوى العامة / العقود', 'طلبات الحراسه الخاصه', NULL, 36, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد المقايضة (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '5b886c20a2dddbcefc559650a5540c8655317868', 'الدعاوى العامة / العقود', 'عقد المقايضه (طلبات تخص العقد)', NULL, 37, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد المقايضة (طلبات التعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '95c77f8f405242309226bee9794098c6721ce446', 'الدعاوى العامة / العقود', 'عقد المقايضه (طلبات التعويض)', NULL, 38, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد المسابقة (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '765606457f031bd22b0be55b3fb1687f6eaab37e', 'الدعاوى العامة / العقود', 'عقد المسابقه (طلبات تخص العقد)', NULL, 39, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد المسابقة (طلبات التعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '7be62be309d269c294ede00d00ac46a1946c681f', 'الدعاوى العامة / العقود', 'عقد المسابقه (طلبات التعويض)', NULL, 40, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد المسابقة (طلبات خاصة بالمسابقة)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '95964cf4b10f5c8c03bf75630c5893f66014d476', 'الدعاوى العامة / العقود', 'عقد المسابقه (طلبات خاصه بالمسابقه)', NULL, 41, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد المقاولة (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '4254b487d3034ef9e3a70fbf86a42267589c52d5', 'الدعاوى العامة / العقود', 'عقد المقاوله (طلبات تخص العقد)', NULL, 42, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد المقاولة (طلبات التعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '170a0369c11892e09d8692b22e337be389a9de74', 'الدعاوى العامة / العقود', 'عقد المقاوله (طلبات التعويض)', NULL, 43, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد المقاولة (طلبات خاصة بالمقاولة)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '6a912581db68c78421cc9180e9e7da70dbcb86d1', 'الدعاوى العامة / العقود', 'عقد المقاوله (طلبات خاصه بالمقاوله)', NULL, 44, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'العقود الغير مسماة (طلبات تخص العقد)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '23ffd64b95858d31da6fa6cf29d6f45f75d335d3', 'الدعاوى العامة / العقود', 'العقود الغير مسماه (طلبات تخص العقد)', NULL, 45, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'العقود الغير مسماة (طلبات التعويض)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '08da190579b96446d61ede443d90cb782f15a0af', 'الدعاوى العامة / العقود', 'العقود الغير مسماه (طلبات التعويض)', NULL, 46, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'العقود الغير مسماة (طلبات أخرى)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'bf65948db4b17859bd9df2ecea2a0fc29076b1f0', 'الدعاوى العامة / العقود', 'العقود الغير مسماه (طلبات اخري)', NULL, 47, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='9d1954522cf8a39d6a728384323190cc35b66602'), 'عقد المقايضة (طلبات خاصة وضمان المقايضة)', NULL, 1, 0, '7ad3445741c40f64e67be1171ba0daa11fc6558b', 'الدعاوى العامة / العقود', 'عقد المقايضه (طلبات خاصه وضمان المقايضه)', 'type heading present in source with no detail block', 48, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='ae4f049d3708fbd02f251b75bd9af83ec1eb934b'), 'الامتناع', 'قبول الجزء الذي لم يُنفذ من السند، أو قبول السند وتنفيذه.', 0, 0, '7ee2ee0cb3a7cf3f4ed4477ed8ee12de129ccaa2', 'دعاوى التنفيذ / الامتناع عن قبول السند', 'الامتناع', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='1e4cdf86e3d8d55ea5dcc79e47a416e6713b4dd4'), 'دعوى الإعسار أو الملاءمة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '33a573f8c395e3ec8d0b9b771e67f48d15d0b852', 'دعاوى التنفيذ / دعوى الإعسار أو الملاءمة', 'دعوي الاعسار او الملاءمه', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='1e4cdf86e3d8d55ea5dcc79e47a416e6713b4dd4'), 'دعوى الإعسار أو الملاءمة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '3ccf2e2188e78d8e6ad0aa783fd8a2fd0634dd5a', 'دعاوى التنفيذ / دعوى الإعسار أو الملاءمة', 'دعوي الاعسار او الملاءمه', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='e0e3a7fcad2a1d4c88ff0e6d062e8f29b776f8d6'), 'منازعات شكلية', 'عدم توفر شرط شكلي في السند أو تزويره.', 0, 1, 'c1c83c40210249d8d70e2afa2568a29715cb4798', 'دعاوى التنفيذ / منازعات شكلية', 'منازعات شكليه', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='e0e3a7fcad2a1d4c88ff0e6d062e8f29b776f8d6'), 'منازعات شكلية', 'عدم الصفة.', 0, 1, '85a2cef16beb0532c7edb128b36a4aa426d8ffe0', 'دعاوى التنفيذ / منازعات شكلية', 'منازعات شكليه', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'الإبراء', 'إثبات الإبراء بعد صدور السند التنفيذي.', 0, 0, 'a3dd49587de11942a019ab0011324d7752c08dca', 'دعاوى التنفيذ / منازعات غير شكلية', 'الابراء', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'الحوالة', 'إثبات الحوالة بعد صدور السند التنفيذي.', 0, 0, '5c6f1ea3f99989c0086dd4f3839e6ba308eb0f62', 'دعاوى التنفيذ / منازعات غير شكلية', 'الحواله', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'الصلح', 'إثبات الصلح بعد صدور السند التنفيذي.', 0, 0, 'c2f1fe6b449549931a18f12bed7f95bcabf3f46d', 'دعاوى التنفيذ / منازعات غير شكلية', 'الصلح', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'الوفاء', 'إثبات الوفاء بعد صدور السند التنفيذي.', 0, 0, 'de38722183cc422c92449ce45c38817bff4fe426', 'دعاوى التنفيذ / منازعات غير شكلية', 'الوفاء', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'التأجيل', 'إثبات التأجيل بعد صدور السند التنفيذي.', 0, 0, '0aef6f3c5712440bfa9d023d99d47c955cc7d8d2', 'دعاوى التنفيذ / منازعات غير شكلية', 'التاجيل', NULL, 5, 1)
ON DUPLICATE KEY UPDATE
    subcategory_id            = VALUES(subcategory_id),
    name_ar                   = VALUES(name_ar),
    definition                = VALUES(definition),
    definition_missing        = VALUES(definition_missing),
    requests_declared_absent  = VALUES(requests_declared_absent),
    source_ref                = VALUES(source_ref),
    search_norm               = VALUES(search_norm),
    parse_note                = VALUES(parse_note),
    sort_order                = VALUES(sort_order),
    is_active                 = 1;

INSERT INTO najiz_case_types
    (subcategory_id, name_ar, definition, definition_missing, requests_declared_absent,
     source_key, source_ref, search_norm, parse_note, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'المزاد والتأثير على سعر المزاد', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '18632065ea00bba5fe581b0d5b3f8df34b95196d', 'دعاوى التنفيذ / منازعات غير شكلية', 'المزاد والتاثير علي سعر المزاد', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'العيب في العين المباعة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '3176c5a7b07d80773c0da1e2d517e219da35b178', 'دعاوى التنفيذ / منازعات غير شكلية', 'العيب في العين المباعه', NULL, 7, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'امتناع شاغل العقار عن الإخلاء لحملة سند تنفيذي', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '38a77ff593f132b11f4ff7c48199458457a471da', 'دعاوى التنفيذ / منازعات غير شكلية', 'امتناع شاغل العقار عن الاخلاء لحمله سند تنفيذي', NULL, 8, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'الحارس القضائي المعين في التنفيذ', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '466c69cc118cb79cb51055e7942fbb87d0dc2a66', 'دعاوى التنفيذ / منازعات غير شكلية', 'الحارس القضايي المعين في التنفيذ', NULL, 9, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'رفع الحجز عن المال الزائد', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'b235fd2300000729f83ce1137c0edb60067bb5c0', 'دعاوى التنفيذ / منازعات غير شكلية', 'رفع الحجز عن المال الزايد', NULL, 10, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'إجراء المقاصة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'b99c9dc34b6590fb76fd54d1f2c44ca8294ace15', 'دعاوى التنفيذ / منازعات غير شكلية', 'اجراء المقاصه', NULL, 11, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'رد المستوفى خطأً', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '92be4f5e70f134c43cf4b70c69e9fae3f95a53d7', 'دعاوى التنفيذ / منازعات غير شكلية', 'رد المستوفي خطا', NULL, 12, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'تصحيح التقرير', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '62d9057d8dd4e9c13e70e0bdb179d5c8b7a4ffe8', 'دعاوى التنفيذ / منازعات غير شكلية', 'تصحيح التقرير', NULL, 13, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'التعويض', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'e402b1809cd034560b8c522000a6a36acaed703d', 'دعاوى التنفيذ / منازعات غير شكلية', 'التعويض', NULL, 14, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'اعتراض على محضر (المخاصمة)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '16bf7f07d8cf8571205d3ce591a70a2ca2d12261', 'دعاوى التنفيذ / منازعات غير شكلية', 'اعتراض علي محضر (المخاصمه)', NULL, 15, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'التعويض عن أضرار التقاضي (التنفيذ)', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '19c4a0e13d5039fd28bce0586770323f75c09098', 'دعاوى التنفيذ / منازعات غير شكلية', 'التعويض عن اضرار التقاضي (التنفيذ)', NULL, 16, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='8ff39d5983703c5b995b5e2278cd5b2daf87a5fd'), 'أتعاب محاماة أو وكلاء', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'b9c11a37bf3bbc03177aba06de825f05f9208cfb', 'دعاوى التنفيذ / منازعات غير شكلية', 'اتعاب محاماه او وكلاء', NULL, 17, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='685c3b2f4f9426c9c212f41025cb05c093ae2f4d'), 'تعديل وتمييز على صك إنهائي', NULL, 1, 0, '56ee20dc93aa160e019a230ea89a302dbbae41a0', 'الدعاوى المتعلقة بالإنهاءات / تعديلات الصكوك', 'تعديل وتمييز علي صك انهايي', 'type heading present in source with no detail block', 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='b4408ba06efb6ab7431e5a62dc5fc7dd229a4218'), 'قسمة تركة بالتراضي', 'قسمة التركة بالتراضي مع وجود قاصر أو غائب أو مفقود.', 0, 1, '7b6aa4b75da7bd1ed4d64e071190d8d055b127f9', 'الدعاوى المتعلقة بالإنهاءات / الورثة', 'قسمه تركه بالتراضي', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='b4408ba06efb6ab7431e5a62dc5fc7dd229a4218'), 'بيان أنصباء الورثة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 1, '46badb430b58c870f48dfbead4d4f9a75ee105fe', 'الدعاوى المتعلقة بالإنهاءات / الورثة', 'بيان انصباء الورثه', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='f693893f898755ee5bc951e7d948323b420c8689'), 'الولاية على القاصر سنًا', 'طلب إقامة الولاية على القاصر سنًا.', 0, 1, '9ca108b9b930b918f633cebe3f2c09f18aa1637d', 'الدعاوى المتعلقة بالإنهاءات / الولايات', 'الولايه علي القاصر سنا', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='f693893f898755ee5bc951e7d948323b420c8689'), 'الولاية على القاصر عقلًا', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'd585e4175df13021b839ae72c6934b1e52387ff8', 'الدعاوى المتعلقة بالإنهاءات / الولايات', 'الولايه علي القاصر عقلا', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='f693893f898755ee5bc951e7d948323b420c8689'), 'مال مفقود', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '13dcd716e2bac2b4d23f730ea4956bbf841c9851', 'الدعاوى المتعلقة بالإنهاءات / الولايات', 'مال مفقود', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='f693893f898755ee5bc951e7d948323b420c8689'), 'احتياجات خاصة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '33df626afe42345fdf8c8bb1ee525ad0365e05d1', 'الدعاوى المتعلقة بالإنهاءات / الولايات', 'احتياجات خاصه', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='11ba31688b067112f999a34f74c7ff24a04dc8a4'), 'الزواج أو التزويج', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '20f0b751b873cf45ab89db695d7ff70a63fa3b54', 'الدعاوى المتعلقة بالإنهاءات / إثباتات اجتماعية', 'الزواج او التزويج', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='11ba31688b067112f999a34f74c7ff24a04dc8a4'), 'عضل المرأة / انعدام ولي / موافقة وزارة الموارد البشرية والتنمية الاجتماعية', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 1, '922043aaa63632f3c45bc5b01ed6f0dcda2d8519', 'الدعاوى المتعلقة بالإنهاءات / إثباتات اجتماعية', 'عضل المراه / انعدام ولي / موافقه وزاره الموارد البشريه والتنميه الاجتماعيه', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='11ba31688b067112f999a34f74c7ff24a04dc8a4'), 'الفقد والغيبة', 'إثبات فقد وغيبة حال المفقود بالسلامة والحضور.', 0, 1, 'c64fedba08032fbdc004a783274e68222bfe322b', 'الدعاوى المتعلقة بالإنهاءات / إثباتات اجتماعية', 'الفقد والغيبه', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='11ba31688b067112f999a34f74c7ff24a04dc8a4'), 'تنازل عن أرش إصابة أو الديات', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'e65c2e73a5401734c57f90bb33eb163885dd1d45', 'الدعاوى المتعلقة بالإنهاءات / إثباتات اجتماعية', 'تنازل عن ارش اصابه او الديات', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6d09facb1deed403a3fd2ce60a5d66645705a625'), 'ناظر أو مشرف على وقف أو وصية', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '2673ebe848038127f3bcc6a45fe189466bb84c1c', 'الدعاوى المتعلقة بالإنهاءات / الأوقاف والوصايا', 'ناظر او مشرف علي وقف او وصيه', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6d09facb1deed403a3fd2ce60a5d66645705a625'), 'إثبات وقف أو وصية المتوفى مع وجود غائب أو قاصر أو مفقود من الورثة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 1, 'cb707112e75edc19280fc964bfeccc3c833b72a6', 'الدعاوى المتعلقة بالإنهاءات / الأوقاف والوصايا', 'اثبات وقف او وصيه المتوفي مع وجود غايب او قاصر او مفقود من الورثه', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6d09facb1deed403a3fd2ce60a5d66645705a625'), 'تعديل وإضافة لصك الوقف أو الوصية', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 1, '556d444d98b7b924fb7b638fe8245f230b56b305', 'الدعاوى المتعلقة بالإنهاءات / الأوقاف والوصايا', 'تعديل واضافه لصك الوقف او الوصيه', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6d09facb1deed403a3fd2ce60a5d66645705a625'), 'إذن تصرف بأملاك الوقف أو الوصية', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 1, 'f534f66042840f92e16fb2c8485c01232c335436', 'الدعاوى المتعلقة بالإنهاءات / الأوقاف والوصايا', 'اذن تصرف باملاك الوقف او الوصيه', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6d09facb1deed403a3fd2ce60a5d66645705a625'), 'تسليم الأموال المودعة لصك الوقف أو الوصية لدى الهيئة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 1, '2d82bd61bc379b0a7f97b75857df0db0d5fdbca2', 'الدعاوى المتعلقة بالإنهاءات / الأوقاف والوصايا', 'تسليم الاموال المودعه لصك الوقف او الوصيه لدي الهييه', NULL, 5, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='6d09facb1deed403a3fd2ce60a5d66645705a625'), 'طلب المستثمر فسخ أو تعديل عقد الاستثمار المأذون به من المحكمة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'b085a1248cc181d047daddd935c82be37d5248e0', 'الدعاوى المتعلقة بالإنهاءات / الأوقاف والوصايا', 'طلب المستثمر فسخ او تعديل عقد الاستثمار الماذون به من المحكمه', NULL, 6, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='231db64bf74a812407bed38711c0e6621da9b934'), 'الزواج', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '9af2774d575dc9e804929ce79c3da9ae53290904', 'الدعاوى المتعلقة بالإنهاءات / إنهاءات الأوقاف والمواريث في الأحساء والقطيف', 'الزواج', NULL, 1, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='231db64bf74a812407bed38711c0e6621da9b934'), 'الفرقة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, '8ea7223f11cbfede5272e5c4e3fa5d891bbbd042', 'الدعاوى المتعلقة بالإنهاءات / إنهاءات الأوقاف والمواريث في الأحساء والقطيف', 'الفرقه', NULL, 2, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='231db64bf74a812407bed38711c0e6621da9b934'), 'الأوقاف والوصايا', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'ee90b0c66988f5cb401b2919bef871da545db216', 'الدعاوى المتعلقة بالإنهاءات / إنهاءات الأوقاف والمواريث في الأحساء والقطيف', 'الاوقاف والوصايا', NULL, 3, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='231db64bf74a812407bed38711c0e6621da9b934'), 'الورثة والتركة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'd07cc84777c53911232d08baeff64bd568f56581', 'الدعاوى المتعلقة بالإنهاءات / إنهاءات الأوقاف والمواريث في الأحساء والقطيف', 'الورثه والتركه', NULL, 4, 1),
    ((SELECT id FROM najiz_subcategories WHERE source_key='231db64bf74a812407bed38711c0e6621da9b934'), 'الحضانة', 'لا يوجد تعريف مسجل لهذا النوع في المصدر — راجع نظام المرافعات أو استشر مختصًا عند اللبس.', 0, 0, 'ae39a128c3b871f01242824898b356d540afd7c5', 'الدعاوى المتعلقة بالإنهاءات / إنهاءات الأوقاف والمواريث في الأحساء والقطيف', 'الحضانه', NULL, 5, 1)
ON DUPLICATE KEY UPDATE
    subcategory_id            = VALUES(subcategory_id),
    name_ar                   = VALUES(name_ar),
    definition                = VALUES(definition),
    definition_missing        = VALUES(definition_missing),
    requests_declared_absent  = VALUES(requests_declared_absent),
    source_ref                = VALUES(source_ref),
    search_norm               = VALUES(search_norm),
    parse_note                = VALUES(parse_note),
    sort_order                = VALUES(sort_order),
    is_active                 = 1;

-- ----------------------------------------------------------------------------
-- 4) Judicial requests (640)
--    case_type_id is resolved through the type source_key. Requests belong to
--    exactly one type, as the source presents them; a type with none carries
--    requests_declared_absent rather than a placeholder row.
-- ----------------------------------------------------------------------------
INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='c3d43b92b7f2fcb2cc3d427b5c4ebde1fb8861cc'), 'إثبات عقد الزواج', '6a6724aa34101494038c12a26820704780b28c34', 'اثبات عقد الزواج', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1e6c81f0bf3f2b72d4bd2f9ffdd8642faf77995e'), 'إعادة المهر', '9a06f3c788e87be376ca68c0ba9b5cd40493b6e8', 'اعاده المهر', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3d73112640a41cd746ea914ae0b4ed27f1961a87'), 'فسخ عقد الزواج', 'b3a7b4b1310530405d216751e815dc8b07028500', 'فسخ عقد الزواج', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='537243d08c42665ec7f23e20654ba6d8b280a286'), 'إثبات المراجعة', 'd856bf36b98f95f03837203b8bbe0eb933ac926a', 'اثبات المراجعه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5d14d4e0fffb05efef78a147b366c39df405f15c'), 'عفش الزوجية', '86cc13de99fb2112f39cc0298e04843c9a520af5', 'عفش الزوجيه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='171fbf07213f1300043285a2ab92aa5481c06965'), 'زيادة النفقة', '1cf75285caa6c7ac6250d49a1295dd4ad165137a', 'زياده النفقه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='171fbf07213f1300043285a2ab92aa5481c06965'), 'إنقاص نفقة', '55fa9d49ca4eaf037464b323598ff6015abb1cd2', 'انقاص نفقه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='171fbf07213f1300043285a2ab92aa5481c06965'), 'إلغاء النفقة', 'f99ce37041997ca12414a3ff7fb7598a7983803b', 'الغاء النفقه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='850aaaac4dbad9e05535126f4b0cbed8ea5f4f3e'), 'أجرة الرضاع', '9d798e7cc5bf3370e4a015a74eb25bc0f96275d5', 'اجره الرضاع', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='850aaaac4dbad9e05535126f4b0cbed8ea5f4f3e'), 'أجرة الحضانة', 'a6d580d8490d5b47e8629c6625b3775b0a5a4a83', 'اجره الحضانه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5ef57a5728374b78e32b4742d1691423065b5528'), 'إنقاص أجرة رضاع', 'ee409f3d2ff8a155b25b64bd1edacce765b806b1', 'انقاص اجره رضاع', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5ef57a5728374b78e32b4742d1691423065b5528'), 'إنقاص أجرة حضانة', 'b7cdb0d98240fbf220ae8c1798ccbe1d53be3f86', 'انقاص اجره حضانه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5ef57a5728374b78e32b4742d1691423065b5528'), 'إلغاء أجرة رضاع أو حضانة', 'b16337175f3d0fa69a2b0b3679da4f6baca3d2b1', 'الغاء اجره رضاع او حضانه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5ef57a5728374b78e32b4742d1691423065b5528'), 'رد غير المستحق من أجرة الحضانة أو الرضاع', 'fb8889b245c29c1b71e9568f07342c323dd68bbc', 'رد غير المستحق من اجره الحضانه او الرضاع', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ead7180c298cf1b66f06bbdef796b8fc6f85f1a9'), 'رؤية أسبوعية', 'fa86ba790a58ef516c884666db518e9eb8e6c0e5', 'رويه اسبوعيه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ead7180c298cf1b66f06bbdef796b8fc6f85f1a9'), 'رؤية شهرية', 'f921798323d45d5c72bddab39ee95648dcf3c4af', 'رويه شهريه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ead7180c298cf1b66f06bbdef796b8fc6f85f1a9'), 'الإجازة الفصلية', 'e88a4762830bc177cc42444365abdc087f1cffd2', 'الاجازه الفصليه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ead7180c298cf1b66f06bbdef796b8fc6f85f1a9'), 'رؤية الإجازة الصيفية', '082073e4d88ae96789454410af61446bba318bbf', 'رويه الاجازه الصيفيه', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ead7180c298cf1b66f06bbdef796b8fc6f85f1a9'), 'رؤية عيد الفطر وعيد الأضحى', 'f9d156be5029096afff29b02489e045468d7ddc6', 'رويه عيد الفطر وعيد الاضحي', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='55526a404250856c6dc62d37a7527013c35a32bf'), 'الإفصاح عن تركة', 'f3680e0a5b91bbbc5c9d6a8973511734ce3703bd', 'الافصاح عن تركه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='55526a404250856c6dc62d37a7527013c35a32bf'), 'قسمة التركة', '9205e926b2714fd925f4e4b60d8bbb1ee3e21653', 'قسمه التركه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='55526a404250856c6dc62d37a7527013c35a32bf'), 'تسليم نصيبي من التركة', '9220bd2680cd7ccf0b60d6f1985d4357eb2c92e0', 'تسليم نصيبي من التركه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='55526a404250856c6dc62d37a7527013c35a32bf'), 'بيع المقولات وتسليم نصيبي منها', 'c6fe603c0338fdd5462189f82a67a1de7a915e21', 'بيع المقولات وتسليم نصيبي منها', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='55526a404250856c6dc62d37a7527013c35a32bf'), 'بيع العقارات وتسليم نصيبي منها', 'ff175a0dd154005b987efd1552824b8b45bfde59', 'بيع العقارات وتسليم نصيبي منها', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a6e939a8c7002930579edd004e60a0885188ab8e'), 'قسمة التركة مالية', '8382a3a2d26ca2041829d6f85d6e10e1d7afecee', 'قسمه التركه ماليه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='14d0504ffdb5b20cbe0b1617725783b3a1778b31'), 'قسمة التركة العقارية', '77ec353e79c18a30f273d869cd1b84657f40ff27', 'قسمه التركه العقاريه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='77bed4b4eae6adfd75a43cf16aa3144feeda3a5d'), 'الإفصاح عن التصرفات في نصيبي من التركة', '9c37748f51831fb1cbf4e05fcbac1ca4d10f5cb6', 'الافصاح عن التصرفات في نصيبي من التركه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='77bed4b4eae6adfd75a43cf16aa3144feeda3a5d'), 'استرداد نصيبي من التركة', '44dba0940d19af1f6f6c31a797c53cdb000d1154', 'استرداد نصيبي من التركه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='77bed4b4eae6adfd75a43cf16aa3144feeda3a5d'), 'محاسبة الوكيل على التركة', '3850169fa682016af6628dfc2f607473380df1ad', 'محاسبه الوكيل علي التركه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='67b050ba2c52ddfeedef42bbf7630bd68487cf84'), 'إبطال وقف ضد الواقف', '805fddda087dfac001e030332c2cdfb0134f1d92', 'ابطال وقف ضد الواقف', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='67b050ba2c52ddfeedef42bbf7630bd68487cf84'), 'إبطال وقف ضد ورثة الواقف', '3251a30a8068789097533f1afa17946f47376b9a', 'ابطال وقف ضد ورثه الواقف', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='67b050ba2c52ddfeedef42bbf7630bd68487cf84'), 'إبطال وصية', '3b878de56505528f6d0fe3a9052d1b1e59a4e5b9', 'ابطال وصيه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d043ee79b59db24ca5a822d27d9c35d42a763d02'), 'إثبات وقف ضد الواقف', 'c90198a98391fafce5f5f8befe3715ccdd1889d6', 'اثبات وقف ضد الواقف', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d043ee79b59db24ca5a822d27d9c35d42a763d02'), 'إثبات وقف ضد ورثة الواقف', 'cc1c0e30e8c12fa14d0b9d06c8166dcddd7dca6a', 'اثبات وقف ضد ورثه الواقف', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d043ee79b59db24ca5a822d27d9c35d42a763d02'), 'إثبات وصية', '438b80d42da2f3d8c9df5b1fb28285b8bcfce9e0', 'اثبات وصيه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a9bb54c729782cafba0a07ddabf0b633f31db01a'), 'إثبات استحقاق في وقف أو وصية', '37b8cc0e8eefab0a3fee01d4ea1b14c870a6ad55', 'اثبات استحقاق في وقف او وصيه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a9bb54c729782cafba0a07ddabf0b633f31db01a'), 'تسليم استحقاق في وقف أو وصية', '608231b9fb0b80c1e0f11dae0650aa11d440a561', 'تسليم استحقاق في وقف او وصيه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6c8a04f55b9e813161db27b573c96c69fbc1d48b'), 'إفصاح ناظر وقف أو وصية', 'ea329ad8ae64e2ab9640c835c8d015136e1e5738', 'افصاح ناظر وقف او وصيه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6c8a04f55b9e813161db27b573c96c69fbc1d48b'), 'إقامة محاسب على ناظر وقف أو وصية', 'da539e3034f7514e26b881b45c9907d9398565c6', 'اقامه محاسب علي ناظر وقف او وصيه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6c8a04f55b9e813161db27b573c96c69fbc1d48b'), 'محاسبة ناظر وقف أو وصية', 'f5d5a07037e9fbcc755ca1ceabc3e26f18401ccd', 'محاسبه ناظر وقف او وصيه', 3, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='6c8a04f55b9e813161db27b573c96c69fbc1d48b'), 'عزل ناظر وقف أو وصية', '71f2dedeca445f12536224ccdfd792e915e8c4e0', 'عزل ناظر وقف او وصيه', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e4cb97d610509db7cf7a4015deaff18f7eba3a32'), 'إثبات الهبة', '41d413a0c8629f4c2481189af0e72b1d738f8b7c', 'اثبات الهبه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e4cb97d610509db7cf7a4015deaff18f7eba3a32'), 'رجوع عن الهبة', '90faa15586194c1ee9273a3fdb608289f8b872f9', 'رجوع عن الهبه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e4cb97d610509db7cf7a4015deaff18f7eba3a32'), 'التعويض', 'c004fa1d81ac2cfef744ffd102d5ceb454f5ab15', 'التعويض', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e4cb97d610509db7cf7a4015deaff18f7eba3a32'), 'إلزام المدعى عليه بتسليم قيمة الموهوب', 'ba2b6e2ba17ebeeb20e141cb61a463225a325d2e', 'الزام المدعي عليه بتسليم قيمه الموهوب', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='65733363f3e3510ae61efe76087a3d446b66060f'), 'إثبات هبة لوارث', 'fae20179fa24e38de683d7780bfb33b22c5ee87d', 'اثبات هبه لوارث', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='65733363f3e3510ae61efe76087a3d446b66060f'), 'نقض هبة لوارث', 'f8575bc83e093f7ea26471214f7f23dd9e8a5457', 'نقض هبه لوارث', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='65733363f3e3510ae61efe76087a3d446b66060f'), 'التعويض', '4166d5ee39e389c35df09f2cf1863181fce77794', 'التعويض', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='65733363f3e3510ae61efe76087a3d446b66060f'), 'إلزام المدعى عليه بتسليم قيمة الموهوب', '7061d63edc13b920c127d2bc4bfc8d508cbff2f2', 'الزام المدعي عليه بتسليم قيمه الموهوب', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ed3503ffc6ba1c36decf1951c468b7c6f5b79ffe'), 'معارضة على صدور حكم غيابي', '9272d0550f8d2fd2ece27b5595b01b614badea83', 'معارضه علي صدور حكم غيابي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='60425d848cc0bcd5a48e2ab1321894e22abc2f80'), 'مستندات بين الزوجين', '6d532b5bd996f5ad1a635c4b77a503f178acc9dd', 'مستندات بين الزوجين', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='60425d848cc0bcd5a48e2ab1321894e22abc2f80'), 'مستندات بين الورثة', '10aa539900883f5cd4d206ebb78ffc55b17aeb00', 'مستندات بين الورثه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='60425d848cc0bcd5a48e2ab1321894e22abc2f80'), 'مستندات بين أقارب غير ورثة', '75776fbc4e007703554c20d74bbdddaa7f6fa00f', 'مستندات بين اقارب غير ورثه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='60425d848cc0bcd5a48e2ab1321894e22abc2f80'), 'مستندات أوقاف أو وصايا', 'a4dab1c74d20c6113d93935764a1d0e34f687b14', 'مستندات اوقاف او وصايا', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6134fca31f11dd3bae36279352d2dac4ca822eda'), 'استيثاق لإثبات شهادة يخشى فواتها', '654392219117340aa2778bd5fa45685fd8ad30e3', 'استيثاق لاثبات شهاده يخشي فواتها', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e75f98fa22d7a99fea888c51a5ae01086e9ac932'), 'إقامة حارس قضائي', 'f338eaa5a410c8e7584931c4be00b77a6213eee7', 'اقامه حارس قضايي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1b946e9a688ed1c9bb9bea6774665d17b684892f'), 'المنع من السفر', 'faf6c45a0c26c6ecd127b473e917e7aa5708d715', 'المنع من السفر', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2c10b959cad063815a89919fd8db70b040296449'), 'التعويض عن أضرار التقاضي في الأحوال الشخصية', 'cbad3889e8ae0d1fa3728a629fbb1d535f79db39', 'التعويض عن اضرار التقاضي في الاحوال الشخصيه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='69bfc5226b6d39b5d0963e9134e87d0efea19247'), 'أتعاب محاماة أو وكلاء في قضية', '6c562e5a960dd79907dc7a66d71a9c93761ae9c8', 'اتعاب محاماه او وكلاء في قضيه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ec1039a02c54a95d9d7891c1801177c709ec3330'), 'إثبات عضل ونقل ولاية التزويج', '427c8ebd204abcc6a69c5bc8db4b0b134876d119', 'اثبات عضل ونقل ولايه التزويج', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d70c6042253fef1a747319793aac65c8c4bc4689'), 'حجر', '0987e8cc772a4cd410d69f6278025cbdeb447f19', 'حجر', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d70c6042253fef1a747319793aac65c8c4bc4689'), 'رفع الحجر', '9d492f5b9bfbfcf145b49a70f615444de1d7585d', 'رفع الحجر', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='13399724e745c86d5ce1ebedce3befff61e0a83b'), 'عزل ولي', 'ffa8c26660d112e6a090b70d0013b55bf4c96c2d', 'عزل ولي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6a59f9024942e14dfd6af5853c4180a84e3621c6'), 'محاسبة ولي', '87a0b2d735e7dea4933cd295307c0e6ce055c42e', 'محاسبه ولي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e98a3e0a64914a08a450fb5df1400eef82bae2ed'), 'إفصاح ولي', '0b8c9b1f49de59136f165f0f8e7bbf4cda9f66b7', 'افصاح ولي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e98a3e0a64914a08a450fb5df1400eef82bae2ed'), 'استرداد من ولي', '84da7c123251fcfc845d550ee51f9c36d28d2e5d', 'استرداد من ولي', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e98a3e0a64914a08a450fb5df1400eef82bae2ed'), 'إقامة محاسب على ولي', '1a8de0602337ec7af8031c5bb38efdefc2595aae', 'اقامه محاسب علي ولي', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='24c5ab3d0fcf6b91121391d492791da0de916007'), 'إثبات نسب', '79ac1ef0d4000e2a0c433981de9eb09db8a1dede', 'اثبات نسب', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='24c5ab3d0fcf6b91121391d492791da0de916007'), 'نفي نسب', 'eba739d73ea071d2b6aa841bfcc9fa8cfce6e793', 'نفي نسب', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a1b2ff54d2cdff8c774e4199b07436bb763a2051'), 'جزائي: عقوبات ومخالفات نظام البيانات التجارية', '93fcbab265b7fb8878729fe38d1b164c480e19de', 'جزايي: عقوبات ومخالفات نظام البيانات التجاريه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a1b2ff54d2cdff8c774e4199b07436bb763a2051'), 'مدني: تعويض / فسخ في نظام البيانات التجارية', 'a0be2b4db756430e058b49211279bcb53064f2c5', 'مدني: تعويض / فسخ في نظام البيانات التجاريه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a1b2ff54d2cdff8c774e4199b07436bb763a2051'), 'إداري: إلغاء القرار الإداري في نظام البيانات التجارية', '0f994eb2523ea34cb8dffa588ec070c29b8a94ba', 'اداري: الغاء القرار الاداري في نظام البيانات التجاريه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d776a24873e36c4283d73cd1a74c9823221d167b'), 'تعويض في نظام الاستثمار الأجنبي', '50b84dfbb0e6a2993941aec5488ed2d1360a9646', 'تعويض في نظام الاستثمار الاجنبي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='8b39a60812cfdcb419b7e1aa812b16bb1b879576'), 'تسليم منقول أو سفينة', 'b49b76954094a2d2f59e1b9ad2e844487df24be9', 'تسليم منقول او سفينه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='8b39a60812cfdcb419b7e1aa812b16bb1b879576'), 'إصلاح وإنقاذ السفينة', '2995e2991f681852ff41cf0718cec46691a1f8ba', 'اصلاح وانقاذ السفينه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='8b39a60812cfdcb419b7e1aa812b16bb1b879576'), 'ملكية السفينة ورهنها', '2979305c559bc7d02075e793a2f02fb2decd21a1', 'ملكيه السفينه ورهنها', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='8b39a60812cfdcb419b7e1aa812b16bb1b879576'), 'إذن بالبيع أو الاقتراض', '471e554bc10378fc782c6be9ff11d0e48f08b71b', 'اذن بالبيع او الاقتراض', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='8b39a60812cfdcb419b7e1aa812b16bb1b879576'), 'فسخ أو بطلان عقد', 'a6c4e3887188944113f8200c0734e89bc88800d7', 'فسخ او بطلان عقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='77a9ddde639d8969842b7d95aaddc84d77e0ada1'), 'جزائي: عقوبات مخالفات نظام الامتياز التجاري', '7632355f34eef1aa72ad342198dc2636f42f4063', 'جزايي: عقوبات مخالفات نظام الامتياز التجاري', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='77a9ddde639d8969842b7d95aaddc84d77e0ada1'), 'مالي: التعويض / طلب عمولة / طلب الربح', 'c1cdfb5c64024a037af763e504c6367923177ae8', 'مالي: التعويض / طلب عموله / طلب الربح', 2, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='77a9ddde639d8969842b7d95aaddc84d77e0ada1'), 'إداري: إلغاء القرار الإداري', '29e673af1f4baf5f4cc4128a93ca6cf5169b5971', 'اداري: الغاء القرار الاداري', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='77a9ddde639d8969842b7d95aaddc84d77e0ada1'), 'عقود الامتياز والتزاماتها', '493a40e365cc3dc0e7d25c305e914f0ad4ce834b', 'عقود الامتياز والتزاماتها', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1be5612085ed476dfffa2f214c97e211b3f4e4c7'), 'بيع المال المرهون', 'c0a36b25c9783180f879583ea15547c942b27f45', 'بيع المال المرهون', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1be5612085ed476dfffa2f214c97e211b3f4e4c7'), 'طلب ريع المال المرهون', 'e826520ab6ca73b5ac65b08e0480da442a6090e1', 'طلب ريع المال المرهون', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1be5612085ed476dfffa2f214c97e211b3f4e4c7'), 'المطالبة بالمصروفات والنفقات', '1982b2d027761ba896766cc844ee1b30d5bff5e7', 'المطالبه بالمصروفات والنفقات', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1be5612085ed476dfffa2f214c97e211b3f4e4c7'), 'التعويض', '92ece4b9fe08287b3f00fe81affd23a03d8adf6c', 'التعويض', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1be5612085ed476dfffa2f214c97e211b3f4e4c7'), 'تسليم أو تتبع المال المرهون', '30dc3abca6957a22078fb18af5e32a4db7587201', 'تسليم او تتبع المال المرهون', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1be5612085ed476dfffa2f214c97e211b3f4e4c7'), 'الإذن ببيع المرهون', 'ca6baab409938e88eccb0f51358df0e464c07548', 'الاذن ببيع المرهون', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1be5612085ed476dfffa2f214c97e211b3f4e4c7'), 'إجراءات المحافظة على المال المرهون', 'f76ff4cad9430212f3dd203fde8d1f70bfea9f7d', 'اجراءات المحافظه علي المال المرهون', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1be5612085ed476dfffa2f214c97e211b3f4e4c7'), 'رفع يد المدعى عليه عن المال المرهون', '9685a393677371ad8a6050ed35fc657b05127eb1', 'رفع يد المدعي عليه عن المال المرهون', 8, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2599cf3e68d9934358c94c460adbf5231478b4b2'), 'جزائي: عقوبات مخالفات نظام السجل التجاري', '52e451e7919237d41b076de7478fd60473ddfb6d', 'جزايي: عقوبات مخالفات نظام السجل التجاري', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2599cf3e68d9934358c94c460adbf5231478b4b2'), 'مالي: التعويض', '5f446137bdebf38e79ca77f29c8e83d93457c856', 'مالي: التعويض', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2599cf3e68d9934358c94c460adbf5231478b4b2'), 'إداري: إلغاء القرار الإداري', '3c271d1731613d5d4b43114c0909449b131d0b0f', 'اداري: الغاء القرار الاداري', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9c4003b418c4116884f6af7b7bf10b174ada1dff'), 'جزائي: عقوبات مخالفات نظام الغش التجاري', 'f06f70817c05066a17f26bdaed363e722e227f69', 'جزايي: عقوبات مخالفات نظام الغش التجاري', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9c4003b418c4116884f6af7b7bf10b174ada1dff'), 'مالي: التعويض', '6bb35b1384c985410bfd3d21a8133919d05d2d86', 'مالي: التعويض', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9c4003b418c4116884f6af7b7bf10b174ada1dff'), 'إداري: إلغاء القرار الإداري', 'e010d2b53e3bfb8641eb6de9c700b69c6a865c29', 'اداري: الغاء القرار الاداري', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='fc998d8b845ac3358d2f842eddbc140fa5e1ecb9'), 'عدم استحقاق', '79cae2f4dc3e7bad1902319cbd518a4e5e02a58d', 'عدم استحقاق', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='fc998d8b845ac3358d2f842eddbc140fa5e1ecb9'), 'تسليم ورقة تجارية', 'f0d82d93663bf65cfc3f4fe2ada99b54def4d6a9', 'تسليم ورقه تجاريه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a9717fc33e7caffa5c738d312bcf94585d7eb9ca'), 'جزائي: عقوبات مخالفات نظام المنافسة', 'f02db9bbf297276553f8076804c020b03a6b5fb0', 'جزايي: عقوبات مخالفات نظام المنافسه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a9717fc33e7caffa5c738d312bcf94585d7eb9ca'), 'مالي: التعويض', '1c285d1ab5ea7fdc58623636a59da0c6fb4cdaf9', 'مالي: التعويض', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a9717fc33e7caffa5c738d312bcf94585d7eb9ca'), 'إداري: إلغاء القرار الإداري', '3217921321b038f9d8c61498299953a584bb7839', 'اداري: الغاء القرار الاداري', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b74c96324138cf4b784e7c8e81f3283e950c86ca'), 'جزائي: عقوبات مخالفات نظام الوكالات التجارية', '9bc7127b749db3c29d37a9303811fd7433d53213', 'جزايي: عقوبات مخالفات نظام الوكالات التجاريه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b74c96324138cf4b784e7c8e81f3283e950c86ca'), 'مالي: التعويض', '6c5c17792356ecccc0419291cd9c4a29eba77e8f', 'مالي: التعويض', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b74c96324138cf4b784e7c8e81f3283e950c86ca'), 'إداري: إلغاء القرار الإداري', '86b12e92f379d6fb7e62f0c352d73c7ff99c6f4d', 'اداري: الغاء القرار الاداري', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b74c96324138cf4b784e7c8e81f3283e950c86ca'), 'العقد والتزاماته', 'ebb35cd036cd85646652b7c43929b02b5c24c0ca', 'العقد والتزاماته', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ef2dbf721b68921393c3b86248e1cde849c1f9d5'), 'مدني: التعويض', '97e3c4e516c6f060729052514eacf4a5dbbe0b4e', 'مدني: التعويض', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ef2dbf721b68921393c3b86248e1cde849c1f9d5'), 'إداري: إلغاء القرار الإداري', 'e0d35b9391ad631724a26ed9d79aa7fe44ce79c9', 'اداري: الغاء القرار الاداري', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b180adf7d763863653639e727b766fde86eab53d'), 'منع استغلال المعلومات التجارية السرية', 'cb4c2ca706ddbed85b36f22e3ec974eac6ff951e', 'منع استغلال المعلومات التجاريه السريه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b180adf7d763863653639e727b766fde86eab53d'), 'التعويض في نظام حماية الأسرار التجارية', '763deaead1da3051db0737637c15cdb176cef200', 'التعويض في نظام حمايه الاسرار التجاريه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b180adf7d763863653639e727b766fde86eab53d'), 'بيع المعلومات السرية', '882365897e7064b511b9ecee68a4208cb4f929dd', 'بيع المعلومات السريه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='98bcdf577b28361b0523729f9290c16e98d27946'), 'استرداد المبالغ المسلمة للمحامي أو الوكيل', 'f1d6e707fc683818e24fcc1e170fadbac5a94fb5', 'استرداد المبالغ المسلمه للمحامي او الوكيل', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='98bcdf577b28361b0523729f9290c16e98d27946'), 'أتعاب محاماة أو وكلاء في قضية', '1d7c7fc9c29b2762db8aeb6171a6ed7907fa4e4f', 'اتعاب محاماه او وكلاء في قضيه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a1ef0c3febb4a432dd0984eadbc8721245eb312c'), 'رفع الضرر الواقع بين التجار', '0880bd2fe19e4e4217b848d97edc214b5a29d94d', 'رفع الضرر الواقع بين التجار', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a1ef0c3febb4a432dd0984eadbc8721245eb312c'), 'تعويض عن الضرر بين التجار', 'fdfbb7885496aeb4a5112ec2a6f4f2f230bd4a1a', 'تعويض عن الضرر بين التجار', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a1ef0c3febb4a432dd0984eadbc8721245eb312c'), 'طلب إعادة النظر في تقدير التعويض', '4abb160fb750f2374ffe7df76792ab8319b39621', 'طلب اعاده النظر في تقدير التعويض', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='fa8de4c72a0109f3034b46afe53649d6db29d451'), 'أتعاب أعوان القضاة وتعويض المعينين', 'e1506f63dabf58d35e50d57f229f769095032c02', 'اتعاب اعوان القضاه وتعويض المعينين', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='fa8de4c72a0109f3034b46afe53649d6db29d451'), 'عزل أو اعتزال أحد أعوان القضاة', 'f7b01942386cdb81a9d4184517e7b9da89eb353f', 'عزل او اعتزال احد اعوان القضاه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='fa8de4c72a0109f3034b46afe53649d6db29d451'), 'غير ذلك فيما يخص أعوان القضاة', '882ed2d23a942b95fbf9a252c0c39836872d25fb', 'غير ذلك فيما يخص اعوان القضاه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='bf3b62303c98e7142b57cfce9288fe59b691c715'), 'إصدار أمر أداء', '4e9d5e931868692c9a1a89d86853f2f03f047af9', 'اصدار امر اداء', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='af9229fb5292b1e0f256f915b1888ee48ef83f1e'), 'التعويض عن مصاريف التقاضي', 'adc3afe6d8cd163b6dbc582ba49332cd8898a410', 'التعويض عن مصاريف التقاضي', 1, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='97dc57f39f20a92a70fc765c89cb78a01bc87e41'), 'إلغاء أمر الأداء', '3383bcfa3a18e3a64617846fceebee674cc897f1', 'الغاء امر الاداء', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='97dc57f39f20a92a70fc765c89cb78a01bc87e41'), 'رد المبلغ', '0b8f76ee6dbd9e6d1a58358ff3ddf90797a1bf58', 'رد المبلغ', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='56f1e85456abc527bbd11af055899667799ef05a'), 'جزائي: تطبيق الغرامات والعقوبات', 'bed85386bdce3d54340de68fac98ff80d2498870', 'جزايي: تطبيق الغرامات والعقوبات', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='56f1e85456abc527bbd11af055899667799ef05a'), 'مدني: تعويض / وقف الاعتداء', '06e96230dd07b109456f4bcc259a3bb9a12dcb69', 'مدني: تعويض / وقف الاعتداء', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='56f1e85456abc527bbd11af055899667799ef05a'), 'إداري: إلغاء القرار الإداري', 'ca8c3ee2cdaad2112e8cd48ad9fec04621ec0579', 'اداري: الغاء القرار الاداري', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='fd2376c79607f609807e1aa6fc881234a3755353'), 'جزائي: تطبيق الغرامات والعقوبات', '94185d8ee04bdc8c4f54ecab7643dcadc5e087f3', 'جزايي: تطبيق الغرامات والعقوبات', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='fd2376c79607f609807e1aa6fc881234a3755353'), 'مدني: تعويض في نظام حماية العلامات التجارية / وقف التعدي على علامة', 'dfd87d862f6803bc3ad9db14bff9d529d8f57c3b', 'مدني: تعويض في نظام حمايه العلامات التجاريه / وقف التعدي علي علامه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='fd2376c79607f609807e1aa6fc881234a3755353'), 'إداري: إلغاء أو شطب القرار الإداري / إلغاء العلامة التجارية لسبق استعمالها', 'ab78d11d8c542398943442d34c81e60c4b5bf711', 'اداري: الغاء او شطب القرار الاداري / الغاء العلامه التجاريه لسبق استعمالها', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='35e681c3a3ef1ee32a235f9a29ed456cd850b2f5'), 'جزائي: تطبيق الغرامات والعقوبات', '0139ea1fef0344ea7e218aa177df4432d7921741', 'جزايي: تطبيق الغرامات والعقوبات', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='35e681c3a3ef1ee32a235f9a29ed456cd850b2f5'), 'مدني: تعويض / منع استغلال براءات الاختراع', 'a11084a30440e5418f1ce442641cd4afe06fca38', 'مدني: تعويض / منع استغلال براءات الاختراع', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='35e681c3a3ef1ee32a235f9a29ed456cd850b2f5'), 'إداري: إلغاء القرار الإداري', 'ba39c5826b3829286c44ae3d3c6d6724e2e0c07f', 'اداري: الغاء القرار الاداري', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='91e86e907633e78dbb469db3f56e6d444e86752e'), 'بطلان، فسخ، تعويض، تسليم مستندات في عقد الإجارة', '1dc775c83b6e959b140e8b4f48704491682638b5', 'بطلان، فسخ، تعويض، تسليم مستندات في عقد الاجاره', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='91e86e907633e78dbb469db3f56e6d444e86752e'), 'أجرة عين الإجارة', '76a846ea58be4d156ab0daa35fdf9824db79f176', 'اجره عين الاجاره', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='91e86e907633e78dbb469db3f56e6d444e86752e'), 'رد العين المؤجرة', '67c98fc9f0a35aa40b4c7c54c95dbecc566e0a59', 'رد العين الموجره', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='31faa7622710f0abe4eb0bd5bf7c2eecd93cb8aa'), 'التعويض بمثل ما أُحيل عليه', '5b4e7d924f9b48e8bcc380c817d3e33fb4fe0348', 'التعويض بمثل ما احيل عليه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='31faa7622710f0abe4eb0bd5bf7c2eecd93cb8aa'), 'إلزام المحال عليه بأداء الدين', '80010c3483009e45282476adc5a1adba9dce0784', 'الزام المحال عليه باداء الدين', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='31faa7622710f0abe4eb0bd5bf7c2eecd93cb8aa'), 'إعادة الحال أو التنفيذ بطريق التعويض', '9045f2d3939ebf92a0fb8589f0497ca309904ffa', 'اعاده الحال او التنفيذ بطريق التعويض', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7a158bb8d5267b167401b2364f8bcbec174903e0'), 'تعديل الشروط التعسفية في العقد أو الإعفاء عنها', 'b1f4210fa42764505b83155b6515cce6cfb80921', 'تعديل الشروط التعسفيه في العقد او الاعفاء عنها', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7a158bb8d5267b167401b2364f8bcbec174903e0'), 'بطلان/إبطال العقد', 'cf5c15823bc3fcbd6a6c7041a56824007e3dd911', 'بطلان/ابطال العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7a158bb8d5267b167401b2364f8bcbec174903e0'), 'تسليم مستندات في العقد', 'ee8f5cb06afa809861273341b1e52a59c073cdb9', 'تسليم مستندات في العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7a158bb8d5267b167401b2364f8bcbec174903e0'), 'تحديد مقدار محل الالتزام', 'b719fd9346ec9b865de60f26faa5492e10d5df88', 'تحديد مقدار محل الالتزام', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7a158bb8d5267b167401b2364f8bcbec174903e0'), 'إنقاص/زيادة الالتزام', '3992ab4cf58942ef345dd9304e0eddbba502f3f3', 'انقاص/زياده الالتزام', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7a158bb8d5267b167401b2364f8bcbec174903e0'), 'تمكين من تنفيذ العقد', 'e32ed64f7fc46d5da6b1634c00ec9c1f42f196bc', 'تمكين من تنفيذ العقد', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7a158bb8d5267b167401b2364f8bcbec174903e0'), 'إثبات انفساخ العقد', 'fe2309dfc1d5e4db3acca27157b7063c5b20dcd4', 'اثبات انفساخ العقد', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0f5105a39cf8c7a7c6d5e433619cc680507881b4'), 'رد الثمن في العقد', 'd74988a001c94ecbb6781dfdaa414dd2a6a802d6', 'رد الثمن في العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0f5105a39cf8c7a7c6d5e433619cc680507881b4'), 'فسخ العقد', 'd04fa1ae76d5f18f66bff856f6057a03e70b2a25', 'فسخ العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0f5105a39cf8c7a7c6d5e433619cc680507881b4'), 'التعويض في العقد', '5b3f6d8912e5d07be764bf5a9133a083c640fe90', 'التعويض في العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0f5105a39cf8c7a7c6d5e433619cc680507881b4'), 'شرط جزائي في العقد', '67f7fb1f3db11284c544e1ff91b8b2b200fe125d', 'شرط جزايي في العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0f5105a39cf8c7a7c6d5e433619cc680507881b4'), 'تسليم مستندات في العقد', '67426019f6cf34990931255730e619bdabe42982', 'تسليم مستندات في العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0f5105a39cf8c7a7c6d5e433619cc680507881b4'), 'تمكين من تنفيذ العقد', '8887d3b38656a0889a726af42e5e548992509075', 'تمكين من تنفيذ العقد', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0f5105a39cf8c7a7c6d5e433619cc680507881b4'), 'مستحقات دعاية وإعلان', '9e9fde5535783dbec674c8dd0580cfa7c54d9a20', 'مستحقات دعايه واعلان', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b65556c6c38677fba3b09032f2b4687e88dbd1f0'), 'إلزام الكفيل بدفع المبلغ', 'f96dd3b8fb8fd08217197678dd916bf1f2d89b03', 'الزام الكفيل بدفع المبلغ', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b65556c6c38677fba3b09032f2b4687e88dbd1f0'), 'فسخ العقد', 'ba1a6c41fca386267f73221bb4136234bc0e2a9e', 'فسخ العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b65556c6c38677fba3b09032f2b4687e88dbd1f0'), 'تسليم المستندات', '4a77df2650e964a20dfb194544ba4c6a76262155', 'تسليم المستندات', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b65556c6c38677fba3b09032f2b4687e88dbd1f0'), 'إلزام الكفيل بتسليم العين', '80785a5afc0d03406926ead6c60568b30de42b0f', 'الزام الكفيل بتسليم العين', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b65556c6c38677fba3b09032f2b4687e88dbd1f0'), 'الرجوع على المكفول بالمبلغ', '5c5bab769f0ec775e2f86a077822a3a6c787e21b', 'الرجوع علي المكفول بالمبلغ', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b65556c6c38677fba3b09032f2b4687e88dbd1f0'), 'رجوع كفيل على كفيل آخر', '89b5bc1ed35d5a035468f395fdbf9cb241b3988e', 'رجوع كفيل علي كفيل اخر', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b65556c6c38677fba3b09032f2b4687e88dbd1f0'), 'طلب التعويض', '8b52bd35d501bd7624fd4a326b1a7383a98ac919', 'طلب التعويض', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b65556c6c38677fba3b09032f2b4687e88dbd1f0'), 'شرط جزائي', '006eaccfce677a76d0f579e656e2690004b7ea30', 'شرط جزايي', 8, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e2e1c5abdfc3deb0c6c0e5294997991034c7ec75'), 'مستحقات مالية وحسميات', '295bdc97561fd22ea91670a8993a5150356af8fc', 'مستحقات ماليه وحسميات', 1, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='e2e1c5abdfc3deb0c6c0e5294997991034c7ec75'), 'ضمان حسن التنفيذ والضمان البنكي', '6b3ef995134657066c24faed580db684c81f02b9', 'ضمان حسن التنفيذ والضمان البنكي', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e2e1c5abdfc3deb0c6c0e5294997991034c7ec75'), 'عقد المقاولة وما يتعلق به', 'e2901d80924abeacc4a86a8a2680c1958ea70ec0', 'عقد المقاوله وما يتعلق به', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e2e1c5abdfc3deb0c6c0e5294997991034c7ec75'), 'استمرار العقد أو التمكين من محل التعاقد', '3ebef41de37433e7f66d3b56e2c88878af5e111f', 'استمرار العقد او التمكين من محل التعاقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e2e1c5abdfc3deb0c6c0e5294997991034c7ec75'), 'رد معدات وأدوات في عقد المقاولات', '56b7fb52d09e86a997615ef50bc4724203447130', 'رد معدات وادوات في عقد المقاولات', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9114d077e5ac39f924c923b8b4b91af847004d30'), 'فسخ العقد', '4b702a303aa6cc1b137ecf169e765293f61fbda7', 'فسخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9114d077e5ac39f924c923b8b4b91af847004d30'), 'التعويض في العقد', 'c8ca9e06daffad511558381d385be3dc16fa431f', 'التعويض في العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9114d077e5ac39f924c923b8b4b91af847004d30'), 'شرط جزائي في العقد', '85c99e921d6fdae66ac7a5dbf2a22eaa4da18cf6', 'شرط جزايي في العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9114d077e5ac39f924c923b8b4b91af847004d30'), 'رد الثمن في العقد', '9442839f5a6b379f9c175dd40079918faab8c122', 'رد الثمن في العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9114d077e5ac39f924c923b8b4b91af847004d30'), 'مستحقات مالية في العقد', 'c828ad944a0c153f457a36c63947b088fd257653', 'مستحقات ماليه في العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9114d077e5ac39f924c923b8b4b91af847004d30'), 'تسليم مستندات في العقد', '3ebea9b80ab85a1b5a5f669408779ff069b8faa3', 'تسليم مستندات في العقد', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a9d54b3fbd35147b493c46e289675eac6a7e4b17'), 'التعويض', '2d29245f989918f4c7e3dcf401208298e475a4cd', 'التعويض', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a9d54b3fbd35147b493c46e289675eac6a7e4b17'), 'الشرط الجزائي', '7393aceb79b45b7b1702046724a5a1dc335f5a0d', 'الشرط الجزايي', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a9d54b3fbd35147b493c46e289675eac6a7e4b17'), 'التسليم', '490441348d528fc79cf871b15e3985d5951502f4', 'التسليم', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a9d54b3fbd35147b493c46e289675eac6a7e4b17'), 'الأجرة', '207d79d847ba3e545254faf5ea051231463f3263', 'الاجره', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a9d54b3fbd35147b493c46e289675eac6a7e4b17'), 'رد المستندات', '19073f83a2fbc4d4196317feadcab9b6c68d2610', 'رد المستندات', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a9d54b3fbd35147b493c46e289675eac6a7e4b17'), 'رد الأجرة', 'f90346877896b04e9bab0b2fbbc42850b9a3e0b7', 'رد الاجره', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b28ff82e238480718addacca32861ed5b206e531'), 'فسخ أو بطلان أو شرط جزائي أو تعويض أو تسليم مستندات عقد البيع أو التوريد', '09e364f80624070496d0e92b804241292104915d', 'فسخ او بطلان او شرط جزايي او تعويض او تسليم مستندات عقد البيع او التوريد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b28ff82e238480718addacca32861ed5b206e531'), 'ثمن البيع', '930eab5ead2d53538bbd3de65235b639ff197bb4', 'ثمن البيع', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b28ff82e238480718addacca32861ed5b206e531'), 'رد المبيع', 'd630387a0418bd4833bce8d62af202c0fc013387', 'رد المبيع', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b28ff82e238480718addacca32861ed5b206e531'), 'نقل ملكية المبيع', '7f1ab4b3996be8c3fcc3880dbaa3912279497fc8', 'نقل ملكيه المبيع', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0261a6d554776848d50bd4422295d3d5bf86c7b2'), 'جميع ما يتعلق بعقد السمسرة', 'd63571212f63c16383bcb0e7ae98b437a4e014a1', 'جميع ما يتعلق بعقد السمسره', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0261a6d554776848d50bd4422295d3d5bf86c7b2'), 'طلب عمولة السمسرة', '437d97765d67af81feb8429a9c1c395bcd9d32e5', 'طلب عموله السمسره', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0261a6d554776848d50bd4422295d3d5bf86c7b2'), 'استعادة عمولة', '4d44431dbfd01c2a015bf138522ef9835b18a9c7', 'استعاده عموله', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0261a6d554776848d50bd4422295d3d5bf86c7b2'), 'إثبات نسبة معينة للعمولة', '559ce5505c8b97a07036d750b9372294a7e5fc29', 'اثبات نسبه معينه للعموله', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0261a6d554776848d50bd4422295d3d5bf86c7b2'), 'التعويض بالمثل أو إعادة الحال', '4a7839158a9045a637c8936ed51a4b5b6193ed64', 'التعويض بالمثل او اعاده الحال', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='477887429a7bc3070b61eed1cd1212eb8589b24a'), 'جزائي: تطبيق الغرامات والعقوبات', '5c52ad192043a911c1d748b9550130ae32d4ecea', 'جزايي: تطبيق الغرامات والعقوبات', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='477887429a7bc3070b61eed1cd1212eb8589b24a'), 'ما يتعلق بالعقد', '678ab027723016b5c8b831cfb5baafe4aff681f7', 'ما يتعلق بالعقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='477887429a7bc3070b61eed1cd1212eb8589b24a'), 'إثبات الشراكة', '66ad2eb894515d23cb0f9aa8e50682c34325b11a', 'اثبات الشراكه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='477887429a7bc3070b61eed1cd1212eb8589b24a'), 'إثبات التخارج', '60dc5a38cdc17629828235eedabae4b314833d9a', 'اثبات التخارج', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='477887429a7bc3070b61eed1cd1212eb8589b24a'), 'إنهاء الشراكة بطريق التعويض', 'f958e48abb2e0c44de804766b49780121a03846a', 'انهاء الشراكه بطريق التعويض', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='333ebfb09ccd9f92be8eceebabc00c0310b0eacb'), 'عقد الشركة', '6ca8dbfa5d8d1ded1432ed2326ef0dc2280e4ccb', 'عقد الشركه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='333ebfb09ccd9f92be8eceebabc00c0310b0eacb'), 'حقوق والتزامات الشركاء', 'ad53777b6fb0fbb8eaa98078e755af485f167160', 'حقوق والتزامات الشركاء', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='333ebfb09ccd9f92be8eceebabc00c0310b0eacb'), 'الحصص والأسهم', '64765846c43b2403fda83a387795386c42f8d87d', 'الحصص والاسهم', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='333ebfb09ccd9f92be8eceebabc00c0310b0eacb'), 'إدارة الشركة', '92d4e0fa904deb11ad638f665c1e450b1991df1a', 'اداره الشركه', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='333ebfb09ccd9f92be8eceebabc00c0310b0eacb'), 'حسابات الشركة', '84a8559663cfdd8de4994c90a9aef16976d8a68d', 'حسابات الشركه', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='333ebfb09ccd9f92be8eceebabc00c0310b0eacb'), 'رأس المال', '591acb567232abb85f61ee9faac9f57ae4baf1b7', 'راس المال', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='333ebfb09ccd9f92be8eceebabc00c0310b0eacb'), 'حل وتصفية الشركة', '302ff3deb5bd2f75d3923731d5e3db95bd2eb885', 'حل وتصفيه الشركه', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='333ebfb09ccd9f92be8eceebabc00c0310b0eacb'), 'قرارات الشركاء', '42438adefa4f8c90bb5db74bd57f0e7282af2421', 'قرارات الشركاء', 8, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='333ebfb09ccd9f92be8eceebabc00c0310b0eacb'), 'إيقاع العقوبات النظامية المنصوص عليها في نظام الشركات', 'eb2c3356c7e54323e834379b1e48391be665ec2d', 'ايقاع العقوبات النظاميه المنصوص عليها في نظام الشركات', 9, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='06f423b185a88c7be012dd2d1280754bb0964bb2'), 'عقد الشركة ورأس المال وما يتعلق به', 'f62ed6ace9c82170528e1f82fa9e21a30b154b94', 'عقد الشركه وراس المال وما يتعلق به', 1, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='06f423b185a88c7be012dd2d1280754bb0964bb2'), 'طلبات مالية', '34839eda395f0c913ff38559dffb1610b0521bf4', 'طلبات ماليه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='06f423b185a88c7be012dd2d1280754bb0964bb2'), 'التزام الشركة والتنفيذ العيني', 'db7a9958c3e4a5a8c7f0be5b78714a1fe23d2d2b', 'التزام الشركه والتنفيذ العيني', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='06f423b185a88c7be012dd2d1280754bb0964bb2'), 'الخروج من الشركة', 'f0ab75b402890a70220abb9e1f0d50f1238d1043', 'الخروج من الشركه', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='06f423b185a88c7be012dd2d1280754bb0964bb2'), 'الاطلاع على دفاتر الشركة', 'de71adf4f164877fbf0be42b22b8341cba7bd3ee', 'الاطلاع علي دفاتر الشركه', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='06f423b185a88c7be012dd2d1280754bb0964bb2'), 'محاسبة المدير', 'c9481cc298c44b08a87a92857bb9c833e854dab9', 'محاسبه المدير', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='06f423b185a88c7be012dd2d1280754bb0964bb2'), 'تصفية الشركة', '5b3bd880365022b101f93e194c28c879a86cfefe', 'تصفيه الشركه', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='06f423b185a88c7be012dd2d1280754bb0964bb2'), 'الاعتراض على تصرف الشركة', '9466f63bb79332a5b0b2db53b815aec32584d5c8', 'الاعتراض علي تصرف الشركه', 8, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2bbb848cd221d82dea03c5a2a226765327066a4b'), 'إبطال العقد', '70cdfa20b6431ea01fc43dfcad027aaf4abc579e', 'ابطال العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2bbb848cd221d82dea03c5a2a226765327066a4b'), 'إثبات بطلان العقد', '0ff8443a7967ea0e849dde1d1995f5c7429e9fcd', 'اثبات بطلان العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2bbb848cd221d82dea03c5a2a226765327066a4b'), 'تحديد مقدار محل الالتزام', '35c39226fcbcb6ec36288b3386a619de0b7f697a', 'تحديد مقدار محل الالتزام', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2bbb848cd221d82dea03c5a2a226765327066a4b'), 'زيادة/إنقاص الالتزام', 'a97c06e27cccee8c6c0776cfba6f325410bc4509', 'زياده/انقاص الالتزام', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2bbb848cd221d82dea03c5a2a226765327066a4b'), 'تمكين من تنفيذ العقد', '82c419cf1763aa8ad716d5331c7e9d6dafd95341', 'تمكين من تنفيذ العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2bbb848cd221d82dea03c5a2a226765327066a4b'), 'فسخ العقد', '30fddc09741bfdf1f7282f22f8c89b223e4aa959', 'فسخ العقد', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3052e50eef34b4c7624c4fe8f9302281b81f63ee'), 'الحجز التحفظي', '464cabea993764c298cec1a31a6d8c2ad418c33d', 'الحجز التحفظي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='af7f6e9abeca57109ddaada7705f4e45f7032206'), 'دراسة قضائية', 'd2210b2843d95f91c73faae8b2a0fdb0b7d39e56', 'دراسه قضاييه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a2494e4fdbb31fd0ad54989ca50e87c443107207'), 'منع من السفر', 'c584edb40bf22b0fecceb94e305dc20b3cf88279', 'منع من السفر', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='354b230ffa0da2299eb148d7da3c50dd2b49a3a2'), 'وقف الأعمال الجديدة', '0581bd29afaf85df8de8a380bd753d7834102597', 'وقف الاعمال الجديده', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ea0d190ada65fd214b381dfb50cfa793e097ec8e'), 'استيثاق لإثبات حالة يخشى فواتها', 'b27b672406179eac4c5cd668a64f1ac69ec2247c', 'استيثاق لاثبات حاله يخشي فواتها', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='572e92645ae797c1877e92a9130dce505cd62a1b'), 'وقف تنفيذ', '39a449ffe2227556b6e094ed43d64f531178eac0', 'وقف تنفيذ', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='366cf7b643f34d3ae8908307f8901356047b9e14'), 'الحصول على عينة من منتج', '9d3001399ab2d30b8b48f118e46835a64d17064e', 'الحصول علي عينه من منتج', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d07b582ed8f0a0f96657c89ef8581426b9e1f02f'), 'التحفظ على مستندات معينة', 'c481c1b015ca8bc5f3a91f2ee97709f8efecec22', 'التحفظ علي مستندات معينه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0378bc87f22be206c6a97405ff6f88619c5de86a'), 'المنع من التصرف', '720cb352d80db8bccf71e2207a0bc05fdb959b05', 'المنع من التصرف', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0378bc87f22be206c6a97405ff6f88619c5de86a'), 'الأذن في التصرف', '8c2c6766a4880cf1eac8463ef36593e9f146f10f', 'الاذن في التصرف', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e62c1d0793b9a537010c9663c7cb7c205e3a0fc7'), 'إثبات شهادة', '4c613c3d5aa88c9f4404b5bac61d08a6f69f64e7', 'اثبات شهاده', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2924cf45540a458c37368dad43239e337a1fb9b5'), 'طلبات عاجلة أخرى', '5a13e6e1ddad3f7e2be3fe286634fa6fc7042036', 'طلبات عاجله اخري', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f645f2f1b1ed5e19fc96c0b075a3eca00ac71846'), 'تعويض عن إنهاء العلاقة العمالية (محددة/غير محددة المدة)', 'ca465869c0f6f2274d2afd509a8fbb8e44f927c1', 'تعويض عن انهاء العلاقه العماليه (محدده/غير محدده المده)', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f645f2f1b1ed5e19fc96c0b075a3eca00ac71846'), 'تعويض عن المنافسة غير المشروعة', 'eccc05bd2f9ee7a734378d55aef20924d6b91cf9', 'تعويض عن المنافسه غير المشروعه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f645f2f1b1ed5e19fc96c0b075a3eca00ac71846'), 'الاستمرار في تنفيذ شرط عدم المنافسة', '59d49da56d54b7f0cefd755534617d20d4c6c819', 'الاستمرار في تنفيذ شرط عدم المنافسه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f645f2f1b1ed5e19fc96c0b075a3eca00ac71846'), 'تعويض مهلة إشعار', '2c30e9f18e7ad1ba370c3621f1000860dd3bae0a', 'تعويض مهله اشعار', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f645f2f1b1ed5e19fc96c0b075a3eca00ac71846'), 'تعويض عن ضرر ناشئ عن علاقة عمالية', '0b6be8851922116f9a4db73dc9f6763a0e1a24f3', 'تعويض عن ضرر ناشي عن علاقه عماليه', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3665fed1a4a48965f4cddd4225bb33f9f5ae6cb5'), 'تسليم عهدة', '96341b2090fcf817d8fc419fd88b381743607090', 'تسليم عهده', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3665fed1a4a48965f4cddd4225bb33f9f5ae6cb5'), 'تسليم مستندات ووثائق من العامل', '7b3197a3ee88ca23545644aa7fb1718c4b629652', 'تسليم مستندات ووثايق من العامل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3665fed1a4a48965f4cddd4225bb33f9f5ae6cb5'), 'إثبات/إبطال بطلان عقد', '5a481abced78c3f55c8b0b848fedfa83004e4c99', 'اثبات/ابطال بطلان عقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3665fed1a4a48965f4cddd4225bb33f9f5ae6cb5'), 'إلزام العامل بتنفيذ عقد العمل', '46787e95a5ed784bb5e2506fa052d3c35e3d8afb', 'الزام العامل بتنفيذ عقد العمل', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3665fed1a4a48965f4cddd4225bb33f9f5ae6cb5'), 'إثبات السبب الصحيح لإنهاء العلاقة', '2ecd08754426e4ec6a0fb026717ca1bc0f5e59b5', 'اثبات السبب الصحيح لانهاء العلاقه', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f1ae3729b20767c3cc5342ee2451dc837f203cf4'), 'قيمة متلف', '82d57d21abc22cb6c16763a7420daa63d81e638e', 'قيمه متلف', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f1ae3729b20767c3cc5342ee2451dc837f203cf4'), 'ارش التلف', 'dd1b999c530eef277f84e49109934292e5334096', 'ارش التلف', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f1ae3729b20767c3cc5342ee2451dc837f203cf4'), 'استرداد أجر مدفوع دون سبب مشروع', '865168d6b57b24db049e3fb15ca9ca2259aa53c4', 'استرداد اجر مدفوع دون سبب مشروع', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f1ae3729b20767c3cc5342ee2451dc837f203cf4'), 'دفع الرسوم الحكومية', '58a4e0f0f7e42584d0bbb47166a5dab2bea0d162', 'دفع الرسوم الحكوميه', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f1ae3729b20767c3cc5342ee2451dc837f203cf4'), 'استرداد سلفة', 'b5e0f7a78fff8b85d818780acdb54dd5e63935d1', 'استرداد سلفه', 5, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='f1ae3729b20767c3cc5342ee2451dc837f203cf4'), 'استرداد الرسوم الحكومية المدفوعة', '28b8ba624a2e31598cfa95f3b1d1db3f790cdcb2', 'استرداد الرسوم الحكوميه المدفوعه', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2026d73d940b5bbd4026fb674d52d39d1c516a57'), 'الترقية', '0b0ee66c0389eac41c497309be1645f2a7bf2de1', 'الترقيه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2026d73d940b5bbd4026fb674d52d39d1c516a57'), 'الاعتراض على قرار إيقاع الجزاء', '59b7209f9e8d5a7ea42e9e897537acd8b3bbc469', 'الاعتراض علي قرار ايقاع الجزاء', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2026d73d940b5bbd4026fb674d52d39d1c516a57'), 'العلاوة', '131d291d9b94edc922005cdf0457456a4a72804c', 'العلاوه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2026d73d940b5bbd4026fb674d52d39d1c516a57'), 'تسكين على وظيفة', '4ce4710239bb87be62d5a35961c4f70ae1aea0ce', 'تسكين علي وظيفه', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2026d73d940b5bbd4026fb674d52d39d1c516a57'), 'تعديل المرتبة الوظيفية حسب اللائحة الداخلية', 'cceaef67a6cd19716aae5f8e0987aa3bfd30c093', 'تعديل المرتبه الوظيفيه حسب اللايحه الداخليه', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2026d73d940b5bbd4026fb674d52d39d1c516a57'), 'التمكين', 'be63c623726ddc62a867373c0ff73d34dd37fb6a', 'التمكين', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2026d73d940b5bbd4026fb674d52d39d1c516a57'), 'المساواة الوظيفية', '392400ee55d961816d4ef91245dc84ccf075f81e', 'المساواه الوظيفيه', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2026d73d940b5bbd4026fb674d52d39d1c516a57'), 'تأمين سكن حسب اللائحة الداخلية', 'ebf0c0d0ec744428f3318d75d1d319d0327cad40', 'تامين سكن حسب اللايحه الداخليه', 8, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2026d73d940b5bbd4026fb674d52d39d1c516a57'), 'شهادة خبرة', '07821e7dc0ca13639dfa1f3776f99ff3f20b6613', 'شهاده خبره', 9, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2026d73d940b5bbd4026fb674d52d39d1c516a57'), 'تذكرة العودة للوطن', 'e9a8dd86f5be395c6829121290792726245438a8', 'تذكره العوده للوطن', 10, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e117cb955a812c7b83a584cb39a08bfa95a1c539'), 'إصابة عمل', 'd72d05d45b6a8c15f9c0675b51288cd186e5387d', 'اصابه عمل', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e117cb955a812c7b83a584cb39a08bfa95a1c539'), 'التعويض عن مهلة الإشعار', 'a827928c766d2aee48c6c184baf0570cbde15492', 'التعويض عن مهله الاشعار', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e117cb955a812c7b83a584cb39a08bfa95a1c539'), 'تسليم أجر الإجازات غير المتمتع بها', 'e131dba2db13295bb941dd5dcdae8e8ee02480f4', 'تسليم اجر الاجازات غير المتمتع بها', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e117cb955a812c7b83a584cb39a08bfa95a1c539'), 'التعويض عن عدم التسجيل في التأمينات', '70c04c3f718cd693513de605260bb5087d45f455', 'التعويض عن عدم التسجيل في التامينات', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e117cb955a812c7b83a584cb39a08bfa95a1c539'), 'تعويض عن إنهاء العلاقة العمالية', 'f8e383ab57890aa4d6719f9a597091d754cc6ae4', 'تعويض عن انهاء العلاقه العماليه', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e117cb955a812c7b83a584cb39a08bfa95a1c539'), 'تكاليف العلاج لعدم وجود تأمين', '20d964725a7fc5e80895a6aedfedfaeedde9fc45', 'تكاليف العلاج لعدم وجود تامين', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e117cb955a812c7b83a584cb39a08bfa95a1c539'), 'أجر عمل أثناء الإجازات الرسمية', '1dc0c67a9a83a2d1b295b635dd2fccff90823c5b', 'اجر عمل اثناء الاجازات الرسميه', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='91948095df8f2b93d791bf3942d3eb6a80c16767'), 'أجر متأخر', '86346d997632fbfb2c29d7661534a574e1d230e8', 'اجر متاخر', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='91948095df8f2b93d791bf3942d3eb6a80c16767'), 'مكافأة', 'a88441e73af674456351ea40b793d22a7282b41e', 'مكافاه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='91948095df8f2b93d791bf3942d3eb6a80c16767'), 'طلب بدل', 'dfe5552f127fa67d401bb2b4e3401aac9e37e669', 'طلب بدل', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='91948095df8f2b93d791bf3942d3eb6a80c16767'), 'أجر عمل أثناء الإجازة الرسمية', '727fdfaa439e1bb8259b43fee0e5739eecb138a2', 'اجر عمل اثناء الاجازه الرسميه', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='91948095df8f2b93d791bf3942d3eb6a80c16767'), 'أجر مدة الإيقاف', '32741ef2100eba44a2d252e13265a49b8009f13c', 'اجر مده الايقاف', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='91948095df8f2b93d791bf3942d3eb6a80c16767'), 'فرق زيادة الأجر', 'd9c947323b2ad9740630b8f888fbd3aff4ef96ef', 'فرق زياده الاجر', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='91948095df8f2b93d791bf3942d3eb6a80c16767'), 'استرداد الرسوم الحكومية المدفوعة', '34bdd2dad17daba2c2af829456b8fab944f0817e', 'استرداد الرسوم الحكوميه المدفوعه', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='91948095df8f2b93d791bf3942d3eb6a80c16767'), 'العمولات', 'e2fa89fca04d17e6d4ca8f95ee9e5b1cebb29ccc', 'العمولات', 8, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9d674a8e198c73be86325296ce002d7038be74e2'), 'إنهاء المرأة العاملة العقد خلال 3 أشهر من الوضع', '8d709e57406b942060e631cd55bad9510b471107', 'انهاء المراه العامله العقد خلال 3 اشهر من الوضع', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9d674a8e198c73be86325296ce002d7038be74e2'), 'إنهاء العقد من صاحب العمل دون سبب مشروع', 'e23cc7698c223bf43bc1bd3bfc0e6e00de6965e2', 'انهاء العقد من صاحب العمل دون سبب مشروع', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9d674a8e198c73be86325296ce002d7038be74e2'), 'فسخ العقد وفق المادة 80', '07cc29957c6c71226d0dfe1871c80510540d9448', 'فسخ العقد وفق الماده 80', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9d674a8e198c73be86325296ce002d7038be74e2'), 'انتهاء العقد بسبب القوة القاهرة', 'bad6fd6ed58fddfc15954047b6ac80fa603979ae', 'انتهاء العقد بسبب القوه القاهره', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9d674a8e198c73be86325296ce002d7038be74e2'), 'الاتفاق على إنهاء العقد', '2020de9cf773f9bf1e8c3e25c26e862c12507743', 'الاتفاق علي انهاء العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9d674a8e198c73be86325296ce002d7038be74e2'), 'عجز العامل', 'ab509c679c46d566fb7638f52710de6306eacbe0', 'عجز العامل', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9d674a8e198c73be86325296ce002d7038be74e2'), 'وفاة صاحب العمل أو العامل', '8ee03a8af3b0d8a036dbc1530d6e997a621fd878', 'وفاه صاحب العمل او العامل', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9d674a8e198c73be86325296ce002d7038be74e2'), 'بلوغ سن التقاعد', 'be8744f2497321c2258b1c5082788548acc5f9e9', 'بلوغ سن التقاعد', 8, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9d674a8e198c73be86325296ce002d7038be74e2'), 'فسخ العقد وفق المادة 75', '14e9e4f123e722ba5a78cbe35bf40801a4d38915', 'فسخ العقد وفق الماده 75', 9, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9d674a8e198c73be86325296ce002d7038be74e2'), 'فسخ العقد خلال فترة التجربة', 'f36b6ad11f19ec6720d1da9fd2bb2fe54ecad33f', 'فسخ العقد خلال فتره التجربه', 10, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d70505a92b06d5a73ffd55dfb4af80503378a7b4'), 'إبطال العقد', '57b38ef0c2ac9c67e7e1d7d515cea0018bc6aa7e', 'ابطال العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d70505a92b06d5a73ffd55dfb4af80503378a7b4'), 'إثبات بطلان العقد العمالي', '7160f445a0fe1b359e4918d8f1fd02bae07beca2', 'اثبات بطلان العقد العمالي', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d70505a92b06d5a73ffd55dfb4af80503378a7b4'), 'زيادة/إنقاص التزام في العقد', '822d6194123b64acc23081796411e0bf92b06f5a', 'زياده/انقاص التزام في العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d70505a92b06d5a73ffd55dfb4af80503378a7b4'), 'تحديد مقدار محل الالتزام', 'e37576d1ea5101ae52c40d63e453b918d13eac98', 'تحديد مقدار محل الالتزام', 4, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='d70505a92b06d5a73ffd55dfb4af80503378a7b4'), 'فسخ العقد', '248c131dbe6da9d6934f1062bc687e67809da98e', 'فسخ العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d70505a92b06d5a73ffd55dfb4af80503378a7b4'), 'نسخة من عقد العمل', '6d82c2438db058003154165a17e30628823c6e5f', 'نسخه من عقد العمل', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d70505a92b06d5a73ffd55dfb4af80503378a7b4'), 'كتابة عقد العمل', 'c36ee421a69f362594294a029864e1c34fb87ee9', 'كتابه عقد العمل', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d70505a92b06d5a73ffd55dfb4af80503378a7b4'), 'تعديل الشروط التعسفية أو الإعفاء منها', 'dad1ba6febc3484265b3197cc35dac2fc006ad2b', 'تعديل الشروط التعسفيه او الاعفاء منها', 8, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d70505a92b06d5a73ffd55dfb4af80503378a7b4'), 'تأمين سكن حسب عقد العمل', '7a6da6828ac31910be0dc7c619d6e1a523b60cc4', 'تامين سكن حسب عقد العمل', 9, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='eae951ff5de8208732d0860a88754be420956ace'), 'استرداد مبالغ مدفوعة دون سبب مشروع', 'c36fce30bd02115b809c004dd971794042528051', 'استرداد مبالغ مدفوعه دون سبب مشروع', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='eae951ff5de8208732d0860a88754be420956ace'), 'استرداد سلفة', '21d249673d7568458aaea1e75167b5212d8d9044', 'استرداد سلفه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='eae951ff5de8208732d0860a88754be420956ace'), 'قيمة المتلف', 'a907edaf9274f0b09577f5f4143e150c2f7136e9', 'قيمه المتلف', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='eae951ff5de8208732d0860a88754be420956ace'), 'التعويض عن إنهاء العقد دون سبب مشروع', 'd9e0c9d93b13a85862ff4033a6826a81ecc3276e', 'التعويض عن انهاء العقد دون سبب مشروع', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e1dbcde6f91e102ffbfb6ab5e7c2561df4e8c975'), 'تسليم عهدة لدى العامل المنزلي', '83e998ebef6ce5f434572a39d874be222c8026f4', 'تسليم عهده لدي العامل المنزلي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e1dbcde6f91e102ffbfb6ab5e7c2561df4e8c975'), 'إثبات حالة امتناع العامل عن العمل', '7c8a20eea6d455fe4e9b3ea37e99bda728ae93ee', 'اثبات حاله امتناع العامل عن العمل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='eddab8f4beaa58f91dd58241d3f9cc4b9a0d2005'), 'طلب رسوم حكومية', 'a9424289f3d226bec78b0eae5ba492200160cfce', 'طلب رسوم حكوميه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='eddab8f4beaa58f91dd58241d3f9cc4b9a0d2005'), 'أجر', '1eb02547fa1dc8074de1d148eb581a1ebb54025b', 'اجر', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='eddab8f4beaa58f91dd58241d3f9cc4b9a0d2005'), 'بدل', 'baa180cdd5cd8cfb76dcd71265f7f896f8f1b815', 'بدل', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='eddab8f4beaa58f91dd58241d3f9cc4b9a0d2005'), 'التعويض', '0815a59d4dd7fc74c6f3ffea49ee5bb7268005c4', 'التعويض', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='eddab8f4beaa58f91dd58241d3f9cc4b9a0d2005'), 'مكافأة نهاية الخدمة', '93bdd8856cc732374e635848f635506ad065b6f7', 'مكافاه نهايه الخدمه', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1ad33a1188072c7cf7cac22a90c164a6261eba9c'), 'تأمين مسكن أو مطعم', '39edb3d4b2a9acc34605387b6eceb7163db5883d', 'تامين مسكن او مطعم', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1ad33a1188072c7cf7cac22a90c164a6261eba9c'), 'التعويض ليوم راحة بديل', '203f75b8d61330e798b419f33ff678f8b3f0fc03', 'التعويض ليوم راحه بديل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1ad33a1188072c7cf7cac22a90c164a6261eba9c'), 'التمكين من التمتع بالإجازة السنوية', 'e4b956a094a746e3794967b3ea87083a708dbf72', 'التمكين من التمتع بالاجازه السنويه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1ad33a1188072c7cf7cac22a90c164a6261eba9c'), 'تذكرة سفر لقضاء الإجازة السنوية', '8303082b1c4356a02b0b462b665ec00aa8dfbfa2', 'تذكره سفر لقضاء الاجازه السنويه', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1ad33a1188072c7cf7cac22a90c164a6261eba9c'), 'تذكرة عودة للوطن', 'a8aac981061b5d37182b36d9a30720de79c51b3b', 'تذكره عوده للوطن', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1ad33a1188072c7cf7cac22a90c164a6261eba9c'), 'محل الإقامة', '3fabf487bf4bea0cbe82c1c6801474bc7ab8d2e2', 'محل الاقامه', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1ad33a1188072c7cf7cac22a90c164a6261eba9c'), 'مستندات ووثائق', '9290903ba41981ef20d187fa23d4bd47e6723694', 'مستندات ووثايق', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a4a3d377d5bcf722bafc81f00b11568be767df23'), 'منع من السفر', 'b024aa35a70c865882eaf07ce754a8b5fa709d5b', 'منع من السفر', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a5b06bb339758398c56dead175de1d106b883de5'), 'إثبات تنازل', 'e76d19e1ae2373c780e86011414cdbf24b7e33d5', 'اثبات تنازل', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3baf89a0fa4ce7124a53179d53212e15d77afc7c'), 'تسليم مضبوطات', '63996a1733fda77dfa7e90fc3313ed718c54ee9c', 'تسليم مضبوطات', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ab4fe4b10ddaa1ce5a11d7fec5dbadb3f885a94b'), 'استيثاق لإثبات حالة يخشى فواتها', '724d64babdd794925228f82f2865f104e6a16f71', 'استيثاق لاثبات حاله يخشي فواتها', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='4fb9ebb6c63b7f79e1db142b65a0caae70b7f101'), 'استيثاق لإثبات شهادة يخشى فواتها', '3b58a8eb38b7c72a51d2aca77f4d4af99b4abd49', 'استيثاق لاثبات شهاده يخشي فواتها', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='dc84697ee4ce60c5aeb0db23dc9de176f674837d'), 'إلغاء أمر التوقيف', '60eda46f09a6bfd9fa9fc321d172e5d3fc1be7a2', 'الغاء امر التوقيف', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9d01bb65396fae03294dcd766df732289458e945'), 'القصاص', '63c5ba1d1aea21a4ba07abc7cd3aa6f0ce3ac661', 'القصاص', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a752334a7cbf82951603a6c9212cce48dda9b761'), 'القصاص', 'a0a69054881ad572dd7d342278e49e25b2bf4375', 'القصاص', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e0d44fa025aed5d72794ab5962f7c834d3870057'), 'إقامة الحد', '05b24697d64f9c2216444173b8e5efe515f52874', 'اقامه الحد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='405c0a6a5e40cfaa1fc691cffb611e9f050cce0b'), 'رفع الضرر', '2e8108328f5013664345af0c220153b4f9eb1673', 'رفع الضرر', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='405c0a6a5e40cfaa1fc691cffb611e9f050cce0b'), 'التعويض عن الضرر', '7556bc9a5bb458107a9cbbca8b4747eadb5e59e7', 'التعويض عن الضرر', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='405c0a6a5e40cfaa1fc691cffb611e9f050cce0b'), 'سداد مبلغ الضرر', 'dabf9aab2d04c1cafe83671fd1f961e59819d256', 'سداد مبلغ الضرر', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='57e9a8f5e2fee9bafb211e8dcbb97f0715d5dbb5'), 'التعويض', '0a2b8224cf8ce5193309cd04a36a96ee4768446e', 'التعويض', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='123deaff377e93fcca9f4f7227ec3bce967d58c6'), 'ارش إصابة', 'd72994c6a485293494ea924018c7baed4d729758', 'ارش اصابه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f3788bb48681afbfaf82676e7e286642787fa1b1'), 'التعويض عن السجن', '3bdcc8cb780f56acb3105ceb433f7132780e0ef8', 'التعويض عن السجن', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='678221c7ebc97197716fecbc49e3756b7184a584'), 'تعويض مقدر شرعًا', 'c270cdef5aaa3a318e3f353210ca961e182875ad', 'تعويض مقدر شرعا', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='678221c7ebc97197716fecbc49e3756b7184a584'), 'تعويض غير مقدر شرعًا', '0a08f872fd7427f1c772907ed9fda9f459e20268', 'تعويض غير مقدر شرعا', 2, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='6489bcacf1ec94b88638dae98cba679b05d49481'), 'رد العين', '6120376a02296a37dd2496ad0e251bde718f0cee', 'رد العين', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6489bcacf1ec94b88638dae98cba679b05d49481'), 'رد ثمن العين', 'fa1502dadb10f37340f03dc1da03e6dce6e1c784', 'رد ثمن العين', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6489bcacf1ec94b88638dae98cba679b05d49481'), 'التعويض عن العيب في العين', '0449ab6894fa9c9bdfd2a6510027645752de160d', 'التعويض عن العيب في العين', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='96cfc9263419c62521a4c7917e99e724851779c8'), 'أتعاب محاماة أو وكلاء', 'd965ce7f9bab9ea8b8b54eb8445a9cd3dcabab7a', 'اتعاب محاماه او وكلاء', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='54f72251bfa5c506c604b99f566881a61faae68f'), 'التعويض عن أضرار التقاضي', 'b6d93baaf93207b4fc81e7fab447b77137490d88', 'التعويض عن اضرار التقاضي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='09271b0b1c6fd410c395885afe7c5ead260fd769'), 'استرداد المبالغ المسلمة للمحامي أو الوكيل', '7e6b74ced21b709f1acc71d921264df8d194a677', 'استرداد المبالغ المسلمه للمحامي او الوكيل', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='09271b0b1c6fd410c395885afe7c5ead260fd769'), 'أتعاب محاماة أو وكلاء في قضية أو غير قضية', '27edad6b60793d6bc8e29083003f5b84fb068d09', 'اتعاب محاماه او وكلاء في قضيه او غير قضيه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9ba89f7c16fe1fe89de32960fd5709d2b3f50d62'), 'إثبات الشراكة', '61392ebc5dfb553c58521c798615d7cdebfd651a', 'اثبات الشراكه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9ba89f7c16fe1fe89de32960fd5709d2b3f50d62'), 'بيع العين', 'fa254d71136cf216b549539f40ff9f1a566c74bf', 'بيع العين', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9ba89f7c16fe1fe89de32960fd5709d2b3f50d62'), 'قسمة المنافع مهايأة', 'c51e74cd8ca2e4cf3283f0aa6a7ab61312fc15d4', 'قسمه المنافع مهاياه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9ba89f7c16fe1fe89de32960fd5709d2b3f50d62'), 'قسمة العين', '5808ed813402a34aedd8ca366083f4c1e1652ba6', 'قسمه العين', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9ba89f7c16fe1fe89de32960fd5709d2b3f50d62'), 'أجرة المنفعة', '71aeb3fd8ab6f81022cb53471563c79fb55cc955', 'اجره المنفعه', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9ba89f7c16fe1fe89de32960fd5709d2b3f50d62'), 'إثبات نسبة الشراكة', '443f268b9c7dc873bcadad6ef4bbacef3e759d91', 'اثبات نسبه الشراكه', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7bbe0466fb2c98796558358211debd2b82c35a4b'), 'ارش الجروح والشجاج', '40c7013d1104f5145631e4411019c151753ae63c', 'ارش الجروح والشجاج', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7bbe0466fb2c98796558358211debd2b82c35a4b'), 'إلزام الجاني بدية العاهة المتخلفة', '6889283eadeae7b1dc136a00753b618fab0a53d6', 'الزام الجاني بديه العاهه المتخلفه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7bbe0466fb2c98796558358211debd2b82c35a4b'), 'قسمة التعويض', '385e5be8eece5b8ee21e148bffe08328f86bf250', 'قسمه التعويض', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7bbe0466fb2c98796558358211debd2b82c35a4b'), 'إعادة النظر في تقدير التعويض', '90659d071f3b38e810cfb2c969f92ef05bfd461a', 'اعاده النظر في تقدير التعويض', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c9170746a4ed402cf1b80b5a175a2c02789148d6'), 'تعديل تقدير النفقات', '40932f67bea4429c1f304aa7a577908a8e154fbe', 'تعديل تقدير النفقات', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c9170746a4ed402cf1b80b5a175a2c02789148d6'), 'مطالبة الكفيل بمكافأة', '81f519b8b4a51297e3396bf822db4d312caf9ebd', 'مطالبه الكفيل بمكافاه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c9170746a4ed402cf1b80b5a175a2c02789148d6'), 'عوض تقدير الحادث', '052ad4cb83b249aa7f8f095221b5b8a0666abe93', 'عوض تقدير الحادث', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c9170746a4ed402cf1b80b5a175a2c02789148d6'), 'رسوم تقديم المكفولة', 'b427f7059bcf25e1423aa16303d648640826c1cb', 'رسوم تقديم المكفوله', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c9170746a4ed402cf1b80b5a175a2c02789148d6'), 'ارش تلفيات في غير مركبة', '961334ead9fb3dea541bef8ccfe892fefc474980', 'ارش تلفيات في غير مركبه', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c9170746a4ed402cf1b80b5a175a2c02789148d6'), 'قيمة تلفيات', '42c7185372a26432a5618d8e91dc0531ff859281', 'قيمه تلفيات', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c9170746a4ed402cf1b80b5a175a2c02789148d6'), 'أجرة فحص المركبة', '397068d24f5220cc26043d9bede19f5b096ef0b1', 'اجره فحص المركبه', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c9170746a4ed402cf1b80b5a175a2c02789148d6'), 'ارش أودية الجروح والشجاج والإصابات', '4e2fe159d6a5ab814562631bad06d611ad340d10', 'ارش اوديه الجروح والشجاج والاصابات', 8, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='393e0199b442cc099ad6894ea2de27919910dfe1'), 'أرباح المساهمة', 'e062c15f9cd342a3fc01dfed8d6412e78c944f21', 'ارباح المساهمه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='393e0199b442cc099ad6894ea2de27919910dfe1'), 'تصفية المساهمة', 'af3d9855a933be685374b12d40c9cc384d42a45b', 'تصفيه المساهمه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='393e0199b442cc099ad6894ea2de27919910dfe1'), 'إعادة مساهمة عقارية', '0979d766b0552de2e1a924876ca4080f758fd771', 'اعاده مساهمه عقاريه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='393e0199b442cc099ad6894ea2de27919910dfe1'), 'إعادة رأس مال التسجيل العيني', '531140fe0e46f3c9239cce5975a65366959c3858', 'اعاده راس مال التسجيل العيني', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ffd30bc3ad70d715630e12e5b2dd2c73b07bb503'), 'ارش الجروح والشجاج', '71175f56451c0d5ecef5eba62df65bbf1879071a', 'ارش الجروح والشجاج', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ffd30bc3ad70d715630e12e5b2dd2c73b07bb503'), 'إلزام الجاني بدية العاهة المتخلفة', '00d31ff2f318cd6aa4335183a79bb1c46d58e353', 'الزام الجاني بديه العاهه المتخلفه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ffd30bc3ad70d715630e12e5b2dd2c73b07bb503'), 'إعادة النظر في تقدير التعويض', '7612c7b795ee299755471a5e3d51d8b15010c123', 'اعاده النظر في تقدير التعويض', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5934ace709f1d58e8f8fbc0583b98b383b4efeed'), 'رد العين', '13256ecafa9b0361b218ce6f1bfc549b3ae6467a', 'رد العين', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='eb270ae31aaa25db6326888ec71e7c235a9c4033'), 'التعويض عن أضرار التقاضي', '07f32339cf8d398cc36a8364e721a23d08bd403c', 'التعويض عن اضرار التقاضي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f6fb117f865886957803d0d476c477489206136c'), 'إثبات ملكية العقار', 'a7184f39c2a34dd94d954464c0fe6f39b06e0db8', 'اثبات ملكيه العقار', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f6fb117f865886957803d0d476c477489206136c'), 'إلغاء صك ملكية العقار', 'b6d5a63675da72ee42e62563523a986d8ddc1ab4', 'الغاء صك ملكيه العقار', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f6fb117f865886957803d0d476c477489206136c'), 'إفراغ الجزء المحدد', '5caec17ba245e2a931d0a9f3a3bc0e7f05c02fd2', 'افراغ الجزء المحدد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f6fb117f865886957803d0d476c477489206136c'), 'إثبات الملكية للجزء المحدد', '021b4d51e2fb6af5e9fd8202f796488a73583fc4', 'اثبات الملكيه للجزء المحدد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f6fb117f865886957803d0d476c477489206136c'), 'فك رهن الصك', '8707112c52f5f883bf4bd2781a91e1f085aef1d1', 'فك رهن الصك', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='66a45230354cb397b932a1214c10e472db0be754'), 'فسخ عقد الأجرة وإخلاء العقار', 'bd7e5d26bf9df25186570de71d010e0a239d828e', 'فسخ عقد الاجره واخلاء العقار', 1, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='66a45230354cb397b932a1214c10e472db0be754'), 'إخلاء العقار محل النزاع', 'a0b22c21868456c2c182188d01dc9ba1fc54e3a7', 'اخلاء العقار محل النزاع', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='66a45230354cb397b932a1214c10e472db0be754'), 'إثبات العقد وإخلاء العقار', 'dc42ffc6d1fabddd1bdb7ce42beb8b616b98188a', 'اثبات العقد واخلاء العقار', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='842dcee6fd306fc1aee3655c6df5df4121ffcd92'), 'فتح طريق العبور', '10eacd33e8c04d004d8b8ca603f9bc5bdff86cc8', 'فتح طريق العبور', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='842dcee6fd306fc1aee3655c6df5df4121ffcd92'), 'التمكين من العبور', 'e64e6b60605283eb340d4000da029debb938bf3f', 'التمكين من العبور', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='003c1ac61dcefd0082076479b6182fa43fa2a3aa'), 'رفع اليد عن العقار', 'b2ff0b5aac0f996b80f6430cc2a10756d7e7963d', 'رفع اليد عن العقار', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='003c1ac61dcefd0082076479b6182fa43fa2a3aa'), 'إثبات عدم صحة تملك المدعي لهذا الجزء', '0a74ef72ce629452817f76008e90e15285a58306', 'اثبات عدم صحه تملك المدعي لهذا الجزء', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2f5eed1cbb6113f0ed571ddd407419e80dddcb22'), 'إثبات الشفعة والحكم بالعقار', '1ae197abe8e8a3e4eccbc64f36d2906ca243eac8', 'اثبات الشفعه والحكم بالعقار', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c3a42dfee5b45f16316901afd197bc12b8b4e338'), 'قسمة العقار وتسليم نصيبه منه', 'e76e37f3e107b7ce54d940a487d24df1649521d9', 'قسمه العقار وتسليم نصيبه منه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c3a42dfee5b45f16316901afd197bc12b8b4e338'), 'بيع العقار وتسليم نصيبه من الثمن', '02c4d6055a4e8e59012f9b747bdb70d38ea66241', 'بيع العقار وتسليم نصيبه من الثمن', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='035483f355ddc34fafbe17dac633faa26bc5af2e'), 'رفع اليد عن العقار محل النزاع', '75a93ce7a2b4709ebdab82e9373149a4c5734191', 'رفع اليد عن العقار محل النزاع', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='035483f355ddc34fafbe17dac633faa26bc5af2e'), 'إزالة المخلفات عن العقار محل النزاع', 'd6b97899e4bff95d63db59c81e5479eb1eec2acd', 'ازاله المخلفات عن العقار محل النزاع', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='88898793e90a558774349541bc8281f22de1691c'), 'رفع اليد عن محل النزاع المسيل', '22dd8580ae9347de203d297726c422a695dd916b', 'رفع اليد عن محل النزاع المسيل', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='88898793e90a558774349541bc8281f22de1691c'), 'إعادة قسمة المسيل بين المستفيدين', 'cc9a124a4a2fccc091b87443447a32afb78cba2f', 'اعاده قسمه المسيل بين المستفيدين', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='88898793e90a558774349541bc8281f22de1691c'), 'إغلاق مجرى المياه', 'c5e93e6205fe6f0fd3fb0e1335ddbc8a7a043581', 'اغلاق مجري المياه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='88898793e90a558774349541bc8281f22de1691c'), 'منع الرمل والأطيان', 'd8d0e6fb7b2f7e4f4e21b5eee8966353262372e8', 'منع الرمل والاطيان', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='88898793e90a558774349541bc8281f22de1691c'), 'إلغاء المتداخل مع الجزء المنتفع منه', '7de4a30d94ac3e2d534daf46f945ec1630a96023', 'الغاء المتداخل مع الجزء المنتفع منه', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e7b0bb8a30321e9c7dda9337585ad31793ea3af9'), 'تعديل أتعاب التحكيم', '8e2d1a3b7051515c2c1621f9c46a2663ac813da3', 'تعديل اتعاب التحكيم', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3722e285df7a8a22e40ec5b5afc34c8206ae1ad4'), 'تعيين حكم', '333a976f735c42cb6012886ec41e7cc8097e8ce3', 'تعيين حكم', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3722e285df7a8a22e40ec5b5afc34c8206ae1ad4'), 'اختيار حكم عند غياب هيئة رئيس', '3e7aca99955c8680f95b01f4310493ea2b849640', 'اختيار حكم عند غياب هييه رييس', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3722e285df7a8a22e40ec5b5afc34c8206ae1ad4'), 'تعيين هيئة التحكيم', '636e94c2a1357f0c9b92014b557f1bab490441c5', 'تعيين هييه التحكيم', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3722e285df7a8a22e40ec5b5afc34c8206ae1ad4'), 'تعيين حكم مرجح', 'b0455e259c6f7c3e3eb029bea0c73f170edb2ef2', 'تعيين حكم مرجح', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3722e285df7a8a22e40ec5b5afc34c8206ae1ad4'), 'عزل حكم', '792f36f6018f460267148fdf22c07dbfbd5e5ad3', 'عزل حكم', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3722e285df7a8a22e40ec5b5afc34c8206ae1ad4'), 'الطعن على حكم التحكيم', 'cbf8b9573457bce187c5f9274f8afa5b0484e5e0', 'الطعن علي حكم التحكيم', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3722e285df7a8a22e40ec5b5afc34c8206ae1ad4'), 'تحديد مدة حكم التحكيم', 'd63b0bf455ed1c61822c9401420f699fa3e55966', 'تحديد مده حكم التحكيم', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9bbc300abc940a9f9b9c7a8a8c72ba949320579e'), 'إيداع حكم تحكيمي', '78fa2998a65fbadbb0573aa8cfa3f7a2ce2a3740', 'ايداع حكم تحكيمي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9bbc300abc940a9f9b9c7a8a8c72ba949320579e'), 'إيداع أمر التحكيم الوقتي والتحفظي', 'b42b6db1fed6f2b4e6391ac9183bf79d6181b2c8', 'ايداع امر التحكيم الوقتي والتحفظي', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='df560df2144fcd3c71ea4e82a1dcce1de518fc94'), 'اتخاذ تدابير مؤقتة أو تحفظية', 'ae06860b4ea0f2106e2c3d6b4584ff06cd0eb3e7', 'اتخاذ تدابير موقته او تحفظيه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='df560df2144fcd3c71ea4e82a1dcce1de518fc94'), 'الرجوع عن التدابير التحفظية', '6af031ba4daba024e839b7aa0255decb94511f4a', 'الرجوع عن التدابير التحفظيه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='df560df2144fcd3c71ea4e82a1dcce1de518fc94'), 'بطلان أمر التحكيم التحفظي أو الوقتي', '1d5a94aa4aad5cc5ba621fead9d750d555b00ce9', 'بطلان امر التحكيم التحفظي او الوقتي', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='df560df2144fcd3c71ea4e82a1dcce1de518fc94'), 'تنفيذ أمر التحكيم التحفظي أو الوقتي', 'a74eaf4842192bd61ff27c39a20d305e77022666', 'تنفيذ امر التحكيم التحفظي او الوقتي', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2f1ce46d37f1512ae9febcdd08cfcf700c948b68'), 'الأمر بتنفيذ حكم التحكيم', 'e945dc821771ed0cda58c022041bd84890e8b3a8', 'الامر بتنفيذ حكم التحكيم', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='dca39362010df8e8d61fea3a968c105fe20e06c3'), 'بطلان حكم تحكيمي', '58c3c149aea3b4f077e48cc780afc6680d92ddcb', 'بطلان حكم تحكيمي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='dca39362010df8e8d61fea3a968c105fe20e06c3'), 'وقف تنفيذ حكم التحكيم', '7dee2d52acf0cdbd39a8630e25c648beadd43e3d', 'وقف تنفيذ حكم التحكيم', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='fdd65f243df3b9896248aa64e6017168b0a21dda'), 'إثبات انفساخ العقد بسبب هلاك العقار كليًا', '3348067e922ca8770526bd8a47adcbded452d081', 'اثبات انفساخ العقد بسبب هلاك العقار كليا', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='fdd65f243df3b9896248aa64e6017168b0a21dda'), 'إبطال العقد', 'd6e1fa916e7f1d2cf9c9be9857ac8a9ef20786d2', 'ابطال العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='fdd65f243df3b9896248aa64e6017168b0a21dda'), 'إثبات بطلان العقد', 'b486601ebe835718542c702eb405a7c53f27556c', 'اثبات بطلان العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='fdd65f243df3b9896248aa64e6017168b0a21dda'), 'رد المنفعة العائدة من تنفيذ العقد', '743c098f0f2c8c8735583b96e2ab5efb727d848e', 'رد المنفعه العايده من تنفيذ العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='fdd65f243df3b9896248aa64e6017168b0a21dda'), 'فسخ العقد', '5e1a29a57462beaeaf91a1c0bd8e80f244d41335', 'فسخ العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='009e31ddbb3327581e934e472f2e32d689ae05d7'), 'التعويض النقدي', '271e81355b346977081b461270e48669e97979d4', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='009e31ddbb3327581e934e472f2e32d689ae05d7'), 'إعادة الحال إلى ما كان عليه بالمثل', 'c4404be116c32656a802c3ec8d203fbc661345e0', 'اعاده الحال الي ما كان عليه بالمثل', 2, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='009e31ddbb3327581e934e472f2e32d689ae05d7'), 'التنفيذ بمال إذا تعذر التنفيذ العيني', 'ac9e5bdf4f0e21f530febf1bd27525fb1ae11e2d', 'التنفيذ بمال اذا تعذر التنفيذ العيني', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3a882f170eee4d31ce5b77da34681bd4de0d72f3'), 'سداد أجرة العقار للمدة الزائدة عن العقد', '34d9bb1ec16e9edd5616fe443ec183b4e6875e24', 'سداد اجره العقار للمده الزايده عن العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3a882f170eee4d31ce5b77da34681bd4de0d72f3'), 'دفع فواتير الخدمات المتفق عليها في العقد', '80721a72370b49aa1619a2615a2b1049ed75a2a7', 'دفع فواتير الخدمات المتفق عليها في العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2bc0eef1dd6bbc4c3accd043f256b3596d1044bd'), 'إثبات انفساخ العقد', 'f51562b93288c4f94522bcf1a0c54017696eb506', 'اثبات انفساخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2bc0eef1dd6bbc4c3accd043f256b3596d1044bd'), 'فسخ العقد', '83a357258aab77f6885df33eb776734388e28430', 'فسخ العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2bc0eef1dd6bbc4c3accd043f256b3596d1044bd'), 'إبطال العقد', 'dca162f42a845a9f900d5d209526c9db212c6a02', 'ابطال العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2bc0eef1dd6bbc4c3accd043f256b3596d1044bd'), 'إثبات بطلان العقد', '22c1dd40be1ecb190210a476a73fdbe9d4ec9a4d', 'اثبات بطلان العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b0d3c34c7ad589e4026eae4a7cdb23d51b64cfc6'), 'دفع مبلغ الأجرة المتفق عليها للمدة المتبقية', '405d537dba6833b216d672261dd0900b987db050', 'دفع مبلغ الاجره المتفق عليها للمده المتبقيه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b0d3c34c7ad589e4026eae4a7cdb23d51b64cfc6'), 'زيادة الأجرة', '740bfd0304ecf908504e33a7266975fc4ddd2a77', 'زياده الاجره', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b0d3c34c7ad589e4026eae4a7cdb23d51b64cfc6'), 'الأجرة اليومية المتفق عليها', '2e57013d7c4cbae083645469d6971550e9eea2ab', 'الاجره اليوميه المتفق عليها', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ae508c1bb303d03668970b108b7d445a259b3bb8'), 'التعويض النقدي بالمثل', '014cdb7dc312e47e28d00c062bcaed114d280dc3', 'التعويض النقدي بالمثل', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ae508c1bb303d03668970b108b7d445a259b3bb8'), 'إعادة الحال إلى ما كانت عليه', '2225668db596c09374b58924f2bcdc46f935458f', 'اعاده الحال الي ما كانت عليه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ae508c1bb303d03668970b108b7d445a259b3bb8'), 'التنفيذ عينًا عند تعذر التنفيذ', '8fb568ee6cab1b6cdcab5043c229fcceb9f83e2d', 'التنفيذ عينا عند تعذر التنفيذ', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='447d4b201cf8c4b0cfa63c2a3f1b7eb4a4a8e9d5'), 'إثبات انفساخ العقد', '9a6345468454308e6c6a928beffac9222bb98abb', 'اثبات انفساخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='447d4b201cf8c4b0cfa63c2a3f1b7eb4a4a8e9d5'), 'إبطال العقد', 'c8067e9bdbc269a0c83dc16636f82aeeee554d0b', 'ابطال العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='447d4b201cf8c4b0cfa63c2a3f1b7eb4a4a8e9d5'), 'إثبات بطلان العقد', 'a7c63b3a5b957e817a236d4a78a5850a9a24e8a3', 'اثبات بطلان العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='447d4b201cf8c4b0cfa63c2a3f1b7eb4a4a8e9d5'), 'رد المنفعة العائدة من تنفيذ العقد', '97937d997a191612ad5f1f3bdf2e2078f8a6fc77', 'رد المنفعه العايده من تنفيذ العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='447d4b201cf8c4b0cfa63c2a3f1b7eb4a4a8e9d5'), 'فسخ العقد', 'd04ae51c0af02421464523ab1142288b8487c313', 'فسخ العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='543d6204d6f2f5468755952e6f5976e488ede7bc'), 'التعويض النقدي', '4b43d79a5ca1def93556caf93ca2a10db342ecae', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='543d6204d6f2f5468755952e6f5976e488ede7bc'), 'إعادة الحال إلى ما كانت عليه بالمثل', 'bc53a3616cf8ae9ec19ba5143aab0b5e086a00ce', 'اعاده الحال الي ما كانت عليه بالمثل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='543d6204d6f2f5468755952e6f5976e488ede7bc'), 'التنفيذ بمال إذا تعذر التنفيذ عينًا', '4b712c07bfa29ad1f4be064e5cb29eee9334e20e', 'التنفيذ بمال اذا تعذر التنفيذ عينا', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5fcae6776fa62d7e2b88737277306560edd588c4'), 'رد الوديعة عند الطلب', '2b1c1c66a6490965033350ec3b3948a2c4300c9b', 'رد الوديعه عند الطلب', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5fcae6776fa62d7e2b88737277306560edd588c4'), 'رد الوديعة في حال ضياعها', 'd67acea1ecafc94a1a87ec98d579db4bf1932c29', 'رد الوديعه في حال ضياعها', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d9cce0e3261beb3264c261c31caa7c1745ca2bbf'), 'إثبات انفساخ العقد', '5366ad749def4ac3152f1219aa6880545838940e', 'اثبات انفساخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d9cce0e3261beb3264c261c31caa7c1745ca2bbf'), 'إبطال العقد', 'adba6c8527c8b847680dfa73adccbd32913491dc', 'ابطال العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d9cce0e3261beb3264c261c31caa7c1745ca2bbf'), 'إثبات بطلان العقد', '7fa291c6fe16fc541e8da0fac067db02f3e6b0d0', 'اثبات بطلان العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d9cce0e3261beb3264c261c31caa7c1745ca2bbf'), 'رد المنفعة العائدة من تنفيذ العقد', '805991ef1e6bf9a9eb5e7cff1902b0a7ad20a545', 'رد المنفعه العايده من تنفيذ العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d9cce0e3261beb3264c261c31caa7c1745ca2bbf'), 'فسخ العقد', 'c25383ffedaf7a31c2ed71f1102edb725cbda886', 'فسخ العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f192de08a2db60eb3a73207edd9889b3dc5e305e'), 'التعويض النقدي', '24e99f45059ff8e6b4b29f5b31250fa92c61a1d3', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f192de08a2db60eb3a73207edd9889b3dc5e305e'), 'إعادة الحال إلى ما كانت عليه بالمثل', 'bc1a63581645635d26b7a6a6016b4ef69c08338f', 'اعاده الحال الي ما كانت عليه بالمثل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='f192de08a2db60eb3a73207edd9889b3dc5e305e'), 'التنفيذ عينًا عند تعذره', '21cc748bca96a688354eb3c65dd1315174e46011', 'التنفيذ عينا عند تعذره', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5967adcd031f0cb522ad1d32e25d2285e091f6c9'), 'الإفصاح عن معلومات تنفيذ الوكالة وتقديم حساب عنها', '750a257a1da88fb933ea088afda8ab7fec3c0850', 'الافصاح عن معلومات تنفيذ الوكاله وتقديم حساب عنها', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c01d3b86015c4f9ef80826da6f294c8792f142b8'), 'إثبات انفساخ العقد', 'd21db095aee837154afc9c4debe7ccb1a370547e', 'اثبات انفساخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c01d3b86015c4f9ef80826da6f294c8792f142b8'), 'إبطال العقد', '5481b43dc774f1e39ff5e497977b9519ee5c65f3', 'ابطال العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c01d3b86015c4f9ef80826da6f294c8792f142b8'), 'إثبات بطلان العقد', 'f03e4a19f428c04ba61e65c124df53bbac79442d', 'اثبات بطلان العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c01d3b86015c4f9ef80826da6f294c8792f142b8'), 'رد المنفعة العائدة من تنفيذ العقد', '76cc96b8a696bcf4f5185fc778c1f4df6d24c65c', 'رد المنفعه العايده من تنفيذ العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c01d3b86015c4f9ef80826da6f294c8792f142b8'), 'فسخ العقد', '5a0f7569464a1341c884c6768ce9a644e4afb6aa', 'فسخ العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='373756644897fb4623d02d84a500a569fa0454d0'), 'التعويض النقدي', '1611fa6ec7d71ec03efc1cb9aa144849c955434a', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='373756644897fb4623d02d84a500a569fa0454d0'), 'إعادة الحال إلى ما كانت عليه بالمثل', '94b08b53730da229504b52e92de060cb00b869d3', 'اعاده الحال الي ما كانت عليه بالمثل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='373756644897fb4623d02d84a500a569fa0454d0'), 'التنفيذ بمال إذا تعذر التنفيذ عينًا', 'ccf79ff6c23f64b6c1966cf97ede8d602cf4c32a', 'التنفيذ بمال اذا تعذر التنفيذ عينا', 3, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='1366a51ef766b2924298ea2ceb67e76548785296'), 'دفع مبلغ الحوالة وتسليم المحال به', '5cf2a71da4288231f055099c0f2d79a04de5ffb3', 'دفع مبلغ الحواله وتسليم المحال به', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='1366a51ef766b2924298ea2ceb67e76548785296'), 'أداء الحق', '5f3646a4262095afcf3cd141bb3bf53801abc7b6', 'اداء الحق', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2daeb6c4c3ac0d6f8b080b2ef3bc081f0575ce3e'), 'إثبات انفساخ العقد', '07987f1071fd884cbaeba863361f510b1f1c4fee', 'اثبات انفساخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2daeb6c4c3ac0d6f8b080b2ef3bc081f0575ce3e'), 'إبطال العقد', '9c050a250bf7756548553f45aa071303ff0f1cf8', 'ابطال العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2daeb6c4c3ac0d6f8b080b2ef3bc081f0575ce3e'), 'إثبات بطلان العقد', 'c13ff7e519ea134985a3a32d8a59a216c67e47da', 'اثبات بطلان العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2daeb6c4c3ac0d6f8b080b2ef3bc081f0575ce3e'), 'رد المنفعة العائدة من تنفيذ العقد', 'ee4af05d188056d16efcf50cc3b081e314248603', 'رد المنفعه العايده من تنفيذ العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2daeb6c4c3ac0d6f8b080b2ef3bc081f0575ce3e'), 'فسخ العقد', 'e95cf454dd2629413bd6de16cb683d004210e77b', 'فسخ العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='417003f930712fa8727d7f3d185f06c24fb3279f'), 'التعويض النقدي', '91b8b3134941ba9bf998e95603a25574b38adef8', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='417003f930712fa8727d7f3d185f06c24fb3279f'), 'إعادة الحال إلى ما كانت عليه بالمثل', 'ee604adb021859b7a1985894131536195c63834b', 'اعاده الحال الي ما كانت عليه بالمثل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='417003f930712fa8727d7f3d185f06c24fb3279f'), 'التنفيذ بمال إذا تعذر التنفيذ عينًا', '9c4a61bce4f02756ad665e08cc64684c4e1c6fc2', 'التنفيذ بمال اذا تعذر التنفيذ عينا', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2b6902de51d8f4af5f1bbf8428aaa14f9719535f'), 'رد مثل القرض عينًا أو نقدًا', '794647428e59463ef0845c060dc93aadbb0bf8c2', 'رد مثل القرض عينا او نقدا', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2b6902de51d8f4af5f1bbf8428aaa14f9719535f'), 'اقتضاء الدين لسقوط الأجل', '7db0059165d3d137b74cbd9b00a5c98fb06f2057', 'اقتضاء الدين لسقوط الاجل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2b6902de51d8f4af5f1bbf8428aaa14f9719535f'), 'تقديم ضمان قبل حلول الأجل', '6ae0364e6281e4a290680ebba6feb7911f75e626', 'تقديم ضمان قبل حلول الاجل', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6f161f46b42695a82f680f68fc2842885d9bd4b7'), 'إثبات انفساخ العقد', 'aa17b9c33e79abae92e3e79c8cf5aa33db897d5d', 'اثبات انفساخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6f161f46b42695a82f680f68fc2842885d9bd4b7'), 'إبطال العقد', '837935213201894b4e1e98c63876734b2fa2afc5', 'ابطال العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6f161f46b42695a82f680f68fc2842885d9bd4b7'), 'إثبات بطلان العقد', '795d58bf5d600ed8600e69adfef234cd4b35b8b5', 'اثبات بطلان العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6f161f46b42695a82f680f68fc2842885d9bd4b7'), 'رد المنفعة العائدة من تنفيذ العقد', 'e0ab98a9d12ff2b9683677bf6a94ae37da922771', 'رد المنفعه العايده من تنفيذ العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6f161f46b42695a82f680f68fc2842885d9bd4b7'), 'فسخ العقد', '84b3abb5ec1085d3d2a47c7d6a5cda94a77022c4', 'فسخ العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='cb3fedb445fd03548fa0b0172706d2341aac6754'), 'التعويض النقدي', 'aa38d908573ae4a699a20cfdb85d6b4428afb30f', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='cb3fedb445fd03548fa0b0172706d2341aac6754'), 'إعادة الحال إلى ما كانت عليه بالمثل', '5f21304e94b88054d6807e6ef6ab693baeb1701a', 'اعاده الحال الي ما كانت عليه بالمثل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='cb3fedb445fd03548fa0b0172706d2341aac6754'), 'التعويض لهلاك الموهوب', '37f310750841ac1ccd8c098bbfa60cca114d1913', 'التعويض لهلاك الموهوب', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='cb3fedb445fd03548fa0b0172706d2341aac6754'), 'رجوع الواهب', '12ea4d8d706b72cb98dc3919916f5d06b7496331', 'رجوع الواهب', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e14062a3431b9ff67b814bc3521ac175b2fefbf2'), 'إثبات الهبة', '47df2d2c3e3b36059084f2559ca44e0ea4ca62cd', 'اثبات الهبه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e14062a3431b9ff67b814bc3521ac175b2fefbf2'), 'تسليم الموهوب', '67e7e53c473587e961624fcfd83b8ca790dfa111', 'تسليم الموهوب', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e14062a3431b9ff67b814bc3521ac175b2fefbf2'), 'رد الهبة', '413c960e01e3a3f85b5ca102e4cb7c24f6e8b5e6', 'رد الهبه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e14062a3431b9ff67b814bc3521ac175b2fefbf2'), 'نقل ملكية الموهوب', '829b23c292396ef182df3745566067e7475db64b', 'نقل ملكيه الموهوب', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='65d00bdda95bb05df225210c26983cf2e5b0ac30'), 'إثبات انفساخ العقد', '1db37a4e1e37522b025d305e66bdd030458b6aa5', 'اثبات انفساخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='65d00bdda95bb05df225210c26983cf2e5b0ac30'), 'إبطال العقد', 'c7cc47e96504c59e3fc28643a2d032a559dd16d4', 'ابطال العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='65d00bdda95bb05df225210c26983cf2e5b0ac30'), 'إثبات بطلان العقد', 'b0adc96816c5f278d2ba2950daa6d2a4051dcf57', 'اثبات بطلان العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='65d00bdda95bb05df225210c26983cf2e5b0ac30'), 'رد المنفعة العائدة من تنفيذ العقد', '65c27ec46b66c867f86e801bcd4c37fd2c95532f', 'رد المنفعه العايده من تنفيذ العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='65d00bdda95bb05df225210c26983cf2e5b0ac30'), 'فسخ العقد', 'b3aa1a444f2131c1853ec17477138617200da626', 'فسخ العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='8cc5fd54a2a087d31fc0ed4f69ce5d3143ed1ccb'), 'التعويض النقدي', 'e73935d9ef9c32d7577bc7eef0a23ab34f7e1e90', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='8cc5fd54a2a087d31fc0ed4f69ce5d3143ed1ccb'), 'إعادة الحال إلى ما كانت عليه بالمثل', '1a0b3e8780385a76675dd9612be1d37a9d5e9e38', 'اعاده الحال الي ما كانت عليه بالمثل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='8cc5fd54a2a087d31fc0ed4f69ce5d3143ed1ccb'), 'التنفيذ بمال إذا تعذر التنفيذ عينًا', '2c166cd2f025403fe7605f0aa9fca7e51695fcb2', 'التنفيذ بمال اذا تعذر التنفيذ عينا', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='bf5e23e9e1470e08e07f6f349f8c1b85ec362743'), 'نقل ملكية العين المرهونة واحتساب قيمتها من الدين', '24a3fc6b359064ba9770dd6b36155827cd2479dc', 'نقل ملكيه العين المرهونه واحتساب قيمتها من الدين', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='bf5e23e9e1470e08e07f6f349f8c1b85ec362743'), 'بيع العين المرهونة واستيفاء مبلغ الرهن', '24bbd1630a17a152c63f44703758965baec844e1', 'بيع العين المرهونه واستيفاء مبلغ الرهن', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='bf5e23e9e1470e08e07f6f349f8c1b85ec362743'), 'إثبات الرهن', '7cb7d473bd3782b1518e6a78ccf6527127b2909c', 'اثبات الرهن', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='bf5e23e9e1470e08e07f6f349f8c1b85ec362743'), 'فك الرهن', 'b9bf7083de98750780d6b6cf068c57d4c85c48f5', 'فك الرهن', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='bf5e23e9e1470e08e07f6f349f8c1b85ec362743'), 'نفقات عقد الرهن والتسجيل', 'c51451e5c46cc8e4119748cb2b7fdd2e2c92e270', 'نفقات عقد الرهن والتسجيل', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='dcbbe55a61403acd5863ea39735bb5097a7b04a4'), 'إثبات انفساخ العقد', '2d49a68d5dea62d9829631c6a108a6c260a54f76', 'اثبات انفساخ العقد', 1, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='dcbbe55a61403acd5863ea39735bb5097a7b04a4'), 'فسخ العقد', 'e7bed51209e7b754caa9bc192334273504f19f47', 'فسخ العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='dcbbe55a61403acd5863ea39735bb5097a7b04a4'), 'إبطال العقد', '3b09992a7534fb73168f76ebe181823257eaec9a', 'ابطال العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='dcbbe55a61403acd5863ea39735bb5097a7b04a4'), 'إثبات بطلان العقد', '2e493a022bc4b11a09a7e25210b723be8346986b', 'اثبات بطلان العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='dcbbe55a61403acd5863ea39735bb5097a7b04a4'), 'رد المنفعة العائدة من تنفيذ العقد', 'a86143f5c28148b1ec9c9eebf7c87ac84bce5f8a', 'رد المنفعه العايده من تنفيذ العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='8c5785377cd09040b686702c162737a23252c86f'), 'التعويض النقدي', '161807db2865a963fb62b8881bdae48229993f9a', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='8c5785377cd09040b686702c162737a23252c86f'), 'إعادة الحال إلى ما كانت عليه بالمثل', '36d74e667bc35413594463fec6483d018a999575', 'اعاده الحال الي ما كانت عليه بالمثل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='8c5785377cd09040b686702c162737a23252c86f'), 'التنفيذ عينًا عند تعذره', '04076ad07965eb69c9860d137febce9b62e8b9e9', 'التنفيذ عينا عند تعذره', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='01852e75bb0b2c128fd11f52a7816de1629e5145'), 'أجرة الشيء المعار من حين الامتناع عن تسليمه', 'b1a5981308ca2a56f10202b58d1824fda3f81960', 'اجره الشيء المعار من حين الامتناع عن تسليمه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='01852e75bb0b2c128fd11f52a7816de1629e5145'), 'رد الشيء المعار', 'edf14af3a4a00f437b3128d42778c2ff8710576e', 'رد الشيء المعار', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='01852e75bb0b2c128fd11f52a7816de1629e5145'), 'نفقات الشيء المعار', 'a52a3eb4dbb6426a7243143a0368b284ec85644f', 'نفقات الشيء المعار', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='01852e75bb0b2c128fd11f52a7816de1629e5145'), 'إلزام المعير باستلام الشيء المعار', 'f9df6a67e75d2d662873d8f5f81ae019ae3ad163', 'الزام المعير باستلام الشيء المعار', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0e46db24f7e735aa903920710c212139152d9cde'), 'إثبات انفساخ العقد', 'da8502f3202bfa7fc56fec53eeee762db4d2d0d1', 'اثبات انفساخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0e46db24f7e735aa903920710c212139152d9cde'), 'فسخ العقد', 'f87116820542e325013a1cb88d4284d2623544fc', 'فسخ العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0e46db24f7e735aa903920710c212139152d9cde'), 'إبطال العقد', '37afa8ec3bac523f63051e2697965ba7867319d2', 'ابطال العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0e46db24f7e735aa903920710c212139152d9cde'), 'إثبات بطلان العقد', '8949c3b465f9a083b1ee01021cd8c93cf60cee7a', 'اثبات بطلان العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0e46db24f7e735aa903920710c212139152d9cde'), 'تسليم المبيع', 'e0d4e8b0d5854581799cd47e1242dfc1e64a5a87', 'تسليم المبيع', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0e46db24f7e735aa903920710c212139152d9cde'), 'نقل الملكية للمدعى عليه', 'd8f956c6c50ace374c71cf7d175e1f4e5070eae2', 'نقل الملكيه للمدعي عليه', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0e46db24f7e735aa903920710c212139152d9cde'), 'تقديم ضمان', '1b309ede9a1db1d4c41b62b09040aa969fac9b9f', 'تقديم ضمان', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7e1d561b6ecc133b50273a71e41ae30e34d840aa'), 'التعويض النقدي', '4029bd457d09dd03a9a006663c254124556aec8c', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7e1d561b6ecc133b50273a71e41ae30e34d840aa'), 'إعادة الحال إلى ما كانت عليه بالمثل', 'd8fcd74382df20de35c2c505a7096fd156a8b534', 'اعاده الحال الي ما كانت عليه بالمثل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7e1d561b6ecc133b50273a71e41ae30e34d840aa'), 'تسليم ثمن المبيع كاملًا', 'a319b6eecdc9c2477df74fd06fd3ea510386fbb0', 'تسليم ثمن المبيع كاملا', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7e1d561b6ecc133b50273a71e41ae30e34d840aa'), 'تسليم الحال والجزء المتبقي وقت حلوله', '632cac9068bfa94930297e666855d469c0d11f7c', 'تسليم الحال والجزء المتبقي وقت حلوله', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7e1d561b6ecc133b50273a71e41ae30e34d840aa'), 'تحديد مقدار الثمن', '80b65936fe4f9b60111cb59eb458c47b66a6439a', 'تحديد مقدار الثمن', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='660cc5aeecc28e54bbccb7c8358a91a7bf8e4451'), 'إثبات انفساخ العقد', '3d3060d6b3cb8a22b940e7993a910d4944c2e215', 'اثبات انفساخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='660cc5aeecc28e54bbccb7c8358a91a7bf8e4451'), 'فسخ العقد', '6921789687a59b1c5405d1724a76c664a638543a', 'فسخ العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='660cc5aeecc28e54bbccb7c8358a91a7bf8e4451'), 'إبطال العقد', '8ab8f5c2ea3dccd7fce2402f7159d2de9350b9d9', 'ابطال العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='660cc5aeecc28e54bbccb7c8358a91a7bf8e4451'), 'إثبات بطلان العقد', '3b7f602c056640417b509b72e586088e7278e736', 'اثبات بطلان العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='660cc5aeecc28e54bbccb7c8358a91a7bf8e4451'), 'تسليم مستندات الوفاء بالدين', 'dc4dde2a9bfc22bff2afec1617afc5e6a9407ac7', 'تسليم مستندات الوفاء بالدين', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a0da7aa9a81019768a026f438153573493ac0092'), 'الرجوع على باقي الكفلاء المتضامنين', '6a8c4d97148b863d51a103d37763957637485676', 'الرجوع علي باقي الكفلاء المتضامنين', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a0da7aa9a81019768a026f438153573493ac0092'), 'نفقات الضمان العيني', 'a9ae71720d7af490321c542441780d6015fa460b', 'نفقات الضمان العيني', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a0da7aa9a81019768a026f438153573493ac0092'), 'تسليم الضمان العيني', '4ad2198ad1ccec983b9b91e8dcede68644b47309', 'تسليم الضمان العيني', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a0da7aa9a81019768a026f438153573493ac0092'), 'نقل حقوق الضمان العيني', '1c2baf55cd9b979e66717b41c00314c8d7a48035', 'نقل حقوق الضمان العيني', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6bc0c6c7cb7e38e18ab973ffd832665e2aa5c524'), 'التعويض النقدي', 'fc103ead6bbe9748d8e964c1577bb0b47dd2ecab', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6bc0c6c7cb7e38e18ab973ffd832665e2aa5c524'), 'إعادة الحال إلى ما كانت عليه بالمثل', '2052f6f7d95764580eccad6783d0e90f78150aec', 'اعاده الحال الي ما كانت عليه بالمثل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6bc0c6c7cb7e38e18ab973ffd832665e2aa5c524'), 'التنفيذ عينًا عند تعذره', 'fe6d367032f11555b385d8c61e7e314d6ce85108', 'التنفيذ عينا عند تعذره', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ad52b12689412986ef69277712be42d00ee63247'), 'دفع مبلغ الكفالة', 'c020181e9e43d1755dce20628756c8305c3e427a', 'دفع مبلغ الكفاله', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ad52b12689412986ef69277712be42d00ee63247'), 'دفع المبلغ المسلم للدائن', 'cb3e1e8c80631aafe8c6e884770c3d199d5fd2a3', 'دفع المبلغ المسلم للداين', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ad52b12689412986ef69277712be42d00ee63247'), 'نفقة عقد الكفالة', 'a8b16361ebd9032956fb0db510d21383a17644e8', 'نفقه عقد الكفاله', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e0c8d35c374840fe02793dda6b4c7be0facc7b7c'), 'إثبات انفساخ العقد', '9835eecf23cb961c7aae229cdf4531443509e7ab', 'اثبات انفساخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e0c8d35c374840fe02793dda6b4c7be0facc7b7c'), 'فسخ العقد', '420c38f92be1f6dadafcae31fef0226e1d5f9991', 'فسخ العقد', 2, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='e0c8d35c374840fe02793dda6b4c7be0facc7b7c'), 'إبطال العقد', 'bd7fe479efd2fa62fecf71d3be694d1eacb079f4', 'ابطال العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e0c8d35c374840fe02793dda6b4c7be0facc7b7c'), 'إثبات بطلان العقد', '325f833c709a19c2cbdff6e4888ccd7ccab710b3', 'اثبات بطلان العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e0c8d35c374840fe02793dda6b4c7be0facc7b7c'), 'رد المنفعة العائدة من تنفيذ العقد', '90df4b8f5e8335d586f7a7bd2bd2a5059336e87b', 'رد المنفعه العايده من تنفيذ العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='765d07cfa1ed1c28086d5d86bcf000695a93a729'), 'التعويض النقدي', '859358c49d76f2b9d8f5cf98adee9ab3e1380e75', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='765d07cfa1ed1c28086d5d86bcf000695a93a729'), 'إعادة الحال إلى ما كانت عليه بالمثل', 'ef6f3fa63dc118f168d6b3c3e5f74e1d5f1ec32a', 'اعاده الحال الي ما كانت عليه بالمثل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='765d07cfa1ed1c28086d5d86bcf000695a93a729'), 'التنفيذ عينًا عند تعذره', 'a34bada426dd862493cc5b1c5089a3cf3e9cf2be', 'التنفيذ عينا عند تعذره', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c4a5cfc050127ad813d6d947064810345b215544'), 'رد المال المحروس مع غلته', 'ae5cb7a94fb104b16da949c9ab3db7068d4f2703', 'رد المال المحروس مع غلته', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c4a5cfc050127ad813d6d947064810345b215544'), 'تقديم معلومات عن أعمال الحراسة وحساب عنها', 'dab917242413167b46504493f6ce88b4b094775e', 'تقديم معلومات عن اعمال الحراسه وحساب عنها', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c4a5cfc050127ad813d6d947064810345b215544'), 'استرداد المبالغ المنفقة في الحراسة', '18fbd268939baf3c5320c7b738dc142b885575ea', 'استرداد المبالغ المنفقه في الحراسه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c4a5cfc050127ad813d6d947064810345b215544'), 'دفع أجرة الحراسة', '8b97deb01a079071439df5e305044787dcff6f9f', 'دفع اجره الحراسه', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5b886c20a2dddbcefc559650a5540c8655317868'), 'إثبات انفساخ العقد', 'e8183babdcdf91874ac3d3af7718cbeafaaa33af', 'اثبات انفساخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5b886c20a2dddbcefc559650a5540c8655317868'), 'فسخ العقد', 'f9b829865c13dd439cf6dfbfd1be72aa5dfb0caf', 'فسخ العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5b886c20a2dddbcefc559650a5540c8655317868'), 'إبطال العقد', '81f868c8d541fa99ea7c8f0b10ac74e329e4f6cf', 'ابطال العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5b886c20a2dddbcefc559650a5540c8655317868'), 'إثبات بطلان العقد', 'e492807d5da73e19384355b378e6896dc2a1efc9', 'اثبات بطلان العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5b886c20a2dddbcefc559650a5540c8655317868'), 'رد المنفعة العائدة من تنفيذ العقد', '1d34c36cd88bb53742fc654dd8fe40e02bf925e7', 'رد المنفعه العايده من تنفيذ العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='95c77f8f405242309226bee9794098c6721ce446'), 'التعويض النقدي', '5b3f9226b0ba01eab83a9edef385f3d9cc5d97cd', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='95c77f8f405242309226bee9794098c6721ce446'), 'إعادة الحال إلى ما كانت عليه بالمثل', 'f4d0d6c609ec5c0640a6fbd18f9a93e9a3253bb2', 'اعاده الحال الي ما كانت عليه بالمثل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='95c77f8f405242309226bee9794098c6721ce446'), 'التنفيذ عينًا عند تعذره', 'cd68bd90998c42f726001b152105e7ae4b6cbfec', 'التنفيذ عينا عند تعذره', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='765606457f031bd22b0be55b3fb1687f6eaab37e'), 'إثبات انفساخ العقد', '8117d3791897d4837b2d20518ec3d8fdb3982c09', 'اثبات انفساخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='765606457f031bd22b0be55b3fb1687f6eaab37e'), 'فسخ العقد', 'ff2a371e9fe3d27abdc2cb0eca4acfcb7d1a8d18', 'فسخ العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='765606457f031bd22b0be55b3fb1687f6eaab37e'), 'إبطال العقد', 'b9de3df62d5c829d65abb0a3d1a9df779b11cff6', 'ابطال العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='765606457f031bd22b0be55b3fb1687f6eaab37e'), 'إثبات بطلان العقد', '1cc039900bddabaf3701d85b70b02d09865fabd6', 'اثبات بطلان العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='765606457f031bd22b0be55b3fb1687f6eaab37e'), 'رد المنفعة العائدة من تنفيذ العقد', '7fd9ecb1c1a3a59d56b1d297c5c6204a97df354b', 'رد المنفعه العايده من تنفيذ العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7be62be309d269c294ede00d00ac46a1946c681f'), 'التعويض النقدي', 'a351dd9315b3ace410a4946431732777f0b8c904', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7be62be309d269c294ede00d00ac46a1946c681f'), 'إعادة الحال إلى ما كانت عليه بالمثل', '25a2d92a9f6434626a1fe2445fbcccb4d4504051', 'اعاده الحال الي ما كانت عليه بالمثل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7be62be309d269c294ede00d00ac46a1946c681f'), 'التنفيذ عينًا عند تعذره', 'd099e8cc79785f1805a6828326b687dc4f4c2da6', 'التنفيذ عينا عند تعذره', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='95964cf4b10f5c8c03bf75630c5893f66014d476'), 'تسليم مقابل المسابقة العيني', '15c9553a11593654544edef7d4536a78b7099656', 'تسليم مقابل المسابقه العيني', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='95964cf4b10f5c8c03bf75630c5893f66014d476'), 'تسليم مقابل المسابقة المالي', '714aacd016aa125f48566b04dfc81733fdbe7ac7', 'تسليم مقابل المسابقه المالي', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='4254b487d3034ef9e3a70fbf86a42267589c52d5'), 'إثبات انفساخ العقد', 'f86d63eb8f7343fb4ae437b254b82b349c662866', 'اثبات انفساخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='4254b487d3034ef9e3a70fbf86a42267589c52d5'), 'فسخ العقد', '83485d99970b8b091432a6e2cf40f5b83cb704f6', 'فسخ العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='4254b487d3034ef9e3a70fbf86a42267589c52d5'), 'إبطال العقد', '53a3a5927f26dbba09af450c1b594903d08a0d8e', 'ابطال العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='4254b487d3034ef9e3a70fbf86a42267589c52d5'), 'إثبات بطلان العقد', 'bfe1bfe72985f46a27a847a2299c4cb369f49d0f', 'اثبات بطلان العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='4254b487d3034ef9e3a70fbf86a42267589c52d5'), 'إعادة التوازن العقدي بزيادة أو إنقاص الالتزام', 'e860eafb33b5b06686303f9d7a9b481e4644d5e9', 'اعاده التوازن العقدي بزياده او انقاص الالتزام', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='170a0369c11892e09d8692b22e337be389a9de74'), 'التعويض النقدي', 'd58c1e50abdba085284b7867c4c5883879519d02', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='170a0369c11892e09d8692b22e337be389a9de74'), 'إعادة الحال إلى ما كانت عليه بالمثل', 'd8ceaaec6a960d406c3fe2b5b7557fc06eefb929', 'اعاده الحال الي ما كانت عليه بالمثل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='170a0369c11892e09d8692b22e337be389a9de74'), 'التنفيذ عينًا عند تعذره', '6ad7cac02a9b36a1ea7bf56464e3f86e92f350b9', 'التنفيذ عينا عند تعذره', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6a912581db68c78421cc9180e9e7da70dbcb86d1'), 'دفع أجرة الأعمال المتفق عليها', 'ec51f0f0a39ef11e28c1f337784b2846c1fd959e', 'دفع اجره الاعمال المتفق عليها', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6a912581db68c78421cc9180e9e7da70dbcb86d1'), 'منع تسييل الضمان البنكي', '3850d46654097e29d5ccb23c33f5ba254032af47', 'منع تسييل الضمان البنكي', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6a912581db68c78421cc9180e9e7da70dbcb86d1'), 'الإفراج عن الضمان', 'cd4d5fbdb2ea4dcb36e6e48a67dbc81f0c4b49e2', 'الافراج عن الضمان', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6a912581db68c78421cc9180e9e7da70dbcb86d1'), 'التمكين من محل التعاقد', '16d0db69463848361ce55b32427bf68c59a6160b', 'التمكين من محل التعاقد', 4, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='6a912581db68c78421cc9180e9e7da70dbcb86d1'), 'رد مبلغ الضمان', 'bbc6f5f69b31aea551e935eaaed5c4b706c92ffc', 'رد مبلغ الضمان', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6a912581db68c78421cc9180e9e7da70dbcb86d1'), 'الإصلاح والصيانة', '987670a1a33041b12e3c65c8629782aefd28a856', 'الاصلاح والصيانه', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='6a912581db68c78421cc9180e9e7da70dbcb86d1'), 'تنفيذ العمل المتفق عليه', '4087ec17edf1c325b69ef3370774f59b9587f199', 'تنفيذ العمل المتفق عليه', 7, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='23ffd64b95858d31da6fa6cf29d6f45f75d335d3'), 'إثبات انفساخ العقد', '80b3ee9fa5a2743d2142449df532fd45f081ad55', 'اثبات انفساخ العقد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='23ffd64b95858d31da6fa6cf29d6f45f75d335d3'), 'فسخ العقد', 'd2627b776fd534832c00ab00d61b46783a94631c', 'فسخ العقد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='23ffd64b95858d31da6fa6cf29d6f45f75d335d3'), 'إبطال العقد', 'bab75dc953eb4140737a12ed4dae8856e626a762', 'ابطال العقد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='23ffd64b95858d31da6fa6cf29d6f45f75d335d3'), 'إثبات بطلان العقد', 'a80f81745f8008c8af908a2e31847fb82c06df49', 'اثبات بطلان العقد', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='23ffd64b95858d31da6fa6cf29d6f45f75d335d3'), 'رد المنفعة العائدة من تنفيذ العقد', '1ce1daddd4a34cff0a569e99882f4090ba5573ee', 'رد المنفعه العايده من تنفيذ العقد', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='08da190579b96446d61ede443d90cb782f15a0af'), 'التعويض النقدي', '86cfd47605b986e75ec113f3f16e8492ba9ad377', 'التعويض النقدي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='08da190579b96446d61ede443d90cb782f15a0af'), 'إعادة الحال إلى ما كانت عليه بالمثل', 'ce9e9be27efe5add97c81ca8d917f655aaff533b', 'اعاده الحال الي ما كانت عليه بالمثل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='08da190579b96446d61ede443d90cb782f15a0af'), 'التنفيذ عينًا عند تعذره', '0706bee7fde8123770a20862713197edb0e8750a', 'التنفيذ عينا عند تعذره', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='bf65948db4b17859bd9df2ecea2a0fc29076b1f0'), 'الإفصاح عن معلومات تنفيذ العقد وتقديم حساب عنه', 'd24edc3747320954a337ebca81c06a2827cf3461', 'الافصاح عن معلومات تنفيذ العقد وتقديم حساب عنه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='bf65948db4b17859bd9df2ecea2a0fc29076b1f0'), 'تحديد مقدار محل الالتزام', 'e6353af5cf311bf88f6d123203a3f503828370fa', 'تحديد مقدار محل الالتزام', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='bf65948db4b17859bd9df2ecea2a0fc29076b1f0'), 'أتعاب محاماة', '0e3ca9b73a1be1a767027a643ffbf9de28fca7db', 'اتعاب محاماه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='bf65948db4b17859bd9df2ecea2a0fc29076b1f0'), 'الالتزام بالتنفيذ العيني', 'a55cf3d8ff6877aed9763c9209504f647232b4a5', 'الالتزام بالتنفيذ العيني', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7ee2ee0cb3a7cf3f4ed4477ed8ee12de129ccaa2'), 'قبول الجزء الذي لم ينفذ', '59ed92794046099fd5d1c7fe55389a2c90147203', 'قبول الجزء الذي لم ينفذ', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='7ee2ee0cb3a7cf3f4ed4477ed8ee12de129ccaa2'), 'قبول السند وتنفيذه', 'd986bfc6481c8c0c1f8bee9a4b9acbc007e01ecf', 'قبول السند وتنفيذه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='33a573f8c395e3ec8d0b9b771e67f48d15d0b852'), 'إثبات ملاءمة', '3c5b6cf705ec1698b0e8c406b9ab13ba3c8403ab', 'اثبات ملاءمه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3ccf2e2188e78d8e6ad0aa783fd8a2fd0634dd5a'), 'إثبات إعسار', '22cc03bb4cc8aa1dbdceb46733f85c23752a5666', 'اثبات اعسار', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='a3dd49587de11942a019ab0011324d7752c08dca'), 'إثبات الإبراء', '7614dace23797525a01a46e4adc1c1fbbee6a9bf', 'اثبات الابراء', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='5c6f1ea3f99989c0086dd4f3839e6ba308eb0f62'), 'إثبات الحوالة', '4d2ad32ff3c2dc9ce501ca1d760f00d3484fe3d1', 'اثبات الحواله', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='c2f1fe6b449549931a18f12bed7f95bcabf3f46d'), 'إثبات الصلح', '3e3e0d4e6229eff5f58b265b21f396af8b928b73', 'اثبات الصلح', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='de38722183cc422c92449ce45c38817bff4fe426'), 'إثبات الوفاء', '984a5398bd50cac5f1555ff47274248091dfeea3', 'اثبات الوفاء', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='0aef6f3c5712440bfa9d023d99d47c955cc7d8d2'), 'إثبات التأجيل', 'c3a01da89c287310b69c78a9183f066e3255e954', 'اثبات التاجيل', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='18632065ea00bba5fe581b0d5b3f8df34b95196d'), 'إعادة ما تم دفعه ثمنًا للعين', '924dd9414ee49d4a9ba62196a9251c479a6bc3d3', 'اعاده ما تم دفعه ثمنا للعين', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='18632065ea00bba5fe581b0d5b3f8df34b95196d'), 'إعادة مبلغ السعي أثناء تواطؤ المزاد', '29441b1e0f0375287c80eae412ad84756cb2a749', 'اعاده مبلغ السعي اثناء تواطو المزاد', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='18632065ea00bba5fe581b0d5b3f8df34b95196d'), 'إبطال المبايعة', 'b8912b42a9ba8396a1dc8f5021ccac3fccad104f', 'ابطال المبايعه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='18632065ea00bba5fe581b0d5b3f8df34b95196d'), 'ارش النقص لعيب في العين المباعة', '95bd754f1699d57ce54940ee5f8777710796cbbd', 'ارش النقص لعيب في العين المباعه', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='18632065ea00bba5fe581b0d5b3f8df34b95196d'), 'إعادة فرق السعر', 'eb8acc9fe84d8f2f2101656f898d5944d00dc4b5', 'اعاده فرق السعر', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3176c5a7b07d80773c0da1e2d517e219da35b178'), 'إثبات انفساخ البيع', 'fee53645827e1e2d67bf7927564df1d67f9fd585', 'اثبات انفساخ البيع', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3176c5a7b07d80773c0da1e2d517e219da35b178'), 'إعادة ما دُفع ثمنًا للمبيع', '7bfc6fa183a21496b2ca6d97bf68b9423fb2507f', 'اعاده ما دفع ثمنا للمبيع', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3176c5a7b07d80773c0da1e2d517e219da35b178'), 'إعادة مبلغ العربون', '20132c0a483d4a4d9bebd98f51e98827651a4bef', 'اعاده مبلغ العربون', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3176c5a7b07d80773c0da1e2d517e219da35b178'), 'ارش النقص لعيب في العين', 'e4bdeca94bd1a7c1fde086cddb61e296d57b8f10', 'ارش النقص لعيب في العين', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3176c5a7b07d80773c0da1e2d517e219da35b178'), 'إعادة مبلغ السعي لعيب في العين', '534c923c472f88c3549e3d586dfdc0c2dbc3625e', 'اعاده مبلغ السعي لعيب في العين', 5, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='3176c5a7b07d80773c0da1e2d517e219da35b178'), 'فرق السعي لعيب في العين', '72ed3664ebad91bab206b776936f3fb838da76ba', 'فرق السعي لعيب في العين', 6, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='38a77ff593f132b11f4ff7c48199458457a471da'), 'تسليم العين محل التنفيذ', 'c03fa940726ddd213f90551b4d76d6426f5638d8', 'تسليم العين محل التنفيذ', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='38a77ff593f132b11f4ff7c48199458457a471da'), 'وقف التنفيذ', '242397b20c06198525a52318bf996daadfefb16e', 'وقف التنفيذ', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='38a77ff593f132b11f4ff7c48199458457a471da'), 'إلغاء أمر الإخلاء', '1514cd306e56be4a97006a0be22fca280ea39ed4', 'الغاء امر الاخلاء', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='466c69cc118cb79cb51055e7942fbb87d0dc2a66'), 'تعديل أجرة الحارس القضائي', '60c17d01bf7217ee18e70a6a2f65b74bea2cbb26', 'تعديل اجره الحارس القضايي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='466c69cc118cb79cb51055e7942fbb87d0dc2a66'), 'استبدال الحارس القضائي', 'cac84be9eec09c154e27617f8f6f5c895e4d1d81', 'استبدال الحارس القضايي', 2, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

INSERT INTO najiz_judicial_requests
    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)
VALUES
    ((SELECT id FROM najiz_case_types WHERE source_key='466c69cc118cb79cb51055e7942fbb87d0dc2a66'), 'محاسبة الحارس القضائي', '3adab51f12665a79a385eb34bcf50cc7252f6292', 'محاسبه الحارس القضايي', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b235fd2300000729f83ce1137c0edb60067bb5c0'), 'رفع الحجز عن المال الزائد', 'c263c02021ab2354240312d76e3cfdcbab93e979', 'رفع الحجز عن المال الزايد', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b99c9dc34b6590fb76fd54d1f2c44ca8294ace15'), 'إجراء المقاصة', 'da3decf4551bfb47714ae7c87df37bbc82985cd6', 'اجراء المقاصه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='92be4f5e70f134c43cf4b70c69e9fae3f95a53d7'), 'رد المستوفى خطأً', 'b999edc8659ab68260302b993a34de9e7c4c05ed', 'رد المستوفي خطا', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='62d9057d8dd4e9c13e70e0bdb179d5c8b7a4ffe8'), 'تصحيح التقرير', '6f497862596497f7490589de2e171480703f02f4', 'تصحيح التقرير', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e402b1809cd034560b8c522000a6a36acaed703d'), 'التعويض', '8419dad823851cc35a66b68587e994ca222df15d', 'التعويض', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='16bf7f07d8cf8571205d3ce591a70a2ca2d12261'), 'اعتراض على محضر', '44e6fa5cd9112b4322674e3fa51991ca04e26832', 'اعتراض علي محضر', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='19c4a0e13d5039fd28bce0586770323f75c09098'), 'التعويض عن أضرار التقاضي التنفيذي', 'a562ef4782dd3bb5bddf10c777067478cb8ea49d', 'التعويض عن اضرار التقاضي التنفيذي', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b9c11a37bf3bbc03177aba06de825f05f9208cfb'), 'أتعاب محامي أو وكلاء في قضية', 'a011f2ea72582ad6ac52698b1e501c24570ae58b', 'اتعاب محامي او وكلاء في قضيه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b9c11a37bf3bbc03177aba06de825f05f9208cfb'), 'استرداد المبالغ المسلمة للمحامي أو الوكيل', 'c49b0a52716c919a35418f8e5f7d3f4fe58a2efc', 'استرداد المبالغ المسلمه للمحامي او الوكيل', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d585e4175df13021b839ae72c6934b1e52387ff8'), 'إقامة ولاية على قاصر عقلًا', '590690df5f4bc58430243fb5e4d19c58563d7b88', 'اقامه ولايه علي قاصر عقلا', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d585e4175df13021b839ae72c6934b1e52387ff8'), 'استمرار ولاية على قاصر عقلًا', 'f0510c14444e93faa8c4f132fb97e143f24f019d', 'استمرار ولايه علي قاصر عقلا', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d585e4175df13021b839ae72c6934b1e52387ff8'), 'فسخ الولاية لموت الولي أو بطلب منه', '5852b18e11a74da4ab292ff26e83dad8650c3700', 'فسخ الولايه لموت الولي او بطلب منه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d585e4175df13021b839ae72c6934b1e52387ff8'), 'تقدير نفقة قاصر', 'ef813da3288d7be79096afa681a2ef1ae12baed6', 'تقدير نفقه قاصر', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='13dcd716e2bac2b4d23f730ea4956bbf841c9851'), 'فسخ ولاية على مال مفقود', '10b9e95668ebae9a7e243b1910de30ac51bae7e2', 'فسخ ولايه علي مال مفقود', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='13dcd716e2bac2b4d23f730ea4956bbf841c9851'), 'إقامة ولاية على مال مفقود', 'c9d269b09d021fd2d5f3520fc45d23c9e575ba0e', 'اقامه ولايه علي مال مفقود', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='33df626afe42345fdf8c8bb1ee525ad0365e05d1'), 'ولاية ورعاية وحضانة يتيم أو ذوي احتياجات خاصة', 'bebc0534cb05705f3cb123b94b314e662ce85c37', 'ولايه ورعايه وحضانه يتيم او ذوي احتياجات خاصه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='20f0b751b873cf45ab89db695d7ff70a63fa3b54'), 'إثبات انقطاع الأولياء عن المرأة لغرض الزواج', '34f9c94cba304ed5d2400665caba61ceac7f428a', 'اثبات انقطاع الاولياء عن المراه لغرض الزواج', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='20f0b751b873cf45ab89db695d7ff70a63fa3b54'), 'الموافقة على الزواج المبكر', '60673ebb0e85befa912f56e444f7564ddec4d16b', 'الموافقه علي الزواج المبكر', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e65c2e73a5401734c57f90bb33eb163885dd1d45'), 'تنازل بعوض', '0cd33550ed62d805b0ddadd126a780e2b8d79242', 'تنازل بعوض', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e65c2e73a5401734c57f90bb33eb163885dd1d45'), 'تنازل بغير عوض', '23957fbe40224ebd404bb768f38d8003c57f7a0e', 'تنازل بغير عوض', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='e65c2e73a5401734c57f90bb33eb163885dd1d45'), 'تنازل عن دية القتل غير العمد وشبه العمد', 'ba78b8b08ad721756a617e7e863d462575c7e76c', 'تنازل عن ديه القتل غير العمد وشبه العمد', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2673ebe848038127f3bcc6a45fe189466bb84c1c'), 'إقامة ناظر على وقف', '0c95d410b009e8a617d9e169a252175eaab572b7', 'اقامه ناظر علي وقف', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2673ebe848038127f3bcc6a45fe189466bb84c1c'), 'إقامة مشرف على وقف أو وصية', '2747f14d86334b2373939f6ad2ff695a1ea9837f', 'اقامه مشرف علي وقف او وصيه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2673ebe848038127f3bcc6a45fe189466bb84c1c'), 'استقالة ناظر على وقف', 'a8e07557da06acd532e17d2d226e6d5a406ce9e5', 'استقاله ناظر علي وقف', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='2673ebe848038127f3bcc6a45fe189466bb84c1c'), 'تفويض لمراجعة جهة مختصة', 'ca0de8458d8bb5bf1dcf072a4e921e5771466f12', 'تفويض لمراجعه جهه مختصه', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b085a1248cc181d047daddd935c82be37d5248e0'), 'فسخ عقد الاستثمار المأذون به', '15ba738859647629b231d5659295ea1f1e73057f', 'فسخ عقد الاستثمار الماذون به', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='b085a1248cc181d047daddd935c82be37d5248e0'), 'تعديل عقد الاستثمار المأذون به', 'b7d5b26c04db29b892c65cb851810ddf0dfda3c6', 'تعديل عقد الاستثمار الماذون به', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9af2774d575dc9e804929ce79c3da9ae53290904'), 'إثبات زواج سابق', 'ae9334ba6921a275968fab92bafbf457225771f9', 'اثبات زواج سابق', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9af2774d575dc9e804929ce79c3da9ae53290904'), 'إثبات زواج أحد الزوجين غير سعودي', 'db3e47bf6a4e3baa02640eabd8b989ab76894f8a', 'اثبات زواج احد الزوجين غير سعودي', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9af2774d575dc9e804929ce79c3da9ae53290904'), 'الموافقة على الزواج المبكر', '5f08c86f145c88792446f9e69b87db0ff68b0f7d', 'الموافقه علي الزواج المبكر', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='9af2774d575dc9e804929ce79c3da9ae53290904'), 'إنشاء عقد زواج', 'd5e0b350ddb229a1cf52e811209b816e2697e8a6', 'انشاء عقد زواج', 4, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='8ea7223f11cbfede5272e5c4e3fa5d891bbbd042'), 'إثبات خلع', '90aa0ab78f4bb0ce469b9429c92d9b14348c79f3', 'اثبات خلع', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='8ea7223f11cbfede5272e5c4e3fa5d891bbbd042'), 'إثبات طلاق', '630a928578d1549b3f5509912d35f9c03fb9840e', 'اثبات طلاق', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='8ea7223f11cbfede5272e5c4e3fa5d891bbbd042'), 'إثبات رجعة', 'e684b7f5cb6b59f95a3bebdbbccb462c9a48339c', 'اثبات رجعه', 3, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ee90b0c66988f5cb401b2919bef871da545db216'), 'إثبات وقف', '62e5804f32fda976c69bf090f6ef091a097809a2', 'اثبات وقف', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ee90b0c66988f5cb401b2919bef871da545db216'), 'إثبات وصية', 'e7fe3b3214e6287ce4c19eb9dd1c072055bc974c', 'اثبات وصيه', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d07cc84777c53911232d08baeff64bd568f56581'), 'إثبات ورثة', 'e2aaa532ffbb7cf1c9395178e84bb7cb34534098', 'اثبات ورثه', 1, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='d07cc84777c53911232d08baeff64bd568f56581'), 'قسمة تركة بالتراضي', 'f84b6366459f6ccb152c4f686dd90319f5671293', 'قسمه تركه بالتراضي', 2, 1),
    ((SELECT id FROM najiz_case_types WHERE source_key='ae39a128c3b871f01242824898b356d540afd7c5'), 'إثبات الحضانة', '1c1d8b9ca84aa7a99d743c1acb0bb4f83d44eb57', 'اثبات الحضانه', 1, 1)
ON DUPLICATE KEY UPDATE
    case_type_id = VALUES(case_type_id),
    name_ar      = VALUES(name_ar),
    search_norm  = VALUES(search_norm),
    sort_order   = VALUES(sort_order),
    is_active    = 1;

-- ----------------------------------------------------------------------------
-- 5) Result. Read-only: what this file produced, so the outcome is seen rather
--    than assumed. Compare against the expected counts above.
-- ----------------------------------------------------------------------------
SELECT 'categories'          AS kind, COUNT(*) AS rows_now, 7 AS expected FROM najiz_categories
UNION ALL SELECT 'subcategories',      COUNT(*), 38 FROM najiz_subcategories
UNION ALL SELECT 'case types',         COUNT(*), 234 FROM najiz_case_types
UNION ALL SELECT 'judicial requests',  COUNT(*), 640 FROM najiz_judicial_requests;

-- If a count is lower than expected, re-running this file is safe and will
-- complete the remainder. Nothing is ever deleted by doing so.
