# تقرير نظام إدارة مكاتب المحاماة — منصة مواثيق

**المشروع:** MAWATHEQ (مواثيق) — منصة LegalTech سعودية، عربية/RTL أولاً
**التقنيات:** PHP 8.4.24 · MySQL/MariaDB · متعدد المستأجرين (multi-tenant)
**الأسطح:** الموقع العام + موقع المكتب العام (جديد) + بوابة العميل + مساحة المحامي + لوحة الإدارة
**التاريخ:** 2026-09-29
**الفرع:** `phase-4-enterprise-ui`

> **إفصاح منهجي:** هذا التقرير مبنيّ على **تدقيق فعلي** للكود وقاعدة البيانات، وليس على تصميم نظري. كل بند صُنِّف إلى: منفَّذ ومُعاد استخدامه، أو منفَّذ حديثاً في هذه المهمة، أو غير منفَّذ (مُدرج بأمانة في قسم القيود). لم يُعَد بناء أي نظام قائم، ولم تُحذف أي ميزة عاملة.

---

## 1. جرد الميزات الكامل

### 1.1 المنفَّذ والمُعاد استخدامه (لم يُمَس)

| المجال | الحالة | المكونات الفعلية |
|---|---|---|
| النواة متعددة المستأجرين | منفَّذ | `law_firms`, `law_firm_members`, `includes/tenant.php` |
| مساحة المحامي | منفَّذ | `lawyer/dashboard`, `cases`, `case-detail`, `consultations`, `calendar`, `drafting`, `review-queue`, `invoices`, `profile`, `availability`, `firm`, `export-cases` |
| إدارة القضايا | منفَّذ | `cases` + `case_parties`, `case_notes`, `case_evidence`, `case_evidence_reports`, `case_tasks`, `case_timeline`, `case_facts`, `case_claims`, `case_risks`, `case_analyses`, `case_time_entries` |
| الجلسات والمحاكم | منفَّذ | `legal_calendar_events`, `lawyer/calendar.php`, `calendar-export.php` |
| المهام وسير العمل | منفَّذ | `case_tasks` (بحقل `client_visible`), `draft_workflow_reviews` |
| إدارة المستندات | منفَّذ | `documents`, `download-document.php`, `api/upload_document.php`, `document_entities`, `document_findings` |
| العقود والصياغة | منفَّذ | `draft_documents`, `draft_document_versions`, `contract_comparisons`, `draft_reviews` |
| المالية والمحاسبة | منفَّذ | `invoices`, `invoice_items`, `invoice_payments`, `payment_transactions`, `gateways`, SADAD, `admin/reports.php` |
| الموارد البشرية (أساسي) | منفَّذ | `lawyers`, `law_firm_members` (أدوار وصلاحيات `permissions_json`) |
| التقارير والتحليلات | منفَّذ | `admin/reports.php`, لوحات التحكم التحليلية |
| بوابة العميل | منفَّذ | `dashboard`, `my-cases`, `case-tasks`, `documents`, `invoices`, `consultations`, `account`, `draft-reviews` |
| الذكاء الاصطناعي والمعرفة | منفَّذ | `includes/ai_gateway.php`, `ai_services.php`, `knowledge_center.php`, ACL لكل مصدر، `semantic_search.php` |
| الإشعارات | منفَّذ | `notifications`, `includes/notifications.php` |
| التكاملات | منفَّذ | `api/v1/*`, `integration_api_tokens`, `integration_webhooks`, `integration_deliveries` |
| لوحة الإدارة المركزية | منفَّذ | `admin/*` (35 صفحة) |

### 1.2 المنفَّذ حديثاً في هذه المهمة

| الميزة الجديدة | الملفات |
|---|---|
| **الموقع العام لكل مكتب** | `firm.php` (الرئيسية)، `firm-page.php` (الصفحات)، `firm-team.php` (الفريق)، `firm-articles.php` (المنشورات) |
| **هيكل الموقع العام** | `includes/firm_header.php`, `includes/firm_footer.php`, `includes/firm_not_found.php` |
| **طبقة خدمة الموقع** | `includes/firm_site.php` (قراءة عامة + صلاحيات كتابة) |
| **لوحة إدارة موقع المكتب** | `lawyer/firm-site.php` |
| **الهيكل التنظيمي** | فروع (`firm_branches`)، أقسام (`firm_departments`)، إعلانات داخلية (`firm_announcements`) |
| **ترحيل قاعدة البيانات** | `database/upgrade_v50.sql` + دمج في `database/schema.sql` |
| **التنسيق البصري** | `assets/css/firm-site.css` + مساعدات مُضافة في `assets/css/dashboard.css` |
| **الاختبارات** | `tests/phase_firm_site_test.php` (43 فحصاً)، `tests/phase_firm_site_http_probe.php` (15 فحصاً حياً) |
| **SEO** | إدراج مواقع المكاتب المنشورة في `sitemap.php` + `LegalService` JSON-LD |

