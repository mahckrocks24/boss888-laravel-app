# BOSS888 — PLATFORM EVENT CATALOG

**Date:** 2026-07-30 · **Status:** one event implemented, one subscriber implemented, the rest RESERVED
**Table:** `platform_events` · **Writer:** `App\Core\PlatformEvents\Outbox` (the only one)

> **Reading rule.** An event listed as **RESERVED** does **not** exist. There is no class, no producer, no payload contract, and nothing emits it. Reserved entries record a naming and sequencing intent so two engineers do not invent two names for one fact.

---

## Envelope — shared by every event

| Field | Notes |
|---|---|
| `event_id` | uuid, **unique**, deterministic from type + schema version + subject + workspace |
| `event_type` | dotted, lower-case, past tense: `engine.subject.verb` |
| `schema_version` | integer, starts at 1; a breaking payload change increments it |
| `workspace_id` | **mandatory** — an event that cannot name its tenant cannot be delivered safely |
| `actor_type` / `actor_id` | `user` \| `agent` \| `system` \| `customer` |
| `subject_type` / `subject_id` | subject_id is a string; a domain name is a valid subject |
| `capability_key` | the `engine.action` that produced it, where one applies |
| `correlation_id` | one customer journey |
| `causation_id` | the event that caused this one |
| `sensitivity` | `public` \| `internal` \| `restricted` |
| `occurred_at` / `recorded_at` | fact time / write time |
| `payload_json` | typed and validated per event; redaction-checked |

**Payload prohibitions, enforced by `Outbox`:** no credentials, API keys, payment tokens, EPP/auth codes, card data, session or cookie data, raw request bodies, or raw provider responses. Blocked by field name at any depth.

---

## IMPLEMENTED (1)

### `domain.order.created` — schema v1

| Attribute | Value |
|---|---|
| **Status** | ✅ **IMPLEMENTED** (shadow producer, default-OFF) |
| Class | `App\Core\PlatformEvents\Types\DomainOrderCreated` |
| Producer | `DomainCommerceService::createOrder()`, inside the order transaction |
| Capability | `domain.register` |
| Subject | `domain_order` / order id |
| Sensitivity | `internal` |
| Feature flag | `PLATFORM_EVENTS_DOMAIN_ORDER_CREATED` (default `false`) |
| Subscribers | **none** |

**Payload**

| Field | Type | Notes |
|---|---|---|
| `order_id` | int > 0 | required |
| `currency` | string(3) | required |
| `subtotal_minor` | int ≥ 0 | required; **retail**, not cost |
| `item_count` | int ≥ 1 | required; must equal `count(domains)` |
| `domains` | string[] | required, non-empty |

**Deliberately excluded:** `registrar_cost_minor`, `markup_minor`, `cost_total_minor` — internal commercial data no approved subscriber needs. The typed event **throws** if any is supplied, so widening the payload for convenience is not possible without an explicit code change and review.

**Semantics — important for any future subscriber:** this event fires when an order is *created*, before any payment. It is **not** revenue and must not be counted as such.

---

## RESERVED — not implemented

None of the following exist. Listed to fix naming and sequencing only.

### Domain commerce

| Event | Intended producer | Sensitivity | Note |
|---|---|---|---|
| `domain.payment.confirmed` | Stripe webhook path | internal | Requires touching payment code — out of scope until Phase 4 |
| `domain.registration.requested` | registration task dispatch | internal | |
| `domain.registered` | `RegisterDomainJob` success | internal | **Deliberately not first**: producing it means editing the crash-recovery path that survived a real mid-purchase failure |
| `domain.registration.failed` | `RegisterDomainJob` terminal failure | **restricted** | Carries provider error codes — never customer-facing |
| `domain.refund.required` | `refund_due` transition | **restricted** | Customer paid, domain not registered — operator-urgent |
| `domain.expiring` | scheduled expiry scan | internal | Expiry automation is not built (see masterplan §6) |
| `domain.renewal.requested` | renewal task | internal | `domain.renew` capability is declared, not built |
| `domain.renewed` | renewal success | internal | |
| `domain.renewal.failed` | renewal terminal failure | **restricted** | |
| `domain.synced` | `SyncCustomerDomainJob` | internal | |
| `domain.nameservers.changed` | nameserver update | internal | |
| `domain.autorenew.changed` | auto-renew update | internal | Provider may accept without applying — subscribers must not assume the change took effect |
| `domain.privacy.changed` | privacy update | internal | |
| `domain.transfer.requested` / `.completed` / `.failed` | transfer capability | **restricted** | Capability declared, not built |

### Other families — naming reserved, nothing designed

`hosting.*`, `dns.*`, `certificate.*`, `mailbox.*` — reserved so that when those capabilities arrive they do not invent a parallel vocabulary. Capability families stay explicit rather than collapsing under a generic `infrastructure.*`.

---

## Approved future subscribers — none active

| Subscriber | Status | Phase |
|---|---|---|
| **`platform.audit`** → `audit_logs` | **IMPLEMENTED v1 — declared, tested, flag OFF** | **1B — done** |
| Notification → `NotificationService` | not implemented | 1D |
| Memory → `workspace_memory` | not implemented | after notification |
| Analytics | not implemented | later |
| Executive reporting | not implemented | later |

### `platform.audit` v1 — implemented

| Attribute | Value |
|---|---|
| Consumes | `domain.order.created` schema **v1** only |
| Activated at | **2026-07-29 20:00:00** — delivery is prospective from this instant |
| Retry | 5 attempts; 30s / 120s / 600s / 3600s |
| Sensitivity allowance | public, internal, restricted (an audit trail with gaps is not an audit trail) |
| Unsupported schema | `skipped` with a recorded reason — never silent success |
| Customer-facing side effects | **none** — and therefore replayable |
| Output | one `audit_logs` row, action `domain.order.created`, with full source traceability in `metadata_json` |
| Flag | `PLATFORM_EVENTS_SUBSCRIBER_AUDIT` (default **false**) |

**Exactly one subscriber is declared.** An architecture test fails the build if a second appears without authorisation, and separate tests assert the audit subscriber emits no notification and writes no memory.

### Delivery ledger

Per-subscriber outcomes live in `platform_event_deliveries`, keyed uniquely on
`(event_id, subscriber_key, subscriber_version)`. The event row records only
whether fan-out has happened (`fanned_out_at`, `delivery_count`) — never a
per-subscriber outcome, because with N subscribers there is no single outcome.

---

## Dispatch status vocabulary

| Status | Meaning |
|---|---|
| `pending` | recorded, awaiting a dispatcher pass |
| `dispatching` | claimed by a worker |
| `dispatched` | at least one handler ran successfully |
| `no_subscribers` | dispatcher ran, found zero handlers, **delivered nothing** |
| `failed` | handler failed and attempts are exhausted |

In Phase 1A every dispatched event settles at `no_subscribers`. That is the truthful outcome, not a defect.

---

## Versioning rules

1. **Additive payload changes** — add an optional field, keep the version.
2. **Breaking changes** — removing or retyping a field increments `schema_version`; the old version stays readable.
3. **`event_id` derivation includes `schema_version`**, so a v1 and a v2 event for the same subject are distinct rows and neither suppresses the other.
4. **An event type is never reused for a different fact.** Retire the name; add a new one.
