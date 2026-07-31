# BOSS888 — PHASE 1B AUDIT SUBSCRIBER REPORT

**Date:** 2026-07-29/30 · **Milestone:** delivery ledger + `platform.audit` subscriber
**Authorisation:** Mark, 2026-07-29 · **Status:** ✅ **PASSED**
**Production dispatch:** **not scheduled** · **All five flags:** **false**

---

## 1. What is proven

End-to-end consumption, in the test environment:

```
order transaction → platform_events → platform_event_deliveries
                                    → platform.audit → audit_logs
```

The subscriber demonstrably cannot roll back the order transaction, duplicate the logical audit record, lose an event silently, cross a workspace boundary, falsely report delivery, or trigger customer-facing behaviour. Each is a named test.

**Exactly one subscriber** exists: `platform.audit`. No notifications, memory, analytics or executive reporting — asserted, not merely omitted.

---

## 2. Delivery schema — `platform_event_deliveries`

| Column | Purpose |
|---|---|
| `id` | surrogate key |
| `event_id` | the event being delivered (no FK — events are immutable and never deleted; a FK would add lock contention to the fan-out hot path for no integrity gain) |
| `subscriber_key` | e.g. `platform.audit` |
| `subscriber_version` | part of the identity, so a behaviour change permits deliberate re-delivery without reopening settled rows |
| `workspace_id` | denormalised for tenant scoping without a join |
| `status` | pending / processing / delivered / retryable_failure / permanently_failed / skipped |
| `attempt_count`, `next_attempt_at` | bounded retry with backoff |
| `claimed_at` | stale-claim reaping after a worker crash |
| `delivered_at`, `failed_at` | outcome timestamps |
| `last_error` | inspectable failure |
| `skip_reason` | why a row was skipped — `skipped` is never a mystery |

**Unique constraint `ped_identity_unique` on `(event_id, subscriber_key, subscriber_version)`** — the hard idempotency guarantee.

**Indexes:** `(subscriber_key, status, next_attempt_at)` for the claim path, `(workspace_id, status)` for operational inspection.

### Event-level vs delivery-level state

The event row must not pretend to a single delivery outcome when N subscribers may disagree. Phase 1B therefore added **`platform_events.fanned_out_at`** and **`delivery_count`** — additively.

`platform_events.status` was **deliberately not repurposed**. Phase 1A wrote to it and its tests assert on it; redefining a column's meaning to save two new fields would have broken a passing suite for no benefit. The distinction is now:

- **event row** — has fan-out happened, and how many delivery rows resulted
- **delivery rows** — what each subscriber actually did

A test asserts `platform_events` carries no `subscriber_key`, `subscriber_version` or `delivered_to` column.

---

## 3. Subscriber declaration model

`SubscriberDeclaration` — validated on construction, declared **only** in `SubscriberRegistry`:

| Field | `platform.audit` |
|---|---|
| `key` | `platform.audit` |
| `version` | 1 |
| `accepts` | `domain.order.created` → schema `[1]` |
| `activatedAt` | **2026-07-29 20:00:00** |
| `maxAttempts` | 5 |
| `backoff` | 30s, 120s, 600s, 3600s |
| `sensitivityAllowance` | public, internal, restricted |
| `tenancy` | `workspace_scoped` |
| `unsupportedSchemaPolicy` | `skip` |
| `emitsCustomerFacingSideEffects` | **false** |

Audit is the one subscriber that legitimately sees restricted data — an audit trail with gaps is not an audit trail.

**A subscriber is ACTIVE only when** it is declared **and** `fanout.enabled` is true **and** its own flag is true. All three. A declaration alone changes nothing.

---

## 4. Activation behaviour — prospective only

Fan-out compares `event.recorded_at` against `activatedAt`. An event recorded **before** activation receives **no delivery row**.

This is the rule that stops a future notification subscriber emailing customers about three-month-old orders the moment it is wired up. Historical events are reachable **only** through an explicit, approved replay.

Tested: `test_an_event_predating_activation_receives_no_delivery`.

---

## 5. Fan-out behaviour

Producers never learn which subscribers exist — their responsibility ends at recording the typed event. An architecture test fails the build if any file under `app/Services/Domains/`, `app/Jobs/` or `app/Http/Controllers/` references a subscriber, the registry, the worker or the delivery table.

