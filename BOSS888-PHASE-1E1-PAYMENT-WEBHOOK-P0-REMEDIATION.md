# BOSS888 — PHASE 1E.1 PAYMENT WEBHOOK P0 REMEDIATION

**Date:** 2026-07-30 · **Status:** ✅ **PASSED**
**P0 fixed** · **Retryable failures now return 503** · **Fulfilment contained** · **Test isolation established**
**429 tests green in an isolated database** · `platform-events:verify` **PASS 15/15**
**Production unchanged:** 0 `domain.order.paid` events · 5 orders still `pending` · 0 queued registration jobs · 0 Stripe calls · 0 Namecheap calls

---

## 1. P0 incident statement

**The failure chain, exactly as it stood:**

```
Stripe webhook (checkout.session.completed, domain order)
  → domain-order branch in StripeService::handleWebhook()
  → app(\App\Services\Domains\DomainCommerceService::class)
  → BindingResolutionException
       "Unresolvable dependency resolving [Parameter #0 [ <required> string $environment ]]
        in class App\Connectors\Infrastructure\Namecheap\NamecheapClient"
  → route catches \Throwable
  → route returns HTTP 200 {"received": true}
  → Stripe records a SUCCESSFUL delivery
  → no retry, ever
  → order remains `pending` despite a completed customer payment
```

**This is a potential charged-but-unfulfilled incident.** A customer completes checkout, Stripe takes the money, the platform records nothing, and Stripe is told everything is fine. The only trace was a single `staging.ERROR` line.

The dependency chain: `DomainCommerceService` → `DomainPricingService` → `NamecheapRegistrarConnector` → `NamecheapClient(string $environment, …)`, and nothing binds `$environment`. The class has always provided a `::make()` factory for exactly this reason, and **every other caller uses it** — `CustomerDomainController` (5 sites) and `DomainAdminController`. This one branch did not.

### No verified real domain payment has ever traversed this path

```
order 1  ws1  completed  paid_at 2026-07-29 12:00:32  intent = pi_e2e_simulated
order 2  ws2  completed  paid_at 2026-07-29 13:11:46  intent = pi_chefred_accept
```

Both references are **simulated values written by test harnesses**, not Stripe payment intents. They prove a code path was called directly; they prove nothing about webhook processing. Orders 3–7 have never been paid. The branch has therefore been broken for its entire existence without observable symptom.

---

## 2. Part A — service resolution fix

**Narrow, one call site, using the established pattern.**

```php
// before
return app(\App\Services\Domains\DomainCommerceService::class)
    ->markPaidAndProvision((string) ($session->id ?? ''), (string) ($session->payment_intent ?? ''));

// after
return $this->handleDomainCheckoutCompleted($event, $session);
// … which calls \App\Services\Domains\DomainCommerceService::make()->markPaidAndProvision(…)
```

Dependency injection was **not** redesigned across Domain Commerce. No container binding was introduced: `::make()` is the pattern already in use, and adding a binding would have been a wider change with no benefit here.

**Regression tests that fail against the broken call and pass only when it resolves:**

- `test_the_domain_webhook_branch_now_resolves_the_service` — asserts the `app(...)` form is **absent**, `::make()` is present, and that `app(DomainCommerceService::class)` genuinely still throws (proving the fix was necessary, not cosmetic).
- `test_the_webhook_reaches_the_payment_path_without_throwing` — a signed fixture through the real verifier now returns `handled=true, action=marked_paid` with the correct order id.

---

## 3. Part B — webhook HTTP semantics

Outcome classification lives in `StripeService` (where the knowledge of retryability is); the route only translates it to a status code.

| Outcome | HTTP | Cases |
|---|---|---|
| `OUTCOME_OK` | **200** | processed successfully · duplicate already processed · deliberately ignored event type · irrelevant but safe to acknowledge |
| `OUTCOME_SIGNATURE_INVALID` | **400** | signature did not verify — rejected, never acknowledged |
| `OUTCOME_RETRYABLE` | **503** | service-resolution failure · transient database failure · lock timeout · unavailable dependency · any unexpected exception before durable completion · **unresolved order** |
| escaped exception | **503** | we do not know whether the work committed, so we must not claim success |

