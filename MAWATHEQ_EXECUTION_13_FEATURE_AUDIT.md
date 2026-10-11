# MAWATHEQ — Execution 13: Full Feature Audit

**Source of truth:** the repository at commit `ccfb459` on `phase-4-enterprise-ui`.
Every row below was checked against actual files, routes and test suites — not assumed.

Legend — **Status**
- `OPERATIONAL` — implemented and exercised by an existing automated test.
- `CONFIG` — implemented, but needs owner configuration/credentials to work in production.
- `PARTIAL` — implemented for some roles/paths only.
- `PLANNED` — schema or route scaffolding only.

Legend — other columns
- **UI** — server-rendered PHP page(s) exist.
- **API** — `api/v1/` endpoint exists (`—` = none; the Flutter app must not invent one).
- **Perms** — enforced by `tenant_*` capabilities or role checks.
- **Landing** — should the new landing page present it (and how).
- **Flutter** — mobile screen exists in `apps/mobile` after this execution.

---

## 1. Platform-wide facts established during reconnaissance

| Fact | Evidence |
|---|---|
| Public entry point | `index.php` (landing), routed by `.htaccess` |
| API base | `/api/v1/` — versioned, Bearer tokens, documented in `api/v1/README.md` |
| Auth tokens | `api_tokens`, hashed, 1-year TTL, rotate + revoke endpoints |
| Login throttling | 5 failed attempts / 15 min per IP **and** per email (`auth_rate_limits`) |
| Tenancy | `includes/tenant.php` — `tenant_current()`, `tenant_can()`, firm scoping |
| Roles | client (`users.account_type`), lawyer, firm member (`owner`/`partner`/`lawyer`/`staff`), admin (`super_admin`/`editor`/`support`) |
| Permissions | 35 capabilities in 9 modules (`tenant_permission_catalog()`) |
| Jurisdictions | **8** — السعودية (default), البحرين, الإمارات, الكويت, قطر, عُمان, مصر, الأردن (`jurisdiction_options()`) |
| Schema | 107 tables in `database/schema.sql` (fresh install lands at 106 + 1 view/aux) |
| Tests | 14 PHP suites + 3 HTTP probes + 8 Playwright specs |
| AI provider | Anthropic / OpenAI / OpenAI-compatible (self-hosted, keyless allowed) |
| AI provider state | **Not configured in production** — Execution 12 fixed the code path only |

> **Jurisdiction note.** The platform is explicitly multi-jurisdiction, not Saudi-only. The approved
> mobile design reference carries a Saudi headline; the shipped UI generalises it to «أنظمتك
> القانونية» with السعودية as the default selection, to avoid hardcoding one jurisdiction.

---

## 2. Feature matrix

### 2.1 Public website

| Feature | UI | API | Perms | Tested | Prod dependency | Landing | Flutter |
|---|---|---|---|---|---|---|---|
| Landing page (`index.php`) | ✅ | — | public | `public-site.spec.js` | none | **rebuilt** | public home |
| AI assistant page (`ai-assistant.php`) | ✅ | `ai-chat.php` | login | `execution12_*` | **AI provider** | ✅ preview | ✅ assistant |
| Laws library (`laws.php`) | ✅ | — | public | `phase5_knowledge_*` | seeded law data | ✅ | research |
| Knowledge center (`knowledge.php`) | ✅ | — | mixed | `phase5_knowledge_scope_test` | ACL config | ✅ | research |
| Lawyers directory (`lawyers.php` implied) | ✅ | `lawyers.php` | public | `phase5_saas_core_test` | approved lawyers | ✅ | consult |
| Pricing (`pricing.php`) | ✅ | — | public | `public-site.spec.js` | `plans` rows | ✅ real prices | ✅ pricing |
| Booking (`booking.php`) | ✅ | `available-slots.php` | public | `phase5_saas_core_test` | lawyer availability | ✅ | ✅ |
| Articles / blog (`article.php`, `firm-articles.php`) | ✅ | — | public | `phase_firm_site_test` | — | ✅ | — |
| Contact (`contact.php`) | ✅ | — | public | `public-site.spec.js` | mail config | ✅ | ✅ |
| Privacy / Terms (`privacy.php`, `terms.php`) | ✅ | — | public | `accessibility.spec.js` | — | ✅ links | ✅ |
| Sitemap (`sitemap.php`) | ✅ | — | public | — | — | SEO | — |

