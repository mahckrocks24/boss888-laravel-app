# INFRA888 — S8.1 OBSERVATION → ALERT BRIDGE

**Date:** 2026-08-02 · **Owner:** Hosting Claude (INFRA888) · **Status: DELIVERED — integration only; one test suite still broken (§10)**

---

## 1. Architecture

S8 discovered drift. S7 owned alert lifecycle. They shared a database and nothing else.

```
Observation (S8 registry)
   ↓  findingsFor()          every drift row → one canonical finding
Finding
   ↓  canonicalise()         many symptoms → one customer problem
   ↓  collapseConsequences() causal chain → root cause only
Alert  (S7 lifecycle)        identity = sha256(subject | canonical_issue)
   ↓
Health                       derived from alerts, never computed independently
   ↓
Future delivery (S9)         nothing here
```

**Integration only.** Proven statically on the bridge: `Mail::`/`Notification::`/`notify()`
**0**, provider mutations **0**, escalation triggered **0**, estate writes **0**.

---

## 2. Finding model

Every observation row maps to canonical findings. A **failed** observation is itself a
finding (`observability_lost`) rather than silence — losing visibility is a fact worth
recording.

Ten canonical issues, each with a customer-safe message or an explicit `null`:

| Issue | Correlation group | Customer-visible |
|---|---|---|
| `site_unreachable` | service_delivery | yes |
| `tls_invalid` | service_delivery | yes |
| `dns_broken` | service_delivery | yes |
| `delegation_changed` | domain_lifecycle | yes |
| `expiry_risk` | domain_lifecycle | yes |
| `custody_risk` | domain_lifecycle | yes |
| `mail_at_risk` | communications | yes |
| `insecure_transport` | service_delivery | no |
| `performance_degraded` | service_delivery | no |
| `observability_lost` | observability | no |

One mapping decision worth naming: **"expiry" means different things by dimension.** A
certificate expiring is `tls_invalid`; a *domain* expiring is `expiry_risk`. Same word,
different incident, different owner, different fix.

---

## 3. Alert identity

```
alert_key = sha256("infra888|" + lower(subject) + "|" + canonical_issue)[0:40]
```

Derived from **what is wrong**, never from **which observer noticed**. Consequences:

- same finding → same alert, for all time
- different finding → different alert
- severity changes **update** the alert and log a `severity_changed` history entry
- evidence changes update the alert
- **identity never changes**

Proven: re-running the bridge produced `updated=1`, not a second alert; total stayed at 2.

---

## 4. Deduplication — the part that matters

First implementation was **wrong**, and the live run exposed it: 6 drift findings produced
6 alerts. They correlated into one *incident* but stayed three *alerts* per subject, because
`dns_broken`, `tls_invalid` and `site_unreachable` are genuinely distinct canonical issues.

But they are not independent. **When DNS does not resolve, the TLS handshake and the HTTP
probe cannot succeed** — their failures are consequences, not faults. Three criticals for
one broken thing is how an operator learns to ignore the third one.

`collapseConsequences()` applies a causal chain:

```
custody_risk → expiry_risk → delegation_changed → dns_broken
             → tls_invalid → site_unreachable → insecure_transport → performance
```

Only the earliest issue present survives as an alert. Everything downstream is folded into
it **as evidence**, so nothing is lost.

**Result:**
```
raw drift findings : 6
canonical alerts   : 2
collapsed          : 4 consequence findings folded into their root cause
```

```
[CRITICAL] chefred-fcf3e2.com   service_delivery  (1 alert, 3 observations)
   #8  dns_broken  critical  open
        evidence: dns          dns_resolver   critical  Hostname does not resolve
        evidence: certificate  tls_handshake  critical  TLS handshake failed
        evidence: http         http_probe     critical  HTTPS is not answering
```

One alert. Three observers still visible, agreeing.

---

## 5. Severity resolution

Highest severity wins; **all** evidence is preserved. Collapsing four symptoms into one
alert is pointless if three of them vanish — an operator needs to see that the certificate,
the handshake and the HTTP probe all agree, and which one is the root.

---

## 6. Recovery rules

Recovery requires a **fresh successful observation**, and one that could actually have seen
the issue. Two guards:

1. **Nothing succeeded this round → nothing recovers.** `held` counts alerts deliberately
   left open because we learned nothing.
2. **Dimension coverage.** An alert raised by DNS is not closed because HTTP happened to
   succeed. `dimensionsFor()` maps each canonical issue to the dimensions that can legitimately
   close it, and `recovered_by_observation_id` records which observation proved it.

