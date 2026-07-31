# BOSS888 — PHASE 1C.1 SUBSCRIBER HARDENING REPORT

**Date:** 2026-07-30 · **Status:** ✅ **PASSED**
**Subscriber eligibility is now independent of subscriber execution state.**
**Event #1 replayed:** 1 delivery, 1 audit row · **Final:** 5 events / 5 deliveries / 5 event-generated audit rows
**Duplicates:** 0 · **Retries:** 0 · **Failures:** 0 · **Temporary guards:** retired
**Provider / payment / registrar / notification / memory actions:** none

---

## 1. Root cause of event #1's missing delivery

`SubscriberRegistry::active()` was the single definition of "active", and it answered
two unrelated questions at once:

```php
public static function active(): array
{
    if (config('platform_events.fanout.enabled') !== true) {
        return [];                                   // ← fan-out INFRASTRUCTURE
    }
    $flags = (array) config('platform_events.subscribers', []);
    return array_filter(self::all(),
        fn ($d) => ($flags[$d->key] ?? false) === true);   // ← subscriber EXECUTION
}
```

`EventFanOut::run()` used that list to decide **whether a delivery row exists at all**:

```php
$subscribers = SubscriberRegistry::active();   // ← the defect
```

And `fanOutOne()` stamps the event regardless of the outcome:

```php
// Mark the event fanned out regardless of how many rows were created.
DB::table('platform_events')->where('id', $event->id)->update([
    'fanned_out_at' => now(),
    'delivery_count' => $created + $skipped,
]);
```

So the sequence that lost event #1 was:

1. Stage B enabled fan-out while `PLATFORM_EVENTS_SUBSCRIBER_AUDIT` was still false.
2. `active()` returned `[]` — not because the subscriber was retired or out of scope,
   but because its **execution** switch happened to be off at that instant.
3. Fan-out created no delivery row, then stamped `fanned_out_at`.
4. Fan-out only ever selects `whereNull('fanned_out_at')`, so the event was never
   reconsidered. Turning the audit flag on later changed nothing.

**A momentary operational state destroyed a permanent obligation.** That is the whole
defect: the obligation to audit an event does not depend on whether a worker was
switched on at the moment fan-out happened to run.

---

## 2. Old and corrected semantics

| Question | Before | After |
|---|---|---|
| Does a delivery obligation exist? | `active()` — declaration **AND** `fanout.enabled` **AND** execution flag | `eligible()` — governance only: declaration, activation, disable, retirement |
| May the handler run now? | same `active()` | `executable()` — `eligible()` **AND** execution flag |
| Fan-out consults | `active()` | **`eligible()`** |
| Delivery worker consults | `active()` | **`executable()`** |
| Execution flag off | **no delivery row created — obligation lost** | **pending delivery row created; nothing runs** |
| Delivery worker off | no rows created | **pending obligations preserved** |
| Enabling execution later | nothing to process | **preserved pending deliveries process** |
| `fanout.enabled` off | dissolved eligibility | **irrelevant to eligibility**; `EventFanOut::run()` checks it for itself |

`active()` was **deleted**, not deprecated. Leaving an ambiguous name in place would
invite the same mistake; a removed method fails loudly at the call site.

### The four states, explicitly

Declared in `SubscriberRegistry` as four distinct constants — never one boolean
carrying four meanings:

| State | Constant | New obligations? | Handler runs? | Existing rows |
|---|---|---|---|---|
| Registered and active | `STATE_ACTIVE` | yes | yes | processed |
| Execution paused | `STATE_EXECUTION_PAUSED` | **yes** | no | preserved, pending |
| Disabled for future eligibility | `STATE_DISABLED_FOR_FUTURE` | no, after the timestamp | yes, for existing | preserved |
| Retired | `STATE_RETIRED` | never | n/a | immutable |

Eligibility now comes from two nullable **governance timestamps** on the declaration,
which live in code because they are governance decisions rather than operational
switches:

- `disabledForFutureEligibilityAt` — events recorded at or after this instant get no
  delivery row; rows already created are untouched.
