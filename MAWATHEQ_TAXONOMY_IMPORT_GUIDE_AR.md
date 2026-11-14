# دليل استيراد التصنيفات القانونية (التصنيف القضائي السعودي)

> **الحالة:** هذه الحزمة جاهزة ومُختبرة. **لم تُنفَّذ على قاعدة بيانات الإنتاج.**
> تنفيذها على الإنتاج خطوة منفصلة تتطلب إذن المالك (انظر «الصلاحيات» في النهاية).

---

## 1) ما هذه الحزمة ولماذا

واجهة التطبيق تعرض «اختر تخصصك القانوني» ثم التخصصات والخدمات. التخصصات تأتي من
تصنيف «ناجز» القضائي السعودي. على الإنتاج هذا الجدول فارغ، لذلك لا تظهر أي تخصصات.

هذه الحزمة تُدخل التصنيف الكامل:

| المكوّن | العدد |
|---|---|
| التصنيفات الرئيسية | **7** |
| التصنيفات الفرعية | **38** |
| أنواع الدعاوى | **234** |
| الطلبات القضائية | **640** |

> ملاحظة مهمة: التطبيق يعرض أيضاً **سبعة تخصصات احتياطية مدمجة** حتى قبل تنفيذ هذا
> الاستيراد، فلا تظهر الواجهة فارغة. الاستيراد يجعل البيانات حقيقية وقابلة للبحث
> والمطابقة مع المحامين.

---

## 2) ملفات الحزمة — المسارات في المستودع

| الملف | المسار في المستودع | الدور |
|---|---|---|
| **ملف الاستيراد** | `database/MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql` | يُدخل التصنيفات (7/38/234/640) |
| إصلاح المخطط (شرط مسبق) | `database/MAWATHEQ_DB_SCHEMA_FIX.sql` | ينشئ جداول `najiz_*` ويصلح `schema_migrations` |
| التحقق | `database/MAWATHEQ_DB_VERIFY.sql` | يتحقق من النتيجة بعد الاستيراد |
| المصدر الأصلي | `database/sources/Pasted text(3).txt` | ملف ناجز الأصلي (125,912 بايت) |
| المُولِّد | `tools/generate_legal_import_sql.php` | يولّد ملف الاستيراد من المصدر |

**بصمة المصدر (SHA-256):**
```
f6f5233153aac35684d9b6adae1fa98d7c5e8c07824d0dc5169f1ab13917b269
```
تحقّق منها قبل الاستيراد؛ الملف المقطوع يُحلَّل بصمت لكنه يفقد أنواعاً.

---

## 3) الشروط المسبقة

1. **إصدار PHP 8.4** على الخادم (متطلب `composer.json`).
2. **تطبيق الترقيات** حتى `database/upgrade_v63.sql`. تحديداً:
   - `upgrade_v59.sql` — ينشئ جداول `najiz_*` وعمودي `najiz_case_type_id`.
   - `upgrade_v63.sql` — ينشئ `lawyer_specialties` وأعمدة عرض العميل.
   - `upgrade_v62.sql` — قيمة `image` في `file_category` (مطلوبة لرفع الصور).
3. **صلاحيات قاعدة البيانات:** `ALTER`, `CREATE`, `INSERT`, `SELECT` على قاعدة الموقع.
4. **نسخة احتياطية كاملة** قبل أي شيء (القسم 4).

> **ترتيب مهم:** `upgrade_v63.sql` يشتق وصف/أيقونة العميل **من صفوف موجودة**. على قاعدة
> أنشأت `najiz_categories` للتو تكون فارغة، فلا يكتب شيئاً في التشغيل الأول.
> الترتيب الصحيح: **v59 → v63 → الاستيراد → v63 مرة ثانية**. التشغيل الثاني يملأ
> الأوصاف السبعة والأيقونات.

---

## 4) النسخ الاحتياطي (إلزامي — قبل أي تنفيذ)

من cPanel → **phpMyAdmin** → اختر قاعدة الموقع → **Export** → **Go** (طريقة Quick،
الصيغة SQL). احفظ الملف خارج الخادم.

أو من SSH:
```bash
mysqldump -u USER -p DBNAME > mawatheq-backup-$(date +%F).sql
```

لا تُكمل قبل أن تتأكد أن حجم ملف النسخة معقول ويمكن قراءته.

---

## 5) خطوات الاستيراد من phpMyAdmin (آمنة، بالترتيب)

> لا تستخدم `schema.sql` على قاعدة إنتاج قائمة أبداً — إنه يفترض قاعدة فارغة وقد
> يستبدل بيانات. استخدم الملفات الثلاثة أعلاه فقط.

### الخطوة 1 — إصلاح المخطط
1. phpMyAdmin → اختر قاعدة الموقع في العمود الأيسر.
2. تبويب **Import** → **Choose File** → `MAWATHEQ_DB_SCHEMA_FIX.sql`.
3. الترميز **utf-8** → **Go**.
4. يجب أن ترى في النهاية: `najiz_* tables 7 of 7`.

