# GOVERNANCE HARDENING MASTERPLAN — PHASE P0

**Status:** ✅ **APPROVED 2026-07-26 by Platform Owner** · **P0-A COMPLETE — P0-B not started**

> **Approved with three executive decisions**, now binding and recorded in
> `GOVERNANCE-DECISION-REGISTER.md`:
> **GD-001** Bella → *Govern and Gate* (never retire, never expose; Bella REQUESTS, Governance
> decides) · **GD-002** `query_database` → *Remove* (do not repair — its safety is an unintended
> Laravel 11 incompatibility) · **GD-003** Replay Policy → *Approved* (3 tiers).
>
> P0-A deliverables: Decision Register · Sensitive Operations Register (77) · Capability Registry
> (83) · Bella Governance Specification · Engineering888 Dependency Register · M9 Verification
> Plan (87 tests / 84 MUST). **No production behaviour changed.**
**Date:** 2026-07-26
**Commit context:** `416686e2fb75cca4c0a245a0d60f230ced178429` · branch `feature/studio888-mrc-2a`
**Gate:** Engineering888 development is PAUSED until this phase completes.

**Evidence sources (only):** `LARAVEL-ADMIN-FORENSIC-AUDIT-2026-07-26.md` ·
`ADMIN-AUDIT-BASELINE-2026-07-26.md` · `INCIDENT-REGISTER.md` (INC-2026-001) ·
`ARCHITECTURE-AI-EXECUTION-LAYER.md` · `RUNTIME-2.37.4-DEEPSEEK-V4-DEPLOY-AND-ROLLBACK.md` ·
`BOSS888-MASTERCONTEXT-2026-07-20.md` (+ 2026-07-26 amendment) · `BOSS888-STATE.md` ·
plus read-only route/DB/source enumeration performed for Section 6.

> **This document changes nothing.** It is architecture, sequencing, and impact analysis.

---

# SECTION 1 — EXECUTIVE SUMMARY

## Why governance, not capability, is the blocker

The platform can already *do* the work. It runs 25 workspaces, 24 active subscriptions, 17
engines, 978 routes, 2,411 tasks, and a 43-tool AI runtime. Capability is not the constraint.

**What it cannot do is constrain itself.**

Four evidenced facts define the blocker:

**1. Authority is a single boolean.** There are zero `Gate::define` calls and no `app/Policies/`
directory. Admin identity reduces to `users.is_platform_admin`. Across 11 users, `is_admin` and
`is_platform_admin` are perfectly correlated (2 users hold both, 9 hold neither) — so the platform
has exactly **two authority levels: everything, or nothing.** There is no way to express
"Engineering may deploy but not touch credits."

**2. Privileged operations are inconsistently guarded.** Of **64 mutating admin routes**, only
**16 carry `DenyApiKeyAuth`** and exactly **1 carries MFA step-up**. The unguarded 48 include
`POST /api/admin/workspaces/{id}/credits`, `DELETE /api/admin/users/{id}`,
`POST /api/admin/config`, `POST /api/admin/house-accounts/{id}/top-up`, and
`POST /api/admin/notifications/broadcast`.

**3. A privileged AI agent already exists and is ungoverned.** Bella can adjust credits and
suspend users from **model-generated parameters**, with no confirmation, no approval, and no
second factor. It has never executed (`audit_logs WHERE action='bella_chat'` = 0) — which is the
only reason this is not an active incident. Its most dangerous capability, arbitrary SQL, is
disabled **by a Laravel 11 `TypeError`, not by design**; repairing one line arms it.

**4. The platform cannot see itself fail.** Task failures are recorded in `tasks.error_text`, never
in `failed_jobs`. During INC-2026-001, `failed_jobs` read 0 throughout a **42-hour total AI
outage** affecting 10 workspaces. Governance without observability is unenforceable.

## The strategic argument

Engineering888 is, by definition, a **privileged autonomous system**: it will hold engineering
authority, act on infrastructure, and consume the same runtime that just failed silently for two
days. Building it on the current foundation means granting a new autonomous actor the only
authority level that exists — **full platform admin** — with no role to assign it, no event stream
to observe it, no approval path to gate it, and no MFA to protect it.

**Bella is the proof of what happens without this phase.** A capable, well-written, 1,748-line
autonomous admin agent was built to completion and could not be safely switched on. It has sat
dormant for months. Engineering888 would repeat that outcome at greater scale.

## What P0 is and is not

**P0 is:** a targeted hardening phase that installs an authority model, a governed execution path
for AI actions, an event backbone, and consistent protection of privileged operations.

**P0 is not:** a refactor. It explicitly does **not** touch the 19,363-line `routes/api.php`
monolith, the 508 `.bak` files, engine internals, or Studio. Those are real debt, and they are out
of scope.

**Estimated outcome:** enterprise readiness **5.4 → 7.7** (Section 12), with Authorization moving
3.5 → 8.0 and Security 5.0 → 7.5.

**The platform does not need to be rebuilt. It needs to be made governable.**

---

# SECTION 2 — CURRENT GOVERNANCE ARCHITECTURE (as implemented)

## 2.1 Map

```
REQUEST
  │
  ├─ TrustProxies ──► real client IP (CF-Connecting-IP / X-Forwarded-For)
  ├─ PublishedSiteMiddleware
  ├─ CorsMiddleware · SecurityHeadersMiddleware (CSP) · StagingNoindex
  ├─ LaunchScopeRoutes ──► 404 for out-of-scope surfaces (social/email/mentions)
  │
  ├─ AUTHENTICATION
  │   ├─ JwtAuthMiddleware (883 routes) ─► sets auth_via, auth_via_claim,
  │   │                                    workspace_id, workspace_role, session_id
  │   ├─ ApiKeyAuth (41 routes) ────────► auth_via = 'api_key'
  │   ├─ RuntimeSecretMiddleware (23) ──► shared X-LevelUp-Secret
  │   └─ POST /api/admin/auth ──────────► BELLA_ADMIN_TOKEN ⇒ JWT as User::find(1)
  │                                        provenance = 'shared_admin_token'
  │
  ├─ AUTHORIZATION  (middleware only — no Gates, no Policies)
  │   ├─ AdminMiddleware (129) ─► if (! $user->is_platform_admin) → 403
  │   ├─ DenyApiKeyAuth (45) ───► refuse api_key AND shared_admin_token
  │   ├─ RequireMfaStepUp (1) ──► 4-state governance machine
  │   ├─ TeamRoleMiddleware (5) ► workspace role
  │   └─ PlanMiddleware / AeoPlanGate (12) ► commercial gating
  │
  ├─ EXECUTION
  │   ├─ EngineExecutionService ─► LaunchScopePolicy deny (Step 1a)
  │   ├─ ApprovalService ────────► requestIfNeeded / approve / reject / revise
  │   └─ RuntimeClient ──────────► Railway runtime ─► DeepSeek / OpenAI
  │
  └─ OBSERVABILITY
      ├─ AuditLogService ─► audit_logs (7,557 rows, 20+ action types)
      ├─ task_events (12,561)
      └─ Sentry (configured)   ⚠ NO event bus · NO provider-error alerting
```

