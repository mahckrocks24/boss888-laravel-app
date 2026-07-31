# LevelUp Runtime v2.37.4 — DeepSeek V4 Restoration · Deployment & Rollback (Railway)

**Package:** `C:\Users\markr\LVL\runtime-v2.37.4-deepseek-v4\levelup-runtime2-main\` (89 files)
**Zip (transfer/backup only):** `C:\Users\markr\LVL\runtime-v2.37.4-deepseek-v4.zip` (328 KB)
**Baseline:** `C:\Users\markr\LVL\runtime-pkg-2.37.3\levelup-runtime2-main\` (v2.37.3, deployed since 2026-07-21)
**Status:** ✅ **DEPLOYED 2026-07-26 ~08:45 UTC · VERIFIED IN PRODUCTION 09:05 UTC**
**Supersedes:** `RUNTIME-2.37.3-DEPLOY-AND-ROLLBACK.md` (that document remains valid for the 2.37.3 baseline and rollback)
**Incident:** INC-2026-001 — see `INCIDENT-REGISTER.md`

---

## 1. Which Railway service

The service running the **LevelUp Runtime** (`levelup-runtime`) — the one Laravel reaches via
`RUNTIME_URL` with the `X-LevelUp-Secret` header, host
`levelup-runtime2-production.up.railway.app`.

**Do not** deploy to any other service. It is not the Laravel app, and it is not PTAA.
Creating a *new* service is wrong — it gets a new URL while Laravel's `RUNTIME_URL` still
points at the old one.

## 2. How to deploy

⚠️ **Railway has no ZIP upload and no drag-and-drop.** The `.zip` in `LVL` is for
transfer/backup only. There are exactly two valid routes:

### Option A — GitHub-backed service
Railway → service → **Settings → Source** shows a connected repo.
Copy the **contents** of `levelup-runtime2-main/` to the repo root, commit, push. Railway auto-builds.

### Option B — Railway CLI
```
npm i -g @railway/cli
railway login
railway link          # select the runtime service
cd .../levelup-runtime2-main
railway up
```

**Check Settings → Source first — that one screen decides which route applies.**

> Node/npm are **not installed** on the Windows work machine. Run the CLI from the droplet
> (Node v20.20.2) or install Node locally.

**Upload the contents of `levelup-runtime2-main`, not the parent folder.** `package.json`,
`index.js`, `railway.toml` and `.railway-trigger` must land at the **root** of what Railway
receives. This is the most common way this deploy goes wrong.

`.railway-trigger` carries a new line (`v2.37.4-deepseek-v4-restoration 2026-07-26`) which
forces a rebuild if Railway would otherwise serve a cached build.

## 3. Build command
**None.** NIXPACKS auto-detects Node from `package.json` and runs `npm install`. Do not add one.

## 4. Start command
`node index.js` — set in `railway.toml`. **Do not change.**

## 5. Healthcheck
Path `/health`, timeout 10 s — set in `railway.toml`. **Do not change.**

## 6. Environment variables

**No new variables are required.** Every setting introduced by this release has a safe
default. Deploying with none set is correct and is what was done.

Must already exist: `DEEPSEEK_API_KEY` · `OPENAI_API_KEY` · `REDIS_URL` · `WP_SECRET` ·
`LU_SECRET` · `LARAVEL_BASE_URL`/`LARAVEL_URL`/`WP_URL` · `SYNTHESIS_ENDPOINT` ·
`ENABLED_WORKERS` · worker concurrency vars.

Optional new overrides:

| Variable | Default | Purpose |
|---|---|---|
| `DEEPSEEK_DEFAULT_MODEL` | `deepseek-v4-flash` | production default model |
| `DEEPSEEK_PRO_MODEL` | `deepseek-v4-pro` | pro tier |
| `DEEPSEEK_REASONING_HEADROOM` | `2000` | tokens added so reasoning cannot starve the answer |
| `DEEPSEEK_MAX_TOKENS_CAP` | `8192` | hard ceiling — keeps generation bounded |
| `PLANNER_TIER` | `flash` | set `pro` to upgrade the planner (**raises cost** — Pro reasoning measured ~6× flash) |

## 7. What NOT to overwrite
- Do not change `REDIS_URL` — queue and task memory live there.
- Do not change `startCommand`, `healthcheckPath`, or the scaling block (`minInstances=2`, `maxInstances=10`).
- Do not rotate `WP_SECRET` / `LU_SECRET` during a deploy — it breaks the Laravel handshake.
- Do not deploy any `*.php` file or a Laravel `.env` — they have no business on Railway.
- Do not ship `index.js.bak2` (an 89 KB stray backup present in the 2.37.3 archive; **removed** from this package).

## 8. Changes in this release

| File | Change |
|---|---|
| `deepseek-models.js` | **NEW** (276 lines) — model registry, reasoning headroom, response guards |
| `index.js` | +94 −20 — `/ai/run` model resolution, 2 stream sites, fallback attribution, empty-content + structured-output guards |
| `llm.js` | +30 −7 — provider registry → V4, DeepSeek-only headroom, empty-content guard, usage split |
| `lu-planner.js` | +23 −6 — model, headroom, strict structured-output validation |
| `lu-worker-manager.js` | +3 −2 — model + headroom |
| `ai-complete-endpoint.js` | +2 −2 — dead-code hygiene |
| `.railway-trigger` | appended `v2.37.4-deepseek-v4-restoration 2026-07-26` |
| `test-deepseek-v4.js` | **NEW** — 50-test suite; test-only, never required at runtime |
| `index.js.bak2` | **REMOVED** |

**Integrity:** 81 of 87 baseline files byte-identical (SHA-256 verified). Exactly 6 changed,
2 new, 1 removed — nothing else touched.

**All 8 hardcoded `deepseek-chat` literals eliminated.** Default is `deepseek-v4-flash`.
Nothing routes to Pro by default.

## 9. Verify build success

Build log should end with a successful `npm install` and the service going **Active**.
Expect near the top of the deploy log:
```
[TOOL-REGISTRY] Launch scope: withheld N out-of-scope tools (...)
[REGISTRY] Unified: 43 tools from canonical registry
[SERVER] ✓ LevelUp Runtime v2.28.0 on :PORT
```
(`v2.28.0` in the banner is **pre-existing cosmetic staleness** — not a failed deploy.)

`minInstances = 2` → expect **2 replicas Active**. If one is healthy and one is crash-looping,
roll back (§12) — Laravel load-balances across both.

## 10. ⚠️ Verify the deployed version — `/health` is NOT a deploy signal

`/health` still returns `"version":"2.37.3"`. The constant at `index.js:491` was
**deliberately not bumped** (outside the minimal-patch scope, and a version string can be
bumped without the fix working).

**The definitive deploy proof:**
```bash
curl -X POST https://levelup-runtime2-production.up.railway.app/ai/run \
  -H 'Content-Type: application/json' \
  -H 'X-LevelUp-Secret: <RUNTIME_SECRET>' \
  -d '{"task":"chat_json","prompt":"json test","model":"deepseek-chat"}'