- `retiredAt` — no new delivery row at any event time; historical evidence immutable.

Reactivation after retirement deliberately requires a **new subscriber version** or an
explicit governance decision, never just clearing the field: delivery identity is
`(event_id, subscriber_key, subscriber_version)`, so reusing a retired version would
silently collide with history.

The audit feature flag remains exactly what Boss specified — an **execution control**.
It no longer decides whether the obligation is created.

---

## 3. Files changed

### Production (5)

| File | Change |
|---|---|
| `app/Core/PlatformEvents/SubscriberDeclaration.php` | `disabledForFutureEligibilityAt` + `retiredAt` params, timestamp validation, `retiredAt >= activatedAt` check, `isRetired()`, `acquiresNewObligations()`, `eligibleForEventRecordedAt()` |
| `app/Core/PlatformEvents/SubscriberRegistry.php` | four `STATE_*` constants; `active()` **removed**; `eligible()`, `executable()`, `executionEnabled()`, `state()`, `states()` added |
| `app/Core/PlatformEvents/EventFanOut.php` | selects `eligible()`; per-event check delegated to `eligibleForEventRecordedAt()` |
| `app/Core/PlatformEvents/DeliveryWorker.php` | gates on `executable()` |
| `app/Core/PlatformEvents/EventSystemHealth.php` | reports `eligible_subscribers`, `executable_subscribers`, `subscriber_states`; pending-age thresholds apply only to executable subscribers; new `pending_awaiting_paused_subscriber` counter and INFO finding |

**No migration. No schema change. No new table or column.**

### The health-reporting consequence, handled

Once obligations accrue while paused, the old backlog rule would have aged those rows
and raised **CRITICAL for a deliberate operational decision**. Pending-delivery age is
now measured only across subscribers that may actually run; rows held for a paused
subscriber are counted separately and reported as INFO:

> `N delivery obligation(s) preserved for paused subscriber(s) platform.audit — these will process when execution resumes`

### Tests (4 files)

| File | Change |
|---|---|
| `tests/Feature/PlatformEvents/SubscriberEligibilityTest.php` | **new, 23 tests** |
| `tests/Feature/PlatformEvents/SubscriberGovernanceTest.php` | `test_a_subscriber_is_inactive_without_both_fanout_and_its_own_flag` replaced by `test_eligibility_and_execution_are_separate_concerns` + `test_the_four_subscriber_states_are_distinct` |
| `tests/Feature/PlatformEvents/ActivationGateTest.php` | `test_the_command_is_safe_when_no_subscriber_is_active` → `test_a_paused_subscriber_still_receives_its_obligation`; health-flag assertions updated to the state model |
| `tests/Feature/PlatformEvents/DeliveryTest.php` | `test_fanout_creates_nothing_when_the_subscriber_flag_is_off` → `test_fanout_still_creates_the_obligation_when_execution_is_paused` |

**Three tests asserted the old behaviour and had to be inverted.** Their names literally
encoded the defect — "fanout creates nothing when the subscriber flag is off" is the bug
stated as a requirement. Inverting them implements your correction; each carries a
comment recording what it used to assert and why it changed. Nothing was deleted or
weakened to obtain green.

---

## 4. Tests added — the ten requirements

| # | Requirement | Test |
|---|---|---|
| 1 | Active + execution enabled → one delivery, processes | `test_active_subscriber_with_execution_enabled_delivers` |
| 2 | Execution paused → pending delivery, no audit row | `test_execution_paused_still_creates_a_pending_delivery` |
| 3 | Enabled later → existing pending processes, no duplicate | `test_resuming_execution_processes_the_preserved_delivery` |
| 4 | Disabled for future → later events get no delivery | `test_an_event_at_or_after_the_disable_timestamp_gets_no_obligation` + `test_fanout_creates_no_obligation_outside_the_governance_window` |
| 5 | Existing delivery before disable → preserved | `test_an_existing_delivery_survives_the_subscriber_becoming_ineligible` |
| 6 | Duplicate fan-out → never a second identity | `test_duplicate_fanout_never_creates_a_second_delivery_identity` |
| 7 | Scheduler runs while paused → fan-out continues, no execution | `test_a_scheduled_pass_fans_out_but_does_not_execute_while_paused` |
| 8 | Redis unavailable → fails closed | `test_the_command_fails_closed_when_the_cache_is_unavailable` |
| 9 | Allow-list still enforced | `test_no_event_is_produced_outside_the_allow_listed_workspace` |
| 10 | Phase 0/1A/1B/1C green | full suites below |

