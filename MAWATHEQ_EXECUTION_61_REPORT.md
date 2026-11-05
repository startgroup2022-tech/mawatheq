# مواثيق — التقرير 61: حزمتا إصدار مستقلتان لـ Google Play
# MAWATHEQ — Execution 61: Two Independent Google Play Bundles

**الإصدار / Release:** `v2026.11.05-exec61` (يحل محل `v2026.11.05-exec60`)
**الفرع / Branch:** `exec56-lawyer-platform`
**التاريخ / Date:** 2026-11-05
**الحالة / Status:** الحزم مبنية ومُتحقَّق منها — **غير موقّعة بمفتاح الإصدار** (انظر القسم 3).

---

## 1. الملخص التنفيذي / Executive summary

الهدف: إنتاج **حزمتي Android App Bundle (AAB)** جاهزتين لـ Google Play:

| # | التطبيق | applicationId | الحزمة |
|---|---|---|---|
| A | تطبيق العملاء (منشور سابقًا على Play) | `com.mawatheq.app` | `MAWATHEQ_CLIENT_v1.7.0+17_release.aab` |
| B | تطبيق المحامين (أول إصدار) | `com.mawatheq.lawyer` | `MAWATHEQ_LAWYER_v1.1.0+2_release.aab` |

كلا الحزمتين بُنيتا بنجاح من `--release`، وتم التحقق من الهوية (package / versionCode /
versionName / minSdk / targetSdk)، ومن قائمة الأذونات، ومن أن `usesCleartextTraffic=false`
وأن التطبيق غير قابل للتصحيح (`debuggable` غائب).

**القيد الوحيد الحاسم:** لا يوجد مفتاح رفع (upload key) في المستودع ولا على جهاز البناء،
فكلتا الحزمتين موقّعتان بمفتاح **Android Debug**. هذه الحزم صالحة للاختبار الداخلي فقط ولا
يمكن رفعها إلى Play إلا بمفتاح المالك. التفاصيل في القسم 3.

---

## 2. جدول الحزم / Artifact manifest

| البند | تطبيق العملاء | تطبيق المحامين |
|---|---|---|
| الملف | `MAWATHEQ_CLIENT_v1.7.0+17_release.aab` | `MAWATHEQ_LAWYER_v1.1.0+2_release.aab` |
| applicationId | `com.mawatheq.app` | `com.mawatheq.lawyer` |
| versionName | `1.7.0` | `1.1.0` |
| versionCode | `17` | `2` |
| minSdkVersion | `24` (Android 7.0) | `24` (Android 7.0) |
| targetSdkVersion | `36` | `36` |
| compileSdkVersion | `37` | `37` |
| الحجم (بايت) | 78,384,968 | 79,775,461 |
| الحجم (مقروء) | 78.4 MB | 79.8 MB |
| SHA-256 | `c541c323bd3e73dc61fe201973d7ed27e8af803d3389c213a4fe3b5e5878769f` | `761e10a1ccf382d1328385aa0ea9ae9f2c4f24438be3bbdd911f74cf0daa6728` |
| التوقيع | Android Debug ⚠️ | Android Debug ⚠️ |
| اسم التطبيق (label) | مواثيق | مواثيق للمحامين |

> تحقّق بصمة الملف: `sha256sum -c MAWATHEQ_EXECUTION_61_COMPLETE.sha256`.

### الأذونات (متطابقة بين التطبيقين، ولا جديد عن الإصدار السابق)

`INTERNET`, `ACCESS_NETWORK_STATE`, `CAMERA`, `RECORD_AUDIO`, `MODIFY_AUDIO_SETTINGS`,
`BLUETOOTH`, `POST_NOTIFICATIONS`, `RECEIVE_BOOT_COMPLETED`, `SCHEDULE_EXACT_ALARM`,
`VIBRATE` (و`WAKE_LOCK` إضافي في تطبيق المحامين من مكدّس الاتصال).

مجموعة أذونات تطبيق العملاء **مطابقة بايت-ببايت** لما نُشر في `exec59` — لم يُضَف أي إذن.

---

## 3. ⚠️ قيد التوقيع — اقرأ هذا قبل الرفع / Signing blocker

**الوضع:** لا يوجد `*.jks` / `*.keystore` ولا `android/key.properties` في المستودع ولا على
الجهاز (كلها في `.gitignore`). لذلك كلا الحزمتين وقعتهما `build.gradle.kts` بمفتاح التصحيح
الافتراضي:

