# INFRA888 — E2: BUSINESS EMAIL PROVIDER CONTRACT & FAKE ADAPTER

**Date:** 2026-08-05 · **Milestone:** E2 of the E0–E10 roadmap
**Scope:** finalise the provider contract, add capability flags, build a deterministic fake, and prove every capability against it.
**Out of scope:** E3 and beyond. No vendor adapter, no customer or admin route, no scheduler, no job, no credential, no network call, no purchase, no PTAA change.

**Repository:** `/var/www/levelup-staging` · **Branch:** `feature/studio888-mrc-2a` · **HEAD at start:** `95130f5387dbd36547e7163fe958c37f422fb225`

---

## 1. E1 baseline

E1 delivered 6 migrations, 6 models, 43 lifecycle states across 6 machines, 15 registered capabilities, white-label guards, workspace tenancy, and reuse of `infra_operations` / `infra_observation_facts` / `infra_provider_resources` instead of parallel spines. It made no provider call, because it had no provider to call.

E1 also shipped an explicit debt: **five registered capabilities had no method on `EmailProviderConnector`.** They were declared as gaps rather than invented, and that list was E2's worklist. All five are closed. `BusinessEmailCapabilityRegistry::contractGaps()` now returns `[]`, and a test keeps it there.

Every E1 test still passes unchanged except two, both updated deliberately and documented in §18.

---

## 2. The five gaps and how each was resolved

| # | Gap | Capability | Lifecycle impact | Idempotency | Verification | Contract change or flag? | Resolution |
|---|---|---|---|---|---|---|---|
| 1 | **No restore.** Suspend existed; nothing reversed it. | `email.mailbox.restore` | `suspended → active` was declared evidence-required but unreachable — a one-way door. | caller key | read-back must show not-suspended | **Contract.** A flag cannot substitute for a missing method. | `restoreMailbox(ref, ctx)` |
| 2 | **No alias delete.** | `email.alias.delete` | `active → removing → removed` unreachable. | caller key | read-back must show absent | **Contract**, and a signature fix: it takes the ALIAS's own opaque reference. The old `createAlias(providerResourceId, …)` implied the mailbox's, which would make deletion impossible for any provider modelling an alias as a first-class object. | `deleteAlias(aliasRef, ctx)` |
| 3 | **No forwarder delete.** | `email.forwarder.delete` | as above | caller key | read-back absent | **Contract**, same signature correction. | `deleteForwarder(forwarderRef, ctx)` |
| 4 | **No catch-all at all.** | `email.catchall.configure` | whole `EmailCatchAllState` machine unreachable. | caller key | read-back shows target | **Both.** A method AND a capability flag — the masterplan records catch-all support as varying by provider, so the engine must be able to refuse before calling. | `configureCatchAll(domain, CatchAllSpec, ctx)` + `clearCatchAll(domain, ctx)` + `CATCHALL_CONFIGURE` / `CATCHALL_CLEAR` flags |
| 5 | **No usage read.** | `email.usage.sync` | none (measurement) | caller key — it writes `email_usage` rows | n/a | **Contract**, as a READ. | `getUsage(domain, ctx)`. Deliberately **no** `syncUsage()`: the provider is only ever read, and "sync" is what the engine does with the answer. Two methods would imply a measurement mutates the provider. |

### Three further defects the review surfaced

6. **`email.mailbox.update` was mapped to a method that could not perform it.** `setMailboxQuota()` cannot change a display name. Replaced by `updateMailbox(ref, MailboxSpec, ctx)`, where null fields mean "leave unchanged" and an empty string means "clear" — a distinction a quota-only method could not express.
7. **DNS requirements could not be asked for.** `getDomainAuthStatus()` reports whether records are *right*; nothing reported what they should *be*. Added `getDnsRequirements()`, returning `MailDnsRecord` objects. This is the load-bearing white-label decision in the whole contract — see §5.
8. **Nothing could enumerate.** Without a listing there is no reconciliation, and without reconciliation the platform trusts its own database indefinitely. Added `getInventory()`, and made `INVENTORY_LIST` a *required* capability: a provider that cannot be reconciled cannot deliver the product.

