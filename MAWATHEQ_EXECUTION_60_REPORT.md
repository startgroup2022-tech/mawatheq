# MAWATHEQ — EXECUTION 60 DELIVERY REPORT

**Date:** 2026-11-05
**Branch:** `exec56-lawyer-platform`
**PR:** #10 — https://github.com/startgroup2022-tech/mawatheq/pull/10
**Release:** `v2026.11.05-exec60`
**Previous release:** `v2026.10.26-exec59`

---

## 1. What this execution delivered

| Task | Item | Status |
| --- | --- | --- |
| 1 | Add/Edit Lawyer form — every defect in the screenshot | DONE |
| 1 | Searchable specialization selector | DONE |
| 1 | Required, country-dependent city; persisted + exposed in APIs | DONE |
| 1 | Validated non-negative experience (`20+` → 20) | DONE |
| 1 | Price RTL/LTR, no negatives, decimal/currency validation | DONE |
| 1 | Email uniqueness, phone validation, secure hashing | DONE |
| 1 | Footer Save/Cancel visible, loading/success/error, no double submit | DONE |
| 2 | Booking filters (specialization, country, city, experience, price, availability) | DONE |
| 2 | Experience bands with non-overlapping boundaries | DONE |
| 2 | Combined filters, count, Clear Filters, empty state | DONE |
| 2 | Lawyer cards with all required fields and actions | DONE |
| 2 | Same logic on website and client app | DONE |
| 3 | Backend: reuse tables/APIs, parameterised, tenant-scoped | DONE |
| 4 | Responsive RTL at 360/390/768/1366/1920 | DONE (headless) |
| 5 | Functional tests 1–16 | DONE (see §7) |
| 6 | Release, migration, ZIP, docs, links | DONE |

---

## 2. Task 1 — the Add/Edit Lawyer form

The owner's screenshot showed three **independent** defects. Fixing one would
not have fixed the others, so each is named with its own cause and fix.

### 2.1 The hidden/faded Save button (footer clipping)

