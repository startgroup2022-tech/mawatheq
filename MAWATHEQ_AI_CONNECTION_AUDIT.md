# MAWATHEQ — AI Connection Audit (Execution 12)

Scope: read-only audit of the existing AI implementation plus the narrow fixes made in Execution 12.
Everything below is derived from the repository at commit `46bc590` on `phase-4-enterprise-ui`.

**No production database, setting, or server was modified. No credential was read or printed.**

---

## 1. What the platform actually supports (verified in code)

`includes/ai_provider.php` is the single dispatch layer. It supports exactly three chat providers:

| Provider id | Wire protocol | Endpoint | Credential |
|---|---|---|---|
| `anthropic` | Anthropic Messages API | fixed `https://api.anthropic.com/v1/messages` | API key (required) |
| `openai` | OpenAI Chat Completions | fixed `https://api.openai.com/v1/chat/completions` | API key (required) |
| `openai_compatible` | OpenAI Chat Completions schema | admin-supplied base URL, `+ /chat/completions` | optional — a local server needs none |

The `openai_compatible` path is the self-hosted route. Because vLLM, Ollama (`/v1`), llama.cpp
server, LM Studio, LocalAI and LiteLLM all expose the same `/v1/chat/completions` shape, one code
path serves all of them. `ai_endpoint_normalize()` accepts only `http`/`https` with a real host and
rejects everything else, so a malformed value can never be sent to an unintended host.

Dispatch (`ai_provider_chat()`):

```
ai_provider_chat($settings, $system, $user, ...)
  ├─ provider === 'anthropic'          → call_anthropic_raw()          (x-api-key header)
  ├─ provider === 'openai_compatible'  → call_openai_compatible_raw()  (endpoint + optional Bearer)
  └─ otherwise                         → call_openai_raw()             (Authorization: Bearer)
```

## 2. Execution flow (admin setting → provider call)

```
admin/settings.php  (form: ai_enabled, ai_provider, ai_model, ai_api_key, ai_endpoint)
        ↓  save_setting() → site_settings table
ai_load_settings($pdo)                       [includes/secret_crypto.php]
        ↓  decrypts ai_api_key (AES-256-GCM, enc:v1:)
ai_settings_configured($settings)            [includes/ai_provider.php]
        ↓
ai_gateway_authorize() / ai_gateway_call()   [includes/ai_gateway.php]  ← privacy gate + usage log
        ↓
ai_provider_chat($settings, …)
        ↓
call_*_raw()  → curl → provider HTTP
        ↓
response parse (choices[0].message.content) / error surface
```

`ai_settings_configured()` is the authoritative definition of "usable". It is used by the gateway,
the health probe and the admin Settings status pill.

## 3. Exact condition behind the reported message

The message the administrator saw is emitted at **`lawyer/drafting.php:127`**:

> هذه الميزة تحتاج تفعيل مزوّد ذكاء اصطناعي من لوحة تحكم الإدارة ← الإعدادات أولاً.

It fires when `$aiEnabled` is false. `$aiEnabled` was computed at `lawyer/drafting.php:107` as:

```php
$aiEnabled = !empty($settings['ai_provider'])
          && !empty($settings['ai_api_key'])      // ← requires a key, unconditionally
          && ($settings['ai_enabled'] ?? '1') !== '0';
```

That third condition is wrong for a self-hosted provider. A local/OpenAI-compatible model has no
credential, and `ai_settings_configured()` explicitly allows an empty key for it. So a perfectly
reachable local model was judged "not activated".

## 4. Defects found (and fixed)

### Defect A — self-hosted provider mistaken for an unconfigured one (primary)

Twelve call sites hand-rolled the key requirement instead of calling the shared predicate. Any of
them shows the activation message for a working keyless local provider:

| File | Line (pre-fix) | Extra problem |
|---|---|---|
| `lawyer/drafting.php` | 107 | — |
| `lawyer/dashboard.php` | 72 | — |
| `lawyer/case-detail.php` | 188, 211 | — |
| `api/ai_chat.php` | 56 | — |
| `api/upload_document.php` | 85 | also gated file analysis on chat availability (see Defect C) |
| `api/v1/documents.php` | 82 | also gated file analysis on chat availability (see Defect C) |
| `includes/legal_ai.php` | 90 | degrades to source-only answers |
| `includes/evidence_intelligence.php` | 18 | — |
| `includes/court_order_extraction.php` | 146 | also excluded local provider |
| `includes/contract_comparison.php` | 21 | also excluded local provider |
| `includes/ai_workflow.php` | 56 | also excluded local provider |
| `admin/ai-orchestration.php` | 50 | also excluded local provider |

Three of them (`court_order_extraction`, `contract_comparison`, `ai_workflow`, `ai-orchestration`)
additionally hardcoded `in_array($provider, ['openai','anthropic'])`, so the local provider could
never pass at all — even with a key.

**Fix:** introduced `ai_chat_provider_configured()` in `includes/ai_provider.php` as a thin delegate
to `ai_settings_configured()`, and routed all twelve sites through it. One definition, one behaviour.

### Defect B — resilience knobs saved but never loaded

`admin/settings.php` and `admin/ai-operations.php` write `ai_timeout_seconds`, `ai_max_retries` and
`ai_rate_limit_per_hour`. `ai_load_settings()` never selected those keys — it selected the older
`ai_timeout`, `ai_retries`, `ai_rate_limit`. `includes/ai_resilience.php` reads the `*_seconds` /
`ai_max_retries` / `ai_rate_limit_per_hour` names from the settings array, so every value silently
fell back to the hardcoded defaults (25s / 2 retries / 120 per hour). Admin edits appeared to save
and changed nothing.

**Fix:** `ai_load_settings()` now also loads `ai_timeout_seconds`, `ai_max_retries`,
`ai_rate_limit_per_hour`, `ai_fallback_enabled`. The legacy names are kept for backward
compatibility.

### Defect C — file analysis gated on chat availability (found while fixing A)

`api/upload_document.php` and `api/v1/documents.php` gate automatic document analysis on
`ai_chat_provider_configured()`, then hand the file to `analyze_document_with_ai()`. That function
only implements the OpenAI and Anthropic vision routes; for any other provider it returns an error,
and the endpoint recorded the document as `status='failed'` with a generic message. So a self-hosted
chat provider — now correctly recognised as configured after Fix A — would make every upload look
like a hard failure.

**Fix:** added `ai_vision_provider_configured()` (chat-configured **and** provider is
`openai`/`anthropic`), and both endpoints now record a precise `pending` message ("the active
provider does not read files directly; that needs OpenAI or Anthropic") instead of a false failure.
Chat features are unaffected; this only distinguishes the file-reading path.

### Not a defect (confirmed correct)

- `ai_endpoint_normalize()` / `ai_openai_endpoint_url()` — correct, restrictive.
- The gateway and `ai_provider_health_probe()` — already used the correct predicate.
- `admin/settings.php` status pill — already used `ai_settings_configured()`, which is why the
  Settings page could say "configured" while `drafting.php` said "not activated". That disagreement
  was the visible symptom of Defect A.
- Test Connection (`admin/ai_test.php`) — tests the **saved** configuration, which is correct.
- `ai_api_key` encryption — AES-256-GCM at rest, never re-displayed. Preserved.

## 5. Does the code require the endpoint to be OpenAI-compatible?

Yes, for `openai_compatible`. The request is a standard Chat Completions body
(`model`, `max_tokens`, `temperature`, `messages[]`). A server that does not implement that schema
will not work through this path. Ollama exposes it under `/v1`; a base URL of
`http://host:11434/v1` is the correct form. Note that `max_tokens` is the OpenAI name; servers that
only accept `max_completion_tokens` will reject the request.

## 6. What the code does NOT tell us

- **Which provider, model, endpoint and port the owner's production model uses.** The repository
  contains no production endpoint, host or credential. This is only discoverable on the server.
- **Whether the owner's model is still running.** Not verifiable from here.
- The empty `ai_*` values reported by the owner are consistent with a provider that was never saved
  through the Settings form — but they do **not** by themselves prove the model is missing, and this
  audit does not draw that conclusion.
