# INFRA888 — LAUNCH MASTERPLAN v2
### The infrastructure platform behind LevelUp Growth
**Board-level document · 2026-08-01 · Owner: INFRA888 · Supersedes v1**

---

## WHAT CHANGED FROM v1, AND WHY

v1 benchmarked us against GoDaddy, Hostinger and Cloudways. That was the wrong comparison set, and it produced the wrong strategy — it framed infrastructure as a **margin** business, so it optimised for gross margin per hosting account.

Infrastructure here is not a margin business. It is three things:

1. **A delivery capability** — every custom system we build has to run somewhere, and today that somewhere is improvised per project.
2. **An acquisition channel** — "let us take over your infrastructure" is a far easier first sale than "buy our CRM".
3. **A revenue-quality transformation** — it converts lumpy project revenue into recurring revenue.

The third point is the one that matters at board level and was entirely absent from v1.

> **A custom software business sells for 3–5× EBITDA. A platform with recurring infrastructure revenue and low churn sells for a multiple of revenue.** INFRA888 is not a cost centre attached to the agency. It is the mechanism that changes what the company is worth.

Everything below follows from that.

---

## 1. WHAT BUSINESSES DOES INFRA888 ACTUALLY SUPPORT?

Four distinct populations, with genuinely different economics and service models. Conflating them is how infrastructure businesses lose money.

### 1.1 Internal platform *(highest criticality, zero direct revenue)*
Boss888 itself: Builder, CRM, Marketing, AI, Studio, Analytics, Chatbot — plus co-tenanted properties (PTAA, ChefListed, Media888, markraymundo.com).

**Economics:** cost centre. **Service model:** highest reliability tier; an outage here stops every customer simultaneously.
**Strategic role:** the proving ground. We should never sell an infrastructure capability we do not run for ourselves first.

### 1.2 Custom software projects *(highest revenue per customer)*
CRM, ERP, HRM, booking, travel, marketplace, membership, custom SaaS, mobile backends.

**Economics:** project fee, then **a mandatory managed infrastructure subscription for the life of the system**.
**Service model:** dedicated environments, staged deployments, defined RPO/RTO.
**Strategic role:** this is where infrastructure changes the business model. Today a build ends and revenue stops. Under this model a build *begins* an annuity.

> **This is the single highest-value change in this document: no custom system is handed over without a managed infrastructure subscription attached. Not as an upsell — as a delivery requirement.**

### 1.3 Migration customers *(the acquisition engine)*
Established businesses with a website, domain, email and DNS scattered across three vendors, nobody owning it, and nobody testing the backups.

**Economics:** low or zero-margin migration, deliberately, to win a recurring subscription and an account we can expand into.
**Service model:** high-touch during migration, then standardised.
**Strategic role:** **the entry point into the Boss888 ecosystem.** They arrive for infrastructure relief and stay for the platform.

### 1.4 Hosting-only customers *(a category I recommend we do not create)*
See §5. Briefly: they price-shop, churn, generate the highest support cost per pound, and never adopt the platform. They are the customer GoDaddy is built to serve. We should decline them politely.

---

## 2. WHAT WE ACTUALLY SELL

**Design rule: we sell accountability, not components.** The moment a line item is a component (GB, vCPU, mailbox), it is comparable to a commodity vendor. Every product below is priced on *what we take responsibility for*.

### The product set

**1. Managed Website Platform**
For Builder and custom-website customers. Hosting, CDN, SSL, DNS, backups, uptime monitoring, performance and security — all included, none itemised.
*Target:* SMEs whose website matters but is not their operating system. *Recurring.*

**2. Managed Business Infrastructure** — ***the flagship***
The whole digital estate under one subscription: domains, DNS, SSL, email, hosting, backups, monitoring, security patching, incident response, disaster recovery, renewals and vendor management.
*Target:* established SMEs currently juggling four vendors. *This is what migration customers buy.* *Recurring, tiered by estate size and criticality.*

**3. Managed Application Platform**
For the custom systems we build — CRM, ERP, booking, marketplaces. Dedicated environments, staging, deployment pipeline, database operations, scaling, DR.
*Target:* every custom project client. *Recurring, mandatory, tiered by environment count and RPO/RTO.*

**4. Infrastructure Migration**
A one-time professional service: audit, plan, migrate, verify, cut over, warranty period.
*Priced as a project — and frequently waived against an annual commitment, because it is customer acquisition cost, not a revenue line.*

