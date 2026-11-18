# MAWATHEQ — Execution 46 test report

**Date:** 2026-10-23
**Branch:** `exec43-home-slider` @ `d1de2ca`
**App version:** `1.3.2+6` (versionCode 6, versionName 1.3.2)
**APK:** `MAWATHEQ_APP_v1.3.2+6_release.apk`
**APK SHA-256:** `ffb1d449088c2945c222130841b13ca52ba266a15ab7df50597091b39958f1af`

---

## 1. What was wrong, and what fixed it

### 1.1 Approved lawyers were invisible to the customer journey (the root cause)

**Symptom.** Every specialty reported `lawyer_count: 0`, and the journey told the
customer no lawyer was available, while the public lawyer directory listed the same
lawyer. Two surfaces disagreed about the same database.

**Confirmed against production** (`https://mawatheq.com`, 2026-10-23):

```
GET /api/v1/journey.php?action=specialties
  → 200, seven specialties, every one "lawyer_count": 0

GET /api/v1/lawyers.php
  → 200, {"id":6,"full_name":"thaer aljallad","specialization":"قانون جنائي", ...}
```

A real approved lawyer with a declared specialty, and zero lawyers found for any
specialty. Both statements come from the same database.

**Cause.** The journey required an explicit row in `lawyer_specialties`. That table is
created **empty on purpose** by `upgrade_v63.sql` — linking a lawyer to an expertise is
an operator action, and inventing one would route a real customer to a lawyer who
cannot help. So the requirement made every lawyer invisible by default.

**Fix.** `legal_journey_lawyer_category_index()` in `includes/legal_journey.php` builds
one index of lawyer → categories. A lawyer's categories are their explicit
`lawyer_specialties` rows when any exist; only a lawyer with none falls back to their
own declared `lawyers.specialization`, mapped through
`legal_journey_specialization_categories()`. An explicit link always wins. A
specialization outside the controlled list contributes nothing — silence, never a guess.
Both readers (the specialty count and the lawyer lookup) derive from this one index, so
they cannot disagree.

### 1.2 The no-lawyer empty state leaked a raw server map

`api/v1/journey.php` sends `alternative` as an **object** (`reason`, `suggest`,
`specialties`). The client read it as a string, so the customer saw
`{reason: ..., suggest: ..., specialties: [...]}`. The model now parses it into typed
fields, and the screen shows one plain message plus two real routes out
(المساعد الذكي / تواصل معنا) — no server diagnostic, no icon clutter.

### 1.3 `_MetaChip` overflowed its `Wrap`

A real `RenderFlex overflowed by 4.0 pixels` at 110px width, caught by the widget tests.
The chip's text now ellipsizes.

### 1.4 The release build could never pass its own guard

`tools/build_release.sh` normalized modes with `chmod 755`, but **`chmod 755` does not
clear a directory's setgid bit**. A source tree whose directories are `2755` — which a
setgid parent such as the checked-out `vendor/` leaves behind — produced `2755` in the
package, and the `every packaged file is 644 or 755` guard could never be satisfied. The
build now clears setuid/setgid/sticky first.

---

## 2. Verification performed

### 2.1 PHP suites — 41 / 41 pass, 0 failures

`bash tests/run_all_tests.sh` (run as root; the DB user requires it):

```
suites passed: 41   failed: 0
all suites passed
```

The two suites that failed at the start of this execution now pass:

| Suite | Before | After |
|---|---|---|
| `exec33_najiz_classification_test` | FAIL — "import runs recorded" | PASS |
| `execution29_1_release_smoke_test` | FAIL — package guard | PASS — 61 checks |

`exec33` failed because a manual SQL import had left `najiz_import_runs` with one row
while the suite asserts `>= 2`; the suite imports its own taxonomy on a clean database.
`execution29_1_release_smoke_test` failed on the setgid bug above.

**New suite:** `tests/exec46_lawyer_visibility_test.php` — 11 checks, 0 failures,
re-runnable, cleans up its own rows (verified: 0 residual `Exec46%` lawyers after two
runs).

```
1. Declared specialization → discoverable without an explicit link   (5 checks)
2. An explicit lawyer_specialties link wins over the declared value  (2 checks)
3. An unmapped specialization contributes nothing                     (1 check)
4. Only approved lawyers are matched                                  (1 check)
5. The specialization map resolves against the live category names    (2 checks)
```

### 2.2 Flutter — 224 tests pass, `flutter analyze` clean

```
$ flutter analyze
No issues found! (ran in 11.9s)

$ flutter test
00:20 +224: All tests passed!
```

