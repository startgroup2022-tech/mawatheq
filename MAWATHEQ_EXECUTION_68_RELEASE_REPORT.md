# MAWATHEQ — Execution 68 Release Report

**Branch:** `feature/ai-workspace-firm-suite` · **PR:** #10
**Commit:** `a3ff193` · **Date:** 2026-11-27

---

## 1. Priority 1 — N-Genius payment failure: root cause and fix

### The reported symptom

Production showed:

> «تعذّر بدء عملية الدفع الإلكتروني حالياً. لم يتم خصم أي مبلغ.»

with no indication of what failed and nothing an operator could act on.

### Root cause

That sentence is the **last line** of `gateway_diag_customer_message()` — the
fall-through for any reason code the map does not name. The code that reached it was
`token_failed`, which the payment paths emit *themselves*, and which was unmapped.

Why `token_failed` rather than a precise code: both payment paths flattened **every**
`http_*` token result into it:

```php
if ($code === '' || strpos($code, 'http_') === 0 || $code === 'connection_failed') {
    $code = 'token_failed';
}
```

So `http_401` and `http_403` — a **rejected credential**, a permanent fault — were
indistinguishable from a transient network blip. A misconfigured API key was therefore
presented to the customer as "try again later", forever.

### The fix

The token stage now classifies through `gateway_diag_reason_code()`, exactly as the order
stage already did:

```php
$code = $code === '' ? 'token_failed' : gateway_diag_reason_code($code);
```

| Raw outcome | Code | Customer message |
|---|---|---|
| `http_401`, `http_403` | `gateway_rejected` | permanent — contact support |
| `http_500`, `http_429`, `connection_failed` | `connection_failed` | retryable |
| empty (no code at all) | `token_failed` | retryable |
| `badTokenRequest`, `realmNameNotAvailable`, `outletNotAvailable` | verbatim | permanent |

`token_failed` and `order_failed` stay retryable **on purpose**. They are the genuinely
unknown buckets; making them permanent to hide a vague message would tell a customer to
stop trying when the next attempt could have succeeded. The defect was the
classification upstream, and that is what was fixed.

### Proven over real HTTP, both directions

`tests/exec68_payment_message_http_probe.php` (13 checks) logs in as a real client, POSTs
`consultation-pay.php` with a real CSRF token, and reads the banner the browser renders:

- **Gateway disabled** → «الدفع الإلكتروني غير مفعّل حالياً. لم يتم خصم أي مبلغ. تواصل معنا
  لإتمام الاشتراك. الرقم المرجعي: 5D6C8235» — a specific cause, a reference, and no false
  invitation to retry.
- **Gateway enabled** → 302 to the hosted payment page (`/hosted/CONS-1-...`), i.e. the
  success path.

`tests/exec68_gateway_diagnostics_console_probe.php` (18 checks) closes the operator half:
the reference the customer quotes is entered on `admin/gateways.php?ref=<ref>` and shows
the stage, reason code and detail for that exact attempt.

### The failure actually recorded on production

`gateway_diagnostics` held a real row: `unsupported_currency` —
`site currency SAR != outlet BHD`. That is now surfaced **proactively** on
`admin/gateways.php` before a customer reaches it, naming both currencies and the fix.
Verified over HTTP by section 4 of the console probe.

---

## 2. Priority 2 — Plans, pricing, subscriptions

Verified DB-driven end-to-end, no hardcoded price anywhere:

- `plans` holds Basic 0 / Professional 149·1490 / Corporate 799·7990.
- The pricing page, `packages.php`, `checkout.php` and `admin/plans.php` all read that table.
- `package_order_create()` resolves the amount from the plan row, **not** from the form.
  Proven: an order for plan 2 (monthly) produced `amount=149.000 SAR`, matching
  `plans.price_monthly` exactly, with `currency` from `site_settings`.
- `tests/exec69_plan_catalog_test.php`: 79 checks, 0 failures.

---

## 3. Priority 3 — Booking

The full chain is wired and tested (`tests/exec76_payment_booking_regression_test.php`,
66 checks; `exec70_client_hearings_test.php`, 6 checks):

- `booking.php` + `consultation-journey.php` (web) and `api/v1/available-slots.php` (app)
- real availability from lawyer schedules, interval-based conflict prevention
  (`booking_slot_overlap`)
