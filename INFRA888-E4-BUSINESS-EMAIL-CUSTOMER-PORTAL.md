# INFRA888 — E4: Business Email Customer Portal

**Milestone:** E4 — Customer Portal
**Date:** 2026-08-05
**Environment:** `/var/www/levelup-staging` (the single LevelUp Laravel; `APP_ENV=staging`, serves `levelupgrowth.io` and live customer domains)
**Provider:** deterministic fake only. No vendor adapter, no credential, no external call.
**Result:** **PASS**, with the customer surface **switched off** on the shared environment.

---

## 1. E1–E3 baseline

E4 builds on three completed milestones and redesigns none of them.

| Milestone | What it established | Touched by E4 |
|---|---|---|
| **E1 — Foundation** | 6 tables (`email_domains`, `email_mailboxes`, `email_aliases`, `email_forwarders`, `email_catchall`, `email_usage`), 6 models, 7 state machines, the capability registry, the engine skeleton. Reuses `infra_operations` / `infra_events` / `infra_provider_resources` / `infra_observation_facts` rather than growing a parallel spine. | No |
| **E2 — Provider contract & fake** | `EmailProviderConnector` (22 methods), 20 capability flags, `ProviderOutcome` (verified / accepted / retryable / permanent / ambiguous), `EmailFlightPlan`, reconciliation, and `FakeEmailProvider` with 12 outcomes. | Contract file unchanged |
| **E3 / E3.1 — Admin provisioning** | 8 admin screens, 12 read + 16 action endpoints, `BusinessEmailAdminAccess` (7 capabilities, elevated allowlist), `BusinessEmailAdminGate`. | No |

Two rules inherited unchanged and re-proven at E4:

- **Accepted is not verified.** An operation the provider merely acknowledged is never reported as done.
- **Ambiguity is neither success nor failure**, and is never retried automatically.

---

## 2. Customer SPA architecture

The customer application is vanilla JavaScript served from `public/app/`. `index.html` carries the shell and sidebar; each product area is a module in `public/app/js/` that exposes a global and paints into the shared body.

E4 follows the established pattern exactly:

```
public/app/js/business-email.js
  → window.luBusinessEmail.mount(ctx)
```

`ctx` is the helper bundle the Infrastructure module already passes to its own surfaces (`paintBody`, `pageShell`, `card`, `req`, `statusPill`, `metricStrip`, `enterpriseEmpty`, `esc`, `fmtTime`, `ICONS`). Business Email introduces no second design system, no framework, and no second HTTP client.

---

## 3. Existing Email Accounts state (before E4)

`HOSTING → Email Accounts` already existed in the sidebar as `#ni-infra-email`, gated by `data-feature="infrastructure"`. Clicking it opened the Infrastructure module's `email` tab, which rendered a single empty state:

> **Business email is coming soon** — Prerequisite: a connected custom domain. We'll let you know the moment email is available.

That copy is **still there**, and is still what a customer sees today.

---

## 4. Navigation decision

The directive locked one entry point and told me not to guess if closed-gate behaviour was ambiguous. It was, so the decision was made to **change nothing observable**:

- `#ni-infra-email` remains the **only** Email Accounts entry. No second nav item exists (asserted mechanically — exactly one occurrence of `ni-infra-email` and one of `business-email.js` in `index.html`).
- `infrastructure.js` delegates its `email` tab to `window.luBusinessEmail` when the module is present.
- **When the gate is closed, the module renders the pre-existing coming-soon state, using the same wording.** A customer on production today sees precisely what they saw before E4.
- Hosting remains **Advanced-mode only** and **plan-gated on `features.infrastructure`**. E4 did not relax either.

No product decision was taken on Mark's behalf, because no visible production behaviour changed.

---

## 5. Customer routes

One file, required into the existing authenticated Infrastructure group — inheriting `auth.jwt` and `DenyApiKeyAuth` from it rather than re-declaring them:

```
routes/api/authenticated/business-email.php   (11 routes)
```