## 2.2 Component assessment

| Component | Implementation | Strength | Weakness |
|---|---|---|---|
| **JWT auth** | `JwtAuthMiddleware`, 135 lines; sets 6 request attributes incl. provenance | Provenance survives refresh rotation (session-level tagging) | Guards `web`/`api` both session-driver; `api` guard exists only so legacy `Auth::guard('api')` doesn't throw |
| **Admin auth** | `POST /api/admin/auth`, static `BELLA_ADMIN_TOKEN` → JWT as user 1 | `hash_equals` (timing-safe); provenance tagged | Shared secret · no MFA · no expiry · no rotation · **no individual attribution** · no dedicated throttle (login has `10,5`; this has none) |
| **Platform admin** | `AdminMiddleware`, single check `is_platform_admin` | Column not writable from any user-facing endpoint | Binary. No delegation, no scoping, no temporary elevation |
| **Authorization** | Middleware only | Consistently applied where present | **0 Gates · 0 Policies · 0 role tables · 0 permission tables**. A route that omits middleware has no second line of defence |
| **MFA** | `RequireMfaStepUp` — BOOTSTRAP / ACTIVATED / **DEGRADED (fail-closed, 423)** / RECOVERY | **Best control in the codebase.** Explicitly prevents "offboard an admin to disable MFA". Emergency revocation deliberately left outside | Protects **1 of 978 routes**. **0 of 11 users enrolled** ⇒ system sits in BOOTSTRAP and stands down |
| **DenyApiKeyAuth** | Refuses `api_key` + `shared_admin_token` | Docblock states the exact threat model | Applied to 45 routes; **absent from 48 mutating admin routes incl. all Bella routes** |
| **Audit logging** | `audit_logs` 7,557 rows; `task.created` 2,417, `task.executed` 1,984, `approval.approved` 109 | Rich, actively written, workspace/user/entity/metadata shape is generic | Invoked explicitly per call site (no events) ⇒ coverage inconsistent; **0 `bella_chat` rows** |
| **Approvals** | `ApprovalService` + `ApprovalPolicyRegistry` + `ApprovalAuthorizationService`; `approvals` 125 rows | **Second-best asset.** Per-capability classification, separation-of-duties for INFRA888, **fail-closed strict default** for unclassified capabilities, and self-service exemptions *explicitly flagged for product review* rather than silently grandfathered | Covers ~11 capabilities. Not wired to admin routes or Bella. `requester_actor_type` includes NULL |
| **Queues** | 4 priority queues, 3 Supervisor workers, `--max-time=3600`, autorestart | Idempotency keys + execution hash on `tasks` | **Failures never reach `failed_jobs`** ⇒ invisible to queue monitoring (INC-2026-001) |
| **Runtime authz** | `RuntimeSecretMiddleware` shared secret, 23 routes | Single boundary; `RuntimeClient` sanitises responses | Shared secret, no per-caller identity, no rotation schedule |
| **Bella authz** | `api → JwtAuth → AdminMiddleware` | Action allowlist is a closed `match` with `default => error`; `suspend_user` refuses platform admins; input validated (2,000 char / 20 turns); audit row per completion | **No `DenyApiKeyAuth`** · no approval · no MFA · no confirmation · **params are model-generated** · log content enters the prompt (indirect injection) · attribution collapses to user 1 |

## 2.3 Summary judgement

The platform has **excellent isolated governance components** — `RequireMfaStepUp`,
`DenyApiKeyAuth`, `ApprovalPolicyRegistry`, `LaunchScopePolicy` — and **no governance system**.
The parts exist; the wiring does not. P0 is predominantly an integration phase, not an invention
phase.

---

# SECTION 3 — TARGET GOVERNANCE ARCHITECTURE

## 3.1 Principal model

Three principal classes, twelve principals.

```
┌─ HUMAN PRINCIPALS ────────────────────────────────────────────────────┐
│                                                                       │
│  PLATFORM OWNER            ultimate authority · break-glass · cannot   │
│    │                       be suspended or demoted by any other        │
│    │                       principal · MFA mandatory                   │
│    ├── PLATFORM ADMINISTRATOR   full operational admin, minus owner-   │
│    │      │                     only ops · MFA mandatory               │
│    │      ├── ENGINEERING       deploy · runtime · infra · diagnostics │
│    │      │                     NO credits · NO billing · NO user      │
│    │      │                     deletion                               │
│    │      ├── SUPPORT           read-all · workspace assist · session  │
│    │      │                     revoke · NO financial mutation         │
│    │      ├── FINANCE           credits · billing · plans · refunds    │
│    │      │                     NO infra · NO user deletion            │
│    │      └── MARKETING         content · templates · campaigns        │
│    │                            NO customer data mutation              │
│    │                                                                   │
│    └── CUSTOMER (workspace owner)                                      │
│           └── WORKSPACE USER (owner / admin / member / viewer)         │
└───────────────────────────────────────────────────────────────────────┘

┌─ MACHINE PRINCIPALS ──────────────────────────────────────────────────┐
│  API        integration key (api_keys, type=connector)  — bounded      │
│  SERVICE    Railway runtime via X-LevelUp-Secret         — bounded     │
│  SYSTEM     scheduler / queue worker (29 commands)       — bounded     │
│  MACHINE    autonomous AI actor: Bella, Engineering888, Sarah          │
│             ⚠ MAY NEVER hold standing privileged authority.            │
│               Acts only through a governed execution path.             │
└───────────────────────────────────────────────────────────────────────┘
```

## 3.2 Principal ↔ authority matrix (target)

Legend: **R** read · **W** write · **A** approve · **O** override · **—** denied

| Capability domain | Owner | Platform Admin | Engineering | Support | Finance | Marketing | Customer | API | Service | System | Machine |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Platform configuration | RWO | RW | R | R | — | — | — | — | — | — | — |
| User create / update | RWO | RW | — | R | — | — | — | — | — | — | — |
| **User delete / suspend** | **RWO** | **RW+A** | — | — | — | — | — | — | — | — | **request only** |
| Session revoke | RWO | RW | R | RW | — | — | — | — | — | — | — |
| **Credit adjustment** | **RWO** | **RW+A** | — | R | **RW+A** | — | — | — | — | — | **request only** |
| Billing / plans / subscriptions | RWO | RW | — | R | RW | — | R (own) | — | — | — | — |
| Workspace ownership transfer | RWO | RW+A | — | — | — | — | — | — | — | — | — |
| **Infrastructure / hosting** | RWO | RW+A | RW+A | R | — | — | R (own) | — | — | — | **request only** |
| Domains / DNS / SSL | RWO | RW | RW | R | — | — | RW (own) | — | — | — | — |
| **Deployment / runtime config** | **RWO** | **RW** | **RW+A** | R | — | — | — | — | R | — | **request only** |
| AI provider / model routing | RWO | RW | RW | R | — | — | — | — | R | — | — |
| Agents / capabilities | RWO | RW | RW | R | — | — | — | — | — | — | — |
| Content / templates / media | RWO | RW | — | R | — | RW | RW (own) | RW (scoped) | — | — | RW (scoped) |
| **Database direct read** | **RWO** | **R+A** | **R+A** | — | — | — | — | — | — | — | **— DENIED** |
| Audit log read | RWO | R | R | R | R | — | — | — | — | — | R |
| Approve others' requests | A | A | A (eng only) | — | A (fin only) | — | A (own ws) | — | — | — | **— NEVER** |
| Break-glass / emergency | **O** | — | — | — | — | — | — | — | — | — | — |

