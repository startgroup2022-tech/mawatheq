# مواثيق — استيراد التصنيف القضائي (النجيز) — دليل phpMyAdmin

**التنفيذ 52** · الملف: `MAWATHEQ_TAXONOMY_IMPORT.sql`

---

## 1. ما هو هذا الملف؟

ملف **بيانات فقط**. يحتوي التصنيف القضائي السعودي الكامل:

| المستوى | العدد |
|---|---|
| التخصصات الرئيسية | **7** |
| التخصصات الفرعية | **38** |
| أنواع الدعاوى | **234** |
| الطلبات القضائية | **640** |

المصدر: `database/sources/Pasted%20text%283%29.txt`
(بصمة SHA-256: `f6f5233153aac35684d9b6adae1fa98d7c5e8c07824d0dc5169f1ab13917b269`)

الملف **لا يُنشئ جداول ولا يعدّل بنية قاعدة البيانات**. يقوم فقط بـ:
1. التحقق من وجود جداول `najiz_*` (ويتوقف إن لم تكن موجودة).
2. حذف صفوف التصنيف القديمة.
3. إعادة إدخال كل الصفوف بأرقامها الأصلية.
4. التحقق من النتيجة (7/38/234/640) وإلغاء العملية بالكامل إن اختلفت.

كل ذلك داخل معاملة واحدة (`START TRANSACTION` … `COMMIT`)، فأي خطأ يترك قاعدة
البيانات كما كانت.

---

## 2. تحذيرات إلزامية

> ⚠️ **لا تُشغّل `database/schema.sql` على قاعدة بيانات قائمة.** هذا الملف للتركيب
> الجديد فقط، وقد يُفقد بياناتك.

> ⚠️ **لا تشغّل أي شيء قبل أخذ نسخة احتياطية.** انظر القسم 3.

> ⚠️ **لا تُعدّل قاعدة بيانات الإنتاج دون إذن المالك.** هذا الدليل جاهز للتنفيذ
> متى ما أذن المالك.

---

## 3. النسخة الاحتياطية (قبل أي خطوة)

من **phpMyAdmin**:

1. اختر قاعدة البيانات من القائمة اليمنى.
2. تبويب **Export**.
3. طريقة التصدير: **Quick** — الصيغة: **SQL**.
4. اضغط **Export** واحفظ الملف باسم مثل `mawatheq_backup_2026-10-23.sql`.

أو من سطر الأوامر (cPanel → Terminal):

```bash
mysqldump -u USER -p DBNAME > mawatheq_backup_2026-10-23.sql
```

**احتفظ بالنسخة خارج الاستضافة.** النسخة على نفس الخادم لا تحميك من حذف الخادم.

---

## 4. الترتيب الصحيح للترقيات

الترتيب مهم. `upgrade_v63.sql` يشتقّ وصف التخصصات من الصفوف الموجودة فعلاً، فعلى
قاعدة بيانات أنشأ فيها `v59` جدولاً فارغاً، **التشغيل الأول لـ v63 لا يكتب شيئاً**.
لذلك:

| # | الملف | الغرض |
|---|---|---|
| 1 | `database/upgrade_v59.sql` | يُنشئ جداول `najiz_*` |
| 2 | `database/upgrade_v63.sql` | يضيف `customer_desc_ar` و `customer_icon_key` |
| 3 | **`MAWATHEQ_TAXONOMY_IMPORT.sql`** | يُدخل التصنيف الكامل |
| 4 | `database/upgrade_v63.sql` **مرة ثانية** | يملأ الأوصاف والأيقونات |

الخطوة 4 إلزامية. تم التحقق منها محلياً: بعد الخطوة 3 تكون كل الأوصاف `NULL`،
وبعد الخطوة 4 تصبح السبعة أوصاف مملوءة مع أيقوناتها
(`family`, `commercial`, `labor`, `criminal`, `general`, `enforcement`, `endowments`).

---

## 5. التنفيذ عبر phpMyAdmin

لكل ملف من الأربعة:

1. اختر قاعدة البيانات.
2. تبويب **Import**.
3. **Choose File** → اختر الملف.
4. ترميز الأحرف: **utf-8**.
5. تأكد أن **Partial import** غير مفعّل.
6. اضغط **Go**.

### ملاحظات مهمة

