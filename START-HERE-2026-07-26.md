# START HERE — DeepSeek V4 Incident + Runtime Restoration · Handoff 2026-07-26

Read this first. It orients a fresh session on the current production state after
INC-2026-001 and on what the next approved phase is.

---

## 1. Current running state (staging droplet = production)

- **Laravel:** `/var/www/levelup-staging` on `134.209.93.41`. **No Laravel code was changed
  during this incident.**
- ⚠️ **The repo is checked out on branch `feature/studio888-mrc-2a` @ `416686e`** — the Studio
  freeze point, **not** a mainline branch. Preserve branch `preserve/studio888-mrc-2a-pre-deepseek-v4`
  and tag `studio888-mrc-2a-pre-deepseek-v4` both exist at that commit. All Studio work is
  **committed**, not dirty. The only uncommitted changes are this documentation sync
  (`BOSS888-STATE.md`, `BOSS888-MASTERCONTEXT-2026-07-20.md` modified; 4 new docs untracked).
  **Anyone starting the Admin Forensic Audit must be aware they are on a feature branch.**
- **Runtime:** `levelup-runtime` **v2.37.4-content** deployed to Railway 2026-07-26 ~08:45 UTC.
  `/health` still reports **`2.37.3`** — the version constant was deliberately not bumped.
- **AI provider:** DeepSeek **`deepseek-v4-flash`** is the production default.
  `deepseek-v4-pro` available by explicit tier only. **Nothing routes to Pro by default.**
- **Production health:** **47 tasks completed · 0 failed** in the 90 min after deploy
  (baseline: 36 failed / 72 h). `failed_jobs` 0. Autonomous agents resumed unaided.
- **Studio (STUDIO888 MRC-2A):** still **PAUSED** at `416686e`, inert in production.
  The V4 change is exactly what it was waiting on — see §6.

**Deploy proof (`/health` is NOT a valid signal):**
```bash
curl -X POST https://levelup-runtime2-production.up.railway.app/ai/run \
  -H 'Content-Type: application/json' -H 'X-LevelUp-Secret: <RUNTIME_SECRET>' \
  -d '{"task":"chat_json","prompt":"json test","model":"deepseek-chat"}'
# → 400 {"code":"DEEPSEEK_INVALID_MODEL", ...}   ← only exists in v2.37.4
```

---

## 2. THE architectural fact to internalise before touching anything AI-related

**Laravel orchestrates. The Railway Node runtime executes.**

Provider calls do **not** happen in Laravel. `config/llm.php` and
`app/Connectors/DeepSeekConnector.php` contain model identifiers, but that path is dormant —
the 2026-04-12 "hands vs brain" refactor moved essentially every call site to `RuntimeClient`.

During INC-2026-001, patching Laravel would have changed **nothing** a customer could observe.
Full detail: `ARCHITECTURE-AI-EXECUTION-LAYER.md`.

---

## 3. Governing documents for this arc