**Three invariants:**
1. **No machine principal may approve anything** — including its own request.
2. **No machine principal holds standing privileged authority** — it requests; a human approves.
3. **The Platform Owner cannot be suspended, demoted, or deleted by any other principal.**
   (Partially present today: `actionSuspendUser` already refuses platform admins.)

## 3.3 Relationship to what exists

| Target concept | Existing foundation | Gap |
|---|---|---|
| Platform Owner | `users.is_platform_admin` (2 users), user 1 hardcoded in `/admin/auth` | No distinct owner tier |
| Platform Administrator | `AdminMiddleware` | Identical to owner today |
| Engineering / Support / Finance / Marketing | **none** | Entirely new |
| Workspace User | `workspace_users` (28 rows), `TeamRoleMiddleware`, `ApprovalPolicyRegistry` roles (owner/admin/member) | Exists and works |
| API | `api_keys` (3 rows, `type=connector`, `scopes`) | `scopes` column exists but 2 of 3 keys have it empty; one is `["*"]`; **no key has an expiry** |
| Service | `RuntimeSecretMiddleware` | Works; no rotation policy |
| System | 29 scheduled commands | Works; unauthenticated by design (CLI) |
| Machine | Bella, Sarah, agents | **No governed execution path** |

---

# SECTION 4 — BELLA SECURITY REDESIGN

## 4.1 The core defect

```
LLM output ──► extractActionBlock() ──► allowlist check (verb only) ──► executeAction(action, params)
                                                                        ▲
                                                        params are 100% model-generated
                                                        no confirmation · no approval · no MFA
```

The allowlist gates **what kind** of action runs. Nothing gates **what it runs on**. A model that
emits `{"action":"adjust_credits","params":{"workspace_id":7,"amount":999999}}` executes it.

## 4.2 Risk classification of all 12 actions

| # | Action | Class | Governance path (target) |
|---|---|---|---|
| 1 | `get_analytics` | **READ-ONLY** | Direct execute · audit |
| 2 | `get_queue` | **READ-ONLY** | Direct execute · audit |
| 3 | `get_engine_status` | **READ-ONLY** | Direct execute · audit |
| 4 | `generate_report` | **READ-ONLY** | Direct execute · audit |
| 5 | `get_audit_logs` | **LOW** | Direct execute · audit · rate-limit |
| 6 | `remember` / `forget` | **LOW** | Direct execute · audit · scoped to Bella's own memory |
| 7 | `list_users` | **MEDIUM** (PII) | Direct execute · audit · **field allowlist** (never `password`, `mfa_*`) |
| 8 | `get_workspace` | **MEDIUM** (cross-tenant) | Direct execute · audit · workspace named in response |
| 9 | **`adjust_credits`** | **HIGH — financial** | **Propose → human approval → MFA → execute → event → audit** |
| 10 | **`suspend_user`** | **HIGH — customer impact** | **Propose → human approval → MFA → execute → event → audit** |
| 11 | **`query_database`** | **CRITICAL** | **DENY by default.** If ever enabled: table+column allowlist, tenant scoping, owner-only, MFA, dry-run mandatory |
| 12 | *(future)* any new action | **CRITICAL by default** | **Fail-closed** — unclassified ⇒ denied |

## 4.3 Target execution model

```
Admin message
   │
   ▼
[1] INTENT VALIDATION ─── deterministic pre-parse, before the LLM
       reject prompt-injection markers · cap length · strip control chars
   │
   ▼
[2] LLM CALL (RuntimeClient → runtime → DeepSeek)
       ⚠ context hardening: STOP injecting raw laravel.log lines (B-2).
         Replace with structured, sanitised error counts.
   │
   ▼
[3] ACTION EXTRACTION
       allowlist check (verb)  ──►  fail-closed on unknown
   │
   ▼
[4] PARAMETER VALIDATION ─── NEW. Per-action schema, server-side.
       type · range · bounds (e.g. |amount| ≤ N) · existence · tenancy
       ⚠ Model output is UNTRUSTED INPUT and is validated as such.
   │
   ▼
[5] RISK CLASSIFICATION ─── §4.2 table
   │
   ├─ READ-ONLY / LOW / MEDIUM ──► [7] EXECUTE
   │
   └─ HIGH / CRITICAL
         │
         ▼
   [6] GOVERNANCE GATE
         ├─ create Approval row (ApprovalService::requestIfNeeded)
         │     requester_actor_type = 'machine'  ← NEW actor type
         │     self_approval_allowed = FALSE (always, for machines)
         ├─ DRY-RUN: compute and return the exact effect, execute nothing
         ├─ human reviews the dry-run and approves
         ├─ RequireMfaStepUp on the approval endpoint
         └─ SAFE MODE flag: if BELLA_SAFE_MODE=true → propose only, never execute
         │
         ▼
   [7] EXECUTE  ──► [8] EMIT EVENT ──► [9] AUDIT (actor, approver, params, before/after)
                                        └─► [10] ROLLBACK TOKEN where reversible
```

## 4.4 Controls specified

| Control | Requirement |
|---|---|
| **Action execution** | Only through the pipeline above. No direct `DB` facade mutation from the controller. |
| **Approval workflow** | Reuse `ApprovalService` + `ApprovalPolicyRegistry`. Add capability keys `bella.adjust_credits`, `bella.suspend_user`. Machine requester ⇒ `self_approval_allowed=false` unconditionally. |
| **Confirmation** | HIGH/CRITICAL require an explicit human confirm on a rendered dry-run, not a chat message. |
| **Parameter validation** | Per-action schema. `adjust_credits`: integer, hard ceiling, workspace must exist, not a house account without extra approval. `suspend_user`: user exists, not platform admin (already present), not self. |
| **Intent validation** | Deterministic pre-filter before the LLM; reject known injection markers. |
| **Policy enforcement** | Fail-closed: any action not explicitly classified is denied. |
| **Audit logging** | Every attempt (proposed / approved / rejected / executed / failed), with actor, approver, full params, before/after state. `bella_chat` alone is insufficient. |
| **Privileged ops** | `DenyApiKeyAuth` on all four Bella routes. Shared admin token must not drive mutations — the codebase's own docblock already requires this. |
| **MFA** | `RequireMfaStepUp` on the Bella approval endpoint. |
| **Platform Owner controls** | Kill switch (`BELLA_ENABLED`), safe mode (`BELLA_SAFE_MODE`), per-action enable flags, and a rate limit on `/api/admin/bella`. |
| **Dry-run** | Mandatory for HIGH/CRITICAL; available on demand for all. |
| **Safe mode** | Default-on until Bella has a reviewed operating history. |
| **Rollback** | `adjust_credits` is reversible via a compensating `credit_transactions` row; `suspend_user` via status restore. Record a rollback token per mutation. |
| **`query_database`** | **Explicit decision required.** Recommendation: **remove the action**. Its inertness is a Laravel 11 accident, not a control, and the audit path already provides governed read access. |

