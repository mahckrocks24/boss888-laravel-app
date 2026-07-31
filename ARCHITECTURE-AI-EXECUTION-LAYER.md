# ARCHITECTURE — AI EXECUTION LAYER
_Serves as the **Architecture**, **AI Provider**, and **Runtime** documentation for LevelUp Growth._
_Established 2026-07-26 from evidence gathered during INC-2026-001. Supersedes assumptions in
`BOSS888-META-PROVIDER-BLOCKED-2026-07-22.md` regarding where provider calls execute._

> This document records **current architectural truth**, verified by live probe and production
> smoke test — not intended design. Where something is unverified, it says so.

---

## 1. THE CORE ARCHITECTURAL FACT

**Laravel orchestrates. The Railway Node runtime executes.**

```
┌──────────────────────────────────────────────────────────────┐
│ LARAVEL  (DigitalOcean droplet 134.209.93.41)                │
│ /var/www/levelup-staging  —  "staging" IS production         │
│                                                              │
│  Orchestrator → Engine Services (Write/CRM/Builder/Creative) │
│  Tenancy · credits · usage metering · persistence · auth     │
│  LaunchScopePolicy · RuntimeClient boundary sanitizer        │
│                                                              │
│  ✗ DOES NOT call AI providers directly (path near-dormant)   │
└───────────────────────────┬──────────────────────────────────┘
                            │ HTTPS + X-LevelUp-Secret
                            │ RUNTIME_URL / RUNTIME_SECRET
                            ▼
┌──────────────────────────────────────────────────────────────┐
│ RAILWAY NODE RUNTIME  "levelup-runtime"  v2.37.4-content     │
│ levelup-runtime2-production.up.railway.app                   │
│                                                              │
│  ✔ THE EXECUTION LAYER for all provider requests             │
│  Prompt assembly · agent loop · tool execution · streaming   │
│  Model selection · provider fallback · response validation   │
└───────┬──────────────────────────────────┬───────────────────┘
        ▼                                  ▼
  api.deepseek.com                   api.openai.com
  v4-flash (default) / v4-pro        gpt-4o · gpt-image-1
                                     gpt-4o-mini (fallback)
```

**Consequence, learned the hard way:** a provider-level change **cannot** be fixed by
patching Laravel. During INC-2026-001, `config/llm.php` and `DeepSeekConnector.php` both
contained the retired identifier, but patching them would have changed nothing a customer
could observe. **Always fix the runtime.**

**Why Laravel's direct path is dormant:** the "hands vs brain" refactor (2026-04-12,
Phase 2C-W1/W2) migrated essentially every call site from `DeepSeekConnector` to
`RuntimeClient`. The connector is still injected into ~12 services but its `chat()` method
is rarely reached.

---

> ⚠️ **GOVERNANCE OVERLAY (added 2026-07-26, P0-A).** A Governance layer is now *approved* for
> insertion between Laravel orchestration and privileged execution. Under **GD-001**, machine
> principals (Bella, and in future Engineering888) may **REQUEST** privileged actions but may
> **never execute them directly** — the Governance layer decides. This does not yet exist in
> code. See `GOVERNANCE-HARDENING-MASTERPLAN-P0.md`, `BELLA-GOVERNANCE-SPECIFICATION.md`, and
> `CAPABILITY-REGISTRY.md`. The AI execution path described below is unchanged.

## 2. ARCHITECTURAL DECISIONS (current truth, 2026-07-26)