**Stripe retry implications.** A 503 makes Stripe retry with backoff, which is correct: the payment is real and the work is not yet durable. A 400 for a signature failure will also be retried by Stripe and will surface in the dashboard — deliberately, because the realistic cause is a wrong or rotated `STRIPE_WEBHOOK_SECRET`, and the original code's own comment identified silent discard as the greater hazard. A retry loop that is loudly visible is better than silence.

**No internal detail is returned to Stripe** — `test_no_internal_exception_detail_is_returned_to_stripe` asserts the response body contains no `BindingResolutionException`, no `NamecheapClient`, no stack trace, and neither secret.

**Structured internal logging** carries: Stripe event id, event type, session reference, payment reference, order id when resolved, classification, `retryable` boolean, exception class, correlation id, timestamp. It carries **no** secret, no card data, no signing secret and no raw body.

### Permanent failure policy

| Case | Current behaviour |
|---|---|
| Signature failure | **400, rejected** — remains rejected, as required |
| Unknown order for the session | **503 + durable incident record** — option (1): non-2xx plus operator remediation, so a replay can succeed once the association is fixed |
| Wrong workspace / malformed metadata / invalid business state | reaches the same unresolved-order path → 503 + incident record |
| Order cancelled before payment | not currently distinguished — see unresolved decisions |
| Payment reference conflicts with another order | not currently detected — see unresolved decisions |

**A valid payment that cannot safely update an order fails visibly.** It is never silently acknowledged.

---

## 4. Part C — durable idempotency model

Inspected, and it is durable at the database level in two independent layers:

**Layer 1 — the payment transition.** Inside one transaction: `DomainOrder::where('stripe_session_id', …)->lockForUpdate()->first()`, then `if ($order->isPaid()) return already_processed`, then the write. The guard and the write share a row lock, so a concurrent duplicate blocks and then observes the committed state.

**Layer 2 — the event.** `event_id = hash(domain.order.paid | v1 | domain_order | <order_id> | ws<id>)` with a unique index. One order can produce exactly one paid event, whatever the Stripe event id.

| Required guarantee | Mechanism | Test |
|---|---|---|
| Same Stripe event id cannot process twice | row lock + `isPaid()` | `test_a_duplicate_already_processed_event_returns_2xx` |
| Different event ids, same intent, cannot pay twice | same — keyed on the ORDER, not the event id | `test_the_same_event_pays_once_and_a_second_event_id_does_not_pay_again` |
| HTTP retry after commit returns success without a second event | `already_processed` → 200 | same test |
| Concurrent deliveries cannot double-pay or double-dispatch | `lockForUpdate` + unique index | `test_concurrent_recording_leaves_exactly_one_paid_event` (Phase 1E) |
| Failure before commit stays retryable | nothing persisted → the guard sees an unpaid order | `test_a_failed_transaction_leaves_the_order_unpaid_and_retryable` |
| Not marked processed before commit | the event is written **inside** the payment transaction; the success marker is written only after it returns | same test |

**No additional mechanism was required**, so none was added. Nothing relies on process memory, cache or a controller conditional: the cache entries added in Part G are **observability only** and no decision depends on them.

---

## 5. Part D — fulfilment containment

New: `config/domains.php` → `domains.fulfilment.enabled`, `env('DOMAINS_FULFILMENT_ENABLED', true)`.

**The code default is `true`**, explicitly verified as the existing intended production behaviour (the previous code dispatched unconditionally). **The deployed value is `false`**, set in `.env` for the controlled Phase 1E validation.

At the single dispatch site, after the payment transaction has committed:

```php
if (config('domains.fulfilment.enabled') !== true) {
    $this->recordFulfilmentSuppressed($order);
    return $outcome + ['dispatched' => 0, 'fulfilment' => 'suppressed'];
}
```

