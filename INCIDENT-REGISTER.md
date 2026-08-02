# LevelUp Growth — INCIDENT REGISTER

Chronological register of production incidents. Newest first.
Created 2026-07-26 (no register previously existed).

**Convention:** one section per incident, id `INC-YYYY-NNN`. Every claim must cite
evidence produced during the incident, not recollection.

---

# INC-2026-001 — DeepSeek model retirement breaks AI content generation

| Field | Value |
|---|---|
| **Severity** | **P1 — customer-facing feature outage** (no data loss, no security impact) |
| **Status** | **RESOLVED** 2026-07-26 |
| **Detected** | 2026-07-26 ~07:45 UTC (during unrelated SSH session; **not** by monitoring) |
| **Actual onset** | 2026-07-24 ~13:37 UTC |
| **Undetected for** | **~42 hours** |
| **Resolved** | 2026-07-26 ~08:45 UTC (Railway deploy by Boss) |
| **Verified** | 2026-07-26 09:05 UTC |
| **Execution layer** | Railway Node runtime `levelup-runtime` v2.37.3 |
| **Laravel involvement** | **None causally.** Laravel was the victim, not the cause. |

---

## 1. Root cause

DeepSeek retired the legacy API model identifiers `deepseek-chat` and `deepseek-reasoner`
on **2026-07-24 15:59 UTC**. Both now return **HTTP 400**:

```
{"error":{"message":"The supported API model names are deepseek-v4-pro or
deepseek-v4-flash, but you passed deepseek-chat.",
"type":"invalid_request_error","code":"invalid_request_error"}}
```

The Railway Node runtime had `'deepseek-chat'` **hardcoded as a string literal in 8 places**.
It had no environment-variable indirection and no model registry, so there was no way to
change the model without a code change and redeploy.

`GET https://api.deepseek.com/models` now returns exactly two models:
```json
{"object":"list","data":[
 {"id":"deepseek-v4-flash","object":"model","owned_by":"deepseek"},
 {"id":"deepseek-v4-pro","object":"model","owned_by":"deepseek"}]}
```

**Contributing factors:**
1. **Hardcoded model identifiers** — 8 literals, no central registry, no env override.
2. **No provider-error alerting** — task failures were caught and recorded as task rows, so
   `failed_jobs` stayed at 0 and nothing paged anyone. This is why it ran ~42 h undetected.
3. **Partial fallback masked the blast radius** — `/ai/run` silently degraded to OpenAI
   `gpt-4o-mini` while `/internal/write/draft` hard-failed, so the system looked
   "partly working" rather than broken.

---

## 2. Discovery timeline (UTC, 2026-07-26)

| Time | Event | Evidence |
|---|---|---|
| 07:39 | SSH session opened for an unrelated task | `auth.log` |
| ~07:45 | Repo-wide audit for DeepSeek identifiers begins | audit report §4 |
| ~07:50 | `config/llm.php:10` found: `env('DEEPSEEK_MODEL','deepseek-chat')`; `DEEPSEEK_MODEL` **not set** in `.env` | resolved-config probe |
| ~07:55 | **Live API probe confirms the retirement is real** — `deepseek-chat` → 400, `v4-flash`/`v4-pro` → 200 | `/models` + 4 chat probes |
| ~08:00 | `laravel.log` shows the 05:00 scheduled run failing on the retired name | log extract §3 |
| ~08:05 | **Architectural discovery:** the failing call is `RuntimeClient::writeDraft` → Railway, *not* Laravel's `DeepSeekConnector` | trace in log |
| ~08:10 | Runtime source located; **8 hardcoded literals** found (initial audit under-reported 6 — `index.js:588`, the `/ai/run` default, was missed and corrected) | `grep` inventory |
| ~08:15 | **V4 behaviour probes:** reasoning always-on, `reasoning_tokens` billed inside `completion_tokens`, HTTP 200 + empty content possible | capability probes §8 |
| ~08:20 | Reasoning cost **measured** on real workloads (flash 70–324, **pro 1238**) | measurement table §3.1 |
| ~08:30 | Patch applied to an isolated copy; 50/50 tests pass (43 offline + 7 live) | test output |
| ~08:35 | **Deployment blocked** — no Railway CLI/token anywhere | pre-deployment report §5 |
| ~08:45 | **Boss deploys to Railway** | — |
| 08:47 | Deploy proof: `deepseek-chat` → `DEEPSEEK_INVALID_MODEL` | smoke §2 |
| 09:05 | **47 tasks completed, 0 failed** in 90 min | task query |

