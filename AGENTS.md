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

## Testing

There is no PHPUnit suite. UI work is verified with a Playwright audit that logs
in as each role (admin needs a fresh TOTP) and renders every page at several
viewports, checking horizontal overflow, sub-11px text and undersized targets.

- Seed accounts: `client@example.com` / `Test-Client-2026`,
  `lawyer@example.com` / `Test-Lawyer-2026`,
  `admin@mawatheq.com` / `Test-Admin-2026` (TOTP from `admins.totp_secret`).
- The audit clears `auth_rate_limits` before logging in; repeated runs otherwise
  trip the `admin-2fa` throttle and the admin login silently fails.
- `php -l <file>` on every touched PHP file is the quick syntax gate.

## Gotchas

- `lawyer/review-queue.php` returns 403 unless the account has a law-firm
  membership — that is the page's own guard, not a routing bug.
- Checkbox/radio inputs must stay >= 24px (WCAG 2.5.8); their wrapping `<label>`
  is the real hit area and should clear 44px. Some legacy pages hard-code a
  smaller inline size, so `enterprise.css` wins on `min-height`.
- Long tables switch to card mode on narrow viewports; a `min-width` on the
  table would defeat that.
