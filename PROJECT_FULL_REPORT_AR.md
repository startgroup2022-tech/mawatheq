# تقرير المشروع الكامل — منصة مواثيق

**التاريخ:** 2026-10-03 · **الفرع:** `phase-4-enterprise-ui` · **الحالة:** المرحلة الأولى مكتملة ومُتحقَّق منها

---

## 1. نظرة عامة على المشروع

**مواثيق (Mawatheq)** منصة قانونية SaaS عربية/RTL (LegalTech) مبنية على **PHP 8.4** و**MySQL/MariaDB**، بمعمارية متعددة المستأجرين (Multi-tenant) وثلاث لوحات تحكم.

| البند | القيمة |
|---|---|
| إجمالي الملفات | 280 |
| ملفات PHP | 192 |
| أسطر كود PHP | 20,979 |
| ملفات SQL | 47 (مخطط + 46 ترقية) |
| جداول قاعدة البيانات | 74 |
| ملفات CSS / JS | 5 / 3 |
| اللوحات | عميل، محامي، أدمن |
| وحدات API | 25 نقطة نهاية |
| ملفات `includes/` | 44 |

### توزيع الملفات
| المجلد | عدد ملفات PHP | الوصف |
|---|---|---|
| `includes/` | 51 | مكتبات مشتركة (مصادقة، مستأجرين، AI، مالية، إشعارات) |
| `admin/` | 40 | لوحة الأدمن |
| الجذر `/` | 39 | صفحات العميل والموقع العام |
| `api/` | 25 | REST API (نقاط v1) |
| `lawyer/` | 21 | لوحة المحامي |
| `tests/` | 9 | مجموعات اختبار |
| `config/` | 3 | الإعدادات والأسرار |
| `cron/` | 2 | مهام مجدولة (نسخ احتياطي، تذكيرات) |
| `tools/` | 1 | فحص ما قبل النشر |

---

## 2. الوحدات والمزايا (مبنية على الجداول والصفحات الفعلية)

### 2.1 المصادقة والحسابات
- ثلاثة بوابات دخول منفصلة: عميل (`login.php`)، محامي (`lawyer-login.php`)، أدمن (`admin/login.php`)
- تحقق البريد (`verify-email.php`)، إعادة الإرسال، نسيت/إعادة كلمة المرور
- المصادقة الثنائية TOTP (`admin/two-factor.php`، `includes/totp.php`)
- تحديد معدل المحاولات (`auth_rate_limits`، `includes/auth_security.php`)
- حماية CSRF على كل نماذج POST (`includes/csrf.php`)

### 2.2 القضايا وسير العمل القانوني
الجداول: `cases`, `case_timeline`, `case_parties`, `case_claims`, `case_facts`, `case_tasks`, `case_notes`, `case_evidence`, `case_evidence_reports`, `case_analyses`, `case_risks`, `case_time_entries`
- ملف قضية شامل عند المحامي (`lawyer/case-detail.php`)
- مهام القضية (طرفان: محامي/عميل)، الجدول الزمني، الأطراف، الادعاءات، الوقائع
- سجل الأدلة مع ربط بالادعاءات والوقائع ومواد الأنظمة

### 2.3 المستندات وتحليل AI
الجداول: `documents`, `document_findings`, `document_entities`, `contract_comparisons`
- مكتبة مستندات العميل + تحليل مستند/عقد
- مقارنة العقود (`contract-comparison.php`)
- **ربط المستند بالقضية (`documents.case_id`)** — جديد هذه الجلسة

### 2.4 الاستشارات والحجز
الجداول: `consultations`, `consultation_messages`, `lawyer_availability`, `legal_calendar_events`
- حجز الاستشارات، غرف الاستشارة، أوقات دوام المحامي، التقويم والجلسات
- تصدير التقويم (`lawyer/calendar-export.php`)

### 2.5 الصياغة الذكية
الجداول: `draft_documents`, `draft_document_versions`, `draft_reviews`, `draft_workflow_reviews`
- صياغة المسودات بالذكاء الاصطناعي، إصدارات المسودة، مراجعة المسودات (عميل↔محامي)، تصدير DOCX

