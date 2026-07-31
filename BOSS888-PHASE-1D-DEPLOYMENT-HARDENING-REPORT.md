# BOSS888 — PHASE 1D DEPLOYMENT HARDENING REPORT

**Date:** 2026-07-30 · **Status:** ✅ **PASSED**
**Runtime symbol compatibility is now verified before deployment** — `platform-events:verify`, 15 checks
**Event #1 `delivery_count`: 0 → 1** · **All five projections match the ledger**
**Scheduler failures return non-zero AND degrade health** · **Temporary guards: still retired**
**No provider, payment, registrar, notification or memory action occurred**

---

## 1. Production failure timeline

| Time (UTC) | Event |
|---|---|
| ~10:17–10:20 | I applied the Phase 1C.1 patch set. My patcher reloaded each file from disk before every replacement, so only the **last** edit per file survived. `EventFanOut` kept `SubscriberRegistry::active()` after that method had been deleted; `SubscriberDeclaration` gained helpers reading `$this->retiredAt` with no such property. |
| 10:20:02 | The scheduler dispatched `platform-events:process`. The scheduler log records `DONE` — that is the **dispatch**, not the outcome. |
| **10:20:05** | The command threw and caught: `staging.ERROR: [PlatformEvents] processing pass threw {"error":"Call to undefined method App\Core\PlatformEvents\SubscriberRegistry::active()"}`. It returned `self::FAILURE`. |
| 10:20:07 | The schedule's `onFailure` hook fired: `staging.ERROR: platform-events:process cron failed`. |
| ~10:21 | I grepped for stale `active()` references after the patch had reported success, and found the call site at `EventFanOut.php:45`. |
| ~10:22 | Repaired with a write-once patcher; the command ran clean immediately. |
| 10:25:03 | Next scheduled cycle clean — **and `--health` reported `severity: OK` with no trace of the failure.** |

**Impact: one lost scheduled cycle, no data effect.** The throw happened at the subscriber-selection line, before any event was claimed or stamped, so nothing was marked fanned out and no delivery row was created or corrupted.

### Correction to what I reported in Phase 1C.1

I said the command's own try/catch contained and logged the failure. That was true but incomplete: **the schedule's `onFailure` hook also fired**, so there were two error log lines and a non-zero exit status. Log visibility was never the gap. The gap was narrower and worse — **anyone who looked at health afterwards saw a clean system.**

---

## 2. Root cause

Two independent defects, both mine:

**(a) The patch tooling silently discarded edits.** The helper read the file inside itself:

```python
def sub(rel, old, new, label):
    s = load(rel)          # ← reloads from disk on EVERY call
    ...
    return s.replace(old, new, 1), True
```

Calling it three times for one file produced three independent transformations of the *original* text, and only the last was written. Each call reported success. Nothing reported that the earlier two had been thrown away.

**(b) Nothing proved runtime symbol compatibility.** The change was validated with `php -l` and a targeted test run. Neither can see a deleted method that is still called, and the file that contained the stale call was not exercised by the tests I ran at that moment.

The two together are what reached production: (a) created the inconsistency, (b) failed to detect it, and a five-minute cron executed it.

---

## 3. Why `php -l` was insufficient

`php -l` answers one question: *can this file be parsed?* It says nothing about whether the symbols the file names exist. All three of these are syntactically valid PHP and all three are runtime fatals:

```php
SubscriberRegistry::active();          // undefined static method   ← the 10:20:05 failure
SubscriberRegistry::STATE_IMAGINARY;   // undefined class constant
$this->retiredAt;                      // undefined property
```

Both files in the broken state passed `php -l` cleanly. The second one is why I now also check constants by reflection: after adding the four `STATE_*` constants I verified them with `ReflectionClass::getConstant()` rather than assuming, precisely because `php -l` had already proved it could not tell me.

Grep is not a substitute either. It cannot distinguish a call site from prose: an early version of this gate reported failures for class names appearing in its own docblocks and inside the deleted-API needle string. A gate that cries wolf trains an operator to ignore it, so the final implementation **tokenises** the source with `token_get_all()`, which yields comments and string literals as distinct token types and excludes them by construction.

