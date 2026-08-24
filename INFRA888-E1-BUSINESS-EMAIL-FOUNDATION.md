# INFRA888 — E1: BUSINESS EMAIL FOUNDATION

**Date:** 2026-08-04 · **Milestone:** E1 (of the E0–E10 roadmap in `INFRA888-BUSINESS-EMAIL-WHITE-LABEL-MASTERPLAN.md`)
**Scope:** architecture guards, provider-agnostic schema, entities, lifecycle, engine skeleton, capability registration, tests, documentation.
**Explicitly out of scope:** E2 and beyond. No vendor adapter, no customer API, no admin action, no network call, no provisioning, no purchase.

**Repository:** `/var/www/levelup-staging` · **Branch:** `feature/studio888-mrc-2a` · **HEAD at start:** `f04a6b363b085f42ca2a35f0749664587cb1b3e5`

---

## 1. Locked business decisions

These were supplied as locked inputs and are implemented, not re-litigated.

| # | Decision | How E1 implements it |
|---|---|---|
| 1 | **LevelUp Growth is the Business Email provider.** | Every entity, state, capability and message is named in LevelUp's vocabulary. No vendor concept exists above the connector seam. |
| 2 | **Customers never see or contract with the underlying vendor.** | `toCustomerArray()` on every model is enumerated, not exclusion-based; guard tests assert no provider key or provider-shaped key can appear. |
| 3 | **LevelUp owns and pays for provider accounts.** | Provider identity is a platform-level `infra_provider_connections` row (nullable `workspace_id` = platform-owned), not a per-tenant credential. |
| 4 | **Provider cost is a LevelUp operating expense.** | Capability registry declares a `cost_class` per operation (`none` / `operational` / `billable`) so margin can be computed from the operation log rather than guessed. |
| 5 | **PTAA is the first reference tenant.** | No PTAA data was touched. E1 creates only empty schema; PTAA remains on its existing provider, unmodified. |
| 6 | **Business Email may proceed independently of the Domains-live gate.** | E1 proceeded. The masterplan §13 risk 7 gate is recorded as overridden for E1–E4; it still governs E5 (first vendor adapter). |
| 7 | **Option A on DNS visibility** — provider identity may appear in DNS where unavoidable, never in LevelUp UI, API, invoices, notifications, support or public docs. | Guards enforce the second half mechanically across `app/`, `config/`, `database/migrations/`, `routes/`, `resources/js`, `resources/views`, `public/app`, `public/marketing`. |
| 8 | **Provider-specific code only under `app/Connectors/Infrastructure/Email/<Vendor>/`.** | That directory does **not exist**, and a guard asserts it does not. It is the single exclusion in the scanner. |

---

## 2. Existing platform primitives reused

The single most consequential engineering decision in E1 was **what not to build**. The masterplan lists eight conceptual entities; two of them already exist as generic platform primitives, and building email-specific copies would have created a second provider/audit spine that drifts from the first.

| Masterplan concept | Reused primitive | Why reuse is correct |
|---|---|---|
| `email_operations` | **`infra_operations`** | Already provides `(workspace_id, idempotency_key)` uniqueness, retry classification, attempt counting, timeout/stuck handling, approval and task linkage, actor provenance, and redacted request/result payloads. Its `owner_type` comment has listed `mailbox` as an anticipated owner since Phase 1A. A parallel table would have to re-earn all of that. |
| `email_observations` | **`infra_observation_facts`** | Dimension-agnostic by design (`subject` + `dimension` + `custody` + `confidence`). MX/SPF/DKIM/DMARC are four more dimensions, not a new kind of fact. Reusing it means Business Email health inherits the S6–S8.3 custody, drift, confidence and alert-eligibility model instead of inventing a second, weaker one. |
| provider object references (`provider_*_ref`) | **`infra_provider_resources`** | Already the canonical business-record → opaque-external-object map, with `provider_resource_id` and a `(provider, type, id)` uniqueness constraint. Hosting stores no provider ref on `infra_hosting_accounts` for this reason; Business Email follows the same precedent. **This is why no `email_*` table has a `_ref` column, and a test asserts none ever does.** |
| provider identity / binding | **`infra_provider_connections`**, `infra_providers`, `infra_provider_capabilities` | The registry already separates "the platform supports X" from "X is wired up here". Email binds through a nullable FK to `infra_provider_connections`. |
| audit trail | **`infra_events`** | Append-only, records denials and failures (which the platform `audit_logs` path does not — it logs `array_keys($params)` only and returns early on denial). |
| billing-period metering | **`infra_usage_records`** | Business Email usage samples roll up into it. E1 does not duplicate period/overage/invoicing state. |
| health alerting | **`infra_estate_alerts`**, `infra_incidents` | E9 wires Business Email health into the existing alert identity and eligibility model. |
| custody / lifecycle / confidence | **`Custody`**, **`AssetLifecycle`** | `email_domains.custody` uses `Custody::` values verbatim; `email_usage.confidence` uses `AssetLifecycle::confidenceLadder()`. No competing vocabulary was created. |
| state machine base | **`StateMachine`** | All six email machines extend it, inheriting validation, guards and reachability. |
| provider result shape | **`ProviderResult`** | Reused unchanged. The engine returns `failed()` / `accepted()` and **never** `verified()`. |
| capability→connector resolution | **`InfrastructureConnectorResolver`** | The engine routes through it and translates its throw into a typed refusal. |
| tenancy | **`BelongsToWorkspace`**, **`WorkspaceContext`** | Every email model uses the trait; the engine wraps DB work in `WorkspaceContext::run()`. |

**Two tables were deliberately NOT created, and a test asserts they never are:** `email_operations` and `email_observations`.

---

## 3. New files

**Engine — `app/Engines/Infrastructure/Email/`** (19 classes)