```
Owner: C=US, O=Android, CN=Android Debug
SHA256: 25:46:02:19:A1:7B:48:8A:9E:1F:7A:51:F3:86:83:0E:AD:AC:AD:CA:D7:61:52:1C:B5:A8:1D:C2:8D:13:D7:EF
```

**الأثر:**

- Google Play **يرفض** حزمة موقّعة بمفتاح التصحيح.
- بصمة روابط التطبيق (App Links) في `/.well-known/assetlinks.json` لن تطابق التطبيق.

**ما يلزم من المالك (خطوة واحدة لكل تطبيق):**

1. مفتاح رفع الإنتاج (JKS) + الاسم المستعار (alias) + كلمتا المرور، أو تفعيل
   **Play App Signing** والسماح لجوجل بإدارة المفتاح.
2. ضع المفتاح في `android/key.properties` (غير مُتتبَّع في git):
   ```properties
   storeFile=/absolute/path/upload-key.jks
   storePassword=********
   keyAlias=mawatheq-upload
   keyPassword=********
   ```
3. أعد البناء: `flutter build appbundle --release` — ستُوقَّع الحزمة بمفتاح الإصدار تلقائيًا
   (المنطق موجود في `android/app/build.gradle.kts` عبر `hasReleaseKeystore`).

> **للتطبيق المنشور سابقًا (`com.mawatheq.app`):** إن كان Play App Signing مفعّلًا، فمفتاح
> الرفع المسجّل سابقًا هو نفسه الذي يجب استخدامه الآن، وبصمته المسجّلة في التقرير 51:
> `57:21:BF:0C:FD:D9:F6:FC:EF:5D:C3:BA:F8:6B:21:C9:EC:26:56:C2:AD:4F:B0:6A:3C:DA:18:9A:35:5E:CD:09`.
> إن كان هذا المفتاح مفقودًا، فالطريق الوحيد لتحديث التطبيق هو **طلب تغيير مفتاح الرفع**
> (upload key reset) من Play Console → App integrity.

---

## 4. استراتيجية الإصدار / Version strategy

| التطبيق | قبل | بعد | السبب |
|---|---|---|---|
| العملاء | `1.6.1+16` | **`1.7.0+17`** | ترقية minor لإصدار Play جديد؛ `versionCode` أعلى من كل بناء سابق (أعلى قيمة تاريخية = 16) |
| المحامين | `1.1.0+2` | **`1.1.0+2`** | لم يُنشر على Play قط، ولا يوجد سجل يُلزمنا بتغييره؛ الإصدار الحالي يطابق نسخة التطبيق النشطة |

سجل `versionCode` لتطبيق العملاء (من تاريخ git): `1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16`
— لذا `17` آمن ولا يتعارض مع أي بناء سابق. القيمة الأخيرة الموثّقة في تقرير Play = `11`.

تمت مزامنة `appVersion` داخل التطبيق مع `pubspec.yaml`:
- العملاء: `app_config.dart` → `'1.7.0'`
- المحامين: `lawyer_config.dart` → `'1.1.0'` (دون تغيير)

---

## 5. فصل التطبيقين / App separation

| الجانب | تطبيق العملاء | تطبيق المحامين |
|---|---|---|
| نقطة الدخول | `/api/v1/auth/login.php` | `/api/v1/auth/lawyer-login.php` |
| حارس الدور | واجهات العميل | `AuthStatus.signedIn when auth.user.isLawyer` وإلا شاشة دخول المحامي |
| الشاشة الرئيسية | `HomeShell` | `LawyerShell` |
| سطح العميل | موجود | **غير موجود إطلاقًا** |
| الاستدعاءات (WebRTC/Jitsi) | `features/calls/` | نفس المكدّس عبر حزمة `mawatheq_mobile` |

تطبيق المحامين **لا يعرض أي واجهة عميل**: أي رمز غير صالح أو رمز عميل يُعاد إلى شاشة دخول
المحامي، والدور يُحدَّد من الخادم لا من عَلَم محلي. التطبيقان مستقلان تمامًا في
`applicationId` وفي واجهات الدخول.

---

## 6. تغيير المصدر في هذا الإصدار / Source change

التغيير الوحيد في كود التطبيق (بخلاف أداة لقطات الشاشة ورقم الإصدار):