---

## 3. Production impact

**Failure signature in `laravel.log` (2026-07-26 05:00, the daily scheduled run):**
```
staging.WARNING: RuntimeClient::writeDraft non-2xx or success=false
  {"http_code":500,"body":{"success":false,"error":"AI generation failed:
   LLM API 400: The supported API model names are deepseek-v4-pro or
   deepseek-v4-flash, but you passed deepseek-chat."}}
staging.ERROR: Orchestrator failed for task 2595
  {"action":"write_article","error":"Step 1 (write_article) failed:
   Failed after 3 attempts: ..."}
```

**Task outcomes, 72 h before the fix:**

| status | count |
|---|---|
| completed | 179 |
| **failed** | **36** |
| blocked | 63 |
| cancelled | 13 |
| pending | 5 |
| degraded | 1 |

**`api_usage_logs` — the smoking gun:** `deepseek-chat` stops dead at **2026-07-24 13:37:23**
(3,651 lifetime calls). `gpt-4o-mini` continues to 2026-07-26 05:00:28 (70 calls) — the
silent fallback.

### Affected workspaces (failed tasks, 72 h)
| workspace | failed |
|---|---|
| 990003 (INFRA888 Test) | 10 |
| 2 (Chef Red Raymundo) | 5 |
| 1 (LevelUp Growth) | 3 |
| **7 (Shukran Group — customer)** | 3 |
| 27 (Boss Mac Architecture) | 3 |
| 29 (Boss Gym) | 3 |
| 990061 (Inkredible Tattoo) | 3 |
| 990060 (Inkredible Tattoo) | 2 |
| 26 (AMG Global Travel) | 1 |
| 28 (Alex Clothing Store) | 1 |

### Affected endpoints
| Endpoint | Behaviour during outage |
|---|---|
| `/internal/write/draft` | **HARD FAIL** — no fallback, 3 retries then task failure |
| `/ai/run` (12 task types) | **SILENT DEGRADE** → OpenAI `gpt-4o-mini`, mis-attributed as DeepSeek |
| `llm.js callLLM()` (meeting room, synthesis, agents) | **HARD FAIL** |
| `lu-planner.js` (autonomous planner) | **HARD FAIL** |
| `lu-worker-manager.js` (async write queue) | **HARD FAIL** |
| Laravel `DeepSeekConnector` (direct path) | would fail; nearly all call sites already migrated to RuntimeClient |

**Not affected:** image generation (`gpt-image-1`), vision (`gpt-4o`), Stripe, CRM, Builder
publishing, hosting/INFRA888, PTAA.

---

## 4. Why the outage occurred, and why Laravel was not the problem

Laravel **orchestrates** AI work; it does not **execute** provider calls. The chain is:

```
Laravel (Orchestrator → WriteService → RuntimeClient)
   → HTTPS + X-LevelUp-Secret
      → Railway Node runtime (levelup-runtime v2.37.3)
         → api.deepseek.com          ← the retired identifier was sent HERE
```

`config/llm.php` and `app/Connectors/DeepSeekConnector.php` do contain `deepseek-chat`, but
that path is nearly dormant — the "hands vs brain" refactor (2026-04-12, Phase 2C-W1/W2)
moved essentially every call site to `RuntimeClient`. **Patching Laravel alone would have
changed nothing a customer could observe.** This was the single most important finding of
the audit and it inverted the initial assumption of the task.