## 4.5 Isolation posture

Bella today reaches **14+ tables directly via the `DB` facade**, bypassing
`EngineExecutionService`, `LaunchScopePolicy`, `ApprovalService`, and credit-reservation logic.
**Target: Bella becomes a consumer of engine services, not a parallel data-access layer.** This is
the single change that converts it from architecturally adjacent to architecturally integrated.

---

# SECTION 5 — AUTHORIZATION FRAMEWORK

## 5.1 Options considered

| Option | Fit | Verdict |
|---|---|---|
| **A. Laravel Policies** | Model-centric (`UserPolicy`, `WorkspacePolicy`) | **Partial.** Many privileged ops are not model-scoped (`updateConfig`, `purgeFailedJobs`, deployments). Good for the ~40% that are. |
| **B. Laravel Gates** | Named ability checks — `Gate::allows('credits.adjust', $ws)` | **Strong.** Matches the capability-key idiom already used by `ApprovalPolicyRegistry` and `AgentCapabilityService`. |
| **C. Full RBAC package** (e.g. Spatie) | Roles + permissions tables + UI | **Rejected.** Adds a dependency and a second authority vocabulary alongside `agent_capabilities`, `ApprovalPolicyRegistry`, `workspace_users.role`, and `LaunchScopePolicy`. Risk of four competing models. |
| **D. Extend middleware only** | Add more middleware classes | **Rejected.** This is the status quo that produced 48 unguarded mutating routes. Route-level-only protection has no second line of defence. |
| **E. HYBRID — Gates + capability registry + Policies where model-scoped** | — | **RECOMMENDED** |

## 5.2 Recommendation — Hybrid (Option E)

**Rationale, grounded in what already exists:**

1. **The capability-key idiom is already the platform's native vocabulary.**
   `ApprovalPolicyRegistry` keys on `infrastructure.provision_hosting`, `builder.publish_website`.
   `agent_capabilities` holds 381 rows. `LaunchScopePolicy` enumerates removed engines/actions/
   tools. A Gate-based capability model **extends an existing pattern** instead of importing a
   competing one.

2. **`ApprovalPolicyRegistry` already proves the design works.** It is per-capability, fail-closed
   for unclassified capabilities, and it documents *why* each exemption exists rather than
   grandfathering silently. That is exactly the property an authorization registry needs.

3. **Gates give the missing second line of defence.** Today a route that omits `AdminMiddleware`
   is simply unprotected. With `Gate::authorize()` inside the controller action, a routing mistake
   fails closed.

4. **Policies where models exist.** `UserPolicy`, `WorkspacePolicy`, `CreditPolicy`,
   `ApiKeyPolicy` cover the model-scoped subset cleanly.

**Proposed shape:**

```
PermissionRegistry (new, mirrors ApprovalPolicyRegistry's structure)
  'credits.adjust'        => [roles: owner, platform_admin, finance   | mfa: true  | approval: true ]
  'user.delete'           => [roles: owner, platform_admin            | mfa: true  | approval: true ]
  'user.suspend'          => [roles: owner, platform_admin, support   | mfa: false | approval: true ]
  'platform.config.write' => [roles: owner, platform_admin            | mfa: true  | approval: false]
  'deployment.execute'    => [roles: owner, platform_admin, engineering| mfa: true | approval: true ]
  'database.read_direct'  => [roles: owner                            | mfa: true  | approval: true ]
  ...
  DEFAULT (unclassified)  => DENY   ← fail-closed, same principle as STRICT_DEFAULT
```
Backed by: `roles` (new table), `user_roles` (new pivot), and Gates registered from the registry
in a service provider. `is_platform_admin` is retained as the **Platform Owner/Admin bootstrap**
so nothing breaks on day one.

## 5.3 Required mechanisms

| Mechanism | Design |
|---|---|
| **Platform-owner override** | Owner may override any denial. Override is a distinct, always-audited, event-emitting action — never a silent bypass. |
| **Delegation** | An owner/admin may grant a role for a bounded scope. Delegation is itself an approvable, audited, expiring act. |
| **Temporary elevation** | Time-boxed grant (default 60 min) requiring MFA at grant time and auto-expiring. Modelled on `RequireMfaStepUp`'s existing RECOVERY window. |
| **Emergency access** | Break-glass: owner-only, MFA required, fixed short window, emits a high-priority event, and — following the existing MFA middleware's precedent — **credential revocation and provider disable remain reachable in every state.** |

---

# SECTION 6 — SENSITIVE OPERATIONS REGISTER

Derived from live route enumeration. **64 mutating admin routes**; only **16 hardened**, **1 MFA**.

Classification: **R** read · **W** write · **A** approve · **O** override · **D** dangerous ·
**C** critical. "Guard today" = middleware actually attached.

## 6.1 Users & identity

| Operation | Route / method | Class | Guard today | Target |
|---|---|---|---|---|
| Create user | `POST /api/admin/users` → `createUser` | W · D | admin | +approval |
| Update user | `PUT /api/admin/users/{id}` → `updateUser` | W · D | admin | +approval |
| **Delete user** | `DELETE /api/admin/users/{id}` → `deleteUser` | **C** | admin | **+approval +MFA +hardened** |
| **Suspend user** | `POST /api/admin/users/{id}/suspend` | **D** | admin | **+approval +hardened** |
| **Suspend user (Bella)** | `bella.suspend_user` | **D** | **none** | **+approval +MFA +hardened** |
| Revoke session | `POST /api/admin/sessions/{id}/revoke` | W | admin | +hardened |
| **Revoke API key** | `POST /api/admin/api-keys/{id}/revoke` | **D** | admin | +hardened |
| Update membership | `PUT /api/admin/memberships/{id}` | W · D | admin | **+approval** (workspace access) |
| MFA enrol / confirm / verify / recovery-codes | 4 × `POST /api/admin/mfa/*` | W | **hardened** ✅ | unchanged |

## 6.2 Credits & billing

| Operation | Route / method | Class | Guard today | Target |
|---|---|---|---|---|
| **Adjust workspace credits** | `POST /api/admin/workspaces/{id}/credits` → `adjustCredits` | **C — financial** | admin | **+approval +MFA +hardened** |
| **Adjust credits (Bella)** | `bella.adjust_credits` | **C — financial** | **none** | **+approval +MFA +hardened +bounds** |
| **House account top-up** | `POST /api/admin/house-accounts/{id}/top-up` | **C — financial** | admin | **+approval +MFA** |
| House account settings | `PUT /api/admin/house-accounts/{id}/settings` | W · D | admin | +approval |
| Assign plan | `POST /api/admin/workspaces/{id}/plan` → `assignPlan` | W · D | admin | +approval |
| Create / update plan | `POST /api/admin/plans`, `PUT /api/admin/plans/{id}` | W · D | admin | +approval |

## 6.3 Platform configuration

