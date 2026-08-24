# INFRA888 — LAUNCH MASTERPLAN
### The operating model for LevelUp Hosting
**Board-level document · 2026-08-01 · Owner: INFRA888**

---

## EXECUTIVE SUMMARY

**We should not launch a hosting company. We should launch a hosting *business model* that only we can run.**

Competing with Hostinger on shared hosting is a losing position: they sell at $2.99/month on hardware bought at a scale we will not reach for years. We would be a worse GoDaddy.

What we have that they do not is **the site already built, by our own Builder, for a customer who is already paying us**. The hosting is not the product. The hosting is what makes the product *keepable*.

Three conclusions drive everything below:

1. **Hosting must become the internal cloud operating system of Boss888 — not another Engine.** Builder already hosts three live customer sites. Domains, Studio, Email and every future product need DNS, SSL and certificates. If Hosting stays a peer engine, each of those reimplements the same primitives badly.
2. **Launch order is Domains → Custom Domains → Managed Site Hosting → Email.** This is the order of *margin per unit of operational risk*, and the first two are almost entirely built already.
3. **Roughly a third of the infrastructure work already completed has no launch value and should stop today.** Detailed in §11.

**The revenue thesis:** hosting is not a product line, it is a **retention and attach-rate mechanism**. A customer with our Builder site, our domain, our SSL and our email has four reasons not to leave and one invoice. That is worth more than winning a price comparison.

---

## 1. PRODUCT STRATEGY — what we are actually selling

We are selling **"your business online, handled"** — not compute.

### 1.1 The products

| Product | Target customer | Dependencies | Launch | Gross margin | Ops complexity |
|---|---|---|---|---|---|
| **Domains** | Every customer, day one | Registrar (**built**) | **Launch 1** | 25–40% on registration; renewals are the annuity | **Low** — provider does the work |
| **Custom Domains + SSL** | Builder customers with an existing domain | Cloudflare for SaaS (**built, gated**) | **Launch 2** | Indirect — protects the whole subscription | **Low** |
| **Managed Site Hosting** | Builder customers | Existing render path + capacity | **Launch 3** | 70–85% (bundled, not metered) | **Medium** |
| **Business Email** | SMEs who need `name@theirdomain` | Provider adapter (**absent**) | **Launch 4** | 40–60% per mailbox | **Medium-high** — deliverability, abuse, support |
| **Backups** | All hosted customers | Object storage | **Launch 3** (included) | Included; premium retention upsell | Medium |
| **DNS management** | Domain + hosting customers | Cloudflare | **Launch 2** | £0 — a moat, not a SKU | Low |
| **CDN** | All hosted sites | Cloudflare | **Launch 3** | Included | Low |
| **Monitoring** | All hosted customers | **Built and running** | **Launch 3** (included) | Included; alerting upsell later | Low |
| **Managed WordPress** | Migrating SMEs | Real hosting provider | **Launch 5, conditional** | 60–75% | **High** — the support burden of the industry |
| **Laravel / Node / Python / Docker hosting** | Developers | Real provider + control plane | **Do not launch** | 40–60% | **Very high** |
| **VPS** | Technical buyers | Provider passthrough | **Do not launch** | 15–25% | High |

### 1.2 What we should deliberately NOT sell — and why

**VPS, Docker, Node, Python, generic Laravel hosting.** These serve a customer who is not ours. A developer choosing between us and Vultr is comparing price and root access; we would lose, and they generate the highest support cost per pound of revenue. Selling them would also drag us into 24/7 on-call before we have the team for it.

**Shared cPanel hosting.** cPanel licensing is a per-account cost that scales linearly against a price that is racing to zero, and it commits us to a legacy control panel our product does not need.

**Managed WordPress — not yet.** It is genuinely lucrative and genuinely brutal: plugin conflicts, hacked sites, migrations from a hundred bad hosts. Revisit only once Launches 1–4 are stable and we have staffed support. Our Builder is the strategic answer to WordPress, not our WordPress offering.

> **Challenge to the existing roadmap:** earlier INFRA888 work modelled a general "hosting provisioning wizard" with plan/product/entitlement machinery capable of selling arbitrary compute. That is a platform for a business we have decided not to be in. See §11.

---

## 2. LAUNCH ROADMAP — the commercial sequence

### Launch 0 — Internal only *(where we are)*
**Dogfood.** LevelUp's own sites and PTAA run on our infrastructure and are recorded accurately in INFRA888.
**Why it exists:** we cannot sell an inventory system that does not know about the three sites we already host. Today INFRA888 records one hosted site and two hosting accounts while the Builder serves three published customer sites. Fixing that gap is the cheapest credibility we will ever buy.
**Exit criteria:** every live site and domain appears in INFRA888 with correct state; monitoring covers all of them; the operations dashboard is truthful.

