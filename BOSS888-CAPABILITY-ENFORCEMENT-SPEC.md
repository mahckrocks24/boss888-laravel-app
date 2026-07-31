# BOSS888 — CAPABILITY & ENFORCEMENT SPECIFICATION

**Date:** 2026-07-29 · **Status:** SPECIFICATION — nothing implemented
**Purpose:** Define the governed `domain.*` capabilities, and make OS participation structurally impossible to skip

---

## PART A — DOMAIN CAPABILITY DEFINITIONS

Engine slug: **`domain`** (recommendation — see masterplan open decision 4).
Risk vocabulary reuses `InfrastructureCapabilityRegistry`: `low | medium | high | irreversible`.

### Legend

**Callers:** C=customer(portal) · A=agent · O=operator · S=system(cron) · P=public API
**Approval:** `none` · `confirm` (self-service confirmation) · `review` (human approval) · `sod` (separation of duties)

### Read capabilities — never approval-gated, never billable

| Capability | Callers | Risk | Revers. | Cost | Approval | Entitlement | Idempotency | Emits | Audit | Notify | Memory |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `domain.search` | C A O P S | low | n/a | free | none | `custom_domain` | none (cacheable) | — | sampled | — | search intent (aggregate) |
| `domain.price` | C A O P S | low | n/a | free | none | `custom_domain` | none | — | sampled | — | — |
| `domain.list` | C A O | low | n/a | free | none | — | none | — | — | — | — |
| `domain.view` | C A O | low | n/a | free | none | — | none | — | — | — | — |
| `domain.transfer.status` | C A O S | low | n/a | free | none | — | none | — | — | — | — |

**Rationale for `sampled` audit on search:** auditing every keystroke-driven search would add ~10× to `audit_logs` for no forensic value. Sample, and always audit the search that precedes an order.

### Write capabilities — reversible, non-billable

| Capability | Callers | Risk | Revers. | Cost | Approval | Idempotency | Emits | Audit | Notify | Memory | Provider verify |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `domain.sync` | C A O S | low | reversible | free | none | operation key | `domain.synced` | ✔ | — | health history | read-back |
| `domain.nameservers.update` | C O | **high** | reversible | free | **confirm** | order/op key | `domain.nameservers.changed` | ✔ | ✔ customer | preferred DNS | **read-back required** |
| `domain.privacy.update` | C O | low | reversible | free | none | op key | `domain.privacy.changed` | ✔ | — | preference | read-back |
| `domain.autorenew.update` | C A O | medium | reversible | free | none | op key | `domain.autorenew.changed` | ✔ | ✔ on enable | renewal preference | **read-back required** |

**`domain.nameservers.update` is HIGH risk despite being reversible and free** — wrong nameservers take a customer's website and email offline immediately. Risk is measured in customer impact, not in money or undo-ability. Agents may not call it.

**`domain.autorenew.update` requires read-back** because the registrar returns `Status="OK"` for a change it does not apply — observed 2026-07-29. The capability must report `accepted`, not `verified`, when read-back disagrees.

### Billable capabilities — money leaves the building

| Capability | Callers | Risk | Revers. | Cost | Approval | Idempotency | Emits | Audit | Notify | Memory | Timeout/Retry |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `domain.register` | C O · **A proposal only** | **irreversible** | **irreversible** | **billable** | `review` (waived by paid order or budget envelope) | `lvl-order-{o}-item-{i}` | `domain.registration.requested` → `domain.registered` / `.failed` | ✔ | ✔ both | purchase pattern | 60s; **no auto-retry on ambiguous** |
| `domain.renew` | C O S · **A within envelope** | high | **irreversible** | **billable** | `review` unless within envelope | `renew-{domain}-{term}` | `domain.renewal.*` | ✔ | ✔ both | renewal behaviour | 60s; no auto-retry on ambiguous |
| `domain.transfer.request` | C O | **irreversible** | **irreversible** | **billable** | **`sod`** | `transfer-{domain}` | `domain.transfer.*` | ✔ | ✔ both | — | 60s; never auto-retry |

### The three rules that govern agents

1. **An agent may never execute an `irreversible` + `billable` capability.** It may only create a *proposal* — a Task in `awaiting_approval`. This is structural, not policy: the capability declaration carries `agent_execute: false`.
2. **The one exception is a budget envelope** — a workspace-scoped, human-authorised standing permission (e.g. *"auto-renew any domain under $50 while the subscription is active"*). The envelope is itself an approved artefact with an expiry. `domain.register` is **never** envelope-eligible; only `domain.renew` is, because renewal preserves an asset the customer already owns while registration creates a new obligation.
3. **`domain.transfer.request` requires separation of duties** and is never agent-executable under any envelope. Transfers are the standard vector for domain theft; the requester must never be the approver.

