# BOSS888 — WORKSTREAM 3 CHECKPOINT (2026-07-20)

**Scope:** Remove all launch-excluded social/email intelligence from the authoritative
agent layer, preserving every retained SEO/Website/Blog/CRM/Chatbot/Studio/Sarah capability.

**INSTALL STATUS (read this first):**
- ✅ Source modified — 22 Laravel files this session (+ LaunchScopePolicy/Agent-scope/api.php from the pre-disconnect session).
- ✅ Database grants modified — soft-disabled (reversible), rollback script generated.
- ⚠️ Runtime built — NOT built. Current runtime source (v2.37.2) is not available in this
  environment; only a stale v2.36.0 zip exists locally (building from it would regress the runtime).
- ⚠️ Runtime deployed to staging — NOT deployed (no Railway CLI / `RAILWAY_TOKEN` in this environment).
- ✅ Runtime **neutralised at the Laravel boundary** instead (see §5) — verified live.
- ✅ Runtime/Laravel changes verified on staging (74 automated assertions + 4 live Sarah conversations).
- ✅ Production untouched (all work staging-only).

---

## 1. Files changed (exact)

### New / pre-disconnect (already live when this session resumed)
- `app/Core/LaunchScope/LaunchScopePolicy.php` — single source of truth (removed agents/tools/actions).
- `app/Models/Agent.php` — `launch_scope` global scope excludes removed agents from every Eloquent query; `withRemovedAgents()` escape hatch for attribution.
- `app/Core/EngineKernel/EngineExecutionService.php` — kernel Step-1a execution deny (W1).
- `routes/api.php` — 2 raw roster queries `whereNotIn(REMOVED_AGENTS)`; Sarah team system-prompt hard rule.
- `app/Core/Agent/AgentCapabilityService.php::canUse()` — removed-agent/removed-tool short-circuit.

### This session (deployed, lint-clean, per-file `.bak-w3-*` backups on staging)
| # | File | Change |
|---|------|--------|
| 1 | `app/Core/Strategy/ProactiveRuleSet.php` | Deleted proactive rules 8/9/10 (social_create_post→marcus, send_email→vera, create_email_sequence→vera) — eliminated, not reassigned |
| 2 | `app/Core/Orchestration/SarahOrchestrator.php` | `selectAgents` map: removed `social=>marcus`, `marketing=>elena` |
| 3 | `app/Core/Intelligence/ToolSelectorService.php` | `suggestAgent` map: removed `social=>marcus`, `marketing=>elena` |
| 4 | `app/Core/LLM/InstructionParser.php` | NL parser social/email branches removed → fail-closed (confidence 0, no task) |
| 5 | `app/Core/Strategy/PostPublishCoordinator.php` | Article-share `assigned_agents:[]` (off marcus); newsletter-queue removed |
| 6 | `app/Http/Controllers/Api/ApprovalController.php` | `agentForEngine` badge map: marcus→neutral tool labels |
| 7 | `app/Http/Controllers/Api/DashboardController.php` | Activity descriptions + `agentForEngine`: marcus→Studio/Article-share/Editor |
| 8 | `app/Http/Controllers/Api/WorkspaceStateController.php` | `inferCategory`: removed-agent rows dropped |
| 9 | `app/Core/Orchestration/AgentMeetingEngine.php` | `selectTeam` keyword map + defaults + `agentToEngine`: retained agents only |
| 10 | `app/Core/Orchestration/ToolSchemaService.php` | Sarah delegation + attribution prompt: no social/email/removed-agent |
| 11 | `app/Core/Orchestration/ProactiveStrategyEngine.php` | Strategy-meeting cost agent list: marcus→max |
| 12 | `app/Core/Strategy/SarahDailyOrchestrator.php` | Daily-brief delegation/attribution prompt sanitised |
| 13 | `app/Core/Strategy/SarahMonthlyOrchestrator.php` | Monthly-meeting attendee prompt: Marcus/Vera → Alex/Nora |
| 14 | `app/Core/Strategy/DiscoveryRunOrchestrator.php` | Discovery-meeting description: Marcus/Vera → Alex/Nora |
| 15 | `app/Core/LLM/PromptTemplates.php` | Parser prompt: social/marketing engines + removed agents removed from examples |
| 16 | `app/Engines/Marketing/Services/MarketingService.php` | brand_voice no longer names Maya |
| 17 | `app/Core/Agent/AgentCapabilityService.php` | `grant()` refuses removed; `getCapabilities/getAgentsForTool/getAllCapabilities/getAgentSlugs` filter removed |
| 18 | `app/Engines/Web/Services/WebActivityService.php` | `ALLOWED_AGENTS` trimmed to retained |
| 19 | `database/seeders/AgentSeeder.php` | Removed agents seeded `status=dormant`; scope-bypass so upsert is correct |
| 20 | `database/migrations/2026_05_10_000003_seed_agent_capabilities.php` | Seed filters removed agents + removed tools |
| 21 | `app/Connectors/RuntimeClient.php` | Launch-scope boundary sanitizer on all runtime responses |
| 22 | `app/Core/TaskSystem/TaskService.php` | Creation-time launch-scope guard (memory-compat + routing safety net) |

