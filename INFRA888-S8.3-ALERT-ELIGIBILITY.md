# INFRA888 — S8.3 ALERT ELIGIBILITY & DELIVERY POLICY

**Date:** 2026-08-02 · **Owner:** Hosting Claude (INFRA888) · **Status: DELIVERED — evaluation only**

---

## 1. Eligibility architecture

This phase answers **"is this alert allowed to leave INFRA888?"** — not how it is
delivered. It evaluates and records; it delivers nothing, schedules nothing, mutates
nothing, and never changes alert state.

```
Alert (S7/S8.1)
   ↓
AlertEligibility::evaluate()      10 composable rules, first non-eligible wins
   ↓
Verdict + full rule trail
   ↓
infra_alert_eligibility_decisions  append-only audit
   ↓
S9 delivery (not built)
```

Five verdicts: `eligible` · `ineligible` · `suppressed` · `deferred` · `manual_review`.

The distinction between them is deliberate. **`deferred` is not `ineligible`** — a
medium-severity alert still exists and is still visible, it simply does not interrupt a
human. Collapsing those two would make "we decided not to page you" indistinguishable from
"we decided this doesn't matter".

---

## 2. Rule engine

Rules run in a fixed pipeline. Order encodes precedence, and **safety rules run first** so
nothing about a sandbox asset can be overridden by a high severity later in the chain.

| # | Rule | Fails to |
|---|---|---|
| 1 | `alert_state` | suppressed — recovered/acknowledged/suppressed means a human already knows |
| 2 | `asset_retired` | ineligible — released/expired/suspended is not an incident |
| 3 | `asset_ownership` | **manual_review** — unknown custody must be classified before anyone is told |
| 4 | `internal_asset` | ineligible — our own scaffolding |
| 5 | `sandbox_asset` | ineligible — non-production registrar environment |
| 6 | `observation_confidence` | deferred — never page a human on a guess |
| 7 | `maintenance_window` | suppressed — planned work is not an incident |
| 8 | `duplicate_cooldown` | deferred — 240 min between repeats of the same alert |
| 9 | `severity_threshold` | deferred — below critical/high is digest, not interrupt |
| 10 | `customer_visibility` | **fails open** — operator channel even without customer wording |

### The two failure directions

Paging someone about a sandbox artefact teaches them to ignore the channel. Silently
withholding a real customer outage is worse than any amount of noise. So each rule states
which way it fails, and the last rule **fails open**: if there is no customer-safe wording,
the alert still goes to the operator channel. Withholding a real outage because we lack a
polite sentence would be the worse error.

`observation_confidence` carries the same asymmetry: `inferred` evidence (WHOIS) is deferred
below critical, but **allowed through at critical**, because a possibly-expired customer
domain is worth a conversation even on imperfect data.

---

## 3. Customer safety — no hard-coded hostnames

The brief required internal, sandbox, rehearsal and retired assets to never qualify
**without hostname exceptions**. A name list stops protecting you the moment someone
registers a new test domain.

Identification is therefore **behavioural**:

> **Internal asset** = registered by us **AND** serves no website **AND** has never once
> resolved in DNS.

An asset that never carried traffic is scaffolding, whatever it is called. A real customer
domain resolves at some point in its life.

**Sandbox asset** = registrar-managed while the registrar connector is in a non-production
environment, or carrying explicit sandbox metadata. Derived from the provider environment,
not the name.

**Retired asset** = `customer_domains.status` in released/expired/suspended.

**Live proof** — this closes the sandbox-noise problem carried since S8 §10:

```
ID  SUBJECT                 SEVERITY  VERDICT      DECIDING RULE   REASON
8   chefred-fcf3e2.com      critical  INELIGIBLE   internal_asset  registered by us, serves no
9   lvl-shop-eb6db479.com   critical  INELIGIBLE   internal_asset  website, never resolved in DNS

{"eligible":0,"ineligible":2,"suppressed":0,"deferred":0,"manual_review":0}
```

Both would have been pages on day one of S9. Neither reaches a human, and neither is
blocked by name.

---

## 4. Policy audit

Every evaluation records the **full trail**, not just the verdict — because the decision has
to be explainable in both directions: why a customer was paged at 3am, and why an operator
was *not* told about something that mattered.

```
ALERT #8  chefred-fcf3e2.com  [dns_broken / critical / open]
VERDICT: INELIGIBLE  (internal_asset)

RULE TRAIL:
  alert_state      ELIGIBLE     alert is open
  asset_retired    ELIGIBLE     asset is live
  asset_ownership  ELIGIBLE     ownership established: managed_by_us
  internal_asset   INELIGIBLE   registered by us, serves no website, never resolved in DNS
```

`infra_alert_eligibility_decisions` is append-only and snapshots custody, severity,
confidence and environment, so a historical decision stays interpretable after the estate
moves on.

---

## 5. Architecture guards

