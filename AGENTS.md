# AGENTS.md — Mawatheq Platform

PHP + MySQL/MariaDB LegalTech SaaS, Arabic-first (RTL) with an English (LTR) mode.
Three surfaces share one design system: public site, client portal, lawyer
workspace, plus the admin console.

## Layout

- `config/` — bootstrap and DB (`config.php`), `includes/totp.php` for 2FA.
- `includes/` — shared shells. `header.php`/`footer.php` for the public site,
  `app_header.php`/`app_footer.php` for the client portal,
  `lawyer/includes/lawyer_*.php` for the lawyer workspace,
  `admin/includes/admin_header.php` for the console.
  `nav.php` (nav data) and `ui.php` (icon set) are the single source of truth.
- `assets/css/style.css` → `app.css` → `enterprise.css` load in that order.
  `admin/assets/admin-ui.css` loads last for the console.
- Everything UI-facing is namespaced: client portal under `.app-*`, admin under
  `body.adm`.

## Dashboards and the service model

Both home screens (`dashboard.php` for clients, `lawyer/dashboard.php` for lawyers)
are ordered by what the user needs to act on, not by database shape. Three shared
modules back them — reuse these rather than re-deriving entitlements in a page:

- `includes/ai_services.php` — one definition of every AI service, its icon,
  description, and how a plan gates it (`ai_plan_usage()`, `ai_service_state()`,
  `ai_service_nav_items()`). The nav, the dashboards, and `includes/plan_limits.php`
  all read from here, so the UI cannot disagree with the server-side gate.
- `includes/workspace.php` — the shared workspace service catalogue
  (`ws_client_service_groups()`, `ws_lawyer_service_groups()`, `ws_render_service_groups()`).
  Firm-only destinations are omitted for a solo lawyer, matching `nav.php`, so the
  dashboards never render a link that answers 403.
- `assets/css/dashboard.css` — loaded last (after style/app/enterprise) and additive.
- `includes/dashboard_panels.php` — the shared read models for the list panels
  (lawyer: clients, open tasks, client documents; client: their lawyers, their
  documents, their alerts). Tenant-scoped through `tenant_owner_where()`, and the
  balance figures come from `finance_client_balances()`, so both home screens
  describe the same rows identically. A panel with no rows returns `[]` and the
  page renders its empty state rather than inventing content.

  Static assets must stay world-readable (`644`); only `config/secrets.php` is `600`.
  Apache serves CSS as a different user than the file owner, so a `600`/`660`
  stylesheet returns **403** and the dashboard loses its styling while every page
  still reports HTTP 200. When packaging or copying the tree, preserve modes.

  The language switch is handled in both shells — `includes/app_header.php` and
  `lawyer/includes/lawyer_header.php` — each applying `?lang=` to `$_SESSION['lang']`
  before `current_lang()` is read. The lawyer header carries its own `?lang=` control;
  a shell without the handler makes its switch silently inert.

Gating contract: `plans.ai_queries_limit` / `plans.document_analysis_limit` are
`NULL` for unlimited, and **no plan at all means no limit is enforced** (legacy
accounts). `check_ai_query_allowed()` / `check_document_upload_allowed()` in
`includes/plan_limits.php` are the enforcement points; the dashboards only display
the same numbers.

`lawyer/firm.php` is reachable by every lawyer (a solo lawyer uses it to *create* a
firm) — never gate it as firm-only. `lawyer/review-queue.php` *is* firm-member-only
and returns 403 for a solo lawyer, so only link it when `tenant_is_firm()` is true.

## Conventions

- All page CSS/JS must be CSP-safe (`script-src 'self'`): no inline handlers in
  new code, no third-party JS. `enterprise.js` is the behaviour layer and
  deliberately keeps the legacy `window.openModal(id)` / `window.closeModal(id)`
  API because older pages still call it from inline `onclick`.
- Bilingual strings: `is_rtl()` for the active direction, `t()` for public-site
  copy, `adm('ar', 'en')` inside the admin console.
- Reuse the design tokens (`--navy`, `--teal`, `--surface`, `--r-md`,
  `--focus-ring`, spacing `--s-*`) rather than new literals.

## Currency

The platform is single-currency per deployment. The active currency lives in
`site_settings.site_currency` and is chosen in `admin/settings.php`; it defaults
to SAR. Every amount on the site must go through `includes/currency.php` — never
hard-code a symbol, a 3-decimal assumption, or `number_format(..., 3)`.

- Read it with `currency_code()` (cached per request). After an in-process write
  to the setting, call `currency_forget_cache()`.
- Format with `money()` (HTML, RTL-safe), `money_text()` (plain text / CSV),
  `currency_number()` / `currency_raw()` (numbers only) and `currency_step()`
  for `<input type="number">`.
- Precision is per currency: SAR/AED/QAR/EGP/USD/EUR use 2 decimals, the legacy
  BHD/KWD/OMR/JOD use 3. In `includes/finance.php`, `finance_round()` rounds to
  the *invoice's own* currency (`finance_round_for()`), so old BHD invoices keep
  their third decimal after the site switches to a 2-decimal currency.
- Invoices carry a `currency` column; new rows default to the site currency via
  `finance_currency()`. `invoice_payments` copies the invoice's currency, so a
  payment always matches the invoice it settles.
- N-Genius uses the Saudi gateway (`api-gateway.ksa...`). Its settlement currency is
  configurable in `admin/settings.php` (setting `ngenius_currency`, default SAR) and must
  match the merchant/outlet account. `ngenius_supports_currency()` gates `pay-invoice.php`
  and `api/v1/pay-invoice.php`: an invoice in any other currency is refused rather than sent
  as the same numeric value (which would mis-charge it). The amount is sent in minor units
  (`ngenius_minor_units()` — halalas ×100 for SAR, fils ×1000 for 3-decimal currencies).