| Method | URI |
|---|---|
| GET | `api/infrastructure/business-email/overview` |
| GET | `api/infrastructure/business-email/domains/{id}` |
| GET | `api/infrastructure/business-email/domains/{id}/setup` |
| GET | `api/infrastructure/business-email/mailboxes` |
| GET | `api/infrastructure/business-email/mailboxes/{id}` |
| GET | `api/infrastructure/business-email/aliases` |
| GET | `api/infrastructure/business-email/forwarders` |
| GET | `api/infrastructure/business-email/catch-all` |
| GET | `api/infrastructure/business-email/usage` |
| GET | `api/infrastructure/business-email/health` |
| POST | `api/infrastructure/business-email/domains/{id}/actions/{action}` |

There is **no workspace parameter on any route.** The workspace comes from the verified token claim (`$request->attributes->get('workspace_id')`), never from the request.

---

## 6. Customer APIs

`BusinessEmailCustomerController` — 10 reads. `BusinessEmailCustomerActionController` — 14 actions behind the single `actions/{action}` endpoint, with a fixed action → capability map the caller cannot influence.

Every response is an explicit projection. **No model is ever serialised directly.** Payload keys carry no provider reference, no operation id, no idempotency key, no failure code, no credential state, no drift or confidence value.

Outcome semantics reach the customer as:

| Engine outcome | HTTP | Customer wording |
|---|---|---|
| verified | 200 | done |
| accepted | 202 | *pending*, not done |
| ambiguous | 202 | *under review* — never "failed", never retried |
| refused pre-flight | 409 | plain reason, no internals |

---

## 7. Customer projections

Four final, wholly static classes under `app/Engines/Infrastructure/Email/Customer/`:

- **`CustomerStatus`** — 8 customer-safe states (`not_set_up`, `setup_required`, `verifying`, `provisioning`, `active`, `action_required`, `suspended`, `unavailable`) with an explicit per-state-machine map. `reconciling` and `provisioning_failed` both resolve to `action_required` with identical wording, so internal recovery machinery is invisible.
- **`CustomerHealth`** — 4 statuses and 4 checks (MX, SPF, DKIM, DMARC), each with a plain label, its technical name, and why it matters. `null` never renders as a pass.
- **`CustomerTimeline`** — an **allowlist** of 15 event keys, each with its own customer wording. Ten event families are explicitly withheld. **It never reads `context_json`**, so an event payload cannot leak through a history screen.
- **`CustomerEntitlements`** — LevelUp's own allowances, read from `config('business_email.customer_entitlements')`, never from provider capacity.

---

## 8. Customer action policy

14 actions. One is deliberately **not** executed by the customer:

- **`mailbox.delete`** is separation-of-duties. The customer's request records an `email.customer_request` event and returns **202 `awaiting_review`**. Nothing is destroyed on a customer's word alone.

**Password reset** returns no secret. The contract does not produce a temporary password, so the UI does not imply one exists — it states what will happen and nothing more. No one-time secret flow was invented.

---

## 9. Screen inventory

Eight surfaces, all captured in the browser (§15):

| # | Screen | Content |
|---|---|---|
| 1 | Overview | domain count, mailbox count, storage, needs-attention; per-domain status; allowance bars |
| 2 | Domain setup | neutral DNS requirements (Mail delivery / Sender authorization / Email signing / Email protection) with copy buttons |
| 3 | Mailboxes | list, allowance, create form (only when the server says the action is available) |
| 4 | Mailbox detail | attributes, password panel, manage controls, history |
| 5 | Aliases | list + explanation |
| 6 | Forwarding | list |
| 7 | Storage & Usage | storage allowance, per-mailbox usage, "not measured yet" distinct from zero |
| 8 | Health | simplified MX/SPF/DKIM/DMARC with why-it-matters |

---

## 10. Entitlements

Defaults in `config/business_email.php` → `customer_entitlements`: 1 domain, 10 mailboxes, 25 aliases, 25 forwarders, 51200 MB storage, catch-all **off**.

`allowance()` returns `used / limit / remaining / at_limit / percent`. At the limit the create form is not rendered — an action that would predictably be refused is never offered.

---

## 11. Health language

Provider vocabulary is absent. The engine is described to customers as "the Business Email service". Each check states what it does in ordinary language and what breaks without it. Unknown is shown as unknown; a check that has never run does not render as passing.

---

## 12. Timeline language