| Operation | Route / method | Class | Guard today | Target |
|---|---|---|---|---|
| **Update platform config** | `POST /api/admin/config` → `updateConfig` | **C** | admin | **+approval +MFA +hardened** |
| Update engine capabilities | `PUT /api/admin/engines/capabilities` | W · D | admin | +approval |
| Update agent | `PUT /api/admin/agents/{id}` | W · D | admin | +hardened |
| Grant / revoke agent capability | `POST`/`DELETE /api/admin/agents/{slug}/capabilities[/{toolId}]` | **D** | admin | **+approval** |

## 6.4 Infrastructure, hosting, domains, DNS, SSL

| Operation | Route | Class | Guard today | Target |
|---|---|---|---|---|
| Create provider | `POST /api/admin/infrastructure/providers` | W · D | **hardened** ✅ | +MFA |
| **Approve provider request** | `POST .../approvals/{aid}/approve` | **A · C** | **hardened + MFA** ✅ | **reference implementation** |
| Store / verify / rotate / revoke credential | 4 × `POST .../credentials/*` | **C** | hardened ✅ | +MFA on revoke |
| Disable provider / capability | 2 × `POST .../disable*` | **D** | hardened ✅ | unchanged |
| Move to testing · probe · declare capability · request activation | 5 × `POST` | W | hardened ✅ | unchanged |
| Create hosting | `POST /api/infrastructure/hosting` | W · D | hardened | +approval |
| Domains / DNS / SSL | 4 mutating routes | W · D | 3 user, **1 OPEN** | **classify + guard** |

## 6.5 Deployment & runtime

| Operation | Reality | Class | Guard today | Target |
|---|---|---|---|---|
| **Runtime deploy (Railway)** | **Outside Laravel entirely.** No CLI/token in the environment; Boss deploys manually | **C** | **none in-platform** | Record as an out-of-band governed act; log a deployment event |
| Force task state transition | `POST /api/admin/orchestration/transition` | **D** | admin | **+approval +hardened** |
| Recover orphans / recover stale | 2 × `POST` | W | admin | +hardened |
| Retry / cancel task | `POST /api/admin/tasks/{id}/{retry,cancel}` | W | admin | +hardened |
| **Purge failed jobs** | `POST /api/admin/failed-jobs/purge` | **D** (destroys evidence) | admin | **+approval +hardened** |
| Delete / retry failed job | `DELETE`/`POST /api/admin/failed-jobs/{id}[/retry]` | W · D | admin | +hardened |

## 6.6 AI providers & agents

| Operation | Reality | Class | Guard today | Target |
|---|---|---|---|---|
| Model selection | `deepseek-models.js` in the **runtime**, env-overridable | **C** | Railway env only | Governed deploy + event |
| Provider fallback policy | `index.js` `/ai/run` | **D** | none | Owner decision + event |
| `config/llm.php` | **stale — names retired `deepseek-chat`** | R | — | Correct or annotate |
| Bella chat | `POST /api/admin/bella` | **D** | admin | **+hardened +rate-limit +safe-mode** |

## 6.7 Email & mass communication

| Operation | Route | Class | Guard today | Target |
|---|---|---|---|---|
| **Broadcast notification** | `POST /api/admin/notifications/broadcast` | **D — mass comms** | admin | **+approval +hardened** |
| Email templates CRUD | 4 × `/api/admin/email-templates*` | W | admin | +hardened |
| Email tracking pixels | 4 public routes | R | **open by design** | rate-limit review |

## 6.8 Database & data

| Operation | Reality | Class | Guard today | Target |
|---|---|---|---|---|
| **Bella `query_database`** | Arbitrary SELECT, no table/column restriction, no tenant scoping. **Inert via Laravel 11 `TypeError`** | **C** | **none** | **Remove, or owner-only + allowlist + MFA + dry-run** |
| Media bulk delete | `POST /api/admin/media/bulk-delete` | **D** | admin | +approval |
| Purge expired knowledge | `POST /api/admin/knowledge/{wsId}/purge-expired` | W · D | admin | +hardened |

## 6.9 Aggregate

| Domain | Mutating routes | Guarded today |
|---|---|---|
| email | 40 | 34 user · 4 admin · **2 OPEN** |
| ai/provider/agents | 30 | 22 user · 8 admin |
| users/identity | 27 | 9 user · 6 admin · 4 hardened · **8 OPEN** |
| hosting/infra | 15 | **13 hardened · 1 hardened+MFA** ✅ |
| credits/billing | 14 | 9 user · 4 admin · **1 OPEN** |
| database/config | 13 | 9 user · 3 admin · **1 OPEN** |
| workspace | 11 | 10 user · 1 admin |
| deployment/runtime | 11 | 10 user · **1 OPEN** |
| domains/DNS/SSL | 4 | 3 user · **1 OPEN** |

> "OPEN" counts include intentionally public endpoints (auth, invites, tracking pixels, public
> chatbot). Each must be **classified explicitly**, not assumed benign — that classification is a
> P0-A deliverable.

---

# SECTION 7 — EVENT ARCHITECTURE

## 7.1 Why

`app/Events/`, `app/Listeners/`, `app/Observers/` are **all empty**. Audit logging is invoked
explicitly at each call site, which is why coverage is inconsistent — and why a 42-hour outage
produced 904 `task.execution_failed` audit rows while `failed_jobs` stayed at 0 and nothing paged.

**Governance requires that consequential actions announce themselves rather than being remembered
about.**

## 7.2 Event model

```
GovernanceEvent (abstract)
  ├─ event_id           uuid
  ├─ occurred_at        timestamp
  ├─ actor_type         owner|platform_admin|engineering|support|finance|
  │                     marketing|customer|workspace_user|api|service|system|machine
  ├─ actor_id           user id · api_key id · agent slug
  ├─ on_behalf_of       nullable — for machine actors
  ├─ auth_via           jwt|api_key|shared_admin_token|runtime_secret|cli
  ├─ workspace_id       nullable
  ├─ capability_key     e.g. 'credits.adjust'
  ├─ risk_class         read|low|medium|high|critical
  ├─ approval_id        nullable FK → approvals
  ├─ mfa_verified       bool
  ├─ payload_before     nullable
  ├─ payload_after      nullable
  ├─ rollback_token     nullable
  └─ correlation_id     ties a request → approval → execution → outcome
```

## 7.3 Event catalogue

