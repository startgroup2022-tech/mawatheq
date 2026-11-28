# مواثيق — التنفيذ 68 (استكمال): تشخيص بوابة N-Genius

**الفرع:** `feature/ai-workspace-firm-suite` — **طلب الدمج:** #10 (مسودّة — **لم يُدمج، لم يُنشر على الإنتاج**)
**الأساس:** `v2026.11.27-exec68` (`c2998e3`)

---

## 1. الرسالة التي أبلغ عنها العميل

> «تعذّر بدء عملية الدفع الإلكتروني حالياً. لم يتم خصم أي مبلغ.»

هذه هي الجملة **العابرة** (transient) في `includes/gateway_diagnostics.php`. تُعرض للعميل في
حالتين فقط:

1. رمز الخطأ المسجَّل غير معروف للتصنيف، أو
2. رمز الخطأ هو `token_failed` / `order_failed` تحديداً.

وهذا هو جوهر العيب: **الجملة نفسها تُقال لكل فشل لم يستطع النظام تسميته**، فتدعو العميل إلى
إعادة المحاولة في عطل لا يمكن أن تنجح إعادته (مفتاح مرفوض، منفذ خاطئ، عملة غير مطابقة).
النظام كان يملك التشخيص لكنه كان **يتخلّص من الإجابة**.

---

## 2. العيبان الحقيقيان (مُثبَّتان باختبار، لا بالقراءة)

### 2.1 أداة التشخيص كانت تُسقط رمز سبب البوابة

`gateway_diag_probe()` في `includes/gateway_diagnostics.php` كانت تسجّل:

```php
$record('token', false, null, 'token_failed', ...);   // دائماً
$record('order', false, null, 'order_failed', ...);   // دائماً
```

بينما `ngenius_get_access_token()` تُرجع `code` بعناية (`badTokenRequest`،
`realmNameNotAvailable`، `http_401`، `connection_failed` …)، و`ngenius_create_order()` كذلك.
الأداة التي وُجدت للإجابة على سؤال «لماذا فشل الدفع؟» كانت **ترمي الإجابة**.

**الأثر:** كل عطل — مفتاح مرفوض أو شبكة مقطوعة — ظهر للعميل بنفس الجملة، وظهر للمشغّل
باسم `token_failed` الذي لا يدلّ على شيء.

**الإصلاح:** تمرير الرمز عبر `gateway_diag_reason_code()`، وحفظ الرمز الأصلي في التفصيل:

```php
$code = (string)($token['code'] ?? '');
$record('token', false, null,
    $code !== '' ? gateway_diag_reason_code($code) : 'token_failed',
    (string)($token['error'] ?? '') . ' [code=' . $code . ']');
```

النتيجة: مفتاح مرفوض ← `badTokenRequest` ← رسالة **دائمة** («تواصل معنا») بدل «أعد المحاولة».
عطل شبكة ← `connection_failed` ← رسالة عابرة، وهي الوحيدة التي يجوز فيها «أعد المحاولة».

### 2.2 مفتاح مخزَّن لكن غير قابل للفك — يُقرأ كأنه غير موجود

`secret_decrypt_value()` تُرجع `''` في **كل** حالات الفشل:

| الحالة | القيمة المُرجعة |
| --- | --- |
| لا يوجد مفتاح مخزَّن | `''` |
| `MAWATHEQ_ENCRYPTION_KEY` تغيّر بعد حفظ المفتاح | `''` |
| blob تالف / مقطوع | `''` |
| مفتاح صحيح | القيمة |

فالمفتاح **الموجود** الذي فُقد مفتاح تشفيره يبدو تماماً كالمفتاح **غير المُدخل**. المشغّل كان
يُقال له «مفتاح الحساب ناقص» وهو موجود، ولا شيء يدلّه على أن السبب هو تغيّر مفتاح التشفير.

**الإصلاح:** `secret_decrypt_status()` في `includes/secret_crypto.php` — تُرجع **اسماً** لا قيمة:
`empty | plaintext | ok | no_key | no_openssl | corrupt | wrong_key`. لم يتغيّر عقد
`secret_decrypt_value()` (11 مستدعياً)، فالإصلاح إضافي بالكامل.

