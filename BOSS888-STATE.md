# BOSS888-STATE.md

**Last updated:** 2026-05-07
**Updated by:** Claude Code (orientation session)
**Rule:** Refresh this file at every session end. Never let it go stale.

---

## Stack

- Laravel: 11.51.0
- PHP: 8.3.30 (NTS, OPcache 8.3.30, built 2026-05-05)
- Runtime: 2.28.0 (Railway, phase 2)
- Agents: 21 (dmm + 20 specialists)
- Tools: 58 runtime tools
- ai_run task types: 11 (`chat_json` LIVE — Phase 0.17 shipped)
- Internal routes: 3 (`/internal/health`, `/internal/image/generate`, `/internal/vision/analyze`)
- DB: `levelup_staging` @ 127.0.0.1 (MySQL 8.0.45)
- Redis: 6 (PECL-built ext, see project_redis_apt_untracked_files.md)
- Droplet: 134.209.93.41 AMS3 (`Level-Up-Growth`, Ubuntu 22.04)
- SSH key: `~/.ssh/do_levelup`
- Mail: Postmark PENDING APPROVAL (Test mode) ⏳ — infrastructure FULLY WIRED 2026-05-07: `symfony/postmark-mailer` v7.4.9 + `symfony/http-client` v7.4.9 installed, `POSTMARK_TOKEN` set, DKIM ✅ + Return-Path ✅ verified for `levelupgrowth.io`. Account is in Test mode pending Postmark manual approval (expected within hours). In Test mode, sends only deliver to addresses within verified domains (e.g. `admin@levelupgrowth.io` worked); external recipients will be rejected until approval. Once approval lands, no code change needed — mail starts flowing automatically.
- Notifications v2: LIVE 2026-05-07 — extended `notifications` table (+9 cols, backward-compat with existing send()), new `notification_preferences` table, NotificationService extended with dispatch/broadcast/unreadCount/markAllRead, queued SendNotificationEmail job + Blade template, NotificationController +4 endpoints, AdminNotificationController + admin broadcast, `lu:notifications:purge` command (daily 90-day retention), trigger wiring shipped at: StripeService (5 events, 9 sites incl. dev paths), AuthController (signup → admin), EngineExecutionService (agent task complete/fail/approval, gated by source==agent).
- Contact form pipeline (T3.2): LIVE 2026-05-08 — `POST /api/public/contact/{subdomain}` (rate-limited 10/min/IP), CRM write to `contacts` with email-based dedup + polymorphic `activities` touchpoint logging, LEAD_CONTACT_FORM + LEAD_DUPLICATE_FLAGGED notifications, BuilderRenderer::renderContact() rewritten with proper `name=` attrs + `fetch()` handler + success/error UI, blog gating (Growth+ plans only via `content_writing=true`; workspace 1 exempt), LEAD_CHATBOT_CAPTURE notification added to ChatbotResponseService::captureLead.
- Media Library (T3.1): UNBLOCKED 2026-05-08 — MediaController SQL bug fixed (removed `industry`, `description`, `use_count` references — columns no migration ever added; library() now returns 200). 17 platform hero images backfilled into `media` table (category=hero, is_platform_asset=1, source=platform). 6 mis-flagged Chef Red ws=2 rows corrected. All 17 hero JPGs regenerated via DALL-E 3 (1792x1024 hd) replacing earlier mockup screenshots — photorealistic stock photography, ~2-3MB per image, prompts + provenance recorded in media.prompt + metadata_json.
- Thumbnails + media delete + admin generate (T3.1D): LIVE 2026-05-08 (commit b19774a) — `ThumbnailService` (GD 400×300 cover-crop JPEG q85) + `ThumbnailController` (self-healing cache: nginx try_files miss → PHP generates + saves → 2nd+ request served by nginx directly) + `lu:thumbnails:backfill` artisan command (96/112 generated, 16 skipped due to pre-existing Chef Red path drift). DELETE `/api/media/{id}` (workspace-scoped) + DELETE `/api/admin/media/{id}` (admin) — both remove original + thumbnail + DB row. POST `/api/admin/media/generate` (admin DALL-E) generates with inline thumbnail so picker grid never shows blank cards.
- Admin Media Library Generate UI (T3.8): LIVE 2026-05-08 (commit 8320d5d) — `✦ Generate` button (cyan) added to admin Media Library toolbar alongside `+ Upload`. Modal: prompt textarea (1000-char counter, red >900) + size (square / landscape / portrait) + quality (standard / hd) + category dropdown + optional filename. Live cost preview (6-state matrix $0.04–$0.12). Auto-filename = first 5 prompt words slugified + epoch. Submit POSTs JSON to `/api/admin/media/generate`; success → toast `✓ Image generated — $X.XX charged` + grid refresh; backdrop click locked while busy. JS syntax verified via `node --check` (2,462-line SPA block clean).
- Security Perimeter Patch 1 (commit 6a6a921, 2026-05-08): post-DR-audit hardening. (1) Replaced `auth:sanctum` → `auth.jwt` on 6 builder routes (publish/unpublish/custom-domain x3/check-subdomain) — was causing HTTP 500 "Auth guard [sanctum] is not defined"; now returns 401 cleanly. (2) Created `config/auth.php` (was missing entirely; cause of guard resolution failure). (3) Throttle `10,5` on `/api/auth/login` + `5,15` on `/api/auth/forgot-password` — verified 429 on 11th attempt via direct-origin curl. (4) `AuthService::forgotPassword` no longer returns plain `reset_token` in JSON response; instead sends a Postmark email via new `resources/views/emails/password-reset.blade.php` template. (5) 4 `.env.bak*` files moved to `/root/quarantine/20260508/`.
- Scheduler + TrustProxies Patch 2 (commit c916bb3, 2026-05-08): (1) Laravel scheduler enabled via `www-data` crontab (`* * * * * cd /var/www/levelup-staging && php artisan schedule:run >> /var/log/laravel-scheduler.log 2>&1`). All 8 scheduled commands now fire — Sarah proactive (daily/weekly/monthly), `trial:expire`, `seo:track-ranks`, `credits:replenish-house`, `house:weekly-proactive`, `lu:notifications:purge`. Verified within 60s of install ("No scheduled commands are ready to run" = OK, none due now; lu:notifications:purge fires next at 0 0 * * *). (2) `App\Http\Middleware\TrustProxies` created with full Cloudflare IPv4 + IPv6 ranges (15 v4 blocks + 7 v6 blocks). Prepended in `bootstrap/app.php` so `request->ip()` resolves the real client IP from `CF-Connecting-IP` / `X-Forwarded-For`, not the CF edge node. Verified: standalone PHP test confirms `203.0.113.42` extracted from synthetic CF-Connecting-IP header (vs `162.158.42.5` REMOTE_ADDR). Direct-origin throttle test with fixed CF-Connecting-IP shows clean 9→0 decrement and HTTP 429 on 11th attempt. End-to-end through-CF test now emits `x-ratelimit-remaining` headers (was absent before TrustProxies).
- Durability + Observability Patch 6 (commit e84175c, 2026-05-08): the May-4-must-never-repeat patch. (1) **RUNBOOK-RESTORE.md** (283 lines, lives at repo root + git-tracked) — documented full restore procedure with two paths (snapshot restore = 5min RTO, full rebuild = 85min RTO). Lists every secret needed, every untracked file, every credential. Step-by-step with verified commands. (2) **`scripts/daily-backup.sh`** — reads DB creds from `.env` (no baked-in passwords), mysqldump → gzip → `/root/backups/db-{TS}.sql.gz`, 7-day auto-rotation, optional s3cmd offsite push when `/root/.s3cfg` is configured. **Verified working**: today's 714 KB dump compressed to 96 KB. (3) **`scripts/do-snapshot.sh`** — calls DO API to snapshot the droplet, reads droplet ID from cloud-init metadata, sources `DO_API_TOKEN` from `/root/.do-token` (placeholder, owner provides). Errors cleanly without crashing when token missing. (4) **Root crontab now has 3 entries** — daily-backup at 01:00, do-snapshot at 02:00, certbot renew at 03:00 (Patch 3). (5) **`sentry/sentry-laravel ^4.25` installed** via composer; `SENTRY_LARAVEL_DSN=` placeholder added to `.env.example`. Once owner provides DSN, errors will land in Sentry without further code change. (6) **`/root/MONITORING-SETUP.md`** (server-only, not committed) — 7-item owner checklist: CF SSL → Full, DO Spaces creation, DO API token, Sentry DSN, UptimeRobot monitor, OpenAI/DeepSeek spend caps, DO billing alert + second payment method (the May 4 root cause). **Local-only durability is live; offsite + observability are pending owner-action items.**
- Marketing Truth + Agent Fallback Patch 5 (commit 6447997, 2026-05-08): (1) **8 unsupported marketing claims removed** from both blade templates AND the static HTML files actually served. Critical finding mid-patch: routes/web.php at lines 48/52/56/etc. serves `public/marketing/*.html` (NOT the blade templates). My initial blade edits weren't reaching live pages — same fixes had to be applied to the static HTMLs. Specifics: home (`results-guaranteed` → `outcome-focused`; `run automatically — 24/7` → `Sarah proactively monitors and recommends`), features (`Multi-platform social posting (Instagram, LinkedIn, TikTok, X)` → `Native publishing to FB/IG; drafts for LinkedIn, TikTok, X to post manually`; `automation sequences` → `AI-assisted copy`; platform grid relabeled), specialists (Tyler `LinkedIn Marketing Expert` → `B2B Content Lead`; Aria `TikTok & Reels Creator` → `Visual Content Lead`; Jordan `Twitter/X & Community` → `Community & Social Listening`; Maya `drip campaigns` → `Email campaigns and newsletters`; Kai `Drip sequences` → `Email-campaign nurturing`; Marcus `creates and schedules across IG/LI/FB/TikTok` → `posts natively to FB/IG; drafts post-ready content for LinkedIn/X/TikTok`). (2) **`PromptTemplates::strategyMeeting()` DB-failure fallback** at lines 138-146 no longer hardcodes the 5-of-20 specialist list (James/Priya/Marcus/Elena/Alex). Now returns `"Your team is temporarily unavailable. Reason about general capabilities only."` so Sarah degrades gracefully rather than silently excluding 15 specialists when cache:clear races with a stuck DB connection. (3) **Plans DB verified** — already matches the canonical $39 add-on spec (Free/Starter/AI Lite/Growth: chatbot_included=false + addon_eligible=true; Pro/Agency: included=true). No PlanSeeder change needed; the audit's "mismatch" was vs. stale memory not vs. canonical spec. **Verified live**: curl `https://staging.levelupgrowth.io/` shows `outcome-focused` + `proactively monitors` (not `results-guaranteed` + `automatically 24/7`); `/specialists` shows `B2B Content Lead` + `Visual Content Lead` (not `LinkedIn Marketing Expert` + `TikTok & Reels Creator`); HTTP 200 on home + `/features` + `/specialists`. **Reported for next patch (NOT touched in this one)**: `AgentMeetingEngine.php:754,808` `selectTeam()` still has a hardcoded 6-agent keyword regex map limiting routing to 6 of 20 specialists — migrating to ToolSelectorService scoring is a follow-up.
- Hands-vs-Brain Re-Enforcement Patch 4 (commit 3f77d6c, 2026-05-08): the CLAUDE.md "eliminated 2026-04-12" claim was stale — forensic audit found **12 live LLM/provider bypass sites** across 7 files. All eliminated in one commit. (1) **StudioAiService**: 3 `$this->llm->chatJson` (lines 83/239/279) → `runtime->chatJson`; 1 direct DALL-E `Http::post` (line 166) → `runtime->imageGenerate`. Constructor: `DeepSeekConnector` → `RuntimeClient`. (2) **GeometryAnalyzerService + SceneValidatorService**: each had a direct `Http::post('https://api.openai.com/v1/chat/completions')` for GPT-4o vision → `runtime->visionAnalyze` (passes URL through). `getOpenAiKey()` deleted from both. (3) **BellaController**: `DeepSeekConnector $llm` DI removed (was injected but never actually called — `$this->runtime` has been the real path since Phase 2L.5). (4) **EmailBuilderService**: 3 `$this->llm->chatJson` (lines 681/737/766) + 1 `$this->llm->chat` (line 1421 — wrapped with JSON insight schema since runtime returns parsed JSON) → all `runtime->chatJson`. `DeepSeekConnector` DI removed. (5) **routes/api.php**: 3 inline closures rewritten — `/studio/chat` (line 2543-2580), AI assistant fallback (3684-3708), Arthur chat fallback (7084-7109) → all `runtime->chatJson`. (6) **AdminMediaController::generate** (T3.8): the direct `curl_init('https://api.openai.com/v1/images/generations')` I built today (line 606) → `runtime->imageGenerate`. Same-day cleanup. (7) **Studio AI credit gate**: 4 routes (`/api/studio/ai/{generate-design,generate-image,suggest-copy,chat}`) wrapped with shared closure helper that runs `FeatureGateService::canUseAI` (403 if not allowed) + `CreditService::reserve` (402 if insufficient) + commit-on-success / release-on-failure. Costs: generate-design 5, generate-image 8, suggest-copy 1, chat 1. Verified end-to-end: `POST /api/studio/ai/suggest-copy` returned HTTP 200 with 5 real LLM suggestions through the runtime + plan + credit pipeline. Builder publish + wizard 501 still hold.
- Origin TLS + Builder Wizard Patch 3 (commit dd86cff, 2026-05-08): (1) Let's Encrypt cert issued for `staging.levelupgrowth.io` (R13, expires 2026-08-06). nginx 443 server block added with HSTS (`max-age=31536000; includeSubDomains`). Auto-renewal: root crontab `0 3 * * * certbot renew --post-hook 'systemctl reload nginx'` plus the apt-installed `certbot.timer`. Cert covers `staging.levelupgrowth.io` only — **CF SSL must be set to "Full" (NOT "Full strict")** until a SAN/wildcard cert covers all `*.levelupgrowth.io` tenant subdomains. nginx config IS NOT in git — lives at `/etc/nginx/sites-available/levelup-staging` with backup `levelup-staging.bak-PATCH3-{TS}`. (2) Builder wizard fatal Errors eliminated. `BuilderService::wizardGenerate()` body replaced with clean 501-style return (was calling 4 helpers removed 2026-04-19). `POST /api/builder/wizard` and `POST /api/websites/create` route handlers swapped to inline 501 closures with `replacement: '/api/builder/arthur/message'` JSON. Both endpoints now return HTTP 501 with auth, HTTP 401 without — never 500. (3) Latent regressions fixed during verification: `config/auth.php defaults.guard` changed from `api` to `web` (the Patch 1 `api`/`token` driver was making `Auth::user()` query the non-existent `users.api_token` column on every auth'd request, producing 500s — now any auth path defaults to session guard, JWT remains the only real auth path via `JwtAuthMiddleware`). `routes/api.php` builder publish + unpublish closures were querying `workspaces.user_id` (column doesn't exist; ownership lives in `workspace_users` pivot) — both swapped to `workspace_users.where(workspace_id, user_id)`. **Verified end-to-end**: `/api/builder/websites/2/publish` with auth returns HTTP 200 success (was 500); both wizard endpoints return clean 501 with replacement message.

## Image generation policy (LOCKED — T3.6 implementation pending)

| Tier | Hero image source | Cost per site |
|---|---|---|
| Free | Fixed `/storage/builder-heroes/{industry}.jpg` (same stock per industry) | $0 |
| Trial (3-day) | Random pick from the existing 17 hero pool | $0 |
| Starter+ (paid) | Fresh DALL-E 3 hd 1792×1024 generated at site-creation time, stored at `/storage/workspace-heroes/{workspace_id}/{website_id}.jpg` | $0.12 |
| Growth+ — blog featured images | DALL-E 3 standard 1024×1024 | $0.04 each |

**Implementation gate:** T3.6 in PLAN.md. Depends on T5.2 (pricing) and BLOCKS production launch. Files to patch when T3.6 runs: PlanSeeder (`hero_image_generation` boolean per plan), FeatureGateService (`canGenerateHeroImage(wsId)`), the site-creation flow, BuilderRenderer (workspace-specific hero with industry-stock fallback).

**Today's T3.1 hero regeneration was a one-off platform-asset replacement** (the 17 industry stock photos themselves) — not a tenant-on-creation generation. Tenants are NOT generating heroes today; they get the industry stock by default until T3.6 ships.

---

## Last Commit

```
e84175c chore(durability): Patch 6 — DR runbook + backup automation + Sentry
6447997 fix(marketing/agents): Patch 5 — marketing truth + agent fallback
3f77d6c fix(architecture): Patch 4 — eliminate LLM bypass sites + studio AI credit gate
dd86cff fix(security/builder): Patch 3 — origin TLS + builder wizard
c916bb3 fix(security/scheduler): Patch 2
6a6a921 fix(security): Patch 1 — security perimeter
8320d5d feat: T3.8 Media Library admin generate UI
b19774a feat: on-the-fly thumbnails + media delete + admin generate
8c30b07 feat: unique template images — 79 DALL-E 3 slots across 17 industry templates
a4eab58 fix: AdminMediaController SQL — remove non-existent columns
67a4704 fix: MediaController SQL — remove non-existent columns
0e6e5bd feat: T3.2 contact form pipeline — platform-wide
f6029d1 fix: notification dedup — remove legacy send() duplicates in StripeService
```

---

## Workspaces & Sites

| Metric | Count |
|---|---|
| workspaces | 2 |
| websites | 2 |
| plans | 6 |
| users | 2 |

Plan slugs: `free`, `starter`, `ai-lite`, `growth`, `pro`, `agency`.

---

## Live Client Sites

**Static, deployed alongside Laravel:**
- `/var/www/chef-red/` — full site: `index.html`, `blog/`, `images/`, `sitemap.xml`, `404.html`, `_slug-map.json`. Chef Red is the first live client. CHATBOT888 widget injected (per recent commits).
- `/var/www/clutter-angels/` — `index.html` only (placeholder, full site deploy pending).

**Nginx sites-enabled (wildcard 2026-05-07):**
- `levelup-staging` → `134.209.93.41`, `levelupgrowth.io`, `*.levelupgrowth.io`, `chefredraymundo.com`, `www.chefredraymundo.com`
- `clutter-angels` → `clutter-angels.levelupgrowth.io` (likely shadowed by the wildcard now; can be retired later)
- Pre-wildcard backup: `/etc/nginx/sites-available/levelup-staging.bak-wildcard-20260507-1514`
- Effect: any new `*.levelupgrowth.io` subdomain auto-routes to Laravel; no manual nginx edits needed for new tenants.

**Nginx sites-available (NOT enabled):**
- `chef-red` (file exists but no symlink — chef-red.levelupgrowth.io and chefredraymundo.com are served via the levelup-staging vhost's multi server_name list)
- `default` (catch-all `_`)
- `levelup-staging.bak.20260506-2002` (rollback artifact)

---

## Chatbot Tables

8 tables (created 2026-05-06 via `2026_05_06_200000_create_chatbot_tables`, batch [7]):

```
chatbot_escalations
chatbot_knowledge_chunks
chatbot_knowledge_sources
chatbot_messages
chatbot_sessions
chatbot_settings
chatbot_usage_logs
chatbot_widget_tokens
```

---

## Templates

17 industry templates in `storage/templates/`:
architecture, automotive, cafe, childcare, cleaning, construction, consulting, education, finance, hospitality, logistics, marketing_agency, pet_services, photography, real_estate_broker, technology, wellness.

Each is a directory containing a `manifest.json`.

---

## Migration Ledger Summary

91 migrations, all status **Ran**. Batch breakdown:
- [1] 27 — auth/workspace/plans/credits/CRM foundation (2026-04-04)
- [2] ~50 — intelligence, creative, SEO, blog cols, studio, bookings (through 2026-04-18)
- [3] 11 — media library, studio, sequences, email builder, approvals, onboarding (through 2026-04-25)
- [4] `recovery_lost_columns` (2026-05-05 droplet redeploy patch)
- [5] `recovery_builder_default_assets` (2026-05-06)
- [6] `recovery_approvals_schema` (2026-05-06)
- [7] `create_chatbot_tables` (2026-05-06 — chatbot rebuilt post-recovery)

---

## Backups (latest on `/root/`)

- `backup-20260507-0646.sql` (604K) — today's morning backup
- `backup-20260506-2200.sql` (599K)
- `backup-20260506-1300.sql` (401K)

Daily backup is purely manual (no cron yet — open infra task).

---

## Known Code Gaps (refreshed end-of-day 2026-05-07)

**Resolved during Block 2** (commit 3ea4934):
- ~~`BuilderRenderer::injectChatbotWidget` missing~~ → added in T2.2; exercised end-to-end through nginx (with T2.5 `enabled=false` suppression)
- ~~PlanSeeder missing 4 chatbot keys~~ → added in T2.1; all 6 plans now have full entitlement matrix
- ~~No `STRIPE_MODE` env var~~ → set to `test` in T2.4 stage 1 (.env additions tracked via .env.example placeholders, not committed)
- ~~CHATBOT888 settings change doesn't bust render cache~~ → cache invalidation added in T2.3 (`updateSettings` / `mintWidgetToken` / `revokeWidgetToken`)
- ~~`Subscription` model fillable missing chatbot_addon fields~~ → fixed in T2.6 (latent pre-existing bug surfaced during verify)

**Resolved post-Block 2** (commits f8d98e9, 9939f6c):
- ~~`POSTMARK_TOKEN` empty + Postmark transport not installed~~ → infrastructure fully wired 2026-05-07. Token set, `symfony/postmark-mailer` + `symfony/http-client` v7.4.9 installed (commit 9939f6c), DKIM + Return-Path verified for `levelupgrowth.io`, live `Mail::raw()` test dispatched cleanly to admin@levelupgrowth.io. **Account in Test mode pending Postmark manual approval** — external recipients gated until approval lands. Code path is complete; no further dev work needed for mail to start flowing.
- ~~nginx server_name brittle (per-subdomain entries needed)~~ → wildcard `*.levelupgrowth.io` in place 2026-05-07 (commit f8d98e9 documents); new tenants auto-route to Laravel

**Still open**:
1. **Blog completely ungated** — `routes/web.php:128-129` + `routes/api.php:5125` have no auth, no plan check. Owner decision pending (T5.1).
2. **No `daily-progress/` or `storage/audits/` directories on the server** — both are local-only on Windows. Server-side log discipline not yet established.
3. **`subscriptions.chatbot_addon_active=1` for ws=2 with NULL `chatbot_addon_item_id`** — data drift from Chef Red May-6 setup. Functionally fine (Path 1 entitlement masks it). Cleanup deferred to T6.2.
4. **CHATBOT_ADDON_PRICE_ID not in `config/billing.php`** — only env-resolved. Works fine via `config(...) ?? env(...)` fallback in StripeService, but for symmetry with other Stripe keys should be added to the config file. Minor.
5. **Stripe live add-on flow only PARTIALLY verified** (T2.4) — synchronous `subscriptionItems->create` requires a real `stripe_subscription_id` on the workspace, which requires real checkout flow + webhook reachability. Deferred to T6.2.
6. **`storage/templates/` + `storage/creative-templates/` untracked in git** — 17 industry packs are on disk but not version-controlled. Should be tracked or explicitly gitignored.
7. **No `default_server` directive in nginx port 80 vhosts** — Host-header fallthrough relies on alphabetical sites-enabled load order. Surfaced during T2.2 when `levelupgrowth.levelupgrowth.io` was missing from server_name. Adding explicit `default_server` would harden against future drift.

---

## Open Tasks (from masterplan BOSS888-MASTERPLAN-2026-05-07.md)

| ID | Task | Status |
|---|---|---|
| T1.1 | STATE.md created | ✅ 2026-05-07 |
| T1.2 | Backfill daily-progress May 3-7 | ✅ 2026-05-07 |
| T1.3 | Update PLAN.md | ✅ 2026-05-07 |
| T2.1 | PlanSeeder chatbot keys | ✅ 2026-05-07 |
| T2.2 | BuilderRenderer injectChatbotWidget | ✅ 2026-05-07 |
| T2.3 | Cache invalidation on chatbot toggle | ✅ 2026-05-07 |
| T2.4 | Stripe TEST add-on verification | ⚠️ PARTIAL 2026-05-07 (live sub create → T6.2) |
| T2.5 | injectChatbotWidget: suppress when enabled=false | ✅ 2026-05-07 |
| T2.6 | StripeService chatbot_addon_active + Subscription fillable fix | ✅ 2026-05-07 |
| T3.1 | Template hero zoom/crop fix | ⬜ |
| T3.2 | Contact form audit | ⬜ (audit done 2026-05-07, fix pending) |
| T3.3 | Clutter Angels workspace setup | ⏸ ON HOLD 2026-05-07 |
| T3.4 | Chef Red: migrate static HTML → BuilderRenderer | ⬜ NEW 2026-05-07 (multi-session) |
| T3.5 | Platform rule: no static-only sites; DB sections_json required | ⬜ NEW 2026-05-07 |
| T4.1 | KB retrieval OR logic fix | ⬜ |
| T4.2 | LevelUpGrowth homepage content | ⬜ |
| T5.1 | Blog gating — awaiting owner decision | ⏳ |
| T5.2 | Pricing changes — awaiting owner input | ⏳ |
| T6.1 | Phase 9 APP888 patches | ⬜ |
| T6.2 | Phase 10 production deployment | ⬜ |
