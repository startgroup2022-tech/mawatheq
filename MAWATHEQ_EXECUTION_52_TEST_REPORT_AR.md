# تقرير الاختبارات — مواثيق التنفيذ 52
# MAWATHEQ Execution 52 — Test Report

**التاريخ / Date:** 2026-10-23
**الفرع / Branch:** `exec43-home-slider`
**الإصدار / Version:** Android `1.5.0` (versionCode `12`)
**حزمة التطبيق / Package:** `com.mawatheq.app`

---

## 1. الملخّص / Summary

| المجال | النتيجة |
| --- | --- |
| `flutter analyze` | **نظيف** — No issues found |
| اختبارات Flutter | **253 / 253 ناجح** |
| لقطات الشاشة (goldens) | **13 / 13 ناجحة** |
| اختبار حذف الحساب (PHP) | **21 / 21 ناجح** |
| استيراد التصنيف القضائي | **7 / 38 / 234 / 640 — مطابق** |
| بناء APK | **ناجح — موقّع بمفتاح الإصدار** |

---

## 2. فحص الشيفرة الساكنة / Static analysis

```
$ flutter analyze
Analyzing mobile...
No issues found! (ran in 12.2s)
```

---

## 3. اختبارات Flutter / Flutter tests

```
$ flutter test
00:34 +253: All tests passed!
```

تشمل الاختبارات الجديدة والمحدّثة:

| الملف | ما يثبته |
| --- | --- |
| `exec43_home_slider_test.dart` | السلايدر متحرّك، الأزرار، التخصصات، RTL، الوضع الداكن |
| `exec40_home_actions_test.dart` | صف الأزرار يبقى مضغوطاً ومقروءاً |
| `exec52_app_version_test.dart` | **جديد** — الإصدار المعروض يطابق `pubspec.yaml` |

### إصلاح حقيقي كشفه الاختبار
كانت شاشة الحساب تطبع إصداراً ثابتاً `1.0.0` بينما الحزمة تشحن `1.4.0`، فأصبحت تقرأ `AppConfig.appVersion`، والاختبار الجديد يقرأ `pubspec.yaml` ويفشل إن تباعد الرقمان.

---

## 4. اختبار حذف الحساب / Account deletion test

```
$ php tests/exec52_account_deletion_test.php
== 1. wrong password is refused, account untouched ==
  ok:   wrong password -> wrong_password
  ok:   email unchanged after a failed attempt
  ok:   status unchanged after a failed attempt
== 2. empty password is refused ==
  ok:   empty password -> missing_password
== 3. a real deletion anonymises the account ==
  ok:   deletion succeeded
  ok:   the row still exists (not hard-deleted)
  ok:   the email was replaced by a tombstone
  ok:   the tombstone is unique to the account
  ok:   the name was anonymised
  ok:   the phone was cleared
  ok:   the reset token was cleared
  ok:   the status can no longer log in
  ok:   the password no longer verifies
  ok:   deleted_at records the time
== 4. dependent rows are preserved ==
  ok:   the invoice survived the deletion
== 5. every API token is revoked ==
  ok:   the token is revoked
== 6. a tombstoned address frees the original for re-registration ==
  ok:   the same address can register again
== 7. an account with work in flight is refused, with blockers ==
  ok:   deletion refused while a case is open
  ok:   at least one blocker is reported
  ok:   the refused account is still active
== cleanup ==
  ok:   no probe rows remain

21 checks, 0 failure(s)
```

### الخصائص الأمنية المُثبتة
1. **كلمة المرور مطلوبة** — جلسة صالحة أو رمز Bearer لا يكفي.
2. **الفاتورة تنجو من الحذف** — هذا هو مبرّر إخفاء الهوية بدل الحذف النهائي؛ كل مفاتيح الربط على `invoices`/`cases`/`consultations` هي `ON DELETE CASCADE`، فالحذف النهائي يمحو سجل الطرف الآخر.
3. **كل رموز الـ API تُبطَل** — الجهاز القديم لا يستطيع القراءة بعدها.
4. **البريد القديم يتحرّر** للتسجيل من جديد، مع بقاء قيد `UNIQUE(email)` سليماً.

### خطآن حقيقيان كشفهما الاختبار
| الخطأ | الأثر | الإصلاح |
| --- | --- | --- |
| `cases.status NOT IN ('closed','archived')` | لا يوجد هذان القيمتان في الـ enum، فكل قضية تُحسب مفتوحة → **لا يستطيع أي عميل حذف حسابه أبداً** | `status IN ('open','in_review')` |
| `invoices.status IN ('unpaid','partial')` | القيمة الحقيقية `partially_paid` | تصحيح القيمة |

---

## 5. اختبار المسار الكامل عبر HTTP / End-to-end HTTP test

### حذف الحساب — الويب
```
account.php                                      200  13211 bytes
logged in?                                       1
--- wrong confirm ---
302 redirect=http://127.0.0.1:8080/account.php
--- correct ---
302 redirect=http://127.0.0.1:8080/login.php?deleted=1
--- db ---
id: 7 | full_name: حساب محذوف | email: deleted+7-00490f5b@deleted.invalid
status: suspended | deleted_at: 2026-10-24 07:15:01
```

