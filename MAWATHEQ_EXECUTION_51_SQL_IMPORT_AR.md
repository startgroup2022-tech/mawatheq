# مواثيق — دليل استيراد التصنيفات القانونية (الإصدار 51)

**التصنيفات القانونية السعودية (نجز):** 7 تصنيفات رئيسية، 38 تصنيفًا فرعيًا،
234 نوع دعوى، 640 طلبًا قضائيًا.

> **تنبيه:** لا تُنفّذ أي أمر على قاعدة بيانات الإنتاج دون إذن المالك. هذا الدليل
> للقراءة والتحضير. جميع الملفات **إضافية ولا تحذف شيئًا**.

---

## 1. الملف المطلوب بالضبط

| الملف | المسار في المستودع | الحجم | الغرض |
|---|---|---|---|
| **ملف الاستيراد الرئيسي** | `database/MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql` | 292,050 بايت | **هذا هو الملف المطلوب** — كل التصنيفات |
| إصلاح المخطط (شرط مسبق) | `database/MAWATHEQ_DB_SCHEMA_FIX.sql` | 17,941 بايت | ينشئ جداول `najiz_*` |
| ربط تخصصات المحامين | `database/MAWATHEQ_LAWYER_SPECIALTY_BACKFILL.sql` | 4,173 بايت | يربط المحامين بتخصصاتهم |
| الترقية الكاملة | `database/upgrade_v63.sql` | 12,490 بايت | ترقية المخطط الشاملة |
| فحص التحقق (للقراءة فقط) | `database/MAWATHEQ_DB_VERIFY.sql` | 9,036 بايت | يتحقق من النتيجة |

**بصمة SHA-256 لملف الاستيراد:**

```
10b09093e2ab3f4242efef5e00bb9105ddbf86bcd83df3d755b76816f1468134  MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql
```

تحقق منها بعد التحميل:

```bash
sha256sum MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql
```

---

## 2. الشروط المسبقة

يجب أن تكون الترقيات التالية مُطبَّقة على قاعدة البيانات (بالترتيب الرقمي):

- `database/upgrade_v59.sql` — ينشئ جداول التصنيف `najiz_*` الأساسية.
- `database/upgrade_v63.sql` — يضيف أعمدة وصف العميل وأيقونات التخصصات.
- `database/MAWATHEQ_DB_SCHEMA_FIX.sql` — يصلح عيب المخطط (`statements_ok` وجداول التصنيف).

> **مهم:** التصنيف يُستورد **بعد** الترقيات. ملف الاستيراد يتحقق بنفسه من وجود الجداول
> ويتوقف برسالة واضحة إن كانت ناقصة.

### ترتيب التنفيذ الصحيح

```
1) upgrade_v59.sql
2) upgrade_v63.sql              ← يُشغَّل مرة أولى (الجدول فارغ، لا يكتب شيئًا)
3) MAWATHEQ_DB_SCHEMA_FIX.sql
4) MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql   ← الاستيراد الفعلي
5) upgrade_v63.sql              ← يُشغَّل مرة ثانية (يملأ الأوصاف والأيقونات الآن)
6) MAWATHEQ_LAWYER_SPECIALTY_BACKFILL.sql
7) MAWATHEQ_DB_VERIFY.sql       ← للتحقق فقط
```

**لماذا `upgrade_v63.sql` مرتين؟** لأنه يشتق أوصاف العميل من صفوف `najiz_categories`
الموجودة. في التشغيل الأول يكون الجدول فارغًا فيكتب لا شيء؛ وبعد الاستيراد يملأ
التشغيل الثاني الأوصاف والأيقونات السبعة. الملف آمن للتشغيل أكثر من مرة.

---

## 3. النسخ الاحتياطي (قبل أي شيء)

**من phpMyAdmin:**

1. اختر قاعدة البيانات من القائمة اليسرى.
2. تبويب **Export**.
3. اختر **Custom** ثم **SQL**.
4. فعّل **Add DROP TABLE / VIEW / PROCEDURE** و **Add CREATE TABLE**.
5. اضغط **Go** واحفظ الملف في مكان آمن.

**من سطر الأوامر:**

```bash
mysqldump -u USER -p --single-transaction --routines --triggers DBNAME > backup_before_import_$(date +%F).sql
```

> لا تكمل قبل التأكد من نجاح النسخ الاحتياطي وفتحه للتأكد أنه ليس فارغًا.

---

## 4. الاستيراد من phpMyAdmin

