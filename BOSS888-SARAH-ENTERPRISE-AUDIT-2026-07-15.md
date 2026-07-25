# BOSS888 / Sarah — Enterprise & Launch-Readiness Master Audit

**Date:** 2026-07-15
**Auditor:** Claude Code (multi-agent forensic + live behavioral testing)
**Environment:** staging (`134.209.93.41` → `https://staging.levelupgrowth.io`), Laravel `/var/www/levelup-staging`, runtime `levelup-runtime2-production.up.railway.app` (v2.36.0)
**Workspace under test:** ws2 = chef-red (`chefredraymundo.com`)
**Method:** Live behavioral drive of Sarah (real chat API, verified vs ground-truth DB) + 7 background code-forensic investigators across the orchestration, brain, and execution layers, each pulling live DB evidence.

---

## EXECUTIVE VERDICT

**Is Sarah an enterprise, launch-ready Digital Marketing Manager? — NO at enterprise tier, and not launchable *as advertised* today.**

Two headline reasons, in order of severity:
1. **Truthfulness gaps** — Sarah reports work as done that never delivered (most seriously: she says social posts were "pushed" when the social engine never publishes to any platform).
2. **Budget-tier by design** — the entire stack (brain, images, content) runs on economy models/settings, with several "claimed > delivered" gaps.

**But underneath that, there is a genuinely capable SMB-grade marketing autopilot** in a subset of channels: SEO (esp. AEO + internal linking), content generation, email campaigns, and lead nurture. In those channels, with a human reviewing output, it is a real, shippable product — meaningfully more than a demo.

### Full-stack scorecard

| Layer / Engine | Rating | One-line truth |
|---|---|---|
| Sarah (orchestration) | 🟡 Strong-for-SMB | Real delegation, proactivity, retrospective reasoning, honest reporting — but dead learning loop, single-shot planning, no market intel. |
| Runtime (brain) | 🟡 Capped | Real multi-agent meeting-room + structured synthesis — on `deepseek-chat` (budget), non-agentic single-turn. |
| WRITE888 (content) | 🟡 Budget | Unique, word-count-solid content — but single-shot, no SERP grounding, no fact-check, no E-E-A-T, fake quality scores. |
| CREATIVE888 (images) | 🔴 Low-by-design | `quality='low'` hardcoded & non-overridable; all 1024×1024 bottom-tier; QA layer dead. |
| Studio (video) | 🔴 Barely runs | Real ffmpeg + real tiers, but AI video unfunded, 0-for-27 renders (all timeout), 2 videos ever. |
| Email / Marketing | 🟢 Real | Genuine Postmark sends + tracking + drip. Enterprise-ish; never fired on staging; broken legacy "sent" path. |
| Social | 🔴 Facade | Real OAuth connect, but publishing is mock/phantom — never reaches any platform. Sarah reports posts as done. |
| CRM | 🟢 Real | Actual pipeline, scoring, state machine, live data, nurture via email. |
| SEO / AEO | 🟡 Mixed | AEO differentiator + orphan/internal-linking genuinely work (572 live links); audit shallow, DataForSEO dead, rank tracking = 2 flat keywords. |

---

# PART 1 — SARAH AS A DMM (orchestration layer)

## 1.1 Live behavioral battery (direct drive, verified vs ground truth)