| # | Decision | Status |
|---|---|---|
| **AD-01** | **The Railway runtime is the execution layer for provider requests.** All model selection, provider calls, streaming, tool execution, and response validation happen there. | LOCKED |
| **AD-02** | **Laravel orchestrates but does not directly execute provider calls.** It owns business logic, tenancy, credits, metering, persistence. The `DeepSeekConnector` direct path is legacy and should not gain new callers. | LOCKED |
| **AD-03** | **Railway is the production execution runtime.** Service `levelup-runtime`, NIXPACKS, `node index.js`, `/health` 10 s, `minInstances=2 / maxInstances=10`. | LOCKED |
| **AD-04** | **`deepseek-v4-flash` is the production default model** (`DEEPSEEK_DEFAULT_MODEL`). Applies to `/ai/run`, both write-stream paths, the async write queue, the planner, and `llm.js callLLM()`. | LOCKED |
| **AD-05** | **`deepseek-v4-pro` remains available via explicit tier selection** (`tier: "pro"` on `/ai/run`, or `PLANNER_TIER=pro`). **Nothing routes to Pro by default** — Pro's measured reasoning cost is ~6× flash. | LOCKED |
| **AD-06** | **The runtime validates structured output.** JSON-mode responses are parsed and validated (empty · invalid · truncated · wrong top-level type · missing required fields) and rejected with `DEEPSEEK_INVALID_STRUCTURED_OUTPUT`. Malformed output is never returned as success. | LOCKED |
| **AD-07** | **The runtime rejects empty final content.** HTTP 200 with empty/whitespace/null content is a failure (`DEEPSEEK_EMPTY_FINAL_CONTENT`), **except** a tool-call turn, which legitimately has empty content. | LOCKED |
| **AD-08** | **The runtime centralizes model selection** in `deepseek-models.js`. No model identifier may be hardcoded at a call site. Retired names are rejected before the wire with `DEEPSEEK_INVALID_MODEL`. | LOCKED |
| **AD-09** | **Thinking mode is a model capability, not a provider.** V4 reasoning is always-on and cannot be disabled; there is no separate "reasoner" model to route to. | LOCKED |
| **AD-10** | **Token budgets must carry reasoning headroom.** `withReasoningHeadroom()` adds `REASONING_HEADROOM` (2000) bounded by `MAX_TOKENS_CAP` (8192). | LOCKED |
| **AD-11** | **Provider attribution is explicit at the runtime boundary** — `requested_provider`, `actual_provider`, `requested_model`, `actual_model`, `fallback_used`, `fallback_reason`. **Laravel does not yet consume these (TD-01).** | PARTIAL |
| **AD-12** | **The OpenAI fallback is retained on `/ai/run` only.** No fallback exists on `/internal/write/draft` and none may be added without Boss approval. | LOCKED |

---

## 3. AI PROVIDER REGISTRY

### 3.1 DeepSeek (primary — text, JSON, tools, agent loop)

| Property | Value |
|---|---|
| Endpoint | `https://api.deepseek.com/v1/chat/completions` |
| Models | **`deepseek-v4-flash`** (default) · `deepseek-v4-pro` (tier-selected) |
| Retired 2026-07-24 15:59 UTC | `deepseek-chat`, `deepseek-reasoner` → **HTTP 400** |
| Auth | `Authorization: Bearer $DEEPSEEK_API_KEY` |
| Reasoning | **Always on, not disableable.** `reasoning_content` always present |
| Token accounting | `reasoning_tokens` counted **inside** `completion_tokens` → consumes `max_tokens` |
| Streaming | SSE; deltas carry `reasoning_content` with `content: null` |
| Tool calling | Supported on both tiers, including *during* reasoning |
| Structured output | `response_format: {type: "json_object"}` — requires the literal word "json" in the prompt |
| Pricing | 🔴 **UNVERIFIED for V4.** Laravel still hardcodes V3 rates ($0.14 in / $0.28 out per 1M) — TD-02 |

**Measured reasoning overhead (2026-07-26, real LevelUp workloads):**
flash **70–324 tokens** · pro **1238 tokens** on an identical planner prompt.

### 3.2 OpenAI (images, vision, fallback)

| Use | Model | Where |
|---|---|---|
| Image generation | `gpt-image-1` | `/internal/image/generate` |
| Vision analysis | `gpt-4o` | `/internal/vision/analyze` |
| **Fallback only** | `gpt-4o-mini` | `/ai/run` catch block |
| `llm.js` OpenAI provider | `gpt-4o` | when `LLM_PROVIDER=openai` |

### 3.3 Model selection contract (`deepseek-models.js`)

