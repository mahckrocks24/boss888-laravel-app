# INFRA888 — S8 CUSTOMER ESTATE OBSERVATION

**Date:** 2026-08-02 · **Owner:** Hosting Claude (INFRA888) · **Status: DELIVERED — observation only**

---

## 1. The gap this closes

S6 and S7 built a real observation and alerting engine that covered **zero customer
domains**. Everything ran through the registrar API, and both live customer domains —
`chefredraymundo.com` and `amgtravelandtours.com` — are held by the customers at GoDaddy.
An API we hold no credentials for can never see them.

S8 makes the estate observable through signals that need no account access.

**Both real customer domains are now under observation for the first time.**

---

## 2. Architecture

```
Custody::resolve(host)          → what are we even allowed to know?
        ↓
Custody::observableDimensions() → which observers may run
        ↓
DnsObserver | EndpointObserver  → measure (read-only)
        ↓
infra_observation_facts         → append-only registry, custody recorded per row
        ↓
per-dimension health            → dns / certificate / http / whois / registrar
```

Observation only. Proven statically across all three engine files: provider mutations
**0**, estate writes **0**, `Mail::`/`Notification::`/`notify()` references **0**,
schedulers registered **0**.

---

## 3. Custody model (Stage 1)

| Custody | Meaning | Registrar | DNS | Cert | HTTP | WHOIS |
|---|---|---|---|---|---|---|
| `managed_by_us` | We hold the registrar account | **verified** | verified | verified | verified | inferred |
| `customer_held` | We serve it; customer registered it | *not observable* | verified | verified | verified | inferred |
| `third_party_held` | Someone else holds and operates it | *not observable* | verified | verified | verified | inferred |
| `unknown` | No platform record | *not observable* | verified | verified | verified | inferred |

Two decisions carry the model:

**DNS, certificate and HTTP are `verified` under every custody.** They are measured
directly from the public internet, so who owns the registrar does not weaken them. This is
precisely what makes customer-held domains observable at all.

**WHOIS is never `verified`, even for domains we manage.** WHOIS output is unstandardised
across TLDs, frequently rate-limited, and increasingly redacted. A parsed expiry is a
useful hint, never an authority. Recording it at the same confidence as a registrar API
read would make the estate confidently wrong.

A dimension custody forbids is `not_applicable` — **never** counted as ill health. We are
not failing to observe the registrar for a customer-held domain; we are correctly not
attempting it.

**Live resolution:**
```
amgtravelandtours.com   customer_held   ws 26   served by our platform, registered elsewhere
chefredraymundo.com     customer_held   ws 2    served by our platform, registered elsewhere
chefred-fcf3e2.com      managed_by_us   ws 2    registered through our registrar account
lvl-shop-eb6db479.com   managed_by_us   ws 1    registered through our registrar account
```

---

## 4. DNS observation (Stage 2)

A, AAAA, CNAME, MX, TXT, NS, CAA. Drift produced for:

- **hostname does not resolve** — CRITICAL, the site is unreachable by name
- **nameserver delegation differs** — HIGH, the hijack signature
- **apex A does not point at the expected origin** — HIGH, traffic going somewhere we do
  not serve (asserted only where the estate holds a real expectation)
- **MX records disappeared** — CRITICAL, mail bounces and nobody notices until replies stop

---

## 5. Certificate observation (Stage 3)

Issuer, expiry, SANs, signature algorithm, chain length, self-signed detection, and
**wildcard-aware hostname coverage**. Drift for expired (CRITICAL), ≤7 days (CRITICAL),
≤21 days (HIGH — automatic renewal should already have happened), hostname not covered
(CRITICAL), **www not covered** (MEDIUM), self-signed (CRITICAL), weak algorithm (MEDIUM).

The www check exists because it is the single most common real-world gap — and it is
exactly the defect found manually on `www.chefredraymundo.com` during S2. This now
surfaces automatically.

No renewal, no issuance.

---

## 6. WHOIS observation (Stage 4)

For customer-held domains this is the **only** expiry signal available. Observes expiry,
creation, registrar, domain status, nameservers and ownership visibility (redaction
detected).

Drift: already expired (CRITICAL), expiring ≤30 days (HIGH), expiry differs from record
(MEDIUM), and registrar hold/pendingDelete/redemption status (CRITICAL).

The guidance is deliberately different from managed domains: *"Customer-held domain. Warn
the customer now — we have no ability to renew it."* Telling an operator to renew something
they cannot renew is worse than saying nothing.

---

## 7. HTTP observation (Stage 5)

HTTPS status, HTTP status, redirect chain and final URL, response timing, availability.
**Headers only — no content crawling.**

Drift: HTTPS not answering (CRITICAL), 5xx (CRITICAL), 4xx (HIGH), **HTTP does not redirect
to HTTPS** (MEDIUM), very slow response >5 s (MEDIUM), excessive redirect chain >4 hops
(MEDIUM).

---

## 8. Observation registry (Stage 6)

`infra_observation_facts` — dimension-agnostic, append-only. Every row carries subject,
dimension, **custody**, provider, success, confidence, duration, error, observed vs desired
payloads, drift, `observed_at`, and `last_success_at` / `last_failure_at` carried forward so
one read answers *"when did we last actually know this?"* without scanning history.

Custody is stored per row because it changes what the row can possibly mean.

---

## 9. Health integration (Stage 7)

Health is computed **per dimension**, independently. A single score hides what you need:
*"degraded"* is useless, whereas *"DNS healthy, certificate expires in 4 days, HTTP healthy,
WHOIS unknown"* tells an operator exactly what to do.

