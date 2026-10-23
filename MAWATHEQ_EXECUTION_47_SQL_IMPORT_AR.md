# مواثيق — التصنيف القانوني: ملف SQL وتعليمات الاستيراد الآمنة

**Execution 47 — ملحق التسليم**
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

**التصنيف موجود على الإنتاج بالفعل.** فحص مباشر للـ API الحي:

```
GET https://mawatheq.com/api/najiz_classify.php?action=stats
→ {"ok":true,"stats":{"categories":7,"subcategories":38,"lawsuit_types":234,"judicial_requests":640}}

GET https://mawatheq.com/api/v1/journey.php?action=specialties
→ 200، سبعة تخصصات بأسمائها ووصفها

GET https://mawatheq.com/api/v1/journey.php?action=issue-types&category_id=4
→ 200، مطالبات قضائية حقيقية
```

**لا حاجة إلى استيراد هذا الملف على الإنتاج الآن.** الملف هو أداة تعافٍ: إن فُقدت
البيانات، أو نُقل الموقع إلى خادم جديد، أو أُنشئت قاعدة بيانات من الصفر — هذا هو الملف
الذي يعيد بناء التصنيف.

> **لا تُعدّل قاعدة بيانات الإنتاج دون إذن المالك.** هذا الملحق جاهز للتنفيذ، لكن التنفيذ
> على الإنتاج قرار المالك.

---

## 3. ما يحتويه الملف

| المستوى | العدد |
|---|---|
| التصنيفات الرئيسية (التخصصات) | **7** |
| التصنيفات الفرعية | **38** |
| أنواع الدعاوى | **234** |
| المطالبات القضائية | **640** |

تم التحقق من هذه الأعداد باستيراد الملف فعليًا على قاعدة بيانات تجريبية مطابقة للإنتاج،
ثم عدّ الصفوف:

```sql
SELECT 'categories', COUNT(*) FROM najiz_categories
UNION ALL SELECT 'subcategories', COUNT(*) FROM najiz_subcategories
UNION ALL SELECT 'case_types', COUNT(*) FROM najiz_case_types
UNION ALL SELECT 'judicial_requests', COUNT(*) FROM najiz_judicial_requests;
-- 7 / 38 / 234 / 640
```

---

## 4. المتطلبات المسبقة — تحقق منها أولًا

الملف يحتاج سبعة جداول وعمودين. إن غاب أي منها **يرفض الملف العمل ويطبع رسالة واضحة**
بدلًا من أن يستورد نصف البيانات:

```
PLEASE RUN MAWATHEQ_DB_SCHEMA_FIX.sql FIRST
```

### 4.1 الجداول المطلوبة

`najiz_categories`، `najiz_subcategories`، `najiz_case_types`، `najiz_judicial_requests`،
`najiz_search_synonyms`، `najiz_import_runs`، `najiz_audit_log`.

هذه الجداول تُنشئها **`database/upgrade_v59.sql`**.

### 4.2 العمودان المطلوبان

`najiz_categories.customer_desc_ar` و `najiz_categories.customer_icon_key`.

هذان العمودان يُنشئهما **`database/upgrade_v63.sql`**.

### 4.3 فحص سريع قبل الاستيراد

من phpMyAdmin، تبويب **SQL**، نفّذ:

```sql
SELECT TABLE_NAME
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('najiz_categories','najiz_subcategories','najiz_case_types',
                      'najiz_judicial_requests','najiz_search_synonyms',
                      'najiz_import_runs','najiz_audit_log');
-- يجب أن يعيد 7 صفوف

SELECT COLUMN_NAME
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'najiz_categories'
   AND COLUMN_NAME IN ('customer_desc_ar','customer_icon_key');
-- يجب أن يعيد صفين
```

إن أعاد أقل من ذلك، طبّق `database/upgrade_v59.sql` ثم `database/upgrade_v63.sql` أولًا.

---

## 5. النسخة الاحتياطية — لا تتخطَّ هذه الخطوة

من phpMyAdmin:

1. اختر قاعدة البيانات من القائمة اليسرى.
2. تبويب **Export**.
3. اختر **Custom** — لا **Quick**.
4. تأكد أن **Structure and data** محدَّد.
5. الصيغة **SQL**، والترميز **utf-8**.
6. اضغط **Export** واحفظ الملف خارج الخادم.

أو من سطر الأوامر (إن كان متاحًا):

```bash
mysqldump -u USER -p --single-transaction --routines --triggers \
  DATABASE_NAME > backup_before_taxonomy_$(date +%F).sql
```

تحقق من النسخة قبل المتابعة:

```bash
ls -lh backup_before_taxonomy_*.sql   # يجب أن يكون حجمه معقولًا، لا صفرًا
grep -c "CREATE TABLE" backup_before_taxonomy_*.sql
```

---

## 6. الاستيراد من phpMyAdmin — خطوة بخطوة

1. افتح phpMyAdmin وسجّل الدخول.
2. من القائمة اليسرى **اختر قاعدة البيانات** (لا تضغط عليها مرتين، اخترها فقط).
3. اضغط تبويب **Import** من الأعلى.
4. **File to import:** اضغط **Choose File** واختر
   `MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql`.