### الخطوة 2 — استيراد التصنيفات
1. تبويب **Import** → `MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql`.
2. **Go**. لا تُغيّر أي إعداد.
3. في النهاية تظهر نتيجة القراءة:
   ```
   categories        7     7
   subcategories     38    38
   case types        234   234
   judicial requests 640   640
   ```

### الخطوة 3 — تشغيل v63 مرة ثانية (لملء الأوصاف)
1. تبويب **Import** → `database/upgrade_v63.sql` → **Go**.
2. هذا يملأ `customer_desc_ar` و`customer_icon_key` للتصنيفات السبعة.

### الخطوة 4 — التحقق
1. تبويب **Import** → `MAWATHEQ_DB_VERIFY.sql` → **Go**.
2. راجع الجدول: كل صف يجب أن يكون `OK` أو مطابقاً للعدد المتوقع،
   وأصفار للـ«orphans» والتكرارات.

> **الاستيراد آمن للتكرار.** تشغيله مرتين لا يُدخل شيئاً في المرة الثانية ولا يكرر
> تصنيفاً. لا يوجد `DROP` ولا `TRUNCATE` ولا `DELETE`، ولا يُقرأ أو يُكتب أي صف
> لعميل أو محامٍ أو قضية أو فاتورة.

---

## 6) استعلامات التحقق (بعد الاستيراد)

```sql
-- 1) الأعداد المطلوبة
SELECT 'categories'         AS kind, COUNT(*) AS rows_now, 7   AS expected FROM najiz_categories
UNION ALL SELECT 'subcategories',     COUNT(*), 38  FROM najiz_subcategories
UNION ALL SELECT 'case types',        COUNT(*), 234 FROM najiz_case_types
UNION ALL SELECT 'judicial requests', COUNT(*), 640 FROM najiz_judicial_requests;

-- 2) لا يوجد يتيم (كل فرع له تصنيف، وكل نوع له فرع)
SELECT COUNT(*) AS orphan_subcategories FROM najiz_subcategories s
  LEFT JOIN najiz_categories c ON c.id = s.category_id WHERE c.id IS NULL;
SELECT COUNT(*) AS orphan_case_types FROM najiz_case_types t
  LEFT JOIN najiz_subcategories s ON s.id = t.subcategory_id WHERE s.id IS NULL;

-- 3) لا يوجد تكرار في المفتاح المنطقي
SELECT source_key, COUNT(*) n FROM najiz_case_types
  GROUP BY source_key HAVING n > 1;

-- 4) أوصاف العميل السبعة مملوءة (بعد تشغيل v63 الثاني)
SELECT COUNT(*) AS with_desc FROM najiz_categories WHERE customer_desc_ar IS NOT NULL;
```

### فحص من الموقع (الأفضل)
بعد الاستيراد، افتح:
```
https://mawatheq.com/api/v1/journey.php?action=specialties
```
يجب أن يعيد قائمة غير فارغة (كانت `"specialties":[]`). أو افتح التطبيق → الرئيسية →
يجب أن تظهر التخصصات الحقيقية بأسمائها وأوصافها.

---

## 7) حالات الفشل الشائعة

| العَرَض | السبب | الحل |
|---|---|---|
| `#1054 Unknown column 'statements_ok'` | جدول `schema_migrations` قديم | شغّل `MAWATHEQ_DB_SCHEMA_FIX.sql` أولاً |
| الأعداد أقل من المتوقع | الاستيراد توقّف في المنتصف | أعد تشغيل ملف الاستيراد — آمن ومكمل |
| التخصصات بأسماء بدون أوصاف | `upgrade_v63.sql` لم يُشغَّل بعد الاستيراد | شغّل `upgrade_v63.sql` مرة ثانية |
| الواجهة تعرض «لم يتم تفعيل دليل التصنيفات» | جداول `najiz_*` غير موجودة | شغّل `MAWATHEQ_DB_SCHEMA_FIX.sql` |
| `api/najiz_classify.php` يعيد 500 | نفس السبب أعلاه | نفس الحل |

---

## 8) الصلاحيات — ما لم يُنفَّذ

- ✅ **تم:** التحقق المحلي الكامل (7/38/234/640، صفر يتيم، صفر تكرار، استيراد
  متكرر آمن، بصمة المصدر مطابقة).
- ⛔ **لم يُنفَّذ:** الاستيراد على قاعدة بيانات الإنتاج `mawatheq.com`.
  هذا يتطلب **إذن المالك** بشكل صريح، لأن أي تعديل على قاعدة الإنتاج غير قابل
  للتراجع بدون النسخة الاحتياطية.

**طلب التنفيذ على الإنتاج:** يُرجى تأكيد المالك، ثم تُنفَّذ الخطوات في القسم 5
بالترتيب مع النسخة الاحتياطية أولاً.

---

## 9) سجل التحقق المحلي (هذه الجلسة)

```
scratch DB: mawatheq_verify
schema.sql           → 107 جدول
MAWATHEQ_DB_SCHEMA_FIX.sql   → najiz_* tables 7 of 7
MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql → 7 / 38 / 234 / 640
تشغيل ثانٍ للاستيراد          → لا جديد (7 / 38 / 234 / 640)
MAWATHEQ_DB_VERIFY.sql       → كل الفحوص OK، orphans = 0، duplicates = 0
```
