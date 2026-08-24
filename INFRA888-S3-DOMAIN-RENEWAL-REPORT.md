# INFRA888 — S3 DOMAIN RENEWAL ORCHESTRATION

**Date:** 2026-08-02 · **Owner:** Hosting Claude (INFRA888) · **Status: DELIVERED — execution gate CLOSED pending authorisation**

---

## 1. Existing architecture — what was actually there

Audited before designing. Everything below was proven, not assumed.

| Component | Verdict | Evidence |
|---|---|---|
| `NamecheapRegistrarConnector::renewDomain()` | **REAL and good** | Captures `expiry_before` *before* calling — already designed for retry-safety |
| `renewDomain` / `setAutoRenew` / `quoteRenewal` on the contract | **REAL** | `DomainRegistrarConnector` lines 33, 35 |
| `ProviderResult` verified/accepted/failed + `isRetryable()` | **REAL** | Retry classification vocabulary is the literal string `'retryable'` |
| `RenewalState` state machine | **REAL** | 8 states, transitions, terminal/actionable sets |
| `InfraRenewal` model + `infra_renewals` table (23 columns) | **REAL but INAPPLICABLE** | Keyed to `subscription_id` → `infra_subscriptions`, **which has 0 rows** |
| Renewal scheduling | **MISSING** | No entry in 37 scheduled tasks |
| Renewal execution orchestration | **MISSING** | No command, no service, no caller of `renewDomain()` anywhere |
| Renewal verification / read-back | **MISSING** | — |
| Retry policy / dunning | **MISSING** | — |
| Renewal monitoring | **MISSING** | — |
| Renewal notifications | **MISSING** | No renewal type in `NotificationTypes` |
| Renewal tests | **MISSING** | Zero |

### The decisive structural finding

`infra_renewals` models the renewal of a **hosting subscription**. Real registered
domains live in **`customer_domains`** (2 live rows, expiring 2027-07-29) and have no
subscription row at all. **The existing renewal machinery is structurally incapable of
renewing a domain** — not incomplete, inapplicable.

So the adapter was real, the state vocabulary was real, and everything between them was
absent. S3 built that middle.

**Nothing existing was "fake"** — but `infra_renewals` reads as renewal coverage on a
schema diagram while being unable to renew anything a customer owns.

---

## 2. New orchestration

New ledger `infra_domain_renewals` (32 columns), keyed to `customer_domains` — the thing
actually being renewed. `infra_renewals` was left untouched: it belongs to the
subscription concern, owned elsewhere.

A renewal is **an event with history**, not a date field, so "did we already renew this?"
stays answerable after a crash, a timeout, or an ambiguous reply.

### The governing constraint

**Renewing a domain spends money and cannot be undone.** Every design choice follows:

| Risk | Mitigation |
|---|---|
| Double charge | One open renewal per domain, enforced in a `lockForUpdate` transaction + **DB-unique `idempotency_key`** |
| Charged but not recorded | `expiry_before` captured immediately before spending; a retry can detect a prior success |
| Provider lies / is optimistic | **Provider OK is never success.** RENEWED requires reading the registrar's expiry back |
| Timeout | Neither success nor failure → `VERIFYING` |
| Silent expiry | Monitoring escalates rather than retrying blindly |

---

## 3. State machine

`DomainRenewalState` — modelled on the existing `RenewalState` vocabulary so operators
read one language, but a separate machine because domain renewal has a failure mode
subscriptions do not: **the provider may charge us and then fail to tell us.**

```
SCHEDULED ──► DUE ──► ATTEMPTING ──┬──► VERIFYING ──┬──► RENEWED        (terminal)
                                   │                ├──► FAILED
                                   │                └──► NEEDS_MANUAL
                                   ├──► FAILED ──► DUNNING ──► ATTEMPTING
                                   ├──► NEEDS_MANUAL
                                   └──► ABANDONED   (permanent rejection only)
```

**`ATTEMPTING` cannot reach `RENEWED` directly.** Success is established by evidence, not
by the provider's say-so. The only paths out of an attempt are VERIFYING, FAILED,
NEEDS_MANUAL, or — for a permanent rejection where no money moved — ABANDONED.

`NEEDS_MANUAL` is an operational state, not an error bucket: automation has correctly
refused to guess and a human must decide.

---

## 4. Retry model

| Attempt | Backoff |
|---|---|
| 1 | 15 min |
| 2 | 2 h |
| 3 | 12 h |
| 4 | 24 h |
| >4 | → `NEEDS_MANUAL` |

Verification read-backs: up to 6, then escalate. **A renewal in `ATTEMPTING` or
`VERIFYING` is refused re-execution outright** — money may already have moved.

---

## 5. Failure model

| Class | Meaning | Destination |
|---|---|---|
| `permanent` | Registrar refused outright; no charge | `ABANDONED` |
| `transient` (provider `retryable`) | Try again later | `FAILED` → backoff → `DUNNING` |
| **`ambiguous`** | **Timeout/exception on a billable call** | **`VERIFYING`** — never failure, never success |

Ambiguity codes: `TRANSPORT_AMBIGUOUS`, `VERIFY_UNREADABLE`, `EXPIRY_UNCHANGED`.

---

## 6. Monitoring

`infra:domain-renewals monitor` — six checks, each answering a question an operator would
actually ask: `upcoming_expiry_unplanned`, `overdue_renewal`, `missing_expiry`,
`verification_stuck` (≥2 h in flight = unresolved billable outcome), `needs_manual`,
`retry_due`, `renewal_drift`, `stale_verification` (>7 days unobserved).