| File | Purpose |
|---|---|
| `BusinessEmailEngine.php` | The single governed entry point. Validates, authorises, records, refuses. |
| `Models/EmailDomain.php` | Root entity. |
| `Models/EmailMailbox.php` | Billable unit. |
| `Models/EmailAlias.php` | Address → mailbox/address routing. |
| `Models/EmailForwarder.php` | Address → external destination. |
| `Models/EmailCatchAll.php` | One per domain. |
| `Models/EmailUsage.php` | Numeric usage samples. |
| `States/EmailDomainState.php` | 10-state domain lifecycle. |
| `States/EmailVerificationState.php` | 6-state DNS evidence machine. |
| `States/EmailMailboxState.php` | 8-state mailbox lifecycle. |
| `States/EmailRoutingRuleState.php` | Shared 7-state machine for aliases and forwarders. |
| `States/EmailAliasState.php` | Typed subclass. |
| `States/EmailForwarderState.php` | Typed subclass. |
| `States/EmailCatchAllState.php` | 6-state catch-all lifecycle. |
| `Registry/BusinessEmailCapabilityRegistry.php` | 15 capabilities with full governance metadata. |
| `Support/EmailAddress.php` | One definition of a valid local part, domain and address. |
| `Support/ForwarderLoopSafety.php` | Pure loop detection over records we hold. |
| `Support/BusinessEmailFailure.php` | Typed failure codes, customer-safe messages, retry classes. |
| `Support/EmailOperationContext.php` | Immutable request context. |

**Migrations — `database/migrations/`** (6)

`2026_08_04_140501_create_email_domains_table.php`
`2026_08_04_140502_create_email_mailboxes_table.php`
`2026_08_04_140503_create_email_aliases_table.php`
`2026_08_04_140504_create_email_forwarders_table.php`
`2026_08_04_140505_create_email_catchall_table.php`
`2026_08_04_140506_create_email_usage_table.php`

**Tests — `tests/Feature/Infrastructure/Email/`** (7)

`BusinessEmailWhiteLabelGuardTest.php`
`BusinessEmailSchemaTest.php`
`BusinessEmailMigrationReplayTest.php`
`BusinessEmailLifecycleTest.php`
`BusinessEmailModelTest.php`
`BusinessEmailCapabilityRegistryTest.php`
`BusinessEmailEngineTest.php`

**Test configuration**

`phpunit.e1.xml` — dedicated database `levelup_infra_e1_7c3f21a4_test`.

---

## 4. Modified files

**None.**

E1 created 33 new files (19 engine classes, 7 test files, 6 migrations, 1 phpunit config) and modified **zero** existing files. `git status` for the E1 paths shows only `??` entries, and the index is clean — nothing was staged or committed.

This was a deliberate constraint, not an accident, and it cost one design decision: **`config/infrastructure.php` was not given an `email` connector key.** Adding `'email' => env('INFRA_EMAIL_CONNECTOR')` would have broken two live regression tests — `RegistrationDriftTest::test_every_configured_connector_class_exists_and_implements_the_contract` and `::test_resolver_returns_a_connector_whose_capability_matches_its_key` both iterate the connector map and would fail on a null class. The absent key already produces the correct behaviour: `InfrastructureConnectorResolver` throws loudly, and the engine turns that into a typed refusal. Declaring the absence would have been marginally more explicit and measurably more dangerous.

---

## 5. Schema

Six tables. All workspace-scoped, all with explicit lifecycle columns, no vendor names, no provider reference columns, no fabricated defaults.

### `email_domains` — the root entity
Identity: `UNIQUE(domain, deleted_at)` — **globally** unique, not per-workspace, because MX is a property of the domain in public DNS and two tenants cannot both operate mail for it. Including `deleted_at` (MySQL treats NULLs as distinct) permits unlimited soft-deleted history alongside exactly one live row.

Key columns: `workspace_id`, `domain(253)`, `customer_domain_id`, `website_id`, `custody(24)`, `lifecycle_state(32)`, `previous_state`, `verification_state(32)`, `health_state(24)`, `provider_connection_id`, `dns_verified_at`, `last_observed_at`, `last_observation_id`, `last_operation_id`, `state_changed_at`, `state_changed_by_user_id`, `created_by_user_id`, `suspended_at`, `suspension_reason`, `terminated_at`, `settings_json`, timestamps, `deleted_at`.

8 indexes. 1 FK (`provider_connection_id` → `infra_provider_connections`, RESTRICT).

### `email_mailboxes`
Identity: `UNIQUE(email_domain_id, local_part, deleted_at)`.
`quota_mb` is **NOT NULL with no default** — a mailbox with an unstated quota is not a real mailbox, and a default would be a fabricated value that later reads as deliberate.
**No stored address column** — identity is (domain, local_part); the address is derived.
**No credential material of any kind.** `password_set_at` and `last_password_reset_at` record that an event happened, never what it contained. A test asserts no column name matches `password|secret|token|credential|passphrase` beyond those two event timestamps.
`suspension_reason_code(32)` is separate from `lifecycle_state`: the state says mail is stopped, the reason says who may restart it.

6 indexes. 2 FKs (domain CASCADE, provider connection RESTRICT).

### `email_aliases`
Identity: `UNIQUE(email_domain_id, source_local_part, deleted_at)`.
`target_type` ∈ {`mailbox`, `address`} with a **CHECK constraint** (`email_alias_target_chk`) making the three invalid shapes — no target, both targets, target contradicting the declared type — unrepresentable rather than merely discouraged. MySQL 8.0.46 on this host enforces CHECK constraints; verified before the migration was written.

4 indexes. 3 FKs (domain CASCADE, target mailbox **RESTRICT**, provider connection RESTRICT).

### `email_forwarders`
Identity: `UNIQUE(email_domain_id, source_local_part, destination_address, deleted_at)` — a source may legitimately fan out to several destinations.
`loop_check_state(24)` defaults to `unchecked`, which is **blocking**. A forwarder that has never been evaluated may not be provisioned. Defaulting to `safe` would be a fabricated clearance whose failure mode is mail amplification against an uninvolved third party.

