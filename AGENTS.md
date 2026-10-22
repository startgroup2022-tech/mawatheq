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
through `upgrade_v54.sql`); the migrations are idempotent. `schema.sql` folds v50–v52, so a
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

  ### Outbound mail: prefer SMTP over PHP mail()

  `includes/mailer.php`'s `send_mail()` is the single entry point for all system
  mail (account verification in `includes/auth_security.php`, password reset in
  `forgot-password.php`). It prefers the authenticated SMTP relay configured in
  **Admin → Settings → Email delivery** (`includes/smtp.php`, STARTTLS/SSL,
  AUTH LOGIN) and falls back to PHP `mail()` only when SMTP is off. The SMTP
  password is stored encrypted in `site_settings` via `secret_store_setting()`.

  On shared cPanel hosting `mail()` frequently fails silently: it can return
  `true` with nothing delivered, offers no authentication, and a sending IP
  without reverse-DNS / SPF alignment gets its mail rejected or spam-foldered
  with no error visible in the app. When diagnosing "verification email never
  arrives", check `mail_diagnostics($pdo)` (also surfaced on the settings page)
  before touching application code - it reports the active transport, whether
  the SMTP host is reachable, and why the legacy path would fail. The
  `send_mail()` return value is what drives `email_sent` in
      `api/v1/auth/register.php`, so a silent delivery failure is now visible there.

    `includes/smtp.php` is an **optional** module, not a hard dependency. The app
    must keep sending through `mail()` on any deployment that did not ship the new
    file (partial FTP upload, a release archive built from an exclusion list, a
    host that only synced tracked files). `send_mail()` therefore guards the
    require with `is_file()`/`function_exists()`, and `admin/settings.php` defines
    local fallbacks when the module is missing; an unconditional
    `require_once includes/smtp.php` turns a harmless "SMTP not configured" host
    into a fatal on **every** registration, resend and password reset. This was a
    real regression (see `tests/mail_smtp_test.php`, section 7) — keep the module
    optional and let the legacy path remain the zero-config default.

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


## Execution 15 — password reset, API auth, and Android App Links (v52)

### One shared reset implementation, three callers

Password reset used to be duplicated between `forgot-password.php` and
`reset-password.php`. Both now call `includes/auth_security.php`, which is also what
the mobile API uses, so the website and the app cannot drift apart:

- `password_reset_account_config($type)` maps `client`/`lawyer`/`admin` to
  `users`/`lawyers`/`admins` and their login/forgot URLs. An unknown type falls back
  to `client` — never to a caller-supplied table name.
- `issue_password_reset()` stores a 64-hex token with a 1-hour `reset_expires` and
  emails `SITE_URL/reset-password.php?as=<type>&token=<token>`. It returns the mail
  delivery result but **never** the token, and the page ignores the boolean so the
  response never reveals whether the address exists.
- `consume_password_reset()` claims the token once, rejects
  `invalid_token`/`weak_password`/`too_long`/`mismatch`, clears the token, and
  revokes every `api_tokens` row for the account. Setting a password invalidates
  tokens issued before it, so a stolen session cannot outlive the reset.
- The reset token is stored as a SHA-256 hash, never plaintext (Execution 29). The
  `reset_token` column keeps its name but now holds the hash, not the raw value. The
  database is a copy that leaves the server in every backup, dump and support ticket,
  so a plaintext reset token there is a usable credential for anyone who reads it.
  `issue_password_reset()` writes `hash('sha256', $token)` and
  `consume_password_reset()` hashes the presented token before matching, so the raw
  value exists only in the email link.
- Consumption is atomic: the claim is one conditional
  `UPDATE ... SET reset_token=NULL, ... WHERE reset_token=? AND reset_expires>NOW()`,
  and the affected-row count decides the winner. Two concurrent submissions of the same
  link cannot both succeed. A read-then-check-then-write pair would let both requests
  pass the check before either cleared the token.
- A reset also stamps `session_invalidated_at` on the account. The session guard
  compares it against the session's start time and ends sessions older than the reset,
  so a reset logs out anyone already signed in — not only the API tokens.

### API auth endpoints

`api/v1/auth/forgot-password.php` and `api/v1/auth/reset-password.php` are public
and mirror the pages. The forgot response is a neutral 200 whether or not the
address exists; `429` marks the 15-minute throttle; the reset returns `410`
(invalid/expired token) or `422` (weak/too-long/mismatch). Documented in
`api/v1/README.md`.

### Mobile deep links (`apps/mobile/lib/core/deep_link_service.dart`)

- `IncomingLink.tryParse()` requires `https`, an allow-listed host
  (`mawatheq.com`, `www.mawatheq.com`), the `/reset-password(.php)` path, and a
  64-hex token. A link failing any check is dropped — a hostile link cannot steer
  the app to another host or endpoint.
- `DeepLinkService` bridges `app_links` into the running app and hands the token to
  `ResetPasswordScreen`. `onResetPassword` is settable (assigned after the widget
  tree is built).

### Android App Links

Three things must agree or the link opens the browser instead of the app:

1. `android:autoVerify="true"` intent filters in `AndroidManifest.xml`
   (`mawatheq.com` + `www.mawatheq.com`, path prefix `/reset-password`).
2. `<repo root>/.well-known/assetlinks.json`, served by the web root, with
   `package_name = com.mawatheq.app`.
3. The **release** certificate SHA-256 fingerprint in `assetlinks.json`.

The committed `assetlinks.json` carries a `REPLACE_WITH_RELEASE_SHA256_FINGERPRINT`
placeholder — the flow is complete in code but App Links do **not** verify until the
owner pastes the real fingerprint (from the upload key, or the Play App Signing key
in Play Console → App integrity). `key.properties` (gitignored) drives release
signing; without it the release build falls back to the debug signature, which will
not verify against a release fingerprint. Emulator/device check:
`adb shell pm get-app-links com.mawatheq.app`.

### Tests

- `tests/password_reset_test.php` — the shared helper contract (single-use token,
  1-hour TTL, refusals, session revocation, throttling). Runs in a rolled-back
  transaction.
- `apps/mobile/test/exec15_password_reset_test.dart` — link parsing (host/path/token)
  and the forgot/reset screens against a fake transport.

### Suite prerequisites are environmental, not defects

- `tests/execution12_ai_connectivity_test.php` reports 3 failures (mock up, live
  reachable, blocking=null) when the mock OpenAI server is **not running**. Start it
  first: `mkdir -p /tmp/mockai`, drop the router from the test header, then
  `php -S 127.0.0.1:11434 /tmp/mockai/router.php`. With it up the suite is 49/49.
- `tests/executive09_features_test.php` needs `antiword` or `catdoc` on PATH for the
  `.doc` extraction check; the image here has neither.
- `tests/sadad_gateway_test.php` passes 105/105 in isolation; a couple of failures in a
  single `for` loop over every suite are pre-existing cross-suite state leakage, not
  caused by the seed or the reset work.
- `tests/seed_phase6.php` is idempotent: the firm-membership row is upserted on
  `uq_law_firm_member_lawyer` (a plain INSERT threw `SQLSTATE 1062` on the second run).


## Execution 29 — security hardening and runtime database safety

Four defects were found by writing probes that attack the app over real HTTP, not by
reading it. Each fix below has a regression suite named beside it.

### Runtime schema mutation is opt-in

`config/config.php`, `api/v1/bootstrap.php` and `includes/jurisdiction.php` used to call
`schema_ensure_column()` on every request, so a missing column was added as a side effect
of serving a page. That means the schema was changed by whichever request arrived first,
concurrently, with no ordering against the migration files and no record of what was
applied — the reason a drift incident was hard to reason about. Self-healing now requires
`MAWATHEQ_ALLOW_RUNTIME_SCHEMA_DDL=1`; the default path is
`database/repair/00_preflight_check.php` to report drift and `database/upgrade_v*.sql` to
fix it. `tests/execution29_runtime_ddl_test.php` drops a column, drives requests, and
asserts it is not re-added. The helper still validates identifiers before interpolating
them, so it can never become an injection point.

### Admin RBAC is shared, and root-level routes must apply it

The role map lived as a local variable inside `admin/includes/admin_guard.php`, so a route
outside `/admin/` could not use it. `download-document.php` sits at the web root and its
only check was "is an admin logged in" — an `editor`, a role the map refuses the documents
console, could download any client's document by id. The map now lives in
`includes/admin_rbac.php` (`admin_role_allows()`, `admin_can_access()`), and
`download-document.php` re-reads the role from the database so a demoted operator loses
access immediately rather than at the next console page load. **When adding a root-level
route that an admin may reach, call `admin_can_access($pdo, '<console script>')`** — do not
assume `admin_guard.php` ran.

### File downloads

`download-document.php` is the only route that serves a private document to a client, and
it checks the document belongs to `$_SESSION['user_id']`, resolves the path with
`realpath()` and requires it to sit under `UPLOAD_DIR` with a 40-hex filename, so a
traversal-shaped `stored_path` or a hand-placed file is refused. `uploads/` denies the
directory and any executable extension. `tests/execution29_file_security_probe.php`
attacks all of it, including the cross-tenant and role cases.

### Mobile API tokens

- Integration tokens (`integration_api_tokens`) carry `expires_at` (v54, default 180 days).
  Lookup treats an expired token exactly like an unknown one, so a token issued for a
  one-off export does not stay valid forever.
- `api/v1/logout.php` revokes exactly the token that authenticated the request. It is
  idempotent and refuses an integration token, which has no session.
- The legacy plaintext-to-hash upgrade in `api_authenticate()` was a write on a read path
  with no guard; two concurrent requests could both match the plaintext row and rewrite it,
  after which the token stopped working. The `UPDATE` repeats the plaintext condition so it
  can only touch a row that is still plaintext.

### Tenant isolation

`api/v1/calendar.php` returned HTTP 500 for every caller completing an event: the `UPDATE`
had no table alias while the tenant scope clause was written against `e`. A child table
that inherits its tenant (`case_evidence`) must be scoped through a join on the parent case
— see the Gotchas section. `tests/execution29_tenant_isolation_probe.php` seeds two firms
and attacks every entity type as both lawyer and client.

### Probe conventions

Execution 29 probes (`tests/execution29_*`) need the app on `127.0.0.1:8080`, seed their own
accounts and rows with a random suffix, and delete them in a `cleanup` section. If a probe
is interrupted before cleanup, its rows survive — `tests/execution29_runtime_ddl_test.php`
repairs its own schema baseline on start for exactly this reason.

