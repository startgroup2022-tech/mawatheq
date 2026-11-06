# مواثيق — التنفيذ 61B: استعادة التوقيع والإصدار النهائي
# MAWATHEQ — Execution 61B: Signing Recovery & Final Release

**الإصدار / Release:** `v2026.11.05-exec61b`
**الفرع / Branch:** `exec56-lawyer-platform`
**التاريخ / Date:** 2026-11-05
**الحالة / Status:** حزمة المحامين **موقّعة بمفتاح إنتاج جديد وجاهزة**. حزمة العملاء
**غير قابلة للرفع** لأن مفتاح الرفع الأصلي غير موجود في هذا البيئة (تفصيل كامل أدناه).

> **اقرأ القسم 1 قبل أي شيء.** مفتاح رفع تطبيق العملاء مفقود. لا يجوز استبداله بمفتاح
> مختلف. المسار الصحيح هو **طلب تغيير مفتاح الرفع** من Play Console (القسم 5).

---

## 1. الملخص التنفيذي / Executive summary

| # | التطبيق | applicationId | الإصدار | الحزمة | التوقيع |
|---|---|---|---|---|---|
| A | العملاء (منشور على Play) | `com.mawatheq.app` | `1.7.0+17` | `MAWATHEQ_CLIENT_v1.7.0+17_debug-signed_PENDING-UPLOAD-KEY.aab` | ❌ مفتاح التصحيح — **غير قابل للرفع** |
| B | المحامين (أول إصدار) | `com.mawatheq.lawyer` | `1.1.0+2` | `MAWATHEQ_LAWYER_GOOGLE_PLAY_FINAL.aab` | ✅ **مفتاح إنتاج جديد** |

**الخلاصة:** حزمة المحامين مكتملة وموقّعة بمفتاح إنتاج حقيقي. حزمة العملاء تحتاج إجراءً
من المالك (استعادة مفتاح الرفع) قبل أن تصبح قابلة للرفع — ولم أستبدل المفتاح بمفتاح آخر
لأن ذلك يكسر التحديث على تطبيق منشور.

---

## 2. التحقيق في مفتاح الرفع الأصلي (المهمة 1) / Signing-key investigation

### 2.1 ما هو المفتاح الأصلي بالضبط

من تقارير التنفيذ 51 و52 و53 (`MAWATHEQ_EXECUTION_51_TEST_REPORT.md`،
`MAWATHEQ_EXECUTION_53_TEST_REPORT_AR.md`) و`apps/mobile/README.md`:

| البند | القيمة |
|---|---|
| الاسم المميّز للشهادة (DN) | `CN=Mawatheq Legal Platform, OU=Mobile, O=Mawatheq, L=Riyadh, ST=Riyadh, C=SA` |
| بصمة SHA-256 | `5721bf0cfdd9f6fcef5dc3baf86b21c9ec2656c2ad4fb06a3cda189a355ecd09` |
| بصمة SHA-1 | `f18a51efadc19c7c835c135f7e14a5637ff32ec6` |
| اسم الملف | `mawatheq-upload-key.jks` |
| الاسم المستعار (alias) | `mawatheq-upload` |
| آلية التخزين | «محفوظ محليًا فقط ولم يُرفع» — `MAWATHEQ_EXECUTION_51_PLAY_READINESS_AR.md` |

هذا هو المفتاح الذي وقّع أول إصدار على Play (`1.4.0+11`) و`1.5.1+13`. بصمته مسجّلة
أيضًا في `.well-known/assetlinks.json` لتطبيق `com.mawatheq.app`.

### 2.2 أين بحثت بالضبط (كل المواقع التي فُحصت)

