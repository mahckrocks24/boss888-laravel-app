# BOSS888 — Living State Doc

> Single source of truth for current platform state. Updated end of each session.
> Last update: **2026-05-18 (Waves 1–15 complete; Wave 10 + 11 + 12 + 14 + 15 shipped this session)**

---

## Build state

- **Laravel**: 11.51.0 on AMS3 droplet `134.209.93.41` (web user `www-data`, path `/var/www/levelup-staging`)
- **PHP**: 8.3
- **DB**: MySQL `levelup_staging` (user `levelup`)
- **Runtime**: v2.25.3 on Railway (operational)
- **Active git branch**: `master` (uncommitted: scoring fixes + Waves 1–9 + Wave 13)
- **Cache buster (seo.js)**: `5.12.1-wave18c-rich-summary-pdf`
- **Cache buster (messages-ui.js)**: `4.6.0-wave16b-site-scope`
- **Cache buster (blog.js)**: `1.0.1-wp-publish`

---

## What shipped this session (2026-05-17 + 2026-05-18)

### SEO scoring system (F1-F12) — verified end-to-end
Weight rebalance to sum 100, F1 articles.seo_score writeback, F2 daily authority cron, F3 weight math, F4 H1 placeholder reject, F5 readability column, F6 UI clampScore, F7 score_version, F8 syncFromArticle full inputs, F9 per-workspace weight wiring, F10 dashboard per-category aggregation, F11 live `/api/seo/knowledge`, F12 4 missing-route aliases.

### Wave 1 — 90-day chat retention + disclaimer modal
- `seo_assistant_messages` table + 90-day purge cron
- Disclaimer column on users + `accept-disclaimer` route + UI modal

### Wave 2 — Driver behavior (rules 1-4)
- R1 audit-freshness gate on article gen (2-step proposal if last audit > 7 days)
- R2 `runPreflightSweep` tool aggregation on every proposal
- R3 auto-propose when LLM recommends alternative + keyword extraction
- R4 conversational tone in narrations + system prompt TONE block

### Wave 3 — Engine ecosystem
- R6 post-article engine chain (next_proposal mechanism in `branchConfirm`)
- R7 `aiInsertLink` actually mutates HTML (preview + apply, position rules)
- R9 RuntimeClient auto-injects workspace KB into every aiRun call

### Wave 4 → 5 — Proactive notifications, then refactored to platform messaging
- Wave 4 built parallel SEO-specific notification infrastructure
- Wave 5 refactored to use existing `agent_messages` + `notifications` + `messages-ui.js` floater
- New `AgentMessageService::postAsAgent($wsId, $slug, $content)` — single entry point any agent can use

### Wave 6 — Sarah on the platform messaging pattern
- All 4 ProactiveStrategyEngine sites now dual-write `notifications` + Sarah's `agent_messages` thread
- Same pattern available for any other agent (one-line per call site)

### Wave 7 — Publish wiring + Wave-4 cleanup
- New `POST /api/write/articles/{id}/publish` orchestrates: status flip → WP push → wp_post_id persist → Priya notification
- `blog.js _blPublish` now two-step (save edits + call publish endpoint)
- Removed 4 unused Wave-4 routes (table preserved for safety)

### Wave 8 — Two-context worker labels (the user-clarified branding rule)
- `_lgseIsEmbed()` + `_lgseAgentLabel(slug)` helpers
- WP iframe → all AI surfaces collapse to "SEO AI Assistant"
- Laravel SaaS → full named team (James, Sarah, Priya, etc.)
- Sarah delegation modal routes through SEO AI Assistant in embed mode (option C: open drawer + auto-send → user gets "Shall I proceed?" proposal)
- 5 FAB/drawer "LevelUp SEO" labels normalized to bare "SEO AI Assistant"

### Wave 9 — Context-aware publish notification routing
- WP plugin caller (X-API-KEY auth) → publish notification posts to James's thread (visible in WP SEO drawer)
- Laravel SaaS caller (JWT auth) → publish notification posts to Priya's thread (Content Manager owns the surface in SaaS UX)

### Wave 10 — post-publish image-optimization hook
- New `PostPublishOptimizeJob` (queued, 2 tries, 300s timeout): Tier-2 browser-renders the published URL → upserts `seo_images` rows → loops not-yet-optimized rows for that page_url → calls `OptimizationOrchestrator::dispatch()` for each. Plan-gating happens inside the orchestrator (free tier returns `plan_upgrade_required`, no spurious queue work).
- Wave 7 publish route (`POST /api/write/articles/{id}/publish`) now fire-and-forget dispatches the job after WP confirms the post. Publish endpoint stays fast (~200ms); puppeteer + compression runs async on the existing `levelup-worker` supervisor.
- Idempotent — orchestrator's `already_in_state` check prevents double-queueing on republish.

