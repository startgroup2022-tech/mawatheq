# مواثيق — ظهور المحامين في رحلة الاستشارة: السبب الجذري والإصلاح

**Execution 50 — ملحق التسليم**
**التاريخ:** 2026-10-23

---

## 1. المشكلة التي أبلغ عنها المالك

> «عند الضغط على المحامين تظهر رسالة: لا يوجد محامٍ متاح» — مع وجود محامٍ معتمد فعليًا.

تم التحقق مباشرة من الإنتاج (`https://mawatheq.com`) ومن بيئة محلية مطابقة:

| الطلب | الإنتاج | المحلي (بنفس البيانات) |
|---|---|---|
| `GET /api/v1/lawyers.php` | 200 — محامٍ واحد (`thaer aljallad`، تخصص `قانون جنائي`) | نفس النتيجة |
| `GET /api/v1/lawyers.php?category_id=4` | `{"lawyers":[]}` | `matched:true` — محامٍ واحد |
| `GET /api/v1/journey.php?action=lawyers&category_id=4` | `{"matched":false,"lawyers":[]}` | `matched:true` — محامٍ واحد |

**البيانات سليمة في الإنتاج.** أسماء التصنيفات السبعة مطابقة بايت-ببايت لما في الكود
(تمت المقارنة بترميز UTF-8 لكل اسم). لا يوجد نقص بيانات ولا مشكلة في حالة المحامي —
فهو `approved` وله تخصص معلن.

---

## 2. السبب الجذري — فرق إصدار في ملف واحد

الملف المسؤول هو `includes/legal_journey.php`.

- **الكود القديم المنشور على الإنتاج:** دالة `legal_journey_lawyers_for_specialty()`
  تربط المحامي بالتخصص عبر جدول `lawyer_specialties` **فقط**:

  ```sql
  FROM lawyers l
  JOIN lawyer_specialties ls ON ls.lawyer_id = l.id
  WHERE ls.category_id = ? AND l.status = 'approved'
  ```

  المحامي `thaer aljallad` لا يملك صفًّا في `lawyer_specialties`، فهو **غير مرئي** مهما
  كان تخصصه المعلن صحيحًا.

- **الكود الحالي في المستودع (منذ الالتزام `d1de2ca`):** يبني فهرسًا عبر
  `legal_journey_lawyer_category_index()` يقرأ `lawyer_specialties` **وإن لم يوجد صف**
  يرجع إلى العمود المعلن `lawyers.specialization` ويمرّره عبر خريطة
  `legal_journey_specialization_categories()`. لذا يظهر المحامي.

**الخلاصة: الإنتاج يشغّل نسخة أقدم من هذا الملف. الإصلاح ليس في البيانات.**

تم التحقق من صحة الكود الحالي محليًا:

```
php tests/exec46_lawyer_visibility_test.php
checks: 14   failures: 0
```

---

## 3. الإصلاح — مسارَان، وكلاهما مقبول

### المسار (أ) — نشر الكود (المفضّل)

انشر الملف `includes/legal_journey.php` من المستودع (الفرع `exec43-home-slider`)
إلى الإنتاج. هذا يجعل ظهور المحامي يعتمد على تخصصه المعلن، وهو السلوك الصحيح دائمًا.

إن أمكن، انشر معه `lawyer/specialties.php` (صفحة المحامي لإدارة تخصصاته).

### المسار (ب) — إصلاح بياناتي بلا نشر كود

إن تعذّر نشر الكود الآن، فإن ملف SQL المرفق يكتب صفوف `lawyer_specialties` الناقصة،
فيصبح المحامي مرئيًا حتى مع الكود القديم:

| البند | القيمة |
|---|---|
| اسم الملف | `MAWATHEQ_LAWYER_SPECIALTY_BACKFILL.sql` |
| المسار في المستودع | `database/MAWATHEQ_LAWYER_SPECIALTY_BACKFILL.sql` |
| الحجم | 4,173 بايت (78 سطرًا) |
| SHA-256 | `2523f53e78efa6584a4da4dd868d4be51d413bea8554779b2a22bb88e7243a2d` |

> المسارَان يتقاربان على نفس النتيجة. الكود الجديد يعتبر الصف الصريح في
> `lawyer_specialties` **مرجعًا أولًا**، لذا تنفيذ هذا الملف قبل نشر الكود لا يغيّر
> شيئًا بعد النشر. نفّذ أيًّا منهما؛ لا حاجة لكليهما.

---

## 4. كيف يعمل ملف الإصلاح (ولماذا هو آمن)

الملف **بيان واحد** `INSERT ... SELECT` يربط كل محامٍ معتمد بالتخصص الحي المطابق
لتخصصه المعلن، عبر نفس الخريطة التي يستخدمها الكود:

```sql
INSERT IGNORE INTO lawyer_specialties (lawyer_id, category_id)
SELECT l.id, c.id
  FROM lawyers l
  JOIN ( ... VALUES مطابقة التخصص → اسم التصنيف ... ) m
    ON m.spec = TRIM(l.specialization)
  JOIN najiz_categories c ON c.name_ar = m.cat AND c.is_active = 1
 WHERE l.status = 'approved';
```

ضمانات السلامة، لأن هذا يعمل على الإنتاج:

- **`INSERT IGNORE` فقط.** المفتاح الفريد `(lawyer_id, category_id)` يعني أن إعادة
  التنفيذ لا تضيف شيئًا، وأن أي ربط أنشأه المشرف يدويًا **لا يُستبدل** أبدًا.