### Launch 1 — Domains
**Why first:** the registrar adapter is complete and sandbox-validated. It is the only revenue line we can turn on with engineering we already own. Domains also create the **annual renewal annuity** that makes every later product stickier.
**Sells:** registration, renewal, transfer-in, auto-renew, nameserver and DNS control, WHOIS privacy.
**Exit criteria:** a real customer completes a real purchase; renewals run unattended; expiry is impossible to miss.

### Launch 2 — Custom Domains + SSL
**Why second:** it is *already built and switched off*. It converts the Builder from "a site on a LevelUp subdomain" into "the customer's real website". This is the single largest perceived-value jump available to us, and its marginal cost is a Cloudflare token.
**Sells:** bring-your-own-domain, automatic certificates, DNS management.
**Exit criteria:** a customer points their own domain at their Builder site, certificate issues automatically, renewal is unattended.

### Launch 3 — Managed Site Hosting
**Why third:** this is productising what we already do, not building new infrastructure. Bundled into plan tiers, with backups, CDN and monitoring included.
**Sells:** the hosting of the site we built, with SLA language, backups and restore.
**Exit criteria:** provision, suspend, restore, terminate, backup and restore all work end-to-end for a Builder site; capacity and isolation are understood.

### Launch 4 — Business Email
**Why fourth and not earlier:** email is where hosting companies get hurt — deliverability, spam reputation, abuse, support volume. It should only sit on top of a working domain and DNS platform, because that is what makes it easy for us and hard for a competitor to unbundle.
**Sells:** mailboxes on the customer's domain, aliases, forwarders, catch-all.

### Launch 5 — Application Hosting *(conditional)*
Only if inbound demand proves it. Requires a real provider, a control plane and on-call. **The default answer is no.**

### Launch 6 — Platform
Multi-region, reseller/agency tier, marketplace. Years out. Listed so we do not accidentally foreclose it.

---

## 3. CUSTOMER LIFECYCLE

**Discovery.** Hosting is not marketed standalone. It appears inside the Builder at the moment of value: *"Your site is ready. Give it a real address."*

**Purchase.** Domain search inline, single checkout, one invoice for site + domain + hosting + email. **One bill is a competitive weapon** — GoDaddy sends four.

**Provisioning.** Automatic and immediate. Target under 60 seconds to a working site on the customer's domain. No ticket, no human.

**Welcome.** One email that states what they now own, what is automatic (SSL, backups, renewals), and the single link to manage it. Not a 12-step onboarding sequence.

**Deployment.** Publishing from the Builder *is* deployment. No separate concept is exposed to the customer.

**Daily management.** A single Site Detail view: domain, SSL status, backups, uptime, email. The customer should need it rarely — that is the goal, not a UX failure.

**Renewal.** Automatic by default, with a 60/30/7-day notice ladder for domains. A failed card triggers dunning, never silent expiry. **Domain expiry is the single most damaging failure in this business** — it loses the customer their identity, and it is unrecoverable after the redemption window.

**Upgrade.** Immediate, prorated, no re-provisioning.
**Downgrade.** Effective at period end; usage checked against the new tier first, with a clear warning if data must be removed.

**Cancellation.** Immediate suspension is wrong. Grace period → suspension → retention window → deletion, with data export available throughout.

**Migration (in).** The acquisition lever for Launches 3–4: domain transfer-in, DNS cutover with pre-verification, mailbox import. Migration-in must be free and assisted.

**Recovery.** Self-service restore from backup, and an "undo" for destructive actions.

**Support.** Tiered by product: domains are largely self-service; hosting is business-hours with an emergency path; email will generate the most contacts, which is why it launches after the platform is stable.

**Offboarding.** Full export, domain transfer-out honoured promptly and without dark patterns. **Retention through hostage-taking is a strategy that ends in chargebacks and reviews.**

**Retention.** The real mechanism is attach rate. One product is a vendor; four products is an operating system. Track attach rate as a primary KPI, not just churn.

---

## 4. HOSTING LIFECYCLE

