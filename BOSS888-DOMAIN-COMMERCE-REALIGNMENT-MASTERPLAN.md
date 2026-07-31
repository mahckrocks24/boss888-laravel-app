# BOSS888 — DOMAIN COMMERCE REALIGNMENT MASTERPLAN

**Date:** 2026-07-29 · **Status:** DESIGN ONLY — no code, schema, or production state changed
**Author:** Architecture review, second pass
**Scope:** Realign Domain Commerce onto the existing Boss888 execution pipeline without rebuilding it

---

## 0. Correction to the second-pass review

Two claims in my previous review were wrong and are corrected here on the evidence:

| Previous claim | Evidence | Correction |
|---|---|---|
| "No capability registry exists" | `CAPABILITY-REGISTRY.md` RATIFIED 2026-07-26; `ApprovalPolicyRegistry` (`app/Core/Governance/`); `CapabilityMapService`; `InfrastructureCapabilityRegistry` (328 lines) | A registry exists, is ratified, and is partially enforced |
| "Domain expiry is a P0" | Both owned domains are **sandbox**, expire **2027-07-29 (365 days)**, production credentials **absent** | **Not a P0.** See §6 |

A third correction, to my own method: two greps in this review produced false negatives (`->method(` reachability, `extends BaseEngineController`). Both were re-verified. Conclusions in this document rest on the re-verified results.

---

## PART 1 — THE EXISTING OS SPINES (traced, not assumed)

### 1.1 Capability Registry — **PRODUCTION-READY, partially enforced**

| Aspect | Finding |
|---|---|
| Files | `app/Core/Governance/ApprovalPolicyRegistry.php`, `app/Core/Governance/PermissionRegistry.php`, `app/Core/EngineKernel/CapabilityMapService.php`, `app/Engines/Infrastructure/Registry/InfrastructureCapabilityRegistry.php` (328 lines) |
| Tables | none — capabilities are code constants, not rows |
| Contract | `engine.action` key; `ApprovalPolicyRegistry::forCapability(engine, action, mode)` returns a policy array |
| Risk vocabulary | `RISK_LOW`, `RISK_MEDIUM`, `RISK_HIGH`, `RISK_IRREVERSIBLE` (already includes reversibility) |
| Callers | `TaskService:198`, `ApprovalAuthorizationService`, `ProviderControlPlaneController:548`, `PermissionRegistry` |
| Tenancy | policy is workspace-independent; authorization is workspace-scoped in `ApprovalAuthorizationService` |
| Failure | fail-closed by design (AP-1: unlisted ⇒ denied) — **but only where a caller consults it** |
| Domain entries | **zero** |

`CapabilityMapService` is the *runtime* map (`approval_mode`, `credit_cost` per action). It has **no `domain.*` entries**, which is the mechanical reason Domain Commerce is ungoverned.

### 1.2 Approval System — **PRODUCTION-READY**

| Aspect | Finding |
|---|---|
| Files | `app/Core/Governance/ApprovalService.php`, `ApprovalAuthorizationService.php`, `app/Models/Approval.php` |
| Table | `approvals` — 127 rows (106 approved, 11 rejected, 10 pending) |
| Key columns | `capability_key`, `approval_policy_json`, `task_id`, `engine`, `action`, `requester_actor_type`, `decision_actor_type` |
| Entry point | `ApprovalService::requestIfNeeded(workspaceId, engine, action, approvalMode, data)` |
| Tenancy | workspace-scoped |
| Sync/async | synchronous decision record; execution resumes asynchronously |
| Idempotency | `batch_id` groups related approvals; no per-request key |

`requester_actor_type` / `decision_actor_type` already distinguish human from agent. **This is the field that makes agent governance possible today.**

### 1.3 TaskService — **PRODUCTION-READY**

| Aspect | Finding |
|---|---|
| File | `app/Core/TaskSystem/TaskService.php` |
| Table | `tasks` — **2,450 rows** (2,062 completed, 213 failed, 91 cancelled, 63 blocked, 5 degraded) |
| Entry | `create(int $workspaceId, array $data)` |
| What it wires | LaunchScope gate → category derivation → approval mode from `CapabilityMapService` → creates `approvals` row with `capability_key` + `approval_policy_json` → **sends notification** → dispatches or parks |
| Status enum | `pending, awaiting_approval, queued, running, verifying, completed, failed, cancelled, blocked, degraded` |
| Retry | `retry_count`, `max_attempts`, `requeue(delay)` |
| Tenancy | workspace-scoped |