Run the whole suite with `bash tests/run_all_tests.sh` (optionally with a name filter, e.g.
`bash tests/run_all_tests.sh execution29`). Do **not** hand-roll a `for` loop over
`tests/*.php`: the probes authenticate over real HTTP and the app rate-limits repeated logins
per account and IP, so a later suite inherits an earlier suite's throttle and reports login
failures that have nothing to do with the code. The runner clears `auth_rate_limits` before
each suite and starts the mock AI provider if it is down. A phantom failure is worse than no
check, because it teaches everyone to ignore real ones.

## Execution 29.1 - migration verification

Five defects were fixed here, all with the same shape: **a check that could not fail
reported success on a database that was broken.** When touching anything under
`database/repair/`, keep that frame: a check that prints a row, or that tests one
column on behalf of many, is not a check.

### `database/upgrade_v42.sql`: guard every object on itself

The original file guarded a multi-column `ALTER` on a single column:

```sql
IF legal_name absent THEN ALTER TABLE law_firms ADD legal_name, display_name, email, ...
```

MySQL stops a file at the first error. A run aborted after `legal_name` landed left
`display_name` and nine others missing **forever**, because the guard now found
`legal_name` present and skipped the whole statement. The same shape wrapped
`ADD COLUMN firm_id` + `ADD INDEX` + `ADD CONSTRAINT` on every firm-owned table.

Every column, index and constraint is now guarded on itself. Two consequences:

- A re-run **completes** what an aborted run left unfinished. This is the supported way
  to repair a partial migration - do not hand-write `ALTER` statements to patch a schema.
- `AFTER <column>` must never appear. Naming a sibling column that does not exist is
  `SQLSTATE 42S22`, and MySQL abandons the rest of the file. Column position has no
  functional effect; leave the clause out.

### `database/repair/migration_verify.php`: requirements come from the file

Requirements are extracted from each `upgrade_v*.sql` by a quote-aware scanner.
Quote-aware matters: `ENUM('active')` and `'...'` string bodies otherwise truncate naive
splitting, and a truncated ALTER is a silently-empty requirement set - the worst possible
failure mode for a verifier. Measured across all 54 files: 328 objects, 0 unparsed. A file
the scanner cannot understand is reported `unknown`; never assume it applied.

### `database/repair/02_migration_ledger.php`: record only proven work

The v53 ledger recorded a migration on the strength of a single hand-mapped "proof
column". `upgrade_v42.sql` declares 41 objects, so `audit_logs.firm_id` alone recorded it
as applied. Now a file is recorded only when every declared object is present, with states
`verified`/`partial`/`missing`/`unknown` and exit 1 when anything is incomplete. Two
properties to preserve:

- **A historical checksum is never overwritten.** `checksum` is what was applied;
  `current_checksum` is the file on disk now. Drift is reported beside the record, not on
  top of it.
- **It exits non-zero.** A bare `SELECT` that prints a row nobody reads is not a check.

### `database/repair/01_repair_reset_columns.sql`: 13, not 12

The final check declared `12 AS expected` against a real requirement of 13 (users 5 +
lawyers 5 + admins 3), over a predicate matching a column *name* on *any* of the three
tables. So a database missing exactly one column counted 12, equalled the expectation, and
**passed on the script that exists to catch a missing column**. It was also a bare
`SELECT`, so it could not fail a deployment.

The expectation is now derived from a requirement list (a session temporary table), and
each column is verified by table, name **and type**, with `SIGNAL SQLSTATE '45000'` on
failure. A count can only say "one is missing", never which one - and a database missing
`lawyers.reset_token` but not `users.reset_token` satisfies any name-based predicate.

### v42 backfill: never guess a tenant

The old backfill joined `law_firm_members` directly. Correct today only because of
`UNIQUE(lawyer_id)` in another table; relaxing that uniqueness would pick an arbitrary
firm and move one tenant's records into another. Assignment now joins a derived table
filtered to lawyers with exactly one distinct firm, every statement is
`WHERE firm_id IS NULL` (an existing owner is never overwritten), and ambiguous rows stay
`NULL` and are reported. Rows that cannot be resolved are surfaced for reconciliation,
never guessed.

### `00_preflight_check.php`: blockers vs warnings

Preflight and postflight (`--postflight`), exit 0/1/2. A missing `ALTER` grant or a missing
critical column is a **blocker** (stop the deployment); an unrecorded-but-verified
migration is a **warning** (bookkeeping is behind; the ledger command fixes it with no
schema change). It measures every migration against the **live schema**, not the ledger -
the ledger can be wrong, the schema cannot.

### Testing migrations: schema.sql is the consolidated CURRENT schema

This will silently invert a test if you miss it. `database/schema.sql` already contains
everything the migrations add, so loading it and measuring `upgrade_v42.sql` reports
`verified` without the migration having done anything. Use
`e291_build_without($admin, $db, $file)` from `tests/lib/migration_harness.php`, which
loads `schema.sql` and then removes every object the file declares.

Two related traps, both hit while writing these tests:

- Truncating a migration to "simulate an interruption" at too few statements executes
  nothing, producing a `missing` state that masquerades as a passing partial test.
  Truncate **mid-file**, where some objects have landed and others have not.
- `config.php` uses `if (!defined(...))`, so a `DB_NAME` define in a test `secrets.php`
  **wins over `MAWATHEQ_DB_*`** and the tool connects to the wrong database. The tests
  pass `--database=<name>` or set the environment and omit the DB defines.

### Release artifact smoke test

`tests/execution29_1_release_smoke_test.php` tests the **downloaded** artifact, not the
working tree: it verifies checksums, asserts the site archive carries no
secrets/tests/docs/.git, unpacks both archives, writes the documented `secrets.php`,
overlays the migration package and applies it in the documented order to a scratch
database. A test against the repository copy proves the repository is correct and says
nothing about the bytes an operator actually downloads.

## Execution 30 — consultation attachments

### The attachment file lives outside the web root, and the route is the only door

`includes/consultation_attachments.php` resolves the storage root to a sibling of the
application root (`../mawatheq-private/consultation-attachments`, sharded by consultation
id) and falls back to `<app>/storage/...` only when the parent is not writable. There is no
public URL for a stored file: `download-consultation-attachment.php` re-checks ownership on
every request instead of trusting a token in the URL. A signed link was the alternative and
was rejected because it cannot be revoked when a consultation is reassigned or a lawyer
leaves the firm, and it leaks access through history, proxies and referrers.

The root guard is `02770` (group write + setgid), not `0750`. Apache writes the files but
the maintenance sweeper and any cron job run as a different user, and both must be able to
rename and unlink inside these directories; the setgid bit keeps the group stable as files
are added instead of letting each writer's primary group take over and lock the other out.
`tests/setup_test_env.sh` adds the CLI user to the `www-data` group for the same reason — a
fresh clone otherwise uploads fine over HTTP and then fails every direct filesystem check.

### Validation is three checks, not one

`consultation_attachment_validate()` requires the extension to be in the catalog, the
finfo-detected MIME to be one of the MIMEs that extension legitimately produces, and the
body not to carry an executable signature. Each alone is defeated by an ordinary file: a
`.pdf` whose bytes are a PNG passes the extension check, and a text file named `.php` passes
a MIME sniff that trusts the extension. `consultation_attachment_has_executable_signature()`
scans for PHP/open-tag and script markers rather than rejecting leading NUL bytes — an
MP4/MOV/M4A is ISO-BMFF and its box size legitimately begins with `\x00`, so a NUL check
rejects real media. `tests/execution30_consultation_attachments_probe.php` covers both
directions (a PNG named `.pdf`, a PDF named `.txt`, and a PDF that is also valid PHP).

### Limits are clamped to what PHP will actually accept

`consultation_attachment_limits()` takes the configured values and clamps each per-file
limit to `upload_max_filesize` and to `post_max_size` minus a multipart-envelope allowance,
via `consultation_attachment_ini_bytes()` (which parses `2M`/`512K`/`1G`). Advertising the
configured 100 MB video limit on a server with a 2 MB `upload_max_filesize` promises
something the server discards before the script runs, which surfaces as an empty `$_FILES`
rather than a size error. The probe reads the effective limit instead of asserting a
constant, so it stays correct on any php.ini.

Related: an oversized POST arrives with `$_POST` and `$_FILES` both empty. `config.php` used
to answer that with a bare 403 "Invalid request", which reads as a CSRF failure and sends
the uploader looking in the wrong place; it now returns a request-too-large answer, and both
upload endpoints also check `CONTENT_LENGTH` to say the same thing in JSON.

### Two-phase upload: stage now, claim at booking

The booking form collects files before the consultation row exists, so an upload is written
`status='staged'` with `consultation_id` NULL and a `draft_token`, then claimed by
`booking.php` when the form is submitted. `consultation_id` is nullable for this reason. A
staged row whose token is never claimed is an abandoned draft, reclaimed by
`consultation_attachment_purge_drafts()`; a file with no row at all (a crash between
`move_uploaded_file()` and the INSERT) is reclaimed by `consultation_attachment_purge_orphans()`,
which reports rows whose file is missing rather than deleting them — the row is the user's
record that the file once existed. `booking.php` accepts only the token this session was
issued, so a crafted POST cannot adopt another client's staged uploads.

### Do not run the PHP suite and Playwright at the same time

`tests/password_reset_test.php` wraps its rate-limit section in a transaction and drives
`auth_rate_limit_failure_key()`, whose `INSERT ... ON DUPLICATE KEY UPDATE` scans
`auth_rate_limits`. A Playwright run logs in through the real app, which writes the same
table, so the two together make InnoDB raise `SQLSTATE[HY000] 1020 Record has changed since
last read in table 'auth_rate_limits'` and the suite exits 255. It reads like a regression in
the rate limiter and is not one: run alone, the suite is green. Serialize them.

### Test probes that mutate seeded rows must resolve them by identity, not by "newest"

`tests/phase_firm_site_http_probe.php` used to snapshot `SELECT * FROM law_firms ORDER BY id
DESC LIMIT 1` and assume that was owner-b's firm. That held until other suites seeded their
own firms; the probe then published owner-b's site on a *different* firm's row, so
`firm.php?firm=<slug>` kept returning 200 after the probe set `is_public=0` on the wrong row.
It now joins `law_firm_members` through `owner-b@example.com`, which is the identity the
probe actually drives. The same probe now clears `probe-page`/`draft-page` for that firm
before it starts: it asserts an HTTP 302 after saving a page, and a run killed before its
cleanup leaves the row behind, after which the unique key on `(firm_id, slug)` makes the next
INSERT fail and the save render 200 instead of redirecting. Both bugs were invisible until
the suite was run twice in a row.