**5. Business Continuity**
Backup, tested restore, DR runbooks, RPO/RTO commitments, annual DR drill with a report.
*Sold as a tier upgrade, not a separate product.* The tested-restore report is the thing customers actually pay for — almost nobody else provides evidence.

**6. AI Infrastructure**
Dedicated capacity, data residency and isolation for customers running AI agents in their business.
> **Dependency: AI Runtime. Owned by another workstream. Not modified.** INFRA888 provides the substrate and isolation guarantees only.

### Challenging the brief

You listed **Corporate Email** and **Managed Domains** as products. **I recommend neither is sold standalone.**

Both are *components of Managed Business Infrastructure*. Sold separately, they are immediately price-comparable — a mailbox is a mailbox, and a .com is a .com. Sold as part of an estate we are accountable for, they are unpriceable by a competitor because the value is the accountability, not the mailbox.

**The exception:** domains must remain individually *purchasable* inside an existing subscription, because customers acquire new domains constantly. That is a transaction inside a relationship, not a product line.

**Also challenged:** "Application Hosting" as a standalone SKU. If we did not build the application, hosting someone else's bespoke code means owning failure modes we did not create. Offer it only where we also hold the maintenance contract.

---

## 3. THE CUSTOMER LIFECYCLE — from someone else's WordPress to a LevelUp account

The migration funnel is the most commercially important design in this document, so it is specified in full.

### Stage 1 — Discovery: the Infrastructure Audit *(free, and the entire wedge)*

We offer a **free Infrastructure Health Audit**. Non-invasive, external, delivered as a written report:

- Domain: registrar, expiry, auto-renew, transfer lock, WHOIS exposure
- DNS: hosting, configuration errors, single points of failure
- SSL: issuer, expiry, chain, renewal mechanism
- Email: deliverability, SPF/DKIM/DMARC, spam-list status
- Website: uptime history, performance, security headers, exposed versions
- Backups: whether any exist, and whether anyone has ever restored one

**Why this works:** almost every SME fails several of these and does not know it. The report is genuinely useful whether or not they buy — which is exactly why it earns trust. It also converts an abstract pitch ("managed infrastructure") into a specific, uncomfortable, *fixable* list.

**It is also a qualification filter.** An estate that is fine does not need us; one that is a mess is our ideal customer.

### Stage 2 — Migration
Fixed-scope, fixed-price, warranty-backed. Website, domain, DNS, SSL, email, database. Pre-verified cutover, rollback plan, zero-downtime target, and a **post-migration verification report** proving every item now passes the audit that failed at Stage 1.

That before/after report is the single best retention artefact we will ever produce.

### Stage 3 — Managed Business Infrastructure *(recurring revenue begins)*
They now have one vendor, one bill, one number to call. Their SSL renews, their backups are tested, their domain cannot silently expire.

### Stage 4 — Telemetry becomes advice
Because we now run their estate, we can see things they cannot: traffic patterns, slow pages, form abandonment, search visibility, email deliverability.

**This is the bridge to the platform, and it must be earned rather than sold.** Not "would you like to buy our marketing tool", but *"your contact form has been failing for eleven days — here is what it cost you"*.

### Stage 5 — Platform adoption
Marketing SaaS → CRM → Automation → AI, in whatever order their pain dictates. Each addition raises switching cost and lowers the marginal cost of the next.

> **Dependency: Builder, CRM, Marketing, AI, Chatbot, Analytics. Owned by other workstreams. Not modified.** INFRA888's role is to make adoption frictionless — same login, same bill, same account, no new infrastructure decision.

### Stage 6 — Long-term account
The end state is a customer whose website, domain, email, CRM, marketing and AI all sit with us. **Four products is a vendor; six is an operating system.** Their realistic alternative to us is no longer "another host" but "a six-month replatforming project".

### Retention and offboarding
Retention comes from accountability, not lock-in. Offboarding must remain clean and prompt — full export, domain transfer-out honoured without obstruction. **A hosting business that traps people ends up in review sites and chargebacks**, and we would be trading a small amount of churn for the reputation the whole ecosystem depends on.

---

## 4. PRICING

**Principle: price on risk transferred and outcomes owned — never on resources consumed.** Resource pricing invites the comparison we cannot win and should not want.

