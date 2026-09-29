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
- `/sadad-webhook.php` is CSRF-exempt in `config/config.php` (server-to-server, authenticated by
  signature). Any new webhook path must be added to `$csrfExemptPaths` there.
- `invoice_payments.method` enum, the `finance_record_payment()` allow-list and the schema must
  stay in sync when adding a gateway method; `database/upgrade_v45.sql` extends the enum.
- Admin console pages must `require_once` `config.php`, `lang/strings.php`, `admin_guard.php`
  (and `audit_log.php` if they call `log_action()`) **before** using `is_rtl()` or `log_action()`
  at the top of the file — `admin_header.php` alone is not enough ordering if you use those
  helpers above the header include.

## Testing

CLI suites live in `tests/` and run against the configured database with
`php tests/<name>_test.php` (each prints `N checks, M failure(s)` and exits
non-zero on failure): `tenant_isolation_test.php`, `phase3_finance_test.php`,
`phase3_ai_gateway_test.php`, `currency_test.php`, `sadad_gateway_test.php`,
`phase5_saas_core_test.php` (tenant scoping, notification fan-out, the firm
permission catalog and its no-escalation rule, and the audit actor extension).

`sadad_gateway_test.php` starts the real fixture server `tests/fixtures/sadad_server.php`
on an ephemeral port and exercises the live curl path — no HTTP mocking. It runs inside a
transaction and rolls back, so it leaves no residue.

Environment (local sandbox): install with
`sudo apt-get install -y php-cli php-curl php-mysql php-mbstring mariadb-server`, start with
`sudo service mariadb start`, and create the `mawatheq` DB/user matching `config/secrets.php`.
Load `database/schema.sql` then every `database/upgrade_v*.sql` in order (currently
through `upgrade_v46.sql`); the migrations are idempotent.

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