### 1.3 غير المنفَّذ (أُدرج بأمانة)

انظر القسم 12 «القيود المتبقية».

---

## 2. معمارية الموقع العام للمكتب

### 2.1 المفهوم

كل مكتب محاماة يستطيع إدارة **موقع عام خاص به** ضمن نفس منصة مواثيق، دون نظام منفصل. الموقع يُعرض بترويسة وتذييل **بهوية المكتب** وليس هوية المنصة.

### 2.2 المسارات

| المسار | الغرض |
|---|---|
| `/firm.php?firm=<slug>` | الصفحة الرئيسية للمكتب (نبذة، مجالات الممارسة، فريق، مقالات، تواصل، فروع) |
| `/firm-page.php?firm=<slug>&page=<slug>` | صفحة محتوى منشورة (من نحن / الخدمات / الخصوصية / مخصصة) |
| `/firm-team.php?firm=<slug>` | ملفات المحامين المهنية |
| `/firm-articles.php?firm=<slug>` | منشورات المكتب |
| `/lawyer/firm-site.php` | لوحة إدارة الموقع (مالك/شريك) |

### 2.3 الميزات المغطاة من المتطلبات

✅ اسم المكتب وشعاره وبيانات التواصل · ✅ صفحة رئيسية احترافية · ✅ نبذة عن المكتب · ✅ مجالات الممارسة · ✅ ملفات المحامين والفريق · ✅ الخدمات القانونية (عبر الصفحات) · ✅ حجز الاستشارة (رابط إلى `booking.php`) · ✅ نماذج التواصل والاستفسار (رابط إلى `contact.php`) · ✅ المقالات والمنشورات · ✅ عنوان المكتب وساعات العمل · ✅ بيانات SEO الوصفية والبيانات المنظمة · ✅ عربي RTL وإنجليزي LTR · ✅ تصميم متجاوب · ✅ سياسة الخصوصية والإشعارات القانونية · ✅ نشر المحتوى بتحكم المكتب.

### 2.4 العزل وعدم إفشاء بيانات العملاء

**عقد أمني مُختبَر:** كل قراءة عامة تبدأ من `firm_site_public_firm()` التي تُرجع `null` إلا إذا كان `is_public=1`. الصفحات العامة **لا تقرأ** أي جدول يتعلق بالعملاء أو القضايا أو المستندات — تعرض فقط المحتوى التسويقي للمكتب وملفات محاميه المهنية (الاسم، التخصص، النبذة) دون بريد أو هاتف. الصفحات غير المنشورة تُرجع **404** لا محتوى جزئي.

---

## 3. معمارية اللوحات

| اللوحة | المسار | الدور | الحالة |
|---|---|---|---|
| لوحة المحامي | `lawyer/dashboard.php` | المحامي | منفَّذ (إعادة تصميم Phase 4) |
| لوحة مالك/شريك المكتب | `lawyer/firm.php` + `lawyer/firm-site.php` | owner / partner | منفَّذ |
| لوحة مكتب العميل | `dashboard.php` | العميل | منفَّذ |
| لوحة الإدارة | `admin/*` | مدير المنصة | منفَّذ |

**الأدوار:** `owner`, `partner`, `lawyer`, `staff` في `law_firm_members.member_role`. الكتابة على موقع المكتب مقصورة على `owner`/`partner` عبر `firm_site_can_manage()`؛ أما `lawyer`/`staff` فيرون اللوحة **للقراءة فقط** (مُحقَّق حياً).

> **ملاحظة:** لا يوجد حساب «مدير مكتب» مُنشأ تلقائياً ككيان مستقل؛ إدارة المكتب تُدار عبر نموذج الأدوار القائم. هذا مُوثَّق كقيد في القسم 12.

---

## 4. إدارة القضايا / 5. إدارة الموظفين / 6. الإدارة المالية / 7. بوابة العميل