| الموقع | الطريقة | النتيجة |
|---|---|---|
| المستودع الحالي | بحث في الشجرة عن `*.jks` / `*.keystore` / `*.p12` / `*.pepk` / `key.properties` | **لا شيء** |
| كل تاريخ Git (كل الفروع والوسوم) | `git log --all --name-only` + بحث `-S` عن `.jks` و`storeFile` و`keyAlias` | **لا شيء** (لم يُرفع المفتاح قط) |
| كل كائنات Git (blobs) | فحص توقيع `PRIVATE KEY` / `feedfeed` لكل blob | **لا شيء** |
| ملفات معلّقة في Git | `git fsck --lost-found` | لا شيء سوى وسوم قديمة |
| `.gitignore` | `apps/mobile/android/.gitignore` يستثني `key.properties` و`**/*.jks` | مؤكَّد |
| نظام الملفات | `find` على `/workspace /home /root /opt /srv /var/www /tmp` | **لا شيء** (بصمة/CN أيضاً) |
| متغيرات البيئة | فحص `keystore`/`storepass`/`keypass`/`signing` | **لا شيء** |
| GitHub Actions | `gh secret list` / `gh variable list` (المستودع والمنظمة) | لا أسرار/متغيرات (403 — لا صلاحية قراءة، لكن لا يوجد أي ملف سير عمل أصلاً) |
| مستودعات المنظمة | `gh repo list startgroup2022-tech` (15 مستودعاً) | لا مستودع مفاتيح |
| بحث كود GitHub | `org:startgroup2022-tech extension:jks` / `filename:key.properties` | **0 نتائج** |
| الأرشفة/النسخ الاحتياطية | بحث عن `*.zip`/`*.tar*` في نظام الملفات | لا شيء ذو صلة |
| مسارات الكاش/الإعداد | `~/.gradle/gradle.properties`، `~/.android/`، `.config/`، `.cache/`، `.local/` | **لا شيء** |
| سجل المحادثات/الأحداث | `grep` في `/workspace/conversations/` و`/workspace/bash_events/` | لا يوجد إنشاء للمفتاح ولا كلمة مرور — فقط فحص بصمة مفتاح التصحيح |

**الاستنتاج:** مفتاح الرفع الأصلي `mawatheq-upload-key.jks` **غير موجود** في هذا البيئة
ولا في المستودع ولا في أي مخزن متاح. البيئة الحالية أُعيد ضبطها (فُقد JDK وأجزاء من
`.pub-cache` — انظر القسم 6)، ومفتاح كان «محليًا فقط» لم ينجُ من ذلك الضبط.

### 2.3 ما يجب ألا يحدث (ولم يحدث)

لم أُنشئ مفتاحًا بديلاً لتطبيق العملاء. استخدام مفتاح مختلف لنفس `applicationId` يؤدي إلى:

- رفض Google Play للحزمة (`com.mawatheq.app` منشور بالفعل بمفتاح آخر)، أو
- قطع سلسلة التحديث عن المستخدمين الحاليين إن تجاوز Play ذلك.

المسار الوحيد الصحيح هو استعادة مفتاح الرفع عبر Play Console (القسم 5).

---

## 3. تطبيق المحامين — أول إصدار (المهمة 3) / Lawyer app first release

### 3.1 هل يوجد مفتاح إنتاج سابق؟ لا.

- لم يُنشر تطبيق `com.mawatheq.lawyer` على Play قط.
- البصمة `97:86:FF:60:…:90` المسجّلة في `assetlinks.json` كانت **مفتاح التصحيح**
  (`CN=Android Debug`)، وليست مفتاح إنتاج — أثبت ذلك تقرير التنفيذ 58 (السطر 331).

### 3.2 المفتاح الجديد المُنشأ

| البند | القيمة |
|---|---|
| المسار | `/workspace/project/mawatheq-private/signing/mawatheq-lawyer-upload-key.jks` |
| النوع | PKCS12، RSA 4096، صلاحية 10,000 يوم |
| الاسم المستعار | `mawatheq-lawyer-upload` |
| DN | `CN=Mawatheq Lawyer, OU=Mobile, O=Mawatheq, L=Riyadh, ST=Riyadh, C=SA` |
| بصمة SHA-256 | `56:BC:76:D7:D8:D0:C7:07:66:F0:21:EF:9B:4B:1B:CA:8E:45:A7:5F:7F:CA:DE:83:7E:1A:2C:39:8E:8A:CC:2A` |

**إدارة المفتاح (أمن):**
- المفتاح وكلمة مروره محفوظان **خارج المستودع** في `/workspace/project/mawatheq-private/signing/`
  (المجلد `0700`، الملفان `0600`) — نفس نمط الجذر الخاص للمرفقات، ولا يمكن الالتزام بهما.
