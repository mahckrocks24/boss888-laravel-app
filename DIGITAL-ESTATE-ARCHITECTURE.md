# DIGITAL ESTATE ARCHITECTURE
### The foundation model for INFRA888 — the Digital Estate Operating Layer
**Enterprise architecture · 2026-08-02 · Owner: INFRA888 · Foundation document**

---

## 0. PURPOSE AND ACCEPTANCE TEST

INFRA888 manages one thing: **a customer's Digital Estate**. Everything else — hosting, domains, certificates, mailboxes, applications, agents — is an asset inside it.

**The acceptance test for this architecture is the absence of special cases.** A domain, a TLS certificate, a Postgres database and an AI agent must share one identity model, one lifecycle, one health model, one permission model and one operations model. If a new asset type requires a change to the core, the core is wrong.

Every decision below answers: *does this improve our ability to manage a customer's Digital Estate across its entire lifecycle?*

### Architectural principles

1. **The Estate is the aggregate root.** Nothing exists outside an estate.
2. **Identity is ours, never the provider's.** Provider resource IDs are attributes of a binding, not of the asset.
3. **Relationships are first-class and typed.** The edge carries semantics, not just a link.
4. **Desired state and observed state are separate facts.** Their divergence is drift, and drift is a managed condition, not an error.
5. **All change flows through Operations.** Assets are read models; operations are the write path.
6. **Health is derived and multi-dimensional.** Never a stored single number.
7. **Extension happens at the edges.** New types and providers are declarations, not core changes.

---

## 1. THE DIGITAL ESTATE — entity model

### 1.1 Estate

The aggregate root and the boundary for tenancy, permissions, billing, health and reporting. **One customer, one estate**, for their whole relationship with us — regardless of how many products they hold or which stage of maturity they occupy.

| Attribute | Purpose |
|---|---|
| Estate identity | Stable, permanent, survives every product change |
| Owning party | The customer organisation |
| Tenancy anchor | The workspace boundary all access derives from |
| Service tier | Criticality, response commitments, RPO/RTO class |
| Maturity stage | Current position in the maturity model (Masterplan v3 §1) |
| Lifecycle state | Prospective · Active · Suspended · Terminating · Archived |
| Health summary | Derived, never stored as truth |
| Commercial linkage | Reference to the billing relationship |

**A prospective estate is legitimate and important.** A Health Audit on a non-customer creates an estate in *Prospective* state populated entirely with externally-observed assets. That estate becomes the customer's real estate on conversion, carrying its history — including the findings that won them.

> **Design note.** Modelling prospects as estates rather than as a separate CRM concept is deliberate: the audit, the migration and the managed relationship then operate on one continuous object, and we can prove improvement over time against a baseline we recorded before they were a customer.

### 1.2 Asset

The universal unit. Everything INFRA888 manages is an asset.

**Core attributes — identical for every asset type:**

| Attribute | Purpose |
|---|---|
| Asset identity | Ours. Permanent. Never a provider identifier |
| Estate | Owning estate — mandatory, immutable |
| Type | Reference to an Asset Type declaration (§2) |
| Name / label | Human-meaningful |
| **Custody** | Who actually controls it (§1.3) |
| Lifecycle state | From the universal state machine (§5) |
| Desired state | What we intend to be true |
| Observed state | What was last seen to be true |
| Last verified at | When observation last occurred — **staleness is a first-class concern** |
| Criticality | Inherited from estate, overridable per asset |
| Health | Derived (§6) |
| Commercial attribution | What this asset costs us and contributes |
| Provenance | How it entered the estate: provisioned, migrated, discovered, declared |

**Type-specific attributes live in a typed facet** governed by the type's declared schema. A domain facet carries registrar, expiry, transfer lock, nameservers. A certificate facet carries issuer, subject, expiry, chain. The core never knows what those mean.

### 1.3 Custody — an attribute most infrastructure models omit

**Custody records who genuinely controls an asset**, which is distinct from who owns the estate.

| Custody | Meaning |
|---|---|
| **Managed** | We control it and are accountable |
| **Customer-held** | The customer controls it; we observe and advise |
| **Third-party-held** | A previous agency, developer or vendor controls it |
| **Disputed / unknown** | Control cannot be established |

This exists because the highest-value finding in the Health Audit is an ownership finding — a business discovering its domain is registered to a developer who left years ago. That is not a health problem or a configuration problem; it is a **custody** problem, and it needs somewhere to live in the model.

Custody also drives what operations are *possible*: we cannot renew a domain we do not control, and the estate must represent that honestly rather than showing a broken action.

---

## 2. ASSET HIERARCHY AND TAXONOMY