**`TaskService::create()` already performs five of the seven governance links.** This is the single most important finding in the plan: the target pipeline is not hypothetical.

### 1.4 Orchestrator — **PRODUCTION-READY**

`app/Core/TaskSystem/Orchestrator.php` — `execute(Task)`, `executeStep()`, `transientRequeueDelay()`, `sanitizeAuditPayload()`, and **`shadowCapabilityCheck()`** which logs (does not block) when an agent runs an action it does not hold. Enforcement ramp already begun.

Note the code's own comment: *"The platform has TWO execution engines"* — `Orchestrator` (async/queued) and `EngineExecutionService` (synchronous/manual). Domain Commerce is a **third**.

### 1.5 Entitlements — **PARTIAL, duplicated**

| System | State |
|---|---|
| `FeatureGateService` + `plans.features_json` | **LIVE** — 10 plans populated; `hasFeature`, `canUseEngine`, `getActivePlanFor` |
| `EntitlementResolver` + `infra_entitlement_definitions` | **DORMANT** — 0 rows |

Two entitlement systems. Only the first is real.

### 1.6 audit_logs — **PRODUCTION-READY**

`AuditLogService::log(...)`; table `audit_logs` (7,678 rows). Action naming is `engine.action` — `task.created` (2,458), `write.publish_article` (43), `seo.add_keyword` (28), `approval.approved` (111). **Domain actions: 0.**

### 1.7 NotificationService — **PRODUCTION-READY**

`send(workspaceId, channel, type, data)`, `dispatch(...)`, `broadcast(...)`. 3,099 notifications. Header comment states sender is *"LevelUp Growth"* for system events — branding already handled centrally. **Domain flow calls it zero times.**

### 1.8 Memory — **PRODUCTION-READY, simple**

| Table | Rows | Nature |
|---|---|---|
| `workspace_knowledge` | 1,293 | knowledge base |
| `creative_memory_records` | 1,138 | domain-specific |
| `agent_workspace_memory` | 166 | per-agent |
| `workspace_memory` | 41 | **key/value + TTL** (`key`, `value_json`, `ttl`) |
| `bella_memory` | 0 | unused |

`WorkspaceMemoryService`: `get/set/forget/all/getContextForTask`. **Design consequence:** memory is a KV store, not an event store. Infrastructure memory must be *derived summaries written under stable keys*, not an event log.

### 1.9 infra_events — **PRODUCTION-READY, subsystem-scoped**

`InfraEventRecorder::record/recordStateChange/recordDenial/redact`. 38 rows: `domain_commerce` 18, `monitoring` 10, `manual` 8, `task` 2. **It already has a `redact()` method** — the sensitivity concern is solved here and should be reused, not reinvented.

### 1.10 infra_operations — **PRODUCTION-READY, barely used**

4 rows (3 `awaiting_approval`, 1 `succeeded`). Columns include `idempotency_key`, `attempt_count`, `max_attempts`, `retry_classification`, `next_retry_at`, `timeout_at`, **`approval_id`**, **`task_id`**, `provider_correlation_id`, `failure_code`, `request_json`, `result_json`.

**The `task_id` and `approval_id` foreign keys prove the intended hierarchy:** an infra_operation is *subordinate to* a Task, not a peer of it. This resolves the three-status-machine question in §3.

### Spine readiness summary

| Spine | Production-ready | Used by Domain Commerce |
|---|---|---|
| Capability registry | Yes (enforcement partial) | **No** |
| Approvals | Yes | **No** |
| TaskService | Yes (2,450 tasks) | **No** |
| Orchestrator | Yes | **No** |
| Entitlements | Partial (2 systems) | **No** |
| audit_logs | Yes (7,678) | **No** |
| Notifications | Yes (3,099) | **No** |
| Memory | Yes | **No** |
| infra_events | Yes | **Yes** (only spine used) |
| infra_operations | Yes | **No** |

**`BaseEngineController` is documented as "THE enforcement layer" and is extended by 5 engine controllers** (SEO, Write, CRM, Infrastructure, ProviderControlPlane). `CustomerDomainController` extends nothing.

---

## PART 2 — DOMAIN COMMERCE EXECUTION TRACE

### 2.1 Numbered trace, with transaction boundaries