### Wave 11 — complete-article pipeline (text + image + alt + draft-on-failure)
- New migration: `articles.featured_image_alt VARCHAR(500)`, `featured_image_error TEXT`, `featured_image_attempts TINYINT`
- `SeoAssistantService::execGenerateArticle` now retries image gen 3× via `/api/connector/pages/regenerate-image` if the first connector attempt fails. Aborts retry early on `insufficient_credits` / `plan_upgrade`.
- On final failure: article stays as draft with `featured_image_error` populated + `featured_image_attempts` recorded. Narration tells user the image failed + how to manually retry.
- On image success: runtime auto-generates SEO-friendly alt text (max 120 chars) via `runtime->aiRun('seo_content_generation', ...)` and persists to `featured_image_alt`.

### Wave 12 — calendar-aware assistant + daily online report
- New `getCalendarContext($wsId)`: today + upcoming-7-days items from `calendar_events` + `articles.scheduled_at`. Returns `['today' => [...], 'week' => [...]]`.
- `buildLiveContext` now includes `'calendar'` in its return.
- `buildSystemPrompt` inserts a `════ CALENDAR ════` section (TODAY + UPCOMING) so the LLM is always aware of scheduled work.
- New `maybePostDailyOnlineReport($wsId, $userId)` called at top of `handle()`:
  - Fires only when gap since last user message ≥ 12 hours (`abs()` — Carbon 3 returns signed hours)
  - Idempotent via `meta_json->daily_report` flag on seo_assistant_messages
  - Posts a proactive assistant turn into Redis + DB chat history
  - Triggers `notify()` so the unified floater badge increments and `agent_messages` records the message under James's thread
- Smoke-tested end-to-end on staging: seed calendar event + scheduled article → daily report posts once → second invocation no-op (idempotent).

### Wave 18c — confirm-on-download + real PDF + 5×5 KPI summary
Three user-reported issues fixed in one wave.
- **Confirm before download**: both `lgseExportCsv` and `lgseDownloadReport` now show a `confirm()` prompt naming the report + the site scope (e.g. *"Download the Orphan pages CSV for shukranuae.com?"*) — no more instant-download surprises.
- **Real PDF**: new `tools/report-render-pdf.cjs` puppeteer script reads HTML on stdin and writes binary PDF on stdout. `GET /api/seo/reports/audit/pdf` now pipes the report through it via `proc_open`, returning `Content-Type: application/pdf` (106 KB for ws1, valid `%PDF-` magic bytes verified). Falls back to HTML 503 + `X-PDF-Fallback` header if puppeteer fails (script missing / proc_open failure / non-PDF output); the frontend detects this and warns the user before saving HTML.
- **Richer summary on Reports tab**: replaced the 5-KPI strip with **5 sections × 5 cards = 25 KPIs** matching the PDF report layout:
  - **Site health**: Avg score, Score change, Audits run, Open issues, Pages indexed
  - **Content**: Avg content score, Below 50, Thin (<300w), Missing meta, No H1
  - **Links**: Orphan pages, Weak (1–2), Suggested, Applied, Apply rate %
  - **Keywords + topics**: Tracked, Top 3, Top 10, Improving, Declining
  - **Images + anchors**: Image rows, Optimized, Image errors, Bytes saved, Generic anchors
- New `GET /api/seo/reports/summary` endpoint serves all 25 KPIs in one shot. Site-scoped via `SiteScope::hostFromRequest`. The SPA fetches it after the initial Reports-tab render so layout never blocks on it.
- **Cache buster** `5.12.0-wave18b-action-reports` → `5.12.1-wave18c-rich-summary-pdf`.
- **Smoke-verified end-to-end** for ws1 (52 avg / 0 change / 35 orphans / 592 suggested / 0% apply rate / 1 keyword / 0 image optimized) and ws7 (72 avg / -7 declining / 16 orphans / 1318 suggested / 0% rate / 2 keywords / 3 image errors / 2 generic anchors). PDF is a real PDF for both. Confirm dialog fires before every download.

### Wave 18b — 6 Tier-2 action-list reports (+ frontend wiring)
- **6 new CSV endpoints** sourced from data the SPA already correctly computes (Wave 16e/16f/16g):
  - `/reports/export/orphans` — Wave 16f orphan list (35/16 rows for ws1/ws7)
  - `/reports/export/weak-pages` — Wave 16f weak list (12/1 rows)
  - `/reports/export/quick-wins` — Wave 16c SeoDataService::quickWins data (6/2 rows)
  - `/reports/export/cluster-gaps` — Wave 16g per-cluster gap analysis (4/13 rows; emits ALL clusters with their gap-key field, healthy ones blank)
  - `/reports/export/anchor-health` — Wave 16e classification, but only rows with at least one issue (generic / over_optimised / too_long) — 17/54 rows
  - `/reports/export/link-backlog` — per-source-page aggregation of suggested vs inserted vs dismissed, with apply_rate_pct — 29/55 source pages