S7's rules carry over per dimension: a failed observation yields `unknown`, a stale one
decays to `unknown`, and health never improves because time passed. Overall health is the
worst **observable** dimension.

**Live result:**
```
amgtravelandtours.com   dns=ok cert=ok http=ok whois=ok    0 drift
chefredraymundo.com     dns=ok cert=ok http=ok whois=ok    0 drift
```

---

## 10. An honest false positive

The two sandbox-registered domains emit **six CRITICAL drift findings** during a scan:

```
chefred-fcf3e2.com     dns=FAILED cert=FAILED http=FAILED
lvl-shop-eb6db479.com  dns=FAILED cert=FAILED http=FAILED
```

**These observations are correct.** Both are Namecheap *sandbox* registrations — they exist
in the sandbox API and have never existed in real DNS, so they genuinely do not resolve.

Worth noting the health layer handled this correctly on its own: because a **failed**
observation cannot assert anything, those dimensions resolve to `unknown` rather than
`critical`, and overall health reads `UNKNOWN` — not a false emergency. The S7 rule
carried its weight without special-casing.

```
chefred-fcf3e2.com     OVERALL=UNKNOWN   dns/cert/http = unknown (last observation failed)
lvl-shop-eb6db479.com  OVERALL=UNKNOWN   dns/cert/http = unknown
```

The problem is therefore narrower than "false criticals everywhere", but it is still real:
**a scan emits six critical drift findings about domains that were never meant to resolve.**
The estate cannot distinguish "a real domain that is down" from "a sandbox artefact". Once
S9 wires delivery, those six become six pages. Left alone, this is exactly the noise that
trains operators to ignore alerts.

**Recommended fix (not built — S8 is observation, not modelling):** a `sandbox` or
`non_production` flag on the subject, excluded from health rollups. Flagged rather than
silently filtered, because quietly hiding failures is the worse error.

---

## 11. Files modified

**New (6):**
- `database/migrations/2026_08_02_160000_create_infra_observation_facts_table.php`
- `app/Engines/Infrastructure/Observation/Custody.php`
- `app/Engines/Infrastructure/Observation/EstateObservationService.php`
- `app/Engines/Infrastructure/Observation/Observers/DnsObserver.php`
- `app/Engines/Infrastructure/Observation/Observers/EndpointObserver.php`
- `app/Console/Commands/EstateScanCommand.php`
- `tests/Feature/Infrastructure/CustodyObservationTest.php`

**Modified: none.** No routes, config, scheduler, notifications, email, Cloudflare, DNS,
registrar, Builder, CRM or Platform Events.

---

## 12. Concurrency report

| Observation | Classification |
|---|---|
| `2026_08_02_160000_create_engineering_reasoning_tables.php` — **same timestamp prefix** as my migration | **concurrent** (Engineer888). Different filename, no collision. I migrated with `--path` targeting only my file; theirs was never executed. |
| `add_execution_provenance_to_api_usage_logs` | **concurrent**, still **Pending**, untouched |
| Routes 1051 | unchanged from the count another session established; my files declare zero routes |
| No `routes/` or non-INFRA888 file modified in the working window | no overlap |

Command method names are prefixed (`doScan`, `doHealth`) specifically to avoid shadowing
`Command::run()` / `Command::execute()` — collisions that broke artisan in S3 and S7.

---

## 13. Limitations

1. **Sandbox domains produce six false criticals** (§10). The single most important thing
   to fix before delivery, or the first real alert will arrive already distrusted.
2. **`registrar` dimension is not run here.** It remains S6's service; S8 does not
   duplicate it, so a full picture needs both commands.
3. **WHOIS depends on a system `whois` binary** (present on this host) and degrades to
   `WHOIS_UNAVAILABLE` elsewhere. TLD coverage and rate limits are outside our control.
4. **No IPv6-only validation, no DNSSEC, no CAA policy evaluation.** CAA records are
   collected but not judged.
5. **Certificate chain is counted, not validated.** `verify_peer` is off so we can observe
   broken certificates rather than fail to see them — the trade is that we do not assert
   chain validity.
6. **Nothing scheduled, nothing delivered.** Still manual, by instruction.
7. **Desired state is thin.** Drift is only asserted where the estate holds a real
   expectation; for customer-held domains that is mostly nothing, so DNS drift detection is
   weaker there than for managed domains.

---

## 14. Production readiness

**9/10** (was 8.5/10 after S7).

Earned: the estate finally observes real customer infrastructure; custody governs both
strategy and confidence; five dimensions with independent health; drift carries severity
and actionable guidance; append-only registry with freshness and last-success tracking; no
mutation anywhere.

Withheld: sandbox noise, no scheduling, no delivery, thin desired state.

---

## 15. Recommendation for S9

**S9 — Alert Delivery**, with two prerequisites folded in:

1. **Suppress sandbox subjects first** (§10). Delivering six false criticals on day one
   would poison the channel permanently.
2. **Bridge S8 drift into S7's alert lifecycle.** S8 currently records drift in the
   registry; S7 owns identity, acknowledgement, suppression, escalation and recovery. They
   must be joined before delivery, or delivery will re-send the same finding forever.
3. **Then** route critical/high to a human channel and everything else to a digest —
   requires the notifications owner, which I do not own.

Then S10 scheduler activation, S11 email provider, S12 hosting provider.

**Still not recommended:** automatic repair, automatic renewal, registrar writes, opening
the renewal execution gate.