هذه المجالات **منفَّذة بالكامل في البنية القائمة** (انظر الجرد 1.1) ولم تُعَد بناؤها. أُجري لها **تحقق حيّ** عبر HTTP على صفحات حقيقية بعد تسجيل دخول فعلي:

- المحامي: `dashboard`, `cases`, `calendar`, `invoices`, `firm`, `firm-site`, `profile`, `drafting` → كلها **200** بلا أخطاء PHP.
- العميل: `dashboard`, `my-cases`, `documents`, `invoices`, `consultations`, `case-tasks`, `account` → كلها **200** بلا أخطاء PHP.

**الإضافة الجديدة** في مجال الموظفين/التنظيم: الفروع والأقسام والإعلانات الداخلية، مع ربط مدير القسم بعضوية المكتب الحقيقية.

---

## 8. الأمن والعزل

| الضابط | الحالة | الدليل |
|---|---|---|
| عزل المستأجرين على مستوى المكتب | مُحقَّق | اختبار `tenant_isolation_test` (24 فحصاً) + فحوص العزل الجديدة |
| RBAC | مُحقَّق | `firm_site_can_manage` يرفض العضو العادي والغريب |
| صلاحيات لكل قضية | منفَّذ | حراس الصفحات |
| وصول آمن للمستندات | منفَّذ | `download-document.php` |
| سجل التدقيق | مُطبَّق | `log_action()` على كل كتابة موقع/هيكل |
| أمن الجلسة | مُطبَّق | `csrf_is_valid` على كل POST، `lawyer_guard.php` |
| ترخيص API | مُحقَّق | `phase5_integration_test` (47 فحصاً) |
| ACL قاعدة المعرفة | منفَّذ | `phase5_knowledge_acl_test` (19 فحصاً) — تُضيّق فقط |
| خصوصية الذكاء الاصطناعي | مُحقَّق | `phase3_ai_gateway_test` (42 فحصاً) |

**ضوابط إضافية جديدة:**
- كتابة الموقع كلها مقيَّدة بـ `firm_id` المستخرج من الجلسة، لا من مُعامل الطلب.
- كل `UPDATE`/`DELETE` على كيان المكتب يتضمن `AND firm_id=?` — لا كتابة عبر المستأجرين.
- عناوين URL للصور تُفلتَر، والروابط تُتحقق عبر `FILTER_VALIDATE_URL`.
- مدير القسم يُتحقق من كونه عضواً نشطاً في نفس المكتب.

---

## 9. تغييرات قاعدة البيانات

**ترحيل جديد: `database/upgrade_v50.sql`** — إضافي فقط، آمن لإعادة التشغيل (`IF NOT EXISTS`)، ولا يحذف أي بيانات.

**أعمدة مضافة على `law_firms` (15):** `public_slug`, `is_public`, `tagline_ar/en`, `about_ar/en`, `practice_areas`, `working_hours`, `website_url`, `social_json`, `meta_title_ar/en`, `meta_description_ar/en`.

**أعمدة مضافة على `articles` (2):** `firm_id`, `author_lawyer_id`.

**جداول جديدة (4):** `firm_pages`, `firm_branches`, `firm_departments`, `firm_announcements` — كلها بمفتاح أجنبي `ON DELETE CASCADE` على `law_firms`.

**النتيجة:** من 78 إلى **82 جدولاً**. الترتيب `v47 → v48 → v49 → v50`. مُختبر على تثبيت نظيف من `schema.sql` (78 + 4 = 82 جدولاً).

### دليل التراجع (Rollback)

```sql
DROP TABLE IF EXISTS firm_announcements, firm_departments, firm_branches, firm_pages;
ALTER TABLE law_firms DROP KEY uq_firm_public_slug;
ALTER TABLE law_firms
  DROP COLUMN public_slug, DROP COLUMN is_public,
  DROP COLUMN tagline_ar, DROP COLUMN tagline_en,
  DROP COLUMN about_ar, DROP COLUMN about_en,
  DROP COLUMN practice_areas, DROP COLUMN working_hours,
  DROP COLUMN website_url, DROP COLUMN social_json,
  DROP COLUMN meta_title_ar, DROP COLUMN meta_title_en,
  DROP COLUMN meta_description_ar, DROP COLUMN meta_description_en;
ALTER TABLE articles DROP KEY idx_articles_firm,
  DROP COLUMN firm_id, DROP COLUMN author_lawyer_id;
```

