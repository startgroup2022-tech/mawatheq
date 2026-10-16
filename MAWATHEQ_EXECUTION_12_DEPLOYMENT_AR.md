# MAWATHEQ — Execution 12: incremental deployment guide (existing cPanel installs)

**English summary.** A tiny, PHP-only update: it overwrites 15 existing files. **No database
migration is required.** It does not touch `config/secrets.php`, `uploads/`, or any customer data.
Take a backup first (below). Full Arabic steps follow.

---

# دليل النشر الجزئي — التحديث 12 (نسخة قائمة على cPanel)

هذا التحديث صغير ومحدود: **يستبدل 15 ملف PHP قائماً فقط**. لا يضيف جداول، ولا يعدّل
`schema.sql`، ولا يمسّ `config/secrets.php` أو `uploads/` أو بيانات العملاء.

- الحزمة: `MAWATHEQ_EXECUTION_12_COMPLETE.zip` (761,772 بايت)
- مبنية من الإصدار المتحقَّق منه: `0e38e05` على فرع `phase-4-enterprise-ui`
- SHA-256: `44b3a1350d007066e3fef4f9fef269fe99467855df5f42f826d99e60b8fe6d92`

## 0. تحقّق من الحزمة قبل فكّها

نزّل الملفين `.zip` و`.sha256` إلى نفس المجلد، ثم:

```bash
sha256sum -c MAWATHEQ_EXECUTION_12_COMPLETE.sha256
# يجب أن تظهر: MAWATHEQ_EXECUTION_12_COMPLETE.zip: OK
```

إن لم يظهر `OK` فلا تكمل. أعد التنزيل.

## 1. النسخة الاحتياطية (إلزامية قبل أي رفع)

1. **قاعدة البيانات:** cPanel → **phpMyAdmin** → اختر قاعدة مواثيق → **Export** → صيغة SQL → نزّل
   الملف واحفظه خارج السيرفر. أو من الطرفية:
   ```bash
   mysqldump -u DBUSER -p DBNAME > ~/backup_mawatheq_$(date +%F).sql
   ```
2. **الملفات:** cPanel → **File Manager** → اختر مجلد الموقع (`public_html`) → **Compress** → `tar.gz`،
   ثم نزّله واحفظه خارج السيرفر. هذا يحفظ أيضاً `config/secrets.php` و`uploads/`.
3. دوّن أن الحزمة **لا** تحتوي الأسرار ولا الرفعات، فلن يستبدلها الرفع؛ النسخة الاحتياطية للأمان فقط.

## 2. الملفات المتغيّرة — استبدال 15 ملفاً فقط

ارفع الملفات التالية من الحزمة إلى مسارها نفسه في `public_html` (استبدال كامل للملف):

| # | الملف داخل الحزمة | الغرض |
|---|---|---|
| 1 | `includes/ai_provider.php` | جوهر التحديث: الشرط الموحّد + `ai_diagnostics()` |
| 2 | `includes/secret_crypto.php` | تحميل مفاتيح الصلابة الصحيحة |
| 3 | `includes/legal_ai.php` | الشرط الموحّد |
| 4 | `includes/ai_workflow.php` | الشرط الموحّد |
| 5 | `includes/contract_comparison.php` | الشرط الموحّد |
| 6 | `includes/court_order_extraction.php` | الشرط الموحّد |
| 7 | `includes/evidence_intelligence.php` | الشرط الموحّد |
| 8 | `api/ai_chat.php` | الشرط الموحّد |
| 9 | `api/upload_document.php` | بوابة الرؤية (ملفات) |
| 10 | `api/v1/documents.php` | بوابة الرؤية (ملفات) |
| 11 | `lawyer/drafting.php` | إزالة رسالة «فعّل مزوّداً» الخاطئة |
| 12 | `lawyer/dashboard.php` | الشرط الموحّد |
| 13 | `lawyer/case-detail.php` | الشرط الموحّد |
| 14 | `admin/ai-orchestration.php` | الشرط الموحّد |
| 15 | `admin/ai-operations.php` | لوحة تشخيص الاتصال (`?diag=1`) |

