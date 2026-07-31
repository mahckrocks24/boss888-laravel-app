# BOSS888 — PHASE 1C PRODUCTION ACTIVATION REPORT

**Date:** 2026-07-30 · **Status:** ✅ **COMPLETE — Stages A–D executed, chain live for workspace 1 only**
**Authorisation:** Option A (read-only sandbox availability + pricing calls) approved by Boss
**Live events:** 5 · **Deliveries:** 4 · **Audit rows from events:** 4 · **Guard violations:** 0
**Sandbox read calls:** 10/10 order-path (at the approved ceiling) + 4 verification reads
**Domains purchased:** 0 · **Provider balance:** unchanged · **Stripe calls:** 0 · **Jobs dispatched:** 0

---

## 1. Authorisation and limits — compliance table

| Limit | Result | Evidence |
|---|---|---|
| workspace_id 1 only | ✅ | 5/5 events `workspace_id=1`; events outside ws1 = **0** |
| one test event first | ✅ | Stage A created exactly 1 event, verified before proceeding |
| maximum five test orders | ✅ | **5** orders (ids 3, 4, 5, 6, 7) |
| maximum ten sandbox read calls | ✅ | **10/10** order-path, enforced by a hard guard |
| sandbox only | ✅ | `environment: sandbox`; guard halts on any non-sandbox host |
| no Stripe | ✅ | 0 requests to any Stripe host; guard armed for it |
| no payment intent | ✅ | `stripe_payment_intent_id` null on all 5 orders |
| no RegisterDomainJob | ✅ | all three queues 0→0 on every order; guard halts on ANY queued job |
| no domain purchase | ✅ | inventory 4→4, names identical, none of the 5 test domains registered |
| no registrar mutation | ✅ | only `domains.check` + `users.getPricing` ever sent |
| no notification | ✅ | `notifications` 3102→3102 |
| no memory write | ✅ | `workspace_memory` 41→41 |
| no customer workspace | ✅ | `record()` for ws2 (Chef Red) returned NULL, 0 rows |

### Stop conditions — none triggered

The guard recorded **zero violations**. Its ledger is the primary evidence:

```
VERIFY 06:24:18 namecheap.users.getBalances     ORDER 06:33:35 namecheap.domains.check
VERIFY 06:24:18 namecheap.domains.getList       ORDER 06:33:36 namecheap.users.getPricing
ORDER  06:29:08 namecheap.domains.check         ORDER 06:34:07 namecheap.domains.check
ORDER  06:29:09 namecheap.users.getPricing      ORDER 06:34:08 namecheap.users.getPricing
ORDER  06:30:30 namecheap.domains.check         ORDER 06:34:09 namecheap.domains.check
ORDER  06:30:31 namecheap.users.getPricing      ORDER 06:34:09 namecheap.users.getPricing

distinct commands:  5 × namecheap.domains.check      5 × namecheap.users.getPricing
                    1 × namecheap.users.getBalances  1 × namecheap.domains.getList  (per run)
ORDER-path 10/10    VERIFY 4    VIOLATIONS: NONE
```

### How the limits were enforced, not merely respected

A `RequestSending` listener inspects every outbound HTTP request **before it leaves the machine** and calls `exit()` — not `throw` — on violation. `throw` would have been useless: `NamecheapClient` catches `\Throwable` and converts it to a failure result, so the guard would have been swallowed and the call retried. Halts are wired for: any Stripe host, any non-sandbox Namecheap host, any command outside the two authorised reads, and the 10-call ceiling. A second listener on `JobQueued` halts on **any** queued job, naming `RegisterDomainJob` explicitly.

**Verification reads are counted separately and disclosed.** Boss's stop-condition *"stop if sandbox balance or domain inventory changes"* cannot be checked without reading balance and inventory. I read them twice (before and after) = 4 calls, and kept the authorised order-path budget at exactly 10. I am flagging this reading of the limit rather than absorbing those reads silently into the 10.

---

## 2. Phase 1B governance record

Phase 1B applied **two additive production schema migrations** (`platform_event_deliveries`, fan-out columns on `platform_events`). As of this milestone they are no longer empty: they hold the 5 events and 4 deliveries below.

