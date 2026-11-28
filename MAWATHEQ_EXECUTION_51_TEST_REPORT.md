# MAWATHEQ — Execution 51 Test Report

**Date:** 2026-10-24
**Commit:** `d41fe97d6b4b86fed0c724e02201fac53b8a92b1` (branch `exec43-home-slider`)
**App version:** `1.4.0+11`
**Environment:** PHP 8.4.26, MariaDB 11.8.6, Apache 2.4.68 (mod_php), Flutter 3.47.7,
Android SDK build-tools 35.0.0/37.0.0, JDK 21.

---

## 1. Flutter

| Check | Result |
|---|---|
| `flutter analyze` | **No issues found!** |
| `flutter test` | **251 / 251 passed** |
| Screenshot goldens (`capture_screenshots.dart`) | **13 / 13 passed**, no diffs |

The suite covers the Execution 43 slider, the two primary buttons, the journey,
navigation/startup, the dark theme, and the blank-screen sweep.

---

## 2. PHP suites (`bash tests/run_all_tests.sh`)

| Result | Count |
|---|---|
| Suites passed | **41** |
| Suites failed | **0** |

Full green after the environment fixes described in section 4. Highlights:

- `exec33_najiz_classification_test` — taxonomy import + reconciliation.
- `exec46_lawyer_visibility_test` — 14 checks, 0 failures.
- `execution29_*` probes — file security, mobile tokens, tenant isolation.
- `execution30_consultation_attachments_probe` — 131 checks, 0 failures.
- `executive08_http_probe` — 53 checks, 0 failures.
- `phase5_integration_http_probe`, `phase_firm_site_http_probe` — pass.

---

## 3. Taxonomy verification (real imported data)

Imported `database/MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql` into a scratch
database and read the live endpoints:

| Kind | Imported | Expected |
|---|---|---|
| categories | 7 | 7 |
| subcategories | 38 | 38 |
| case_types | 234 | 234 |
| judicial_requests | 640 | 640 |

Idempotency: a second import inserted nothing; counts stayed identical.

`GET /api/najiz_classify.php?action=stats` returns:

```json
{"ok":true,"stats":{"categories":7,"subcategories":38,"lawsuit_types":234,"judicial_requests":640}}
```

`GET /api/v1/journey.php?action=specialties` returns 7 specialties with Arabic
names, descriptions and icon keys — e.g. `الأحوال الشخصية`, `الدعاوى التجارية`,
`الدعاوى العمالية`, `الدعاوى الجزائية`, `الدعاوى العامة`, `دعاوى التنفيذ`,
`الدعاوى المتعلقة بالإنهاءات`.

---

## 4. Environment fixes found during verification

These were **not application defects**; they were sandbox/permission issues that
made the HTTP probes fail with HTTP 500.

1. **`config/secrets.php` was unreadable by Apache.** It was mode `600`, owner
   `openhands:openhands`, so `www-data` could not read it and every page that
   includes `config/config.php` returned 500 (`Failed opening required
   .../config/secrets.php`). Fixed to `640`, group `www-data`.

2. **`tests/exec39_najiz_graceful_test.php` replaced the file's inode.** The test
   rewrites `config/secrets.php` for its unmigrated-database check and restored it
   with `rename($backup, $secrets)`. `rename()` replaces the inode, so the file came
   back owned by the CLI user's group instead of `www-data`, and under a restrictive
   umask it also came back `0600`. Every page then 500'd for the rest of the run
   while the suite reported success. The restore now rewrites the file in place
   from memory, preserving owner, group and mode. Committed in `d41fe97`.

3. **The test database was empty of the taxonomy**, which is correct: the
   classification is filled by the importer, not by a migration. It was imported for
   the verification above.

---

## 5. Android artifacts

| Artifact | Size | Signature |
|---|---|---|
| `MAWATHEQ_APP_v1.4.0+11_release.apk` | 60,100,490 bytes | Production upload key |
| `MAWATHEQ_APP_v1.4.0+11_release.aab` | 58,547,812 bytes | Production upload key |

**Signer (APK):**

```
Signer #1 certificate DN: CN=Mawatheq Legal Platform, OU=Mobile, O=Mawatheq, L=Riyadh, ST=Riyadh, C=SA
Signer #1 certificate SHA-256 digest: 5721bf0cfdd9f6fcef5dc3baf86b21c9ec2656c2ad4fb06a3cda189a355ecd09
Signer #1 certificate SHA-1 digest:   f18a51efadc19c7c835c135f7e14a5637ff32ec6
```

**Manifest (`aapt2 dump badging`):**

```
package: name='com.mawatheq.app' versionCode='11' versionName='1.4.0'
minSdkVersion:'24'    (Android 7.0)
targetSdkVersion:'36'
compileSdkVersion='37'
native-code: 'arm64-v8a' 'armeabi-v7a' 'x86_64'
```

The APK is signed with the **production upload key**, not the debug key. This is the
first Google Play release; the upload key is generated locally and its private key is
not uploaded anywhere.

---

## 6. UI verification (screenshots)

The 13 goldens in `apps/mobile/tool/screenshots/goldens/` were regenerated and
matched. Relevant frames:

- `01_guest_home.png`, `02_signed_in_home.png`, `03_signed_in_home_dark.png` —
  slider, both buttons, specialties.
- `10_guest_home_fresh_slider.png` — slider on a fresh install (built-in slides).
- `11_guest_home_imported_taxonomy.png` — home with the imported taxonomy.
- `05`–`07` — journey steps; `12`, `13` — the honest "no lawyer yet" states.

---

## 7. Production status (checked read-only, 2026-10-24)

A read-only probe of the live site shows the taxonomy is **already present in
production**:

```
GET https://mawatheq.com/api/najiz_classify.php?action=stats
{"ok":true,"stats":{"categories":7,"subcategories":38,"lawsuit_types":234,"judicial_requests":640}}
```

`GET /api/v1/journey.php?action=specialties` returns 7 specialties, and
`/api/v1/banners.php` returns 200. These three endpoints were 404/500 during
Execution 39, so the deployment gap recorded there has since been closed.

No write command was run against the production database in this execution. The
SQL import file ships as a reference and a repair tool for a new host or a restored
backup; it is idempotent, so re-running it on the populated production database
changes nothing.

## 8. What this execution did NOT verify

The Flutter tests, goldens and the blank-screen sweep run against a fake transport.
They prove the screens build, render and handle canned JSON. They do **not** prove a
live booking, a production login, a real document upload or a real AI call.