- 2FA is opt-in for admins: enable it at `/admin/two-factor.php`. There is no forced
  enrollment redirect; accounts without TOTP log in with a password alone. When TOTP is
  enabled, `admin_guard.php` requires a verified code before any admin route runs.
- Migration for existing databases: `database/upgrade_v44.sql`.

## Payment gateways (SADAD + N-Genius)

Two gateways share one adapter layer under `includes/gateway/`:

- `contract.php` (interface), `bootstrap.php` (registry + `gateway_all()` / `gateway_for_new_payment()`),
  `payment_service.php` (orchestration, `payment_transactions` logging, settle/idempotency).
- `sadad/{config,service,gateway}.php` — config-driven SADAD client. Endpoints are entered
  verbatim in the admin UI; the code never assumes a SADAD URL. `sadad-return.php` (browser)
  and `sadad-webhook.php` (server-to-server) both re-verify before settling.
- `ngenius/gateway.php` — thin wrapper over the existing `includes/ngenius.php` helpers.
- Admin UI: `admin/gateways.php` (the only place SADAD is configured), `admin/sadad-test.php`
  (JSON connection/test-payment probe), `admin/gateway-transactions.php` + `-transaction.php`.

Rules that matter:

- `payment_service.php` logs the initiation row as `event_type='ORDER_CREATED'`. This name is
  required by `payment-return.php`, `ngenius-webhook.php` and `api/v1/payment-status.php`;
  do not rename it.
- Per-tenant credentials live in `tenant_gateway_settings` (`settings_json`), encrypted with
  `MAWATHEQ_ENCRYPTION_KEY`. Secrets are stored tagged with `sadad_credentials_env`: switching
  Sandbox ↔ Live without entering new secrets clears them, so Sandbox keys can never be sent
  to Live. The admin test button never creates a real payment on Live.
- `/sadad-webhook.php` and `/ngenius-webhook.php` are CSRF-exempt in `config/config.php`
  (server-to-server, authenticated by their own secret/signature). The Bearer-token REST API
  under `/api/v1/` is exempt too because it authenticates with `Authorization`, not the session
  cookie. Every other POST — including the browser-facing `/api/` helpers such as
  `api/ai_chat.php` and `api/upload_document.php` — requires a CSRF token. Any new webhook path
  must be added to `$csrfExemptPaths` there.
- `invoice_payments.method` enum, the `finance_record_payment()` allow-list and the schema must
  stay in sync when adding a gateway method; `database/upgrade_v45.sql` extends the enum.
- Admin console pages must `require_once` `config.php`, `lang/strings.php`, `admin_guard.php`
  (and `audit_log.php` if they call `log_action()`) **before** using `is_rtl()` or `log_action()`
  at the top of the file — `admin_header.php` alone is not enough ordering if you use those
  helpers above the header include.

## Deployment (Apache / cPanel)

The app is verified against a real `php:8.4-apache` image, not only the PHP built-in
server — the built-in server hides production-only behaviour:

- Apache/mod_php drops the `Authorization` header before PHP runs. `.htaccess` exposes it
  via `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`; without that rule
  every `/api/v1/` Bearer request returns 401 and the mobile API is dead.
- `FilesMatch "(^\.)"` only matches a file's own name, so nested paths such as `.git/config`
  bypass it. `.htaccess` blocks the whole `.git` directory with a rewrite rule, and also
  blocks `config/`, `database/`, `includes/` and `tests/`.
- `config/secrets.php` must be `600` and owned by the hosting user (not `nobody`) so other
  shared-hosting accounts cannot read it; app files `644`, directories `755`. It is
  git-ignored and must never be committed.
- Roles log in through different pages: clients `/login.php`, lawyers `/lawyer-login.php`,
  admins `/admin/login.php`. There is no `/lawyer/login.php`.
- `admin/setup.php` is removed from the repo; a fresh install creates the first admin
  through it before it is deleted on the server.
- After every deploy run `php tools/production_check.php` on the server. The most common
  production failure is a database that never received the migrations: a missing column in
  `api_tokens` (e.g. `revoked_at`, added in `upgrade_v27.sql`) makes every authenticated
  `/api/v1/` request return a bare 500 instead of 401, which silently kills the whole mobile
  API. The tool checks the columns/tables the code depends on and exits non-zero on drift.
- Never deploy code ahead of the migrations it needs, and never assume a running site is on
  the current version: probe it (e.g. `/api/v1/ai-agents.php` 404 means pre-Phase-3).

## Testing

CLI suites live in `tests/` and run against the configured database with
`php tests/<name>_test.php` (each prints `N checks, M failure(s)` and exits
non-zero on failure): `tenant_isolation_test.php`, `phase3_finance_test.php`,
`phase3_ai_gateway_test.php`, `currency_test.php`, `sadad_gateway_test.php`,
`phase5_saas_core_test.php` (tenant scoping, notification fan-out, the firm
permission catalog and its no-escalation rule, and the audit actor extension).
`phase5_integration_test.php` (scoped API tokens, webhook signing/tamper/replay
rejection, event fan-out, delivery idempotency, retry accounting, inactive-endpoint skip),
`phase5_knowledge_scope_test.php` (negative access-control tests for the Knowledge Base
scope model — global/firm/case/private — driven through the real `knowledge_retrieve()`),
`phase5_knowledge_acl_test.php` (the additive per-source grant layer below).

Knowledge Base access control is the `knowledge_sources.scope_type`/`scope_id` model,
enforced identically by `knowledge_retrieve()` and `knowledge_semantic_search()`.
`knowledge_source_permissions` is an *additive ACL layer* that can only narrow that scope
model, never widen it — a grant on a source the scope model hides does not expose it.
Activation is opt-in and behavior-preserving: call `knowledge_acl_initialize()` to seed one
`read` grant per currently-visible source (`INSERT IGNORE`, idempotent); until any grant row
exists (`knowledge_permissions_initialized()`), retrieval behaves exactly as the plain scope
model. On any ACL error the code logs and falls back to the scope model rather than locking
users out. Integration token scopes are explicit only — `integration_token_issue()` refuses a
`'*'` wildcard so a stored token can never satisfy a scope that did not exist when it was issued.

