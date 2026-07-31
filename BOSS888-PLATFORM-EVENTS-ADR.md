# BOSS888 — PLATFORM EVENTS ADR

**ADR-001** · **Date:** 2026-07-29 · **Status:** PROPOSED — not implemented
**Decision needed from:** Mark

---

## Context

Boss888 has **no event mechanism**. Verified: `app/Events` = 0 files, `app/Listeners` = 0 files, `event(new …)` call sites = **0** across the entire codebase.

Every cross-cutting concern is therefore a separate manual call:

```php
$this->auditLog->log(...);            // 17 callers
$this->notifications->send(...);      // 15 callers
$this->memory->set(...);              //  7 callers
$this->infraEvents->record(...);      // 13 callers
```

Domain Commerce made **one of four**. That is the mechanism of the drift, not a lapse of discipline: participation requires four remembered calls and nothing fails when one is missing.

---

## Decision

**Adopt a transactional outbox with queued dispatch, using Laravel's existing queue. Do not adopt raw Laravel domain events alone.**

### Options considered

| Option | Guarantees delivery | Survives crash | Retry | Complexity | Verdict |
|---|---|---|---|---|---|
| Laravel domain events (sync listeners) | No | No | No | Lowest | **Rejected** — a listener exception rolls back the purchase |
| Laravel events + `ShouldQueue` listeners | Partly | **No** — dispatched after commit, lost if the process dies between | Yes | Low | **Rejected** — silent loss |
| **Outbox table + queued relay** | **Yes** | **Yes** | **Yes** | Medium | **ACCEPTED** |
| Reuse `tasks` as the event log | Yes | Yes | Yes | Medium | Rejected — conflates work items with facts; pollutes a 2,450-row operational table |
| External broker (Redis Streams, SQS) | Yes | Yes | Yes | High | Rejected — no operational need at this scale; revisit at multi-service |

### Why the outbox

The event row is inserted **inside the same database transaction as the state change**. Either the domain is registered *and* the event exists, or neither. No dual-write gap. A relay worker then dispatches to subscribers asynchronously, with retries, and cannot affect the original transaction.

This is the only option that satisfies both "the business transaction completes even if a subscriber fails" and "important events are not silently lost."

---

## Envelope

Typed event classes **plus** a shared envelope. Not a single generic `WorkspaceEvent`.

**Why typed:** a generic envelope with a free-form payload cannot be validated, cannot be versioned per event, and gives subscribers no contract. Within a month, `payload['domain']` and `payload['domain_name']` both exist and no test catches it. Typed classes give a schema; the shared envelope gives one pipeline.

```
platform_events (outbox)
├─ event_id            uuid, PRIMARY, client-derived deterministic
├─ workspace_id        int, NOT NULL      ← tenancy on the event itself
├─ event_type          string             'domain.registered'
├─ schema_version      int                starts at 1
├─ capability_key      string, nullable   'domain.register'
├─ actor_type          enum(user|agent|system|customer)
├─ actor_id            int, nullable
├─ subject_type        string             'customer_domain'
├─ subject_id          int/string
├─ correlation_id      uuid               constant across one customer journey
├─ causation_id        uuid, nullable     the event that caused this one
├─ payload_json        json               typed per event, redacted
├─ sensitivity         enum(public|internal|restricted)
├─ occurred_at         timestamp          when the fact happened
├─ recorded_at         timestamp          when we wrote it
├─ available_at        timestamp          for delayed events
├─ dispatched_at       timestamp, null
└─ attempts            int
```

Plus `platform_event_deliveries` (event_id, subscriber, status, attempts, last_error, completed_at) — the **per-subscriber idempotency ledger**. Without it, a relay retry re-notifies the customer.

### Guarantees mapped to mechanism

