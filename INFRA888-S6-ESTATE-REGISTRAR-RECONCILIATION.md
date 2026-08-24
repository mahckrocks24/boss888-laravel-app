# INFRA888 — S6 ESTATE ↔ REGISTRAR RECONCILIATION

**Date:** 2026-08-02 · **Owner:** Hosting Claude (INFRA888) · **Status: DELIVERED — read-only, no repair, no scheduler**

---

## 1. Existing architecture — the gap S5 exposed

The estate knew what it **believed**. Nothing read what actually **existed**.

S5 proved this with live evidence: the registrar said `2030-07-29`, the estate said
`2029-07-29`, and monitoring reported *"No findings"*. The renewal monitor only ever
compared **our own ledger rows** to the estate, so any change originating outside our
ledger — registrar auto-renew, a staff renewal in the Namecheap UI, a transfer away, a
nameserver change — was structurally invisible.

S6 builds the observation half.

---

## 2. Observation model

New table `infra_domain_observations` (32 columns). Each row is **one read-only look at
the registrar, kept forever, never overwritten**.

Three column families, deliberately separated:

| Family | Meaning |
|---|---|
| `observed_*` | What the registrar said |
| `desired_*` | What the estate believed **at observation time**, snapshotted |
| `drift_json` | The classified difference, with guidance |

Snapshotting desired state matters: a historical row stays interpretable after the estate
later changes. Keeping history rather than a single "latest" row also matters — *"the
nameservers changed last Tuesday"* is a security question, and you cannot ask it of a
table that only remembers now.

`reachable = false` is itself a recorded observation. A registrar we could not reach
produces evidence of ignorance, not silence.

---

## 3. Reconciliation engine

`RegistrarObservationService` — strictly read-only against **both** sides:

- never mutates the registrar
- never repairs the estate
- never renews
- writes **only** observation rows

**Proven statically:**
```
writes to customer_domains      : 0
writes to infra_domain_renewals : 0
registrar mutation calls        : 0
CustomerDomain save/update calls: 0
observation inserts             : 1
```

**Proven behaviourally:** the test fake throws `LogicException` from every mutating
registrar method; observation completes without touching one.

### Why it must not self-heal

`customer_domains` is desired state and is never touched — not even to "helpfully" correct
an expiry we can plainly see is wrong. Silent repair destroys the signal this system exists
to raise: had observation quietly fixed the estate, S5's year-long divergence would have
vanished without anyone learning that a renewal happened outside the ledger. **The system
records facts. Operators decide.**

---

## 4. Drift taxonomy

Thirteen classes, each carrying severity, operator guidance, customer visibility and a
recommended action — a finding nobody can act on is noise.

| Class | Severity | Why |
|---|---|---|
| `expiry_earlier_than_believed` | **CRITICAL** | Lapses sooner than any schedule we hold — the dangerous direction |
| `expiry_later_than_believed` | WARNING | Renewed outside our ledger; renewing again would double-charge |
| `custody_lost` | **CRITICAL** | Registrar no longer reports it as ours — stop all spending |
| `not_managed_by_us` | WARNING | Exists at registrar, not in our account |
| `nameserver_drift` | WARNING | How a domain gets hijacked |
| `auto_renew_drift` | WARNING | Renewal planning assumes the wrong setting |
| `registrar_lock_drift` | WARNING | Unexpected unlock precedes transfer-away |
| `status_drift` | WARNING | Lifecycle state we do not hold |
| `registrar_unavailable` | **CRITICAL** | Absence of findings means nothing while true |
| `stale_observation` | WARNING | Old look = unknown, not healthy |
| `orphaned_estate` | **CRITICAL** | We may be billing for a domain that isn't there |
| `orphaned_registrar_asset` | INFO | We own something nobody is tracking; it will silently expire |
| `unknown_drift` | WARNING | Difference the taxonomy doesn't describe — extend it |

Expiry drift is **two classes, not one**, because direction changes both severity and the
correct action. Nameserver comparison is order-insensitive.

---

## 5. Live result — the S5 drift is now caught

```
DOMAIN                    ESTATE BELIEVES   REGISTRAR SAYS   DRIFT       LAST SEEN
lvl-shop-eb6db479.com     2029-07-29        2030-07-29       1 WARNING   2026-08-02 13:29
chefred-fcf3e2.com        2027-07-29        2027-07-29       0           2026-08-02 13:29

[WARNING] Registrar expiry is LATER than the estate believes
          observed=2030-07-29  desired=2029-07-29
          -> Confirm who renewed it, then update the recorded expiry deliberately.
             Renewing again would double-charge.
```

**Estate after observation: unchanged.** `expires_at` still `2029-07-29`, `last_synced_at`
still `12:17:19` from S4. Exactly as intended.

---

## 6. Monitoring

`infra:domain-observe monitor` — stale observations (>24 h, and *never observed* counts),
registrar unreachable, every recorded drift class, custody changes, nameserver changes,
critical expiry mismatch. Non-zero exit on any CRITICAL so it can gate a task later.

**No scheduler was registered.**

---

## 7. Customer / Admin experience

