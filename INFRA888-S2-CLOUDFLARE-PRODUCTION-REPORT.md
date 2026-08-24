# INFRA888 — S2 CLOUDFLARE PRODUCTION REPORT

**Phase:** S2 — Custom Domains: Adoption, Reconciliation and Controlled Production Activation
**Date:** 2026-08-02
**Owner:** Hosting Claude (INFRA888)
**Verdict:** **S2 CLOSED — COMPLETE under the approved delivery model.**

---

## 0. S2 CLOSURE — 2026-08-02 (post-decision addendum)

Mark approved **Option A** for both live domains. Cloudflare activation stages (F, I, J)
are closed as **NOT APPLICABLE BY APPROVED DELIVERY-MODEL DECISION** — not as failed work.

**Approved operating model, recorded on both `custom_domains` rows as `desired_state`:**
```
registrar-direct DNS → 134.209.93.41 → nginx → certbot-managed TLS → application
approved_by: Mark   approved_on: 2026-08-02
cloudflare_saas: not applicable by approved delivery-model decision
```
Cloudflare SaaS mutation gate remains **CLOSED**. No broader Cloudflare credentials
requested or installed for these two domains.

### 0.1 Emergency nginx safety repair (authorised)

A latent total-outage trigger was found while executing S2 and repaired under
Decision 2. `/etc/nginx/sites-available/levelup-staging` lines 145–146 — inside the
main HTTPS block serving levelupgrowth.io, staging, the platform and PTAA — pointed at
`/etc/letsencrypt/live/rehearsal.levelupgrowth.io/`, a certificate that never existed
(written by certbot during the 2026-07-29 provisioning rehearsal; no `live/`, no
`archive/`, no renewal config). nginx had run since 2026-07-22 on its older in-memory
config, so every site was up — but the next reload, restart or reboot would have failed
to start nginx and taken all of them down together.

| | Before | After |
|---|---|---|
| `nginx -t` | **configuration file test FAILED** | **test is successful** |
| master PID | 846022 | 846022 (reload, **not** restart) |
| Surfaces healthy | 6/6 | 6/6 |

Exactly two lines changed, verified by diff against the backup; `server_name`, upstreams,
locations, redirects, PTAA routing and all unrelated SSL directives untouched. Backups at
`/root/levelup-staging.nginx.{bak,prerepair,prewww}-*`.

### 0.2 Chef Red www completion

`www.chefredraymundo.com` was down (Cloudflare edge refused the TLS handshake —
alert 40, no certificate offered; HTTP 409). Mark repointed DNS at the origin; the
certificate was then expanded.

```
Domains    : chefredraymundo.com www.chefredraymundo.com
Expiry     : 2026-10-31 (89 days)   Issuer: Let's Encrypt YR2
SANs       : DNS:chefredraymundo.com, DNS:www.chefredraymundo.com
Dry run    : "The dry run was successful" (both names, nginx authenticator)
```

Verified externally in a real browser: `https://www.chefredraymundo.com/` → **301** →
apex, renders the site, valid TLS. Deliberate redirect, matching the intended nginx block.

**A verification caveat worth recording:** immediately after the fix, on-server checks
still reported `000`/`409` for www. That was the droplet's own resolver cache still
holding the previous Cloudflare IPv6 addresses — not a failure of the fix. Forcing
resolution to the origin, and flushing the cache, both returned `301` over valid TLS.
A green *or red* result from a single vantage point is not proof; the vantage point has
to be checked too.

### 0.3 Final surface matrix

| Surface | Status |
|---|---|
| https://levelupgrowth.io | 200 |
| https://staging.levelupgrowth.io | 200 |
| https://platform.levelupgrowth.io | 200 |
| https://levelupgrowth.io/ptaa | 301 (unchanged) |
| https://chefredraymundo.com | 200 |
| **https://www.chefredraymundo.com** | **301 → apex (was DOWN)** |
| https://amgtravelandtours.com | 200 |
| https://www.amgtravelandtours.com | 200 |

`nginx -t` successful · nginx active (pid 846022) · `certbot.timer` enabled · routes 1040
· 0 rows claiming a Cloudflare provider · **zero customer downtime**.

---

---

## 1. Executive summary

INFRA888 is now truthful about the two live customer domains. Both are represented,
correctly attributed, and linked to the S1 estate. Zero customer impact.

