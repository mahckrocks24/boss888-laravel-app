# INFRA888 — E3: BUSINESS EMAIL ADMIN PROVISIONING

**Date:** 2026-08-05 · **Status: COMPLETE — E3 PASSED** (completed in E3.1; the original partial-delivery disclosure is preserved below as the record of what E3 alone delivered).
**Repository:** `/var/www/levelup-staging` · **Branch:** `feature/studio888-mrc-2a`

---
---

# PART ONE — E3 AS ORIGINALLY DELIVERED (PARTIAL)

> Preserved verbatim in substance. At the time this was written the milestone had **not** passed. Sections marked "not delivered" here are superseded by Part Two.

## 1. E1/E2 baseline

E1 delivered the schema, six models, 43 lifecycle states and 16 capabilities. E2 finalised the provider contract (22 own methods), added 20 capability flags, a deterministic fake, the execution path, reconciliation, and 249 tests / 5,966 assertions. Both remain green and **untouched** by E3: the frozen engine manifest (38 files) and migration manifest (252 files) are byte-identical before and after.

## 2. Admin architecture (E3-A findings)

The canonical shell was identified before anything was written. There is exactly one admin application and E3 did not create a second.

| Concern | Canonical location |
|---|---|
| Shell | `resources/views/admin/shell.blade.php`, served by `App\Http\Controllers\Admin\AdminPageController` |
| Navigation | `config/admin_pages.php` — the single source of truth for sidebar, titles and routes |
| Visibility | `App\Core\Platform\Admin\AdminRegistry` → `AdminAccess`, filtered by a `capability` key per entry |
| Page renderers | `resources/views/admin/pages/<slug>.blade.php` (subdirectories supported) |
| Admin API | `Route::middleware(['auth.jwt', AdminMiddleware])->prefix('admin')` in `routes/api.php` |
| **Route extraction precedent** | `routes/api/admin/ads.php`, `routes/api/admin/engineer888.php`, each pulled in by one `require` |
| Mutating-route control | `App\Http\Middleware\DenyApiKeyAuth` |
| Permission conventions | `AdminAccess` capability prefixes (fail-closed on an unknown prefix); `App\Core\Governance\PermissionRegistry` |

**Files owned by other sessions:** `routes/api.php` is edited concurrently. E3 used the extraction precedent and added **one `require` line** rather than forty routes.

## 3. Navigation — *not delivered in E3* (see Part Two)

No entry was added to `config/admin_pages.php`, deliberately: `AdminPageController` aborts 500 when a registered slug has no view, so registering navigation without renderers would have made the console worse.

## 4. Admin APIs — delivered

14 routes under `/api/admin/business-email/`.

**Reads (12):** `overview` · `domains` · `domains/{id}` · `mailboxes` · `mailboxes/{id}` · `routing` · `operations` · `operations/{id}` · `reconciliation` · `observations` · `providers` · `health`

**Governed actions (2 entry points):** `POST domains/{id}/actions/{action}` (16 mapped actions) and `POST operations/{id}/resolve`.

Every read projects through the E1/E2 `toAdminArray()` methods — no model is serialised directly. Reads carry pagination, filtering and sorting. The reconciliation read runs `record: false` so opening a screen never writes observation facts as a side effect.

## 5. Authorization model — delivered

`BusinessEmailAdminAccess`, registered as the `business_email` prefix in `AdminAccess` alongside `engineer888`. Seven capabilities:

| Capability | Granted to |
|---|---|
| `business_email.read` | any platform admin |
| `business_email.operate` | any platform admin |
| `business_email.manual_review` | any platform admin |
| `business_email.provider.assign` | any platform admin |
| `business_email.provider.credentials` | **explicitly named operators only** |
| `business_email.mailbox.destroy` | **explicitly named operators only** |
| `business_email.domain.destroy` | **explicitly named operators only** |

The elevated allowlist defaults to **empty** — those three are refused for every operator on every install until an owner names someone. Not a parallel RBAC system: the platform rejected adding a fifth authority vocabulary, and registering Business Email in `PermissionRegistry` is an E4 entry condition.

## 6–7. Provider administration and credentials — partially delivered

`GET providers` reports connection state, health, resource counts, capability support and a `credential_policy` block. It reports credential **state** and never credential **value**; no code path in either controller reads a secret column.

**Not delivered:** credential rotation and provider-assignment mutations. Read surfaces only. *(Still outstanding after E3.1.)*

## 8. Governed actions — delivered

16 admin actions mapped to engine capabilities. The controller **never calls a provider**: every action builds an `EmailOperationContext` and hands it to `BusinessEmailEngine::execute()`. An architecture test asserts no connector method name appears in either controller.