### 2.1 Type declarations, not subclasses

An **Asset Type** is a declaration describing: its class, its facet schema, its permitted lifecycle transitions, its health dimensions, its permitted relationship types, the capability required to manage it, and its operations.

**Adding a type is a declaration. It requires no change to the core.** This is the mechanism that satisfies the no-special-cases test.

### 2.2 Asset classes

Classes group types for policy, presentation and permission. They are a *taxonomy*, not an inheritance hierarchy.

| Class | Example types | Characteristic |
|---|---|---|
| **Identity** | Domain, subdomain | The customer's name in the world; expiry-bearing; highest consequence of loss |
| **Naming** | DNS zone, DNS record | Resolution and routing |
| **Trust** | TLS certificate | Expiry-bearing; automatable; silent failure mode |
| **Compute** | Site environment, application environment, container workload, function | Runs code |
| **Data** | Database, object storage bucket, file volume | Holds state; backup-bearing |
| **Communication** | Mailbox, alias, forwarder, catch-all, mail domain | Deliverability-bearing |
| **Automation** | Scheduled job, queue worker, AI agent runtime | Executes on a schedule or trigger |
| **Interface** | API endpoint, webhook receiver, integration | Contract with the outside world |
| **Protection** | Backup set, restore point, DR plan | Exists to recover other assets |
| **Observation** | Monitor, health check, alert rule | Exists to watch other assets |
| **Secret** | Credential, key, token *(referenced, never stored here)* | Access-bearing |
| **Provider relationship** | Provider account, provider credential binding | Our commercial and technical link to a vendor |

> **AI agents are Automation-class assets.** They have a lifecycle, a health state, dependencies (data, compute, credentials), and an operational blast radius. They belong in the estate for exactly the same reasons a cron job does.
> *Dependency: AI Runtime. Owned by another workstream. Not modified.* INFRA888 models the agent as an estate asset and owns its infrastructure lifecycle only.

### 2.3 Composition versus hierarchy

**There is no rigid parent-child tree.** A website is not "inside" a domain; it *depends on* one. Trees force false hierarchies and break the moment an asset is shared — one certificate securing several sites, one database serving two applications.

**Composition is expressed as a relationship** (`contains`), which makes it one edge type among several rather than a structural constraint. The estate is therefore a **graph with a single root**, not a tree.

---

## 3. PROVIDER BINDING — separating identity from vendor

**The most consequential structural decision in this document.**

A naive model places `provider` and `provider_resource_id` on the asset. That welds our identity to a vendor: migrating providers becomes a data migration, and the exit strategy becomes theoretical.

Instead, a **Provider Binding** is its own entity:

| Attribute | Purpose |
|---|---|
| Asset | The asset it binds |
| Provider account | Which vendor relationship |
| Provider resource identity | The vendor's identifier |
| Binding state | Active · Migrating · Superseded · Orphaned |
| Observed provider state | Last known truth from the vendor |
| Last reconciled at | Staleness of that truth |

**Consequences:**

- An asset can be **re-bound** to a different provider without changing identity, history, relationships or customer-visible references. Provider migration becomes an operation, not a rebuild.
- An asset may hold **more than one binding** during migration — the old one *Superseded*, the new one *Active* — which is what makes zero-downtime provider migration expressible.
- **Orphaned** bindings — provider resources we are paying for with no corresponding asset — become discoverable. Orphan detection is a margin control, and it needs a place in the model to exist at all.
- The asset model stays clean of vendor concepts. Nothing above the binding layer knows what a droplet is.

---

## 4. RELATIONSHIPS AND THE DEPENDENCY GRAPH

### 4.1 Typed edges

A relationship is a **directed, typed edge** between two assets. **The type carries semantics** — this is what distinguishes a dependency graph from a link table.

| Edge type | Meaning | Health propagates | Deletion semantics |
|---|---|---|---|
| `requires` | Hard dependency — target failure means source failure | **Yes, fully** | Blocks target deletion |
| `uses` | Soft dependency — degraded, not failed | **Partially** | Warns |
| `contains` | Composition — source is composed of target | Yes | Cascades |
| `resolves_to` | DNS name → destination | Yes | Blocks |
| `secures` | Certificate → protected asset | Yes | Warns |
| `routes_to` | Traffic direction | Yes | Blocks |
| `delivers_mail_for` | Mail service → domain | Yes | Blocks |
| `backs_up` | Backup set → protected asset | **Inverse** — the backup's failure degrades the protected asset's *continuity* | Warns |
| `monitors` | Observation → observed | **Inverse** — losing monitoring degrades *confidence*, not availability | Warns |
| `deploys_to` | Application → environment | Yes | Blocks |
| `authenticates_with` | Asset → credential | Yes | Blocks |
| `supersedes` | Replacement during migration | No | Cleanup marker |