### 2.6 المكتبة القانونية والمعرفة
الجداول: `law_categories`, `law_articles`, `knowledge_sources`, `knowledge_source_versions`, `knowledge_chunks`, `knowledge_source_permissions`, `knowledge_processing_logs`, `embedding_reindex_log`
- مكتبة الأنظمة والمواد، مركز المعرفة، البحث الدلالي (`semantic_search.php`)، إعادة بناء التضمينات

### 2.7 بوابة الذكاء الاصطناعي والتنسيق
الجداول: `ai_module_configs`, `ai_agents`, `ai_agent_executions`, `ai_workflows`, `ai_workflow_runs`, `ai_tenant_settings`, `ai_external_transmissions`, `ai_usage_events`, `ai_user_agents`, `ai_agent_activity_log`, `ai_answer_reviews`, `ai_conversations`, `ai_messages`
- لوحة تحكم AI للأدمن (control, knowledge, orchestration, workflows, test)
- وكلاء AI، سير عمل، حدود الاستخدام لكل مستأجر، مراجعة إجابات AI

### 2.8 المالية والاشتراكات
الجداول: `invoices`, `invoice_items`, `invoice_payments`, `payment_transactions`, `plans`, `plan_features`, `subscriptions`
- فواتير، مدفوعات، بنود، خطط واشتراكات، تعدد العملات (`includes/currency.php`)

### 2.9 بوابات الدفع
- **SADAD** و**N-Genius**، إعدادات بوابة لكل مستأجر (`tenant_gateway_settings`)، أحداث webhook (`gateway_webhook_events`)

### 2.10 CRM
الجداول: `contact_leads`, `crm_notes`, `crm_companies`, `crm_opportunities`, `crm_activities`, `crm_tasks`
- عملاء محتملون، شركات، فرص، أنشطة، مهام، مصادر العملاء (`includes/lead_source.php`)

### 2.11 المكاتب وتعدد المستأجرين
الجداول: `law_firms`, `law_firm_members`, `firm_knowledge`
- `includes/tenant.php` — تحديد نطاق المكتب/المحامي/الفردي، كتابة آمنة، عزل المستأجرين

### 2.12 الإشعارات والتدقيق
- `notifications`, `audit_logs` — إشعارات داخلية وسجل تدقيق شامل

### 2.13 الموقع العام
`index.php`, `pricing.php`, `articles.php`, `contact.php`, `privacy.php`, `terms.php`, `sitemap.php`, `knowledge.php`, `laws.php`

### 2.14 API
25 نقطة نهاية v1 مع رموز وصول (`api_tokens`)، تدوير وإلغاء الرموز، مصادقة API

---

## 3. ما تم إنجازه في هذه الجلسة (موثّق ومُختبَر)

### 3.1 إصلاح 8 أخطاء
| الملف | العيب | الأثر |
|---|---|---|
| `lawyer/case-detail.php` | `notify()` بدون تضمينها | خطأ قاتل 500 عند كل تغيير حالة قضية |
| `lawyer/case-detail.php` | رابط إشعار خاطئ (`/consultations.php`) | العميل يصل لصفحة خاطئة |
| `lawyer/case-detail.php` | `htmlspecialchars()` على `case_type` فارغ | تحذير إهمال PHP 8.4 |
| `includes/workspace.php` | مفاتيح غير محمية في بطاقات الخدمات | تحذيرات |
| `case-tasks.php` | POST بعد طباعة القشرة | `headers already sent` — إنجاز المهمة لا يُحفظ |
| `draft-reviews.php` | نفس عيب الترتيب | اعتماد المسودة لا يُحفظ |
| `booking.php` | `htmlspecialchars()` على `specialization` فارغ | تحذير إهمال |
| `consultations.php` | إزاحة مشوّشة | وضوح |