---

## 4. Verification-gate design

**`php artisan platform-events:verify`** — exit 0 = safe to deploy, non-zero = at least one check failed.

Implemented as `App\Core\PlatformEvents\ChainVerifier` (logic, callable from tests) plus a thin command wrapper.

### Strictly read-only

Produces no event, fans out nothing, executes no subscriber, updates no row, queues no job, and contacts no registrar, payment provider or notification channel. Proven by test, not asserted: `test_verification_writes_nothing` snapshots events, deliveries, audit rows and one event's `fanned_out_at` across a full run of both the class and the command.

Its single side effect is a one-second probe lock on **`platform-events:verify-probe`** — deliberately a different key from `platform-events:process`, so verification can never block, steal or contend for a real run. `test_the_verify_command_never_touches_the_processing_lock` enforces that.

### The 15 checks

| Check | Proves |
|---|---|
| `command_resolvable` | the command class resolves from the container |
| `command_registered` | `platform-events:process` exists in the artisan registry |
| `scheduler_points_at_existing_command` | every scheduled `platform-events` entry names a command that exists |
| **`chain_symbols_resolve`** | **every `Class::method()` and `Class::CONST` reference in 15 chain sources resolves by reflection** — the 10:20:05 check |
| `deleted_registry_api_unreferenced` | no **call site** anywhere in `app/` or `tests/` calls a removed `SubscriberRegistry` API |
| `subscriber_handlers_callable` | every configured handler class exists and its method exists and is public |
| `event_classes_valid` | every mapped event class exists, extends `PlatformEvent`, and its `type()` agrees with its map key |
| `subscriber_schema_acceptance` | every accepted version is a positive integer, and each subscriber accepts the version currently **produced** |
| `subscriber_state_constants` | the four `STATE_*` constants exist and are distinct |
| `subscriber_states_resolve` | `state()` returns one of them for every declared subscriber |
| `flag_configuration_valid` | every flag is a boolean and every subscriber flag names a declared subscriber |
| `workspace_allowlist_valid` | entries are positive integers and an empty list still permits nothing |
| `tables_available` | `platform_events`, `platform_event_deliveries`, `audit_logs`, `workspaces`, `users` exist |
| `lock_backend_available` | the probe lock can be acquired and released |
| **`delivery_count_projection_consistent`** | **every event's projection equals its ledger row count** |

Two of Boss's requirements are covered by tests rather than by the gate, because the gate must not process anything: *"the command can execute a no-op pass with all production flags off"* (`test_the_command_is_a_noop_with_all_flags_off`) and *"…with an empty queue"* (`test_the_command_is_a_noop_with_an_empty_queue_of_work`).

### Configuration that is actually resolved

Boss warned against *"configuration strings that are never resolved."* Two new maps were added and both are **load-bearing**, not documentation:

```php
'event_classes' => ['domain.order.created' => DomainOrderCreated::class],
'subscriber_handlers' => ['platform.audit' => [AuditSubscriber::class, 'handle']],
```

- `Outbox::record()` refuses an event whose type has no declared class, or whose class disagrees with the declared one (`test_the_outbox_refuses_an_event_type_with_no_declared_class`).
- `DeliveryWorker` now invokes **the configured callable** via `SubscriberRegistry::handlerCallable()` instead of a hardcoded `->handle()`, so the gate checks the same thing the worker executes. Previously the handler was a hardcoded `match`, which cannot be verified against anything.

### First run found a real defect

The gate's first execution reported **14 OK, 1 FAIL** — and the failure was genuine:

```
[FAIL] delivery_count_projection_consistent  1 event(s) disagree with the ledger:
                                             #1 projected 0 vs ledger 1
```

It found the Part B defect independently, before I repaired it, and refused to pass while it stood.

---

## 5. Patch-safety controls

The required rule is implemented as the only patch function used in this milestone:

1. **load once** into one in-memory string
2. **pass 1** — verify every expected fragment occurs **exactly once**; if any count is 0 or >1, **fail without writing**
3. **pass 2** — apply every transformation to that same string
4. assert each edit changed the string and its expected marker is now present
5. **write once**
6. syntax gate, with automatic restore of the original on failure
7. runtime symbol gate (`platform-events:verify`)
8. focused tests, then the full suite
9. inspect the result

