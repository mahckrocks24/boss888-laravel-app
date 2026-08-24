# INFRA888 — S4 LIVE RENEWAL VALIDATION

**Date:** 2026-08-02 · **Owner:** Hosting Claude (INFRA888) · **Verdict: PASSED — with 4 real defects found and fixed**

---

## 1. Objective

Validate the S3 renewal orchestration against real Namecheap capability with the smallest
possible blast radius. Not a feature milestone — an engineering validation milestone.

**Headline: the orchestration was broken in four ways that no unit test could have caught,
because all four only appear when you talk to a real registrar.** Two live sandbox
renewals now prove the full chain end to end.

---

## 2. Validation scope

| | |
|---|---|
| Domain | **`lvl-shop-eb6db479.com`** — designated internal validation domain, only |
| Environment | **sandbox** |
| Renewals executed | **2** (both supervised, one at a time) |
| Customer domains touched | **none** |
| Chef Red / AMG / PTAA / LevelUp Growth | **untouched, verified 200 after** |
| Money spent | **none** — see §3 |
| Cron registered | **none** |
| Bulk execution | **never possible** |

---

## 3. Preconditions

```
NAMECHEAP_ENVIRONMENT                  = sandbox
NAMECHEAP_PRODUCTION_PURCHASES_ENABLED = false
NAMECHEAP_API_USER (production)        = NOT SET
NAMECHEAP_CLIENT_IP                    = 134.209.93.41   (matches actual outbound IP)
infrastructure.renewals.execution_enabled = false (CLOSED)
```

`assertPurchaseAllowed()` returns early in sandbox — *"sandbox spends nothing"* — and no
production credential exists. **No real money could move, and none did.** The `1828`
minor-units recorded on renewal #1 is a sandbox figure.

Execution used the explicit `force` argument for the supervised run. **The config gate was
never opened, so nothing had to be closed again afterwards** — it has been CLOSED throughout.

---

## 4. Registrar evidence

**Read-only probe (no billable call):**

```
healthCheck()        success=true state=active
getDomainStatus(lvl-shop-eb6db479.com)
  owned=true  found=true  managed_by_us=true  status=Ok
  expires_at = '07/29/2027'          <- Namecheap returns US format MM/DD/YYYY
```

**Field mapping validated.** `observedExpiry()` parsed `'07/29/2027'` → `2027-07-29 00:00:00`
correctly. Note the renew *response* uses a different shape again —
`"7/29/2028 12:00:35 PM"` — which is precisely why the design treats it as a claim and
reads truth back through `getDomainStatus`.

**Renewal #1:** order `3639075`, txn `26149127` → expiry `07/29/2027` → **`07/29/2028`**
**Renewal #2:** order `3639076` → expiry `07/29/2028` → **`07/29/2029`**

---

## 5. Verification evidence

| Claim | Evidence |
|---|---|
| Request leaves correctly | Order + transaction ids returned both times |
| Provider acknowledges | `outcome: ACCEPTED` |
| **Provider OK ≠ renewed** | Ledger state after execute = **`verifying`**, never `renewed` |
| **Expiry actually changes** | Registrar read back: 2027→2028, then 2028→2029 |
| Verification promotes correctly | `RENEWED — Verified: expiry moved 2028-07-29 -> 2029-07-29` |
| **Duplicate execution impossible** | Second `execute()` → `IN_FLIGHT`, `attempt_count` stayed 1 |
| Idempotency | 2 keys, 2 distinct, DB-unique index |
| Ambiguous never becomes renewed | Only path to RENEWED is read-back; enforced by state machine |

---

## 6. Estate, ledger, audit, monitoring

**Estate:** `customer_domains.expires_at` 2027-07-29 → 2028-07-29 → **2029-07-29**,
`last_synced_at` stamped. Updated **only after** proof, never on provider claim.

**Ledger:** both renewals `renewed`, attempts 1/4, verify 1, expiry before/observed recorded.

**Audit — complete chain after the fix:**
```
12:17:16  due        -> attempting  {"attempt":1,"expiry_before":"2028-07-29"}
12:17:19  attempting -> verifying   {"provider_claim":"renewal reported successful", ...}
12:17:19  verifying  -> renewed     {"evidence":"registrar expiry read back and confirmed moved", ...}
transitions recorded: 3
```

**Monitoring:** 0 findings, 0 critical (after the drift fix — see D4).