> إن أردت رفعاً أبسط: ارفع الحزمة كاملة فوق `public_html` مع **عدم** استبدال
> `config/secrets.php`، وأبقِ مجلد `uploads/` كما هو. الحزمة لا تحتوي على أي منهما، فالرفع
> الكامل آمن من هذه الناحية؛ لكن الأفضل رفع الملفات الـ15 أعلاه فقط لتقليل أثر التغيير.

### ملفات لا تُلمس أبداً

- `config/secrets.php` — غير موجود في الحزمة عمداً. **لا تستبدله.**
- `uploads/**` — الحزمة تحوي ملفات `.htaccess` فقط، ولا تحوي أي محتوى عملاء.
- `config/config.php` — **غير متغيّر** في هذا التحديث (لم يُلمس).

## 3. قاعدة البيانات: لا ترقية مطلوبة

**التحديث 12 لا يحتوي أي ملف `upgrade_v*.sql` ولا يعدّل `schema.sql`.** لا تشغّل أي استيراد SQL.

السبب: التغيير كله في طبقة PHP (كيف تُقرأ الإعدادات وتُختبر). لم تُضف أعمدة ولا جداول.

إن كنت **لم** تطبّق التحديث 11 بعد، فطبّقه أولاً (يحتوي `upgrade_v52.sql`، وهو ما يضيف جداول
SSO و`domain_ssl_jobs` و`court_order_extractions` وغيرها). شغّل فحص الجاهزية أدناه للتأكد.

## 4. التحقق بعد النشر (إلزامي)

من مجلد المشروع على السيرفر:

```bash
php tools/production_check.php
echo "exit=$?"
```

- يجب أن يكون الخروج `0` وأن تظهر: **النتيجة: كل الفحوص الحرجة ناجحة**.
- إن ظهرت أعمدة ناقصة، فقاعدتك تحتاج ترقيات التحديث السابق — طبّق ملفات `upgrade_v*.sql` بالترتيب.

### فحص الصلاحيات

بعد الرفع تأكد أن الملفات الـ15 جاءت بصلاحية `644` والمجلدات `755`:

```bash
find . -name '*.php' ! -perm -644 -printf 'UNREADABLE %p\n'
```

لا يُفترض أن يطبع شيئاً. إن طبع أسطراً: `chmod 644` على كل ملف مذكور (الصلاحية `600`/`660` تعني
`403` للوحدات المضمّنة فتتوقف الصفحات).

### فحص وظيفي للذكاء الاصطناعي

1. ادخل كمدير → **عمليات الذكاء الاصطناعي** → أضف `?diag=1` إلى الرابط.
2. يجب أن تظهر لوحة التشخيص. إن كان المزوّد غير مضبوط فستظهر حالة **blocking** واضحة —
   من دون طباعة أي مفتاح.
3. لإعادة ربط مزوّد محلي بلا مفتاح: لوحة التحكم ← الإعدادات ← مزوّد
   `openai_compatible` + عنوان الخادم (بلا مفتاح). بعد التحديث 12 تُقرأ هذه الحالة كـ«مضبوط»
   ولن تظهر رسالة «فعّل مزوّداً» في صفحات المسودات.

## 5. التراجع (Rollback)

1. أعد ملفات `public_html` من أرشيف `tar.gz` الذي أخذته في الخطوة 1 (أو استعد الملفات الـ15 من
   النسخة السابقة).
2. لا يوجد تراجع لقاعدة البيانات، لأن التحديث لم يغيّرها.

## 6. ما لا تفعله

- لا تستورد `schema.sql` فوق قاعدة فيها بيانات.
- لا ترفع `config/secrets.example.php` مكان `config/secrets.php`.
- لا تغيّر `MAWATHEQ_ENCRYPTION_KEY` — تغييره يمنع فكّ أسرار المزوّدات المخزّنة.
- لا تحذف مجلدات `uploads/` ولا ملفات `.htaccess` داخلها.

---

_This guide was produced by an AI agent (OpenHands) on behalf of the repository owner._