5 indexes. 2 FKs.

### `email_catchall`
`UNIQUE(email_domain_id)` — one per domain, structurally.
**No `enabled` boolean.** `state` is the single answer; a boolean cannot express "we asked the provider and are waiting". CHECK constraint `email_ca_target_chk` requires a consistent target in every state that delivers mail, and forbids one in the two states that do not.
No soft deletes — a catch-all is switched off, not removed, and the row dies with its domain.

3 indexes. 3 FKs.

### `email_usage`
Idempotency: `UNIQUE(workspace_id, idempotency_key)`, mirroring `infra_operations`.
CHECK constraint `email_usg_scope_chk` requires `scope` and mailbox linkage to agree.
**Every measure is nullable, deliberately.** A provider that does not report send counts produces NULL, never 0 — `messages_sent => 0` is a claim no mail was sent, which is a lie the customer cannot detect.

5 indexes. 3 FKs.

### Cross-cutting schema rules honoured
- **Tenancy:** `workspace_id` NOT NULL on all six, with composite indexes leading on it. **No FK to `workspaces`** — consistent with `infra_operations`, `infra_provider_resources` and `infra_usage_records`; adding a restrict-on-delete there would change workspace deletion semantics for every other workstream. Enforcement is `BelongsToWorkspace` (global scope + immutable `workspace_id`) plus service-level assertion in the engine.
- **Safe deletion:** a mailbox an alias or catch-all delivers into cannot be hard-deleted (RESTRICT). Domain deletion cascades to its children. Usage samples cascade with their mailbox because they mean nothing without it; billing history survives independently in `infra_usage_records`.
- **No nullable field stands in for a lifecycle.** Every nullable column is an evidence timestamp, an actor, or a link to something that legitimately may not exist yet.

---

## 6. Reused tables and why

Section 2 is the full table. In summary, E1 created 6 tables and reused 10, and the two the masterplan named as email entities (`email_operations`, `email_observations`) are among the reused rather than the created. `BusinessEmailSchemaTest::test_no_parallel_operations_or_observations_table_was_created` asserts this permanently.

---

## 7. Entities and relationships

```
Workspace (tenancy boundary, enforced by trait + index + engine assertion)
    │
    └── EmailDomain ................................. root object
            │   custody · lifecycle_state · verification_state · health_state
            │   provider_connection_id ──► infra_provider_connections (opaque)
            │
            ├── hasMany  EmailMailbox ............... local_part · quota · usage · suspension
            │                 └── hasMany EmailUsage (scope = mailbox)
            │
            ├── hasMany  EmailAlias ................. source → mailbox XOR address
            │                 └── belongsTo EmailMailbox (RESTRICT)
            │
            ├── hasMany  EmailForwarder ............. source → external + loop verdict
            │
            ├── hasOne   EmailCatchAll .............. one per domain, state-driven
            │                 └── belongsTo EmailMailbox (RESTRICT)
            │
            └── hasMany  EmailUsage ................. scope = domain

        governed by ──► infra_operations   (idempotency, retry, approval, actor)
        audited into ─► infra_events       (append-only, records denials)
        observed into ► infra_observation_facts (mx | spf | dkim | dmarc)
        mapped via ───► infra_provider_resources (opaque provider object refs)
```

---

## 8. Lifecycle diagrams

### Domain — `EmailDomainState` (10 states, terminal: `terminated`)

```
                       ┌──────────────────────────────────────┐
                       ▼                                      │
  connected ──► verifying_dns ──► dns_verified ──► provisioning ──► active
                   │  ▲               │                │  │           │  ▲
                   │  │               └────────────────┘  │           │  │
                   ▼  │                  (re-check)       ▼           ▼  │
        verification_failed                          reconciling  suspended
                                                          │           │
                                                          ▼           │
                                                 provisioning_failed  │
                                                          │           │
        every state ─────────────────────────────────► terminated ◄───┘
```
- `reconciling` is reachable from `provisioning` and from `active`; it may reach `active`, `provisioning_failed` or `terminated` — **never back to `provisioning`**, which is the double-provisioning path.
- `active → verifying_dns` exists because DNS is externally mutable after go-live.
- No edge reaches `active` except from `provisioning`, `reconciling` or `suspended`, and all three require evidence.

### DNS verification — `EmailVerificationState` (6 states, no terminal)

```
  unverified ──► pending_records ──► checking ──► verified ──► drifted
                        ▲               │  │         │            │
                        │               │  ▼         ▼            │
                        └──────────── failed      checking ◄──────┘
```
`pending_records → verified` **does not exist**: telling a customer what to add is not evidence they added it.

### Mailbox — `EmailMailboxState` (8 states, terminal: `deleted`)

```
  requested ──► provisioning ──► active ──► suspended
      │              │  │          │           │
      │              │  ▼          ▼           │
      │              │ reconciling ◄───────────┘
      │              ▼      │
      │   provisioning_failed
      │              │      │
      └──────────────┴──────┴──► deleting ──► deleted
```
**No state reaches `deleted` except `deleting`** — every deletion passes one governed, auditable, abandonable stage. `deleting → reconciling` exists because a delete that times out is ambiguous too.

### Alias / Forwarder — `EmailRoutingRuleState` (7 states, terminal: `removed`)

```
  requested ──► provisioning ──► active ──► removing ──► removed
                    │  │            │          ▲   │
                    │  ▼            ▼          │   ▼
                    │ reconciling ◄────────────┘ reconciling
                    ▼      │
         provisioning_failed
```

### Catch-all — `EmailCatchAllState` (6 states, no terminal)

```
  disabled ──► configuring ──► enabled ──► disabling ──► disabled
                  │  │            │  ▲         │
                  │  ▼            │  └─────────┘   (re-configure)
                  │ reconciling ◄─┘
                  ▼
       configuration_failed
```

---

## 9. Capability registry

15 capabilities, each declaring 18 required governance facts.