Allowlist, not denylist. If an event key is not explicitly listed with customer wording, it does not appear. The withheld set covers reconciliation, compensation, drift, credential, provider-resource, operation-internal and retry events.

---

## 13. Provider-leak protections

Enforced in four independent places:

1. **Projection classes** — the only source of customer wording; nothing is derived from provider data.
2. **Controllers** — never call a connector method; they go through the engine.
3. **Architecture guards** — 21 tests, 1,109 assertions (§16).
4. **Rendered-DOM scan** — the browser run reads `document.body.innerText` on every screen and fails on any forbidden term (§15).

Result: **no vendor name, provider id, provider resource, provider plan, provider price, provider support URL, raw provider error, credential state or internal operation id reaches a customer surface.**

---

## 14. Feature gating

`BusinessEmailCustomerGate::isEnabled()` requires **both** `business_email.customer_enabled` **and** `business_email.admin_enabled`. A customer surface for a feature no operator can see or repair produces support requests nobody can answer.

It is **not** an environment check. `APP_ENV` here is `staging` while this Laravel serves live customers, so an environment test would have left the portal switched **on**. A guard asserts `environment(` never appears in the gate.

Shared-environment state, verified after all work:

```
customer_enabled : false
admin_enabled    : false
customer gate    : false
admin gate       : false
email connector  : NONE (RuntimeException)
```

Customer API without a token, against live `levelupgrowth.io` — all **401**:
`overview, mailboxes, aliases, forwarders, usage, health, catch-all`.

---

## 15. Isolated browser validation

Run against a **loopback-only** instance (`127.0.0.1:8099`, `0.0.0.0` bind count = 0), pointed at the milestone's own test schema, with the fake connector registered by a router script that lives in `/tmp` and **not in the repository** — so nothing that wires a fake into a running server can ever ship.

Authentication was a **real form login** by a fixture user in that isolated database. No credential of any existing account was read, reset or modified. The password existed only in a `0600` file that the run shreds; it appears in no screenshot, log or evidence file.

Result:

- 8 screens captured; every Business Email API call **200**
- **`LEAKS: NONE`** — rendered text scanned on every screen
- no markup artifacts
- server error log clean
- the neighbouring workspace's seeded domain and mailbox (`neighbour-secret.test`, `neighbour-private`) never appeared

**Two defects were found here that no static test could see:**

1. **Every customer API request 404'd.** `apiUrl()` already supplies the `/api/` prefix; the module passed a leading slash, producing `/api//infrastructure/business-email/…`. Fixed at both call sites.
2. **Every panel's markup was being injected into a `style` attribute.** The shared helper is `card(inner, extra)` — `extra` is spliced *inside* the style attribute — and it was being called as `card(title, body)`. The browser bailed out at the first quote, so the pages only looked right by accident; titles were lost and a literal `">` rendered as visible text on the setup screen. Fixed by a `panel(title, inner)` helper, now the single caller of `ctx.card`.

Both now have guards (§16).

---

## 16. Architecture guards

`BusinessEmailCustomerGuardTest` — **21 tests, 1,109 assertions**, covering: vendor names, provider concepts in payload keys, direct connector calls, manufactured verified results, workspace-from-request, route parameters, admin-component imports, admin URLs in the SPA, customer wording, prices and plan names, entitlements-from-provider-capacity, hard-coded plan names, the gate, capability lists, the action map, caller-named capabilities, a second nav item, delegation, projection purity, helper signatures, and API path construction.

Comments are stripped before source scans, so a guard cannot pass or fail on its own explanation.

### Controlled injection

Ten violations were injected into real files, each run individually, each file restored and hash-verified:

| # | Injected violation | Result |
|---|---|---|
| 1 | vendor name in the customer SPA | **caught** |
| 2 | provider reference in a customer payload | **caught** |
| 3 | workspace id taken from the request | **caught** |
| 4 | caller names the capability | **caught** |
| 5 | internal vocabulary in customer wording | **caught** |
| 6 | gate downgraded to an environment check | **caught** |
| 7 | entitlements read from provider capacity | **caught** |
| 8 | a second competing navigation item | **caught** |
| 9 | customer controller reaches the operator plane | **caught** |
| 10 | customer controller calls the connector directly | **caught** |

