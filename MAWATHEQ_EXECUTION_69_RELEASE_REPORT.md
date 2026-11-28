# MAWATHEQ — EXECUTION 69 RELEASE REPORT

**Release:** `v2026.11.26-exec69`
**Commit:** `2f3dbcd` (branch `feature/ai-workspace-firm-suite`)
**PR:** #10 — **not merged, awaiting owner approval**
**Production:** **not deployed, awaiting owner approval**

---

## 1. الأولوية الأولى — بوابة N-Genius

### الرسالة التي كان يراها العميل

> «تعذّر بدء عملية الدفع الإلكتروني حالياً. لم يتم خصم أي مبلغ.»

هذه هي جملة **الخطأ المؤقت**. وكانت تظهر لكل فشل، وليس للفشل المؤقت فقط.

### السبب الحقيقي

`includes/gateway/payment_service.php` كان يصنّف الفشل على `$result['error']` — وهي **جملة
عربية مكتوبة للبشر** (`فشل إنشاء طلب الدفع (HTTP 400).`) — بدلاً من `$result['code']` وهو
الرمز الآلي. لا تطابق أي جملة أي رمز، فيسقط كل فشل إلى `order_failed` العام.

### الإصلاح — ثلاث حلقات كانت مكسورة، وكل واحدة وحدها تُعيد العَرَض

| الملف | قبل | بعد |
|---|---|---|
| `includes/ngenius.php` — `ngenius_create_order()` | لا يُعيد `code` عند فشل curl | يُعيد `code => 'connection_failed'` |
| `includes/gateway/ngenius/gateway.php` | يمرّر `error` فقط | يمرّر `code` أيضاً |
| `includes/gateway/payment_service.php` | يصنّف `error` | يصنّف `code ?? error` |

كما أُصلح `includes/gateway_diagnostics.php` ليصنّف الرمز الاصطناعي `http_<status>`:
`4xx` → `gateway_rejected` (نهائي، لا فائدة من إعادة المحاولة)، `429/5xx` → `connection_failed`
(مؤقت). وطُبّق المنطق نفسه على `includes/package_payment.php` و`includes/consultation_payment.php`
اللذين كانا يُحوّلان كل `http_*` إلى `order_failed`.

### الإثبات — على HTTP حقيقي، لا على mock

شُغّل خادم PHP على المنفذ `8098` مع
`MAWATHEQ_NGENIUS_BASE_URL=http://127.0.0.1:19999` (منفذ مغلق)، ثم دُفع `pay-invoice.php`
بفاتورة حقيقية للعميل `client-a@example.com`.

**النتيجة:** العميل يرى الرسالة الصحيحة المحددة مع رقم مرجعي:

> «تعذّر الوصول إلى بوابة الدفع. لم يتم خصم أي مبلغ. تحقّق من اتصالك ثم يمكنك المحاولة مجدداً أو التواصل معنا. الرقم المرجعي: 92B07F92»

وفي جدول `gateway_diagnostics` سُجِّل: `error_code = connection_failed`، `stage = order`،
`operation = invoice_payment`.

### الاختبارات

`tests/exec69_payment_diagnostics_test.php` — **55/55**. القسم 7 يحقن محوّلاً فاشلاً (fixture
adapter) يثبت السلسلة كاملة، فأي تعديل مستقبلي يقرأ `error` يُسقط الاختبار بدل أن يُسقط دفعة عميل.

### 🔴 ما لم يُثبت — يُقال صراحة

**لم تُجرَّب عملية دفع N-Genius حقيقية.** خادم الـ fixture يثبت أن العميل يتحدث مخطط البوابة،
ولا يثبت أن حساب التاجر الحقيقي يعمل. إثبات ذلك يحتاج:

```bash
php tools/gateway_doctor.php --live
```

على الخادم مع بيانات اعتماد حقيقية، ثم دفعة واحدة على الصفحة المستضافة. **لا يمكن تنفيذ ذلك من
هذه البيئة**، ولم أدّعِ نجاحه.

---

## 2. الأولوية الثانية — الباقات والأسعار والمزايا والاشتراكات

### ما كان يعمل أصلاً (تحقّقتُ منه ولم أغيّره)