| Capability | Risk | Rev. | Cost | Approval | Idempotency | Cust. | Contract method |
|---|---|---|---|---|---|---|---|
| `email.domain.onboard` | low | ✓ | none | automatic | derived | ✓ | `getDomainAuthStatus` |
| `email.domain.verify` | low | ✓ | none | automatic | read-only | ✓ | `verifyDomain` |
| `email.mailbox.create` | medium | ✓ | **billable** | automatic | caller key | ✓ | `createMailbox` |
| `email.mailbox.update` | low | ✓ | **billable** | automatic | caller key | ✓ | `setMailboxQuota` |
| `email.mailbox.suspend` | **high** | ✓ | operational | protected | caller key | ✓ | `suspendMailbox` |
| `email.mailbox.restore` | medium | ✓ | operational | protected | caller key | ✓ | *gap* |
| `email.mailbox.delete` | **irreversible** | ✗ | billable | **separation of duties** | caller key | ✓ | `deleteMailbox` |
| `email.password.reset` | **high** | ✗ | operational | protected | caller key | ✓ | `requestPasswordReset` |
| `email.alias.create` | low | ✓ | operational | automatic | caller key | ✓ | `createAlias` |
| `email.alias.delete` | medium | ✓ | operational | protected | caller key | ✓ | *gap* |
| `email.forwarder.create` | **high** | ✓ | operational | protected | caller key | ✓ | `createForwarder` |
| `email.forwarder.delete` | medium | ✓ | operational | protected | caller key | ✓ | *gap* |
| `email.catchall.configure` | **high** | ✓ | operational | protected | caller key | ✓ | *gap* |
| `email.usage.sync` | low | ✓ | none | automatic | caller key | ✗ | *gap* |
| `email.health.observe` | low | ✓ | none | automatic | read-only | ✗ | `healthCheck` |

Each entry also declares: `resource_type`, `entitlement`, `admin_available`, `requires_provider`, `provider_mutation`, `connector_capability` (always the generic `email`), `connector_contract`, `contract_gap`, and its emitted events (15 unique event names, one per capability).

**Contract gaps are declared, not hidden.** `EmailProviderConnector` predates this milestone and declares ten methods. Five capabilities have no method on it. Those entries carry `connector_method => null` and `contract_gap => true`, and a test pins the exact list. **That list is the E2 worklist**, and E2's exit criterion is that it becomes empty.

**Why a separate registry from `InfrastructureCapabilityRegistry`:** that class is the registry of operations INFRA888 can execute *today* — every entry has a working handler. Business Email has none. Merging fifteen handler-less entries into it would make it claim fifteen working operations that fail at the first call, which is precisely the `keyword_research` defect the Phase 0 audit named (registered in the capability map, returns `not_implemented`). A test asserts the two registries stay disjoint until E3 merges them deliberately.

---

## 10. Governance rules

1. **Approval is proportionate to blast radius.** Automatic for low-risk creates; `protected` for anything that stops mail someone relies on; **separation of duties** for mailbox deletion, the one operation that destroys data no rollback restores. A test asserts every high-risk and every irreversible capability is gated.
2. **A requester may never approve their own irreversible action.** Enforced in the engine and proven: self-approval returns `APPROVAL_REQUIRED` and records **no** operation.
3. **Accepted ≠ verified.** `EmailDomain::isDnsVerified()` requires both the evidence state and the timestamp that evidence produced. Nothing may be provisioned beneath an unverified domain.
4. **Elapsed time never promotes a state.** No machine has a time-based edge.
5. **Ambiguity is a state, not a coin flip.** Every mutating state can reach `reconciling`; `reconciling` can never return to the mutating state.
6. **Illegal transitions fail closed** with a typed refusal, not an uncaught exception.
7. **Custody gates action, not observation.** A domain with `custody = unknown` cannot be provisioned; it can still be observed, because refusing to look is how a misattributed domain stays misattributed.
8. **Loop safety blocks by default.** `unchecked` is blocking; `safe` is named for what it can prove ("no loop visible in records we hold"), not for what it cannot.
9. **Privileged local parts are not self-service.** RFC 2142 / CA-Browser-Forum addresses (`postmaster@`, `admin@`, `hostmaster@`, …) are refused from `source = manual` and permitted deliberately by an operator.
10. **Denials are audited; only legitimate-but-blocked requests open operations.** A denial writes `infra_events` and stops. A valid request that cannot proceed because no provider exists opens an `infra_operations` row and **leaves it in `requested`** — deliberately non-terminal, so the same idempotency key resumes the work once a provider is configured instead of replaying a stale refusal forever.
11. **Mutating capabilities must be given an idempotency key; the engine refuses to invent one.** An unstable key looks like protection and is not.
12. **No credential material is stored, logged, returned or serialised.** `email.password.reset` carries `never_log_result => true`.

---

## 11. White-label guards

`tests/Feature/Infrastructure/Email/BusinessEmailWhiteLabelGuardTest.php` — 16 tests, 393 assertions.

**Scanned surfaces:** `app/`, `config/`, `database/migrations/`, `routes/`, `resources/js`, `resources/views`, `public/app`, `public/marketing` — extensions `php, js, jsx, ts, tsx, vue, html, json`. 4,000+ files per run.
**Sole exclusion:** `app/Connectors/Infrastructure/Email/` (the permitted adapter path — which does not exist in E1, and a test asserts it does not). `vendor/`, `node_modules/`, `storage/`, `.bak-*` and `tests/` are excluded with stated reasons.

**Prohibited identities:** the eight patterns declared in `BusinessEmailWhiteLabelGuardTest::prohibitedPatterns()` — the five vendors named in the E1 directive, plus the two-word product names and their common contractions and legacy names, each tolerant of space, underscore, dot and hyphen separators, matched case-insensitively. The patterns are assembled from fragments at runtime so that neither the test file nor this document contains a prohibited literal in readable form; the test is the authoritative list.