Plus the regression guards that matter most:

- `test_eligibility_ignores_the_execution_flag` — the defect itself, asserted directly.
- `test_the_eligible_selector_does_not_read_the_execution_flag` — source-level: `eligible()`
  must not contain `platform_events.subscribers` or `fanout.enabled`.
- `test_fanout_selects_eligible_subscribers_not_executable_ones` — fan-out must never
  reference `executable()`.
- `test_the_delivery_worker_gates_on_executable`.
- `test_the_event_is_still_marked_fanned_out_while_paused` — stamping is now safe
  *because* the obligation was recorded.
- `test_health_reports_paused_obligations_as_info_not_critical`.
- `test_retirement_cannot_predate_activation`, `test_governance_timestamps_must_be_explicit`.

### Suite results

| Suite | Result |
|---|---|
| **Platform events (0 + 1A + 1B + 1C + 1C.1)** | **155 tests / 3,970 assertions OK** |
| Governance (Phase 0 E1/E2) | **44 / 732 OK** |
| Domains + Namecheap | **100 / 277 OK** |
| Component (node) | **37 passed, 0 failed** |

**336 tests green.** Shared `levelup_test` tables verified present after the run.

---

## 5. Targeted replay — dry run

```
event row id          : 1
event_id              : 4c315a96-694e-80e4-3d3a-165760cfcaf1
event_type            : domain.order.created        schema_version: 1
workspace_id          : 1
recorded_at           : 2026-07-30 06:29:10         occurred_at: 2026-07-30 06:29:10
fanned_out_at         : 2026-07-30 06:29:55         delivery_count: 0
subject               : domain_order:3   (ORDER ID 3)

deliveries for this event NOW    : 0   CONFIRMED ABSENT
event-generated audit rows NOW   : 0   CONFIRMED ABSENT

subscriber            : platform.audit v1     state: active
activatedAt           : 2026-07-29 20:00:00
eligible for this event under corrected semantics: TRUE
customer-facing side effects: false
```

### Scope proof — every event, and whether the replay could reach it

```
#1 4c315a96… order=3 recorded=06:29:10  <<< TARGETED
#2 1cb7fab3… order=4 recorded=06:30:32  not targeted
#3 d8756fcd… order=5 recorded=06:33:37  not targeted
#4 42bfda20… order=6 recorded=06:34:08  not targeted
#5 07306eb9… order=7 recorded=06:34:10  not targeted

events matched by the window : 1
matched == target only       : YES — no other event is reachable
```

The window `2026-07-30 06:29:00 → 06:29:59` brackets event #1 and stops 33 seconds
before event #2. Combined with `workspace_id = 1` and
`event_types = ['domain.order.created']`, exactly one event is addressable.

```
PLAN: eligible 1 · already_delivered 0 · would_create 1 · dry_run true
CONFIRMATION TOKEN: 69ef5139a3ff91540d6836ea
EXPECTED: 1 delivery row, then 1 event-generated audit row, plus 1 governance
          audit row naming the authoriser. No new platform_events row.
          No provider / payment / registrar / notification / memory action.
```

---

## 6. Targeted replay — result

```
replay created 1 delivery row (planned 1)
delivery worker: claimed=1 delivered=1 retried=0 failed=0

DELTAS
  events              5 -> 5   (+0)   ← no event was created
  deliveries          4 -> 5   (+1)
  audit_all        7702 -> 7704 (+2)  ← 1 event audit + 1 governance record
  audit_from_events   4 -> 5   (+1)
  notifications    3109 -> 3109 (+0)
  workspace_memory   41 -> 41  (+0)
  domain_orders       7 -> 7   (+0)
  infra_events       43 -> 43  (+0)

DELIVERY  #5 platform.audit v1 status=delivered attempts=1 delivered_at=10:28:10
```