### 2.2 Authentication & identity

| Feature | UI | API | Perms | Tested | Prod dependency | Landing | Flutter |
|---|---|---|---|---|---|---|---|
| Client register/login | ✅ | `auth/register.php`, `auth/login.php` | public | `phase5_saas_core_test` | mail (verification) | ✅ | ✅ |
| Lawyer login | ✅ | `auth/lawyer-login.php` | public | `phase5_saas_core_test` | admin approval | ✅ | ✅ |
| Email verification | ✅ | register returns `verification_required` | public | ✅ | **SMTP** | ✅ mention | ✅ |
| Password reset | ✅ | — (web only) | public | — | SMTP | ✅ mention | web link |
| 2FA / TOTP (`totp.php`) | ✅ | — | login | `TARGETED_FIX_2FA…` | — | ✅ | gap (documented) |
| SSO / SAML (`sso.php`, `sso_configs`) | ✅ | — | login | `executive09_sso_test` | **IdP config** | ✅ enterprise | gap (documented) |
| Token rotate / revoke | — | `rotate-token.php`, `revoke-token.php` | token | `phase5_saas_core_test` | — | — | ✅ sessions |
| Login throttling | — | built into `bootstrap.php` | — | `phase5_saas_core_test` | — | ✅ security | ✅ |

### 2.3 Client portal

| Feature | UI | API | Perms | Tested | Prod dependency | Landing | Flutter |
|---|---|---|---|---|---|---|---|
| Dashboard (`dashboard.php`) | ✅ | `me.php` | login | `client-portal.spec.js` | — | ✅ | ✅ |
| My cases (`my-cases.php`) | ✅ | `cases.php` | owner | `phase5_saas_core_test` | — | ✅ | ✅ |
| Case summary (`case-summary.php`) | ✅ | `cases.php?id=` | owner | ✅ | — | ✅ | ✅ |
| Case tasks (`case-tasks.php`) | ✅ | — | owner | — | — | ✅ | partial |
| Documents (`documents.php`) | ✅ | `documents.php` | owner | `phase5_saas_core_test` | — | ✅ | ✅ |
| Document upload + AI analysis | ✅ | `POST documents.php` | owner + consent | `execution12_*` | **AI provider** | ✅ | ✅ |
| Document analysis page | ✅ | `documents.php?id=` | owner | ✅ | AI provider | ✅ | ✅ |
| Contract comparison | ✅ | `contract-comparisons.php` | owner | `phase3_ai_gateway_test` | AI provider | ✅ | ✅ |
| Consultations (`consultations.php`) | ✅ | `consultations.php` | owner | `phase5_saas_core_test` | — | ✅ | ✅ |
| Consultation room | ✅ | `consultation_messages.php` | participant | `phase5_saas_core_test` | — | ✅ | messages |
| Invoices (`invoices.php`) | ✅ | `invoices.php` | owner | `phase3_finance_test` | — | ✅ | ✅ |
| Online payment | ✅ | `pay-invoice.php`, `payment-status.php` | owner | `sadad_gateway_test` | **gateway creds** | ✅ | ✅ WebView |
| Subscription (`subscription.php`) | ✅ | — | owner | `phase5_saas_core_test` | gateway | ✅ | ✅ |
| Notifications | ✅ | `notifications.php` | login | ✅ | — | ✅ | ✅ |
| Account / profile (`account.php`) | ✅ | `me.php` | login | `client-portal.spec.js` | — | ✅ | ✅ |

### 2.4 Lawyer workspace

| Feature | UI | API | Perms | Tested | Prod dependency | Landing | Flutter |
|---|---|---|---|---|---|---|---|
| Lawyer dashboard | ✅ | `me.php` | lawyer | `lawyer-workspace.spec.js` | — | ✅ | ✅ |
| Case management | ✅ | `cases.php` | `cases.*` | `phase5_saas_core_test` | — | ✅ | ✅ |
| Case timeline / parties / claims | ✅ | `cases.php?id=` | `cases.view` | ✅ | — | ✅ | ✅ |
| Evidence (`case_evidence`) | ✅ | `cases.php` (owner only) | `evidence.view` | `tenant_isolation_test` | — | ✅ | ✅ |
| Legal calendar | ✅ | `calendar.php` | `calendar.manage` | `executive08_test` | — | ✅ | ✅ |
| Drafting (`drafting.php`) | ✅ | — | `drafting.*` | `execution12_*` | AI provider | ✅ | gap (documented) |
| Draft reviews (`draft-reviews.php`) | ✅ | — | `drafting.review` | — | — | ✅ | gap |
| Time tracking (`case_time_entries`) | ✅ | — | — | — | — | ✅ | — |
| Conflict engine (`conflict_engine.php`) | ✅ | — | firm | `executive08_test` | — | ✅ firm | — |
| Court order extraction | ✅ | — | lawyer | `execution12_*` | AI provider | ✅ | gap |