| Doc | Purpose |
|---|---|
| `INCIDENT-REGISTER.md` | INC-2026-001 — root cause, timeline, impact, resolution, lessons |
| `ARCHITECTURE-AI-EXECUTION-LAYER.md` | **Architecture + AI provider + runtime** truth. AD-01…AD-12 |
| `RUNTIME-2.37.4-DEEPSEEK-V4-DEPLOY-AND-ROLLBACK.md` | How to deploy/roll back the runtime |
| `DEEPSEEK-V4-MIGRATION-AUDIT-2026-07-26.md` | The forensic audit that found the cause |
| `PRE-DEPLOYMENT-REPORT-DEEPSEEK-V4-2026-07-26.md` | Patch design, evidence, test results, gates |
| `runtime-v2.37.4-deepseek-v4/DEPLOY-README.md` | Package-level upload instructions |
| `STUDIO888-DEEPSEEK-V4-IMPACT-REGISTER.md` (in `LVL\`) | Studio assumptions to revalidate — partially answered, §6 |

Package: `C:\Users\markr\LVL\runtime-v2.37.4-deepseek-v4\` · Prior: `runtime-pkg-2.37.3\`

---

## 4. KNOWN REMAINING WORK

### 🔴 CRITICAL
| id | Item | Why |
|---|---|---|
| **TD-04** | **Provider-error alerting / monitoring** | Root cause of the ~42 h detection gap. Provider failures land in `tasks.error_text`, never in `failed_jobs`, so nothing pages. A repeat vendor change would again run for days unnoticed. Minimum: alert on N provider errors in M minutes, and on any `fallback_used: true`. |

### 🟠 HIGH
| id | Item | Why |
|---|---|---|
| **TD-10** | **Replay the 213 failed + 78 blocked tasks** | Includes 2595/2599 for ws 7 (Shukran Group, a paying customer). They will not self-heal. **Needs a Boss decision on replay policy.** |
| **TD-02** | **Verify V4 pricing** | `RuntimeClient.php:1207` still hardcodes V3 rates ($0.14/$0.28 per 1M). Every DeepSeek cost figure in admin is wrong. Reasoning bills as output tokens, so real cost is materially higher. **Needs rates from the DeepSeek dashboard.** |
| **TD-01** | **Laravel provider attribution** | `RuntimeClient.php:373` hardcodes `logApiUsage('deepseek', …)`. The runtime now emits `actual_provider` / `actual_model` / `fallback_used` / `fallback_reason`; Laravel ignores them, so OpenAI fallbacks still record as DeepSeek. |
| **TD-03** | **`estimateApiCost()` keys on provider, not model** | The 70 `gpt-4o-mini` fallback calls were priced at DeepSeek rates. Fix together with TD-01/TD-02. |

### 🟡 MEDIUM
| id | Item |
|---|---|
| **TD-13** | **Laravel-side DeepSeek tests** — 0 of 60 test files reference DeepSeek. The 50-test suite lives in the runtime only. |
| **TD-06** | **Runtime streaming unverified** — the patched lines at `index.js:201/2995` are unexercised because neither streaming route is reachable from Laravel. |
| **TD-05** | **`/internal/write/stream-init` broken** — route registered `index.js:271`, body parser `index.js:438` → `req.body` always undefined. Pre-existing (byte-identical to baseline). |
| **TD-07** | **V4 vision unverified** — `llm.js:15` `visionModel` points at flash but was never probed. `/internal/vision/analyze` uses `gpt-4o`, so exposure believed nil. |
| **TD-12** | **`lu-worker-manager.js:53`** requires a missing `./lu-task-worker-fn`; try/caught → `processors.agent_user` permanently null. Pre-existing. |
| **TD-08** | **No lockfile** — NIXPACKS re-resolves `^` ranges every build. |
| **TD-09** | **Runtime has no git repository** — archive-only source, no history, no diff review. |
| — | **Runtime observability** — no structured request log, no per-model latency/error metrics, no `reasoning_tokens` column in `api_usage_logs`. |
| — | **Additional DeepSeek tests** — Pro streaming, tool-calls-during-thinking under load, timeout/retry behaviour, credit reservation/settlement. |

### 🟢 LOW
| id | Item |
|---|---|
| **TD-11** | Version drift — banner `2.28.0` / `/health` `2.37.3` / package content `2.37.4`. Decide whether to bump `/health` to `2.37.4` (nothing in Laravel asserts on it). |
| — | Remove `test-deepseek-v4.js` from the deployed package if a pure-production artifact is preferred (it is inert — nothing requires it). |
| — | Retire the 5 `RuntimeClient.php.bak-*` files and other stale `.bak` clutter in `app/`. |

### 🔵 FUTURE (explicitly out of scope until their phase)
| Item | Note |
|---|---|
| **Engineering888 integration** | **Does not exist in any codebase.** Confirmed: 0 hits across `levelup-staging`, `levelup-recovery`, and the runtime source. The V4 patch exposes a generic `tier` parameter so Engineer888 can select Pro on day one without a new execution path. Needs its own masterplan. |
| **Bella integration** | `bella` exists only as an admin persona in the agent roster (MASTERCONTEXT §1) and a `BellaController`. No engine, no masterplan. Needs its own phase. |
| **Fallback policy decision** | Keep the OpenAI fallback (recommended — genuine resilience) but make it loud and correctly attributed. Boss decision. |
| **Studio Phase M** | Blocked on §6 revalidation. |

---

## 5. CHANGE LOG — INC-2026-001 (chronological, UTC)

### 2026-07-24
| Time | Event |
|---|---|
| 13:37 | Last successful `deepseek-chat` call (`api_usage_logs`). **Outage begins.** |
| 15:59 | DeepSeek retirement deadline for `deepseek-chat` / `deepseek-reasoner` |

### 2026-07-25
| — | Outage undetected. Scheduled runs failing silently; `/ai/run` degrading to `gpt-4o-mini`. |

### 2026-07-26 — audit
| Time | Event |
|---|---|
| 07:39 | SSH session opened (unrelated task) |
| ~07:45 | **AUDIT** — repo-wide search for DeepSeek identifiers |
| ~07:50 | **DISCOVERY** — `config/llm.php:10` default `deepseek-chat`; `DEEPSEEK_MODEL` unset |
| ~07:55 | **DISCOVERY** — live API probe confirms retirement: `/models` returns only v4-flash/v4-pro; legacy names → 400 |
| ~08:00 | **DISCOVERY** — `laravel.log` shows the 05:00 run failing; 36 failed tasks / 72 h across 10 workspaces |
| ~08:05 | **KEY DISCOVERY** — the failing call is `RuntimeClient::writeDraft` → **Railway**, not Laravel |
| ~08:08 | **DISCOVERY** — `/ai/run` silently falls back to `gpt-4o-mini`, mis-attributed as DeepSeek (70 calls) |
| ~08:10 | **DISCOVERY** — 8 hardcoded literals in runtime source (initial count of 6 corrected; `index.js:588`, the `/ai/run` default, had been missed) |
| ~08:15 | **DISCOVERY** — V4 reasoning always-on; `reasoning_tokens` inside `completion_tokens`; HTTP 200 + empty content possible |
| ~08:20 | **MEASUREMENT** — reasoning cost on real workloads: flash 70–324, **pro 1238**. Corrects the earlier risk assessment: flash was never at risk; Pro would have truncated. |
| ~08:22 | Audit report delivered — recommendation **GO on patch, NO-GO on deploying that night** |

### 2026-07-26 — build & test
| Time | Event |
|---|---|
| ~08:25 | **RUNTIME MODIFICATION** — `deepseek-models.js` authored; patch applied to isolated copy `/tmp/rt-patched` |
| ~08:26 | Patch aborted once on an exact-match assertion — **mixed line endings** (`lu-planner.js`, `lu-worker-manager.js` are CRLF). Patcher made line-ending aware; re-applied, 17/17 edits clean |
| ~08:28 | **VALIDATION** — all 79 files parse; `npm install` clean; **43/43 offline tests** pass |
| ~08:30 | **VALIDATION** — **50/50** with 7 live contract tests against the real DeepSeek API |
| ~08:32 | **VALIDATION** — patched runtime booted on an isolated port: `/ai/run` → v4-flash, `tier=pro` → v4-pro, retired names → 400, no secrets in logs |
| ~08:34 | **VALIDATION** — baseline source vs live Railway `/health`: **1520 bytes both, all code-derived fields identical** → FUNCTIONALLY EQUIVALENT |
| ~08:36 | **BLOCKER** — no Railway CLI/token anywhere (PC or droplet). Pre-deployment report: **NO-GO, blocked not failed** |
| ~08:40 | Upload package assembled + integrity-verified (81/87 byte-identical; 6 changed, 2 new, 1 removed); exact artifact re-tested 50/50 and booted |

### 2026-07-26 — deploy & verify
| Time | Event |
|---|---|
| ~08:45 | **DEPLOYMENT** — Boss deploys v2.37.4 to Railway |
| 08:47 | **PRODUCTION VERIFICATION** — deploy proof: `deepseek-chat` → 400 `DEEPSEEK_INVALID_MODEL` |
| 08:48 | **SMOKE** — `/ai/run` v4-flash with full attribution + usage split; `tier=pro` → v4-pro; reasoner + invalid tier rejected |
| ~08:52 | **SMOKE** — `RuntimeClient::writeDraft` (the failing endpoint) returns **5,556 chars** |
| ~08:56 | Two false alarms investigated and cleared — both test-harness errors (`aiRun` key name; `length:'short'` vs int) |
| ~09:00 | **SMOKE** — full Orchestrator `write_article` on ws 990003 → **`completed`**, article persisted, test data removed |
| 09:05 | **PRODUCTION VERIFICATION** — 47 completed / 3 running / 38 queued / **0 failed**; 0 DeepSeek failures; `api_usage_logs` records `deepseek-v4-flash` |
| ~09:45 | **DOCUMENTATION SYNC** — this handoff bundle |

---

## 6. Studio (STUDIO888 MRC-2A) — what the V4 change answered

Studio was paused pending the V4 outcome. Several register questions now have hard evidence
(details in `STUDIO888-DEEPSEEK-V4-IMPACT-REGISTER.md`, updated 2026-07-26):

- **Empty-final-content classification** — the runtime now *enforces* it
  (`DEEPSEEK_EMPTY_FINAL_CONTENT`). Studio's H1 truthfulness assumption is upheld upstream.
- **Reasoning vs final content separation** — confirmed: `reasoning_content` and `content` are
  distinct fields; the usage split is exposed.
- **Structured JSON shape** — `chatJson` parsed shape preserved; malformed output now 502s
  instead of returning `success: true, parsed: null`.
- **Fallback attribution** — runtime now emits `requested_*`/`actual_*`/`fallback_used`;
  **Laravel does not yet consume them (TD-01)**, so Studio must not rely on them yet.
- **Model IDs changed** — any Studio fixture asserting `deepseek-chat` is now wrong.
- **Still unanswered:** streaming accumulation (TD-06), V4 vision (TD-07), latency/retry
  baselines, prompt-wrapper stability.

**Studio remains PAUSED.** Pre-V4 and post-V4 readiness data must stay segmented.

---

## 7. NEXT PHASE (Boss-approved sequence)

| # | Phase | Status |
|---|---|---|
| — | ~~Laravel Admin Forensic Audit~~ | ✅ **COMPLETE 2026-07-26** — score 5.4/10 |
| — | ~~Governance Hardening Masterplan~~ | ✅ **APPROVED 2026-07-26** |
| **1** | **Governance Hardening P0-A** | ✅ **COMPLETE** — registers + specs, zero production change |
| **2** | **Governance Hardening P0-B** | **➡ NEXT — awaiting approval.** ⚠️ riskiest phase: enrol BOTH admins together or DEGRADED trips 423 platform-wide |
| 2 | AI Architecture Audit | queued |
| 3 | Engineering888 Masterplan | queued |
| 4 | Bella Masterplan | queued |

Do **not** begin phase 1 until this documentation sync is acknowledged.

---

## 8. Operational reminders (unchanged, learned the hard way)

- **Staging IS production.** Back up every file before editing (`/root/backups/…` + dated `.bak`).
- Push files with **scp** — never base64-over-pipe (writes empty files; `php -l` false-greens).
- **Never `config:cache`** — `RuntimeClient` reads `env()` directly. Use `config:clear`.
- After ANY `app/` change: `php artisan queue:restart && supervisorctl restart levelup-worker:*`.
- Use **script files for ssh** — inline PowerShell quoting breaks on `(`, `|`, `^`, `$`, unicode.
- PowerShell `Get/Set-Content` corrupts UTF-8 on this PC — use the Write tool or .NET methods.
- Check for a concurrent session before writing (`auth.log`, `ss -tn | grep :22`).
- Codename **BOSS888 / INFRA888 must never render on a customer surface.**
- **Runtime work needs Railway access** — no CLI/token exists in the environment; Boss deploys.

## 9. Backups taken for this documentation sync
`/root/backups/docsync-deepseek-v4-20260726-094249/` — `BOSS888-STATE.md`,
`BOSS888-MASTERCONTEXT-2026-07-20.md`, `START-HERE-2026-07-24.md`
(plus in-place `.bak-docsync-20260726-094249` copies alongside each original).
