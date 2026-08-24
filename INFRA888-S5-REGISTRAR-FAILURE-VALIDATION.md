# INFRA888 — S5 REGISTRAR FAILURE VALIDATION

**Date:** 2026-08-02 · **Owner:** Hosting Claude (INFRA888) · **Verdict: PASSED — 2 defects found, 1 fixed, 1 reported**

---

## 1. Method

Every failure below was produced by a **real transport or a real Namecheap response**.
None were mocked at the `ProviderResult` layer. `NamecheapClient` takes its endpoint and
credentials as constructor arguments, so each scenario is a genuine HTTP attempt against a
controlled target — a local fault-injection endpoint bound to `127.0.0.1:8099`, an
unroutable address, or the real sandbox API with deliberately wrong credentials.

Environment: **sandbox** throughout. Only the designated validation domain was used. No
customer domain was named. No cron registered, no gate opened, no automatic renewal.

---

## 2. Failure matrix — observed, not assumed

| Scenario | success | errorCode | retryClass | isRetryable | normalizedState |
|---|---|---|---|---|---|
| connection refused | false | `TRANSPORT_ERROR` | permanent | false | **needs_reconciliation** |
| DNS resolution failure | false | `TRANSPORT_ERROR` | permanent | false | **needs_reconciliation** |
| network timeout (blackhole) | false | `TRANSPORT_ERROR` | permanent | false | **needs_reconciliation** |
| HTTP 500 (provider outage) | false | `HTTP_500` | permanent | false | **needs_reconciliation** |
| HTTP 503 (maintenance) | false | `HTTP_503` | permanent | false | **needs_reconciliation** |
| HTTP 403 (permission denied) | false | `HTTP_403` | permanent | false | failed |
| malformed body (not XML) | false | `UNKNOWN` | permanent | false | **needs_reconciliation** |
| invalid XML (truncated) | false | `MALFORMED_XML` | permanent | false | **needs_reconciliation** |
| empty body | false | `MALFORMED_XML` | permanent | false | **needs_reconciliation** |
| credentials missing | false | `CREDENTIALS_MISSING` | permanent | false | failed |
| authentication failure (real) | false | **`1011102`** | permanent | false | failed |
| unknown api user (real) | false | **`1011102`** | permanent | false | failed |
| domain not owned (real) | false | **`2019166`** | permanent | false | failed |
| invalid term, 0 years | false | `INVALID_TERM` | permanent | false | failed |
| **IP not whitelisted (real)** | **true** | — | — | — | **active** |