| Property | Mechanism |
|---|---|
| Idempotent | insert against the unique identity; a collision is swallowed |
| Tenant-aware | `workspace_id` copied from the event and re-verified at execution |
| Schema-aware | an unsupported version becomes `skipped` (or `permanently_failed` per declaration) with a reason — **never silent success** |
| Activation-aware | events before `activatedAt` are ignored |
| Concurrency-safe | duplicate inserts collide on the unique index |
| Recoverable | `fanned_out_at IS NULL` is the resumable work queue |

`delivery_count = 0` is meaningful: fan-out ran and nothing was eligible.

---

## 6. Audit mapping — explicit, not a copy

Traceability recorded in `audit_logs.metadata_json`: `source`, `event_id`, `event_type`, `schema_version`, `correlation_id`, `causation_id`, `capability_key`, `actor_type`, `actor_id`, `subject_type`, `subject_id`, `occurred_at`, `subscriber_key`, `subscriber_version`.

| audit_logs column | Source |
|---|---|
| `workspace_id` | `event.workspace_id` |
| `user_id` | `event.actor_id` **only when `actor_type === 'user'`** — an agent or system actor is never misattributed to a person |
| `action` | explicit map: `domain.order.created` → `domain.order.created` |
| `entity_type` | `event.subject_type` |
| `entity_id` | `event.subject_id` **only when numeric** — a domain-name subject goes to metadata rather than being coerced into a wrong integer |
| `metadata_json` | mapped fields only |

**Claims discipline.** The summary reads *"Domain order #N created with M domain(s)"*. Tests assert it does **not** contain "paid", "registered" or "owns", and that `detail.payment_state = 'not_paid_at_time_of_event'`.

**Not a payload copy.** A test adds an `unmapped_future_field` to the payload and asserts it never reaches `audit_logs`.

---

## 7. Idempotency guarantee

**One logical audit output per `(event_id, subscriber_key, subscriber_version)`**, guaranteed by two mechanisms together:

1. **A database unique constraint** on the delivery identity.
2. **The audit insert and the `delivered` status write share ONE transaction.**

`audit_logs` was **not** altered — no new column, no new index, no change to 7,678 existing rows. The brief allowed adding a unique constraint there; it was not needed, and modifying a shared 17-caller table for a guarantee available additively would have been the riskier choice.

**Explicitly not "check then insert".** No read-then-write race exists, because there is no read.

---

## 8. Transaction and crash recovery

**Do the audit insert and delivery completion share one transaction? YES.**

```php
DB::transaction(function () {
    SubscriberRegistry::handler($key)->handle($event, $payload);   // audit insert
    // mark the delivery 'delivered'
});
```

**How is a crash between the audit insert and `delivered` handled?** It cannot happen — there is no "between". They are one commit: either both exist or neither does.

**How does uniqueness guarantee recovery without duplicates?** A crash after the claim but before that commit leaves the row `processing` with the audit insert rolled back. `reapStaleClaims()` returns it to `pending` after 15 minutes and the retry runs cleanly against an empty slate. Tested: `test_a_crash_after_claim_recovers_without_duplicating_the_audit_row` asserts **exactly one** audit row after recovery.

**How are stale claims recovered?** `status = processing AND claimed_at < now() - 15min` → back to `pending`.

**Concurrency.** The claim is a single atomic compare-and-swap (`UPDATE … WHERE status IN (pending, retryable_failure)`, affected-rows = 1). Two racing workers produce one claim, one audit row, one delivery row. Tested.

**The source event and the originating domain order are never modified.** Tested.

### Failure classification

| Failure | Classification | Why |
|---|---|---|
| Integrity constraint (SQLSTATE 23000) | **permanent** | the referenced row will not appear because we retried |
| Invalid JSON / data too long (22032 / 22001) | **permanent** | a data-shape problem does not self-heal |
| `PermanentSubscriberFailure` | **permanent** | only the subscriber knows its own logic can never succeed |
| Anything else | retryable to `maxAttempts` | |

`PermanentSubscriberFailure` was added because a test exposed a real gap: an unmapped event type was being retried five times when no retry could invent a mapping.

---

## 9. Failure semantics — all twelve tested

