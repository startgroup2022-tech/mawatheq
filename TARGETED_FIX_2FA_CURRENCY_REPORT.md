# Targeted Fix — Remove Forced Admin 2FA + Platform Currency = SAR

Date: 2026-09-29
Scope: two production fixes only. No rebuild, no redesign, no architecture change.

---

## 1. How mandatory 2FA enrollment was removed

The forced enrollment lived in exactly one place — `admin/includes/admin_guard.php`:

```php
// removed
if ($role === 'super_admin' && empty($adminAccount['totp_enabled']) && $route !== 'two-factor.php' && $route !== 'logout.php') {
    header('Location: /admin/two-factor.php?required=1');
    exit;
}
```

That block was deleted. The repository-wide audit for `two-factor`, `two_factor`,
`totp`, `TOTP`, `required=1`, `totp_required`, `2fa_required` found **no other
enforcement point**. There is now no code path that redirects an administrator to
`/admin/two-factor.php?required=1`, so there is no redirect loop.

The `$route` and `$role` locals that this block introduced are still defined (they are
used by the RBAC check immediately below), so no adjacent behaviour changed.

### What was preserved

- `admin/login.php` — password authentication, rate limiting, session regeneration.
- `admin/includes/admin_guard.php` line 12 — an account that **has** TOTP enabled is
  still required to present a verified code before any admin route runs. Removing the
  forced *enrollment* did not weaken verification for accounts that opted in.
- `/admin/two-factor.php` — the optional enable/disable page is unchanged and still
  reachable from the admin menu and settings.
- RBAC route table, CSRF on POST, `session_regenerate_id(true)`, audit logging.

---

## 2. Files changed

| File | Change |
|---|---|
| `admin/includes/admin_guard.php` | Removed the mandatory-enrollment redirect block; kept the TOTP verification gate and RBAC. |
| `includes/ngenius.php` | Gateway currency is now configured, not hard-coded: `NGENIUS_DEFAULT_CURRENCY='SAR'`, `ngenius_currency()`, `ngenius_minor_units()`, `ngenius_supports_currency(array,$code)`. Order sends the configured currency/country and the right minor-unit factor. |
| `pay-invoice.php` | Guard passes `$settings`; order uses the configured currency; refusal message is dynamic. |
| `api/v1/pay-invoice.php` | Added the same currency guard (previously missing) and passes the configured currency. |
| `ngenius-webhook.php` | Minor-unit divisor is now `ngenius_minor_units(ngenius_currency($settings))` instead of a hard-coded `/1000`. |
| `admin/settings.php` | Added a “gateway settlement currency” selector and save handler (`ngenius_currency`). |
| `database/schema.sql` | Seeds `ngenius_currency='SAR'` for fresh installs. |
| `database/upgrade_v44.sql` | Also seeds `ngenius_currency='SAR'` (idempotent, non-overwriting). |
| `tests/currency_test.php` | Gateway guard tests updated to the settings-based signature; added minor-unit tests. |
| `tests/phase3_finance_test.php` | Test fixtures now insert `SAR` invoices. |
| `DEPLOY_CPANEL_AR.md`, `PHASE_1_AUDIT_REPORT.md`, `AGENTS.md` | Currency wording corrected to SAR. |

---

## 3. Does optional TOTP remain?

Yes. `/admin/two-factor.php` is untouched and still lets an admin enable or disable
TOTP. Verified live: with `totp_enabled=0` the admin logs straight in; after enabling
TOTP the login form requests a 6-digit code and only a correct code reaches the
dashboard.

---

## 4. Where currency is configured

`site_settings.site_currency`, edited in **Admin → Settings**. The value is read through
`includes/currency.php` (`currency_code()`), which is cached per request and invalidated
with `currency_forget_cache()` after a save.

The payment gateway’s settlement currency is a **separate** setting,
`site_settings.ngenius_currency`, also edited in Admin → Settings → payment gateway,
because the merchant/outlet account determines which currency the gateway can accept.

---

## 5. Currency changed from

`BHD` — displayed as `د.ب` / `دينار بحريني` / `Bahraini dinar`, with 3-decimal amounts.

## 6. Currency changed to

`SAR` — displayed as `ر.س` / `ريال سعودي` / `Saudi Riyal`, with 2-decimal amounts.
Example: `1,250.00 ر.س`.

No exchange-rate conversion was performed and no historical amount was rewritten. The
`invoices`/`invoice_payments` columns remain `DECIMAL(12,3)`, which holds both the old
3-decimal and the new 2-decimal values without loss. Existing rows keep their stored
currency; only the *default for new rows* and the *display currency* changed.

---

## 7. Financial screens updated

Invoices, invoice items, payments, balances, overdue, receivables, reports and dashboards
all render through the currency helpers (`money()` / `money_text()`), so they follow the
configured currency automatically. Verified live across admin, lawyer and client views —
all show `ر.س`.

The underlying calculation logic (`amount`, `amount_paid`, `balance`, `subtotal`,
`discount`, `tax`) was **not** modified.

## 8. API updates

`api/v1/invoices.php` and `api/v1/finance.php` return each invoice’s real `currency`
column (SAR for new rows). No literal currency string is hard-coded in any API response,
and no API contract shape changed. `api/v1/pay-invoice.php` gained the currency guard.

## 9. Payment gateway considerations

This integration points at the **Saudi** N-Genius gateway
(`api-gateway.ksa.ngenius-payments.com`, supporting مدى/Visa/Mastercard), but the code
was sending `BHD` with a ×1000 minor-unit factor and `countryCode: 'BH'` — inconsistent
with a SAR platform and a ~10× mis-charge risk on every order. It now sends the
configured currency (default `SAR`), a ×100 minor-unit factor (halalas) and
`countryCode: 'SA'`.

**External limitation:** production N-Genius credentials were not available, so a live
transaction could not be executed. No transaction was faked. The currency configuration
is prepared and guarded; confirming the outlet is provisioned for SAR must be done
against the real merchant account (Admin → Settings → gateway currency must match it).

## 10. Tests performed

- Full PHP lint: **166 files, 0 errors**.
- `tests/currency_test.php` — 35 checks, 0 failures.
- `tests/phase3_finance_test.php` — 43 checks, 0 failures.
- `tests/phase3_ai_gateway_test.php` — 42 checks, 0 failures.
- `tests/tenant_isolation_test.php` — 24 checks, 0 failures.
- Real HTTP: admin login → dashboard (200, no `two-factor.php?required=1`); authenticated
  admin pages 200; POST sign-out returns to login; logged-out admin access redirected to
  login; bad password rejected; optional TOTP challenge still enforced.
- Real HTTP currency sweep across admin/lawyer/client pages — SAR everywhere, no BHD/د.ب.
- Gateway guard exercised live: SAR invoice passes the guard (reaches the gateway call);
  a non-SAR invoice is refused with a clear message.

## 11. Remaining external limitations

- Live N-Genius transaction not verified (no production credentials); SAR must be
  confirmed on the merchant account.
- Historical BHD invoices, if any exist in production, keep their stored currency by
  design and will continue to display as BHD. This is intentional and is not a defect.