`phase5_integration_http_probe.php` is a live HTTP probe (needs the app running on
`127.0.0.1:8080`); it issues scoped tokens and asserts the endpoint/scope matrix, the
unknown-endpoint 403, and revoked-token 401, then cleans up its own tokens.

`sadad_gateway_test.php` starts the real fixture server `tests/fixtures/sadad_server.php`
on an ephemeral port and exercises the live curl path — no HTTP mocking. It runs inside a
transaction and rolls back, so it leaves no residue.

Environment (local sandbox): install with
`sudo apt-get install -y php-cli php-curl php-mysql php-mbstring mariadb-server`, start with
`sudo service mariadb start`, and create the `mawatheq` DB/user matching `config/secrets.php`.
Load `database/schema.sql` then every `database/upgrade_v*.sql` in order (currently
through `upgrade_v52.sql`); the migrations are idempotent. `schema.sql` folds v50–v52, so a
clean install lands at **106 tables**, identical to a fully migrated database (verified
table-by-table). Replaying the upgrades over `schema.sql` is harmless.

UI work is verified with a Playwright audit that logs
in as each role (admin needs a fresh TOTP) and renders every page at several
viewports, checking horizontal overflow, sub-11px text and undersized targets.

- Seed accounts: `client@example.com` / `Test-Client-2026`,
  `lawyer@example.com` / `Test-Lawyer-2026`,
  `admin@mawatheq.com` / `Test-Admin-2026` (TOTP from `admins.totp_secret`).
- The audit clears `auth_rate_limits` before logging in; repeated runs otherwise
  trip the `admin-2fa` throttle and the admin login silently fails.
- `php -l <file>` on every touched PHP file is the quick syntax gate.

## Gotchas

- Tenant scoping is derived from the entity's own `firm_id`/`lawyer_id` columns.
  Some child tables inherit their tenant instead: `case_evidence` has no
  `lawyer_id`, so its queries must join the parent case (`JOIN cases c ON
  c.id = e.case_id`) and scope on the case alias. Passing such a table alias to
  `tenant_owner_where()` builds an invalid column and turns the page into a 500.
- Notifications to a firm must skip the acting lawyer; use
  `notify_tenant_except()` rather than `notify()` directly, and remember that
  `notifications` also backs the in-app bell rendered in every header.

- `lawyer/review-queue.php` returns 403 unless the account has a law-firm
  membership — that is the page's own guard, not a routing bug.
- Checkbox/radio inputs must stay >= 24px (WCAG 2.5.8); their wrapping `<label>`
  is the real hit area and should clear 44px. Some legacy pages hard-code a
  smaller inline size, so `enterprise.css` wins on `min-height`.
- Long tables switch to card mode on narrow viewports; a `min-width` on the
  table would defeat that.

## UI hardening: survive a missing enhancement layer

`enterprise.css` is the enhancement layer, but it is not guaranteed to load —
production served it (and `enterprise.js`) as **403** because the files were
mode `660`. Treat it as optional:

- Any rule the shell cannot live without must also exist in `app.css` (client,
  lawyer, admin) or `style.css` (public site, auth screens). Keep the fallback
  copies byte-identical to the enterprise rule so nothing shifts when both load.
  Covered today: mobile sidebar/nav stacking, skip-link, topbar
  title/spacer/actions, `.notif-bell-btn`, `.lang-switch`, `.app-navtoggle`,
  public `.nav-toggle`/`.main-nav` dropdown, and `table-cards`.
- Auth screens (`login.php`, `register.php`, `admin/login.php`,
  `lawyer-login.php`) load `style.css` **only** — never rely on `app.css` or
  `enterprise.css` there.
- Card-mode data tables: add `table-cards` to `.table-wrap` and
  `data-label="Column"` to every `<td>` (empty label hides the `::before`).
  Breakpoint is `820px`. The admin console also pins
  `body.adm .admin-table { min-width:680px }` in `admin-ui.css`, so the card
  override must live there too and match that specificity.
- Put media queries at the **end** of the file so they win cascade ties;
  `app.css` had its base `.admin-table` rules after the query and was silently
  losing.
- When auditing with the Chromium CDP tool, disable the HTTP cache
  (`Network.setCacheDisabled`) — a stale `app.css` produces false overflow
  results. Simulate the production failure with
  `--block "*enterprise.css"`.

## Auth portals: client vs lawyer vs admin (verified 2026-09-30)

Three separate, non-interchangeable entry points. Each keeps its own session key
and its own guard; none accepts a role from request input.

| Portal | Page | Session key | Guard | Post-login |
|---|---|---|---|---|
| Client | `/login.php` | `$_SESSION['user_id']` | client pages | `/ai-assistant.php` |
| Lawyer | `/lawyer-login.php` | `$_SESSION['lawyer_id']` | `lawyer/includes/lawyer_guard.php` | `/lawyer/dashboard.php` |
| Admin | `/admin/login.php` | admin session | `admin/includes/admin_guard.php` | `/admin/index.php` |

- A lawyer with a **correct password** can still be refused, and that is not a
  bug. `lawyer_guard.php` requires `email_verified = 1` **and**
  `status = 'approved'`. The login page must therefore report these as
  *notices*, not as "wrong password":
  - unverified -> "activate your email" + link to `/resend-verification.php`
  - verified but not approved -> "under review by admin"
  - suspended -> "contact support"
  Keep the wrong-credential message generic ("incorrect email or password") so
  the form does not confirm which emails exist.
- On success always `session_regenerate_id(true)` and audit-log the event.
  Failed POSTs must pass through `auth_rate_limit_failure()`; the limiter keys
  on IP and on the account, and after 5 tries returns the 15-minute message.
