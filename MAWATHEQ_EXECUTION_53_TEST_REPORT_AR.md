# تقرير الاختبارات — مواثيق التنفيذ 53
# MAWATHEQ Execution 53 — Test Report

**التاريخ / Date:** 2026-10-24
**الفرع / Branch:** `exec43-home-slider`
**الإصدار / Version:** Android `1.5.1` (versionCode `13`)
**حزمة التطبيق / Package:** `com.mawatheq.app`
**آخر إيداع مُختبر / Tested commit:** `51ea63f`

---

## 1. الملخّص / Summary

| المجال | النتيجة |
| --- | --- |
| `flutter analyze` | **نظيف** — No issues found |
| اختبارات Flutter | **253 / 253 ناجح** |
| اختبار الشريط والزرّين (`exec43_home_slider_test`) | **17 / 17 ناجح** |
| لقطات الشاشة (goldens) | **13 / 13 ناجحة** |
| مجموعة Playwright (متصفّح، 6 مقاسات، RTL+LTR) | **666 ناجح / 6 متخطّى / 0 فاشل** |
| مسح رأس الصفحة (16 عرضًا، 320–1920px) | **0 فشل** |
| استيراد التصنيف القضائي | **7 / 38 / 234 / 640 — مطابق** |
| بناء APK | **ناجح — موقّع بمفتاح الإصدار (v2)** |

---

## 2. فحص الشيفرة الساكنة / Static analysis

```
$ flutter analyze
Analyzing mobile...
No issues found! (ran in 12.7s)
```

أُصلح تحذير واحد قائم: استيراد غير مستخدم لـ `package:flutter/material.dart`
في `test/exec52_app_version_test.dart`.

---

## 3. اختبارات Flutter / Flutter tests

```
$ flutter test
00:28 +253: All tests passed!
```

**253 اختبارًا، 0 فشل.** من بينها `test/exec43_home_slider_test.dart` (17 اختبارًا)
الذي يُثبّت بالضبط ما رفضه المالك:

| الاختبار | ما يتحقق منه |
| --- | --- |
| `a fresh install with no banners still renders the carousel` | الشريط يظهر حتى لو كان `home_banners` فارغًا |
| `every built-in slide photo is bundled, not silently a gradient` | كل شريحة لها صورة حقيقية مرفقة داخل التطبيق |
| `operator banners replace the built-in slides` | شريحة المشغّل تحلّ محل الشريحة المدمجة |
| `advances to the next slide after the dwell interval` | **انتقال تلقائي فعلي** بعد 4.5 ثانية |
| `a manual swipe moves the slider too` | **سحب يدوي أفقي** يعمل |
| `both buttons sit below the slider and are labelled` | الزرّان أسفل الشريط بنصّيهما |
| `the booking button opens the real journey` | **احجز استشارة** → مسار الحجز الفعلي |
| `the assistant button opens the real assistant` | **اسأل المساعد الذكي** → المساعد الفعلي |
| `a guest is asked to sign in, and the destination is remembered` | وضع الزائر |
| `the seven real categories show when the backend has none` | التخصصات السبع |
| `the imported taxonomy reaches the screen through the real client` | التصنيف المستورد يصل للشاشة عبر العميل الحقيقي |
| `the carousel lays out in RTL for Arabic` | اتجاه RTL |
| `Arabic copy is anchored to the RIGHT edge of its slide` | النص العربي على الحافة اليمنى |
| `every built-in slide keeps its copy inside the slide frame` | لا نصّ يخرج من إطار الشريحة |
| `the built-in slider renders in the dark theme` | الوضع الداكن |

---

## 4. لقطات الشاشة / Screenshot goldens

```
$ flutter test tool/screenshots/capture_screenshots.dart --update-goldens
00:04 +13: All tests passed!
```

**13 / 13.** كل لقطة تتحقق من وجود النص **قبل** الرسم، فلقطة شاشة فارغة تُفشل
الاختبار ولا تُنتج صورة خضراء بلا معنى.