- إذا كان الملف أكبر من حد الاستيراد في phpMyAdmin (`upload_max_filesize`)، فارفع
  الحد من **cPanel → MultiPHP INI Editor**، أو نفّذ من سطر الأوامر.
- عند استيراد ملف كبير، قد يعرض phpMyAdmin **timeout**. الحل: سطر الأوامر.
- `MAWATHEQ_TAXONOMY_IMPORT.sql` حجمه ≈ 240 كيلوبايت — أصغر من أي حد معتاد.

### من سطر الأوامر (أسرع وأضمن)

```bash
cd ~/public_html
mysql -u USER -p DBNAME < database/upgrade_v59.sql
mysql -u USER -p DBNAME < database/upgrade_v63.sql
mysql -u USER -p DBNAME < MAWATHEQ_TAXONOMY_IMPORT.sql
mysql -u USER -p DBNAME < database/upgrade_v63.sql
```

---

## 6. التحقق

### أ) من داخل الملف نفسه

في نهاية `MAWATHEQ_TAXONOMY_IMPORT.sql` استعلام تحقق. يجب أن يطبع:

```
main_specialties  sub_specialties  case_types  judicial_requests
7                 38               234         640
```

وإن اختلف أي رقم، يُلغي الملف العملية برسالة:

```
ERROR 1644 (45000): VERIFY FAILED: expected 7/38/234/640 - the import is incomplete
```

### ب) استعلامات تحقق يدوية

```sql
SELECT COUNT(*) AS main_specialties FROM najiz_categories;        -- 7
SELECT COUNT(*) AS sub_specialties  FROM najiz_subcategories;     -- 38
SELECT COUNT(*) AS case_types       FROM najiz_case_types;        -- 234
SELECT COUNT(*) AS judicial_requests FROM najiz_judicial_requests; -- 640
```

### ج) التحقق من الأوصاف (بعد الخطوة 4)

```sql
SELECT id, name_ar, customer_desc_ar, customer_icon_key
FROM najiz_categories ORDER BY id;
```

يجب أن تكون `customer_desc_ar` و `customer_icon_key` **غير فارغة** للصفوف السبعة.
إن كانت `NULL`، أعد تشغيل `upgrade_v63.sql`.

### د) التحقق من الموقع

افتح: `https://mawatheq.com/api/v1/journey.php?action=specialties`

يجب أن يُرجع `"ok":true` مع 7 تخصصات، لكل منها `name` و `description` و `icon_key`.

---

## 7. استكشاف الأخطاء

| الرسالة | السبب | الحل |
|---|---|---|
| `PREREQUISITE FAILED: run upgrade_v59.sql first` | جداول `najiz_*` غير موجودة | نفّذ `upgrade_v59.sql` أولاً |
| `VERIFY FAILED: expected 7/38/234/640` | الاستيراد ناقص | أعد الاستيراد؛ إن تكرر، تحقق من ترميز الملف (utf-8) |
| الأوصاف `NULL` بعد الاستيراد | لم تُنفّذ `upgrade_v63.sql` بعد الاستيراد | نفّذها الآن (الخطوة 4) |
| الصفحة تعرض "لم يتم تفعيل دليل التصنيفات" | `upgrade_v59.sql` لم يُنفّذ | نفّذ الترقيات بالترتيب |
| `Access denied` عند الاستيراد | مستخدم قاعدة البيانات لا يملك صلاحية `INSERT`/`DELETE` | استخدم مستخدم القاعدة من `config/secrets.php` |

---

## 8. ما لا يفعله هذا الملف

- لا يُنشئ جداول — إن لم تكن موجودة يتوقف.
- لا يُعدّل أي جدول آخر (قضايا، مستخدمون، فواتير).
- لا يحذف أي بيانات غير صفوف التصنيف الأربعة.
- لا يحتاج إعادة تشغيل الخادم.

---

## 9. حالة الإنتاج

آخر تحقق (قراءة فقط، دون تعديل) أظهر أن الإنتاج **يحتوي التصنيف كاملاً بالفعل**:
7 / 38 / 234 / 640، وكل الواجهات تُرجع `200`. لذلك **الاستيراد على الإنتاج قد لا
يكون مطلوباً**. تحقق أولاً بالاستعلامات في القسم 6؛ إن كانت الأرقام صحيحة، لا تنفّذ
شيئاً.

إن كانت الأرقام ناقصة، فالملف جاهز ومُختبَر، وينتظر إذن المالك.