Baseline before: 19 passed. After each injection: exactly 1 failed. After restore: 19 passed, **every file byte-identical** (sha256 verified). Zero residue.

---

## 17. Test matrix

| Suite | Tests | Assertions | Result |
|---|---|---|---|
| E4 customer portal | 37 | 454 | **pass** |
| E4 customer guards | 21 | 1,109 | **pass** |
| Business Email E1–E4, every file exactly once | **358** | **7,996** | **pass (RC=0)** |
| Wider INFRA888 (disjoint from the above) | 486 passed, 2 failed, 1 skipped | 1,732 | **baseline only** |

Dedicated database: `levelup_infra_e4_2f9b71c4_test`, under exclusive access, never a shared or another workstream's schema. Explicit test file paths only — no substring filters.

The directive's 32 required areas are covered by the E1–E4 set. Notably, **fresh migration replay** is not an ad-hoc command but a standing suite — `BusinessEmailMigrationReplayTest` — which is inside the 358 and passed. `RefreshDatabase` additionally rebuilds the milestone schema from empty at the start of every run above, so every one of those 358 tests ran against freshly replayed migrations.

No standalone `migrate:fresh` was issued by hand. It would have had to trust an `--env` flag to redirect the connection, and this platform lost a production database to exactly that assumption on 30 July 2026. The default connection on this box still resolves to `levelup_staging`, which is precisely why that command was not typed.

**Defects the E4 suite itself found and fixed:**

- **Action ordering.** `resolveSubject()` created the local mailbox record *before* pre-flight ran, so the duplicate-address check found the row it had just inserted: every create returned **409**, and where the unique index fired first the customer got a **500** instead of a polite refusal. Pre-flight now runs first.
- A guard failing on its own comment (fixed by stripping comments before scanning).

---

## 18. Regression evidence

**Business Email, E1 through E4 — 358 passed, 7,996 assertions, RC=0. Zero failures.**

**Wider INFRA888 — 486 passed, 2 failed, 1 skipped.** The two failures are the documented pre-existing baseline and nothing else:

```
Tests\Feature\Infrastructure\RegistrationDriftTest
  Unresolvable dependency resolving [Parameter #0 [ <required> string $environment ]]
  in class App\Connectors\Infrastructure\Namecheap\NamecheapClient
```

That is the Namecheap registrar work (a different workstream, blocked on sandbox credentials), not Business Email. **The baseline was reproduced exactly: two failures, one skip.** It was not changed, suppressed or absorbed, and regression did not worsen.

Two guards were **narrowed, not suppressed**. Both asserted *"no customer-facing Business Email route exists — that is E4"*. E4 is the milestone that makes that statement false, so each was retargeted at the rule it was actually protecting: a Business Email route may exist in exactly two files (the admin console and the authenticated customer file) and nowhere else. Both still fail if a route appears in a public, webhook or unauthenticated file. This is the same narrowing E3 applied to E1's version of the same guard.

The pre-existing baseline — two `RegistrationDriftTest` failures and one skip — was not changed, suppressed or absorbed.

---

## 19. Files created / modified

**Created**

```
app/Engines/Infrastructure/Email/Customer/CustomerStatus.php
app/Engines/Infrastructure/Email/Customer/CustomerHealth.php
app/Engines/Infrastructure/Email/Customer/CustomerTimeline.php
app/Engines/Infrastructure/Email/Customer/CustomerEntitlements.php
app/Engines/Infrastructure/Email/Customer/BusinessEmailCustomerGate.php
app/Http/Controllers/Api/BusinessEmailCustomerController.php
app/Http/Controllers/Api/BusinessEmailCustomerActionController.php
routes/api/authenticated/business-email.php
public/app/js/business-email.js
tests/Feature/Infrastructure/Email/BusinessEmailCustomerTest.php
tests/Feature/Infrastructure/Email/BusinessEmailCustomerGuardTest.php
```

**Modified**

```
config/business_email.php                  customer_enabled + customer_entitlements
public/app/index.html                      one script tag (?v=1.0.1-e4)
public/app/js/infrastructure.js            delegate the email tab to luBusinessEmail
routes/api.php                             one require line
tests/…/BusinessEmailE2GuardTest.php       guard narrowed (§18)
tests/…/BusinessEmailWhiteLabelGuardTest.php  guard narrowed (§18)
```