```
 1  Browser        domains-commerce.js — client-side domain format validation
 2  HTTP           GET /api/domains/search  → CustomerDomainController::search
                   [auth.jwt → workspace_id from token; never from input]
 3  Service        DomainCommerceService::search → DomainPricingService::searchWithPricing
 4  Provider       NamecheapRegistrarConnector::searchDomain + quoteRegistration + quoteRenewal
 5  Response       customerSafe() strips cost/markup/provider/error codes
 ─────────────────────────────────────────────────────────────────────────────
 6  Browser        cart in localStorage, keyed lu.domains.cart.<workspace>
 7  HTTP           POST /api/domains/orders  {items:[{domain,years}], idempotency_key}
                   [NO price accepted from the client — asserted by test]
 8  Service        createOrder(): idempotency lookup → cart dedupe → RE-PRICE each item
                   (network calls happen OUTSIDE the transaction, deliberately)
 9  ══ TX BEGIN ══ DB::transaction
10                 DomainOrder::create(...)  status=pending
11                 DomainOrderItem::create(...) × N, cost/markup/retail FROZEN
12  ══ TX END ════
13  Audit          DomainAuditLogger::orderCreated → infra_events ONLY
 ─────────────────────────────────────────────────────────────────────────────
14  HTTP           POST /api/domains/orders/{id}/checkout   [workspace-scoped find]
15  Billing        StripeService::createDomainCheckoutSession — mode=payment,
                   price_data from FROZEN retail, metadata.order_type='domain'
16  Order          status → awaiting_payment, stripe_session_id stored
17  Audit          checkoutStarted → infra_events
 ─────────────────────────────────────────────────────────────────────────────
18  Stripe         customer pays (browser leaves the platform)
19  Webhook        POST /api/webhook/stripe → StripeService::handleWebhook
                   signature verified → checkout.session.completed
                   → routed on metadata.order_type === 'domain'
20  ══ TX BEGIN ══ markPaidAndProvision()
21                 DomainOrder lockForUpdate() on stripe_session_id
22                 if already paid → return already_processed  ◄ DUPLICATE WEBHOOK GUARD
23                 update status=paid, paid_at, payment_intent
24  ══ TX END ════ (dispatch happens AFTER commit — deliberate)
25  Order          status → provisioning
26  Queue          RegisterDomainJob::dispatch(itemId) per item, queue=tasks-high
27  Audit          paid + provisioningDispatched → infra_events
 ─────────────────────────────────────────────────────────────────────────────
28  Worker         RegisterDomainJob::handle()
29  ══ TX BEGIN ══ claim
30                 DomainOrderItem lockForUpdate()  ◄ CONCURRENT WORKER GUARD
31                 if status not in (pending, registering) → return  ◄ SETTLED GUARD
32                 status=registering, attempts++
33  ══ TX END ════
34  Guard          order->isPaid() false → FAIL NOT_PAID  ◄ UNPAID GUARD
35  Contacts       DomainContactResolver (sandbox identity gated; production refuses fallback)
36  Provider       registerDomain(domain, years, contacts, registrarIdempotencyKey())
                   ── inside the adapter ──
                   36a contact validated LOCALLY (zero API calls if malformed)
                   36b getDomainStatus() ownership pre-check  ◄ ALREADY-OWNED GUARD
                   36c namecheap.domains.create
37  ══ TX BEGIN ══ on success
38                 item: status=registered, order_id, transaction_id, registered_at
39                 CustomerDomain::updateOrCreate(['domain'=>…])  ◄ UNIQUE INDEX GUARD
40  ══ TX END ════
41  Audit          registrationSucceeded (records idempotent_replay) → infra_events
42  Order          recomputeStatus() → completed / partially_completed / failed
43  Queue          SyncCustomerDomainJob → authoritative expiry + nameservers
 ─────────────────────────────────────────────────────────────────────────────
44  Failure        transient  → status=pending, release(backoff), retry
                   permanent  → status=failed → then refund_due + audit
                   AMBIGUOUS  → treated as permanent (never auto-retried)  ◄ DOUBLE-CHARGE GUARD
45  Exhausted      failed() hook → refund_due, JOB_EXHAUSTED
46  Customer       GET /api/domains, /api/domains/{id}/timeline (allow-listed events)
47  Operator       GET /api/admin/domains/attention, /events, POST …/retry
```

### 2.2 Invariants that MUST NOT be touched