## Execution 31 — affiliate, referral and partner marketing (v57)

The engine (attribution, commission, ledger, payout state machine, fraud signals) is in
`includes/affiliate*.php` and `database/upgrade_v57.sql`. This section is the operational
knowledge the UI work added.

### Money is integer minor units, everywhere

`affiliate_to_minor()` / `affiliate_from_minor()` convert at the edges; nothing in between
is a float. A new affiliate surface that does `$amount * 100` is the bug, not the fix.

### Programmatic callers of the payout service must pass a method

`affiliate_request_payout()` fell back to a null method until Execution 31: the guard read
`in_array($options['method'] ?? 'bank_transfer', [...]) ? $options['method'] : 'bank_transfer'`,
so the true branch dereferenced the key the `??` had just defaulted. The web form always
supplies `method`, which is why only direct service calls failed — with `create_failed`, not
a useful message. It now normalises first. Anything else that defaults a value inside the
condition it also reads needs the same look.

### Admin consoles call the service, never write state directly

`admin/affiliate-detail.php` and `admin/affiliate-payout.php` route every change through
`affiliate_set_status()`, `affiliate_set_compliance()` and `affiliate_payout_transition()`.
Those functions own the transition table, the second-approver rule (`second_approver_required`
when the same admin approves twice) and the evidence rule (`evidence_required` before `paid`).
An `UPDATE ... SET status=` in a page bypasses all three, which is how a payout gets paid with
no transfer reference.

The admin pages need `affiliate_payout.php` and `affiliate_conversion.php` included as well as
`affiliate_admin.php`: the helpers the list and detail pages call (`affiliate_payable_minor()`,
`affiliate_earnings_summary()`) live there, and a missing include is an HTTP 500 that lints
clean. Only an HTTP probe catches it.

### RBAC: the nav filters, the list must too

`includes/admin_rbac.php` holds the route→role map and a route absent from it defaults to
`super_admin`. The nav is role-filtered automatically, but a page that links to a
higher-privilege page is not — `admin/affiliates.php` hides the detail link from `support`,
who may read the list but would otherwise be offered a button that 403s.

### The affiliate probe is order-sensitive; seed settings with an upsert

`tests/exec31_admin_console_probe.php` turns the programme on and moves the payout thresholds.
`UPDATE site_settings ... WHERE setting_key=?` matches nothing on a database that has never
been configured, so the probe silently kept the defaults and reported a payout failure that
had nothing to do with the code — it passed standalone and failed in the full run. It now
upserts and restores the prior values in cleanup, so a later suite does not inherit its
configuration as if it were the deployment's.

### The www-data group is required for the full suite, not just attachments

`tests/setup_test_env.sh` adds the CLI user to `www-data`, but the group only applies to a new
login. In a session that predates the `usermod`, `execution30_consultation_attachments_probe`
fails six filesystem checks with `scandir(...): Permission denied` while the same probe passes
under `sg www-data -c '...'`. Run the suite as `sg www-data -c 'bash tests/run_all_tests.sh'`
or expect those six and confirm they are the only ones.


## Execution 32 — affiliate onboarding journey (v58)

`includes/affiliate_auth.php`, `affiliates.php`, `admin/affiliate-applications.php` and
`database/upgrade_v58.sql`. Two independent paths reach an affiliate account, and only one of
them was ever exercised before this execution:

- **Registered** — `/affiliate/register.php` creates the account *with* a password, status
  `pending`. The applicant can sign in immediately and watch the review.
- **Public form** — `/affiliates.php` (and `affiliate_submit_application()`) only *queues* an
  application. No account, no password. Approval then calls `affiliate_create_account()`,
  which sets no `password_hash`.

So an approved public-form applicant is an account nobody can sign into, and the invitation is
the **only** route to credentials. Three defects met on that route and each is pinned by a test:

### `??` used as a value, not a test

```php
// before: the value is read from a key that was only *tested* with ??, so it is null
$inviteType = in_array($data['invite_type'] ?? 'individual', $allowed, true)
    ? $data['invite_type'] : 'individual';
```
`$data['invite_type']` is undefined whenever the caller omits it (every caller did), so the
ternary's true branch yields `null`, and the `NOT NULL` column rejected the insert — the
function could not create an invitation at all. Normalise the value first, then use it.

### An invitation must not be a password reset

The original guard refused any email that already had an affiliate row. That is exactly the
account the public-form path must reach, so it had to become "refuse only an account that
already has credentials". Keep that condition when touching this function: a fresh token
delivered to an address that can already sign in would otherwise reset its password.

### Do not pre-record the formal agreement at invitation time

`affiliate_accept_invitation()` wrote the `affiliate_agreements` rows itself. `terms.php`
then found the agreement already accepted, rendered **no form**, and the activation that only
runs from that form never fired — the partner stayed `approved` forever and could never earn.
Consent at acceptance is still audited (`affiliate_audit(..., 'invitation_consent', ...)`),
but the formal agreement is accepted by the applicant on the terms page, exactly as for a
registrant. `affiliate_has_accepted()` must be false after accepting an invitation.

### `accepted_ip` is a 64-char hash in a VARCHAR(45) column

`affiliate_ip_hash()` returns a SHA-256 digest (64 chars). `affiliate_applications.accepted_ip`
and `affiliate_agreements.accepted_ip` were `VARCHAR(45)` — the width of an IPv6 literal. Under
`sql_mode=STRICT_TRANS_TABLES` every insert was refused with `SQLSTATE[22001] Data too long`,
which `affiliate_submit_application()` catches and reports as the generic `create_failed`, so
the public form silently did nothing. `affiliate_accept_terms()` failed the same way, and
because `terms.php` only activates when both calls return true, an approved affiliate could
never reach `active`. `upgrade_v58.sql` widens both columns; it is guarded and idempotent.

### Tests must clear the rate-limit buckets they consume

The public form and the login both count **successful** attempts against per-IP buckets, so a
second run inside the 15-minute window reports `rate_limited` and reads like a regression.
Worse, `auth_rate_limit_remaining()` takes a scope and checks two buckets, and
`auth_rate_limit_keys()` derives **two** keys per scope (`auth-ip|` and `auth-account|`), so a
single scope needs **four** keys cleared — clearing only the `auth-ip|` ones leaves the account
bucket blocked. `auth_rate_limit_keys('affiliate-apply', '127.0.0.1')` hashes the *address* as
if it were the email for the account key, which is why the account bucket fills even though no
applicant email repeats. Both `tests/exec32_affiliate_journey_test.php` and
`tests/e2e/affiliate-journey.spec.js` clear these in setup; keep that when adding cases.

Verified on this execution: 636/636 browser tests, `exec32_affiliate_journey_test` 64/64,
`exec31_affiliate_test` 105/105, `exec31_admin_console_probe` 100/100, 31 PHP suites (the one
failure is the pre-existing `execution30` `www-data` group issue described above).


## Release preparation — the final consolidated build

### PHP 8.4 is mandatory, not "8.3 or newer"

`composer.json` requires `php >=8.4` and the bundled `onelogin/php-saml` writes that into
`vendor/composer/platform_check.php` as `PHP_VERSION_ID >= 80400`, which **aborts** the
autoloader below 8.4. So a cPanel host on 8.3 does not degrade gracefully — the site dies
at first include, on every page. Check the version before starting a deploy. Nothing in the
application uses 8.4-only syntax (no property hooks, no asymmetric visibility), so the floor
comes from the dependency, not the code.

### `tools/build_release.sh` ships two names per archive, on purpose

The canonical names (`MAWATHEQ_WEBSITE_CPANEL_FINAL.zip`, `MAWATHEQ_DB_MIGRATIONS_SAFE.zip`)
are what `tests/execution29_1_release_smoke_test.php` unpacks, so they are not renamed. The
delivery names (`MAWATHEQ_WEBSITE_FINAL.zip`, `MAWATHEQ_DATABASE_MIGRATIONS.zip`) are
**hardlinks** to the same inodes: no extra bytes, and they cannot drift from the canonical
file. `SHA256SUMS.txt` lists all of them plus the deployment guide, so a download of either
name verifies against the same digest. If a `SHA256SUMS.txt` check reports the canonical
names as "No such file", that is a missing download, not a corrupt archive.

### The build is byte-reproducible; verify it that way

Everything is stamped with the commit time (`SOURCE_DATE_EPOCH` convention) and modes are
normalized to 644/755, so two builds of the same ref produce identical bytes. After any
change to the build inputs, confirm with a second build and `diff` of the two
`SHA256SUMS.txt` files. A rebuild that differs means an input is carrying "now" or a mode.

### Archive extensions are denied at the web layer

`.htaccess` denies `.zip|tar|tgz|gz|rar|7z|bz2|apk|ipa`. The deployment steps upload
`MAWATHEQ_WEBSITE_FINAL.zip` into `public_html` and extract it there; if the archive is left
behind it is publicly downloadable and exposes the whole site tree. Nothing legitimate is
served as a static archive — consultation attachments and affiliate CSV reports are streamed
from PHP routes with `Content-Disposition`, and no template links to a `.zip`. Verified live:
`.zip`/`.tar.gz`/`.apk` return 403 while ordinary files still return 200. The guide also
tells the operator to delete the archive after extraction; the deny rule is a second layer,
not a substitute.

### Verify migrations by applying them, twice

A fresh database plus `schema.sql` plus all 56 upgrades in **numeric** order yields 130
tables, 22 of them `affiliate_*`, plus `consultation_attachments`. Running the whole set a
second time is a no-op — that is the idempotency guarantee, and it is worth re-checking after
touching any upgrade file. Sort numerically: a lexical sort puts `upgrade_v10.sql` before
`upgrade_v2.sql`.

### `git fetch` does not bring the stacked branches

`remote.origin.fetch` is pinned to `+refs/heads/main:refs/remotes/origin/main`, so
`git fetch origin --prune` silently omits `phase-3`, `phase-4`, `recovery/*` and any feature
branch. Fetch them explicitly (`git fetch origin <branch>:refs/remotes/origin/<branch>`) or
a branch that plainly exists on GitHub looks absent. Pushes go through
`https://x-access-token:$GITHUB_TOKEN@github.com/...`; `GITHUB_PERSONAL_ACCESS_TOKEN` is not
push-capable.


## Merge topology — how the execution stack reached main

The four branches were a **strict linear stack**, not four independent lines:

```
main 3587b0a
 └─ phase-3-finance-ai-integrations   6508e68   (+3 commits)
     └─ phase-4-enterprise-ui         47d40ab   (+31)
         └─ recovery/execution-28-to-29 0ecd180 (+9)
             └─ feature/affiliate-referral-system cd4ddc2 (+11)
```