5. **Character set of the file:** اختر `utf-8`.
6. تأكد أن **Partial import** غير محدَّد.
7. تأكد أن **Allow interrupt of import** غير محدَّد.
8. اضغط **Go** في الأسفل.

الاستيراد يستغرق ثوانٍ. عند النجاح تظهر رسالة خضراء:

```
Import has been successfully finished, N queries executed.
```

### إن ظهرت رسالة حمراء

| الرسالة | المعنى | الحل |
|---|---|---|
| `PLEASE RUN MAWATHEQ_DB_SCHEMA_FIX.sql FIRST` | جدول أو عمود ناقص | طبّق `upgrade_v59.sql` ثم `upgrade_v63.sql`، ثم أعد الاستيراد |
| `Table 'x.najiz_categories' doesn't exist` | لم تختر قاعدة البيانات | اختر قاعدة البيانات من اليسار وأعد المحاولة |
| `You have an error in your SQL syntax` | الملف مقطوع أثناء الرفع | أعد رفع الملف كاملًا (292,050 بايت) |
| `Access denied` | صلاحيات المستخدم | استخدم مستخدمًا يملك `INSERT` و `SELECT` على قاعدة البيانات |

---

## 7. الأمان — ما يفعله الملف وما لا يفعله

**يفعل:**

- **إضافي ومتكرر الأمان (idempotent).** كل صف يُطابَق على مفتاح ثابت، فتشغيل الملف مرتين
  لا يُدرج شيئًا في المرة الثانية. تم التحقق: التشغيل الثاني أنتج نفس الأعداد بالضبط.
- **يحترم تخصيصات المشغّل.** الوصف والرمز يكتبان فقط حيث كان الحقل فارغًا، فلا يُمحى نص
  كتبه المشغّل بيده.
- **لا معرّفات مثبَّتة.** الصفوف الأب تُحلّ بمفتاحها الفريد لا برقمها، فيعمل الملف على قاعدة
  بيانات أرقامها مستخدمة بالفعل.

**لا يفعل:**

- لا `DROP`، لا `TRUNCATE`، لا `DELETE`.
- لا يقرأ ولا يكتب بيانات العملاء أو المحامين أو القضايا أو الاستشارات أو الفواتير أو
  المستندات. يكتب في جداول `najiz_*` فقط.

---

## 8. التحقق بعد الاستيراد

من phpMyAdmin، تبويب **SQL**:

```sql
-- 1) الأعداد الإجمالية
SELECT 'categories' AS level, COUNT(*) AS n FROM najiz_categories
UNION ALL SELECT 'subcategories', COUNT(*) FROM najiz_subcategories
UNION ALL SELECT 'case_types',    COUNT(*) FROM najiz_case_types
UNION ALL SELECT 'judicial_requests', COUNT(*) FROM najiz_judicial_requests;
-- المتوقع: 7 / 38 / 234 / 640

-- 2) التخصصات السبعة بأسمائها
SELECT id, name_ar, customer_icon_key
  FROM najiz_categories
 WHERE is_active = 1
 ORDER BY sort_order;
-- المتوقع: 7 صفوف، وكل customer_icon_key غير فارغ

-- 3) الوصف الموجَّه للعميل ممتلئ
SELECT COUNT(*) AS with_desc
  FROM najiz_categories
 WHERE customer_desc_ar IS NOT NULL AND customer_desc_ar <> '';
-- المتوقع: 7

-- 4) لا صفوف مكررة
SELECT name_ar, COUNT(*) AS n
  FROM najiz_categories
 GROUP BY name_ar HAVING n > 1;
-- المتوقع: صفر صفوف

-- 5) أنواع الدعاوى موزّعة على التصنيفات
SELECT c.name_ar AS category, COUNT(t.id) AS types
  FROM najiz_categories c
  LEFT JOIN najiz_subcategories s ON s.category_id = c.id
  LEFT JOIN najiz_case_types t ON t.subcategory_id = s.id
 GROUP BY c.id, c.name_ar
 ORDER BY c.sort_order;
```

### التحقق عبر الواجهة

```
GET https://mawatheq.com/api/najiz_classify.php?action=stats
→ يجب أن يعيد 7 / 38 / 234 / 640

GET https://mawatheq.com/api/v1/journey.php?action=specialties
→ يجب أن يعيد 7 تخصصات
```

---

## 9. إن لم تكن تملك صلاحية الإنتاج

هذا أمر متوقع. التسليم يكتمل بدونه:

1. ملف SQL جاهز ومتحقَّق منه على قاعدة تجريبية مطابقة.
2. تعليمات الاستيراد الآمنة أعلاه.
3. استيراد الإنتاج يبقى **خطوة معلَّقة بانتظار إذن المالك** — وهذا صحيح، لأن الملف يعمل
   على قاعدة البيانات الحيّة ولا يُشغَّل دون قرار واعٍ.

---

## 10. تحذير: لا تستورد `schema.sql` على قاعدة إنتاج قائمة

`database/schema.sql` يُنشئ قاعدة بيانات كاملة من الصفر (107 جداول). استيراده على قاعدة
قائمة سيحاول إنشاء جداول موجودة، ولن يعيد أي بيانات محذوفة. هو لتركيب جديد فقط.