| # | Protects against | Mechanism | Steps |
|---|---|---|---|
| I1 | Double charging | ambiguous failures classified permanent; never auto-retried | 44 |
| I2 | Double registration | 5 layers: job claim lock, status guard, deterministic idempotency key, adapter ownership pre-check, unique index | 29–33, 36b, 39 |
| I3 | Incomplete ownership rows | item update + `CustomerDomain` write in ONE transaction | 37–40 |
| I4 | Cross-tenant access | `workspace_id` from token only; `forWorkspace()` on every read | 2, 14, 46 |
| I5 | Registrar success + local failure | ownership pre-check makes replay idempotent; retry reconciles | 36b |
| I6 | Payment success + provider failure | `refund_due` as a distinct terminal state, surfaced to operators | 44–45, 47 |
| I7 | Price tampering | server-side re-price; client posts `{domain, years}` only | 7–11 |
| I8 | Duplicate order from double-submit | `idempotency_key` unique index on `domain_orders` | 8 |
| I9 | Duplicate webhook | `lockForUpdate` + `isPaid()` inside TX | 20–23 |

**These nine invariants are the acceptance criteria for the realignment.** Any refactor that cannot demonstrate all nine is rejected.

---

## PART 3 — TARGET EXECUTION PIPELINE

### 3.1 One path for all callers

```
Portal · API · Agent · Cron · Admin · Automation
                  │
                  ▼
        CapabilityMapService            (engine='domain', action='register')
                  │                      fail-closed: unknown ⇒ denied
                  ▼
        Authorization + Entitlement     (workspace role, plan feature)
                  │
                  ▼
        ApprovalPolicyRegistry          (risk, reversibility, cost, actor type)
                  │
                  ▼
        TaskService::create()           ◄ the ONE entry point
                  │                       (creates approval + notification)
                  ▼
        Orchestrator::execute(Task)
                  │
                  ▼
        Engine (DomainCommerceService)
                  │
                  ▼
        infra_operation  ──►  Provider (NamecheapRegistrarConnector)
                  │
                  ▼
        Platform Event (see ADR)
                  │
     ┌────────┬───┴────┬─────────┬──────────┐
   Audit   Notify   Memory   Analytics   Executive
```

### 3.2 Stage obligations

| Stage | Mandatory | Sync/Async | In provider TX? | May roll back a completed purchase? | Failure mode |
|---|---|---|---|---|---|
| Capability lookup | **Yes** | sync | no | n/a | **fail-closed** |
| Authorization / entitlement | **Yes** | sync | no | n/a | **fail-closed** |
| Approval policy | **Yes** | sync | no | n/a | **fail-closed** |
| Task creation | **Yes** for mutations | sync | no | n/a | **fail-closed** |
| Engine execution | Yes | async | no | n/a | classified |
| infra_operation record | **Yes** for provider calls | sync | **yes — same TX as the state write** | n/a | fail-closed |
| Provider call | Yes | async | n/a | n/a | classified |
| **Local state write** | **Yes** | sync | **yes** | n/a | fail-closed |
| Event emission | **Yes** | sync write, async fan-out | **yes — outbox insert in TX** | **NEVER** | fail-closed on insert |
| Audit | **Yes** | async | no | **NEVER** | fail-open + **alert** |
| Notification | Yes | async | no | **NEVER** | fail-open + alert |
| Memory | No | async | no | **NEVER** | fail-open, silent |
| Analytics | No | async | no | **NEVER** | fail-open, silent |

**Rule:** everything left of the event is fail-closed; everything right of it is fail-open. A failed audit write must alert loudly but must never reverse a registered domain.

### 3.3 Idempotency propagation

One key, derived once, carried the whole way:

```
client idempotency_key
  → domain_orders.idempotency_key        (unique)
  → task.payload_json.idempotency_key
  → infra_operations.idempotency_key
  → registrarIdempotencyKey()  "lvl-order-{order}-item-{item}"
  → event_id (deterministic per capability+subject+attempt)
```

Deterministic, not random — the property that made the real crash recoverable.

### 3.4 THREE STATUS MACHINES — resolution

**Do not introduce a fourth. Do not merge the three. Assign each a single question.**

| Store | Answers | Authoritative for | Never used for |
|---|---|---|---|
| `domain_orders.status` | *Commercially, what happened?* | pending → awaiting_payment → paid → refunded | fulfilment progress |
| `tasks.status` | *Is the work item running?* | pending → awaiting_approval → queued → running → completed/failed | money, provider truth |
| `infra_operations.state` | *What did the provider do on attempt N?* | requested → running → succeeded/failed/needs_reconciliation | orchestration |
| `customer_domains.status` | *What does the customer own now?* | active / expired / released | in-flight anything |

