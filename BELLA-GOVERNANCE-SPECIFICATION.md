# BELLA GOVERNANCE SPECIFICATION

**Phase:** P0-A · **Date:** 2026-07-26 · **Status:** RATIFIED — architecture only, **no code**
**Governing decision:** GD-001 (Govern and Gate) · GD-002 (`query_database` removed)
**Subject:** `App\Http\Controllers\Api\Admin\BellaController` — 1,748 lines, 38 methods, **0 executions to date**

---

## 1. THE GOVERNING RULE

> **Bella NEVER directly executes privileged actions.**
> **Bella may REQUEST actions.**
> **The Governance layer decides whether those actions may execute.**
> — GD-001-R1, permanent architectural rule

Bella is reclassified from *administrative actor* to **administrative requester**. This is a
change of constitutional status, not a feature toggle. Every clause below derives from it.

---

## 2. CURRENT STATE (baseline for the contract)

| Property | Value | Evidence |
|---|---|---|
| Executions to date | **0** | `bella_conversations`=0 · `bella_memory`=0 · `audit_logs action='bella_chat'`=0 |
| Reachability | **Reachable** — 4 routes registered, middleware passes for any platform admin | `route:list` |
| Middleware | `api → JwtAuthMiddleware → AdminMiddleware` — **no `DenyApiKeyAuth`, no MFA, no rate limit** | `route:list` |
| Action model | LLM emits JSON → verb checked against allowlist → executed with **model-supplied params** | source §4.5 |
| Data access | Direct `DB` facade into 14+ tables, bypassing engine services | source |
| Prompt inputs | Platform context, schema map, **raw `laravel.log` error lines** | `getRecentLogErrors()` |
| LLM path | `RuntimeClient::chatJson` → Railway → DeepSeek (correct and current) | constructor |

---

## 3. ACTION CLASSIFICATION

Every Bella action falls into exactly one tier. **Unclassified ⇒ FORBIDDEN (AP-1).**

### Tier 0 — READ-ONLY · direct execution permitted
| Action | Notes |
|---|---|
| `bella.read_analytics` | aggregate figures only |
| `bella.read_queue` | queue depths, worker state |
| `bella.read_engine_status` | engine registry state |
| `bella.generate_report` | derived from permitted reads only |

### Tier 1 — LOW · direct execution, rate-limited
| Action | Notes |
|---|---|
| `bella.read_audit_logs` | rate-limited; never returns secret payloads |
| `bella.memory_write` (`remember` / `forget`) | scoped strictly to Bella's own `bella_memory` |

### Tier 2 — MEDIUM · direct execution, constrained output
| Action | Constraint |
|---|---|
| `bella.list_users` | **field allowlist.** `password`, `mfa_secret_encrypted`, `mfa_recovery_codes_encrypted` are never returned under any prompt |
| `bella.get_workspace` | tenancy recorded in the audit row; cross-workspace reads explicitly logged |

### Tier 3 — HIGH / CRITICAL · **REQUEST ONLY — execution forbidden**
| Action | Governance path |
|---|---|
| **`bella.adjust_credits`** | propose → validate → dry-run → human approval → **MFA** → execute → event → audit → rollback token |
| **`bella.suspend_user`** | propose → validate → dry-run → human approval → **MFA** → execute → event → audit → rollback token |

### Tier 4 — FORBIDDEN
| Action | Reason |
|---|---|
| **`bella.query_database`** | **REMOVED — GD-002.** Do not repair. Do not replace with unrestricted SQL. Do not reintroduce under another name. |
| Any unlisted action | Fail-closed (AP-1) |
| Approving anything | **MA-1** — no machine principal may approve, including its own request |
| Acting on a Platform Owner | **PO-1** — owner cannot be suspended/demoted/deleted by any principal |
| Self-approval | **MA-3** — `self_approval_allowed=false` unconditionally |
| Satisfying MFA | **MA-5** — a machine can never provide a second factor |

---

## 4. TARGET EXECUTION MODEL

