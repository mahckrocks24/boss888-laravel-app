# BOSS888 — PHASE 1E DOMAIN.ORDER.PAID REPORT

**Date:** 2026-07-30 · **Status:** ⚠️ **NOT PASSED — Stage A complete, Stages B/C/D BLOCKED**
**Live signed replay: NOT performed.** Four independent structural blockers, one of them a **P0 production defect**.
**Live state unchanged:** 0 `domain.order.paid` events · 5 orders still unpaid · 0 queued registration jobs · producer flag **false**
**Stripe API calls: 0 · Namecheap calls: 0 · notifications +0 · memory +0**

---

## 1. THE HEADLINE: a P0 defect on the money path

**The domain-order branch of the Stripe webhook cannot execute. It has never worked.**

`StripeService::handleWebhook()` routes a domain purchase like this:

```php
return app(\App\Services\Domains\DomainCommerceService::class)
    ->markPaidAndProvision((string) ($session->id ?? ''), (string) ($session->payment_intent ?? ''));
```

`DomainCommerceService` is **not container-resolvable**. Proven, both directions:

```
app(DomainCommerceService::class)  → FAILS  BindingResolutionException:
    Unresolvable dependency resolving [Parameter #0 [ <required> string $environment ]]
    in class App\Connectors\Infrastructure\Namecheap\NamecheapClient
DomainCommerceService::make()      → resolves
```

The chain is `DomainCommerceService` → `DomainPricingService` → `NamecheapRegistrarConnector` → `NamecheapClient(string $environment, …)`, and nothing binds `$environment`. The class provides a `::make()` factory for exactly this reason, and **every other caller in the codebase uses it** — `CustomerDomainController` (5 call sites) and `DomainAdminController` both do. This one call site does not.

### Why nobody noticed

The webhook route is deliberately built to always answer 200:

```php
} catch (\Throwable $e) {
    Log::error('Stripe webhook crash', [...]);
    return response()->json(['received' => true, 'error' => 'logged'], 200);
}
```

That is correct design for retry-storm protection, but it means this failure is **silent to Stripe**. Stripe records the webhook as delivered and never retries.

**Consequence:** a customer completes checkout, Stripe takes the money, the webhook fires, the handler throws, Stripe is told "received", and the order stays `pending` forever. No payment recorded, no registration, no notification, no retry. The only trace is one `staging.ERROR` line.

### Proof it has never worked

```
order 1  ws1  completed  paid_at 2026-07-29 12:00:32  intent=pi_e2e_simulated     idem=e2e-4613829960
order 2  ws2  completed  paid_at 2026-07-29 13:11:46  intent=pi_chefred_accept    idem=chefred-accept-8781f11f
orders 3–7    pending    paid_at NULL                 intent NULL
```

Both "paid" orders carry **simulated** payment intents written by test harnesses, not Stripe references. No order has ever transitioned through the real webhook, which is why a total failure of that branch has gone unobserved.

### The fix, and why I have not applied it

One line: `app(\App\Services\Domains\DomainCommerceService::class)` → `\App\Services\Domains\DomainCommerceService::make()`.

I have **not** applied it. It is on the payment path, and your strict non-goals say *"Do not change Stripe processing."* A one-line dependency-resolution repair to a path that currently always throws is not, in my judgement, "changing Stripe processing" — but it is exactly the kind of change that should be authorised explicitly rather than slipped into a phase about something else. **Requesting authorisation as a standalone P0 fix.**

Regression coverage is already written and passing: `test_p0_the_domain_webhook_branch_is_currently_unresolvable` asserts the current broken state and will **fail** the moment the fix lands, forcing the expectation to be updated consciously.

---

## 2. Why Stage C was not performed — four blockers

| # | Blocker | Your instruction |
|---|---|---|
| **1** | **P0 above.** The webhook cannot reach `markPaidAndProvision` at all. | — (discovered by this phase) |
| **2** | `markPaidAndProvision()` dispatches `RegisterDomainJob` **unconditionally**. No feature flag exists anywhere. | *"If markPaid() currently dispatches registration automatically, **stop**."* |
| **3** | The five test orders have `stripe_session_id = NULL`. The webhook resolves orders by session id, so **none of them is reachable** without writing money-path data. | *"resolve to the selected workspace 1 order"* — impossible without altering the money path |
| **4** | One `STRIPE_WEBHOOK_SECRET`, read once in the constructor. Signing a fixture that passes the real verifier requires the **live** secret. | *"If the current implementation cannot safely verify a fixture without changing the production secret, **stop and report**."* |

### Blocker 2 in detail — the registrar coupling

```php
// after the payment transaction commits:
$order->update(['status' => DomainOrder::STATUS_PROVISIONING]);

foreach ($order->items as $item) {
    RegisterDomainJob::dispatch($item->id)->onQueue('tasks-high');
}
```

Searched for an existing gate: `config/`, `app/Services/Domains/`, `app/Jobs/RegisterDomainJob.php` — **no** fulfilment or auto-register flag exists. `RegisterDomainJob` self-gates only on item status (`pending`/`registering`) and `$order->isPaid()`; a paid order with pending items proceeds to a real Namecheap registration.

**Narrowest safe activation method (proposed, not built):** a `domains.fulfilment.enabled` flag defaulting **true** so production behaviour is unchanged, read at the single dispatch site, with a fail-closed test that the dispatch happens when true and does not when false. That is one config key and one `if`, it is testable, and it leaves no hidden guard behind. I did not add it — you said not to alter the money path to suppress dispatch, and to propose instead.

**Setting an item to a non-pending status would also make the job a no-op**, but the job would still be *queued*, and your expected row changes require `queued registration jobs: +0`. So that is not a valid route either.

### Blocker 4 in detail — the signing secret

`handleWebhook()` uses a single `$this->webhookSecret` from `config('billing.stripe.webhook_secret')`, set once in the constructor. There is no replay path and no second secret. Your preferred approach required a narrowly-conditioned internal replay secret, but also forbade *"a broadly accessible second webhook secret or permanent bypass"* — and signing with the live secret would mean reading a live production credential into a script to forge requests. I did neither.

**Note:** the tests DO exercise the real verifier, by configuring an isolated test secret in the test environment only (`whsec_phase1e_test_only_…`). That proves signature verification works without touching production material — see §5.

---

## 3. Selected test order (Stage B pre-report)

Selected **order 3**, and reported before touching anything:

| Attribute | Value |
|---|---|
| Order ID | **3** |
| Workspace ID | **1** (LevelUp Growth, internal) |
| Order status | `pending` |
| Payment state | **unpaid** — `paid_at` NULL |
| `stripe_payment_intent_id` | NULL |
| `stripe_session_id` | **NULL** ← blocker 3 |
| `metadata_json.internal_test` | **true** |
| Unfulfilled | item 3 `lvl-p1c-a41f7c.com` status `pending`, `registered_at` NULL |
| Registration dispatched | **no** — `tasks-high` depth 0 |
| Provider purchase | **none** — registrar inventory 4 domains, none of the five test domains present |

No sixth order was created. Nothing was modified.

---

## 4. Event contract — `domain.order.paid` v1

**Sensitivity: `internal`.** It names a tenant's spend and a provider reference: commercially sensitive to that tenant, not a secret and not personal data. `restricted` would overstate it and would block the audit subscriber for no benefit.

### Payload — six fields, all required

| Field | Type | Why |
|---|---|---|
| `order_id` | positive int | subject identity |
| `currency` | 3-letter code | — |
| `total_minor` | int, minor units | the amount actually paid. **This is revenue**, unlike `subtotal_minor` on the created event. A float is refused: it would silently lose money to rounding |
| `paid_at` | explicit timestamp | when the money moved |
| `payment_provider` | must equal `stripe` | a free-text provider would let a new integration in without review |
| `payment_reference` | non-empty string | reconciliation against the provider's own records |

### Deliberately excluded, and **asserted by name**

`card`, `card_last4`, `card_brand`, `payment_method`, `payment_method_details`, `billing_address`, `billing_details`, `customer_email`, `receipt_url`, `raw_event`, `stripe_event`, `signature`, `registrar_cost_minor`, `markup_minor`, `cost_total_minor`.

Plus **`workspace_id`**: the envelope already carries it as a mandatory field. Two copies can disagree, and then neither is trustworthy. Same reasoning for actor and correlation. Documented in the class.

And a credential-shape check: a `payment_reference` beginning `sk_`, `rk_` or `whsec_` is refused as "a credential, not a payment identifier".

**Ordering:** the Outbox blocked-key scan runs **before** the event-type/class validation, as established in Phase 1D — if a payload carries both a secret and a structural error, the secret is what must be reported.

### Actor semantics

```
actor_type = system     (a webhook confirmed this, not a person)
actor_id   = null
capability_key = null   (an inbound provider callback is not an actor invoking an engine action)
correlation_id = the domain.order.created event's correlation id (same customer journey)
causation_id   = the domain.order.created event's event_id
```

An operator who authorises a controlled replay is recorded in a **separate** governance audit row. A business fact and an operator authorisation are different records; merging them would make the audit trail claim a human performed a payment they merely permitted a test of.

### Deterministic identity

`event_id = hash(domain.order.paid | v1 | domain_order | <order_id> | ws<id>)`. One order can produce exactly **one** paid event, so a duplicate webhook delivery collides on the unique index rather than recording a second payment. That is the durable uniqueness mechanism you asked for — not a controller-level check.

---

## 5. Atomicity and the producer

Recorded **inside** the existing payment transaction, immediately after the status/`paid_at`/intent write and before the transaction returns:

```php
$paidAt = now();
$order->update(['status' => STATUS_PAID, 'paid_at' => $paidAt, 'stripe_payment_intent_id' => $paymentIntentId]);
// … resolve the origin event for correlation/causation …
$this->outbox->record(new DomainOrderPaid(...));
return ['handled' => true, 'action' => 'marked_paid', ...];
```

`Outbox::record()` retains its `DB::transactionLevel() >= 1` assertion. Either the order becomes paid **and** the event exists, or neither does — proven by `test_the_payment_transition_and_the_event_roll_back_together`.

**One deliberate design decision:** `payment_reference` falls back to `'session:' . $sessionId` when Stripe supplies no payment intent. Without that fallback an empty reference would make the event constructor throw and **roll back a payment that genuinely succeeded**. An unreconcilable payment record is bad; losing the payment is worse.

The webhook acknowledgement does **not** depend on fan-out or audit delivery — those happen later, on the schedule.

**The producer flag ships OFF** (`PLATFORM_EVENTS_DOMAIN_ORDER_PAID` absent → `false`). With the flag off `record()` returns null and the money path is byte-for-byte unchanged.

---

## 6. Fixture construction and signature evidence

Built as **test material only** — no production replay command was created, because Stage C is blocked.

```
event type   : checkout.session.completed
event id     : evt_test_phase1e_001            (stable)
session id   : cs_test_phase1e_001             (matches the test order)
intent       : pi_test_phase1e_001             (stable)
metadata     : {"order_type":"domain"}         (the discriminator the controller reads)
signature    : t=<ts>,v1=hmac_sha256(ts . "." . body, secret)   — Stripe's own scheme
secret       : whsec_phase1e_test_only_…       ISOLATED TEST MATERIAL
```

The live `STRIPE_WEBHOOK_SECRET` was never read, used or replaced. Its presence and length were checked without printing the value.

### Signature verification results — the real `\Stripe\Webhook::constructEvent`, unmocked