### 2.5 Law firm administration

| Feature | UI | API | Perms | Tested | Prod dependency | Landing | Flutter |
|---|---|---|---|---|---|---|---|
| Firm profile | ✅ | — | `firm.view/edit` | `phase_firm_site_test` | — | ✅ | — |
| Team members (`law_firm_members`) | ✅ | — | `firm.manage_members` | `executive08_test` | — | ✅ | — |
| Role capabilities (35 perms) | ✅ | — | `tenant_can()` | `tenant_isolation_test` | — | ✅ | ✅ |
| Branches (`firm_branches`) | ✅ | — | firm | `executive08_test` | — | ✅ | — |
| Custom domains + SSL | ✅ | — | admin | `executive08_test` | **DNS/cert** | ✅ enterprise | — |
| Firm public site (`firm.php`) | ✅ | — | public | `phase_firm_site_test` | — | ✅ | — |
| Firm knowledge base | ✅ | — | firm | `phase5_knowledge_acl_test` | — | ✅ | — |
| HR / employees (`hr_*`) | ✅ | — | firm | `executive08_test` | — | ✅ enterprise | — |
| CRM (`crm_*`) | ✅ | — | firm | `executive09_features_test` | — | ✅ | — |

### 2.6 Admin console

| Feature | UI | API | Perms | Tested | Prod dependency | Landing | Flutter |
|---|---|---|---|---|---|---|---|
| Admin dashboard | ✅ | — | admin | `admin-console.spec.js` | — | — | — |
| Users / lawyers approval | ✅ | — | admin | `admin-console.spec.js` | — | — | — |
| AI operations + diagnostics | ✅ | — | admin | `execution12_ai_connectivity_test` | AI provider | — | — |
| Integrations & webhooks | ✅ | — | admin | `phase5_integration_test` | — | ✅ enterprise | — |
| Audit logs (`audit_logs`) | ✅ | — | admin | `executive08_test` | — | ✅ security | — |
| Site settings / plans | ✅ | — | admin | `phase5_saas_core_test` | — | — | — |

### 2.7 Security, privacy, AI

| Feature | UI | API | Perms | Tested | Prod dependency | Landing | Flutter |
|---|---|---|---|---|---|---|---|
| CSRF protection (`csrf.php`) | ✅ | — | all POST | `accessibility.spec.js` | — | ✅ | n/a |
| Tenant isolation | — | enforced centrally | — | `tenant_isolation_test` | — | ✅ | ✅ |
| Document access control | ✅ | protected download | owner | `tenant_isolation_test` | — | ✅ | ✅ |
| Encryption at rest (`secret_crypto.php`) | — | — | — | `execution12_ai_connectivity_test` | **key** | ✅ | n/a |
| Audit trail | ✅ | — | admin | ✅ | — | ✅ | — |
| Data retention (`data_retention_policies`) | ✅ | — | admin | — | — | ✅ privacy | — |
| AI: laws-first retrieval | ✅ | `ai-chat.php` | login | `execution12_*` | AI provider | ✅ | ✅ |
| AI: document findings/entities | ✅ | `documents.php` | owner | ✅ | AI provider | ✅ | ✅ |
| AI: copilot runs / workflows | ✅ | — | firm | `execution12_*` | AI provider | ✅ | gap |
| AI: agents (`ai_agents`) | ✅ | `ai-agents.php` | firm | `execution12_*` | AI provider | ✅ | — |

---

## 3. Verified vs. unverified — the honest position

### Verified operational (test-exercised, no external dependency)
Cases, documents (storage + ACL), consultations + booking, invoices, notifications, calendar,
tenant isolation, permissions, CSRF, audit logs, knowledge ACL/scope, firm site, CRM, HR,
currency, plan limits.

