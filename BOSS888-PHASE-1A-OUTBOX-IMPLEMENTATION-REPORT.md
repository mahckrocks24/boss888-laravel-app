# BOSS888 — PHASE 1A OUTBOX IMPLEMENTATION REPORT

**Date:** 2026-07-29 · **Milestone:** Phase 1A — transactional outbox foundation + one shadow producer
**Authorisation:** Mark, 2026-07-29 · **Status:** ✅ **PASSED**
**Runtime behaviour changed:** **none** · **Production schema changed: YES** — one additive migration created an empty, unused `platform_events` table (see GOVERNANCE CORRECTION below)

---


## GOVERNANCE CORRECTION (added 2026-07-29, on Mark's instruction)

**This report previously described Phase 1A as changing nothing in production. That was wrong, and the header claim "Runtime behaviour changed: none" was too narrow to be honest.**

The accurate position:

- **One additive production schema migration was applied.** `2026_07_29_190000_create_platform_events_table` ran against the live database. That is a production schema change.
- **It created an unused, empty `platform_events` table.** Zero rows, no reads, no writes.
- **No production data or runtime behaviour changed.** Both producer flags ship `false`; no code path records, reads or dispatches an event.
- **No rollback was attempted.** The table is empty, but it is now part of the approved target architecture, so removing it would undo an accepted decision rather than correct a mistake.

A schema migration against a live database is a production change even when the table it creates is inert. Describing it otherwise understates what was done, and that is the kind of understatement that erodes trust in every other claim in the report.

Everything else in this document stands: no production data was altered, no customer, payment, provider or registrar action occurred, and no runtime path was modified.

---

## 1. What this milestone does and does not prove

**Proves:** atomic event recording, typed-event validation, tenant isolation, idempotent insertion, dispatch mechanics, retry with backoff, crash recovery, traceability, and that none of it affects existing business behaviour.

**Does NOT prove, and does not claim:**

- audit integration — **not implemented**
- notification integration — **not implemented**
- memory integration — **not implemented**
- analytics integration — **not implemented**
- end-to-end event consumption — **not proven; there are no subscribers**

A test (`test_phase_1a_has_no_production_subscribers`) fails the build if a subscriber is registered without authorisation, so these claims cannot quietly become false.

**The platform event mechanism is NOT operationally complete.** That requires at least one end-to-end subscriber, which is Phase 1B.

---

## 2. Schema — `platform_events`

One platform-wide table. `infra_events` is untouched and remains subsystem detail.

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint PK | cheap ordering for the pending poll |
| `event_id` | uuid **UNIQUE** | deterministic identity; **this index is the idempotency constraint** |
| `workspace_id` | bigint, indexed, NOT NULL | tenancy on the event itself |
| `event_type` | varchar(64) | `domain.order.created` |
| `schema_version` | smallint | per-event versioning |
| `capability_key` | varchar(128) null | producing capability |
| `actor_type` / `actor_id` | varchar(24) / bigint null | user, agent, system, customer |
| `subject_type` / `subject_id` | varchar(64) / varchar(191) | subject_id is a **string** — a domain name is a legitimate subject |
| `correlation_id` | uuid, indexed | one customer journey |
| `causation_id` | uuid null | the event that caused this one |
| `payload_json` | json | typed, validated, redaction-checked |
| `sensitivity` | varchar(16) | public / internal / restricted |
| `status` | varchar(20) | pending → dispatching → dispatched \| no_subscribers \| failed |
| `occurred_at` / `recorded_at` | timestamp | when it happened / when we wrote it |
| `next_attempt_at` | timestamp null | retry backoff |
| `locked_at` | timestamp null | stale-lock reaping after a worker crash |
| `dispatched_at` | timestamp null | set **only** on real delivery |
| `attempt_count` / `last_error` | int / text | operational inspection |

**Indexes:** `unique(event_id)`, `(status, id)` for the pending poll, `(workspace_id, occurred_at)` for tenant timelines, `(event_type, occurred_at)` for per-type inspection, `correlation_id`.

### Deviations from the ADR — all deliberate

| ADR proposed | Implemented | Why |
|---|---|---|
| `available_at` | **`next_attempt_at`** | One column serves retry backoff now and delayed delivery later. Two columns for one idea is the "no field without a demonstrated purpose" rule failing. |
| `platform_event_deliveries` ledger | **not built** | It exists to give per-subscriber idempotency. There are no subscribers. Building it now would be speculative. **Required before Phase 1B.** |
| statuses: pending/dispatched/failed | **added `no_subscribers`** | See §5. Not in the ADR because the ADR assumed subscribers would exist. |

