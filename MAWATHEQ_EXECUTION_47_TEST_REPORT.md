# MAWATHEQ — Execution 47 test report

**Date:** 2026-10-23
**Scope:** splash-screen hang (the reported blocker), plus re-verification of the
home slider, the two primary actions, the legal specialties and the taxonomy SQL.

Everything below was run in this session. Where a claim could not be verified, it
says so rather than implying a pass.

---

## 1. The defect: the app could sit on the splash screen

### Symptom

The owner reported the app hanging on the splash screen.

### Root cause

`MawatheqApp` shows the splash while `_restored` is false:

```dart
bool get _showSplash => !_restored || !_splashDone;
```

`_restored` is set only after `AuthController.restore()` returns, and `restore()`
called `_repo.me()` → `GET /api/v1/me.php` with **no budget of its own**. That
request inherits `ApiClient`'s 30-second timeout.

A network that completes the TCP handshake and then never answers — a captive
portal, a hotel/airport Wi-Fi login wall, or a server under load — therefore held
the user on the logo for the full 30 seconds. On a slower device that is
indistinguishable from a hang, and if the socket never times out at the OS level
it is a genuine hang.

The existing startup tests could not catch it: every one of them uses a fake
transport that resolves on the next microtask, so `restore()` always completed
before the splash could be observed.

### Fix — three layers, innermost first

| Layer | File | Change |
|---|---|---|
| 1. Bound the session check | `lib/core/auth_controller.dart` | `restore()` awaits `_repo.me().timeout(AppConfig.startupTimeout)` (5 s). A timeout lands in the existing catch, which **keeps** the stored token — an unverified session is not a revoked one, so the next launch can still restore it. |
| 2. Name the two budgets | `lib/core/config/app_config.dart` | Added `startupTimeout` (5 s) and `splashCeiling` (8 s), each with the reason it differs from the interactive 30 s `timeout`. |
| 3. Hard ceiling | `lib/app.dart` | An 8 s `Timer` marks the check done regardless, cancelled the moment `restore()` resolves. A future startup call that forgets its own timeout cannot reintroduce the hang. |

The token is deliberately **not** cleared on a timeout. Clearing it would sign a
user out for a temporary network problem; the flag `sessionExpired` stays down so
the welcome screen does not claim the session expired when it merely could not be
reached.

### Regression test

`apps/mobile/test/exec47_startup_hang_test.dart` — 4 checks, all pass.

It drives the **real** `MawatheqApp` widget through the provider set `main.dart`
supplies (`ApiRepository`, `TokenStore`, `AuthController`, `GuestSession`,
`LocaleController`), with a transport that stalls `/me.php` and answers everything
else. The provider set matters: the older startup tests omit `ApiRepository`,
which `HomeShell` reads, so they were not exercising the shipping tree.

| Check | Asserts |
|---|---|
| a stalled `/me.php` does not hold the splash past its budget | With a stored token and a dead network, the app reaches `HomeShell` by 6 s with no user action. |
| a first launch with no stored token never waits on the network | No token → no request → the splash is bounded by the animation alone. |
| a healthy startup still lands on the shell | The fix does not break the normal path. |
| `restore()` itself is bounded, not only the widget | `restore()` returns in under 20 s and leaves `AuthStatus.checking`. |

Confirmed failing before the fix (all four timed out) and passing after.

---

## 2. Full Flutter suite

```
flutter analyze     → No issues found!
flutter test        → 228 tests, all passed
```

228 = the 224 tests present before this execution plus the 4 new startup checks.
No regressions.

---

## 3. The home slider — re-verified

`apps/mobile/test/exec43_home_slider_test.dart` — **16 checks, all pass.** It
covers exactly the four properties the brief calls out, and each is a real
assertion rather than a smoke test:

| Requirement | Test | How it is proven |
|---|---|---|
| One banner at a time | a fresh install still renders the carousel | `find.byType(PageView)`; the built-in slide list has more than one entry |
| Automatic transitions every 4–5 s | advances to the next slide after the dwell interval | Clock advanced 4.6 s + 0.6 s; `controller.page` becomes 1 and slide two's **unique** headline is found while slide one's is gone |
| Manual horizontal swiping | a manual swipe moves the slider too | A rightward fling in RTL advances to slide 2 |
| Correct Arabic RTL | the carousel lays out in RTL for Arabic; Arabic copy is anchored to the RIGHT edge; every built-in slide keeps its copy inside the slide frame | Layout geometry, not just text presence |

Also asserted: every built-in slide's photo is a **bundled asset** that really
exists (`isNotEmpty`, > 1000 bytes), so a slide cannot silently degrade to a flat
gradient; operator banners replace the built-in slides.

### Visual confirmation

`tool/screenshots/capture_screenshots.dart` renders 11 screens at 1080×2280 and
asserts each screen's text **before** capturing, so a blank screen fails rather
than producing a useless golden.

The banner band of `11_guest_home_imported_taxonomy.png` contains **63,955
distinct colours, 63,953 of them rare** — the signature of a photographic
background, not a flat rectangle. A two-card layout produces a handful of
colours; this is three orders of magnitude more.

---

## 4. The two primary actions — re-verified

Both buttons are drawn **inside each slide**, not in a row beneath the carousel.
That placement is the fix for "two static cards instead of a slider": a pair of
cards under the slider is what read as static content.

| Check | Result |
|---|---|
| both buttons present and labelled inside the banner | `احجز استشارة` and `اسأل المساعد الذكي` found inside `BannerCarousel` |
| no leftover `ActionCard` row | `find.byType(ActionCard)` finds nothing |
| the booking button opens the real journey | Tapping it pushes `ConsultationJourneyScreen` |
| the assistant button opens the real assistant | Tapping it pushes `AssistantScreen` |
| a guest is asked to sign in and the destination is remembered | The intended destination survives the auth round-trip |