**The inverse-propagation cases are the interesting ones.** A failed backup does not make a website unavailable — but it materially degrades its *continuity*. A dead monitor does not break anything — but it destroys our *confidence*. A health model that ignores this reports green while flying blind, which is precisely how infrastructure companies get surprised.

### 4.2 Blast radius and impact

Two traversals, both derived, neither stored:

- **Blast radius** — follow inbound edges from an asset: *"what breaks if this fails?"* Used before every destructive operation and during incident triage.
- **Dependency closure** — follow outbound edges: *"what must be healthy for this to work?"* Used for provisioning ordering and root-cause analysis.

**Every destructive operation must compute blast radius and present it before proceeding.** That is a rule of the operations model (§7), not a UI nicety.

### 4.3 Discovered relationships

Edges may be **declared** (we created them) or **discovered** (observation inferred them — a DNS lookup showing where mail is delivered). Discovered edges are how a Health Audit builds an estate map for a business that is not yet a customer, and they carry lower confidence than declared ones. **Confidence is an attribute of the edge.**

---

## 5. ASSET LIFECYCLE

### 5.1 One state machine

| State | Meaning |
|---|---|
| **Declared** | Known to exist; not yet under management (typical for audit-discovered assets) |
| **Requested** | Provisioning intended, not started |
| **Provisioning** | Being created |
| **Active** | Operating normally |
| **Degraded** | Operating with a known health problem |
| **Suspended** | Deliberately stopped; recoverable; data retained |
| **Migrating** | Moving between providers or environments |
| **Terminating** | Wind-down initiated; grace period running |
| **Terminated** | Stopped; data retained per retention policy |
| **Archived** | Cold retention; recoverable at cost |
| **Deleted** | Irreversible; only the audit record remains |

**Type declarations restrict which transitions apply** — a certificate never suspends — but they never add states. One machine, restricted per type.

### 5.2 Rules

- **Every transition is an Operation** (§7). State never changes as a side effect.
- **Terminated is not Deleted.** The gap between them is the retention window, and it is where recoverability and win-back live.
- **Degraded is a first-class state, not an error.** Most infrastructure spends real time here, and a model that only knows Active/Failed forces operators to lie.
- **Migrating is explicit**, because migration is a permanent capability of this platform, not an exception.
- Deletion requires blast-radius acknowledgement and honours edge-level blocking.

---

## 6. ASSET HEALTH MODEL

### 6.1 Health is derived, multi-dimensional, and never a stored number

A single stored health value is unusable: it cannot be explained, cannot be recomputed, and hides the dimension that matters.

**Six dimensions**, matching the Health Audit so internal and customer-facing health are the same instrument:

| Dimension | Question |
|---|---|
| **Control** | Do we/does the customer actually own and control this? |
| **Availability** | Is it up and responding? |
| **Security** | Is it patched, encrypted, correctly configured, unexposed? |
| **Continuity** | Could we recover it, and has that been proven? |
| **Deliverability** | Does its output reach its destination? *(communication assets)* |
| **Performance** | Is it fast enough for its purpose? |

Not every dimension applies to every type — the type declaration states which do. **Inapplicable is distinct from healthy**, and both are distinct from **unknown**.

### 6.2 Unknown is a health state

If an asset has not been verified within its freshness window, its health is **Unknown**, not Healthy. Stale truth presented as current truth is the failure mode behind most infrastructure surprises, and the model refuses to allow it.

### 6.3 Composition

- **Asset health** = its own observations, combined with propagated health from its dependency closure per edge semantics (§4.1).
- **Estate health** = composition across assets, weighted by criticality — never a plain average, which would let one catastrophic failure hide behind many healthy assets.
- **Estate score** = the Health Audit score, computed by the same engine. The number a prospect sees in an audit and the number a managed customer watches improve are **the same calculation**, which is what makes improvement provable.

---

## 7. OPERATIONS — the write path

### 7.1 Every change is an Operation

No asset changes except through an Operation. This yields audit, idempotency, retry, approval and blast-radius checking universally rather than per feature.

| Attribute | Purpose |
|---|---|
| Operation identity | Stable reference |
| Estate and target asset(s) | Scope |
| Operation type | Declared by the asset type |
| Requested by | Actor: customer, operator, product, automation |
| Intent | Desired end state |
| Idempotency key | Deterministic — a repeated request cannot double-execute |
| State | Pending · Awaiting approval · Executing · Succeeded · Failed · Compensating · Abandoned |
| Attempts and outcome classification | Transient vs terminal |
| Blast radius snapshot | What was at risk when it was authorised |
| Approval record | Who authorised, where required |