Structurally it cannot repeat the Phase 1C.1 failure: the file is read once and never reloaded between edits.

### It caught two of my mistakes in this milestone

- **A wrong `present` marker.** I wrote `"'processing_failures' => \$processing,"` in Python; `\$` is not an escape, so the marker contained a literal backslash and could never match. The gate refused the write rather than producing a half-applied file.
- **A bug in my own strictness check.** My first version verified an edit by counting the old fragment *after* replacing. That is wrong whenever the new text legitimately **contains** the old text — e.g. adding a constant after an existing line. It failed a correct edit. I fixed the check (compare before/after strings and assert the new marker) rather than weakening the anchor.

Both are recorded because they are the same class of problem as the original defect: tooling that reports success it has not earned.

### Permanent detection

- `chain_symbols_resolve` — the general case, by reflection over tokenised source.
- `deleted_registry_api_unreferenced` — the specific removed API, call sites only.
- `test_a_deleted_registry_method_reference_fails_verification` — proves the gate **fails** on a real call site.
- `test_a_mention_in_a_comment_or_string_does_not_fail_verification` — proves it does **not** fire on prose, so it stays trustworthy.

---

## 6. Scheduled command failure semantics

Containment is preserved — one bad pass must not take the scheduler down — but the failure can no longer be normalised into a clean-looking system.

| Required | Mechanism | Test |
|---|---|---|
| Non-zero command exit status | `return self::FAILURE` (already present) | `test_an_unexpected_processing_exception_returns_non_zero` |
| Structured error log | `Log::error` with `error` **and** `exception` class | timeline above, plus the command source |
| Event-system health degradation | recorded state → `EventSystemHealth` finding → severity **CRITICAL** within an hour, **WARNING** after | `test_an_unexpected_processing_exception_degrades_health` |
| Operator-visible failure count | `processing_failures.count`, with `first_at` and `last_at` | `test_repeated_failures_are_counted` |
| No event stamping or partial claim | the throw precedes any claim | `test_an_unexpected_exception_performs_no_partial_stamping` |
| No deletion of evidence | row counts unchanged across a failed pass | `test_an_unexpected_exception_deletes_no_evidence` |

**A successful run deliberately does NOT clear the record.** That is the whole point: at 10:25:03 a clean cycle made a real failure invisible. Clearing is now an explicit operator action:

```
php artisan platform-events:process --ack-failures
```

`test_a_later_successful_pass_does_not_erase_the_failure` locks that behaviour in, and `test_the_failure_state_is_cleared_only_by_explicit_acknowledgement` proves the acknowledgement path works.

**No customer notification path was created.** Visibility is the existing internal health report plus `Log::error`.

**The failure record carries no payload and no secret** — exception class and message only, truncated to 400 characters, verified by `test_the_failure_record_carries_no_payload_or_secret`.

**Failure bookkeeping can never mask a failure:** `recordFailure()` is wrapped in its own try/catch, because by the time it runs the non-zero exit and the log line are already in place.

---

## 7. Replay projection correction

### The rule

`platform_event_deliveries` **is and remains authoritative.** `platform_events.delivery_count` is a denormalised cache of it. Every writer now **derives** the value:

```php
public static function sync(string $eventId): int
{
    $count = (int) DB::table('platform_event_deliveries')->where('event_id', $eventId)->count();
    DB::table('platform_events')->where('event_id', $eventId)->update(['delivery_count' => $count]);
    return $count;
}
```

Deliberately **not** an increment. Counting rows makes the operation idempotent: any number of replays, in any order, after any duplicate-suppressed insert, yields the same number. `test_the_projection_is_derived_not_incremented` asserts the source contains no `increment(` and no `delivery_count + 1`.

### Atomic with delivery creation

```php
DB::transaction(function () use ($event, $decl): void {
    DB::table('platform_event_deliveries')->insert([...]);
    DeliveryProjection::sync((string) $event->event_id);
});
$created++;   // only after the transaction commits
```