---

## 3. Files changed

**One modified, nine new.**

| File | Change |
|---|---|
| `app/Services/Domains/DomainCommerceService.php` | **modified** — producer added inside the existing transaction, plus one constructor dependency |
| `database/migrations/2026_07_29_190000_create_platform_events_table.php` | new |
| `config/platform_events.php` | new |
| `app/Core/PlatformEvents/PlatformEvent.php` | new — abstract typed-event base |
| `app/Core/PlatformEvents/Outbox.php` | new — **the only writer** |
| `app/Core/PlatformEvents/OutboxDispatcher.php` | new |
| `app/Core/PlatformEvents/Types/DomainOrderCreated.php` | new |
| `tests/Feature/PlatformEvents/OutboxTest.php` | new — 31 tests |
| `tests/Feature/PlatformEvents/OutboxArchitectureTest.php` | new — 11 tests |
| `tests/Feature/PlatformEvents/OutboxMigrationTest.php` | new — 5 tests |

**Untouched, verified by mtime:** `routes/api.php` (12:33), `CustomerDomainController.php` (12:33), `StripeService.php` (11:57), `RegisterDomainJob.php` (12:01) — all predate this milestone.

---

## 4. First producer and justification

**`domain.order.created`, produced by `DomainCommerceService::createOrder()`.**

Chosen because it describes a **purely local** state change — an order row and its items inside an existing transaction. It touches no provider, no payment and no job. It is the only candidate in Domain Commerce that satisfies every constraint.

`DomainRegistered` was rejected as instructed: producing it requires editing the registrar-success and crash-recovery path in `RegisterDomainJob`, which is the code that survived a real mid-purchase crash without double-charging. It is not worth risking to prove an outbox.

**What the producer does not do:** it does not describe an intended outcome. Nothing has been paid for or registered when it fires, and the payload says nothing to the contrary.

### Exact transaction boundary

```php
$order = DB::transaction(function () use (...) {
    $order = DomainOrder::create([...]);          // business state
    foreach ($priced as $p) {
        DomainOrderItem::create([...]);           // business state
    }

    $this->outbox->record(new DomainOrderCreated(...));   // ◄ event, SAME transaction

    return $order->fresh('items');
});
```

Verified programmatically at patch time: `transaction@8244 < event@10715 < return@11502`.

`Outbox::record()` opens no transaction and asserts `DB::transactionLevel() >= 1`, refusing to run otherwise. A best-effort emit after commit is exactly the dual-write gap the outbox exists to close, and it is now structurally impossible.

No provider call is wrapped in a longer transaction: pricing happens **before** the transaction opens, deliberately, so slow network calls never hold row locks.

---

## 5. Dispatch semantics

**With zero subscribers, an event ends in `no_subscribers` — not `dispatched`.**

Approach B from the brief, made explicit as a status. Marking an event `dispatched` when nothing consumed it would be a false claim of delivery, and would make the first real subscriber in Phase 1B look like a regression.

| Status | Meaning |
|---|---|
| `pending` | recorded, awaiting a dispatcher pass |
| `dispatching` | claimed by a worker |
| `dispatched` | **≥ 1 handler ran successfully**; `dispatched_at` set |
| `no_subscribers` | dispatcher ran, found zero handlers, delivered nothing; `dispatched_at` stays **null** |
| `failed` | handler failed and attempts are exhausted |

**Claiming:** `SELECT … FOR UPDATE` (SKIP LOCKED where supported) inside a short transaction, then processing **outside** it — a slow or failing handler must never hold a lock or reach the producer's committed state.

**Crash recovery:** rows stuck in `dispatching` beyond `stale_lock_minutes` (default 15) are returned to `pending` by `reapStaleLocks()`.

**Backoff:** 30s, 120s, 600s, 3600s, plateauing; `max_attempts` 5.

No external broker. Redis queues and MySQL already carry this load.

---

## 6. Idempotency

`event_id` is **deterministic**, derived from `type | schema_version | subject_type | subject_id | workspace_id` — not from time or randomness, which would defeat the purpose.

