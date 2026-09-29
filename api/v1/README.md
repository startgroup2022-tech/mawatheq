# واجهة مواثيق البرمجية (REST API) v1

هذه الواجهة مخصّصة لتطبيق الجوال (Flutter) أو أي عميل خارجي آخر. كل الروابط تحت:

```
https://mawatheq.com/api/v1/
```

## المصادقة (Authentication)

التسجيل يرجع `verification_required` ويرسل رابط تأكيد إلى البريد. أكّد البريد أولاً ثم سجّل الدخول لتحصل على `token`. أرسله بكل طلب لاحق داخل الترويسة:

```
Authorization: Bearer <token>
```

الرمز صالح لمدة سنة من تاريخ إصداره ويُخزّن بصيغة مجزأة. حد تسجيل الدخول خمس محاولات فاشلة خلال 15 دقيقة لكل عنوان IP ولكل بريد؛ بعدها يتوقف الدخول 15 دقيقة. لا تحصل الحسابات غير مؤكدة البريد على رمز دخول.

## إدارة رمز الجهاز (يحتاج الرمز الحالي)

`POST /api/v1/rotate-token.php` مع `{ "device_label": "iPhone 15" }` يصدر رمزاً جديداً ويلغي الحالي داخل معاملة واحدة. احفظ الرمز الجديد فوراً.
`POST /api/v1/revoke-token.php` يلغي الرمز الحالي نهائياً؛ بعده لن يقبل هذا الرمز أي طلب.

## نقاط الدخول (Endpoints)

### تسجيل دخول عميل
`POST /api/v1/auth/login.php`
```json
{ "email": "a@a.com", "password": "12345678", "device_label": "iPhone 15" }
```
→ `{ "ok": true, "verification_required": true, "email_sent": true, "user": { ... } }` (HTTP 202)

### تسجيل حساب عميل جديد
`POST /api/v1/auth/register.php`
```json
{ "full_name": "أحمد", "email": "a@a.com", "password": "12345678", "phone": "+966501234567" }
```
→ `{ "ok": true, "token": "...", "user": { ... } }`

### تسجيل دخول محامٍ
`POST /api/v1/auth/lawyer-login.php`
```json
{ "email": "lawyer@a.com", "password": "12345678" }
```
→ `{ "ok": true, "token": "...", "lawyer": { ... } }`

### الملف الشخصي الحالي (يحتاج توكن)
`GET /api/v1/me.php`
→ `{ "ok": true, "type": "client"|"lawyer", "profile": { ... } }`

### دليل المحامين (عام، بدون توكن)
`GET /api/v1/lawyers.php?jurisdiction=السعودية&specialization=قانون تجاري&q=اسم`
→ `{ "ok": true, "lawyers": [ ... ] }`

### الأوقات المتاحة لحجز محامٍ (عام، بدون توكن)
`GET /api/v1/available-slots.php?lawyer_id=3&date=2026-09-25`
→ `{ "ok": true, "slots": ["09:00","09:30", ...] }`

### الاستشارات (يحتاج توكن)
`GET /api/v1/consultations.php` → قائمة استشاراتي (حسب نوع الحساب: عميل أو محامٍ)

`POST /api/v1/consultations.php` (عميل فقط)
```json
{ "lawyer_id": 3, "consultation_type": "video", "date": "2026-09-25", "time": "10:00", "topic": "استشارة عقد" }
```
→ `{ "ok": true, "consultation_id": 55 }` أو خطأ `409` إن أصبح الوقت محجوزاً بالتزامن.

### الإشعارات (يحتاج توكن)
`GET /api/v1/notifications.php` → `{ ok, unread_count, notifications: [...] }`
`POST /api/v1/notifications.php` مع `{ "mark_all_read": true }`

### القضايا (يحتاج توكن)
`GET /api/v1/cases.php` → قائمة قضاياي (عميل أو محامٍ حسب التوكن)
`GET /api/v1/cases.php?id=5` → تفاصيل قضية واحدة: `case`, `timeline`, `parties`, `claims`, `tasks`; يعرض `evidence` للمحامي المالك فقط. لا تتضمن البيانات مسارات الملفات؛ التنزيل يبقى عبر endpoint محمي.

### التقويم القانوني (للمحامي)
`GET /api/v1/calendar.php` → أحداث ومواعيد المحامي القانونية القادمة والحديثة.
`POST /api/v1/calendar.php` مع `title`, `event_type`, `event_at` بصيغة `YYYY-MM-DDTHH:MM`، و`case_id` اختياري لإضافة جلسة أو استئناف أو انتهاء/تجديد عقد أو موعد حكومي.
`POST /api/v1/calendar.php` مع `{ "action": "complete", "event_id": 7 }` لإكمال موعد يملكه المحامي.