```
  Admin message
        │
  ┌─────▼──────────────────────────────────────────────────────────┐
  │ STAGE 1 — INTENT VALIDATION            (deterministic, pre-LLM)│
  │  length cap · control-char strip · injection-marker rejection  │
  │  reject ⇒ PromptInjectionSuspected event · no LLM call         │
  └─────┬──────────────────────────────────────────────────────────┘
        │
  ┌─────▼──────────────────────────────────────────────────────────┐
  │ STAGE 2 — CONTEXT ASSEMBLY                                     │
  │  ⚠ raw laravel.log lines REMOVED from the prompt               │
  │    replaced by structured, sanitised error counts              │
  │  schema map: names only, never values                          │
  └─────┬──────────────────────────────────────────────────────────┘
        │
  ┌─────▼──────────────────────────────────────────────────────────┐
  │ STAGE 3 — LLM CALL  (RuntimeClient → Railway → DeepSeek)        │
  │  Output is UNTRUSTED INPUT (MA-6)                              │
  └─────┬──────────────────────────────────────────────────────────┘
        │
  ┌─────▼──────────────────────────────────────────────────────────┐
  │ STAGE 4 — ACTION EXTRACTION                                    │
  │  verb ∈ registry?  ── no ──► deny · MachineActionDenied event  │
  └─────┬──────────────────────────────────────────────────────────┘
        │
  ┌─────▼──────────────────────────────────────────────────────────┐
  │ STAGE 5 — PARAMETER VALIDATION       ← THE MISSING CONTROL     │
  │  per-action server-side schema:                                │
  │   type · range · bounds · existence · tenancy · target class   │
  │  adjust_credits: integer, |amount| ≤ CEILING, workspace exists │
  │  suspend_user:   user exists, not platform admin, not self     │
  │  fail ⇒ deny · event · audit                                   │
  └─────┬──────────────────────────────────────────────────────────┘
        │
  ┌─────▼──────────────────────────────────────────────────────────┐
  │ STAGE 6 — TIER ROUTING                                         │
  └──┬──────────────────────────────────────┬─────────────────────┘
     │ Tier 0/1/2                           │ Tier 3
     ▼                                      ▼
  EXECUTE                        ┌──────────────────────────────┐
     │                           │ STAGE 7 — GOVERNANCE GATE    │
     │                           │  BellaActionRequested event  │
     │                           │  ApprovalService::request…   │
     │                           │   requester_actor_type=machine│
     │                           │   self_approval_allowed=FALSE │
     │                           │  DRY-RUN computed + stored    │
     │                           │  ── execution DOES NOT occur ─│
     │                           └──────────┬───────────────────┘
     │                                      │ (human, out-of-band)
     │                           ┌──────────▼───────────────────┐
     │                           │ STAGE 8 — HUMAN DECISION     │
     │                           │  reviews the DRY-RUN         │
     │                           │  RequireMfaStepUp enforced   │
     │                           │  approve / reject            │
     │                           └──────────┬───────────────────┘
     │                                      │ approved
     ▼                                      ▼
  ┌────────────────────────────────────────────────────────────────┐
  │ STAGE 9 — EXECUTION                                            │
  │  through engine services — NOT the raw DB facade               │
  │  before/after captured · rollback token minted                 │
  └─────┬──────────────────────────────────────────────────────────┘
        ▼
  ┌────────────────────────────────────────────────────────────────┐
  │ STAGE 10 — EVENT + AUDIT                                       │
  │  BellaActionExecuted / BellaActionFailed                       │
  │  audit: actor(machine) · on_behalf_of(human) · approver ·      │
  │         params · before · after · rollback_token · correlation │
  └────────────────────────────────────────────────────────────────┘
```

---

## 5. GOVERNANCE CONTRACT

### 5.1 Allowed actions
Tier 0, 1, 2 as listed in §3 — subject to output constraints and rate limits.

### 5.2 Forbidden actions
`bella.query_database` (GD-002) · approving anything (MA-1) · acting on a Platform Owner (PO-1) ·
self-approval (MA-3) · satisfying MFA (MA-5) · any unlisted action (AP-1).

### 5.3 Approval-required actions
`bella.adjust_credits` · `bella.suspend_user` — and **every future Tier 3 capability by default**.

### 5.4 Owner-only controls
| Control | Effect |
|---|---|
| `BELLA_ENABLED` | Master kill switch. Default **false** until M9 certification. |
| `BELLA_SAFE_MODE` | Propose-only. Tier 3 actions never execute even after approval. Default **true**. |
| Per-action enable flags | Individual Tier 2/3 capabilities can be disabled independently. |
| Credit ceiling | Absolute bound on `adjust_credits`, settable only by the Platform Owner. |
| Rate limit | Requests per admin per window on `/api/admin/bella`. |