- `key.properties` الخاص بالمحامين **مستثنى من Git** (مؤكَّد بـ`git check-ignore`) و`0600`.
- كلمة المرور عشوائية 40 حرفًا (من `/dev/urandom`) ولم تُطبع أو تُرفع قط.
- **لا شيء من هذا داخل حزمة الإصدار.**

> **ملاحظة انتقالية مهمة:** هذا المفتاح أُنشئ داخل بيئة البناء (sandbox) التي تُعاد
> تهيئتها. قبل الرفع إلى Play، **يجب على المالك نسخ المفتاح وكلمة مروره إلى مخزن آمن
> دائم** (مدير كلمات مرور/خزنة). البيئة ليست مخزنًا دائمًا. سكربت الاستخراج:
> `docs/exec61b/EXTRACT_LAWYER_KEY.sh`.

---

## 4. التحقق النهائي (المهمة 4) / Final verification

| الفحص | العملاء | المحامين |
|---|---|---|
| `applicationId` | `com.mawatheq.app` ✅ | `com.mawatheq.lawyer` ✅ |
| `versionCode` | `17` ✅ | `2` ✅ |
| `versionName` | `1.7.0` ✅ | `1.1.0` ✅ |
| `minSdk` | 24 ✅ | 24 ✅ |
| `targetSdk` | 36 ✅ | 36 ✅ |
| التوقيع | ❌ `CN=Android Debug` | ✅ `CN=Mawatheq Lawyer` |
| `jarsigner -verify` | `jar verified` ✅ | `jar verified` ✅ |
| `flutter analyze` | No issues found ✅ | No issues found ✅ |
| `flutter test` | 316 ناجح ✅ | 9 ناجح ✅ |
| SHA-256 (AAB) | `38eb2c5a692c37f1262ce8e10f5a5fdedd919721969a5427edac7362ce473e82` | `9a0fc9fb56c9d9ee5bf0cc1bb1b37f2b3a074b645074515419d0ea49e7641fcd` |
| الحجم | 78,384,970 بايت | 79,776,338 بايت |

> تم التحقق من الهوية من **داخل الحزمة نفسها** عبر
> `bundletool dump manifest --bundle=<aab>`، لا من ملف الإعداد.

**توافق Google Play:** تطبيق المحامين يستوفي متطلبات Play (targetSdk 36، AAB موقّع
بمفتاح إنتاج، لا إذن غير مبرَّر، غير قابل للتصحيح). **لا يعني ذلك أنه «جاهز» نهائيًا**
حتى يُنشأ التطبيق في Play Console ويُرفع المفتاح.

---

## 5. إجراء استعادة مفتاح الرفع (تطبيق العملاء) / Upload-key reset procedure

هذا هو الإجراء الرسمي من Google، وهو المسار الصحيح لأن مفتاح الرفع الأصلي مفقود.
**لا تستبدله بمفتاح مختلف خارج هذا الإجراء.**

1. سجّل الدخول إلى **Google Play Console** → اختر التطبيق `com.mawatheq.app`.
2. **Setup → App integrity** (أو **Test and release → App integrity**).
3. في قسم **App signing**، إن كان **Play App Signing** مفعّلاً (وهو الافتراضي للتطبيقات
   الجديدة منذ 2021)، اضغط **Request upload key reset**.
4. اتبع التعليمات: ستُطلب منك **شهادة المفتاح الجديد** (وليس المفتاح الخاص نفسه).
   ولّد مفتاح رفع جديدًا (يمكن استخدام مفتاح جديد بأي اسم مميّز) ثم صدّر شهادته:
   ```bash
   keytool -export -rfc \
     -keystore new-upload-key.jks -alias <alias> \
     -file upload_certificate.pem
   ```
   وارفع ملف `upload_certificate.pem`.
5. تراجع Google الطلب (عادةً حتى 48 ساعة)، ثم **يعتمد المفتاح الجديد** كأول رابط في
   سلسلة الرفع. (مفتاح التوقيع الفعلي للتطبيق — *app signing key* — يبقى بيد Google
   ولا يتغيّر، لذا لا يتأثر المستخدمون الحاليون ولا يتغيّر شيء في التطبيق المثبَّت.)
6. بعد الاعتماد، ضع المفتاح الجديد في `apps/mobile/android/key.properties` وأعد البناء:
   ```bash
   flutter build appbundle --release
   ```