Below the carousel, `اختر تخصصك القانوني` heads the legal specialties, which are
loaded from `journey.php?action=specialties`.

---

## 5. Legal specialties — verified against a real database

The Exec 46 fix makes an approved lawyer discoverable through their **declared**
`lawyers.specialization` when they have no explicit `lawyer_specialties` row,
mapped through `legal_journey_specialization_categories()`.

Verified end to end through the **live HTTP API** on a local database seeded with
one approved lawyer (`specialization = 'قانون جنائي'`) and the full taxonomy:

```
GET /api/v1/journey.php?action=specialties
  → 4 'الدعاوى الجزائية' count=1     ← the lawyer is counted
  → 1,2,3,5,6,7            count=0

GET /api/v1/journey.php?action=lawyers&category_id=4
  → matched: true, n: 1
  → 1 محامي اختبار 47
```

The count a customer sees (1) and the list they get (1) agree — they are derived
from the same index, which is the property that makes "0 lawyers" and an empty
list consistent rather than contradictory.

`tests/exec46_lawyer_visibility_test.php` — 11 checks, 0 failures.

### Production

Production currently has **one** approved lawyer (`thaer aljallad`,
`قانون جنائي`). The Exec 46 fix is server-side PHP and is **not yet deployed**,
so production still reports `lawyer_count: 0` for every specialty. Once the
website files are deployed, that lawyer becomes discoverable under
`الدعاوى الجزائية` with no operator action.

---

## 6. Full PHP suite

```
bash tests/run_all_tests.sh  →  41 suites passed, 0 failed
```

Includes the HTTP probes (`executive08_http_probe`, `phase5_integration_http_probe`,
`phase_firm_site_http_probe`, the four `execution29_*` probes,
`execution30_consultation_attachments_probe`) against Apache on
`127.0.0.1:12000` and `127.0.0.1:8080`, with the mock AI provider up on
`127.0.0.1:11434`.

Environment rebuilt from scratch this session via `tests/setup_test_env.sh` after
the sandbox reset removed PHP, MariaDB and the Apache vhosts.

---

## 7. The taxonomy SQL — verified by importing it

The exact file is
`database/MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql`
(292,050 bytes, SHA-256 `10b09093e2ab3f4242efef5e00bb9105ddbf86bcd83df3d755b76816f1468134`).

Verified by applying it to a scratch database built the same way as production
(`schema.sql` then all 63 upgrades in **numeric** order), then counting rows:

| Level | Expected | Actual |
|---|---|---|
| Main categories | 7 | **7** |
| Subcategories | 38 | **38** |
| Lawsuit types | 234 | **234** |
| Judicial requests | 640 | **640** |

Idempotency confirmed: the second run inserted nothing and left every count
unchanged. All 7 `customer_desc_ar` values are filled, so the journey cards render
with descriptions without needing `upgrade_v63.sql` to run a second time.

### A trap worth recording

The first scratch database was built from the upgrades **without** `schema.sql`,
and `upgrade_v63.sql` died with
`errno: 150 "Foreign key constraint is incorrectly formed"` because
`lawyer_specialties` references `lawyers`, which did not exist. MySQL stops a file
at the first error, so the two `najiz_categories` columns below that statement
were never added — and the taxonomy import then correctly refused with
`PLEASE RUN MAWATHEQ_DB_SCHEMA_FIX.sql FIRST`.

That is the documented partial-schema failure mode: the tables exist, so nothing
recreates them, and the symptom looks like a bug in the import. Always build a
scratch database as `schema.sql` **then** the upgrades.

### Production status

Production **already has** the full taxonomy — verified live:

```
GET https://mawatheq.com/api/najiz_classify.php?action=stats
→ {"categories":7,"subcategories":38,"lawsuit_types":234,"judicial_requests":640}
```

So this file is a **disaster-recovery artifact**, not a pending import. Importing
it into production is a separate, owner-authorized step and was deliberately not
performed. Instructions are in `MAWATHEQ_EXECUTION_47_SQL_IMPORT_AR.md`.

---

## 8. What was NOT verified — stated plainly

- **No physical device or emulator.** The sandbox has no emulator package, no AVD,
  no system image and no KVM. Every UI claim above is from widget tests and
  rendered goldens at 1080×2280, not from a running phone. The splash fix in
  particular is proven by driving the real widget tree with a stalled transport —
  which reproduces the mechanism, but is not the same as holding a phone with no
  signal.
- **The slider's auto-advance on a real device.** The timer logic is asserted by
  advancing the test clock; the visual smoothness of the transition is not.
- **The release APK signature.** No `android/key.properties` exists in the repo, so
  the build falls back to the debug keystore. The APK installs and runs, but it is
  **not Play-uploadable** and Android App Links will not verify against it. The
  owner's upload key is required.
- **Production's website files.** The Exec 46 lawyer-visibility fix is not deployed,
  so production still shows `lawyer_count: 0`. Deploying it is a separate step.
- **No production database was modified.** By instruction.

---

## 9. Totals

| Suite | Result |
|---|---|
| `flutter analyze` | clean |
| `flutter test` | **228 passed** |
| `exec47_startup_hang_test.dart` | 4 passed (new) |
| `exec43_home_slider_test.dart` | 16 passed |
| `exec46_lawyer_visibility_test.php` | 11 checks, 0 failures |
| `bash tests/run_all_tests.sh` | **41 suites, 0 failed** |
| Taxonomy import, scratch DB | 7 / 38 / 234 / 640, idempotent |