**Provision** — from a plan entitlement, idempotent, with a deterministic resource identity. Failure must leave no orphan.
**Deploy** — the Builder publishes; hosting exposes the target, never a bespoke pipeline.
**SSL** — issued automatically on domain attachment; renewal is a background guarantee, never a customer action.
**DNS** — we manage the zone where we can; where the customer keeps their own DNS, we verify and instruct.
**Domain attachment** — verify ownership, provision certificate, cut over, confirm. Reversible at every step.
**Monitoring** — every hosted site is monitored from creation. Already built and running.
**Backups** — scheduled, offsite, encrypted. **Retention is a product decision, not an engineering default.**
**Restore** — self-service, point-in-time, and *tested* (see §6 — an untested backup is a rumour).
**Scaling** — vertical first, invisible where possible; scaling should be an operational event, not a customer purchase decision.
**Migration** — between servers and providers without customer-visible downtime. This capability is also our vendor-lock-in insurance (§7).
**Suspension** — for non-payment or abuse; reversible, with data preserved and a clear customer-facing reason.
**Unsuspension** — immediate on payment.
**Termination** — customer-initiated, with grace.
**Deletion** — after a retention window, irreversible, audited.
**Archiving** — cold storage of terminated sites for the retention period; cheaper than live hosting, and it makes win-back possible.
**Disaster recovery** — defined RPO/RTO per tier, with a restore drill cadence. Untested DR is not DR.

---

## 5. OPERATIONS — running this like a hosting company

**Provisioning queue.** Every provision is a tracked operation with state, attempts and an outcome. `infra_operations` already models this.

**Incident queue.** Provider outage, site down, certificate failure, backup failure, payment failure. Already modelled (`infra_incidents`, `infra_incident_transitions`).

**Failed provisioning.** Never silent, never a half-built account. Automatic retry with backoff, then an operator queue with the failure reason. A customer who paid and got nothing is the worst outcome in this business.

**Retry strategy.** Distinguish transient (retry) from terminal (escalate). **Ambiguous provider failures must be treated as terminal** — the same discipline already applied to domain registration, where retrying an ambiguous state risks double-charging.

**Support workflow.** Ticket → triage → runbook → escalation, tied to the affected asset so support sees the site, its state and its history.

**Maintenance windows.** Published, off-peak, per-region. Scheduled maintenance is announced; emergency maintenance is announced *during*, not after.

**Server replacement.** Routine, not an incident: provision replacement, migrate, verify, cut over, retire.

**Customer communication.** Proactive on anything customer-visible. **If we detect it before they do, we tell them before they ask.** That is the entire difference between a trusted host and a cheap one.

**Status page.** Public, honest, updated during incidents. Non-negotiable for a hosting business.

**Runbooks.** Written for every recurring failure: certificate stuck, DNS not propagating, provisioning timeout, backup failed, provider degraded.

**Escalation.** Clear tiers, named owners, defined response times per severity.

**Provider outages.** We are accountable to the customer regardless of whose fault it was. Multi-provider capability (§7) is what makes that survivable.

---

## 6. AUTOMATION STRATEGY — INFRA888's strongest claim

**Principle: any operation a human performs twice is a defect.** These must run without intervention:

- **SSL issuance and renewal** — with expiry alerting long before failure
- **Domain renewals** — with dunning on payment failure and escalating notices
- **DNS record management** on attachment and change
- **Provisioning** end-to-end, including rollback of partial failures
- **Backups** on schedule, with failure alerting
- **Restore validation** — periodic automated test restores. *A backup that has never been restored is not a backup.* This is the one automation most hosts skip and most regret.
- **Certificate expiry monitoring** across every hosted domain
- **Health checks and uptime monitoring** — already live
- **Billing suspension and reinstatement** on payment state
- **Retry with backoff** for all provider operations
- **Alerting** routed by severity, deduplicated
- **Rollback** of failed provisions and failed deploys
- **Scaling** on threshold where safe
- **Cleanup** of orphaned resources — the silent margin killer in every hosting business
- **Drift detection** between our records and provider truth. We already learned this lesson: an accepted provider response is not a verified effect.

---

## 7. PROVIDER STRATEGY

### Recommendation

| Role | Provider | Rationale |
|---|---|---|
| **Primary compute** | **Hetzner** | Best price/performance in the market by a wide margin; EU/US coverage; strong API. Margin comes from the input cost |
| **Secondary compute** | **DigitalOcean** | Mature API, broad regions, credible failover. We already operate here |
| **Edge / DNS / SSL / CDN** | **Cloudflare** | Cloudflare for SaaS is *already built* in our codebase; free tier viable at launch scale; removes an entire class of certificate work |
| **Control plane** | **Forge** (launch) → **CloudPanel/Ploi** (scale) | Forge is fastest to a working platform. Revisit when per-server licensing outweighs the engineering to replace it |
| **Object storage** | **Backblaze B2** | Backup cost is the difference between backups being included or being an upsell |