ثم:
- `ngenius_settings()` تسجّل `_secret_status` لكل سرّ (اسم فقط، لا قيمة).
- `gateway_diag_probe()` تُصدر رمزاً جديداً `secret_unreadable` بسبب صريح.
- `admin/gateways.php` يعرض تحذيراً أحمر في الصفحة التي يُدخل فيها المفتاح أصلاً.

**الإثبات الحيّ:** بمفتاح مُدوَّر، الصفحة تُرجع 57,562 بايت وتحتوي «تعذّر فك تشفيره»؛ وبعد
إعادة تشفير المفتاح ترجع 57,107 بايت بلا تحذير — أي أن الفرق في جسم الاستجابة، لا في التوقّع.

---

## 3. الاختبارات

### 3.1 حزمة جديدة: `tests/exec68_gateway_diagnosis_test.php` — **33/33**

خمسة أقسام:

| القسم | ما يُثبته |
| --- | --- |
| 1 | مفتاح مُدوَّر ← `wrong_key`، و`secret_decrypt_value()` تُبقي عقدها (`''`) |
| 2 | `_secret_status` تحمل أسماءً فقط، ولا تحمل قيمة السرّ |
| 3 | `secret_unreadable` سبب مصنَّف **دائم** (لا يدعو لإعادة المحاولة) |
| 4 | مرحلة token/order تقرأ `code` فعلاً، وكل رموز البوابة تُصنَّف |
| 5 | كل عطل في الاعتماد/الإعداد يُعطي رسالة دائمة لا عابرة |

### 3.2 الحزم القائمة (لم تتأثر)

| الحزمة | النتيجة |
| --- | --- |
| `exec68_payment_diagnostics_fixes_test` | 25/25 |
| `exec69_payment_diagnostics_test` | 55/55 |
| `exec76_payment_booking_regression_test` | 66/66 |
| `exec68_gateway_message_coverage_test` | 39/39 (أُضيف `secret_unreadable`) |
| `exec68_payment_packages_test` | 45/45 |

### 3.3 الحزمة الكاملة

```
bash tests/run_all_tests.sh
suites passed: 86   failed: 0
```

### 3.4 Flutter

```
flutter analyze  → No issues found!
flutter test     → 387 tests, All tests passed!
apps/mobile/pubspec.lock → غير معدَّل (git status نظيف)
```

### 3.5 Playwright

```
npx playwright test → 1032 passed, 12 skipped, 0 failed (9.6m)
```

---

## 4. ما لم يُثبَت (بصراحة)

- **لم يُجرَّب دفع حقيقي.** مفتاح N-Genius في بيئة التطوير قيمة اصطناعية
  (`FIXTURE-api-key-for-tests`)، ومفتاح الإنتاج مشفَّر على الخادم ولا يمكن قراءته من هنا.
  ما أُثبت هو **مسار التشخيص**: أن العطل يُسمّى ويُصنَّف ويُعرض للمشغّل.
- **سبب فشل الإنتاج الفعلي غير مؤكَّد بعد.** الإصلاح يضمن أن التشخيص سيقوله. لتحديد السبب
  الحالي على `mawatheq.com` يجب تشغيل:

  ```bash
  php tools/gateway_doctor.php
  ```

  أو فتح `/admin/gateways.php` والضغط على زر الفحص. إن ظهر `secret_unreadable` فالسبب مفتاح
  التشفير؛ إن ظهر `badTokenRequest` فالمفتاح نفسه؛ إن ظهر `unsupported_currency` فالعملة.
- **لم يُنشر على الإنتاج ولم يُدمج PR #10** — بانتظار موافقة المالك.

---

## 5. الملفات المعدَّلة

| الملف | التغيير |
| --- | --- |
| `includes/secret_crypto.php` | `+secret_decrypt_status()` (23 سطراً) |
| `includes/ngenius.php` | `_secret_status` في `ngenius_settings()` |
| `includes/gateway_diagnostics.php` | حفظ رموز token/order، `secret_unreadable` (سبب + رسالة) |
| `admin/gateways.php` | تحذير المفتاح غير القابل للفك |
| `tests/exec68_gateway_diagnosis_test.php` | **جديد** — 33 فحصاً |
| `tests/exec68_gateway_message_coverage_test.php` | `secret_unreadable` ضمن التغطية |

**لا ترحيل قاعدة بيانات مطلوب** لهذا التنفيذ (لا تغيير في المخطط).
