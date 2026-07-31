# BOSS888 — PHASE 0 CAPABILITY ENFORCEMENT REPORT

**Date:** 2026-07-29 · **Milestone:** Phase 0 + E1/E2 (enforcement and visibility only)
**Authorisation:** Mark, 2026-07-29 · **Status:** ✅ **PASSED**
**Runtime behaviour changed:** none

---

## 1. Objective and interpretation

Phase 0 registers the approved `domain.*` capability declarations and adds architecture tests that expose current violations, **without changing runtime behaviour**.

### A design decision that needs stating plainly

The declarations were **not** added to `CapabilityMapService`.

`CapabilityMapService::MAP` is consulted at runtime by `EngineExecutionService` and `TaskService::create()`. Adding `domain.*` entries there would make those actions resolvable — changing approval routing, credit reservation and dispatch behaviour. That is a runtime migration, which this milestone forbids.

Instead the declarations live in a **new, dedicated, currently-unconsulted registry**: `App\Core\Governance\DomainCapabilityRegistry`. It is fail-closed within its own scope (unknown key throws) and states honestly, per capability, which controls are `enforced` today and which are only `declared`.

Wiring this registry into `CapabilityMapService` is Phase 4 work and requires separate authorisation.

---

## 2. Files changed

**Four new files. Nothing modified. Nothing deleted.**

| File | Bytes | Purpose |
|---|---|---|
| `app/Core/Governance/DomainCapabilityRegistry.php` | 21,301 | 12 capability declarations, fail-closed lookup |
| `tests/Feature/Governance/DomainCapabilityRegistrationTest.php` | 11,957 | E1 |
| `tests/Feature/Governance/GovernedRouteEnforcementTest.php` | 11,311 | E2 |
| `tests/Feature/Governance/fixtures/domain_governance_allowlist.json` | 6,090 | dated exception register |

**Explicitly not touched:** `routes/api.php`, `CustomerDomainController`, `CapabilityMapService`, `StripeService`, `RegisterDomainJob`, any migration, any schema, any production data.

`routes/api.php` and `CustomerDomainController.php` both retain their 12:33 timestamps — before this milestone began.

---

## 3. Capability declarations added

All 12 approved keys, in the `domain` namespace (not `infrastructure.*`, per the approved decision):

| Capability | Risk | Reversibility | Cost | Approval | Agent may execute |
|---|---|---|---|---|---|
| `domain.search` | read | n/a | free | none | yes |
| `domain.price` | read | n/a | free | none | yes |
| `domain.list` | read | n/a | free | none | yes |
| `domain.view` | read | n/a | free | none | yes |
| `domain.transfer.status` | read | n/a | free | none | yes |
| `domain.sync` | low | reversible | free | none | yes |
| `domain.privacy.update` | low | reversible | free | none | no |
| `domain.autorenew.update` | medium | reversible | free | none | yes |
| `domain.nameservers.update` | **high** | reversible | free | confirm | **no** |
| `domain.register` | **irreversible** | irreversible | **billable** | review | **no** |
| `domain.renew` | high | irreversible | **billable** | review | **envelope only** |
| `domain.transfer.request` | **irreversible** | irreversible | **billable** | **sod** | **no** |

Each declaration carries 20 required fields including entitlement, idempotency strategy, emitted events, audit/notification/memory policy, timeout, retry policy and provider-verification requirement.

**Three declarations worth defending explicitly:**

- **`domain.nameservers.update` is HIGH risk despite being free and reversible.** Wrong nameservers take a customer's website and email offline immediately. Risk is measured in customer impact, not in money or undo-ability. Not agent-callable.
- **`domain.renew` is the only envelope-eligible billable capability.** Renewal preserves an asset the customer already owns; registration creates a new obligation. Registration and transfer are never envelope-eligible.
- **`domain.autorenew.update` and `domain.nameservers.update` require read-back.** The registrar returns `Status="OK"` for an auto-renew change it does not apply — observed in sandbox on 2026-07-29. An envelope is not an effect.

---

## 4. E1 — capability registration enforcement

**Result: 18 tests, 455 assertions, PASS.**

Design: assert completeness, vocabulary correctness, and the governance rules that must hold structurally rather than by convention.