**Payment is never conditional on fulfilment.** The payment transaction has already committed by this point; this decides only whether to act on it.

When disabled: the order is marked **paid** and stays `paid` — it does **not** advance to `provisioning`, and that status is itself the trace. A `fulfilment_suppressed` marker is **merged** into the existing `metadata_json` column (no schema change, and `priced_at` / `internal_test` provenance survives):

```json
{"fulfilment_suppressed": true,
 "fulfilment_suppressed_at": "…",
 "fulfilment_suppressed_reason": "domains.fulfilment.enabled is false",
 "registration_state": "pending_operator_fulfilment"}
```

Nothing is queued, no registrar is contacted, no customer is notified, and the item stays `pending` with `registered_at` null.

| Test | Proves |
|---|---|
| `test_fulfilment_disabled_pays_and_records_but_queues_nothing` | payment commits, paid event recorded, `Queue::assertNothingPushed()`, status stays `paid` |
| `test_fulfilment_disabled_does_not_claim_registration_completed` | suppressed marker present, `registration_state = pending_operator_fulfilment`, item untouched, prior metadata preserved |
| `test_fulfilment_enabled_preserves_the_existing_dispatch_behaviour` | 1 job pushed, status `provisioning` — unchanged from before |
| `test_the_code_default_preserves_the_intended_production_behaviour` | committed config still defaults to `true` |
| `test_re_enabling_does_not_replay_a_backlog` | re-enabling dispatches nothing for a historical order |

**No automatic backlog replay was introduced.**

---

## 6. Part E — internal test-order setup

**Not built, and not needed yet.** Your brief: *"If the setup write is not necessary until Phase 1E resumes, leave all five unchanged."* It is not necessary for 1E.1 — the remediation is proven in the isolated database with purpose-made orders. **All five production orders are untouched** (`stripe_session_id` still NULL, `paid_at` still NULL).

The guard logic a future setup command must satisfy is already written and tested (`assertFixtureOrderIsUsable` in `DomainOrderPaidTest`): refuses a non-`internal_test` order, refuses an already-paid order. Building the command belongs with the replay it enables, so both can be removed together.

**Design for when Phase 1E resumes** — internal Artisan command, no route, no UI:
`domains:attach-test-session {order} --expect-status=pending --session=… --confirm=<token> [--dry-run]`; single order id, workspace 1 only, refuses customer orders, refuses paid/fulfilled/cancelled, refuses overwriting an existing non-test reference, records user 1 as authoriser in `audit_logs`, prints exact before/after, never marks the order paid, never contacts Stripe, removed after validation.

---

## 7. Part F — signing-secret containment design

**Not implemented, as instructed** (*"Design and test the containment only"*). The live `STRIPE_WEBHOOK_SECRET` was never read, used or replaced.

**Proven in 1E.1:** the real, unmocked `\Stripe\Webhook::constructEvent` accepts a correctly signed fixture and rejects a wrong secret, a tampered body and a stale timestamp — using an **isolated test secret configured only in the test environment**. That is the containment property, demonstrated without weakening the public webhook.

**Design for the future replay** — an internal command submitting to the real local route, passing through routing, middleware, controller, signature verification, idempotency and the transactional payment path. Fixture-secret acceptance would require **all** of: an explicit feature flag; workspace 1; one exact fixture event id; one exact order id; local invocation; a test-mode Stripe object (`livemode: false`); and immediate disablement after use. **No generally accepted second webhook secret**, and no permanent bypass.

---

## 8. Part G — failure visibility

`EventSystemHealth` gained a `domain_payment_webhook` section, printed by `platform-events:process --health`:

```
domain payment webhook:
  last_success                   NULL
  last_failure                   NULL
  unacknowledged_failures        0
  retryable_failures             0
  permanent_failures             0
  oldest_unresolved_age_minutes  NULL
  fulfilment_enabled             false
  queued_registration_jobs       0
```

Successes and failures live under **separate cache keys**, precisely so a later clean webhook cannot quietly close an unresolved incident. An unacknowledged failure raises **CRITICAL** with the message *"a customer may have been charged without the order being updated"*. Fulfilment being disabled raises a standing **INFO**.