### 4.1 Custom-built projects
**Project fee + mandatory Managed Application Platform subscription.**
Tiered by environments, criticality and RPO/RTO — not by CPU. Annual commitment, uplift at renewal. The subscription is quoted as part of the build from the first conversation, never introduced at handover.

### 4.2 Managed Business Infrastructure
Monthly per estate, tiered by **what we are accountable for**: number of sites and applications, mailboxes, criticality tier, response commitment, DR guarantees. Not by gigabytes.

### 4.3 Managed Website Platform
Bundled into Builder plan tiers. The customer buys "their website, live and looked after" — hosting is never a separate line on the invoice, because a separate line invites a comparison.

### 4.4 Migration services
Fixed-price by estate complexity. **Routinely discounted or waived against a 12-month commitment.** Treat it explicitly as customer acquisition cost with a payback period, and measure it that way.

### 4.5 Support
**Included in every tier. Never sold separately.**
Selling support as an add-on creates a perverse incentive: unreliable infrastructure becomes a revenue source. Bundling it means every automation that reduces support cost increases our margin — which is the incentive we want.

### 4.6 Hosting-only
Not offered. See §5.

### 4.7 What this looks like commercially
Three revenue qualities, deliberately:
- **Project revenue** — lumpy, high value, expensive to win
- **Recurring infrastructure** — predictable, high margin at scale, low churn
- **Platform SaaS** — expansion revenue on an already-won account

The strategic objective is to grow the second and third until the first is no longer what determines whether it is a good year.

---

## 5. SHOULD HOSTING EXIST AS A STANDALONE PRODUCT?

**No. And this is a firmer recommendation than in v1.**

Hosting should be an **entry point and a delivery capability**, never a product line.

**The case against standalone hosting:**
- It invites a price comparison against vendors with structurally lower costs
- It attracts price-led buyers — the segment least likely to adopt the platform and most likely to churn
- Support cost per pound of revenue is the worst of anything we could sell
- It carries the same operational risk as our strategic products while generating none of the strategic value
- It positions us as a host, and the whole thesis is that we are not one

**What replaces it:**
- Builder customers get hosting **inside** the Managed Website Platform
- Custom-build clients get it **inside** the Managed Application Platform
- Migration customers get it **inside** Managed Business Infrastructure

**The one legitimate use of the word "hosting" externally** is as the migration hook — *"move your hosting to us"* — because that is the language customers already use for the pain they feel. They arrive asking for hosting; what they buy is management. The word is a marketing entry point, not a SKU.

> **The test:** if a prospect wants only hosting and refuses everything else, we should decline. That is not our customer, and serving them well would cost more than it earns.

---

## 6. HOW INFRA888 INTEGRATES WITH EVERY BOSS888 SYSTEM

### The one architectural law

> **Every Boss888 product consumes INFRA888. INFRA888 consumes nothing from them.**

A one-way dependency. The moment INFRA888 depends on the Builder or the CRM, it can no longer be the substrate they all rest on — and it inherits their release cycles and their outages.

### What INFRA888 owns, exclusively

- **The asset graph** — the single answer to *"what do we run, for whom, and is it healthy?"* Every site, application, domain, certificate, mailbox, database and backup, with its owner and state
- **Provisioning and lifecycle** — one intake, one state machine, whatever the requesting product
- **The provider abstraction** — no product ever knows which vendor is underneath
- **Domains, DNS, certificates**
- **Backups, restore, disaster recovery**
- **Monitoring, incidents, escalation**
- **Infrastructure commercial state** — what each customer's estate costs and what they are entitled to

### What products get

A single request surface: *"I need an environment of this shape, for this customer, at this criticality."* INFRA888 returns a managed asset and owns it thereafter. The product never learns what a droplet is.

### Integration by consumer

| Product | Consumes | Notes |
|---|---|---|
| **Builder** | site hosting, custom domains, SSL, CDN, backups | Largest tenant. Already hosting live sites today — INFRA888 must record them (this remains the first engineering task) |
| **CRM / ERP / custom apps** | dedicated environments, databases, deployment targets, DR | The Managed Application Platform |
| **Marketing / SEO / Analytics** | domain and DNS truth, uptime and performance telemetry | Consumes the asset graph as a data source |
| **AI / Agents** | isolated compute, data residency guarantees | **Dependency: AI Runtime. Owned by another workstream. Not modified.** |
| **Media888 / ChefListed / PTAA** | standard managed hosting | Prove the migration model on properties we already control |
| **Future SaaS** | the same intake, unchanged | If a new product needs a bespoke infrastructure path, INFRA888 has failed |