| Requirement | Mechanism |
|---|---|
| Transaction completes even if a subscriber fails | subscribers run in the relay worker, never in the producer TX |
| Events not silently lost | outbox insert is inside the producer TX |
| Subscribers retry safely | per-subscriber delivery rows + queue retry |
| Tenant isolation | `workspace_id` on the event; subscribers must scope |
| Event idempotency | deterministic `event_id` = `hash(capability_key + subject + attempt)` |
| Traceability | `correlation_id` (journey) + `causation_id` (chain) |
| No duplicate notifications | unique(event_id, subscriber) in deliveries |
| No duplicate memory entries | same, plus memory writes are keyed upserts |
| No false audit success | audit delivery marked complete only after the `audit_logs` insert returns |

**Sensitivity classification** reuses the existing `InfraEventRecorder::redact()` rather than inventing a second redactor.

---

## First Domain Commerce events

Emitted **in addition to** existing `infra_events` during migration. Not to be implemented in this phase.

| Event | Emitted at | Subject | Sensitivity | Audit | Notify | Memory |
|---|---|---|---|---|---|---|
| `domain.order.created` | order committed | domain_order | internal | ✔ | — | — |
| `domain.payment.confirmed` | webhook TX commit | domain_order | internal | ✔ | ✔ customer | — |
| `domain.registration.requested` | task queued | domain_order_item | internal | ✔ | — | — |
| `domain.registered` | ownership row committed | customer_domain | internal | ✔ | ✔ customer | ✔ purchase pattern |
| `domain.registration.failed` | terminal failure | domain_order_item | restricted | ✔ | ✔ operator | ✔ failure pattern |
| `domain.refund.required` | refund_due set | domain_order_item | restricted | ✔ | ✔ operator **urgent** | ✔ |
| `domain.expiring` | scheduled scan | customer_domain | internal | ✔ | ✔ customer | ✔ renewal behaviour |
| `domain.renewal.requested` | task queued | customer_domain | internal | ✔ | — | — |
| `domain.renewed` | expiry advanced | customer_domain | internal | ✔ | ✔ customer | ✔ |
| `domain.renewal.failed` | terminal failure | customer_domain | restricted | ✔ | ✔ both | ✔ |

Naming is `engine.subject.verb`, past tense, consistent with existing `audit_logs` actions (`task.created`, `write.publish_article`).

**`domain.registration.failed` and `domain.refund.required` are `restricted`** — they carry provider error codes that must never reach a customer surface.

---

## Consequences

**Positive.** Participation becomes the default: emit one event, get audit + notification + memory + analytics. New subscribers require no producer changes. Full causal traceability from customer click to provider call.

**Negative.** One new table plus a relay worker to operate. Eventual consistency between the fact and its audit row (bounded by relay latency — target < 5s). A poison event can block a subscriber; mitigated by per-subscriber delivery rows so one failure does not block others.

**Rejected explicitly:** making audit synchronous "for safety." That is precisely how an audit outage becomes a purchase outage.

---

## Decision required from Mark

1. Approve the outbox over simpler Laravel events, accepting one table + one worker.
2. Approve typed events + shared envelope over a single generic `WorkspaceEvent`.
3. Confirm relay latency target (proposed: p95 < 5s, alert at > 60s unprocessed).


---

## Amendment 1 — corrections from Phase 1A implementation

**Date:** 2026-07-29 · **Source:** `BOSS888-PHASE-1A-OUTBOX-IMPLEMENTATION-REPORT.md`

Implementation evidence required three corrections to this ADR. The original text
above is left unaltered; these supersede it where they conflict.

### C1 — `available_at` replaced by `next_attempt_at`

The ADR listed `available_at` for delayed delivery. Implementation added
`next_attempt_at` for retry backoff and found the two describe one idea: "do not
process before this time". Carrying both would breach the ADR's own rule that no
field ships without a demonstrated purpose.

**Correction:** the envelope has `next_attempt_at`. It serves retry backoff today
and delayed delivery when a producer needs it.

### C2 — `platform_event_deliveries` deferred, and it is now a Phase 1B blocker

The ADR specified a per-subscriber delivery ledger for idempotency. It was **not
built**, because Phase 1A has no subscribers and building it would have been
speculative.