The audit row:

```
#8024 ws=1 user=1 action=domain.order.created entity=domain_order:3 at=10:28:10
  occurred_at   : 2026-07-30 06:29:10   ← the ORIGINAL fact time, not the replay time
  event_id      : 4c315a96-694e-80e4-3d3a-165760cfcaf1
  payment_state : not_paid_at_time_of_event
  subscriber    : platform.audit v1
  correlation_id: d7292c02-5b33-4e22-b9d8-7b27ec7f12ec
```

`occurred_at` carries the original 06:29:10, so the audit trail records when the order
was actually created — a replayed audit row does not pretend the event happened at
replay time.

The governance record:

```
#8023 ws=1 user=1 action=platform_events.replay at=10:28:10
  window {from 06:29:00, to 06:29:59} · event_types [domain.order.created]
  subscriber platform.audit v1 · planned_would_create 1 · actually_created 1
```

Attributed to **user 1 (`admin@levelupgrowth.io`)**, workspace 1's owner and a platform
admin, under your written authorisation. `EventReplay::execute()` refuses a
zero/negative user id, so the reach-back is always attributable.

### Verification

| Check | Result |
|---|---|
| One delivery row created | ✅ #5, `delivered`, attempt 1 |
| One audit row created | ✅ #8024 |
| Delivery marked delivered | ✅ `delivered_at 10:28:10` |
| No duplicate event created | ✅ events +0, duplicate event_ids 0 |
| No other historical event affected | ✅ all other events byte-identical (fingerprinted before/after) |
| No notification or memory write | ✅ +0 / +0 |
| No provider / payment / registrar call | ✅ zero outbound HTTP, proven by the instrumentation before it was retired |
| Duplicate deliveries | ✅ 0 |

Replay remains **code-only**: no route, no command, no UI.

---

## 7. Temporary guard disposition

**They were never in production code.** Proven, not asserted:

```
references to p1c_guard / P1C_* in app|config|routes|bootstrap|database : 0
RequestSending / JobQueued listeners in production code                 : 0
exit() / die() anywhere in app/Core/PlatformEvents/                     : 0
git-tracked files matching p1c                                          : 0
```

The instrumentation lived only at `/tmp/p1c/p1c_guard.php`, loaded by one-off CLI
scripts. It used `exit()` rather than `throw` deliberately: `NamecheapClient` catches
`\Throwable` and converts it to a failure result, so a throw would have been swallowed
and the call retried.

**Now retired.** Evidence preserved first, at `/root/p1c-activation-evidence/`:

```
README.md                      what the instrumentation did and why it is gone
namecheap-call-ledger.log      10 order-path reads + 4 verification reads, 0 violations
provider-baseline.json         pre-activation balance and inventory
domain-orders-pre-marker.json  the five orders before the marker was merged
replay-token.txt               the targeted-replay token and target event id
p1c_guard.php.retired          the instrumentation, non-loadable as PHP
```

The live guard file is deleted; no loadable `p1c_guard.php` exists anywhere on disk.

**Equivalent permanent coverage** replaces it, at no API cost:

- `test_order_creation_dispatches_no_job_and_calls_no_payment_provider` — brace-bounded
  extraction of `createOrder()`, asserting it contains no `dispatch(`,
  `RegisterDomainJob`, `Stripe`, `createDomainCheckoutSession`, `PaymentIntent`,
  `NotificationService` or `->notify(`.
- `test_no_temporary_activation_guard_survives_in_production_code` — walks
  `app/ config/ routes/ bootstrap/ database/` and fails on any `p1c_*` marker, so this
  instrumentation cannot silently return.
- `test_the_platform_event_chain_contains_no_exit_or_die` — no `exit()`/`die()` in any
  of the eight platform-event classes.