**No comment stripping.** Unlike `ObservationArchitectureTest`, this guard reads raw source *including comments*. A vendor name in a comment is still a leak — comments get copied into commit messages and pasted into customer docs. The rule is easier to keep than to qualify, and the guard file itself proves it is keepable: it discusses the prohibition at length without naming anything.

**What the 16 guards assert**

| Guard | Asserts |
|---|---|
| engine source names no vendor | ≥15 files scanned, zero matches |
| no platform surface names a vendor | >500 files scanned, zero matches |
| no migration names a vendor | full migrations directory |
| email migrations declare no vendor-named identifier | every quoted literal in the 6 migrations |
| every model declares both projections | `toCustomerArray()` + `toAdminArray()` on all 6 |
| customer projections never expose provider identity | 15 forbidden keys + any `^provider[_.]` or `_ref$` shaped key |
| customer projections never expose raw provider errors | 11 forbidden keys |
| provider binding hidden from default serialisation | `$hidden` contains `provider_connection_id` |
| customer failure messages leak nothing | no vendor, no URL, no HTTP status |
| every failure code is complete | message + valid retry class + normalized state |
| the engine never constructs a verified result | no `ProviderResult::verified(` anywhere in the engine |
| the engine makes no network call | 7 pattern families: HTTP client, Guzzle, cURL, sockets, remote file access, shell, DNS |
| no vendor adapter directory exists | E5 gate |
| no Business Email route is registered | no customer or admin surface |
| capability registry is provider-agnostic | JSON of all 15 entries |
| engine resolves no provider and says so | `connector() === null`, typed `unavailable` result |

Plus, at the database level in `BusinessEmailSchemaTest`: no table, column or index name in the live schema may match a vendor pattern, and no `email_*` column may end in `_ref`.

### Controlled injection proof

| Step | Result |
|---|---|
| 1. Baseline `EmailAddress.php` | md5 `8f4a8cc46a8dfe778f7b3befa6e6ece5`, 5,224 bytes |
| 2. Guard before injection | **16 passed (393 assertions)** |
| 3. Inject one vendor name **in a comment** | md5 `ffdab177ca9c4888d48cd08a836a03e0`, 5,262 bytes |
| 4. Guard with violation present | **2 failed, 14 passed — exit code 1** (both the engine-scoped and platform-wide guards fired, naming the exact file and pattern label) |
| 5. Restore from pristine copy | md5 `8f4a8cc46a8dfe778f7b3befa6e6ece5`, 5,224 bytes — **byte-for-byte identical** |
| 6. Guard after restoration | **16 passed (393 assertions) — exit code 0** |
| 7. Repo-wide grep for the injected literal | **zero occurrences** across `app config database routes resources tests public` |

No injected violation was committed. Nothing is committed at all — see §14.

---

## 12. Test matrix

All runs against `levelup_infra_e1_7c3f21a4_test` via `phpunit.e1.xml`, by explicit file path.

| Test file | Tests | Assertions | Result | Proves |
|---|---:|---:|---|---|
| `BusinessEmailWhiteLabelGuardTest` | 16 | 393 | **PASS** | vendor-name guards across 8 scan roots; customer-projection leak guards; no fabricated success; no network call; no adapter; no route |
| `BusinessEmailSchemaTest` | 23 | 1,368 | **PASS** | tables, columns, nullability, indexes, unique constraints, FK delete rules, CHECK constraints (proven by rejected inserts), live-row uniqueness, no credential columns, no `_ref` columns, no vendor identifiers in the live schema, reuse decision |
| `BusinessEmailMigrationReplayTest` | 6 | 62 | **PASS** | clean install from zero, migrations recorded, unique timestamps, down/up cycle, idempotent re-run, touches only its own tables |
| `BusinessEmailLifecycleTest` | 20 | 726 | **PASS** | exhaustive state-pair validity (334 pairs), reachability, terminal behaviour, evidence requirements, governance declarations, the no-double-provision and no-shortcut-to-verified rules |
| `BusinessEmailModelTest` | 32 | 105 | **PASS** | tenancy isolation across all 6 entities, immutable `workspace_id`, relationships, cascade/restrict behaviour, mass-assignment drift both ways, casts, model defaults vs machine `initial()`, target exclusivity, address normalisation, loop safety |
| `BusinessEmailCapabilityRegistryTest` | 19 | 652 | **PASS** | all 15 capabilities registered with 18 governance facts each, closed vocabulary, declared connector methods exist, contract gaps declared not invented, governance proportionate to risk, unique event names, registry separation |
| `BusinessEmailEngineTest` | 31 | 127 | **PASS** | resolver honesty, every denial path, operation recording, idempotency (same key → one operation), separation of duties, audit trail, no verified result on any path, no success on any of the 11 mutating capabilities |
| **E1 total** | **147** | **3,433** | **PASS** | |

### 12.1 Regression against baseline

`php artisan test -c phpunit.e1.xml --testsuite Infrastructure` — this suite includes `tests/Feature/Infrastructure/Email`, so the two runs are directly comparable.

| | Tests passed | Failed | Skipped | Assertions |
|---|---:|---:|---:|---:|
| **Baseline** (before any E1 file existed, HEAD `f04a6b3`) | 486 | **2** | 1 | 1,732 |
| **After E1** | 633 | **2** | 1 | 5,165 |
| **Delta** | **+147** | **0** | 0 | **+3,433** |

The delta in passing tests is exactly the E1 suite. **No pre-existing test changed result.**

**The 2 failures are pre-existing and are not E1's.** Both are in `RegistrationDriftTest`, and both are caused by the `registrar` connector another INFRA888 work package added to `config/infrastructure.php` on 2026-07-29:

- `test_resolver_returns_a_connector_whose_capability_matches_its_key` — the container cannot resolve `NamecheapClient`'s `string $environment` constructor parameter.
- `test_unconfigured_capability_throws_rather_than_silently_no_ops` — asserts resolving `registrar` throws `RuntimeException`; it now throws `BindingResolutionException` instead, because a registrar connector exists.