---

## 10. الاختبارات والأدلة

### 10.1 مجموعات الانحدار الكاملة — **399 فحصاً، 0 فشل**

| المجموعة | الفحوص | الفشل |
|---|---|---|
| `tenant_isolation_test` | 24 | 0 |
| `phase3_finance_test` | 43 | 0 |
| `phase3_ai_gateway_test` | 42 | 0 |
| `currency_test` | 35 | 0 |
| `sadad_gateway_test` | 105 | 0 |
| `phase5_saas_core_test` | 18 | 0 |
| `phase5_integration_test` | 47 | 0 |
| `phase5_knowledge_scope_test` | 23 | 0 |
| `phase5_knowledge_acl_test` | 19 | 0 |
| **`phase_firm_site_test` (جديد)** | **43** | **0** |
| **الإجمالي** | **399** | **0** |

*(الأساس المرجعي السابق كان 356 فحصاً؛ أُضيف 43 فحصاً جديداً للموقع العام دون أي تراجع.)*

### 10.2 الفحص الحي عبر HTTP — **15 فحصاً، 0 فشل**

`tests/phase_firm_site_http_probe.php` يُشغّل تسجيل دخول حقيقي ثم:
- ✅ مالك المكتب ينشر الموقع فيصبح متاحاً علناً بمحتواه.
- ✅ الصفحة المنشورة متاحة والصفحة **المسودة 404**.
- ✅ محامٍ عادي يُرفَض بترميز **403** عند الكتابة، ويرى اللوحة للقراءة فقط.
- ✅ مالك مكتب آخر لا يستطيع حذف صفحة مكتب غريب (الصفحة تبقى).
- ✅ المكتب غير المنشور و slug غير موجود → **404**.
- ✅ البيانات تُستعاد بالكامل بعد الاختبار.

### 10.3 أدلة أخرى

- **فحص الجاهزية للإنتاج** (`tools/production_check.php`): **كل الفحوص الحرجة ناجحة**.
- **فحص HTTP التكاملي:** كل التوقعات محققة؛ الرمز المُبطَل → 401.
- **صلاحيات الملفات:** أُعيد ضبطها إلى 664 لتوافق النمط القائم وتجاوز فحص الجاهزية.
- **`config/secrets.php`:** غير متتبَّع، النمط `0600`.
- **لا أسرار في الملفات الجديدة** (فحص آلي: 0 مطابقة).

---

## 11. تعليمات النشر للإنتاج

1. **النسخ الاحتياطي الإلزامي:** خذ نسخة كاملة من قاعدة البيانات قبل الترقية.
2. **تطبيق الترحيل:** `mysql -u <user> -p <db> < database/upgrade_v50.sql` (آمن لإعادة التشغيل).
3. **التحقق من الجداول:** يجب أن يصبح العدد 82 جدولاً، مع وجود `firm_pages`, `firm_branches`, `firm_departments`, `firm_announcements`.
4. **رفع الملفات الجديدة:** `firm*.php`, `includes/firm_*.php`, `includes/firm_site.php`, `lawyer/firm-site.php`, `assets/css/firm-site.css`.
5. **الصلاحيات:** `chmod 664` على ملفات PHP/CSS الجديدة.
6. **الأسرار:** تأكد أن `config/secrets.php` بنمط `0600` وغير متتبَّع.
7. **التحقق بعد النشر:** افتح `/lawyer/firm-site.php` كمالك، انشر موقعاً، ثم افتح `/firm.php?firm=<slug>` في وضع التخفي.
8. **فحص الجاهزية:** `php tools/production_check.php` → «كل الفحوص الحرجة ناجحة».

---

## 12. القيود المتبقية (بأمانة)