- The caller **cannot name a capability** — only an action the fixed map knows.
- A subject must belong to the domain in the URL (cross-tenant defence).
- Destructive actions return **428** until `confirm: true` is sent.
- `verified` → 200; `accepted` → **202**, so a console cannot render "done" for a mailbox that does not exist yet.
- Password-reset responses are redacted with `Cache-Control: no-store, private`.
- Mutating routes carry `DenyApiKeyAuth`.

## 9. Manual review — delivered

`POST operations/{id}/resolve` accepts `confirm`, `acknowledge` or `retry`, each requiring a stated reason (422 without one). `GET operations/{id}` returns the timeline plus an explicit allowed/forbidden action list.

**Retry of an ambiguous operation is refused with 409**, pointing at `confirm`. Acknowledging records the decision and **does not change the operation state**.

## 10. Screen inventory — *not delivered in E3* (see Part Two)

Zero of the ten required screens were built.

## 11. Password-reset safety — delivered, by construction

E2's contract **forbids an adapter returning credential material**, so there is no password to show once. E3 did not invent one, which E3-I explicitly required.

## 12. Operations, audit and events — delivered

Reuses `infra_operations`, `infra_events`, `infra_observation_facts`, `infra_provider_resources`. **No parallel tables.** Two new event types: `email.admin_action` and `email.manual_review_resolved`, both carrying actor and reason.

## 13. Fake-provider validation — partially delivered

Fake remains test-only and unreachable from configuration. Scenario control is configured and gated but **has no endpoint**.

## 14. Test matrix — *11 of 27 at E3* (superseded by Part Two)

## 15. Browser evidence — *none at E3* (superseded by Part Two)

## 19. Production gating — delivered and verified

An **explicit opt-in that is off on every environment**, not an environment check: `APP_ENV` here is `staging`, and the same Laravel serves levelupgrowth.io and live customer domains. An `environment('production')` test would have left the area switched **on** for real operators.

## 20–21. Parallel sessions / production impact

No file belonging to another session modified. Zero migrations, gate closed, no credential, no network call, no scheduler, no customer route. **PTAA impact: none.**

## 23. Honest readiness at E3: 3 / 10 — did not pass

I ran out of working budget partway through and stopped rather than lower the bar. Writing ten thin blade files and declaring the milestone green would have shipped exactly the "half-operational Admin surface" this milestone was told not to ship.

---
---

# PART TWO — E3.1 CONTINUATION AND COMPLETION

**Status: E3 COMPLETE — PASSED.**

## E3.1-1. Continuation scope

E3 delivered the server-side admin layer and stopped: no screens, no navigation, 16 of 27 tests failing, no regression, no injection proof. E3.1 completed all of it. Nothing E3 built was restarted or replaced — the controllers, gate, permissions and route file are the same files, corrected in place where they were wrong.

| E3 section | E3 status | E3.1 status |
|---|---|---|
| §3 Navigation | not delivered | **delivered** — 8 entries, capability-gated |
| §10 Screen inventory | 0 of 10 | **delivered** — 8 screens covering all 10 required views |
| §14 Test matrix | 11 of 27 | **delivered** — 49 E3 tests, all passing |
| §15 Browser evidence | none | **partial** — gate-closed behaviour verified live; open-gate deliberately not run |
| Regression | not run | **delivered** |
| Injection proof | not performed | **delivered** |

## E3.1-2. JWT harness correction — and what the hypothesis got wrong

E3 reported the 16 failures as "a test-harness gap, not a defect in the controllers", and explicitly flagged that as a hypothesis. **It was half right, and the half it got wrong mattered.**

Replacing `actingAs()` with real tokens minted through `RefreshTokenService::issueTokenPair()` — the pattern used by `ProviderControlPlaneHttpTest`, INFRA888's own canonical admin HTTP test, with no second helper invented — moved the failures from **401 to 403**. That was a second, real defect underneath:

**`Workspace::firstOrCreate(['id' => 9501], …)` silently assigned an auto-increment id**, because `id` is not mass-assignable. The token's `ws` claim therefore named one workspace while the `workspace_users` row named another, and `JwtAuthMiddleware` correctly answered `403 workspace_access_revoked`. Forcing the id with `forceFill` fixed it. Had the harness been "fixed" by relaxing `auth.jwt`, this would never have surfaced.

Two further real defects were found only because the tests then ran for real:

1. **The action controller granted `mailbox_limit => null`** intending "unlimited". `EmailOperationContext::hasEntitlement()` reads null as **not granted**, and the required entitlement for `email.mailbox.create` IS the mailbox-limit key — so every admin mailbox create was refused with `email_not_entitled`. Corrected to `true`, which `limit()` reads as granted-with-no-ceiling.
2. **`BusinessEmailAdminAuthTest` used `DatabaseTransactions`**, copied from `ProviderControlPlaneHttpTest`. That trait does not migrate. Alone the class passed, because an earlier `RefreshDatabase` run had left a migrated schema; inside the suite all 16 of its tests failed with `Unknown column 'is_platform_admin'`. **A test whose result depends on what ran before it is not a test.** Switched to `RefreshDatabase`.

Two of my own assertions were also wrong and were corrected rather than worked around: one grepped the substring `password` and flagged the legitimate capability name `mailbox.password_reset`; another globbed every migration dated today and failed when *another session* added one.

### The ten required proofs

`BusinessEmailAdminAuthTest` — 11 tests, all through the real `auth.jwt → admin → DenyApiKeyAuth` stack.

| # | Proof | Result |
|---|---|---|
| 1 | No token → rejected | 401 |
| 2 | Invalid token → rejected | 401 |
| 3 | Expired token → rejected | 401 (signed with the real secret, so only expiry can reject it) |
| 4 | Customer token → admin routes rejected | 403 on all 6 read routes |
| 5 | Workspace manager without platform permission → rejected | 403 |
| 6 | Admin without the capability → rejected | 404 `not_available` |
| 7 | Authorized admin → allowed | 200 |
| 8 | Cross-workspace scoping is explicit | filter honoured |
| 9 | Credential management narrower than operation | proven both directions |
| 10 | Destructive narrower than read | proven both directions |
| + | API key on a mutating route | 403 `api_key_not_permitted` |

## E3.1-3. Navigation (supersedes §3)

Eight entries in `config/admin_pages.php`, patched atomically with a syntax gate and a pre-patch backup. Built **after** the views existed, so no registered slug can 500.

```
Business Email
├── Overview                 /admin/business-email/overview
├── Domains                  /admin/business-email/domains
├── Mailboxes                /admin/business-email/mailboxes
├── Aliases & Forwarding     /admin/business-email/routing
├── Operations               /admin/business-email/operations
├── Reconciliation           /admin/business-email/reconciliation
├── Providers                /admin/business-email/providers
└── Health                   /admin/business-email/health
```

Every entry carries `capability => business_email.read`. While the gate is closed, `AdminRegistry` removes the whole group before the sidebar is built and before `window.ADMIN_PAGES` is serialised — **the browser never receives the slugs**. Nothing is hidden by JavaScript.

*Deviation from the requested `Infrastructure └── Business Email`:* the admin has no `Infrastructure` group today (Hosting sits under `Content & Creative`). Creating one would have moved another package's page. Business Email is its own coherent group immediately after Hosting.

## E3.1-4. Screens (supersedes §10)

Eight views under `resources/views/admin/pages/business-email/`, following the canonical one-view-per-menu-item pattern with a shared `_helpers` partial. The two "detail" screens are in-page drill-downs (`?id=`) on their list pages — the canonical admin is multi-page with in-page detail, and ten separate shells would not have matched it.

| Required screen | Delivered as |
|---|---|
| 1 Overview | `overview` — totals by lifecycle, storage, unresolved count, manual-review call-out, provider status |
| 2 Domains | `domains` — workspace, lifecycle, custody, health, mailbox count, binding, last verified |
| 3 Domain Detail | `domains?id=` — overview, DNS requirements, mailboxes, aliases, forwarding, usage, operations, reconciliation link, audit |
| 4 Mailboxes | `mailboxes` — address, workspace, lifecycle, quota, usage, observed, binding |
| 5 Mailbox Detail | `mailboxes?id=` — lifecycle, quota, usage history, operations, binding, credential statement |
| 6 Aliases & Forwarding | `routing` — aliases, forwarders with loop verdicts, catch-all; blocked rules surfaced first |
| 7 Operations | `operations` — capability, subject, state, idempotency key, attempts, retry class, manual-review flag; `?id=` gives timeline + resolve panel |
| 8 Reconciliation | `reconciliation` — conclusiveness first, desired vs observed counts, findings with severity and evidence, plus the customer projection shown so an operator can confirm it leaks nothing |
| 9 Providers | `providers` — connections, credential *state*, credential policy, capability support matrix |
| 10 Health | `health` — provider, domain health, observation freshness, unsupported capabilities, recent observation facts |

Three rendering rules are asserted by test: no screen names a vendor; no screen calls anything completed unless the **server** said `verified`; no screen writes to `localStorage`, `sessionStorage` or `document.cookie`.