They were failing before E1 started and are failing identically after. E1 did not touch, fix or work around them — that file belongs to another work package.

### 12.2 Defects the tests found in E1's own code

Both were found by tests written to assert intent rather than to confirm the implementation, and both were real.

**1. `UNIQUE(domain, deleted_at)` constrained nothing.**
The intent was "one live mail domain per name, unlimited soft-deleted history". MySQL permits duplicate rows in a unique index whenever **any** indexed column is NULL — and `deleted_at` is NULL for every live row. The constraint existed, read correctly in the migration, appeared in `information_schema`, and allowed two workspaces to register the same domain. The same defect was present on `email_mailboxes`, `email_aliases` and `email_forwarders`.

*Fix:* a generated column `active_flag TINYINT UNSIGNED GENERATED ALWAYS AS (IF(deleted_at IS NULL, 1, NULL)) STORED` participates in each identity key. Live rows carry `1` and are constrained; soft-deleted rows carry NULL and are exempt — by the same rule that caused the problem. MySQL 8 has no partial indexes, and `IF(deleted_at IS NULL, …)` is deterministic and therefore legal in a generated column. Covered by four new assertions including a positive duplicate-rejection test and a soft-delete-then-reuse test.

**2. Freshly created models had no lifecycle state.**
Eloquent does not read database defaults back after an insert, so a just-created entity held an empty `lifecycle_state` in memory while the row on disk held `connected`. The first `transitionTo()` failed with `unknown current state ''`. Worse, `EmailForwarder::loop_check_state` held NULL rather than `unchecked` — and NULL is **not** a blocking value, so the loop-safety default would have been bypassed by the very path that creates forwarders.

*Fix:* every model declares `$attributes` seeded from its state machine's constants, making the model authoritative about its own initial state. A test asserts the model defaults equal each machine's `initial()`, so the two declarations cannot drift.

Note that `test_a_model_refuses_an_illegal_transition` was *passing* before this fix — for the wrong reason. It expected an `InvalidArgumentException` and got one, but from the empty state rather than from the illegal transition. Fixing the defect made that test meaningful.

---

## 13. Migration evidence

**Timestamps.** Six migrations, `2026_08_04_140501` → `140506`, chosen with second-level precision. Zero `2026_08_04_*` migrations existed before this work. Collisions re-checked immediately before the first write: **0 for all six**. The repository has 14 pre-existing timestamp collisions among other migrations; none involves these. `BusinessEmailMigrationReplayTest::test_the_migration_family_has_unique_timestamps` asserts this permanently.

**Atomicity.** All six were authored locally in one block and transferred in a single operation. No partial family ever existed on disk.

**Clean install from zero.** `migrate:fresh` over the full 251-migration chain: **PASS** (187.9s). All six tables present afterwards; all six recorded in the `migrations` table.

**Rollback and re-run.** Every migration's `down()` executed in reverse dependency order (usage → catch-all → forwarders → aliases → mailboxes → domains), all six tables confirmed gone, then every `up()` re-executed from the same source and all six confirmed back: **PASS** (147.9s). This exercises the foreign-key direction, the named indexes, and the generated columns.

**Idempotent re-run.** Each `up()` executed a second time against an existing schema — every migration guards on `Schema::hasTable`, so a partial deploy is recoverable by re-running rather than by hand-editing: **PASS** (139.6s), with data confirmed unaltered.

**Ownership.** `test_no_business_email_migration_touches_a_table_it_does_not_own` parses each migration for `Schema::create|table|drop|dropIfExists` and asserts every target is one of the six. E1 alters nothing pre-existing.

**Key widths.** The widest key is `email_fwd_identity_uq` at 8 + 64×4 + 320×4 + 1 = **1,553 bytes**, inside MySQL's 3,072-byte limit. That limit has already broken a clean install on this project once, which is why it is stated rather than assumed.

**Live schema, verified after the run:**

| Table | Unique identity constraint | CHECK constraints |
|---|---|---|
| `email_domains` | `(domain, active_flag)` | — |
| `email_mailboxes` | `(email_domain_id, local_part, active_flag)` | — |
| `email_aliases` | `(email_domain_id, source_local_part, active_flag)` | `email_alias_target_chk` |
| `email_forwarders` | `(email_domain_id, source_local_part, destination_address, active_flag)` | — |
| `email_catchall` | `(email_domain_id)` | `email_ca_target_chk` |
| `email_usage` | `(workspace_id, idempotency_key)` | `email_usg_scope_chk` |

All three CHECK constraints are proven by tests that attempt an invalid raw insert (bypassing the model guards) and require the database to reject it.

**Where they ran.** Only `levelup_infra_e1_7c3f21a4_test`. Verified after the run: **0** of the six tables exist in `levelup_staging`; the only `email_*` tables there remain Marketing's four (`email_blocks`, `email_campaigns_log`, `email_links`, `email_templates`).

---

## 14. Parallel-session classification

Multiple Claude sessions were active on `/var/www/levelup-staging` throughout E1. Observed concurrent work at the time of writing:

| Workstream | Evidence | E1 interaction |
|---|---|---|
| **Studio (Execution/Selection layer)** | `app/Engines/Studio/Execution/**` and `tests/Feature/Studio/Execution/**` written 13:36–13:40 UTC on 2026-08-04 | None. No shared file. |
| **Platform / WP connector schema** | live `php artisan test -c phpunit.e888.xml tests/Feature/Platform/WpConnectorSchemaGuardTest.php` at 13:45 | None, except it justified taking a dedicated database. |
| **Engineer888** | `app/Core/Engineer888/**` untracked tree, `phpunit.e888.xml` modified | None. |
| **INFRA888 registrar (Namecheap)** | `config/infrastructure.php` modified 2026-07-29 adding a `registrar` connector; `app/Connectors/Infrastructure/Namecheap/` untracked | **Same workstream lineage, different work package.** Its change causes the 2 pre-existing regression failures recorded in §12.1. E1 did not touch, fix or work around it. |
| **AI provenance (D-01/D-02)** | `app/Connectors/RuntimeClient.php` modified | None. |