| # | Scenario | Result |
|---|---|---|
| 1 | event + active subscriber | one `pending` delivery |
| 2 | event predates activation | **no** delivery created |
| 3 | duplicate fan-out | no duplicate row |
| 4 | subscriber succeeds | one audit row, delivery `delivered` |
| 5 | crash before audit insert | no audit row, delivery retryable |
| 6 | crash after insert, before completion | **impossible** — one transaction; recovery yields exactly one audit row |
| 7 | invalid workspace | fails closed, `permanently_failed`, **no cross-tenant audit row** |
| 8 | unsupported schema version | `skipped` with a reason, no audit row, never success |
| 9 | poison event | bounded retries → `permanently_failed`, error inspectable |
| 10 | dispatcher unavailable | event and pending delivery both preserved |
| 11 | concurrent workers | one logical result |
| 12 | subscriber disabled | delivery stays `pending`, no false delivery |

---

## 10. Tests

**103 tests / 364 assertions in `tests/Feature/PlatformEvents/`** — 47 carried from Phase 1A **unchanged**, **56 added**.

| Suite | Tests |
|---|---|
| `DeliveryTest` | 34 — schema, fan-out, audit delivery, idempotency, crash window, tenancy, no-side-effects, full chain |
| `SubscriberGovernanceTest` | 17 — declarations, centralisation, producer isolation, flags, replay guards, state separation |
| `DeliveryMigrationTest` | 5 — up/down/re-run/uniqueness on isolated in-memory sqlite |
| Phase 1A (unchanged) | 47 |

### Regression — nothing weakened

| Suite | Baseline | After |
|---|---|---|
| Governance (Phase 0 E1/E2) | 44 / 732 | **44 / 732 — unchanged** |
| Domains + Namecheap | 100 / 277 | **100 / 277 — unchanged** |
| Component (browser) | 37 | **37 — unchanged** |

**Total 284 tests green.**

Two Phase 1A test **allow-lists** were extended — not weakened. `OutboxArchitectureTest::TABLE_WRITERS` gained the three Phase 1B platform-event components that legitimately read the events table, and the producer-count regex was scoped to the `producers` block after Phase 1B added a `subscribers` block with the same dotted key shape. The assertions themselves are unchanged and still fail for any unauthorised file.

### Four defects these tests found

1. **`audit_logs` has FOREIGN KEYS to `users.id` and `workspaces.id`.** Discovered when every audit write failed against a test database with zero users and zero workspaces. **This is a production consideration, not just a test problem:** an event whose workspace is deleted after recording can never be audited. It now fails permanently and visibly instead of retrying.
2. **An FK violation was being retried.** Fixed by failure classification.
3. **An unmapped event type was being retried.** Fixed by `PermanentSubscriberFailure`.
4. **`workspaces` requires `slug` and `created_by`**, and `created_by` references `users.id` — so fixtures must insert the user first. My first fixture had the order wrong.

---

## 11. Files changed

**One modified, twelve new.**

| File | Change |
|---|---|
| `config/platform_events.php` | **modified** — four new flag groups |
| `database/migrations/2026_07_29_200000_create_platform_event_deliveries_table.php` | new |
| `database/migrations/2026_07_29_200100_add_fanout_fields_to_platform_events.php` | new |
| `app/Core/PlatformEvents/SubscriberDeclaration.php` | new |
| `app/Core/PlatformEvents/SubscriberRegistry.php` | new |
| `app/Core/PlatformEvents/Subscribers/AuditSubscriber.php` | new |
| `app/Core/PlatformEvents/EventFanOut.php` | new |
| `app/Core/PlatformEvents/DeliveryWorker.php` | new |
| `app/Core/PlatformEvents/EventReplay.php` | new — designed, **not exposed** |
| `app/Core/PlatformEvents/PermanentSubscriberFailure.php` | new |
| `tests/Feature/PlatformEvents/DeliveryTest.php` | new |
| `tests/Feature/PlatformEvents/SubscriberGovernanceTest.php` | new |
| `tests/Feature/PlatformEvents/DeliveryMigrationTest.php` | new |

**Untouched:** `routes/api.php` (12:33), `CustomerDomainController.php` (12:33), `RegisterDomainJob.php` (12:01), `StripeService.php` (11:57), `NamecheapRegistrarConnector.php` (12:33) — all predate this milestone. No PTAA file touched.

---

## 12. Feature flags

**Five independent switches, all `false` in committed configuration:**

```
PLATFORM_EVENTS_ENABLED                  master
PLATFORM_EVENTS_DOMAIN_ORDER_CREATED     producer
PLATFORM_EVENTS_FANOUT_ENABLED           fan-out
PLATFORM_EVENTS_DELIVERY_ENABLED         delivery worker
PLATFORM_EVENTS_SUBSCRIBER_AUDIT         the audit subscriber
```