**If the call-budget mechanism is ever reused it fails closed.** The retired version now
probes writability before arming and refuses to arm if it cannot record, and a mid-run
write failure is a halt rather than a silent pass. There are no `@`-suppressed writes.
A future reuse must not place the ledger under a root-owned `/tmp` path — the archived
README records that requirement.

---

## 8. Five internal test orders

**Not deleted.** An explicit marker was added using the **existing `metadata_json` JSON
column** — no schema change, exactly as instructed.

My first attempt refused to write, because all five already carried
`{"priced_at": …}` from the commerce service. Overwriting that provenance would have
destroyed real data, so the marker is **merged**, not replaced:

```
order 3: marked (preserved keys: priced_at)
order 4: marked (preserved keys: priced_at)
order 5: marked (preserved keys: priced_at)
order 6: marked (preserved keys: priced_at)
order 7: marked (preserved keys: priced_at)
```

The marker:

```json
{"priced_at":"…",              ← preserved
 "internal_test":true,
 "phase":"1C",
 "purpose":"platform event activation evidence — order created to produce domain.order.created",
 "unpaid_by_design":true,
 "exclude_from_commercial_metrics":true,
 "authorised":"Boss, Phase 1C activation, 2026-07-30"}
```

`status`, `paid_at` and every money field were deliberately excluded from the update.
Prior state backed up to `/root/p1c-activation-evidence/domain-orders-pre-marker.json`.

```
order 3..7  ws1  status=pending  paid_at=null  payment_intent=null  marked=yes
```

### Commercial-metric exclusion — the existing safe mechanism

`DomainAdminController` computes revenue from `DomainOrder::whereNotNull('paid_at')`.
All five test orders have `paid_at = NULL`, so **they are already excluded from revenue
by an existing mechanism** — 2 of 7 orders are counted, and none of the five is among
them.

**One honest gap:** `orders_total => DomainOrder::count()` is a raw count and **does**
include the five. Revenue, ARPU and anything derived from `paid_at` are clean; the raw
order tally reads 7 instead of 2.

### Proposed later cleanup (non-destructive, not performed)

Transition all five to `DomainOrder::STATUS_CANCELLED` — an existing status, no schema
change, no deletion. That removes them from "open orders" views while preserving the
rows, the marker and the audit trail. It needs its own authorisation because it mutates
records that are currently activation evidence, and because the five events already
audited refer to these order ids.

---

## 9. OutboxDispatcher — still unscheduled and unused

| Check | Result |
|---|---|
| References in `bootstrap/app.php` | **0** |
| Console commands referencing it | **0** |
| Jobs referencing it | **0** |
| `www-data` crontab entries | **0** |
| Present in `schedule:list` | **0** |
| Non-test callers anywhere in `app/` | **none — only its own class file** |

Not retired, as instructed. Retirement remains a separate cleanup milestone.

---

## 10. Final row counts and parity

```
platform_events            5
platform_event_deliveries  5
audit rows from events     5      ← full parity, was 4 of 5 before the replay
audit_logs (all)        7,704
domain_orders               7      (2 real + 5 marked internal test)
notifications           3,109      unchanged by this milestone
workspace_memory           41      unchanged by this milestone
infra_events               43      unchanged by this milestone

duplicate event_ids                         : 0
duplicate (event, subscriber, version)      : 0
events outside workspace 1                  : 0
retryable failures / permanent failures     : 0 / 0
stale processing claims / processing now    : 0 / 0
```

Every event now has exactly one delivered obligation and one audit row:

```
event #1 order 3  →  delivery #5  delivered 10:28:10  →  audit #8024   (replayed)
event #2 order 4  →  delivery #1  delivered 06:30:46  →  audit #8005
event #3 order 5  →  delivery #2  delivered 06:33:53  →  audit #8006
event #4 order 6  →  delivery #3  delivered 06:34:26  →  audit #8007
event #5 order 7  →  delivery #4  delivered 06:34:26  →  audit #8008
```