Real Namecheap error codes captured: **1011102** ("API Key is invalid or API access has
not been enabled") and **2019166** ("Domain name not available").

### The key insight

**Every ambiguous billable failure is classified `permanent` on purpose.** `failBillable()`
overrides the retry class for anything carrying `billable_risk` — *"Never auto-retry an
ambiguous billable failure"* — and signals it out-of-band through
`normalizedState = needs_reconciliation`.

So `isRetryable()` is **not** the classification signal for billable calls. Reading it
alone is a trap, and I had fallen into it (§5, D5).

**`insufficient funds` and `already renewed` were not reproducible.** The sandbox account
is pre-funded and cannot be drained, and Namecheap permits repeat renewals (each simply
adds a year). Both are honestly **UNVALIDATED**, not passed.

**`IP not whitelisted` did not fail.** The sandbox ignores `ClientIp` mismatch and the
renewal *succeeded*. In production this is enforced, so its behaviour is also
**UNVALIDATED**. This call renewed the validation domain a third time — see §9.

---

## 3. Classification matrix

| Class | Failures | Ledger state | Retry? | Who acts |
|---|---|---|---|---|
| **Ambiguous** (money may have moved) | TRANSPORT_ERROR, HTTP_500, HTTP_503, MALFORMED_XML, UNKNOWN | `needs_manual` | **never** | **Operator reconciles against the account** |
| **Permanent** | HTTP_403, CREDENTIALS_MISSING, 1011102, 2019166, INVALID_TERM | `abandoned` | no | Operator fixes config/eligibility |
| **Transient** | (none observed on billable calls) | `failed` | yes, backoff | Automatic |
| **Verification required** | any provider success | `verifying` | n/a | Read-back |

Observed behaviour, post-fix:

```
transport (conn refused)   AMBIGUOUS_BILLABLE   needs_manual   ambiguous    attempts=1  retry=-
provider 500               AMBIGUOUS_BILLABLE   needs_manual   ambiguous    attempts=1  retry=-
provider 503 maintenance   AMBIGUOUS_BILLABLE   needs_manual   ambiguous    attempts=1  retry=-
invalid XML                AMBIGUOUS_BILLABLE   needs_manual   ambiguous    attempts=1  retry=-
permission denied 403      PERMANENT_FAILURE    abandoned      permanent    attempts=1  retry=-
credentials missing        PERMANENT_FAILURE    abandoned      permanent    attempts=1  retry=-
auth failure (real)        PERMANENT_FAILURE    abandoned      permanent    attempts=1  retry=-
```

**No billable failure is ever retried automatically.** The backoff ladder (15 m / 2 h /
12 h / 24 h, then escalate) exists for genuinely transient classes and, on real Namecheap
behaviour, is currently **unreachable for renewals** — every observed billable failure is
either ambiguous or permanent. That is the correct outcome, and it means the retry policy
is presently theoretical for this operation.

---

## 4. Critical invariants — all held

| Invariant | Result |
|---|---|
| Any failure marked RENEWED | **NONE** |
| Any `expiry_observed` set on failure | **NONE** |
| Any `verified_at` set on failure | **NONE** |
| Estate expiry changed by a failure | **unchanged** |
| Ambiguous escalated, not abandoned | **4 of 4** |
| Any ambiguous ABANDONED | **NONE** |
| Idempotency keys distinct | 7 of 7 |
| Re-executing a failed renewal | `NOT_ACTIONABLE`, `attempt_count` stayed 1 |

**Audit** (transport failure):
```
due        -> attempting   {"attempt":1,"expiry_before":"2029-07-29"}
attempting -> needs_manual {"failure":"TRANSPORT_ERROR"}
```

**Customer:** `"Our team is reviewing this renewal"`, `action_required: true`,
`provider: "LevelUp Growth"` — no registrar identity, no error code.
**Admin:** `failure_code=TRANSPORT_ERROR`, `class=ambiguous`, full summary including
*"the charge may have succeeded. Reconcile against the account before retrying."*

**Monitoring:** 4 × `[CRITICAL] needs_manual` — one per ambiguous failure, naming the
domain and the failure code.

---

## 5. Defects discovered

**D5 — ambiguous billable failures were being ABANDONED. (FIXED)**
`DomainRenewalService` mapped `! isRetryable()` → `ABANDONED`. Because the connector
deliberately marks ambiguous outcomes `permanent`, a renewal that **may already have been
charged** was silently abandoned instead of escalated — the single most expensive possible
misreading: the money is gone *and* the domain still lapses. Now: `needs_reconciliation`
→ `NEEDS_MANUAL`, never retried, surfaced as CRITICAL.
Also reconciled the vocabulary split — the client emits `'transient'` while
`ProviderResult::isRetryable()` tests for `'retryable'`, so the service now accepts both.

**D6 — the estate can silently diverge from the registrar. (REPORTED, NOT FIXED)**
Proven with live evidence:
```
registrar : 07/29/2030
estate    : 2029-07-29
monitoring: "No findings"
```
`renewal_drift` only compares *our own ledger rows* to the estate. **A renewal that happens
outside our ledger is invisible** — registrar auto-renew, a staff renewal in the Namecheap
UI, or (as here) a call we didn't record. Detecting it requires the monitor to query the
registrar, which is new functionality; S5's rules forbid building it. **Recommended as the
first S6 item.**

**Platform-level inconsistency (not mine to fix):** the `'transient'` vs `'retryable'`
vocabulary split affects every caller of `ProviderResult::isRetryable()`, including
purchases and registrations. **Dependency: owned by Domain Commerce. Not modified.**

---

## 6. Files changed

**Modified (1):** `app/Engines/Infrastructure/Services/DomainRenewalService.php` — D5 only.

No new functionality. No migration, config, route or cron. No other workstream touched.
All S5 synthetic ledger rows were removed after validation (7 created, 7 deleted); the
ledger holds only the 2 genuine S4 validation renewals.

---

## 7. Regression

```
S3 orchestration suite : 30 passed  (72 assertions)
Wider suites           : 162 passed (709 assertions)
```
Isolated DB `levelup_p1e1_test`. Routes **1040** unchanged. `chefredraymundo.com` 200,
`amgtravelandtours.com` 200. Execution gate **CLOSED**, renewal cron **0**, environment
**sandbox**, production purchases **false**.

**Nothing already proven regressed.**

---

## 8. Remaining production risks

1. **`insufficient funds` never validated** — the sandbox cannot be drained. This is the
   most likely real-world production failure and its behaviour is unknown.
2. **`IP not whitelisted` never validated** — sandbox ignores it; production enforces it.
3. **`already renewed` never validated** — Namecheap simply adds another year, so a
   double-execution against a live domain would **double-charge and double-extend**, not
   error. Our duplicate prevention is therefore the *only* thing standing between a retry
   and a double charge.
4. **Estate↔registrar drift is undetected** (D6).
5. **No production credential exists**, so none of the above can be exercised today.
6. **Notifications still unwired**; `NEEDS_MANUAL` raises a CRITICAL monitor finding that
   nobody is paged for.

---

## 9. Honest side effect

The `IP not whitelisted` probe **succeeded** and renewed the validation domain a third
time, moving it to **2030-07-29** while the estate still records 2029-07-29. I left the
divergence in place rather than papering over it: it is the live evidence for D6. Only
`lvl-shop-eb6db479.com` is affected — no customer domain.

---

## 10. Production readiness score

**7/10** (was 4/10 after S4).

Earned: real failure classification, ambiguity never mistaken for success or for failure,
duplicate prevention proven under failure, audit complete, customer/admin separation
correct, monitoring escalates.

Withheld: the three unvalidated production-only failures (§8.1–8.3), undetected estate
drift, no production credential, and no alerting on CRITICAL.

---

## 11. Recommended S6

1. **Estate↔registrar drift detection** (D6) — the monitor must read the registrar, not
   just our own ledger.
2. **Reconciliation command** for `NEEDS_MANUAL`: read the registrar, decide whether the
   charge landed, resolve to `RENEWED` or `ABANDONED` with evidence.
3. **Alerting** on CRITICAL findings, with the notifications owner.
4. **Then** a funded production credential and one supervised production renewal.

**Still not recommended:** cron, automatic execution, bulk renewal, opening the gate.