- **Frontend (`public/app/js/seo.js`)**:
  - Reports tab now has TWO sections: "Raw data exports" (the Wave-18a five) and "Action-list reports" (the Wave-18b six). Each button has a descriptive sub-label (e.g. "Pages with 0 inbound links").
  - `lgseExportCsv` and `lgseDownloadReport` patched to propagate `window._lgseActiveSiteUrl` via `?site_url=…` query string — closes the Wave-16 site-filter gap for raw-fetch helpers.
- **Cache buster** `5.11.2-wave16c-controller-routes` → `5.12.0-wave18b-action-reports`.
- **Smoke-verified** end-to-end for both ws1 and ws7: all 6 endpoints return 200, headers correct, row counts match the corresponding SPA tabs.

### Wave 18a — fix 7 broken Reports-tab endpoints (-7 silent 404s)
- The Reports-tab UI already had 5 CSV-export buttons + Export HTML + Print→PDF, but every one of them was calling a 404. Verified live: `curl -o /dev/null -w '%{http_code}' /api/seo/reports/...` → 404 for all 7.
- **New endpoints** (each honors Wave 16 `?site_url=` filter via `SiteScope::hostFromRequest()`):
  - `GET /api/seo/reports/export/keywords` — `seo_keywords` CSV; target_url-host filter with NULL-allowed for untargeted KWs
  - `GET /api/seo/reports/export/pages`    — `seo_content_index` JOIN `articles` (Wave 11 fields included)
  - `GET /api/seo/reports/export/links`    — `seo_links` (source OR target host match)
  - `GET /api/seo/reports/export/images`   — `seo_images` (page_url OR image_url host match)
  - `GET /api/seo/reports/export/anchors`  — flattened per-anchor rows with classification (Wave 16e logic reused)
  - `GET /api/seo/reports/audit/html`      — full single-page HTML report (KPI cards + site state + audit history table)
  - `GET /api/seo/reports/audit/pdf`       — same handler; frontend triggers `window.print()` to PDF-export
- **All streamed** via `response()->streamDownload(...)` — no PHP memory bloat on big workspaces (ws7 = 1,318 link rows, 110 image rows).
- **Smoke-verified end-to-end**:
  - ws1: keywords=1 / pages=48 / links=592 / images=33 / anchors=29 / html=4.8 KB ✓
  - ws7: keywords=2 / pages=62 / links=1318 / images=110 / anchors=59 / html=4.8 KB ✓
  - Bogus site_url → 0 rows for all (filter cuts cleanly). Untargeted keywords still surface (correct per Wave 16c OR-IS-NULL design).