**Rejected:** AWS/Azure — cost and complexity unjustified at our scale, and the pricing model makes margins unpredictable. cPanel/DirectAdmin — per-account licensing against commodity pricing, plus a legacy surface our product does not need. Vultr — credible, but a third compute vendor adds operational burden without new capability.

### Vendor lock-in and exit

**The abstraction we already have is the strategy.** Capability contracts (`HostingProviderConnector`, `DnsProviderConnector`, `CertificateProviderConnector`, `BackupProviderConnector`) mean a provider is an adapter, not an architecture.

Three rules protect us:
1. **No provider-specific concepts leak into the domain model.** A "site" is ours; a droplet is theirs.
2. **Migration capability is built before we need it** (§4). If we can move a customer between servers, we can move them between providers.
3. **Backups live at a third party** (B2), not with the compute provider. If we lose the provider, we do not lose the data.

**Exit test:** we should be able to name the work required to move every customer off the primary provider, and it should be measured in days, not quarters.

---

## 8. COMMERCIAL ARCHITECTURE

**Design principle: sell outcomes in bundles; never expose a line item the customer can price-compare.**

**Plan-led, not SKU-led.** Hosting, SSL, backups, CDN and monitoring are *included in a tier*. The moment we sell "hosting" standalone we invite a comparison with a $2.99 competitor and lose.

| Line | Model | Notes |
|---|---|---|
| **Domains** | One-time + annual renewal | Cost-plus with a percentage markup and a flat minimum floor — the floor matters because a $0.99 promotional TLD still costs us support |
| **Hosting** | Recurring, bundled into plan tiers | The margin engine |
| **Email** | Recurring, per mailbox | Naturally usage-scaled |
| **SSL** | **Free, always** | A competitive weapon. Charging for SSL in 2026 reads as predatory |
| **Backups** | Included; extended retention paid | Table stakes included, premium retention as upsell |
| **Overages** | Usage-based, generous allowances | Bandwidth/storage overage should be rare and never a surprise |

**Upsells:** extended backup retention, additional mailboxes, priority support, additional sites.
**Cross-sells:** the natural ladder is **site → domain → email → additional sites**. Attach rate is the primary commercial KPI.

**Recurring revenue strategy.** Annual domain renewals plus monthly hosting produces predictable revenue with two different churn profiles. Annual prepay on hosting (with a discount) improves cash and cuts churn measurably.

**Margin discipline.** Every product must have a known unit cost — including support minutes. A product that is profitable on infrastructure and unprofitable on support is unprofitable.

---

## 9. OPERATIONAL DASHBOARD — the morning view

**Top band — is anything on fire?**
Active incidents · sites down · failed provisions in the last 24h · certificates expiring in 7 days · failed backups · provider status.

**Money.**
MRR and change · new subscriptions · failed payments and dunning state · domains expiring in 30 days (**with auto-renew status** — the highest-consequence number on the page) · upcoming renewal revenue · attach rate.

**Fleet.**
Servers and health · capacity headroom · storage and bandwidth against allowance · sites per server · resources with no owner (cost leak).

**Queues.**
Provisioning in flight · retrying · awaiting operator · support by age and severity.

**Trust.**
Uptime by tier against SLA · restore test results (**last successful test restore — if this is stale, we have no DR**) · drift between our records and provider truth.

**Design rule:** the top of this page answers *"can I go and do something else today?"* If it cannot answer that in five seconds, it is badly designed.

---

## 10. INFRASTRUCTURE ARCHITECTURE — what engineering must build

**Already exists (real, verified):** domain registrar adapter (25 methods, sandbox-validated); monitoring (live, thousands of results); Cloudflare for SaaS custom-hostname provider (built, gated off); the operations/incident/asset/provider schema; the governed provisioning flow *shape*; the customer and admin portal surfaces.

**Needs modelling:** hosting as a first-class product tied to plan entitlements; capacity and placement; backup policy and retention; SLA tiers; the archive/deletion lifecycle.

**Needs implementation:** hosting lifecycle write operations (currently read-only); backup and restore; certificate issuance and renewal; DNS management surface; domain renewal orchestration; transfer completion; WHOIS privacy; the reconciliation of existing Builder sites into INFRA888.

**Needs provider:** real hosting compute; email; object storage for backups; certificates (via Cloudflare).

**Needs UI:** customer Site Detail as the single management surface; domain renewal and expiry management; backup/restore self-service; email mailbox management; the operational dashboard in §9.

**Needs automation:** everything in §6 — restore validation and certificate renewal first.

**Needs governance:** who may suspend, terminate, delete, or restore; approval for destructive customer-visible actions; audit of every state transition.