- `pricing.php` و`packages.php` يقرآن `SELECT * FROM plans WHERE status='active'` مباشرة.
- **إثبات:** غيّرت `price_monthly` للباقة 2 من 149 إلى 155 في قاعدة البيانات، فظهر `155.00` في
  `pricing.php` في الطلب التالي بلا أي إعادة تشغيل. ثم أعدت القيمة. لا توجد قيمة مكتوبة في الكود.

### العيب الحقيقي الذي أُصلح

`package_payment_activate()` يمنح الباقة بكتابة `users.plan_id`، و**لا شيء كان يقرأ
`subscriptions.ends_at` أبداً**. النتيجة: باقة شهرية تُشترى مرة واحدة تبقى سارية **إلى الأبد** —
كل باقة مدفوعة كانت مجانية دائمة بعد أول شراء، ولا تجديد ولا تخفيض.

**الإصلاح:**

- `subscriptions_expire_due()` في `includes/package_payment.php` — تُنهي الاشتراكات المستحقة.
- `cron/subscription-expiry.php` — يشغّلها يومياً، ويرفض العمل عبر HTTP.

قاعدتان حاسمتان في التنفيذ:

1. **التخفيض فقط بينما المستخدم لا يزال يحمل الباقة التي منحها الاشتراك**
   (`WHERE id=? AND plan_id=?`). من ترقّى يحتفظ بالباقة الأعلى، ومنح إداري من لوحة التحكم لا
   يُطمس باشتراك قديم ينتهي.
2. **savepoint عند وجود معاملة خارجية** — PDO لا يدعم التعشيش، فاختبار يلفّ بياناته في معاملة
   كان يموت بـ «There is already an active transaction» قبل الوصول لأي تحقق.

المقارنة `ends_at < today` صارمة عن قصد: يوم ينتهي اليوم لا يزال يوماً مدفوعاً.

### العرض في صفحة الشراء

`packages.php` كان يعرض نصوص التسويق (`plan_features`) فقط. أُضيف عرض **الحدود الفعلية** التي
يفرضها الخادم، فلا تعرض صفحة الشراء باقة لا تُذكر سقوفها الحقيقية.

### الاختبارات

`tests/exec69_subscription_expiry_test.php` — **14/14**: الانتهاء، حدّ «ينتهي اليوم لا يزال
سارياً»، الاشتراكات المستقبلية، من ترقّى يحتفظ بالأعلى، الملغاة، والتكرار الآمن.
`tests/exec69_plan_catalog_test.php` — **76/76**.

---

## 3. الأولوية الثالثة — الحجوزات

### العيب الذي كان يمنع الحجز من التطبيق

تقويم التطبيق كان يُعطّل **كل** يوم لمحامٍ لا يملك `lawyer_availability` — وهي **الحالة
الافتراضية لكل محامٍ في قاعدة البيانات**. أي عميل لم يكن يستطيع الحجز من التطبيق أبداً. الموقع
كان يعالج الحالة (طلب وقت حر)، فالتطبيق كان السطح الوحيد المكسور.

**الإصلاح:** `_BookingCalendar` يأخذ `hasHours`؛ بلا ساعات تُعرض أوقات مقترحة بدل طريق مسدود،
والأيام المغلقة المنشورة تبقى معطّلة. فشل تحميل الشهر يعود إلى الأوقات المقترحة بدل شاشة فارغة.

### الإشعارات

تأكيد/إلغاء/إكمال المحامي للحجز يُشعر العميل الآن. سابقاً كان العميل يرى «قيد الانتظار» إلى الأبد.

### منع التعارض — مُثبت لا مفترض

`booking_create_locked()` يُسلسل على `GET_LOCK(<lawyer, slot>)` ويعيد فحص الإتاحة **داخل القفل**
مباشرة قبل الـ INSERT.

**إثبات:** عمليتان حقيقيتان على نفس الموعد مع تأخير 150ms داخل القسم الحرج:

```
B => ok=1 err=- id=41
A => ok=0 err=slot_taken id=0
=== rows ===
id  topic
41  race B
```

صف واحد فقط. الطرف الآخر رُفض بـ `slot_taken`.

### الفواتير

الحجز المدفوع يُصدر فاتورة عبر `consultation_invoice_issue()` (idempotent — الاستدعاء الثاني
يعيد نفس المعرّف).

### الاختبارات