None is set in `.env`. A test reads the **committed file** — runtime config is mutated by sibling tests, so asserting on it would prove nothing about what ships.

---

## 13. Deployment state

| Item | State |
|---|---|
| Migrations | applied to **production** and `levelup_test` |
| `platform_events` rows (production) | **0** |
| `platform_event_deliveries` rows (production) | **0** |
| Audit rows generated in production | **0** |
| Producer | disabled |
| Fan-out | disabled |
| Delivery worker | disabled |
| Audit subscriber | disabled |
| Scheduled dispatch | **none — nothing runs any of it** |

Two additive production schema migrations were applied. Nothing executes.

---

## 14. Rollback

| Step | Action |
|---|---|
| Disable everything | all five flags already default false |
| Stop delivery | nothing is scheduled |
| Remove code | revert one config file, delete twelve new files |
| Remove schema | `down()` on both migrations — **only while both tables are empty** |

Recorded rows are always preserved for investigation. **Disabling any of this never requires touching a domain order.**

Once real events or deliveries exist, neither migration may be rolled back without a retention and export decision.

---

## 15. Concurrency evidence

| Other session (root) | Last touched |
|---|---|
| `config/ai_provenance.php` | 13:16 |
| `2026_07_29_130000_add_execution_provenance_to_api_usage_logs.php` | 13:16 — **still pending on production; not run by me** |
| `tests/Feature/Ai/RuntimeUsageProvenanceTest.php` | 13:22 |

Zero root-owned changes in my directories in the 20 minutes before writing. Migration slots `2026_07_29_20*` and `21*` verified clear before creation. All eight target paths confirmed non-existent. Nothing reset, stashed, renamed or absorbed.

---

## 16. Known limitations

1. **Nothing is scheduled.** Fan-out and delivery are invocable but no cron or worker runs them. Deliberate: production dispatch requires separate authorisation.
2. **No production execution has occurred.** End-to-end consumption is proven in the test environment only; production has 0 events, 0 deliveries, 0 generated audit rows.
3. **`audit_logs` FKs are a real constraint.** An event for a deleted workspace or user can never be audited. It fails permanently and visibly — but the audit gap is unrecoverable for that event.
4. **`no_subscribers` events from Phase 1A are not backfilled.** Fan-out only considers `fanned_out_at IS NULL`; Phase 1A rows settled by the legacy relay are eligible, but any event predating activation is not. Zero such rows exist in production.
5. **Two relay mechanisms coexist.** The Phase 1A `OutboxDispatcher` (in-memory subscribers, unscheduled, no production consumers) remains alongthe Phase 1B fan-out + delivery worker. Retaining it keeps 47 Phase 1A tests unchanged, but it is duplication and should be retired in Phase 1C.
6. **Replay is code-only.** Correct for now, but it means a replay currently requires a developer with a console.
7. **Backfill decision still open.** If a subscriber is activated later, events between the previous and new activation timestamps need an explicit replay.

---

## 17. Unresolved decisions

1. **When to enable production dispatch** — needs the producer, fan-out, worker and subscriber flags on, plus a schedule and alerting on unprocessed age.
2. **How to expose replay** — artisan command with confirmation, or admin-only endpoint. Currently neither.
3. **Retiring `OutboxDispatcher`** (limitation 5) — and accepting that 47 Phase 1A tests would then need rewriting.
4. **Audit gaps for deleted tenants** (limitation 3) — accept, or relax the `audit_logs` FKs.
5. **Alerting thresholds** for `permanently_failed` deliveries and unprocessed age.

---

## 18. Recommended Phase 1C

**Controlled production activation of this exact chain — not a second subscriber.**

Why activation before notification:

1. **Nothing in production has ever executed.** Adding a second subscriber before the first has run once in production doubles the untested surface.
2. **Activation is where the real risks are** — scheduling, unprocessed-age alerting, worker supervision, and the `audit_logs` FK behaviour under real tenants. None can be proven by tests.
3. **Audit is the safest possible first production consumer.** Its worst failure is a missing audit row; a notification subscriber's worst failure is contacting a customer.

Sequence: enable in a single internal workspace → produce a handful of real events → verify audit parity against `infra_events` → schedule fan-out and delivery with alerting → soak → then propose notification as Phase 1D.

**Notification should be Phase 1D**, and it needs one thing Phase 1B did not: `emitsCustomerFacingSideEffects = true` is already declared in the model and blocks replay — that guard exists and is tested, but it has never been exercised against a real customer-facing subscriber.


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