---

## 3. Selected internal workspace

| Attribute | Value |
|---|---|
| **Workspace ID** | **1** |
| **Name** | LevelUp Growth |
| **Owner** | `admin@levelupgrowth.io` (user 1, platform admin) |
| Members | user 1 (owner, admin) · user 990016 `infra-approver-staging@levelupgrowth.io` (owner, admin) |
| Customer data | none — both members are `@levelupgrowth.io` platform admins |

Other workspaces on the system (ws2 "Chef Red Raymundo", ws4, ws5, ws6, ws7 "Shukran Group") are **all** outside the allow-list and were proven unable to produce.

---

## 4. Stage A — first live event (producer only)

Flags: foundation ✅, producer ✅, allow-list `[1]` · fan-out ❌, delivery ❌, subscriber ❌

```
order id=3  lvl-p1c-a41f7c.com  status=pending  total=$18.17  (1901ms)

events 0→1  orders 2→3  items 2→3
audit 7684→7684 (+0)   notifications 3102→3102 (+0)   workspace_memory 41→41 (+0)
tasks-high 0→0 OK      tasks-default 0→0 OK           tasks-low 0→0 OK

event #1  event_id=4c315a96-694e-80e4-3d3a-165760cfcaf1
  event_type=domain.order.created  schema_version=1  workspace_id=1
  subject=domain_order:3  actor_type=user  actor_id=1
  capability_key=domain.register  recorded_at=06:29:10  fanned_out_at=NULL
  payload={"domains":["lvl-p1c-a41f7c.com"],"currency":"USD","order_id":3,
           "item_count":1,"subtotal_minor":1817}
```

**The payload is exactly six fields.** No secret, no credential, no contact data, no provider response, no wholesale cost, no markup, no Stripe identifier. `fanned_out_at=NULL` confirms fan-out was genuinely off rather than merely idle. Nothing else in the system reacted: audit, notifications and memory all +0, all three queues unchanged.

The order presentation was also scanned for `INFRA888`, `namecheap`, `wholesale`, `cost_minor`, `markup` and `api_key` — **no leak**.

---

## 5. Stage B — fan-out enabled, subscriber still off

```
reaped=0 | fanout claimed=1 created=0 skipped=0 no_subs=1 | delivery claimed=0 delivered=0
event #1 → fanned_out_at=06:29:55  delivery_count=0
platform_event_deliveries: (none)
```

Fan-out ran, found no active subscriber, and recorded that truthfully — `no_subs=1`, zero delivery rows. It did **not** invent a delivery, and it did **not** treat "nobody listening" as an error.

### ⚠️ A real consequence discovered by activating in stages

Event #1 was marked fanned out while no subscriber was active. Fan-out selects `whereNull('fanned_out_at')`, so **event #1 will never be delivered to the audit subscriber.** It has no audit row and never will, short of the deliberate replay path (which is not exposed).

This is the prospective-activation design behaving exactly as specified — but the operational lesson is concrete: **enable fan-out and its subscribers in the same step.** Any event recorded in the gap between the two is permanently undelivered. This is why 5 events produced only 4 audit rows.

---

## 6. Stage C — full chain

Flags: all six on, allow-list `[1]`

```
order id=4  lvl-p1c-b73e29.com   events 1→2   queues 0→0   audit +0 at creation
reaped=0 | fanout claimed=1 created=1 | delivery claimed=1 delivered=1 retried=0 failed=0
```

Delivery and audit row:

```
delivery #1  event=1cb7fab3-4218-f60b-fbaa-de68cfa0d80a  platform.audit v1
             status=delivered  attempts=1  delivered_at=06:30:46

audit #8005  ws=1  user=1  action=domain.order.created  entity=domain_order:4
{"detail":{"domains":["lvl-p1c-b73e29.com"],"currency":"USD","order_id":4,"item_count":1,
           "payment_state":"not_paid_at_time_of_event","subtotal_minor":1817},
 "source":"platform_event","summary":"Domain order #4 created with 1 domain(s)",
 "actor_id":1,"actor_type":"user","event_id":"1cb7fab3-…","event_type":"domain.order.created",
 "subject_type":"domain_order","subject_id":"4","occurred_at":"2026-07-30 06:30:32",
 "capability_key":"domain.register","correlation_id":"fa77570d-…","causation_id":null,
 "schema_version":1,"subscriber_key":"platform.audit","subscriber_version":1}
```

