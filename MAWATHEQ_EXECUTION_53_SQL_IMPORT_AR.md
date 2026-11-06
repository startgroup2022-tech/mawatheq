# مواثيق — استيراد التصنيف القضائي السعودي (النجيز)
# MAWATHEQ — Saudi Najiz legal taxonomy import

**التنفيذ / Execution:** 53
**الملف / File:** `MAWATHEQ_TAXONOMY_IMPORT.sql`
**المسار في المستودع / Repository path:** `MAWATHEQ_TAXONOMY_IMPORT.sql` (جذر المستودع)
**الحجم / Size:** 240,330 بايت
**بصمة SHA-256 / SHA-256:** `785b769436c7aa4f0d95015ecc39d719496a0dea0df051f5fda7cca1b1b3f17a`

> ⚠️ **هذا الدليل جاهز للتنفيذ. لا تُعدّل قاعدة بيانات الإنتاج دون إذن المالك.**
> لا يوجد في هذا التنفيذ أي اتصال بقاعدة بيانات الإنتاج. الاستيراد خطوة منفصلة
> يُنفّذها المالك أو من يفوضه.

---

## 1. ما هو هذا الملف؟ / What this file is

ملف **بيانات فقط (data only)**. لا يُنشئ جدولًا ولا يُعدّل بنية قاعدة البيانات.

يحتوي التصنيف القضائي السعودي الكامل المستخرج من ملف النجيز الرسمي:

| المستوى | العدد |
|---|---|
| التخصصات الرئيسية (main specialties) | **7** |
| التخصصات الفرعية (sub-specialties) | **38** |
| أنواع الدعاوى (case types) | **234** |
| الطلبات القضائية (judicial requests) | **640** |

المصدر الأصلي: `database/sources/Pasted%20text%283%29.txt`
(125,912 بايت — بصمة `f6f5233153aac35684d9b6adae1fa98d7c5e8c07824d0dc5169f1ab13917b269`)

الملف يقوم بالتالي داخل **معاملة واحدة** (`START TRANSACTION` … `COMMIT`):

1. **فحص المتطلبات المسبقة** — إن كانت جداول `najiz_*` غير موجودة يتوقف فورًا
   برسالة عربية واضحة بدل إفساد البيانات.
2. **حذف صفوف التصنيف القديمة** من الجداول الأربعة.
3. **إعادة الإدخال** بكل الصفوف وأرقامها الأصلية (`id` ثابت).
4. **فحص النتيجة** — إن لم تكن الأعداد 7/38/234/640 تُلغى العملية بالكامل
   (`SIGNAL SQLSTATE '45000'`).

أي خطأ في أي خطوة يعني `ROLLBACK` تلقائي — قاعدة البيانات تعود كما كانت تمامًا.

---

## 2. تحذيرات إلزامية / Mandatory warnings

> ⚠️ **لا تُشغّل `database/schema.sql` على قاعدة بيانات قائمة.**
> هذا الملف للتركيب الجديد فقط وقد يُفقد بياناتك. على قاعدة قائمة استخدم ملفات
> الترقية `database/upgrade_v*.sql` فقط.

> ⚠️ **لا تُشغّل أي شيء قبل أخذ نسخة احتياطية.** انظر القسم 4.

> ⚠️ **لا تشغّل الملف قبل ترقيات المخطط.** انظر القسم 3 بالترتيب الدقيق.

---

## 3. المتطلبات المسبقة وترتيب التنفيذ / Prerequisites and exact order

**الترتيب مهم، وليس اختياريًا.** الخطوة 4 (`v63` مرة ثانية) ضرورية:

`upgrade_v63.sql` يشتقّ الوصف العربي وأيقونة العميل (`customer_desc_ar`,
`customer_icon_key`) **من الصفوف الموجودة وقت تشغيله**. على قاعدة أنشأ فيها
`v59` الجداول فارغة، التشغيل الأول لـ `v63` لا يكتب شيئًا. إعادة تشغيله بعد
الاستيراد تملأ الأوصاف والأيقونات السبع كاملة.

| # | الخطوة | الملف | الغرض |
|---|---|---|---|
| 1 | إنشاء جداول `najiz_*` | `database/upgrade_v59.sql` | الجداول الأربعة |
| 2 | إضافة أعمدة العميل | `database/upgrade_v63.sql` | `customer_desc_ar` / `customer_icon_key` |
| 3 | **استيراد التصنيف** | `MAWATHEQ_TAXONOMY_IMPORT.sql` | 7/38/234/640 |
| 4 | **إعادة تشغيل** | `database/upgrade_v63.sql` | ملء الأوصاف والأيقونات |

