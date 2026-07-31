# GOVERNANCE DECISION REGISTER

**Authority:** Platform Owner (Boss) · **Opened:** 2026-07-26 · **Phase:** P0-A
**Status:** ACTIVE — this register is binding on all future work.

> This is the permanent record of governance decisions for the LevelUp Growth platform.
> A decision recorded here may only be changed by a new dated entry in this register.
> Code, plans, and documents that contradict an entry here are wrong by definition.

---

## GD-001 — Bella: GOVERN AND GATE

| Field | Value |
|---|---|
| **Decision** | **Govern and Gate.** Bella is retained and becomes a governed administrative subsystem. |
| **Rejected alternatives** | Retire Bella · Expose Bella as-is |
| **Decided** | 2026-07-26 · Platform Owner |
| **Status** | APPROVED — binding |
| **Evidence basis** | `LARAVEL-ADMIN-FORENSIC-AUDIT-2026-07-26.md` §4 |

### Permanent architectural rule (GD-001-R1)

> **Bella NEVER directly executes privileged actions.**
> **Bella may REQUEST actions.**
> **The Governance layer decides whether those actions may execute.**

This rule is **absolute and non-negotiable**. It admits no exception, no emergency override, and
no "temporary" bypass. Any future design in which Bella mutates state without passing through the
Governance layer is a violation of this register, regardless of who authored it.

### Consequences
- Bella's `executeAction()` may no longer be reached directly from LLM output for any privileged action.
- Bella becomes a **requester**, never an **executor**, for anything above the READ/LOW tier.
- Bella must not be exposed to customers or to any non-platform-admin principal.
- Bella remains **dormant** (`audit_logs WHERE action='bella_chat'` = 0) until P0-E completes and
  M9 certifies it.

---

## GD-002 — `query_database`: REMOVE

| Field | Value |
|---|---|
| **Decision** | **Remove the capability entirely.** Intentionally deprecated. |
| **Decided** | 2026-07-26 · Platform Owner |
| **Status** | APPROVED — binding |
| **Evidence basis** | Forensic audit §4.5 B-3; live verification that `DB::select(DB::raw($sql))` throws `TypeError` on Laravel 11 |

### Standing prohibitions (GD-002-R1)

| Prohibition | Rationale |
|---|---|
| **Do NOT repair it** | Its present safety is an **unintended Laravel 11 incompatibility**, not a control. Repairing the line arms unrestricted database read access including `users.password`, `users.mfa_secret_encrypted`, and `api_keys.key`. |
| **Do NOT replace it with unrestricted SQL** | A denylist over arbitrary SQL is not a security boundary. |
| **Do NOT reintroduce under another name** | Any equivalent capability is covered by this decision. |

### Replacement path
Future governed data access occurs through **dedicated services and approved runtime tools** with
explicit table and column allowlists, tenant scoping, and per-query audit — never through
free-form SQL.

### ⚠️ Trap warning (permanent)
The broken line **looks like a trivial bug**. Any engineer encountering
`TypeError: PDO::prepare(): Argument #1 ($query) must be of type string` in `BellaController` must
be directed to this register entry. **The correct action is deletion, not repair.**

---

## GD-003 — Replay Policy: APPROVED

| Field | Value |
|---|---|
| **Decision** | Adopt the following **permanent** replay policy for failed and blocked work. |
| **Decided** | 2026-07-26 · Platform Owner |
| **Status** | APPROVED — binding |
| **Applies to** | 213 failed + 63 blocked tasks at time of adoption, and all future failures |

### Tier 1 — AUTOMATIC REPLAY (no human approval)
- Infrastructure operations
- AI processing
- SEO jobs
- Internal synchronization
- Background maintenance

### Tier 2 — MANUAL APPROVAL REQUIRED
- Customer deliverables
- Billing
- Credits
- Emails
- Publishing
- Deployments
- Notifications

### Tier 3 — AUTOMATICALLY DISCARD
- Expired drafts
- Superseded reports
- Historical temporary jobs
- Obsolete snapshots

### Classification rule
Where a task's tier is ambiguous, it is treated as **Tier 2 (manual approval)**. Replay
classification is **fail-safe toward human review**, never toward automatic execution.