**Why Railway is the actual execution layer:** the runtime owns the provider SDK calls, the
prompt assembly (`buildWritePrompt`), the agent loop, tool execution, streaming, and the
OpenAI fallback. Laravel holds business logic, tenancy, credits, persistence, and metering.

---

## 5. Runtime architecture (as discovered)

- **Service:** `levelup-runtime` v2.37.3 · Railway · NIXPACKS · `node index.js` · `/health` (10 s)
- **Scaling:** `minInstances=2`, `maxInstances=10` (`railway.toml`)
- **Source of truth:** archive only — **no git repository**, **no lockfile**
- **Auth:** `X-LevelUp-Secret` = `WP_SECRET`; Laravel sends `RUNTIME_SECRET`
- **8 DeepSeek call sites** (pre-fix, all `'deepseek-chat'`):

| File:line | Purpose | Streaming | JSON | Fallback |
|---|---|---|---|---|
| `llm.js:14` | `PROVIDERS.deepseek.model` → `callLLM()` | no | no | none |
| `llm.js:15` | `visionModel` | no | no | none |
| `index.js:201` | write-draft stream #1 | yes | no | none |
| `index.js:588` | **`/ai/run` `DEFAULT_MODEL`** | no | `chat_json` | gpt-4o-mini |
| `index.js:2995` | write stream #2 | yes | no | none |
| `lu-planner.js:125` | autonomous planner | no | `json_object` | none |
| `lu-worker-manager.js:95` | async write queue | no | no | none |
| `ai-complete-endpoint.js:26` | `/internal/ai/complete` — **dead code** | no | no | none |

---

## 6. DeepSeek V4 API changes (all verified by live probe)

| Capability | flash | pro | Note |
|---|---|---|---|
| Basic completion | ✅ | ✅ | ~1.0–1.4 s |
| Structured JSON (`json_object`) | ✅ | ✅ | |
| Tool calling | ✅ | ✅ | |
| Tool calls **during** reasoning | ✅ | ✅ | `reasoning_content` + `tool_calls` in one message |
| Streaming (SSE) | ✅ | ✅ | deltas carry `reasoning_content`, `content: null` |
| Thinking mode | always-on | always-on | **cannot be disabled** |
| Invalid-model error | HTTP 400 | HTTP 400 | names both valid models |

### The dangerous change: reasoning consumes `max_tokens`

`reasoning_tokens` are counted **inside** `completion_tokens`, therefore inside `max_tokens`.
When the budget is exhausted by reasoning the API returns **HTTP 200 with `content: ""` and
`finish_reason: "length"`** — a silent empty success.

Proof (`max_tokens: 50`, prompt `"hi"`): all 50 tokens went to reasoning, `content` empty.

**Measured reasoning cost on real LevelUp workloads (2026-07-26):**

| Workload | Model | max_tokens | reasoning | final content | finish |
|---|---|---|---|---|---|
| write/long | flash | 1600 | 70 | 1230 tok | stop |
| write/long | flash | 3600 | 324 | 1000 tok | stop |
| planner JSON | flash | 1500 | 246 | 661 tok | stop |
| planner JSON | flash | 3500 | 194 | 644 tok | stop |
| **planner JSON** | **pro** | 3500 | **1238** | 586 tok | stop |
| ai_run | flash | 1200 | 98 | 860 tok | stop |

**Two conclusions that corrected the initial risk assessment:**
1. **Flash at existing budgets was never actually at risk** — every realistic flash workload
   finished with `finish_reason: stop` and non-empty content even before headroom was added.
   The "90 % reasoning" figure came from 1-token probe prompts and overstated the risk.
2. **Pro genuinely would have broken** — 1238 reasoning + 586 content = 1824 needed against
   the planner's historic 1500 budget. This is why **flash is the restoration default** and
   nothing was auto-migrated to Pro.

