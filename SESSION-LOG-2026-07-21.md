# BOSS888 — SESSION LOG · 2026-07-21

Session goal: check the 2026-07-20 handoff → discovered the genuine v2.37.2 runtime source →
patched, validated and packaged v2.37.3 → continued into W4.

---

## 1. Handoff review + state re-verification
- Read `BOSS888-HANDOFF-2026-07-20.md`, `BOSS888-STATE.md`, `BOSS888-W3-CHECKPOINT-2026-07-20.md`.
- Ran the handoff's 2-minute re-verify: roster exactly 10 retained agents, workers 3/3, no other
  SSH sessions. **No regression since 07-20.**

## 2. Runtime source discovered
- `C:\Users\markr\LVL\levelup-runtime2-main-v2.37.2 .zip` (dated 07-21 12:40) confirmed **genuine
  v2.37.2** via `package.json` — not the stale 2.36.0. This cleared blocker item (a).
- Blocker item (b), Railway deploy access, remains outstanding → chose **Option 1** (patch + package
  locally, Boss deploys).

## 3. Scope correction
Original handoff named 6 files. Actual inspection:
- `registry.js` and `lu-agent-search.js` were **already clean** (0 references).
- **14 files** carried removed-agent references; **20 files** once removed-*tools* were included.
- Worked from the real footprint, not the handoff list. Fresh repo-wide search run before and after.

## 4. Patch
New: `launch-scope.js` (runtime mirror of Laravel `LaunchScopePolicy`), `launch-scope-validate.js`.
Modified (22): `agents.js`, `capability-map.js`, `tool-registry.js`, `tool-discovery.js`,
`lu-planner.js`, `task-memory.js`, `meeting-prompts.js`, `meeting-room.js`, `task-worker.js`,
`index.js`, `lu-event-bus.js`, `lu-synthesis-routes.js`, `lu-sarah-synthesis-routes.js`,
`assistant-tool-router.js`, `lu-worker-manager.js`, `tool-governance-intelligence.js`,
`tool-health-check.js`, `param-resolver.js`, `intelligence-validation.js`, `tool-test-runner.js`,
`package.json`, `.railway-trigger`.

Key decisions:
- **Kept `dmm`** as Sarah's runtime key (~25 call sites) — explicitly verified, not renamed.
- **Did not** blindly remap Marcus→Alex. Removed work is *unavailable*, not reassigned; the planner
  DROPS a task assigned to a removed agent rather than re-pointing it at a retained lookalike.
- **Article-share is context-bound**: `isArticleShareContext()` requires an explicit marker AND a real
  article id/URL. `publish_post` is not generally selectable.
- MARKETING_TOOLS / SOCIAL_TOOLS / CRM sequence tools **deleted**, not commented or merely filtered.
  The launch-scope filter remains as a backstop for aliases and future regressions.

## 5. Validation (all off-Railway, Node 20.20.2 on the droplet, isolated `/root/rt-w3rt`)
- `node --check`: **77/77 pass**
- `launch-scope-validate.js` (written this session): **87/87 pass**
- `intelligence-validation.js` (pre-existing, updated): **35/35 pass**
- Local boot + `/health`: clean, 10 agents, 43 tools, 0 removed, 0 errors
- `/ai/run` out-of-scope refusal: 6/6 refused; retained `write_article` correctly NOT refused
- Isolation: Redis DB 9, `ENABLED_WORKERS=none`, port 3987 — never touched the Laravel queue

**Three real bugs caught by validation before packaging:**
1. `list_campaigns` leaked into Sarah's tool prompt via a retained tool's param description
2. `email_subject_suggest` bypassed the out-of-scope guard and reached the LLM call
3. `update_post` sat in an executable dispatch allow-list in `index.js` bypassing registry + capmap

**Pre-existing bug fixed:** sparse-array holes in `capability-map.js` made
`hasCapability(agent, undefined)` return `true`.

## 6. Package
`levelup-runtime2-main-v2.37.3-launch-scope.zip`
SHA-256 `CD33FFBF7E6D7A2F4B97035C221435284E9244EDD6606AFD745B5108E5BDE647`
87 files · 313.2 KB zipped · 981.4 KB uncompressed · version 2.37.3
Re-extracted and re-validated on the server: checksum matched, 77/77 syntax, 87/87 + 35/35 tests.

Packaging defects caught and corrected: `index.js.bak2` initially slipped the filter; .NET
`CreateFromDirectory` wrote backslash path separators (rebuilt with explicit `/`).

## 7. W4 — routes & public endpoints (LIVE on staging)
- New `app/Http/Middleware/LaunchScopeRoutes.php`, registered `bootstrap/app.php:429`.
- **83 routes** now return 404 `not_in_product`: all `api/social/*` incl. public OAuth callbacks,
  `api/marketing/{campaigns,sequences,email,email-builder,automations}`, `api/mentions/*`,
  `api/watchlists/*`, `api/studio/designs/*/publish-social`.
- Protect-list verified: Stripe webhook 200, auth/password/verify/WP-plugin/internal untouched.
- Retained verified 200: seo/keywords, seo/articles, write/articles, crm/leads, builder/pages,
  agents, tasks, studio/templates, calendar/events.
- **No email open/click tracking pixel routes exist** — nothing to remove there.

## 8. Incidents this session
1. **PowerShell UTF-8 corruption** — `Get-Content -Raw` + `Set-Content` double-encoded em dashes in
   `tool-test-runner.js`. Restored from pristine baseline, redid via the Edit tool. Later PS file
   edits used `[System.IO.File]::ReadAllText/WriteAllText` with UTF8-no-BOM.
2. **Stuck queue worker** — `supervisorctl restart` left worker_02 wedged in STOPPING for 11 min.
   Killed the specific levelup-staging pid (verified it was not the unrelated `/var/www/ptaa` worker)
   and restarted. 3/3 RUNNING.
3. **`config:cache` regression (self-inflicted, fixed)** — I ran `php artisan config:cache`; live
   `RuntimeClient` reads `env()` directly, which returns null once config is cached. 12
   `RuntimeClient not configured` errors 14:00–14:06; `generate_meta` tasks 2143/2144/2145 failed.
   Fixed via `config:clear` + fpm reload + worker restart. `isConfigured: YES`, 0 errors since 14:20.
   **Rule: do not run `config:cache` on this app** until RuntimeClient moves to `config/services.php`.
4. **Wrong log filename** — I checked `laravel-YYYY-MM-DD.log`; this app writes a single
   `laravel.log`. My first "logs clean" reading was off a non-existent file and was re-done properly.

## 9. State at pause
- Staging: W4 live, workers 3/3, RuntimeClient configured, roster 10/10, no errors since 14:20.
- Railway: **untouched** — v2.37.2 still deployed. v2.37.3 package waiting.
- Production: **untouched** throughout.