Activation did **not** proceed, for a reason that was discovered rather than assumed:
**both domains are already working correctly, and neither is on Cloudflare.** They run
registrar-direct DNS to our origin with certbot-managed certificates that auto-renew.
Moving them to Cloudflare for SaaS would introduce risk, cost and lock-in to replace a
mechanism that currently has no fault.

The Cloudflare credential we hold is also **not capable** of custom-hostname work. That
is now proven rather than suspected.

One genuinely broken thing was found: **`www.chefredraymundo.com` is down** and has been
independently of this phase.

---

## 2. Current-state forensic evidence (Stage A)

Observation only. No mutation of DNS, certificates, nginx, Cloudflare or `websites`.

| Fact | chefredraymundo.com | amgtravelandtours.com |
|---|---|---|
| Workspace / website | 2 / 3 | 26 / 62 |
| Authoritative NS | ns47/ns48.domaincontrol.com (**GoDaddy**) | ns27/ns28.domaincontrol.com (**GoDaddy**) |
| Apex A | 134.209.93.41 (our origin) | 134.209.93.41 (our origin) |
| Apex TTL | 1799s | 3599s |
| AAAA | none | none |
| www | CNAME → chef-red.levelupgrowth.io → 188.114.96.0/97.0 (**Cloudflare edge**) | A → 134.209.93.41 |
| **MX** | **none** | **none** |
| TXT | google-site-verification | none |
| CAA | none | none |
| Edge proxied | no (no `cf-ray`) | no (no `cf-ray`) |
| HTTP | 301 → HTTPS | 301 → HTTPS |
| HTTPS apex | **200** | **200** |
| Cert issuer | Let's Encrypt / YR1 | Let's Encrypt / YR2 |
| Cert SANs | apex **only** | apex **+ www** |
| Cert expiry | 2026-10-04 | 2026-09-26 |
| Renewal | certbot on origin (`certbot.timer` enabled) | certbot on origin (`certbot.timer` enabled) |
| Origin server | nginx/1.18.0 (Ubuntu) | nginx/1.18.0 (Ubuntu) |
| nginx vhost | `/etc/nginx/sites-available/levelup-staging` | same |

**No MX records on either zone.** No email depends on this DNS. This materially lowers
the risk of any future DNS change — but it does not make it zero, and it is recorded as
an observation with a timestamp, not a permanent guarantee.

**Registrar/DNS control:** both zones are at GoDaddy. **Whether LevelUp controls those
GoDaddy accounts is unproven.** This is a declared stop condition (§9).

---

## 3. Existing delivery model (Stage B)

Both hostnames use **Model 1 — registrar-direct DNS → origin IP → nginx → certbot.**
Neither uses Cloudflare proxying, and neither uses Cloudflare for SaaS.

```
Browser
  → GoDaddy DNS (A → 134.209.93.41)
  → origin directly (no edge)
  → nginx/1.18.0 terminates TLS  ← SSL terminates HERE
  → Laravel / Builder
  → website
```

**TLS terminates at our own nginx. Renewal is owned by certbot on the droplet.** There is
no third party in the request path.

The one exception is `www.chefredraymundo.com`, which takes a different path and fails —
see §12.

---

## 4. Adoption results (Stage C)

New command: `app/Console/Commands/AdoptCustomDomainsCommand.php`

```
php artisan infra:adopt-custom-domains [--dry-run] [--confirm=] [--workspace=]
```

Dry-run mandatory; confirmation token derived from the plan (`682a03a2dc8e5f42594297ea`);
workspace-scopable; idempotent.

Adoption **measures** live state on every run — DNS resolution and one TLS handshake — and
records it with `last_checked_at`. It does not accept assumptions.

**Result:**

| id | ws | website | hostname | provider | state | ownership | ssl | routing |
|---|---|---|---|---|---|---|---|---|
| 1 | 2 | 3 | chefredraymundo.com | `origin_nginx_certbot` | connected | verified | active | registrar_dns_direct_origin |
| 2 | 26 | 62 | amgtravelandtours.com | `origin_nginx_certbot` | connected | verified | active | registrar_dns_direct_origin |

**The `provider` column defaults to `cloudflare_saas` in the schema.** Accepting that
default would have written a provider binding that does not exist. It is set explicitly.

Honesty flags persisted on every row:

```
custody                   : managed
managed_by_infra888       : false
provisioned_by_infra888   : false
provisioning_source       : builder_platform
dns_controlled_by_infra888: false
ssl_automated_by_infra888 : false
original_system_of_record : websites.custom_domain
desired_state             : null      ← observed state is stored separately
```

**Estate linkage (S1 → S2), no duplicates created:**

| asset | ws | type | name | mode | health | provider_id | source | → hosted-site asset |
|---|---|---|---|---|---|---|---|---|
| 37 | 2 | domain | chefredraymundo.com | adopted | healthy | NULL | custom_domain:1 | 34 |
| 38 | 26 | domain | amgtravelandtours.com | adopted | healthy | NULL | custom_domain:2 | 36 |

`provider_id` is NULL on both: **we hold no provider binding for these domains.**

---

## 5. Target delivery model recommendation (Stage E)

### Recommendation: **Option A — keep registrar-direct DNS + nginx + certbot; bring under INFRA888 observation. Do NOT migrate these two live domains to Cloudflare now.**

| Criterion | Option A (keep + observe) | Option C (Cloudflare for SaaS) |
|---|---|---|
| Zero-downtime feasibility | **No change at all** | Apex cutover on live customer traffic |
| **Apex CNAME feasibility** | n/a | **GoDaddy does not support CNAME/ALIAS at apex** — blocking |
| Certificate automation | Already automated (certbot, timer enabled) | Automated by CF, but replaces a working system |
| Customer DNS control | Customer keeps registrar control | Requires customer DNS edits |
| Cloudflare account ownership | Not required | Required, unproven |
| Cost | £0 | Per-hostname SaaS billing; zone is **Free plan** |
| Rollback | Nothing to roll back | DNS TTL-bound, 30–60 min exposure |
| Vendor lock-in | None | Meaningful |
| Fixes an actual fault? | — | **No fault exists to fix** |

The decisive technical point: **the apex A record cannot become a CNAME on GoDaddy DNS.**
Cloudflare for SaaS routes custom hostnames via a CNAME to a routing target. For these two
apex domains that path is not available without moving nameservers to Cloudflare — which
is a far larger change than S2 contemplates and requires customer authorisation.

Cloudflare for SaaS remains the right model for **future** customer domains onboarded
through the Domains product, where we control the onboarding flow from the start. It is
the wrong tool for two working apex domains today. Cloudflare code existing is not a
reason to migrate.

---

## 6. Cloudflare adapter review (Stage D)

- **Class:** `app/Services/Domains/Providers/CloudflareSaasProvider.php`
- **Contract:** `app/Connectors/Infrastructure/Contracts/CustomHostnameConnector.php`
- **Lifecycle implemented:** `createCustomHostname`, `getCustomHostname`, `listCustomHostnames`, `verifyCustomHostname`, `deleteCustomHostname`, `getValidationInstructions`, `getCertificateStatus`
- **Model:** genuine Cloudflare for SaaS **Custom Hostnames** (not ordinary DNS records)
- **Auth:** bearer API token; reads `cloudflare.{zone_id, account_id, api_token, routing_target, ssl_validation_method}`
- **Currently selected connector:** `NullCustomHostnameConnector` via `INFRA_CUSTOM_HOSTNAME_CONNECTOR`
- **Why Null:** deliberate. `config/cloudflare.php` carries `saas_enabled` as a **production mutation gate**, currently **false**. This is correct and was left untouched.
- `config/services.php` is absent; Cloudflare config lives in its own `config/cloudflare.php`. Not a defect.

---

## 7. Read-only Cloudflare validation (Stage G)

New command: `app/Console/Commands/ValidateCloudflareCommand.php` — `infra:validate-cloudflare [--json]`.
**Every call is a GET. No custom hostname was created. No DNS record was written.**
Secrets are never printed; credentials appear only as `sha256:` fingerprints.

```
[PASS] config.token_present        present, fingerprint sha256:8a38cd98bd8c
[PASS] config.zone_id_present      present, sha256:8cd48f2de957
[FAIL] config.account_id_present   CLOUDFLARE_ACCOUNT_ID is not set — required for Cloudflare for SaaS
[PASS] config.saas_gate_closed     saas_enabled=false — production mutation gate is CLOSED
[PASS] token.verify                token status: active (HTTP 200)
[PASS] account.read                0 account(s):                    ← token has no account-level visibility
[PASS] zone.read                   zone levelupgrowth.io · status active · plan Free Website
[PASS] zone.active                 zone status: active
[PASS] dns.read                    readable · 17 record(s) in zone
[FAIL] saas.custom_hostnames.read  INSUFFICIENT_SCOPE: needs Zone:SSL and Certificates:Read
[FAIL] saas.fallback_origin        HTTP 403
```