## E3.1-5. Manual-review UI

The `operations?id=` screen renders the operation, subject, capability, actor, idempotency key, attempt count, retry classification, provider correlation id, failure code and full timeline — then a resolve panel with a **mandatory** reason field.

Buttons are rendered from the server's `resolution.allowed` list, never assumed. The server's `resolution.forbidden` list is rendered too, with reasons: a console that merely omits the retry button leaves the operator wondering whether it is missing or broken.

- **Retry on an ambiguous operation** → 409 with `use_instead: confirm`.
- **Acknowledge** records the decision and returns the state **unchanged**, with the note "the operation remains unresolved until evidence settles it". Asserted: after acknowledging, the operation is still `compensation_pending`.
- Force-success, provider-id editing and audit deletion appear only in the forbidden list; no endpoint implements them.

## E3.1-6. Password reset

Unchanged and deliberately so. E2's contract cannot return credential material, so **there is nothing to show once**. The UI states: *"No password is stored by LevelUp for this mailbox, and none can be retrieved. Authentication is owned by the service."* No one-time secret flow was invented.

Asserted: response body and both operation payloads contain no `password`, `temporary_password`, `reset_token`, `reset_url` or `secret`; `Cache-Control: no-store, private`; no screen persists anything client-side.

## E3.1-7. Production gate — unchanged and re-proven

Verified after all changes: `gate=false`, `scenario=false`, `elevated=[]`, `infrastructure.connectors.email=NULL`.

**The open-gate browser workflow was deliberately NOT run on staging.** Stage 8 forbids enabling the feature globally on the shared live environment to capture screenshots, and staging is that environment.

## E3.1-8. Architecture guards and injection

E1 guard **17 passed / 426 assertions**; E2 guard **18 passed / 1,754 assertions**. Both gained a test.

**Two guards were deliberately evolved, and both became stricter, not weaker:**

- E1's `test_no_business_email_route_is_registered` asserted no route existed anywhere, because E1 added none. Replaced by `test_no_customer_facing_business_email_route_exists`, permitting routes **only** in `routes/api/admin/business-email.php` (plus the one `require` line) and failing on one anywhere else — plus a new test that the admin route file does not re-declare its own authentication.
- E2's `test_no_business_email_route_or_admin_page_was_added` likewise. Replaced by `test_every_admin_page_entry_is_capability_gated` and `test_no_customer_facing_business_email_surface_exists`, which additionally scans non-admin views.

**Controlled injection**, against an E3-owned file (`BusinessEmailAdminController.php`):

| Step | Result |
|---|---|
| Baseline | md5 `994c33104ce4d4f7debc9d1b50d39252`, 30,137 bytes |
| Guard before | **17 passed (426 assertions)** |
| Inject one vendor name in a comment | md5 `755114ccff87ed9da27dc2d8751166ac`, 30,203 bytes; `php -l` clean |
| Guard with violation | **1 failed, 16 passed — exit 1** |
| Restore | md5 `994c33104ce4d4f7debc9d1b50d39252`, 30,137 bytes — **byte-for-byte identical**, `php -l` clean |
| Guard after | **17 passed — exit 0** |
| Repo-wide residue | **zero** |

## E3.1-8b. Test matrix and regression (supersedes §14)

**Canonical single-execution totals. Nothing counted twice.**

| Suite | Tests | Assertions | Result |
|---|---:|---:|---|
| `BusinessEmailAdminTest` (E3, JWT-corrected) | 27 | 183 | PASS |
| `BusinessEmailAdminAuthTest` (E3.1) | 11 | 22 | PASS |
| `BusinessEmailAdminScreensTest` (E3.1) | 11 | 165 | PASS |
| **E3 total** | **49** | **370** | **PASS** |

**Whole Business Email suite** (all 11 files — E1 + E2 + E3 together, including migration replay): **300 passed, 0 failed, 6,382 assertions.**

**Full INFRA888 regression:**

| | Passed | Failed | Skipped | Assertions |
|---|---:|---:|---:|---:|
| E2 close | 735 | **2** | 1 | 7,698 |
| E3.1 close | 786 | **2** | 1 | 8,115 |
| Delta | **+51** | 0 | 0 | +417 |

+51 is exactly the 49 E3 tests plus the two guard tests added when the route guards were evolved. **The two failures are the stated pre-existing `RegistrationDriftTest` baseline** (Namecheap registrar connector, another work package) — unmodified, unsuppressed, unabsorbed. The one skip is likewise baseline.

**Fresh migration replay:** included in the Business Email suite run (6 tests, 62 assertions) — clean install from zero, rollback and re-run, idempotent re-run.