Because each branch was an ancestor of the next, no rebase or conflict resolution was
needed. PRs #1 and #3 targeted `main` directly; PRs #2 and #4 targeted their parent branch,
so their **base had to be retargeted to `main` after the parent landed** — otherwise merging
them would have put the commits on the parent branch, which was already merged, and the
changes would never have reached `main`. PR #2 was also a **draft** and GitHub refuses to
merge drafts; it had to be marked ready first.

Merge commits on `main`, in order: `2884d93` (#1), `dcccf59` (#2), `b64acf0` (#3),
`b5cc04c` (#4). Verify nothing was lost with
`git rev-list --count <branch> --not main` → must be 0 for every branch, and
`git merge-base --is-ancestor <branch-head> main` → must succeed for all four heads. All
source branches keep their original SHAs, so a mistake here is recoverable by pointing
`main` back at the pre-merge SHA; no force-push was used.

### `ai_enabled` is seeded to `1`, but AI is inert until a provider is set

`schema.sql` and `upgrade_v10.sql` seed `ai_enabled = '1'`, which looks like "AI is on".
It is not: `ai_settings_configured()` also requires a chat-capable `ai_provider` plus either
an API key (hosted) or a reachable endpoint (local), and `ai_provider` seeds to empty. With
no provider, `ai_diagnostics()` reports `blocking = 'no_provider'` and every feature page
treats AI as unconfigured. So "no AI/Ollama activation" holds on a fresh deploy without
touching that seed — do not "fix" it to `0`, that would change behaviour for an operator who
has legitimately configured a provider. The `127.0.0.1:11434` listener in the test
environment is the mock at `/tmp/mockai/router.php`, not Ollama.


## Execution 33 — Saudi Najiz legal classification (v59)

### The source document is tracked in the repo, and the importer reads it at runtime

`database/sources/Pasted text(3).txt` (the filename contains literal `%20`, `%28`, `%29`
characters — quote it in every shell command) is the authoritative Najiz classification
export: **125,912 bytes, SHA-256 `f6f5233153aac35684d9b6adae1fa98d7c5e8c07824d0dc5169f1ab13917b269`**.
`includes/najiz_import.php` parses it at run time and writes the tables. No classification
text is embedded in PHP anywhere — if you find yourself typing an Arabic lawsuit name into a
source file, stop: the data belongs in the document.

Re-verify the checksum before trusting a fresh checkout; a truncated fetch still parses but
loses types silently.

### The document's grammar is positional, and three traps cost real debugging time

Labels repeat a fixed number of times and the parser walks them in order. Three things about
the layout are not obvious from reading it:

* **A definition can contain a line that looks like the next label.** The parser must stop
  consuming a definition at the first *label* line, not at the first line that merely starts
  like one, or types get swallowed.
* **The document ends with a disclaimer paragraph.** It is prose, not a lawsuit type. It is
  captured into `footer_notes` and must never become a row.
* **Seven types are listed with no detail block at all.** They are real types; they carry a
  `parse_note` and zero judicial requests. Do not "fix" the zero by inventing requests.

The reconciliation that proves correctness: **234 parsed types = 234 declared** (the sum of
the 38 subcategory counts the document itself prints), 38 subcategories, 640 judicial
requests, 227 definitions, 7 types without detail.

### Five lawsuit-type names repeat inside one parent — they are distinct records

`ولي` x3, `النفقة` x2, `الحضانة` x2, `دعوى الإعسار أو الملاءمة` x2, `منازعات شكلية` x2.
The idempotency key is therefore **occurrence-aware** (`source_key` = parent path + ordinal +
name), not the name alone. Keying on the name collapses them and loses three real types.
`tests/exec33_najiz_classification_test.php` fails if that regresses.

### Import is idempotent and ids are stable; re-running is always safe

The second import inserts nothing, updates in place, and every `najiz_case_types.id` is
unchanged. Case and consultation rows point at those ids, so a re-import cannot repoint
existing casework. `najiz_import_runs` records each execution with the source checksum.

### Classification is optional everywhere, and the FK is RESTRICT on purpose

`cases.najiz_case_type_id` and `consultations.najiz_case_type_id` are nullable, and every
write path validates the posted id against `is_active = 1` before storing it. A stale or
tampered id degrades to NULL instead of failing the insert. The FK is `ON DELETE RESTRICT`:
**there is deliberately no delete anywhere in the admin console** — entries are retired with
`is_active = 0`, because deleting a classification a case already references would either
break the case or orphan its meaning. Do not add a delete button.

### Adding a migration column: never use a positional `AFTER`

Execution 29 was burned by this. `ALTER TABLE ... ADD COLUMN x AFTER some_column` aborts the
whole migration file if `some_column` is absent in that database, and MySQL stops at the first
error — so statements *below* it never run and the migration looks applied while the repair it
existed to perform silently did not happen. All v59 columns are appended positionally. If you
must place a column, guard on `information_schema.COLUMNS` first.

### The public guide and API are unauthenticated; the console is not

`/classification-guide.php` and `/api/najiz_classify.php` are public and read-only by design —
the hierarchy is reference data. The API rejects unknown actions with 400 and never reflects
input. `/admin/classification.php` sits behind the console guard and is registered in
`includes/admin_rbac.php` as `super_admin` + `editor`. Adding an admin page means adding it to
that map too — a route absent from the map is super_admin-only, which silently locks out
editors.

### Permission gotcha that bit this execution

Files written by the editor tool land as `0660`, which Apache cannot read, and every new page
returns **HTTP 500** with `Failed to open stream: Permission denied` in the Apache log — not a
PHP error. `chmod 644` every new file (and `755` for directories) before testing over HTTP.

## Execution 34 — affiliate registration, restricted staff, landing page

### `admin/index.php` branches before the confidential dashboard renders

A restricted `staff` account must never receive the main dashboard's figures. Hiding panels
with CSS is not enough — the numbers are in the response body and readable from page source.
`admin/index.php` therefore `require`s `dashboard-staff.php` and `exit`s *before* the main
dashboard queries run. Keep that branch first if you touch the file.

### Two session bugs worth remembering

* **A login must refresh `mawatheq_session_started_at`.** `admin/login.php` originally did not,
  so after a password reset the guard compared the new session against the account's
  `session_invalidated_at` stamp, judged it stale, and the user looped between login and the
  guard with no way out.
* **`account_session_is_stale()` must use strict `>`, not `>=`.** The stamp means "sessions
  beginning *before* this instant are invalid", so a session created in the same second is a
  new session. With `>=`, revoke-then-sign-in within one second loops forever.

### Test-suite isolation: restore what you found, don't delete it

`exec31_affiliate_test.php` used to `DELETE FROM site_settings WHERE
setting_key='affiliate_program_enabled'` in cleanup. The row is part of the shipped seed
(`upgrade_v57.sql`), and the application treats a *missing* row as "off" via its in-code
defaults — so the deletion was invisible to the app but broke the next suite that asserted the
seeded state. Tests now capture the prior state and restore it (deleting only if the row was
absent to begin with). **Rule: a test restores the state it found; it does not assume it owns
the row.**

### `tests/exec33_najiz_classification_test.php` is self-seeding

`setup_test_env.sh` builds the schema but nothing seeds the Najiz classification, so on a fresh
clone exec33 saw 0 rows and failed on data, not code. It now imports when `najiz_categories` is
empty and leaves a populated database alone. It also reads `category_id` from the API response
instead of hardcoding `2`, since seed ids are not part of the contract.

### `lawyer_specialties` ships empty, so a suite that needs a match must make one

`database/upgrade_v63.sql` creates `lawyer_specialties` **empty** on purpose: linking a lawyer
to an expertise is an operator action, and inventing one would route a real customer to a lawyer
who cannot help. "No match" is a first-class outcome, so `legal_journey_lawyers_for_specialty()`
returns `[]` on a clean database — correct behaviour, not a bug.

`tests/exec37_consultation_journey_test.php` asserted `matched:true` unconditionally and so
could never pass on a fresh checkout (12 failures that read like a regression in the journey
code). The e2e spec added in the same commit already handles this correctly — it
`test.skip`s when nothing matched. The PHP suite now inserts the one `lawyer_specialties` row it
needs and deletes it in cleanup, and **re-reads** `/journey.php?action=specialties` afterwards,
because the list it read before the insert still reports `lawyer_count: 0` and the
unstaffed-specialty check would otherwise pick the specialty the fixture just staffed.
**Rule: a suite asserts the code, not the seed. If it needs a row the migration deliberately
omits, it creates and removes that row itself.**

### Running the suites: the `www-data` group matters

Apache writes consultation attachments as `www-data` under a group-only private root
(`../mawatheq-private`). A login shell's supplementary groups are fixed at login, so a user
added to `www-data` by `setup_test_env.sh` afterwards still cannot read those files and
`execution30_consultation_attachments_probe` fails on a file it is entitled to read.
`run_all_tests.sh` re-enters the group per suite with `sg www-data -c` and detects membership
from `getent group www-data`, **not** `id -nG` (which reflects the stale login-time groups).

### Current state

* Full suite: **39/39 green** (`sg www-data -c 'bash tests/run_all_tests.sh'`).
* `exec34_staff_permissions_test.php`: 118 checks. `exec34_affiliate_registration_test.php`:
  43 checks (Parts A + C, including the closed-programme path).
  `exec34_affiliate_e2e_workflow_test.php`: 43 checks (true HTTP E2E, cookie jar + CSRF).
* `database/upgrade_v60.sql` is additive and idempotent — verified by applying it twice to a
  scratch database. 24 modules seeded, 13 marked `grantable = 0`.

### Visual review: measure contrast from pixels, and beware these four traps

`tools/visual_review_exec34.js` samples rendered pixels rather than reasoning about computed
styles, because the DOM cannot answer the contrast question here: dark bands use
`background: linear-gradient(...)`, which a DOM walk cannot resolve, so it falls through to
the white body and reports white-on-white. Every one of these mistakes produced a confident
wrong number at some point, so they are worth knowing before writing a similar tool:

* **`page.screenshot({ clip: boundingBox })` mixes coordinate systems.** A bounding box is
  viewport-relative, a clip is document-relative. Below the fold the sample lands on the
  wrong region.
* **`page.screenshot({ fullPage: true })` does not faithfully paint off-screen content.**
  Rows below the fold came back as a single flat colour (1 distinct colour at y=3000 and
  y=7441, against 968 for the same region scrolled into a normal viewport). Layout does not
  shift, so it is the capture, not the page.
* **`html { scroll-behavior: smooth }` (set in `style.css`) makes `scrollTo` animate**, so the
  capture lands mid-flight. Inject `scroll-behavior:auto !important` before scrolling.
* **Do not derive the rectangle from `docY - scrollY`.** The offset drifts (~45px at zoom
  150%), enough to land the sample on blank space. Re-read `getBoundingClientRect()` from the
  element *after* scrolling, in the same frame as the capture.

Two guards keep the sampler honest. A region with fewer than 8 distinct colours carries no
glyphs and is skipped rather than scored, and use true ink/paper extremes (min/max) rather
than percentiles — a padded button or card heading can hold glyphs in well under 5% of its
area, so the 5th/95th percentiles both land on the background and score a flat 1.00:1.

### Two ways `getComputedStyle` says "visible" for something invisible

Both bit this tool; check for them before trusting a visibility guard.

* A collapsed `<details>` hides its contents with `content-visibility: hidden`, not
  `display: none`. Those elements keep a real box and paint nothing, so sampling one reported
  a confident 1.12:1 for text a visitor never sees. Check `contentVisibility`, and treat
  anything in a closed `<details>` outside its own `<summary>` as hidden.
* A colour declared on the element itself beats one it would otherwise inherit. The base
  sheet's `h1,h2,h3,h4 { color: var(--navy-dark) }` is `#0F1F33` — the same colour as
  `--aff-ink`, the dark end of `.aff-hero`'s own gradient. The affiliate hero title therefore
  rendered navy on navy and was **completely invisible** (0 near-white pixels across the
  heading band) while `.aff-hero`'s `color: #fff` looked correct in the stylesheet. `.aff-hero
  h1` and `.aff-hero p` now state their colour; `.aff-hero .btn-outline` does too, since a
  transparent button keeps the base sheet's navy label. Any new element on a dark band needs
  its colour stated rather than inherited.

Formerly-recorded finding, now **fixed**: `status-pending` (`enterprise.css`, via `--warning` on
`--warning-bg` `#FDF3E1`) measured **3.73:1**, below the 4.5:1 needed at its 12px size. The
token is shared by 33 call sites across admin, lawyer and public pages, so it was left for a
deliberate change — that change is `--warning: #7A5409` (**6.16:1**), which is also the colour
`.form-alert.warning` already used, so the two warning surfaces now agree. If you retune this
token, re-measure; the badge text is 12px and needs 4.5:1, not 3:1.

## Saudi Arabia is the platform default (v61)

The platform was built Bahrain-first, and the old defaults were literals scattered across the
affiliate module rather than one constant. If you are adding anything country-, currency-,
dialling- or timezone-shaped, use these — do not reintroduce a literal.

| Default | Where | Value |
|---|---|---|
| Country | `COUNTRY_DEFAULT`, `includes/country.php` | `'SA'` |
| Currency | `CURRENCY_DEFAULT`, `includes/currency.php` | `'SAR'` |
| Timezone | `SITE_TIMEZONE`, `config/config.php` | `'Asia/Riyadh'` (env `MAWATHEQ_TIMEZONE`) |
| Language / direction | `current_lang()` / `is_rtl()` | `'ar'` / RTL |
| Jurisdiction | `jurisdiction_options()[0]` | `'السعودية'` |

* `includes/country.php` is the single source for the country list and
  `render_country_select()`. The list used to be copy-pasted verbatim into
  `affiliate/register.php`, `affiliate/profile.php` and `affiliates.php` — which is exactly how
  `'BH'` stayed the default in all three. Add a country in one place now.
* Normalise request input with `country_normalize()` / `currency_normalize()`, never
  `substr($x, 0, 2)`. Both fall back to the default, so a missing or malformed field degrades
  instead of storing garbage. `country_normalize()` takes `mixed` on purpose: a country field
  submitted as an array must fall back, not raise a `TypeError`.
* `config/config.php` sets the timezone because nothing did before — PHP followed the host, so
  the same timestamp could differ by three hours between machines. Do not remove it.
* `affiliate_settings_defaults()` is split out of `affiliate_settings()` so the *code* default
  is assertable without reading `site_settings`. Tests run alphabetically and a previous suite
  writes to `site_settings`, so a test that reads the table is testing the other suite's
  leftovers, not the default. Assert against the defaults function.
* `BHD`, `BH`, `+973` and `'البحرين'` are still *legitimate list entries* — the currency
  selector, the dialling-code list and `jurisdiction_options()` all still offer Bahrain. They
  must not be *defaults*. `tests/saudi_defaults_test.php` pins both halves of that.
* `database/upgrade_v61.sql` moves ten column defaults `BH`/`BHD` → `SA`/`SAR`. It is
  **defaults only** — no stored row is rewritten, and the seeded `affiliate_payout_currency` is
  moved only while it still equals what `upgrade_v57.sql` seeded, so an operator override
  survives. Guarded by `information_schema` and idempotent.
* Gotcha: `information_schema.COLUMN_DEFAULT` for a `CHAR` column is stored **with quotes**
  (`'BH'`, not `BH`). A guard comparing against an unquoted value matches nothing and the
  migration silently does nothing while still looking applied.

### Adding a new file: check its mode

A file created through an editor landed as `0600` (`includes/country.php`), which Apache cannot
read — every page including `affiliate.php` returned **500**, and four suites caught it. New
files must be `0644` (`chmod 644 <file>`); the default umask is not applied by the editor. Same
trap applies to new `.sql` files under `database/`.

### Running the suites

`bash tests/run_all_tests.sh` (background it; it takes ~5 minutes and the runtime reclaims idle
foreground jobs). Suites are auto-discovered as `tests/*_test.php` and `tests/*_probe.php`.
Playwright: `npx playwright test` (~6.3 minutes, 684 tests, six viewports).
Flutter: `export PATH="/workspace/flutter_sdk/bin:$PATH"` then `flutter analyze && flutter test`
(93 tests) — see "Flutter is at `/workspace/flutter_sdk`" below.

### Multimodal attachments (Execution 35)

* `consultations.firm_id` is the column that authorizes a firm-scoped lawyer
  (`tenant_owner_where_for_lawyer` → `firm_id = ?`). `upgrade_v42.sql` backfilled it **once**.
  Nothing set it afterwards, so every consultation booked since was `NULL` and invisible to the
  whole firm — including the lawyer who owned it. **Any new INSERT into `consultations` must
  resolve the firm**: `tenant_context_for_lawyer($pdo, $lawyerId)['firm_id']`. Two paths insert:
  `booking.php` (web) and `api/v1/consultations.php` (REST). A solo lawyer resolves to `NULL`,
  which is the correct "no firm" marker. `tests/exec35_multimodal_consultation_test.php` fails
  loudly if either path regresses.
* `booking.php` did **not** include `includes/tenant.php`; adding a `tenant_context_for_lawyer()`
  call there without the `require_once` is a fatal error, not a warning.
* `consultation_attachment_claim()` takes the firm id as its 4th argument. Passing `null` leaves
  the claimed row's `firm_id` empty and the firm cannot see its own attachments.
* `file_category` is an `ENUM`. Adding a category means adding the value to the ENUM **and** the
  PHP catalog (`consultation_attachment_catalog()`). Appending to the END of the ENUM list keeps
  existing values at their current index; re-ordering silently reinterprets stored data.
* **Test-helper shape trap:** a cURL helper that returns the raw body string cannot be indexed as
  `$resp['key']` — PHP reads that as a string offset and yields nonsense instead of erroring, so
  every assertion built on it fails for a reason unrelated to the code under test. Decode JSON in
  the helper (`json_decode`; return the string when it is not JSON, for binary downloads).
* Integration tokens are `mwq_`-prefixed (`INTEGRATION_TOKEN_PREFIX`). A hand-built token without
  the prefix is never recognized, so a "token is rejected" assertion passes for the wrong reason.
  `api_integration_endpoint_scopes()` in `api/v1/bootstrap.php` is the allow-list; anything not
  listed is closed to integration tokens by default.

### `.gitignore` patterns must be anchored

An unanchored `storage/` also matches `apps/mobile/lib/core/storage/`. That silently kept
`token_storage.dart` (imported by five files) out of git and left the Flutter app unbuildable from
a fresh clone — with no error anywhere, because the file existed on the developer's disk. Anchor
directory patterns to the root (`/storage/`) unless the intent is truly "any directory by this
name". `git check-ignore -v <path>` tells you which rule is responsible.

### Browser capture (Execution 35 follow-up)

* **A currency column and the programme default are not interchangeable.** PR #7 moved the
  platform default to SAR. The payout path then resolved the *programme* currency
  (`affiliate_payout_currency()`, SAR) instead of the affiliate's own `payout_currency` (BHD for
  anyone registered earlier), summed a currency the commissions were never recorded in, and got
  `0`. Payout requests were refused with `nothing_payable` while the dashboard showed a real
  balance — the money existed and was unreachable. Use
  `affiliate_payout_currency_for($affiliate)` for anything that sums a specific affiliate's
  money; the programme-wide function is only right for display of the programme default. This is
  the failure mode of a "default changed" refactor: it looks like a config change and behaves
  like a data bug.
* **`MediaRecorder` writes a voice note to `.webm`, which the catalog maps to video.** The file
  uploaded fine and was then permanently untranscribable, because
  `consultation_attachment_is_audio()` reads the stored category. The container has to be
  inspected: `consultation_attachment_webm_is_audio_only()` requires a positively identified
  audio track (`TrackType` 0x02) *and* no video track (`0x83\x81\x01`). Requiring the positive
  audio track matters — an early version downgraded on "no video track" alone, which
  reclassified `execution30`'s synthetic WebM fixture (a header with no tracks at all) and broke
  that suite. Never guess a category from an absence.
* `consultation_attachment_validate()` refuses any path that did not arrive as an HTTP upload
  (`is_uploaded_file`). A unit-style call with a `tmpUpload()` path returns "not a valid upload",
  not a classification result — test classification through the real endpoint instead.
* The transcribe endpoint needs a `consultation_id`. A *draft* attachment has none, so the
  request 404s before the audio check and the assertion passes for the wrong reason. Upload onto
  the consultation when testing the audio gate.
* Capture degrades in three steps, deliberately: `MediaRecorder` for in-page recording, the
  `capture` input attribute on devices that support it, and the plain file picker otherwise. The
  buttons are hidden until at least one route exists. Microphone and camera tracks are released
  on stop, cancel and `pagehide` — a track left open keeps the recording indicator on.
* `navigator.mediaDevices` requires a secure context. On the local test app (`http://127.0.0.1`)
  it works because loopback is treated as secure; on a plain-HTTP production host it will not.

### The deployment guide goes stale silently

`MAWATHEQ_DEPLOYMENT_GUIDE_AR.md` is a *deliverable*, not documentation — the operator follows
it literally. It described the 29.1/30/31 bundle while the site files already needed
`upgrade_v62.sql` (a photo upload reads `'image'` from `file_category`, which the column rejects
until v62 runs). Nothing failed; the guide simply did not mention the step. **When an execution
adds a migration, update the guide's step list, checklist and troubleshooting table in the same
change** — a missing step is invisible until a deploy fails.

### Verifying a published download

Checksum the build, then *download over the public URL and checksum again* — a local
`sha256sum -c` proves the files on disk, not the ones served. Also confirm the served archive
still contains the fix (`unzip -p site.zip <path> | grep <symbol>`): a stale download directory
serves old bytes with a 200, which a status-code check cannot see.

## Execution 37 — customer consultation journey, internal classification hidden

### The internal classification has three exits, and each one needed closing

`najiz_case_type_id` is the lawyer's taxonomy. The customer must never see it. There were three
separate routes out and they had to be closed independently — closing one proves nothing about
the others:

* `includes/legal_journey.php` builds the customer vocabulary. It maps a customer-safe specialty
  and issue-type onto the internal ids; the ids stay server-side.
* `api/v1/journey.php` is the shared endpoint (website **and** Flutter app). Its `specialties`
  action returns `id, name, description, icon_key, lawyer_count` and nothing else.
* `api/v1/consultations.php` — the client branch used `SELECT c.*`, which shipped
  `najiz_case_type_id` to the customer in their own consultation list. It now names its columns.

The regression test asserts on all three, because a future `c.*` is exactly the kind of edit that
looks harmless. The lawyer's own views keep the full classification — the rule protects the
customer, it does not hide the taxonomy from the professional.

### The empty match table is the correct state, not a bug

`upgrade_v63.sql` creates `lawyer_specialties` **empty** and assigns no lawyer a specialty.
Inventing an expertise the lawyer never claimed is a fabrication, and it would route a real
customer to a lawyer who cannot help. So the journey must handle "no match" as a first-class
outcome: the specialty still appears (with its real lawyer count, including zero) and the lawyer
step shows a clear alternative sentence rather than an unrelated match or a dead end. When
testing locally, an empty match list is expected until a lawyer fills `/lawyer/specialties.php`.

### `_FakeTransport` returns a JSON string, and a stray quote fails as "server error"

A malformed JSON literal in a Dart test does not fail where it is written. `ApiClient._decode`
catches the `FormatException` and rethrows `ApiException(server, 'رد غير متوقع من الخادم.')`, so
the failure surfaces as a 200-with-a-complaint and points at the client, not at the fixture. The
cause here was a doubled quote (`}],"` + `"not_sure"` → `}],"",{`). When a Dart fixture test
reports "unexpected response" against a 200, validate the literal before reading the client code:
`dart run` a two-line file that `jsonDecode`s it.

### The Playwright client account did not exist, so the suite passed vacuously

`tests/e2e/helpers.js` logged in as `client-firm17@example.com`. `seed_phase6.php` creates
`client-a`/`client-b`; `seed_phase6_business.php` creates `client-firm<N>` only for the firms it
sees. With two firms seeded, `firm17` never existed. `login()` then silently stayed on the login
page, and every "the dashboard renders for the client" assertion passed against the login form —
eight tests green for a client who could not sign in. The helper now asserts the URL left the
login page, so a rejected login fails loudly, and the default account is the plain seeded one.
**A helper that swallows a failed login turns a whole spec file into a no-op.**

### The customer journey submits on a button, not on picking an option

Step 2's issue-type radio is `position:absolute;opacity:0` and only fills a hidden input; the
navigation happens when the customer presses «عرض المحامين المتاحين». A test that clicks the
label and waits for the URL stays on `consultation-journey.php`. Drive the form the way a person
does — click the option, then click `#issueForm button[type="submit"]`.

### `najiz_categories` is filled by the importer, not by a migration

`includes/najiz_import.php` inserts the classification at runtime; `database/schema.sql` does not
contain it and no upgrade seeds it. So a migration that derives data from category names (v63
writes `customer_desc_ar` from `name_ar`) fills nothing on a fresh install, because at migration
time the table is empty. It is not a bug in the migration — it is a real ordering dependency, and
the fix is that v63 is idempotent and fills only rows that are still `NULL`, so **re-running it
after the import** completes the data. The release smoke test asserted "descriptions were filled"
against a scratch database and failed for exactly this reason; the useful assertion is the
mechanism (insert a category, re-run v63, expect the description), not the count on an empty
table. When a migration derives from data, test the derivation, not the row count.

### Flutter is at `/workspace/flutter_sdk`

Not on `PATH` by default. `export PATH="/workspace/flutter_sdk/bin:$PATH"` then
`flutter pub get && flutter analyze && flutter test`. `flutter pub get` rewrites
`apps/mobile/pubspec.lock` (7 packages resolve to newer versions); revert it with
`git checkout -- apps/mobile/pubspec.lock` unless the dependency change is intended — the lock
churn is noise, not part of the change. Mobile tests use the same fake-transport pattern as the
PHP contract tests: a fake `BaseClient` returns the endpoint's real JSON, and the production
client, repository and widgets handle it.

### Android release builds need three environment variables, and a full pub cache

A fresh sandbox resets PHP/MariaDB/Apache, Java, the Android SDK *and* parts of
`~/.pub-cache`. Each failure looks like a different bug, so check all of them before
debugging the app:

1. `flutter build apk --release` reports `No Android SDK found` — the SDK is at
   `/workspace/android-sdk`, not on `PATH`. `export ANDROID_HOME=/workspace/android-sdk`
   and `ANDROID_SDK_ROOT` to match. It ships platforms 34/35/36, build-tools 35.0.0 and
   36.0.0, and NDK 27/28, so no download is needed.
2. `Gradle task assembleRelease failed` with `JAVA_HOME is not set` — no JDK is
   installed. AGP 8.11.1 + Gradle 8.14.3 are fine on JDK 21:
   `sudo apt-get install -y openjdk-21-jdk-headless`, then
   `export JAVA_HOME=/usr/lib/jvm/java-21-openjdk-amd64`. (The image has no
   `openjdk-17-*` package; 21 is what `apt` offers.)
3. Gradle fails reading `~/.pub-cache/hosted/pub.dev/provider-6.1.5+1/lib/provider.dart`
   — the cache survived the reset with only the test-only packages. `flutter pub get`
   restores the runtime set; `pubspec.lock` must come back unchanged, so verify with
   `git status --short apps/mobile/pubspec.lock`.

The build takes ~2 minutes and must run in the background: the terminal caps a foreground
command at 1080s, and Gradle's process renames from `flutter` to `GradleWrapperMain`/
`gradlew`, so poll on `pgrep -f GradleWrapperMain`, not on the `flutter` command name.

### The Android artifacts are debug-signed unless `key.properties` exists

`apps/mobile/android/app/build.gradle.kts` falls back to the debug keystore when
`hasReleaseKeystore` is false, which is the state of a fresh checkout: there is no
`key.properties` and no `.jks` in the repo. `apksigner verify --print-certs` then reports
`CN=Android Debug`. That signature is fine for local testing but a Play upload needs the
owner's upload key, so say so in the release notes rather than calling the artifact
"release-signed". Verify with
`apksigner verify --print-certs --verbose build/app/outputs/flutter-apk/app-release.apk`
(binary at `/workspace/android-sdk/build-tools/35.0.0/`) and
`jarsigner -verify` for the `.aab`.

## Execution 38 — consumer-grade customer app and operator-managed home banners (v64)

### The customer app opens on the product, not on a slideshow

The unauthenticated route was a value-proposition slideshow (`OnboardingScreen`) that had to be
dismissed before anything was visible, and the page behind it was a marketing page whose nav
named destinations a guest cannot reach. Both are gone; `AuthStatus.guest` and the unauthenticated
fallback now both resolve to `PublicGuestShell`, so the first screen is the home experience itself.
`app.dart` no longer imports `onboarding_screen.dart` or `public_home_screen.dart` — if a future
edit reintroduces a route to either, the file will not exist.

### A bottom-nav index is not a page index

`PublicGuestShell` fed the bottom-nav `selectedIndex` straight into `IndexedStack.index`. The nav
lists "Consult" as a destination with no corresponding page, so the two lists are offset from
"Consult" onward and `Account` indexed past the end of the children list — an assertion in debug,
a blank body in release. `_navIndex` (what the bar highlights) and `_pageIndex` (what the stack
shows) are now separate, and the mapping between them is explicit. Any shell that adds a nav entry
which is an action rather than a page needs the same split; add the test that taps *every*
destination, not just the first.

### `Future.delayed` in a carousel outlives `dispose`

`BannerCarousel`'s auto-advance re-armed itself with `Future.delayed` inside `initState`. The
callback kept firing after the widget was gone, calling `setState` on a disposed state — the
classic "test passes alone, flakes in a suite" failure, because a short-lived widget test finishes
before the delay elapses. It is a cancellable `Timer?` cancelled in `dispose`, behind an
`autoAdvance` flag, and it honours `MediaQuery.disableAnimations`. Widget tests for it disable
animations rather than waiting, so they do not depend on timing.

### `home_banners` ships empty, and that is the correct state

Same rule as `lawyer_specialties` in v63: the brief forbids inventing a promotion, so
`upgrade_v64.sql` creates the table and inserts nothing. An empty slider is what a fresh install
shows until an operator adds a banner in `/admin/banners.php`. Do not "fix" the empty state by
seeding a slide — `api/v1/banners.php` returning `{"ok":true,"banners":[]}` is correct.

A banner's destination is a `link_type` (`none`/`category`/`specialty`/`url`) plus a
`link_target`, not a free-text URL, because the app must not follow an arbitrary address. `url`
must be absolute **https**; `category` and `specialty` must resolve to a real `najiz_categories`
row. `banner_public_row()` degrades any destination that fails validation to `none`, so a banner
whose specialty was deleted still renders — it just stops being tappable. Test that by pointing a
banner at a nonexistent category id and asserting the API returns `link_type: "none"`; pointing it
at `javascript:alert(1)` and asserting the same is the security half of the same check.

`specialty` and `category` both resolve against `najiz_categories` — the customer-facing specialty
cards and the journey's categories are one taxonomy. They stay distinct `link_type`s only because
the app routes them to different screens.

### `uploads/banners/` is public by design — and was not gitignored

Unlike `uploads/documents/` and the other upload dirs, banner images are served to customers, so
`banner_storage_dir()` deliberately writes **no** `.htaccess` deny guard (see the comment in
`includes/banners.php`). Uploads are still validated by real MIME (`finfo`) *and* `getimagesize`,
never by extension, because these files are web-served. `uploads/banners/*` is now in `.gitignore`
like every sibling dir; without it an operator's uploaded image was committable.

### The release build reads `git archive`, so untracked migrations do not ship

`tools/build_release.sh` stages the site from `git archive "$COMMIT"`. A migration that exists in
the working tree but is not committed is therefore absent from the package, and the smoke test's
`BUILD FAILED: database/upgrade_v64.sql is missing from the migration package` is the correct
signal — commit before building. The guard list in the script asserts the *current* head migration
by name; when you add `upgrade_v65.sql`, move the assertion, do not add a second one, or the next
build fails on a stale expectation.

### Verifying the banner stack locally

Apache serves the repo at `:8080` (docroot `/workspace/project/mawatheq`, DB from
`config/secrets.php`). `curl -s localhost:8080/api/v1/banners.php` is the fastest end-to-end check.
Seeding requires the admin DB user (`sudo mysql mawatheq_test`); note `najiz_categories` has
`name_ar`/`name_en`, not `name`, and `lawyer_specialties` is a `(lawyer_id, category_id)` join
table with no name column.

## Execution 39 — the Android app's app-wide "INTERNAL SERVER ERROR" is a deployment gap

### The error text is not in the APK, so it came from the server

`grep` for `internal server error` (and `INTERNAL SERVER ERROR`) in the built APK's
`classes.dex` and `lib/*/libapp.so` returns **nothing**, in both UTF-8 and UTF-16LE. The Arabic
strings are all present in UTF-16LE in the AOT snapshot (`مواثيق` x5, `تخصصاتنا القانونية`,
`خدمات سريعة`, `تعذّر الاتصال بالخادم`), which is the positive control that the search works.
A Dart AOT snapshot stores strings as UTF-16LE, so a UTF-8-only grep finds nothing even when the
string *is* compiled in. Conclusion: the text the owner saw was a **server response body**
(`api/v1/bootstrap.php` emits exactly `{"ok":false,"error":"internal server error"}`), not a
client string. Never conclude "the app hardcodes this" from a `grep` that did not first prove it
can find a string you know is in the binary.

### Production is running the pre-Execution-37 build, and the app is post-Execution-38

The released APK calls `GET /api/v1/banners.php` and `GET /api/v1/journey.php?action=specialties`.
Both are **404** on `mawatheq.com` (LiteSpeed HTML 404, not JSON) while `api/v1/README.md` is 403,
so the `api/v1/` directory exists but the two files were never deployed. Everything else the app
calls works: `plans.php` 200, `lawyers.php` 200, `available-slots.php` 200, the auth-gated set
(`me/cases/documents/consultations/invoices/notifications/calendar/ai-status/...`) 401, and
`auth/*` 400/410. `consultation-journey.php`, `lawyer/specialties.php` and `affiliate/index.php`
are also 404. So the failure is not one broken endpoint — the deployed website is simply older
than the client.

### A real 500 on production: `api/najiz_classify.php`, and the page already says why

`GET /api/najiz_classify.php?action=categories` returns **HTTP 500**
`{"ok":false,"error":"server_error"}` (the endpoint's own `catch (Throwable)`). The cause is in
production's own HTML: `classification-guide.php` renders "لم يتم تفعيل دليل التصنيفات بعد على هذا
النظام. يرجى تطبيق ترقية قاعدة البيانات upgrade_v59.sql." The `najiz_*` tables do not exist
because **`upgrade_v59.sql` was never applied**. The guide page guards on `$hasTables` and degrades
politely; `api/najiz_classify.php` has no such guard and dies on the first query. That is why the
same missing table produces a readable page and an opaque 500. A public read-only endpoint should
answer `{"ok":true,"categories":[]}` when its reference tables are absent, not 500.

### The home screen blanks itself on any load failure

`PublicHomeTab._load()` records `_error` for a failed banners *or* specialties call, and `build()`
replaces the entire screen with `ErrorState` when `_error != null && _banners.isEmpty &&
_specialties.isEmpty`. Reproduced faithfully by replaying production's exact 404 HTML and 200 JSON
through the real `ApiClient`/`ApiRepository` and rendering the real `PublicHomeTab`: the screen
shows **2 texts total** — `العنصر المطلوب غير موجود.` and `إعادة المحاولة` — with no greeting, no
search, no categories and no quick services. A single missing optional file (the banner slider)
therefore erases the whole product.

### `messageFromBody` passes the backend's raw string through

`ApiException.message` is documented as carrying "the server's own Arabic message", and
`messageFromBody` returns `decoded['error']` verbatim whenever it is a non-empty string. It only
falls back to Arabic when the body is *not* JSON. So any English server error — `internal server
error`, `unauthorized: invalid or expired token` — is shown to an Arabic-speaking customer exactly
as the server wrote it. The status-based fallbacks below it are unreachable for JSON bodies.

### Categories come from the importer, so a deployment that skipped v59 has none

`najiz_categories` is filled by `includes/najiz_import.php` from
`database/sources/Pasted%20text%283%29.txt`; no migration seeds it (see the Execution 37 note).
`journey.php?action=specialties` and `api/najiz_classify.php?action=categories` are the only two
readers, and on production the first is absent and the second 500s. The website homepage has no
legal-category section at all, so there is nothing to scrape as a fallback — restoring categories
requires applying `upgrade_v59.sql` **and** running the import on production.

### Production is missing exactly two `api/v1` files, and the migration order matters

Probing every `api/v1/*.php` on `mawatheq.com` returns 401/400/200 for 23 of them and **404 for
exactly two**: `banners.php` and `journey.php`. Both exist in the repo and their entire include
closure (`includes/banners.php`, `includes/legal_journey.php`, `najiz_search_lib.php`,
`najiz_parser.php`, `availability.php`, `currency.php`, `najiz_import.php`) is already deployed —
each answers 403 through the web layer, i.e. present but denied. So the server-side gap is two
files, not a version.

`upgrade_v63.sql` derives `customer_desc_ar` / `customer_icon_key` **from the existing rows** of
`najiz_categories`. On a database where v59 just created that table it is empty, so the first run
writes nothing and the journey cards lose their descriptions. The order that works is
**v59 → v63 → importer → v63 again**; the second v63 run fills all 7 descriptions and icons. A
package that ships v59 and v63 without saying this leaves the home screen with 7 correctly-named
but description-less cards.

### Verifying the delivery package

`tests/exec39_najiz_graceful_test.php` drives the real endpoint against a scratch database with no
`najiz_*` tables, but the environment cannot redirect the connection: `config/secrets.php` defines
`DB_NAME` before `config/config.php`'s `MAWATHEQ_DB_NAME` fallback is reached, so exporting the
variable has no effect. The suite removes just the `define('DB_NAME'…)` line into a temp copy,
starts a second `php -S` on port 8099, and restores the file afterwards. Reuse that pattern for any
future "unmigrated install" test. Note also that PHP's built-in server rejects a raw non-ASCII
request line, so an Arabic query parameter must be `rawurlencode`d or the test fails for a reason
unrelated to the code under test.

### A sandbox reset also reverts `/etc/apache2` and the private root's group

The 2026-10 reset removed more than the installed packages, and each loss shows up as a
different, misleading test failure. All of it is repairable in a few commands, and none of it
is an application defect:

- **The vhosts and ports are gone.** `setup_test_env.sh` writes
  `/etc/apache2/sites-available/mawatheq-{8080,12000}.conf` and appends the `Listen` lines; the
  reset restores `ports.conf` to `Listen 80` and removes both site files, so Apache answers on
  neither port and *every* `*_http_probe.php` fails at once. Re-create them (the script is
  idempotent — re-running it is the simplest fix) rather than debugging the probes.
- **`/workspace/project/mawatheq-private` keeps its `openhands` group** while `www-data` loses
  the membership. The symptom is `execution30_consultation_attachments_probe` failing with
  `Permission denied` on files the CLI user is entitled to read, and
  `exec35_multimodal_consultation_test` dying with a TypeError in
  `consultation_attachment_absolute_path()` because a query returned `false`. Restore with
  `sudo chgrp -R www-data /workspace/project/mawatheq-private` and `chmod 2770` on that
  directory, plus `sudo usermod -aG www-data $(id -un)`, then run through `sg www-data -c`.
- **`mawatheq_test` survives with a partial schema.** It had 48 tables and no `lawyers` table,
  which is worse than an empty database: the tables exist, so nothing recreates them, and the
  failures look like code bugs. `upgrade_v63.sql` then dies with errno 150
  (`Foreign key constraint is incorrectly formed`) because `lawyer_specialties` references a
  table that is not there. Drop and rebuild: `database/schema.sql` gives 107 tables, and the
  63 `upgrade_v*.sql` files in numeric order give 143. **Do not apply
  `MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql` by hand** — `exec33_najiz_classification_test`
  imports only when `najiz_categories` is empty, so a manual import leaves `najiz_import_runs`
  with one row and the suite fails `import runs recorded` (it asserts `>= 2`). Leave the
  taxonomy empty and let the suite import, or truncate the taxonomy tables first.

### A batch failure that passes standalone is usually test-order state

`exec33` reported `FAIL: import runs recorded` in `run_all_tests.sh` and `99/99` when run
alone, purely because of the manual import above. Before investigating a single suite failure,
re-run that suite on its own — `run_all_tests.sh` prints per-suite output, but the cause is
often the database state an earlier step left behind, not the code under test.

### Pinning `compileSdk` is the fix for a plugin that compiles against a newer SDK

Flutter 3.47.7's `FlutterExtension` defaults `compileSdkVersion` to **36**, but
`permission_handler_android` compiles against 37, so `flutter build apk --release` fails
dependency validation with `requires Android SDK version 37`. `android/app/build.gradle.kts`
now pins `compileSdk = 37` explicitly. Compiling against a higher SDK than `targetSdk` is
backward compatible, so `targetSdk` stays at Flutter's default 36 and runtime behaviour is
unchanged — the pin only widens the API surface visible at compile time. The Android SDK ships
platform 37, so nothing is downloaded.

### Kotlin 2.3.20 is the floor; Gradle 9 / AGP 9 are a separate, deferred migration

`flutter build apk --release` warns that Kotlin 2.2.20 "will soon be dropped" (needs >= 2.3.20).
`android/settings.gradle.kts` is now on 2.3.20 and that warning is gone. The remaining two
warnings name **Gradle 8.14.3 -> >= 9.1.0** and **AGP 8.11.1 -> >= 9.0.1**; those are
major-version migrations with their own breaking changes and are deliberately not part of an
additive change. They are warnings, not failures — the build succeeds. Suppress them for a
one-off run with `--android-skip-build-dependency-validation`, never by editing the versions
casually.

## Execution 40 — the mobile app's dark mode (theme tokens)

### There was no dark theme; the work was adding one without moving the light palette

`AppTheme` built a single light theme and the file said so explicitly ("There is intentionally no
dark theme"). The app is Arabic-first and the owner's phone is commonly in dark mode, so the
requested change was a real dark theme. The hard constraint is the opposite one: the approved light
palette must stay byte-identical, because it is the shipped, signed-off design.

The structure that satisfies both is a token layer. `lib/core/theme/app_tokens.dart` holds only the
**neutral** colours that must change (background, surfaces, borders, body text, shadows) plus a few
new composites; the **brand** colours (navy, teal, gold, cyan, and the status semantics) stay in
`AppColors` and do not change between themes. A dark theme that re-tinted the brand would stop
looking like Mawatheq. Screens ask for `context.tokens.surface`, never "is dark mode on?".

`app_tokens.dart` was written so that `AppThemeTokens.light` is literally the old constants —
`bg` is `AppColors.bg`, `heading` is `AppColors.navy`, and so on. `exec40_dark_theme_test.dart`
pins every one of those equalities, which is what makes "the light palette did not move" a checked
claim rather than a promise.

### The bug class to look for: a correct token that nothing calls

Three defects in this area all had the same shape — the dark-mode support existed and was not
wired, so it looked done:

- `StatusBadge` kept painting the pale light-mode washes (`AppColors.backgroundForStatus`) on dark
  cards. `backgroundForStatusIn` existed and had no callers.
- `DashboardCard` fell back to `accent ?? AppColors.navy` for its number. Navy on a dark card is
  invisible. The fallback is now `context.tokens.heading`.
- `filledButtonTheme` used `AppColors.navy` in **both** themes, directly under a comment claiming
  "dark mode needs a lighter navy … the light navy would read as a hole in the card". The comment
  was right and the code did not do it; the navy separates from the dark surface by only 1.4:1.

So when auditing this area, check that each dark-mode-aware helper has a caller. Contrast maths on
the token table is not enough on its own — `exec40_dark_render_test.dart` mounts the real widgets
and asserts the *resolved* colour, which is what catches an unused token.

### Contrast floors and the deliberate exceptions

Body text is held to 4.5:1 and muted text, borders and badge text to 3:1. Two values are
deliberately **not** fixed:

- The **light** info badge (brand teal on the pale info wash) measures 2.54:1. This predates the
  theme work. Raising it means editing the approved light palette, which the brief forbids, so
  `exec40_dark_theme_test.dart` pins the value at 2.54 instead — if someone changes it, the test
  tells them they moved a shipped colour. The dark info badge uses the same teal and reaches 5.1:1,
  so no per-theme variant is needed there.
- The dark `brandFill` (0xFF35608F) separates from the dark surface by 2.53:1, below the 3:1 used
  for borders. That is intentional: it is a large filled control with a 6.5:1 label, and pushing it
  to 3:1 costs the white label its margin.

### The theme chooser has to exist, and the controller has to be injectable

`ThemeController` was wired into `main.dart` and `app.dart` but `setMode` had no caller, so the
feature was unreachable. The chooser now lives in the account screen's settings list
(`_ThemeSettingTile`, an "المظهر" tile opening a bottom sheet with حسب النظام / فاتح / داكن).

Two things about it are load-bearing:

- It reads the controller as `context.watch<ThemeController?>()`. A widget test that mounts the
  screen without a provider must hide the tile, not crash — same tolerance `app.dart` uses around
  `ProviderNotFoundException`.
- `ThemeController` takes a `PrefsStore`, not the concrete `PrefsStorage`. It took the concrete
  class, which made the persistence path untestable; that is why the interface exists.

### Widget-test note: `pumpAndSettle` does not work on the account screen

`AccountScreen` shows `LoadingSkeleton` while invoices load, and it animates forever, so the frame
scheduler never goes idle and `pumpAndSettle` times out. Pump explicit frames instead
(`await tester.pump(const Duration(milliseconds: 400))`). This is not a bug in the screen; it is a
property of any test that mounts it.

### A shared specialty-icon map replaced two copies

`journey_screen.dart` and `home_tab_public.dart` each had a private `_iconFor`. They are now
`lib/shared/specialty_icons.dart`. The consolidation was not cosmetic: both copies were missing
`enforcement` and `endowments`, so those two categories silently fell through to the default icon.

## EXECUTION 41 — mobile screenshots, and the two defects they exposed

### The screenshot harness asserts before it captures

`apps/mobile/tool/screenshots/capture_screenshots.dart` renders nine screens at 1080x2280 and writes
goldens to `tool/screenshots/goldens/`. It asserts each screen's text **before** calling
`matchesGoldenFile`, through a shared `_shot()` helper. The order is the point: `matchesGoldenFile`
will happily write a picture of a blank screen, so without the assertion a broken screen produces a
green test and a useless image. Regenerate with:

```
flutter test tool/screenshots/capture_screenshots.dart --update-goldens
```

### Arabic fonts are bundled and runtime fetching is off

`google_fonts` resolves a family+weight to a file by name (`Cairo-Bold.ttf`,
`IBMPlexSansArabic-SemiBold.ttf`, ...). Those files live in `apps/mobile/assets/fonts/` and are
declared as assets. `main.dart` sets `GoogleFonts.config.allowRuntimeFetching = false` and the
harness does the same, so the harness cannot pass on a font the shipped app would not have.

This replaced a runtime fetch from fonts.gstatic.com. The consequence to remember: **a new weight in
`app_theme.dart` needs a matching file in `assets/fonts/`**, or the app throws at first paint with
"allowRuntimeFetching is false but font X was not found". Weights in use: Cairo 400/700/800, IBM Plex
Sans Arabic 400/500/600/700. Cairo is published only as a variable font, so the static instances were
pinned from the `wght` axis with `fontTools.varLib.instancer`.

They are app assets rather than harness assets deliberately: declaring `tool/screenshots/` in
`pubspec.yaml` shipped ~1.5 MB of fonts *and the harness source itself* into the release APK.

### Do not sweep-test screens at the default 800x600 surface

The Flutter test surface is 800x600, a tablet-ish window. Phone layouts overflow on it, which looks
like a layout bug and is not one. Set the surface in any widget test that asserts layout:

```dart
tester.view.physicalSize = const Size(1080, 2280);
tester.view.devicePixelRatio = 3.0;
addTearDown(tester.view.resetPhysicalSize);
addTearDown(tester.view.resetDevicePixelRatio);
```

Also wrap the screen under test in a `Scaffold`: `TextField` and `ChoiceChip` throw
"No Material widget found" without one.

### Screens must accept an injected `ApiRepository`

`ConsultationsScreen`, `CasesScreen`, `NotificationsScreen` and `AssistantScreen` hardcoded
`ApiRepository()` in a field initialiser. A screen that builds its own client cannot be tested at
all, and that is exactly how `ConsultationsScreen` came to render an empty list in the harness: the
fixture was served by a fake transport the screen never used. They now take `this.repository` and
fall back to `ApiRepository()`, matching `DocumentsScreen` and `PublicLawyersScreen`.

`test/exec41_blank_screen_sweep_test.dart` mounts every data-loading screen against a fake transport
and asserts the fetched content is on screen. That is the regression guard for "the screen builds but
shows nothing", which neither a compile check nor a mount-only test catches.

## EXECUTION 41 — the release, and the minSdk claim that was wrong

### `flutter.minSdkVersion` is 24, and nothing in the repo said so

`android/app/build.gradle.kts` sets `minSdk = flutter.minSdkVersion`, which is Flutter's default and
not written anywhere in this repo. On Flutter 3.47.7 that default is 24 (`FlutterExtension.kt`), so
the app requires **Android 7.0**. The desugaring comment above that line said "minSdk 21" and the
Arabic delivery guide repeated "أندرويد 5.0 (API 21)", which would have told a reader the app
installs on Android 5.0. It does not.

Never state a minSdk from memory or from a neighbouring comment. Read it off the built artifact:

```bash
aapt2 dump badging app-release.apk | grep -E "minSdkVersion|targetSdkVersion"
# minSdkVersion:'24'   targetSdkVersion:'36'
```

### Release assets are named `vX.Y.Z+<versionCode>`, and the release is `vYYYY.MM.DD-execNN`

Assets in this repo's releases carry the version *and* the version code
(`MAWATHEQ_APP_v1.1.0+2_release.apk`) so the file name matches `aapt2 dump badging` output. Keep
that when adding an asset.

A `+` in an asset name is percent-encoded in the download URL. The link works only as `%2B`:

```bash
curl -sL -o app.apk \
  "https://github.com/startgroup2022-tech/mawatheq/releases/download/v2026.10.22-exec41/MAWATHEQ_APP_v1.1.0%2B2_release.apk"
```

### The GitHub release API returns 204 with an empty body on asset DELETE

`json.load(r)` on that response raises `JSONDecodeError: Expecting value: line 1 column 1`. Read the
body and parse only if it is non-empty. A failed run can also leave a half-replaced asset set: the
delete succeeds and the upload does not, so re-list the assets rather than assuming.

### The tag has to point at the commit the assets were built from

The release is created with `target_commitish: main`, which creates the tag at `main` *at that
moment*. Pushing a later commit leaves the tag behind `main`, and a reader clicking the tag gets
sources that do not match the APK. After any post-release commit, move the tag:

```bash
git tag -f -a v2026.10.22-exec41 -m "..." HEAD
git push -f origin v2026.10.22-exec41
git rev-parse v2026.10.22-exec41^{commit}   # must equal git rev-parse origin/main
```

### Signing: v2 only, debug key

The release APK is signed with `CN=Android Debug` and **only** APK Signature Scheme v2
(`apksigner verify --verbose` → `Verified using v1 scheme (JAR signing): false`). v2-only is fine
above minSdk 24, but the debug key means it cannot go to Google Play and App Links cannot verify
against it. `android/key.properties` (gitignored) is what switches it to a real release key.

### What screenshot and widget tests do not prove

The 204-test suite, the nine goldens and the blank-screen sweep all run against a fake transport.
They prove the screens build, render, and handle canned JSON. They prove nothing about a live
booking, a production login, a real document upload or an AI call. Do not let a green suite stand in
for those in a delivery report — list them as untested.