الترقيات الأخرى المطلوبة على قاعدة قائمة (إن لم تكن مُطبَّقة):
`upgrade_v42.sql`, `upgrade_v47.sql`, `upgrade_v60.sql`, `upgrade_v61.sql`,
`upgrade_v62.sql`, `upgrade_v64.sql`, `upgrade_v65.sql`.

**تحقّق من الترقيات المطبَّقة** (شغّل على قاعدة الإنتاج قراءة فقط):

```sql
-- هل جداول التصنيف موجودة؟
SELECT table_name FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name LIKE 'najiz\_%';

-- هل أعمدة العميل موجودة (من v63)؟
SELECT table_name, column_name FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'najiz_categories'
  AND column_name IN ('customer_desc_ar','customer_icon_key');
```

إن كانت القائمة فارغة فطبّق `upgrade_v59.sql` أولًا، ثم `upgrade_v63.sql`.

---

## 4. النسخة الاحتياطية / Backup procedure

**لا تتجاوز هذه الخطوة.** الاستيراد يحذف صفوف التصنيف الحالية ويعيد إدخالها.

### 4.1 نسخة احتياطية للجداول الأربعة فقط (سريعة — كافية)

```bash
mysqldump -u USER -p DBNAME \
  najiz_categories najiz_subcategories \
  najiz_case_types najiz_judicial_requests \
  > taxonomy_backup_$(date +%F).sql
```

### 4.2 نسخة احتياطية كاملة لقاعدة البيانات (مفضّلة قبل أي ترقية مخطط)

```bash
mysqldump -u USER -p --single-transaction --routines --triggers DBNAME \
  > mawatheq_full_$(date +%F_%H%M).sql
```

### 4.3 على استضافة cPanel (بدون سطر أوامر)

`cPanel → Backup → Download a MySQL Database Backup` ثم اختر قاعدة البيانات.
أو `phpMyAdmin → Export → Quick → Go`.

**تحقّق من النسخة قبل المتابعة:** يجب أن يكون حجمها أكبر من صفر، وأن تحتوي
`CREATE TABLE najiz_categories`.

---

## 5. التنفيذ عبر phpMyAdmin / Running it in phpMyAdmin

1. سجّل الدخول إلى **cPanel → phpMyAdmin**.
2. اختر قاعدة البيانات من القائمة اليسرى (اسمها مطابق لـ `DB_NAME` في
   `config/secrets.php`).
3. افتح تبويب **Import**.
4. **Choose File** → اختر `MAWATHEQ_TAXONOMY_IMPORT.sql`.
5. تأكد أن **Character set of the file = `utf-8`**.
6. **لا** تفعّل "Enable foreign key checks" (الملف يعطّلها ويشغّلها بنفسه).
7. اضغط **Import**.

### التنفيذ عبر سطر الأوامر (أسرع وأوضح)

```bash
mysql -u USER -p DBNAME < MAWATHEQ_TAXONOMY_IMPORT.sql
```

### كيف تعرف أن الملف لم يُنفَّذ؟

- **نجاح:** يظهر جدول نتيجة بالعنوان
  `main_specialties | sub_specialties | case_types | judicial_requests`
  وقيمته `7 | 38 | 234 | 640`.
- **فشل المتطلبات:** `ERROR 1644 (45000) … PREREQUISITE FAILED: run upgrade_v59.sql first.`
  → طبّق `upgrade_v59.sql` ثم أعد المحاولة. **لم يتغيّر أي شيء** في قاعدة البيانات.
- **فشل التحقق:** `ERROR 1644 (45000) … VERIFY FAILED: expected 7/38/234/640`.
  → العملية أُلغيت بالكامل. لا تقبل نتيجة جزئية؛ أعد التنفيذ.
- **خطأ في الترميز (????):** أعد الاستيراد مع ضبط الترميز على `utf-8`.

### بعد الاستيراد — الخطوة 4 الإلزامية

```bash
mysql -u USER -p DBNAME < database/upgrade_v63.sql
```

---

## 6. استعلامات التحقق / Verification queries

شغّل هذه الاستعلامات بعد الخطوة 4. **النتيجة المتوقعة مكتوبة بجانب كل سؤال.**

