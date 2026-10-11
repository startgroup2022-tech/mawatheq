# MAWATHEQ — Execution 12 Report
## Restore Existing AI Provider Connectivity & Production Diagnostics

- Repo: `startgroup2022-tech/mawatheq`
- Branch: `phase-4-enterprise-ui`
- Base: `46bc590`
- PR: **#2** (existing, draft) — updated, not duplicated

---

## 1. Exact root cause found in code

**A self-hosted (OpenAI-compatible) AI provider was treated as "unconfigured" by twelve feature
pages, because they required an API key that a local model does not have.**

The authoritative predicate `ai_settings_configured()` (`includes/ai_provider.php:257`) is explicit:
a hosted provider needs a key, but `openai_compatible` needs only a valid endpoint, *"because a
local model has no credential to send."* The gateway, the health probe and the admin Settings status
pill all used it. Twelve other call sites did not — they hand-rolled:

```php
!empty($settings['ai_provider']) && !empty($settings['ai_api_key']) && ($settings['ai_enabled'] ?? '1') !== '0'
```

Four of them (`court_order_extraction`, `contract_comparison`, `ai_workflow`, `ai-orchestration`)
went further and hardcoded `in_array($provider, ['openai','anthropic'])`, excluding the local
provider entirely.

A **second, independent defect** was found during the audit: the resilience knobs
(`ai_timeout_seconds`, `ai_max_retries`, `ai_rate_limit_per_hour`) were written by the admin UI but
never selected by `ai_load_settings()`, so every value silently fell back to the hardcoded default
and admin edits had no effect.

## 2. Why production shows the activation warning

The administrator's message is emitted at **`lawyer/drafting.php:127`**, guarded by `$aiEnabled`
computed at line 107. With the production settings empty (`ai_provider` blank, `ai_api_key` absent),
`$aiEnabled` is false and the warning appears.

Two distinct situations produce the same message, and the old code could not tell them apart:

1. **Genuinely unconfigured** — no provider chosen yet. This matches the reported empty settings.
2. **Configured but keyless self-hosted** — a working local model that the key-check wrongly
   rejected. This is what the fix addresses.

The owner reports a model *was* previously installed. If that model is self-hosted and keyless,
condition (2) alone was enough to block every AI feature — which is precisely the symptom described.
The repository does not contain the production endpoint, so this audit does **not** conclude which
case production is in; Step 0 of the recovery guide resolves it on the server.

## 3. Files changed

| File | Change |
|---|---|
| `includes/ai_provider.php` | Added `ai_chat_provider_configured()` (delegates to `ai_settings_configured()`) and `ai_diagnostics()` (admin-only, redacted, distinguishes unconfigured from unreachable). |
| `includes/secret_crypto.php` | `ai_load_settings()` now also loads `ai_timeout_seconds`, `ai_max_retries`, `ai_rate_limit_per_hour`, `ai_fallback_enabled`. |
| `lawyer/drafting.php`, `lawyer/dashboard.php`, `lawyer/case-detail.php` | Route AI gating through the shared predicate. |
| `api/ai_chat.php`, `api/upload_document.php`, `api/v1/documents.php` | Same. The two document endpoints also gained a vision-capability gate so a chat-only provider records an honest `pending` state instead of a false `failed`. |
| `includes/legal_ai.php`, `includes/evidence_intelligence.php`, `includes/court_order_extraction.php`, `includes/contract_comparison.php`, `includes/ai_workflow.php` | Same; the last three also stopped excluding the local provider. |
| `admin/ai-orchestration.php` | Same; stopped excluding the local provider. |
| `admin/ai-operations.php` | New **AI connectivity diagnostics** panel (super-admin only, CSRF-gated page). |
| `tests/execution12_ai_connectivity_test.php` | New suite (49 checks). |
| `tests/execution12_e2e_local_provider.php` | New end-to-end demonstration against a local provider. |
| `AGENTS.md` | Documented the single predicate, the diagnostics, and the resilience-key fix. |

No existing feature, table, migration, or endpoint was removed. Tenant isolation and the privacy gate
are untouched.

## 4. Tests executed and results

| Suite | Result |
|---|---|
| `php -l` — every tracked PHP file | **241 files, 0 failures** |
| Execution 12 AI connectivity (new) | **49 checks, 0 failures** |
| Executive 08 (feature + HTTP probe) | 145 + 53 checks, 0 failures |
| Executive 09 (features + SSO) | 33 + 13 checks, 0 failures |
| Phase 3 (AI gateway + finance) | 42 + 43 checks, 0 failures |
| Phase 5 (integration + HTTP probe + ACL + scope + SaaS core) | 47 + all expectations + 19 + 23 + 18 checks, 0 failures |
| Phase firm-site (feature + HTTP probe) | 43/43 + 15/15 |
| SADAD gateway | 105 checks, 0 failures |
| Tenant isolation | 24 checks, 0 failures |
| Currency | 35 checks, 0 failures |
| Playwright browser suite | **588 passed** |