```js
DEFAULT_MODEL = process.env.DEEPSEEK_DEFAULT_MODEL || 'deepseek-v4-flash'
PRO_MODEL     = process.env.DEEPSEEK_PRO_MODEL     || 'deepseek-v4-pro'
SUPPORTED_MODELS = { deepseek-v4-flash, deepseek-v4-pro }
RETIRED_MODELS   = { deepseek-chat, deepseek-reasoner, deepseek-coder }
REASONING_HEADROOM = 2000    // env DEEPSEEK_REASONING_HEADROOM
MAX_TOKENS_CAP     = 8192    // env DEEPSEEK_MAX_TOKENS_CAP

resolveModel(tier)            // 'flash' | 'pro' → model id, throws on unknown
resolveRequestedModel(m)      // explicit model if valid, else DEFAULT_MODEL
assertModelSupported(m)       // throws DEEPSEEK_INVALID_MODEL on retired/unknown
withReasoningHeadroom(n, fb)  // n + HEADROOM, capped
assertUsableContent(c, meta)  // throws DEEPSEEK_EMPTY_FINAL_CONTENT (tool-call exempt)
parseStructuredOutput(c, o)   // throws DEEPSEEK_INVALID_STRUCTURED_OUTPUT
extractUsage(usage)           // input / reasoning / final_output / total — null, never invented
redactSample(text)            // strips sk-* and Bearer * before diagnostics
```

**Error codes:** `DEEPSEEK_INVALID_MODEL` (400) · `DEEPSEEK_EMPTY_FINAL_CONTENT` ·
`DEEPSEEK_INVALID_STRUCTURED_OUTPUT` (502).

---

## 4. RUNTIME DOCUMENTATION