The Exec43 slider suite (`test/exec43_home_slider_test.dart`) covers, against the real
widgets:

* a fresh install with no operator banners still renders the carousel;
* advances to the next slide after the dwell interval;
* a manual swipe moves the slider too;
* both buttons are present, labelled, and open the real destinations;
* the carousel lays out in RTL and the Arabic copy is anchored to the right edge;
* every built-in slide keeps its copy inside the slide frame;
* the built-in slider renders in the dark theme;
* the seven real categories show when the backend returns none.

### 2.3 Golden screenshots

Regenerated at 1080x2280 (`flutter test tool/screenshots/capture_screenshots.dart
--update-goldens`). The harness asserts each screen's text **before** capturing, so a
blank screen fails rather than producing a green test and a useless image.

| File | Shows |
|---|---|
| `01_guest_home.png` | guest home, slider + buttons |
| `10_guest_home_fresh_slider.png` | fresh install: carousel, احجز استشارة, اسأل المساعد الذكي, اختر تخصصك القانوني |
| `11_guest_home_imported_taxonomy.png` | same, plus real categories (الدعاوى العمالية، دعاوى التنفيذ، ...) |
| `02`/`03_signed_in_home*.png` | signed-in home, light and dark |
| `05`–`07_journey_*.png` | specialty → issue type → lawyer card |
| `08_lawyer_directory.png` | public directory |
| `04`, `09` | account tab, consultations |

### 2.4 APK

```
$ aapt2 dump badging app-release.apk
package: name='com.mawatheq.app' versionCode='6' versionName='1.3.2'
minSdkVersion:'24'   targetSdkVersion:'36'   compileSdkVersion='37'
application-label:'مواثيق'
native-code: 'arm64-v8a' 'armeabi-v7a' 'x86_64'

$ apksigner verify --print-certs
Signer #1 certificate DN: CN=Android Debug
```

`flutter build apk --release` → `✓ Built app-release.apk (60.1MB)`, 80.2s.

---

## 3. Google Play readiness

| Item | State |
|---|---|
| Application ID | `com.mawatheq.app` — fixed, matches `assetlinks.json` |
| App name | مواثيق |
| Version | 1.3.2+6 (incremented this execution) |
| minSdk / targetSdk | 24 (Android 7.0) / 36 |
| Cleartext traffic | `usesCleartextTraffic="false"`; API base is HTTPS |
| Debug logging | none — no `print`/`debugPrint` in `lib/` |
| Permissions | all justified: INTERNET, ACCESS_NETWORK_STATE, RECORD_AUDIO, CAMERA, POST_NOTIFICATIONS, SCHEDULE_EXACT_ALARM, RECEIVE_BOOT_COMPLETED |
| Signing | **debug key** — see the blocker below |

**Blocker for Play upload:** the APK is signed with the debug keystore
(`CN=Android Debug`), v2 scheme only. This is correct and expected on a fresh checkout —
`android/key.properties` is gitignored and the owner has not supplied the upload key.
A Play upload requires that key. Android App Links also cannot verify against a debug
signature.

**Not built, as instructed:** no AAB.

---

## 4. Production status

Production was **inspected, not modified** — no production database write was performed.

| Check | Result |
|---|---|
| `journey.php?action=specialties` | 200, seven specialties with names and descriptions |
| `journey.php?action=issue-types&category_id=4` | 200, real judicial requests |
| `journey.php?action=lawyers&category_id=1` | 200, `matched:false`, `alternative` is an object with `reason`/`suggest`/`specialties` |
| `lawyers.php` | 200, approved lawyer id 6 |
| `banners.php` | 200, `banners: []` — the built-in slider carries the carousel |
| `najiz_classify.php` | 404 — not deployed; the app does not call it |

**The taxonomy is already on production** (7 / 38 / 234 / 640 verified). The SQL import
file is therefore a recovery tool, not a pending migration. The one live gap is the
lawyer-visibility fix, which is server-side PHP and ships with the website package.

---

## 5. What was not tested

Stated plainly, so a green suite is not mistaken for a live proof:

* The APK was **not installed on a physical device**. Rendering, carousel motion and
  touch behaviour are proven by widget tests and golden images, not on hardware.
* **No live booking, production login, document upload or AI call** was performed. The
  tests run against a fake transport and a local database.
* The lawyer-visibility fix was verified against the **local** database with real
  imported taxonomy, and the bug was confirmed on production by API inspection — but the
  fix itself has not been deployed to production.
* Signing with the owner's upload key, and Play App Links verification, remain
  unverified because the key is not available in this environment.
