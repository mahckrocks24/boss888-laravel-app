# LevelUp Runtime v2.37.3 — Deployment & Rollback (Railway)

**Package:** `levelup-runtime2-main-v2.37.3-launch-scope.zip`
**SHA-256:** `CD33FFBF7E6D7A2F4B97035C221435284E9244EDD6606AFD745B5108E5BDE647`
**Baseline:** `levelup-runtime2-main-v2.37.2 .zip` (genuine v2.37.2, confirmed via package.json)
**Status:** VERIFIED FOR STAGING DEPLOYMENT — RAILWAY DEPLOYMENT AND DEPLOYED-RUNTIME PROBES PENDING

> Nothing in this package has been deployed. All validation was performed off-Railway
> (Node 20.20.2, Linux, isolated dir on the staging droplet). Deployed-runtime behaviour
> is unverified until the probes in `runtime-probe-2.37.3.sh` pass against Railway.

---

## 1. Which Railway service

The service running the **LevelUp Runtime** (`levelup-runtime`) — the one whose
`/health` currently returns `"version":"2.37.2"` and lists 20 agents. This is the
service Laravel reaches via `RUNTIME_URL` / `LARAVEL_BASE_URL` handshake with the
`X-LevelUp-Secret` header.

**Do not** deploy this to any other service. It is not the Laravel app.

## 2. How to deploy

`railway.toml` uses **NIXPACKS** and there is **no lockfile** in the baseline (this
package preserves that — see §6). Two options depending on how the service is wired:

### Option A — Git-backed service (if Railway builds from a repo)
1. Extract the ZIP over your local clone's working tree (it contains a single
   top-level `levelup-runtime2-main/` folder — copy its **contents** to the repo root).
2. `git status` and review the diff — 21 files changed, 1 added (`launch-scope.js`),
   1 added (`launch-scope-validate.js`).
3. Commit and push to the branch Railway watches. Railway auto-builds.

### Option B — Direct upload / CLI
1. `railway link` → select the runtime service.
2. From the extracted `levelup-runtime2-main/` directory: `railway up`.

Either way `.railway-trigger` has a new line (`v2.37.3-launch-scope-runtime-sanitation
2026-07-21`) which forces a rebuild if Railway would otherwise cache.

## 3. Build command

**None required.** NIXPACKS auto-detects Node from `package.json` and runs
`npm install`. Do not add a build command.

## 4. Start command

`node index.js` — already set in `railway.toml` under `[deploy] startCommand`.
**Do not change it.**

## 5. Healthcheck

Path `/health`, timeout 10s — already set in `railway.toml`. **Do not change it.**

## 6. Environment variables that MUST already exist

These are read at boot. This package changes none of them and contains no secrets.
Confirm they are still set on the service **before** deploying:

| Variable | Purpose |
|---|---|
| `REDIS_URL` | Memory + BullMQ queue (Railway Redis plugin injects it) |
| `LU_SECRET` | Internal auth |
| `WP_SECRET` | `X-LevelUp-Secret` header value — Laravel handshake |
| `DEEPSEEK_API_KEY` | LLM provider |
| `LARAVEL_BASE_URL` (or `LARAVEL_URL` / `WP_URL`) | Authoritative agent roster fetch |
| `SYNTHESIS_ENDPOINT` | Agent prose synthesis |
| `ENABLED_WORKERS` | Worker domains (leave as-is, normally `all`) |
| Worker concurrency vars | `AGENT_CONCURRENCY`, `WRITE_CONCURRENCY`, etc. |

## 7. What NOT to overwrite

- **Do not** change `REDIS_URL` or point it at a different database — the queue and
  task memory live there.
- **Do not** change `startCommand`, `healthcheckPath`, or the scaling block
  (`minInstances=2`, `maxInstances=10`).
- **Do not** rotate `WP_SECRET` / `LU_SECRET` during this deploy — Laravel's
  `RuntimeClient` uses the current value; rotating mid-cutover breaks the handshake.
- **Do not** deploy the Laravel `.env` or any `*.php` file — the baseline ZIP
  accidentally contained `api.php.staging-current`, `ApprovalService.php` and
  `CampaignOutcomeService.php`; **all three are excluded from this package** (they
  are Laravel source and have no business on Railway).

## 8. Verify build success

Railway build log should end with a successful `npm install` and the service moving
to **Active**. In the deploy log expect these lines near the top:

```
[TOOL-REGISTRY] Launch scope: withheld N out-of-scope tools (...)
[REGISTRY] Unified: 43 tools from canonical registry
[SERVER] ✓ LevelUp Runtime v2.28.0 on :PORT
```

(The `v2.28.0` string in that banner is a pre-existing cosmetic staleness in the
startup log — the authoritative version is `/health` → `"version":"2.37.3"`.)

## 9. Verify replica health

`minInstances = 2`, so expect **2 replicas Active**. Both must pass the `/health`
check. If one replica is healthy and one is crash-looping, **roll back** (§13) —
do not leave a split fleet, because Laravel will load-balance across both and you
will get inconsistent rosters.

## 10. Inspect logs

```
railway logs --service <runtime-service>
```
Look for: `Cannot find module`, `SyntaxError`, `[REDIS] connection` failures,
unhandled rejections. A clean boot has **no** ERROR lines.

## 11. Verify the deployed version

```
curl -s https://<runtime-host>/health | grep -o '"version":"[^"]*"'
```
Expected: `"version":"2.37.3"`. If it still says `2.37.2`, the build did not take —
check that `.railway-trigger` changed and force a redeploy.

## 12. Post-deployment probes

Run `runtime-probe-2.37.3.sh` (see that file). It is read-only and consumes no
meaningful credits. **All probes must pass before W3 can be called complete.**

## 13. Rollback

The prior package is unchanged on disk: `C:\Users\markr\LVL\levelup-runtime2-main-v2.37.2 .zip`

**Rollback via Railway (fastest, preferred):**
1. Railway dashboard → runtime service → **Deployments**.
2. Find the last deployment showing `"version":"2.37.2"`.
3. **Redeploy** that build. Railway keeps prior images; this is a one-click revert
   and needs no source.

**Rollback via source (if the Railway image is gone):**
1. Extract `levelup-runtime2-main-v2.37.2 .zip`.
2. Deploy it by the same route used in §2.
3. Confirm `/health` returns `"version":"2.37.2"`.

**Important:** rolling the runtime back does **not** reopen the removed capability to
users. The Laravel kernel deny (W1), the cron/job guards (W2), the DB grant revocation
and the `RuntimeClient` boundary sanitizer (W3) all remain live and independent of
this package. A rollback returns the runtime to *boundary-enforced-only* — the state
it has been in since 2026-07-20 — which is safe but leaves the in-runtime cleanup undone.

**No database or Redis migration is involved in this deploy**, so there is nothing to
un-migrate. Task memory written under v2.37.3 is schema-compatible with v2.37.2
(the launch-scope fields are additive and ignored by the older build).