Never by acknowledgement, suppression, elapsed time, or scheduler success.

---

## 7. Health pipeline

Health is now **derived from alerts**, not computed independently:

```
amgtravelandtours.com  HEALTHY   alerts=0  [derived_from=alerts]
chefredraymundo.com    HEALTHY   alerts=0  [derived_from=alerts]
chefred-fcf3e2.com     CRITICAL  alerts=1  [derived_from=alerts]
lvl-shop-eb6db479.com  CRITICAL  alerts=1  [derived_from=alerts]
```

S8 computed health straight from observation rows, so health and alerts could disagree — a
*suppressed* alert still dragged health down, and an *acknowledged* one looked identical to
an unhandled one. One pipeline, one answer. Freshness is still gated first: no fresh
successful observation ⇒ `unknown`, regardless of alerts.

---

## 8. Files modified

**New (2):** `app/Engines/Infrastructure/Observation/ObservationAlertBridge.php`,
`app/Console/Commands/BridgeCommand.php`

**Modified (1):** `tests/Feature/Infrastructure/CustodyObservationTest.php` — attempted
fixture repair (see §10). No migration, no routes, no config, no scheduler, no other
workstream.

---

## 9. Concurrency report

| Observation | Classification |
|---|---|
| Routes 1051 → **1055** | **concurrent** — not mine; my S8.1 files declare zero routes |
| 10 non-INFRA888 `app/` files modified in the window | **concurrent** — untouched |
| 1 `routes/` file modified in the window | **concurrent** — untouched |
| Engineer888 migration sharing my S8 timestamp prefix | **concurrent** — never executed |
| `add_execution_provenance_to_api_usage_logs` | **concurrent**, still Pending |

No file was shared. Command methods are prefixed (`doBridge`, `doHealth`) to avoid the
`run()`/`execute()` base-class collisions that broke artisan in S3 and S7.

---

## 10. Honest limitations

1. **The S8 test suite still does not run. 16 errors.** `websites.workspace_id` has a
   foreign key to `workspaces`; my fixture creates a workspace row but the table has many
   further NOT NULL columns and the insert still fails. I attempted a fix in this milestone
   and **it did not work**. The S8 production code is proven by live execution against real
   domains, but its unit coverage is absent — that is a real gap, not a formality.
2. **S8.1 has no test suite of its own.** Deduplication, identity, causal collapse and
   recovery-coverage are proven only by live execution. They need the same fixture that is
   currently broken.
3. **Causal chain is single and linear.** One chain covers domain-lifecycle → service-delivery.
   Real topologies branch (an expired certificate does not break DNS), so the chain is
   correct in the observed direction but not general.
4. **Correlation is per-subject.** A registrar-wide outage affecting twenty domains produces
   twenty incidents, not one.
5. **Sandbox subjects still generate alerts** (S8 §10). Now 2 rather than 6, but they remain
   noise and must be suppressed before delivery.
6. **Nothing scheduled, nothing delivered.** By instruction.

### Passing suites

```
DomainRenewalOrchestrationTest (S3/S4/S5) : 30 passed
EstateAlertTest + ObservationTest (S6/S7) : 70 passed
CustodyObservationTest (S8)               : 16 ERRORS  ← fixture, not production code
```

Live: routes 1055, `/api/health` 200, `/admin/dashboard` 200, my schedulers 0.

---

## 11. Production readiness

**8.5/10** — down from S8's 9/10, deliberately.

The pipeline is materially better: one alert per customer problem, deterministic identity,
evidence preserved, recovery that cannot be faked, health that cannot disagree with alerts.

But **two of the last three milestones now have no working unit tests**, and I have twice
reported work as delivered while its test suite was failing. Live proof is real evidence,
and it is not a substitute for tests that run.

---

## 12. Recommendation for S9

**Do not start Alert Delivery yet.** Two things must land first, in this order:

1. **Fix the test fixture and write S8.1's suite.** Delivery makes every defect visible to
   a human immediately; shipping it on top of two untested milestones is the wrong order.
   The fix is likely a workspace factory rather than a raw insert — worth checking whether
   the codebase already has one before hand-rolling more fixture SQL.
2. **Suppress sandbox subjects.** Two false criticals become two pages on day one.

Then S9 delivery: critical/high to a human channel, everything else to a digest — requires
the notifications workstream, which I do not own.

Then S10 scheduler activation, S11 email provider, S12 hosting provider.
