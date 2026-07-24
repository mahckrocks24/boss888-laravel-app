# BOSS888 — MASTER CONTEXT (Launch-Scope Remediation)
_Last updated: 2026-07-20 · Authoritative running state: `BOSS888-STATE.md` · This doc = the big picture, zero re-discovery._

> "BOSS888" is the internal codename for **LevelUp Growth**. Never let it appear on any public/customer-facing surface.

---

## 0. What this program is
Make LevelUp Growth **truthful for launch** by removing every capability the product does NOT actually deliver, from the *authoritative* layer down — so the app can never claim, propose, assign, or execute work it can't do.

**REMOVE from launch:** social-media automation + social intelligence (listening/sentiment/analytics) + customer-facing **email marketing** (campaigns, newsletters, sequences/drips, automations) + the 10 specialist agents that own that work.
**RETAIN:** SEO (incl. technical SEO), Blog/Content, Website Builder, CRM, Studio (direct user-prompted image/video), Chatbot/AI Website Assistant, Sarah (DMM orchestrator), and the **retained blog-article-share** sliver (share a published article's link — service-executed, no agent).

**Audit verdict:** SAFE WITH CONDITIONS (accepted). Execution hard-stop is the correct foundation; remaining leaks are visibility/reasoning, not execution.

---

## 1. Authoritative classification (internal slugs/ids)
**REMOVED agents (10):** `marcus`(12, Social Media Mgr), `jordan`(16), `tyler`(14), `zara`(13), `zoe`(15), `maya`(9) — social; `vera`(20), `kai`(19) — email; `chris`(10), `leo`(8) — video/ad-copy (locked REMOVE; Studio video is retained as a *tool*, not via Chris).
**CONSTRAINED agents (6, kept but social/email grants revoked):** `sarah`(1, DMM/is_dmm), `priya`(7), `elena`(17), `max`(21), `nora`(11), `sofia`(6).
**RETAINED untouched:** `alex`(3, **Technical SEO** — NOT Marcus), plus SEO team `james`(2), `diana`(4), `ryan`(5); builder persona `arthur`; admin persona `bella`.

**Marcus question is RESOLVED:** Marcus = pure social = REMOVE. Alex = the Technical-SEO specialist = RETAIN. Do not confuse them.

---

## 2. Enforcement architecture (how the boundary can't drift)
`app/Core/LaunchScope/LaunchScopePolicy.php` is the **single source of truth** (REMOVED_ENGINES/ACTIONS/AGENTS/TOOLS, CONSTRAINED_AGENTS, RETAINED_SOCIAL_ACTIONS + `isArticleShareContext`). Every layer reads from it:
1. **Kernel execution deny** — `EngineExecutionService` Step-1a (before credit/provider/job). Removed action → refused. (W1)
2. **Capability grants** — `AgentCapabilityService::canUse` short-circuits removed agent/tool over BOTH the DB `agent_capabilities` table AND the static `CAPABILITY_MAP` fallback. `grant()` refuses to (re)grant removed. Accessors filter removed.
3. **Roster** — `Agent` model `launch_scope` global scope excludes removed agents from every Eloquent query; `Agent::withRemovedAgents()` is the ONLY way to see them (historical attribution). Raw roster queries in `routes/api.php` carry explicit `whereNotIn(REMOVED_AGENTS)`.
4. **Task creation** — `TaskService::create` launch-scope guard: no source (memory/goal/template/runtime/caller) can create a removed task or route to a removed agent. Retained + article-share pass.
5. **Runtime boundary** — `RuntimeClient` scrubs every runtime response (agents/tools/proposals) — the deployed Node runtime is NOT yet sanitised internally, so Laravel is the enforced gateway.
6. **Prompts** — Sarah's system/delegation/meeting prompts state social+email are not in the product; specialists' delegation never names a removed agent. (Prevention; the guards above are enforcement.)
7. **Recurrence** — seed migration + `AgentSeeder` are launch-scope-aware; a `migrate:fresh --seed` reproduces the launch-scoped set, not the pre-launch one.

**Design principle proven repeatedly in this codebase:** prompts = prevention, deterministic guards = enforcement. Always add the guard.

---

## 3. Workstream sequence & status
| WS | Scope | Status |
|----|-------|--------|
| W0 | Baseline + Marcus/Alex identity | ✅ done |
| W1 | LaunchScopePolicy + kernel execution deny | ✅ core done (W1-tail: read routes/plan copy → folded into W4/W7) |
| W2 | Cron + internal-guard disable (`lu:sequences:run`, `mention:scan-daily`, RunSequences/queueSendCampaign/sendTestEmail/scanWatchlist) | ✅ core done |
| **W3** | **agent_capabilities revoke · Sarah prompts · routing landmines · roster APIs · seeders · memory · runtime** | ✅ **DONE 2026-07-20** (runtime = boundary-enforced; in-runtime rebuild BLOCKED — see §5). Report: `BOSS888-W3-CHECKPOINT-2026-07-20.md` |
| W4 | Routes & public endpoints (social/email routes, OAuth connect callbacks, email open/click tracking pixels, public APIs exposing removed engines) | ⏭ NEXT |
| W5 | Retained article-share implementation (rebuild caption generation OFF marcus — service-owned) | pending |
| W6 | Frontend sanitation (`EXEC_INTENTS` core.js:4171, nav, onboarding, agent cards) | pending |
| W7 | Public marketing + plan truth (copy must not sell social/email; prices unchanged) | pending |
| W8 | Dormant data controls (archive+hard-disable email templates/blocks; ContentPack trace-then-dormant) | pending |
| — | full no-leak regression → browser QA → production-safe smoke → final report | pending |

**Locked decisions:** chris+leo = REMOVE agent (Studio video retained as tool); email templates/blocks = ARCHIVE + hard-disable; blog-share caption = BUILD before launch (W5); ContentPack = trace-then-dormant; prices unchanged but copy must be truthful. Do NOT re-request routine checkpoint approval — continue W4→W8 unless a genuine blocker/product decision emerges.

---

## 4. What W3 changed (summary — full detail in the W3 checkpoint report)
- **DB:** removed-agent grants (153 rows) + constrained social/email/sequence grants (42 rows) soft-disabled (`is_active=0`, reversible). Rollback `/root/backups/w3-capability-rollback.sql` + snapshots.
- **Source:** 22 Laravel files this session (routing maps, meeting candidates, proactive rules, article-share off marcus, NL parser fail-closed, Sarah prompts, capability accessors, seeders, `TaskService` guard, `RuntimeClient` sanitizer). All `.bak-w3-*`.
- **Verified:** 64/64 automated + live rosters (0 removed; `/api/internal/agents` = 10 retained, alex present) + 4 live Sarah negative chats (refuse+redirect, 0 removed tasks/assignments created). Alex keeps 26 Technical-SEO tools.

---

## 5. ⚠️ THE ONE BLOCKER (needs Boss action at home)
**In-runtime registry sanitation.** The Node runtime (Railway, v2.37.2) still lists all 20 agents + social/email tools in its own baked-in registry. It is **mitigated at the Laravel boundary** (RuntimeClient scrubs it live) so nothing leaks to users or executes — but to sanitise it *inside* the runtime we need:
1. **Current runtime source (v2.37.2).** Only a stale `C:\Users\markr\LVL\levelup-runtime2-main-2.36.0.zip` exists locally — building from it would regress the runtime ~2 versions (lose 2.37.x agent-search 5/5, brand-monitoring, retry fixes). **Do NOT deploy from the 2.36.0 zip.**
2. **Railway deploy access** (railway CLI + token, or the connected git repo). None present on staging or the work machine.

Files to change once source+access exist: `capability-map.js`, `agents.js`, `registry.js`, `lu-planner.js`, `lu-agent-search.js`, `tool-registry.js` — apply the same launch-scope filter, rebuild, deploy to Railway, then re-run the runtime probes.

---

## 6. Environment quick-ref
- **Staging:** `ssh staging` → `root@134.209.93.41`, key `~/.ssh/do_levelup`. Laravel at `/var/www/levelup-staging`, DB `levelup_staging` (creds in `.env`; user `levelup`). App URL `https://staging.levelupgrowth.io` (curl with `--resolve staging.levelupgrowth.io:443:127.0.0.1`).
- **Runtime:** Railway `https://levelup-runtime2-production.up.railway.app` (v2.37.2). `RUNTIME_SECRET` in `.env` (header `X-LevelUp-Secret`). Boss deploys.
- **Test accounts:** chef-red = user2/ws2 (main). Mint a JWT: `php boss888-ni/mint.php 2 2`.
- **tinker NOT installed** → bootstrap in a standalone PHP script: `require vendor/autoload` + `$app=require bootstrap/app.php` + `$app->make(Kernel::class)->bootstrap()`.
- **After code edits:** `php artisan cache:clear && config:clear`; reload php-fpm (opcache); `php artisan queue:restart`; `supervisorctl restart levelup-worker:`.
- **HARD RULES:** backup before every edit; `migrate:fresh` PROHIBITED; staging-only until launch phase; server is source of truth (git drift — pull before local edits); CREATIVE888 logic LOCKED (route/connector fixes OK).

## 7. Gotchas learned this session
- **SSH + nested quotes:** PowerShell mangles `$(...)`/`"..."` inside `ssh "..."`. Reliable pattern: write a `.sh`, `scp` it, `ssh staging "bash /tmp/x.sh"`. (Piping over stdin adds a BOM — avoid.)
- **File push = scp**, never base64-over-pipe (writes empty files; `php -l` false-greens).
- **`tasks.source` is a constrained enum** — article-share must keep a valid value (`agent`) and carry `payload.mode='article_share'` + `auto_share=true` markers (that's what LaunchScopePolicy detects). Do NOT set `source='article_share'` (data-truncation, silently breaks auto-share).
- **Agent model global scope breaks naive `updateOrCreate`** in the seeder (it hides removed rows → duplicate-insert). Use `Agent::withRemovedAgents()` for seed/attribution writes.

## 8. Key artifacts
- **Staging + `C:\Users\markr\LVL\`:** `BOSS888-STATE.md` (running state, W3 banner on top), `BOSS888-W3-CHECKPOINT-2026-07-20.md` (24-item W3 report), this file, `BOSS888-HANDOFF-2026-07-20.md`.
- **Backups (staging `/root/backups/`):** `prelaunch-src-20260719-221710.tar.gz`, `agent_capabilities-*` snapshots, `w3-capability-rollback.sql`. Per-file source `*.bak-w3-*`.
- **Local edited-file working copy:** `C:\Users\markr\LVL\w3-work\` (the 22 files as deployed).