### Wave 16d — fix duplicate "LevelUp Growth" in admin's site dropdown
- **Bug**: ws1 dropdown showed 7 entries including TWO "LevelUp Growth" — one was the real `websites` row id=2 (host=`platform.levelupgrowth.io`, the user's static Laravel-built house site), the other was a phantom `external_wp` synthesized from `seo_settings.site_url=staging.levelupgrowth.io` (stale platform dev URL).
- **Root cause**: `/api/seo/sites` dedup only checked exact host match. `platform.levelupgrowth.io ≠ staging.levelupgrowth.io` so both got listed even though they share the same logical brand and root domain.
- **Fix**: dedup now uses **root domain** comparison (last 2 dot-segments). If the external `seo_settings.site_url`'s root matches any internal `websites` row's root, the fallback entry is skipped. Unrelated brands (e.g. ws7 has no internal sites, external WP is `shukranuae.com`) still get the entry — verified.
- **Verified after fix**: ws1 → 6 entries (all real, no phantom). ws7 → 1 entry (shukranuae.com, kind=external_wp). Default site: ws1 → `123-fitness-gym.levelupgrowth.io` (first), ws7 → `shukranuae.com`.

### Wave 16c — site scope extended to controller-backed routes
- **SeoService** (controller-backed reads now honor `params.site_url` / `$siteUrl`):
  - `linkSuggestions($wsId, $params)` — filters `source_url` OR `target_url`
  - `listKeywords($wsId, $filters)` — `target_url LIKE` OR NULL (untargeted keywords show in every scope)
  - `listAudits($wsId, $filters)` — `url LIKE`
  - `getDashboard($wsId, ?$siteUrl)` — every sub-query (keywords, audits, links) filtered
  - `getReport($wsId, ?$siteUrl)` — forwards site_url to all sub-fetches
- **SeoDataService**:
  - `quickWins($wsId, ?$url, ?$siteUrl)` — `seo_audit_items.url` + `seo_keywords.target_url` both filtered
- **SeoController**:
  - `dashboard()` now passes `$r->input('site_url')`
  - `report()` now passes `$r->input('site_url')`
  - `linkSuggestions`/`listKeywords`/`listAudits` already pass `$r->all()` which contains site_url when the frontend sends it
- **Closures augmented** in `routes/api.php`:
  - `/quick-wins` — forwards `$r->query('site_url')`
  - `/topics/authority` — filters `seo_clusters.pillar_url`
  - `/links/anchor-analysis` — filters `seo_anchor_analysis.target_url`
  - `/anchors/bulk-analysis` — same (stats + distribution both filtered)
- **Smoke-verified end-to-end**:
  - ws1: keywords 1→0 (kw target on other domain), audits 19→1, dashboard `{kw:1,audits:19}→{kw:0,audits:1}` for staging.levelupgrowth.io scope. Bogus site → all 0.
  - ws7: keywords 2→2 (both targeted/untargeted match), audits 29→20 (9 historical URLs filtered), quickWins 2→2.
- **What's still workspace-only** (low-traffic surfaces, can extend later if needed): `/agents/{slug}/messages` history (already gets site_url via brand-facts), `/outbound`, `/redirects`, `/ai-status`, `/agent-status`, `/goals` (workspace-level by design).

### Wave 16b — Sarah + AI Assistant aligned to active site
- **SEO Assistant (`SeoAssistantService`)**:
  - New `$currentSiteUrl` private property + `siteHostPattern()` helper.
  - `handle()` reads `$context['site_url']` and stores it for the turn.
  - `buildLiveContext()` filters `seo_audits` (`url LIKE`), `seo_content_index` (`url LIKE`), `seo_keywords` (`target_url LIKE` OR `IS NULL` for global), `seo_links` (target OR source). Returns `active_site_url` so the system prompt knows.
  - `buildSystemPrompt()` now emits an **ACTIVE WEBSITE:** line in the LIVE SITE DATA section telling the LLM the current scope explicitly.
- **POST `/api/seo/assistant/message`**: accepts top-level `site_url` (in body, or `X-Lgse-Active-Site` header) and forwards it into the assistant context.
- **Sarah / all agents** (`POST /api/agents/{slug}/messages`): brand-facts block now injects `Currently active website: <url>` when site_url is sent, plus a directive line telling Sarah to anchor strategy + delegations to THAT site.
- **Frontend**:
  - `_lgseDrawerSend` (assistant FAB chat — line 7904) and `_lgseAssistantSend` (Overview's tab assistant — line 8010) both add `site_url: window._lgseActiveSiteUrl` to the POST body + `X-Lgse-Active-Site` header.
  - `messages-ui.js` agent chat (Sarah, James, Priya, etc.) merges `site_url` into the `/agents/{slug}/messages` body via `Object.assign`.
- **Smoke-verified end-to-end**: ws1's 48 pages narrow to 46 when scoped to `https://staging.levelupgrowth.io` (2 from other domain excluded); ws7's 62 pages stay 62 when scoped to its only site. Non-existent site URL returns 0. ACTIVE WEBSITE line verified in the system prompt.

### Wave 16 — global site dropdown (multi-site scope for the SEO engine)
- **Problem**: Laravel SaaS workspaces can register N websites in the `websites` table, but every SEO data table (SCI, keywords, audits, links, insights, articles) is workspace-scoped ONLY — no site discriminator. All tabs blend data across all sites of the workspace.
- **Phase A solution (Wave 16)**: URL-LIKE filter at query time. No schema change. Frontend dropdown selects active site; backend routes accept `?site_url=` and filter `WHERE url LIKE '%//<host>%'` (or source_url/target_url for the link-graph).
- **Backend**:
  - `app/Engines/SEO/Support/SiteScope.php` — new helper, extracts lowercased host from request's `site_url` query/body param.
  - `GET /api/seo/sites` — picker inventory. Unions `websites` table (internal Laravel sites with non-empty domain/subdomain/custom_domain) + `seo_settings.site_url` for external WP-connected sites (paid-tier, e.g. ws7). Default site = most-recently-audited host that matches a site row, else first site.
  - `/indexed-content`, `/link-graph/orphans`, `/link-graph` — now respect `site_url` query param. Empty/absent param = full workspace scope (backward compatible).
- **Frontend (`public/app/js/seo.js`)**:
  - New "Site" dropdown row injected by `buildShell` ABOVE the tab strip. Hidden in WP-embed mode (single-site iframe; backend filter also bypassed). Visible only in Laravel SaaS.
  - `window._lgseActiveSiteUrl` global, persisted per-workspace in `localStorage.lgseActiveSite_ws<id>`. Restored on page load.
  - New `lgseLoadSites()` populates dropdown from `/api/seo/sites`. New `lgseSwitchSite(url)` handler persists choice + re-renders current tab.
  - `api()` helper auto-appends `?site_url=<active>` to GET requests + merges into POST/PATCH body (when active site set). Bypassed entirely in embed mode.
- **Smoke-verified**: ws1 returns 7 valid sites (3 drafts with no domain filtered out). ws7 returns 1 (the WP-connected `shukranuae.com` from seo_settings). `/indexed-content?site_url=...` correctly narrows the page list.
- **Defaults per user spec**: most-recently-audited site (#1), no "All sites" view (#2 — scope is always single-site), AI Assistant context will be scoped in Wave 16b (#3), WP sites surface as `external_wp` dropdown entries.

### Wave 15.5 — Phase C: aggressive collapse (75 dead functions, -2040 lines, -19.9%)
- **Backup**: pre-15.5 snapshot of `seo.js` (633 KB) and `index.html` (360 KB) uploaded to `s3://boss888-backups/seo-cleanup-2026-05-18/` (DO Spaces). Local copies at `/tmp/seo.js.bak-pre-15.5-*` on staging. Git history at `d25d1f2` is the immediate-prior commit; full rollback via `git revert da1b8c6..HEAD` or restoring from the Spaces backup.
- **Process**: single-pass node script with brace-balanced body detection. Skip-list preserved: `_seoApi`, `_seoApplyTopLinks`, `_seoFixOrphan`, `_seoFixAllOrphans`, `_seoRetryImage`, `_seoNewGoalModal`, `_seoPageSave`, `_seoPagesState`, `_seoTab`. Everything else `_seo*` collapsed to a one-line `console.warn` stub. Special case: `_seoSwitchTab` stub also falls back to `lgseSwitchTab(arguments[0])` so any straggler caller still navigates correctly.
- **75 collapsed functions** include the major dead namespaces:
  - **Layer 1 originals** (`_seoDashboard`, `_seoAudits`, `_seoRunAudit`, `_seoViewAudit`, `_seoSerp`, `_seoRunSerp`, `_seoAddKeyword`, `_seoDeleteKeyword`, `_seoContent`, `_seoAiReport`, `_seoWriteArticle`, `_seoImproveDraft`, `_seoGoals`, `_seoCreateGoal`, `_seoPauseGoal`, `_seoResumeGoal`, `_seoWorkspace`, `_seoInsights`, `_seoReports`, `_seoOutbound`, `_seoCheckOutbound`, `_seoIntegrations`, `_seoRedirects`, `_seoAddRedirect`, `_seoDeleteRedirect`, `_seoSettings`, `_seoSaveSettings`, `_seoScoreSettings`, `_seoUpdateWeightTotal`, `_seoSaveScoreWeights`, `_seoStubPage`, `_seoStat`, `_seoError`, `_seoPages`, `_seoCtr`, `_seoWins`, `_seoGsc`, `_seoGenerateLinks`, `_seoInsertLink`, `_seoDismissLink`)
  - **GSC + Image legacy** (`_seoGscConnect`, `_seoGscSync`, `_seoGscDisconnect`, `_seoImages` override, `_seoImgSuggest`, `_seoApplyLink`)
  - **W2S2 Link Intelligence** (`_seoLinks` (147-line body), `_seoBuildLinkGraph`, `_seoCalcEquity`, `_seoTopics`, `_seoBuildClusters`, `_seoViewCluster`)
  - **Topics/Competitors** (`_seoCompetitors`, `_seoCmpAnalyze`, `_seoCmpGaps`, `_seoCmpCompare`, `_seoCmpTrack`, `_seoInsights` override, `_seoReports` override, `_seoDownloadReport`, `_seoExportCsv`)
  - **Overview consolidation** (`_seoOverview` × 2, `_seoAudits` override, `_seoKeywords` override, `_seoGsc` override, `_seoConnectGsc`, `_seoRenderShell`, `_seoSwitchTab`)
  - **Composed views** (`_seoPagesAll`, `_seoPipeline`, `_seoLinksAll`, `_seoInsightsAll`, `_seoViewAudit`, `_seoIntegrations`, `_seoOverview` final)
- **Verification**: file `seo.js` 10,231 → 8,191 lines (-2,040 / -19.9%). Syntax check passes. 22 references to CTA helpers preserved. Live entry chain confirmed end-to-end: `window.seoLoad` (line 7598) → `buildShell` (line 1386) → `window.lgseSwitchTab` (line 1553) → `renderPages` (line 2902) → `lgseRenderPagesTable` → user's Pages tab chips.
- **What to watch for**: `[LU SEO 15.5] dead path:` warnings in DevTools console during normal usage. If any appear, that's a real caller we missed and we revert immediately via `git revert` or the Spaces backup.

### Wave 15.4 — Phase B: collapse 8 intermediate overrides (-292 lines)
- Targets had explicit `window.X = ...` overrides further down the file; the later assignment always wins for global function bindings, so the intermediate bodies are unreachable. Collapsed via a single-pass node script (`tmp/wave15_4_collapse.js`) that finds each function's brace-balanced end and replaces the body with a one-line stub that warns if invoked.
- **Collapsed** (8 blocks, all the body weight saved):
  - `_seoKeywords` at line 608 (-112 lines)  · later override at 2466
  - `_seoLinks` at line 1133 (-65 lines)  · later override at 1280
  - `_seoRenderShell` at lines 1226, 1601, 2225 (-48 lines total)  · last live override at 2680
  - `_seoSwitchTab` at lines 1243, 1619, 2247 (-75 lines total)  · last live override at 2697
- **Preserved** (latest of each name kept as safety fallback): `_seoKeywords` @ 2466 · `_seoLinks` @ 1280 · `_seoRenderShell` @ 2680 · `_seoSwitchTab` @ 2697. All four are still unreachable per the live-entry chain (`window.seoLoad` → `buildShell` → LGSE `switchTab`), but conservative for Phase B — Phase C will collapse them once console-warning monitoring confirms zero callers.
- **Result**: `seo.js` 10,523 → 10,231 lines (-292, -2.8%). All Wave 15.2 CTAs intact (17 reference points). Live entry chain preserved end-to-end. Syntax check passes.

### Wave 15.3 — conservative dead-code collapse (5 originals)
- **Goal**: start retiring the cascading-override stack in seo.js. 5 functions where the original (lines 1-700) has at LEAST one explicit `window.X = …` override later in the file — i.e. 100%-confirmed-dead.
- **Touched** (function declarations only — kept callable as no-op stubs that warn if invoked):
  - `_seoRenderShell` (line 61) — replaced by buildShell/LGSE
  - `_seoSwitchTab` (line 93) — replaced by lgseSwitchTab; stub falls back to it so any straggler caller still works
  - `_seoKeywords` (line 159) — replaced by renderKeywords (LGSE)
  - `_seoLinks` (line 203) — replaced by loadInternalLinks (LGSE)
  - `_seoImages` (line 475) — replaced by lgseRenderImages (LGSE)
- **Untouched** (later overrides, kept as-is): all `window._seoSwitchTab =` / `window._seoRenderShell =` etc. assignments at lines 1226-2697. The LIVE UI is `window.seoLoad` (line 10022) → `buildShell` → `switchTab` → LGSE renderers. None of these legacy overrides actually fire, but conservative collapse — only originals this round.
- **Lines saved**: 10,559 → 10,523 (-36)
- **Safety**: anything still calling these prints `[LU SEO 15.3] dead path` warning in console. `_seoSwitchTab` stub additionally routes to `lgseSwitchTab` so even an unexpected caller still navigates correctly.
- Wave 15.2 CTAs (Pages chips + Links bulk buttons) verified intact (14 references preserved).

### Wave 15.2 — forensic: CTAs were in the wrong UI namespace entirely
- **Root cause (the real one)**: `seo.js` actually has TWO SEO UIs stacked in the same file. The original `_seo*` UI (lines 1-3300) plus a complete rewrite using the `lgse*` namespace (lines 3600-10000+). The LIVE entry point `window.seoLoad` (line 2813) calls `_seoSwitchTab` which the `lgseSwitchTab` IIFE has effectively replaced via `_seoRenderShell` / `lgseSwitchTab = switchTab` (line 3945). Result: the LIVE Pages tab is rendered by `renderPages` (line 5294) → `lgseRenderPagesTable` (line 5626). The LIVE Links tab is `renderLinks` (line 6118) → `loadInternalLinks` (line 6142). Both my Wave 15.0 AND Wave 15.1 edits hit the dead `_seo*` UI nobody renders.
- **The fix**:
  - **Pages tab chips** — added inline CTA chips inside the URL cell of `lgseRenderPagesTable` (lines ~5736-5754). Always visible regardless of toggleable column state.
  - **Links tab bulk button** — added `⚡ Apply top 20 (N)` and `⚠ Fix all orphans (N)` buttons into `loadInternalLinks` next to "Rebuild graph" / "Recalculate equity" (lines ~6211).
  - New `window._seoFixAllOrphans` handler — calls `/links/apply-bulk` with `mode=orphans_first` after a fresh `/link-graph/orphans` fetch for the confirm dialog count.
- **Cache buster**: bumped `5.10.1-wave15-ctas-fix` → `5.10.2-wave15-live-ui`. DevTools marker log updated to match.

### Wave 15.1 — dead-code fix: Apply-top button must live in the W2S2 override
- **Root cause**: `seo.js` declares `_seoLinks` at line 260 (function declaration), then immediately overrides it via `window._seoLinks = async function...` at line 1189 (W2S1) and again at line 1336 (W2S2 — "Link Intelligence" view). The override at line 1336 is what renders when the user clicks the Links tab. My Wave 15.0 edit went into the line-260 declaration — dead code. Fix: ported the "Apply top 20" button into the W2S2 override's title bar, with `suggestedCount` derived from a cheap `GET /links` probe.
- **Cache buster**: bumped `5.10.0-wave15-ctas` → `5.10.1-wave15-ctas-fix`. Old buster's URL would have been served stale by CF on next-fetch since CF only re-validates on a NEW URL.
- **Diagnostic console.log added** at the top of seo.js so users can confirm in DevTools console which version actually loaded: `[LU SEO] seo.js v5.10.1-wave15-ctas-fix loaded — Pages chips + Links bulk button active`.
- **Pages tab chips**: code at line ~870 was always live (only one `_seoPages` declaration, no override). Per disk check 18/25 page-1 rows have `inbound_links=0`, so once the browser loads the new file the orphan chip should appear on most rows immediately.

### Wave 15 — manual CTAs in Pages + Links tabs (both contexts)
- **Backend**:
  - `SeoAssistantService::bulkApplyLinkSuggestionsExternal($wsId, $userId, $params)` — public wrapper around Wave-14 executor for non-chat callers. Plan-gates, refreshes orphan count, returns unified envelope.
  - `execApplyLinkSuggestions` now honors `params.target_url` so per-page "Fix orphan" can scope to one URL.
  - `POST /api/seo/links/apply-bulk` — body `{limit?, mode?, target_url?}`. Returns `success/result` or 402 `insufficient_credits` envelope.
  - `POST /api/seo/pages/retry-image` — body `{url, force?}`. Looks up the article by `wp_post_id` (via SCI) or slug fallback, calls the existing connector regenerate-image endpoint, clears `featured_image_error` on success.
  - `GET /api/seo/indexed-content` augmented — LEFT JOIN `articles ON wp_post_id+workspace_id`. Response now carries `featured_image_error/alt/attempts` + `article_id`. New filters: `orphans`, `image_failed`.
- **Frontend (`public/app/js/seo.js`)**:
  - Links tab top bar — "Apply top 20" button (visible when queue has ≥1 suggested row). Confirms, calls `/links/apply-bulk`, toasts the applied/skipped/credits result with top skip reason.
  - Pages tab Actions column — orphan chip "⚠ Orphan · Fix" (when `inbound_links === 0`), image-error chip "⚠ Image · Retry" (when `featured_image_error` non-null). Tooltips show the failure message + attempt count.
  - New globals: `_seoApplyTopLinks(limit)`, `_seoFixOrphan(url)`, `_seoRetryImage(url)`.
- **Two-context safety**: chips/buttons render the same in WP iframe and Laravel SaaS. Underlying executor's `notify()` respects Wave-9 context-aware agent routing (WP → James thread, Laravel → Priya thread for content actions).
- **Cache buster**: bumped `seo.js?v=5.9.2-save-meta-fix` → `5.10.0-wave15-ctas`.
- **Smoke-verified**: bulk wrapper returns insufficient_credits envelope correctly when balance < 2 (ws1 has 0); retry-image returns 404 `article_not_found` for unmatched URL; indexed-content response carries all 5 new fields.

### Wave 14 — orphan-fix execution path (A + B + E)
- **A — Intent + Proposal** in `SeoAssistantService`: new `apply_link_suggestions` action. detectIntent recognises "fix orphans" / "apply link suggestions" / "add internal links" / "link the orphans" + regex preflight for variable phrasings ("fix my orphan pages", "apply 30 link suggestions"). Listed before `link_suggestions` so apply phrases win over generate.
- **B — Bulk executor** `execApplyLinkSuggestions($wsId, $params, $memory)`: picks top-N seo_links rows (status='suggested'), orphan-targeting first via SQL `ORDER BY CASE WHEN target_url IN (...orphans...) THEN 0 ELSE 1 END`, then calls existing `SeoService::aiApplyLinkInsertion` for each. Tracks applied/skipped + skip-reason histogram. Cost = applied × 2 credits (matches CapabilityMap `insert_link` cost). Refreshes orphan count for narration. Notifies via the unified messages floater.
- **E — Engine chain**: `execLinkSuggestions` now returns `next_proposal => apply_link_suggestions` whenever generation produced suggestions AND orphans > 0. A single user `yes` chains generate → apply.
- **System-prompt hint**: when both orphans > 0 and suggestions > 0, the assistant gets `TOOL AVAILABLE: apply_link_suggestions` line so the LLM proposes execution instead of "go to the Links tab".
- Smoke-verified on workspace 1 (35 orphans / 592 suggested): intent detection, proposal cost (50 credits cap for limit=25), narration, executor with limit=2 returned applied=0/skipped=2 with `source_not_internal_article` reason (correct — most ws1 suggestions target WP-hosted pages, which can't be edited from Laravel). System-prompt hint visible.

### Wave 13 — 404 route stubs
- 11 routes that the UI calls but had no backend handlers now return structured `{success, feature_status, message}` envelopes
- 404 count in full UI sweep: **13 → 1**
- **Wave 13b**: fixed the last 404 — `/connector/save-meta` (UI was calling `_seoApi('PATCH', '/connector/save-meta')` which mounted as `/api/seo/connector/save-meta`; corrected URL to `/save-meta` which routes to `/api/seo/save-meta` and accepts the same body)
- **Final 404 count: 0**. Every UI endpoint has a backend handler.

---

## Compliance with AI Assistant Operating Rules (all 7)

| # | Rule | Status |
|---|---|---|
| 1 | Audit-freshness preflight before article gen | ✅ Wave 2 |
| 2 | Tool sweep before proposing | ✅ Wave 2 |
| 3 | Proactive driver (auto-propose) | ✅ Wave 2 |
| 4 | Zero-SEO-knowledge user (friendly tone) | ✅ Wave 2 |
| 5 | No auto-publish, one-by-one | ✅ All waves; Wave 7 publish only on explicit user click |
| 6 | 90-day chat retention + disclaimer | ✅ Wave 1 |
| 7 | AI uses engines, never bypasses | ✅ Wave 3 (engines wired) + Wave 5 (platform messaging) |

## Compliance with two-context branding rule
- WP iframe → all AI worker surfaces show "SEO AI Assistant" only (Wave 8)
- Laravel SaaS → all AI worker surfaces show named specialists (unchanged from baseline)

---

## Active deprecations (kept for safety, can remove next sprint)

- `seo_assistant_notifications` table — no new writes since Wave 5, rows still queryable
- `'assistant_proactive'` role in `seo_assistant_messages` — no new writes
- `renderAssistant` dead-code tab handler — kept with leak labels fixed for hygiene

---

## Open known issues

| Area | Issue | Severity |
|---|---|---|
| Auto-optimization | ✅ Wave 10 shipped — `PostPublishOptimizeJob` queued from publish route; tier-2 scan + orchestrator dispatch | DONE |
| Featured image | ✅ Wave 11 shipped — 3× retry, alt-text auto-gen, draft-on-failure with explanatory error | DONE |
| Calendar | ✅ Wave 12 shipped — context injected into every system prompt + daily online report. Date-parsing for scheduling via chat is still future scope | PARTIAL (Wave 12) |
| `/connector/save-meta` PATCH | Last hard 404 — wrong route-prefix group, needs separate handling | LOW |
| Sarah extension | Sarah's existing calls now mirror to her chat thread (Wave 6 DONE). Other agents (Priya/Marcus/Elena) have no proactive code today | LOW (N/A until features land) |
| Git commits | All Wave 1-13 changes uncommitted on `master` working tree (safe only in tarballs) | HIGH |

---

## Crons active (relevant)
```
0  23  * * *   seo:track-ranks
0  3   * * *   seo:rank-track
30 3   * * *   seo:serp-refresh
0  0   * * *   seo:insights
0  3   * * *   seo:authority-score          # F2: weekly → daily
0  2,14 * * *  seo:outbound-check
0  4   * * 0   seo:cluster
0  4   * * *   seo:purge-chat-history       # NEW W1: 90-day chat retention
```

---

## Workspace 1 test data
- `articles.id=26` "E2E Smoke: Ergonomic Office Chairs Dubai" — kept for repeated smoke tests
- Disclaimer accepted for admin user (id=1)

INSTALL STATUS: SAFE TO INSTALL — STAGING ONLY (Waves 1-9 + 13 deployed + smoke verified).