```sql
-- 1) الأعداد الأساسية — يجب 7 / 38 / 234 / 640
SELECT
  (SELECT COUNT(*) FROM najiz_categories)         AS main_specialties,   -- 7
  (SELECT COUNT(*) FROM najiz_subcategories)      AS sub_specialties,    -- 38
  (SELECT COUNT(*) FROM najiz_case_types)         AS case_types,         -- 234
  (SELECT COUNT(*) FROM najiz_judicial_requests)  AS judicial_requests;  -- 640
```

```sql
-- 2) هل مُلئت أوصاف وأيقونات العميل؟ — يجب 7 / 7
SELECT
  SUM(customer_desc_ar  IS NOT NULL) AS with_description,   -- 7
  SUM(customer_icon_key IS NOT NULL) AS with_icon           -- 7
FROM najiz_categories;
```

```sql
-- 3) كل تخصص فرعي مرتبط بتخصص رئيسي موجود؟ — يجب 0
SELECT COUNT(*) AS orphans
FROM najiz_subcategories s
LEFT JOIN najiz_categories c ON c.id = s.category_id
WHERE c.id IS NULL;
```

```sql
-- 4) كل نوع دعوى مرتبط بتخصص فرعي موجود؟ — يجب 0
SELECT COUNT(*) AS orphans
FROM najiz_case_types t
LEFT JOIN najiz_subcategories s ON s.id = t.subcategory_id
WHERE s.id IS NULL;
```

```sql
-- 5) القائمة التي يراها العميل (7 صفوف، بلا أي رقم تصنيف داخلي)
SELECT id, name_ar, customer_desc_ar, customer_icon_key
FROM najiz_categories
WHERE is_active = 1
ORDER BY sort_order, id;
```

```sql
-- 6) فحص نهائي شامل — يجب أن تكون كل الأعمدة 0
SELECT
  (SELECT COUNT(*) FROM najiz_categories) = 7         AS cats_ok,
  (SELECT COUNT(*) FROM najiz_subcategories) = 38     AS subs_ok,
  (SELECT COUNT(*) FROM najiz_case_types) = 234       AS types_ok,
  (SELECT COUNT(*) FROM najiz_judicial_requests) = 640 AS reqs_ok,
  (SELECT COUNT(*) FROM najiz_categories
     WHERE customer_desc_ar IS NULL) = 0              AS descs_ok;
```

---

## 7. التحقق من الواجهة / Verifying the UI afterwards

بعد نجاح الاستيراد، يجب أن تعرض الواجهات التالية التصنيفات:

| الواجهة | الرابط | المتوقع |
|---|---|---|
| الموقع — دليل التصنيف | `/classification-guide.php` | الشجرة كاملة (7/38/234) |
| الموقع — مسار العميل | `/consultation-journey.php` | 7 بطاقات تخصص بالوصف |
| واجهة الجوال | `GET /api/v1/journey.php?action=specialties` | 7 تخصصات مع الوصف والأيقونة |
| واجهة الجوال (الرئيسية) | قسم **اختر تخصصك القانوني** | 7 بطاقات |

فحص سريع من سطر الأوامر:

```bash
curl -s "https://mawatheq.com/api/v1/journey.php?action=specialties" \
  | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d['specialties']),'specialties')"
# المتوقع: 7 specialties
```

> ملاحظة: `lawyer_count` قد يكون `0` — وهذا **صحيح وليس خطأ**. جدول
> `lawyer_specialties` يُنشأ فارغًا عن قصد، وربط محامٍ بتخصص إجراء يقوم به
> المالك. راجع `/lawyer/specialties.php` لربط المحامين.

---

## 8. الخصائص الآمنة / Safety properties

| الخاصية | الحالة |
|---|---|
| بيانات فقط (لا `CREATE` / `ALTER` / `DROP`) | ✅ |
| معاملة واحدة — فشل = تراجع كامل | ✅ |
| فحص متطلبات مسبقة برسالة عربية | ✅ |
| فحص نتيجة بعد الاستيراد (7/38/234/640) | ✅ |
| إعادة التشغيل آمنة (idempotent) | ✅ |
| `id` ثابت — لا يعيد توجيه القضايا القائمة | ✅ |
| يعمل على قاعدة فيها بيانات قضايا | ✅ |
| لا يُعدّل `cases` / `consultations` / أي جدول عميل | ✅ |

تم اختبار الملف فعليًا على قاعدة بيانات اختبارية: استيراد نظيف (7/38/234/640)،
ثم إعادة تشغيل بلا أي تغيير، ثم فشل آمن عند غياب المتطلبات المسبقة.