**Stale observation reports as unknown, never as healthy.** Exit code is non-zero on any
critical finding, so it can gate a scheduled task.

---

## 7. Customer / Admin visibility

**Customer** sees plain-language status, renewal date, and whether action is needed.
`provider` is reported as **LevelUp Growth**. Proven by test: the payload contains no
`namecheap`, no order id, no transaction id, no idempotency key.

**Admin** retains everything: provider identity and identifiers, state and previous
state, attempts, verify attempts, next attempt, expiry before/observed, failure code and
class, amount, idempotency key, and the full transition history.

---

## 8. Files changed

**New (4):**
- `database/migrations/2026_08_02_120000_create_infra_domain_renewals_table.php`
- `app/Engines/Infrastructure/States/DomainRenewalState.php`
- `app/Engines/Infrastructure/Services/DomainRenewalService.php`
- `app/Console/Commands/DomainRenewalsCommand.php`
- `tests/Feature/Infrastructure/DomainRenewalOrchestrationTest.php`

**Modified:** none. **`infra_renewals`, `customer_domains`, `config/infrastructure.php` and
`routes/*` were not touched.** Routes 1040 → 1040. The other session's pending migration
(`add_execution_provenance_to_api_usage_logs`) remains Pending, unexecuted.

---

## 9. Tests

`phpunit.p1e1.xml` → `levelup_p1e1_test` (isolated; `levelup_test` never used).

```
S3 orchestration : 30 passed (72 assertions)
Regression       : 180 passed (739 assertions)
```

Proven: eligibility windowing · no-expiry rejection · idempotent scheduling (3 runs → 1
row) · dry-run writes nothing · **provider success ≠ RENEWED** · **timeout = ambiguous,
not failure** · in-flight refuses re-execution · RENEWED only when expiry moves ·
unmoved expiry escalates after 6 read-backs · permanent → ABANDONED · transient →
FAILED with backoff · execution gate blocks by default · customer payload leaks no
provider identity · admin retains provider detail · monitoring detects overdue + stale ·
workspace scoping honoured.

### Two real bugs the tests caught

1. **`execute()` collided with Symfony's `Command::execute()`** — a fatal that broke the
   entire artisan CLI, including the 5-minutely `platform-events:process` cron. HTTP was
   unaffected (200s throughout). Caught and fixed within one run.
2. **The service read `$result->errorMessage`, which does not exist** — the property is
   `errorSummary`. Every failure summary would have been silently empty. Found only
   because a test asserted on failure classification.

A third finding was the state machine correctly refusing `attempting → abandoned`; the
machine was too narrow and was widened deliberately, for permanent rejections only.

---

## 10. Production readiness

| Gate | State |
|---|---|
| `infrastructure.renewals.execution_enabled` | **CLOSED** (absent ⇒ false) |
| Bulk execution | **Impossible** — `--id` required, one at a time |
| Confirmation token | Required for `schedule` and `execute` |
| Namecheap production purchases | Separately gated by the connector |
| Scheduled automation | **Not registered** — deliberately |

Read-only paths (`eligible`, `schedule --dry-run`, `monitor`, `status`) work with no
registrar configured at all: an operator must be able to see what is expiring even when
the provider is unreachable.

---

## 11. Honest limitations

1. **No renewal has ever been executed against a real registrar.** Every money path is
   proven against a controllable fake. The first live renewal will be the first real
   exercise, and it must be one domain, watched.
2. **No customer notifications are wired.** `reminder_sent_at` exists; nothing writes it.
   The 30-day reminder in the journey is modelled but not delivered — sending email
   crosses into a workstream I do not own.
3. **Not scheduled.** No cron entry was added; automation of a billable action needs
   explicit authorisation.
4. **Payment is not integrated.** Renewals assume the registrar account is funded. There
   is no charge-the-customer step.
5. **`auto_renew` is recorded but not enforced** — it sets mode, nothing more.
6. **The two live domains expire 2027-07-29**, so the eligibility path returns empty on
   real data today. Windowing is proven by test, not by production data.
7. **Verification depends on `getDomainStatus` returning `expires_at`.** Proven against
   the fake; the real Namecheap field mapping is untested end-to-end.

---

## 12. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| Double renewal | High | Unique idempotency key + locked open-renewal check + in-flight refusal |
| Charged without record | High | `expiry_before` + read-back + `NEEDS_MANUAL` escalation |
| Silent expiry → customer site dies | High | `overdue_renewal` critical + `upcoming_expiry_unplanned` |
| Registrar field mapping wrong | Medium | First live renewal must be manually watched |
| Unfunded registrar account | Medium | Surfaces as a permanent failure; not detectable in advance |

---

## 13. Recommended S4

**S4 — Renewal Activation & Notification Delivery**, in this order:

1. Authorise one supervised live renewal on a low-value internal domain
   (`lvl-shop-eb6db479.com`), watched end to end, verifying real Namecheap field mapping.
2. Wire the 30-day reminder and outcome notifications (needs the notifications owner).
3. Register the scheduled tasks — `schedule`, `due`, `verify`, `monitor` — with `execute`
   deliberately left manual until step 1 has run several times cleanly.
4. Then S5 Digital Estate, which can consume renewal state as a health dimension.

**Do not open the execution gate and register automation in the same change.**

---

## 14. Human action required

1. **Authorise (or defer) the first supervised live renewal.**
2. **Decide whether `execution_enabled` should be added to `config/infrastructure.php`.**
   I did not add it — that file is shared and the absent-⇒-false default is the safer
   state. It currently cannot be turned on without an explicit config edit, which is
   intentional.