| السويت | النتيجة |
|---|---|
| `exec69_booking_notifications_test.php` | **11/11** |
| `exec69_booking_conflict_test.php` | **28/28** |
| `exec69_booking_no_hours_test.dart` (Flutter) | **2/2** |

---

## 4. نتائج الاختبارات الكاملة

| المجموعة | النتيجة |
|---|---|
| **PHP suites** | **73 / 73 نجحت، 0 فشل** |
| **Playwright** | **1032 نجحت، 12 متخطاة، 0 فشل** |
| **Flutter** | `flutter analyze` نظيف، **379 اختباراً نجحت** |
| **Release smoke test** | **65/65** على الملفات المُنزَّلة نفسها |

### قاعدة البيانات

| الخطوة | الجداول |
|---|---|
| `schema.sql` وحدها | 114 |
| `schema.sql` + كل الترقيات 82 | **180** |
| إعادة تشغيل كل الترقيات مرة ثانية | 180 (لا تغيير — idempotent) |

`upgrade_v81.sql` و`upgrade_v82.sql` طُبّقتا مرتين بلا خطأ.

---

## 5. الملفات المعدّلة

```
includes/ngenius.php                         code على فشل curl
includes/gateway/ngenius/gateway.php         يمرّر code
includes/gateway/payment_service.php         تصنيف على code
includes/gateway_diagnostics.php             تصنيف http_<status>
includes/package_payment.php                 تصنيف code + subscriptions_expire_due()
includes/consultation_payment.php            تصنيف http_<status>
cron/subscription-expiry.php                 جديد
includes/plan_features.php                   حدود الباقة الفعلية
packages.php                                 عرض الحدود المفرَّضة
pricing.php                                  <h1> + title
lawyer/consultations.php                     إشعار العميل
apps/mobile/.../journey_screen.dart          حجز بلا ساعات
tests/exec69_*.php (6 suites)                اختبارات
tests/e37dbg_tmp.php                         حُذف (ملف خربشة)
```

---

## 6. طريقة النشر

```bash
# 1) ارفع الموقع وفكّ الضغط في public_html
#    ثم احذف الأرشيف (‏.htaccess يحجب .zip كطبقة ثانية)
MAWATHEQ_WEBSITE_FINAL.zip

# 2) طبّق الترقيات بترتيب رقمي
MAWATHEQ_DATABASE_MIGRATIONS.zip

# 3) أضف مهمة cron يومية جديدة
php /home/USER/public_html/cron/subscription-expiry.php
```

```bash
# 4) تحقّق بعد النشر
php tools/production_check.php          # يجب أن يخرج 0
```

**متطلبات إلزامية:** PHP **8.4** بالضبط (`vendor/composer/platform_check.php` يمنع أقل من ذلك
ويميت الموقع عند أول include).

---

## 7. ما لم يُثبت — بصراحة

1. **لا دفعة N-Genius حقيقية.** السبب مُصلح ومُثبت بـ HTTP حقيقي على منفذ مغلق، لكن حساب التاجر
   الحقيقي لم يُستخدم. يحتاج `tools/gateway_doctor.php --live` + دفعة واحدة من المالك.
2. **لم يُنشر على الإنتاج.** بانتظار موافقة المالك.
3. **PR #10 لم يُدمج.** بانتظار موافقة المالك.
4. **الـ APK موقّع بمفتاح debug** (`CN=Android Debug`، v2 فقط). صالح للاختبار المحلي، **غير صالح
   للنشر على Google Play** ولا لتفعيل App Links. يحتاج `android/key.properties` بمفتاح المالك.
5. **خطأ 500 على `api/najiz_classify.php` في الإنتاج ليس عيب كود** — هو فجوة نشر:
   `upgrade_v59.sql` لم تُطبَّق فجداول `najiz_*` غير موجودة.
6. اختبارات Playwright وFlutter تعمل على fake transport — تُثبت أن الشاشات تُبنى وتُعرض وتتعامل
   مع JSON ثابت. **لا تُثبت** حجزاً حقيقياً أو دخولاً إنتاجياً أو رفع مستند أو نداء AI حقيقياً.

---

_أُعدّ هذا التقرير بواسطة وكيل ذكاء اصطناعي (OpenHands) نيابةً عن مالك المستودع._