**Correction:** the ledger is a **prerequisite for Phase 1B, not an optional
extra**. Without it, a relay retry re-runs a handler that already succeeded — for
the notification subscriber that means a customer receives the same message
twice. No subscriber may be added before it exists.

### C3 — a `no_subscribers` status was added

The ADR's status vocabulary assumed subscribers would exist and offered only
pending / dispatched / failed.

**Correction:** `no_subscribers` is a distinct terminal state. Marking an event
`dispatched` when nothing consumed it would be a false claim of delivery, and
would make the first real subscriber in Phase 1B look like a regression.

**Consequence to plan for:** events settled as `no_subscribers` are not
automatically re-dispatched when a subscriber later appears. Phase 1B must decide
whether to backfill or reset them.

### Unchanged and confirmed by implementation

- transactional outbox over raw Laravel events — **confirmed**; the assertion that
  `Outbox::record()` runs inside a transaction is what makes atomicity structural
  rather than intended
- typed events over a shared envelope — **confirmed**; per-event validation caught
  two real payload defects during Phase 1A
- deterministic `event_id` as the idempotency constraint — **confirmed**
- no external broker — **confirmed**; Redis and MySQL carry this comfortably


---

## Amendment 2 — corrections from Phase 1B implementation

**Date:** 2026-07-30 · **Source:** `BOSS888-PHASE-1B-AUDIT-SUBSCRIBER-REPORT.md`

### C4 — the delivery ledger is built, and its shape differs from the ADR sketch

Amendment 1 recorded `platform_event_deliveries` as deferred and a Phase 1B
blocker. It is now built. Two fields the ADR did not anticipate:

- **`subscriber_version` is part of the unique identity**, not merely metadata. That
  lets a deliberate re-delivery under changed behaviour happen without reopening
  settled rows.
- **`skip_reason`** — because `skipped` must never be a mystery. An unsupported
  schema version produces an inspectable row, never silence.

### C5 — the audit_logs FK constraint is a real architectural limit

`audit_logs` carries foreign keys to `users.id` **and** `workspaces.id`. An event
whose workspace or actor is deleted after the event was recorded **can never be
audited**. The delivery fails permanently and visibly rather than retrying, but
the audit gap for that event is unrecoverable.

The ADR assumed audit was the safest possible subscriber because its failures are
invisible to customers. That remains true, but "safe" is not "cannot fail".

### C6 — subscribers need a way to declare a failure permanent

The ADR left failure classification to the relay. Implementation showed the relay
cannot always tell: "there is no audit mapping for this event type" will not
become true on retry, but it is indistinguishable from a transient error at the
worker level.

**Correction:** `PermanentSubscriberFailure` lets a subscriber declare that its own
logic can never succeed for a given event. The worker also classifies SQLSTATE
23000 / 22032 / 22001 as permanent on its own.

### C7 — the event status question, resolved additively

Amendment 1 added `no_subscribers` to `platform_events.status`. Phase 1B found that
a single event-level status cannot express N subscriber outcomes at all.

**Correction:** the event row carries **fan-out** state only (`fanned_out_at`,
`delivery_count`); per-subscriber outcomes live exclusively in
`platform_event_deliveries`. `platform_events.status` was **not** repurposed —
redefining it would have broken 47 passing Phase 1A tests for no benefit. It is
retained as the Phase 1A relay field and should be retired with that relay.

**Consequence:** two relay mechanisms now coexist — the Phase 1A `OutboxDispatcher`
and the Phase 1B fan-out plus delivery worker. This is duplication, kept only to
preserve the Phase 1A suite, and it should be retired in Phase 1C.

### Confirmed by implementation

- audit insert and delivery completion in **one transaction** — this is what makes
  the crash window not merely unlikely but non-existent
- unique delivery identity as the idempotency guarantee, with **no** change to
  `audit_logs` required
- prospective activation — proven to prevent historical delivery
- no external broker needed
