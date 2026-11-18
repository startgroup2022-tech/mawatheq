# مواثيق — التقرير 65: أسطح حساب العميل (مركز الدعم، الخصوصية، سجل العمليات)
# MAWATHEQ — Execution 65: customer account surfaces (support centre, privacy, transactions)

**الإصدار / Release:** `v2026.11.05-exec65`
**الفرع / Branch:** `exec56-lawyer-platform` (PR #10)
**التاريخ / Date:** 2026-11-05
**الحالة / Status:** مكتمل ومُتحقَّق منه — **غير منشور على الإنتاج** (بانتظار موافقة المالك).

---

## 1. الملخص التنفيذي / Executive summary

أُضيفت أسطح حساب العميل داخل تطبيق العميل: **مركز الدعم** (تذاكر + مواعيد دعم + قنوات
تواصل)، **الخصوصية والموافقات**، و**سجل العمليات** (مع تفاصيل الفاتورة). كل سطح يقرأ من
واجهة برمجية جديدة تحت `/api/v1/`، وكل شيء مبنيّ على جداول الترحيل `upgrade_v71.sql`.

**خلل حقيقي وُجد وأُصلح أثناء التنفيذ:** `api/v1/privacy.php` كان يقرأ `action` من
سلسلة الاستعلام (`$_GET`) فقط. تطبيق العميل يُرسل `action` داخل جسم الطلب (JSON)، فكان
**كل حفظ موافقة** يُرفَض بـ `unknown action` (400) بينما الواجهة تبدو سليمة ومسار القراءة
يعمل. أُصلح ليطابق `support.php` (`$action = $in['action'] ?? $action`).

---

## 2. الملفات / Files

### جديد (Backend)
| الملف | الغرض |
|---|---|
| `api/v1/support.php` | مركز الدعم: settings (عام)، categories، tickets، ticket، slots، appointments + كل عمليات الكتابة |
| `api/v1/privacy.php` | روابط السياسة (عام) + تفضيلات الموافقة (توكن) |
| `api/v1/transactions.php` | سجل العمليات الموحّد (استشارات/باقات/فواتير/دفعات/مستردات) |
| `api/v1/invoice.php` | تفاصيل فاتورة + بنودها + دفعاتها + رابط الطباعة |
| `includes/support.php` | منطق تذاكر الدعم ومواعيدها وقنواتها |
| `includes/transactions.php` | منطق تجميع سجل العمليات |
| `admin/support-tickets.php`, `admin/support-ticket.php` | لوحة الإدارة: قائمة التذاكر وتفاصيلها |
| `admin/support-appointments.php`, `admin/support-settings.php` | لوحة الإدارة: المواعيد وإعدادات القنوات |
| `database/upgrade_v71.sql` | جداول التذاكر والمواعيد والموافقات + مفاتيح القنوات |

### جديد (تطبيق العميل)
| الملف | الغرض |
|---|---|
| `apps/mobile/lib/core/network/account_models.dart` | نماذج: Transaction، SupportTicket، SupportSlot، ConsentPreference … |
| `apps/mobile/lib/features/account/support_center_screen.dart` | مركز الدعم |
| `apps/mobile/lib/features/account/support_ticket_screen.dart` | تذكرة + محادثة |
| `apps/mobile/lib/features/account/support_appointments_screen.dart` | مواعيد الدعم |
| `apps/mobile/lib/features/account/privacy_screen.dart` | الخصوصية والموافقات |
| `apps/mobile/lib/features/account/transactions_screen.dart` | سجل العمليات |
| `apps/mobile/lib/features/account/invoice_detail_screen.dart` | تفاصيل الفاتورة |
| `apps/mobile/lib/core/security/{biometric_service,rate_app_service}.dart` | خدمات اختيارية (بصمة/تقييم) |

### معدَّل
`api/v1/README.md`، `includes/admin_rbac.php` (صلاحيات صفحات الدعم)،
`admin/includes/admin_header.php` (مجموعة قائمة «الدعم»)،
`apps/mobile/lib/core/network/api_repository.dart`،
`apps/mobile/lib/core/storage/token_storage.dart`،
`apps/mobile/lib/features/account/account_screen.dart`،
`apps/mobile/android/app/src/main/AndroidManifest.xml`،
`apps/mobile/pubspec.yaml`،
`MAWATHEQ_DEPLOYMENT_GUIDE_AR.md`، `tools/build_release.sh`، `AGENTS.md`.

---

## 3. الأمان / Security

- **عزل المستأجر:** كل قراءة/كتابة للتذاكر والمواعيد مربوطة بـ `user_type`+`user_id`
  من التوكن؛ معرّف لا يخصّ الحساب يعيد `404` (لا `403` يكشف الوجود).
- **رموز التكامل:** `privacy.php` يرفض توكن التكامل (`403`) — هذه أسطح عميل.
- **الموافقات:** مفاتيح من قائمة سماح ثابتة؛ مفتاح غير معروف يُرفَض (400) ولا يُخزَّن.
  غياب الصف يعني «غير موافق»، وليس «موافق افتراضياً».
- **الأسرار:** لا يُعاد أي `password_hash` ولا أي سر عبر أي واجهة.
- **استعلامات مُعاملة:** كل الاستعلامات `prepare`/`execute`، بلا دمج نصّي لمدخلات.
- **RBAC:** صفحات الدعم مسجّلة في `admin_rbac.php` (قراءة/رد = `super_admin`+`support`،
  إعدادات القنوات = `super_admin`).

---

## 4. الترحيل / Migration — `database/upgrade_v71.sql`

إضافي وآمن تماماً: كل جدول `CREATE TABLE IF NOT EXISTS`، وكل إدراج بشرط «غير موجود»، ولا
يُحذف ولا يُعدَّل أي جدول أو صف قائم. تم التحقق: تشغيله مرتين على قاعدة نظيفة لا يغيّر
شيئاً (108 جدول من `schema.sql` ← 115 بعد الترحيل، وثابت عند إعادة التشغيل).

```bash
mariadb -u USERNAME -p DBNAME < database/upgrade_v71.sql
```

> **ترتيب مهم:** `upgrade_v63.sql` يشتقّ `customer_desc_ar` من صفوف `najiz_categories`.
> على قاعدة جديدة يطبّق v63 قبل الاستيراد فلا يكتب شيئاً. الترتيب الصحيح للتصنيفات
> (من التنفيذ السابق): **v59 → v63 → المستورد → v63 مرة أخرى**. لا علاقة له بـ v71.

---

## 5. الاختبارات / Tests

| النوع | الملف | النتيجة |
|---|---|---|
| Probe (HTTP حقيقي) | `tests/exec65_account_surfaces_probe.php` | **43/43** |
| Widget tests (Flutter) | `apps/mobile/test/exec65_account_surfaces_test.dart` | **9/9** |
| تحليل Flutter | `flutter analyze` | **No issues found** |
| مجموعة PHP كاملة | `bash tests/run_all_tests.sh` | **50/50 suite** |
| مجموعة Flutter كاملة | `flutter test` | **334 اختبار** |
| تراجع Exec 60 | `tests/exec60_lawyer_form_filters_test.php` | **139/139** |

**ملاحظة بيئة (ليست خللاً في الكود):** إعادة ضبط الحاوية أسقطت الصلاحيات العامة لمستخدم
`mawatheq` ومزوّد الذكاء الاصطناعي الوهمي. أُعيد منح الصلاحيات وإعادة إنشاء المزوّد، ثم
نجحت كل المجموعات. هذا موثَّق في `AGENTS.md`.

**غير مُختبَر على جهاز حقيقي:** لم تُجرَّب هذه الأسطح على هاتف فعلي ولا على قاعدة بيانات
إنتاج. اختبارات الودجت تعمل مقابل ناقل بيانات وهمي — تُثبت أن الشاشات تُبنى وتُعرَض
وتتعامل مع JSON حقيقي، ولا تُثبت حجزاً حقيقياً ولا تسجيل دخول إنتاجياً.

---

## 6. النشر / Deployment

1. ارفع ملفات الموقع (الموقع + `api/v1/` + `includes/` + `admin/`).
2. طبّق `database/upgrade_v71.sql` (الخطوة 9.4 في دليل النشر).
3. شغّل `php database/repair/02_migration_ledger.php` وتأكد أنه ينتهي بـ `0`.
4. من لوحة الإدارة → الدعم: اضبط القنوات وساعات العمل، وأنشئ فئات التذاكر ومواعيد الدعم.
5. لا حاجة لإعادة بناء تطبيق العميل إن لم تُنشَر الأسطح الجديدة فيه — لكن الشاشات الجديدة
   تتطلّب نسخة تطبيق محدّثة تعرضها.

---

## 7. المتبقّي / Remaining blockers

- **الموافقة على النشر مطلوبة** — لم يُنشَر شيء على الإنتاج ولم تُعدَّل قاعدة بياناته.
- **التطبيق:** الأسطح الجديدة تحتاج بناء APK/AAB ونشره ليصلها العميل.
- **التصنيفات على الإنتاج:** تتطلّب تطبيق `upgrade_v59.sql` وتشغيل المستورد (انظر §4).
- **إعداد المشغّل:** قنوات الدعم وفئات التذاكر ومواعيد الدعم تُنشأ يدوياً بعد الترحيل.