| Domain | Events |
|---|---|
| **Identity** | `UserCreated` · `UserUpdated` · `UserSuspended` · `UserDeleted` · `SessionRevoked` · `ApiKeyRevoked` · `MembershipChanged` |
| **Financial** | `CreditsAdjusted` · `HouseAccountToppedUp` · `PlanAssigned` · `PlanChanged` · `SubscriptionChanged` |
| **Workspace** | `WorkspaceCreated` · `WorkspaceUpdated` · `WorkspaceDeleted` · `WorkspaceOwnershipTransferred` |
| **Governance** | `ApprovalRequested` · `ApprovalGranted` · `ApprovalRejected` · `ApprovalExpired` · `PolicyDenied` · `OwnerOverrideUsed` · `EmergencyAccessGranted` · `TemporaryElevationGranted` · `TemporaryElevationExpired` |
| **AI / machine** | `BellaActionRequested` · `BellaActionApproved` · `BellaActionRejected` · `BellaActionExecuted` · `BellaActionFailed` · `MachineActionDenied` · `PromptInjectionSuspected` |
| **Deployment / runtime** | `DeploymentStarted` · `DeploymentCompleted` · `DeploymentFailed` · `RuntimeModelChanged` · `ProviderFallbackEngaged` · `ProviderErrorThresholdBreached` |
| **Infrastructure** | `ProviderCreated` · `ProviderDisabled` · `CredentialStored` · `CredentialRotated` · `CredentialRevoked` · `HostingProvisioned` · `DomainConnected` · `SslIssued` |
| **Engineering888** *(reserved)* | `EngineeringTaskCreated` · `EngineeringTaskCompleted` · `EngineeringTaskFailed` · `EngineeringActionRequested` |
| **Platform** | `PlatformConfigChanged` · `CapabilityGranted` · `CapabilityRevoked` · `FailedJobsPurged` |

## 7.4 Consumers

| Listener | Purpose |
|---|---|
| `AuditLogListener` | Universal — replaces per-call-site audit invocation |
| `AlertingListener` | **Closes the INC-2026-001 gap**: thresholds on `ProviderErrorThresholdBreached`, any `ProviderFallbackEngaged`, `PromptInjectionSuspected` |
| `NotificationListener` | Reuses `NotificationService` / `PushDispatcherService` |
| `MetricsListener` | Counters for the readiness scorecard |
| `EngineeringTelemetryListener` *(reserved)* | Engineering888 consumes the same stream — **no new plumbing needed** |

**Design constraints:** events are emitted **after** successful commit (no phantom events);
listeners are queued except audit (synchronous, must not be lost); events are additive and never
replace `audit_logs`, which remains the legal record.

---

# SECTION 8 — APPROVAL FRAMEWORK

## 8.1 Foundation that already exists

`ApprovalService` (approve / reject / revise / `requestIfNeeded`) · `ApprovalPolicyRegistry`
(per-capability, fail-closed `STRICT_DEFAULT`) · `ApprovalAuthorizationService` · `approvals`
table (125 rows, 19 columns incl. `requester_actor_type`, `decision_actor_type`, `capability_key`,
`approval_policy_json`). Real usage across `strategy`, `content`, `builder`, `infrastructure`,
`crm`, `marketing`, `write`, `social`. **109 `approval.approved` and 8 `approval.rejected` audit
rows.**

**This is a working system. P0 extends its coverage; it does not build a new one.**

## 8.2 Where approval becomes mandatory

| Operation | Approval | Self-approval | MFA | Rationale |
|---|---|---|---|---|
| Credit adjustment (admin or Bella) | **Yes** | **No** | **Yes** | Financial mutation |
| House account top-up | **Yes** | No | Yes | Financial |
| User deletion | **Yes** | No | **Yes** | Irreversible |
| User suspension | **Yes** | No | No | Customer impact, reversible |
| Workspace deletion / ownership transfer | **Yes** | No | Yes | Irreversible / authority change |
| Plan assignment / plan change | **Yes** | No | No | Commercial |
| Platform config change | **Yes** | No | **Yes** | Blast radius |
| Provider create / credential revoke | **Yes** ✅ | No ✅ | Yes | *Already implemented — the model* |
| Hosting provisioning | **Yes** ✅ | No ✅ | — | *Already implemented* |
| Deployment (runtime) | **Yes** | No | Yes | Currently out-of-band |
| Purge failed jobs | **Yes** | No | No | Destroys evidence |
| Force task transition | **Yes** | No | No | Bypasses the state machine |
| Broadcast notification | **Yes** | No | No | Mass communication |
| Agent capability grant/revoke | **Yes** | No | No | Authority change |
| **Any Bella HIGH/CRITICAL action** | **Yes** | **Never** | **Yes** | Machine principal |
| **Any Engineering888 mutating action** | **Yes** | **Never** | Per risk class | Machine principal |
| Self-service publishing | Confirm only ✅ | Yes ✅ | No | *Existing, deliberately flagged for product review* |

## 8.3 Required extensions

1. **`machine` actor type** — `requester_actor_type` currently holds `NULL`, `'system'`, `'user'`.
   Machines need a distinct, non-approving class.
2. **Machine invariant** — `self_approval_allowed = false` unconditionally for machine requesters,
   regardless of capability classification.
3. **Dry-run payload** — `approvals.data_json` carries the computed effect so a human approves a
   concrete change, not an intention.
4. **Expiry** — pending approvals must expire (there are 3 pending with no expiry today).
5. **Approval events** — emit `ApprovalRequested/Granted/Rejected/Expired`.
6. **Resolve the NULL actor type** — classify the 78 rows with empty engine/action before relying
   on this table for governance decisions.

---

# SECTION 9 — ENGINEERING888 DEPENDENCIES

## 9.1 MANDATORY — Engineering888 cannot safely begin without these

| # | Dependency | Why blocking | Evidence |
|---|---|---|---|
| M1 | **Role/permission model** | Engineering888 needs an *Engineering* authority that is not full platform admin. Today only `is_platform_admin` exists — granting it means granting everything | 0 Gates, 0 Policies, 0 role tables |
| M2 | **Machine principal class + governed execution path** | Engineering888 is a machine actor that will mutate infrastructure. Without §4's pipeline it repeats Bella's defect at greater scale | Bella executes model-generated params |
| M3 | **Approval extension to machine actors** | `requester_actor_type` has no machine class; self-approval must be structurally impossible | `approvals` actor types: NULL, system, user |
| M4 | **Event backbone** | Engineering telemetry, audit, and alerting all depend on it. Without events, every listener is a per-call-site invocation — the pattern that already produced inconsistent coverage | `app/Events`, `app/Listeners`, `app/Observers` all empty |
| M5 | **Provider-error alerting** | Engineering888 acts on the runtime that failed silently for 42 h. It must not be the thing that notices too late | `failed_jobs`=0 during INC-2026-001 |
| M6 | **MFA enrolment for both platform admins** | The fail-closed machine is built and correct but stands down in BOOTSTRAP with 0 enrolled users | 0 of 11 users `mfa_enabled=1` |
| M7 | **`DenyApiKeyAuth` on privileged admin routes** | Otherwise a shared static token can drive Engineering888-adjacent operations with no attribution | 48 of 64 mutating admin routes lack it |
| M8 | **Bella disposition decision** | Two ungoverned autonomous agents is strictly worse than one. Bella must be governed, gated, or retired **before** a second is built | Bella dormant but reachable |

## 9.2 RECOMMENDED — materially safer, not strictly blocking

| # | Dependency | Value |
|---|---|---|
| R1 | Sensitive Operations Register ratified (§6) | Engineering888 inherits a classified surface instead of re-deriving it |
| R2 | Approval dry-run payloads | Engineering actions are high-consequence; approving an intention is weak |
| R3 | Temporary elevation | Lets Engineering888 hold *no* standing authority and request time-boxed elevation |
| R4 | Admin/Bella test coverage (currently zero) | Governance without tests regresses silently |
| R5 | Provider attribution + model-keyed pricing (TD-01/02/03) | Engineering888 cost attribution will otherwise be wrong at birth |
| R6 | Replay/disposition of 213 failed + 63 blocked tasks | Engineering888 will inherit an unexplained failure backlog |

