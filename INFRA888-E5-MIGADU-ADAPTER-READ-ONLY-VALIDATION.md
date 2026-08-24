# INFRA888 — E5: First Business Email Provider Adapter (Migadu), Read-Only Validation

**Milestone:** E5 — first vendor adapter + read-only validation
**Date:** 2026-08-05
**Environment:** `/var/www/levelup-staging` (the single LevelUp Laravel; serves `levelupgrowth.io` and live customer domains)
**Result:** **PASS with one blocking dependency** — the adapter is complete, proven and contained; **live read-only validation could not be performed because no LevelUp Growth Migadu account exists.**

> **This document is internal.** It names the vendor throughout, which is permitted here and in `app/Connectors/Infrastructure/Email/Migadu/` and nowhere else.

---

## 1. E1–E4 baseline

| Milestone | Established | Changed by E5 |
|---|---|---|
| **E1** | 6 tables, 6 models, 7 state machines, capability registry, engine skeleton | no |
| **E2** | `EmailProviderConnector` (27 methods), 20 capability flags, `ProviderOutcome`, `EmailFlightPlan`, reconciliation, `FakeEmailProvider` | **contract unchanged** |
| **E3/E3.1** | 8 admin screens, 12 reads + 16 actions, capability-gated admin access | no |
| **E4** | Customer portal: 11 routes, 10 reads, 14 actions, 8 screens, customer projections | no |

**The E2 contract was not modified.** Every Migadu limitation is expressed through `supports()` and the capability matrix — the mechanism E2 built for exactly this. Nothing was weakened to fit the vendor.

---

## 2. Current official Migadu findings

Verified **2026-08-05** from official sources only (`migadu.com/api/`, `migadu.com/pricing/`, `migadu.com/terms/`, `migadu.com/guides/`). The July planning document was **not** treated as a current source of vendor fact.

| Item | Verified finding |
|---|---|
| API base URL | `https://api.migadu.com/v1/` |
| Authentication | HTTP Basic — username = account email, password = API key from *My Account → API Keys* |
| Content type | `application/json` |
| Documented status codes | **200, 400, 422 only** |
| Pagination | **Not documented** |
| Rate limits | **Not documented** |
| Plan restrictions on API | **Not documented** — no tier is stated to exclude API access |
| Domain endpoints | list, get, create, **patch**, `/records`, `/diagnostics`, `/activate`, `/usage` |
| Domain deletion | **Deliberately not exposed** — "we do not expose the one for domain deletion, as that action is irreversible" |
| Mailbox object | `local_part, domain, address, name, is_internal, wildcard_sender, may_send, may_receive, may_access_imap, may_access_pop3, may_access_managesieve, password, password_recovery_email, password_method, spam_*, sender_denylist, sender_allowlist, recipient_denylist, footer_*` |
| Mailbox **has no** | `is_active`, `storage_usage`, per-mailbox `quota` |
| Alias object | `local_part, domain, address, is_internal, destinations` — **"aliases can redirect only on the same domain"** |
| Forwarding object | `address, blocked_at, confirmation_sent_at, confirmed_at, expires_on, is_active, remove_upon_expiry` — nested **under a mailbox** |
| Rewrite object | `domain, name, local_part_rule, order_num, destinations` — "aliases that listen on predefined patterns" |
| API maturity | Self-described as **"still in early beta and not all functionalities are exposed"** |
| **Standard plan** | **$29/month or $290/year** — 100 GB storage, unlimited domains, unlimited mailboxes, 3,000 inbound/day, **500 outbound/day** |
| Other plans | Micro $19/yr (5 GB) · Mini $90/yr (30 GB, 100 out/day) · Maxi $990/yr (500 GB) |
| Pricing model | Flat per account; additional addresses cost nothing. **Consumption-based, not per-mailbox.** |
| Reseller / white-label terms | **Silent.** No clause permits or prohibits resale, sublicensing or third-party commercial use. Agencies are named as a customer segment and one account manages all domains. |

### Contradictions with prior planning documents