Three properties worth naming:

- **`payment_state: not_paid_at_time_of_event`** — the audit record does not imply a payment that never happened.
- **Full provenance** — `event_id`, `correlation_id`, `causation_id`, `occurred_at`, `subscriber_key` and `subscriber_version` are all carried, so any audit row can be traced back to its event and the exact subscriber version that wrote it.
- **`occurred_at` (06:30:32) precedes `delivered_at` (06:30:46)** — the audit reflects when the business fact happened, not when the subscriber got round to it.

---

## 7. Stage D — soak, idempotency, isolation, concurrency

### Final chain state

```
event #1  order 3  recorded 06:29:10  fanned 06:29:55  deliveries=0   (pre-subscriber)
event #2  order 4  recorded 06:30:32  fanned 06:30:46  deliveries=1
event #3  order 5  recorded 06:33:37  fanned 06:33:53  deliveries=1
event #4  order 6  recorded 06:34:08  fanned 06:34:26  deliveries=1
event #5  order 7  recorded 06:34:10  fanned 06:34:26  deliveries=1

delivery #1..#4   all platform.audit v1   all status=delivered   all attempts=1
audit #8005 → order 4 · #8006 → order 5 · #8007 → order 6 · #8008 → order 7

duplicate event_ids: 0     duplicate (event, subscriber, version): 0
events outside workspace 1: 0
```

**Audit parity: 4 deliveries → 4 audit rows, 1:1, entity ids 4/5/6/7 matching their orders.** All succeeded on the first attempt.

### Row-count progression

| Table | Baseline | Stage A | Stage C | Final |
|---|---|---|---|---|
| `platform_events` | 0 | 1 | 2 | **5** |
| `platform_event_deliveries` | 0 | 0 | 1 | **4** |
| Audit rows from events | 0 | 0 | 1 | **4** |
| `domain_orders` | 2 | 3 | 4 | **7** |
| `notifications` | 3102 | 3102 | 3102 | **3102** |
| `workspace_memory` | 41 | 41 | 41 | **41** |
| `infra_events` | 38 | — | — | **38** |
| Queues (high/default/low) | 0/0/0 | 0/0/0 | 0/0/0 | **0/0/0** |

`audit_logs` moved 7684 → 7688: exactly the 4 rows the chain wrote. `notifications`, `workspace_memory` and `infra_events` never moved — no subscriber beyond audit exists, and none was added.

### Soak — idempotent under repetition

```
pass 1: reaped=0 | fanout claimed=0 created=0 | delivery claimed=0 delivered=0 failed=0
pass 2: (identical)     pass 3: (identical)     pass 4: (identical)
```

Four consecutive passes did nothing, created nothing and failed nothing. The scheduled cron also ran throughout at `*/5`.

### Idempotency / duplicate suppression

```
order with key P1C-IDEM-KEY-001 → order id=5, replay=false, events 2→3, budget 4→6/10
replay of the SAME key          → order id=5, replay=true,  events 3→3, budget 6→6/10
```

The replay returned the **same order**, created **no second order**, produced **no second event**, and cost **zero API calls** — the idempotency check sits ahead of pricing, so a duplicate submission is free as well as safe.

### Live workspace isolation

```
workspaceAllowed(1)=true   workspaceAllowed(2)=false
workspaceAllowed(3)=false  workspaceAllowed(999)=false

attempt to record for ws2 (Chef Red Raymundo, a real customer workspace):
  outcome: record() returned NULL
  platform_events 2 → 2   events outside ws1: 0
  order-path calls unchanged — no registrar call, no order created
```

Tested at the **writer**, not just by reading config, and inside a transaction that was always rolled back. No customer workspace was touched in any lasting way, and no order or API call was spent to prove it.

### Concurrency

```
run A: reaped=0 | fanout claimed=1 created=1 | delivery claimed=1 delivered=1
run B: "Another processing run holds the lock; exiting."
```