Sent real messages to Sarah as chef-red; compared answers to DB ground truth (articles=155 published/28 draft, keywords=36, leads=4 ids 3/6/7/16, real failed task #1726 create_automation).

| # | Use case | Result | Evidence |
|---|---|---|---|
| 1 | "How many articles?" | ✅ PASS | "155 published, 28 drafts" — exact. |
| 2 | "How am I ranking?" | ✅ PASS (exemplary) | "GSC *average* positions, NOT live ranks… avg 3 but only 5 impressions — not a real rank." |
| 3 | "How many keywords?" | ❌ FAIL → FIXED | Said "0" (truth 36). Router had no keyword-count branch. **Fixed + verified "36".** |
| 4 | "Fix orphans" | ✅ PASS | Delegated to James; task #1840 completed (orphans 3→2). |
| 5 | "Write an article" | ❌ INTERMITTENT | "Hit a snag" once; **succeeded on retry** with a full correct chain (real article written). |
| 6 | "Update lead 999" (fake id) | ✅ PASS (strong) | No fabrication — listed real leads, asked which. |
| 7 | "What failed recently?" | ⚠ PARTIAL → FIXED | Confabulated a completed fix_orphans as the failure. **Fixed** → now names real #1726 create_automation. |

**DMM-level strategic tests (the acid test):**
- **Open strategic mandate** ("grow my private chef business this quarter"): produced a **data-grounded, impact-prioritized plan** (fix orphans → BOFU "private chef cost NJ" article for purchase-intent → LinkedIn post to warm leads), delegated across **3 specialists** (James/Priya/Marcus), 7-task chained campaign. Dup-skip worked ("Skipped 1 duplicate — already in progress").
- **Retrospective probe**: genuine analysis — *"queries rank positions 1–4 but get zero clicks → optimize meta for those high-rank/low-CTR queries."*
- **Monitoring/status probe**: *"142 tasks completed (2 failed, 11 cancelled)… owns failures with reasons and a corrective learning."*
- **Consistency probe**: repeated priorities consistently AND folded the retrospective insight into the new plan.

**Live gaps surfaced:** reported "tracking 0 keywords" *inside a plan* (grounding bug persists in planning path beyond the router fix); set **no persistent goal** from a quarter mandate; failure attribution varies by phrasing.

## 1.2 Planning & Proactivity (forensic)

- **Planning is reasoned-LLM over a templated menu, single-shot, runtime-dependent.** `SarahDailyOrchestrator` states outright: *"This file does NO synthesis… the runtime owns the intelligence."* One LLM call synthesizes; candidates seeded by `ProactiveRuleSet` deterministic heuristics (orphans, opportunity keywords #11-30, thin pages, stale content, budget under-deployment). **No local reasoning fallback** → degrades to bland stubs on runtime blip (goal-clarity 0.0, ROI 0.5). No plan→critique→replan loop.
- **Runtime strategy endpoints** (`find-opportunities`, `assess-goal-clarity`, `estimate-roi`, `generate-recommendation`, `calculate-task-roi/risk`) are wired and consumed — but on the goal/campaign path, not the daily brief; scatter of one-shot calls, not an integrated planner.
- **Proactivity is the strongest pillar** — genuinely autonomous. Scheduled (`sarah:morning-brief` hourly TZ-gated, `weekly-review`, `monthly-strategy`, `house:weekly-proactive`, and the bounded-autonomy lane `sarah:auto-execute`). Auto-execute: safe-slug whitelist, tier caps 12/25/40, max 8 actions/run, publish/email/social human-gated, value-ordered. **387 proposals on ws2, 5–13/day, substantive** (e.g. "Expand 6 thin pages to 800+ words").
- **Goals: partial.** `workspace_goals` — ws2 has exactly **1** user-given goal ("Rank top 10 for 10 chef NJ keywords"), measured daily by `GoalLifecycleService` (status off_track, tracked). **No `set_goal` tool** — Sarah cannot originate goals or decompose into milestones.

## 1.3 External research / market intelligence (forensic)

- **~4 of ~45 tools reach live external data.** Works: `web.fetch` (any URL), `serp_analysis` (DataForSEO SERP), `platform.search_performance` (live GSC/GA, wired into content decisions).
- **No market radar:** **zero** Google Trends, news, social listening, seasonality anywhere in the codebase. Cannot answer "what's trending in our industry."
- **Broad web search broken** — `web.search` (DataForSEO backend) errors on every recent call (48 err vs 33 historical ok).
- **Competitor intel cached & ~1 month stale** — SERP positions only (since Jun 9/19), no view of competitor campaigns/messaging/pricing.
- **Correction:** DataForSEO is configured/wired (creds present), not simply "dead"; the *agent-search* path errors. Real external usage today is mostly **brand-vanity monitoring** (daily "Chef Red" mention scans), also currently erroring.

## 1.4 Monitoring / Reporting / Retrospectives / Learning (forensic)

- **Monitoring: real.** Multiple cron reapers (recover-orphans/15min via `TaskStateMachine::recoverOrphans`, `boss888:recover-stale`, `studio:reap-stuck-renders`, `credits:reap-orphans`). 155 tasks properly failed, not zombies. **But recovery is silent to Sarah.**
- **Report-back: partial, pull-not-push.** `SarahReadBackService` narrates completed tasks (78% carry `sarah_read_at`, honest about no-ops). But only reactively (piggybacked on next message/brief), leaving a **336-task completed-but-unread backlog**, and **failures are filtered out of her voice** (read-back is `status='completed'` only, :37). 155 failures never narrated.
- **Retrospectives: partial.** `sarah:weekly-review` runs (`SarahWeeklyOrchestrator`), produced **106 `weekly_pivot` proposals**. **But reviews aggregates, not artifacts** — never joins a shipped article to its actual GSC traffic/rank. No per-campaign post-mortem.
- **Learning loop: DEAD.** `StrategyLearningService` (outcomes journal) write path gated to `created_via IN ('sarah_proposal','auto_orphan_rescue')` — gate never fires → **`strategy_outcomes` = 0 rows**, `getLearnings()` always empty. `CampaignOptimizationEngine` (A/B) → **`experiments` = 0**, dormant. **She tells you "the task ran"; she cannot tell you "the marketing worked."**

---

# PART 2 — THE RUNTIME BRAIN (Railway, v2.36.0; source reviewed = v2.34.1)

- **Model = `deepseek-chat` (DeepSeek V3, budget ~$0.14/$0.28 per 1M tok)**, fallback `gpt-4o-mini`. Code explicitly disables the reasoning model (*"never use deepseek-reasoner"*). This is the single biggest ceiling on strategic reasoning quality.
- **Not agentic in chat:** *"single-turn tool calls — no multi-round agentic loop in assistant"* (`index.js:1394`), `MAX_TOOL_CALLS_PER_TURN=3`, 8000-char prompt cap, temp 0.7, ~1200-token outputs.
- **Genuine strengths (under-credited at first):**
  - **`meeting-room.js` is real multi-agent deliberation** — manager → specialists → per-agent `runDeliberation` (internal reasoning) → synthesis → task generation, with dedup, turn caps, timeouts. The monthly strategy meeting is a real deliberative process, not scripted.
  - **Synthesis routes well-built** — `synthesize-weekly` ingests `outcomes_7d` (rank movements, article performance, leads); `synthesize-monthly` builds a 30-day plan with competitor signals, AEO, pipeline health, credit-budgeted weekly allocations.
  - Tactical content prompts (social/email/SEO/studio) show real marketing domain knowledge (platform-native rules, honesty guards, brand grounding).
- **Verdict:** an architecturally credible brain (real deliberation + structured synthesis) **quality-capped by a budget model + non-agentic chat loop**. The intelligence isn't theater — it's a real engine on an economy motor.
- **Caveat:** reviewed the v2.34.1 zip from staging `/tmp`; live is v2.36.0 (deltas: brief-reframe, metrics-honesty, fill_missing_images — no architectural change). Exact-live diff requires the `C:\Users\User\Runtime` source (home PC).

---

# PART 3 — EXECUTION ENGINES

## 3.1 WRITE888 — content generation
- **Single-shot** `RuntimeClient::writeDraft` on deepseek-chat; NOT a research→outline→sections→verify pipeline (outline/meta/AEO/image are separate chained tools).
- **Strengths:** word-count enforced 1000-1200 + retry-if-short (corpus avg 1138, 86% ≥1000); brand-voice/blueprint injection; **uniqueness guard empirically works** (Jaccard scan of 316 articles → max 0.118, **zero near-duplicates**); AEO enricher real (TLDR/FAQ/JSON-LD) but only **54%** coverage; prose coherent & locally specific.
- **Gaps:** **No SERP/keyword grounding in write path** (DataForSeoConnector exists, `writeArticle` never calls it); **no fact-check, no plagiarism/AI-detection, no E-E-A-T** (generic Organization author); **fake quality signals** — readability = `words×1.5` syllable guess, SEO score = presence checklist (corpus avg **44**); flat structure (no H3), 2-3 internal links, ~30% missing featured images.
- **Verdict:** competent budget generator ("solo-blogger-in-a-box"), not research-grounded enterprise content.

## 3.2 CREATIVE888 — images
- Provider: OpenAI **gpt-image-1** (white-labeled "LevelUp AI").
- **`quality='low'` hardcoded & non-overridable:** `imageGenerate` builds its POST with `array_filter(['prompt','style','size'])` — the `quality` param callers pass is **never transmitted** (`RuntimeClient.php:470/490/606`). Every auto-gen image (featured/blog/studio hero) is gpt-image-1's cheapest bottom tier, **for every tenant on every plan**. Size/quality tiers in code are dead parameters.
- **DB evidence:** 307 images produced (7 failed), **306 are identical 1024×1024** low-tier PNG (~1.3MB). No portrait/landscape variety in practice.
- Vision-QA layer `GeometryAnalyzerService` = **dead code** (referenced nowhere) → no post-gen QA/regenerate. Brand grounding = 2 hex colors injected as prompt text.
- **Verdict:** cost-optimized commodity image feed. Not enterprise.

## 3.3 Studio — video / design
- **Real ffmpeg renderer** (`RenderStudioVideoJob`): clip concat + xfade, Ken Burns zoompan, drawtext animations, logo/lower-third/countdown, color grade, audio afade; shells to `/usr/bin/ffmpeg` v4.4.2, validates output.
- **Tiers are REAL & plan-gated** (`resolveVideoPolicy`): free→720+forced watermark, pro→1080, agency→4K, with resolution+CRF scaling. Watermark genuinely forced.
- **Caveats:** "4K" is a lanczos **upscale** of ≤1080 canvas; `-preset ultrafast`; max canvas 1920×1080.
- **Barely produces:** `studio_designs` = **20 pending, 7 failed, 0 done** — all 7 = *"Render timed out after 20m."* Only **2 videos ever** (July-2 test), with broken media metadata (0 bytes/null duration).
- **AI video unfunded/dead:** MiniMax keys **empty in .env**, Runway unimplemented → no text-to-video; only clip/still stitching.
- **Verdict:** promising but fragile & half-built. Not enterprise-ready.

## 3.4 Email / Marketing — 🟢 REAL
- `EmailBuilderService::sendCampaign` → `EmailConnector::sendViaPostmark` → **real Postmark HTTP API**, stores `postmark_message_id`, per-recipient `email_campaigns_log`, bounce handling. Env armed (`MAIL_MAILER=postmark`, `POSTMARK_TOKEN` set).
- Block-based builder, merge-variable rendering, open-pixel + click-rewrite tracking (`injectTracking` L1245), unsubscribe tokens, campaign analytics (opens-by-hour, top-links, AI insight L1344).
- **Real drip:** `lu:sequences:run` cron every 15min (`bootstrap/app.php:270`) sends due step emails, advances `current_step_order`.
- **DB:** campaigns=0, email_campaigns_log=0 (**capability real, never fired on staging**).
- **Landmines:** broken legacy `MarketingService::sendCampaign` (L125) calls non-existent `->send()`, sends nothing, yet marks campaign `status='sent'`. No real A/B (generates variants, no split-send/winner logic). Analytics not fed to CRM scoring.

## 3.5 Social — 🔴 FACADE
- **Real OAuth connect** (FB/IG Graph, LinkedIn, Twitter/X; long-lived tokens, page+IG discovery).
- **Publishing is mock/phantom** — `publishPost` → `connector->publish()` routes to MOCK (fabricated post_id + fake URL `https://{platform}.com/p/{id}`, `SocialConnector.php:748-770`) or a "phantom relay" `Http::baseUrl($SOCIAL_CONNECTOR_URL)->post(...)` (:794). **No Graph/LinkedIn/X publish call exists.** Comments admit *"Session 2 replaces with Graph API"* (:775, :134).
- Staging config: `SOCIAL_MOCK_MODE=false` + `SOCIAL_CONNECTOR_URL=''` → every publish attempt **silently fails** → post marked `failed`. **No scheduled-publisher cron.**
- **DB:** social_posts=0, social_accounts=0.
- **CRITICAL:** in the live test, Sarah delegated + reported *"pushed out a LinkedIn post"* — **nothing was delivered to any platform.** Capability gap + truthfulness gap.

## 3.6 CRM — 🟢 REAL
- Leads + Contacts + Deals + reorderable pipeline Stages, Activities, Notes. Lead scoring (`calculateScore` L1005 — completeness+engagement+deal+activity), lifecycle **state machine** (`validateStatusTransition` L1031: new→contacted→qualified→converted/lost), CSV import/export, merge + dedup, revenue/conversion/win-rate reporting. Proper structure (Eloquent models, `Actions/`, `Events/`, Repository pattern).
- **Live data:** leads=12, contacts=7, activities=8, automations=3, deals=0. Nurture real via email sequence engine.
- **Gaps:** scoring static (email opens/clicks not fed in — no behavioral scoring); `app/Engines/CRM/Jobs/` empty (no rot/SLA/auto-reassign); AI outreach ephemeral (TODO L50, `crm_outreach_drafts` unused); deals=0 (pipeline unexercised).

## 3.7 SEO / AEO — 🟡 MIXED (largest engine, 4553 lines)
- **Technical audit** (`deepAudit`/`runTechnicalChecks` :388/:3047): real single-URL `Http::timeout(15)` + DOMDocument parse; ~28 checks (code claims "40+" — inflated). **Not a crawler** (one URL/call, no link-following), **no JS rendering** in the audit path, **no real Core Web Vitals** (perf = TTFB + HTML byte size). No redirect chains/hreflang/sitemap validation. 545 audits, 3436 items — runs at scale.
- **AEO** (`AeoAuditService`): **real differentiator** — fetches page + robots.txt + llms.txt, checks 12 answer-engine signals (Article+FAQPage JSON-LD, dateModified, TLDR, question H2s, lists/tables, citations, **AI-crawler allow-rules GPTBot/ClaudeBot/PerplexityBot/Google-Extended**, llms.txt). 197 audits with real score variance (ws2 63.8, ws7 25.9, ws1 19.5). Score weighting delegated to black-box runtime.
- **Rank tracking + DataForSEO: degraded/dead** — live call failed (`serp_provider_20000`), **$0.00 across 153 `dfs_usage_log` rows**, `seo_serp_results` frozen since **2026-06-19**. `keyword_rank_history` = 32 points, **only 2 distinct keywords**, 100% GSC-sourced, **perfectly flat**. Competitor gaps basic (title-level grouping, no traffic weighting) on month-stale data.
- **Internal linking / orphans: STRONGEST area** — genuine Jaccard relevance (`jaccard*0.7 + authority*0.3`, over title+H1+meta only = coarse), placeability-validated anchors, **pushes edits to live WordPress** (`pushLinkUpdateToWordPress`), self-bills. `seo_links` = 4457 (**572 actually inserted**), orphans down to **2/147**. Real end-to-end automated execution.
- **Effectiveness:** no evidence work moves rankings (2 flat keywords, identical audit scores for months) — but tracking data far too thin to even measure a loop.
- **Core intelligence** (AEO scoring, anchor extraction, CTR/SERP scoring, intent) all offloaded to the black-box runtime; fail-safe to 0/null.
- **Verdict:** competent light-to-mid SEO *helper* with real execution + one differentiator (AEO). Would lose to Ahrefs/Screaming Frog on crawl/vitals/rank depth.

---

# PART 4 — CONVERGENT THEMES & LAUNCH-BLOCKERS

## Themes (every layer agrees)
1. **Budget-tier by design** — deepseek-chat brain, gpt-image-1 *low*, no fact-grounding. Cost-optimized, not premium.
2. **Execution is real but wildly uneven** — email, CRM, SEO-linking, and (budget) content genuinely deliver end-to-end; **social delivers nothing**; premium images & AI video don't exist.
3. **Claimed > delivered** — "40+ SEO checks" (28), image quality tiers (fake), **social "posted" (never)**, "enterprise DataForSEO" (dead). Enterprise-credibility *and* honesty risks.
4. **Learning loop dead + market intelligence near-absent** (Part 1).

## Launch-blockers (fix before ANY launch, not just enterprise)
- 🔴 **Social truthfulness** — build real Graph/LinkedIn/X publishing + a scheduled publisher, OR make Sarah honestly say "drafted, needs manual posting." Do NOT ship "I posted to social" when it's a phantom.
- 🔴 **`MarketingService::sendCampaign` legacy path** marks campaigns "sent" while sending zero — repair or delete.
- 🟠 **Creative images locked to low tier** (remove the hardcode, plan-gate quality); **video pipeline produces nothing at scale** (timeouts).

---

# PART 5 — LAUNCH-READINESS VERDICT & ROADMAP

**Ready for:** a **proactive SMB marketing autopilot** across **SEO (AEO + internal linking), content generation, email campaigns, and lead nurture**, with a human reviewing output. Real and shippable in those channels.

**NOT ready for:** enterprise-tier autonomous DMM, or any launch claiming social publishing / premium creative / research-grounded content / self-learning strategy.

### Prioritized path to enterprise
1. **Fix the honesty gaps first (non-negotiable):** social (build or disclose), the fake "sent" path, remove fake quality tiers/scores.
2. **Unlock creative quality:** stop hardcoding image `quality='low'`; plan-gate it; fix/fund video (MiniMax key, render timeouts).
3. **Revive the learning loop** (fix the `created_via` gate → populate `strategy_outcomes`; per-campaign attribution) **+ fund DataForSEO** (restore rank tracking).
4. **Upgrade the brain** for strategic work (deepseek-chat → a frontier reasoning model).
5. **Ground content** in SERP/keywords + add fact-checking/E-E-A-T; add a real crawler + Core Web Vitals to the SEO audit.
6. **Add market intelligence** (trends/news/competitor moves) — the missing "outward radar."
7. **Make report-back push + failure-aware;** let Sarah set/decompose her own goals.

---

# PART 6 — FIXES DEPLOYED DURING THIS ENGAGEMENT (staging, all backed up)

| Fix | File | Backup | Status |
|---|---|---|---|
| Message-refresh / event-stream (cursor normalize + 2s overlap + advance; cache-bust core.js `5.7.52-eventsfix`) | `AgentDispatchService::getEvents`, `public/app/index.html` | `.bak-20260714-eventsfix` | DEPLOYED, smoke 5/5 |
| Runtime→Laravel callback repoint verified live (v2.35, 313× `/api/internal/*` → 200) | (verification only) | — | CONFIRMED |
| GSC disconnect root-caused: OAuth app in "Testing" → 7-day token expiry (Boss to publish app; client `600147651433-…`) | `GscClient` | — | DIAGNOSED (Boss action) |
| Sarah forensic audit tool fixed (false-positive detector) → 33 wired / 0 stubs / 0 missing | `/root/sarah-forensic-audit.php` | — | FIXED |
| Pruned 3 dead-stub capabilities (keyword_research/keywords_suggest/keyword_check) | `AgentCapabilityService`, `ToolSchemaService` | `.bak-kwprune-20260715` | DEPLOYED |
| Keyword-count router branch (was "0", now "36") | `routes/api.php` | `.bak-kwcount-20260715` | DEPLOYED + verified |
| Case 7 error-attribution — deterministic "what failed" branch (real failed tasks) | `routes/api.php` | `.bak-case7-20260715` | DEPLOYED + verified |
| Dup-key idempotency — `TaskService::create` returns existing on key match | `TaskService.php` | `.bak-idem-20260715` | DEPLOYED + tested |
| "Hit a snag" instrumentation — final-row silent catch now logs the real error | `routes/api.php:2881` | `.bak-snagdiag-20260715` | DEPLOYED (awaiting live capture) |

**Known test artifacts on chef-red (ws2):** live behavioral + reproduction tests created real content (a "Private Chef Cost NJ" article chain, a strategic-mandate campaign chain) and test chat messages. Flagged for optional cleanup.

---

# APPENDIX — KEY EVIDENCE INDEX

**Live DB numbers (ws2 unless noted):** articles 155 pub/28 draft (`articles` table; `seo_content_index`=146); keywords 36; leads 12/contacts 7/activities 8/automations 3/deals 0; strategy_proposals 387; strategy_outcomes 0; experiments 0; images 307 (306×1024²); studio_designs 20 pending/7 failed/0 done; videos-ever 2; campaigns 0/email_log 0; social_posts 0/social_accounts 0; seo_audits 545/items 3436; aeo_audits 197; seo_links 4457 (572 inserted); orphans 2/147; keyword_rank_history 32pts/2kw; dfs_usage_log 153 rows @ $0; seo_serp_results frozen since 2026-06-19.

**Model/provider facts:** runtime LLM `deepseek-chat` (fallback gpt-4o-mini); images gpt-image-1 (quality hardcoded 'low'); email Postmark; video ffmpeg 4.4.2; AI-video MiniMax (unfunded).

**Key files:** `app/Core/Orchestration/ToolSchemaService.php`, `app/Core/Strategy/SarahDailyOrchestrator.php` + `SarahWeeklyOrchestrator.php` + `WorkspaceStateGatherer.php`, `app/Core/Orchestration/SarahReadBackService.php`, `app/Core/Intelligence/StrategyLearningService.php` (dormant) + `CampaignOptimizationEngine.php` (dormant), `app/Console/Commands/SarahAutoExecuteCommand.php`, `app/Connectors/RuntimeClient.php` (imageGenerate ~470/490/606; strategy endpoints) + `DataForSeoConnector.php`, `app/Engines/Write/Services/WriteService.php`, `app/Engines/Creative/Services/{CreativeService,BlueprintService,GeometryAnalyzerService}.php`, `app/Jobs/RenderStudioVideoJob.php`, `app/Engines/Marketing/Services/{EmailBuilderService,MarketingService}.php` + `EmailConnector.php`, `app/Engines/Social/Services/SocialService.php` + `app/Connectors/SocialConnector.php`, `app/Engines/CRM/Services/CrmService.php`, `app/Engines/SEO/Services/{SeoService,AeoAuditService,GscClient}.php`. Runtime (v2.34.1 zip): `index.js`, `meeting-room.js`, `meeting-prompts.js`, `lu-sarah-synthesis-routes.js`, `agents.js`, `lu-planner.js`.

**Scope NOT reviewed:** Builder/Arthur engine (215KB website builder), Chatbot engine (site chat) — treated as adjacent to the DMM-marketing question; available on request.

*End of master audit.*