### 7.2 Rules

- **Ambiguous provider outcomes are terminal, never retried.** An operation whose effect is unknown must be reconciled by observation, not repeated — repetition risks double-charging and duplicate resources.
- **Accepted is not verified.** A provider acknowledging a request updates *desired* state; only observation updates *observed* state.
- **Destructive operations require blast radius acknowledgement**, and where policy demands, explicit approval.
- **Compensation over rollback.** A partially-completed provision is compensated by an operation that removes what was created; infrastructure rarely supports true rollback.
- Every operation emits lifecycle events for consumers.
  > *Dependency: Platform Events. Owned by another workstream. Not modified.* INFRA888 must remain fully functional if the bus is unavailable.

### 7.3 Reconciliation

A continuous loop compares desired against observed for every asset:

- **Match** → refresh `last_verified_at`.
- **Divergence** → raise drift; remediate automatically where the type permits, otherwise raise an exception.
- **Missing at provider** → the asset exists to us but not to them: incident.
- **Present at provider, absent from us** → **orphan**: cost leak and security exposure.

**Reconciliation is what makes this an operating layer rather than a records system.** Without it, the estate is a list of things we once did.

---

## 8. ESTATE DASHBOARD ARCHITECTURE

### 8.1 Projections, not queries

Dashboards read **projections** built from the asset graph and operations stream — never live graph traversal at request time. Traversal cost grows with estate size; projection cost does not, and the five-year target (§ Masterplan v3) is hundreds of estates and thousands of assets.

### 8.2 The projections

| Projection | Answers |
|---|---|
| **Estate summary** | What do we run for this customer, and is it healthy? |
| **Health scorecard** | Six dimensions, current and trend, with findings |
| **Expiry horizon** | Everything expiring by date, with automation status — domains, certificates, contracts |
| **Dependency map** | The graph, visually, with health overlaid |
| **Operations feed** | What is happening, what failed, what needs a human |
| **Drift register** | Where desired and observed disagree |
| **Estate economics** | Cost to serve versus revenue, per estate |
| **Fleet view** *(internal)* | The same data across all estates, exception-first |

### 8.3 Two planes

**Customer plane** — their estate only, in their language, showing what they own, its health, what needs attention, what we handle for them.

**Control plane** — internal, cross-estate, exception-first: what is failing, what is stale, what is orphaned, what breaches SLA, where margin is leaking.

**Both derive from the same model.** They are different projections and different permissions over one truth — never two systems, which is how the customer view and the operator view come to disagree.

**The morning question the control plane must answer in five seconds:** *is anything wrong that automation has not already handled?* If the answer is no, the operator closes it.

---

## 9. ESTATE API ARCHITECTURE

### 9.1 The one law

> **Every Boss888 product consumes INFRA888. INFRA888 consumes nothing from them.**

A one-way dependency. INFRA888 must be startable, operable and correct with every other product offline.

### 9.2 Surfaces

**Estate Query API** — read the estate: assets, relationships, health, expiry, operations. Consumed by the portal, the control plane, and any product needing infrastructure truth.

**Provisioning Intake API** — a single request surface: *"an asset of this type, for this estate, at this criticality, with these dependencies."* The caller never names a provider. This is the only way assets are created, whether the caller is the Builder, a custom application, or an operator.

**Operations API** — request lifecycle transitions; returns an operation reference, never a synchronous result. Infrastructure changes are asynchronous; an API pretending otherwise forces every consumer to invent its own polling.

**Estate Event Stream** — lifecycle, health and drift events emitted outward.

**Health Audit API** — run an audit on any domain, whether or not it belongs to a customer. This is the only surface that operates on a *prospective* estate.

### 9.3 Consumer contract

Consumers may: request assets, read state and health, subscribe to events, reference assets by our identity.
Consumers may **not**: name providers, write asset state directly, bypass Operations, or hold provider identifiers.

> *Dependencies: Builder, CRM, Marketing, AI Runtime, Domain Commerce. Owned by other workstreams. Not modified.* Each consumes the surfaces above.

---

## 10. ESTATE PERMISSIONS

### 10.1 Scoping

Permission is evaluated against **(actor, estate, asset class, operation, criticality)** — not against a global role. An estate is the boundary; nothing crosses it without an explicit control-plane grant.

### 10.2 Roles