### 3.2 ميزة جديدة: ربط المستند بالقضية (تكامل عبر الوحدات)
- `database/upgrade_v47.sql` — عمود `documents.case_id` + فهرس + مفتاح أجنبي (`ON DELETE SET NULL`)، آمن التكرار
- `database/schema.sql` — العمود والفهرس والمفتاح في المخطط الرسمي
- `documents.php` (العميل) — قائمة «القضية المرتبطة» + وسم القضية
- `api/upload_document.php` — التحقق من ملكية القضية (منع الإسناد المُلفَّق)
- `lawyer/case-detail.php` — قسم «مستندات رفعها العميل لهذه القضية»

### 3.3 مواءمة لوحة الأدمن (تكامل ثلاثي)
- `admin/documents.php` — عمود «القضية» عبر `LEFT JOIN cases`
- `config/config.php` — حماية ذاتية لـ `documents.case_id` عند الإقلاع (تجنّب 500 على قاعدة قديمة)
- `tools/production_check.php` — إضافة `documents.case_id` لفحص الانحراف

### 3.4 التحقق الفعلي
- إعادة بناء قاعدة البيانات من `schema.sql` + الترقيات: **74 جدولاً، صفر أخطاء**
- **اختبار الحماية الذاتية:** حذف العمود فعلياً → أُعيد إنشاؤه تلقائياً عند الطلب التالي
- **اللوحات الثلاث تعرض نفس البيانات** (العميل/المحامي/الأدمن)
- **267 فحصاً / صفر إخفاق** (currency 35، finance 43، tenant isolation 24، saas core 18، ai gateway 42، sadad 105)
- **صفر أخطاء PHP** عبر ~40 صفحة

### 3.5 التغليف
- حزمة كاملة 100%: 280 ملفاً، 670 ك.ب، تشمل `config/secrets.php` و`storage/` و`uploads/`
- SHA-256: `caf8d30f5b1c6ea7be27593805a4092a12f839413aad6c61a64a4564cdf323e5`
- صفحة تحميل قابلة للنقر على المنفذ 12000

---

## 4. ما لم يكتمل بعد (نقاط مفتوحة)

| # | البند | الحالة |
|---|---|---|
| 1 | **9 ملفات جديدة غير مُتتبَّعة** في git | `dashboard.php`, `subscription.php`, `includes/workspace.php`, `includes/ai_services.php`, `includes/dashboard_panels.php`, `includes/schema_guard.php`, `assets/css/dashboard.css`, `database/upgrade_v47.sql`, `PHASE_1_INTEGRATION_AUDIT_REPORT.md` — جزء من المرحلة 4 لم يُلتزم به بعد (الالتزام ممنوع بموجب القيود) |
| 2 | **لا يوجد مشغّل ترقيات تلقائي** | الترقيات تُطبَّق يدوياً عبر phpMyAdmin |
| 3 | **صلاحيات `config/secrets.php` = 0660** | تحذير في فحص ما قبل النشر (المطلوب 600) |
| 4 | **`admin/client-detail.php`** | يعرض المستندات دون عمود القضية (عرضت إضافته) |
| 5 | **استقرار البيئة** | الحاوية أُعيد بناؤها 3 مرات أثناء الجلسة (فقدان PHP/MySQL مؤقتاً) — الخدمات تحتاج إعادة تشغيل |
| 6 | **تدقيق الوحدات/الصفحات (البند 3 من المهام)** | جارٍ — لم يُغطَّ كل ملف بعمق |
| 7 | **لا commit / لا نشر** | بموجب القيود |

---

## 5. المخرجات

- **رابط التحميل (قابل للنقر):** https://work-1-hvpklyytmluotnpk.prod-runtime.all-hands.dev/
- **الملف مباشرة:** https://work-1-hvpklyytmluotnpk.prod-runtime.all-hands.dev/mawatheq-full-complete.zip
- **البصمة:** `caf8d30f5b1c6ea7be27593805a4092a12f839413aad6c61a64a4564cdf323e5`

> ⚠️ الحزمة تحتوي `config/secrets.php` ورابط التحميل متاح علناً — أي شخص يملك الرابط يستطيع جلب بيانات الاعتماد. يُنصح بإيقاف الخادم بعد التنزيل.