### Note on the current backlog
The 213 failed tasks include `write_article` work for paying workspaces (e.g. tasks 2595/2599,
workspace 7 — Shukran Group). Those are **customer deliverables ⇒ Tier 2**, requiring approval
before replay. Implementation is **not** part of P0-A.

---

## GD-004 — Platform Owner Invariants

Binding. Derived from Masterplan §3.2.

| ID | Invariant |
|---|---|
| **PO-1** | The Platform Owner **cannot be suspended, demoted, or deleted** by any other principal — human or machine. *(Partial precedent already in code: `actionSuspendUser` refuses platform admins.)* |
| **PO-2** | Only the Platform Owner may exercise **break-glass / emergency access**. |
| **PO-3** | Owner override is a **distinct, always-audited, event-emitting action** — never a silent bypass. |
| **PO-4** | **Emergency credential revocation and provider disable remain reachable in every governance state**, including DEGRADED. *(Precedent: `RequireMfaStepUp` already excludes incident routes by design.)* |
| **PO-5** | The Platform Owner may not delegate PO-1, PO-2, or PO-4. |

---

## GD-005 — Machine Authority Invariants

Binding. Applies to **every** machine principal: Bella, Engineering888, Sarah, agents, runtime,
scheduler, API keys.

| ID | Invariant |
|---|---|
| **MA-1** | **No machine principal may approve anything** — including its own request. |
| **MA-2** | **No machine principal holds standing privileged authority.** It requests; a human approves. |
| **MA-3** | `self_approval_allowed = false` **unconditionally** for machine requesters, regardless of capability classification. |
| **MA-4** | Machine identity must be **distinguishable in the audit trail** from the human on whose behalf it acts. |
| **MA-5** | A machine principal **may never satisfy MFA step-up**. *(Precedent already in code: `RequireMfaStepUp` denies `auth_via === 'api_key'`.)* |
| **MA-6** | Model output is **untrusted input**. Parameters generated by a language model are validated server-side against an explicit schema before any use. |

---

## GD-006 — Approval Invariants

| ID | Invariant |
|---|---|
| **AP-1** | **Fail-closed:** any capability not explicitly classified is **denied**. *(Precedent: `ApprovalPolicyRegistry::STRICT_DEFAULT`.)* |
| **AP-2** | Approvals **expire**. No approval grants open-ended authority. |
| **AP-3** | HIGH and CRITICAL actions are approved against a **computed dry-run**, not an intention. |
| **AP-4** | Every approval decision emits an event and writes an audit record naming the **actual human**. |
| **AP-5** | Self-approval exemptions must be **explicitly classified and documented**, never grandfathered by omission. *(Precedent: `ApprovalPolicyRegistry` already flags its six self-service exemptions for product review.)* |
| **AP-6** | Separation of duties applies wherever an action creates a financial obligation or destroys a customer resource. |

---

## GD-007 — Authorization Principles

| ID | Principle |
|---|---|
| **AZ-1** | **Hybrid model:** Gates + a capability registry, with Policies where operations are model-scoped. Rejected: a third-party RBAC package (would create a fifth competing authority vocabulary). |
| **AZ-2** | The capability-key idiom (`engine.action`) is the platform's **native authority vocabulary** and is extended, not replaced. *(18 keys already exist in `ApprovalPolicyRegistry`.)* |
| **AZ-3** | **Defence in depth:** a route that omits its middleware must still fail closed at the controller via `Gate::authorize()`. Route-level protection alone is insufficient — 48 of 64 mutating admin routes prove why. |
| **AZ-4** | `is_platform_admin` is retained as the **Platform Owner/Admin bootstrap** so nothing breaks on day one. |
| **AZ-5** | Authority is **time-bounded where elevated**: temporary elevation expires; break-glass is windowed. |
| **AZ-6** | Enforcement changes ship in **shadow mode first** — evaluate and log, compare, then enforce. *(Precedent: Studio Phases I–L.)* |

---

## Change control

| Rule |
|---|
| Entries are **append-only**. A superseded decision is marked SUPERSEDED with a pointer to its replacement; the original text is never edited or deleted. |
| Only the Platform Owner may add, supersede, or revoke an entry. |
| Any plan, design, or code review that conflicts with this register must cite the conflicting entry and obtain an explicit superseding decision **before** proceeding. |

---

**Register opened 2026-07-26 · P0-A · 7 entries · 0 superseded**
