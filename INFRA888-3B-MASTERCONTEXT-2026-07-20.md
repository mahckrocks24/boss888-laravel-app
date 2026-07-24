# INFRA888 PHASE 3B — MASTER CONTEXT (resume-cold)
**Written:** 2026-07-20 ~14:15 UTC · **By:** office session (Claude Code, IP 80.227.244.94) · **For:** home-PC continuation
**Companion docs (same dir on server `/var/www/levelup-staging/`):**
- `INFRA888-3B-HANDOFF-2026-07-20.md` — START HERE (status + next actions)
- `INFRA888-3B-SESSION-LOG-2026-07-20.md` — chronological log of this session
- `PHASE-3B-ENTERPRISE-INFRASTRUCTURE-INTELLIGENCE-FINAL-2026-07-20.md` — full validation report (the "log report")

---

## What INFRA888 is
The infrastructure operating system inside LevelUp Growth (BOSS888 = internal codename, never customer-facing). Phase 3A shipped monitoring + Founder Mode governance. **Phase 3B** = the enterprise **Infrastructure Intelligence** layer: canonical asset graph + first-class incidents + historical intelligence + an executive dashboard, all workspace-isolated, provider-agnostic, read-only over the operational record.

## Where 3B stands NOW: **GO (Founder-stage), conditional on pagination**
Backend was already built + green (the home PC finished the code ~10:02 today). THIS office session completed the **closeout**: enterprise dashboard frontend + backend management-composition + full 4-layer validation + docs.

### Architecture (all LIVE on staging)
- **Canonical node:** `infra_assets`. Modes: `adopted | provisioned | managed_externally | managed_by_infra888`. Health: `healthy | degraded | down | unknown`. Risk: `ok | watch | at_risk`. Rich tables (hosting_accounts/hosted_sites/monitor_checks) point back via `source_type`/`source_id`. Edges: `infra_asset_relationships` (cross-ws links refused).
- **Services:** `InfrastructureIntelligenceService` (deterministic MTTR/MTBF/uptime + rule-based risk — NOT AI), `AssetGraphService` (neighbourhood/blastRadius/orphans), `IncidentService` (6-state lifecycle detected→acknowledged→investigating→mitigated→resolved→closed; append-only `infra_incident_transitions`).
- **Endpoints** (all `GET`, `auth.jwt`+`DenyApiKeyAuth`, workspace-scoped, READ-ONLY), routes/api.php ~19039, prefix `infrastructure`:
  `intelligence/dashboard` · `intelligence/assets` (?type=&health=&risk=) · `intelligence/assets/{id}` · `intelligence/assets/{id}/blast-radius` · `intelligence/incidents` (?open_only=1) · `intelligence/reliability` (?days=30)
  ⚠️ These return payload at TOP LEVEL (`r.json`), NOT `r.json.data` (unlike Phase 1D hosting endpoints).
- **Frontend:** `public/app/js/infrastructure.js` v1.2.0-infra888-intelligence (71KB). Tabs: Overview / Assets / (Asset drill-down) / Incidents / Reliability / Hosting / Domains / Email. Mounted via core.js:1048 → `infraLoad(#infrastructure-root)`. Design tokens = index.html `:root`. `LU_CFG.api='/api/'`, Bearer from `localStorage.lu_token`.

### PTAA (first validation customer) — ws #990006, slug `ptaa-infra`, member user_id=1 (owner)
3 canonical assets: monitoring_check `managed_by_infra888` (healthy) · server **adopted** (health unknown — never faked) · website **adopted** (healthy, monitored). INFRA888 MONITORS PTAA; it did NOT provision it. This distinction renders correctly in the UI.

## Validation evidence (all green)
INFRA888 suite **315/0/1skip** · intelligence proof **23/0** · live authenticated HTTP **29/0** (real middleware + minted JWTs; isolation proven 3 ways) · REAL headless-Chromium browser **25/0** (live API, PTAA data, 0 console/API errors, no overflow @1440/390). Full-platform regression: 315 pass / **66 fail** / 1 skip → **0 INFRA888 failures**; the 66 are NON-3B (see handoff).

## Environment quick-ref
- Staging: `ssh staging` → root@134.209.93.41, Laravel `/var/www/levelup-staging`, DB `levelup_staging` (MySQL). App served at **https://levelupgrowth.io/app/** (nginx server_name `levelupgrowth.io *.levelupgrowth.io`, TLS).
- Local (home): `C:\Users\markr\LVL` (working copies for this closeout are in `C:\Users\markr\LVL\infra888-3b\`).
- **Push edits with scp** (never base64-over-pipe). Back up every file before edit. `migrate:fresh` PROHIBITED on staging. tinker NOT installed → bootstrap Laravel in a standalone PHP script.
- **PowerShell→ssh quoting:** parentheses/pipes/`^`/unicode in inline `ssh '...'` commands break. Always write a `.sh`/`.php` script, `scp` it, then `ssh 'bash /tmp/x.sh'`. This bit me ~8 times — just use script files.
- **Real browser available ON the server:** puppeteer 24 + Chromium at `.puppeteer-cache/chrome/linux-147.0.7727.57/chrome-linux64/chrome`. Use `--no-sandbox`. Verification script: repo-root `infra3b-browser-verify.cjs` + harness `public/app/infra-verify-3b.html`.
- **JWT minting (side-effect-free):** `app(App\Core\Auth\RefreshTokenService::class)->issueAccessToken($user,$workspace)`. Middleware requires a real `workspace_users` membership for the (user,ws) pair.

## Docs/dirs note
`BOSS888-STATE.md` and `daily-progress/` / `boss888-audit/` — the audit + daily-progress DIRECTORIES referenced by STATE.md do **NOT exist** on the server (verified). This session's durable record is the four `INFRA888-3B-*` / `PHASE-3B-*` docs at the project root + `daily-progress/2026-07-20-infra888-3b.md` (created this session).