**Customer plane:** Estate Owner (full commercial and technical authority, including termination) · Estate Administrator (technical management, not termination or commercial change) · Estate Collaborator (scoped to specific assets — a developer with access to one application) · Estate Viewer (read-only, including health and reports).

**Control plane:** Infrastructure Operator (day-to-day across estates, non-destructive) · Infrastructure Engineer (destructive with approval) · Infrastructure Administrator (provider credentials, policy, custody changes) · Auditor (read-only, everything, including history).

### 10.3 Rules

- **Destructive operations require elevation**, regardless of role — authority to manage is not authority to destroy.
- **Cross-estate access is never implicit**, and every instance is recorded.
- **Custody constrains permission.** No role can operate an asset we do not control; the model surfaces "not ours to change" rather than an action that will fail.
- **Secrets are referenced, never returned.** The estate records that a credential exists, its purpose and its rotation state — never its value.
- **Reading is auditable too**, in the control plane. Who looked at a customer's estate is an answer we must be able to give.

---

## 11. EXTENSIBILITY — the no-special-cases proof

A new asset type is added by declaring: class, facet schema, applicable lifecycle transitions, applicable health dimensions, permitted relationship types, required capability, available operations, freshness window.

**No core change. No new table pattern. No bespoke lifecycle. No special-case dashboard.**

A new provider is added by declaring a capability adapter and a provider account. Existing assets are re-bound by operation.

### The test, applied

| Asset | Class | Key dimensions | Characteristic edges | Special case needed? |
|---|---|---|---|---|
| Domain | Identity | Control, Security | `resolves_to`, `delivers_mail_for` | No |
| DNS zone / record | Naming | Availability, Control | `contains`, `resolves_to` | No |
| TLS certificate | Trust | Security, Availability | `secures` | No |
| Site environment | Compute | Availability, Performance, Continuity | `requires`, `deploys_to` | No |
| Application environment | Compute | All | `requires`, `deploys_to`, `authenticates_with` | No |
| Database | Data | Availability, Continuity, Security | `requires`, `backs_up` (inverse) | No |
| Mailbox | Communication | Deliverability, Availability, Security | `requires` mail domain | No |
| Backup set | Protection | Continuity | `backs_up` | No |
| Monitor | Observation | — *(confers confidence)* | `monitors` | No |
| Scheduled job | Automation | Availability | `requires`, `authenticates_with` | No |
| **AI agent runtime** | Automation | Availability, Security, Performance | `requires` compute + data + credential | No |
| Provider account | Provider relationship | Control, Availability | binding target | No |

**All twelve fit the same core.** That is the acceptance test met.

---

## 12. WHAT THIS ARCHITECTURE DELIBERATELY DOES NOT DO

- **No rigid hierarchy.** Shared assets break trees.
- **No provider concepts above the binding layer.**
- **No stored health.** Derived only, and explainable.
- **No synchronous provisioning contract.**
- **No direct state writes.** Operations only.
- **No secret storage.** References and rotation state only.
- **No cross-estate implicit access.**
- **No per-product infrastructure paths.** A product needing a bespoke route means the intake is wrong.

---

## 13. DEPENDENCIES DECLARED

> *Dependency: Platform Events — owned by another workstream. Not modified.* INFRA888 emits and consumes lifecycle events, and remains fully functional without the bus.
> *Dependency: AI Runtime — owned by another workstream. Not modified.* Agent runtimes are modelled as Automation-class assets; INFRA888 owns their infrastructure lifecycle only.
> *Dependency: Builder — owned by another workstream. Not modified.* The Builder is a consumer of the Provisioning Intake API and the largest tenant of the estate model.
> *Dependency: CRM — owned by another workstream. Not modified.* Health Audit findings are emitted as structured opportunity data; the CRM owns pipeline.
> *Dependency: Domain Commerce payment confirmation — owned by another workstream. Not modified.* Treated as an inbound event that may arrive late or repeat, never a synchronous guarantee.
> *Dependency: Engineering888 governance — owned by another workstream. Not modified.* INFRA888 conforms; it does not design it.

---

## 14. THE FOUNDATION IN ONE PARAGRAPH

**A customer has one Digital Estate. An estate is a graph of assets, each with an identity we own, a custody status, a desired state, an observed state and a time at which that observation was last true. Assets are connected by typed edges that carry health propagation and deletion semantics, so the system can answer what depends on what, what breaks if this fails, and what must be true for this to work. Every asset follows one lifecycle, is measured across the same six health dimensions, and is bound to providers through a separate layer so a vendor can be changed without changing anything the customer sees. All change flows through operations that are idempotent, auditable, blast-radius aware and reconciled continuously against reality. Hosting is one asset class among twelve. The estate is the product.**