`test_a_later_successful_webhook_does_not_clear_failure_history` proves a good delivery leaves the incident count at 1 and health still CRITICAL. `test_the_failure_record_carries_no_secret_or_raw_body` proves the record contains no secret, no signature material and no raw body. **No customer notification path was created.**

---

## 9. Part H — test database isolation

**This was the precondition for every other claim in this report.**

During Phase 1E another engineering session ran large Studio batches plus its own `phpunit.e888.xml` suite, repeatedly dropping and rebuilding the shared `levelup_test`. I watched four tables vanish inside single runs, four separate times. Results obtained that way are not evidence.

**Established:**

```
database : levelup_p1e1_test        (201 tables, migrated fresh)
config   : phpunit.p1e1.xml         <env name="DB_DATABASE" value="levelup_p1e1_test" force="true"/>
guard    : tests/Support/IsolatedDatabase.php, used by all 12 platform-event test classes
```

The guard **refuses to run** against `levelup_staging` or the shared `levelup_test`, and fail-closed requires a positive match on `/^levelup_[a-z0-9]+_test$/` — an unrecognised name is rejected, not assumed safe. Demonstrated:

```
$ phpunit tests/Feature/PlatformEvents/…       (shared config)
REFUSING TO RUN: connected to 'levelup_test' (connection 'mysql').
```

**A discovery worth recording:** the repository already contains `app/Core/Safety/ProductionDatabaseGuard`, called from `tests/TestCase.php`, which requires a positive match on `/_test$/` before any suite may run — added after `--env=testing` resolved to production on 2026-07-27. My first database name (`levelup_test_p1e1`) failed it. I **renamed to conform** (`levelup_p1e1_test`) rather than widen an existing safety control that the other session also depends on.

Safeguards met: unique per-session name · runtime assertion of the resolved name · refuses staging/production/shared · **no `migrate:fresh` against any shared database** · Schema facade clearing retained in the migration tests · teardown scoped to this session's own workspace ids · the other session was never modified or interrupted.

---

## 10. Part I — review of the Stage A `domain.order.paid` work

| Check | Result |
|---|---|
| Producer flag | **`false`** in committed config and in production |
| Live paid event emitted | **none** — 0 rows |
| Replay control enabled | none exists |
| Atomic placement | inside the payment transaction, before it returns |
| Deterministic identity | `hash(type\|v1\|domain_order\|id\|ws)` — verified |
| `workspace_id` duplicated in payload | **no** — refused by name; the envelope owns it |
| `payment_reference` restrictions vs the future test reference | **compatible** — `pi_test_…` and `cs_test_…` pass; only `sk_`, `rk_`, `whsec_` prefixes are refused |
| `platform-events:verify` | **PASS 15/15** |
| Payload expanded / new event added | **no** |

One correction made during 1E.1: the origin lookup for `correlation_id`/`causation_id` queried `platform_events` **directly from `DomainCommerceService`**, which the architecture test correctly flagged — only the Outbox may touch that table. Moved to `Outbox::originEnvelope()` (read-only) rather than allow-listing the exception.

---

## 11. Tests

**All run in `levelup_p1e1_test`.**

| Suite | Result |
|---|---|
| **Platform events (Phase 0 → 1E.1)** | **248 tests / 4,424 assertions OK** |
| Governance | **44 / 732 OK** |
| Domains + Namecheap | **100 / 277 OK** |
| Component (node) | **37 passed, 0 failed** |

**429 tests green.**