## 9.3 OPTIONAL — can follow Engineering888

| # | Item |
|---|---|
| O1 | `routes/api.php` decomposition (19,363 lines) |
| O2 | `.bak` archival (508 files / 81 MB) |
| O3 | `traffic_logs` index review |
| O4 | Runtime git repository + lockfile |
| O5 | `/health` version bump; `config/llm.php` correction |
| O6 | Bella front-end |

## 9.4 Dependency graph

```
                       ┌──────────────────────┐
                       │  P0-A  Classify      │  (no code: registers + decisions)
                       └──────────┬───────────┘
                                  ▼
     ┌────────────────────────────┴────────────────────────────┐
     ▼                            ▼                            ▼
┌──────────┐              ┌──────────────┐            ┌────────────────┐
│ P0-B     │              │ P0-C         │            │ P0-D           │
│ Coverage │              │ Events +     │            │ Roles + Gates  │
│ (Deny+   │              │ Alerting     │            │ (M1)           │
│  MFA)    │              │ (M4, M5)     │            │                │
│ M6,M7    │              └──────┬───────┘            └───────┬────────┘
└────┬─────┘                     │                            │
     │                           └────────────┬───────────────┘
     │                                        ▼
     │                             ┌─────────────────────┐
     └────────────────────────────►│ P0-E  Machine       │
                                   │ governance + Bella  │
                                   │ (M2, M3, M8)        │
                                   └──────────┬──────────┘
                                              ▼
                                   ┌─────────────────────┐
                                   │ P0-F  Verify        │
                                   └──────────┬──────────┘
                                              ▼
                                   ╔═════════════════════╗
                                   ║  ENGINEERING888     ║
                                   ╚═════════════════════╝
```

---

# SECTION 10 — MIGRATION STRATEGY

Six phases. Every phase is **additive and reversible**. No phase refactors existing engines.

## P0-A — Classification & Decisions *(no code)*
**Scope:** ratify the Sensitive Operations Register; classify all 95 unauthenticated routes;
decide Bella's disposition; decide `query_database`; decide the task-replay policy; define the
role vocabulary; obtain V4 pricing.
**Deliverables:** signed register · role definitions · three Boss decisions.
**Risk:** None (no code). **Dependencies:** none. **Rollback:** n/a.
**Complexity:** Low. **Blocking for:** every later phase.

## P0-B — Coverage of Existing Controls *(lowest risk, highest immediate value)*
**Scope:** attach `DenyApiKeyAuth` to privileged admin routes; enrol MFA for both platform admins
(moves governance BOOTSTRAP → ACTIVATED); extend `RequireMfaStepUp` to the highest-risk mutations;
add a dedicated throttle to `/api/admin/auth`; rate-limit `/api/admin/bella`.
**Risk:** **Medium — this is the phase most likely to break a working admin flow.** Attaching
`DenyApiKeyAuth` will 403 any tooling currently using the shared admin token; enrolling MFA
activates a fail-closed state machine.
**Mitigations:** inventory shared-token consumers first; enrol **both** admins before activation
(activating with one triggers DEGRADED/423); stage route-by-route.
**Dependencies:** P0-A. **Rollback:** remove middleware from the route list; MFA enrolment is
reversible but returns governance to BOOTSTRAP.
**Complexity:** Low-Medium. **New code:** minimal — uses controls that already exist.

## P0-C — Event Backbone & Alerting
**Scope:** `app/Events` + `app/Listeners`; `GovernanceEvent` base; `AuditLogListener`;
`AlertingListener` watching `tasks.error_text` / `task_events` (**not** `failed_jobs`); emit the
Governance and Financial event families first.
**Risk:** Low — purely additive; existing audit calls remain until listeners are proven.
**Dependencies:** P0-A. **Rollback:** stop dispatching; nothing else depends on it yet.
**Complexity:** Medium. **Closes:** M4, M5, and the INC-2026-001 detection gap.

## P0-D — Authorization Framework
**Scope:** `roles` + `user_roles` tables (additive migrations — 210 already applied);
`PermissionRegistry` modelled on `ApprovalPolicyRegistry`; Gates registered from it; Policies for
`User`, `Workspace`, `Credit`, `ApiKey`; `Gate::authorize()` inside privileged controller actions
as the second line of defence. `is_platform_admin` retained as the owner/admin bootstrap.
**Risk:** **Medium-High — the largest surface.** A wrong Gate denies a working operation.
**Mitigations:** ship in **shadow mode first** — evaluate and log the decision without enforcing,
compare against actual middleware outcomes, then enforce. This mirrors the Studio Phase I–L
shadow-mode pattern already proven in this codebase.
**Dependencies:** P0-A, P0-C (for `PolicyDenied` events).
**Rollback:** feature flag disables enforcement; shadow logging continues.
**Complexity:** **High.** **Closes:** M1.

## P0-E — Machine Governance & Bella
**Scope:** `machine` actor type in `approvals`; machine invariant (`self_approval_allowed=false`);
Bella pipeline per §4.3 — intent validation, parameter schemas, risk classification, dry-run, safe
mode, kill switch; route `adjust_credits`/`suspend_user` through `ApprovalService`; remove raw log
injection from the prompt; resolve `query_database` per the P0-A decision.
**Risk:** Low-Medium — **Bella has never executed, so there is no production behaviour to break.**
This is the safest phase to change aggressively.
**Dependencies:** P0-C, P0-D, and the P0-A Bella decision.
**Rollback:** `BELLA_ENABLED=false` restores the status quo exactly (dormant).
**Complexity:** Medium-High. **Closes:** M2, M3, M8.

## P0-F — Verification & Certification
**Scope:** governance test suite (admin + Bella currently have **zero** tests); prove each
Mandatory dependency; re-score enterprise readiness; produce the Engineering888 go/no-go.
**Risk:** Low. **Dependencies:** P0-B…E. **Rollback:** n/a.
**Complexity:** Medium. **Closes:** R4.

## Sequencing rationale
P0-B first because it delivers real risk reduction using controls that **already exist and are
already proven** — no new architecture. P0-D is deliberately late and shadow-first because it is
the only phase that can break working authorization at scale. P0-E is late but low-risk precisely
because Bella is dormant.

---

# SECTION 11 — RISK REGISTER

Severity = f(Likelihood, Impact). "Likelihood" is assessed **as the platform stands today**.

