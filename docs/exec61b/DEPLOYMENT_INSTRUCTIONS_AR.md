# مواثيق — تعليمات النشر والرفع (التنفيذ 61B)
# MAWATHEQ — Deployment & upload instructions (Execution 61B)

> **لا تنفّذ أي خطوة رفع أو لمس للإنتاج دون إذن صريح من المالك.**

## 1. تطبيق المحامين — أول إصدار على Google Play

### 1.1 انقل المفتاح إلى مخزن دائم (إلزامي قبل الرفع)

المفتاح أُنشئ في بيئة بناء مؤقتة. انسخه إلى مدير كلمات مرور/خزنة:

```bash
bash docs/exec61b/EXTRACT_LAWYER_KEY.sh /path/to/secure/vault
```

ثم احذف النسخ من البيئة المؤقتة. **لا ترفع المفتاح إلى GitHub ولا تضعه في أي ZIP.**

### 1.2 أنشئ التطبيق في Play Console

1. Play Console → **Create app**.
2. **App name:** مواثيق المحامين (أو «Mawatheq Lawyer»).
3. **Default language:** العربية.
4. **App or game:** App. **Free or paid:** Free.
5. Package name **لا يُدخل يدويًا** — يُقرأ من أول AAB ترفعه: `com.mawatheq.lawyer`.

### 1.3 ارفع الحزمة

1. **Test and release → Production** (أو Internal testing أولاً للتحقق).
2. **Create new release** → ارفع `MAWATHEQ_LAWYER_GOOGLE_PLAY_FINAL.aab`.
3. **فعّل Play App Signing** عند الطلب (موصى به بشدة): ستدير Google مفتاح التوقيع،
   ويبقى مفتاحك أعلاه «مفتاح رفع» فقط.
4. أكمل: وصف المتجر، لقطة الشاشة، أيقونة، سياسة الخصوصية، تصنيف المحتوى،
   Data safety (الأذونات المستخدمة: `INTERNET`, `CAMERA`, `RECORD_AUDIO`, ...).

### 1.4 بعد الاعتماد

- اقرأ **Play Console → App integrity → App signing key certificate**، وبصمته هي
  الصحيحة لـApp Links — **لا** بصمة مفتاح الرفع.
- حدّث `.well-known/assetlinks.json` لبند `com.mawatheq.lawyer` بهذه البصمة،
  وارفع الملف إلى جذر `mawatheq.com`.

---

## 2. تطبيق العملاء — تحديث الإصدار

### 2.1 الحالة

الحزمة الحالية `MAWATHEQ_CLIENT_v1.7.0+17_debug-signed_PENDING-UPLOAD-KEY.aab`
موقّعة بمفتاح التصحيح و**لا يمكن رفعها**. السبب: مفتاح الرفع الأصلي
(`mawatheq-upload`, SHA-256 `5721bf0c…`) مفقود.

### 2.2 الخطوة المطلوبة (لا بديل عنها)

1. Play Console → `com.mawatheq.app` → **Setup → App integrity**.
2. تحقّق من حالة **Play App Signing**:
   - **مفعّل** → اضغط **Request upload key reset**، وارفع شهادة مفتاح رفع جديد
     (القسم 5 في التقرير). عند الاعتماد أعد البناء بمفتاح الرفع الجديد.
   - **غير مفعّل** → المفتاح الأصلي هو مفتاح التوقيع الفعلي وضياعه يعني عدم إمكانية
     تحديث نفس `applicationId`؛ راجع المالك قبل أي إجراء.
3. أعد البناء بعد الحصول على المفتاح:
   ```bash
   # ضع المفتاح الجديد في apps/mobile/android/key.properties ثم:
   cd apps/mobile && flutter build appbundle --release
   ```
4. حدّث بصمة `com.mawatheq.app` في `assetlinks.json` من
   **App integrity → App signing key certificate** (لا مفتاح الرفع).

---

## 3. التحقق من أي حزمة قبل الرفع

```bash
# الهوية
java -jar bundletool-all-1.18.1.jar dump manifest --bundle=<aab> \
  | grep -oE 'package="[^"]*"|versionCode="[^"]*"|versionName="[^"]*"'

# التوقيع — يجب أن يكون CN هو مفتاح الإنتاج، لا Android Debug
keytool -printcert -jarfile <aab> | grep -iE "Owner:|SHA256:"

# السلامة
jarsigner -verify <aab>
sha256sum -c SHA256SUMS.txt
```

**مؤشر الفشل:** أي حزمة يظهر فيها `CN=Android Debug` غير قابلة للرفع إلى Play.

---

## 4. ملاحظة App Links

`.well-known/assetlinks.json` يجب أن يحمل **بصمة مفتاح توقيع التطبيق** لكل حزمة.
عند تفعيل Play App Signing، هذه البصمة تختلف عن بصمة مفتاح الرفع. استخدم دائمًا
البصمة الظاهرة في Play Console → App integrity.