**Frozen manifests after all work:** E1/E2 engine (38 files) unchanged; all 252 migrations unchanged.

## E3.1-8c. Browser evidence (partial — supersedes §15)

Verified live against `https://staging.levelupgrowth.io` with the gate **closed**, the only state this shared environment may be in:

| URL | Result |
|---|---|
| `/admin/login` | 200 |
| `/admin/dashboard` | 302 → login |
| `/admin/hosting` | 302 → login |
| `/admin/business-email/overview` | **302 → login** (not 500 — the registered slug resolves) |
| `/api/health` | 200 |

**Anonymous `/admin/dashboard`: 422 bytes, `business-email` mentions = 0.** The navigation entries are not merely hidden — they are absent from what the server sends.

**Not captured:** the open-gate workflow (populated screens, a governed action driven through the UI, destructive confirmation). Stage 8 forbids enabling the feature on the shared live environment, and no isolated browser environment exists for this install. Stated as a limitation rather than worked around.

## E3.1-9. Files created and modified in E3.1

**Created (11):** 9 files under `resources/views/admin/pages/business-email/` (`_helpers`, `overview`, `domains`, `mailboxes`, `routing`, `operations`, `reconciliation`, `providers`, `health`), plus `tests/…/BusinessEmailAdminAuthTest.php` and `tests/…/BusinessEmailAdminScreensTest.php`.

**Modified (5):**

| File | Change |
|---|---|
| `config/admin_pages.php` | 8 navigation entries, patched atomically with a syntax gate |
| `app/Http/Controllers/Api/Admin/BusinessEmailAdminActionController.php` | entitlement `null` → `true` (the mailbox-create defect) |
| `tests/…/BusinessEmailAdminTest.php` | real JWT auth; forced workspace id; two corrected assertions |
| `tests/…/BusinessEmailWhiteLabelGuardTest.php` | route guard evolved (stricter) |
| `tests/…/BusinessEmailE2GuardTest.php` | route/admin-page guards evolved (stricter) |

**E3 + E3.1 combined:** 19 files created, 7 modified. Only two are tracked pre-existing platform files: `routes/api.php` (one `require` line) and `app/Connectors/…/EmailProviderConnector.php` (E2's contract). No file belonging to another session was touched. Nothing staged, nothing committed.

## E3.1-10. Known limitations carried forward

1. **No open-gate browser evidence.** Screens proven by component test and their APIs end-to-end through real HTTP, but not *seen* rendering with data.
2. **Provider credential rotation and provider assignment remain read-only.**
3. **Scenario control has no endpoint.** The gate exists and is proven closed; nothing consumes it.
4. **Approval is still the operator's own action.** Real approval records are E4.
5. **`PermissionRegistry` integration outstanding** — the elevated allowlist is the documented interim.

## E3.1-11. E4 entry conditions (supersedes §24)

1. Register the seven `business_email.*` capabilities in `PermissionRegistry` and retire the config allowlist.
2. Build credential rotation and provider assignment, with separation of duties.
3. Capture open-gate browser evidence in an isolated environment.
4. Bind approval to real approval records.
5. E4's customer surface must not reuse any admin projection: `toCustomerArray()` exists on every model and is the only permitted source.

## E3.1-12. Honest readiness score (supersedes §23)

**E3 as specified: 8 / 10. It passed.**

What earns it: the JWT correction was pursued as a *hypothesis to test* rather than an excuse, and testing it surfaced three further real defects — a workspace-id mismatch that `auth.jwt` was right to reject, an entitlement `null`/`true` confusion that refused every mailbox create, and a transaction-trait mismatch that made 16 of my own tests depend on execution order. None would have been found by relaxing the middleware. The two route guards were evolved to be stricter rather than deleted. The regression is back on baseline exactly.

What costs the two points:

- **No open-gate browser evidence.** Stage 8's constraint made that the correct trade, and it is still a gap.
- **`BusinessEmailAdminTest` has 27 tests and 183 assertions.** The same thin-assertion habit I flagged at E1 and E2 and have now carried through a third milestone. The entitlement defect existed because a test asserted a status code without asserting the failure code beneath it.
- **The 16-failure regression was caught by running the suite, not by design.** Each class passed alone. I only looked because the suite total disagreed with the baseline.

**Business Email as a product: 3 / 10** (up from 2). An authorised operator now has a complete, safe console — behind a gate that is closed everywhere. No customer can do anything. E4 and E5 both remain.

**Confidence in the authorization model: high** — proven through the real middleware stack across ten distinct principal/permission combinations.
**Confidence in the screens: medium** — correct by construction and component test; unobserved in a browser with data.
