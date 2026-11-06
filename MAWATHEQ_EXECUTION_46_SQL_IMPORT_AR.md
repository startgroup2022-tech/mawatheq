# مواثيق — دليل استيراد التصنيف القانوني (التخصصات والمطالبات القضائية)

**الملف:** `MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql`
**المسار في المستودع:** `database/MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql`
**الحجم:** 292,050 بايت
**SHA-256:** `10b09093e2ab3f4242efef5e00bb9105ddbf86bcd83df3d755b76816f1468134`

---

## 1. ما هذا الملف؟

التصنيف القضائي السعودي الكامل كملف SQL جاهز للاستيراد من phpMyAdmin، بلا سطر أوامر وبلا PHP:

| المستوى | العدد |
|---|---|
| التصنيفات الرئيسية (التخصصات) | **7** |
| التصنيفات الفرعية | **38** |
| أنواع الدعاوى | **234** |
| المطالبات القضائية | **640** |

هذه هي نفس الصفوف التي يكتبها مستورد لوحة التحكم بالضبط — كلاهما يستدعي نفس المحلّل ونفس دوال
المفاتيح والتطبيع، فالاستيراد من أي من المسارين لا ينتج عنه تكرار.

---

## 2. حالة الإنتاج الحالية (تم التحقق منها في 2026-10-23)

الإنتاج يعمل على `https://mawatheq.com` **والتصنيف موجود بالفعل عليه**. الفحص المباشر يُظهر:

```
GET /api/v1/journey.php?action=specialties   → 200، سبعة تخصصات بأسماء ووصف
GET /api/v1/journey.php?action=issue-types&category_id=4 → 200، مطالبات قضائية حقيقية
```

**لا حاجة إلى استيراد هذا الملف على الإنتاج.** احتفظ به كأداة تعافٍ: إن فُقدت البيانات أو
نُقل الموقع إلى خادم جديد، هذا هو الملف الذي يعيد بناءها.

> **تنبيه:** الملف `database/schema.sql` يُنشئ قاعدة بيانات كاملة من الصفر. **لا تستورده أبدًا على
> قاعدة بيانات إنتاج قائمة** — سيحاول إنشاء جداول موجودة ولن يعيد لك بياناتك.

---

## 3. قبل الاستيراد — ثلاث خطوات إلزامية

### 3.1 خذ نسخة احتياطية (لا تتخطَّ هذه الخطوة)

من phpMyAdmin:

1. اختر قاعدة البيانات من القائمة اليسرى.
2. تبويب **Export**.
3. اختر **Custom** ← **SQL** ← تأكد من تحديد كل الجداول.
4. فعّل **Add DROP TABLE / VIEW / PROCEDURE / FUNCTION / EVENT**.
5. اضغط **Export** واحفظ الملف على جهازك.

أو من سطر الأوامر على الخادم:

```bash
mysqldump -u USER -p DBNAME > backup_$(date +%F_%H%M).sql
```

### 3.2 تحقّق من الشروط المسبقة

نفّذ هذا الاستعلام في تبويب **SQL** داخل phpMyAdmin. يجب أن يعيد **سبعة صفوف**، كلها بحالة
`EXISTS`:

```sql
SELECT 'najiz_categories' AS table_name,
       IF(COUNT(*) > 0, 'EXISTS', 'MISSING') AS status
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'najiz_categories'
UNION ALL SELECT 'najiz_subcategories',
       IF(COUNT(*) > 0, 'EXISTS', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'najiz_subcategories'
UNION ALL SELECT 'najiz_case_types',
       IF(COUNT(*) > 0, 'EXISTS', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'najiz_case_types'
UNION ALL SELECT 'najiz_judicial_requests',
       IF(COUNT(*) > 0, 'EXISTS', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'najiz_judicial_requests'
UNION ALL SELECT 'najiz_search_synonyms',
       IF(COUNT(*) > 0, 'EXISTS', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'najiz_search_synonyms'
UNION ALL SELECT 'najiz_import_runs',
       IF(COUNT(*) > 0, 'EXISTS', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'najiz_import_runs'
UNION ALL SELECT 'najiz_audit_log',
       IF(COUNT(*) > 0, 'EXISTS', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'najiz_audit_log';
```

إن ظهر أي صف بحالة `MISSING`، **لا تستورد ملف التصنيف**. طبّق أولاً، وبالترتيب:

```
database/upgrade_v59.sql     ← ينشئ جداول التصنيف
database/upgrade_v63.sql     ← يضيف أعمدة واجهة العميل (الوصف والأيقونة)
```

### 3.3 ترتيب مهم: v63 قبل الاستيراد لا تكفي

`upgrade_v63.sql` يشتق `customer_desc_ar` و`customer_icon_key` **من الصفوف الموجودة** في
`najiz_categories`. على قاعدة بيانات أنشأ فيها `v59` الجدول للتو، الجدول فارغ، فالمرور الأول
لا يكتب شيئًا.

**الترتيب الذي يعمل:**

```
v59  →  v63  →  استيراد التصنيف  →  v63 مرة ثانية
```

المرور الثاني لـ `v63` آمن (idempotent) ويملأ الأوصاف والأيقونات للتخصصات السبعة. بدونه تظهر
بطاقات التخصصات بأسماء صحيحة **وبلا وصف**.

---

## 4. خطوات الاستيراد من phpMyAdmin

1. افتح phpMyAdmin واختر قاعدة بياناتك من القائمة اليسرى.
2. اضغط تبويب **Import**.
3. **Choose File** ← اختر `MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql`.
4. تأكد أن **Character set of the file** = `utf-8`.
5. تأكد أن **Format** = `SQL`.
6. اضغط **Go**.

الملف **إضافي (additive) وآمن للتكرار**: كل صف يُطابَق بمفتاح ثابت، فالمرور الثاني لا يُدرج شيئًا
ولا يُنشئ تكرارًا، ومعرّفات `najiz_case_types.id` تبقى كما هي — أي قضية أو استشارة تشير إليها
لا تُعاد توجيهها.

إن استغرقت العملية أكثر من مهلة phpMyAdmin، استورد من سطر الأوامر بدلاً منها:

```bash
mysql -u USER -p DBNAME < database/MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql
```

---

## 5. استعلامات التحقق بعد الاستيراد

يجب أن تعيد الأعداد التالية **بالضبط**:

```sql
SELECT 'categories'         AS level, COUNT(*) AS rows_now, 7   AS expected FROM najiz_categories
UNION ALL SELECT 'subcategories',     COUNT(*), 38  FROM najiz_subcategories
UNION ALL SELECT 'case types',        COUNT(*), 234 FROM najiz_case_types
UNION ALL SELECT 'judicial requests', COUNT(*), 640 FROM najiz_judicial_requests;
```

تحقّق من اكتمال أعمدة واجهة العميل (يجب أن يكون `missing_descriptions` = 0):

```sql
SELECT COUNT(*) AS total,
       SUM(customer_desc_ar IS NULL OR customer_desc_ar = '') AS missing_descriptions
  FROM najiz_categories
 WHERE is_active = 1;
```

تحقّق من أن سجل الاستيراد يحتفظ ببصمة الملف المصدر:

```sql
SELECT id, source_sha256, rows_categories, rows_case_types, created_at
  FROM najiz_import_runs
 ORDER BY id DESC
 LIMIT 3;
```

---

## 6. العلاقة بتطبيق الجوال

بعد الاستيراد يقرأ التطبيق التصنيف من نفس نقطة النهاية التي يقرأها الموقع:

```
GET /api/v1/journey.php?action=specialties
GET /api/v1/journey.php?action=issue-types&category_id=N
GET /api/v1/journey.php?action=lawyers&category_id=N
```

**ملاحظة مهمة عن ظهور المحامين:** جدول `lawyer_specialties` يُنشأ **فارغًا عن قصد** في
`upgrade_v63.sql` — ربط محامٍ بتخصص إجراء يقوم به المشغّل. لذلك:

* المحامي المعتمد الذي اختار تخصصه من القائمة المنسدلة عند التسجيل **يظهر الآن تلقائيًا** في
  رحلة العميل (هذا ما أُصلح في التنفيذ 46).
* المحامي الذي لا تخصص له في القائمة يبقى خارج الرحلة حتى يضبط تخصصاته من
  `/lawyer/specialties.php`.

تحقّق مباشر من ظهور محامٍ في تخصصه:

```
GET /api/v1/journey.php?action=lawyers&category_id=4
```

يجب أن يعيد `"matched": true` وقائمة المحامين المعتمدين في ذلك التخصص.
