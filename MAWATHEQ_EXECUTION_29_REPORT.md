# MAWATHEQ — Execution 29 report

Security hardening, file access control, and runtime database safety.
Branch `recovery/execution-28-to-29`.

## Summary

Four real defects were found by writing probes that attack the running application over HTTP
rather than by reading the code. Each is fixed and covered by a regression suite. One of them
— an admin role that could read every client's documents — was reachable from the public web
root with no special tooling.

| # | Defect | Impact | Fix |
|---|---|---|---|
| 1 | `download-document.php` never ran the console guard | An `editor` could download **any** client's document by id | `includes/admin_rbac.php` + role check re-read from the DB |
| 2 | Password reset tokens stored plaintext, consumption not atomic | A DB dump, backup, or support ticket yields a usable reset link; two concurrent submits could both succeed | SHA-256 hash, single conditional `UPDATE` claim, sessions invalidated |
| 3 | `api/v1/calendar.php` completed-event UPDATE had no table alias | HTTP 500 for every caller, every tenant | Declared the alias |
| 4 | Ordinary requests ran `ALTER TABLE` | Schema changed by whichever request arrived first, unordered, unrecorded | Opt-in via `MAWATHEQ_ALLOW_RUNTIME_SCHEMA_DDL=1` |

Also fixed: integration API tokens never expired; a plaintext-to-hash upgrade in
`api_authenticate()` was an unguarded write on a read path.

## The privilege escalation in detail

`download-document.php` sits at the web root, so it never included
`admin/includes/admin_guard.php`. Its admin branch checked only that `$_SESSION['admin_id']`
was set. The RBAC map that refuses an `editor` the documents console lived as a local variable
*inside* the guard, so the root-level route could not consult it even if it had wanted to.

The map now lives in `includes/admin_rbac.php`. The route calls
`admin_can_access($pdo, 'documents.php')`, and the role is re-read from `admins` rather than
trusted from the session, so a demoted operator loses access on the next request instead of at
the next console page load. `admin_guard.php` and `admin_header.php` were moved onto the same
helper, so the console and the route cannot disagree.

## Runtime schema mutation

Three call sites (`config/config.php`, `api/v1/bootstrap.php`, `includes/jurisdiction.php`)
called `schema_ensure_column()` on every request. Serving a page could therefore add a column
to the production database — concurrently, with no ordering against the migration files and no
record of what was applied. That is why "the SQL was run" and "the error persists" could both
be true. Self-healing now requires an explicit opt-in; the supported path is
`database/repair/00_preflight_check.php` to report drift and `database/upgrade_v*.sql` to fix
it. The helper still validates identifiers before interpolating them into DDL.

## Test results

Every number below was produced by running the suite; none is carried over from a prior
session's notes.

| Suite | Result |
|---|---|
| PHP unit / contract suites | **19 / 19 passed** |
| HTTP probes (`tests/*_probe.php`) | **6 / 6 passed** |
| Playwright browser audit (6 viewports, RTL + LTR) | **588 passed, 0 failed** |
| Release build reproducibility | 3 consecutive builds → identical SHA-256 for both archives |
| Deployment smoke test (unpacked archive under Apache) | 13 pages render, 10 sensitive paths denied, 2 public assets reachable, 0 PHP fatals |

Execution 29 probes, 196 checks total:

| Probe | Checks |
|---|---|
| `execution29_migration_repair_test.php` | 34 |
| `execution29_password_reset_test.php` | 45 |
| `execution29_tenant_isolation_probe.php` | 54 |
| `execution29_mobile_token_probe.php` | 26 |
| `execution29_file_security_probe.php` | 18 |
| `execution29_runtime_ddl_test.php` | 19 |

`tests/run_all_tests.sh` runs all 25 suites in one command. It exists because a plain loop
over `tests/*.php` reports false failures: the probes log in over real HTTP, the app
rate-limits repeated logins per account and IP, and a later suite inherits the throttle from
an earlier one. Two suites failed that way during this session
(`execution29_file_security_probe` saw the owner's download answered 302 → `/login.php`,
`executive08_http_probe` six login failures) and both pass in isolation and under the runner,
which clears the throttle before each suite. A loop that reports phantom failures teaches
people to ignore real ones.