The counter is incremented **after** commit, so a rolled-back write is never reported as created. On a duplicate-suppressed insert the projection is still synchronised, because a suppressed insert must not leave a stale count behind — and since `sync()` counts rows, that cannot overcount.

### A second drift path, also fixed

`EventFanOut` wrote `delivery_count = $created + $skipped` — what *that pass* inserted. Re-running fan-out over an event whose rows already existed inserted nothing, so a correct count was rewritten to **0**. This was a latent bug that had not yet fired in production; `test_re_running_fanout_does_not_reset_a_correct_count_to_zero` now covers it.

### Scope of the new writer

`DeliveryProjection` is allow-listed in two architecture tests, with the permission bounded and enforced: exactly one `->update()`, setting exactly one column, no insert, delete, truncate, upsert or raw statement, and never a write to the ledger it reads. `test_the_projection_writer_only_touches_delivery_count` enforces every part of that. The invariant those tests protect — *events are created only by the Outbox* — is untouched: maintaining a derived counter on an existing row does not create an event.

---

## 8. Event #1 — before and after

```
BEFORE
  event #1  order 3  projected=0  ledger=1   <<< MISMATCH
  audit(): checked=5 drifted=1
    drifted: event row #1 (4c315a96…) projected=0 ledger=1

REPAIR (repairDrifted — touches only events that disagree)
  4c315a96… : delivery_count 0 -> 1

AFTER
  event #1  order 3  projected=1  ledger=1   MATCH
```

| Guarantee | Result |
|---|---|
| Exactly one event changed | ✅ `changed: #1 0->1` |
| No delivery created | ✅ deliveries 5 → 5 |
| No audit row created | ✅ `audit_all` 7704 → 7704, `audit_from_events` 5 → 5 |
| No replay performed | ✅ the repair only recomputes counts |
| No notification / memory write | ✅ 3109 → 3109, 41 → 41 |
| Idempotent | ✅ a second repair changed **0** events |

---

## 9. All five projection-to-ledger comparisons

```
event #1  order 3  projected=1  ledger=1  delivered=1  MATCH
event #2  order 4  projected=1  ledger=1  delivered=1  MATCH
event #3  order 5  projected=1  ledger=1  delivered=1  MATCH
event #4  order 6  projected=1  ledger=1  delivered=1  MATCH
event #5  order 7  projected=1  ledger=1  delivered=1  MATCH

all match: YES        audit(): checked=5 drifted=0
duplicate event_ids: 0    duplicate deliveries: 0    events outside ws1: 0
```

The gate now reports `delivery_count_projection_consistent: 5 event(s) match the delivery ledger`.

---

## 10. Files changed

### New (3)

| File | Purpose |
|---|---|
| `app/Core/PlatformEvents/DeliveryProjection.php` | `sync()` derives one event's count from the ledger; `audit()` compares read-only; `repairDrifted()` fixes only what disagrees |
| `app/Core/PlatformEvents/ChainVerifier.php` | the 15-check gate; tokenises chain sources and resolves every symbol by reflection |
| `app/Console/Commands/PlatformEventsVerifyCommand.php` | `platform-events:verify`, read-only wrapper, non-zero on failure |

### Modified (6)

| File | Change |
|---|---|
| `app/Core/PlatformEvents/EventFanOut.php` | projection derived from the ledger instead of `created + skipped` |
| `app/Core/PlatformEvents/EventReplay.php` | delivery insert + projection sync in **one** transaction; `$created` incremented only after commit; suppressed insert still synchronises |
| `app/Core/PlatformEvents/SubscriberRegistry.php` | `handlerCallable()` resolves the handler from configuration, fail-closed at every step; `handler()` delegates to it |
| `app/Core/PlatformEvents/DeliveryWorker.php` | invokes the **configured** callable rather than a hardcoded `->handle()` |
| `app/Core/PlatformEvents/Outbox.php` | `assertDeclaredEventClass()` makes `event_classes` load-bearing; payload-secret refusal reordered to run **first** |
| `app/Core/PlatformEvents/EventSystemHealth.php` | reads the recorded processing-failure state, adds `processing_failures` to the report and degrades severity |
| `app/Console/Commands/PlatformEventsProcessCommand.php` | records failures durably; `--ack-failures`; richer error log |
| `config/platform_events.php` | `event_classes` and `subscriber_handlers` maps |

