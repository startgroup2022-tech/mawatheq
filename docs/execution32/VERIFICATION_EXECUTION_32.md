# Execution 32 — affiliate onboarding journey, header and landing page

Verification report for the branch `execution-32-affiliate-journey`.

| | |
|---|---|
| Base | `1c750e1` (`docs(agents): record merge topology and the inert AI default`) |
| Fix commit | `9770196cb93fa943c769e197ca5ba6d0c2624baf` |
| Docs commit | `c1b074e701922231599087705772f630d1a6c7e4` |
| Branch | `execution-32-affiliate-journey` |
| Migration | `database/upgrade_v58.sql` (additive, idempotent) |

## 1. Scope actually delivered

The three tasks in this PR were not equally large, and the report says which is which
rather than implying all three were built here.

| Task | Status in this PR | Evidence |
|---|---|---|
| TASK-1 — global website header | **Fixed here** | `includes/header.php`, `assets/css/style.css` |
| TASK-2 — affiliate landing page | **Already delivered** in Execution 31 (`08cc3e0`); **verified here** | `affiliates.php` is byte-identical to `origin/main` — this PR does not touch it |
| TASK-3 — affiliate onboarding journey | **Fixed here** | `includes/affiliate_auth.php`, `admin/affiliate-applications.php`, `database/upgrade_v58.sql` |

`affiliates.php` has exactly one commit in its history (`08cc3e0`, Execution 31) and is
unchanged between `1c750e1` and this branch. The landing page work in this PR is
verification, not authorship: the page was audited at five widths in two languages, and
the defects found were in the shared header and the invitation path, not in the page.

## 2. The defects fixed

### 2.1 Header — the navigation wrapped to three rows

The header is a three-zone flex row (brand | nav | actions). Every zone was allowed to
shrink, and the nav had no `white-space:nowrap`, so at ≥1080px the eight Arabic labels
wrapped inside the row. Measured before/after at identical viewports:

| Viewport | Language | Nav rows before | Nav rows after |
|---|---|---|---|
| 1440 | ar / en | **3** | **1** |
| 1280 | ar / en | **3** | **1** |
| 768 | ar / en | 1 (collapsed to panel) | 1 |
| 390 | ar / en | 1 (collapsed) | 1 |
| 360 | ar / en | 1 (collapsed) | 1 |

At 1280px in English the header also pushed the document wider than the viewport
(`scrollWidth` 1283 vs `clientWidth` 1280) — a horizontal scrollbar caused by the header
alone. After the fix `scrollWidth == clientWidth` at every width tested.

The fix pins the three zones (`flex:none` on brand and actions), lets only the nav flex
(`flex:1 1 auto; min-width:0`), forces `white-space:nowrap` on the labels, and lowers the
social-row hide breakpoint to 1180px so the row is not squeezed. Below 1080px the links
collapse into the existing mobile panel, which is absolutely positioned against the
sticky header so it can never widen the document.

### 2.2 Onboarding journey — three defects on the invitation route

There are two ways to reach an affiliate account, and only the registered one had ever
been exercised:

- **Registered** (`/affiliate/register.php`) — creates the account *with* a password,
  status `pending`.
- **Public form** (`/affiliates.php`) — only *queues* an application. Approval calls
  `affiliate_create_account()`, which sets no `password_hash`.

So an approved public-form applicant is an account nobody can sign into, and the
invitation is the **only** route to credentials. All three defects sat on that route:

1. **`??` used as a value, not a test.** `in_array($data['invite_type'] ?? 'individual',
   ...) ? $data['invite_type'] : ...` read from a key that was only *tested* with `??`,
   so the value was `null` and the `NOT NULL` column rejected **every** invitation — the
   function could not create one at all.
2. **It refused any email that already had an affiliate row** — exactly the account this
   path must reach. It now refuses only an account that *already has credentials*, so a
   fresh token can never act as a password reset.
3. **Accepting an invitation pre-recorded the formal agreement.** `terms.php` then found
   the agreement already accepted, rendered no form, and the activation that only runs
   from that form never fired — the partner stayed `approved` forever and could never
   earn. Consent is still audited; the agreement is now accepted on the terms page.

### 2.3 `accepted_ip` — a 64-character hash in a `VARCHAR(45)` column

`affiliate_ip_hash()` returns a SHA-256 digest (64 chars). `affiliate_applications.accepted_ip`
and `affiliate_agreements.accepted_ip` were `VARCHAR(45)`, the width of an IPv6 literal.
Under `sql_mode=STRICT_TRANS_TABLES` every insert was refused, and
`affiliate_submit_application()` catches the exception and reports the generic
`create_failed` — so the public form silently did nothing. `affiliate_accept_terms()`
failed the same way, and because `terms.php` only activates when both calls return true,
an approved affiliate could never reach `active`.

Reproduced against a column narrowed to the production shape:

```
ERROR 1406 (22001) at line 1: Data too long for column 'accepted_ip' at row 1
```

## 3. The six required journey scenarios

