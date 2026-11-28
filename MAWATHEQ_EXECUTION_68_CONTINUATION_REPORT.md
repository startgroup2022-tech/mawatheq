# MAWATHEQ — Execution 68 (continuation) report

**Branch:** `feature/ai-workspace-firm-suite` · **PR:** #10 (open, draft, not merged)
**Base commit:** `c2998e3` (tag `v2026.11.27-exec68`)
**Scope of this pass:** the three priorities from the brief — N-Genius payment
diagnosis, plans/subscriptions, and the lawyer surfaces that were missing — plus the
verification work that backs each claim.

Nothing here is asserted from reading the code. Every "verified" line below was produced
by running the code and is reproducible with the command shown beside it.

---

## 1. N-Genius: the real cause is now named, not a reference number

### What the customer saw

```
تعذّر بدء عملية الدفع الإلكتروني حالياً. لم يتم خصم أي مبلغ.
```

That exact sentence is the **transient fallback** in
`gateway_diag_customer_message()`. It is shown whenever the error code is not in the
permanent map — so the message told the customer "you can retry" without saying what was
wrong, and told the operator nothing at all.

### The defect found

`gateway_diag_reason()` — the function that turns a raw gateway code into an
operator-facing cause — had **no entry** for three codes the payment path actually emits:

| Code | Emitted by | Before | After |
|---|---|---|---|
| `gateway_rejected` | any 4xx from the gateway | "سبب غير مصنّف (gateway_rejected)" | names credentials / outlet / currency / amount |
| `connection_failed` | network, timeout, 5xx (4 call sites) | "سبب غير مصنّف" | names the transport fault and says a retry is safe |
| `timeout` | a curl timeout | "سبب غير مصنّف" | names the timeout and the server's outbound access |

Proof (`php -r` against `includes/gateway_diagnostics.php`):

```
http_401  -> gateway_rejected   ok
http_403  -> gateway_rejected   ok
http_500  -> connection_failed  ok
http_429  -> connection_failed  ok
connection_failed: timeout -> connection_failed  ok
... 14 codes checked, unclassified count: 0
```

Before the change, the same loop reported **3 unclassified**. That is the opaque
diagnosis the brief asked to replace.

### What was deliberately *not* changed

`token_failed` and `order_failed` keep the retry sentence. Both are **catch-alls** for a
failure the classifier could not name (`gateway_diag_reason_code('')` returns
`order_failed`; an unexplained token stage returns `token_failed`). Giving them a
permanent "contact us, do not retry" message would tell a customer with a genuinely
transient fault to give up. Two existing suites (`exec69_payment_diagnostics_test`,
`exec76_payment_booking_regression_test`) encode that distinction; the first attempt at
this fix broke both, which is how the rule was confirmed rather than assumed.

### To actually run a live payment

`MAWATHEQ_NGENIUS_GOLIVE_CHECKLIST_AR.md` lists the seven settings keys, the encryption
key, the required migrations and the dry-run probe stages. **No live payment was
executed in this environment** — there is no merchant outlet here, and the brief forbids
claiming one without evidence. The claim proved here is narrower and checkable: *every
gateway failure now maps to a named cause*.

---

## 2. Currency stays SAR

| Check | Result |
|---|---|
| `currency_code()` | `SAR` |
| `CURRENCY_DEFAULT` | `SAR` |
| `schema.sql` seed for `site_currency` | `SAR` |
| `ngenius_currency([])` (nothing configured) | `SAR` — no BHD fallback |
| `ngenius_currency(USD)` (explicitly configured) | `USD` — the explicit value is honoured |
| minor units for SAR | 100 (halalas) |
| `supports(BHD)` for the N-Genius path | `false` — a BHD invoice is refused, not sent at the same numeric value |
| plan prices | 0 / 149 / 799 SAR |

---

## 3. Plans and subscriptions

### New: `lawyer/subscription.php`

A lawyer previously had no way to see which plan governed them or how much of each
limit was left. The page reports:

- plan name, monthly/yearly price, billing cycle and end date;
- a usage table built from `plan_usage()`, the same function `plan_check()` refuses with;
- the plan's feature list from `plan_features`.

The property that matters is asserted, not assumed: **every reported limit equals the
value `plan_check()` enforces** (`tests/exec68_lawyer_subscription_test.php`, 11/11).