**إصلاح تجاوز (overflow) في `LoadingSkeleton`** —
`apps/mobile/lib/shared/widgets/brand_widgets.dart`.

كان الهيكل عمودًا (`Column`) من بلاطات بارتفاع 76px + هامش. على هاتف 360×640 تبقى
~470px تحت شريط التطبيق وشريط التنقل السفلي، بينما ست بلاطات (تُستخدم في لوحة المحامي)
تتجاوز ذلك بـ **56px** — فيظهر شريط التحذير الأصفر/الأسود في وضع التصحيح.

الإصلاح: عند وجود ارتفاع محدود من الأب، تُرسم البلاطات داخل `ListView` غير قابل للتمرير
(فلا تُبنى الزائدة عن الحاجة)، وعندما يكون الأب قابلًا للتمرير (ارتفاع غير محدود) يبقى
`Column` كما هو. الإصلاح عام لكل شاشات التحميل، وليس خاصًّا بلوحة المحامي.

هذا التغيير في كود التطبيق هو سبب **إعادة بناء الحزمتين** بعد أول بناء.

---

## 7. لقطات الشاشة / Screenshots

أداة اللقطات (`tool/screenshots/capture_*_screens.dart`) تدعم الآن وضع متجر Google Play:

- الافتراضي: `1080×2280` (نسبة 2.11:1) — **تتجاوز** سقف Play البالغ 2:1.
- وضع المتجر: `MAWATHEQ_STORE_SHOTS=1` → `1080×1920` (9:16، داخل الحدود) إلى
  `goldens_store/` و`goldens_lawyer_store/` دون المساس باللقطات المثبَّتة.

النتيجة: **13** لقطة لتطبيق العملاء + **6** لتطبيق المحامين، جميعها `1080×1920`.
نسخة المتجر منسوخة في `docs/exec61/store/`.

> أُعيد توليد لقطتين مثبَّتتين (`07_journey_lawyers`, `12_journey_no_lawyer`) لأنهما كانتا
> قديمة (آخر تحديث 2026-10-23) بينما غيّرت Execution 60 شاشة الرحلة في 2026-11-05.

---

## 8. أصول المتجر / Store assets (جاهزة)

| الأصل | المسار | المقاس |
|---|---|---|
| أيقونة تطبيق العملاء | `docs/exec61/store/client_play_icon_512.png` | 512×512 |
| أيقونة تطبيق المحامين | `docs/exec61/store/lawyer_play_icon_512.png` | 512×512 |
| صورة ترويجية (عملاء) | `docs/exec61/store/client_feature_graphic_1024x500.png` | 1024×500 |
| صورة ترويجية (محامين) | `docs/exec61/store/lawyer_feature_graphic_1024x500.png` | 1024×500 |
| لقطات العملاء (13) | `docs/exec61/store/client_screenshots/` | 1080×1920 |
| لقطات المحامين (6) | `docs/exec61/store/lawyer_screenshots/` | 1080×1920 |

---

## 9. نص قائمة المتجر / Play listing copy

### تطبيق العملاء

- **الاسم (AR):** مواثيق — استشارات قانونية
- **الاسم (EN):** Mawatheq — Legal Consultations
- **الوصف المختصر (AR، ≤80 حرفًا):** استشارات قانونية موثوقة، حجز فوري، ومتابعة قضاياك.
- **الوصف المختصر (EN، ≤80):** Trusted legal advice, instant booking, and case tracking.

**الوصف الكامل (AR):**

```
مواثيق منصة قانونية سعودية تربطك بمحامين معتمدين.

• اختر تخصصك القانوني واحجز استشارة في دقائق.
• فلترة المحامين حسب التخصص والدولة والمدينة وسنوات الخبرة والسعر.
• استشارات مرئية وصوتية عبر مكالمة آمنة داخل التطبيق.
• تابع قضاياك ومستنداتك وفواتيرك في مكان واحد.
• مساعد ذكي للإجابة عن أسئلتك القانونية العامة.
• دعم كامل للغة العربية واتجاه RTL، مع وضع فاتح وداكن.

جميع المحامين معتمدون من المنصة. مواثيق لا تغني عن الاستشارة القانونية المتخصصة.
```

**الوصف الكامل (EN):**

