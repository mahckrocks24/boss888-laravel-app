# BOSS888 — MASTER CONTEXT (Launch-Scope Remediation)
_Last updated: 2026-07-20 · Authoritative running state: `BOSS888-STATE.md` · This doc = the big picture, zero re-discovery._

> "BOSS888" is the internal codename for **LevelUp Growth**. Never let it appear on any public/customer-facing surface.


---

## ⚠️ AMENDMENT — 2026-07-26 (supersedes §5, corrects §6)

_Added after INC-2026-001 (DeepSeek V4 outage). Everything below is verified by live probe or
production smoke test. See `handoff-2026-07-26/START-HERE-2026-07-26.md`._

### §5 "THE ONE BLOCKER" is **RESOLVED** — do not act on it as written

| §5 claim (2026-07-20) | Reality 2026-07-26 | Evidence |
|---|---|---|
| Runtime is v2.37.2 | Runtime is **v2.37.4-content** (deployed 2026-07-26) | package + deploy |
| Runtime "still lists all 20 agents + social/email tools" | **`/health` lists exactly 10 agents and 43 tools** — in-runtime sanitation shipped with v2.37.3 on 2026-07-21 | live `/health` |
| No current runtime source locally | **Source exists:** `LVL\runtime-pkg-2.37.3\` and `LVL\runtime-v2.37.4-deepseek-v4\` | local packages |
| No Railway deploy access | **Boss can and did deploy** (2026-07-26 ~08:45 UTC). Still **no CLI/token in the working environment** — Boss deploys manually; an assistant session cannot | pre-deployment report §5 |

⇒ The listed files (`capability-map.js`, `agents.js`, `registry.js`, `lu-planner.js`,
`lu-agent-search.js`, `tool-registry.js`) **no longer need launch-scope filtering** — that work
landed in v2.37.3. The `RuntimeClient` boundary sanitizer remains in place as defence in depth.

### NEW ARCHITECTURAL TRUTH (the biggest finding of INC-2026-001)

**Laravel orchestrates; the Railway Node runtime EXECUTES all AI provider calls.**
`config/llm.php` and `app/Connectors/DeepSeekConnector.php` still contain model identifiers,
but that path is **dormant** — the 2026-04-12 hands-vs-brain refactor moved essentially every
call site to `RuntimeClient`. During the outage, **patching Laravel would have fixed nothing.**
All 8 hardcoded `deepseek-chat` literals were in the runtime.

Canonical reference: **`handoff-2026-07-26/ARCHITECTURE-AI-EXECUTION-LAYER.md`** (decisions
AD-01…AD-12). Read it before any AI/provider work.

### AI provider state
- **`deepseek-v4-flash` is the production default**; `deepseek-v4-pro` by explicit tier only.
- `deepseek-chat` / `deepseek-reasoner` **retired by DeepSeek 2026-07-24 15:59 UTC** → HTTP 400.
- Model identifiers are centralised in the runtime's `deepseek-models.js`. **Never hardcode a
  model name at a call site.**
- V4 reasoning is always-on and its tokens consume `max_tokens`; HTTP 200 with empty content is
  possible and is now rejected (`DEEPSEEK_EMPTY_FINAL_CONTENT`).

### §6 corrections
- **SSH key:** §6 says `~/.ssh/do_levelup`. That key was lost with the old PC. The working key is
  **`C:\Users\markr\.ssh\id_staging`** (alias `ssh staging`, no passphrase).
- **Runtime version** in §6 (v2.37.2) is stale — see above.
- **Runtime URL and `X-LevelUp-Secret` handshake are unchanged.** Boss still deploys.
- **`config:cache` is PROHIBITED** (not just discouraged) — `RuntimeClient` reads `env()`
  directly, so caching config breaks the runtime connection. Use `config:clear`.

### ⚖️ GOVERNANCE IS NOW BINDING (2026-07-26, P0-A)

`GOVERNANCE-DECISION-REGISTER.md` is **binding on all future work**. Code, plans, or documents
that contradict it are wrong by definition. Three executive decisions:

- **GD-001 Bella → Govern and Gate.** Permanent rule: **Bella NEVER directly executes privileged
  actions. Bella may REQUEST. The Governance layer decides.** No exception, no bypass.
- **GD-002 `query_database` → REMOVE.** ⚠️ **DO NOT REPAIR.** Its current safety is an unintended
  Laravel 11 `TypeError`; the fix looks trivial and would arm unrestricted database reads.
- **GD-003 Replay Policy → 3 tiers** (automatic / manual-approval / discard; ambiguous ⇒ manual).

Plus invariants: **PO-1…PO-5** (Platform Owner), **MA-1…MA-6** (machine authority — no machine
approves anything, none holds standing authority), **AP-1…AP-6** (approvals fail closed),
**AZ-1…AZ-6** (hybrid Gates + capability registry; defence in depth).

**Authorization model adopted: HYBRID** — Gates + capability registry + Policies where
model-scoped. A third-party RBAC package was **rejected** to avoid a fifth competing authority
vocabulary alongside `agent_capabilities`, `ApprovalPolicyRegistry`, `workspace_users.role`, and
`LaunchScopePolicy`.

Registers: `SENSITIVE-OPERATIONS-REGISTER.md` (77 ops) · `CAPABILITY-REGISTRY.md` (83 capabilities)
· `ENGINEERING888-DEPENDENCY-REGISTER.md` (M1–M9) · `GOVERNANCE-VERIFICATION-PLAN-M9.md`.

### Next approved phase sequence
~~1. Laravel Admin Forensic Audit~~ ✅ **COMPLETE 2026-07-26 (5.4/10)** → **Governance Hardening P0 (IN PROGRESS — P0-A complete)** → 2. AI Architecture Audit →
3. Engineering888 Masterplan → 4. Bella Masterplan.
**Engineering888 does not exist in any codebase** (0 hits repo-wide); **Bella** exists only as an
admin persona + `BellaController`. Both require their own masterplan phase.

---
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

---

## UPDATE 2026-07-26 — CHAT PLATFORM FORENSIC AUDIT

The conversational layer was audited end-to-end. **Grade 2/10.** Nine surfaces, seven frontend
clients, five message stores, three credit models, two `CreditService` classes, zero real-time
transports, **zero tests**. 16 contract drifts and 20 open risks catalogued.

Authoritative documents (in `/root/handoff-2026-07-26/` and `C:\Users\markr\LVL\handoff-2026-07-26\`):
`CHAT-PLATFORM-FORENSIC-AUDIT.md` · `CHAT-SURFACE-INVENTORY.md` · `CHAT-CONTRACT-DRIFT-MATRIX.md` ·
`CHAT-FUNCTIONAL-PARITY-MATRIX.md` · `CHAT-ENTERPRISE-ARCHITECTURE-MASTERPLAN.md` ·
`CHAT-MIGRATION-ROADMAP.md` · `CHAT-CONTRACT-TEST-PLAN.md` · `CHAT-RISK-REGISTER.md`

**Binding conclusions for all future chat work:**
1. No further single-surface chat patching. Contract and tests come first (roadmap P1).
2. The agent-chat two-phase contract (`{pending, ack, poll_url}`) is the interim platform
   contract. Any client reading `{reply}` from `/api/agents/{slug}/messages` is broken.
3. `persist → meter → generate` is the required order. A refused message must still be saved.
4. No surface may fabricate a reply. Studio's keyword fallback (**CR-03**) violates this and is
   the highest-severity trust defect on the platform.
5. Read state must become per-user (`message_reads`); the current workspace-wide `read_at` is
   wrong for multi-user workspaces (ws1, ws990003).
6. Customer chat history and unread state are never cleared, migrated destructively, or dropped.

---

## UPDATE 2026-07-26 (late) — CHAT PLATFORM P1 COMPLETE

The conversational layer now has a **normative contract and a measured baseline**.

- `CHAT-CONTRACT-v1.md` — 118 clauses, `chat.contract.version = 1.0`. **Binding on every current
  and future conversational surface.**
- `contracts/chat/v1/` — 14 machine-readable JSON schemas.
- `tests/Feature/Chat/` — one conformance suite, 74 cases, 11 surface adapters.
- **Measured: 52.4% conformance — 207 pass, 188 fail, 47 critical.** First such number ever.
- CR-01 (markup leak), CR-02 (duplicate mark-read), CR-18 (swallowed persistence failure) —
  **closed, 16 regression assertions**. Zero regressions across the whole baseline.

**Binding additions to the chat rules already recorded above:**
7. **No chat change ships without a conformance run.** A drop in pass count blocks the change —
   this is the control that would have caught the `{reply}`→`{ack}` break before a customer did.
8. **Errors are never assistant messages** (clause X-08) and **icons are never message text**
   (clause G-04). Both were violated in production and are now regression-tested.
9. **No surface may fabricate a reply** (clause F-06). Studio still violates this — CR-03, open.
10. **Legacy response fields are deprecated, not removed** (contract §16). Every alias has a
    canonical replacement, an owner surface, a removal phase, and a test.
11. **Check for a concurrent session before editing `routes/api.php`** (CR-22). A second agent was
    writing to it during P1.

**P2 is not started.** Highest-leverage next step by measurement: an idempotency key plus
`message_charges` closes ~39 failures across 10 surfaces. See `CHAT-P2-DECISION-REPORT.md`.

---

## UPDATE 2026-07-26 (P2-A) — STABILIZATION COMPLETE · CHAT CONTRACT FROZEN

**`chat.contract.version = 1.0` is FROZEN.** Any change to an endpoint, payload, schema or response
contract requires all five together: version bump · adapters · schemas · conformance tests · docs.
Removing a violation, or adding a field v1 already defines, does not require a bump.

Delivered: **CR-03 closed** (Studio no longer fabricates replies — it used to pick a palette with
`array_rand()` and report it as an AI decision); **SEO persists the user's message on refusal**;
structured errors; swallowed failures logged. One production file, five edits, **zero regressions**.

**Three corrections to earlier records, all evidence-backed:**
- `App\Services\CreditService` **does not exist** — Studio chat has never charged. The
  "three credit models / two CreditService classes" claim was wrong; there is one CreditService.
- Aria (`/api/assistant`) is the **Builder's** assistant and is client-held by design — it cannot
  persist without a migration.
- One critical conformance failure is a proven **false positive**; the true count is 45.

Binding addition to the chat rules: **12. Never edit a conformance test to improve a score.** If a
case is wrong, say so in the report and fix it in a named phase.

`ROUTES-DECOMPOSITION-PROPOSAL.md` proposes splitting the 19,576-line `routes/api.php` into 18
module files. **Proposal only — not implemented, blocked on CR-22.**

---

## UPDATE 2026-07-27 (P2-B) — CHAT IDEMPOTENCY AND CHARGE INTEGRITY

The platform now has a shared idempotency primitive and a charge-lifecycle record.
`chat.contract.version` remains **1.0** — every error P2-B emits was already in the frozen taxonomy.

- `chat_idempotency_records` — UNIQUE `(workspace_id, surface, idempotency_key)`. Acquisition IS the
  INSERT: the database decides who was first. **Never scope idempotency by key alone** —
  `tasks.idempotency_key` is globally unique and that is the anti-pattern, not the precedent.
- `message_charges` — UNIQUE `idempotency_record_id`; the message↔ledger join clause K-07 requires.
  **It is not a balance.** `credits`/`credit_transactions` remain authoritative.
- SEO assistant integrated behind a per-surface flag; the gate sits BEFORE the surface's meter.

**Binding additions to the chat rules:**
13. Idempotency scope is (workspace, surface, key). Never the key alone.
14. `message_charges` never holds or decides a balance.
15. A capability is declared only after behavioural proof — never in anticipation.
16. Studio charges nothing and its dead `class_exists()` guard must not be "fixed" (CR-23 is a
    pricing decision). Aria stays ephemeral until its classification is decided.

Conformance 215/195, criticals 46→43, zero regressions, 105 chat tests.
**P2-C not started.**

---

## UPDATE 2026-07-27 (P2-C) — PRIMITIVE ADOPTION + PARALLEL CERTIFICATION + ENV SAFETY

Chatbot adopted the P2-B primitive (no duplicate services). Genuine OS-process parallelism certified:
10 simultaneous processes → one execution, one user message, one final, one charge, one correlation id.
Conformance 217/193, criticals 43→41, zero regressions. Contract still 1.0.

**Binding additions to the chat rules:**
17. `--env=testing` once resolved to PRODUCTION (INC-2026-002). `.env.testing` and
    `ProductionDatabaseGuard` now exist and fail closed. Never delete them. Tests use the
    restricted `levelup_tester` MySQL user, which cannot read production.
18. Charging may be DELEGATED to a surface that already meters correctly. `not_chargeable` means
    two different things — always read `metadata.reason`.
19. **CR-22 is a prerequisite for shared-file work.** A concurrent session reverted three phases of
    production fixes on 2026-07-27 (INC-2026-003), briefly restoring the `array_rand()` fabricated
    Studio reply. Take backups from the CURRENT file; patch on top; verify other workstreams' markers.
20. Concurrency claims must be process-parallel, never interleaved-and-described-as-parallel.

**P2-D / broader migration not started.**

---

## UPDATE 2026-07-27 (CR-22) — ROUTE OWNERSHIP CONTROLS

Safety controls delivered; route extraction HELD pending a boundary decision.

**Binding additions:**
21. **Never restore a .bak over current source.** A backup without a manifest is not
    authoritative; a manifest predating current source is refused. Reconstruct by re-applying
    edits on top of current content. 61 of 64 legacy backups were classified UNSAFE — each
    would have reintroduced the array_rand fabricated-reply defect — and are now quarantined.
22. **Acquire a source-ownership lock before editing a shared file.** Fail-closed: no lock =
    refused. `storage/app/source-locks/`, audited.
23. **Route ORDER is behaviour.** The equivalence gate asserts the ordered signature per route
    (982). An order difference is never waived as serialization noise.
24. Measured fact: `routes/api.php` BLOCK17 is 68% of the file and contains all four
    INC-2026-003 collision points. A top-level-only split does not reduce the risk.

---

## UPDATE 2026-07-27 (CR-22B) — MODULAR ROUTE STRUCTURE LIVE

routes/api.php 19,595 → 7,991 lines. BLOCK17 split into 13 owned modules at
routes/api/authenticated/. 982 routes, ordered signature unchanged (c46d8a07…).

**Binding additions:**
25. **Route source spans 14 files.** Any tool or test reading route SOURCE TEXT
    must read `routes/api.php` + `routes/api/authenticated/*.php` (tests:
    `Tests\Support\RouteSource::all()`). A reader pinned to one filename sees 41%
    of the source and passes while guarding nothing.
26. **Every route module re-declares the full parent import set.** `use` aliases
    do not cross a `require`; a missing one silently degrades `X::class` to the
    string "X" and registers a route against a nonexistent controller. Measured:
    8 routes affected before the signature gate caught it.
27. **Never derive PHP structure from indentation in routes/api.php.** It embeds
    JavaScript in heredocs. Use `token_get_all`. Indentation reported 352
    top-level children where the lexer finds 172.
28. **Never hand-restore the pre-extraction monolith.** It lives outside the
    project at /root/cr22b-legacy/, mode 0440. Copying it over routes/api.php
    reverts CR-22B and orphans 13 module files. Use rollback.php.
29. **TD-14 — SourceOwnershipLock is advisory and was violated again on
    2026-07-27.** It stops nothing that does not call it. Enforcement needs a
    filesystem or pre-commit hook. Highest-value remaining infrastructure item.

---

## UPDATE 2026-07-27 (ENTERPRISE888 E0) — CONTROL PLANE AUDIT

**Binding additions:**
30. **Bella has unguarded Level-3 actions.** `adjust_credits` and `suspend_user`
    mutate billing and user access with no approval, no governance and no MFA,
    dispatched by regex over model output. She has never been used. Do not enable
    Bella until these are removed.
31. **Governance is in SHADOW mode.** `effective=true` regardless of decision.
    Never describe or rely on it as an enforcing control until the mode changes.
32. **No evidence model exists.** No completion claim on this platform can be
    substantiated. Evidence is the precondition for trusting any agent report.
33. **Some runtime tools are STUBS** and return success shapes. A stub must never
    satisfy an evidence requirement.
34. **There is no Filament.** The admin is a bespoke SPA — every admin screen is
    a build, not a resource declaration.
35. **Both platform admins have MFA disabled.** Every governance design assumes
    step-up is possible; today it is not.

---

## UPDATE 2026-07-27 (ENTERPRISE888 E0.5) — BELLA CONTAINMENT

36. **Bella can no longer mutate billing or user access.** `adjust_credits` and
    `suspend_user` were removed at every layer, with 29 alias variants blocked by
    an authoritative backend gate. Removal does not depend on GOVERNANCE_MODE.
37. **`mfa.stepup` stands down before governance activation** and activation
    requires two MFA-enrolled admins. Never assume it protects a surface. Use
    `mfa.enrolled` (`RequireEnrolledMfa`) for fail-closed enforcement.
38. **Bella is closed to everyone** until an admin completes MFA enrolment. This
    is intended.
39. **Enrolling the second platform admin activates governance platform-wide.**
40. **TD-19** — `routes/web.php` and `routes/exec-api.php` are NOT protected
    paths; governed write and drift detection do not cover them.

---

## UPDATE 2026-07-27 (E0.6) — PROTECTED-PATH COMPLETION

41. **All active route source is protected**: `routes/web.php`,
    `routes/exec-api.php`, `routes/api.php`, the 13 modules, and
    `app/Engines/CRM/Http/Routes.php`. Derive the set from the loading path
    (`withRouting` + `loadRoutesFrom`), never from memory.
42. **CORRECTION to entry 39:** enrolling the second admin does NOT activate
    governance platform-wide. `mfa.stepup` is on ONE route, and `id 990016` is a
    `validation_only` identity excluded from eligibility regardless of MFA.
    Enrolling Mark gives an eligible count of 1 against a bar of 2 — no activation.
43. **Governance activation is one-way** and, once active, an admin losing
    eligibility puts the provider-approval route into a fail-closed 423 until an
    explicit time-boxed recovery.
44. **TD-20** — `app/Engines/CRM/Http/Routes.php` is shadowed by `crm-01.php` and
    contributes 0 live routes. Editing it appears to do nothing.

---

## UPDATE 2026-07-27 — E1 AUTHORIZED · EX-2026-001

45. **EX-2026-001** — the E0.6 OPERATIONAL MFA gate is DEFERRED by Platform Owner
    decision for implementation velocity. It is an exception, not a waiver.
    Boundaries unchanged: governance stays bootstrap/shadow, no enforcement, Bella
    admin-only and MFA-closed, no security control weakened.
46. **The binding deadline for EX-2026-001 is BEFORE E5**, not "before launch".
    E5 is the first phase where an agent writes to production. An agent mutating
    protected paths under an advisory lock (TD-14) with no MFA-verified approver
    is the combination that carries real risk.
47. **Bella is inaccessible to everyone** while 0 of 2 admins are enrolled. That
    is the fail-closed gate working, not a fault.
48. **E1 is read-only.** No execution, no mutating endpoints, no new tables, no
    migrations. Buttons that would act are ABSENT, not disabled.