### Deployment smoke test detail

The release archive was unpacked into a clean directory, given the documented
`config/secrets.php`, and served by Apache on port 12001 — the same mod_php configuration as
production, which is where the `Authorization`-header behaviour differs from the PHP built-in
server.

- Rendered 200: `/`, `/login.php`, `/register.php`, `/forgot-password.php`, `/contact.php`,
  `/sitemap.php`, `/robots.txt`, `/api/v1/plans.php`, `/admin/login.php`, `/lawyer-login.php`.
- Redirected 302 → `/login.php` (correct): `/consultations.php`, `/laws.php`.
- Denied 403: `/config/secrets.php`, `/config/config.php`, `/database/schema.sql`,
  `/includes/auth_security.php`, `/tests/setup_test_env.sh`, `/AGENTS.md`, `/composer.json`,
  `/package.json`, `/.git/config`, `/uploads/documents/.htaccess`.
- Reachable 200: `/.well-known/assetlinks.json`, `/assets/css/style.css`.
- `/api/v1/me.php` and `/api/v1/logout.php` without a token: **401 with a JSON body**, not a
  blank 500 — the failure mode that silently kills the mobile API.
- `Authorization: Bearer …` reaches PHP: 401, not a dropped header.
- Zero `PHP Fatal` / `Uncaught` entries in the Apache error log.

## Release artifacts

Built by `build_release.sh` from a git ref, not the working tree, so a rebuild of the same
commit yields the same bytes.

- `MAWATHEQ_WEBSITE_CPANEL_FINAL.zip` — 393 files. No `config/secrets.php`, no `.git`, no
  `tests/`, no `docs/`, no `apps/`. Bundles `vendor/` because `onelogin/php-saml` backs the
  SSO routes.
- `MAWATHEQ_DB_MIGRATIONS_SAFE.zip` — 58 files: `schema.sql`, `upgrade_v2..v54.sql`, the
  repair tooling, and the Arabic deployment guide.
- `SHA256SUMS.txt` — the single source of truth for verification.

### Two packaging rules the script enforces

**File modes are normalized to 644 / 755, and asserted.** Apache serves a stylesheet as a user
that is not the file owner, so a `0660` or `0600` CSS file returns **403** while every page
still reports HTTP 200. The site loses its styling and no status check notices. The build
fails if any packaged file or directory has another mode.

**Timestamps are pinned to the commit time.** `zip` records each file's mtime, so a package
assembled by copying files is not reproducible — two builds of one commit differ. Every staged
path is stamped with the commit date. Without this the site archive happened to be stable
(it comes from `git archive`) while the migration archive changed on every run, which would
have made the published checksum meaningless.

## What could not be verified

The Execution 28 archive transfer is blocked. This branch contains **no** `build_release.sh`,
no delivery archives, and no APK files, so the Execution 28 artifact claims — a 47 MB site
archive, a 92 KB migration archive, APK SHA-256 values matching `manifest.json` — cannot be
compared against anything here and are not reproduced in this report. The build script and the
artifacts above are new work on this branch. The Execution 28 figures should be treated as
unverified until that archive is available.

## Deployment ordering

`database/repair/01_repair_reset_columns.sql` runs **before** the site files. The new code
assumes the new columns exist; uploading files first leaves login and password reset broken
for the interval between the upload and the SQL. The Arabic guide states this and repeats it
in its "what not to do" section.

One caveat worth the operator's attention: the repair needs `ALTER` on the production
database. Without it the repair degrades safely rather than failing loudly, and the columns
are never added. `database/repair/00_preflight_check.php` reports the missing privilege before
the work starts.

## Rollback

No migration reversal is required. Every schema change is additive — new columns and indexes
only; nothing was dropped or altered. Rollback is restoring the previous files. The guide notes
that rolling back also restores the privilege-escalation gap, so it is an emergency measure
only.

## Artificial intelligence

Deferred as instructed. Ollama is not installed, no inference server is listening, and no
external provider is configured. Existing AI functionality is untouched
(`phase3_ai_gateway_test.php` passes).

## Not done

Not deployed to production. The SQL repair and the `upgrade_v*.sql` files still have to be run
against the production database by the owner, in the order the guide specifies.