Live proof: with plan 2 attached to firm 1 the page renders «احترافية», «غير محدود» for
the unlimited columns and real remaining counts. `plan_check` refuses a request past the
ceiling with `plan_limit_reached`.

---

## 4. Clients

### New: `lawyer/clients.php`

The dashboard showed only five clients (`dash_panel_lawyer_clients()`) and there was no
page for the rest. The new page derives a client from cases/consultations/invoices via
`tenant_client_ids_subquery()` — the same derivation the dashboard and
`finance_client_balances()` use, so the three cannot disagree — and adds a detail view
with that client's cases, invoices and outstanding balance.

**A real SQL defect surfaced during testing.** A bare `UNION` inside `IN (...)` is a
syntax error in MariaDB when the subquery also carries an extra predicate:

```
SELECT ... WHERE u.id IN (SELECT a ... UNION SELECT b ...)   -- ERROR 1064
```

`php -l` passes and the page returns **HTTP 500 with an empty body**, which reads like a
routing problem. The fix wraps the union as a derived table
(`SELECT * FROM (...) AS scope_clients`). The regression test runs the page's real
statement rather than a simplified stand-in, which is what would have caught it.

Live proof, both directions:

| Request | Result |
|---|---|
| firm A list | 200, shows only `client-firm1` |
| firm A detail of its own client | 200 |
| firm A requests firm B's client id | **404** |
| firm B list | 200, shows only `client-firm2` |
| firm B requests firm A's client id | **404** |

`tests/exec68_lawyer_clients_test.php` — 10/10.

---

## 5. Word and PDF export

| Format | Result |
|---|---|
| `.docx` | HTTP 200, 1,333 bytes, valid zip, 3 entries, `word/document.xml` carries the Arabic body, correct OOXML MIME type |
| `.txt` | 200, UTF-8 with BOM (the existing contract — it is labelled «تحميل (txt)» in the UI) |
| `.pdf` | **new endpoint**, 36,369 bytes, `%PDF` header, `%%EOF` marker, `application/pdf` |

The PDF is not "a file was produced". `pdffonts` reports:

```
MPDFAA+IBMPlexSansArabic-Regular  CID TrueType  emb=yes  sub=yes
MPDFAA+IBMPlexSansArabic-Bold     CID TrueType  emb=yes  sub=yes
```

The Arabic face is embedded and subset — a fallback face would still produce a plausible
file. And because `pdftotext` silently drops numerals out of an Arabic run in this
mPDF/font combination (a limitation the existing `exec69_pdf_render_test` documents), the
digits were proved by **measured ink**: the same line renders 3,502 dark pixels with
«5000» and 3,184 without — a 318-pixel difference. The number is on the page.

### A genuine bug found while doing this

`includes/pdf.php` used one shared temp directory, `sys_get_temp_dir()/mawatheq-mpdf`,
created `0700`. Whoever rendered first owned it, so after any CLI render **every web
request failed with a permission error** — and vice versa. The PDF endpoint returned 503
over HTTP while the same code succeeded from the CLI, which is exactly the kind of
"works on my machine" split that hides until production. Fixed by keying the directory on
the effective uid, with an explicit `is_writable` check that throws instead of failing
silently.

`tests/exec68_draft_pdf_test.php` — 12/12, including that an unauthenticated caller and
another firm's lawyer are both refused the PDF.

---

## 6. Test results

| Suite | Result |
|---|---|
| PHP (`tests/run_all_tests.sh`) | **83 suites, 0 failures** (run twice — before and after the gateway edits) |
| Flutter `flutter analyze` | No issues found |
| Flutter `flutter test` | **379 tests passed** |
| Playwright | **1032 passed, 12 skipped, 0 failed** (1044 tests, 9.6 min) |

The 12 Playwright skips are the two documented data-dependent guards, not silent passes:
`consultation-journey.spec.js` skips when no lawyer matches a specialty (the
`lawyer_specialties` table ships empty by design), and `exec63-journeys.spec.js` skips when
the seeded client has no consultation. Both are `test.skip(...)` with an explicit reason.

### Booking race — proved, not assumed

The brief asks for real availability with conflict prevention. `includes/booking_slots.php`
serialises the final check and the INSERT under a named lock keyed on
`(lawyer, slot)`. Proved with two independent PDO connections on the same slot, the
first holding the lock for 700 ms:

```
c1 ok=true result=A-done
c2 ok=true result=B-done
interleaving: A-enter -> A-exit -> B-enter -> B-exit
SERIALIZED (no overlap): YES
```

B could not enter until A exited, so the window between "this slot is free" and the
INSERT is closed.

### Plans → payment chain — proved

`package_order_create()` reads the price from `plans`, never from the form:

```
monthly: amount=149.000 currency=SAR  (plans row says 149.000)  matches: YES
yearly : amount=1490.000 (expect 1490)
hidden plan refused: YES
free plan activated: YES
bad cycle refused: YES
unknown plan refused: YES
```

---

## 7. What is *not* done, and why

### Android signing — blocked, and honestly so

The brief asks for official signing with the previously approved upload key. **That key
is not on this machine.** Only Flutter's `debug.keystore` exists. Generating a
replacement would be forbidden and would also make the app un-updatable, because Play
identifies an app by its signing key.

What was delivered instead is the pipeline, so the signing is a one-step operation once
the key is supplied:

- `apps/mobile/android/key.properties.example` — the exact keys the Gradle file reads;
- `apps/mobile/tool/verify_release_signature.sh` — refuses to call an artifact ready
  unless `apksigner` reports a non-debug signer.

The verifier was **hardened after it produced a false pass**. Its first version printed
"OK: not the debug key" and exited 0 when `java` was missing, because the empty
`apksigner` output contained no "Android Debug" string. Missing tooling is now a
BLOCKER. Run against the real released APK it reports:

```
Signer #1 certificate DN: C=US, O=Android, CN=Android Debug
BLOCKER: signed with the debug key. Google Play will reject it.
RESULT: NOT ready for a Play upload (see BLOCKER above).
exit code 1
```

Package identity is unchanged and must stay so: `com.mawatheq.app`, versionCode 26,
versionName 1.8.8, targetSdk 36, compileSdk 37.

**To unblock:** place the upload keystore and `key.properties` in
`apps/mobile/android/`, then run the verifier. It will name the signer and exit 0 only
when that signer is the upload key.

### Not tested, and therefore not claimed

- A live N-Genius payment (no merchant outlet here).
- A production login or a real document upload.
- Play upload (blocked on the key above).

---

## 8. Delivery status

**The work is committed locally** (`0529b7d` on `feature/ai-workspace-firm-suite`) but
**could not be pushed from this session.** All three available credentials were tried and
each fails for a different reason:

| Credential | Result |
|---|---|
| `GITHUB_TOKEN` | not present in this environment (empty) |
| `GITHUB_PERSONAL_ACCESS_TOKEN` | API reports `push: true`, but every write returns `403 Resource not accessible by personal access token` — over git *and* over the REST contents API (probed with a throwaway file) |
| `OH_GIT_CREDENTIAL_ACCESS_TOKEN_GITHUB` | `401 Invalid username or token` |

The remote branch is still at `c2998e3`, so **PR #10 has not been updated** with this
pass. Pushing requires a push-capable token in the environment; nothing else here can
write to the repository.

To finish the delivery once that token is available:

```bash
git push "https://x-access-token:$GITHUB_TOKEN@github.com/startgroup2022-tech/mawatheq.git" \
  feature/ai-workspace-firm-suite:feature/ai-workspace-firm-suite
```

Then append this section to PR #10 — **do not open a second PR, and do not merge**.

No production deploy was performed, and PR #10 was not merged, per the brief.

---

## Files in this pass

**New**
- `lawyer/clients.php`
- `lawyer/subscription.php`
- `lawyer/download-draft-pdf.php`
- `tests/exec68_lawyer_clients_test.php`
- `tests/exec68_lawyer_subscription_test.php`
- `tests/exec68_draft_pdf_test.php`
- `MAWATHEQ_NGENIUS_GOLIVE_CHECKLIST_AR.md`
- `apps/mobile/android/key.properties.example`
- `apps/mobile/tool/verify_release_signature.sh`

**Changed**
- `includes/gateway_diagnostics.php` — three missing reason codes added
- `includes/pdf.php` — per-uid temp directory
- `includes/nav.php` — Clients and Subscription entries
- `lawyer/drafting.php` — PDF download button
