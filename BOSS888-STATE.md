# BOSS888 STATE - 2026-07-24 (SARAH + PUBLISHING + FEATURED IMAGES)

Handoff: `/root/handoff-2026-07-24/HANDOFF-SARAH-PUBLISHING-2026-07-24.md`
(local mirror `C:\Users\markr\LVL\handoff-2026-07-24\`).

### DONE THIS SESSION
- **Chef Red (ws2): 0 articles missing a featured image** (was 28). Sarah ran it
  herself via chat. Credits 137 -> 66.
- **Article publishing works end-to-end** (was fully broken — 5 bugs fixed).
- **Sarah chat UX/intelligence fixed**: sensible greeting/social acks, no more
  28<->10 count flip-flop, no raw tool-language leaks, reliable bulk image fill.

### ⚠️ OPERATIONAL RULE (learned the hard way)
Queue workers (`levelup-worker:*`) are long-running and DO NOT reload `app/` code
on edit. After ANY `app/` change:
`php artisan queue:restart && supervisorctl restart levelup-worker:*`.
`routes/api.php` + `public/*` need no worker restart. Never `config:cache`.

### FIXES (each has dated .bak + /root/backups copy)
- W6 Cloudflare edge CLEARED -> W6 COMPLETE AND VERIFIED.
- Calendar IDOR + invalid-column patch (routes/api.php) -> SECURITY PATCH VERIFIED (26/26).
- AgentSeeder 'dormant'->'disabled' (8/8).
- Publishing (5): index.html BASIC_VISIBLE+blog/write; CapabilityMap publish_article;
  routes/api.php server-side target resolution + two-turn title carry; TaskService
  explicit-confirmation opt-out; Orchestrator image-prompt ordering.
- Params: CrmService::createLead name-derive; SeoService::deepAudit URL-resolve;
  CapabilityMap retry_blocked.
- UX: AckGeneratorService greeting/social acks; ToolSchemaService leak-fix +
  missing-image breakdown; routes/api.php image grounding breakdown; routes/api.php
  fill_missing_images DETERMINISTIC BACKSTOP; Orchestrator dispatch ENGINE
  NORMALIZATION (the real fill_missing_images killer — creative vs write).

### OPEN (nothing blocking)
1. Sarah "fabricated success" integrity bug (reports done when task failed/dropped/
   never emitted) — biggest open item; needs reply-vs-actual-outcome reconciliation.
2. competitor_gaps = stub ("not supported"). 3. calendar/create_event stuck pending.
4. ws2 stuck tasks: publish_article #2160/#2161 (07-22), delete_lead #1890 (07-15).
5. Draft #408 "Private Chef Cost NJ" still draft — one "yes publish" takes it live.

### CONCURRENT (not mine): INFRA888 Hosting created 5 QA-range ws (tattoo/realestate)
22:04-22:32 on 07-23 + ws4 articles. My clones all torn down (0 @qa.invalid).

---

# BOSS888 STATE - 2026-07-23 (PUBLISHING PIPELINE FIXES)

### ARTICLE PUBLISHING — END-TO-END FIXED AND VERIFIED (2026-07-23)
Boss report: "not able to publish articles" + "if the user explicitly gives a go,
Sarah confirms then publishes". Root-caused to FIVE independent defects, all fixed,
verified through the LIVE FPM path (CLI opcache reads were unreliable — FPM is authoritative).

**BUG 1 — Blog/Write unreachable in Basic mode (the primary "can't publish").**
`public/app/index.html:2399` `BASIC_VISIBLE` omitted 'blog' and 'write', so the
mode-switch guard swallowed `nav('blog')`, `blog.js` never loaded, and the Publish
button had NO handler. Zero publish POSTs ever reached the server (nginx 09-23 Jul).
Fix: added 'blog','write' to BASIC_VISIBLE. Verified in Chromium: publish POST fires
in basic/unset/advanced. Live through CF (`DYNAMIC`, uncacheable). Users must hard-refresh.

**BUG 2 — `publish_article` never registered as a capability (capability<->dispatch drift).**
`write/publish_article` was in the Orchestrator dispatch map, TaskCategoryService,
ApprovalController labels and Sarah's prompt — but MISSING from `CapabilityMapService`.
`Orchestrator::execute()` threw "No capability mapped for action: publish_article" at
step 2, so an agent-driven publish had NEVER once succeeded. Fix: registered
`'publish_article' => [engine=write, connector=null, approval_mode=protected, credit_cost=0]`.
Same class as the 2026-07-14 move_lead drift, mirrored.

**BUG 3 — Sarah invents article_id; task silently dropped.**
The chat LLM guesses `article_id` (ws2 2026-07-22: SIX publish tasks dropped as
"article_id=385..390 not in this workspace"; QA: article_id=1/2). The guard at the
Sarah-chat task loop discarded the task with only a Log::warning — the user was told
"publishing now" and nothing happened, silently. Fix: RESOLVE the target server-side by
title against the workspace's own drafts (single-draft workspaces are unambiguous);
"backend finds the id, Sarah never guesses" — the same principle the codebase already
uses for write/fill_missing_images.

**BUG 4 — publishing double-gated / language wrong (Boss decision).**
publish_article was in `$destructiveActions` (forcing requires_approval) AND
TaskService force-gates every 'publish'-category action. So even after the user
confirmed, the article sat in the Review Queue for a SECOND consent. Boss decision:
one explicit go is enough. Fixes: removed publish_article from $destructiveActions;
TaskService now honours an explicit-confirmation opt-out for `publish_article` ONLY
(website/page publishes and all other publish-category actions stay hard-gated);
the affirmative is matched SERVER-SIDE from the user's own message (deterministic —
not trusting the LLM); Sarah's prompt updated to emit the task on confirm and to stop
promising a Review-Queue step that no longer exists for article publishing.

**BUG 5 — featured-image generation fails ("Missing required parameters: prompt").**
ORDERING bug in `Orchestrator::execute()`: the parameter resolver (step 5) hard-requires
`prompt` and threw BEFORE the dispatch map (step 7) could call `ensureImagePrompt()`,
which derives a prompt from the article title/id. Sarah proposal/chat image tasks
carrying only title/article_id died on every retry, and repeated failures tripped the
creative connector's circuit breaker, degrading later image tasks. Hit ws2/ws29/ws990003.
Fix: derive the image prompt BEFORE validation for the three creative image actions.

**GUARDRAIL (live FPM, definitive):**
- Request-only, no confirmation -> NO publish task, article stays draft. Gate holds.
- Request THEN "yes, confirm" -> published, approval=0, ~10s. Works as asked.
- publish_website with a stray confirm flag -> still hard-gated. Scope not leaked.

**FILES CHANGED (6, each with dated .bak + /root/backups copy):**
- public/app/index.html                          (BASIC_VISIBLE; bug 1)
- app/Core/EngineKernel/CapabilityMapService.php (register publish_article; bug 2)
- routes/api.php                                 (server-side id resolution + confirm flag + $destructiveActions + Sarah prompt; bugs 3,4)
- app/Core/TaskSystem/TaskService.php            (confirmation opt-out; bug 4)
- app/Core/TaskSystem/Orchestrator.php           (image prompt ordering; bug 5)
- (AgentSeeder.php + Calendar security were earlier today — separate blocks)

**RECONCILIATION:** routes 978, workers 3/3, jobs/failed 0, transmitted:true 0,
QA leftovers 0. Baselines: users 11, workspaces 20, articles 349, tasks 2060, websites 28.
17 ERROR lines dated today are ALL accounted for: rolled-back Calendar repros, the image
bug now fixed, the "no capability" failures now fixed, and my own QA-script bugs. None are
new production breakage.

**STILL OPEN / FOR THE BOSS:**
- ws 27/28/29/990003 will still 422 `site_not_configured` on publish — their Builder site
  is `status=draft`. Publish the Builder site first (Boss action — outward-facing).
- The mobile companion app has NO publish route (11 exec-api routes, none for articles).
- ws1 & ws7 share an identical `webhook_secret` (looks like copy-paste).
- `_blApi` (blog.js:22) discards HTTP status — errors surface via body only; fragile.
- 4 Infrastructure files (ProvisioningService, SubdomainService, infrastructure.js,
  SubdomainServiceTest) show today's mtime but were NOT changed by this work — a separate
  INFRA888 process is active (infrastructure.js changed at 17:45, after my last edit).

---

# BOSS888 STATE - 2026-07-23 (W6 CLOSED + CALENDAR SECURITY PATCH)

### W6 COMPLETE AND VERIFIED (2026-07-23). Cloudflare edge condition CLEARED.
Canonical `N22` PASS + `N23` PASS (as originally written, origin-only) **plus** a new
edge-aware extension **32/32** and a real headless-Chromium execution proof **16/16**.
- The canonical N22/N23 were **origin-only** - that is precisely why they passed on
  2026-07-22 while Cloudflare was still serving the four removed bundles. Both layers
  are now kept: `/root/w6-edge-retest.sh` (edge+origin) and `/root/w6-edge-qa.cjs` (Chromium).
- All four URLs: HTTP 200, `content-type: text/html`, `cf-cache-status: BYPASS`, 349237 B,
  origin and edge **byte-identical** (sha256 `beef72b0b8223652`). PoPs covered: DXB, MRS, AMS.
- Signatures were extracted from the REAL pre-removal bundles in
  `/root/backups/w6b-presrc-20260722-191104.tar.gz`, not guessed. All 21 absent at edge AND origin.
- Chromium fired `onerror`, never `onload`: the browser **refuses to execute** all four on
  MIME/nosniff grounds. `LU_LOADED_ENGINES` empty, `socialLoad`/`mktLoad`/`automationLoad`/
  `mentionsLoad` all `undefined`, live app requests 0 of the 4, 0 page errors.
- **Why this is durable, not a TTL fluke:** the bundles are gone from disk, so the SPA
  fallback answers and it sets a session cookie - Cloudflare will not cache a `Set-Cookie`
  response, so `BYPASS` is now structural. The old objects had `max-age=14400` and no cookie.
- **CLEANUP ITEM (not a blocker):** a missing static asset returns the SPA document rather
  than 404. Fine for W6 (no removed source is reachable or executable) but should eventually
  be a real 404 for `/app/js/*.js`.

### CALENDAR/CRM TENANCY + SCHEMA SECURITY PATCH - COMPLETE AND VERIFIED
Re-derived independently from live code and schema; the prior line numbers had shifted.
- **IDOR** `PUT /api/crm/appointments/{id}` (`routes/api.php:4044`) updated
  `calendar_events` scoped by **id alone**. Any authenticated user of any workspace could
  mutate any workspace's event by global id. No role gate exists on the `crm` prefix.
- **INVALID COLUMNS** `calendar_events` has **no `type` and no `status` column**.
  `POST /appointments` wrote `type` (so it threw `SQLSTATE[42S22]` on *every* call and has
  never once succeeded); `PUT` wrote `status`.
- **FIX (one file, no migration):** `type` -> real column `category`; `status` dropped (no
  domain concept backs it - no column was added to preserve a controller mistake);
  `->where('workspace_id', $wsId)` added; uniform `404 {"error":"Event not found"}` for
  missing AND foreign ids; `title`/`starts_at` validated -> 422 instead of a SQL error.
  `/api/calendar/events/{id}` PUT+DELETE were already scoped but returned a bare bool and
  surfaced not-found as 500 - now a uniform 404.
- **26/26 isolated tenancy tests green.** Cross-workspace update/delete denied; workspace B
  row byte-unchanged; missing vs foreign responses **byte-identical** (no existence leak);
  client-supplied `workspace_id` cannot move an event; same-workspace CRUD still works.
- QA used two NEW isolated workspaces (990022/990023) - **workspace 2 was not reused** and no
  QA user was added to a customer workspace. Full teardown; all baselines restored exactly.
- **Exploitation evidence:** nginx logs retained 09-23 Jul show 10 hits on
  `/api/crm/appointments` (all `GET ?upcoming=1`, all 200) and 8 on `/api/calendar/events`
  (all GET). **Zero PUT/DELETE with a numeric id, ever, in the window.** Laravel log 13-23 Jul:
  26 `42S22` errors, **none on `calendar_events`**. **What this CANNOT prove:** anything before
  09 Jul (rotated away), and `calendar_events` is empty (0 rows, AUTO_INCREMENT 383) so there
  is no row-level audit trail. Absence of evidence, not evidence of absence.
- Backups: `routes/api.php.bak-20260723-calsec` + `/root/backups/api.php.bak-calsec-20260723-071400`.
  Rollback = restore either + `php artisan config:clear`. **Never `config:cache`.**

### AGENTSEEDER RESIDUAL - FIXED (8/8 regression green)
`AgentSeeder` set removed agents to status `'dormant'`, but `agents.status` is
`enum('active','disabled')` and MySQL runs `STRICT_TRANS_TABLES` - so **every reseed aborted**
with `Data truncated for column 'status'`. Changed to `'disabled'`, an existing enum member.
No migration, no deleted rows. Verified by running the seeder for real inside a transaction:
0 removed agents active, all 10 retained as disabled historical rows, 10 retained still active,
attribution preserved, then rolled back with production rows untouched.
- **OPEN DECISION:** the 10 removed agents are still `status='active'` in the live DB (reads are
  already neutralised by `AgentDirectory` + the model global scope, which key off slug not
  status). A reseed would now correct them to `disabled`. Not changed in this pass.
- **NEW RESIDUAL FOUND:** the seeder contains an **11th agent, `sam`, not present in the DB and
  not in either the removed or retained list**. A reseed would create it as **active**, breaking
  the "10 agents" invariant (check 43). Needs a scope decision before any reseed.

### DASHBOARD REACT BUNDLE - DEAD SOURCE, NOT SERVED (do not modify)
`resources/js/pages/Dashboard.jsx` does contain a removed-agent colour map
(`marcus:'#EC4899'`, `leo`) but it **does not participate in production**:
- vite `outDir` is `public/app-react/` - **that directory does not exist**; the build has never
  been run into the webroot.
- **No `@vite` directive in any blade.** No `public/build/`. No hashed vite bundles.
- The customer SPA is `public/app/index.html`, which loads only hand-written
  `/app/js/*.js` (launch-scope, orb, core, onboarding, media-picker, builder, messages-ui,
  arthur-chat, chatbot-spa, workspace-v2). No React.
- `resources/js/*` is outside the webroot: `/resources/js/App.jsx` -> **404**, `/main.jsx` -> **404**.
- Every file under `resources/js/` is untouched since the 2026-04-05 scaffold.
Per directive, unrelated React source was NOT altered.

### NEW FINDING (W6-class, NOT fixed - needs a decision)
`public/js/*.js` is a **legacy vanilla bundle set that IS publicly fetchable**:
`/js/dashboard.js` -> **200, `application/javascript`, 13108 B**. Five files contain removed-agent
or removed-feature copy: `agents.js`, `dashboard.js`, `engines.js`, `orb.js`, `tasks.js`
(e.g. `marcus:'social'` orb map, `'Campaigns sent (7d)'`).
It is referenced by **no** blade and **no** served app shell, so no customer sees it in-product -
but it is directly retrievable, same class as the `.bak` webroot finding W6b fixed.
**NOT removed in this pass:** `site.js`/`app.js`/`auth.js` were modified 11-12 May, later than the
rest, so published customer sites may reference them. Verify against published sites first.

### RECONCILIATION (2026-07-23)
Routes **978 -> 978**. Workers 3/3 RUNNING. jobs 0 / failed_jobs 0. `transmitted:true` = 0.
publisher_posts/social_posts/social_accounts = 0/0/0. leads 29, activities 10, pipeline_stages 20,
tasks 2039, articles 346, users 11, workspaces 20, calendar_events 0. **No provider contacted.**
W6b regression re-run post-patch: **76 passed, 5 failed** - all five are teardown artifacts
(4x "no token", 1x "QA accounts users=0 ws=0"), i.e. the cleanup succeeded. Not regressions.
One `ERROR` dated 2026-07-23 is mine: a deliberately rolled-back FK-constrained repro attempt
at 07:34:36; nothing was written.

### NEXT -> W7 public marketing + pricing truth (now UNBLOCKED).
`resources/views/marketing/{home,pricing,features,faq,specialists}.blade.php` + the static
`public/marketing/pages/*.html` twins. Check `specialists.blade.php` first.

---

# BOSS888 STATE - 2026-07-22 (W6b LAUNCH-SCOPE REMEDIATION)

### W6 COMPLETE WITH CONDITIONS (2026-07-22) - the real frontend/payload/mobile pass. LIVE.
**352 checks green** = 81 W6b harness (51 spec + 30 new-defect) + 127 prior regressions
+ 144 AUTHENTICATED headless-Chromium QA across 8 context/viewport combos.
**0 provider transmissions** (`transmitted:true` = 0 of 120 log lines). No route reopened.
Routes 978 -> 978. Queues 0. Stranded credit reservations 0. Workers 3/3.

- **THE FINDING THAT MATTERED:** `LU_SCOPE.filterAgents` and `isRemovedAgent` had **ZERO
  callers**. The agent half of the W6 guard was dead code. Shortest path to a customer
  seeing a removed agent was: log in -> Agents -> the STATIC Marcus card at
  `index.html:4167`. No API, no cache, no crafted reply needed.
- **MOBILE PERIMETER WAS ZERO:** `LaunchScopeRoutes` matched only `api/*`; every mobile
  route is `exec-api/*`. Fixed by namespace normalisation against the SAME blocklist
  (no second list). 23 live legacy task rows (marketing 16 / social 7) + 11 rows assigned
  to removed agents were reachable; now rejected as legacy, NOT deleted.
- **RAW-QUERY BYPASS:** `DashboardController::agentForSlug` + `ApprovalController::agentBadge`
  used raw `DB::table('agents')`, walking around the `Agent` global scope. NEW
  `app/Core/LaunchScope/AgentDirectory.php` is now the single authority - a removed agent
  resolves as `"<Name> - historical agent"`, `active=false`, `assignable=false`.
- **HISTORICAL RECORDS PRESERVED** per directive: 10 agent rows, 11 tasks, 129 messages,
  12 meeting messages, 16 proposals all KEPT. Authorship intact, availability removed.
- **LANGUAGE GUARD 2 -> 5 call sites** (+ mobile chat, + WordPress/SEO assistant, +
  historical message read). Added `SELF_SUFFICIENT_FRAMING`. Transactional-email wording
  ("SMTP is not configured", password-reset failures) verified PRESERVED.
- **`SeoAssistantService:2666`** was instructing the model to say *"The [Social/Marketing/
  CRM/Builder] section handles that"* - to WordPress SEO customers. Fixed.
- **PUBLIC WEBROOT:** 38 `.bak` files were fetchable (`/app/index.html.bak-20260622-bulkenrich`
  -> 200, 351 KB). Quarantined + NEW `nginx snippets/levelup-deny-artifacts.conf` on 4 server
  blocks. Two-arg `return 404 "..."` is deliberate: `error_page 404 /index.php` was answering
  artifact paths with the app shell.
- **PLAN TRUTH AT SOURCE:** `LaunchScopePolicy::filterPlanFeatures()` applied to
  `/api/billing/plans` + `PlanGatingService`. DB keys retained for one-UPDATE rollback.
- **CALENDAR:** `PublisherService::syncCalendar()` writes `Publish - Facebook` /
  `Status: published` rows. There was NO filter - the first pass's test 25 passed only
  because the table is empty. Now null-safe excluded; user rows survive.
- **PRODUCT DECISIONS (Boss):** Builder signup section KEPT (+ `email_signup`/`lead_capture`
  aliases). Key Competitors field KEPT - verified read by ZERO JS, so it unlocks nothing.

### CONDITION (the only one) - CLOUDFLARE EDGE CACHE
`/app/js/{social,marketing,automation,mentions}.js` are gone from origin but still
`cf-cache-status: HIT` (`max-age=14400`). `social.js` still carries "Connect your Facebook
Page so Marcus can publish and schedule posts." `CLOUDFLARE_API_TOKEN` is EMPTY in `.env`
so no programmatic purge is possible. **Boss action:** Cloudflare dashboard -> levelupgrowth.io
-> Caching -> Configuration -> Purge Custom URLs (the 4 URLs), then re-run the retest.

### QA ACCOUNTS - CREATED AND TORN DOWN SAME SESSION
3 users + 2 workspaces (990044/990045/990046, ws 990020/990021) for authenticated QA.
Removed via `scripts/w6-drop-qa-accounts.php --commit`: users 14->11, ws 22->20,
ws2 members 2->1. `tasks` 2037 and `articles` 346 UNCHANGED. Credentials never left
`/root/.w6qa-credentials` (0600), now deleted.

### OPERATIONAL NOTES
- `/api/auth/login` is `throttle:10,5` - repeated harness logins return 429. Reuse tokens.
- Never `php artisan config:cache` (RuntimeClient reads `env()`). `config:clear` only.
- This host serves live customer domains (chefredraymundo.com, amgtravelandtours.com,
  levelupgrowth.io) - it is PRODUCTION regardless of the `levelup-staging` path.

### NEXT -> W7 public marketing + pricing truth. All 10 removed specialists still appear in
### `resources/views/marketing/{specialists,features,home}.blade.php` AND the static
### `public/marketing/pages/*.html` twins. Blocked until the Cloudflare purge is confirmed.

---

# BOSS888 STATE - 2026-07-22 (W6)

### W6 COMPLETE WITH CONDITIONS (2026-07-22) - frontend launch-scope sanitation. LIVE.
Report: `BOSS888-W6-REPORT-2026-07-22.md`. **166 checks green (132 automated + 34 real headless
browser). No provider contacted.**
- **NEW `public/app/js/launch-scope.js`** - one authoritative allowlist enforced at sidebar, router,
  quick actions, chains, proposal rendering and restored browser state. Loads BEFORE core.js so
  nav() is wrapped pre-paint. **Activating Publisher later = removing 'publisher' from two arrays.**
- **Nav removed:** ni-social, ni-mentions, ni-marketing, ni-automation. Guard also strips anything
  wired to a removed view, matching labels, and empty sections.
- **SARAH ROOT CAUSE:** the launch-scope rule said "never propose" but never said HOW TO DECLINE, so
  the model invented "social media isn't connected — Marcus can't publish there". Fixed with an
  explicit refusal-language prompt block + NEW `LaunchScopeLanguageGuard` (deterministic backstop) +
  unhooked SocialContextProvider. Live retest: 16 replies, **0 removed specialists named, 0 "not
  connected/configured", 0 upgrade framing, 0 "coming soon"**.
- **Two subtle bugs the tests caught:** (1) matching used ASCII apostrophes but models emit U+2019,
  so the guard missed the exact sentence it existed to catch; (2) cache migration was gated entirely
  behind a version stamp, so a key rewritten afterwards by a stale tab was never cleaned - now
  two-tier (one-time purge + idempotent scrub every boot).
- **EXEC_INTENTS root cause:** intents were data with no policy between them and the renderer.
  Now filtered at intent array, chain array and _renderProposal. Browser-verified 19 retained / 0 removed.
- **CONDITIONS:** authenticated browser QA not done (needs a test login) - dashboard cards, Strategy
  Room, assignee dropdowns, billing/plan copy, onboarding not visually verified; mobile payload and
  WordPress plugin not verified; no command palette/global search exists in this SPA (N/A).
- **NEXT: W7** marketing/pricing truth. Check `marketing/specialists.blade.php` first - it likely
  still lists removed agents. Do NOT advertise Publisher/Facebook/Instagram until provider proof.

---

# BOSS888 STATE - 2026-07-22 (PROVIDER HARDENING)

### PUBLISHER PROVIDER: BLOCKED - META PERMISSIONS OR TEST ASSETS (2026-07-22)
Doc: `BOSS888-META-PROVIDER-BLOCKED-2026-07-22.md`. **109 tests green. NOTHING TRANSMITTED.
NO ROUTE ADDED OR REOPENED - all W4 routes incl. api/publisher/* still 404.**
- **THE BLOCKER:** publishing scopes were never even requested. `getAuthUrl()` asks only for
  `public_profile, pages_show_list`; the code comment says the publishing scopes need App Review.
  Missing: pages_manage_posts, pages_read_engagement, instagram_basic, instagram_content_publish.
  App is `app_type:0` (consumer, not Business), 1 admin, 0 test users, NO privacy/ToS URL (both
  required to even submit App Review). Zero connected accounts. No designated test assets.
  Meta's registered redirect currently 404s under W4 - which blocks the App Review screencast too.
- **SECURITY FINDING (fixed):** old OAuth put the workspace id in an UNSIGNED query param
  (`$state = "{ws}_{nonce}"`). Replaced by `SignedOAuthState` (HMAC, single-use, provider-bound).
- **SHIPPED (Meta-independent):** Transport seam (MockTransport has no HTTP at all; live sending OFF
  by default via config('publisher.live_transport', false) with no config file), MetaErrorMap,
  Facebook + Instagram connectors (IG uses the real container workflow), ConnectionHealth with six
  real states + encrypted credentials, approval token bound to caption_hash/media_hash/schedule_key,
  `publishing_unknown` + reconcile-before-retry.
- **3 REAL BUGS CAUGHT BY TESTS:** (1) failure_class varchar(32) overflowed INSIDE the failure
  handler; widened to 96. (2) honesty regression - mock "success" was marking rows `published`;
  now dry runs yield `dry_run_ok` / post status `validated`, external_post_id stays NULL.
  (3) `dry_run_ok` was terminal unconditionally, so enabling live transport would have skipped every
  validated post forever; now terminal only while the transport is dry.
- **NEXT (Boss):** Business-type app, privacy+ToS URLs, Business Verification, designated test Page +
  IG professional account, approve the route carve-out, submit App Review for the 4 scopes.
- **NEXT (me, once unblocked):** route carve-out -> real FB/IG proof -> then Sarah collaboration.
  Sarah remains UNWIRED from publishing by design until provider transmission is proven.

---

# BOSS888 STATE - 2026-07-22 (CONTENT PUBLISHER SCOPE CHANGE)

### SCOPE CHANGED: manual Content Publishing is RETAINED (2026-07-22)
Full doc: `BOSS888-PUBLISHER-SCOPE-CHANGE-2026-07-22.md`. **Publisher core LIVE on staging, 77/77 tests.**
Social Management / Social Intelligence stay REMOVED. Autonomous + recurring publishing stay REMOVED.
- **PLATFORMS DECIDED: Facebook + Instagram only.** LinkedIn/X have no credentials, Threads + Google
  Business have NO CODE AT ALL. They are modelled but never offered — advertising a channel we cannot
  publish to is a lie. See the table in the doc.
- **CORRECTION TO THE W5 REPORT:** SocialConnector's private createPost/publishPost POST to
  `{SOCIAL_CONNECTOR_URL}/api/social/posts`, an external microservice that was NEVER BUILT (env empty).
  There has never been a Graph API call here. Manual publishing is greenfield, not a restore.
- **NEW:** `app/Core/Distribution/PublisherService.php` + `publisher_posts` table. `social_posts` is
  REUSED as the per-platform variation/publication row (gains publisher_post_id). Not duplicated.
- **APPROVAL BOUNDARY IS STRUCTURAL:** `ArticleShareToken` v2 gained an `intent`; a manual-publish
  token cannot be minted without approval_method in EXPLICIT_APPROVALS (publish_now | schedule |
  ui_publish_button | ui_schedule_button | approval_click) AND an approver id. execute() needs that
  token. So upload/caption/edit CANNOT publish — autonomous publishing is unrepresentable, not just
  forbidden. Editing a variation clears approval and forces re-approval.
- **Still terminates at DryRunSocialConnector** — no provider transmission exists yet. Nothing is ever
  marked 'published' without a real send.
- **NEXT:** (1) real FB/IG Graph transmission, (2) reopen ONLY api/social/oauth/facebook/callback +
  new api/publisher/*, (3) Sarah collaboration (never approves for the user), (4) W6 nav rename
  Social -> Content Publisher, (5) W7 copy truth (FB+IG only, approval always required).

---

# BOSS888 STATE - 2026-07-22 (W5)

### W5 COMPLETE WITH CONDITIONS (2026-07-22) - retained article distribution. LIVE ON STAGING.
Full report: `BOSS888-W5-REPORT-2026-07-22.md`. **47/47 tests pass. No provider was ever contacted.**
- **NEW OWNER:** `app/Core/Distribution/ArticleDistributionService.php` - the retained service the
  `mode='article_share'` marker always implied but never had. Not an agent, not Marcus/Zoe.
  Plus CanonicalUrlResolver, ShareCaptionService, PlatformPolicy, DryRunSocialConnector,
  ArticleShareToken. PostPublishCoordinator rewritten to delegate to it.
- **ROOT CAUSE of the empty draft:** the coordinator queued a task whose payload had NO content and
  NO url; `SocialService::createPost()` line 79 wrote `content = $data['content'] ?? ''`. Nothing
  ever resolved a canonical URL. `article_share` was only a permission marker - no code branched on
  it. W3 stripped marcus/zoe correctly but no retained service took ownership.
- **SECURITY FIX:** `isArticleShareContext()` used to return TRUE for anyone supplying
  mode='article_share' / auto_share / source='blog_share' - unlocking create/publish/update/schedule
  with ARBITRARY content and no article. Now requires an **ArticleShareToken** (HMAC over ws +
  article + platform + account + canonical URL + idempotency key, keyed by APP_KEY); every signed
  claim must equal the live call, so tokens cannot be replayed onto another article/workspace.
- **PROVIDER PUBLISHING DOES NOT EXIST AND WAS NOT BUILT.** SocialConnector::publish() is a stub
  that never carries the caption; SOCIAL_CONNECTOR_URL empty; social_accounts=0; W4 404s the OAuth
  callbacks. The pipeline TERMINATES at DryRunSocialConnector, which has no HTTP client at all.
  Real transmission = separate, explicitly-authorised work (needs Graph impl + one reopened OAuth
  callback + a designated test Page).
- **DB:** social_posts += 10 additive nullable/defaulted columns + unique idempotency_key. Reversible.
- **ENVIRONMENT TRUTH:** there is NO separate production Laravel. /var/www/levelup-staging serves
  levelupgrowth.io AND live customer domains (chefredraymundo.com, amgtravelandtours.com).
  Treat it as production.
- **W3 probe item 9 (Sarah) DONE:** 8/8 refused, 0 removed-work tasks, 0 social_posts, 0 jobs.
  CONDITIONS: 3 replies named removed specialists (Marcus/Chris/Leo) and framed removal as
  "not connected" rather than "not in the product" -> fix in W6/W7.
- **W3 probe item 11 (Railway logs) STILL BOSS:** no railway CLI/token on the droplet.
- Rollback + residual risks: section 30/31 of the report. Backup /root/backups/w5-presrc-20260722-065615.tar.gz

### NEXT -> W6 (frontend: EXEC_INTENTS core.js:4171, nav, onboarding) -> W7 -> W8 -> 54-pt regression.

---

# BOSS888 STATE - 2026-07-22

### W3 RUNTIME v2.37.3 DEPLOYED + PROBED (2026-07-22) - BLOCKER CLEARED
Boss deployed to Railway. Verified against the LIVE service, not the off-Railway copy.
- `/health` = **version 2.37.3**, **10 agents** (dmm,james,alex,diana,ryan,sofia,priya,nora,elena,max),
  **43 tools**, 0 removed agents, 0 removed tools, 0 removed generation modes.
- `runtime-probe-2.37.3.sh`: **41 passed / 1 failed**. Handshake OK (secret accepted on
  `/internal/health`, unauthenticated = 401). All 7 out-of-scope generation modes REFUSED
  (social_post, email_generation, email_template_generate_v2, social_hashtag_pack,
  email_subject_suggest, email_block_rewrite, social_platform_adapt). Retained paths + Studio OK.
- **The 1 failure is PRE-EXISTING, not a launch-scope regression:** probe item 10 expects
  `export_website` in `/health`. It is granted to Alex in `capability-map.js:108` but has NEVER
  been defined in `tool-registry.js` - confirmed absent in the June 2026 `.bak` copies, months
  before this program. Same mismatch applies to `hydrate_page` and `export_page`.
  Effect: those three are unreachable, NOT leaked. No scope risk. Probe item 10 tests the
  registry while `launch-scope-validate.js:195` tests the capability map - two different layers,
  which is why off-Railway validation passed 87/87.
  **FOLLOW-UP (not blocking launch):** either register the 3 builder/export tools or drop them
  from Alex's capability map so the two layers agree.
- **STILL MANUAL (Boss):** probe item 9 (8 Sarah excluded-request chats in the app, expect refuse
  + redirect, 0 tasks/assignments for removed agents) and item 11 (`railway logs` clean +
  2 replicas Active).
- Rollback unchanged: one-click Railway redeploy of 2.37.2. Laravel kernel deny, cron guards, DB
  revocation and the boundary sanitizer are independent and stay live either way.

### W3 + W4 now both LIVE. NEXT -> W5 (article-share caption) -> W6 (frontend) -> W7 (plan/marketing
### copy truth) -> W8 (dormant data) -> 54-pt regression -> browser QA -> smoke. None need Railway.

---

# BOSS888 STATE - 2026-07-21

### RUNTIME v2.37.3 PACKAGED (2026-07-21) - RAILWAY DEPLOYMENT PENDING
**W3's runtime blocker is HALF-CLEARED.** Boss supplied the genuine v2.37.2 source (verified via
package.json). Patched -> validated -> packaged as **v2.37.3**. NOT deployed.
- **Package:** `levelup-runtime2-main-v2.37.3-launch-scope.zip` - SHA-256
  `CD33FFBF7E6D7A2F4B97035C221435284E9244EDD6606AFD745B5108E5BDE647` - 87 files, 313.2 KB.
- **Scope correction:** handoff named 6 files; real footprint was 14 (agents) / 20 (incl. tools).
  `registry.js` + `lu-agent-search.js` were already clean.
- **Changed:** 22 files + NEW `launch-scope.js` (runtime mirror of LaunchScopePolicy) +
  `launch-scope-validate.js`.
- **Validated OFF-RAILWAY** (Node 20.20.2, isolated /root/rt-w3rt, Redis DB9, workers off):
  node --check 77/77 - launch-scope 87/87 - intelligence 35/35 - boots clean - /health = 10 agents,
  43 tools, 0 removed - /ai/run refuses 6/6 out-of-scope, retained write_article passes.
- **Fixed pre-existing bug:** sparse-array holes made `hasCapability(agent, undefined)` return TRUE.
- **Caught 3 bugs before packaging:** list_campaigns leaked into Sarah's prompt via a param
  description; email_subject_suggest bypassed the guard; update_post sat in an executable dispatch
  allow-list bypassing registry+capmap.
- **`dmm` PRESERVED** as Sarah's key (~25 call sites). Removed work is unavailable, NOT reassigned.
- **PENDING (Boss):** deploy to Railway, then run `runtime-probe-2.37.3.sh`.
- **W3 IS NOT COMPLETE** until deployed + probes pass. Deployed roster/planner/Sarah verification
  all PENDING. Rollback = Railway redeploy of the last 2.37.2 build (one click).

### WORKSTREAM 4 COMPLETE (2026-07-21) - routes & public endpoints. LIVE ON STAGING.
NEW `app/Http/Middleware/LaunchScopeRoutes.php`, registered `bootstrap/app.php:429`.
**83 routes now return 404 `not_in_product`** (404 not 403 - 403 leaks that the surface exists):
all `api/social/*` incl. the PUBLIC OAuth callbacks (which could still store platform tokens),
`api/marketing/{campaigns,sequences,email,email-builder,automations}`, `api/mentions/*`,
`api/watchlists/*`, `api/studio/designs/*/publish-social`.
- Chose ONE middleware over ~60 closure edits in a 1.1 MB routes/api.php - reads the same
  LaunchScopePolicy as the kernel, so the route boundary cannot drift from the execution boundary.
- **Closed the real leak:** GET/read paths (listCampaigns/listSequences/listTemplates/
  getCampaignAnalytics/mentions stats) called services DIRECTLY, bypassing the W1 kernel deny, and
  were still returning real data. Write paths were already execution-dead.
- **Protect-list verified:** Stripe webhook 200, auth/password/email-verify/WP-plugin/internal OK.
- **Retained verified 200:** seo/keywords, seo/articles, write/articles, crm/leads, builder/pages,
  agents, tasks, studio/templates, calendar/events.
- **No email open/click tracking pixel routes exist** - nothing to remove there.
- Backups: `routes/api.php.bak-w4-20260721-134331`, `bootstrap/app.php.bak-w4-20260721-134331`,
  `/root/backups/w4-presrc-20260721-134331.tar.gz`.

### !! DO NOT RUN `php artisan config:cache` ON THIS APP !!
I ran it during W4 cache clearing and it BROKE the runtime connection: live
`app/Connectors/RuntimeClient.php` reads `env('RUNTIME_URL')`/`env('RUNTIME_SECRET')` DIRECTLY, and
`env()` returns null once config is cached. Result: 12 `RuntimeClient not configured` errors
14:00-14:06 and failed generate_meta tasks 2143/2144/2145.
**FIXED** via `config:clear` + fpm reload + worker restart (isConfigured: YES, 0 errors since 14:20).
Use `config:clear` only. FOLLOW-UP: finish the migration to a `config/services.php` entry - see
`RuntimeClient.php.bak-envconfig-20260707-194219`, started 07-07, never landed.
**Also:** this app logs to a SINGLE `storage/logs/laravel.log`, not `laravel-YYYY-MM-DD.log`.

### NEXT -> W5 (article-share caption) -> W6 (frontend) -> W7 (marketing/plan truth) -> W8 (dormant
data) -> 54-pt regression -> browser QA -> smoke. W5-W8 need NO Railway access.
The W4 guard's `[launch-scope]` INFO lines in laravel.log show exactly which surfaces the UI still
calls - use them to drive W6.

---

# BOSS888 STATE — 2026-07-20

### ✅ WORKSTREAM 3 COMPLETE (2026-07-20) — agent/runtime sanitation. Report: `BOSS888-W3-CHECKPOINT-2026-07-20.md`
**Removed 10 agents (marcus,jordan,tyler,zara,zoe,maya,vera,kai,chris,leo) + constrained 6 (sarah,priya,elena,max,nora,sofia) from the AUTHORITATIVE agent layer.**
- **DB grants:** all removed-agent grants + constrained social/email/sequence grants soft-disabled (`is_active=0`, reversible). Rollback `/root/backups/w3-capability-rollback.sql` + snapshots. `canUse` short-circuits removed regardless.
- **Source (22 files this session, `.bak-w3-*`):** routing landmines fixed (engine→agent maps, meeting candidates `AgentMeetingEngine`, `ProactiveRuleSet` rules 8/9/10 deleted, `PostPublishCoordinator` article-share OFF marcus + newsletter removed, `InstructionParser` fail-closed, badge/attribution maps); Sarah prompt surfaces sanitised (`ToolSchemaService`,`SarahDaily/Monthly`,`DiscoveryRun`,`PromptTemplates`); `AgentCapabilityService` grant()+accessors filtered; `WebActivityService.ALLOWED_AGENTS`; seeders launch-scope-aware (`AgentSeeder` dormant, seed migration filtered); **`TaskService::create` launch-scope guard** (memory-compat + routing safety net); **`RuntimeClient` boundary sanitizer**.
- **Roster APIs verified live:** `/api/internal/agents` = exactly 10 retained (alex present, 0 removed); customer rosters 0 removed.
- **Runtime (§5) BOUNDARY-ENFORCED, in-runtime rebuild BLOCKED:** deployed runtime v2.37.2 `/health` still lists 20 agents; Laravel `RuntimeClient` scrubs them → exposes only 10 retained. **Cannot rebuild/redeploy: no current runtime source (only stale 2.36.0 zip) + no Railway CLI/RAILWAY_TOKEN.** BOSS ACTION: provide 2.37.2 source + Railway access to sanitise capability-map.js/agents.js/registry.js/lu-planner.js/lu-agent-search.js/tool-registry.js.
- **Validation:** 64/64 automated assertions + 4 live Sarah negative chats (all refuse+redirect, 0 removed tasks/assignments created) + logs clean, workers 3/3.
- **INSTALL STATUS:** source ✅ modified · DB ✅ modified · runtime ⚠️ NOT built/deployed (blocked) · Laravel changes ✅ verified on staging · production untouched.
- **NEXT → W4** routes/public OAuth+email-tracking. (Sequence: W4→W5 blog-share caption→W6 frontend→W7 marketing/plan truth→W8 dormant data→regression→browser QA→smoke.) Do NOT re-checkpoint per accepted plan.

---


### 🚀 LAUNCH-SCOPE REMEDIATION IN PROGRESS (2026-07-20) — server-side enforcement DONE
Audit: `boss888-audit/LAUNCH-SCOPE-REMOVAL-AUDIT-2026-07-20.md` (SAFE WITH CONDITIONS, accepted).
Progress log: `boss888-audit/LAUNCH-REMEDIATION-PROGRESS-2026-07-20.md`. Backup `/root/backups/prelaunch-src-20260719-221710.tar.gz`.
**Removing: social automation/intelligence + email marketing + 10 agents. Retaining: SEO/Blog/Builder/CRM/Studio/Chatbot/Sarah + blog-share.**
**✅ W0:** baseline captured, ZERO drift vs audit, **Marcus identity RESOLVED** — single pure-social agent (id=12); Technical-SEO specialist is **Alex (id=3)**, NOT Marcus; Marcus=REMOVE correct.
**✅ W1 core:** NEW `app/Core/LaunchScope/LaunchScopePolicy.php` = single source of truth; wired as kernel Step-1a in `EngineExecutionService` (before credit/provider/job). Verified: 14 removed actions DENIED, retained blog-share + 6 core engines ALLOWED.
**✅ W2 core:** disabled crons `lu:sequences:run` + `mention:scan-daily` (gone from schedule:list); internal guards on RunSequences/queueSendCampaign/sendTestEmail/scanWatchlist — ALL verified REFUSING. Workers restarted.
**⏭ REMAINING:** W1-tail (read routes, plan copy) · W3 agent_capabilities revoke + Sarah 6 prompt surfaces + routing landmines + drop removed agents from `/api/internal/agents` (**runtime redeploy**) · W4 routes/public OAuth+email-tracking · W5 blog-share caption rebuild (reassign off marcus) · W6 frontend (EXEC_INTENTS core.js:4171, nav, onboarding) · W7 marketing/plan copy · W8 data archive-disable · 54-pt regression + browser QA + smoke.
**Locked decisions:** chris+leo=REMOVE agent (Studio video retained); email templates/blocks=ARCHIVE+hard-disable; blog-share caption=BUILD before launch; ContentPack=trace-then-dormant; prices unchanged but copy must be truthful.
⚠️ Execution hard-stop is complete+proven; remaining leaks are visibility/reasoning (agents in prompts, nav in Advanced mode), NOT execution.
### ✅ SESSION 2026-07-19 (D) — ALL-AGENT INTELLIGENCE + ANTI-FABRICATION
Two agents caught lying: James "I've queued the fix for myself — in progress", Priya "I've already flagged it
to Sarah" — both false; specialists CANNOT queue (only sarah is_dmm=1 delegates). Root cause: every rich
context block was inside `if($isSarah)`; the other 19 ran a ~10-line prompt. Four prompt rules failed to stop
it — same lesson as numeric fabrication: prompt=prevention not enforcement.
**Fix 1 — SHARED AGENT CORE** (`routes/api.php` else-branch, front-loaded): owner identity+NAMES ·
never-claim-unqueued-work · "you CANNOT queue, only Sarah can" · plain-language (no ids/raw-minutes/slugs) ·
escalate-don't-interrogate · disconnected-engines. All 19 specialists now Sarah-grade.
**Fix 2 — `AgentClaimValidator`** (`app/Core/Integrity/`, ENFORCEMENT): post-gen/pre-store at final-reply
insert (~api.php:3070). Sentence-level strip of completed/in-progress claims + role-correct honest line.
Specialists ALWAYS checked; Sarah only when this turn queued nothing (fresh `tasks.created_at>=now-25s` — the
in-scope counter isn't available there). `is_dmm` from DB, data-driven. **6/6 unit, 5/5 false-positive guard
(offers like "Shall I flag it to Sarah?" pass), live James strip confirmed in log.**
⚠️ RESIDUAL: validator kills the LIE reliably; James still sometimes INSTRUCTS the owner ("Please confirm once
you've done that") instead of escalating — prompt-level, improved not guaranteed. Not policed by validator
(would risk stripping legit approval/credential requests). Follow-up if it recurs.
Backups `.bak-agentcore/escalate/claimvalidator-*`. Server-side — live on installed APK, no rebuild.
Also this session: Sarah identity fix (called owner "Sarah"), disconnected-engines in her memory + dead
email/social approvals cleared, delegate-don't-instruct rule. All live+verified.
### ✅ 2026-07-18/19 SHIPPED (digest — full detail in daily-progress/2026-07-1{8,9}.md)
All LIVE + verified on staging. Backups per-file `.bak-*`; DB dumps in `/root/backups/`.
- **DataForSEO (DFS-F2):** account was broke since ~07-16 (402 hidden as "20000 Ok"); credit restored ~$49.70.
  Added response cache (serp 6h/keyword 7d PER-KEYWORD; ⛔ trackKeywordRank NEVER cached; ⛔ TTL_SERP<24h;
  Cache::=DB1 vs queue/sarah_state=DB0), `dfs_usage_log` http_status/ok/cache_hit, and SeoAssistant billing
  parity (was narrating "1 credit" while debiting 0; now reserve/commit at canonical serp1/ai_report2/audit3).
  ⚠️ generate_article/batch_articles NOT charged here — may already be metered in runtime; verify before extending.
- **SEO-DRAIN:** SarahAutoExecute `$spentToday` counted ALL workspace commits → drainer no-op; now scoped to
  own drain, **anchor=`approved_at` NOT updated_at (don't revert)**. ProactiveStrategyEngine 7-day blind wipe →
  signal-aware (gap>0 keeps pending; +`superseded_reason`).
- **Bugs:** improve_draft self-resolves target (was 9/10 failing; +IDOR fix, workspace-scoped); proposals can't
  report success when all tasks failed; mention-scan stamps only on success (fixed the 28-day lockout).
- **Runtime v2.37.2** (Boss-deployed): agent-search 3/5→2/5→**5/5** (per-route 75s budget + retry on transient
  40101 + depth 20→10 =43% cheaper). 28-day brand-monitoring outage CLOSED.
- **Web UI:** `LU_statusLabel()` — raw proposal/subscription status enums no longer leak as chip text.
  Cache-buster aligned 5.7.54.
- **Mobile v1.4.14 (APK BUILT `LevelUpGrowth_App/levelupgrowth-app888-v1.4.14.apk`, commit 7f25238):** in-chat
  Approve/reject were dead onPress → wired; Android reject (Alert.prompt iOS-only) → cross-platform Modal;
  attachments hit `undefined/media/upload` → apiBase; **logout loop** (refresh sent expired token in header →
  401 → logout; now auth:false + only genuine token-reject logs out) + **post-logout notifications** (device
  DELETE never checked res.ok → row survived; now parks+retries via flushPendingUnregister).
- **Four-surface audit** (`boss888-audit/FOUR-SURFACE-FORENSIC-AUDIT-2026-07-19.md`): WP v1.3.11 ships NO
  working SEO Assistant (admin-shell.js never enqueued; false v1.1.6 changelog). ws1 badge 57 vs Sarah ~3
  (different tables). No IDOR anywhere. **Agent voice LIVE:** 17/20 agents were mute; `agents:report-completions`
  hourly:20, completions-only-except-Sarah, batched as Sarah's delegation.
**⚠️ OPEN BOSS DECISIONS:** (1) backfill 6 falsely-`completed` proposals to failed, or keep history?
(2) Chef Red brand watchlist is in ws1 not ws2 — move? (3) ws2 email/social marked disconnected in memory +
dead approvals cleared — if another ws connects them, its own memory governs (not global).
## ⏸️ PAUSED 2026-07-17 (reboot) → READ `BOSS888-CHECKPOINT-2026-07-17.md` FIRST
═══════════════════════════════════════════════════════════════════════════
**Stopped mid-wiring the V2 Numerical Validator into the live chat flow.** Next action: deploy `boss888-ni/NumericalValidator.php` → `app/Core/Integrity/`, call it on `$reply` before `routes/api.php:~2920` (agent_messages insert) + the tool-follow-up path (~2232), then live-test + re-measure with the LOCKED scorer. LIVE on staging now: Phase-B NI mitigation (fabrication 43%→20%) + approval-followup gatherer. ws2 chat thread was CLEARED (backup: `boss888-ni/ws2_agent_messages_backup_20260716.sql`). Runtime approval-followup zip built, NOT deployed (Boss→Railway). Full detail: `BOSS888-CHECKPOINT-2026-07-17.md` (local `LVL\` + staging).

═══════════════════════════════════════════════════════════════════════════
## ➡️ HANDOFF (2026-07-16) — approval follow-up — archived summary
**Boss: "Sarah never follows up on approvals."** Fixed: `WorkspaceStateGatherer` had ZERO approval fields;
`readApprovalsState()` now surfaces `needs_owner_ok` (oldest-first) + count/age/credits, external asks only.
`SarahDailyOrchestrator` fallback prompt gained an "AWAITING YOUR OK" section. LIVE
(`.bak-approvals-20260716-idorfollowup`). **⚠️ Runtime half NOT deployed** — built to
`levelup-runtime2-main-2.36.0-approval-followup.zip` on `C:\Users\markr\LVL\`, **a path that does not exist
on this machine; rebuild before deploying.** Still open: 30+ stranded drafts need Boss's publish approval.
## ➡️ HANDOFF (2026-07-15 → 16) — archived summary
**Cross-tenant IDOR sweep across 9 engine surfaces — FIXED + VERIFIED** (CRM, Write, Builder incl.
custom-domain hijack, Social, Marketing incl. send, Calendar, Studio incl. duplicateDesign content-theft,
Creative route-guard, EES dispatch). Pattern: entities looked up by raw `id` with no `workspace_id` scope.
**Worth remembering:** several methods *received* `$wsId` but never used it to scope — `sendCampaign`,
`duplicateDesign`, `publishToSocial`, `saveExportToMedia`. Invisible to a signature grep. (Same class found
again 2026-07-18 in `WriteService::improveDraft` — see session 6.)
Detail: `BOSS888-SARAH-ENTERPRISE-AUDIT-2026-07-15.md` + `daily-progress/2026-07-15.md`.

## 🏗️ INFRA888 — INFRASTRUCTURE OPERATING SYSTEM (Phase 3B done 2026-07-20)
**No longer a feature: it is the infra OS inside LevelUp Growth. Canonical asset graph + operational
intelligence layer LIVE. First real capability (monitoring) feeds it. NO provider adapter (D1/D2 gated).**

### PHASE 3B — canonical intelligence foundation
- **Canonical asset graph**: infra_assets (10 types) + infra_asset_relationships (typed edges). ONE
  graph — hosted_sites/hosting_accounts/monitor_checks attach via asset_id; asset back-points via
  source_type/source_id. No parallel hierarchy.
- **First-class incidents**: infra_incidents (detected→ack→investigating→mitigated→resolved→closed,
  illegal transitions refused) + infra_incident_transitions (append-only, actor-attributed). MTTR on
  resolve. Monitoring PRODUCES into this one model (attached checks; bare checks = legacy bridge).
- **Historical intelligence**: MTTR/MTBF/uptime/latency/trends (deterministic SQL). infra_monitor_daily
  rollup + infra:rollup-monitor-daily (hourly) = retention foundation.
- **Executive API**: 6 read-only tenant-scoped endpoints /api/infrastructure/intelligence/* (dashboard/
  assets/asset/blast-radius/incidents/reliability). HTTP-verified against PTAA w/ real JWT.
- **PTAA in graph**: 3 assets (website+server ADOPTED, monitor managed_by_infra888), 2 edges, real
  health. management_mode enforces adopted != provisioned (audit integrity).

### INTELLIGENCE SPLIT (locked runtime rule honoured)
Deterministic aggregation + rule-based indicators = Laravel. Predictive/anomaly SCORING = runtime;
integration point built at risk_state surface (drops in without schema change). Rule-based = honest floor.

### FULL PLATFORM STATE
Governance (Founder Mode + credential roles + cert framework) + monitoring + canonical intelligence.
Tests: INFRA888 **314/0** (1 skip); full **357 pass / 23 fail (0 INFRA888) / 1 skip**.

### 🔴 BLOCKERS
- **D1** company CF account — OPEN. Gates provider ADAPTERS (populate DNS/SSL/deployment/cost/backup dims).
- **D2** diagnostics token — OPEN. Gates adapter validation.
- **D3** — resolved-in-principle by Founder Mode (founder MFA enrol pending = founder action).
- Model HOLDS gated dimensions as unknown/managed_externally — NOT faked. Only monitoring is real today.

### PARTIAL (honestly scoped next, not faked)
- Executive SPA dashboard VIEW: API done+HTTP-verified; the rendered /app view is the next milestone.
- Runtime predictive-scoring module: integration point done; deterministic floor working.
- Retention prune + queued monitoring fan-out: designed (scale levers), implement on scale ramp.

### NEXT MILESTONE (buildable now on real data, no adapter)
Enterprise SPA dashboard view on the intelligence API → retention prune + queued monitoring → founder
MFA enrol → (D1/D2) CF read-only adapter begins populating DNS/SSL dims via connector contracts.

### REFERENCES
boss888-audit/INFRA888/: PHASE-2B-1..R3, PHASE-3A-FOUNDER-MODE-PTAA-MONITORING,
PHASE-3B-ENTERPRISE-INTELLIGENCE-PLATFORM-2026-07-20.md. Daily: daily-progress/2026-07-19.md.
Scripts: infra888-verify-{control-plane,governance,diagnostics-cert,r3,ptaa-monitoring,intelligence}.php,
-onboard-ptaa.php, -reconcile-ptaa-graph.php, -cf-preflight.sh, -certify-cloudflare.php.

### ENVIRONMENT QUICK-REF
- Staging: `root@134.209.93.41`, key `~/.ssh/do_levelup`, Laravel at `/var/www/levelup-staging`, DB `levelup_staging`.
- Runtime: Railway v2.36.0 (Claude Code BUILDS zip to `C:\Users\User\Runtime`; Boss deploys).
- Test accounts: Laravel/APP888 → **chef-red (user2/ws2)**; WP connector → shukran (user6/ws7). AMG = ws26.
- tinker NOT installed → bootstrap Laravel in a standalone PHP script (`require vendor/autoload` + `bootstrap/app` + Kernel::bootstrap).
- After task-code edits: `queue:restart` + `supervisorctl restart levelup-worker:`.
- HARD RULES: CREATIVE888 logic LOCKED (route/connector fixes OK); backup before every edit; migrate:fresh PROHIBITED on prod; staging-only until Phase 9; server is source of truth (git drift — pull before local edits).
- Master docs on staging: `BOSS888-SARAH-ENTERPRISE-AUDIT-2026-07-15.md`, `BOSS888-PHASE2-CERTIFICATION-FINAL-2026-07-15.md`. Working plan: `boss888-audit/PLAN.md`; per-day notes: `boss888-audit/daily-progress/2026-07-15.md`.