**The corrective change:** `domain_orders.status` currently carries `provisioning`, `partially_completed`, `failed` — fulfilment states that belong to Tasks. Those become **derived** (a read-model over child tasks), not stored transitions. `DomainOrder::recomputeStatus()` becomes a projection.

**Ordering rule:** Task is the parent; `infra_operations.task_id` and `approvals.task_id` already express this. `domain_orders` links to the Task by id. No new join table.

---

## PART 6 — DOMAIN EXPIRY DECISION (evidence-based)

### Evidence

| Question | Answer |
|---|---|
| Domains the platform owns | 2 — `lvl-shop-eb6db479.com`, `chefred-fcf3e2.com` |
| Environment | **sandbox** (`NAMECHEAP_ENVIRONMENT=sandbox`) |
| Expiry (DB and registrar agree) | **2027-07-29 — 365 days** |
| Registrar auto-renew | `false` on both |
| Production credentials installed | **0** |
| Production purchases enabled | **false** |
| Sandbox balance | USD 8,943.28 — **fictional money** |
| Cloudflare custom domains | 0 rows |
| Real customer domains (`chefredraymundo.com`, `amgtravelandtours.com`) | registered **elsewhere**; LevelUp does not own or renew them |

### Decision: **Option B — build the foundation, implement expiry once.**

**M0 is withdrawn as a P0.** I previously called it one; the evidence does not support it and I should have checked before asserting it.

Reasoning:
- **No customer obligation exists.** The platform cannot register a production domain today — there are no production credentials. The first production domain cannot exist until Mark installs them, and would then have ~365 days of runway.
- **Time to real risk ≥ 12 months** from the first production purchase.
- Option A costs a scheduler + notification + tests, then discards them at M3 — real duplicated work for zero risk reduction.
- **Operational safeguard covering the transition:** enable registrar-side auto-renew on any production domain at the moment of purchase, and review `SELECT domain, expires_at FROM customer_domains ORDER BY expires_at` at each monthly ops review. That is sufficient for a portfolio in single digits.

**Trigger that re-raises this to P0:** the day production credentials are installed *or* the first production domain is registered — whichever comes first. At that point expiry automation must exist before the 90-day mark.

---

## PART 8 — DOMAINBINDING VALIDATION

### Current reality of `websites.custom_domain`

It is a varchar, and it is **deeply load-bearing across nine subsystems**:

| Consumer | Dependency |
|---|---|
| `PublishedSiteMiddleware` | **host → website resolution — this is how custom domains are served at all** |
| `CanonicalUrlResolver` | canonical host (custom_domain when `domain_verified=1`) |
| SEO (`SeoService`, `BuilderPageIndexer`, `PageDiscoveryService`, `TrackKeywordRanksCommand`) | rank tracking + indexing host |
| Chatbot (`ChatbotContextBuilder`, `ChatbotWebsiteCrawler`) | widget host matching |
| Write, DiscoveryRunOrchestrator, ToolSchemaService | article/live URLs |
| `PublicContactController`, `PublicNewsController`, `PublicChatbotController` | public widget host resolution |
| `DashboardController` | live URL display |
| `builder.js`, `infrastructure.js` | live URL precedence, domain tab |
| `CustomDomainService` | writes it, and keeps `custom_domains` in sync |

**Serving path (verified):** customer domains are **not** in nginx vhosts. nginx catch-all → Laravel → `PublishedSiteMiddleware` → `websites.custom_domain` lookup. Certbot holds real certs for `chefredraymundo.com` and `amgtravelandtours.com`.

### Assessment

`DomainBinding` is the right long-term entity, and **introducing it now would be reckless**. Roughly 50 read sites across nine subsystems depend on the column, including the request-path middleware that serves every custom-domain page view. A migration error takes customer websites offline.

### Recommended shape (NOT to be built yet)

```
Workspace
 ├── CustomerDomain (registered by us)      ── DnsZone ── DnsRecord
 │        └── Mailbox
 ├── Website ── HostingResource
 └── DomainBinding   { domain_id, website_id, role, state }
          └── Certificate
```

`DomainBinding` should support: apex + www, multiple domains per website, a `primary` flag, redirect roles, verification state, provisioning state, migration state, certificate association, and rollback to a previous website.

**Additive-first strategy:** introduce `domain_bindings` as the new source of truth **while `websites.custom_domain` remains a maintained denormalized cache**. No reader changes in phase one. Readers migrate individually, each behind its own test. The column is dropped only when zero readers remain — likely more than a year out. This is explicitly *not* part of the Domain Commerce realignment.

