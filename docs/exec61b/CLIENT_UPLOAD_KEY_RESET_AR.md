# إجراء استعادة مفتاح الرفع — تطبيق العملاء (com.mawatheq.app)
# Upload Key Reset procedure — CLIENT app (com.mawatheq.app)

> **لا تُنشئ مفتاح رفع بديلًا وتستخدمه مباشرة.** تطبيق العملاء **منشور على Google Play**
> ولديه مستخدمون. استبدال مفتاح الرفع خارج إجراء Google الرسمي إمّا يُرفض أو يكسر سلسلة
> التحديث. الإجراء أدناه هو المسار الوحيد الصحيح.

---

## 0. المعطيات المؤكَّدة (من التحقيق)

| البند | القيمة |
|---|---|
| `applicationId` | `com.mawatheq.app` — **لا يتغيّر أبدًا** |
| مفتاح الرفع المفقود | `CN=Mawatheq Legal Platform`, alias `mawatheq-upload` |
| بصمة SHA-256 للمفتاح المفقود | `5721bf0cfdd9f6fcef5dc3baf86b21c9ec2656c2ad4fb06a3cda189a355ecd09` |
| آخر إصدار مرفوع موثَّق | `1.4.0+11` (سجل Play)، وآخر بناء `1.5.1+13` |
| الإصدار الجاهز حاليًا | `1.7.0+17` (موقّع بمفتاح التصحيح — غير قابل للرفع) |
| حالة المفتاح | **مفقود** — لا يوجد في المستودع، ولا في أي تاريخ Git، ولا نظام الملفات، ولا متغيّرات البيئة، ولا أسرار GitHub، ولا أي مستودع في المنظمة |

---

## 1. الخطوة الأولى والحاسمة: هل Play App Signing مفعّل؟

سجّل الدخول إلى **Google Play Console** → اختر تطبيق `com.mawatheq.app` →
**Setup → App integrity** (أو **Test and release → App integrity**).

### الحالة (أ): Play App Signing **مفعّل** ✅ (الافتراضي للتطبيقات الجديدة)

هذه أفضل حالة. معنى ذلك:

- **مفتاح توقيع التطبيق الفعلي (app signing key) محفوظ لدى Google** ولا يتغيّر أبدًا.
- مفتاحك المفقود هو **مفتاح الرفع** فقط — مجرّد «مفتاح إدخال» يمكن استبداله.
- **المستخدمون الحاليون لا يتأثّرون إطلاقًا**، ولا يتغيّر `applicationId`، ولا يُعاد التثبيت.

**الإجراء:**

1. في **App integrity → App signing** اضغط **Request upload key reset**.
2. ستطلب Google **شهادة** مفتاح رفع جديد (المفتاح العام فقط — لا الخاص). ولّد مفتاحًا جديدًا:
   ```bash
   keytool -genkeypair -v \
     -keystore mawatheq-client-upload-key-v2.jks \
     -alias mawatheq-upload \
     -keyalg RSA -keysize 4096 -validity 10000 \
     -dname "CN=Mawatheq Legal Platform, OU=Mobile, O=Mawatheq, L=Riyadh, ST=Riyadh, C=SA"
   # صدّر الشهادة العامة فقط:
   keytool -export -rfc -keystore mawatheq-client-upload-key-v2.jks \
     -alias mawatheq-upload -file upload_certificate.pem
   ```
3. ارفع `upload_certificate.pem` إلى Play Console.
4. انتظر موافقة Google (عادةً حتى 48 ساعة).
5. بعد الاعتماد، ضع المفتاح في `apps/mobile/android/key.properties` وأعد البناء:
   ```bash
   cd apps/mobile && flutter build appbundle --release
   ```
6. حدّث `.well-known/assetlinks.json` ببصمة **مفتاح توقيع التطبيق** من
   **App integrity → App signing key certificate** (وليست بصمة مفتاح الرفع الجديد).

### الحالة (ب): Play App Signing **غير مفعّل** ⚠️

هنا المفتاح المفقود هو **مفتاح التوقيع الفعلي نفسه**. ضياعه يعني:

- **لا يمكن تحديث `com.mawatheq.app` نهائيًا** على نفس المعرّف.
- الخيارات:
  1. **إن وُجد أي نسخة احتياطية** للمفتاح في أي مكان (جهاز قديم، نسخة سحابية، مُطوّر سابق) —
     استعدها فورًا. هذا يحلّ المشكلة بالكامل.
  2. **إن لم توجد** — الحل الوحيد هو نشر تطبيق **بمعرّف جديد** (`applicationId` جديد)،
     وهذا يعني **خسارة المستخدمين الحاليين والتقييمات**، ويجب موافقة المالك الصريحة.
- **أوقف كل شيء واسأل المالك قبل أي خطوة** في هذه الحالة.

---

## 2. ماذا لا تفعل

- ❌ لا تولّد مفتاحًا جديدًا وترفع به الحزمة مباشرة دون إجراء Google — سيُرفض.
- ❌ لا تغيّر `applicationId` من `com.mawatheq.app` (حتى في الحالة ب، يجب أن يكون قرارًا واعيًا).
- ❌ لا ترفع أي مفتاح أو كلمة مرور إلى GitHub (المستودع عام).
- ❌ لا تنشر على Play دون موافقة المالك.

---

## 3. بعد استعادة المفتاح — قائمة التحقق

- [ ] الحزمة الجديدة موقّعة بمفتاح الرفع الجديد (`keytool -printcert -jarfile` → ليس `Android Debug`).
- [ ] `versionCode` أعلى من آخر إصدار منشور على Play.
- [ ] `.well-known/assetlinks.json` يحمل بصمة **مفتاح توقيع التطبيق** (من Play Console).
- [ ] اختبار التثبيت والتحديث على جهاز حقيقي.
- [ ] تحديث نسخة العميل في هذا المجلد (`MAWATHEQ_CLIENT_*`).