### 5.5 Machine restrictions (MA-1…MA-6 applied)
1. Bella never approves — including its own request.
2. Bella holds no standing privileged authority.
3. `self_approval_allowed=false` unconditionally.
4. Bella is distinguishable in audit from the human it acts for (`actor_type=machine`, `on_behalf_of=<user>`).
5. Bella can never satisfy MFA step-up.
6. All model output is validated server-side before use.

### 5.6 Dry-run behaviour
- **Mandatory** for Tier 3; **available on request** for all tiers.
- Computes and persists the **exact** effect: target entity, before value, after value, reversibility, rollback method.
- Executes nothing and mutates nothing.
- Stored in `approvals.data_json`; the human approves **the dry-run**, not the intention (AP-3).
- A dry-run that cannot be computed ⇒ the request is **denied**, never approved blind.

### 5.7 Confirmation workflow
1. Bella proposes; the admin sees the proposal **and** the rendered dry-run.
2. Confirmation is an **explicit action on the approval record** — never a chat message. A chat
   reply such as "yes do it" is **not** consent.
3. MFA step-up is enforced at the approval endpoint.
4. Approval is **single-use**, bound to that dry-run, and **expires** (AP-2).
5. Any parameter change invalidates the approval and requires a new dry-run.

### 5.8 Audit behaviour
Every stage writes an audit record — proposed · validated · denied · requested · approved ·
rejected · executed · failed · rolled-back.

Each record carries: `correlation_id` · `actor_type=machine` · `actor_id=bella` ·
`on_behalf_of` · `auth_via` · `capability_key` · `risk_class` · full params · before/after ·
`approval_id` · `approver_user_id` · `mfa_verified` · `rollback_token` · `session_id` · `ip`.

**The existing single `bella_chat` audit row is insufficient** and is superseded by this model.

### 5.9 Rollback expectations
| Action | Reversible | Method | Window |
|---|---|---|---|
| `adjust_credits` | **Yes** | compensating `credit_transactions` row + balance restore | Unbounded (ledger) |
| `suspend_user` | **Yes** | restore prior `users.status` | Unbounded |
| `memory_write` | Yes | prior value retained | Bounded |
| Tier 0/1/2 reads | n/a | — | — |

Rules: a rollback token is minted for every Tier 3 execution; rollback is itself an **audited,
event-emitting action**; **an action whose rollback method is unknown may not be added to Tier 3.**

---

## 6. ARCHITECTURAL INTEGRATION TARGET

| Aspect | Today | Target |
|---|---|---|
| Data access | Direct `DB` facade, 14+ tables | Through engine services |
| Credit mutation | Direct `credits` + `credit_transactions` write | Through the billing/credit service |
| Launch scope | Bypassed | Subject to `LaunchScopePolicy` |
| Approvals | Not used | `ApprovalService` + `ApprovalPolicyRegistry` |
| Route hardening | `admin` only | `admin + DenyApiKeyAuth + rate limit` |
| Coupling | Parallel data-access layer | **Consumer of engine services** |

---

## 7. ENABLEMENT GATE

Bella remains **DORMANT** (`BELLA_ENABLED=false`) until **all** are true:

1. P0-C event backbone live (`BellaActionRequested/Approved/Rejected/Executed/Failed`).
2. P0-D authorization framework enforcing (`bella.*` capabilities registered).
3. P0-E pipeline implemented — Stages 1–10.
4. GD-002 executed — `query_database` removed from source.
5. `DenyApiKeyAuth` + rate limit attached to all four Bella routes.
6. MFA enrolled for both platform admins; governance ACTIVATED.
7. **M9 certification passed** — §Governance Verification Plan.
8. Platform Owner explicitly sets `BELLA_ENABLED=true`.

**Safe mode remains ON after enablement** until a reviewed operating history exists.

---

## 8. WHAT THIS SPECIFICATION DOES NOT DO

- It does **not** change any code. `BellaController` is untouched.
- It does **not** enable Bella. It remains dormant and reachable exactly as before.
- It does **not** remove `query_database` — that is a **P0-E implementation task** under GD-002.
- It does **not** design a front-end.

---

**Specified 2026-07-26 · P0-A · architecture only · Bella remains dormant and unmodified**