**No migration. No schema change. No new table or column.**

### Tests (5 files)

| File | Change |
|---|---|
| `tests/Feature/PlatformEvents/DeploymentVerificationTest.php` | **new, 27 tests** |
| `tests/Feature/PlatformEvents/ReplayProjectionTest.php` | **new, 15 tests** |
| `OutboxArchitectureTest.php` | allow-list gains `ChainVerifier` (read-only) and `DeliveryProjection` (one column) with the reasoning recorded |
| `SubscriberGovernanceTest.php` | ledger allow-list gains both as readers |
| — | *(no existing assertion was weakened; see §12)* |

---

## 11. Tests

| # | Requirement | Test |
|---|---|---|
| 1 | Deleted registry method reference fails verification | `test_a_deleted_registry_method_reference_fails_verification` |
| 2 | Missing subscriber handler class fails | `test_a_missing_subscriber_handler_class_fails_verification` |
| 3 | Non-callable handler fails | `test_a_non_callable_subscriber_handler_fails_verification` (+ `test_the_worker_refuses_a_handler_that_is_not_callable`) |
| 4 | Missing event class fails | `test_a_missing_event_class_fails_verification` (+ `..._whose_type_disagrees_...`) |
| 5 | Missing referenced constant fails | `test_a_missing_referenced_constant_fails_verification` |
| 6 | Scheduled command resolves | `test_the_scheduled_command_resolves` |
| 7 | Scheduler references the correct command | `test_the_scheduler_must_point_at_an_existing_command` (both directions) |
| 8 | Unexpected exception returns non-zero | `test_an_unexpected_processing_exception_returns_non_zero` |
| 9 | Unexpected exception degrades health | `test_an_unexpected_processing_exception_degrades_health` |
| 10 | No partial stamping | `test_an_unexpected_exception_performs_no_partial_stamping` |
| 11 | Replay creates one delivery and syncs the projection | `test_replay_creates_one_delivery_and_synchronises_the_projection` |
| 12 | Duplicate replay does not overcount | `test_duplicate_replay_does_not_overcount` (three executions, count stays 1) |
| 13 | Failed replay rolls back ledger and projection | `test_a_rolled_back_replay_leaves_neither_ledger_nor_projection_changed` |
| 14 | Repair changes only incorrect events | `test_projection_repair_changes_only_incorrect_events` (2 correct + 1 wrong; only the wrong one is touched) |
| 15 | Phase 0 → 1C.1 remain green | full suites below |

Additional guards worth naming: `test_a_mention_in_a_comment_or_string_does_not_fail_verification`, `test_a_later_successful_pass_does_not_erase_the_failure`, `test_verification_writes_nothing`, `test_the_verify_command_never_touches_the_processing_lock`, `test_the_projection_writer_only_touches_delivery_count`, `test_the_ledger_remains_authoritative`, `test_re_running_fanout_does_not_reset_a_correct_count_to_zero`, `test_the_failure_record_carries_no_payload_or_secret`.

### Suite results

| Suite | Result |
|---|---|
| **Platform events (0 → 1D)** | **197 tests / 4,133 assertions OK** (+42 this milestone) |
| Governance (Phase 0 E1/E2) | **44 / 732 OK** |
| Domains + Namecheap | **100 / 277 OK** |
| Component (node) | **37 passed, 0 failed** |

**378 tests green.** Shared `levelup_test` tables verified present after the run.

**Test-cache isolation checked before writing the failure tests:** `phpunit.xml` forces `CACHE_STORE=array`, so a test recording a failure state cannot leak into the production Redis health record. I verified that rather than assuming it, because these tests deliberately write a failure marker.

---

## 12. Three tests that had to change, and why

Adding the load-bearing checks broke three existing tests. Each was a real consequence, and each got a correct fix rather than a relaxed assertion:

1. **`OutboxTest::test_blocked_payload_keys_are_refused`** — it records a fabricated type `test.secret.event` with a secret in the payload. My new declared-class assertion fired first, so the refusal reason changed from "blocked list" to "no declared class". **Fix: reordered `Outbox::record()` so the payload-secret refusal runs first.** That is the correct order on its own merits — if both are wrong, the secret is the one that must be reported, or the caller fixes the type and re-submits the secret. The test's assertion is unchanged.

2 & 3. **`OutboxArchitectureTest` and `SubscriberGovernanceTest` allow-lists** — they enforce "only the Outbox writes `platform_events`" and "only fan-out, the worker and replay touch the ledger". `ChainVerifier` and `DeliveryProjection` are new files those lists could not know about. **Fix: added both, with the permission stated and bounded**, plus a new test that proves `DeliveryProjection` performs exactly one update of exactly one column and never writes to the ledger. The invariant is intact: events are still created only by the Outbox.

---

## 13. Live scheduler evidence

Two full cycles after deployment, from `/var/log/laravel-scheduler.log`:

```
2026-07-30 11:25:03  Running ['artisan' platform-events:process] in background  14.93ms DONE
2026-07-30 11:30:02  Running ['artisan' platform-events:process] in background  15.88ms DONE
2026-07-30 11:35:03  Running ['artisan' platform-events:process] in background   8.18ms DONE
```

Clean 5-minute cadence, single-digit-to-mid millisecond dispatch, no failures.

Gate and health after the cycles:

```
platform-events:verify → PASS — 15 checks, 0 failures
  delivery_count_projection_consistent   5 event(s) match the delivery ledger

health → severity: OK
  events_total 5 · deliveries_total 5 · delivered 5
  pending_deliveries 0 · oldest_pending_delivery_age_minutes —
  retryable_failures 0 · permanently_failed 0 · stale_processing 0
  audit_rows_from_events 5 · scheduler_registered true
```

**No new staging errors since Phase 1D began** (log window 11:00 onward, empty).

---

## 14. Row counts

```
platform_events            5
platform_event_deliveries  5
audit rows from events     5      ← parity maintained
audit_logs (all)       7,704      ← unchanged by Phase 1D
domain_orders              7      (2 real + 5 marked internal test, untouched)
notifications          3,109      unchanged
workspace_memory          41      unchanged

duplicates: 0 event_ids · 0 (event, subscriber, version)
events outside workspace 1: 0
retryable failures: 0 · permanent failures: 0 · stale claims: 0 · pending: 0
```

Phase 1D created no event, no delivery and no audit row. The only row change in the entire milestone was **event #1's `delivery_count`, 0 → 1**.

---

## 15. Flags, allow-list, concurrency, rollback

### Final live state

```
PLATFORM_EVENTS_ENABLED                = true
PLATFORM_EVENTS_DOMAIN_ORDER_CREATED   = true
PLATFORM_EVENTS_WORKSPACES             = 1
PLATFORM_EVENTS_FANOUT_ENABLED         = true
PLATFORM_EVENTS_DELIVERY_ENABLED       = true
PLATFORM_EVENTS_SUBSCRIBER_AUDIT       = true

schedule: */5 * * * *  php artisan platform-events:process   (firing)

eligible_subscribers   [platform.audit]
executable_subscribers [platform.audit]
subscriber_states      {platform.audit: active}
```

### Workspace restriction — unchanged

```
workspaceAllowed(1) = true    LevelUp Growth (internal)
workspaceAllowed(2) = false   Chef Red Raymundo (customer)
events outside workspace 1: 0
```

No workspace added, no producer added, no subscriber added.

### Concurrency

```
run A: reaped=0 | fanout claimed=0 | delivery claimed=0 delivered=0
run B: "Another processing run holds the lock; exiting."

process + verify simultaneously:
  process: reaped=0 | fanout claimed=0 | delivery claimed=0
  verify : PASS — 15 checks, 0 failures
```

The gate uses its own probe key, so it runs safely **alongside** a live processing pass — which matters, because it is meant to be runnable in production at any time.

### Rollback