**Customer** sees `last_verified`, a `verification_confidence` of
`verified | checking | needs_attention | stale | unknown`, and only drift messages
explicitly written to be customer-safe. Provider is `LevelUp Growth`.

An unreachable registrar yields `unknown` — **never** `verified`. Proven by test.

Leak-tested: no `namecheap`, no nameserver hostnames, no `managed_by_us`, no raw status
codes, no DNS provider.

**Admin** sees raw observed values, desired values, full drift classification with
guidance and recommended action, the raw provider payload, and observation history.

---

## 8. Files modified

**New (4):**
- `database/migrations/2026_08_02_140000_create_infra_domain_observations_table.php`
- `app/Engines/Infrastructure/Observation/DomainDrift.php`
- `app/Engines/Infrastructure/Observation/RegistrarObservationService.php`
- `app/Console/Commands/DomainObserveCommand.php`
- `tests/Feature/Infrastructure/ObservationTest.php`

**Modified: none.** No route, no config, no cron, no other workstream, no Cloudflare, no
Builder, no email.

---

## 9. Tests

```
S6 observation      : 34 passed (204 assertions)
S3/S4/S5 renewal    : 30 passed (72 assertions)
Wider suites        : 162 passed (709 assertions)
```
Isolated DB `levelup_p1e1_test`. **Nothing from S1–S5 regressed.**

Proven: observation never repairs the estate · never calls a mutating registrar method ·
records history rather than overwriting · expiry-later = WARNING · expiry-earlier =
CRITICAL · custody loss = CRITICAL · nameserver drift detected and order-insensitive ·
auto-renew and lock drift · unreachable = CRITICAL and not silence · orphaned estate =
CRITICAL · no drift when everything agrees · customer view leaks nothing · confidence is
`unknown` when unreachable · admin retains raw/desired/drift/history · every taxonomy entry
has severity, guidance and action · never-observed counts as stale · workspace scoping.

---

## 10. Concurrency

**Route count moved 1040 → 1051 (+11). Not mine.** My S6 files declare zero routes.
`routes/api/admin/engineer888.php`, `routes/api/authenticated/studio-02.php` and
`routes/api.php` were all modified within the preceding two hours by other sessions.
**Classification: concurrent. Not touched, not reverted, not absorbed.**

Also concurrent and untouched: `app/Core/Engineer888/Signals/Shell.php`. The other
session's migration `add_execution_provenance_to_api_usage_logs` remains **Pending**.

---

## 11. Honest limitations

1. **`orphaned_registrar_asset` is defined but never raised.** Detecting a domain the
   registrar holds and the estate does not requires enumerating the registrar account via
   `listDomains()`. That is a new capability; S6's remit is comparison of known domains.
   The class exists so the taxonomy is complete and the gap is visible rather than absent.
2. **No DNS-level observation.** Registrar-reported nameservers are compared, but actual
   resolution is not queried — a registrar can report delegation that DNS contradicts.
3. **Observation is manual.** No scheduler, by instruction. Until one is registered,
   `stale_observation` will fire for everything within 24 hours.
4. **Only sandbox has been observed.** The production registrar has never been read; no
   production credential exists.
5. **Nothing is alerted.** CRITICAL findings surface only to someone running the command.
6. **Two domains observed.** Both are internal validation domains. The customer domains
   `chefredraymundo.com` and `amgtravelandtours.com` are **not registrar-managed by us** —
   they sit at GoDaddy under customer control (S2, Option A), so they are outside registrar
   observation entirely.

Limitation 6 is the significant one: **the estate's registrar observation currently covers
zero customer domains**, because no customer domain is registered through us.

---

## 12. Remaining production blockers

1. No production registrar credential.
2. No scheduled observation, so drift is found only when someone looks.
3. No alerting on CRITICAL.
4. Registrar-account enumeration (`orphaned_registrar_asset`) not implemented.
5. The `'transient'` vs `'retryable'` vocabulary split from S5 remains open —
   **Dependency: owned by Domain Commerce. Not modified.**

---

## 13. Production readiness score

**8/10** (was 7/10 after S5).

Earned: the estate can now detect that it is wrong; drift is classified with severity and
action; observation cannot repair, renew or mutate; customer confidence degrades honestly
to `unknown`; admin retains full evidence; history is preserved.

Withheld: manual-only, unalerted, sandbox-only, no registrar enumeration, and covering no
customer domain.

---

## 14. Recommended S7

1. **Scheduled observation + alerting** — the two changes that convert this from a tool
   someone runs into a system that tells you. Needs the notifications owner for alerting.
2. **Reconciliation command for `NEEDS_MANUAL` renewals** (carried over from S5): read the
   registrar, decide whether an ambiguous charge landed, resolve with evidence.
3. **Registrar account enumeration** to close `orphaned_registrar_asset`.
4. **Extend observation to non-registrar custody** so the GoDaddy-held customer domains
   get expiry and nameserver observation via WHOIS/DNS rather than registrar API — this is
   what makes the Digital Estate honest about domains we do not register.

**Still not recommended:** automatic repair, automatic renewal, opening the execution gate.