**One cosmetic inconsistency, disclosed:** event #1 still reports
`delivery_count = 0` even though delivery #5 exists and is delivered.
`EventReplay::execute()` inserts the delivery row but does not update the
denormalised counter on `platform_events`. The delivery ledger is authoritative and
parity is correct; the counter on that one row is stale. I did not patch it in this
milestone because changing replay's write set was not in scope. Recommend fixing it
when replay is next touched, and treating `delivery_count` as a hint rather than a
source of truth until then.

---

## 11. Scheduler-cycle evidence

Two full cycles observed after the correction and the replay, from
`/var/log/laravel-scheduler.log`:

```
2026-07-30 10:35:04  Running ['artisan' platform-events:process] in background  19.83ms DONE
2026-07-30 10:40:02  Running ['artisan' platform-events:process] in background   9.94ms DONE
2026-07-30 10:45:03  Running ['artisan' platform-events:process] in background   9.17ms DONE
```

Clean 5-minute cadence, single-digit millisecond dispatch, no failures. Health after
the cycles (inside artisan, authoritative):

| Check | Required | Actual |
|---|---|---|
| Pending event older than warning threshold | none | `events_awaiting_fanout 0`, oldest age `-` |
| Retryable failures | 0 | **0** |
| Permanent failures | 0 | **0** |
| Stale processing rows | 0 | **0** |
| Platform events | 5 | **5** |
| Delivery rows | 5 | **5** |
| Event-generated audit rows | 5 | **5** |
| Duplicates | 0 | **0** |
| Events outside workspace 1 | 0 | **0** |
| Severity | — | **OK** |

`scheduler_registered: true`, `queue_workers_running: true`, `queue_connection: redis`.
`last_successful_delivery_at 2026-07-30 10:28:10`, `last_failure_at —`.

No new staging errors. The only `ERROR` lines in the window carry the `testing.`
channel and belong to the concurrent session's Studio/ImageIntelligence test runs, not
to production or to this chain.

---

## 12. Final live state

```
PLATFORM_EVENTS_ENABLED                = true
PLATFORM_EVENTS_DOMAIN_ORDER_CREATED   = true
PLATFORM_EVENTS_WORKSPACES             = 1
PLATFORM_EVENTS_FANOUT_ENABLED         = true
PLATFORM_EVENTS_DELIVERY_ENABLED       = true
PLATFORM_EVENTS_SUBSCRIBER_AUDIT       = true

schedule: */5 * * * *  php artisan platform-events:process   (registered, firing)

eligible_subscribers    [platform.audit]
executable_subscribers  [platform.audit]
subscriber_states       {platform.audit: active}
```

### Workspace restriction

```
workspaceAllowed(1)   = true    LevelUp Growth (internal)
workspaceAllowed(2)   = false   Chef Red Raymundo  (customer)
workspaceAllowed(3)   = false
workspaceAllowed(999) = false
events outside workspace 1: 0
```

No workspace was added. No producer was added. No subscriber was added. Notifications,
memory and analytics remain absent.

---

## 13. Concurrency and rollback

### Concurrency

```
run A: "Another processing run holds the lock; exiting."
run B: reaped=0 | fanout claimed=0 created=0 | delivery claimed=0 delivered=0 failed=0
```

Two simultaneous invocations, one declined. No double delivery, no duplicate audit row.

### Rollback — the new semantics, proven at two depths

**Execution paused** (`PLATFORM_EVENTS_SUBSCRIBER_AUDIT=false`) — this is the state that
used to destroy obligations:

```
eligible_subscribers    [platform.audit]     ← obligation still exists
executable_subscribers  []                   ← nothing runs
subscriber_states       {platform.audit: execution_paused}
severity: OK

platform_events 5 · deliveries 5 · audit_from_events 5    ← ALL PRESERVED
```

**Everything off:**

```
command output: reaped=0 | fanout claimed=0 created=0 | delivery claimed=0 delivered=0
platform_events 5 · deliveries 5 · audit_from_events 5 · domain_orders 7   ← ALL PRESERVED
```

No migration rolled back, no table dropped, no row deleted, no order touched. The
authorised live state was then restored and re-verified.

Site health after everything: `/api/health` 200, `/admin/dashboard` 200, `/app` 200,
`/ptaa/` 200, 1,040 routes, protected-path drift **0/0/0**.