Five guards, source-analysed with comments stripped via `token_get_all()`:

| Guard | Proves |
|---|---|
| never delivers | no `Mail::`, `Notification::`, `->notify(`, `Http::post`, `curl_exec` |
| never schedules or dispatches | no `Schedule::`, `dispatch(`, `event(`, `Queue::`, `Bus::` |
| never mutates assets | no writes to customer, website, observation, user or workspace tables |
| **never changes alert state** | no update/delete/insert against `infra_estate_alerts` |
| writes only its own audit table | the *only* table written is `infra_alert_eligibility_decisions` |

The last is the strongest form: rather than enumerate forbidden tables, it extracts every
written table from the source and asserts the set is exactly one.

---

## 6. Tests

```
EligibilityTest : 63 passed (4,226 assertions)
```

Customer safety (internal/sandbox/retired/unowned), the positive path (a real customer
critical **is** eligible — over-blocking is the worse failure), each rule individually,
deferred-vs-ineligible semantics, inferred-evidence asymmetry, maintenance windows,
duplicate cooldown, full audit trail recording, and the five architecture guards.

---

## 7. Regression

Running at the time of writing; results reported separately rather than predicted.

**Also still outstanding from S8.2:** the S6/S7 and wider S1/S2 suites plus the
guard-violation proof were launched in a background job that stalled and never produced
output. Those rows remain genuinely unconfirmed. Confirmed to date: S8 (36), S8.1 (36),
S8.2 guards (16), renewal (30), and now S8.3 (63).

---

## 8. Files modified

**New (4):**
- `database/migrations/2026_08_02_180000_create_infra_alert_eligibility_decisions_table.php`
- `app/Engines/Infrastructure/Observation/AlertEligibility.php`
- `app/Console/Commands/EligibilityCommand.php`
- `tests/Feature/Infrastructure/EligibilityTest.php`

**Modified: none.** No delivery, scheduler, registrar mutation, renewal, provider mutation,
Builder, CRM or Platform Events.

---

## 9. Concurrency report

| Observation | Classification |
|---|---|
| `2026_08_02_180000_create_engineer888_approval_tables.php` — **same timestamp prefix** | **concurrent** (Engineer888). Different filename; I migrated with `--path` targeting only my file. Theirs never executed. |
| 3 non-INFRA888 `app/` files modified in window | **concurrent**, untouched |
| `routes/` modified: 0 · routes 1055 unchanged | no overlap |
| All four new files | **this session**, nothing overwritten |

This is the second milestone where Engineer888 and I have independently chosen the same
migration timestamp. Harmless so far because filenames differ, but worth noting as a
recurring near-miss.

---

## 10. Honest limitations

1. **`internal_asset` depends on observation history.** A brand-new legitimate domain that
   has not yet been observed, and is not yet serving a website, would be classified
   internal and blocked. The rule is right for the estate as it exists but has a cold-start
   hole; onboarding should record the first successful observation before alerts matter.
2. **`sandbox_asset` reads a global setting.** `namecheap.environment` is per-connector, not
   per-domain, so switching the connector to production reclassifies every managed domain at
   once. Per-asset provenance would be better and is not recorded today.
3. **Maintenance windows are config-only.** No UI, no API, no per-workspace scoping beyond
   an optional exact hostname match.
4. **Cooldown is global per alert**, not per channel. Once delivery exists, a 4-hour
   cooldown may be wrong for a paging channel and right for a digest.
5. **Guards are lexical.** A violation routed through a variable class name or an indirect
   helper would pass.
6. **No customer-facing eligibility surface.** Verdicts exist in a table and a CLI only.

---

## 11. Production readiness

**9/10.**

Earned: the last governance gate before delivery exists, is deterministic, is fully
audited, and demonstrably blocks exactly the noise that would have poisoned S9 — without a
single hostname exception. The positive path is proven too: a genuine customer critical
passes.

Withheld: cold-start hole on new assets, global rather than per-asset sandbox provenance,
and two S8.2 regression rows still unconfirmed.

---

## 12. Recommendation for S9

**S9 Alert Delivery is now unblocked from INFRA888's side.** The contract I own is complete:
which alerts may leave (`eligible`), at what severity, with what customer-safe wording, and
an audit explaining every decision.

1. **Confirm the outstanding S8.2 regression rows first** — S6/S7 and wider suites plus the
   guard-violation proof. Small, and I would not ship delivery on top of unconfirmed rows.
2. **Then S9:** consume `verdict = eligible`, route critical/high to a human channel and
   `deferred` to a digest. **The channel itself belongs to the Notification Engine —
   Dependency: not owned by INFRA888.** My side stops at the contract.
3. Close the cold-start hole in `internal_asset` before onboarding a new customer domain.

Then S10 scheduler activation, S11 email provider, S12 hosting provider.

**Still not recommended:** automatic repair, automatic renewal, registrar writes, opening
the renewal execution gate.
