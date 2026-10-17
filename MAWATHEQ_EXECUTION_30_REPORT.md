# Mawatheq — Execution 30 Report
## Consultation attachments (website, API, database, dashboards)

**Scope:** additive only. The existing consultation booking flow is unchanged and
regression-tested. No AI feature was installed or enabled; the existing AI functionality is
untouched.

**Verified on:** PHP 8.4, MariaDB 11.8.6, Apache 2.4. **29/29** PHP suites, **588** Playwright
checks (six viewports, RTL + LTR), **131** live end-to-end checks on the new feature.

---

## 1. What was delivered

| Layer | Artifact |
|---|---|
| Database | `database/upgrade_v56.sql`, `database/schema.sql` baseline |
| Library | `includes/consultation_attachments.php` |
| Web upload | `api/consultation_attachments.php`, `booking.php`, `includes/consultation_attachments_panel.php` |
| Web download | `download-consultation-attachment.php` |
| Mobile API | `api/v1/consultation-attachments.php` |
| Dashboards | `consultation-room.php` (client), `lawyer/consultation-room.php`, `admin/consultation-attachments.php` |
| Assets | `assets/css/consultation-attachments.css`, `assets/js/consultation-attachments.js` |
| Wiring | `includes/admin_rbac.php`, admin/lawyer/app footers, admin header nav |
| Config | `config/config.php` (request-too-large answer) |
| Tests | `tests/execution30_consultation_attachments_probe.php`, `tests/phase_firm_site_http_probe.php`, `tests/setup_test_env.sh` |

---

## 2. Design decisions that are load-bearing

### Storage outside the web root, one authorized door

Files are written to a sibling of the application root
(`../mawatheq-private/consultation-attachments`, sharded by consultation id), never under the
document root. There is no public URL to guess: `download-consultation-attachment.php`
re-resolves ownership on every request.

A signed URL was the alternative and was rejected. It cannot be revoked when a consultation is
reassigned or a lawyer leaves the firm, and it leaks access through browser history, proxies
and referrers. Re-checking authorization per request costs one indexed query.

### Validation is three checks, not one

`consultation_attachment_validate()` requires all of:

1. the extension is in the catalog;
2. the finfo-detected MIME is one the extension legitimately produces;
3. the body carries no executable signature.

Each check alone is defeated by an ordinary file. A `.pdf` whose bytes are a PNG passes the
extension check. A text file named `.php` passes a MIME sniff that trusts the extension. The
signature scan looks for PHP/open-tag and script markers rather than rejecting leading NUL
bytes — an MP4/MOV/M4A is ISO-BMFF and its box size legitimately begins with `\x00`, so a NUL
check rejects real media.

### Limits are clamped to what PHP will actually accept

`consultation_attachment_limits()` clamps each per-file limit to `upload_max_filesize` and to
`post_max_size` minus a multipart-envelope allowance. Advertising the configured 100 MB video
limit on a server with a 2 MB `upload_max_filesize` promises something PHP discards before the
script runs — which surfaces as an empty `$_FILES`, not a size error.

### Two-phase upload: stage now, claim at booking

The booking form collects files before the consultation row exists. A file is written
`status='staged'` with `consultation_id` NULL and a `draft_token`, then claimed by `booking.php`
when the form is submitted. `booking.php` accepts only the token this session was issued, so a
crafted POST cannot adopt another client's staged uploads.

Abandoned drafts are reclaimed by `consultation_attachment_purge_drafts()`; a file with no row
at all is reclaimed by `consultation_attachment_purge_orphans()`, which *reports* rows whose
file is missing rather than deleting them — the row is the user's record that the file existed.

### One file per request

A multi-file `<input>` posts everything in one request, which makes a real per-file progress
bar impossible and a partial failure ambiguous. One file per request gives each file its own
progress and its own error, and one rejected file does not discard the others.

---

## 3. Bugs found and fixed during this execution