### Implemented but requires configuration
| Feature | Missing |
|---|---|
| **Legal AI inference** | An AI provider + key/endpoint in admin settings. Code path is fixed (Execution 12); no provider is configured. |
| Email (verification, reset) | SMTP credentials |
| Online payment | N-Genius / Sadad merchant credentials |
| SSO / SAML | Identity provider metadata |
| Custom domains + SSL | DNS + certificate issuance |
| Integration webhooks | Destination URLs + secrets |

### Partially implemented
- 2FA exists for admins, not for clients.
- Messages exist inside a consultation, not as a general inbox.
- Firm HR/CRM have web UI but no mobile API.

### Planned only
- Native push notifications (no FCM/APNs registration exists).
- Mobile-specific endpoints beyond `api/v1/` (not needed — see below).

---

## 4. Mobile readiness of the existing backend — the decisive finding

`api/v1/` **already exists and is Flutter-ready**. It was built in an earlier execution for exactly
this purpose (`api/v1/README.md` says so explicitly). It provides:

| Endpoint | Purpose |
|---|---|
| `auth/register.php`, `auth/login.php`, `auth/lawyer-login.php` | authentication + device label |
| `me.php` | current profile (client or lawyer) |
| `lawyers.php`, `available-slots.php` | public directory + booking slots |
| `consultations.php` | list / create consultations |
| `cases.php` | list + detail (timeline, parties, claims, tasks, evidence) |
| `documents.php` | list / detail / **upload + AI analysis** |
| `contract-comparisons.php` | list / detail / create |
| `ai-chat.php` | legal AI with laws-first retrieval + references |
| `invoices.php`, `pay-invoice.php`, `payment-status.php` | billing + gateway |
| `notifications.php` | list + mark-read |
| `calendar.php` | legal calendar |
| `rotate-token.php`, `revoke-token.php` | session lifecycle |

**Consequence:** Execution 13 needs **no new endpoint for the core mobile app**. The Flutter client
consumes `api/v1/` as-is. This satisfies Rule 0 ("do not duplicate backend logic") and Phase 8
("implement minimal secure mobile-facing endpoints only where required") — the honest answer is
*where required = nowhere for the core screens*.

### Gaps that are documented, not fabricated
| Capability | Why no mobile screen | Correct handling |
|---|---|---|
| Drafting / draft reviews | No `api/v1/` endpoint; web-only workflow | Document the gap; propose the contract; do not fake it |
| Firm HR / CRM | Web-only | Document as future roadmap |
| 2FA enrolment | Web-only | Deep-link to web |
| Push notifications | No device-registration table | Document; in-app notification list is real |
| Contract comparison upload | Needs two analysed documents | Uses real endpoint, may be empty |

---

## 5. Landing-page presentation rules derived from this audit

1. Present the platform as a **complete legal workspace**, not an AI chatbot.
2. Pricing must come from the `plans` table (0 / 149 / 799 SAR) — never invented.
3. Jurisdiction copy must say **«أنظمتك القانونية»** and list the 8 supported jurisdictions;
   Saudi stays the default but is not hardcoded as the only one.
4. AI section must describe **laws-first retrieval with references** and must **not** claim live
   inference is running.
5. Mobile section shows the real Flutter app and states honestly that store links come after publication.
6. Testimonials come from the `testimonials` table only — no invented counters. The existing
   «+100K / +18K» figures are replaced by verified statements or removed.
7. Security claims are limited to what exists: hashed tokens, throttling, RBAC, tenant isolation,
   audit logs, CSRF, encryption helper — no certification claims.

---

## 6. Toolchain status for this execution

| Tool | Available | Notes |
|---|---|---|
| PHP 8.4.24 | ✅ installed | matches production |
| MariaDB 11.8.6 | ✅ installed | matches production |
| Node 24 / npm 11 | ✅ | Playwright present |
| Flutter 3.35.4 / Dart 3.9.2 | ✅ installed to `/tmp/flutter` | stable |
| Android SDK | ❌ | no `sdkmanager`; APK build not attempted |
| Xcode / macOS | ❌ | iOS build cannot run on Linux |

iOS builds are therefore **not claimed**. Android APK depends on SDK availability (checked in
`MAWATHEQ_MOBILE_TEST_REPORT.md`).

---

_This audit was produced by an AI agent (OpenHands) on behalf of the repository owner._
