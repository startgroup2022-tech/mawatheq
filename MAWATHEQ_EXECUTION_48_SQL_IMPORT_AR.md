# مواثيق — التصنيف القانوني: ملف SQL وتعليمات الاستيراد الآمنة

**Execution 48 — ملحق التسليم**
**التاريخ:** 2026-10-23

---

## 1. الملف المطلوب بالضبط

| البند | القيمة |
|---|---|
| اسم الملف | `MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql` |
| المسار في المستودع | `database/MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql` |
| الحجم | 292,050 بايت (1,304 سطر) |
| الترميز | utf8mb4 |
| SHA-256 | `10b09093e2ab3f4242efef5e00bb9105ddbf86bcd83df3d755b76816f1468134` |

تحقق من الملف الذي بين يديك قبل الاستيراد:

```bash
sha256sum MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql
# يجب أن يطابق القيمة أعلاه حرفًا بحرف
```

هذا هو **الملف الوحيد** الذي يحمل التصنيف القضائي السعودي كاملًا. وهو مولَّد من
`database/sources/Pasted text(3).txt` بواسطة `tools/generate_legal_import_sql.php`، الذي
يستدعي نفس المحلّل ونفس دوال المفاتيح والتطبيع التي يستدعيها مستورد لوحة التحكم — لذا
فالمسارَان يكتبان الصفوف نفسها بالضبط، وإعادة الاستيراد من أي منهما لا تُنشئ تكرارًا.

---

## 2. حالة الإنتاج — تم التحقق منها مباشرة (2026-10-23)

**التصنيف موجود على الإنتاج بالفعل.** فحص مباشر للـ API الحي من هذه الجلسة:

```
GET https://mawatheq.com/api/najiz_classify.php?action=stats
→ {"ok":true,"stats":{"categories":7,"subcategories":38,"lawsuit_types":234,"judicial_requests":640}}

GET https://mawatheq.com/api/v1/journey.php?action=specialties
→ 200، سبعة تخصصات بأسمائها ووصفها وأيقوناتها

GET https://mawatheq.com/api/v1/banners.php
→ 200
```

**لا حاجة إلى استيراد هذا الملف على الإنتاج الآن.** الملف هو أداة تعافٍ: إن فُقدت
البيانات، أو نُقل الموقع إلى خادم جديد، أو أُنشئت قاعدة بيانات من الصفر — هذا هو الملف
الذي يعيد بناء التصنيف.

> **لا تُعدّل قاعدة بيانات الإنتاج دون إذن المالك.** كل ما في هذا المستند تعليمات؛
> التنفيذ على الإنتاج قرار المالك.

---

## 3. المتطلبات المسبقة

يجب أن يكون **كل** ما يلي متحققًا قبل الاستيراد:

| # | المتطلب | كيف تتحقق |
|---|---|---|
| 1 | جداول `najiz_*` السبعة موجودة | القسم 3.1 أدناه |
| 2 | عمودا `customer_desc_ar` و`customer_icon_key` في `najiz_categories` | القسم 3.1 |
| 3 | عمود `schema_migrations.statements_ok` موجود | القسم 3.1 |
| 4 | نسخة احتياطية كاملة من قاعدة البيانات | القسم 4 |

الملف **يرفض نفسه** إن غاب أي متطلب: يرفع خطأ واضحًا
`PLEASE RUN MAWATHEQ_DB_SCHEMA_FIX.sql FIRST` ويتوقف **قبل** كتابة أي صف. هذا سلوك
مقصود — استيراد نصف مكتمل أسوأ من استيراد مرفوض.

### 3.1 فحص المتطلبات (استعلام واحد)

نفّذه في phpMyAdmin ← تبويب **SQL**، بعد اختيار قاعدة البيانات:

```sql
SELECT 'najiz tables' AS check_name,
       COUNT(*) AS found, 7 AS expected
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('najiz_categories','najiz_subcategories','najiz_case_types',
                     'najiz_judicial_requests','najiz_search_synonyms',
                     'najiz_import_runs','najiz_audit_log')
UNION ALL
SELECT 'customer columns', COUNT(*), 2
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'najiz_categories'
  AND COLUMN_NAME IN ('customer_desc_ar','customer_icon_key')
UNION ALL
SELECT 'schema_migrations.statements_ok', COUNT(*), 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'
  AND COLUMN_NAME = 'statements_ok';
```