| # | Defect | Consequence | Fix |
|---|---|---|---|
| 1 | ISO-BMFF media rejected by a NUL-byte check | every MP4/MOV/M4A upload refused | signature scan instead of a NUL check |
| 2 | Weak Ogg/WebM/MP3 signatures | real audio rejected, crafted files accepted | container-accurate signatures |
| 3 | Configured limits exceeded the server's php.ini | silent empty `$_FILES` on large files | clamp on read via `consultation_attachment_ini_bytes()` |
| 4 | Oversized POST answered with a bare 403 "Invalid request" | reads as a CSRF failure; uploader misled | legible 413 `request_too_large` from `config.php` |
| 5 | Storage dir mode `0750` | CLI sweeper/cron could not rename or unlink | `02770` (setgid) + test user in the `www-data` group |
| 6 | `phase_firm_site_http_probe` resolved the firm by "newest" | probe published on another firm's row; 13/15 | resolve via owner-b's `law_firm_members` row; 15/15 |
| 7 | Same probe left `probe-page` behind on interrupt | next run's INSERT hit the unique key; 200 instead of 302 | pre-clean the slugs before the probe starts |

Bugs 6 and 7 were pre-existing and unrelated to this feature. They were invisible until the
suite was run twice in a row.

---

## 4. Verification

### PHP suite — 29/29 PASS

```
execution30_consultation_attachments_probe   PASS   131 checks, 0 failure(s)
phase_firm_site_http_probe                   PASS   15/15 checks passed
execution29_1_release_smoke_test             PASS   40 checks, 0 failure(s)
execution29_1_migration_integrity_test       PASS   52 checks, 0 failure(s)
execution29_file_security_probe              PASS   18 checks, 0 failure(s)
execution29_mobile_token_probe               PASS   26 checks, 0 failure(s)
tenant_isolation_test                        PASS   24 checks, 0 failure(s)
... 22 more, all PASS
```

### `execution30_consultation_attachments_probe` — 17 sections, 131 checks

1. every allowed file type accepted, with the right category and original name
2. the per-consultation cap enforced by the server, and the refusal names the limit
3. unsupported extensions refused (`.php`, `.zip`)
4. MIME/extension mismatch refused (PNG named `.pdf`, PDF named `.txt`)
5. a polyglot (valid PDF that is also valid PHP) refused and not stored
6. an oversized file refused per category, with a legible reason
7. the owner downloads their own attachment; bytes verified against the file on disk
8. unauthorized downloads refused (anonymous, and a different client)
9. a cross-tenant lawyer refused; the same-tenant lawyer succeeds
10. a deleted attachment refused; a row whose file is gone answers 410
11. mobile API: Bearer-token list, upload, download
12. staged drafts, claim on booking, and orphan cleanup
13. truncated multipart and empty-file uploads answered, not 500
14. CSRF enforced on the upload endpoint
15. the storage directory is outside the app root and carries a deny-all `.htaccess`
16. a traversal-shaped and an absolute `stored_path` both refused, no system file leaked
17. the existing booking flow still works, with no attachments
18. the admin console renders for a super_admin; an `editor` is refused both the console and
    the download route, while a super_admin can download the real bytes

### Playwright — 588 passed

Six viewports (mobile-320/375, tablet-768/1024, desktop-1440/1920) across public site, client
portal, lawyer workspace, admin console, accessibility and responsive suites.

### Migration — idempotent

`upgrade_v56.sql` was applied twice in succession to a scratch database built from
`database/schema.sql`. Both runs succeeded and the resulting table matches the baseline. No
positional `AFTER` clause appears anywhere in the file.

---

## 5. Security summary

- Files are outside the document root; no public URL exists.
- Ownership and tenant scope are re-checked on every download, for the web route and the API.
- Three-way upload validation (extension, detected MIME, container signature).
- Inline preview is a small allow-list (PDF, images); everything else is sent as an attachment
  with `X-Content-Type-Options: nosniff` and a sandbox CSP.
- A tampered `stored_path` (traversal or absolute) cannot escape the storage root, and the
  stored filename must match the generated pattern.
- CSRF is enforced on every session-backed POST, including the upload endpoint.
- The console and the download route share one RBAC map; an `editor` is refused both.
- `config/`, `database/`, `includes/` and `storage/` are denied at the web layer; archives are
  denied too.
- No secret is written to any attachment path, log line, or JSON response.

---

## 6. Not done, deliberately

- **Not deployed to production.** The migration package and site files must be applied by the
  operator. See `MAWATHEQ_EXECUTION_30_DEPLOYMENT_AR.md`.
- **No AI feature installed or enabled.** Ollama is not installed, no inference server is
  listening, and no external provider is configured. The existing AI functionality is
  preserved (verified by `phase3_ai_gateway_test`).