- payment via the shared gateway layer, invoice issuance
  (`consultation_invoice_issue()`), notifications to both lawyer and client
- `case-hearings.php` for the client's hearing schedule (renders 200, 14 KB)

---

## 4. Also landed

Uncommitted Execution 70 work that was in the tree, now verified and committed:

- **Per-firm chatbot:** `lawyer/chatbot.php` (200, 25 KB), `admin/firm-chatbots.php`,
  `api/firm-chat.php` (405 on GET — method-guarded), `includes/firm_chatbot.php`,
  `assets/js/firm-chat-widget.js`.
- **Template library:** `lawyer/templates.php` (200, 26 KB), `includes/template_library.php`,
  `database/upgrade_v83.sql` seeding 10 Saudi instruments.
- **Client hearings:** `case-hearings.php`.

**A real defect fixed on the way:** `assets/js/firm-chat-widget.js` was mode `600`. Apache
serves that as **403**, so the chatbot widget would have silently never loaded while every
page still returned 200 — the exact failure mode AGENTS.md documents. Now `644`, verified
served (`curl` → 200).

---

## 5. Verification

| Suite | Result |
|---|---|
| PHP suites (`run_all_tests.sh`) | **80/80 passed, 0 failures** |
| Playwright e2e | **1032 passed, 0 failed** (six viewports, RTL + LTR) |
| Flutter `analyze` | clean, no issues |
| Flutter `test` | **379 passed** |
| Release smoke test (on the artifact) | 65 checks, 0 failures |
| Migrations, applied twice | 180 tables both times, 0 errors — idempotent |
| Build reproducibility | two builds → identical `SHA256SUMS.txt` |

New regression guards added: `exec68_payment_message_http_probe.php`,
`exec68_gateway_diagnostics_console_probe.php`, `exec68_gateway_message_coverage_test.php`
(35 checks). `tests/setup_test_env.sh` now writes the N-Genius fixture `SetEnv`, and
`tests/run_all_tests.sh` starts the fixture, so the payment probes are not
environment-dependent.

---

## 6. Artifacts

| File | Size |
|---|---|
| `MAWATHEQ_WEBSITE_FINAL.zip` | 66 MB |
| `MAWATHEQ_DATABASE_MIGRATIONS.zip` | 328 KB |
| `MAWATHEQ_APP_v1.8.8+26_release.apk` | 98,900,218 B |
| `MAWATHEQ_APP_v1.8.8+26_release.aab` | 78,584,600 B |
| `MAWATHEQ_DEPLOYMENT_GUIDE_AR.md` | updated for v81–v83 |

Checksums verify (`sha256sum -c SHA256SUMS.txt` → all OK). The payment fix is confirmed
**inside the shipped bytes** (`unzip -p … includes/consultation_payment.php | grep
gateway_diag_reason_code` → 2 matches), and `upgrade_v83.sql` is in the migration package.

### Deployment

The guide previously stopped at `upgrade_v80.sql` while the site needs v81–v83 — a missing
step that is invisible until a deploy fails. Steps 9.14–9.16, the migration table and the
checklist now cover all three.

Apply in order: `upgrade_v81.sql` → `upgrade_v82.sql` → `upgrade_v83.sql`, then
`php database/repair/02_migration_ledger.php` and `php tools/production_check.php`.

---

## 7. What is NOT proven — stated plainly

- **A live N-Genius merchant account settling real money is not proven.** Every payment
  test runs against `tests/fixtures/ngenius_server.php`. That proves the client speaks the
  gateway's schema and that the failure messages and diagnostics are correct. It does not
  prove a live outlet charges a card or that the production credentials are valid.
- **The production currency mismatch is diagnosed, not fixed.** The recorded row says
  `site currency SAR != outlet BHD`. Whether the merchant account settles in SAR or BHD is
  a decision only the owner can make; the console now names the mismatch and the two ways
  to resolve it.
- **The APK/AAB are debug-signed** (`CN=Android Debug`). Fine for local testing; a Play
  upload needs the owner's upload key via `android/key.properties` (gitignored, absent).
- **Nothing was deployed to production and PR #10 was not merged** — both await the
  owner's approval, as instructed.
- The screenshot and widget tests run against a fake transport. They prove screens build
  and render canned JSON; they do not prove a live booking, login or upload.