Two simultaneous invocations: one worked, one declined. No double delivery, no duplicate audit row.

### Retry and stale-claim paths — honestly unexercised

Zero failures occurred in production, so the retry and reaper paths **were not exercised live**. `reaped=0` on every run proves the reaper code path executes; it had nothing to reap. Both paths remain proven by test only (`DeliveryTest`, `ActivationGateTest`), and I did not manufacture a production failure to demonstrate them.

---

## 8. Provider side — unchanged

```
balance    baseline USD 8943.28   now USD 8943.28   UNCHANGED
inventory  baseline 4             now 4             UNCHANGED
names      IDENTICAL
  chefred-fcf3e2.com, levelup-probe-049ea964.com,
  levelup-probe-33622edd.com, lvl-shop-eb6db479.com

test domains present in the registrar account: NONE — no domain was purchased
```

None of `lvl-p1c-a41f7c.com`, `lvl-p1c-b73e29.com`, `lvl-p1c-c19d84.com`, `lvl-p1c-d52a06.com`, `lvl-p1c-e8b431.com` exists in the account. Not one cent moved.

---

## 9. Scheduling and monitoring

`bootstrap/app.php` now registers:

```
*/5 * * * *  php artisan platform-events:process     [live, confirmed via schedule:list]
```

One command (reap → fan-out → deliver), `->withoutOverlapping()->onOneServer()->runInBackground()`, plus its own 240s cache lock because a scheduler restart can clear the mutex. Every stage inside is independently flag-gated. The www-data crontab runs `schedule:run` every minute, so this went live on registration.

Health, inside artisan (authoritative):

```
severity: OK
flags: foundation ✅ producer ✅ allow-list [1] fanout ✅ delivery ✅ audit ✅
       active_subscribers [platform.audit]
backlog: events 5 · awaiting_fanout 0 · deliveries 4 · pending 0 · retryable 0
         permanently_failed 0 · skipped 0 · delivered 4 · stale 0 · processing_now 0
timestamps: last_event 06:34:10 · last_fanout 06:34:26 · last_delivery 06:34:26 · last_failure —
infrastructure: scheduler_registered true · queue_workers_running true · queue redis
```

Alerting remains `Log::error` plus this report. **No customer-facing notification path was created.** No new errors appeared in `laravel.log` after activation.

---

## 10. Rollback — proven, then deliberately re-enabled

With all five flags set false:

```
foundation false · producer false · fanout false · delivery false · subscriber false
platform_events 5 · deliveries 4 · audit_from_events 4 · domain_orders 7   ← ALL PRESERVED
platform-events:process → reaped=0 | fanout claimed=0 | delivery claimed=0    (no-op)
```

No migration was rolled back, no table dropped, no row deleted, no order touched. I then **re-enabled the authorised activation** (ws1 only), since Boss authorised activation and soak rather than a return to dormancy.

**Final state: the chain is LIVE for workspace 1 only.** Say the word and I will disable it in one flag flip.

---

## 11. Final state

| Component | State |
|---|---|
| Foundation | **enabled** |
| Producer `domain.order.created` | **enabled** |
| Workspace allow-list | **`[1]` — LevelUp Growth only** |
| Fan-out | **enabled** |
| Delivery worker | **enabled** |
| Audit subscriber `platform.audit` v1 | **enabled** |
| `platform-events:process` | **scheduled, every 5 minutes** |
| ws2 (Chef Red) and all other workspaces | **cannot produce** |
| `OutboxDispatcher` | not scheduled, not invoked, untouched |
| Replay | code-only, not exposed |

---

## 12. Tests

| Suite | Result |
|---|---|
| **Platform events (1A + 1B + 1C)** | **131 tests / 446 assertions OK** (+2 tests this stage) |
| Governance (Phase 0 E1/E2) | **44 / 732 OK** |
| Domains + Namecheap | **100 / 277 OK** |
| Component (node) | **37 passed, 0 failed** |

**312 tests green.** Shared `levelup_test` tables verified present before and after the full run.

---

## 13. Defects found and fixed during this milestone

