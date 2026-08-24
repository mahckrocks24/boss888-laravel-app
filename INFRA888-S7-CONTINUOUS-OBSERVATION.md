# INFRA888 — S7 CONTINUOUS ESTATE OBSERVATION & ALERTING

**Date:** 2026-08-02 · **Owner:** Hosting Claude (INFRA888) · **Status: DELIVERED — observation only, no scheduler activated**

---

## 1. Architecture

S6 could detect drift. It could not remember that it had already told you — every run
re-emitted the same findings, with no acknowledgement, suppression, escalation or
recovery. That is the difference between a diagnostic tool and an operational service.

S7 adds three things and nothing else:

| Component | Role |
|---|---|
| `ObservationCadence` | How often each fact is observed, and why. **Definitions only.** |
| `infra_estate_alerts` | Alert **identity** and lifecycle |
| `EstateAlertService` | Health computation + alert lifecycle |
| `EstateObserveCommand` | Cycle entry point and operator actions |

**Observation only.** Nothing repairs, renews, reconciles automatically, mutates a
provider, or writes desired state. Proven statically: across both new files, registrar
mutation calls **0**, writes to `customer_domains` **0**, writes to `infra_domain_renewals`
**0**.

---

## 2. Scheduler design

Cadence is a risk decision, not a preference — chosen from how fast a fact can change AND
how bad it is to learn late. Staleness is ~3× the interval: one missed run is noise, three
consecutive misses is a real gap in knowledge.

| Kind | Every | Stale after | Stale severity | Why |
|---|---|---|---|---|
| registrar | 6 h | 18 h | MEDIUM | Authoritative for ownership/lock/auto-renew, but rate-limited and IP-whitelisted. 6 h bounds how long a custody change or silent renewal can hide, inside sane API budgets. |
| expiry | 6 h | 24 h | MEDIUM | Moves once a year; frequent polling buys nothing. Rides the same registrar call at no extra cost. |
| custody | 6 h | **12 h** | **HIGH** | The most expensive fact to learn late — a released or transferred domain cannot be recovered by spending money. Same call, tighter staleness, because unknown custody is itself dangerous. |
| nameserver | 1 h | 4 h | MEDIUM | Delegation change is the hijack signature and the one fact an attacker controls directly. Blast radius is the whole site plus its email. |
| dns | 1 h | 4 h | MEDIUM | Resolution is what customers actually experience and can contradict the registrar. Cheap public lookup, no API budget. |
| certificate | 12 h | 48 h | LOW | Certs move on a 60–90 day cycle. Exists to catch a *failed* renewal with weeks of runway, not to watch a healthy cert. |

**No schedule was registered.** `ObservationCadence::scheduleProposal()` holds the intended
registration as inert, reviewable data:

```
0 */6 * * *   infra:estate-observe run --kind=registrar    # registrar+expiry+custody, one API call
0 * * * *     infra:estate-observe run --kind=dns          # public lookups only
0 */12 * * *  infra:estate-observe run --kind=certificate  # TLS handshake observation
*/15 * * * *  infra:estate-alerts escalate                 # advances clocks; raises nothing
```

---

## 3. Observation lifecycle

`observe → reconcile alerts → health`. Each cycle records a new observation row (S6's
append-only history) and then reconciles alerts against what was actually seen.

Live, twice:

```
cycle 1: lvl-shop  reachable=yes drift=1  raised=1 rearmed=0 recovered=0
cycle 2: lvl-shop  reachable=yes drift=1  raised=0 rearmed=1 recovered=0
         alert rows: 1   occurrences: 2
```

The same problem seen twice is **one alert with an occurrence count**, not two alerts.

---

## 4. Alert lifecycle

States: `open → acknowledged | suppressed | escalated → recovered`.
Severity ladder: `critical | high | medium | low | info`.

**The load-bearing rule:** `recovered_by_observation_id` is mandatory on every recovered
alert. **An alert may only close because a fresh, successful observation proved the drift
gone.** It can never close because it aged out, because nobody looked, or because the
registrar became unreachable. Timing alerts out is how a real problem becomes an invisible
one.

Consequences, all tested:
- an **unreachable** observation recovers nothing — we learned nothing, so nothing closes
- **acknowledgement stops the escalation clock but does not close the alert** — a human
  knowing is not the problem being gone
- **suppression is capped at 7 days** and returns automatically; permanent silence is how
  outages get missed
- an expired suppression returns the alert to `open` when the drift is still present
- escalation ladders 30 min (critical) / 2 h (high) / 12 h (medium) / 48 h (low), stopping
  at level 3 — keep shouting, stop counting

---

## 5. Health computation

Health is derived from evidence: observation freshness, registrar reachability, unresolved
alerts, and observation failure.

States, worst to best: `critical → at_risk → degraded → unknown → healthy`.

**Two rules make it honest:**

1. **Health never improves because time passed.** Only a fresh successful observation can
   raise it. A domain nobody has looked at decays to `unknown`.
2. **Unknown outranks false certainty.** An unreachable probe or a stale reading yields
   `unknown`, never `healthy` — absence of findings is not evidence of health.

Every health result carries `reasons[]`. Live:

```
lvl-shop-eb6db479.com  DEGRADED  age=0 min  · 1 unresolved alert(s)
chefred-fcf3e2.com     HEALTHY   age=0 min  · fresh successful observation, no unresolved alerts
```

---

## 6. Customer visibility

```json
{"domain":"lvl-shop-eb6db479.com","last_verified":"2026-08-02 14:17:51",
 "verification_age_minutes":0,"confidence":"checking",
 "active_warnings":[],"provider":"LevelUp Growth"}
```

Last Verified, Verification Age, Confidence, Active Warnings. Nothing else.