```
- **New code:** `HTTP 400` + `{"code":"DEEPSEEK_INVALID_MODEL", "error":"DeepSeek model \"deepseek-chat\" was retired on 2026-07-24 ..."}`
- **Old code:** an opaque upstream 400/500 **without** that code.

That string exists only in v2.37.4.

## 11. Post-deployment smoke tests

| # | Test | Expected |
|---|---|---|
| 1 | `/health` | ok · 2.37.3 · 10 agents · 43 tools |
| 2 | `model: deepseek-chat` | 400 `DEEPSEEK_INVALID_MODEL` |
| 3 | `/ai/run` chat_json | `actual_model: deepseek-v4-flash`, `fallback_used: false`, `parsed` present, `usage.reasoning_tokens` present |
| 4 | `tier: "pro"` | `actual_model: deepseek-v4-pro` |
| 5 | `model: deepseek-reasoner` | 400 |
| 6 | `tier: "turbo"` | 400, lists valid tiers |
| 7 | `RuntimeClient::writeDraft` via Laravel | non-empty article content |
| 8 | Full Orchestrator `write_article` task | status `completed` |
| 9 | Railway logs | `model=deepseek-v4-flash`; **zero** `deepseek-chat` |
| 10 | `api_usage_logs` | rows with `deepseek-v4-flash` |

**Results 2026-07-26:** all 10 passed. `writeDraft` returned 5,556 chars; a full Orchestrator
task on ws 990003 completed and persisted an article; **47 tasks completed / 0 failed** in the
first 90 minutes (baseline: 36 failed / 72 h).

## 12. Rollback

**Primary (fast, preferred):** Railway → runtime service → **Deployments** → redeploy the
previous deployment ID. ~1 minute, no rebuild.

> ⚠️ Capture the Active deployment ID **before** deploying. It cannot be recovered afterwards
> from outside Railway, and no Railway API access exists in the working environment.

**Backup (if the Railway image is gone):** restore the 5 modified files from
`C:\Users\markr\LVL\runtime-pkg-2.37.3\levelup-runtime2-main\` and delete
`deepseek-models.js`. The patch is additive and fully reversible.

**No database or Redis migration is involved** — nothing to un-migrate.

### ⚠️ Rolling back restores the outage
Reverting reinstates `deepseek-chat`, which DeepSeek rejects with HTTP 400. Roll back **only**
if v2.37.4 causes a failure *worse* than a total AI-generation outage.

## 13. Post-deploy verification of Laravel

The runtime deploy required **no Laravel change**. If Laravel is ever touched alongside it:
- `php artisan config:clear` — **never** `config:cache` (`RuntimeClient` reads `env()` directly)
- `php artisan queue:restart && supervisorctl restart levelup-worker:*` — workers cache `app/` code

Neither was required for this deploy, and neither was performed.