7. حدّث `.well-known/assetlinks.json` ببصمة **مفتاح توقيع التطبيق** من
   **Play Console → App integrity → App signing key certificate** (لا بصمة مفتاح الرفع).

> **إن لم يكن Play App Signing مفعّلاً** على `com.mawatheq.app` (تطبيق قديم)، فالمفتاح
> الأصلي هو مفتاح التوقيع الفعلي، وضياعه يعني **عدم القدرة على تحديث التطبيق نهائيًا**
> على نفس الـ`applicationId`. الحل حينها هو نشر تطبيق جديد بـ`applicationId` جديد
> (يخسر المستخدمين الحاليين والترتيب). **تحقّق من حالة Play App Signing أولاً — هذا
> يحدّد أي المسارين.**

---

## 6. حالة البيئة أثناء البناء / Environment notes

أُعيد ضبط البيئة منذ التنفيذ 61، ما استلزم خطوات ترميم (لا علاقة لها بالكود):

1. **JDK مفقود:** لا `/usr/lib/jvm`. أُعيد التثبيت:
   `sudo apt-get install -y openjdk-21-jdk-headless`.
2. **كاش pub ناقص:** فشل البناء بـ`No such file or directory` لعشرات الحزم في
   `~/.pub-cache`. أُصلح بـ`flutter pub get` في كل تطبيق. `pubspec.lock` لم يتغيّر
   (مؤكَّد بـ`git status`).
3. **bundletool:** نُزّل `bundletool-all-1.18.1.jar` للتحقق من المانيفست.

---

## 7. الأثر على App Links / App Links impact

`.well-known/assetlinks.json`:

- `com.mawatheq.lawyer` حُدِّث إلى بصمة المفتاح الإنتاجي الجديد
  `56:BC:76:D7:…:2A` (كان مفتاح التصحيح `97:86:FF:60:…`).
- `com.mawatheq.app` بقي على بصمة المفتاح الأصلي `57:21:BF:0C:…:09`. عند استعادة
  مفتاح الرفع (القسم 5) يجب تحديث هذه القيمة إلى بصمة **مفتاح توقيع التطبيق** من
  Play Console.

---

## 8. ما لم يُختبر / Not tested

- **لا اختبار على جهاز/محاكي فعلي** في هذا التنفيذ.
- **لم يُرفع شيء إلى Google Play** (بانتظار إذن المالك).
- **لم يُلمس الإنتاج** (لا موقع ولا قاعدة بيانات).
- اختبارات Flutter تجري على نقل بيانات وهمي؛ لا تُثبت حجزًا فعليًا أو نداء AI حقيقيًا.

---

## 9. الملفات المُسلَّمة / Delivered files

| الملف | الوصف |
|---|---|
| `MAWATHEQ_LAWYER_GOOGLE_PLAY_FINAL.aab` | حزمة المحامين — موقّعة بمفتاح إنتاج جديد ✅ |
| `MAWATHEQ_CLIENT_v1.7.0+17_debug-signed_PENDING-UPLOAD-KEY.aab` | حزمة العملاء — موقّعة بمفتاح التصحيح ⚠️ (اسم الملف يوضّح حالته) |
| `SHA256SUMS.txt` | بصمات الحزمتين |
| `MAWATHEQ_EXECUTION_61B_REPORT.md` | هذا التقرير |
| `docs/exec61b/EXTRACT_LAWYER_KEY.sh` | سكربت آمن لاستخراج مفتاح المحامين إلى مخزن دائم |
| `docs/exec61b/DEPLOYMENT_INSTRUCTIONS_AR.md` | تعليمات النشر والرفع الدقيقة |

---

## 10. المتبقّي / Remaining blockers

1. **مفتاح رفع تطبيق العملاء مفقود** — يتطلب طلب تغيير مفتاح الرفع من Play Console
   (القسم 5). بدونه لا يمكن رفع تحديث `com.mawatheq.app`.
2. **نقل مفتاح المحامين إلى مخزن دائم** قبل الرفع (القسم 3).
3. **إنشاء تطبيق المحامين في Play Console** + تفعيل Play App Signing + رفع المفتاح.
4. **تحديث بصمة `com.mawatheq.app`** في `assetlinks.json` بعد الاستعادة.
5. **إذن المالك** قبل أي رفع أو لمس للإنتاج.