---

## 14. A defect I introduced during this milestone

**I briefly broke production.** My patch script reloaded each file from disk before every
replacement, so within a file only the **last** edit survived. The result was
half-applied changes:

- `SubscriberDeclaration` received the new state-helper methods but **not** the two
  constructor properties they read — `$this->retiredAt` was undefined.
- `EventFanOut` received the per-event eligibility change but **not** the selector
  change, so it still called `SubscriberRegistry::active()` — a method I had already
  deleted.

`php -l` passes on both: neither an undefined property nor an undefined static method is
a syntax error. The scheduled command therefore failed on the **10:20:05 cycle**:

```
[2026-07-30 10:20:05] staging.ERROR: [PlatformEvents] processing pass threw
  {"error":"Call to undefined method App\\Core\\PlatformEvents\\SubscriberRegistry::active()"}
```

**Impact: one lost scheduled cycle, no data effect.** The throw happened at the
subscriber-selection line, *before* any event was claimed or stamped, so no event was
marked fanned out and no delivery row was created or corrupted. The command's own
try/catch contained it and logged it rather than dying silently, and the next cycle ran
clean once repaired.

**How I found it:** grepping for stale `active()` references after the patch reported
success. **How I fixed it:** a rewritten patcher that applies every edit to one
in-memory copy and writes once, plus a runtime reflection check that every `STATE_*`
constant actually resolves — because `php -l` cannot see an undefined class constant
either. Three separate false-positive skip heuristics in my own tooling contributed;
the audit script that enumerated what actually landed is what made the damage visible.

The lesson is the one that matters for this chain: **a syntax gate is not a correctness
gate.** For a file that a 5-minute cron executes, a runtime smoke check has to run
before the change is left in place.

---

## 15. Recommendation for the first expanded producer

**`domain.order.paid`, at the existing `markPaid()` transaction.**

Reasons, in order of weight:

1. **It is the only event that closes a money loop.** `domain.order.created` records an
   intent; nothing currently records that the intent was honoured. An audit trail that
   contains orders but not payments cannot answer the first question anyone asks.
2. **The call site is already transactional.** `markPaid()` opens a `DB::transaction`
   with `lockForUpdate()` on the order, so `Outbox::record()` can sit inside it and
   inherit the same atomicity guarantee — no new transaction boundary to reason about,
   which is exactly the property Phase 1A established.
3. **It exercises a second actor type.** `domain.order.created` was `actor_type=user`.
   The paid event arrives from the Stripe webhook, i.e. `actor_type=system` with a null
   actor id — a path the audit subscriber maps but has never run in production.
4. **It needs no new infrastructure.** It inherits the workspace allow-list, the
   delivery ledger, deterministic event ids, the four-state subscriber model, retry,
   monitoring and rollback proven here.

**Sequence I recommend:** `domain.order.paid` first and alone, then
`domain.registered` and `domain.registration_failed` together — those two are the
success/failure pair around `RegisterDomainJob` and are best proven side by side so a
failure cannot be mistaken for silence.

**Two prerequisites before it goes live:**

- `markPaid()` is reached from a Stripe webhook, and the payload must carry no payment
  token, card data or Stripe secret. The `blocked_payload_keys` guard already refuses
  `stripe_secret`, `card`, `pan` and `access_token` by name, but the payload should be
  designed to contain only `order_id`, `currency`, `amount_minor` and `paid_at`.
- Testing it end to end means a real Stripe event. Under the current authorisation
  that is prohibited, so it will need either a webhook-replay fixture or explicit
  authorisation for a Stripe test-mode charge. Worth settling before the work starts,
  not after — Phase 1C's blocker was exactly this kind of unexamined precondition.

**Not recommended next:** adding workspaces, or adding the notification/memory/analytics
subscribers. Widening tenants multiplies one event type; adding a customer-facing
subscriber introduces outbound side effects, and `EventReplay` deliberately refuses to
replay anything with `emitsCustomerFacingSideEffects = true`, which means that class of
subscriber loses the recovery path this milestone just demonstrated.
