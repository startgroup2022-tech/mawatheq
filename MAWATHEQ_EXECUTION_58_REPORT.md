# MAWATHEQ — EXECUTION 58 DELIVERY REPORT

**Date:** 2026-10-25
**Branch:** `exec56-lawyer-platform`
**PR:** #10 — https://github.com/startgroup2022-tech/mawatheq/pull/10
**Release:** `v2026.10.25-exec58`

---

## 1. What this execution delivered

| Priority | Item | Status |
| --- | --- | --- |
| 1 | Website header defect — root cause and fix | DONE (fix in package, production not yet updated) |
| 2 | Lawyer application — independent app | DONE (APK + AAB built) |
| 3 | Lawyer wallet integrated with the financial backend | DONE (verified real ledger) |
| 4 | Voice and video calls | DONE in code; **live two-party call BLOCKED** (needs a TURN server on the production host) |
| 5 | Client bottom navigation | DONE (verified, no client rebuild needed) |
| 6 | Privacy and account deletion | DONE in repo; **production returns 404** (undeployed) |
| 7 | Security and reliability | DONE (see §6) |
| 8 | Real end-to-end testing | PARTIAL — see §7 for exactly what was and was not tested |
| 9 | Build and package | DONE |
| 10 | GitHub release and preview links | DONE |

---

## 2. Priority 1 — the website header

### Root cause

The header overlap is **not** a styling preference problem. Production serves an
older `assets/css/style.css` (28,769 bytes) than the repository (33,253 bytes).
In the production stylesheet `.site-header .container` is still:

```css
display: flex;
justify-content: space-between;
```

The navigation links are `white-space: nowrap`. Inside a flex container whose
children cannot shrink, the nav's content box needs ~620 px but the flex row
only gives it 578 px. The nav has `overflow: visible`, so the links **paint
outside their own box** and land on top of the brand logo and the action
buttons. Nothing about the nav element's own rectangle looks wrong, which is why
a box-measuring test reported zero failures on the broken page.

The repository stylesheet replaces the flex row with a grid:

```css
.site-header .container {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: var(--s-4);
}
```

`minmax(0, 1fr)` lets the middle track shrink below its content width, so the
nav can never overflow onto its neighbours.

### How the defect is proven, and how the fix is verified

`tools/header_capture.js` measures the **painted rectangles of the links
themselves** (`getBoundingClientRect()` on each `<a>`), not the nav box. That is
the measurement that makes the defect visible.

```
# Production (mawatheq.com) — the broken page
node tools/header_capture.js https://mawatheq.com /tmp/evidence/before
→ 372 checks, 16 failure(s)
   OVERLAP /privacy.php @1920 (scrolled): brand ∩ nav

# Repository CSS (local dev server) — the fixed page
node tools/header_capture.js http://127.0.0.1:8080 /tmp/evidence/after
→ 204 checks, 0 failure(s)
```

Both runs were re-executed during this execution; the numbers above are the live
output, not carried over from an earlier report.

The 16 production failures are overlaps at 1210 / 1280 / 1440 / 1920 px, in both
the top-of-page and scrolled states, on `/` and on `/privacy.php`. The
repository passes all 204 checks across 360, 390, 768, 980, 1024, 1210, 1280,
1440 and 1920 px.

**Before/after screenshots** are in `screenshots/header_before/` (production)
and `screenshots/header_after/` (fixed) inside the screenshot archive.

### Modified files

- `assets/css/style.css` — the grid header (already committed in an earlier
  execution; this execution confirmed it is the correct root-cause fix).
- `tools/header_capture.js`, `tools/header_probe.js` — the detectors (new).

### Is production updated?

**No.** The corrected stylesheet is inside
`MAWATHEQ_WEBSITE_CPANEL_FINAL.zip`. The owner must upload it (see §8). No
deployment was performed from here — there is no production server access in
this environment, and the instruction was not to deploy without authorization.

---

## 3. Priority 2 — the independent lawyer application

The lawyer app is now a genuinely separate application, not a flag inside the
customer app:

| Property | Value |
| --- | --- |
| Source | `apps/lawyer_mobile/` |
| Application ID | `com.mawatheq.lawyer` |
| Label | مواثيق للمحامين |
| Version | 1.0.0+1 |
| minSdk / targetSdk | 24 / 36 |
| APK | `MAWATHEQ_LAWYER_APP_v1.0.0+1_release.apk` (99.3 MB) |
| AAB | `MAWATHEQ_LAWYER_APP_v1.0.0+1_release.aab` (79.2 MB) |

The customer app (`com.mawatheq.app`, version 1.6.0+15) was **not modified** in
this execution beyond one shared-library fix described in §5.

### Why it is independent, not a copy

The lawyer app is a second *shell* over the shared, already-verified library
(`mawatheq_mobile`). There is exactly one definition of a consultation, one HTTP
client and one wallet model, so the two apps cannot drift apart in how they talk
to the backend. What differs is the surface:

- The only signed-out screen is the lawyer sign-in. There is no public directory,
  no consultation booking, no AI assistant and no account-type switch.
- `app.dart` routes `AuthStatus.signedIn when auth.user.isLawyer` to
  `LawyerShell`; **anything else — a client token, a guest, a signed-out lawyer —
  is sent to `LawyerLoginScreen`.** A client token cannot reach a lawyer surface.
- `/api/v1/auth/lawyer-login.php` returns the account under a `lawyer` key with
  no top-level `type`. `exec58_lawyer_app_test.dart` pins that exact shape,
  because an earlier test invented `user: {type: 'lawyer'}` and hid a defect
  where a signed-in lawyer was parsed as a client.

### Password reset now lands in the right app

Both apps claimed `/reset-password` in their Android manifests, so a lawyer reset
email opened the **customer** app. The lawyer app now claims
`/lawyer-reset-password`, and `includes/auth_security.php` points lawyer resets at
that path (`lawyer-reset-password.php`, a new route).

### Verified surfaces (real backend, not fixtures)

The 29-file lawyer workspace was verified end to end against the live API by
`tests/exec57_lawyer_platform_probe.php` (85 checks): role isolation, the
server-computed dashboard, the wallet ledger, profile and settings, two-party
call signalling, and the admin console.

Screenshots of the real screens (dashboard light and dark, wallet, profile,
settings, sign-in) are in `screenshots/lawyer/`. They are produced by
`apps/lawyer_mobile/tool/screenshots/capture_lawyer_screens.dart`, which
**asserts each screen's text before capturing**, so a blank screen fails the
capture instead of producing a green test and a useless image.

---

## 4. Priority 3 — the lawyer wallet

The wallet is a real ledger, not a displayed number.

- `wallet_balances()` computes available / pending / reserved from the
  `wallet_ledger` rows; the commission is resolved **at payment time**, so a
  later commission-rate change does not rewrite history.
- Endpoints: `GET /api/v1/wallet.php` `action=summary|statement|transactions|withdrawals`
  and `POST` `action=request-withdrawal|save-payout`.
- A withdrawal moves through `requested → approved → paid`; the admin side
  reviews it. `affiliate_payout_currency_for()` style per-account resolution is
  used for anything that sums one lawyer's money (the platform default is only
  right for display).
- `tests/exec56_wallet_test.php` — 52 checks, 0 failures.

---

## 5. Priority 4 — voice and video calls

### What exists

- `includes/calls.php` — provider settings, ICE server assembly, token issuance.
- `api/v1/calls.php` — start / accept / signal / poll / end, scoped to one call.
- `apps/mobile/lib/features/calls/` — `call_service.dart` (real
  `flutter_webrtc`), `call_screen.dart`, `incoming_call_watcher.dart`.
- Admin: the calling card in `admin/settings.php` shows the readiness state and
  the TURN field.

### What is verified

`tests/exec57_lawyer_platform_probe.php` drives the **full signalling exchange
over real HTTP** between two authenticated parties (85 checks, all pass):

- the client starts a call on its own consultation; the lawyer sees it ring;
- offer → answer → ICE candidate are delivered to the **other** party only;
- a foreign lawyer cannot obtain a call token;
- a foreign token cannot post a signal; a third party cannot poll the call;
- an unsupported signal kind is refused; the call ends and the duration is
  recorded.

### What is NOT verified — read this before claiming calling works

**No real two-party media call has been placed.** The signalling path is proven;
the media path is not. The blocker is a documented dependency:

> **TURN server.** `call_stun_urls` defaults to `stun:stun.l.google.com:19302`.
> STUN alone lets two peers on ordinary networks find each other, but **not**
> through symmetric NAT (most mobile carriers). Without a TURN relay, a call
> between two phones on mobile data will fail to connect even though every
> signalling check passes.

**Next action for the owner:** run a TURN server (coturn on the production host,
or a hosted provider) and enter its URL, username and credential in
**Admin → Settings → Calling**. The code already sends those to the client as
ICE servers; nothing else needs to change. Until then, treat calling as
**BLOCKED on infrastructure**, not complete.

---

## 6. Priorities 5–7

### Client bottom navigation (Priority 5)

The client app was **not redesigned**. The bar is a Material `NavigationBar`
over an `IndexedStack`, so tab state is preserved; the account tab is rebuilt
only when the sign-in state changes. `test/exec57_bottom_nav_test.dart` covers
all nine requirements — five sections, per-destination rendering, state
retention, non-zero height, safe area, keyboard, dark mode, RTL order, and a
sign-out — and passes 9/9. **No new client APK is needed for this.**

### Privacy and account deletion (Priority 6)

Two different states on production, and a status code alone does not tell them
apart:

- **`privacy.php` IS deployed and returns 200**, with the correct `<h1>سياسة
  الخصوصية</h1>`. It is an **older revision**: comparing the rendered text
  against the repository (1,240 vs 769 characters) shows production is missing
  the account-deletion paragraph and the footer link to it. So the page works;
  it is simply behind.
- **`delete-account.php` is 404 on production** — never deployed.

In the repository both are public, RTL, responsive, and need no login. The
deletion itself runs through `includes/account_deletion.php::account_delete()`,
which revokes every `api_tokens` row, clears credentials and reset tokens, and
refuses to delete another party's records. `tests/exec52_account_deletion_test.php`
covers it (21 checks, 0 failures).

No company detail or retention period was invented.

### Security (Priority 7)

| Area | Result |
| --- | --- |
| Authentication | Lawyer login requires `email_verified = 1` **and** `status = 'approved'`; wrong-credential message is generic |
| Role authorization | `app.dart` sends any non-lawyer session to sign-in; the server refuses a client token at `/auth/lawyer-login.php` |
| Data isolation | `tests/exec57_lawyer_platform_probe.php` §1 and `execution29_tenant_isolation_probe.php` |
| IDOR | Cross-tenant reads return 404/null; verified in the exec57 probe (foreign lawyer, foreign token, third-party poll) |
| API access control | Bearer tokens, scoped; CSRF on every browser POST |
| File access | `download-document.php` checks ownership, `realpath()` under `UPLOAD_DIR`, 40-hex filename |
| Payment verification | Gateway settlement re-verified server-side; no payment is marked paid without confirmation |
| Idempotency | `payment_service.php` ORDER_CREATED row; atomic reset-token claim |
| Tokens/sessions | `session_regenerate_id(true)`, idle timeout, `session_invalidated_at` |
| Audit logging | Login and admin actions logged |
| Secrets | `config/secrets.php` is 600 and never packaged (build asserts it) |

`tests/exec52_account_deletion_test.php` — 21 checks, 0 failures.

---

## 7. Priority 8 — what was actually tested

Be precise about the difference:

| Level | What ran | Result |
| --- | --- | --- |
| **Unit / widget** | Client 283 tests, lawyer 8 tests, bottom-nav 9 | All pass |
| **Integration (real DB + real HTTP)** | `exec57_lawyer_platform_probe` (85), `exec56_wallet_test` (52), `exec52_account_deletion_test` (21) | All pass |
| **Header** | 204 repo checks vs 16 production failures | Fix confirmed in repo |
| **Staging** | None — no staging environment exists | — |
| **Production verification** | Read-only probes: header capture, endpoint status, asset download | See §10 |
| **Physical device** | **Not performed** — no device is attached to this environment | BLOCKED |

**Not tested:** a live payment through a real gateway, a live two-party media
call, and anything on a physical phone. Do not read the green suite as proof of
those.

---

## 8. Deployment instructions

The site package is `MAWATHEQ_WEBSITE_CPANEL_FINAL.zip`; the SQL is
`MAWATHEQ_DB_MIGRATIONS_SAFE.zip`. Full steps are in
`MAWATHEQ_DEPLOYMENT_GUIDE_AR.md`, which is in the package.