**النتيجة المطلوبة:** كل صف `found` = `expected`. إن نقص أي رقم، نفّذ
`MAWATHEQ_DB_SCHEMA_FIX.sql` أولًا (القسم 5) ثم أعد هذا الفحص.

---

## 4. النسخ الاحتياطي (إلزامي قبل أي استيراد)

**من phpMyAdmin:**

1. اختر قاعدة البيانات من القائمة اليسرى.
2. تبويب **Export**.
3. طريقة التصدير: **Quick**، الصيغة: **SQL**.
4. اضغط **Go** — ينزل ملف `.sql` كامل. احفظه في مكان آمن.

**من سطر الأوامر (cPanel SSH):**

```bash
mysqldump -u USER -p --single-transaction --routines --triggers DBNAME > backup_$(date +%F_%H%M).sql
```

تحقق أن الملف غير فارغ وأن حجمه معقول قبل المتابعة:

```bash
ls -lh backup_*.sql
```

> لا تكمل إن فشل النسخ الاحتياطي. هذا هو خط الرجوع الوحيد.

---

## 5. ترتيب التنفيذ الصحيح

الملفان يعملان بهذا الترتيب، ولا يعملان معكوسَين:

```
1) MAWATHEQ_DB_SCHEMA_FIX.sql              (إصلاح المخطط — آمن، قابل لإعادة التنفيذ)
2) MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql   (التصنيف القانوني)
3) MAWATHEQ_DB_VERIFY.sql                  (التحقق)
```

### 5.1 من phpMyAdmin

1. اختر قاعدة البيانات في العمود الأيسر (مثلًا `mawatheq_thaer`).
2. تبويب **Import**.
3. **Choose File** ← اختر `MAWATHEQ_DB_SCHEMA_FIX.sql`.
4. الترميز: اتركه **utf-8**.
5. اضغط **Go**. انتظر رسالة النجاح.
6. أعد الخطوات 2–5 مع `MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql`.

> إن ظهر `#1054 - Unknown column 'statements_ok'` فهذا يعني أنك استوردت ملف التصنيف قبل
> ملف الإصلاح. نفّذ `MAWATHEQ_DB_SCHEMA_FIX.sql` ثم أعد الاستيراد.

### 5.2 من سطر الأوامر

```bash
mysql -u USER -p DBNAME < MAWATHEQ_DB_SCHEMA_FIX.sql
mysql -u USER -p DBNAME < MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql
mysql -u USER -p DBNAME < MAWATHEQ_DB_VERIFY.sql
```

---

## 6. ما الذي يفعله الملف (وما لا يفعله)

**يفعل:**

- يضيف 7 فئات رئيسية، و38 فئة فرعية، و234 نوع دعوى، و640 طلبًا قضائيًا.
- يملأ `customer_desc_ar` و`customer_icon_key` لكل فئة — وهي نفس القيم التي تكتبها
  `upgrade_v63.sql`، فتعمل بطاقات المسار فورًا بلا ترقية ثانية.

**لا يفعل (مهم):**

- **لا** `DROP`، ولا `TRUNCATE`، ولا `DELETE`.
- **لا** يقرأ أو يكتب أي صف من صفوف العملاء أو المحامين أو القضايا أو الاستشارات أو
  الفواتير أو المستندات.
- **لا** يعتمد على معرّفات ثابتة: الصفوف الأب تُحلّ بمفتاحها الفريد، فيعمل على قاعدة
  معرّفاتها مستعملة.
- **لا** يستبدل صياغة عدّلها المشغّل: الوصف والأيقونة يُكتبان في الخانات الفارغة فقط.

**قابل لإعادة التنفيذ:** كل صف يُطابَق على مفتاح حتمي، فالتنفيذ الثاني لا يُدخل شيئًا
ولا يُكرّر أي تصنيف.