### حذف الحساب — الـ API
| الحالة | المتوقع | الفعلي |
| --- | --- | --- |
| بدون `confirm` | 400 | **400** ✅ |
| كلمة مرور خاطئة | 401 | **401** ✅ |
| صحيح | 200 `{"ok":true,"deleted":true}` | **200** ✅ |
| الرمز القديم بعد الحذف | 401 | **401** ✅ |
| تسجيل الدخول بالبيانات القديمة | 401 | **401** ✅ |

---

## 6. استيراد التصنيف القضائي / Taxonomy import

نُفِّذ على قاعدة بيانات نظيفة (`mawatheq_taxprobe`) بالترتيب الموثّق:

```
schema.sql                     → 107 جدولاً
upgrade_v59.sql                → جداول najiz_*
upgrade_v63.sql                → أعمدة الوصف
MAWATHEQ_TAXONOMY_IMPORT.sql   → الاستيراد
upgrade_v63.sql (مرة أخرى)     → ملء الأوصاف
```

**النتيجة:**
```
main_specialties  sub_specialties  case_types  judicial_requests
7                 38               234         640
```
وبعد إعادة `v63`: **7 من 7 أوصاف مملوءة** (`customer_desc_ar`).

### حارس المتطلبات المسبقة
عند حذف جداول `najiz_*` قبل الاستيراد، يوقف الملف نفسه:
```
ERROR 1644 (45000) at line 48: PREREQUISITE FAILED: run upgrade_v59.sql first.
Missing: najiz_categories, najiz_subcategories, najiz_case_types, najiz_judicial_requests
```
✅ لا يترك بيانات ناقصة.

### التكرار الآمن
إعادة تشغيل الملف تمسح وتعيد الإدراج داخل معاملة واحدة؛ المعرّفات ثابتة فلا تتأثر القضايا المرتبطة.

---

## 7. السلايدر الترويجي / The promotional carousel

فحص الشيفرة يثبت أنه سلايدر حقيقي وليس بطاقتين ثابتتين:

| الخاصية | التنفيذ |
| --- | --- |
| بانر واحد في كل مرة | `PageView.builder` |
| انتقال تلقائي 4–5 ثوانٍ | `Timer` بـ **4500ms** (`_interval`) |
| سحب يدوي | `PageView` (تمرير أفقي) |
| مؤشرات الترقيم | `_Dots(count, active)` |
| RTL | اتجاه النص من `LocaleController`، والحجاب من الحافة اليمنى |
| تقليل الحركة | يتوقف عند `MediaQuery.disableAnimationsOf` |
| تسرّب الذاكرة | `Timer` قابل للإلغاء في `dispose` |

الاختبارات: 24 اختباراً في `exec43_home_slider_test.dart`، تشمل «the slider moves on its own» و«Arabic copy is anchored to the RIGHT edge».

---

## 8. الأزرار والتخصصات / Buttons and specialties

| العنصر | الموقع | الوجهة |
| --- | --- | --- |
| **احجز استشارة** | `home_tab.dart:425` | `ConsultationJourneyScreen` (تدفّق حجز حقيقي) |
| **اسأل المساعد الذكي** | `home_tab.dart:434` | `AssistantScreen` (مساعد ذكي حقيقي) |
| **اختر تخصصك القانوني** | `home_tab.dart:221` | شبكة التخصصات الفعلية |

تعمل للزوّار والمستخدمين المسجّلين (`exec43_home_slider_test.dart` يغطي الحالتين).

---

## 9. توقيع الحزمة / APK signing

```
$ apksigner verify --print-certs app-release.apk
Signer #1 certificate DN: CN=Mawatheq Legal Platform, OU=Mobile, O=Mawatheq,
                         L=Riyadh, ST=Riyadh, C=SA
SHA-256: 5721bf0cfdd9f6fcef5dc3baf86b21c9ec2656c2ad4fb06a3cda189a355ecd09

Verified using v2 scheme (APK Signature Scheme v2): true
```

**موقّع بمفتاح الإصدار الحقيقي** (لا بمفتاح التصحيح). المفتاح خارج المستودع ومستثنى من Git.

> **ملاحظة للتحقق من App Links:** بصمة مفتاح التوقيع أعلاه يجب لصقها في
> `.well-known/assetlinks.json` مكان `REPLACE_WITH_RELEASE_SHA256_FINGERPRINT`
> ليعمل فتح روابط إعادة تعيين كلمة المرور داخل التطبيق.

---

## 10. ما لم يُختبر / What is NOT proven

هذا مهم بقدر ما سبقه. الاختبارات أعلاه لا تُثبت:

- **الحجز الفعلي على الإنتاج** — الاختبارات تستخدم بيانات وهمية (fake transport).
- **تسجيل الدخول على الإنتاج** — لم يُجرَّب من هذه الجلسة.
- **رفع مستند حقيقي** أو **استدعاء ذكاء اصطناعي حقيقي**.
- **استيراد قاعدة بيانات الإنتاج** — خطوة معلّقة تحتاج تفويض المالك.
- **التحقق من App Links على جهاز حقيقي** — يحتاج البصمة في `assetlinks.json` أولاً.

مجموعة اختبارات خضراء ليست بديلاً عن هذه الفحوص.