- CSRF: every auth POST validates `csrf_is_valid()` and returns 403 on a miss.

### Why a page can render "empty" in the browser but 200 in curl

An uncaught `Throwable` in a page body (e.g. a bad column alias in a new query)
aborts the include **after** `header.php` has already printed `<head>` + sidebar
+ topbar. HTTP stays 200, the byte count is plausible, and the content area is
blank. Always diff the rendered size against a known-good run and grep for
`fatal error|uncaught` - `php -l` will not catch it. Column aliases matter:
`tenant_owner_where($pdo, 'co')` must be built for the alias actually used in
the query, not the bare table name.

## POST-redirect pages must handle POST before rendering the shell

`includes/app_header.php` prints the whole `<head>`, sidebar and topbar immediately,
and there is no output buffering (`ob_start` is never called). A page that includes
the shell and *then* calls `header('Location: ...')` gets "headers already sent": the
redirect is dropped and the form action silently does nothing. Keep the POST handler,
its `header()` redirect and `exit` **above** the `require_once includes/app_header.php`
line. `case-tasks.php` and `draft-reviews.php` were both bitten by this; the two
symptoms were "clicking Mark complete / Submit review appears to work but nothing
changes".

## Client documents and case evidence are two different tables

`documents` is the client's own upload library (keyed by `user_id`); `case_evidence`
is the lawyer's per-case register (keyed by `case_id`, `added_by_lawyer_id`). They are
not the same store. `documents.case_id` (added in `upgrade_v47.sql`) is the optional
link that surfaces a client-filed document inside the case file. When storing a
`case_id` from a client form, always verify the case belongs to that client first —
otherwise a forged id attaches a document to another tenant's matter.

## `/admin/crm.php` looks empty on a fresh database — it is not broken

`admin/crm.php` guards itself with a probe over `crm_opportunities`, `crm_tasks`,
`crm_activities`, `crm_companies` and `contact_leads`; if any is missing it renders
a friendly "tables not ready" shell. On a *seeded but empty* install every probe
passes and the page renders 200 with the correct empty states ("لا توجد فرص بعد"),
so it reads as blank while being fully functional. Diagnose by row count, not by
HTTP status:

```bash
for t in crm_opportunities crm_companies contact_leads crm_tasks crm_activities; do
  printf '%-20s %s\n' "$t" "$(mariadb -N -B mawatheq -e "SELECT COUNT(*) FROM $t;")"
done
```

Enums to respect when seeding (`lead_type`: contact|join_lawyer|corporate;
`contact_leads.status`: new|contacted|closed; `crm_opportunities.stage`:
new|qualified|proposal|negotiation|won|lost; `crm_tasks.task_type`: task|follow_up;
`crm_tasks.status`: open|done; `crm_activities.activity_type`:
call|email|meeting|whatsapp|note|stage_change). On an opportunity card the `<small>`
under the title is the linked **user's** name (`users.full_name`), not the company —
that is by design, not a bad join.

## Per-firm public websites (v50)

Each law firm can publish a public website served from the platform, distinct from the
platform's own marketing site. The read side lives in `includes/firm_site.php`; nothing
public reads client/case/document tables.

- **Entry point is always `firm_site_public_firm($pdo, $slug)`** — it returns `null` unless
  the row has `is_public = 1` and a matching `public_slug`. Public pages (`firm.php`,
  `firm-page.php`, `firm-team.php`, `firm-articles.php`) call `firm_not_found()` on `null`;
  never render partial content for a draft firm.
- **Writes are gated by `firm_site_can_manage($pdo, $firmId, $lawyerId)`** — true only for
  `owner`/`partner` members of that firm. `lawyer/firm-site.php` shows a read-only view to
  everyone else; a non-manager POST gets 403.
- **Scope every write by the session's firm, not a request parameter.** The dashboard uses
  `WHERE id = ? AND firm_id = ?` on every UPDATE/DELETE, so a manager of firm A cannot touch
  firm B's page/branch/department/announcement even with a guessed id.
- Public team (`firm-team.php`) exposes only name, title, specialisation and bio — no email,
  phone or any client data. Unapproved (`status <> 'approved'`) lawyers are excluded.
- `articles.firm_id` marks firm-owned posts; `firm_site_articles()` filters on it, so the
  platform blog and firm blogs never cross.
- Migration is `database/upgrade_v50.sql` (additive, idempotent) and is mirrored into
  `database/schema.sql`; a clean install lands at 106 tables once v52 is folded in. Rollback SQL is in
  `MAWATHEQ_LAW_FIRM_MANAGEMENT_REPORT.md`.

**Credential gotcha (2026-09-29).** Only `GITHUB_TOKEN` is push-capable here; the
fine-grained `GITHUB_PERSONAL_ACCESS_TOKEN` authenticates and reports `push: true` on the
repo API but every actual write returns `403 Resource not accessible by personal access
token` (git and REST alike). The token embedded in `origin`'s URL is often stale, so push with
`https://x-access-token:$GITHUB_TOKEN@github.com/startgroup2022-tech/mawatheq.git`, or
`git remote set-url origin` to the same. Confirm non-destructively first with
`git push --dry-run`. Note the repo is **stacked**: PR #2 (`phase-4-enterprise-ui`) targets
`phase-3-finance-ai-integrations`, not `main`; do not open a second PR per branch.

## Executive 08: legal AI, conflict engine, HR, branch access, custom domains (v51)

Migration is `database/upgrade_v51.sql` (additive, idempotent) and is mirrored into
`database/schema.sql`; a clean install lands at **98 tables**, identical to the working DB
(verified table-by-table and column-by-column). Rollback SQL is in
`MAWATHEQ_EXECUTION_08_RELEASE_REPORT.md`.

### Branch-level access isolation (`includes/branch_access.php`)