- **لا `UPDATE`، لا `DELETE`، لا DDL، ولا إنشاء جداول.**
- **محامون `approved` فقط، وتصنيفات `is_active = 1` فقط** — مطابق لما تعرضه الواجهات.
- **لا يقرأ ولا يكتب أي بيانات عملاء أو قضايا أو مستندات أو مدفوعات.**
- **مستقل عن أي معرّف.** يُنفّذ `SELECT` على قاعدة الخادم نفسه، فلا معرّف من جهاز آخر.

تم اختبار الملف محليًا على حالة تطابق الإنتاج تمامًا:

| | قبل | بعد |
|---|---|---|
| `lawyer_specialties` | فارغ | صف واحد |
| عدد محامي «الدعاوى الجزائية» (بالكود القديم) | 0 | 1 |
| إعادة التنفيذ الثانية | — | لا تغيير (لا يزال 1) |

---

## 5. المتطلبات المسبقة

نفّذ هذا الاستعلام في phpMyAdmin ← تبويب **SQL** بعد اختيار قاعدة البيانات:

```sql
SELECT 'lawyer_specialties table' AS check_name, COUNT(*) AS found, 1 AS expected
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lawyer_specialties'
UNION ALL
SELECT 'najiz_categories table', COUNT(*), 1
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'najiz_categories'
UNION ALL
SELECT 'categories are active', COUNT(*), 7
FROM najiz_categories WHERE is_active = 1
UNION ALL
SELECT 'approved lawyers', COUNT(*), COUNT(*)
FROM lawyers WHERE status = 'approved';
```

**النتيجة المطلوبة:** الصفوف الثلاثة الأولى `found = expected`. الصف الرابع يعرض العدد
الفعلي للمحامين المعتمدين (لا قيمة متوقعة؛ للتوجيه فقط).

إن كان أي جدول ناقصًا، نفّذ `MAWATHEQ_DB_SCHEMA_FIX.sql` أولًا ثم أعد الفحص.

---

## 6. النسخ الاحتياطي (إلزامي قبل أي تنفيذ)

**من phpMyAdmin:** اختر قاعدة البيانات ← تبويب **Export** ← **Quick** ← **SQL** ← **Go**.

**من SSH:**

```bash
mysqldump -u USER -p --single-transaction --routines --triggers DBNAME > backup_$(date +%F_%H%M).sql
ls -lh backup_*.sql     # تأكد أن الحجم معقول
```

> لا تكمل إن فشل النسخ الاحتياطي.

---

## 7. خطوات الاستيراد الآمنة (phpMyAdmin)

1. افتح cPanel ← **phpMyAdmin**.
2. اختر قاعدة بيانات الموقع من القائمة اليسرى (لا تختر `information_schema`).
3. تبويب **Import**.
4. **Choose File** ← اختر `MAWATHEQ_LAWYER_SPECIALTY_BACKFILL.sql`.
5. تأكد أن **Character set** = `utf-8`.
6. اضغط **Go**.
7. يجب أن تظهر رسالة نجاح مثل `1 row affected` (أو `0 rows affected` إن كان الإصلاح
   قد نُفّذ سابقًا — وهذا نجاح أيضًا).

---

## 8. استعلامات التحقق (نفّذها بعد الاستيراد)

```sql
-- 1. كم ربطًا صار موجودًا
SELECT COUNT(*) AS links FROM lawyer_specialties;

-- 2. المحامون المعتمدون الظاهرون الآن، وتحت أي تخصص
SELECT l.id, l.full_name, c.name_ar AS specialty
  FROM lawyer_specialties ls
  JOIN lawyers l ON l.id = ls.lawyer_id
  JOIN najiz_categories c ON c.id = ls.category_id
 WHERE l.status = 'approved'
 ORDER BY l.id;

-- 3. المحامون المعتمدون الذين ما زالوا بلا أي ربط (يجب أن تكون القائمة فارغة
--    إن كان لكل محامٍ تخصص معلن مطابق)
SELECT l.id, l.full_name, l.specialization
  FROM lawyers l
 WHERE l.status = 'approved'
   AND NOT EXISTS (SELECT 1 FROM lawyer_specialties ls WHERE ls.lawyer_id = l.id);
```

ثم تحقق من التطبيق مباشرة:

```bash
# يجب أن يعرض عددًا أكبر من صفر للتخصص المشغول
curl -s "https://mawatheq.com/api/v1/journey.php?action=specialties"

# يجب أن يعرض matched:true وقائمة غير فارغة
curl -s "https://mawatheq.com/api/v1/journey.php?action=lawyers&category_id=4"
```

---

## 9. حالة الإنتاج

> **لم تُعدَّل قاعدة بيانات الإنتاج في هذا التنفيذ.** لم تُنفَّذ أي أوامر على
> `mawatheq.com`. كل ما سبق تعليمات، والتنفيذ قرار المالك.

- التصنيف القانوني **موجود بالفعل** على الإنتاج (7 / 38 / 234 / 640 — تحقق مباشر).
- لا حاجة إلى استيراد `MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql`.
- المطلوب فقط: **نشر `includes/legal_journey.php`**، أو **تنفيذ ملف الإصلاح هذا** —
  أحدهما يكفي.

---

## 10. إعادة توليد الملف (للمطورين)

الملف مولَّد آليًا، ويستدعي نفس الخريطة التي يستدعيها الكود:

```bash
php tools/generate_lawyer_specialty_backfill.php
```

أعد التوليد عند تغيير `legal_journey_specialization_categories()` في
`includes/legal_journey.php`.