Also corrected: E1 recorded `email.domain.onboard` as `provider_mutation => false` because it mapped onboarding to a status read. Registering a domain at a provider is a mutation almost everywhere; it is now `true` with its own method.

---

## 3. Final provider contract

`app/Connectors/Infrastructure/Contracts/EmailProviderConnector.php` — 22 own methods, 27 including inherited.

**Reads:** `healthCheck` · `synchronize` · `verify` (inherited) · `getDomainAuthStatus` · `getDnsRequirements` · `getMailboxStatus` · `getCatchAll` · `getUsage` · `getInventory`

**Mutations:** `onboardDomain` · `verifyDomain` · `createMailbox` · `updateMailbox` · `suspendMailbox` · `restoreMailbox` · `deleteMailbox` · `requestPasswordReset` · `createAlias` · `deleteAlias` · `createForwarder` · `deleteForwarder` · `configureCatchAll` · `clearCatchAll`

**Local declarations:** `capabilitySet` · `supports`

Every method that talks to a provider returns `ProviderResult`; a test asserts it. Every mutation takes a `ProviderCallContext` carrying workspace, business-object identity, idempotency key, correlation id and operation id; a test asserts that too.

**The two deliberate exceptions.** `capabilitySet()` and `supports()` return a typed set and a bool. They make no network call and cannot fail. Wrapping them in a result type that models failure, retry classification and verification would be dishonest about what they are, and would make every "should this button exist?" question a potential outage.

### Rules every implementation must honour

- **Accepted is not verified.** `accepted()` when the provider acknowledged; `verified()` only when the effect was independently read back. An adapter returning `verified()` because an API returned 200 has broken the contract in the way that matters most.
- **Ambiguity is neither.** A timeout on a mutating call is reported as `failed()` with an ambiguous error code and `manual` retry classification — never success, never a retryable failure.
- **Never return a password.** `requestPasswordReset()` triggers the provider's own flow. No implementation may put credential material in `$data`, because that payload is persisted to `infra_operations`.
- **Opaque references.** No implementation may assume, and no caller may parse, the format of a `*Ref`.

---

## 4. Capability flags

`EmailProviderCapability` — 20 provider-neutral flags across domain, mailbox, routing and observation. `EmailProviderCapabilitySet` answers `supports()` locally and for free.

**Required** (a provider lacking any of these is not viable and must not be activated): `domain.onboard`, `domain.verify`, `mailbox.create`, `mailbox.delete`, `inventory.list`.
**Optional** (the product sells without them; the UI hides the action): the other 15, including `catchall.configure`, `mailbox.rename`, `last_login.read`, `domain.dkim.rotate`.

**The rule that makes flags worth having:** an unsupported capability is **not a provider failure**. It is a configuration fact known before any call. The engine refuses it *before opening a governed operation*, classifies it `permanent`, and never queues a retry for something that can never succeed. Proven: with `catchall.configure` withdrawn, the request returns `email_capability_not_supported`, the fake is never called, **zero operations exist**, and the catch-all stays `disabled`.

---

## 5. Typed neutral data objects

`ProviderResult`'s own docblock says nothing else gets a DTO without concrete justification. Twelve value objects were added; the justification for each class of them:

- **`ProviderCallContext`** — four facts that must accompany every mutating call on fourteen methods. As loose parameters they are transposable: swapping the idempotency key and the correlation id compiles, passes review, and silently destroys idempotency. The constructor refuses an empty key.
- **`MailboxSpec` / `AliasSpec` / `ForwarderSpec` / `CatchAllSpec`** — the provider-neutral inputs. An array crossing this seam is how a provider-specific key reaches the engine. `MailboxSpec` has nowhere to put a credential, and a test asserts no property name could hold one.
- **`MailDnsRecord`** — **the load-bearing one.** MX, SPF and DKIM values physically contain provider hostnames; that is the accepted, unavoidable limit (Option A). What is *not* unavoidable is provider **prose**. A provider returning "Add these records — see help.example-vendor.com" would put a vendor name on a LevelUp screen if the payload were a string. Splitting the record into typed fields means the engine writes the instruction in LevelUp's words and only the record value passes through. `purpose` is declared, not parsed: deciding "this TXT is the SPF one" by string-matching its content would restore exactly the coupling the object removes.
- **`RemoteMailbox` / `RemoteAlias` / `RemoteForwarder` / `RemoteCatchAll` / `ProviderInventory` / `MailboxUsageSample`** — *observed* state, deliberately separate types from *desired* state. Reconciliation diffs the two, and you cannot diff a thing against itself. None of them has a `toCustomerArray()`, and a guard asserts none ever gains one: there is no customer-safe view of a provider object.

Nullability is load-bearing throughout. `RemoteMailbox::$quotaMb` null means "the provider does not report it", never a mismatch. `RemoteCatchAll::$enabled` has three states — on, off, and *cannot tell* — because collapsing null into false would report drift about our own ignorance.

---

## 6. Fake adapter design

`tests/Fakes/Infrastructure/Email/FakeEmailProvider.php` — implements all 27 contract methods.

**Five structural safeguards against it reaching production,** each asserted by a guard test:
1. Lives under `Tests\Fakes\`, which is `autoload-dev` only — it does not exist in a production autoloader.
2. `provider()` returns `fake-email`, visible in every `infra_operations` and `infra_provider_resources` row it touches.
3. **Throws** if constructed when `app()->environment('production')`.
4. Never registered in `config/infrastructure.php`; injected per-test through the existing `InfrastructureConnectorResolver::fake()` seam. A fake reachable from configuration is one environment variable away from production.
5. No production file references it (asserted across `app/`, `config/`, `routes/`, `database/migrations/`).

**Twelve configurable outcomes:** verified · accepted · retryable failure · permanent failure · ambiguous · timeout · malformed response · unsupported capability (via the capability set) · duplicate idempotency request · stale read-back · successful read-back after accepted · partial inventory.

**Records:** every call with arguments, idempotency keys in order, per-method call counts, the configured outcome, and its own in-memory provider state.

**Reaches nothing.** No HTTP, DNS, SMTP, IMAP, filesystem, shell or queue — asserted by source inspection across seven pattern families. Fully deterministic: references are hashes of the idempotency key, so the same request always yields the same identifiers.

**The one behaviour that matters most:** an ambiguous outcome **still applies the mutation** to the fake's state. That is what makes ambiguity dangerous, and a fake that skipped the write would let a blind-retry bug pass its tests.

---

## 7–9. Flows

### Domain (E2-E)

```
connected --dnsRequirements--> verifying_dns --verifyDomain(evidence)--> dns_verified
          --confirm--> provisioning --read-back--> active