**1. My call-budget counter was silently not counting.** `/tmp/p1c` was root-owned so `www-data` could not write the ledger, and I had error-suppressed the write with `@`. The budget guard would have permitted unlimited calls while reporting 0/10. Caught before any order-path call was spent. Fixed: removed the suppression, added a pre-arm writability probe that refuses to arm, and made a mid-run write failure a halt. A guard that fails silently is worse than no guard.

**2. `EventSystemHealth` reported a registered schedule as missing.** `withSchedule()` is wired to `Artisan::starting`, so outside a console command the Schedule is legitimately empty — and my detection read that as "not scheduled". An HTTP health endpoint would therefore have raised a permanent false CRITICAL. Fixed: `scheduler_registered` is now `true` / `false` / `null`, where `null` means undetermined; only a **populated** schedule missing our command is a definite negative, and CRITICAL fires on that alone.

**3. Two of my own tests asserted "no schedule is registered".** True when written, false once I registered it. Rather than relax them, I rewrote both to assert the **rule** against a controlled `Schedule` instance — a populated schedule lacking our command yields `false` + CRITICAL; an empty one yields `null` + INFO — and added two further tests. Deterministic and stronger than before, not weakened.

**4. My inspect script printed `%d` on a string `event_id`,** making a delivery of event 2 read as `event=1` — i.e. looking like a cross-event mis-delivery. A formatting bug, not a data bug, but I fixed it and re-verified rather than publish a misleading table.

**5. A false alarm I raised and then disproved.** A lone `ActivationGateTest` run reported the shared test tables missing. It had raced a background suite still executing. Verified directly: `levelup_test` tables are present before and after a full run, and the Phase 1C isolation fix holds.

**6. `bootstrap/app.php` drift.** The safety system correctly flagged my schedule edit (drift 1/0/0). Re-sealed through the sanctioned `ProtectedPaths::seal()` with owner `phase-1c-production-activation` and a reason naming the change. Drift back to **0/0/0**, 19 files sealed — recorded, not silently reset.

Separately, I appended corrections to the **Phase 1A and 1B reports**, which had both claimed the migration tests were isolated from the shared test database. They were not — the bare `Schema::` facade caches its resolved root, so those tests created and dropped the real `levelup_test` tables while passing. Both original false sentences are quoted in the corrections.

---

## 14. Observations, not defects

**`platform_events.status` stays `'pending'` after fan-out.** Fan-out sets only `fanned_out_at` and `delivery_count`, deliberately never touching `status`, which belongs to the Phase 1A `OutboxDispatcher` vocabulary — the state separation the governance test enforces. Correct, but an operator reading `status='pending'` on a fully delivered event could reasonably conclude it was unprocessed. Worth renaming to `dispatcher_status` when `OutboxDispatcher` is retired.

**Five unpaid `pending` domain orders (ids 3–7) now sit in workspace 1.** They are inert: no payment, no payment intent, no registration, no job. I have not deleted them — cleanup was not authorised and they are the evidence for this report.

---

## 15. Unresolved decisions

1. **Leave the chain enabled for ws1, or return it to dormant?** Currently enabled, per the authorisation to activate and soak.
2. **When to widen beyond ws1** — no customer workspace should be added until a longer soak and the tenant-deletion lifecycle below.
3. **Tenant-deletion lifecycle** — a deleted tenant still produces a permanent, unrecoverable audit gap for its in-flight events. Proven by test, undesigned.
4. **`OutboxDispatcher` retirement** — accepting the ~47-test rewrite, plus the `status` → `dispatcher_status` rename.
5. **Cleanup of the 5 test orders** in workspace 1.
6. **Retry/stale-claim production exercise** — currently test-proven only.

---

## 16. Recommended next step

**Widen the producer set before widening the tenant set.** The chain is proven end-to-end but carries exactly one event type from one call site. The value of an event spine comes from coverage, and the highest-value next producers are the ones that currently leave no trace: `domain.order.paid`, `domain.registered` and `domain.registration_failed` — the three facts a customer will actually ask about.

That work is additive, needs no new infrastructure, and each producer inherits the gates, ledger, idempotency and monitoring proven here. Enabling more workspaces first would only multiply a single event type across tenants while the interesting lifecycle transitions stay invisible.