### 4.1 Service facts
| Property | Value |
|---|---|
| Name | `levelup-runtime` (`package.json`) |
| Host | `levelup-runtime2-production.up.railway.app` |
| `/health` version | **`2.37.3`** — hardcoded at `index.js:491`, **deliberately not bumped** for v2.37.4 |
| Startup banner | `v2.28.0` — pre-existing cosmetic staleness (TD-11) |
| Node | `>=18` (deployed: v20) |
| Deps | bullmq, express, ioredis, axios, dotenv, uuid, cheerio |
| Source of truth | **archive only — no git repo, no lockfile** (TD-08, TD-09) |
| Local package | `C:\Users\markr\LVL\runtime-v2.37.4-deepseek-v4\levelup-runtime2-main\` (89 files) |
| Prior version | `C:\Users\markr\LVL\runtime-pkg-2.37.3\` |

### 4.2 Endpoints that reach a provider
| Endpoint | Model source | Stream | JSON | Fallback |
|---|---|---|---|---|
| `/ai/run` (12 tasks) | `resolveModel(tier)` / `resolveRequestedModel()` | no | `chat_json` | **gpt-4o-mini** |
| `/internal/write/draft` | `DEFAULT_MODEL` | no | no | **none** |
| `/internal/write/improve`, `/rewrite` | `callLLM()` | no | no | none |
| `/internal/write/stream` | `DEFAULT_MODEL` | yes | no | none |
| `/internal/write/stream-init` | `DEFAULT_MODEL` | yes | no | none — **⚠ broken, see 4.4** |
| `/internal/image/generate` | `gpt-image-1` | no | no | none |
| `/internal/vision/analyze` | `gpt-4o` | no | no | none |
| meeting-room / synthesis | `callLLM()` | no | no | none |
| `lu-planner.js` | `resolveModel(PLANNER_TIER \|\| 'flash')` | no | `json_object` | none |
| `lu-worker-manager.js` | `DEFAULT_MODEL` | no | no | none |

### 4.3 Environment variables
**Required:** `DEEPSEEK_API_KEY` · `OPENAI_API_KEY` · `REDIS_URL` · `WP_SECRET` · `LU_SECRET`
**Optional (all have safe defaults):** `DEEPSEEK_DEFAULT_MODEL` · `DEEPSEEK_PRO_MODEL` ·
`DEEPSEEK_REASONING_HEADROOM` · `DEEPSEEK_MAX_TOKENS_CAP` · `PLANNER_TIER` · `LLM_PROVIDER`
· `ENABLED_WORKERS` · worker concurrency vars

**Laravel side:** `RUNTIME_URL` · `RUNTIME_SECRET` (= runtime's `WP_SECRET`) ·
`RUNTIME_TIMEOUT=120` · `INTELLIGENCE_VIA_RUNTIME=true`.
⚠️ **Never `php artisan config:cache`** — `RuntimeClient` reads `env()` directly. Use `config:clear`.

### 4.4 ⚠️ Streaming is effectively dead code — do not assume it works
- **`/internal/write/stream-init` is broken (pre-existing).** The route is registered at
  `index.js:271` but the body parser at `index.js:438`; Express applies middleware in
  registration order, so `req.body` is **always undefined** and the handler always returns
  `"title or brief required"`. Verified byte-identical to the pre-patch baseline
  (sha `ecb5f7fba969b658`) — **not** caused by the V4 patch.
- **`/internal/write/stream` requires `jti` + `callback_url`** and is callback-driven.
- **Laravel calls neither.** No `internal/write/stream` references in `app/` or `routes/`,
  and `RuntimeClient` has no streaming method. Laravel's own `api/internal/write/stream-chunk`
  and `stream-poll` are *receivers*, not initiators.
- ⇒ The V4-patched streaming lines (`index.js:201`, `:2995`) are **unexercised**. TD-06.

### 4.5 Known runtime defects (all pre-existing)
| Defect | Detail |
|---|---|
| `stream-init` body parser | §4.4 · TD-05 |
| `lu-task-worker-fn` missing | `lu-worker-manager.js:53` try/catches a `require` of a file that does not exist → `processors.agent_user` permanently null. TD-12 |
| Version drift | banner `2.28.0` / `/health` `2.37.3` / package content `2.37.4`. TD-11 |
| No lockfile | NIXPACKS re-resolves `^` ranges each build. TD-08 |
| `ai-complete-endpoint.js` | dead code — bare `app.post` at top level, never required, missing `/v1` in its URL |

---

## 5. METERING & COST

**Path:** runtime returns `usage` → Laravel `RuntimeClient::logApiUsage()` → `api_usage_logs`
→ `estimateApiCost()`.

**Working:** `api_usage_logs` records `deepseek-v4-flash` post-fix (verified: 26 calls,
7,180 tokens). Runtime emits the full usage split including `reasoning_tokens`.

**Broken (TD-01, TD-02, TD-03):**
1. `RuntimeClient.php:373` hardcodes `logApiUsage('deepseek', …)` — an OpenAI fallback is
   still recorded as DeepSeek. The runtime now emits `actual_provider`; Laravel ignores it.
2. `estimateApiCost()` uses **V3** rates for DeepSeek.
3. `estimateApiCost()` matches on `provider`, not model — 70 `gpt-4o-mini` fallback calls
   were priced at DeepSeek rates.
4. `reasoning_tokens` are not stored in `api_usage_logs` (no column).

**Customer credits are unaffected.** `ChatbotResponseService::CREDIT_COST_PER_MESSAGE = 1`
is a flat per-message charge decoupled from tokens. **The exposure is margin, not billing
correctness.**

---

## 6. HOW TO CHANGE THE MODEL (the procedure that did not exist before)

1. **Never** hardcode a model identifier at a call site. Add it to `deepseek-models.js`.
2. To switch the default without a code change: set `DEEPSEEK_DEFAULT_MODEL` in Railway.
3. To route one workload to Pro: pass `tier: "pro"`, or set `PLANNER_TIER=pro`.
4. Verify a model exists before adopting it: `GET https://api.deepseek.com/models`.
5. Deploy per `RUNTIME-2.37.4-DEEPSEEK-V4-DEPLOY-AND-ROLLBACK.md`.
6. Prove the deploy took by sending a **retired** model name and expecting
   `DEEPSEEK_INVALID_MODEL` — `/health` is not a valid deploy signal.