Reported rather than absorbed:

1. **Masterplan §9 claimed "Rename mailbox: varies".** Verified: **no rename operation exists.** Mailboxes are keyed by `local_part`; renaming would be create-plus-delete, which loses stored mail.
2. **Masterplan §9 claimed "Catch-all: yes".** Verified **true**, but implemented as a *rewrite* with pattern `*`, not as a catch-all field.
3. **Masterplan §13 risk 7 records a standing gate:** *"no Migadu adapter until Domains is production-ready"*. Your E5 directive authorises this work; the gate is noted as superseded rather than silently ignored.
4. **The ToS silence on resale** is a business question, not a technical one — see §27.

**None of these required a contract change, so no stop was triggered on that ground.**

---

## 3. Account / subscription status

Inspected: environment key names, `infra_providers`, `infra_provider_credentials`, `infra_provider_connections`, the local keys folder, every INFRA888 and PTAA document, and all repository config.

| Question | Answer |
|---|---|
| Account found | **NO** |
| Account owner | n/a |
| Subscription found | **NO** |
| Plan | n/a |
| Credential present | **NO** — `infra_provider_credentials` holds **0 rows** |
| Credential type | n/a |
| Authentication verified | **NO — could not be attempted** |

Every reference to Migadu in the repository and documents is forward-looking (*"Create Migadu account"*), and PTAA plan decision **D1 (approve + who pays)** remains open. The only `infra_providers` row is an unrelated Cloudflare certification entry.

**Per E5-B this is a hard stop for live validation.** Everything not requiring an account was completed.

---

## 4. Signup / credential actions required from you

Nothing was purchased and no account was created. This is the exact block:

1. **Signup URL** — `https://admin.migadu.com/public/signup`
2. **Account / business name** — `LevelUp Growth`
3. **Account email convention** — a role address LevelUp controls permanently, not a personal one. Recommended: `infrastructure@levelupgrowth.io` (or `ops@`). **Never** a customer address, and never `marlon@ptaa.org.ph`-style personal ownership — the PTAA audit already shows what happens when a vendor account is registered to someone who leaves.
4. **Recommended plan** — **Standard**
5. **Billing period** — **Yearly**
6. **Verified current price** — **$290/year** (or $29/month). Verified 2026-08-05.
   *Why Standard:* storage is not the constraint — PTAA is 10.82 GB of 100 GB. **Outbound volume is.** Mini allows 100 sends/day across 27 mailboxes (~4 each); Standard allows 500 (~18 each).