---

## PART 10 — SAFE MIGRATION PLAN

Guiding constraint: **the working purchase path is not deleted until the governed path passes all nine invariants plus browser acceptance and a rollback drill.**

### Phase 0 — Register capabilities (no behaviour change)

- **Objective:** `domain.*` keys exist in `CapabilityMapService` + `CAPABILITY-REGISTRY.md` with risk/approval, but nothing consults them yet.
- **Files:** `CapabilityMapService.php`, `CAPABILITY-REGISTRY.md`
- **DB:** none
- **Compatibility:** total — dead configuration
- **Tests after:** registry completeness test; golden-file test mirroring `capability_map_golden.json`
- **Rollback:** revert two files
- **Removal criteria:** n/a

### Phase 1 — Platform event outbox (producers only)

- **Objective:** `platform_events` outbox table + emitter; Domain Commerce emits the 10 events **in addition to** existing `infra_events`. No subscribers.
- **DB:** one new table (additive)
- **Compatibility:** dual-write; `infra_events` untouched
- **Tests before:** full 137-test suite green
- **Tests after:** event emitted inside the same TX as the state write; emission failure fails the TX; payload contains no secrets (reuse `InfraEventRecorder::redact`)
- **Observability:** outbox depth, unprocessed age
- **Rollback:** stop emitting; drop table
- **Dual-write:** yes — indefinitely until Phase 5

### Phase 2 — Subscribers: audit first

- **Objective:** audit subscriber writes `domain.*` to `audit_logs`. Then notifications, then memory.
- **Compatibility:** additive; `infra_events` still written
- **Tests after:** every domain event reaches `audit_logs`; a subscriber exception does not fail the producer; no duplicate notifications on event replay
- **Rollback:** disable subscriber
- **Removal criteria for `DomainAuditLogger` direct writes:** 30 days of parity between `infra_events` and `audit_logs`

### Phase 3 — Route reads through the governed path

- **Objective:** `CustomerDomainController` extends `BaseEngineController`; **reads only** (`domain.search`, `domain.list`, `domain.view`) use `readJson()`.
- **Compatibility:** reads are side-effect-free — safest possible first cut-over
- **Tests after:** all 15 portal tests + 37 component tests unchanged
- **Browser acceptance:** required (search + dashboard)
- **Rollback:** revert controller base class

### Phase 4 — Route the purchase through TaskService (**the critical phase**)

- **Objective:** `POST /api/domains/orders` and checkout create a **Task**; `RegisterDomainJob` is invoked by the Orchestrator rather than dispatched directly; an `infra_operation` records each provider attempt.
- **Compatibility:** **feature flag `DOMAIN_GOVERNED_PATH`, default off.** Both paths coexist. Legacy path remains byte-identical while the flag is off.
- **Tests before:** all 137 green; a recorded fixture of the current trace
- **Tests after — all nine invariants, each with a named test:** double-charge, double-registration, crash-mid-purchase replay, duplicate submit, duplicate webhook, cross-tenant, unpaid guard, ambiguous-failure parking, `refund_due` surfacing
- **Sandbox purchase:** required on the governed path
- **Crash/retry drill:** required — kill the worker between provider success and local commit; prove recovery without a second charge
- **Browser acceptance:** full checklist
- **Rollout:** flag on for one internal workspace → sandbox purchase → 7-day soak → all workspaces
- **Rollback:** flag off (instant, no data migration)
- **Removal criteria for the legacy path:** 30 days flag-on, ≥ 5 governed purchases, zero `refund_due` caused by the new path

### Phase 5 — Retire duplication

- Remove direct `RegisterDomainJob::dispatch`; remove `DomainAuditLogger` direct writes (events now carry them); make `domain_orders.status` a projection over tasks.
- **Only after Phase 4 removal criteria are met.**

### Phase 6 — Renewal, transfer, DNS as capabilities

New capabilities on the proven governed path. Expiry automation is implemented **here, once** (per §6).

---

## Open architectural decisions requiring Mark's approval

1. **Withdraw M0 as P0** and accept the monthly-review safeguard (§6).
2. **Feature-flag name and rollout gate** for Phase 4.
3. **Whether `domain_orders.status` becomes a projection** (recommended) or stays stored.
4. Whether Domain Commerce becomes engine slug `domain` or lives under `infrastructure` — affects every capability key. **Recommendation: `domain`**, because it will later host renewals, transfers and DNS, and `infrastructure.domain_register` reads as a subsystem rather than a capability.