Nothing belonging to Engineer888, Studio, Builder, CRM, Billing, Platform Events, the Notification Engine or the AI Runtime was touched. Other sessions' in-flight files were left alone.

---

## 20. Routes

11 customer routes (§5) + the 14 admin routes from E3, unchanged. `php artisan route:list --path=infrastructure/business-email` → **11**.

---

## 21. Migrations

**E4 added no migration.**

The six E1 migrations (`2026_08_04_140501`–`140506`) are **still Pending on the shared database**, and the `email_*` tables **do not exist in `levelup_staging`**. This was verified, not assumed.

That is an additional layer of safety rather than a gap: even if both gates were flipped on by mistake, there is no schema for the feature to write to. **Applying those migrations is a deployment decision and was deliberately not taken.** It is a prerequisite for any future enablement.

One unrelated migration is also pending and belongs to another workstream: `2026_07_29_130000_add_execution_provenance_to_api_usage_logs`. It was not run.

---

## 22. Production impact

**None observable.**

- Both gates closed; the customer API answers 401 without a token and `available: false` behind one.
- The nav item, its wording, its plan gate and its Advanced-mode requirement are unchanged.
- With the gate closed the module renders the **pre-existing** coming-soon copy.
- No provider credential, no external call, no scheduler, no recurring job, no billing event, no customer notification.
- No fake provider is reachable from `app/`, `routes/`, `config/` or `public/` — verified by grep; `FakeEmailProvider` also refuses to construct in production.
- No vendor adapter directory exists under `app/Connectors/Infrastructure/Email/`.
- Live health after all work: `/app` 301, `/api/health` 200, `/admin/login` 200.
- The isolated server was stopped; no listener remains.

---

## 23. PTAA impact

**None.** PTAA was not provisioned, migrated, read or referenced. No DNS record anywhere was created, changed or deleted.

---

## 24. Known limitations

1. **Everything is proven against a fake.** No real provider has ever been called. Timing, error vocabulary and partial-failure behaviour of a real vendor remain unknown.
2. **The feature has no schema in production** (§21). Enablement requires a migration decision.
3. **Catch-all is off by default** and its customer surface is a read-only projection.
4. **Usage figures come from whatever last synchronised.** Where nothing has been measured the UI says so rather than showing zero, but there is no customer-triggered refresh.
5. **Commercial terms do not exist.** Allowances are config defaults, not a priced plan. No plan name is hard-coded, deliberately.
6. **Pre-existing platform drift, found in passing and not fixed** (it belongs to another workstream): `tasks.category` exists in production but **no migration creates it**, so a freshly-migrated environment cannot serve `/api/workspace/state` and the SPA shell will not boot. The E4 fixture mirrors the column locally to get a browser onto the page; the drift itself is reported, not repaired.
7. **`public/app/` contains many web-readable `index.html.bak-*` files.** Not introduced by E4 and not in scope, but they are served publicly and are worth a decision.

---

## 25. E5 entry conditions

E5 (the first real vendor adapter) should not begin until:

1. The commercial decision is made — which provider, on what terms, at what allowances.
2. Provider credentials exist and a custody path for them is agreed (they must live only in the encrypted vault, never in the repo).
3. The E1 migrations are applied to the target environment.
4. The adapter is built **only** inside `app/Connectors/Infrastructure/Email/<Vendor>/`, conforming to the E2 contract with no contract change.
5. It is proven against the same E2 capability suite the fake passes, before any live mailbox exists.
6. A rollback path is agreed for a domain that has already published DNS.

---

## 26. Honest readiness score

**Customer portal, against a fake provider: 9 / 10.**
Complete, tested, guarded, and validated in a real browser — where it found two defects that every static check had missed.

**Ready to serve a paying customer: 3 / 10.**
Not because the portal is unfinished, but because everything underneath it is still simulated: no provider, no credentials, no schema in production, no commercial terms. The portal will do what it says on the day those exist; today there is nothing behind it to do.

**Safe to leave deployed as-is: 10 / 10.**
Inert by construction — two independent gates, no connector, and no tables.