**Headroom is near cost-neutral:** raising the ceiling does not raise typical spend because
the model stops naturally — write/long used 1300 completion tokens at a 1600 budget and 1324
at 3600. A **125 % ceiling increase produced a 1.8 % spend increase.**

---

## 7. Resolution — runtime v2.37.4

**New module `deepseek-models.js` (276 lines)** — single registry:
`DEEPSEEK_DEFAULT_MODEL` (default `deepseek-v4-flash`), `DEEPSEEK_PRO_MODEL`
(`deepseek-v4-pro`), `RETIRED_MODELS` set, `REASONING_HEADROOM` (2000, env-overridable),
`MAX_TOKENS_CAP` (8192 — keeps the ceiling bounded), plus `resolveModel()`,
`assertModelSupported()`, `withReasoningHeadroom()`, `assertUsableContent()`,
`parseStructuredOutput()`, `extractUsage()`, `redactSample()`.

**Changed files (6 changed · 2 new · 1 removed · 81 byte-identical):**

| File | Change |
|---|---|
| `deepseek-models.js` | NEW — registry + guards |
| `index.js` | +94 −20 · `/ai/run` model + 2 stream sites + fallback attribution + guards |
| `llm.js` | +30 −7 · registry, headroom (DeepSeek only), empty-content guard, usage split |
| `lu-planner.js` | +23 −6 · model, headroom, strict structured-output validation |
| `lu-worker-manager.js` | +3 −2 · model + headroom |
| `ai-complete-endpoint.js` | +2 −2 · dead-code hygiene |
| `.railway-trigger` | appended `v2.37.4-deepseek-v4-restoration 2026-07-26` |
| `test-deepseek-v4.js` | NEW — 50-test suite (test-only, never required at runtime) |
| `index.js.bak2` | **REMOVED** — stray 89 KB backup that should never have shipped |

**New error codes:** `DEEPSEEK_INVALID_MODEL` · `DEEPSEEK_EMPTY_FINAL_CONTENT` ·
`DEEPSEEK_INVALID_STRUCTURED_OUTPUT`.

**Behaviour change worth knowing:** `/ai/run` `chat_json` previously returned
`success: true, parsed: null` on malformed JSON. It now returns **HTTP 502** with an error
code. Previously-silent garbage results will surface as visible failures. This is the fix,
not a regression.

---

## 8. Deployment & rollback

**Deployment method:** Railway has **no ZIP upload and no drag-and-drop**. Only
GitHub-connected repo, Railway CLI (`railway up`), or Docker image. Deployed 2026-07-26
~08:45 UTC by Boss. Full procedure: `RUNTIME-2.37.4-DEEPSEEK-V4-DEPLOY-AND-ROLLBACK.md`.

**Deploy proof** (`/health` still reports `2.37.3` — version deliberately not bumped, so it
is NOT a valid deploy signal):
```
POST /ai/run {"model":"deepseek-chat"}
→ HTTP 400 {"code":"DEEPSEEK_INVALID_MODEL", ...}
```