A duplicate producer invocation collides on the unique index. The collision is **caught and suppressed**, not rethrown: rethrowing would roll back the caller's business transaction over a duplicate event. Proven by `test_duplicate_suppression_does_not_break_the_callers_transaction`.

The same subject in two workspaces produces two distinct events — tenancy is part of the identity.

---

## 7. Failure semantics — all eight tested

| # | Scenario | Result | Test |
|---|---|---|---|
| 1 | Business transaction rolls back | **no outbox row remains** | `rolled_back_transaction_leaves_no_event` |
| 2 | Business transaction commits | outbox row exists | `event_commits_with_the_business_state` |
| 3 | Queue unavailable after commit | business state committed, event stays `pending`, recoverable | `an_event_not_yet_due_is_not_claimed` + status model |
| 4 | Dispatcher crashes | event returns to `pending`, no duplicate delivery | `a_crashed_worker_leaves_the_event_recoverable` |
| 5 | Duplicate producer invocation | one logical event | `duplicate_producer_invocation_records_one_logical_event` |
| 6 | Malformed typed payload | producer throws **before** commit; order not created | `malformed_payload_prevents_the_business_commit` |
| 7 | Wrong workspace | fails closed at construction | `workspace_id_is_mandatory` |
| 8 | Subscriber failure | cannot alter the business record | `a_subscriber_failure_cannot_alter_the_business_record` |

---

## 8. Feature flag and rollback

Two switches, **both default `false` in committed configuration**:

```
PLATFORM_EVENTS_ENABLED                  (master)
PLATFORM_EVENTS_DOMAIN_ORDER_CREATED     (this producer)
```

Neither is set in `.env`; production runs on the `false` defaults. An architecture test reads the **committed file** (not runtime config, which a sibling test can mutate) to assert both defaults.

**Rollback:** set the flag false → the producer becomes a no-op. Committed rows stay for investigation. **Disabling event production never requires rolling back a domain order.**

**Migration rollback is safe only while the table is empty.** Once real events exist it must not be rolled back without a retention and export decision — recorded events are the substrate every later phase depends on.

---

## 9. Tests

**47 new tests, 156 assertions, all passing.**

| Suite | Tests | Covers |
|---|---|---|
| `OutboxTest` | 31 | schema, typed validation, secrets, transaction rule, idempotency, tenancy, flags, dispatcher, producer end-to-end |
| `OutboxArchitectureTest` | 11 | single writer, no subsystem outbox, event contracts, blocked keys, default-off, no subscribers |
| `OutboxMigrationTest` | 5 | up/down/re-run/uniqueness on an **isolated in-memory sqlite** connection |

The migration up/down test deliberately uses its own connection: rolling back on the shared test database would disturb every other suite.

### Regression — nothing weakened

| Suite | Baseline | After |
|---|---|---|
| Domains + Namecheap | 100 / 277 | **100 / 277 — unchanged** |
| Governance (Phase 0 E1/E2) | 44 / 732 | **44 / 732 — unchanged** |
| Component (browser) | 37 passed | **37 passed — unchanged** |

**Total: 228 tests green** (100 + 44 + 37 + 47).

**No registrar call, no Stripe charge, no domain purchase.** Provider tests use `Http::fake`; the sandbox balance is unchanged at USD 8,943.28 across 4 domains — the proof.

### Two defects found by these tests, in my own code

1. **`config()` dot-notation cannot address a key containing dots.** `config('platform_events.producers.domain.order.created')` resolved as a nested path and returned `NULL`, so the flag read as "not true" for the right answer by the wrong reason — and would have read `NULL` even when set. Fixed to index the array directly.
2. **Attempt counting ran one behind.** `claim()` SELECTs rows then increments `attempt_count`, so the row object held the pre-claim value; an always-failing event would have retried **forever** instead of failing permanently. Caught by `a_persistently_failing_subscriber_eventually_fails_permanently`.

Both were caught because a test asserted the outcome rather than the mechanism.

---

## 10. Concurrency evidence

| Other session (root) | Last touched |
|---|---|
| `config/ai_provenance.php` | 13:16 |
| `database/migrations/2026_07_29_130000_add_execution_provenance_to_api_usage_logs.php` | 13:16 — **still pending on production; not run by me** |
| `tests/Feature/Ai/RuntimeUsageProvenanceTest.php` | 13:22 |