7. **Payment required now** — **Yes**, to activate the plan. A free trial is not part of this recommendation.
8. **API credential creation** — log in → **My Account → API Keys** → generate a new key. It is shown **once**.
9. **Required scopes** — the vendor issues **one key type with full account access**. There is **no read-only scope**. See §7 for how E5 enforces read-only anyway.
10. **Where credentials must be stored** — `infra_provider_credentials`, encrypted at rest via the existing pattern, with a fingerprint and a hint. **Never** in `.env`, the repository, a screenshot, a document, or shell history. Interim storage only in `C:\Users\markr\Staging\keys\`.
11. **What to send back — without the secret**
    - the account email you registered
    - the plan and billing period actually purchased
    - the **first 4 and last 4 characters** of the API key only
    - confirmation the key was created with **My Account → API Keys**
    - the key itself delivered separately by whichever channel you prefer — I will install it directly into the encrypted credential store and never write it anywhere else.

**Do not send the full key in chat.**

---

## 5. Provider directory architecture

Exactly one directory names the vendor:

```
app/Connectors/Infrastructure/Email/Migadu/
├── MigaduEmailProviderConnector.php   the 27 contract methods
├── MigaduClient.php                   the only socket in the platform
├── MigaduRequestFactory.php           paths and payloads
├── MigaduResponseMapper.php           provider payload → neutral value objects
├── MigaduErrorClassifier.php          provider outcome → platform semantics
├── MigaduCapabilityMap.php            the verified matrix, with evidence
├── MigaduConnectorFactory.php         construction + layered gates
└── MigaduTransportException.php       internal; translated before it escapes
```

Provider-specific DTOs do not exist: the adapter maps straight into E2's neutral value objects, so nothing provider-shaped can travel upward. `MigaduTransportException` never leaves the adapter — every public method translates it into a `ProviderResult`.

---

## 6. HTTP client

`MigaduClient` — TLS verification always on, redirects refused (a redirect would re-send credentials elsewhere), 10s connect / 30s total timeout, 2 MB response ceiling, JSON validation, correlation id per request, structured logging.

**Retry policy — the rule the architecture exists for:**

| Call type | Retries | Rationale |
|---|---|---|
| Read | 2, 250 ms apart | nothing changed; retrying is free |
| **Mutation** | **never** | a retried `POST` is how a customer gets four mailboxes and one invoice line |

Logging is provider-neutral and personal-data-free: paths are reduced to shape before writing, so `domains/acme.test/mailboxes/ceo` is logged as `domains/{domain}/mailboxes/{mailbox}`. Rate-limit headers are parsed from an allowlist; everything else is dropped rather than filtered.

---

## 7. Credential model

Reuses `infra_providers` / `infra_provider_credentials` / `infra_provider_connections`. **No provider-specific credential table was created.**

Encrypted at rest (`secret_encrypted` cast `encrypted`, `$hidden` on the model), fingerprinted, hinted, environment-scoped, rotatable via the existing supersedes chain, and never returned after creation. `MigaduConnectorFactory::status()` reports installation state and is guard-tested to expose no secret field.

**The read-only limitation, stated plainly:** the vendor issues one key type with full account access. **Read-only cannot be enforced at the credential.** E5 therefore enforces it inside INFRA888 via the mutation gate (§below), which is a weaker guarantee than a scoped key and is recorded as such.

---

## 8. Final capability matrix

**15 of 20 supported. All 5 required capabilities present → the provider is viable.**

| Capability | Supported | API | Limitation |
|---|---|---|---|
| `domain.onboard` | ✅ | `POST /domains` | domain *deletion* is not exposed at all |
| `domain.verify` | ✅ | `GET /domains/{d}/diagnostics` | activation is a separate call |
| `domain.dkim.rotate` | ❌ | — | no documented endpoint |
| `mailbox.create` | ✅ | `POST /domains/{d}/mailboxes` | — |
| `mailbox.update` | ✅ | `PUT …/{local_part}` | display name and access flags only |
| `mailbox.rename` | ❌ | — | `local_part` is the identity; rename = create+delete, which loses mail |
| `mailbox.suspend` | ⚠️ | `PUT …/{local_part}` | **emulated** by clearing five access flags |
| `mailbox.restore` | ⚠️ | `PUT …/{local_part}` | **lossy** — restores *default* access, cannot recover custom pre-suspension flags |
| `mailbox.delete` | ✅ | `DELETE …/{local_part}` | irreversible |
| `mailbox.quota` | ❌ | — | no per-mailbox quota field; only a domain-level default |
| `mailbox.password_reset` | ❌ | — | no reset operation; only direct password assignment, which E4 forbids |
| `alias.create` | ✅ | `POST /domains/{d}/aliases` | **same-domain destinations only** |
| `alias.delete` | ✅ | `DELETE …/{local_part}` | — |
| `forwarder.create` | ⚠️ | `POST …/mailboxes/{m}/forwardings` | **requires an existing parent mailbox**; destination must confirm |
| `forwarder.delete` | ✅ | `DELETE …/forwardings/{address}` | — |
| `catchall.configure` | ✅ | `POST /domains/{d}/rewrites` | a rewrite with rule `*` |
| `catchall.clear` | ✅ | `DELETE …/rewrites/{name}` | — |
| `usage.sync` | ⚠️ | `GET /domains/{d}/usage` | **domain granularity only** |
| `last_login.read` | ❌ | — | not exposed |
| `inventory.list` | ✅ | list endpoints | forwardings are per-mailbox; bounded fan-out |

Every verdict carries its documentary evidence in `MigaduCapabilityMap`, and a test asserts none is left without it.

### Portability consequences

- **Per-mailbox quota must be hidden for Migadu tenants.** E4's customer UI offers it; on this provider it does not exist.
- **Password reset must be hidden.** There is no honest implementation.
- **Alias-to-external must be refused**, not silently converted into a forwarder.
- **Suspension is not reversible to a custom prior state.**

---

## 9. Contract-method mapping

All 27 methods implemented. Read methods are fully live-capable; every mutating method is implemented, tested against mocked HTTP, and **blocked by the mutation gate throughout E5**.

Two deliberate refusals rather than approximations:

- **`requestPasswordReset`** returns `unsupported`. Implementing it would mean generating and transmitting a mailbox secret — expressly forbidden by E4.
- **`createForwarder`** checks for a parent mailbox first and returns `unsupported` when there is none. It will **not** create a mailbox the caller did not ask for: that would be a billable object appearing from nowhere.

---

## 10. ProviderResult mapping

| Situation | Result |
|---|---|
| Read succeeded and confirmed | `verified` |
| Domain onboarded | `accepted` / `awaiting_dns` — **never verified on the first call** |
| Mailbox created | `accepted` / `provisioning` — verified only by read-back |
| Forwarder created, destination unconfirmed | `accepted` / `awaiting_confirmation` |
| Forwarder created, `confirmed_at` present | `verified` / `active` |
| 422 with DNS wording | `accepted` / `awaiting_dns` — a waiting state, not an error |
| Delete of something already absent | `verified` / `deleted` (converges) |
| Ambiguous mutation | `failed` with `normalizedState = needs_reconciliation`, **`isRetryable() === false`** |
| Capability absent | `failed` / `unsupported` — never retried |

---

## 11. Error taxonomy

| HTTP | Code | Retry (read) | Retry (mutation) |
|---|---|---|---|
| 401 | `provider_auth_failed` | permanent | permanent |
| 403 | `provider_forbidden` | permanent | permanent |
| 404 | `provider_not_found` | permanent | permanent |
| 409 | `provider_duplicate` | permanent | permanent |
| 422 (DNS wording) | `provider_dns_not_ready` | retryable | retryable |
| 422 (other) | `provider_validation_failed` | permanent | permanent |
| 400 (duplicate wording) | `provider_duplicate` | permanent | permanent |
| 400 (limit wording) | `provider_limit_exceeded` | permanent | permanent |
| 400 (other) | `provider_validation_failed` | permanent | permanent |
| 429 | `provider_rate_limited` | retryable | retryable |
| 408 / 504 | `provider_timeout` / **`provider_indeterminate`** | retryable | **ambiguous** |
| ≥500 | `provider_unavailable` | retryable | retryable |
| unknown | `provider_unknown_error` | retryable | **ambiguous** |
| transport, never sent | `provider_unavailable` | retryable | retryable |
| malformed body | `provider_malformed_response` | retryable | **ambiguous** |

**No unknown mutating outcome is ever a safe retry.** The vendor documents only three status codes, so everything else is derived from HTTP semantics rather than invented vendor behaviour.

**No provider text is ever concatenated into an error summary.** An earlier version appended the vendor's own message when it looked short and harmless; the guard caught it immediately, because the vendor's message names the vendor and `errorSummary` is persisted and shown to operators. Provider detail is used to *classify* and then discarded.

---

## 12. Rate-limit handling

The vendor publishes no rate-limit documentation. `Retry-After`, `X-RateLimit-Reset` and `RateLimit-Reset` are parsed when present, in both numeric and HTTP-date form. **An absent header is reported as `null` (unknown), never as zero** — a zero would be read as "retry immediately". 429 is classified retryable; the caller applies its own backoff rather than the adapter inventing a limit from one response.

---

## 13. Pagination

**The vendor documents no pagination.** The adapter therefore does not fabricate a paging scheme. The place enumeration genuinely runs long is forwarder inventory, which is nested per mailbox: a complete list costs one call per mailbox. That fan-out is bounded at 50, and **beyond the bound the inventory is returned as `ProviderInventory::partial()` with a reason** rather than silently truncated — E1's reconciliation refuses to conclude absence from an incomplete list, which is what stops it deleting objects that were merely not fetched.

Pagination behaviour must be re-checked against a real account with more than 50 mailboxes before E6.

---

## 14. Accepted versus verified

Preserved end to end. Domain onboarding is two-phase; mailbox creation is `accepted` until read-back; an unconfirmed forwarder is `accepted` even though the vendor returns `is_active: true`, because **mail does not flow until the destination confirms** and reporting it active would tell a customer their forwarding works while messages go nowhere.

---

## 15. Ambiguous outcomes

`provider_timeout`, `provider_indeterminate` and `provider_malformed_response` on a mutation all resolve to `needs_reconciliation` with `isRetryable() === false`. A gateway timeout on a `POST` may already have been applied upstream; the client distinguishes connect-phase failure (safe — nothing was sent) from post-send timeout (ambiguous) by reading the transport error.

---

## 16. White-label enforcement

Until E5 the rule was free to keep, because no vendor name existed. From E5 it needs enforcing.

`MigaduWhiteLabelGuardTest` — **11 tests, 521 assertions** — proves the vendor is named nowhere outside its own directory, across the engine, contract package, models, migrations, all four Business Email controllers, both route groups, the customer JavaScript, `index.html` and config; that customer surfaces cannot reach the provider layer at all; that provider plan and cost never enter customer entitlements; that both gates are closed; that mutations cannot be enabled by a single flag; that the status report exposes no secret; and that no credential is committed anywhere.

**The one honest exception**, decided in masterplan §2 and tested explicitly: **DNS record *values* may contain provider hostnames** — MX and SPF physically point at the vendor and no abstraction can hide it. The *labels* around them stay neutral.

---

## 17. Live read-only validation

**NOT PERFORMED — no account exists.** All fifteen checks in E5-I remain outstanding and are the first work of E6 once a credential is installed.

**Zero calls were made to the vendor.** Verified three ways:
1. `network_enabled` defaults to false and the client refuses to send.
2. Every test asserts `Http::assertNothingSent()` with a stub registered that *would* have succeeded.
3. A direct probe confirmed `preventStrayRequests()` throws **before any socket opens** — so even a removed kill-switch could not have produced traffic during injection testing.

The vendor host is reachable from the droplet (`api.migadu.com`, TCP 443 open), so this is a property of the code, not of the network.

---

## 18. Before/after provider counts

**Not applicable — no provider state exists to count.** No account, no domain, no mailbox, no alias, no forwarder, no rewrite. **Mutations performed: zero.**

---

## 19. PTAA local manifest validation

Entirely local. Nothing was sent anywhere; PTAA and InMotion were not contacted.

**11 checks pass, 4 need a decision.**

Passing: all 31 addresses parse · none collide with reserved local parts · no duplicate identities · 27/27 mailbox roster complete · every named forwarder has a parent mailbox · no self-forward · **10.82 GB of 100 GB (10.8%)** · all required capabilities present · catch-all correctly stays disabled · **manifest is provider-neutral**.

### The four unresolved decisions

1. **The forwarder roster is incomplete.** 7 of 14 forwarders are counted but never enumerated in the audit. Each unknown source could be a mailbox or an alias-only address — and that distinction decides whether this provider can represent it at all.
2. **The 4 alias-only destinations are unrecorded** (`mike@`, `paulo@`, `ren@`, `zaldy@`). Same-domain → aliases work. **Any external destination cannot be an alias on this provider** and would require creating a mailbox, changing what exists and what is billed.
3. **Every external forward must be CONFIRMED by its recipient before any mail flows.** Three are identified in the audit (the plan says five deliver to personal gmail/yahoo). These are personal mailboxes outside PTAA's control. **Cutover cannot assume confirmation happens in the window — mail to those addresses stops silently until each recipient clicks.** This is new information the July plan does not account for.
4. **11 autoresponders have nowhere to go.** Neither the 20-flag contract nor the vendor's documented mailbox object supports autoresponders. Two of them currently tell senders that a person has left the organisation.

**Chained forwarding flagged:** `babes@ → ptaa@ → info@`, `legalhotline@ → ptaa@ → info@`, `traveltourexpo@ → ptaa@ → info@`.

---

## 20. Pending production migrations

**Not applied. The six E1 migrations remain Pending and the `email_*` tables do not exist in `levelup_staging`.**

| File | Creates |
|---|---|
| `2026_08_04_140501_create_email_domains_table` | `email_domains` |
| `2026_08_04_140502_create_email_mailboxes_table` | `email_mailboxes` |
| `2026_08_04_140503_create_email_aliases_table` | `email_aliases` |
| `2026_08_04_140504_create_email_forwarders_table` | `email_forwarders` |
| `2026_08_04_140505_create_email_catchall_table` | `email_catchall` |
| `2026_08_04_140506_create_email_usage_table` | `email_usage` |

- **Dependency order** — as listed. There are **no foreign-key constraints**; other tables carry `email_domain_id` as a plain column, so ordering is logical rather than enforced. `email_domains` must exist first.
- **Rollback order** — exact reverse: `140506 → 140501`.
- **Additive?** — **Yes.** Zero non-additive operations in any `up()`; the only `dropIfExists` calls are in `down()`, which is correct. No existing table is touched.
- **Lock characteristics** — six `CREATE TABLE` on tables that do not exist. **No lock is taken on any existing data** and no read or write to any other table is blocked. Four carry a generated column (`active_flag`) added by `ALTER` against the empty table just created.
- **Clean install** — proven on every E5 run: `RefreshDatabase` builds the schema from empty before each suite, and `BusinessEmailMigrationReplayTest` runs inside the set.
- **Production preflight** — take a database backup first; confirm no other session holds a migration lock; note that one unrelated migration is also pending and belongs to another workstream (`2026_07_29_130000_add_execution_provenance_to_api_usage_logs`) — running `migrate` would apply it too.
- **Recommendation** — **a separate, explicitly authorised deployment milestone, not an incidental step.** Applying them changes nothing observable while both gates are closed, but it is a production schema change and deserves its own decision and its own backup.

---

## 21. Tests

Dedicated schema `levelup_infra_e5_7c31d9a2_test`, created for this milestone with exclusive access. Explicit file paths only; no substring filters.

| Suite | Tests | Assertions |
|---|---|---|
| `MigaduAdapterTest` | 46 | ~3,000 |
| `MigaduWhiteLabelGuardTest` | 11 | 521 |
| **E5 combined** | **57** | **3,055** |

Covering: capability-map contract · HTTP client · authentication · credential redaction · credential encryption · pagination bound · rate limits · timeout handling · error classification · ProviderResult mapping · every read method · every mutating method through mocked HTTP · idempotency · accepted-vs-verified · ambiguous outcomes · DNS requirement mapping · mailbox/alias/forwarder/catch-all/usage mapping · reconciliation inventory · provider-resource binding · capability support · white-label guards · controlled injection · **no-network architecture proof**.

### Controlled injection — 11 violations, all caught

| # | Injected violation | Round 1 | Round 2 |
|---|---|---|---|
| 1 | vendor name inside the neutral engine | **caught** | — |
| 2 | vendor name on the customer surface | **caught** | — |
| 3 | customer controller reaches the provider registry | **caught** | — |
| 4 | provider plan name in customer entitlements | **caught** | — |
| 5 | provider wording appended to an error summary | **MISSED** | **caught** |
| 6 | mutation gate removed | **caught** | — |
| 7 | network kill-switch removed | **MISSED** | **caught** |
| 8 | mutations made retryable | **caught** | — |
| 9 | password sent on mailbox create | pattern failed | **caught** |
| 10 | unsupported capability marked supported | pattern failed | **caught** |
| 11 | mutations no longer require the network gate | — | **caught** |

Baseline 57 passed → each injection produced 1–2 failures → final verify **57 passed**, every file restored **byte-identical** (sha256 verified).

**Two guard holes were found by injection and fixed — that is the point of doing it:**

- **#5** — the leak test sampled nine status codes and **400 was not among them**, which was exactly the branch the defect lived in. A leak test that samples statuses tests the sampling. It now covers every status 400–431 plus 5xx, against four body shapes, in both mutating modes.
- **#7** — with no stub registered, a *blocked stray request* and a *working kill-switch* produce an identical `ProviderResult`, so the test passed whether or not the switch existed. It now registers a stub that would succeed, making the switch the only thing preventing a recorded request.

---

## 22. Regression evidence

**Business Email, E1 through E5 — 415 passed, 11,070 assertions, RC=0. Zero failures.**

**Wider INFRA888 — 486 passed, 2 failed, 1 skipped, 1,732 assertions.** The two failures are the documented pre-existing baseline and nothing else:

```
Tests\Feature\Infrastructure\RegistrationDriftTest
  Unresolvable dependency resolving [Parameter #0 [ <required> string $environment ]]
  in class App\Connectors\Infrastructure\Namecheap\NamecheapClient
```

That is the Namecheap registrar workstream, blocked on sandbox credentials. **The baseline was reproduced exactly — two failures, one skip — and was not changed, suppressed or absorbed. Regression did not worsen.**

**Fresh migration replay:** `BusinessEmailMigrationReplayTest` sits inside the 415 and passed. `RefreshDatabase` additionally rebuilds `levelup_infra_e5_7c31d9a2_test` from empty before each suite, so all 415 ran against freshly replayed migrations. No standalone `migrate:fresh` was issued — the default connection on this box still resolves to `levelup_staging`, and this platform lost a production database to exactly that assumption on 30 July 2026.

### Two guards narrowed, not suppressed

Both asserted *"no vendor adapter directory exists — the first one is E5"*. E5 is that milestone, so each was retargeted at the rule it was protecting:

- `BusinessEmailE2GuardTest::test_the_fake_lives_only_in_the_test_tree` now walks the vendor tree and asserts **the fake is not referenced from any of it**, rather than asserting the tree is absent.
- `BusinessEmailWhiteLabelGuardTest` now asserts **a vendor adapter may exist only one directory deep under the permitted root**, that the root contains no loose files, and that each vendor directory is a real connector package.

Both still fail if vendor code appears anywhere it should not. This is the same narrowing E3 applied to E1's version and E4 applied to E2's.

---

## 23. Files created / modified

**Created** — 8 adapter classes under `app/Connectors/Infrastructure/Email/Migadu/`, plus `tests/Feature/Infrastructure/Email/MigaduAdapterTest.php`, `tests/Feature/Infrastructure/Email/MigaduWhiteLabelGuardTest.php`, and `phpunit.e5.xml`.

**Modified** — `config/business_email.php` only (added the `provider` gate block).

**No E1–E4 source file was changed. The E2 contract was not touched.** Nothing belonging to Engineer888, Studio, Builder, CRM, Billing, Platform Events, the Notification Engine or the AI Runtime was modified.

---

## 24. Production impact

**None observable.**

- Both feature gates closed; both provider gates closed.
- `config('infrastructure.connectors.email')` is **unset**, so the resolver still throws for `email` — the adapter is loadable but is not the email connector for anybody.
- No workspace assignment, no domain assignment, no credential, no scheduler, no queued job.
- No network call is possible: `network_enabled` is false and the client refuses to send.
- `FakeEmailProvider` remains test-only and refuses to construct in production.
- The six migrations remain pending; the tables do not exist.

**Six independent controls stand between this code and live provisioning:** foundation gate → admin/customer feature gates → provider connection state → workspace/domain assignment → **network gate** → **mutation gate**. A test asserts that enabling mutations alone achieves nothing.

---

## 25. PTAA impact

**None.** PTAA was not provisioned, migrated, contacted or modified. No InMotion reconnection. No DNS record anywhere was created, changed or deleted. The manifest exists only as a local JSON file containing addresses and counts — no provider data, no secrets.

---

## 26. Parallel-session classification

Checked before every write: git status, file hashes and mtimes, active PHP/PHPUnit/Artisan processes, and test-database connections.

- **Owned by this session (E1–E5):** everything under `app/Engines/Infrastructure/Email/`, `app/Connectors/Infrastructure/BusinessEmail/`, `app/Connectors/Infrastructure/Email/Migadu/`, the four Business Email controllers, both route files, `config/business_email.php`, the six migrations, `public/app/js/business-email.js`, and the Business Email test files.
- **Owned by other sessions and untouched:** ~30 modified files including `RuntimeClient`, `AgentDispatchService`, `StripeService`, `ArthurService`, `StudioAiService`, `AdminController`, `BellaController`, `AppServiceProvider`, `bootstrap/app.php`, `config/infrastructure.php`, `config/studio.php`, `phpunit.e888.xml` and the Engineer888 tree.
- **Conflicts:** **none.** No Migadu path existed before this session; no other session touched a Business Email file.

---

## 27. Limitations

1. **No live validation.** Everything is proven against mocked HTTP and official documentation. Real timing, real error vocabulary, real pagination and real rate limits remain unmeasured.
2. **The API is self-described as "early beta"** and states not all functionality is exposed. The matrix is a dated snapshot, not a permanent truth.
3. **No read-only credential scope exists.** Read-only is enforced by our own gate, which is weaker than a scoped key.
4. **Suspension is emulated and restore is lossy** — it cannot recover custom pre-suspension access flags.
5. **Per-mailbox storage and quota are unavailable.** The customer surface must hide both for this provider.
6. **Autoresponders are unsupported by both the contract and the vendor**, and PTAA has 11.
7. **External forwarding requires recipient confirmation** — a real migration risk for PTAA that the July plan does not account for.
8. **Domain deletion is not exposed by the API at all.** Customer offboarding needs a manual operator step, which no current flow describes.
9. **The ToS is silent on resale and white-labelling.** Nothing prohibits it, nothing permits it. Given that LevelUp is contractually the provider and will bill PTAA, this is worth a direct question to the vendor before customer number two — it is a commercial risk, not a technical one.
10. **Pagination is unverified** and must be re-checked against an account with more than 50 mailboxes.

---

## 28. E6 entry conditions

1. A LevelUp Growth Migadu account exists on Standard, paid, owned by a role address.
2. An API key is installed in `infra_provider_credentials`, encrypted, fingerprinted — never in `.env` or the repository.
3. `network_enabled` turned on **for a supervised read-only run only**, and the fifteen E5-I live checks completed with before/after counts identical.
4. A decision on the six pending migrations, taken as its own deployment step with a backup.
5. PTAA decisions 1–4 in §19 answered — particularly the alias-only destinations and the external-forward confirmation problem.
6. A disposable domain LevelUp owns, for first supervised provisioning. **Not PTAA.**
7. A rollback path agreed for a domain that has already published DNS.
8. A decision on the resale/ToS question in §27.

---

## 29. Honest readiness score

**Adapter engineering: 9 / 10.** All 27 contract methods, 15/20 capabilities verified against official documentation with evidence recorded per verdict, error taxonomy complete, ambiguity handled correctly, containment proven by injection — including two guard holes that injection found and that are now closed.

**Verified against the real provider: 0 / 10.** Not one byte has been exchanged with the vendor. Every behaviour above is inferred from documentation the vendor itself calls early beta. **This number cannot move until an account exists**, and no amount of further code changes that.

**Safe to leave deployed as-is: 10 / 10.** Six independent controls, no credential, no connector registration, no schema. The code is inert by construction.

**Ready for PTAA: 4 / 10.** The adapter could carry PTAA's mailboxes and aliases. It cannot carry their autoresponders at all, their external forwards depend on third parties clicking a confirmation link, and half their forwarder roster has never been enumerated.