Multi-branch firms file records at a branch and restrict a member to a subset of branches.

- **The model is opt-in.** A member with no `law_firm_members.branch_id` and no
  `member_branch_access` rows is **firm-wide** — never silently locked out. `owner`/`partner`
  are always firm-wide regardless of their home branch. Restriction only begins once someone
  explicitly assigns a member.
- `branch_access_context($pdo)` returns the resolved context; the cache lives in
  `$GLOBALS['__branch_access_cache']`, keyed by PDO object id + lawyer id.
- **`branch_access_flush_cache()` must be called after any membership/grant change** — the
  cache is per-request, so without the flush the rest of a request keeps acting on the
  pre-change access set. The management helpers
  (`branch_access_set_member_branch`, `branch_access_grant`, `branch_access_revoke`) call it
  for you; if you write the tables directly, call it yourself.
- **Scoping a list query:** `[$sql, $params] = branch_access_sql($pdo, 'c.branch_id', 'case', 'c.id')`
  returns `('', [])` for a firm-wide user (append nothing), otherwise a predicate that also
  admits rows shared via `branch_record_shares` (not revoked). `lawyer/cases.php` is the
  reference integration.
- A cross-branch **share** is granted *to* a branch (`branch_record_shares.branch_id` is the
  recipient), and only widens visibility while `revoked_at IS NULL`. `entity_type` is one of
  case|document|evidence|consultation|invoice.

### Conflict of interest (`includes/conflict_engine.php`)

- Register the parties the firm already knows (`conflict_parties`), then screen a new matter;
  a match opens a `conflict_reviews` row for a human decision.
- **AI may only flag, never decide.** Screening may attach an AI suggestion
  (`ai_assisted=1`, `ai_rationale`, `ai_confidence`), but `conflict_review_decide()` is a
  human action and each decision is written to `conflict_audit_trail` (append-only).
- Clearing a real conflict requires `cleared_with_override` **plus** a written
  `override_reason` **and** an `override_approved_by` who is an owner/partner of *that* firm —
  enforced server-side (`override_reason_required`, `approver_not_authorized`).
- Cross-firm review reads return `null`: `conflict_review_get()` scopes on firm id.

### HR (`includes/hr.php`)

Employees, attendance (manual + self check-in/out), leave requests and balances, schedules,
and monthly absence / lawyer-workload reports. **No salary or payroll data is collected.**
Every write resolves the firm from the session and enforces branch visibility. Self-service
attendance requires an `hr_employees.lawyer_id` link to the signed-in lawyer.

### Custom domains (`includes/firm_domains.php`)

Owner/partner add a hostname, publish the TXT token at `_mawatheq.<domain>`, press check,
activate. `firm_domain_verify()` performs a real DNS TXT lookup — a domain is never marked
verified because someone clicked a button. **HTTPS is a manual server step; there is no
automatic certificate issuance**, and the UI says so. An unverified domain does not serve.
Writes are firm-scoped; firm B cannot delete firm A's domain.

### One predicate decides whether AI is available (`includes/ai_provider.php`)

- **Always gate AI features on `ai_chat_provider_configured($settings)`.** It delegates to
  `ai_settings_configured()`, which encodes the real rule: a hosted provider (`openai`, `anthropic`)
  needs a non-empty `ai_api_key`, but a self-hosted `openai_compatible` provider needs only a valid
  `ai_endpoint` — a local model has no credential to send. Never re-derive the condition inline.
- Do **not** write `!empty($settings['ai_api_key'])` in a feature page. That check rejects a
  perfectly reachable keyless local model and produces the Arabic "activate an AI provider" message
  even though the provider works. Twelve pages had this bug; the test suite now asserts no page
  reintroduces it (`tests/execution12_ai_connectivity_test.php`, section 3).
- Do **not** hardcode `in_array($provider, ['openai','anthropic'])` as an availability test — that
  excludes `openai_compatible` entirely. When you genuinely need "can this provider read a file
  directly", use `ai_vision_provider_configured()`, which is exactly that and is separate from chat
  availability, so a chat-only provider is reported honestly rather than as unconfigured.
- **"Unconfigured" and "unreachable" are different states.** A valid-but-dead endpoint is still
  *configured*; the call fails with a connection error. `ai_diagnostics()` reports the two
  separately (`blocking` = `no_provider` / `no_endpoint` / `no_credentials` vs `unreachable`) and is
  surfaced read-only in `admin/ai-operations.php?diag=1`. It never returns a key or prompt content —
  keep it that way if you extend it.

### AI resilience and copilot (`includes/ai_resilience.php`, `includes/ai_copilot.php`)

- `ai_resilient_gateway_call()` wraps `ai_gateway_call` with retry + exponential backoff for
  **transient** errors only — a policy denial is final and never retried. Provider health
  lands in `ai_provider_health`; rate limiting in `ai_usage_events`.
- Runtime knobs are the site_settings keys `ai_timeout_seconds`, `ai_max_retries`,
  `ai_rate_limit_per_hour`, read by `ai_request_timeout()`, `ai_max_retries()`,
  `ai_rate_limit_per_hour()`. The `ai_timeout_seconds` value is threaded through
  `ai_gateway_call` into `ai_provider`'s `call_anthropic_raw()` / `call_openai_raw()`
  (`?int $timeoutSeconds`), so raising it here takes effect with no redeploy.
- **`ai_load_settings()` must select the keys the readers use.** It once selected only the older
  `ai_timeout`/`ai_retries`/`ai_rate_limit` names while the admin UI saved
  `ai_timeout_seconds`/`ai_max_retries`/`ai_rate_limit_per_hour`, so every knob silently fell back to
  its hardcoded default. Any new `ai_*` setting needs both a writer *and* a matching entry in the
  `ai_load_settings()` SELECT — otherwise it looks saved and does nothing.