| # | Requirement | Test |
|---|---|---|
| 1 | Webhook branch resolves the service | `test_the_domain_webhook_branch_now_resolves_the_service` |
| 2 | The original `app(...)` failure is prevented permanently | same (asserts the broken form is absent **and** still throws) |
| 3 | Retryable internal exception → non-2xx | `test_a_retryable_failure_returns_non_2xx` (503) |
| 4 | Successful webhook → 2xx | `test_a_successfully_processed_webhook_returns_2xx` |
| 5 | Duplicate already-processed → 2xx | `test_a_duplicate_already_processed_event_returns_2xx` |
| 6 | Invalid signature rejected | `test_an_invalid_signature_is_rejected_with_non_2xx` (400) |
| 7 | Tampered body rejected | `test_a_tampered_body_is_rejected` |
| 8 | Same event id processes once | `test_a_duplicate_already_processed_event_returns_2xx` |
| 9 | Different event ids, one intent, pay once | `test_the_same_event_pays_once_and_a_second_event_id_does_not_pay_again` |
| 10 | Concurrent duplicates pay once | `test_concurrent_recording_leaves_exactly_one_paid_event` |
| 11 | Transaction failure leaves order unpaid | `test_a_failed_transaction_leaves_the_order_unpaid_and_retryable` |
| 12 | Retry after failure can succeed | same |
| 13 | Not marked processed before commit | same (event absent after rollback) |
| 14 | Fulfilment disabled → zero jobs | `test_fulfilment_disabled_pays_and_records_but_queues_nothing` |
| 15 | Fulfilment disabled → zero Namecheap calls | same + `test_the_payment_path_makes_no_provider_or_stripe_api_call` |
| 16 | Fulfilment enabled preserves behaviour | `test_fulfilment_enabled_preserves_the_existing_dispatch_behaviour` |
| 17–20 | Setup command guards | `assertFixtureOrderIsUsable` guards + dry-run design (command not built — Part E) |
| 21 | Health records durable failure | `test_a_webhook_failure_is_recorded_durably_and_surfaces_in_health` |
| 22 | Later success does not clear history | `test_a_later_successful_webhook_does_not_clear_failure_history` |
| 23 | Paid producer disabled in production | `test_the_paid_producer_ships_disabled` + live config |
| 24 | `platform-events:verify` passes | **PASS 15/15** |
| 25 | Tests run in the isolated database | `test_this_suite_runs_in_an_isolated_database` |
| 26 | Existing suites green in a stable environment | table above |

**Two Phase 1E tests were deliberately inverted.** `test_p0_the_domain_webhook_branch_is_currently_unresolvable` and `test_p0_the_route_masks_the_failure_as_a_success` asserted the broken state so that fixing it would force a conscious update. They failed the moment the P0 was fixed — the mechanism working — and now assert the remediated behaviour.

---

## 12. Exact production configuration

```
platform_events.enabled                = true
producers[domain.order.created]        = true
producers[domain.order.paid]           = false     ← disabled
producer_workspace_allowlist           = [1]       ← ws1 only; ws2 permitted = false
fanout.enabled                         = true
delivery_worker.enabled                = true
subscribers[platform.audit]            = true
domains.fulfilment.enabled             = false     ← DISABLED (code default remains true)
platform-events:process schedule       = */5 * * * *  (firing)
```

**Production row state — unchanged by this milestone:**

```
platform_events 5 · domain.order.paid 0 · deliveries 5 · audit-from-events 5
domain_orders 7 · paid orders 2 (ids 1–2 only, both simulated)
orders 3–7: status pending · paid_at NULL · stripe_session_id NULL · intent NULL
queued registration jobs: 0 · workspace_memory 41
```

---

## 13. Deployment-guard terminology correction

The scheduled-command guard is **runtime activation containment**, not deployment enforcement.

It verifies the chain when its fingerprint changes and refuses to **process** anything if verification fails. It does not prevent a broken change from being deployed, because there is no deployment mechanism on this host to intercept — no CI, no deploy script, in-place editing with uncommitted files. Phase 1D's report already stated this; this report restates it in the terms you specified.

**A real pipeline is proposed separately, not built here** (no CI, no git hook, no parallel system created). The required manual step remains:

```
php artisan platform-events:verify     # must exit 0 before leaving a chain change in place
```

---

## 14. Concurrency status