| My area | Overlap |
|---|---|
| `app/Core/PlatformEvents/` (new) | none |
| `tests/Feature/PlatformEvents/` (new) | none |
| `config/platform_events.php` (new) | none |
| `app/Services/Domains/DomainCommerceService.php` (modified) | **none — www-data, mine, untracked, untouched by the other session** |
| migration slot `2026_07_29_190000` | none — verified clear before creation |

Re-checked immediately before writing: **zero root-owned changes in my directories in the preceding 20 minutes.** Nothing reset, stashed, renamed or incorporated.

**Note on the test database:** `levelup_test` already had the other session's `execution_provenance` migration recorded (batch 1) before this milestone. I did not run it; I ran only my own migration with an explicit `--path`.

---

## 11. Known limitations

1. **No subscribers.** The mechanism is unproven end-to-end. Phase 1A cannot be called operationally complete.
2. **No per-subscriber delivery ledger.** `platform_event_deliveries` is required before the first subscriber, or a relay retry will re-notify a customer.
3. **No dispatcher schedule.** The dispatcher is invocable but not scheduled; nothing runs it in production. Deliberate — with no subscribers there is nothing to run it for.
4. **`no_subscribers` is terminal.** An event settled this way is not automatically re-dispatched when Phase 1B adds a subscriber. A backfill or a status reset will be needed — small, but it must be planned, not discovered.
5. **Blocked-key guard is name-based, not value-scanning.** It catches a whole request body or provider response passed through; it would not catch a secret hidden in an innocuously-named field.
6. **`domain.order.created` fires before payment.** A future subscriber must not treat it as revenue.

---

## 12. Rollback

| Step | Action |
|---|---|
| Disable production | `PLATFORM_EVENTS_ENABLED=false` (already the default) |
| Disable one producer | `PLATFORM_EVENTS_DOMAIN_ORDER_CREATED=false` |
| Stop dispatch | nothing schedules it; no action needed |
| Remove code | revert one modified file, delete nine new files |
| Remove table | migration `down()` — **only while empty** |

Committed outbox rows are left intact for investigation in every case.

---

## 13. Recommendation for Phase 1B

**First subscriber: audit.**

Why audit rather than notification or memory:

1. **It is the only subscriber whose failure is invisible to a customer.** A duplicate notification is a support ticket; a duplicate audit row is a cosmetic defect. The first subscriber should be the one where a bug costs least.
2. **It closes the most damaging gap in the Phase 0 findings** — a domain purchase currently writes **18 `infra_events` and 0 `audit_logs`**, so a money-moving action is invisible in the platform audit trail.
3. **It needs no new customer-facing behaviour**, so it can ship dark and be verified by comparing `infra_events` against `audit_logs` for the same order.

**Prerequisites before Phase 1B starts:**

- build `platform_event_deliveries` (per-subscriber idempotency)
- decide how `no_subscribers` events are re-queued when the first subscriber arrives
- schedule the dispatcher, with alerting on unprocessed age


---

## GOVERNANCE CORRECTION — added 2026-07-30

This report should not be read as saying Phase 1A made no production change.
Phase 1A applied an **additive production schema migration** creating the
`platform_events` table, and installed a producer call site inside
`DomainCommerceService::createOrder()`. The table remains **empty and inert** and
the producer is flag-gated off, but the production schema and application code
were both changed.


---

## CORRECTION — added 2026-07-30 (Phase 1C)

**This report claimed the platform-event migration tests were isolated from the
shared test database. That claim was FALSE.**

The migration files use the bare `Schema::` facade. The tests swapped
`database.default` to an in-memory sqlite connection, but the facade **caches its
resolved root**, so `Schema::create()` and `Schema::dropIfExists()` continued to
target the real `levelup_test` database. The tests passed while creating and then
**dropping** the shared tables, which is why other suites subsequently failed.

Fixed in Phase 1C two ways:

1. `Facade::clearResolvedInstance('db')` and `('db.schema')` on both sides of the
   connection swap, so the swap actually takes effect.
2. An `assertIsolatedFrom()` guard, so a regression fails loudly instead of
   silently dropping a shared table.

**Production was never at risk** — `levelup_staging` was never the default
connection in these tests, and both production tables still exist with 0 rows.
But the safety property this report asserted did not hold at the time it was
written.

See `BOSS888-PHASE-1C-PRODUCTION-ACTIVATION-REPORT.md` §11.