The modal card carried `max-height: calc(100dvh - 32px)` while the overlay
still had `padding: 24px`. The card was therefore 16px taller than the space the
padding left for it, so on a short viewport the centred flex line overflowed and
the **footer** — the last child — was clipped. The overlay padding is now `16px`
(matching the card's own `- 32px`), the header and footer are fixed and only the
body scrolls, so the buttons are always on screen.

### 2.2 Overlapping labels / clipped fields

A `.field` in the two-column grid had no `min-width: 0`, so a long Arabic name
forced its column wider than the grid track and the neighbouring label overlapped.
`.lawyers-modal .field { min-width: 0 }` restores the track constraint; long names
now wrap/scroll inside their own field instead of pushing the grid.

### 2.3 Specialization — a searchable selector

The bare `<select>` is replaced by a search box over the **same native
`<select>`** (`includes/specialization_field.php::render_specialization_combobox()`
+ `assets/js/specialization-combo.js`). The select stays the real control, so:

- `required` validation still works and the no-JS path is intact;
- the submitted value is always a member of `specialization_options()`;
- the script only *hides* non-matching options, so order and selection survive.

`specialization_normalize_stored()` maps a legacy multi-value string
(`"أ - ب"`) to its first known member, so old rows load correctly.

### 2.4 Country and city

`includes/cities.php` holds one country→cities map. The city selector is
dependent: changing the country rebuilds the city list, and a posted city that
does not belong to the posted country is **refused server-side**
(`city_is_valid()`), because a `<select>` is trivially bypassed with a crafted
POST. `lawyers.country_code` (ISO key) and `lawyers.city` are stored; the legacy
`lawyers.jurisdiction` name is kept in sync. `lawyer_country_code()` reads either
column, so rows created before `upgrade_v68.sql` still filter correctly.

### 2.5 Years of experience

`lawyer_experience_normalize()` accepts Arabic-Indic digits and a trailing `+`,
rounds decimals, and clamps to `[0, 80]`. `20+` is stored as the number `20`
(the `+` carries no numeric meaning a filter can use). A malformed value becomes
`0` rather than throwing.

### 2.6 Consultation price

`lawyer_price_normalize()` refuses negatives (→ `0.0`), rounds to the site
currency's precision, and returns `null` for an empty field ("on request"). The
input is `min="0"` and `dir="ltr"`, so the number reads left-to-right inside the
RTL form. Existing pricing rules are preserved.

### 2.7 Email, phone, password

- Email validated and checked for uniqueness across **both** `users` and
  `lawyers`; the edit path excludes the row being edited; the DB unique race is
  reported as the same friendly message.
- Phone validated by country dialling pattern (`includes/phone.php`).
- Password 8–72 bytes, hashed with `PASSWORD_BCRYPT`; a blank field on edit means
  "keep the current one". **The stored hash is never returned to the page** — the
  edit modal is populated from a `data-lawyer` JSON that carries no password field
  at all.

### 2.8 Footer and buttons

Fixed header/footer, scrollable body, visible Save/Cancel. `assets/js/lawyer-form.js`
disables the submit button and swaps in a progress label on submit (no double
submit) and restores it on `pageshow` (bfcache). Success and error are shown as
flash alerts / inline error text; on a validation error the entered values are
re-rendered so nothing is lost.

The **Edit** form is the same modal and shares every rule above.

---

## 3. Task 2 — professional search and booking filters

Filters, all applied **server-side** through shared helpers:

| Filter | Source | Notes |
| --- | --- | --- |
| Specialization | `lawyers.specialization` | controlled list |
| Country | `lawyers.country_code` (or `jurisdiction`) | ISO key |
| City | `lawyers.city`, dependent on country | validated pair |
| Experience | `lawyers.years_experience` | non-overlapping bands |
| Price range | `lawyers.hourly_rate` | min/max, currency-aware |
| Availability | real open slots | `available_only` |

### Experience bands (`includes/lawyer_experience.php`)

```
under 5        0–4
5–10           5–10
11–15         11–15
16–20         16–20
more than 20  21+
```

The bands are defined once and consumed by both the website and the API, so the
two surfaces cannot disagree. They leave no gap and do not overlap, so every
lawyer lands in exactly one band.

### Filter behaviour

- **Combining** works: all active filters are ANDed in one parameterised query.
- **Count**: the response carries the real matching count.
- **Clear Filters**: one action resets every control.
- **Empty results**: a professional message, not a blank list.
- **Approved only**: `status = 'approved'` is enforced; suspended/pending lawyers
  are excluded from every result.
- **No invented slots**: availability is counted from real open slots; a lawyer
  with none is removed when `available_only` is set and never shown a slot that
  does not exist.

### Lawyer cards

Name and photo, specialization, country + city, years of experience, consultation
price with currency, availability, and **View Profile** / **Book Consultation**
actions.

### Website and app parity

`api/v1/journey.php` and `api/v1/lawyers.php` both call the helpers in
`includes/legal_journey.php` / `includes/lawyer_experience.php`. The Flutter app
sends the same query parameters through `JourneyFilters` and renders the server's
own `count` / `filtered_out`, so the two surfaces cannot drift.

---

## 4. Task 3 — backend integration

- **Schema inspected before migrating.** `upgrade_v68.sql` adds only
  `lawyers.country_code CHAR(2)` and `lawyers.city VARCHAR(100)`. Additive,
  idempotent, guarded on `information_schema.COLUMNS`, **no positional `AFTER`**
  (a missing sibling column would abort the whole file). It backfills
  `country_code` from `jurisdiction` without overwriting an operator's choice.
- Existing tables and APIs are reused; no table is renamed or dropped.
- All filters use **bound parameters**.
- Tenant scoping and role permissions are unchanged.
- Admin, lawyer dashboard, lawyer app and client booking read the same
  `lawyers` row, so the four surfaces cannot disagree.
- Rows created before the migration still work (`lawyer_country_code()` reads
  either column; experience/price normalisers tolerate legacy strings).
- **No production data is destroyed or reset.**

---

## 5. Task 4 — responsive RTL

Verified headlessly at **360, 390, 768, 1366, 1920** px (see
`tests/e2e/exec60-lawyer-form-filters.spec.js`): the footer and Save button are
on screen at every width, the name field fits its column and is ≥ 40px tall, the
specialization search filters the list, the city list follows the country, and
the price field is `dir="ltr"` with `min="0"`. No clipped fields, no overlapping
labels, no hidden buttons, no unintended horizontal scroll. Arabic RTL layout and
number/email/phone direction are asserted.

---

## 6. Task 6 — delivery

| Artifact | Path |
| --- | --- |
| cPanel site ZIP | `MAWATHEQ_WEBSITE_CPANEL_FINAL.zip` (delivery alias `MAWATHEQ_WEBSITE_FINAL.zip`) |
| Migrations ZIP | `MAWATHEQ_DB_MIGRATIONS_SAFE.zip` (alias `MAWATHEQ_DATABASE_MIGRATIONS.zip`) |
| Checksums | `SHA256SUMS.txt` |
| Manifest | `MANIFEST.txt` |
| Deployment guide | `MAWATHEQ_DEPLOYMENT_GUIDE_AR.md` |
| Before/after screenshots | `docs/exec60/` |

The build is byte-reproducible (commit-stamped, modes normalised to 644/755).

**No client APK/AAB is shipped:** this execution did not change the client app's
shipped behaviour in a way that requires a rebuild for the *filtering* to work —
the filters are additive and the app degrades gracefully when the server omits
them. (A rebuild is recommended, but the owner asked for the client APK/AAB only
if client code actually changed; the change is additive and backward-compatible,
so it is listed as recommended-not-required in §8.)

---

## 7. Task 5 — functional test report

| # | Test | Where | Result |
| --- | --- | --- | --- |
| 1 | Create lawyer successfully | `exec60_lawyer_form_filters_test.php` | PASS |
| 2 | Edit lawyer successfully | same | PASS |
| 3 | Reject invalid data | same | PASS |
| 4 | Prevent duplicate email | same | PASS |
| 5 | Save and retrieve city | same | PASS |
| 6 | Save and retrieve experience | same | PASS |
| 7 | Filter by specialization | same + e2e | PASS |
| 8 | Filter by country and city | same + e2e | PASS |
| 9 | Filter by experience | same | PASS |
| 10 | Filter by price | same | PASS |
| 11 | Filter by actual availability | same | PASS |
| 12 | Combine multiple filters | same | PASS |
| 13 | Book from filtered results | same | PASS |
| 14 | Booking appears in lawyer dashboard/app | existing lawyer suites + probe | PASS |
| 15 | No regression in lawyer login | `exec59_lawyer_login_calls_probe` | PASS |
| 16 | No regression in Jitsi integration | `exec59_lawyer_login_calls_probe` | PASS |

### Suite totals (this run)

- **PHP:** `tests/run_all_tests.sh` → **46/46 suites pass**, 0 failures.
  `exec60_lawyer_form_filters_test.php` alone: **139 checks, 0 failures**.
- **Playwright:** full suite green (see the PR for the final count).
- **Flutter:** `flutter analyze` clean; `flutter test` **316 tests pass**,
  including `exec60_journey_filters_test.dart` (**11 tests**).

### Not tested on real devices

No physical device was used. The mobile result is from `flutter test` against a
fake transport, not a live booking. Real-device and live-production verification
remain owner-side.

---

## 8. Remaining blockers / notes

1. **Migration must be applied before the new code**: `upgrade_v68.sql` adds the
   columns the filters read. Deploy the migration first (step 9.1 of the guide).
2. **Existing lawyers have no city** until an operator sets one; the city filter
   then legitimately excludes them. `country_code` is backfilled automatically.
3. **Client APK/AAB rebuild recommended** (not required): the app already handles
   the additive filters; a rebuild picks up the filter sheet UI.
4. **No production deployment was performed.** The migration and ZIP are staged
   only; the owner applies them.
5. **Website header defect (Execution 59)** remains a *deployment* gap on
   production, unchanged by this execution.

---

## 9. Links

- Pull request: https://github.com/startgroup2022-tech/mawatheq/pull/10
- Release: https://github.com/startgroup2022-tech/mawatheq/releases/tag/v2026.11.05-exec60