1. **لا نطاق مخصص لكل مكتب (custom domain):** الموقع يُخدَم عبر مسار على نطاق المنصة. ربط DNS لكل مكتب غير منفَّذ.
2. **الفروع ليست حداً للعزل:** الفروع معلوماتية (عنوان/هاتف)؛ النطاق الفعلي للبيانات يبقى على مستوى المكتب (`firm_id`). عزل البيانات على مستوى الفرع غير منفَّذ.
3. **وحدة HR كاملة غير منفَّذة:** الحضور والإجازات والرواتب (attendance/leave/payroll) غير موجودة. الموجود: ملفات المحامين والعضوية والأدوار.
4. **محرّك تعارض المصالح غير منفَّذ:** يوجد عمود `conflicts_json` فقط في البذرة، بلا واجهة أو منطق فحص تشغيل.
5. **لا حساب «مدير مكتب» تلقائي:** الإدارة عبر نموذج الأدوار القائم، لا كيان مصادقة منفصل.
6. **تأليف مقالات المكتب:** عمود `firm_id` أُضيف وجاهز، لكن واجهة التأليف تبقى في لوحة الإدارة، ولم تُبنَ واجهة تأليف داخل مساحة المحامي.
7. **لا مساعد AI لصياغة محتوى الموقع** (متطلب «where authorized» — متروك للنطاق المستقبلي).
8. **لا سجل إصدارات كامل لصفحات المكتب:** التعديل يستبدل المحتوى بدل حفظ نسخ تاريخية.
9. **لا يزال الدفع إلى GitHub محظوراً** بسبب غياب بيانات اعتماد بصلاحية كتابة (تفصيله في تقرير منفصل `MAWATHEQ_GITHUB_RELEASE_AUDIT.md`) — لم يُدفع ولم يُدمج ولم يُنشر.

---

## 13. حزمة التسليم

**اسم الملف:** `MAWATHEQ_COMPLETE_FINAL.zip` (بحجم ~2.9 MB، نحو 340 ملفاً)
**ملف التحقق:** `MAWATHEQ_COMPLETE_FINAL.sha256` (يُرفق بجانب الأرشيف)
**رابط التنزيل:** `https://work-1-hvpklyytmluotnpk.prod-runtime.all-hands.dev/MAWATHEQ_COMPLETE_FINAL.zip`

**طريقة التحقق من السلامة:**
```bash
sha256sum -c MAWATHEQ_COMPLETE_FINAL.sha256
# أو: sha256sum MAWATHEQ_COMPLETE_FINAL.zip  ثم قارنه بمحتوى ملف .sha256
```

> لا يُدوَّن بصمة الأرشيف داخل الأرشيف نفسه (استحالة مرجعية ذاتية)؛ لذلك تُنشر البصمة في ملف `.sha256` مرافق.

**المستثنى عمداً (ولماذا):**
- `config/secrets.php` — بيانات الاعتماد الحقيقية (غير متتبَّعة في git أصلاً).
- `.git/` — تاريخ التنقيح الكامل.
- `storage/` — نسخ الترحيل الاحتياطية التي قد تحتوي تفريغاً كاملاً لقاعدة البيانات.
- `uploads/documents/*`, `uploads/evidence/*` — **مستندات عملاء حقيقية** (PDF) أُنشئت أثناء الاختبار؛ حُذفت من الحزمة مع الإبقاء على ملفات `.htaccess` الأربعة.
- `.agent_tmp/`, `node_modules/`, `vendor/`, `*.log`, `*.zip` — مخلفات تشغيل.

**مؤكَّد:** لا ملف أسرار، ولا ملفات مرفوعة لعملاء، ولا تفريغ قاعدة بيانات، ولا نسخة `.git` داخل الحزمة (فُحص آلياً).

---

## 14. إحصاءات الإنجاز

| المؤشر | القيمة |
|---|---|
| ملفات جديدة | 14 |
| ملفات معدّلة | 5 |
| أسطر مضافة | 1,882 |
| جداول قاعدة البيانات | 78 → **82** |
| مجموع الفحوص | 356 → **399** (0 فشل) |
| فحوص حية عبر HTTP | 15 (0 فشل) |
| فحوص الجاهزية للإنتاج | كل الحرجة ناجحة |

---

## 15. الخلاصة

أُضيف **موقع عام كامل لكل مكتب محاماة** — معمارية جديدة حقيقية داخل المنصة، لا إعادة تصميم بصري — مع هيكل تنظيمي (فروع/أقسام/إعلانات)، وإدارة كاملة من لوحة المكتب، وعزل مستأجرين مُختبَر، وكتابة مقصورة على owner/partner. أُعيد استخدام كل النواة القائمة (المصادقة، RBAC، العزل، القضايا، المالية، المستندات، الذكاء الاصطناعي، التكاملات) دون حذف أو تكرار. النتيجة: **399 فحصاً، 0 فشل**، وكل فحوص الجاهزية للإنتاج الحرجة ناجحة.

**لم يُدفع، ولم يُدمج، ولم يُنشر** التزاماً بالقيود.