Leak-tested against `namecheap`, drift class names, `TRANSPORT`, `API`, `probe`. Suppressed
alerts are hidden from customer warnings. Only drift classes with deliberately
customer-safe wording ever surface.

---

## 7. Admin visibility

Everything is explainable: health state **with reasons**, observation history (20 rows:
timestamp, reachability, latency, probe error, drift count, observed vs desired expiry),
and per-alert — severity, state, occurrences, first/last seen, acknowledgement note and
actor, suppression window, escalation level and next escalation, recovery timestamp **and
the observation id that proved it**, plus operator guidance and recommended action.

---

## 8. Files modified

**New (5):**
- `database/migrations/2026_08_02_150000_create_infra_estate_alerts_table.php`
- `app/Engines/Infrastructure/Observation/ObservationCadence.php`
- `app/Engines/Infrastructure/Observation/EstateAlertService.php`
- `app/Console/Commands/EstateObserveCommand.php`
- `tests/Feature/Infrastructure/EstateAlertTest.php`

**Modified: none.** No routes, no config, no scheduler, no Cloudflare, no DNS, no email, no
Builder, no Platform Events, no Engineering888.

---

## 9. Tests

```
S7 alert lifecycle + health : 36 passed (82 assertions)
S6 observation              : 34 passed (204 assertions)
S3/S4/S5 renewal            : 30 passed (72 assertions)
```
Isolated DB `levelup_p1e1_test`.

Proven: repeated drift is one alert with occurrences · recovery names the observation that
proved it · unreachable observation recovers nothing · alerts never recover by age ·
acknowledgement stops escalation without closing · suppression is capped and time-boxed ·
expired suppression reopens · unanswered critical escalates · escalation stops at level 3 ·
never-observed is unknown · stale decays to unknown · unreachable is unknown · **health
improves only after fresh successful observation** · unresolved critical ⇒ critical health ·
health always explains itself · customer view exposes no diagnostics · admin view fully
explainable · suppressed alerts hidden from customers.

---

## 10. Defect found — and repeated

`run()` collides with `Illuminate\Console\Command::run()`, which is public. Declaring it
private was a fatal that **broke the entire artisan CLI**, including the 5-minutely
`platform-events:process` cron. HTTP was unaffected (200s throughout) and it was fixed
within one run.

**This is the second time I have made this exact mistake** — S3 hit the identical trap with
`execute()`. Renaming a `match` arm to a private method that shadows a Symfony/Laravel base
method is a repeatable error, and I did not learn it the first time. Any future command
should use a distinct verb (`runCycle`, `runExecute`) by default rather than discovering the
collision at runtime.

---

## 11. Concurrency

Route count **1051, unchanged** from the count established after another session's earlier
changes. My S7 files declare zero routes. No `routes/` or non-INFRA888 file was modified in
the working window. The other session's migration `add_execution_provenance_to_api_usage_logs`
remains **Pending**, unexecuted.

**Classification: no overlap this milestone.**

---

## 12. Honest limitations

1. **Nothing is scheduled.** By instruction. Until activation is authorised, "continuous"
   is a design, not a behaviour — every cycle still needs someone to run it.
2. **Nothing is delivered anywhere.** Alerts exist in a table with full lifecycle; no email,
   Slack, webhook or page. An escalated critical shouts into a database. Delivery requires
   the notifications workstream, which I do not own.
3. **Only the registrar kind actually probes.** `--kind=dns` and `--kind=certificate` accept
   the flag and run the registrar observation; independent DNS resolution and TLS handshake
   observation are **not implemented**. The cadence for them is designed, not delivered.
4. **Coverage is two internal validation domains.** The customer domains
   `chefredraymundo.com` and `amgtravelandtours.com` are GoDaddy-held under customer control
   (S2 Option A) and are outside registrar-API observation entirely — so continuous
   observation still covers **zero customer domains**.
5. **Sandbox only.** No production registrar credential exists.
6. **Escalation has no recipient.** Levels advance correctly and mean nothing operationally
   until §12.2 is solved.

Limitations 2 and 3 together mean this is a complete alert *engine* with an incomplete alert
*system*.

---

## 13. Remaining blockers

1. Scheduler activation (authorisation required).
2. Alert delivery — **Dependency: owned by the notifications workstream. Not modified.**
3. Independent DNS and certificate observation.
4. Non-registrar custody observation (WHOIS/DNS) to cover customer-held domains.
5. No production registrar credential.
6. The `'transient'` vs `'retryable'` vocabulary split — **Dependency: owned by Domain
   Commerce. Not modified.**

---

## 14. Production readiness score

**8.5/10** (was 8/10 after S6).

Earned: alerts have identity and a full lifecycle; health is evidence-based and degrades
honestly; recovery is impossible without proof; suppression cannot be permanent;
acknowledgement cannot masquerade as resolution; customer and admin views are correct and
separated; cadence is designed with stated rationale.

Withheld: unscheduled, undelivered, two of six observation kinds unimplemented, and still
covering no customer domain.

---

## 15. Recommended S8

**S8 — Alert Delivery & Scheduler Activation.**

1. **Authorise scheduler activation** using the proposed registration in §2, starting with
   the registrar cycle only, observed for a week before adding the hourly DNS cycle.
2. **Alert delivery** — requires the notifications owner. Route critical/high to a real
   human channel; medium and below to a digest. Without this, S7 is a very well-built
   silence.
3. **Implement independent DNS + certificate observation** so `--kind=dns` and
   `--kind=certificate` do what their cadence claims.
4. **Then** non-registrar custody observation, which is what finally puts the two live
   customer domains under continuous watch.

Do **not** activate the scheduler and add delivery in the same change — one of them will be
wrong and you will not know which.

**Still not recommended:** automatic repair, automatic renewal, automatic reconciliation,
opening the renewal execution gate.