| # | Scenario | Result | Where it is proven |
|---|---|---|---|
| 1 | Public signup creates a real affiliate account **with password** | pass | `exec32` §2, §6 — `password_hash` non-empty and verifiable |
| 2 | Account is initially **pending review** | pass | `exec32` §2 — status `pending`; e2e asserts `قيد المراجعة` |
| 3 | **Pending affiliates can log in** and view status | pass | `exec32` §1 + e2e `a pending applicant can sign in again` |
| 4 | **Admin approval activates access** per agreement + compliance | pass | `exec32` §3, §4 — activation refused until terms on record; earning still refused while compliance is not cleared |
| 5 | **Rejected and suspended accounts cannot earn** | pass | `exec32` §1b (new) + `exec31` `suspended cannot earn` |
| 6 | **Invitation onboarding works independently** | pass | `exec32` §6 — public-form applicant approved, invited, signs in, activates |

Scenario 5 had no direct `rejected` coverage before this pass. The gate itself was
already safe by construction — `affiliate_can_earn()` returns true only for
`status === 'active' && compliance_status === 'approved'`, so `rejected` and `suspended`
cannot earn — but nothing asserted it. A new `1b. Earning policy` section pins the whole
matrix (six non-earning statuses, an `active`-but-uncleared account, a cleared account,
and `null`), taking `exec32` from 64 to **73 checks**.

## 4. Migration instructions

`database/upgrade_v58.sql` is **additive and idempotent**. It contains no `DROP`, no
`DELETE`, and no positional `AFTER` clause — a positional `AFTER` naming an absent column
is what made the Execution 28 repair script abort silently, and this file avoids the
pattern entirely.

Apply it against the production database **before** the site files, so the widened column
exists when the new code first writes to it:

```
mysql -u CPANEL_DB_USER -p CPANEL_DB_NAME < database/upgrade_v58.sql
```

Apply twice is safe: verified against a populated database by applying it three times and
diffing `SHOW CREATE TABLE` — identical, with row counts unchanged (195 applications /
184 agreements before and after).

| Property | Result |
|---|---|
| First apply on production shape | exit 0, 0 errors, columns widened to `VARCHAR(64)` |
| Second apply | exit 0, 0 errors |
| Third apply | exit 0, 0 errors |
| Schema after 2nd/3rd apply | **identical** to after 1st — a true no-op |
| Existing rows (`VARCHAR(45)` values, `NULL`) | preserved unchanged |
| 64-char digest insert after apply | succeeds |

No rollback script is needed: the change widens a column, so reverting the code without
reverting the schema is safe. Rolling the schema back is only possible while no 64-char
value is stored, which is why nothing here does so automatically.

**Privilege note:** the file runs `ALTER TABLE`/`MODIFY COLUMN`, so the database user
needs `ALTER`. If it does not, the guarded statements are skipped rather than aborting the
file, but the columns stay narrow and the public form and terms acceptance stay broken.

## 5. Visual audit

Captured at **1440 / 1280 / 768 / 390 / 360 px**, in **Arabic (RTL)** and **English (LTR)**,
for the header on both the home page and the landing page — 60 PNGs plus the raw
measurements in `docs/execution32/audit/`.

The "before" images are the real pre-fix tree: a `git worktree` of `1c750e1` served on a
separate port with the same database and configuration, not a mock-up.

| Check | Result |
|---|---|
| Nav rows | 1 at every width and language (3 at 1440/1280 before) |
| Horizontal overflow of the document | none at any width; 1280/en fixed (1283 → 1280) |
| Sibling overlap in the header | 0 at every width and language |
| Text clipped or truncated | 0 at every width and language |
| Uniform nav link height | yes at every width |
| Mobile toggle is the only nav affordance below 1080px | yes |
| Landing form, consent boxes and submit present | yes at every width and language |

`docs/execution32/audit/comparison.html` is a side-by-side before/after contact sheet.

### Remaining defects

None found in the header or the landing page. Two notes that are not defects:

- The commissionable-services table at 360px is wider than the viewport but sits inside
  `.table-wrap`, and the document itself does not overflow — intended horizontal scroll
  for a data table. It is the same before and after.
- The nav's visual centre is offset 156px (ar) / −141px (en) from the container centre at
  ≥1280px. That is the brand and the action cluster being different widths, not a layout
  fault; the nav links themselves are one row of uniform height with no overlap.

## 6. Regression results

| Suite | Result |
|---|---|
| PHP suites (`tests/run_all_tests.sh`, run as `sg www-data`) | **32 passed, 0 failed** |
| Browser (`npx playwright test`, 6 viewports) | **636 passed, 0 failed** |
| `exec32_affiliate_journey_test` | **73 / 73** (was 64) |
| `exec31_affiliate_test` | 105 / 105 |
| `exec31_admin_console_probe` | 100 / 100 |
| `execution30_consultation_attachments_probe` | 131 / 131 |

`execution30` failed in an earlier session with `scandir(): Permission denied`; it passes
when the suite runs as `sg www-data`, as recorded in `AGENTS.md`. It is not related to
this change.

## 7. Not done

- **Not merged.** The PR is for review.
- **Not deployed**, and no production data touched. The migration was applied only to the
  local test database.
- No email settings, SMTP configuration, or AI infrastructure was modified. AI remains
  deferred: no provider configured, no inference server, and `phase3_ai_gateway_test`
  still passes 42/42.