**Conflict handling:**
- A dedicated database `levelup_infra_e1_7c3f21a4_test` was created (did not previously exist), granted to `levelup_tester`, and proven to have **0 tables and 0 connections** at claim time. `levelup_test`, `levelup_e888_test`, `levelup_p1e1_test`, `levelup_chat_test` and `levelup_infra_s84_998fd8c8_test` were all treated as owned by other packages and never touched.
- A migration and source manifest was frozen before any write (`/tmp/e1-evidence/migrations.manifest`, `source.manifest`, 245 migrations + 11 source files). Re-verified immediately before the first migration write: **all frozen files unchanged**.
- Migration timestamps were re-checked for collisions immediately before writing. Zero `2026_08_04_*` migrations existed; the six chosen timestamps had zero collisions. The whole family was written in one block.
- `BusinessEmailMigrationReplayTest::test_no_business_email_migration_touches_a_table_it_does_not_own` asserts permanently that E1 migrations create only their own six tables and alter nothing pre-existing.

**Files modified belonging to another session: none. Files staged or committed: none.**

---

## 15. Production impact

**None.**

- No migration was run against `levelup_staging`. Every migration ran only against `levelup_infra_e1_7c3f21a4_test`. `ProductionDatabaseGuard` (denylist + positive allowlist + host denylist + APP_ENV agreement) fires before any connection is opened, and `phpunit.e1.xml` forces the target with `force="true"`.
- The six `email_*` tables **do not exist in `levelup_staging`**. They are new files on disk that have not been migrated in production.
- No route, controller, job, command, scheduled task, notification, event listener or service-provider binding was registered. The engine is resolvable from the container but nothing calls it.
- No customer-facing surface changed. The existing "Email Accounts" nav item remains exactly as it was — still a "coming soon" empty state, still masterplan risk #1, still unresolved (see §17).
- No network call was made from the engine, and a guard proves none can be.
- No provider account was created, no credential was configured, no mailbox was provisioned, no DNS record was changed, and PTAA production email was not touched.

---

## 16. Rollback

E1 is rolled back by deleting files. Nothing was modified, so nothing needs reverting.

```bash
cd /var/www/levelup-staging
rm -rf app/Engines/Infrastructure/Email
rm -rf tests/Feature/Infrastructure/Email
rm -f database/migrations/2026_08_04_14050{1,2,3,4,5,6}_create_email_*.php
rm -f phpunit.e1.xml
mysql -e "DROP DATABASE IF EXISTS levelup_infra_e1_7c3f21a4_test;"
mysql -e "REVOKE ALL PRIVILEGES ON \`levelup_infra_e1_7c3f21a4_test\`.* FROM 'levelup_tester'@'localhost';"
```

If the migrations have by then been applied somewhere, `php artisan migrate:rollback --step=1` reverses them; each migration's `down()` is a single `dropIfExists`, and the family's down/up cycle is proven in `BusinessEmailMigrationReplayTest`.

**No production rollback is required, because nothing reached production.**

---

## 17. Known limitations

1. **Nothing executes.** E1 is authorisation, recording and refusal. Every mutating request ends in `PROVIDER_NOT_CONFIGURED`. This is the intended state, but it means the execution path itself (connector call → read-back → state transition) is unwritten and therefore unproven.
2. **Five capabilities have no contract method.** `email.mailbox.restore`, `email.alias.delete`, `email.forwarder.delete`, `email.catchall.configure`, `email.usage.sync`. Declared as gaps; E2 closes them.
3. **`EmailProviderConnector` was not modified.** It predates E1 and finalising it is E2's job. It currently has no `updateMailbox` for display names (only `setMailboxQuota`), no `listMailboxes`/`listAliases` reconciliation source, and no `getMailboxStats`. The masterplan §4 lists these; E1 did not add them because changing the contract before proving it against a fake adapter is the wrong order.
4. **Entitlements are enforced but not resolved.** `EmailOperationContext` receives a resolved entitlement map. Wiring it to `InfrastructureEntitlementService` is E3. The keys (`business_email_access`, `business_email_mailbox_limit`, `business_email_domain_limit`) are declared in the registry rather than `config/infrastructure.php` — see §4 for why E1 touched no shared config.
5. **Cross-entity local-part uniqueness is engine-level, not database-level.** A mailbox `sales@`, an alias `sales@` and a forwarder `sales@` on the same domain are each unique within their own table but could collide across tables. A cross-table constraint is not expressible in MySQL; E3's create path must check, and E2's fake-adapter suite should include the collision case.
6. **Health rollup is a column, not a pipeline.** `email_domains.health_state` exists and defaults to `unknown`. Nothing populates it yet; E9 wires the S6–S8.3 observation stack into it.
7. **`email_*` shares a table-name prefix with Marketing's campaign builder** (`email_blocks`, `email_campaigns_log`, `email_links`, `email_templates`). No name collides, and the entity names were supplied as approved, but the prefix is now ambiguous in the schema. Worth a naming convention note before E4 adds customer screens.
8. **The masterplan's stated entity names `email_operations` and `email_observations` were intentionally not created.** This is a documented reuse decision under the E1-C instruction, not a redesign — but it is a deviation from the masterplan's §3 entity table and is flagged here so it is a conscious carry-forward rather than a surprise.
9. **The customer "Email Accounts" nav item is still live with no product behind it.** Masterplan §13 risk 1 and §14 decision 2 remain open. E1 did not touch it because it belongs to the customer frontend and no decision has been recorded. **This is the highest-priority open product decision.**
10. **The 72-hour cold-start and confidence machinery from `AssetLifecycle` is reused for usage confidence but not yet applied to email domain health.** E9.

---

## 18. E2 entry conditions

E2 is *"provider contract finalised + Null/Fake adapter + full test suite against the fake"*.