- **The copilot is grounded, and the two halves must stay visually separate.** Verified
  columns are deterministic DB reads; the AI proposal is labelled and stored with
  `requires_review=1` and `status='pending_review'` until a lawyer approves it. Citation
  integrity is checked against the retrieved sources (`citation_integrity_ok`); unverified
  citations are surfaced, never silently dropped.
- **The privacy gate is re-entered on every attempt inside the retry loop** — a tenant in
  `local_only`/`external_disabled` never reaches an external provider, on any attempt.
- **A client may only ever see `status='approved'` copilot runs.** `case-summary.php` filters
  on that in SQL, so a template mistake cannot leak a draft.

### Where the new surfaces live

| Surface | Page(s) |
| --- | --- |
| Lawyer | `lawyer/conflicts.php`, `lawyer/hr.php`, `lawyer/branches.php`, `lawyer/domains.php`, `lawyer/ai-copilot.php` |
| Admin (super_admin) | `admin/ai-operations.php` (route RBAC in `admin/includes/admin_guard.php`) |
| Client | `case-summary.php` (linked from `my-cases.php`) |

### Tests

- `tests/executive08_test.php` — 145 DB-level checks across all six modules.
- `tests/executive08_http_probe.php` — 51 live-HTTP checks (auth gate, CSRF enforcement,
  cross-tenant isolation, domain lifecycle, copilot persistence). Needs the dev server on
  `127.0.0.1:12000`; it mutates firm 1 and restores afterwards.
  - Baseline suite stays green: 544 PHP checks + 51 HTTP checks, 0 failures.

  ## Executive 09 — enterprise SSO, TLS jobs, court-order extraction, legacy migration

  ### Enterprise SAML SSO (SP side)

  | Piece | File |
  | --- | --- |
  | Endpoints | `sso/login.php`, `sso/acs.php`, `sso/metadata.php`, `sso/sls.php` |
  | Firm settings | `lawyer/sso.php` (firm-scoped, owner/partner) |
  | Library | `includes/sso.php` (SP key material via `includes/secret_crypto.php`) |
  | Tables | `sso_configs`, `sso_login_attempts` (v52) |

  - The ACS endpoint **fails closed**: an unverified or tampered assertion is refused and
    recorded in `sso_login_attempts`; the audit trail never stores a raw assertion, only a
    reason and a `name_id_hash`/`ip_hash`.
  - When building a test Response with `php-saml`, pass the **IdP certificate** to
    `OneLogin\Saml2\Utils::addSign()`. Passing `null` emits an empty `ds:X509Data`, which fails
    the saml-schema XSD with "Missing child element(s)".
  - Sign the assertion **standalone**, strip its XML declaration, then embed it. `addSign()`
    signs the root element only, and a second XML declaration inside the Response is invalid.
  - SAML schema order inside `Assertion` is `Issuer` **then** `Signature`; the reverse fails the
    XSD.
  - SAML endpoints are exempt from the global CSRF guard in `config/config.php` (alongside
    webhooks) — the IdP cannot supply a CSRF token.

  ### Other Executive 09 surfaces

  | Surface | File(s) |
  | --- | --- |
  | Court-order extraction | `includes/court_order_extraction.php`, `lawyer/court-orders.php` |
  | Document text extraction | `includes/document_text.php` |
  | Domain TLS jobs | `includes/domain_ssl.php`, `cron/domain-ssl.php` |
  | Legacy chat migration | `includes/legacy_chat_migration.php`, `cron/migrate-legacy-chat.php` |

  - **AI output is never authoritative.** `court_order_extractions` keeps the deterministic
    reading (`verified_json`, each field carrying the snippet it came from) separate from the
    model reading (`suggested_json`, always a suggestion). Nothing reaches a client until a
    lawyer sets `status='approved'`.
  - **TLS jobs never claim a certificate that was not issued.** `domain_ssl_run()` marks a job
    `issued` only when a real TLS handshake presented a certificate valid for the host;
    otherwise it stays `pending_dns` or `manual_required`.
  - **Legacy chat migration preserves the original owner.** `ai_chat_archives.user_id` is carried
    over unchanged; `firm_id` is derived only from an existing case/consultation/invoice, so a
    migration cannot widen who can read an old conversation. Re-runs are no-ops.
  - **`documents` has no `firm_id`.** A document's firm is derived through
    `LEFT JOIN cases c ON c.id = d.case_id`. Do not assume `documents.firm_id` exists.
  - **`firm_domains.ssl_status` is an enum without an `issued` value** — use `ready` for a live
    certificate (`unknown|manual_required|ready|failed`).

  ### Tests

  - `tests/executive09_sso_test.php` — 13 checks: real signed assertion accepted, tampered
    assertion refused, disallowed domain refused, non-member refused, each with an audit row.
  - `tests/executive09_features_test.php` — 33 checks across `.doc` extraction, court-order
    extraction + review gate, legacy chat migration, and domain TLS jobs. Re-runnable and
    self-cleaning.


## Execution 10 — verification pass (browser suite, a11y, responsive)

### Running the browser suite

- Playwright lives in `tests/e2e/` with `playwright.config.js` at the root. Seven specs run
  across six viewports (`320x568`, `375x812`, `768x1024`, `1024x768`, `1440x900`, `1920x1080`),
  in both RTL and LTR — 588 tests total.
- The suite needs the dev server up: `php -S 127.0.0.1:12000 -t .`. Override the target with
  `MAWATHEQ_BASE_URL`. Seeded logins come from `tests/e2e/helpers.js`.
- **Run it in the background.** A full pass takes ~4.5 minutes, longer than a foreground
  terminal command may live; `nohup npx playwright test --reporter=line > /tmp/pw.log 2>&1 &`
  and poll the log.

### Below 1024px the workspace nav is off-canvas

- `includes/nav.php` renders `[data-app-nav-toggle]`; under `max-width:1024px` the sidebar is a
  fixed drawer parked outside the inline edge. Any test that clicks a nav link must open the
  drawer first, or the link is legitimately not visible.