```

- `dnsRequirements()` persists neutral `MailDnsRecord` objects into `settings_json` and moves `unverified → pending_records`. **Issuing records is not evidence they exist**, and there is no edge from `pending_records` to `verified`.
- `verifyDomain` moves verification forward **only on a verified answer**. An `accepted` answer means the provider queued a check and says nothing about the records — proven: it lands in `verification_failed`, not `verified`.
- **Onboarding is two-phase, and a verified first phase is still not done.** The engine treats a verified `onboardDomain` as `accepted` and leaves the operation open, because a provider confirming registration says nothing about whether mail flows — the customer's DNS gate sits between. Closing it there would let a domain reach `active` without anyone checking its MX records. Proven: confirming before verification returns `email_domain_not_verified` and the domain does not go active.
- Observed call sequence: `getDnsRequirements → onboardDomain → verifyDomain → getDomainAuthStatus`.

### Mailbox (E2-F)

- **Verified create** → `provisioning → active`, provider reference bound, operation `succeeded`.
- **Accepted create** → stays `provisioning`, operation `running`, reference bound anyway (the object may exist; losing the reference would orphan it). Only `confirm()` permits `active`.
- **Ambiguous create** → subject `reconciling`, operation `timed_out → compensation_pending`, `manual` classification. Re-issuing calls the provider **zero additional times** (asserted: `callCount('createMailbox') === 1`). `confirm()` resolves it to `active` and closes the operation **`compensated`, not `succeeded`** — the record must show the outcome was recovered, not reported cleanly.
- **Malformed success** (200 with no reference) → treated as ambiguity, nothing bound, then recovered by enumeration at read-back.
- **Permanent** → `provisioning_failed`, operation `failed_terminal`. **Retryable** → subject stays in flight, operation `failed_retryable`.
- **Idempotency:** four identical executes → 1 provider call, 1 operation, 1 mailbox.
- **Suspend/restore** round-trip; **delete** passes through `deleting` and is refused on self-approval.
- **Password reset** persists `{redacted: true, outcome}` only; asserted free of password/secret/token/reset_url.

### Alias, forwarder, catch-all (E2-G)

Create/delete round-trips for aliases and forwarders; configure/clear for catch-all. An **unchecked** forwarder never reaches the provider (`unchecked` is a blocking loop verdict); a forwarder pointing back into an operated domain is refused. A provider without `catchall.configure` refuses before any operation exists.

---

## 10. Idempotency model

Every mutating capability integrates with `infra_operations`; **no `email_operations` table exists** and a test asserts it never will.

- The operation is created **before** the provider is called, so a crash mid-flight leaves a recoverable record.
- One operation per `(workspace_id, idempotency_key)`. A terminal operation replays its recorded outcome instead of acting again.
- An operation already `running` or `compensation_pending` returns `awaiting_confirmation` **without calling the provider** — this is the guard that stops a double mutation.
- The engine **refuses to invent** a key for a caller-key capability. An unstable key looks like protection and is not.
- Derived keys collapse repeat submissions of the same intent (proven for domain onboarding).
- A legitimate request that cannot proceed because no provider exists is **parked in `requested`**, deliberately non-terminal, so the same key resumes the work in E5 rather than replaying a stale refusal forever.

---

## 11. Accepted versus verified

| Provider answer | Operation | Subject | Customer may be told |
|---|---|---|---|
| `verified()` | succeeded | success state | it is done |
| `accepted()` | running | stays in flight | it is in progress |
| ambiguous | timed_out → compensation_pending | `reconciling` (existence in doubt only) | we are checking |
| retryable | failed_retryable | stays in flight | it is in progress |
| permanent | failed_terminal | failure state | it failed |

`confirm()` is the only route from unconfirmed to confirmed, and it reads provider truth. Every success transition in every state machine is declared evidence-required, and the engine is where that declaration is honoured.

---

## 12. Failure taxonomy

`ProviderOutcome` classifies into five: **VERIFIED, ACCEPTED, RETRYABLE, PERMANENT, AMBIGUOUS**.

Ambiguity is derived from fields `ProviderResult` already has — a known error code (`provider_timeout`, `provider_indeterminate`, `provider_malformed_response`) plus `manual` retry classification. **`ProviderResult` was not modified**, deliberately: it is shared with the hosting, registrar and certificate work packages, and adding an `ambiguous()` factory would change a primitive three other packages depend on for one package's benefit.

`ProviderOutcome::isAutoRetryable()` returns true for RETRYABLE only. Ambiguity is never auto-retried; that is the rule the whole design rests on.

Customer-facing wording lives in `BusinessEmailFailure` (14 codes) and `ReconciliationFinding` (13 kinds). Guards assert none contains a vendor name, URL, HTTP status, plan/price concept, internal concept (`provider`, `adapter`, `connector`, `api`, `binding`), or test terminology (`fake`, `stub`, `mock`, `simulated`).

---

## 13. Reconciliation model

`EmailReconciliationService` compares desired against observed and records facts into **`infra_observation_facts`** — dimension `email_provider`, provider `business_email` (what observed, never who), with the estate's existing custody and confidence semantics. No Business Email observation table was created.

**Three rules, all asserted:**
1. **It never mutates desired state.** Proven with every kind of drift present simultaneously: lifecycle, quota and domain state unchanged after a pass.
2. **It refuses to conclude from an incomplete read.** A partial inventory produces **no** missing/unexpected findings, because a truncated list makes every unlisted mailbox look deleted — and acting on that means recreating mailboxes that exist, or deleting ones that were on page two.
3. **Null is not a mismatch.** Only two known values may disagree.

All 13 finding kinds are implemented, severity-ranked by what a customer would feel (missing mailbox = critical; unexpected forwarder = high, because mail may be going somewhere unauthorised; stale usage = low). A provider that cannot enumerate is reported as *uncheckable*, not as clean. No notification is delivered — alert eligibility and delivery are S8.3 and S9.

---

## 14. Provider-resource bindings

All bindings use **`infra_provider_resources`**; no `email_*` table has a `_ref` column, and an E1 test still asserts it.

Bound on create for mailbox, alias, forwarder and catch-all. References are opaque; `requireRef()` throws rather than guessing when a binding is absent. Proven: rebinding a mailbox to a different provider object leaves its LevelUp primary key and lifecycle unchanged, and the reference never appears in a customer payload.

---

## 15. White-label enforcement

E1's 16 guards still run and still pass. E2 adds **17 more** covering the two new leak paths: a fake that could escape, and an execution path that now handles real provider references and errors.

**Controlled injection (repeat of the E1 discipline, against an E2-owned file):**

| Step | Result |
|---|---|
| Baseline `MailDnsRecord.php` | md5 `32bac988e62f0263ee426d9a1d9db0a5`, 5,007 bytes |
| Guard before | **16 passed (442 assertions)** |
| Inject one vendor name in a comment | md5 `242ebd07f1cf27a9474d40915ea2958f`, 5,054 bytes |
| Guard with violation | **1 failed, 15 passed — exit 1** |
| Restore | md5 `32bac988e62f0263ee426d9a1d9db0a5`, 5,007 bytes — **byte-for-byte identical** |
| Guard after | **16 passed — exit 0** |
| Repo-wide residue | **zero** |

---

## 16. Test matrix

| Suite | Tests | Assertions | Result |
|---|---:|---:|---|
| `BusinessEmailContractTest` (E2) | 38 | 427 | PASS |
| `BusinessEmailE2GuardTest` (E2) | 17 | 1,699 | PASS |
| `BusinessEmailProvisioningTest` (E2) | 29 | 173 | PASS |
| `BusinessEmailReconciliationTest` (E2) | 17 | 95 | PASS |
| `BusinessEmailWhiteLabelGuardTest` (E1) | 16 | 442 | PASS |
| `BusinessEmailCapabilityRegistryTest` (E1, updated) | 20 | 734 | PASS |
| `BusinessEmailLifecycleTest` (E1) | 20 | 726 | PASS |
| `BusinessEmailModelTest` (E1) | 32 | 105 | PASS |
| `BusinessEmailSchemaTest` (E1) | 23 | 1,368 | PASS |
| `BusinessEmailEngineTest` (E1) | 31 | 135 | PASS |
| `BusinessEmailMigrationReplayTest` (E1) | 6 | 62 | PASS |
| **Business Email total** | **249** | **5,966** | **PASS** |

These are single-execution totals from one run per file; nothing is counted twice.

All 22 required E2-L groups are covered: contract completeness (§16 row 1), capability support, fake behaviour, domain onboarding, DNS verification, mailbox/alias/forwarder/catch-all lifecycles, usage sync, idempotency, retry classification, ambiguous handling, reconciliation, bindings, tenancy, customer projections, admin diagnostics, guards, migration replay, E1 regression, INFRA888 regression.

---

## 17. Migration and regression evidence

**No migration was created or modified in E2.** The frozen 251-file migration manifest is byte-identical before and after. `BusinessEmailMigrationReplayTest` still proves clean install from zero, rollback and re-run, and idempotent re-run (6 tests, 62 assertions).

| | Passed | Failed | Skipped | Assertions |
|---|---:|---:|---:|---:|
| E1 close (baseline) | 633 | **2** | 1 | 5,165 |
| E2 close | 735 | **2** | 1 | 7,698 |
| Delta | **+102** | 0 | 0 | **+2,533** |

+102 is exactly the E2 test count (38+17+29+17) plus the one test added to the E1 registry suite. **No pre-existing test changed result.**

The 2 failures are the same pre-existing `RegistrationDriftTest` failures carried since before E1, caused by the Namecheap registrar connector another INFRA888 work package added on 2026-07-29. Untouched.

**Frozen-source check after the run:** every shared primitive unchanged except `EmailProviderConnector.php`, which is the one intended contract modification.

---

## 18. Files created and modified

**Created (22)**

`app/Connectors/Infrastructure/BusinessEmail/` — `EmailProviderCapability`, `EmailProviderCapabilitySet`, and `Values/` × 10 (`ProviderCallContext`, `MailDnsRecord`, `MailboxSpec`, `AliasSpec`, `ForwarderSpec`, `CatchAllSpec`, `RemoteMailbox`, `RemoteAlias`, `RemoteForwarder`, `RemoteCatchAll`, `MailboxUsageSample`, `ProviderInventory`)
`app/Engines/Infrastructure/Email/Support/` — `ProviderOutcome`, `EmailFlightPlan`
`app/Engines/Infrastructure/Email/Reconciliation/` — `ReconciliationFinding`, `ReconciliationReport`, `EmailReconciliationService`
`tests/Fakes/Infrastructure/Email/FakeEmailProvider.php`
`tests/Feature/Infrastructure/Email/` — `BusinessEmailContractTest`, `BusinessEmailProvisioningTest`, `BusinessEmailReconciliationTest`, `BusinessEmailE2GuardTest`
`phpunit.e2.xml`

**Modified (5)**

| File | Why |
|---|---|
| `app/Connectors/Infrastructure/Contracts/EmailProviderConnector.php` | The contract finalisation. **The only tracked, pre-existing file E2 changed.** Nothing implemented it, so the change broke nothing. |
| `app/Engines/Infrastructure/Email/BusinessEmailEngine.php` | Added `execute()`, `confirm()`, `dnsRequirements()`, dispatch, result interpretation, bindings, usage recording. E1's `authorize()` semantics are preserved — the E1 engine suite passes unchanged. |
| `app/Engines/Infrastructure/Email/Registry/BusinessEmailCapabilityRegistry.php` | Closed 5 gaps, added `email.catchall.clear`, added `provider_capability` to every entry, corrected `domain.onboard` to a mutation. |
| `tests/…/BusinessEmailCapabilityRegistryTest.php` | Its pins moved because the thing they pinned changed: 15 → 16 capabilities, and the gap list is now asserted **empty**. |
| `tests/…/BusinessEmailWhiteLabelGuardTest.php` | One guard relaxed: E1 forbade `ProviderResult::verified()` anywhere in the engine because E1 executed nothing. E2 has one legitimate producer — the read-back in `confirm*()`. `BusinessEmailEngine.php` is exempted there and covered instead by an E2 guard asserting the construction only appears in a confirmation context. The exemption is asserted non-vacuous. |

---

## 19. Parallel-session classification

| Workstream | Evidence | Interaction |
|---|---|---|
| **Engineer888** | Live `php artisan test -c phpunit.e888.xml` against `levelup_e888_test` during E2, plus production health curls | None. Different database, no shared file. |
| **Studio / Platform / Builder / CRM / Billing / Platform Events / Notification Engine** | untracked trees and prior commits | None touched. |
| **INFRA888 registrar (Namecheap)** | `config/infrastructure.php` modified 2026-07-29 | Same workstream lineage, different package. Causes the 2 pre-existing regression failures. Not touched, not fixed, not worked around. |

**Isolation:** dedicated database `levelup_infra_e2_9b4d17e6_test`, created fresh (did not previously exist), proven **0 tables and 0 connections** at claim time. `levelup_test`, `levelup_p1e1_test`, `levelup_infra_s84_998fd8c8_test`, `levelup_chat_test`, `levelup_e888_test` and E1's own `levelup_infra_e1_7c3f21a4_test` were all treated as owned by others.

Source and migration hashes were frozen before the first write and re-verified before it and after the final run. Only the intended contract file changed. **No file belonging to another session was modified; nothing was staged or committed.**

---

## 20. Production impact

**None.**

- 0 of the six `email_*` tables exist in `levelup_staging` (verified after the run). No migration ran against production.
- No customer route, no admin route, no admin page entry, no scheduler entry, no job binding, no service-provider registration — each asserted by a guard.
- No network call. No provider credential. No provider account. No purchase.
- No DNS mutation, no mailbox creation, no billing.
- **PTAA impact: none.** No PTAA record, mailbox, DNS record or credential was read or written.
- With nothing bound to the `email` capability, the engine still answers `email_provider_not_configured` to every mutating request exactly as it did at E1 close — proven by the unchanged E1 engine suite.

---

## 21. Known limitations

1. **Everything is proven against a fake.** That is the milestone's purpose, and it is also its ceiling. No real provider's pagination, rate limiting, error vocabulary or eventual consistency has been met. E5 will surface behaviours this fake does not model.
2. **No retry worker.** A `failed_retryable` operation is recorded with a `next_retry_at` and nothing acts on it. E3.
3. **No approval records.** Separation of duties is enforced against `EmailOperationContext::$approved` / `$approvedBy` supplied by the caller. Binding it to the platform's approval tables is E3.
4. **Entitlements are enforced but not resolved.** Still supplied as a map; wiring to `InfrastructureEntitlementService` is E3.
5. **`confirm()` must be called explicitly.** Nothing polls for accepted or ambiguous operations. Until E3 adds that, an accepted create stays `provisioning` indefinitely — honest, but not self-healing.
6. **Cross-entity local-part uniqueness is still engine-level only** (E1 §17.5, unchanged). A mailbox, alias and forwarder could each claim `sales@` on the same domain. The fake does not model a provider rejecting it.
7. **`moveSubjectVia()` follows at most one intermediate hop.** Sufficient for all six machines today; a future machine needing two would throw rather than mis-transition, which is the right failure but is a latent limit.
8. **Reconciliation is not scheduled and delivers no alerts** — deliberate; S9 owns delivery.
9. **The `email_*` table-name prefix is still shared with Marketing's campaign builder** (E1 §17.7, unchanged).
10. **The customer "Email Accounts" nav item is still live with no product behind it.** Unchanged since E1 and still the highest-priority open product decision.

---

## 22. E3 entry conditions

E3 is *admin provisioning: provider registry, credential vault, operations queue, failure/retry*.

1. **E2 is green** and the INFRA888 regression is no worse than baseline — both true (§17).
2. **`contractGaps()` is empty** and a test keeps it so. E3 must not reopen it.
3. **The two registries merge in E3, deliberately.** `BusinessEmailCapabilityRegistry` and `InfrastructureCapabilityRegistry` are asserted disjoint today because Business Email has no vendor adapter. Merging is an E3 exit criterion, not a side effect — and a guard currently fails if it happens early.
4. **Every `needsHuman()` state must get an admin screen.** `reconciling` and `provisioning_failed` on five machines, plus `compensation_pending` operations. S3's NEEDS_MANUAL lesson: a state requiring a human decision with no operator surface is a dead end.
5. **The retry worker must never act on an ambiguous operation.** `ProviderOutcome::isAutoRetryable()` already encodes this; E3 must consult it rather than reading `retry_classification` directly.
6. **The fake stays in `tests/`.** E3 builds admin surfaces against it. The first `app/Connectors/Infrastructure/Email/<Vendor>/` directory is E5, and a guard fails if it appears sooner.
7. **Still no provider credential required.** E3 builds the vault; filling it is E5.

---

## 23. Honest readiness score

**E2 as specified: 9 / 10.**

What earns it: all five gaps closed with the contract change justified case by case rather than by adding methods until the list emptied; three further defects found and fixed that were not on the worklist; the fake exercises the cases that actually break provisioning systems — ambiguity, malformed success, replication lag, partial enumeration — rather than only happy paths; and the tests found four real defects in my own code (§below). The white-label rule survived a second injection proof against an E2-owned file.

What costs the point: **`BusinessEmailProvisioningTest` has 29 tests but only 173 assertions.** Same criticism as E1's model suite, and I did not fix the habit. Several tests assert one outcome where they could assert the operation state, the subject state, the binding and the call count together — and the four defects below were each caught by one assertion that happened to exist, which means others may not be caught at all.

**Defects the tests found in E2's own code:**
1. **Domain onboarding closed its operation before the DNS gate**, letting a domain reach `active` without anyone checking its MX records. The most serious of the four.
2. **Domain-scoped capabilities were refused for having no subject** — `usage.sync` could never run.
3. **The engine established tenancy per query, not per request.** A lazily-loaded relationship fired outside the wrapped block and threw on exactly two capabilities; it would have thrown on more as relationships were added.
4. **The fake ignored configured outcomes on reads**, so "unreachable provider" could not be tested at all — the reconciliation rule most likely to matter in production was passing vacuously.

**Business Email as a product: 2 / 10** (up from 1). The engine now genuinely provisions, confirms, reconciles and refuses correctly — against a fake. No customer can do anything. Eight milestones remain, and the two that make it a product (E4 customer portal, E5 first adapter) have not started.

**Confidence the engine is provider-agnostic: high.** That is what E2 exists to establish, and it is established by construction rather than assertion: every capability was exercised through the contract with no vendor in the codebase, and a guard fails if one appears outside the adapter path.

**Confidence it will survive a real provider: medium.** The abstraction is sound and the ambiguity discipline is right. What no fake can tell me is whether real providers' error vocabularies map cleanly onto five outcomes, and whether their eventual consistency is measured in seconds or minutes. E5 answers that, and I would expect the failure taxonomy to need one more category, not zero.

**What I did NOT verify:** nothing was exercised through HTTP, a queue worker or a browser, because E2 registers no route, job or schedule. Correct for E2; would not be for E3.

---

## 24. Summary

| Question | Answer |
|---|---|
| Did E2 pass? | **Yes** — 249 Business Email tests, 5,966 assertions, all green |
| Contract gaps closed | **5 of 5**; `contractGaps()` returns `[]` |
| Contract methods finalised | 22 own, 27 including inherited |
| Capability flags | 20 (5 required, 15 optional) |
| Fake scenarios | 12 outcomes, full call recording, zero external reach |
| Domain lifecycle | connected → verifying_dns → dns_verified → provisioning → active, proven; two-phase gate enforced |
| Mailbox lifecycle | create/suspend/restore/delete/update proven; accepted ≠ verified; ambiguous → reconciling → compensated |
| Reconciliation | 13 finding kinds; never mutates; refuses to conclude from a partial read |
| Idempotency | 4 identical executes → 1 provider call, 1 operation, 1 mailbox |
| Ambiguous outcomes | never auto-retried; provider called exactly once; recovered by read-back |
| Architecture guards | E1 16/442 + E2 17/1,699, all pass |
| Controlled injection | **Proven** — failed on injection (exit 1), restored byte-for-byte, passed again, zero residue |
| Canonical totals | **249 tests / 5,966 assertions** (single execution per file, no double counting) |
| Files created / modified | **22 / 5** (one tracked pre-existing file: the contract) |
| Migrations | **none created or modified**; frozen manifest byte-identical |
| Routes | **none** |
| Network calls | **none** |
| Production impact | **none** — 0 email tables in `levelup_staging` |
| PTAA impact | **none** |
| Parallel-session conflicts | **none** |
| Readiness | E2 scope **9/10**; product **2/10** |
| Document | `C:\Users\markr\LVL\INFRA888-E2-BUSINESS-EMAIL-PROVIDER-CONTRACT.md` |
| May E3 begin? | **Yes**, subject to §22 |