```
Mawatheq is a Saudi legal platform connecting you with verified lawyers.

• Pick your legal specialty and book a consultation in minutes.
• Filter lawyers by specialty, country, city, years of experience and price.
• Video and voice consultations over a secure in-app call.
• Track your cases, documents and invoices in one place.
• An AI assistant for general legal questions.
• Full Arabic RTL support with light and dark themes.

All lawyers are platform-approved. Mawatheq does not replace specialist legal advice.
```

### تطبيق المحامين

- **الاسم (AR):** مواثيق للمحامين
- **الاسم (EN):** Mawatheq for Lawyers
- **الوصف المختصر (AR):** لوحة عمل المحامي: القضايا والاستشارات والأرباح.

**الوصف الكامل (AR):**

```
لوحة عمل متكاملة للمحامي على منصة مواثيق.

• استقبل طلبات الاستشارات وأدرها من مكان واحد.
• تابع قضاياك وعملاءك ومهامك ومستنداتك.
• استشارات مرئية وصوتية عبر مكالمة آمنة داخل التطبيق.
• تابع أرباحك ومحفظتك وطلبات السحب.
• إشعارات فورية للطلبات والمواعيد الجديدة.

الدخول متاح للمحامين المعتمدين على منصة مواثيق فقط.
```

**الوصف الكامل (EN):**

```
A complete workspace for lawyers on the Mawatheq platform.

• Receive and manage consultation requests in one place.
• Track your cases, clients, tasks and documents.
• Video and voice consultations over a secure in-app call.
• Monitor your earnings, wallet and payout requests.
• Push notifications for new requests and appointments.

Sign-in is available to approved Mawatheq lawyers only.
```

### ملاحظات الإصدار / Release notes

**العملاء (1.7.0):**
```
• فلترة احترافية للمحامين: التخصص، الدولة، المدينة، سنوات الخبرة، ونطاق السعر.
• عرض عدد المحامين المطابقين وزر لمسح الفلاتر.
• إصلاح تجاوز التخطيط على الشاشات الصغيرة.
• تحسينات في الأداء والاستقرار.
```

**المحامين (1.1.0):**
```
• أول إصدار من تطبيق مواثيق للمحامين.
• لوحة عمل: الاستشارات، القضايا، المحفظة، والملف الشخصي.
• استشارات مرئية وصوتية عبر مكالمة آمنة داخل التطبيق.
• إشعارات فورية ووضع فاتح وداكن.
```

---

## 10. نموذج أمان البيانات (مسودة) / Data safety (draft)

يجب على المالك تأكيد الإجابات داخل Play Console؛ هذه مسودة مبنية على ما يرسله التطبيق فعلًا:

| السؤال | الإجابة |
|---|---|
| يجمع بيانات؟ | نعم |
| بيانات شخصية | الاسم، البريد الإلكتروني، رقم الهاتف |
| بيانات مالية | سجل المشتريات (الفواتير) — لا تُجمع أرقام البطاقات داخل التطبيق (تُعالَج لدى البوابة) |
| الرسائل | لا |
| الصور/الفيديو | الصور التي يرفعها المستخدم (مستندات، مرفقات) |
| الملفات والمستندات | نعم (مستندات القضية) |
| الموقع | لا |
| تشفير أثناء النقل | نعم (HTTPS فقط؛ `usesCleartextTraffic=false`) |
| حذف البيانات | متاح عبر طلب للدعم |

> أذونات الكاميرا والميكروفون تُستخدم فقط أثناء مكالمة الاستشارة داخل التطبيق.

---

## 11. الأدلة الوظيفية / Test evidence

| الفحص | الأمر | النتيجة |
|---|---|---|
| تحليل تطبيق العملاء | `flutter analyze` | نظيف — 0 مشاكل |
| اختبارات تطبيق العملاء | `flutter test` | **316** اختبارًا ناجحًا |
| تحليل تطبيق المحامين | `flutter analyze` | نظيف — 0 مشاكل |
| اختبارات تطبيق المحامين | `flutter test` | **8** اختبارات ناجحة |
| لقطات العملاء (مثبَّتة) | `flutter test tool/screenshots/capture_screenshots.dart` | 13/13 |
| لقطات المحامين (مثبَّتة) | `flutter test tool/screenshots/capture_lawyer_screens.dart` | 6/6 |
| لقطات المتجر (عملاء) | `MAWATHEQ_STORE_SHOTS=1 ...` | 13/13 @ 1080×1920 |
| لقطات المتجر (محامين) | `MAWATHEQ_STORE_SHOTS=1 ...` | 6/6 @ 1080×1920 |