### الفواتير (يحتاج توكن)
`GET /api/v1/invoices.php` → قائمة فواتيري (عميل أو محامٍ)، كل فاتورة فيها `is_overdue` محسوبة لحظياً

### المستندات وتحليلها بالذكاء الاصطناعي (عميل فقط، يحتاج توكن)
`GET /api/v1/documents.php` → قائمة مستنداتي
`GET /api/v1/documents.php?id=5` → تفاصيل مستند: `document`, `findings` (مخاطر/توصيات), `entities` (تواريخ/مبالغ/أطراف)
`POST /api/v1/documents.php` (multipart/form-data، حقل الملف `document` وحقل الموافقة `ai_consent=1`، واختياري `document_type=contract`) → يرفع ويحّلل بنفس محرّك الموقع ويطبق حد الباقة. عند اختيار العقد، تُحفظ التفاصيل المنظمة في `document.contract_analysis_json` وتظهر عند طلب تفاصيل المستند. يلزم الإقرار قبل الإرسال إلى مزود AI.

### مقارنة العقود (عميل فقط، يحتاج توكن)
`GET /api/v1/contract-comparisons.php` → المقارنات المحفوظة للمستخدم.
`GET /api/v1/contract-comparisons.php?id=4` → مقارنة واحدة يملكها المستخدم.
`POST /api/v1/contract-comparisons.php` مع JSON `{ "first_document_id": 4, "second_document_id": 8, "ai_consent": true }` → يقارن نتيجتي عقدين اكتمل تحليلهما، بحد أقصى خمس مقارنات في الساعة. النتيجة مقارنة للتحليلات المستخرجة وليست مقارنة حرفية للنص أو redline.

### المساعد القانوني الذكي (عميل فقط، يحتاج توكن)
`POST /api/v1/ai-chat.php`
```json
{ "message": "ما شروط إنهاء عقد العمل؟", "conversation_id": 12 }
```
`conversation_id` اختياري — إن غاب يُنشئ محادثة جديدة تلقائياً ويرجع رقمها.
→ `{ "ok": true, "conversation_id": 12, "reply": "...", "reference": "نظام العمل - المادة 80", "reference_article_id": 4 }`
نفس منطق الموقع بالضبط: يبحث بمكتبة الأنظمة أولاً (مجاني)، ويطبّق حد الباقة الشهري إن وُجد.

### الدفع الإلكتروني — بوابة N-Genius السعودية (عميل فقط، يحتاج توكن)
`POST /api/v1/pay-invoice.php`
```json
{ "invoice_id": 12 }
```
→ `{ "ok": true, "payment_url": "https://paypage...", "order_reference": "..." }`
افتح `payment_url` داخل WebView بالتطبيق (يدعم مدى، فيزا، ماستركارد). بعد إغلاق الصفحة، استعلم عن النتيجة الحقيقية:

`GET /api/v1/payment-status.php?invoice_id=12`
→ `{ "ok": true, "status": "paid"|"unpaid"|"overdue"|"cancelled", "gateway_state": "CAPTURED" }`
هذا الاستعلام يتحقق من بوابة الدفع الفعلية (وليس فقط ما هو محفوظ)، ويُحدّث حالة الفاتورة تلقائياً إن تأكد النجاح — بالإضافة لتحديث فوري مستقل عبر Webhook من طرف الخادم.

## الأخطاء

كل الأخطاء بصيغة: `{ "ok": false, "error": "..." }` مع كود HTTP مناسب (400/401/403/404/405/409).

## ملاحظات للتوسّع

- هذا الإصدار (v1) يغطي: الدخول/التسجيل، دليل المحامين، حجز الاستشارات، الإشعارات، القضايا، الفواتير، رفع وتحليل المستندات، والمساعد الذكي — يكفي لبناء تطبيق جوال كامل الوظائف.
- عند الحاجة لاحقاً لنقاط إضافية، يمكن إضافتها بنفس النمط (`api/v1/<resource>.php` + `api_authenticate($pdo)`) دون تغيير أي شيء بالموقع الحالي.
- تسجيل الدخول محدد بخمس محاولات فاشلة لكل IP ولكل بريد خلال 15 دقيقة. الرموز المخزنة مجزأة، ويمكن تدويرها أو إلغاؤها.