**Rollback:** Railway → Deployments → redeploy the prior deployment ID (~1 min).
Source backup: `LVL\runtime-pkg-2.37.3\`. The patch is additive and fully reversible.
⚠️ **Rolling back restores `deepseek-chat` — i.e. restores the outage.**

---

## 9. Smoke-test results (production, 2026-07-26 08:47–09:05 UTC)

| # | Test | Result |
|---|---|---|
| 1 | `/health` | ok · 2.37.3 · 10 agents · 43 tools |
| 2 | **Deploy proof** — `deepseek-chat` | **400 `DEEPSEEK_INVALID_MODEL`** ✅ |
| 3 | `/ai/run` chat_json | `deepseek-v4-flash`, parsed OK, usage `{in 67, reasoning 100, final 15}` ✅ |
| 4 | `tier=pro` override | `deepseek-v4-pro` ✅ |
| 5 | Plain-text task | 3,056 chars, `fallback_used: false` ✅ |
| 6 | `deepseek-reasoner` | 400 ✅ |
| 7 | Invalid tier `turbo` | 400, lists valid tiers ✅ |
| 8 | **`RuntimeClient::writeDraft`** (the failing endpoint) | **5,556 chars** ✅ |
| 9 | `RuntimeClient::chatJson` | parsed OK ✅ |
| 10 | `RuntimeClient::aiRun` | 5,768 chars ✅ |
| 11 | **Full Orchestrator task** (ws 990003) | **`completed`**, article persisted, test data removed ✅ |
| 12 | `api_usage_logs` | records `deepseek-v4-flash` — 26 calls, 7,180 tokens ✅ |
| 13 | Secret leakage | none ✅ |

**Post-deploy, 90 minutes:** **47 completed · 3 running · 38 queued · 0 FAILED · 0
DeepSeek-related failures.** `failed_jobs` 0. Autonomous agents resumed unaided across
workspaces 26, 27, 29, 990003.

**Failure comparison:** 36 failed / 72 h → **0 failed / 90 min**.

### Two false alarms during verification — both test-harness errors, not system faults
1. `aiRun` appeared to return 0 chars — the debug script read `output`; the method returns
   `text`. Real response 5,768 chars.
2. A task failed `Unsupported operand types: string - int` — the test payload sent
   `length: 'short'`; `WriteService.php:514` expects an integer word count and line 543
   computes `$length - 100`. Re-run with `length: 900` → completed.

---

## 10. Remaining technical debt (created or exposed by this incident)

| id | Item | Severity |
|---|---|---|
| TD-01 | **Laravel provider attribution** — `RuntimeClient.php:373` hardcodes `logApiUsage('deepseek', …)`. Runtime now emits `actual_provider`/`actual_model`/`fallback_used`, Laravel ignores them. OpenAI fallbacks still record as DeepSeek. | High |
| TD-02 | **V4 pricing unverified** — `RuntimeClient.php:1207` still uses V3 rates ($0.14/$0.28 per 1M). Every DeepSeek cost figure in admin is wrong. Reasoning bills as output, so real cost is higher. | High |
| TD-03 | **`estimateApiCost()` keys on `provider`, not model** — the 70 `gpt-4o-mini` fallback calls were costed at DeepSeek rates. | High |
| TD-04 | **No provider-error alerting** — root cause of the ~42 h detection gap. Failures land in `tasks.error_text`, never in `failed_jobs`. | Critical |
| TD-05 | **`/internal/write/stream-init` is broken** — route registered `index.js:271`, body parser `index.js:438`; `req.body` always undefined. **Pre-existing** (byte-identical to baseline, sha `ecb5f7fba969b658`). Laravel calls neither streaming route. | Medium |
| TD-06 | **Runtime streaming unverified** — patched lines at `index.js:201/2995` are unexercised because both streaming routes are unreachable from Laravel. | Medium |
| TD-07 | **V4 vision unverified** — `llm.js:15` `visionModel` set to flash but never probed. `/internal/vision/analyze` uses `gpt-4o`, so exposure believed nil. | Medium |
| TD-08 | **No lockfile** — NIXPACKS re-resolves `^` ranges every build; a rebuild can pull new transitive deps. Pre-existing. | Medium |
| TD-09 | **Runtime has no git repository** — archive-only source, no commit history, no diff review before deploy. | Medium |
| TD-10 | **213 failed + 78 blocked tasks** never replayed, incl. 2595/2599 (ws 7, Shukran Group). | High |
| TD-11 | **Startup banner reports `v2.28.0`** while `/health` reports 2.37.3 and `package.json` 2.37.4-content. Three-way version drift. Cosmetic. | Low |
| TD-12 | **`lu-worker-manager.js:53` requires missing `./lu-task-worker-fn`** — try/caught → `processors.agent_user` is permanently null. Pre-existing, byte-identical to baseline. | Medium |
| TD-13 | **No Laravel-side DeepSeek tests** — 0 of 60 test files reference DeepSeek. | Medium |

---

## 10a. GOVERNANCE OUTCOME (added 2026-07-26, P0-A)

This incident is the origin of the **Governance Hardening phase (P0)**. Two findings became
binding governance decisions:

- **TD-04 (no provider-error alerting)** — the structural cause of the ~42 h detection gap — is
  now **M5**, a MANDATORY Engineering888 dependency. Alerting must watch `tasks.error_text` /
  `task_events`, **never** `failed_jobs`, which read 0 throughout the outage while 904
  `task.execution_failed` audit rows accumulated.
- The incident's demonstration that **Laravel-side AI config is decorative** (the retired
  `deepseek-chat` still sits in `config/llm.php` with no production effect) informed
  **GD-007 / AZ-3**: route- or config-level protection alone is insufficient; controls must fail
  closed at the point of execution.

See `GOVERNANCE-HARDENING-MASTERPLAN-P0.md` and `GOVERNANCE-DECISION-REGISTER.md`.

---

## 11. Follow-up work

See **KNOWN REMAINING WORK** in `START-HERE-2026-07-26.md` for the prioritised list.

**Immediate decisions needed from Boss:**
1. Replay policy for the 213 failed / 78 blocked tasks (TD-10).
2. V4 pricing lookup so cost/margin figures become truthful (TD-02).
3. Whether to keep the OpenAI fallback (recommend **keep** — genuine resilience — but fix
   attribution and log it loudly).
4. Whether to bump `/health` to `2.37.4` (nothing in Laravel asserts on the version string).

---

## 12. Lessons

1. **Know where execution actually happens.** Two days of the task framing assumed Laravel.
   The evidence inverted it. Audit the call path, never the assumption.
2. **A silent fallback is worse than a hard failure.** `/ai/run` degrading to `gpt-4o-mini`
   with DeepSeek attribution hid both the outage and the cost.
3. **HTTP 200 is not proof of usable output.** V4 can return 200 with empty content.
4. **Measure, don't extrapolate.** Trivial-prompt probes suggested 90 % reasoning overhead;
   real workloads showed 4–20 % for flash. The measurement changed the design.
5. **Hardcoded model identifiers are an outage waiting for a vendor deprecation.**


## INC-2026-006 — Production database emptied by an Engineer888 verification run

**Severity:** P0 · **Date:** 2026-07-30 · **Detected:** 13:52 UTC · **Restored:** 13:58 UTC
**Caused by:** Engineer888 Commit Execution Engine (Sprint 3), first live execution
**Data lost:** ~12h47m (backup 01:00 UTC → incident 13:47 UTC): 10 tasks, 34 task_events, 1 api_usage_log

### What happened

`engineering:commit --group=26` committed 27 files correctly, then ran its selected
verification: `php artisan test -c phpunit.e888.xml <3 test files>`, executed via
`exec()` from inside the running `artisan engineering:commit` process.

That command is correct from a shell — it targets `levelup_e888_test`. Nested inside
a booted Laravel process it targeted `levelup_staging`. `RefreshDatabase` ran
`migrate:fresh`, dropping and recreating all 203 tables empty.

### Root cause — two independent defects

**1. `$_SERVER` leaks the parent's database into the child.**
The parent artisan process exports `DB_DATABASE=levelup_staging` into `$_SERVER`.
`exec()` passes the parent environment to the child. PHPUnit's `<env force="true">`
sets `putenv()` and `$_ENV` but **not** `$_SERVER`, and Laravel's env repository reads
`$_SERVER`. Established by probe, not inference: in the nested child,
`getenv('DB_DATABASE')` and `$_ENV['DB_DATABASE']` both correctly read
`levelup_e888_test` while `$_SERVER['DB_DATABASE']` read `levelup_staging`. The two
correct readings are why this was invisible.

**2. `ProductionDatabaseGuard` ran after the damage it exists to prevent.**
`tests/TestCase::setUp()` called `parent::setUp()` — which boots the app and then runs
`setUpTraits()`, where `RefreshDatabase` executes `migrate:fresh` — and only then
called `assertSafeTestTarget()`. The guard added for INC-2026-002 fired correctly and
reported the disaster it was written to stop. Latent since 2026-07-27.

### Recovery

`/root/backups/db-20260730-0100.sql.gz` (7.6 MB, gzip-verified, 201 tables, also
mirrored to S3). Restored at 13:58 UTC. The emptied state was itself dumped first to
`db-EMPTIED-20260730-135724.sql.gz`. The three Engineer888 tables were dropped and
re-migrated individually so schema and the `migrations` table agree.
`2026_07_29_130000_add_execution_provenance_to_api_usage_logs` remains **Pending**, as
required.

Verified after restore: users 11, workspaces 25, tasks 2450, task_events 12721,
api_usage_logs 3869, websites 3, subscriptions 24; site 200, `/app/` 200, all three
workers RUNNING.

### Fixes

| Layer | Fix | Proof |
|---|---|---|
| Guard order | `tests/TestCase` overrides `setUpTraits()`, asserting before any trait runs | Canary row in a scratch database survived a deliberately mis-pointed run |
| Environment | `TestSelector::sanitisedTestCommand()` prefixes `env -u DB_* -u APP_ENV …` so nothing can be inherited | Nested run resolves to `levelup_e888_test`; regression test asserts each variable |
| Target selection | `configFor()` rejects any config whose `DB_DATABASE` is a production name or is not recognisably a test database | Table-driven test over 6 database names |
| Fail closed | With no provably isolated config, tests are reported **NOT RUN** rather than run | Regression test asserts `covering-tests-skipped` and that it executes nothing |

### What this changes about how Engineer888 works

Engineer888 will not execute a test suite it cannot prove is isolated. Skipping
verification and saying so is now the safe branch; the previous behaviour treated
running the tests as unconditionally safe, and it was not.

### Not fixed here

Any other code path that shells out to `artisan test` from inside a booted process has
the same `$_SERVER` exposure. Only the Engineer888 path is fixed. Defect 2 protects the
rest, but a repository-wide check for nested test invocations is outstanding.


## INC-2026-006 — Engineering Safety Sweep (closure)

**Date:** 2026-07-31 · **Scope:** entire LevelUp Growth platform · **Instrument:** `tools/exec-safety-audit.php`
**Result:** 1,184 PHP files scanned · **0 UNSAFE** · 2 UNKNOWN (both hand-verified) · 126 SAFE

### The defect class

A child process inherits `DB_DATABASE` through **`$_SERVER`**. PHPUnit's `<env force="true">`
sets `putenv()` and `$_ENV` but not `$_SERVER`, and Laravel's env repository reads `$_SERVER`.
Measured, not inferred: in the nested child, `getenv()` and `$_ENV` both read
`levelup_e888_test` while `$_SERVER` read `levelup_staging`.

Two further facts were measured during the sweep:

- A bare Laravel boot with no environment resolves to **`levelup_staging`**. Any unguarded
  PHP child that boots the framework reaches production by default.
- `putenv()` in a parent **does** propagate to children. So forcing works downward; the
  failure was specifically the inherited real environment beating the forced value.

Therefore **overriding is not sufficient — the variable must be absent.** `env -u`.

### Inventory and classification

| Category | Count | Verdict |
|---|---|---|
| process spawn (exec/shell_exec/proc_open/popen/system/backtick) | 43 | 41 SAFE, 2 UNKNOWN |
| in-process `Artisan::call` / `$this->call` | 22 | 22 SAFE — none destructive |
| phpunit configurations | 5 | 5 SAFE (was 1 UNSAFE) |
| `RefreshDatabase` test classes | 58 | 58 SAFE — all inherit the guard |

Excluded as **not live code**, verified rather than assumed: `_backup/` (7 MB, not autoloaded,
not routed, not web-reachable, not included by anything) and `storage/` (runtime state).
They contain 43 spawn sites in stale copies; counting them buried the ~20 that execute.

### Defects found and corrected

| # | Defect | Severity | Fix | Evidence |
|---|---|---|---|---|
| 1 | Guard ran after `RefreshDatabase` | **P0** | `Tests\TestCase::setUpTraits()` asserts before any trait | Suite pointed at a refused DB reports `REFUSING TO RUN`, never `Unknown database` |
| 2 | `TestSelector` spawned a Laravel child without stripping env | **P0** | `sanitisedTestCommand()` — `env -u` for every DB_* and APP_ENV | Nested run resolves to `levelup_e888_test` |
| 3 | `phpunit.integration.xml`: **0 of 7** env entries forced, suite = all of `tests/Feature` (126 RefreshDatabase classes), DB `boss888_test` does not exist | **P0 latent** | `force="true"` on all 7 | All 5 configs now forced |
| 4 | `Shell::run` — choke point, command unprovable | P1 | Strips env unconditionally | `ENV_STRIP` constant |
| 5 | `CommitExecutor::runShell` — executed whatever it was handed | P1 | Strips env unconditionally, `bash -c` wrapped | Belt-and-braces with #2 |
| 6 | Audit tool passed a `RefreshDatabase` class extending PHPUnit's TestCase | P1 | Resolve base class through imports | Selftest case `RogueTest` |

Defect 3 was a loaded gun independent of Engineer888: any nested invocation of that config
would have run `migrate:fresh` against production across the entire feature suite.

### Remaining UNKNOWN — 2, both hand-verified

`AdminMediaController.php:341` and `:359`. `$cmd` is built from
`shell_exec('command -v ffprobe')`, so the binary path is discovered at runtime and is
unresolvable by design. Hand-verified: both run `ffprobe`/`ffmpeg`. The file contains **zero**
references to `artisan`, `phpunit` or `bootstrap/app`, so it cannot boot a second framework.
Left as UNKNOWN deliberately — marking them SAFE would require the tool to assume something
it cannot prove.

### Guard review

`ProductionDatabaseGuard` reads configuration only and never opens a connection, so it can
refuse before anything is touched. It is deny-then-allow: a target must be absent from the
production denylist **and** positively match a test-database pattern. Anything unprovable is
refused. It is now invoked at two points — `setUpTraits()` (before RefreshDatabase) and
`setUp()` (after boot, catching a runtime connection swap).

### Permanent protections

1. `tools/exec-safety-audit.php` — tokenizer-based, with backward variable resolution and a
   **15-case selftest** that must pass before its output is trusted.
2. `tests/Feature/Engineer888/ExecutionSafetyTest.php` — 7 tests that fail the build on any
   UNSAFE finding, any unforced phpunit config, any unguarded `RefreshDatabase` class, and
   re-measure the inheritance mechanism itself so a framework upgrade that changes precedence
   is detected here rather than in production.

### Honest limitations

- The audit is static. A command assembled from database content or an env var at runtime
  cannot be classified; the two UNKNOWNs are exactly that shape.
- Backward variable resolution is nearest-assignment, not full data-flow. A variable
  reassigned in a branch resolves to the textually nearest assignment. This can only produce
  a wrong SAFE if a variable is reassigned to an artisan command after being assigned a
  harmless one — no such case exists today, and the selftest asserts that an unresolvable
  assignment stays UNKNOWN rather than defaulting to SAFE.
- The cross-check compares against grep, which cannot tell code from comments. Its four
  remaining discrepancies are all the word "exec"/"system" in prose, individually verified.
- Third-party code under `vendor/` was not audited.