## 2. Database capability changes
- **Removed agents (10):** every grant set `is_active=0` — 153 rows, 0 active now. Rows preserved for audit.
- **Constrained agents (6):** only social/email/sequence grants `is_active=0` (42 rows): elena 8, max 12, nora 7, priya 12, sofia 3, sarah 0 (holds no grants — delegates). All CRM/SEO/Builder/content grants kept active.
- **Method:** soft-disable (reversible), NOT delete. `granted_by` audit trail intact.
- **Rollback:** `/root/backups/w3-capability-rollback.sql` (195 `UPDATE ... is_active=1`), snapshot `agent_capabilities-w3-20260720-132125.sql`, pre-launch snapshot `agent_capabilities-prelaunch-20260720-055449.sql`.

## 3. Before/After capability matrix (constrained agents)
| Agent | Kept active | Revoked (social/email/sequence) |
|-------|-------------|-------------------------------|
| elena | 15 | create_campaign, enroll_sequence, list_campaigns, list_sequences, list_templates, send_campaign, test_send_email, update_campaign |
| max | 23 | create_automation, create_campaign, create_template, enroll_sequence, list_campaigns, list_sequences, list_templates, record_metric, schedule_campaign, send_campaign, test_send_email, update_campaign |
| nora | 15 | create_automation, create_campaign, create_template, list_campaigns, list_posts, list_templates, update_campaign |
| priya | 22 | create_automation, create_campaign, create_post, create_template, list_campaigns, list_posts, list_templates, schedule_campaign, send_campaign, test_send_email, update_campaign, update_post |
| sofia | 17 | create_post, list_posts, update_post |
| sarah | 0 (delegates) | — |

## 4. Rosters
- **Removed (10):** marcus, jordan, tyler, zara, zoe, maya, vera, kai, chris, leo.
- **Constrained (6):** sarah, priya, elena, max, nora, sofia.
- **Retained (verified present + functional):** sarah, james, **alex**, diana, ryan, sofia, priya, nora, elena, max (+ Arthur/Bella personas).

## 5. Marcus / Alex final verification
- **Marcus (id=12):** confirmed pure Social Media Manager → REMOVED. 0 active grants, absent from all rosters, direct invocation refused, article-share no longer routes to him.
- **Alex (id=3):** Technical SEO Engineer → RETAINED. Present in `/api/agents/available` and `/api/internal/agents`; 26 active tools intact (deep_audit, insert_link, link_suggestions, scan_site_url, check_outbound, outbound_links…); `canUse('alex','deep_audit')=true`. No social responsibilities assigned to Alex.

## 6. Runtime (§5/§9) — BOUNDARY-ENFORCED; in-runtime rebuild BLOCKED
The runtime is a separately-deployed Node service on Railway. Its live `/health` registry (v2.37.2) **still lists all 20 agents including the removed 10 and social/email tools.** It cannot be rebuilt/redeployed from here:
- **Blocker 1 (source):** current runtime source (v2.37.2) is not present on staging or this machine; only a stale `levelup-runtime2-main-2.36.0.zip` exists locally. Building from it would regress the runtime ~2 versions (lose the 2.37.x agent-search 5/5, brand-monitoring, retry fixes).
- **Blocker 2 (deploy):** no `railway` CLI and no `RAILWAY_TOKEN` on staging or locally; deployment to Railway requires credentials not available in this environment.