| Case | Result |
|---|---|
| Correctly signed fixture | **verifies** — execution passes constructEvent and enters the domain branch (where it hits the P0) |
| Wrong secret | `{"handled":false,"error":"Invalid webhook signature"}` |
| Body tampered after signing | rejected |
| Timestamp 1 hour old | rejected (Stripe's 300s tolerance) |
| No signature header | rejected |

Verification was never disabled and the controller was never mocked. Two implementation notes that mattered: `phpunit.xml` forces `STRIPE_SECRET=""` (so the service reports "not configured" unless overridden), and `StripeService` is a **singleton**, so it must be rebuilt after a config override or the route uses a stale instance.

**Why the signature tests assert on the return value rather than an HTTP status:** the route returns 200 for everything by design, so an HTTP status carries no information about whether a signature verified. `handleWebhook()`'s return value is the actual verdict, and it is the same unmocked call the route makes.

---

## 7. Idempotency and concurrency

| Boundary | Mechanism | Result |
|---|---|---|
| Same Stripe event id twice | `isPaid()` check + `lockForUpdate()` inside one transaction | second call returns `already_processed`; **one** paid event |
| Different event ids, same payment intent | same | second returns `already_processed`; **one** paid event |
| Retry after commit, before acknowledgement | same — the guard is durable state, not request state | no duplicate |
| `markPaidAndProvision` on an already-paid order | same | no event at all |
| Concurrent duplicate delivery | **deterministic `event_id` + unique index** | three independent transactions attempting the record leave exactly **one** row |

The durable guarantee is the database, in two layers: a locked row for the payment transition, and a unique index on a deterministic event id for the event. No controller-level check is relied upon.

---

## 8. Registrar containment evidence

Proven mechanically for the selected order, **before** any replay was contemplated:

```
queued registration jobs (tasks-high) : 0
provider inventory                    : 4 domains, none of the five test domains
item 3 status                         : pending, registered_at NULL
Namecheap call ledger                 : unchanged (10 order-path + 4 verify reads, all from Phase 1C)
Stripe API calls                      : 0
```

And by source inspection of the payment transaction itself — `test_the_payment_transaction_contains_no_provider_or_payment_api_call` asserts `markPaidAndProvision`'s body contains no `$this->registrar`, `NamecheapRegistrarConnector`, `searchDomain`, `quoteRegistration`, `\Stripe\`, `StripeService`, `PaymentIntent`, `NotificationService`, `->notify(`, or workspace-memory reference.

**But the dispatch after the transaction is unconditional** — see blocker 2. No fail-closed queue guard was added, and no temporary `exit()` or listener exists in production (Phase 1D's guard-absence tests still pass).

---

## 9. Deployment verification gate

**You asked me to inspect the actual deployment mechanism first. There isn't one.**

```
CI config (.github/workflows, .gitlab-ci.yml, Jenkinsfile) : absent
composer deploy script                                      : none (only post-autoload-dump)
repo deploy script                                          : DEPLOY-PRODUCTION.md — a document, not executable
server scripts                                              : ad-hoc per-task /root/*.sh
git state                                                    : 12+ files modified and uncommitted
```

Deployment is **in-place editing on the server**. There is no executable release step to hook into, and you forbade a git hook and a parallel deployment system.

### Where I placed it, and why

At the boundary your brief actually specifies — *"verification runs before the new release begins processing scheduled events"*. The scheduled command now verifies the chain before it will process anything:

```php
if (! $this->verifyChainBeforeProcessing()) {
    return self::FAILURE;   // processes NOTHING
}
```

Verification runs when `ChainVerifier::fingerprint()` differs from the last fingerprint that passed — i.e. **exactly once after any change**, and never on an unchanged system. The fingerprint covers chain source contents **and** the structural configuration (`event_classes`, `subscriber_handlers`, declared subscribers), because a release can change a map without touching a line of PHP.

On failure: a structured `Log::error`, a Phase 1D durable failure record (so health degrades), non-zero exit, and **nothing processed**. There is **no `--skip-verify`, no `force`, and no `|| true`** — `test_the_gate_has_no_bypass_flag` enforces that.

Observed live:

```
[11:58:23] staging.INFO: [PlatformEvents] chain verified before processing {"checks":15}
second pass: no re-verification (fingerprint unchanged)
```

### Honest limitation, as you required

**This is not deployment-time enforcement, because no deployment mechanism exists to enforce it in.** What it structurally guarantees is that a broken chain **cannot process events** — which is precisely the 10:20:05 failure mode. It does not prevent a broken chain from being deployed; it prevents it from doing work.

**I am not claiming automated deployment enforcement.** The required manual step is:

```
php artisan platform-events:verify     # must exit 0 before leaving a chain change in place
```

Rollback: revert the changed files and re-run `platform-events:verify` until it exits 0; the next scheduled pass then re-verifies automatically. Nothing needs to be un-migrated.

---

## 10. Phase 1D failure-record status

**Not cleared, and not clearable by accident.**

```
recorded failure state: none
processing_failures.count: 0
```

There is no recorded failure to acknowledge: the 10:20:05 incident **predates** the recording mechanism, which Phase 1D added afterwards. So the absence is honest history, not a silent clearance. I did not run `--ack-failures` against any real record; the only invocation was against an empty state, which reported *"No recorded processing failure to acknowledge."*

---

## 11. Tests

**`tests/Feature/PlatformEvents/DomainOrderPaidTest.php` — 30 tests, 107 assertions, OK** (run in a clean window).

| # | Requirement | Test | Status |
|---|---|---|---|
| 1 | Cannot be recorded outside a transaction | `test_domain_order_paid_cannot_be_recorded_outside_a_transaction` | ✅ |
| 2 | Payment and event roll back together | `test_the_payment_transition_and_the_event_roll_back_together` | ✅ |
| 3 | Signed fixture passes real verification | `test_a_correctly_signed_fixture_passes_real_signature_verification` | ✅ |
| 4 | Invalid signature rejected | `test_an_invalid_signature_is_rejected` | ✅ |
| 5 | Modified body rejected | `test_a_body_modified_after_signing_is_rejected` | ✅ |
| 6 | Duplicate event id → one event | `test_the_same_stripe_event_id_twice_produces_one_paid_event` | ✅ |
| 7 | Same intent, different event id → one event | `test_the_same_payment_intent_under_a_different_event_id_...` | ✅ |
| 8 | Concurrent duplicate → one event | `test_concurrent_recording_leaves_exactly_one_paid_event` | ✅ |
| 9 | Already-paid → no second event | `test_an_already_paid_order_produces_no_second_paid_event` | ✅ |
| 10 | Failed payment → no paid event | `test_a_failed_payment_event_produces_no_paid_event` | ✅ |
| 11 | Wrong workspace cannot produce | `test_a_non_allow_listed_workspace_cannot_produce_the_paid_event` | ✅ |
| 12 | Non-internal order refused by the fixture guard | `test_the_fixture_guard_refuses_a_non_internal_order` (+ already-paid) | ✅ |
| 13 | Registration job not dispatched | **CANNOT PASS** — documented instead by `test_the_payment_path_currently_dispatches_registration_which_blocks_live_replay` | ⚠️ |
| 14 | No Stripe API request | `test_the_payment_transaction_contains_no_provider_or_payment_api_call` | ✅ |
| 15 | No Namecheap request | same test + Null connectors + unchanged call ledger | ✅ |
| 16 | No notification or memory write | `test_no_notification_or_memory_write_occurs` | ✅ |
| 17 | Audit carries the original `occurred_at` | `test_the_audit_row_carries_the_original_occurred_at_and_no_secrets` | ✅ |
| 18 | Projection matches the ledger | `test_the_delivery_projection_matches_the_ledger` | ✅ |
| 19 | Verify resolves the new class and handler | `test_verification_resolves_the_new_event_class_and_audit_handler` | ✅ |
| 20 | Gate fails on non-zero verification | `test_the_deployment_gate_blocks_processing_when_verification_fails` | ✅ |
| 21 | Existing tests green | **see the concurrency section — could not be confirmed** | ⚠️ |

Extra: `test_an_unsigned_request_is_rejected`, `test_a_stale_timestamp_is_rejected`, `test_the_paid_event_refuses_forbidden_payload_keys` (8 keys), `test_a_credential_shaped_payment_reference_is_refused` (3 prefixes), `test_a_float_total_is_refused`, `test_the_paid_event_id_is_deterministic`, `test_p0_the_domain_webhook_branch_is_currently_unresolvable`, `test_p0_the_route_masks_the_failure_as_a_success`, `test_the_gate_permits_processing_once_the_chain_verifies`, `test_the_gate_has_no_bypass_flag`.

**Requirement 13 cannot be satisfied without blocker 2's containment mechanism.** Rather than leave a failing test or quietly drop it, the current coupling is asserted deliberately: the test proves registration *is* dispatched, and it will fail the moment containment is added.

### Requirement 21 — could not be confirmed

`platform-events:verify` **PASSES 15/15**, governance passes **44/732**, component passes **37/0**. The platform-event and domain suites could not be completed: another engineering session is running large test batches continuously and repeatedly dropping the shared `levelup_test` schema mid-run. Details in §13.

---

## 12. Expected vs actual row changes

Because Stage C was not performed, **nothing changed**:

| Row | Expected on a successful replay | Actual |
|---|---|---|
| Selected order unpaid → paid | yes | **no — still `pending`** |
| `paid_at` null → timestamp | yes | **still NULL** |
| Payment reference | fixture reference | **still NULL** |
| `platform_events` +1 `domain.order.paid` | +1 | **0 paid events; total still 5** |
| `platform_event_deliveries` +1 | +1 | **still 5** |
| Event-generated audit rows +1 | +1 | **still 5** |
| `notifications` | +0 | **3,109 unchanged** |
| `workspace_memory` | +0 | **41 unchanged** |
| Queued registration jobs | +0 | **0** |
| Provider calls | 0 | **0** |

---

## 13. Concurrency with other engineering sessions

**Active and disruptive.** Observed processes:

```
php vendor/bin/phpunit … 11 Studio test files (batched, ~3 min runs)
php artisan test -c phpunit.e888.xml        (a SECOND phpunit config)
pest --configuration=…/phpunit.xml -c phpunit.e888.xml
```

Their runs repeatedly drop and rebuild the shared `levelup_test` schema. I observed `platform_events`, `platform_event_deliveries`, `domain_orders` and `domain_order_items` vanish mid-run on four separate occasions. My suite passed 30/30 in a clean window; full-suite runs collapsed with `Base table or view not found` at varying points.

I restored the four migrations against `levelup_test` **only** (with `DB_DATABASE` overridden for that process), never touching `levelup_staging`, and I did **not** run `migrate:fresh` on a database another session was actively using.

They also modified files I am working near:

```
bootstrap/app.php                                   12:24  (mine: 3 platform-events entries still present, schedule still firing)
app/Console/Commands/EngineeringBriefCommand.php    12:27
app/Core/Engineer888/**                             12:27  (new subsystem)
```

**Protected-path drift is `1/0/0` on `bootstrap/app.php`, and I deliberately did NOT re-seal it** — the change is theirs, not mine, and sealing it would silently bless an edit I have not reviewed.

---

## 14. Rollback position

**Technical rollback vs business-history reversal — the distinction matters here and nothing needs either.**

- **Technical rollback** of Phase 1E: flip `PLATFORM_EVENTS_DOMAIN_ORDER_PAID` (already false), or revert six files. No migration ran, no schema changed, no row was written.
- **Business-history reversal** would mean undoing a real payment fact. **None exists** — no order was paid, so there is nothing to reverse.

Nothing was deleted: no paid event, no delivery, no audit record, no governance authorisation. The five internal orders are untouched and none was cancelled.

---

## 15. Replay-control final state

**No replay control was ever created**, so there is nothing to disable or remove:

- no replay route
- no admin endpoint or UI
- no second webhook secret
- no conditional signature bypass
- no temporary production switch
- `EventReplay` remains code-only, unexposed
- Phase 1C/1D instrumentation remains retired and archived; Phase 1D's guard-absence tests still pass

The only fixture-signing code lives in the test suite and uses an isolated test secret.

---

## 16. Deviations

1. **Stages B/C/D not performed** — four blockers (§2), three of which you pre-declared as stop conditions.
2. **Requirement 13 inverted and documented** rather than satisfied (§11).
3. **Requirement 21 unconfirmed** — concurrent test-database churn (§13).
4. **Deployment gate is not deployment-time** — no deployment mechanism exists; placed at the processing boundary and explicitly not claimed as deployment enforcement (§9).
5. **`workspace_id` excluded from the payload** despite being on your "consider" list — the envelope carries it, and two copies can disagree. Documented in the event class.
6. **`capability_key` is null** for this event — an inbound provider callback is not an actor invoking an engine action.
7. **`payment_reference` has a session-id fallback** so a missing payment intent cannot roll back a real payment.

---

## 17. Unresolved decisions

1. **AUTHORISE THE P0 FIX** — one line, `app(...)` → `::make()`. Until then domain payment confirmation is broken in production.
2. **Registration containment** — approve the proposed `domains.fulfilment.enabled` flag (default true) so a controlled paid replay becomes possible.
3. **Fixture reachability** — a replay needs a `stripe_session_id` on the selected order. That is money-path data; it needs authorisation, or a checkout run (a Stripe API call).
4. **Replay signing secret** — decide between a narrowly-conditioned internal replay secret and abandoning live replay in favour of the test-environment proof already delivered.
5. **The always-200 webhook contract** — correct for retry storms, but it converts a handler crash into a silent, permanent data loss. Consider returning 500 for *unexpected* exceptions (so Stripe retries) while keeping 200 for signature failures and unhandled event types.
6. **Shared `levelup_test`** — two sessions cannot run suites concurrently. A per-session test database or a lock would remove a whole class of false results.
7. `OutboxDispatcher` retirement, the `status` → `dispatcher_status` rename, and cleanup of the five internal orders all remain open from earlier phases.

---

## 18. Recommended next producer

**None yet. Fix the P0 first.**

Adding `domain.registered` or `domain.registration_failed` now would mean building observability on top of a payment path that cannot execute. The value of an event spine is that it tells the truth about what happened; a `domain.order.paid` producer that can never fire because its caller throws is worse than no producer, because the chain will look healthy and silent.

**Order I recommend:**

1. **P0 fix** + enable `PLATFORM_EVENTS_DOMAIN_ORDER_PAID` for ws1, then a controlled paid replay once containment exists.
2. **`domain.registered` and `domain.registration_failed` together** — they are the success/failure pair around `RegisterDomainJob`, and proving them side by side is what stops a failure being mistaken for silence.
3. Only then widen beyond workspace 1.

---
---

# PHASE 1E.2 — INTERNAL ORDER PREPARATION, SIGNED REPLAY, AND A PRODUCTION WIPE

**Date:** 2026-07-30 · **Status:** ⚠️ **VALIDATION SUCCEEDED, THEN ITS EVIDENCE WAS DESTROYED BY AN UNRELATED PRODUCTION WIPE**

The signed webhook replay completed successfully at 13:39. Between 13:50 and 13:58 an unrelated `db:wipe` + full re-migrate dropped every table in `levelup_staging` (production), and it was then restored from the 01:00 backup. **All Phase 1C/1C.1/1D/1E database evidence is gone**, along with everything else written to production after 01:00 today.

**I did not cause this, and I did not perform the restore.** Details and evidence in §E below.

---

## A. Stage 1 — pre-activation verification

Concurrency inspected first: the other session was working in `app/Core/Engineer888/**`, `EngineeringBriefCommand`, `RepositoryIntelligenceCommand` and `ImageIntelligence*` — **no overlap** with webhook, payment, domain-commerce, event-registry, routes or scheduler files.

```
platform_events 5 · deliveries 5 · audit-from-events 5 · domain.order.paid 0
domain_orders 7 · notifications 3111 · workspace_memory 41 · queued registration jobs 0
flags: foundation ✅ created ✅ paid ❌ fanout ✅ delivery ✅ audit ✅ allow-list [1] fulfilment false
platform-events:verify  PASS 15/15
isolated suite          OK (248 tests, 4456 assertions) in levelup_p1e1_test
```

### Selected order — **order 3**

| Attribute | Value |
|---|---|
| Order ID / workspace | **3** / **1** |
| Status | `pending` |
| paid_at | NULL — **unpaid** |
| stripe_session_id | NULL |
| stripe_payment_intent_id | NULL |
| `internal_test` | **true** |
| Item 3 | `pending`, `registered_at` NULL, `provider_order_id` NULL — **unfulfilled** |
| Cancelled | no |
| Registrar purchase | none |
| Registration job | none — `tasks-high` depth 0 |

No sixth order was created.

---

## B. Stage 2 — governed setup command

`app/Console/Commands/AttachTestSessionCommand.php` — Artisan only, no route, no UI.

Refusals, all fail-closed: non-workspace-1 · non-`internal_test` · already paid · cancelled · status mismatch · any item not unfulfilled · **any existing session or payment reference** · a session id not beginning `cs_test_`. Requires `--dry-run` first, then a confirmation token derived from the plan.

### Dry-run → execution

```
PLAN
  order                    3 (workspace 1)
  status                   pending  (unchanged)
  paid_at                  NULL  (unchanged)
  stripe_session_id        NULL  ->  cs_test_boss888_p1e_order_3   <== THE ONLY CHANGE
  stripe_payment_intent_id NULL  (unchanged — the webhook supplies it)
  total_minor              1817 (unchanged)     items 1, all pending (unchanged)
  no payment transition · no platform event · no queued job · no Stripe API call
DRY RUN — nothing written. Token: f16681179f487ae0e8948c25
```

Executed with the token. **Exactly one column changed.** Verified deltas:

| Check | Result |
|---|---|
| platform_events | 5 → 5 — no event created |
| platform_event_deliveries | 5 → 5 |
| audit rows from events | 5 → 5 |
| audit_logs (all) | 7708 → 7709 — **exactly one governance row** |
| notifications / workspace_memory | unchanged |
| queued registration jobs | 0 |
| orders 4–7 | `stripe_session_id` still NULL, 4/4 |

Governance record #8029: `workspace_id=1, user_id=1, action=domains.test_session_attached, entity=domain_order:3`, metadata naming the authorising operator, the previous (null) reference and the unchanged status/paid_at.

---

## C. Stage 3 + 4 — the one-time signed replay

### Fixture authorisation — exactly one of everything

```
event id   evt_test_boss888_p1e_order_3
session    cs_test_boss888_p1e_order_3
intent     pi_test_boss888_p1e_order_3
order      3          workspace 1
type       checkout.session.completed        livemode false
secret     purpose-built, generated for this run, NEVER the live STRIPE_WEBHOOK_SECRET
```

The fixture verifier (`StripeService::verifyAuthorisedFixture`) requires **all** of: explicit flag · approved environment · **loopback source address** · a secret that exists and is not the live secret · every identifier configured · fulfilment disabled · paid producer enabled · signature verified by the real `\Stripe\Webhook::constructEvent` against the fixture secret · exact event id · exact type · `livemode === false` · exact session · exact payment intent · the session resolving to the one authorised order in workspace 1. Any missing or mismatched condition returns null and the request is rejected as an invalid signature. No wildcard, no prefix match.

Immediately before the replay: `platform-events:verify` **PASS 15/15**.

### The replay

`domains:replay-test-webhook` built the body, signed it with Stripe's own scheme, and POSTed it over **real HTTP to the real local route** — routing → middleware → controller → `StripeService` → real signature verifier → idempotency → transaction → event creation. It never called `StripeService` or `markPaidAndProvision()` directly.

```
POST http://127.0.0.1/api/webhook/stripe   (Host: levelupgrowth.io)

HTTP 200
{"received":true,"result":{"handled":true,"action":"marked_paid","order_id":3,
 "fresh":true,"dispatched":0,"fulfilment":"suppressed",
 "outcome":"ok","type":"checkout.session.completed"}}

order 3   pending -> paid       paid_at 2026-07-30 13:39:00
          stripe_payment_intent_id  pi_test_boss888_p1e_order_3
          status = paid  (NOT provisioning)
```

**Fixture replay was disabled immediately after the first successful response**, before any further step.

| Expected | Actual |
|---|---|
| signature accepted | ✅ |
| HTTP 200 | ✅ |
| order paid, paid_at populated, reference persisted | ✅ |
| order does NOT enter provisioning | ✅ stayed `paid` |
| fulfilment_suppressed metadata | ✅ merged, prior provenance preserved |
| one `domain.order.paid` event | ✅ 0 → 1 |
| zero registration jobs | ✅ 0 |
| zero Stripe API calls / Namecheap calls | ✅ 0 / 0 |
| zero notifications / memory writes | ✅ 3111 → 3111, 41 → 41 |
| orders 4–7 untouched | ✅ |

---

## D. Stage 5 + 6 — fan-out, audit and duplicate

The **live 5-minute cron** fanned it out at 13:40:10 (my manual pass found nothing left to do).

```
event #6  eb388c5c-6c6a-8395-307e-85c183e2120d   domain.order.paid v1   ws 1
  subject domain_order:3
  actor_type system · actor_id NULL · capability_key NULL
  correlation_id d7292c02-…  (the SAME journey as domain.order.created)
  causation_id   4c315a96-…  (the domain.order.created event itself)
  occurred_at 13:39:00 · recorded_at 13:39:00 · fanned_out_at 13:40:10 · delivery_count 1

payload: {"paid_at":"2026-07-30 13:39:00","currency":"USD","order_id":3,
          "total_minor":1817,"payment_provider":"stripe",
          "payment_reference":"pi_test_boss888_p1e_order_3"}

delivery #6  platform.audit v1  delivered  attempt 1  13:40:10
audit    #8030  ws=1  user_id=NULL  action=domain.order.paid  entity=domain_order:3
         detail.fulfilment_state = not_fulfilled_at_time_of_event
```

**Actor semantics exactly as required:** `actor_type=system`, `actor_id=null`, `user_id` NULL on the audit row — the payment is not misattributed to the operator who authorised the replay, whose authorisation is a **separate** governance record (#8029).

**Parity:** events 5→6 · deliveries 5→6 · audit-from-events 5→6 · paid events 1 · projection 1 = ledger 1 · delivered 6 · retries 0 · failures 0 · duplicate event_ids 0 · duplicate deliveries 0 · events outside ws1 0.

### Duplicate, through the same real route

Fixture re-enabled only for the idempotency check, then disabled again.

```
HTTP 200
{"received":true,"result":{"handled":true,"action":"already_processed","order_id":3,
 "outcome":"ok","type":"checkout.session.completed"}}

paid_at before 13:39:00 → after 13:39:00 (unchanged) · status paid
paid events 1 · deliveries 6 · audit-from-events 6 · reg jobs 0 · notifications/memory unchanged
```

No different-event-id or concurrent scenario was run in production; both remain automated-test evidence.

---

## E. ⚠️ THE PRODUCTION WIPE

### Timeline

| Time | Event |
|---|---|
| 13:39:00 | signed replay succeeds; order 3 paid; event recorded |
| 13:40:10 | scheduled cron fans out and delivers; audit row written |
| 13:45:02 | clean scheduler cycle |
| **13:50:00 – 13:58:09** | **every table in `levelup_staging` dropped and recreated** (`information_schema` create_time range) |
| 13:57:24 | someone captured `/root/backups/db-EMPTIED-20260730-135724.sql.gz` (46 KB — the emptied state) |
| ~13:58 | restore from `/root/backups/db-20260730-0100.sql.gz` (the 01:00 backup) |
| now | stable: users 11, workspaces 25, websites 3, audit_logs 7678, domain_orders 2, platform_events 0 |

### Cause

`storage/logs/laravel.log` contains a stack trace through
`Illuminate\Database\Console\WipeCommand->dropAllTables()` — i.e. `php artisan db:wipe`
(or `migrate:fresh`) executed against the default connection, which is `levelup_staging`.

**Not mine.** My test isolation refuses that database by construction:
`tests/Support/IsolatedDatabase` fails any suite whose resolved database is
`levelup_staging` or the shared `levelup_test`, and the repository's own
`ProductionDatabaseGuard` requires a positive `/_test$/` match before any suite runs.
Every command I issued named an explicit non-production database
(`env DB_DATABASE=levelup_p1e1_test`) or was a read.

**I did not perform the restore either.** It appeared between two of my read-only
status commands.

### What was lost

Everything written to production after 01:00 today:

- the five Phase 1C internal test orders (ids 3–7) — `domain_orders` is back to 2
- all 6 platform events, 6 deliveries and 6 event-generated audit rows
- order 3's paid state and its payment reference
- the Phase 1E.2 governance records (#8029 setup, #8030 audit)
- the Phase 1C.1 targeted-replay evidence
- the Phase 1D projection repair
- whatever the other session had written after 01:00

**Application code is intact** — every file change from Phases 1C through 1E.2 is on disk and unaffected. `platform-events:verify` still passes 15/15, and the site returns 200 on `/api/health`, `/admin/dashboard` and `/app`.

### What this means for the validation

**The validation itself succeeded** — the outputs above are the captured evidence, and they are reproducible. What no longer exists is the *database state* that demonstrated it. Anyone auditing production today will find no `domain.order.paid` event, because production was rolled back to a point before this milestone began.

**I stopped on discovery and made no further writes.**

---

## F. Fixture control retirement — completed BEFORE the wipe

```
enabled=false  secret_set=false  event_id=(blank)  session_id=(blank)
intent=(blank)  order=0  ws=0

domains:replay-test-webhook   ->  REFUSED: fixture replay is not enabled
domains:attach-test-session 3 ->  REFUSED: order 3 is already paid
```

And proven negatively: the **retired fixture secret, replayed again through the real route**, returned

```
HTTP 400  {"received":false,"result":{"handled":false,"outcome":"signature_invalid",
           "error":"Invalid webhook signature"}}
```

The fixture path no longer exists. There is no public endpoint, no admin interface, and no second general webhook secret. Tests and documentation are retained.

---

## G. Tests

All in the isolated database `levelup_p1e1_test`.

| Suite | Result |
|---|---|
| `FixtureControlsTest` (new) | **29 tests / 64 assertions OK** |
| Platform events (Phase 0 → 1E.2) | **248 / 4,456 OK** (before the wipe) |
| Governance · Domains+Namecheap · Component | 44 / 100 / 37 OK |

`FixtureControlsTest` covers requirements 1–25: the setup command refuses workspace 2, customer orders, paid orders, cancelled orders, an existing reference, a non-test session id and a wrong confirmation token; its dry-run is byte-for-byte read-only. The fixture is rejected without the flag, from a non-local origin, with no source ip at all, for the wrong workspace / order / event id / session / payment intent, for a live-mode object, when fulfilment is enabled, when the paid producer is disabled, and when the secret is blank — while ordinary traffic signed with the live secret continues to work.

One control could not be exercised as originally written: the approved-environment check. The suite runs as `testing`, so the condition closed the path and the positive tests could not pass. The approved environments are now an explicit config list (`['staging','production']` — production behaviour unchanged) so the control is testable rather than being the one condition nothing could prove.

---

## H. Final state

**Configuration (files and `.env`) — intact and as specified:**

```
platform_events.enabled              true
producers[domain.order.created]      true
producers[domain.order.paid]         true      ← enabled for workspace 1
producer_workspace_allowlist         [1]       ← ws2 and all customer workspaces blocked
fanout.enabled / delivery_worker     true / true
subscribers[platform.audit]          true
domains.fulfilment.enabled           false     ← still disabled
domains.fixture_replay.enabled       false     ← retired, every value blank
schedule                             */5 * * * *  (firing)
```

**Database — rolled back to the 01:00 backup by the wipe and restore:**

```
platform_events 0 · platform_event_deliveries 0 · domain.order.paid 0
domain_orders 2 · audit_logs 7678 · users 11 · workspaces 25 · websites 3
queued registration jobs 0
```

---

## I. Unresolved decisions

1. **The production wipe is the priority, not this milestone.** Root cause, who ran it, and whether any customer-visible data was lost between 01:00 and 13:50 all need establishing. `/root/backups/db-EMPTIED-20260730-135724.sql.gz` preserves the emptied state.
2. **Whether to re-run Phase 1E.2.** Everything needed is on disk: the setup command, the fixture control, the replay command and the tests. Re-running would need fresh authorisation, a new fixture secret and a newly prepared order (the five test orders no longer exist).
3. **`domain.order.paid` is enabled for workspace 1 with an empty event table.** Harmless, but the configuration and the data no longer tell the same story.
4. **Shared-host isolation.** My session's test isolation held perfectly; the wipe came from a command run against the default connection. A guard on `db:wipe`/`migrate:fresh` when the target is `levelup_staging` would have prevented this outright — the same fail-closed pattern already applied to test suites.
5. Carried forward: `OutboxDispatcher` retirement, `status` → `dispatcher_status`, and the five internal orders (now gone).

---
---

# PHASE 1E.2 (RE-ISSUE, 2026-08-01) — BLOCKED AT PART A

**Status:** ⏸️ **NOT RUN — the five internal test orders no longer exist**
**Setup command: not executed · Fixture: not authorised · Replay: not sent · Images/provider calls: 0**

This brief was executed once already, on **2026-07-30**, and succeeded (§C–D above). The
**production wipe at ~13:50 that day** rolled `levelup_staging` back to the 01:00 backup,
destroying every row of that evidence. This section covers the re-issue against today's
state.

---

## Part A — precondition fails

The brief requires selecting one of *"the five existing internal test orders"* and says
explicitly **"Do not create another order."** Both cannot hold: there are none.

```
id  ws  status     paid_at              stripe_session_id                  internal_test
1   1   completed  2026-07-29 12:00:32  cs_test_a1LHA4FBhIyF9g6dhO4Ikk…    NULL
2   2   completed  2026-07-29 13:11:46  cs_test_a19AhOzLt1vcXPiQsMvb5T…    NULL

total domain_orders : 2
internal_test orders: 0
```

Orders **3–7** — created in Phase 1C, marked `internal_test`, unpaid — were destroyed by
the wipe. Neither survivor is eligible under any rule: order 1 is not `internal_test` and
is already paid; order 2 is in **workspace 2** and already paid.

All Phase 1E.2 database evidence is likewise gone: `platform_events` 0,
`platform_event_deliveries` 0, event-generated audit rows 0, setup governance records 0.

## The governed controls survive, and were proven to refuse

Every control built on 2026-07-30 is intact on disk: `AttachTestSessionCommand`,
`ReplayTestWebhookCommand`, the `fixture_replay` config block, `verifyAuthorisedFixture`
in `StripeService`, and both test suites.

Exercised read-only against production, in dry-run:

```
order 1 → REFUSED: order 1 is NOT marked internal_test — a fixture must never touch a customer order
order 2 → REFUSED: order 2 belongs to workspace 2; only workspace 1 is permitted
order 3 → REFUSED: order 3 does not exist
replay  → REFUSED: fixture replay is not enabled
```

Nothing was written: `domain_orders` unchanged (2 rows, both still `completed`),
`audit_logs` 7,703 unchanged, `platform_events` 0.

That is requirements 3, 4, 5 and 7 of the test list demonstrated **in production**, not
just in the suite.

## A defect of mine, found and fixed

`OutboxArchitectureTest::test_only_the_outbox_service_touches_the_outbox_table` failed:

```
These files reference the platform_events table directly:
  - app/Console/Commands/ReplayTestWebhookCommand.php
```

I introduced that on 2026-07-30 — the replay command printed a convenience count by
querying `platform_events` directly — and never caught it, because the wipe interrupted
the final full-suite run. The invariant is that only `Outbox` touches that table.

**Fixed:** the count line is gone (the command already prints order status, `paid_at` and
the payment reference, which is the meaningful evidence), and the now-unused `DB` import
was removed. A convenience count is not a good enough reason to become a second reader of
the outbox.

## Verification after the fix

```
platform events suite (isolated)  OK (277 tests, 4548 assertions)
FixtureControlsTest               OK (29 tests, 64 assertions)
PaymentWebhookRemediationTest     OK (21 tests, 90 assertions)
platform-events:verify            PASS — 15 checks, 0 failures
isolated database                 levelup_p1e1_test
```

## Current production state

```
platform_events.enabled                   true
producers[domain.order.created]           true
producers[domain.order.paid]              true    ← see discrepancy below
producer_workspace_allowlist              [1]
fanout / delivery / platform.audit        true / true / true
domains.fulfilment.enabled                false
domains.fixture_replay.enabled            false   (every value blank)
queued registration jobs                  0
platform_events / deliveries / audit      0 / 0 / 0
```

**Discrepancy, reported not silently corrected:** the brief's safety baseline states
*"domain.order.paid producer initially disabled"*, but it is currently **true** — left
enabled after the 2026-07-30 validation, per that run's agreed final state. Nothing can
produce (no eligible order exists), so it is inert. I have not flipped it, because the two
briefs disagree and the choice is yours.

## Concurrency

No overlap. No modifications to webhook, payment, domain-commerce, event-registry, routes
or scheduler files in the last 90 minutes. One unrelated test process from the other
session was running. The shared `levelup_test` was not touched.

## What is required to run this phase

One of:

1. **Authorise creating a replacement internal test order** in workspace 1, marked
   `internal_test`, unpaid — the brief currently forbids this. It is the smallest unblock:
   the setup and replay commands, the fixture control and all 27 tests are already built
   and passing.
2. **Restore orders 3–7 from the pre-wipe state.** They are not in the 01:00 backup
   (they were created at 06:29–06:34 that morning, after it was taken), so this would mean
   reconstructing them — effectively option 1 with extra steps.
3. **Accept the 2026-07-30 run as the validation of record**, using the captured evidence
   in §C–D, and treat the missing rows as a casualty of the wipe rather than re-running.

Option 1 is the honest path to live evidence. Option 3 is defensible only if the captured
outputs are accepted as sufficient — they are detailed, but the rows behind them no longer
exist and cannot be re-inspected.

---
---

# REPLACEMENT ORDER + REVALIDATION (2026-08-01) — STOPPED AT THE PRECONDITION

**Status:** ⏸️ **NO REPLACEMENT ORDER CREATED — the Phase 1F containment does not exist**
**Production data writes: 0 · Namecheap calls: 0 · Stripe calls: 0 · Orders created: 0**
**One instructed, risk-reducing config correction applied (see §4).**

---

## 1. Why the prior rows were unavailable

Orders 3–7 were created 2026-07-30 at 06:29–06:34 and destroyed by the wipe at ~13:50.
The restore used `db-20260730-0100.sql.gz`, taken at 01:00 — **before** they existed — so
they are in no backup. All Phase 1E.2 evidence went with them: `platform_events` 0,
deliveries 0, event-generated audit 0, setup governance records 0.

## 2. Why orders 1 and 2 were refused

Demonstrated in production, read-only, via the governed command's dry-run:

```
order 1 → REFUSED: order 1 is NOT marked internal_test — a fixture must never touch a customer order
order 2 → REFUSED: order 2 belongs to workspace 2; only workspace 1 is permitted
order 3 → REFUSED: order 3 does not exist
replay  → REFUSED: fixture replay is not enabled
```

Both survivors are also already `completed` and paid. Nothing was written.

## 3. ⛔ PRECONDITION FAILURE — Phase 1F containment does not exist

The brief requires, before any production write, that *"the production database-wipe
containment from Phase 1F is active"*, and to **stop if it is not proven**.

**It is not proven, because Phase 1F was never carried out.** I recommended a `db:wipe`
guard in the 30 July incident report; it was never authorised or built.

| Check | Result |
|---|---|
| `BOSS888-PHASE-1F*` report in the repo | **none** |
| Guard on `db:wipe` / `migrate:fresh` / `dropAllTables` | **none** — the only match anywhere is a *comment* in `Engineer888/Execution/TestSelector.php` describing the incident |
| `ProductionDatabaseGuard` scope | **phpunit only** — invoked at `tests/TestCase.php:40,65` and in chat safety tests. **No console command calls it.** |
| `php artisan db:wipe` | still available: *"Drop all tables, views, and types"* |

**The exact failure mode that wiped production on 30 July is still open.** Creating a
replacement order and a paid event into an unprotected database would be rebuilding
evidence on the same ground that swallowed the last set.

Secondary preconditions: no destructive process running (0); production has 204 tables,
created 2026-07-30 13:57–13:59, i.e. the restore and **no reset since**; isolated test
database `levelup_p1e1_test` active with 201 tables. Those are fine — the containment is
the failure.

## 4. Flag correction — applied

The brief's baseline requires `domain.order.paid` disabled during order preparation; it
was `true`, left enabled after the 30 July run. Disabling it **reduces** risk, is
explicitly instructed, is not a destructive operation and does not depend on the wipe
containment, so it was applied.

```
BEFORE  foundation=true created=true paid=true  fanout=true delivery=true audit=true allow=[1] fulfil=false fixture=false
AFTER   foundation=true created=true paid=false fanout=true delivery=true audit=true allow=[1] fulfil=false fixture=false
```

`platform-events:verify` **PASS 15/15** after the change. No other configuration touched.

## 5. ⛔ PARALLEL-SESSION OVERLAP

The brief requires stopping on overlap. There is overlap.

| File | Owner / mtime | Assessment |
|---|---|---|
| `tests/TestCase.php` | www-data, **2026-08-01 10:43** | **Another session is editing it now**, and `config/governed_files.php:18` **declares their ownership of it**. My `Tests\Support\IsolatedDatabase` guard depends on its behaviour (`ProductionDatabaseGuard` is invoked there). |
| `config/governed_files.php`, `Engineer888/Coordination/{DatabaseAssignment,GovernedFiles,OwnershipManifest}.php`, `Execution/PreflightCheck.php`, `CoordinationCommand.php` | modified within the hour | They are building a coordination/ownership system — active, adjacent work |
| migration `2026_07_29_130000_add_execution_provenance_to_api_usage_logs` | **Pending** | Theirs, unapplied. Schema is not in a settled state. |

**Change classification, as required:**

1. **This session's changes:** `PLATFORM_EVENTS_DOMAIN_ORDER_PAID=false` (§4); the
   `ReplayTestWebhookCommand` architecture fix (§6). Nothing else.
2. **Pre-existing:** everything from Phases 1C–1E.1 and WP2 2.2B.
3. **Concurrent (other session):** `tests/TestCase.php`, `config/governed_files.php`, the
   whole `app/Core/Engineer888/**` tree, one pending migration.
4. **Unresolved ownership:** `tests/TestCase.php` — they claim it; my test isolation
   depends on it. This needs settling before I run suites that rely on its guard.

## 6. Architecture defect fix — preserved and proven

```
DB::table('platform_events') occurrences in ReplayTestWebhookCommand.php : 0
platform events suite (isolated)                                          : OK (277 tests, 4548 assertions)
platform-events:verify                                                    : PASS — 15 checks, 0 failures
```

No allow-list exception was added to hide the violation — the direct read was removed, not
permitted. `OutboxArchitectureTest` is green on its own terms.

## 7. Final state — unchanged except the flag

```
platform_events.enabled                  true
producers[domain.order.created]          true
producers[domain.order.paid]             false   ← corrected to baseline
producer_workspace_allowlist             [1]
fanout / delivery / platform.audit       true / true / true
domains.fulfilment.enabled               false
domains.fixture_replay.enabled           false   (all values blank, secret absent)
domain_orders                            2       (no replacement order created)
platform_events / deliveries / audit     0 / 0 / 0
queued registration jobs                 0
isolated test database                   levelup_p1e1_test
```

## 8. What must happen before this phase can run

1. **Build and prove the Phase 1F containment** — a fail-closed guard on `db:wipe`,
   `migrate:fresh` and `migrate:refresh` when the resolved database is `levelup_staging`,
   mirroring `ProductionDatabaseGuard` but at the **console-command** boundary rather than
   the phpunit boundary. This is the one change that would have prevented 30 July, and it
   is currently the only thing standing between us and a repeat.
2. **Settle ownership of `tests/TestCase.php`** with the other session, or confirm their
   edits do not weaken `ProductionDatabaseGuard::assertSafeTestTarget()`.
3. **Let their pending migration land**, so the schema is settled before production writes.

Only then: create the single replacement order and resume the approved 11-step flow. Every
control it needs — setup command, fixture verifier, replay command, 27 tests — is built,
green and waiting.