The validator distinguishes credential failure, insufficient scope, wrong account, wrong
zone, hostname conflict, missing custom origin, unsupported plan/feature, and transient
provider failure — because "Cloudflare didn't work" is not an actionable answer.

**Conclusion: the token is valid but zone-scoped and cannot perform custom-hostname work.
No fallback origin is configured. The zone is on the Free plan. Cloudflare is not ready.**

---

## 8. Reconciliation evidence (Stage H)

| Check | Required | Actual |
|---|---|---|
| One hostname identity | yes | 2 hostnames, 2 rows, no duplicates |
| One workspace owner each | yes | ws 2, ws 26 |
| One active website relationship | yes | websites 3, 62 |
| Conflicting custom-domain record | none | none |
| Provider orphan | none | 0 provider bindings held |
| Unmanaged duplicate | none | none |
| False SSL status | none | 0 rows claim INFRA888 automates SSL |
| False Cloudflare status | none | **0 rows claim a Cloudflare provider** |

Conflict handling **fails closed before any write**: a hostname claimed by a second live
website, or already recorded under a different workspace or website, aborts the entire run.

---

## 9. Stop conditions reached

Activation was **not** attempted. Three declared stop conditions are live:

1. **DNS/registrar ownership is unclear.** Both zones are at GoDaddy; LevelUp's control of
   those accounts is unproven. Customer-domain DNS is not ours to assume.
2. **Cloudflare token has insufficient scope**, no account ID, no fallback origin, Free plan.
3. **An existing certificate renewal path would be broken.** certbot currently auto-renews
   both certificates. Cutting to Cloudflare abandons a working renewal mechanism.

Per the phase rules, I stopped and preserved evidence rather than proceeding.

---

## 10. Pre-existing defect found: `www.chefredraymundo.com` is DOWN

```
www.chefredraymundo.com  →  CNAME chef-red.levelupgrowth.io
                         →  188.114.96.0 / 188.114.97.0  (Cloudflare edge, levelupgrowth.io zone)
                         →  HTTPS: 000  (connection/TLS failure)
```

**Cause:** `www` points into the Cloudflare-proxied `levelupgrowth.io` zone, which holds no
certificate for `chefredraymundo.com`. The origin's certificate covers the apex **only**
(SAN: `chefredraymundo.com`), and no `www` certificate exists on the origin. TLS therefore
cannot complete at either end of that path.

**Classification: pre-existing.** Not introduced by S2 — adoption performed no DNS, nginx or
certificate change, and the apex was 200 before and after. `www.amgtravelandtours.com`
returns 200 and is unaffected.

**Fix (needs authorisation, not attempted):** point `www.chefredraymundo.com` at
134.209.93.41 like the apex, and reissue the certificate with both SANs — the exact
configuration `amgtravelandtours.com` already has and which works.

---

## 11. Tests

`--configuration=phpunit.p1e1.xml` → **`levelup_p1e1_test`** (session-isolated; `levelup_test` not used).

```
Tests: 68 passed (261 assertions)   [CustomDomain | CloudflareSaas | DomainPortal | SubdomainService]
```

Proven by execution evidence:

| # | Requirement | Evidence |
|---|---|---|
| 1 | Adoption idempotent | re-run → "Nothing to do"; rows stayed 2 |
| 2 | No provider call | 0 connector/HTTP imports; 0 in executable code |
| 3 | No DNS change | records identical before/after |
| 4 | No website change | `websites` = 3, both rows unchanged |
| 5 | Conflicting workspace fails closed | pre-write conflict gate aborts run |
| 6 | Duplicate hostname refused | idempotence by hostname |
| 7 | Builder mapping preserved | `website_id` 3 / 62 intact |
| 8 | Estate linkage correct | assets 37→34, 38→36 |
| 9 | Custody truthful | all 4 honesty flags false |
| 10 | No false Cloudflare status | 0 rows |
| 11 | Read-only validation, no mutation | GET-only; no hostname created |
| 12–14 | Wrong account / zone / scope detected | distinct classifications returned |
| 24 | Both domains reachable after adoption | apex 200 / 200 |
| 25 | No customer domain activated in tests | none |
| 26–27 | S1 green, routes unchanged | 4 hosted sites, **1040 routes** |
| 28–29 | Production protections; isolated DB | gate CLOSED; `levelup_p1e1_test` |
| 30 | Other sessions untouched | see §13 |

