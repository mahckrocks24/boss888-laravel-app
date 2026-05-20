# BOSS888 STATE — Last refreshed 2026-05-20 14:05 UTC

## Active deliverables
- Laravel bundle: `boss888-laravel-FINAL-v1.0.0` + Wave 32-43 patches
- Runtime: **v2.28.0** on Railway (operational — `runtime:ping` clean, 20 agents + 58 tools registered)
- APP888 mobile: v1.3.0 (built, /exec-api/* not yet wired)

## Latest deployed commit
- `024cf51` — Wave 43 — `/connector/generate-article` routes through Sarah chain services
- Branch: `master` on `origin` (mahckrocks24/boss888-laravel-app)

## Asset versions (cache busters)
- `public/app/js/core.js` → `v4.9.6-cmd-meeting-open`
- `public/app/js/seo.js` → `v5.23.0-wave33`

## Schema state
- Last migration: `2026_05_19_000001_add_chat_meter_to_workspaces`
- DB: `levelup_staging` on AMS3 droplet 134.209.93.41

## Credit pricing (canonical, May 2026)
- Chat (Aria + agent direct): 0.1 cr (batched 10:1 via `CreditService::meterChat`)
- Strategy meeting (multi-agent): 8 cr
- SEO research/check/competitor: 1-3 cr per action
- **Article chain (Sarah / WP)**: 2 cr canonical bundle — parent `write_article=2`, chain children=0 (Wave 42)
- Standalone actions retain CapabilityMap defaults (image_mini=1, meta=1, link_suggestions=1, insert_link=2)
- Image standard / high: 2 / 4 cr
- Video (5s): 8 cr
- Sitemap utilities: 0 cr (free)

## Sarah chain (SPA + WP, both end-to-end)
- 5-step chain: `write_article → generate_meta → generate_image_mini → link_suggestions → insert_link`
- Chain gating via `parent_task_id`. Children marked `blocked` until parent completes (Wave 41).
- Children wake on parent completion via Orchestrator hook re-dispatching them. Wave 35b passthrough now runs BEFORE `$dispatchMap`.
- Credit reservation reads `$task->credit_cost ?? $capability['credit_cost']` so chain-bundle override survives (Wave 42b).
- WP path: `/connector/generate-article` calls `WriteService::writeArticle` + `generateMeta` + `CreativeService::generateImage` synchronously. Direct DeepSeek POST eliminated (was a hands-vs-brain violation).

## Command Center "Your team right now"
- Shows ENABLED-only agents (workspace_agents.enabled=true). Plan-tier gate: Growth=Sarah+2, Pro=Sarah+5, Agency=Sarah+10.
- Per-agent stats read from `tasks` table directly (orchestrators count delegated, specialists count `assigned_agents_json[0]`).
- Activity feed reads `metadata_json.agent_slug` first, then walks tasks for `task.*` entries. Excludes `agent.direct_message` + `approval.*` noise.
- Feed labels for `task.*` use `metadata_json.action` + real worker: "Sarah delegated *X* to *Worker*", "*Worker* completed *X*".

## Active chat surfaces (Wave 31 audit)
- `#ai-input` + `/api/assistant` — Aria Laravel SPA assistant ✓ metered
- `#lgse-drawer-input` + `/api/connector/assistant/message` — SEO drawer (WP + SEO engine) ✓ metered
- `#agent-msg-input` + `/api/agents/{slug}/messages` — Laravel app shell agent drawer ✓ metered
- `#lu-msg-input` + `/api/agents/{slug}/messages` — floating messages modal ✓ metered (duplicate)
- `#cmd-input` + `/api/meeting/*` — Strategy Room (8cr meeting price)

## XML Sitemap (Wave 32)
- Laravel-hosted: `https://{subdomain}.levelupgrowth.io/sitemap.xml` (PublishedSiteMiddleware)
- UI: collapsible card in SEO Engine → Pages tab. `GET /api/seo/sitemap` Mode A/B. `POST /api/seo/sitemap/ping` Google+Bing.

## Open / deferred
- `WriteService::generateMeta` intermittently falls back when runtime returns non-JSON — needs markdown-fence stripping + retry before fallback.
- Wave 37 deferred: Calendar/pipeline integration + daily reports.
- Hard-delete dead `renderAssistant` in seo.js.
- Decide agent-chat duplicate (`#agent-msg-input` vs `#lu-msg-input`).
- Per-page sitemap exclusion (`pages.in_sitemap`).
- `authority_score=0` on all indexed articles — `seo:authority-score` cron may be broken.
- Cloudflare API token rejected on purge — needs rotation.

## Daily backup
- Latest: `/var/www/backups/eod-2026-05-20-20260520-140431/`
- 6 artifacts: laravel-code (12M), db dump (1.2M), log tail (835K), git-log/history/diff-stat