E2 may begin when all of the following hold:

1. **E1 is green** — all seven E1 test files pass, and the INFRA888 regression is no worse than the recorded baseline. *(See §12.1.)*
2. **The five contract gaps are the agreed E2 scope.** `BusinessEmailCapabilityRegistryTest::test_the_contract_gap_list_is_the_e2_worklist` pins the exact list; E2's exit criterion is that `BusinessEmailCapabilityRegistry::contractGaps()` returns `[]`.
3. **The fake adapter lives in `tests/`, not `app/`.** The white-label guard permits vendor names only under `app/Connectors/Infrastructure/Email/<Vendor>/`; a Fake is not a vendor and must not create that directory, or `test_no_vendor_adapter_directory_exists` will correctly fail. E5 is the milestone that creates it.
4. **No Null email connector may be added to `config/infrastructure.php` that returns success.** The locked rule stands. E2's fake is a test double registered through `InfrastructureConnectorResolver::fake()`, which already exists as a test seam.
5. **E2 must add the execution path**, and with it the first real use of `reconciling`: a connector call whose response is ambiguous must land in `reconciling` and be resolved by read-back only. The state machines already forbid the unsafe alternative; E2 has to honour it.
6. **A decision on the live "Email Accounts" nav item** (§17.9). Not a technical blocker for E2, but it becomes one for E4.

**E2 does not require:** a provider account, a purchase, DNS changes, or any PTAA involvement.

---

## 19. Honest readiness score

**E1 as specified: 9 / 10.**

What earns it: every E1-A→E1-I deliverable exists, is tested, and the tests assert intent rather than confirm the implementation — which is why they found two real defects, one of which (`UNIQUE(domain, deleted_at)` constraining nothing) would have shipped silently and been discovered only when two tenants collided on a live domain. The guards are proven by controlled injection rather than asserted. Zero existing files were modified, so the parallel-session risk is structurally nil. The regression is bit-for-bit unchanged against baseline.

What costs the point: two things I would rather have done differently.

- `test_a_model_refuses_an_illegal_transition` passed for the wrong reason before the defaults defect was fixed. A green test on a wrong code path is worse than no test, and I only caught it because a *different* test failed. There are likely other tests in this suite whose green is less informative than it looks, and I have no systematic way to tell which.
- `BusinessEmailModelTest` has 32 tests but only 105 assertions. That ratio is low for the surface it covers, and it reflects tests that assert one thing where they could assert three.

**Business Email as a product: 1 / 10.** This is worth stating plainly because §12's numbers could easily be read as more than they are. E1 built the foundation and nothing else. A customer cannot create a mailbox, receive mail, or see anything that was not already there. Every mutating request in the entire engine currently returns "Business Email is not available on this account yet." Nine milestones remain (E2–E10), and the two that determine whether this becomes a product — a working adapter (E5) and a customer portal (E4) — have not started.

**Confidence that the white-label rule will survive future sessions: high.** That is the one claim I would defend strongly. The rule is enforced by a test that scans 4,000+ files across eight surfaces on every run, fails on a vendor name in a *comment*, and was demonstrated failing and recovering. It does not depend on anyone remembering it.

**Confidence that the schema is right: medium-high.** The reuse decisions (§2) are the part most likely to be revisited. Reusing `infra_operations` and `infra_observation_facts` is clearly correct. Reusing `infra_provider_resources` instead of per-entity `provider_*_ref` columns is correct but is a deviation from the masterplan's §3 entity table, and E3 will be the first code to actually exercise that join — if it proves awkward, that is when it will show.

**What I did NOT verify:** nothing in this milestone was exercised through a browser, an HTTP request, or a queue worker, because E1 registers no route, no job and no command. The engine has been proven correct in-process only. That is appropriate for E1 and would not be for E3.

---

## 20. Summary

| Question | Answer |
|---|---|
| Did E1 pass? | **Yes** — 147 tests, 3,433 assertions, all green |
| Architecture guards | 16 tests, 393 assertions, **PASS** |
| Controlled injection | **Proven** — guard failed on one vendor name in a comment (exit 1), file restored byte-for-byte (identical md5 and size), guard passed again, zero residue repo-wide |
| Migrations created | 6 (`2026_08_04_140501`–`140506`) |
| Tables created | 6 — `email_domains`, `email_mailboxes`, `email_aliases`, `email_forwarders`, `email_catchall`, `email_usage` |
| Tables reused | 10 — including `infra_operations` and `infra_observation_facts` **instead of** creating `email_operations` / `email_observations` |
| Models created | 6, all workspace-scoped, all with enumerated customer/admin projections |
| Lifecycle states | 43 across 6 machines (domain 10, verification 6, mailbox 8, alias 7, forwarder 7, catch-all 6) |
| Capabilities registered | 15, each with 18 governance facts; 5 declared contract gaps = the E2 worklist |
| Fresh-install result | **PASS** — full 251-migration chain replays from zero; family rolls back and re-runs cleanly; re-run is idempotent |
| INFRA888 regression | **633 passed / 2 failed / 1 skipped** vs baseline **486 / 2 / 1** — same 2 pre-existing failures, +147 tests, +3,433 assertions, no pre-existing test changed result |
| Files created | 33 (19 engine classes, 7 tests, 6 migrations, 1 phpunit config) |
| Files modified | **0** |
| Production impact | **None** — 0 of the 6 tables exist in `levelup_staging`; no route, job, command or binding registered; no network call; no provider account; PTAA untouched |
| Parallel-session conflicts | **None** — 5 concurrent workstreams identified, no shared file touched, dedicated database proven exclusive, frozen source manifest unchanged before and after |
| Readiness | E1 scope **9/10**; Business Email as a product **1/10** |
| Document | `C:\Users\markr\LVL\INFRA888-E1-BUSINESS-EMAIL-FOUNDATION.md` (mirrored to `/var/www/levelup-staging/`) |
| May E2 begin? | **Yes**, subject to §18 |

---