| ID | Governance issue | Likelihood | Impact | **Severity** | Business risk | Technical risk | Operational risk |
|---|---|---|---|---|---|---|---|
| G-01 | Bella executes model-generated params on financial/customer mutations | Low *(never executed)* | Critical | **CRITICAL** | Unauthorised credit issuance; customer suspension | Prompt injection → privileged execution | No detection path today |
| G-02 | `query_database` re-armed by a routine-looking fix | Medium | Critical | **CRITICAL** | Full customer-data exposure incl. password hashes, MFA secrets, API keys | Denylist over unrestricted SQL, no tenant scoping | Failure would be silent |
| G-03 | No role model — authority is all-or-nothing | Certain | High | **CRITICAL** | Cannot delegate; cannot hire into ops safely | Blocks Engineering888 (M1) | Every admin action is maximum-privilege |
| G-04 | Provider/task failures invisible to monitoring | Certain *(occurred)* | High | **HIGH** | 42 h customer-facing outage already happened | `failed_jobs` bypassed | Detection depended on unrelated manual work |
| G-05 | Shared admin token ⇒ platform-owner authority, no attribution | Medium | High | **HIGH** | No forensic answer to "who did this" | Static secret, no expiry/rotation | Undermines the entire audit trail |
| G-06 | 48 of 64 mutating admin routes lack `DenyApiKeyAuth` | Certain | High | **HIGH** | Credits/users/config reachable by shared token | Inconsistent control application | — |
| G-07 | MFA stands down (0 enrolled) despite a correct fail-closed machine | Certain | High | **HIGH** | No second factor on any privileged op | Governance stuck in BOOTSTRAP | One credential compromise = full control |
| G-08 | No event architecture | Certain | Medium | **HIGH** | Cannot evidence governance to an enterprise buyer | Per-call-site audit ⇒ gaps | Alerting cannot be built cleanly |
| G-09 | Indirect prompt injection via log lines in Bella's prompt | Low | High | **MEDIUM** | Attacker influence without contacting Bella | Untrusted data in a privileged prompt | — |
| G-10 | Machine actors can self-approve (no machine class) | Certain *(if enabled)* | High | **MEDIUM** | Approval theatre | `requester_actor_type` lacks machine | — |
| G-11 | Approvals never expire; 3 pending; 78 rows with empty engine/action | Certain | Medium | **MEDIUM** | Stale authority | Data-quality gap in the governance table | — |
| G-12 | Cost/margin figures wrong (V3 rates, provider-keyed) | Certain | Medium | **MEDIUM** | Margin decisions on wrong numbers | TD-01/02/03 | — |
| G-13 | Zero admin/Bella test coverage | Certain | Medium | **MEDIUM** | Governance regressions ship silently | 60 tests, none on admin | — |
| G-14 | `.env.bak-gsc-creds-20260612` holds credential material | Low *(not web-reachable)* | Medium | **MEDIUM** | Credential exposure if the host is breached | Plaintext on disk | — |
| G-15 | 213 failed + 63 blocked tasks unresolved since 2026-05-08 | Certain | Low-Med | **MEDIUM** | Unfulfilled customer work incl. a paying workspace | No replay path | Backlog grows |
| G-16 | Runtime deploy is manual, out-of-band, unattributed | Certain | Medium | **MEDIUM** | Deployment cannot be governed or evidenced | No CLI/token; no git repo | Bus-factor of one |
| G-17 | `/api/admin/auth` lacks a dedicated throttle | Low | Medium | **LOW** | Brute force on a static secret | Only the global throttle | — |
| G-18 | `config/llm.php` names a retired model | Certain | Low | **LOW** | — | Trap for future engineers | — |

---

# SECTION 12 — ENTERPRISE READINESS PROJECTION

## 12.1 Projected scores

| Subsystem | Now | After P0 | Δ | What moves it |
|---|---|---|---|---|
| Architecture | 6.5 | **7.5** | +1.0 | Event backbone; governed machine path. *(Route monolith stays — out of scope)* |
| **Security** | 5.0 | **7.5** | **+2.5** | MFA active; `DenyApiKeyAuth` coverage; Bella governed; injection surface closed |
| **Authorization** | 3.5 | **8.0** | **+4.5** | Roles + Gates + Policies + delegation + elevation + break-glass |
| Maintainability | 4.0 | **4.5** | +0.5 | Registries add clarity; monolith and `.bak` untouched by design |
| Scalability | 6.0 | **6.0** | 0 | Not addressed in P0 |
| **Observability** | 5.0 | **7.5** | **+2.5** | Events + alerting; INC-2026-001 detection gap closed |
| Extensibility | 7.0 | **8.5** | +1.5 | Capability registry + events = clean Engineering888 insertion |
| Documentation | 7.5 | **8.5** | +1.0 | Governance model documented and ratified |
| Operational maturity | 6.0 | **7.5** | +1.5 | Approval coverage; alerting; classified operations |

## 12.2 Projected overall

# **5.4 → 7.7 / 10**

**Why not higher:** P0 deliberately excludes the 19,363-line route monolith, 508 `.bak` files,
`traffic_logs` indexing, runtime version control, and scalability headroom (1.9 GiB RAM, 74% disk).
Those cap Maintainability at ~4.5 and Scalability at 6.0. **7.7 is the honest ceiling for a
governance-only phase.**

**What 7.7 means:** the platform becomes **governable and defensible** — able to answer *who did
what, under what authority, approved by whom, and how would we know if it went wrong.* It does not
become fully enterprise-mature; that requires the deferred maintainability and scalability work.

## 12.3 If P0 is skipped

Engineering888 would be built on: one authority level, no event stream, no machine governance, no
active MFA, and an ungoverned precedent (Bella). The predictable outcome is a **second completed,
un-enablable autonomous system** — the exact pattern already demonstrated.

---

# FINAL EXECUTIVE RECOMMENDATION

## Recommendation: **APPROVE Phase P0 — proceed P0-A immediately.**

**The finding that justifies this phase is not that the platform is insecure. It is that the
platform's own best security engineering already states the rule that Bella violates.**
`DenyApiKeyAuth`'s docblock says the shared admin token is *"acceptable for read-only admin panels;
it is not acceptable for approving, overriding, retrying, cancelling or force-transitioning."*
Bella adjusts credits and suspends users behind exactly that token. **The reasoning exists. The
enforcement does not.** P0 is fundamentally about closing that gap — integration, not invention.

**Three properties make this phase unusually low-risk:**
1. **Bella has never executed**, so §4 can be designed aggressively with no production behaviour to
   preserve.
2. **The hardest components already exist and are proven** — `ApprovalService` (125 real rows),
   `ApprovalPolicyRegistry` (fail-closed, per-capability), `RequireMfaStepUp` (fail-closed state
   machine), `DenyApiKeyAuth`, `LaunchScopePolicy`.
3. **Every phase is additive and reversible**, and the riskiest (P0-D) ships in shadow mode first —
   a pattern this codebase has already executed successfully in Studio Phases I–L.

**The one phase to watch is P0-B**, not P0-D. Attaching `DenyApiKeyAuth` and activating MFA are
small changes that can immediately 403 a working admin flow. Inventory shared-token consumers, and
enrol **both** admins before activation — enrolling one trips the DEGRADED state and returns 423.

**Immediate next step:** P0-A is decisions and documents only — zero code, zero risk. It needs
three answers from you:
1. **Bella: govern, gate, or retire?**
2. **`query_database`: remove, or govern?** (Recommendation: remove.)
3. **Replay policy** for 213 failed / 63 blocked tasks.

**Engineering888 remains PAUSED until M1–M8 are complete and P0-F certifies them.**

---

**END OF MASTERPLAN — awaiting approval**
No code, configuration, database, or service was modified in producing this document.