- The parked drawer would otherwise give the page a horizontal scrollbar. It is contained with
  `html, body { overflow-x: clip }` in `assets/css/enterprise.css` — **`clip`, not `hidden`**:
  `hidden` turns the root into a scroll container and breaks `position: sticky`.

### `ui_e()` is not global

- `ui_e()` is defined in `includes/ui.php`, which the standalone auth pages do **not** load. On
  `login.php`, `register.php`, `admin/login.php` and friends, use `htmlspecialchars()` instead.
  Calling `ui_e()` there is a fatal error that returns a blank page — and a blank login page
  fails every browser test that starts by logging in.

### Accessibility assertions

- Every page must expose exactly one `<h1>`. The auth pages previously had a lone `<h2>`.
- `tests/e2e/accessibility.spec.js` also rejects any `<a>` with no accessible name. An icon-only
  logo link needs a non-empty `alt` (or `aria-label`); `alt=""` leaves the link unnamed.

### Delivery

- The repo is stacked: PR #2 (`phase-4-enterprise-ui`) targets `phase-3-finance-ai-integrations`,
  not `main`. **Do not open a second PR for this branch** — append an "Update — Execution NN
  pushed" section to the existing PR body instead.
- Push with an explicit token URL; the `origin` remote's stored credential is stale and 403s:
  `git push "https://x-access-token:${GITHUB_TOKEN}@github.com/startgroup2022-tech/mawatheq.git" phase-4-enterprise-ui:phase-4-enterprise-ui`
- Release packages are built with `zipfile` (no `zip` binary in this image). Exclude `.git`,
  `node_modules/`, `vendor/`, `storage/` (it holds a full DB dump), `config/secrets.php`, and
  build archives; keep `tests/`. Name the artifact `MAWATHEQ_EXECUTION_NN_COMPLETE.zip` with a
  `.sha256` sidecar.
  - Never commit an access token. One was previously embedded in `package.json`'s `repository.url`.
  - **Preserve deploy permissions in the archive.** The container umask leaves files group-writable
    (`664`/`660`) and directories `775`, and `zipfile` copies whatever mode is on disk. An archive
    built straight from the checkout therefore ships static assets as `660`, which a shared host
    serves as `403`. Before packaging, normalise the tree (`find … -type d -exec chmod 755` and
    `-type f -exec chmod 644`, with `config/secrets.php` at `600`), then verify with
    `php tools/production_check.php` (its "CSS/JS/module files readable (644)" check must be OK).
    Git already records these as `100644`, so this is purely an on-disk/packaging concern.

  ## Execution 11 — security audit, production readiness, PR review

  - `database/schema.sql` had folded v50/v51 but **not v52**, so a fresh install was missing all
    eight Executive 09 tables (`sso_configs`, `sso_login_attempts`, `domain_ssl_jobs`,
    `court_order_extractions`, `document_text`, `ai_chat_archives`, `ai_chat_archive_messages`,
    `legacy_chat_migration_log`). The fresh-install path in `DEPLOY_CPANEL_AR.md` imports only
    `schema.sql`, so the SSO endpoints and court-order extraction would 500 on a new host. v52 is
    now folded in (pure `CREATE TABLE IF NOT EXISTS`, no FKs, safe to replay); a clean import lands
    at 106 tables, matching a fully migrated database.
  - Local verification needs more than the DB: `executive09_sso_test.php` POSTs a real signed
    assertion to a **live server on `127.0.0.1:12000`**, while `phase5_integration_http_probe.php`
    and `phase_firm_site_http_probe.php` expect **`127.0.0.1:8080`**. Start both
    (`php -S 127.0.0.1:12000 -t .` and `… :8080 …`) before the HTTP suites, or they fail for
    reasons unrelated to the code. `executive09_features_test.php` also needs `antiword` (or
    `catdoc`) installed, and the probes need `tests/seed_phase6.php`, `seed_phase6_business.php`
    and `seed_crm_demo.php` run first. With all of that, all 13 suites and 3 probes pass
    (590 checks + 68 probe checks).
  - The security posture is sound and worth re-confirming rather than rebuilding: CSRF uses
    `hash_equals` and is enforced centrally in `auth_guard.php`; sessions set `use_strict_mode`,
    `use_only_cookies`, `HttpOnly`, `SameSite=Lax`, conditional `Secure`, and rotate on a 30-minute
    idle timeout; deployment secrets are AES-256-GCM encrypted at rest via
    `includes/secret_crypto.php`; `config/config.php` sends `nosniff`, `SAMEORIGIN`,
    `Referrer-Policy` and a CSP. No real credential exists in the working tree or in git history.

## Execution 12 — restore existing AI provider connectivity

- **A self-hosted provider was invisible to most of the app.** `ai_settings_configured()` allows an
  empty key for `openai_compatible`, but twelve call sites re-implemented the check as
  `!empty($settings['ai_api_key'])`. A reachable keyless local model was therefore reported as
  "not activated" on every AI feature. Fixed by `ai_chat_provider_configured()` — see the predicate
  section above. Diagnosing this from the UI is confusing because `admin/settings.php` already used
  the correct predicate: Settings said "configured" while `lawyer/drafting.php` said "not activated".
  When two surfaces disagree about AI availability, suspect a hand-rolled check, not the provider.
- **`127.0.0.1:11434` refusing does not mean the model is gone.** That is Ollama's default local
  address; a model on a separate VPS, another port, or behind a gateway refuses identically. Do not
  conclude the model is missing from a local probe alone.
- **Do not invent endpoints or model names.** Nothing in the repo records the owner's production AI
  host; it is only discoverable on the server (`~/.bash_history`, `ss -ltnp`, `ps aux`).