**تحقق الهوية:** `bundletool dump manifest` على كل حزمة (انظر القسم 2).
**تحقق التوقيع:** `jarsigner -verify -verbose -certs` على كل حزمة.

### ما لم يُختبر / Not tested

- **لم يُجرَّب على جهاز/هاتف حقيقي.** كل الأدلة أعلاه من أداة Flutter على سطح اختبار
  افتراضي، ومن تحليل ثابت للحزم. لم تُجرَّب مكالمة Jitsi حقيقية ولا حجز حقيقي على جهاز.
- **لم يُجرَّب الرفع إلى Play** (محجوب بمفتاح الإصدار).
- اختبارات PHP/الخادم الخاصة بـ Execution 60 (فلترة الحجز، حقول المحامي) لم تُعَد هنا؛
  راجع `MAWATHEQ_EXECUTION_60_REPORT.md`.

---

## 12. خطوات النشر / Deployment instructions

### أ. بناء الحزم الموقّعة (بعد استلام المفتاح)

```bash
export PATH="/workspace/flutter/bin:$PATH"
export ANDROID_HOME=/workspace/android-sdk
export JAVA_HOME=/usr/lib/jvm/java-21-openjdk-amd64

# ضع key.properties كما في القسم 3، ثم:
cd apps/mobile        && flutter pub get && flutter build appbundle --release
cd ../lawyer_mobile   && flutter pub get && flutter build appbundle --release
```

الناتج: `build/app/outputs/bundle/release/app-release.aab` في كل مجلد.

### ب. التحقق قبل الرفع

```bash
# الهوية
java -jar bundletool.jar dump manifest --bundle=app-release.aab | grep -E "versionCode|versionName|package"
# التوقيع — يجب أن يظهر اسم المالك لا "Android Debug"
jarsigner -verify -verbose -certs app-release.aab | grep -E "CN=|SHA256"
```

### ج. الرفع إلى Play Console

1. Play Console → تطبيق العملاء → **Production → Create new release**.
2. ارفع `MAWATHEQ_CLIENT_v1.7.0+17_release.aab`.
3. الصق ملاحظات الإصدار من القسم 9.
4. أضف الأصول من `docs/exec61/store/client_screenshots/` والأيقونة والصورة الترويجية.
5. كرّر الخطوات لتطبيق المحامين (`com.mawatheq.lawyer`) — **تطبيق جديد**، يتطلب إنشاء
   تطبيق مستقل في Play Console ثم رفع `MAWATHEQ_LAWYER_v1.1.0+2_release.aab`.
6. بعد النشر: انسخ بصمة **مفتاح توقيع جوجل** (SHA-256) وحدّثها في
   `/.well-known/assetlinks.json` (انظر التقرير 51، القسم 4).

---

## 13. القوائم النهائية / Checklists

### ما تم في Execution 61
- [x] بناء حزمتي إصدار مستقلتين (`com.mawatheq.app` + `com.mawatheq.lawyer`).
- [x] التحقق من الهوية والأذونات وسلوك الشبكة (HTTPS فقط).
- [x] ترقية إصدار تطبيق العملاء إلى `1.7.0+17` ومزامنة نسخة التطبيق.
- [x] إصلاح تجاوز التحميل على الشاشات الصغيرة (360×640).
- [x] وضع لقطات متجر بمقاس صالح لـ Play (1080×1920) — 13 + 6 لقطة.
- [x] أيقونتا متجر 512×512 وصورتان ترويجيتان 1024×500 للتطبيقين.
- [x] نص قائمة المتجر (AR/EN) وملاحظات الإصدار للتطبيقين.
- [x] مسودة نموذج أمان البيانات.
- [x] `flutter analyze` نظيف و`flutter test` أخضر للتطبيقين (316 + 8).

### المتبقّي على المالك (Blockers)
- [ ] **مفتاح رفع الإنتاج** (أو تفعيل Play App Signing) — بدونه لا يمكن الرفع.
- [ ] إعادة البناء بعد إضافة `key.properties` (خطوة واحدة).
- [ ] إنشاء تطبيق المحامين في Play Console (أول إصدار).
- [ ] سياسة خصوصية على رابط عام + تعبئة App content.
- [ ] تحديث `assetlinks.json` ببصمة مفتاح توقيع جوجل بعد النشر.
- [ ] تجربة على جهاز حقيقي (مكالمة Jitsi + حجز) قبل النشر العام.
