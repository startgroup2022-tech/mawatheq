# MAWATHEQ — AI Production Recovery Procedure (Execution 12)

Goal: reconnect MAWATHEQ to the AI model the owner **previously deployed**. This procedure does not
introduce a new provider and does not replace the existing architecture.

---

## How to read this document

Each item is labelled with how it is known:

- **[CODE]** — verified by reading the repository at `46bc590`.
- **[TEST]** — verified by an executable test in this repo.
- **[OWNER]** — supplied by the owner; not independently verified.
- **[LIVE]** — requires access to the cPanel host or the AI VPS to confirm.

## Facts established

| # | Fact | Basis |
|---|---|---|
| 1 | The platform supports Anthropic, OpenAI, and any OpenAI-compatible server via `ai_endpoint` | [CODE] |
| 2 | A self-hosted provider needs only a valid endpoint, not an API key | [CODE] + [TEST] |
| 3 | Twelve feature pages wrongly demanded a key, so a keyless local model showed the activation warning | [CODE] + [TEST] |
| 4 | The resilience knobs were saved but never loaded | [CODE] + [TEST] |
| 5 | The client speaks correct OpenAI Chat Completions and parses the reply and token usage | [TEST] (mock) |
| 6 | `127.0.0.1:11434` refused on the cPanel host | [OWNER] |
| 7 | The AI settings are empty (`ai_provider`/`ai_model` blank, `ai_enabled` unset) | [OWNER] |
| 8 | Which host/port/model the owner's model runs on | [LIVE] — unknown here |

**Fact 6 is the important one.** `127.0.0.1:11434` is the *default local* Ollama address. It refusing
does **not** mean the model is gone — it means the model is not running on that address on the
cPanel host. A model on a separate VPS, a different port, or behind a gateway would all produce the
same refusal. The owner states a model was previously installed, and nothing here contradicts that.

## Step 0 — Do this first: find the original AI host

Run these **read-only** commands on the cPanel host as the `mawatheq` user. None change anything.

```bash
# 1. Was a model ever started from this account? (shell history is the fastest lead)
grep -Ei "ollama|vllm|llama|localai|litellm|11434|8000|8080|/v1" ~/.bash_history 2>/dev/null | tail -40

# 2. Any leftover config or launch scripts?
ls -la ~ 2>/dev/null
find ~ -maxdepth 3 -iname "*ollama*" -o -maxdepth 3 -iname "*llama*" -o -maxdepth 3 -iname "*vllm*" 2>/dev/null

# 3. Anything actually listening locally right now?
ss -ltnp 2>/dev/null | grep -E "11434|8000|8080|5000|1234"
netstat -ltnp 2>/dev/null | grep -E "11434|8000|8080|5000|1234"

# 4. Any inference process alive?
ps aux | grep -Ei "ollama|vllm|llama-server|localai|litellm" | grep -v grep

# 5. Probe the usual local addresses (a refusal is fine — it narrows the search)
for p in 11434 8000 8080 5000 1234; do
  echo -n "port $p: "; curl -s -m 2 -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:$p/v1/models" || echo "no"
done
```

If nothing is local, the model is on a **separate machine**. Ask the owner for that VPS's hostname
or IP and the port it serves on, then continue at Step 2.

## Step 1 — Confirm the model's API shape [LIVE]

Once you have a candidate host:port, confirm it is OpenAI-compatible before touching MAWATHEQ:

```bash
BASE="http://THE_HOST:THE_PORT/v1"
curl -s -m 5 "$BASE/models" | head -c 500; echo
```

- A JSON list of models means the OpenAI-compatible path will work.
- `404` on `/models` but a working `/chat/completions` also works.
- If it is a bare Anthropic-style or proprietary API, it will not fit `openai_compatible`; use the
  matching built-in provider instead.

Do **not** invent a model name. Take the exact `id` from `/models`, or from the owner's records.

## Step 2 — Secure the connection before wiring it up

A model API is an unauthenticated remote-code-shaped surface. Never expose it publicly.

- If the model is on a **separate VPS**, put it behind an authenticated HTTPS reverse proxy
  (nginx/Caddy + a bearer token or mTLS) and expose only that. Then use the HTTPS URL as the
  MAWATHEQ endpoint.
- If the cPanel host and the VPS can share a **private network** (WireGuard, Tailscale, or a
  provider private VLAN), use the private address and skip public exposure entirely.
- Do **not** port-forward the raw inference port to the internet.
- MAWATHEQ already sends `Authorization: Bearer <key>` when a key is configured for
  `openai_compatible`, so a token-protected gateway fits without any code change.

## Step 3 — Enter the settings in MAWATHEQ admin

Admin console → **الإعدادات** (Settings) → **ربط مزوّد الذكاء الاصطناعي**:

1. Tick **تفعيل التكامل** (enable integration).
2. **المزوّد**: choose **نموذج محلي / متوافق مع OpenAI** for a self-hosted server, or **Anthropic** /
   **OpenAI** for those hosted services.
3. **اسم النموذج المستخدم فعلياً**: the exact model id from Step 1.
4. **مفتاح API**: leave blank for a purely local server; enter the gateway token if the VPS sits
   behind an authenticated proxy. (Stored encrypted; never re-displayed.)
5. **عنوان المزوّد المحلي**: the base URL ending in `/v1`, e.g. `https://ai.internal.example/v1`.
6. **Save**, then press **اختبار الاتصال** (Test Connection).

For a fuller picture, open Admin → **عمليات وحكامة الذكاء الاصطناعي** and press
**فحص الإعداد والوصول الآن**. It reports, separately and without secrets: enabled, provider chosen,
model set, endpoint set, whether credentials are required/present, whether the config is usable,
whether a **real request** reached the server, and — if not — the exact blocking condition
(`disabled` / `no_provider` / `no_endpoint` / `no_credentials` / `unreachable`).

> A green "reachable" means a real inference request returned a real response. It is never inferred
> from configuration alone.

## Step 4 — Prove a real Arabic legal request [LIVE]

After Test Connection is green, exercise the grounded legal answer path end to end:

1. As a lawyer, open a case → **الصياغة** (drafting) and generate a short Arabic document.
2. Ask the legal assistant a Bahrain-law question and confirm the answer cites the retrieved
   knowledge sources.
3. Confirm the citation names match rows in `knowledge_sources` / `law_articles` and are marked
   `verified`. A reply with no citation, or an invented article number, is a failure — stop and
   investigate before declaring success.

## Step 5 — Verify tenant isolation and privacy

- Sign in as a **second firm** and confirm it cannot see firm 1's AI usage, copilot runs, or
  conflict reviews.
- Confirm the privacy gate is in force: `ai_tenant_settings.privacy_mode`. A tenant set to
  `local_only` / `external_disabled` must not have prompts sent to an external provider — the
  gateway enforces this in `ai_gateway_authorize()`.
- Confirm no case content reaches a provider the owner has not approved. If the model is
  self-hosted on the owner's own infrastructure, that is the privacy-preserving choice.

## Rollback

Every change here is reversible from the admin Settings screen. To revert to "off" without deleting
the saved key: untick **تفعيل التكامل** and save. To remove the key entirely: tick
**إلغاء وحذف المفتاح المحفوظ** and save.

## What NOT to do

- Do not point MAWATHEQ at a public unauthenticated model endpoint.
- Do not guess a model name — a wrong name yields a `model not found` error that looks like an
  outage.
- Do not change production settings without the owner's approval.
- Do not send confidential case data to a provider the owner has not authorised.