اللقطات المتعلقة بهذا التنفيذ:

| الملف | المحتوى |
| --- | --- |
| `01_guest_home.png` | الرئيسية للزائر — الشريط والزرّان والتخصصات |
| `10_guest_home_fresh_slider.png` | تركيب نظيف بلا شرائح مشغّل — الشريط المدمج |
| `11_guest_home_imported_taxonomy.png` | التصنيف المستورد على الشاشة |
| `02_signed_in_home.png` | الرئيسية لمستخدم مسجّل |
| `03_signed_in_home_dark.png` | الوضع الداكن |

### إثبات أن الشريط ليس بطاقتين ثابتتين

قيس عدد الألوان الفريدة في نطاق الشريط من اللقطة `01_guest_home.png`
(1080×2280): أعلى نطاق بلغ **1376 لونًا فريدًا**. البطاقة المسطّحة تعطي عشرات
قليلة؛ العدد المرتفع يعني صورة فوتوغرافية حقيقية بتدرّج — أي شريط فعلي.

---

## 5. اختبارات المتصفّح / Browser suite

```
$ MAWATHEQ_BASE_URL=http://127.0.0.1:8080 npx playwright test
  6 skipped
  666 passed (6.1m)
```

**666 ناجح، 6 متخطّى، 0 فاشل** — سبع مجموعات عبر ستة مقاسات
(320×568, 375×812, 768×1024, 1024×768, 1440×900, 1920×1080) بالعربية والإنجليزية.

---

## 6. رأس الصفحة — قبل/بعد / The header, before and after

شُخّص العطل على الموقع الحقيقي قبل أي تعديل:

| العطل | القياس |
| --- | --- |
| روابط القائمة تخرج من صندوقها وتتراكب مع الشعار والأزرار | من 1200px وأعلى: الروابط خارج صندوقها بـ **13px**، `hitBrand=true` و `hitAct=true` |
| أشرطة باهظة تعبر الرأس | `rgba(246,248,250,.94)` + `backdrop-filter:blur(8px)` فوق توهّج البطل |

بعد الإصلاح — 16 عرضًا من 320px إلى 1920px، بالعربية والإنجليزية:

```
ok    320 … ok   1199 nav=drawer
ok   1200 nav=inline esc=false hitBrand=false hitAct=false docOv=0
ok   1280 … ok   1920 nav=inline esc=false hitBrand=false hitAct=false docOv=0
0 failure(s)
```

الحدّ 1199/1200px مُقاس لا مُخمَّن، والطبقتان (`style.css` و `enterprise.css`)
تتفقان عليه — وإلا أعادت الطبقة المُحسِّنة فتح القائمة في نطاق لا يُتيحه التنسيق
الأساسي.

---

## 7. استيراد التصنيف القضائي / Taxonomy import

اختبار حقيقي على قاعدة بيانات اختبارية مستقلة:

| الخطوة | النتيجة |
| --- | --- |
| `schema.sql` + `upgrade_v59.sql` + `upgrade_v63.sql` | نجاح |
| `MAWATHEQ_TAXONOMY_IMPORT.sql` | `7 / 38 / 234 / 640` ✅ |
| `upgrade_v63.sql` (المرة الثانية) | 7 أوصاف + 7 أيقونات ✅ |
| إعادة تشغيل ملف الاستيراد | `7 / 38 / 234 / 640` بلا أي تغيير ✅ |
| تشغيل الملف على قاعدة بلا `najiz_*` | `ERROR 1644 … PREREQUISITE FAILED` وتوقف آمن ✅ |

فحص الواجهة الفعلي:

```
$ curl -s "http://127.0.0.1:8080/api/v1/journey.php?action=specialties"
specialties: 7
   1 الأحوال الشخصية      icon=family       desc=yes
   2 الدعاوى التجارية     icon=commercial   desc=yes
   3 الدعاوى العمالية     icon=labor        desc=yes
   4 الدعاوى الجزائية     icon=criminal     desc=yes
   5 الدعاوى العامة       icon=general      desc=yes
   6 دعاوى التنفيذ        icon=enforcement  desc=yes
   7 الدعاوى المتعلقة بالإنهاءات icon=endowments desc=yes
```

`lawyer_count = 0` صحيح ومقصود: جدول `lawyer_specialties` يُنشأ فارغًا، وربط
محامٍ بتخصص قرار يتخذه المالك في `/lawyer/specialties.php`.

---

## 8. بناء APK والتحقّق منه / APK build and verification

```
$ flutter build apk --release
✓ Built build/app/outputs/flutter-apk/app-release.apk (60.2MB)
```

```
$ aapt2 dump badging app-release.apk
package: name='com.mawatheq.app' versionCode='13' versionName='1.5.1'
targetSdkVersion:'36'
application-label:'مواثيق'
minSdkVersion:'24'
```

```
$ apksigner verify --print-certs --verbose app-release.apk
Verified using v2 scheme (APK Signature Scheme v2): true
Signer #1 certificate DN: CN=Mawatheq Legal Platform, OU=Mobile, O=Mawatheq,
                          L=Riyadh, ST=Riyadh, C=SA
Signer #1 certificate SHA-256 digest:
  5721bf0cfdd9f6fcef5dc3baf86b21c9ec2656c2ad4fb06a3cda189a355ecd09
```

موقّع **بمفتاح الإصدار الحقيقي** (وليس مفتاح التطوير). `minSdkVersion = 24`
(أندرويد 7.0) — مأخوذ من الملف المبني نفسه لا من تعليق.

**تحذيران معروفان وغير معطّلين:** Gradle 8.14.3 و AGP 8.11.1 سيُسحبان دعمهما
مستقبلًا (المطلوب Gradle ≥ 9.1 و AGP ≥ 9.0.1). هذه ترقية إصدار رئيسي لها
تغييراتها الكاسرة، ومؤجّلة عن قصد. البناء ينجح.

---

## 9. ما لم يُختبر / What is NOT covered

الشفافية مهمة هنا. الاختبارات أعلاه تعمل مقابل نقل بيانات وهمي (fake transport):

- **لم يُختبر** حجز استشارة فعلي على الإنتاج.
- **لم يُختبر** تسجيل دخول فعلي بحساب إنتاج.
- **لم يُختبر** رفع مستند حقيقي أو نداء ذكاء اصطناعي حقيقي.
- **لم يُختبر** تشغيل التطبيق على جهاز/محاكي فعلي في هذا التنفيذ.
- **لم يُنفَّذ** استيراد التصنيف على قاعدة بيانات الإنتاج — بانتظار إذن المالك.

مجموعة اختبارات خضراء تعني أن الشاشات تُبنى وتُعرض وتتعامل مع ردود JSON
مُعدّة مسبقًا. لا تعني أن مسار إنتاج حقيقي مرّ بنجاح.

---

## 10. الخلاصة / Conclusion

| البند المطلوب | الحالة |
| --- | --- |
| شريط ترويجي متحرك حقيقي (لا بطاقتان ثابتتان) | ✅ مُثبَت باختبارات ولقطة قياس ألوان |
| زرّان وظيفيان: احجز استشارة، اسأل المساعد الذكي | ✅ كل زر يفتح وجهته الفعلية |
| التخصصات القانونية تظهر عند وجود البيانات | ✅ 7 تخصصات مع الوصف والأيقونة |
| ملف SQL الدقيق + تعليمات آمنة | ✅ `MAWATHEQ_TAXONOMY_IMPORT.sql` |
| APK جديد منشور على GitHub بروابط متحقَّق منها | ✅ `v1.5.1+13` |

**لم يُعدَّل أي شيء على قاعدة بيانات الإنتاج.** استيراد التصنيف خطوة منفصلة
بانتظار إذن المالك.