| Assertion group | What it prevents |
|---|---|
| All approved keys declared; **no extras** | a capability appearing without approval |
| 20 required fields present | a declaration that omits its approval or idempotency position |
| `engine.action` idiom | namespace drift away from the platform convention |
| Reads ≠ writes | a read capability acquiring approval, cost or events |
| Billable ⇒ idempotency declared | a money-moving capability with no replay strategy |
| Billable ⇒ never auto-retry ambiguous | the exact mechanism of double-charging |
| Billable ⇒ always audit + notify | a silent purchase |
| **No agent executes irreversible+billable** | an agent buying a domain |
| Transfer ⇒ separation of duties | the standard domain-theft vector |
| Silent-failure writes ⇒ read-back required | a UI that reports a change the registrar ignored |
| Unknown key throws | AP-1 fail-closed |
| Enforcement position stated | a declaration claiming protection it does not have |

**`declared-but-not-enforced` controls: 29.** The test asserts this list is **non-empty** — if it ever reports zero, a declaration is lying about what Phase 0 actually protects.

Four controls are marked `enforced`, and only because existing tests and a real production incident prove them: five-layer idempotency, no-auto-retry on ambiguous failure, tenancy, and the ownership pre-check.

---

## 5. E2 — governed route enforcement

**Result: 8 tests, 218 assertions, PASS.**

Design: enumerate every live mutating route on the domain surface, and require each to resolve to a controller extending `BaseEngineController` **or** appear in a dated, owned allow-list.

**Measured position:**

```
mutating domain routes: 7 — governed: 0, allow-listed: 7, direct-provider violations: 3
```

The allow-list is hostile to neglect. The suite fails when:

| Failure condition | Test |
|---|---|
| a mutating route has no entry | `every_mutating_domain_route_is_governed_or_explicitly_excepted` |
| an entry lacks owner / removal milestone / expiry | `every_allowlist_entry_is_complete` |
| an entry's expiry date has passed | `no_exception_has_expired` |
| an entry names a capability that does not exist | `every_exception_names_a_capability_that_actually_exists` |
| a route is renamed, or its controller/method changes | `no_allowlist_entry_is_stale` |
| a route becomes governed but the exception remains | `no_allowlist_entry_is_stale` |
| a direct-provider file stops referencing a connector | `direct_provider_access_entries_still_describe_reality` |
| the `setAutoRenew` violation entry is deleted without fixing the code | `known_direct_provider_violation_remains_declared` |

That last test is the anti-grandfathering guard, per instruction.

---

## 6. Violation register

### 6.1 Route exceptions — 7, all expiring 2026-10-31, owner Mark

| Route | Controller@method | Capability | Risk |
|---|---|---|---|
| `POST api/domains/orders` | `CustomerDomainController@createOrder` | `domain.register` | high |
| `POST api/domains/orders/{orderId}/checkout` | `CustomerDomainController@checkout` | `domain.register` | high |
| `POST api/domains/{id}/auto-renew` | `CustomerDomainController@setAutoRenew` | `domain.autorenew.update` | medium |
| `POST api/domains/{id}/sync` | `CustomerDomainController@sync` | `domain.sync` | low |
| `POST api/admin/domains/items/{itemId}/retry` | `DomainAdminController@retry` | `domain.register` | high |
| `POST api/admin/domains/{id}/sync` | `DomainAdminController@sync` | `domain.sync` | low |
| `POST api/admin/domains/sync-all` | `DomainAdminController@syncAll` | `domain.sync` | low |

**Removal milestone for all seven:** Phase 4 — route purchase through TaskService.

### 6.2 Direct provider access — 3

| File | Symbol | Capability | Risk | Expires |
|---|---|---|---|---|
| `CustomerDomainController.php` | `setAutoRenew` | `domain.autorenew.update` | medium | **2026-10-31** |
| `Admin/RegistrarAdminController.php` | `status` | `domain.sync` | low | 2026-12-31 |
| `Admin/RegistrarAdminController.php` | `domains` | `domain.list` | low | 2026-12-31 |

**`CustomerDomainController::setAutoRenew()` is recorded as a VIOLATION, not accepted architecture.** A controller resolves the registrar connector directly, bypassing both the governed pipeline and the engine layer. Per instruction it stays visible, with an owner and a removal date, and a dedicated test fails if the entry is removed while the code still holds.