### Reads and writes are not treated identically

Reads: no approval, no credits, no task, direct service call via `readJson()` — matching `BaseEngineController`'s documented split. Writes: always a Task, always an event, always audit.

---

## PART B — ENFORCEMENT

The problem is not that rules are missing. `CAPABILITY-REGISTRY.md` states *"any capability not listed here is DENIED"* — and Domain Commerce shipped 17 routes and 137 passing tests without appearing in it. **No test asks whether a subsystem participates in the operating system.**

### Existing precedent to build on

| Asset | Use |
|---|---|
| `tests/Feature/Studio/fixtures/capability_map_golden.json` | golden-file pattern for capability drift — already proven |
| `tests/Feature/Governance/GovernanceIntegrationTest.php` | governance assertions exist |
| `tests/Feature/Security/BellaCapabilityRemovalTest.php` | proves a capability *cannot* be reached |
| `Orchestrator::shadowCapabilityCheck()` | shadow-mode enforcement already running |

**No CI workflows, no PHPStan, no Psalm, no Deptrac.** Enforcement must therefore be PHPUnit-based to run at all. Introducing static analysis is a separate proposal, not a dependency.

### E1 — Route/controller boundary test (architecture test)

Asserts, by reflection over every registered route:

- Any route with a mutating method (POST/PUT/PATCH/DELETE) under an authenticated group resolves to a controller extending `BaseEngineController`, **or** appears on an explicit, dated, justified allow-list.
- The allow-list is a fixture file. Adding to it is a visible diff a reviewer must approve.

*This single test would have caught Domain Commerce on day one.*

### E2 — Capability registration test

- Every `engine.action` reachable from a mutating route exists in `CapabilityMapService`.
- Every entry declares `risk`, `reversibility`, `cost_class`, `approval_mode`.
- Golden-file snapshot: any change to the capability map must update the golden file, making it reviewable.

### E3 — Provider boundary test (forbidden dependency)

By static inspection of `use` statements and class references:

- **No controller** may reference `App\Connectors\*`.
- **No agent tool/action** may reference `App\Connectors\*`.
- Only classes under `App\Engines\*\Services\*` and `App\Services\Domains\*` may resolve a connector, and only via `InfrastructureConnectorResolver`.

Today `CustomerDomainController` calls `NamecheapRegistrarConnector::make()` directly in `setAutoRenew()`. **This test fails today** — deliberately. It documents a real violation rather than grandfathering it.

### E4 — Event emission contract test

For each capability declaring `emits`, a test asserts the event is written to the outbox **within the same transaction** as the state change. Implemented by wrapping in a transaction, forcing a rollback, and asserting the event row is absent.

### E5 — Audit reachability test

Every mutating capability produces at least one `audit_logs` row with `action = capability_key`. Asserted end-to-end, not by mocking the audit service — mocking would prove only that the mock was called.

### E6 — Tenancy test (generic)

For every capability accepting an entity id, a cross-tenant call returns 404/403 and performs **zero** provider calls (`Http::assertNothingSent()`). Currently hand-written per controller; becomes a data-driven test over the capability registry.

### E7 — Idempotency declaration test

Every `billable` or `irreversible` capability declares an idempotency strategy, and a replay test exists for it. A capability without a replay test fails the build.

### E8 — Runtime fail-closed check

Promote `shadowCapabilityCheck()` from log-only to blocking, in stages:

1. shadow (today) → 2. shadow + alert → 3. block for agents → 4. block for all callers.

Applies to `EngineExecutionService::execute()` and `Orchestrator::execute()`, which the code itself names as the platform's two execution engines. **A third execution path must be structurally impossible** — E1 enforces that.

### E9 — Notification & memory policy declaration

Not that they *fire*, but that a policy is **declared**. `notify: none` is a legitimate, reviewable choice; an absent declaration is not. This converts a silent omission into an explicit decision.

### Enforcement rollout

| Stage | Tests | Mode |
|---|---|---|
| 1 | E2, E7, E9 (declaration only) | fail build |
| 2 | E1, E3 with dated allow-lists | fail build; allow-list documents debt |
| 3 | E4, E5, E6 | fail build |
| 4 | E8 shadow → block | runtime |

**Allow-lists must carry an owner and a removal date.** An allow-list without an expiry is a permanent exemption with extra steps.

---

## What this prevents

A future engineer building the next excellent island will find:

- a failing architecture test naming their controller,
- a failing capability test naming their unregistered action,
- a failing boundary test naming their direct provider call.

They cannot reach green by writing more tests of their own. That is the point: **Domain Commerce passed 137 of its own tests while violating every platform standard, because it wrote the only tests that judged it.**