- `tests/execution12_ai_connectivity_test.php` needs a local mock OpenAI-compatible server on
  `127.0.0.1:11434` (`php -S 127.0.0.1:11434 /tmp/mockai/router.php`; the router source is in the
  test file's header comment). It is a **mock** — it proves the client speaks the OpenAI schema, not
  that any real model works. `tests/execution12_e2e_local_provider.php` demonstrates the fix end to
  end against that mock and restores the original settings afterwards.
- Suite totals after this execution: 13 suites + 3 probes (639 checks + 68 probe checks) and the
  Playwright suite, all green.

## Execution 12 — release artifact build

- **`php` is not installed in this image.** Every release so far assumed it was. Install it before
  any lint/readiness step: `sudo apt-get install -y php-cli php-mysql php-curl php-mbstring php-xml`
  (Debian 13 trixie ships **8.4.24**, exactly the production target). `sudo` is passwordless here.
- MariaDB **is** installable at the matching version (`sudo apt-get install -y mariadb-server` →
  11.8.6). Start it with `sudo mariadb-safe` after `sudo mkdir -p /run/mysqld && sudo chown mysql:mysql /run/mysqld`.
  `mariadbd --version` and `mysqld --version` may not be on PATH; use `sudo mariadb` as the client.
- To exercise `tools/production_check.php` you need a **sandbox** `config/secrets.php` (DB creds plus
  `ADMIN_SETUP_KEY`/`MAWATHEQ_ENCRYPTION_KEY` ≥32 chars), a writable `uploads/` (755), and at least one
  row in `admins`. Create it in the **extracted copy only**, then delete it — never in the repo. A clean
  `schema.sql` import lands at **106 tables**; production_check then reports 59 OK / 0 warn / 0 fail.
- `config/secrets.php` must be `600` **before** production_check runs; the checker fails a critical
  test otherwise. `chmod 755 uploads` too — `go-rX` makes the write check fail.
- Name the checksum sidecar `MAWATHEQ_EXECUTION_NN_COMPLETE.sha256` (matches the `.zip` stem) so
  `sha256sum -c MAWATHEQ_EXECUTION_NN_COMPLETE.sha256` works. The build script previously wrote
  `…COMPLETE.zip.sha256`; that convention breaks the standard verify command.
- The package is built from **tracked files at the verified commit** (`git ls-tree`), so a doc added
  after that commit (e.g. the deployment guide) is *not* inside the zip and does not change its hash.
  Keep the artifact reproducible: rebuilds must yield the identical SHA-256.
- Execution 12 changed **15 PHP files and no SQL** — `ai_provider.php`, `secret_crypto.php`, the six
  feature includes, four API endpoints, three lawyer pages and two admin pages. Incremental cPanel
  deployment therefore needs **no migration**; see `MAWATHEQ_EXECUTION_12_DEPLOYMENT_AR.md`.
- To hand the artifact over, serve **only the artifact files** on port 12000
  (`python3 -m http.server 12000 --directory /tmp/exec12_dist`) rather than the repo root — the repo
  contains `config/secrets.php` and uploads. Public URL: `https://work-1-<id>.prod-runtime.all-hands.dev/`.

## Execution 13 — landing page 2.0 and the Flutter mobile app

### Landing page 2.0 (public site)

- `index.php` was restructured into 12 `<section>` blocks (`platform`, `features`,
  `solutions`, `legal-ai`, `cases`, `documents`, `consultations`, `security`,
  `mobile`, `how`, `pricing`, `faq`), styled by the new `assets/css/landing.css`.
  `includes/header.php` gained the marketing shell and `lang/strings.php` the new
  copy, so **both** `ar` (default, `dir="rtl"`) and `?lang=en` (`dir="ltr"`) render
  the same sections. There is one canonical URL and a matching `sitemap.xml`.
- Prices on the landing page come from `SELECT ... FROM plans WHERE status='active'`
  in `index.php`. Never hardcode a price: the `plans` table is the only source.
  On a fresh DB it holds `أساسية` (0/0), `احترافية` (149/1490) and `مؤسسية`
  (799/7990) — note `ai_queries_limit`/`document_analysis_limit` are **NULL** for the
  paid tiers, meaning "unlimited", not zero.
- The page is served by the PHP dev server as `200` (~43 KB) with zero PHP notices;
  a `503` on it almost always means the DB credentials in `config/secrets.php` are
  wrong, not that the page is broken.

### Mobile app (`apps/mobile/`)

- Flutter client. **It never touches the database** — it speaks only to the existing
  `api/v1/` endpoints over HTTPS. Adding a screen means adding an `ApiRepository`
  method, not a query.
- Two endpoints were added for it, both following the public-`lawyers.php` pattern:
  - `api/v1/ai-status.php` — reports `available` plus a machine-readable `blocking`
    reason (`no_provider`, `no_credentials`, `unreachable`, ...). It never returns a
    credential. The assistant screen disables sending when `available` is false
    instead of faking an answer.
  - `api/v1/plans.php` — public list of active plans, so the app shows the shipped
    prices rather than its own copy of them.
- `POST /api/v1/auth/register.php` returns **202**, not 200 (email verification
  precedes token issuance) and takes `account_type` (`individual` | `company`).
  `ApiClient` treats any 2xx as success for exactly this reason; a client that only
  accepts 200 would reject a valid registration.
- The token is the only credential the app holds; it lives in the OS secure store.
  `TokenStorage` implements the `TokenStore` interface so `ApiClient` and
  `AuthController` can be unit-tested without the platform keychain.
- Verify with `flutter analyze` (must be clean) and `flutter test` (11 tests). The
  widget tests caught a real `RenderFlex` overflow in the pricing card that only
  appeared below ~430 px logical width — set a phone-sized test surface
  (`1080x2280 @3.0`) rather than the 800x600 default, or list screens overflow
  spuriously.
- Not built, and documented as such in `apps/mobile/README.md`: the document-upload
  file picker (needs a platform plugin outside the approved dependency set) and the
  firm-admin management screens.