### Declared dependencies

> **Dependency: Platform Events. Owned by another workstream. Not modified.** INFRA888 will emit and consume lifecycle events through whatever bus exists, and must remain functional if it is unavailable.
> **Dependency: Domain Commerce payment remediation. Owned by another workstream. Not modified.** INFRA888 treats payment confirmation as an inbound event that may arrive late or repeat — never as a synchronous guarantee.
> **Dependency: Engineering888 governance. Owned by another workstream. Not modified.** INFRA888 conforms to it; it does not design it.

---

## 7. FIVE-YEAR VISION

**Scale:** 500 hosted websites · 200 custom applications · 50 CRM customers · multiple SaaS products · AI agents running parts of customer businesses.

That is roughly **750–1,000 managed environments and several thousand managed assets** (domains, certificates, mailboxes, databases, backup sets).

### What changes qualitatively

**1. Infrastructure becomes load-bearing for customers' businesses, not just their marketing.**
When an AI agent handles a customer's bookings and invoicing, an outage stops their *company*, not their website. The reliability bar moves from "a site is down" to "a business is down". This is the most important consequence of the five-year picture and it must shape architecture now: real DR, tested restores, defined RPO/RTO per tier, and multi-provider capability.

**2. Operations become fleet operations, not customer operations.**
Nobody logs into a server. Environments are standardised templates ("golden paths"); anything bespoke is an exception that must be justified and costed. Configuration is code; drift is detected and remediated automatically.

**3. Humans see only exceptions.**
Automation handles provisioning, certificates, renewals, backups, restore verification, scaling, patching, cleanup and first-line remediation. An operator's day is an exception queue, not a task list. **If headcount scales linearly with customers, the model has failed.**

Realistic team at that scale: **3–6 infrastructure people**, not thirty — but only if automation was built early. Retrofitting it at 500 customers is not feasible.

**4. Economics.**
Indicatively: 500 sites and 200 applications and 50 CRM estates on managed subscriptions produces **£1–2M of annual recurring infrastructure revenue** — before any platform SaaS revenue on the same accounts. At that point INFRA888 is not a supporting function; it is the most predictable revenue in the company and the reason the group commands a revenue multiple rather than an earnings multiple.

**5. Vendor position inverts.**
At that scale we buy compute at volume and providers compete for us. Multi-provider capability, built early as insurance, becomes a commercial lever.

### What operations looks like on a Tuesday morning

One screen: exceptions requiring a human, capacity headroom, expiring assets with automation status, last successful test restore per tier, incidents open against SLA, provider health, and estate revenue against cost. If nothing is red, the correct action is to close it and work on the roadmap.

### What would break this

- Bespoke environments — every snowflake is a permanent tax
- Automation deferred — the point of no return is roughly 100 environments
- Single-provider concentration held too long
- Selling ahead of operational capability
- Support sold separately, which would make unreliability profitable

---

## 8. WHAT THIS MEANS FOR THE NEXT 90 DAYS

The strategy changes; the immediate engineering sequence largely does not — but the *reasons* change, and one priority is displaced.

1. **Record what we already run.** The Builder hosts live customer sites that INFRA888 barely knows about. Under v2 the asset graph is the foundation of everything, so this moves from tidy-up to prerequisite.
2. **Custom domains and SSL — switch on what is built.** Still the highest value per engineering hour, now for a stronger reason: it is a precondition for every migration.
3. **Domain renewal automation.** Under v2 this is not a feature but a promise — we will be telling customers their domain can never silently expire.
4. **The Infrastructure Health Audit.** *Newly promoted.* It is the wedge for the entire migration channel, and it is largely read-only work against systems we already have. **Highest commercial leverage of anything in this list.**
5. **Migration runbook, proven on ourselves.** Migrate a property we control end-to-end and produce the before/after report. That artefact is the sales collateral.
6. **Managed Application Platform definition** for custom builds, so the next project ships with a subscription rather than a handover.

**Displaced:** standalone hosting productisation and the general provisioning wizard. Under v2 they serve a customer we have decided not to pursue.

---

## 9. THE ONE-LINE STRATEGY

**v1:** *"We make it impossible to leave."*

**v2:** **"We take responsibility for a business's digital infrastructure so completely that adopting the rest of our platform becomes the obvious next step — and every system we build arrives with an annuity attached."**