**Needs monitoring:** certificate expiry, backup success, provisioning failure rate, provider health, capacity, drift.

### Dependencies owned by other workstreams

> **Dependency: Stripe payment webhook reliability for domain and hosting purchases.** Owned by another workstream. Not modified. INFRA888 designs around it by treating payment confirmation as an inbound event that may be delayed or retried, never as a synchronous guarantee.

> **Dependency: Builder rendering and publishing.** Owned by another workstream. Not modified. INFRA888 treats the Builder as a hosting *customer* and exposes targets to it, not the reverse.

> **Dependency: platform event bus, engineering governance, AI runtime.** Owned by other workstreams. Not modified. INFRA888 consumes what exists and does not design across those boundaries.

---

## 11. CRITICAL QUESTIONS — direct answers

### Should Hosting remain an Engine, or become the internal cloud operating system?

**It must become the internal cloud operating system.** This is the most consequential decision in this document.

The evidence is already in front of us: **the Builder hosts live customer sites today, and INFRA888 barely records them.** That gap exists precisely because Hosting was modelled as a peer engine rather than the substrate everything sits on.

Every Boss888 product needs the same primitives — a domain, DNS, a certificate, storage, uptime, a bill. If Hosting is a peer, each product reimplements them, badly and inconsistently, and no single system can answer *"what do we run for this customer, and is it healthy?"*

As the cloud OS, INFRA888 owns: identity of every hosted asset; the domain, DNS and certificate layer; provisioning and lifecycle; backups and recovery; monitoring and incidents; the provider abstraction; and the commercial model for all of it. Other engines *consume* it. The Builder becomes its first and largest tenant.

**This is also the honest reading of the commercial position:** we are not entering the hosting market, we are formalising infrastructure we already operate.

### What should launch first — highest business value, not easiest?

**Domains, then Custom Domains.** They happen to also be nearly built, but that is not the argument.

Domains create the **annual renewal annuity** and the identity anchor that every later product attaches to. Custom Domains convert the Builder from a demo into a real website — the largest single jump in perceived value we can deliver, for the cost of a Cloudflare token.

Managed Site Hosting third, because it monetises what we already do. Email fourth, because it is the highest-support product and should sit on a stable platform.

### Which engineering work should STOP?

**Challenging existing priorities directly:**

1. **The general hosting provisioning wizard.** It provisions infrastructure we do not have, for products (VPS, Docker, arbitrary compute) we have decided not to sell. **Stop.**
2. **The catalog/entitlement/subscription machinery, in its current generality.** Products, plans, entitlements and subscriptions are all modelled and all empty. It was built before a pricing decision existed. **Freeze** until §8 is approved, then implement narrowly against real plans.
3. **Provider certification and multi-provider health machinery.** Sophisticated apparatus for a single live provider. **Freeze** until there are two.
4. **Email scaffolding.** Contracts exist with no adapter. **Stop** until Launch 4, and **hide the Email tab** — a portal advertising a product that does not exist is the worst kind of technical debt, because customers see it.
5. **Any further Null-connector lifecycle work.** Proving governed flows against a no-op has reached the end of its usefulness. The next hosting work should be against a real provider or not at all.

### Which work should be ACCELERATED because it unlocks revenue?

1. **Cloudflare custom domains — switch it on.** Built, mock-tested, gated. Needs a scoped token and zone enablement. **This is the highest revenue-per-engineering-hour item we have.**
2. **Domain renewal orchestration.** Not a feature — a liability. Every domain we sell without it becomes a future customer catastrophe.
3. **Hosting reconciliation.** Backfill the sites we already host into INFRA888. Turns the portal from a preview into a truthful management surface with no new provider.
4. **Domain fulfilment un-gating** — subject to the payment dependency owned elsewhere. Without it, no domain sale can complete.
5. **The operational dashboard (§9).** We cannot run a hosting business we cannot see.

---

## 12. RISKS TO THE PLAN

**Support cost is the hidden margin killer.** Every product must be costed with support minutes included.
**Domain expiry is our reputational cliff.** Renewal automation is not optional.
**Untested backups.** Restore validation must be automated before we advertise backups.
**Single-provider concentration.** Acceptable at launch; must be time-boxed with a migration capability built early.
**Selling ahead of operations.** The fastest way to destroy a hosting brand is to sell a product we cannot support at 2am. Launch order in §2 is designed to keep operational load behind capability.

---

## 13. THE ONE-LINE STRATEGY

**We are not selling hosting. We are making it impossible to leave — by owning the customer's site, domain, certificate and email behind a single bill, on infrastructure we can move at will.**