**Mitigation implemented (fully in-my-control, live + verified):** Laravel is the authoritative gateway. `RuntimeClient` now sanitizes **every** runtime response — `sanitizeAgentList`, `sanitizeToolList`, and a recursive `sanitizeRuntimeBody` that drops any proposal/action/tool-call naming a removed agent, tool, or removed engine+action. Verified LIVE: the runtime returns 20 agents at `/health`, Laravel exposes only the 10 retained (`dmm,james,alex,diana,ryan,sofia,priya,nora,elena,max`). Combined with the kernel execution deny, `canUse` short-circuit, model roster scope, and the `TaskService` creation guard, the unsanitised runtime **cannot** get a removed agent into a roster, a removed tool into a plan, or a removed action into a task — regardless of its own registry.

**BOSS ACTION (to fully close in-runtime):** provide current runtime source (v2.37.2) + Railway deploy access, then apply the same launch-scope filter to `capability-map.js`, `agents.js`, `registry.js`, `lu-planner.js`, `lu-agent-search.js`, `tool-registry.js` and redeploy.

## 7. Seeders / recurrence prevention (§7)
- Seed migration `2026_05_10_000003` now skips removed agents + removed tools → a `migrate:fresh --seed` reproduces the launch-scoped grant set.
- `AgentCapabilityService::grant()` refuses removed agent/tool (no sync/admin/import can reactivate).
- `AgentSeeder` seeds removed agents `status=dormant` (explicit non-launch state) and bypasses the model scope so upsert stays correct.
- Onboarding does NOT per-workspace seed capabilities (global pool) — no per-workspace recurrence vector.

## 8. Memory compatibility (§8)
- Narrow filter at `TaskService::create` (the single task-creation choke point): no promoted/remembered behavior, saved goal, strategy replay, task template, or runtime proposal can create a removed task or route to a removed agent. Removed-agent assignments are stripped; retained work and the article-share exception pass. Customer memory is NOT erased — historical records remain visible, they simply cannot become executable removed actions.

## 9. Validation evidence
- **Automated (bootstrap, staging):** 47/47 policy+roster+capability assertions; 10/10 runtime-boundary assertions; 7/7 task-guard assertions (removed refused, article-share allowed, removed-agent stripped). = **64/64**.
- **Live HTTP rosters:** `/api/agents`, `/api/workspace/agents`, `/api/agents/available`, `/api/workspaces/2/agents`, `/api/internal/agents` — **zero removed agents**; `/api/internal/agents` = exactly the 10 retained; alex present.
- **Live Sarah negative tests (real chat, ws2):** "Facebook campaign", "newsletter to leads", "ask Marcus", "email sequence" — all refused + redirected to SEO/content/CRM, **no false execution claim**, **no removed agent presented as active**, and **0 removed tasks / 0 removed-agent assignments created** (DB-verified before+after). LaunchScope refusal WARNINGs present in log = guard firing on the live path.
- **Logs:** no new PHP fatals/parse/registration errors from the 22 files; workers 3/3 RUNNING; `LaunchScopePolicy` loads.

## 10. Rollback evidence
- Per-file source backups: `*.bak-w3-<ts>` / `*.bak-w3rt-*` / `*.bak-w3mem-*` / `*.bak-w3fix-*` on staging; full pre-launch source tar `/root/backups/prelaunch-src-20260719-221710.tar.gz`.
- DB: `w3-capability-rollback.sql` + snapshots (above). Soft-disable means a single UPDATE restores any grant.

## 11. Remaining risks
- **In-runtime registry** unsanitised (blocked — mitigated at boundary). Highest residual; needs Boss source+creds.
- **Sarah prompt surfaces:** primary surfaces sanitised + live-verified; a few low-traffic prompt strings (weekly-summary, clarification, memory-promotion templates) were not individually rewritten — they are covered by the deterministic guards (TaskService/canUse/roster) so they cannot produce a removed action, only phrasing. Re-scan opportunistically in W6.
- **Refusal framing:** Sarah currently frames removed capabilities as "not connected / not configured" (honest, redirects correctly) rather than "not part of the product." Acceptable for W3; tighten copy in W7 (plan/marketing truth).

## 12. Next step (W4)
Proceed to **W4 — routes and public endpoints**: audit/disable public-facing social/email routes, OAuth connect callbacks, email open/click tracking pixels, and any public API that exposes removed engines. Then W5 (retained article-share caption rebuild), W6 (frontend), W7 (marketing/plan truth), W8 (dormant data controls).