With all five flags false:

```
command output: reaped=0 | fanout claimed=0 created=0 | delivery claimed=0 delivered=0
platform_events 5 · deliveries 5 · audit_from_events 5 · domain_orders 7   ← ALL PRESERVED
gate: PASS — 15 checks, 0 failures
```

The gate deliberately passes with the chain disabled: it verifies that the chain *would* work, not that it is currently switched on. No migration rolled back, no table dropped, no row deleted. The authorised live state was restored and re-verified.

### Site health

```
/api/health 200 · /admin/dashboard 200 · /app 200 · /ptaa/ 200
routes 1,040 · protected-path drift 0/0/0
```

`OutboxDispatcher` remains unscheduled and unused: 0 references in `bootstrap/app.php`, 0 in `schedule:list`, 0 non-test callers. Not retired, as instructed.

---

## 16. Unresolved decisions

1. **The failure record lives in the Redis cache, which is volatile.** A Redis flush or eviction loses the record, and it self-expires after 7 days. This was a deliberate choice: it matches the existing operational convention (`SystemHealthService` uses `Cache` the same way) and needs no schema change. If an audited, durable failure history is wanted, it needs a small table — worth deciding before more producers are added, because the volume of processing will grow.
2. **The gate is not wired into any pipeline**, because there is no deployment pipeline on this host — changes are applied in place. It must therefore be run **manually** after any change to `app/Core/PlatformEvents` or the scheduled command. Options: a documented step in the deploy runbook, or a git pre-commit/post-merge hook. I did not add a hook without authorisation.
3. **`delivery_count` remains a denormalised projection.** Now that `DeliveryProjection::audit()` exists and the ledger is authoritative everywhere, the column could simply be dropped and always counted on demand. That is a schema change and a separate decision.
4. **`platform_events.status` still uses the `OutboxDispatcher` vocabulary** — a delivered event reads `status='pending'`. Rename to `dispatcher_status` when the dispatcher is retired.
5. **`OutboxDispatcher` retirement** — still pending, ~47 tests to rewrite.
6. **The five internal test orders** (ids 3–7) — still marked, unpaid, uncancelled. The proposed non-destructive cleanup (transition to the existing `cancelled` status) still needs authorisation.
7. **Verification does not cover the `Types/` directory exhaustively** — it scans the one declared event class. When more producers arrive, `CHAIN_SOURCES` must grow with them. That is a maintenance obligation, not an automatic property; worth adding a check that every class in `event_classes` also appears in `CHAIN_SOURCES`.

---

## 17. Readiness for `domain.order.paid`

**Ready on the engineering side; one product decision outstanding.**

What Phase 1D removed as a risk:

- A deleted or renamed symbol in the chain is now caught **before** deployment rather than by a cron five minutes later.
- A failed pass is now visible in health instead of vanishing on the next clean cycle.
- The projection can no longer drift silently, and drift is now a gate failure rather than a cosmetic footnote.
- The handler and event-class contracts are resolved at runtime, so adding a producer will fail loudly at the gate if its event class or subscriber wiring is incomplete.

Adding `domain.order.paid` therefore inherits: the workspace allow-list, the delivery ledger, deterministic event ids, the four-state subscriber model, retry and backoff, monitoring, rollback, the verification gate and the projection guarantee.

**The outstanding decision is unchanged from my Phase 1C.1 recommendation and should be settled first:** `markPaid()` is reached from a Stripe webhook, so proving the producer end to end needs either a stored webhook-replay fixture or explicit authorisation for a Stripe test-mode charge. Phase 1C stalled on exactly this kind of unexamined precondition, so it is worth deciding before the work starts rather than after.

One additional prerequisite specific to that producer: the payload must carry only `order_id`, `currency`, `amount_minor` and `paid_at`. The `blocked_payload_keys` guard already refuses `stripe_secret`, `card`, `pan` and `access_token` by name, but the payload should be designed not to contain them rather than relying on the guard to strip them.

**Recommended order when authorised:** `domain.order.paid` alone first, then `domain.registered` and `domain.registration_failed` together, so a failure can never be mistaken for silence.
