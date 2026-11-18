# MAWATHEQ — Execution 50 Test Report

**Date:** 2026-10-23
**Scope:** lawyer-visibility root cause, the shared lawyer-availability empty state,
the specialty backfill SQL, and the `1.3.6+10` release build.

---

## 1. Summary

| Area | Result |
|---|---|
| Flutter analyzer | **No issues found** |
| Flutter test suite | **251 / 251 passed** |
| Screenshot harness | **13 / 13 rendered** (2 new for the empty state) |
| `exec46_lawyer_visibility_test.php` | **14 / 14 passed** |
| Backfill SQL, local replay | **verified** (0 → 1 lawyer; idempotent) |
| Taxonomy import SQL, scratch DB | **7 / 38 / 234 / 640** — exact |
| APK build | **built** — versionCode 10, versionName 1.3.6, minSdk 24 |

---

## 2. The defect and its root cause

The owner reported «لا يوجد محامٍ متاح» with a real, approved lawyer present.

Probing production (`https://mawatheq.com`) and a local database with the same data:

| Request | Production | Local (current code) |
|---|---|---|
| `GET /api/v1/lawyers.php?category_id=4` | `{"lawyers":[]}` | 1 lawyer |
| `GET /api/v1/journey.php?action=lawyers&category_id=4` | `matched:false` | `matched:true` |

The seven category names on production are byte-identical to the ones in the code
(verified per-name in UTF-8), so this is not a data mismatch and not a lawyer-status
problem — the lawyer is `approved`.

**Root cause:** production runs an `includes/legal_journey.php` older than commit
`d1de2ca`. The old `legal_journey_lawyers_for_specialty()` joins
`lawyer_specialties` only; the current version falls back to the lawyer's declared
`specialization`. The production lawyer has no `lawyer_specialties` row, so the old
code cannot see him.

**Fix:** publish `includes/legal_journey.php` (preferred), or run the backfill SQL
to write the missing links so the old code sees him.

---

## 3. Local verification of the backfill SQL

A local database was prepared to match production's *broken* state: one approved
lawyer with a declared specialization (`قانون جنائي`) and an empty
`lawyer_specialties` table.

**Before** — the old code path computes the same zero production shows:

```
1  الأحوال الشخصية                    0
2  الدعاوى التجارية                   0
3  الدعاوى العمالية                   0
4  الدعاوى الجزائية                   0      <-- production shows 0 here
5  الدعاوى العامة                     0
6  دعاوى التنفيذ                      0
7  الدعاوى المتعلقة بالإنهاءات        0
```

**After** `database/MAWATHEQ_LAWYER_SPECIALTY_BACKFILL.sql`:

```
4  الدعاوى الجزائية                   1      <-- the lawyer is now visible
```

Re-running the file a second time leaves the count at 1 — `INSERT IGNORE` plus the
unique key `(lawyer_id, category_id)` make it idempotent, and no manual link is
ever replaced.

---

## 4. The taxonomy import SQL

`database/MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql` was imported into a scratch
database (fresh `schema.sql` + all upgrades) and the row counts read back:

| Table | Rows | Expected |
|---|---|---|
| `najiz_categories` | 7 | 7 |
| `najiz_subcategories` | 38 | 38 |
| `najiz_case_types` | 234 | 234 |
| `najiz_judicial_requests` | 640 | 640 |

Exact match. SHA-256 of the file:
`10b09093e2ab3f4242efef5e00bb9105ddbf86bcd83df3d755b76816f1468134`.

---

## 5. Live end-to-end flow (local server, real taxonomy)

With the local server running and the taxonomy imported:

```
GET /api/v1/journey.php?action=specialties
  → 7 specialties, each with icon_key and lawyer_count; ready:true
GET /api/v1/journey.php?action=issue-types&category_id=4
  → 18 issue types (المنع من السفر, إثبات تنازل, تسليم مضبوطات, ...)
GET /api/v1/journey.php?action=lawyers&category_id=4
  → matched:true, 1 lawyer
```

The journey, the specialty list and the lawyer match all work against the real
taxonomy, not fixtures.

---

## 6. The app-side fix (shared empty state)

The empty state is one shared widget (`NoLawyerState`) used by both surfaces that
can reach it, so the two copies cannot drift again:

- `lib/shared/widgets/brand_widgets.dart` — the widget: one message
  (`لا يوجد محامي متاح`) and exactly two working buttons (the AI assistant and
  contact). It does not echo the server's `alternative` object or show a second
  paragraph.
- `lib/features/consultations/journey_screen.dart` — the lawyer step uses it; the
  private duplicate class was removed.
- `lib/features/public/public_lawyers_screen.dart` — the directory empty state uses
  it, with real `_openAssistant` / `_openContact` destinations.

`test/exec50_lawyer_empty_state_test.dart` — 10 checks: both screens show only the
message plus two buttons, and both buttons open real screens.

---

## 7. Screenshots

The harness asserts each screen's text **before** capturing, so a blank screen
fails the test instead of producing a useless image. Thirteen screens render:

`01`–`09` as before, plus:

- `10_guest_home_fresh_slider.png` — the built-in promotional slider on a fresh install
- `11_guest_home_imported_taxonomy.png` — the slider plus the seven imported specialties
- `12_journey_no_lawyer.png` — the fixed empty state in the journey
- `13_directory_no_lawyer.png` — the fixed empty state in the directory

---

## 8. The release APK

| Property | Value |
|---|---|
| File | `MAWATHEQ_APP_v1.3.6+10_release.apk` |
| Package | `com.mawatheq.app` |
| versionCode | 10 |
| versionName | 1.3.6 |
| minSdk | 24 (Android 7.0) |
| targetSdk | 36 |
| compileSdk | 37 |
| Size | 60,096,394 bytes |
| Signature | v2, `CN=Android Debug` |

**The APK is debug-signed.** There is no `android/key.properties` in the
repository, so `build.gradle.kts` falls back to the debug keystore. That signature
is fine for local installation and testing but **cannot go to Google Play**, and
Android App Links will not verify against it. A Play upload needs the owner's
upload key; see `android/key.properties` (gitignored).

No AAB was built, as instructed.

---

## 9. What these tests do NOT prove

The widget suite, the screenshot harness and the golden images all run against a
fake HTTP transport that serves the documented JSON shapes. They prove the screens
build, render, and handle the real response shapes. They prove nothing about:

- a live booking against production,
- a production login,
- a real document upload,
- a live AI call.

Those remain untested here and are listed as such deliberately.

---

## 10. Pending — production

> No production database was modified and no production code was deployed in this
> execution. The SQL and the code change are ready; applying them is the owner's
> decision. See `MAWATHEQ_EXECUTION_50_LAWYER_VISIBILITY_AR.md`.