Short form:

1. Back up the database and the current `public_html`.
2. Upload `MAWATHEQ_WEBSITE_CPANEL_FINAL.zip` into `public_html` and extract it
   **in place** (overwrite).
3. **Delete the archive after extraction.** `.htaccess` denies `.zip`/`.tar.gz`
   as a second layer, but the archive exposes the whole tree if left behind.
4. Apply the migrations in **numeric** order (v1 … v67). They are idempotent; a
   second run is a no-op.
5. `chmod 600 config/secrets.php`; every other file 644, directories 755.
6. Run `php tools/production_check.php`. It must report 0 fail.
7. **To fix the header, clear the CSS cache.** Production is serving a stale
   `style.css`; after upload, confirm the served file is the new size
   (33,253 bytes) — a browser or CDN cache will otherwise keep showing the old
   layout while every page still reports 200.

---

## 9. Deliverables and checksums

Run `sha256sum -c SHA256SUMS.txt` in the download directory.

| File | Notes |
| --- | --- |
| `MAWATHEQ_LAWYER_APP_v1.0.0+1_release.apk` | Debug-signed (see below) |
| `MAWATHEQ_LAWYER_APP_v1.0.0+1_release.aab` | Debug-signed |
| `MAWATHEQ_WEBSITE_CPANEL_FINAL.zip` | Deployable site |
| `MAWATHEQ_DB_MIGRATIONS_SAFE.zip` | schema + upgrades + repair tooling |
| `MAWATHEQ_DEPLOYMENT_GUIDE_AR.md` | Operator guide |
| `screenshots/lawyer/*.png` | 6 lawyer screens |
| `screenshots/header_before/`, `screenshots/header_after/` | header before/after |

### Signing

The APK and AAB are signed with the **Android debug key**
(`CN=Android Debug`, SHA-256 `9786ff60…4690`), because there is no
`android/key.properties` in the repository. That is fine for local testing and
for App Links verification against the fingerprint committed in
`.well-known/assetlinks.json`, but **it cannot go to Google Play**. A Play upload
needs the owner's upload key. Verify with:

```bash
apksigner verify --print-certs --verbose app-release.apk
aapt2 dump badging app-release.apk | grep -E "minSdkVersion|targetSdkVersion"
```

### App Links

`.well-known/assetlinks.json` now lists **both** packages
(`com.mawatheq.app` and `com.mawatheq.lawyer`). The client fingerprint is the
release one; the lawyer entry carries the debug fingerprint until the owner
supplies the lawyer release certificate.

---

## 10. Verification of the delivered links

Every link below was opened and checked during this execution.

| Link | Label | Checked |
| --- | --- | --- |
| https://mawatheq.com | Production | 200 |
| https://mawatheq.com/privacy.php | Production | 200 — but an **older revision** (missing the deletion paragraph) |
| https://mawatheq.com/delete-account.php | Production | **404** — undeployed |
| https://mawatheq.com/admin/login.php | Production | 200 |
| https://mawatheq.com/lawyer-login.php | Production | 200 |
| https://mawatheq.com/api/v1/calls.php | Production | **404** — undeployed |
| https://mawatheq.com/lawyer-reset-password.php | Production | **404** — undeployed |
| Local dev server `http://127.0.0.1:8080` | Local preview | 200; header passes 204/204 |

Production is serving a stale tree: the home page and `privacy.php` are present
but old, and `delete-account.php`, `api/v1/calls.php` and
`lawyer-reset-password.php` are absent. All of it is fixed by deploying the
package in §8 — they are not separate defects.

---

## 11. Blocked items, stated plainly

| Item | Status | Reason | Next action |
| --- | --- | --- | --- |
| Header visible on production | **BLOCKED** | Package not deployed | Owner uploads the ZIP and clears the CSS cache |
| Real two-party audio/video call | **BLOCKED** | No TURN server configured | Owner runs coturn (or a hosted TURN) and enters it in Admin → Settings → Calling |
| Privacy / delete-account on production | **BLOCKED** | Package not deployed | Same upload as the header |
| Play Store upload | **BLOCKED** | Debug signing key | Owner supplies `android/key.properties` |
| Physical-device test | **BLOCKED** | No device attached | Owner installs the APK on a phone |