The other session remains active (Studio batches, `phpunit.e888.xml`, an Engineer888 subsystem under `app/Core/Engineer888/`). It was never modified or interrupted, and `levelup_test` was not touched by me during 1E.1 — it still has its 201 tables.

**Protected-path drift is `2/0/0`, and I did not seal it:**

| Path | Owner |
|---|---|
| `bootstrap/app.php` | **the other session** (12:24) — my schedule entries survive and the cron still fires |
| `routes/api.php` | **mine** — the Part B status classification |

`ProtectedPaths::seal()` seals every path at once, so sealing would silently bless their change as well as mine. Left drifting and reported instead.

---

## 15. Rollback position

Configuration-first, in order:

1. `PLATFORM_EVENTS_DOMAIN_ORDER_PAID=false` — already false.
2. Disable fixture setup / replay controls — none exist.
3. `DOMAINS_FULFILMENT_ENABLED=false` — already false.
4. Webhook failure evidence preserved (30-day durable records, separate keys, explicit acknowledgement only).
5. Existing event and audit rows preserved — 5/5/5 untouched.
6. Revert the P0 code change **only** if the corrected behaviour itself regresses.

**The false-200 behaviour will not be restored as a workaround.** It is the defect, not a mitigation.

---

## 16. Deviations

1. **Part E not built** — your brief permitted leaving the five orders unchanged if the write was not yet necessary. It was not.
2. **Part F designed only**, as instructed.
3. **Requirements 17–20** (setup-command tests) are covered as guard logic and design rather than command tests, because the command does not exist.
4. **Two Phase 1E tests inverted** — intended behaviour of the defect-documenting pattern.
5. **Origin lookup relocated** to `Outbox::originEnvelope()` — an architecture-test catch from Phase 1E's producer work, fixed rather than allow-listed.
6. **Drift left unsealed** (§14).

---

## 17. Unresolved decisions

1. **Order cancelled before payment**, and **a payment reference conflicting with another order**, are not yet distinguished from "unknown order". All three currently return 503 + incident. The first two arguably deserve permanent rejection plus an incident record rather than an indefinite retry.
2. **Webhook failure records live in Redis** (30-day TTL) — volatile, and no explicit acknowledgement command exists yet for webhook incidents (unlike `platform-events:process --ack-failures`). A durable incident table is the obvious next step if this becomes routine.
3. **`DOMAINS_FULFILMENT_ENABLED=false` is now the deployed state.** With the P0 fixed, a real customer payment would commit, record an event, and **not** be fulfilled. There are no real domain customers today (ws1 is internal; orders 1–2 were harness fixtures), so this is safe now — but it must be re-enabled before any customer-facing domain sale.
4. **The always-200 contract elsewhere in the webhook** — non-domain branches (subscriptions, invoices) still return 200 unconditionally. Only the domain branch is classified. Worth extending.
5. Shared `levelup_test` remains a hazard for any session that does not adopt an isolated database.
6. Carried forward: `OutboxDispatcher` retirement, `status` → `dispatcher_status`, cleanup of the five internal orders.

---

## 18. Readiness criteria for resuming Phase 1E

| # | Criterion | Status |
|---|---|---|
| 1 | Domain webhook branch resolves | ✅ done |
| 2 | Retryable failures return non-2xx | ✅ done |
| 3 | Durable idempotency proven | ✅ done (verified, no new mechanism needed) |
| 4 | Fulfilment containment exists and is disabled | ✅ done |
| 5 | Failure visibility in health | ✅ done |
| 6 | Isolated test database | ✅ done |
| 7 | **Test order has a `stripe_session_id`** | ❌ **blocked** — needs the Part E setup command and authorisation |
| 8 | **Fixture can pass signature verification in production** | ❌ **blocked** — needs the Part F narrow replay control, authorised |
| 9 | `domain.order.paid` producer enabled for ws1 | ❌ deliberately off, pending your authorisation |

**Two blockers remain before a signed production replay: 7 and 8.** Both are designed and both need explicit authorisation to build. Everything that made the replay *unsafe* — the P0, the silent 200, and unconditional registration dispatch — is now fixed.
