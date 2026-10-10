# تقرير قاعدة البيانات والترحيل v51 — منصة مواثيق

**الفرع:** `phase-4-enterprise-ui` · **التاريخ:** 2026-09-29
**الملفات:** `database/upgrade_v51.sql` (419 سطراً) · `database/schema.sql` (2004 سطراً)

> تدقيق فعلي: كل رقم أدناه مأخوذ من تشغيل استعلامات على قواعد حقيقية، لا من قراءة ملفات فقط.

---

## 1. مبدأ الترحيل

الترحيل **إضافي بالكامل**: `CREATE TABLE IF NOT EXISTS` وجمل `ALTER` إضافية فقط. لا `DROP`، ولا تغيير نوع عمود قائم، ولا إعادة تسمية. النتيجة: تطبيق الترحيل على قاعدة إنتاجية قائمة لا يفقد بيانات ولا يكسر استدعاءً قائماً.

## 2. محتوى الترحيل

**16 عبارة `CREATE TABLE` جديدة:**

| # | الجدول | الغرض | أعمدة بارزة |
|---|---|---|---|
| 1 | `conflict_parties` | سجل أطراف المكتب | `party_type`, `name_ar/en`, `id_number`, `commercial_register`, `branch_id` |
| 2 | `conflict_reviews` | مراجعة تعارض لكل قضية | `severity`, `match_type`, `match_score`, `status`, `override_reason`, `override_approved_by_lawyer_id`, `ai_assisted` |
| 3 | `conflict_alerts` | تنبيهات غير مقروءة | `review_id`, `is_read` |
| 4 | `conflict_audit_trail` | سجل ملحق فقط | `action`, `actor_lawyer_id`, `detail` |
| 5 | `hr_employees` | موظفو المكتب | `lawyer_id`, `department_id`, `branch_id`, `employment_type` |
| 6 | `hr_attendance` | حضور وانصراف | `work_date`, `check_in`, `check_out`, `status`, `source` |
| 7 | `hr_leave_balances` | أرصدة الإجازات | `leave_type`, `entitled`, `used`, `year` |
| 8 | `hr_leave_requests` | طلبات الإجازة | `start_date`, `end_date`, `days`, `status` |
| 9 | `hr_schedules` | جداول العمل | `schedule_type`, `start_at`, `end_at`, `location` |
| 10 | `member_branch_access` | منح عضو صلاحية فرع | `access_level` (member\|manager) |
| 11 | `branch_record_shares` | مشاركة سجل مع فرع | `entity_type`, `entity_id`, `branch_id`, `revoked_at` — مفتاح فريد `uq_brs` |
| 12 | `firm_domains` | نطاقات المكتب | `domain`, `verification_token`, `status`, `ssl_status`, `is_primary` |
| 13 | `ai_provider_health` | قياسات صحة المزوّد | `provider`, `model`, `ok`, `latency_ms` |
| 14 | `ai_copilot_runs` | تشغيلات المساعد | `kind`, `verified_json`, `suggested_json`, `status`, `requires_review`, `citation_integrity_ok` |
| 15 | `privacy_consents` | موافقات الخصوصية | `tenant/user`, `consent_type` |
| 16 | `data_retention_policies` | سياسات الاحتفاظ | `retention_days`, `scope` |

## 3. تحقق التثبيت النظيف

استُورد `schema.sql` في قاعدة فارغة:

```
جداول التثبيت النظيف: 98
جداول قاعدة العمل:    98
فرق مجموعتي الجداول:  صفر
```

## 4. تطابق الأعمدة (جدولاً بجدول)

قورنت الأعمدة وأنواعها بين القاعدة النظيفة وقاعدة العمل:

| الجدول | الأعمدة | النتيجة |
|---|---|---|
| `conflict_parties` | 18 | مطابق |
| `conflict_reviews` | 24 | مطابق |
| `conflict_audit_trail` | 10 | مطابق |
| `conflict_alerts` | 9 | مطابق |
| `firm_domains` | 14 | مطابق |
| `hr_employees` | 19 | مطابق |
| `branch_record_shares` | 9 | مطابق |
| `ai_copilot_runs` | 16 | مطابق |
| `ai_provider_health` | 9 | مطابق |

## 5. الحتمية

شُغِّل `upgrade_v51.sql` مرتين متتاليتين على قاعدة التثبيت النظيف: صفر خطأ، وعدد الجداول ثابت 98. كل عبارة `CREATE TABLE IF NOT EXISTS` لذا إعادة التشغيل آمنة.

## 6. مسار الترقية التراكمي

شُغِّلت ملفات الترقية من `v2` إلى `v51` بالترتيب على قاعدة فارغة: **صفر خطأ**.

```
for v in $(seq 2 51); do mariadb mawatheq_path < database/upgrade_v$v.sql; done
# لا خطأ
```

هذا يثبت أن قاعدة قديمة جداً (v1) يمكن ترقيتها إلى v51 بلا فجوة.

## 7. الفهارس والمفاتيح

- مفاتيح فريدة: `uq_brs` على `branch_record_shares(firm_id, entity_type, entity_id, branch_id)`؛ نطاق فريد على `firm_domains(domain)`.
- فهارس مستأجر: `firm_id` على كل جدول جديد.
- فهارس استعلام: `(work_date)` على `hr_attendance`، `(status)` على `conflict_reviews` و`ai_copilot_runs`.

## 8. SQL التراجع (يدوي، عند الحاجة فقط)

التراجع يحذف الجداول الجديدة فقط، ولا يمسّ أي جدول قائم. يُنفَّذ فقط بعد أخذ نسخة احتياطية وعلى قاعدة لم تُكتب فيها بيانات جديدة بالخطأ:

```sql
-- Executive 08 rollback — drops ONLY the v51 tables.
-- Requires a full backup taken before running it.
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS data_retention_policies;
DROP TABLE IF EXISTS privacy_consents;
DROP TABLE IF EXISTS ai_copilot_runs;
DROP TABLE IF EXISTS ai_provider_health;
DROP TABLE IF EXISTS firm_domains;
DROP TABLE IF EXISTS branch_record_shares;
DROP TABLE IF EXISTS member_branch_access;
DROP TABLE IF EXISTS hr_schedules;
DROP TABLE IF EXISTS hr_leave_requests;
DROP TABLE IF EXISTS hr_leave_balances;
DROP TABLE IF EXISTS hr_attendance;
DROP TABLE IF EXISTS hr_employees;
DROP TABLE IF EXISTS conflict_audit_trail;
DROP TABLE IF EXISTS conflict_alerts;
DROP TABLE IF EXISTS conflict_reviews;
DROP TABLE IF EXISTS conflict_parties;
SET FOREIGN_KEY_CHECKS = 1;
```

**تحذير:** التراجع يفقد كل بيانات التعارض وHR والموظفين والنطاقات. يجب أخذ نسخة احتياطية كاملة أولاً، والتحقق من عدم وجود بيانات تشغيلية معنوية في الجداول المذكورة.

## 9. قيود

- الترحيل يعمل على MariaDB 11.8.6 (MySQL 8 متوافق للمخطط الأساسي، غير مُتحقَّق منه حيّاً هنا).
- لا يوجد ترحيل بيانات (backfill) لبيانات قائمة: الجداول الجديدة تبدأ فارغة كما هو مقصود.