1. افتح phpMyAdmin وسجّل الدخول.
2. اختر قاعدة البيانات من العمود الأيسر (مثال: `mawatheq_thaer`).
3. اضغط تبويب **Import**.
4. اضغط **Choose File** واختر `MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql`.
5. اترك الترميز **utf-8** كما هو.
6. تأكد أن **Partial import** غير مفعّل.
7. اضغط **Go**.

**النتيجة المتوقعة:** رسالة نجاح خضراء. الملف لا يحذف ولا يعدّل أي صف عميل أو محامٍ أو
قضية — هو يضيف صفوف التصنيف فقط.

### إن ظهرت رسالة خطأ

| الخطأ | المعنى | الحل |
|---|---|---|
| `PLEASE RUN MAWATHEQ_DB_SCHEMA_FIX.sql FIRST` | جداول التصنيف ناقصة | نفّذ `MAWATHEQ_DB_SCHEMA_FIX.sql` أولًا |
| `#1146 Table ... doesn't exist` | الترقية `upgrade_v59.sql` لم تُطبَّق | طبّق الترقيات بالترتيب |
| `#1062 Duplicate entry` | الملف نُفّذ سابقًا | لا مشكلة — الاستيراد متكرر الآمن؛ تجاهل |
| `#1153 Got a packet bigger than 'max_allowed_packet'` | حجم الحزمة صغير | قسّم الملف أو ارفع `max_allowed_packet` |

---

## 5. الاستيراد من سطر الأوامر (بديل)

```bash
mysql -u USER -p DBNAME < MAWATHEQ_DB_SCHEMA_FIX.sql
mysql -u USER -p DBNAME < MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql
mysql -u USER -p DBNAME < MAWATHEQ_DB_VERIFY.sql
```

---

## 6. التحقق من النتيجة

**استعلامات التحقق المباشرة** (phpMyAdmin → تبويب SQL):

```sql
SELECT 'categories'       AS kind, COUNT(*) AS rows_now, 7   AS expected FROM najiz_categories
UNION ALL SELECT 'subcategories',    COUNT(*), 38  FROM najiz_subcategories
UNION ALL SELECT 'case_types',       COUNT(*), 234 FROM najiz_case_types
UNION ALL SELECT 'judicial_requests',COUNT(*), 640 FROM najiz_judicial_requests;
```

**النتيجة الصحيحة:**

| kind | rows_now | expected |
|---|---|---|
| categories | 7 | 7 |
| subcategories | 38 | 38 |
| case_types | 234 | 234 |
| judicial_requests | 640 | 640 |

**أو نفّذ ملف التحقق الكامل:**

```
MAWATHEQ_DB_VERIFY.sql
```

كل سطر يجب أن تكون نتيجته `OK`.

### التحقق من الموقع

افتح المتصفح على:

```
https://mawatheq.com/api/najiz_classify.php?action=stats
```

يجب أن يعرض:

```json
{"ok":true,"stats":{"categories":7,"subcategories":38,"lawsuit_types":234,"judicial_requests":640}}
```

ثم:

```
https://mawatheq.com/api/v1/journey.php?action=specialties
```

يجب أن يعرض 7 تخصصات بأسمائها وأوصافها.

---

## 7. ملاحظات مهمة

- **لا تُنفّذ `database/schema.sql` على قاعدة بيانات إنتاج قائمة.** ملف `schema.sql`
  لتنصيب جديد فارغ فقط، وقد يتعارض مع قاعدة قائمة.
- ملف الاستيراد **متكرر الآمن (idempotent)**: تشغيله مرتين لا يضاعف أي صف.
- التصنيف **اختياري** في كل مسارات الكتابة: إن لم يكن مستوردًا تعمل المنصة، لكن قسم
  التخصصات يظهر فارغًا.
- لا يوجد زر حذف للتصنيفات: يُتقاعد التصنيف بـ `is_active = 0` فقط، لأن حذف تصنيف
  مرتبط بقضية قائمة يكسرها.

---

## 8. حالة الإنتاج

> **حالة الاستيراد على الإنتاج: معلّق بانتظار إذن المالك.**
>
> تم التحقق من الملف ومن الأعداد محليًا (7 / 38 / 234 / 640) على قاعدة بيانات اختبار.
> لم يُنفَّذ أي أمر على قاعدة بيانات الإنتاج. نفّذ الخطوات أعلاه بعد أخذ النسخة الاحتياطية.