### 6.3 Unresolved

**29 declared-but-not-enforced controls.** Not defects — the honest position of a declaration milestone. They resolve across Phases 1–4: audit and events at Phase 1–2, approval and agent-execution gating at Phase 4.

---

## 7. Test evidence

| Suite | Baseline | After | Delta |
|---|---|---|---|
| Domains + Namecheap | 100 tests / 277 assertions | **100 / 277** | **unchanged** |
| Component (browser module) | 37 passed | **37 passed** | **unchanged** |
| Governance (existing) | 18 tests / 59 assertions | included below | — |
| Governance (total, incl. new) | — | **44 tests / 732 assertions** | **+26 tests / +673 assertions** |

**No existing test was weakened, skipped or modified** to obtain green. The domain and component suites are byte-identical in count and assertion totals to the pre-change baseline.

---

## 8. Concurrency check

A second session is active in this codebase.

| Their area | Last activity |
|---|---|
| `config/ai_provenance.php` | 13:16 (root) |
| `database/migrations/2026_07_29_130000_add_execution_provenance_to_api_usage_logs.php` | 13:16 (root) — **pending, untouched** |
| `tests/Feature/Ai/RuntimeUsageProvenanceTest.php` | 13:22 (root) |
| `app/Connectors/RuntimeClient.php` | 13:16 |

| My area | Overlap |
|---|---|
| `app/Core/Governance/` (new file only) | **none** — newest pre-existing file Jul 27 |
| `tests/Feature/Governance/` (new files only) | **none** — newest pre-existing file Jul 27 |

**No overlap.** Re-checked immediately before writing: zero root-owned changes in my target directories in the preceding 20 minutes, and all four target paths confirmed non-existent before creation. Nothing was reset, stashed, overwritten or amended. The other session's pending migration was not run.

---

## 9. Confirmation of no change

| Item | Baseline | After |
|---|---|---|
| `customer_domains` / `domain_orders` / `domain_order_items` | 2 / 2 / 2 | **2 / 2 / 2** |
| `approvals` / `tasks` / `audit_logs` / `infra_events` | 127 / 2450 / 7678 / 38 | **127 / 2450 / 7678 / 38** |
| `users.updated_at` (id 2) | 2026-07-29 13:22:08 | **unchanged — not restored** |
| Routes | 1040 | **1040** |
| Pending migrations | 1 (other session) | **1 — not run** |
| Protected-path drift | 0/0/0 | **0/0/0** |
| Sandbox balance / domains | USD 8,943.28 / 4 | **USD 8,943.28 / 4** |
| `chefred-fcf3e2.com` | present, auto-renew off | **unchanged** |
| Production purchases | false | **false** |
| `/api/health`, `/app`, `/ptaa/` | 200 | **200** |

**Zero provider calls were made by E1 or E2** — both operate on declarations, the route table and the filesystem. The sandbox balance is proof.

---

## 10. Recommendation for the next phase

**Recommend Phase 1 — platform event outbox, producers only.**

Rationale:

1. **Everything downstream subscribes to it.** Audit unification, notifications, memory and analytics are all subscribers. Building any of them first means building them twice.
2. **Cheapest it will ever be** — one table, one emitter, zero subscribers, and Domain Commerce is the only producer.
3. **It attacks the actual mechanism of the drift.** Domain Commerce called one of four cross-cutting services because each is a separate remembered call. An event makes participation the default rather than a discipline. Phase 0 makes violations *visible*; Phase 1 makes them *unlikely*.
4. **It is additive and reversible** — dual-write alongside `infra_events`, no behaviour change, rollback is "stop emitting."

**Not recommended next:** Phase 4 (route purchase through TaskService). It is the highest-risk phase, it re-plumbs a working money path, and it is far safer once events exist to observe it with.

**Prerequisite before Phase 1:** the ADR decisions in `BOSS888-PLATFORM-EVENTS-ADR.md` — outbox over Laravel events, typed events over a generic envelope, and a relay latency target.

---

## 11. Bottom line

Phase 0 **passed**. The platform can now detect, in CI, the class of violation that produced Domain Commerce — and the seven routes and three direct-provider calls that constitute it are recorded with owners and expiry dates rather than discovered in an architecture review six months from now.

Nothing about how a customer buys a domain changed today.