---

## 7. التحقق بعد الاستيراد

نفّذ `MAWATHEQ_DB_VERIFY.sql` — يطبع جدولًا من 19 فحصًا. أو نفّذ هذه الاستعلامات مباشرة:

```sql
-- الأعداد (يجب أن تطابق بالضبط)
SELECT 'categories' AS t, COUNT(*) AS n FROM najiz_categories
UNION ALL SELECT 'subcategories', COUNT(*) FROM najiz_subcategories
UNION ALL SELECT 'case_types',    COUNT(*) FROM najiz_case_types
UNION ALL SELECT 'requests',      COUNT(*) FROM najiz_judicial_requests;
-- المتوقع: 7 / 38 / 234 / 640
```

```sql
-- مطابقة الإعلان بالتحليل: مجموع declared_count يجب أن يساوي عدد الأنواع
SELECT SUM(declared_count) AS declared_types,
       (SELECT COUNT(*) FROM najiz_case_types) AS parsed_types
FROM najiz_subcategories;
-- المتوقع: 234 = 234
```

```sql
-- لا يتيم: كل نوع دعوى مرتبط بفئة فرعية صحيحة
SELECT COUNT(*) AS orphan_case_types
FROM najiz_case_types t
LEFT JOIN najiz_subcategories s ON s.id = t.subcategory_id
WHERE s.id IS NULL;
-- المتوقع: 0
```

```sql
-- الوصف والأيقونة للعملاء مكتملان
SELECT name_ar, customer_icon_key
FROM najiz_categories
WHERE customer_desc_ar IS NULL OR customer_desc_ar = ''
   OR customer_icon_key IS NULL OR customer_icon_key = '';
-- المتوقع: لا صفوف
```

### 7.1 التحقق من الواجهة (بعد الاستيراد)

```
GET https://<الموقع>/api/v1/journey.php?action=specialties
→ يجب أن يعيد 7 تخصصات بأسمائها ووصفها و icon_key
```

ثم افتح التطبيق: قسم **اختر تخصصك القانوني** يجب أن يعرض الفئات السبع الحقيقية.

---

## 8. النتائج التي تحقّقت فعليًا في هذه الجلسة

نُفِّذ المسار كاملًا على قاعدة بيانات نظيفة (MariaDB 11.8.6) مطابقة للإنتاج:

| الخطوة | النتيجة |
|---|---|
| `schema.sql` | 107 جدول |
| `MAWATHEQ_DB_SCHEMA_FIX.sql` | 116 جدولًا، بلا أخطاء |
| `MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql` | 7 / 38 / 234 / 640 بالضبط |
| التنفيذ الثاني للملف | الأعداد نفسها — لا تكرار |
| `MAWATHEQ_DB_VERIFY.sql` | 19 فحصًا، كلها OK |
| `SUM(declared_count)` مقابل الأنواع | 234 = 234 |

المسار البديل `upgrade_v59.sql` ← `upgrade_v63.sql` ← الاستيراد يعطي النتيجة نفسها.

---

## 9. خطة الرجوع

الملف **لا يحذف ولا يعدّل** أي بيانات قائمة، فالرجوع نادرًا ما يحتاج إليه. إن لزم إزالة
التصنيف:

1. أوقف التطبيق مؤقتًا (اختياري).
2. نفّذ:
   ```sql
   DELETE FROM najiz_judicial_requests;
   DELETE FROM najiz_case_types;
   DELETE FROM najiz_subcategories;
   DELETE FROM najiz_categories;
   ```
3. أو استعد النسخة الاحتياطية من القسم 4:
   ```bash
   mysql -u USER -p DBNAME < backup_YYYY-MM-DD_HHMM.sql
   ```

> لا تحذف صفًا من `najiz_case_types` بينما تشير إليه قضية أو استشارة — القيد
> `ON DELETE RESTRICT` يمنع ذلك عن قصد. لتقاعد تصنيف، اضبط `is_active = 0`.

---

*أُنشئ بواسطة وكيل OpenHands.*