**Customer view:** `{"status":"Renewed","expires_on":"2029-07-29","provider":"LevelUp Growth"}`
— **leaks: NONE** (no `namecheap`, no order id, no txn id, no idempotency key).
**Admin view:** retains `provider=namecheap`, order `3639076`, txn, key, full history.

---

## 7. Honest defects discovered

All four were in **my own S3 code**, and all four were invisible to the 30-test suite
because that suite used a fake connector.

**D1 — the service could never have reached the registrar.**
`registrar()` used `app($class)`. `NamecheapClient` takes six constructor scalars and
nothing binds them, so the container throws `BindingResolutionException`. Every working
caller in the codebase uses the static `::make()` factory; `StripeService` line 255 already
documents this exact trap. **S3's renewal service would have failed on its first real call.**
*Fixed: prefer `::make()` when the connector exposes it.*

**D2 — the audit trail had holes at the two moments that matter most.**
`due → attempting` and `verifying → renewed` were direct DB writes that bypassed
`transition()`, so neither appeared in history. The single most important event in the
lifecycle — the one proving we verified before claiming success — was invisible.
*Fixed: both routed through `transition()`.*

**D3 — `scheduled → attempting` was never a legal transition.**
`actionable()` includes `SCHEDULED`, but the machine only allows `SCHEDULED → DUE →
ATTEMPTING`. The direct write in D2 had been silently bypassing the state machine, hiding
the contradiction. Fixing D2 immediately exposed it as 16 test errors.
*Fixed: execute() steps through `DUE`, preserving both the invariant and the audit trail.*

**D4 — monitoring reported drift that was just history.**
The drift check compared *every* `renewed` row against the estate, so once a second renewal
completed, the first correctly-superseded record looked like drift. Surfaced only by
running two renewals back to back.
*Fixed: compare the most recent renewal per domain.*

**A fifth, not fixed — reported only:** `InfrastructureConnectorResolver::resolve()` uses
the same `app($class)` path as D1 and **will throw for the registrar capability**. It is
INFRA888's file, but it is shared across all capabilities and changing it is outside S4's
validation remit. **Dependency: recommended fix, not applied.**

---

## 8. Regression testing

```
S3 orchestration suite : 30 passed  (72 assertions)
Wider suites           : 162 passed (709 assertions)
   [CustomDomain | DomainPortal | DomainCommerce | SubdomainService | StateMachine]
```
Isolated database `levelup_p1e1_test`. `levelup_test` never used.

**Live system:** routes **1040** (unchanged), `/api/health` 200, `/admin/dashboard` 200,
`chefredraymundo.com` 200, `amgtravelandtours.com` 200.

---

## 9. Files changed

**Modified (1):** `app/Engines/Infrastructure/Services/DomainRenewalService.php` — D1–D4 only.

**Not touched:** every other file. No migration. No config change. No route change. No cron.
No other session's work. `config/infrastructure.php` deliberately still has no
`renewals.execution_enabled` key, so the gate cannot be opened without a conscious edit.

---

## 10. Production readiness

**Score: 7/10 for sandbox-proven correctness; 4/10 for production readiness.**

Proven: request, response mapping, field mapping, expiry verification, estate update,
ledger, audit, idempotency, duplicate prevention, monitoring, customer/admin separation.

Not proven in production: retry classification and failure classification were exercised
only against the fake — **the sandbox never returned a failure**, so real Namecheap error
codes have never been mapped. Ambiguous/timeout handling is likewise fake-only.

---

## 11. Remaining blockers

1. **No production credential exists** (`NAMECHEAP_API_USER` unset). A production renewal
   is impossible today — correctly.
2. **Real failure modes unexercised.** No live rate-limit, insufficient-funds, or timeout
   has ever been seen.
3. **`InfrastructureConnectorResolver` still cannot resolve the registrar** (D1's sibling).
4. **Notifications still unwired**; `reminder_sent_at` is never written.
5. **The validation domain now expires 2029-07-29** — two years further out, harmless, but
   worth knowing it was moved by this exercise.

---

## 12. Recommendation for S5

**Do not open the production gate next.** The highest-value remaining work is failure-path
validation, which is still entirely theoretical.

1. **S5a — Failure-path validation (sandbox):** force real error responses (invalid term,
   unowned domain, bad credential) and confirm classification, backoff and dunning against
   genuine Namecheap error codes.
2. **S5b — Fix the resolver** so `resolve('registrar')` works, under its own change.
3. **S5c — Notifications**, with the owning workstream.
4. **Only then** a single supervised *production* renewal, with a funded account, watched.

**Not recommended yet:** cron, automatic execution, bulk renewal, opening the gate.