**The new suite was written first and run against the unfixed code: 34 checks, 10 failures** —
proving the defects were real, not theoretical. After the fix: 49 checks, 0 failures.

### Mock disclosure

The inference integration checks run against a **local mock** OpenAI-compatible server
(`php -S 127.0.0.1:11434 /tmp/mockai/router.php`, source in the test header). The mock proves the
**client** speaks the OpenAI schema correctly, parses `choices[0].message.content`, and reads token
usage without fabricating it. **It proves nothing about the owner's production model.** Real-provider
verification requires the production host (Step 1–4 of the recovery guide).

### End-to-end demonstration

`tests/execution12_e2e_local_provider.php` pointed the app at the mock as a keyless self-hosted
provider on the local dev database, and confirmed: `ai_chat_provider_configured()` → true, the
drafting warning **no longer** fires, a real chat completion succeeds, and a grounded legal answer is
produced — then restored the original settings exactly. This demonstrates the fix on real code paths;
it is not production inference.

## 5. Was the existing AI model located?

**No.** The model runs on the owner's infrastructure, which this environment cannot reach. No
production endpoint, host, port, or credential exists anywhere in the repository, and none was
invented. The recovery guide's Step 0 provides read-only commands to locate it on the server.

## 6. Was real production inference verified?

**No.** Inference was verified only against the local mock. Production inference is unverified until
the owner completes the recovery steps and Test Connection returns a real response on the live host.

## 7. Remaining production actions

1. Locate the original AI host/port/model (recovery guide Step 0).
2. Confirm the API shape at `/v1/models` (Step 1).
3. Secure the connection — authenticated HTTPS gateway or private network, never a public raw port
   (Step 2).
4. Enter provider/model/endpoint in admin Settings and run Test Connection (Step 3).
5. Run a real Arabic legal request and confirm citations are grounded in verified sources (Step 4).
6. Verify tenant isolation and the privacy gate (Step 5).
7. Deploy this branch's fixes (they are required for a keyless self-hosted provider to work).

## 8. Safe commands the owner should execute next

All read-only; see the recovery guide for full context.

```bash
# Find how the model was originally started
grep -Ei "ollama|vllm|llama|localai|litellm|11434|8000|8080|/v1" ~/.bash_history 2>/dev/null | tail -40

# What is listening locally right now
ss -ltnp 2>/dev/null | grep -E "11434|8000|8080|5000|1234"

# Is any inference process alive
ps aux | grep -Ei "ollama|vllm|llama-server|localai|litellm" | grep -v grep

# Probe candidate addresses (a refusal narrows the search)
for p in 11434 8000 8080 5000 1234; do
  echo -n "port $p: "; curl -s -m 2 -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:$p/v1/models" || echo no
done
```

## Status summary

| State | Status |
|---|---|
| Code ready | **Yes** — fixes committed, all suites green |
| Provider configured | **No** — production settings are empty |
| Provider reachable | **Not tested** — no production endpoint known here |
| Real inference verified | **No** — only against a local mock |
| Production AI operational | **No** — pending owner steps |

These five states are independent. Only the first is achieved by this execution.

## Release artifact

| Field | Value |
|---|---|
| File | `MAWATHEQ_EXECUTION_12_COMPLETE.zip` |
| Built from | commit `0e38e05` on `phase-4-enterprise-ui` (tracked files only) |
| Size | 761,772 bytes (0.73 MiB) |
| Files / directories | 294 files, 26 directories |
| SHA-256 | `44b3a1350d007066e3fef4f9fef269fe99467855df5f42f826d99e60b8fe6d92` |
| ZIP modes | 0644 files, 0755 directories (explicit, every entry) |
| Excluded | 75 tracked files: `.git`, secrets, tests, screenshots, Node tooling, dev reports |
| Sidecars | `.sha256`, `_FILES.sha256` (per-file), `_MANIFEST.txt` (included + excluded) |
| Guide | `MAWATHEQ_EXECUTION_12_DEPLOYMENT_AR.md` (incremental cPanel) |

Verification on the extracted package: 221 PHP files lint clean; `tools/production_check.php`
exits 0 (59 OK, 0 warn, 0 fail) against a fresh database built from the packaged `schema.sql`
(106 tables, v51 + v52 tables present); the shipped AI predicates were exercised directly and all
9 assertions held, including that `ai_diagnostics()` output contains no key.

**No database migration is required for Execution 12** — it changes PHP only.