**Not yet automated (activation-dependent):** #15–23 (accepted≠verified, read-back required,
rollback restores routing, customer payload has no Cloudflare identity, admin retains
provider detail, provider/local orphan and DNS drift detection, workspace tenancy under
activation). These cover behaviour that does not exist until a provider model is approved;
writing assertions against unbuilt paths would be theatre. They are the first deliverable
of the activation phase.

---

## 12. Concurrency status

| Observation | Classification |
|---|---|
| `BaselineCommand.php`, `Engineer888/Baseline/*` modified during the phase | **concurrent** — Engineer888 session. Not touched, not reverted. |
| 3 phpunit processes running | **concurrent** — isolated DB used to avoid collision |
| Both new command files | **this session** — new files, no existing target overwritten |
| `custom_domains` empty at start | **pre-existing** |
| `config/cloudflare.php`, connector selection | **pre-existing** — read only, not modified |
| `www.chefredraymundo.com` failure | **pre-existing** — predates this phase |

No file was shared with another session. No migration was run. No shared reset performed.

---

## 13. Changes made

**Files added (2, both new — nothing overwritten):**
- `app/Console/Commands/AdoptCustomDomainsCommand.php`
- `app/Console/Commands/ValidateCloudflareCommand.php`

**Files modified:** none.
**Migrations run:** none.
**Routes added:** none (1040 → 1040).

**Database:**
- `custom_domains` 0 → 2 (insert only)
- `infra_assets` 36 → 38 (2 domain assets)
- `websites` unchanged
- S1 tables unchanged

**Provider changes:** **none.** No Cloudflare mutation. `saas_enabled` still `false`.
**Infrastructure changes:** none — no DNS, nginx, certificate or nameserver change.

---

## 14. Final state

| Item | State |
|---|---|
| Domains adopted | 2 of 2 (100%) |
| Hostname conflicts | 0 |
| Delivery model (both) | registrar_dns_direct_origin (GoDaddy → origin → nginx → certbot) |
| Recommended target model | **Option A — keep and observe** |
| Provider bindings held | 0 |
| Cloudflare credentials | valid token, **insufficient scope**, no account ID, no fallback origin, Free plan |
| SaaS mutation gate | **CLOSED** |
| First hostname activated | **none — blocked at approval boundary** |
| Second hostname activated | not started (correctly gated behind the first) |
| Customer impact | **zero** — apexes 200 before and after |
| Regression | none — 1040 routes, 68 tests green |

---

## 15. Remaining blockers — human action required

1. **Approve the delivery model (Mark).** Recommendation: Option A. If approved, S2's
   activation stages close as *not applicable by decision*, and monitoring becomes the
   remaining work.
2. **Cloudflare readiness — only if Option C is chosen instead.** Requires: a scoped token
   with `Zone:SSL and Certificates:Edit`, `CLOUDFLARE_ACCOUNT_ID`, a configured SaaS
   fallback origin, and a zone plan entitled to Cloudflare for SaaS. Credentials must be
   installed by hidden input on the server — **do not paste them into chat.**
3. **Confirm registrar/DNS ownership** for chefredraymundo.com and amgtravelandtours.com.
   No customer-domain DNS change should be contemplated until this is established in writing.
4. **Authorise the `www.chefredraymundo.com` fix** (§10). A customer-facing hostname is
   currently down. This is the highest-value action available in this lane and is
   independent of every Cloudflare decision.

---

## 16. Monitoring and reconciliation — status

Adoption re-measures DNS, routing and certificate state on every run and stamps
`last_checked_at`, so `--dry-run` already functions as a drift check. A scheduled refresh
that ages stale observations back to `unknown` — so nothing stays "healthy" on the strength
of a measurement taken weeks ago — is designed but **not yet scheduled**, because the
refresh cadence depends on which delivery model is approved. It is the first work item
once §15.1 is answered.
