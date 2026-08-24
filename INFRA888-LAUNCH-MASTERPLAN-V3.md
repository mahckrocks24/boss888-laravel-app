# INFRA888 — LAUNCH MASTERPLAN v3
### The customer maturity engine of LevelUp Growth
**Board-level strategy document · 2026-08-01 · Owner: INFRA888 · Supersedes v1 and v2**

---

## PREFACE — TWO CORRECTIONS

**v3 exists because two recommendations in v2 were wrong.** Both errors were the same shape: I confused a statement about *strategy* with a statement about *policy*.

**Correction 1 — hosting-only customers.**
v2 said: if a prospect wants only hosting, decline. That reasoned from "not our target market" to "not worth having", which does not follow. A customer who buys one product today and six over five years is an excellent customer with a slow start.

The discipline does not belong at the door. **It belongs in the cost to serve.** A hosting-only customer at £15/month who costs £1 to serve is profitable and optionful. The same customer generating three support tickets a month is a loss. So the rule is not *"refuse them"* — it is **"serve them at automated marginal cost, or not at all."**

**Correction 2 — domains and email as products.**
v2 said neither should be sold standalone. That conflated *destination* with *entry*. Customers arrive saying "I need a domain" or "I need business email" — that is not the end of a relationship, it is the most common beginning of one.

More importantly, these are the only lead-generation channels we will ever have that **pay us to acquire the lead**. Most companies pay £50–200 to buy an SME lead. A domain sale acquires the same lead at a positive margin, with verified intent, real contact details and a billing relationship already established.

**Both corrections point at the same reframe, and it is the thesis of v3:**

> **INFRA888's product is not infrastructure. Its product is customer maturity.** Every entry product exists to start a relationship. Every managed service exists to deepen it. Every piece of telemetry exists to reveal the next thing the customer needs before they know they need it.

---

## 1. THE CUSTOMER MATURITY MODEL — the core of this document

Products are the instruments. **Maturity is the objective.**

Every INFRA888 decision is now measured against one question: *does this move customers to the next stage, or does it merely serve them where they are?*

### The stages

| Stage | What they have | What they feel | Annual value (indicative) |
|---|---|---|---|
| **0 — Prospect** | Someone else's infrastructure | Nothing. They are not looking | £0 |
| **1 — Transactional** | A domain, mailbox or hosting from us | "That was easy" | £50–300 |
| **2 — Managed** | Their estate under our accountability | Relief | £600–3,000 |
| **3 — Visible** | Marketing, SEO, analytics | "I can finally see what's happening" | £2,000–8,000 |
| **4 — Operational** | CRM — the business runs on us | Dependence, in the healthy sense | £5,000–20,000 |
| **5 — Automated** | Workflow automation | Time returned | £10,000–35,000 |
| **6 — AI-operated** | Agents running business functions | We are their operating system | £25,000–100,000+ |

A Stage 6 customer is worth **one to two hundred times** a Stage 1 customer. This is why hosting-only customers must be accepted: we are not selling them hosting, we are **buying an option on Stage 6 at a positive price.**

### The mechanics of each transition

Every stage transition needs three things: a **trigger** (something observable), a **proof** (evidence they can check), and a **low-friction next step**. INFRA888 supplies all three because it holds the telemetry.

**Stage 0 → 1 · The Audit or the Transaction**
*Trigger:* they need a domain, or they receive an Infrastructure Health Audit (§3).
*Proof:* the transaction works and someone competent handled it.
*Step:* buy the domain, mailbox or hosting. **No commitment requested. No upsell at checkout.**

**Stage 1 → 2 · The Adjacent Gap** — *the most important transition in the company*
*Trigger:* INFRA888 observes something specific and true — their domain expires in 47 days with auto-renew off; the site their new domain points at has no SSL; their mailbox is failing SPF and landing in spam.
*Proof:* we tell them before it breaks. Repeatedly. **This is the entire mechanism: earned authority, not sales pressure.**
*Step:* "we already handle your domain — let us handle the rest of it."

**Stage 2 → 3 · The Question We Can Already Answer**
*Trigger:* we run their estate, so we hold their uptime, performance, traffic and search visibility. They ask "is my website actually working?" — or we show them it is not.
*Proof:* a report on their own business they could not produce themselves.
*Step:* marketing and SEO. > *Dependency: Marketing, SEO, Analytics. Owned by other workstreams. Not modified.*

**Stage 3 → 4 · The Leak**
*Trigger:* marketing produces enquiries, and enquiries get lost in an inbox.
*Proof:* we can count the leak — form submissions versus follow-ups.
*Step:* CRM. > *Dependency: CRM. Owned by another workstream. Not modified.*

**Stage 4 → 5 · The Repetition**
*Trigger:* CRM data exposes work done manually every week.
*Proof:* hours counted, and costed.
*Step:* automation.

**Stage 5 → 6 · The Judgement**
*Trigger:* automation handles rules; the remaining work needs judgement.
*Proof:* agents perform a real function reliably, in view.
*Step:* AI operating system. > *Dependency: AI Runtime. Owned by another workstream. Not modified.*

### What this means for INFRA888

INFRA888 is the only system that sees a customer at **every** stage, from before they are a customer to full platform adoption. That makes it the natural home of:

- the **customer estate record** — what they own, where, and in what condition
- the **maturity stage** and its history
- the **trigger engine** — the observations that create the next conversation
- the **health baseline** that makes progress provable

**INFRA888 is the platform's demand-generation engine as much as its infrastructure layer.** No other system is positioned to do this.

---

## 2. HOSTING-ONLY, DOMAINS AND EMAIL — the entry-product strategy

### 2.1 The governing principle

**Accept every entry customer. Build the business around none of them. Instrument all of them.**

Three rules make hosting-only customers safe:

1. **Standardised or nothing.** Entry customers get one environment shape. No bespoke configuration, ever. A request for something bespoke is a Stage 2 conversation, not a Stage 1 accommodation.
2. **Automated or unprofitable.** Provisioning, renewal, certificates, backups and monitoring must be untouched by humans. If serving an entry customer requires routine manual work, the *product* is wrong, not the customer.
3. **Support is bounded by design.** Self-service and documentation first, with support included but structurally rare. If entry-tier support volume rises, that is a product defect to fix, not a cost to absorb.

**The test that replaces v2's rejection rule:** we accept any customer we can serve at automated marginal cost. We decline only work that requires bespoke human effort at an entry price — and we decline it by quoting Stage 2 properly, not by refusing the customer.

### 2.2 Entry products, and what each one earns us

| Entry product | Why customers buy | What we gain beyond the margin |
|---|---|---|
| **Domain** | "I need a domain" | Identity, contact, billing relationship, renewal date, and DNS visibility of their entire estate |
| **Business Email** | "I need name@mycompany" | Their domain, their deliverability posture, daily-use dependency |
| **Hosting** | "My host is terrible" | Their website, its performance, traffic and failure modes |
| **Health Audit** | Curiosity, or a scare | A complete diagnostic on a business that is not yet a customer |

**A domain sale gives us the DNS records of everything else they use** — where their mail is, where their site is, what tooling they run. That is a qualified expansion map, delivered by a transaction that pays us. No paid channel comes close.

### 2.3 The nurture engine

Every entry customer enters an automatic sequence driven by *observed facts*, never by a calendar:

- Domain expiring, auto-renew off → **risk alert**
- Domain has no SSL → **offer**
- Mail failing SPF/DKIM/DMARC → **deliverability warning**
- Website slow or intermittently down → **evidence**
- Estate spread across four vendors → **consolidation proposal**
- Backups absent → **continuity conversation**

Each message is true, specific to them, and useful whether or not they buy. **Authority compounds; pressure does not.** A customer who has received four accurate warnings will accept the fifth recommendation.

Target: **Stage 1 → Stage 2 conversion above 25% within 18 months.** That single number determines whether entry products are an acquisition engine or a distraction, and it should be reported monthly.

---

## 3. THE INFRASTRUCTURE HEALTH AUDIT — as a product

Treated here as the **primary lead-generation engine for LevelUp Growth**.

### 3.1 Why it works

Every SME has infrastructure they do not understand, inherited from someone who has left. They know it is probably fine. They are usually wrong, and they cannot check.

The audit is **externally observable** — it needs nothing from the customer but a domain name. That means it can be run **before** they are a prospect, on any business we choose to approach.

> **The most powerful finding is not technical. It is ownership.** When a business discovers their domain is registered to a developer they stopped working with in 2019, that is not a technical finding — it is an existential one. Nothing else we can say produces the same reaction.

### 3.2 What it scans

**Identity and control** — registrar, expiry, auto-renew, transfer lock, **registrant ownership**, WHOIS exposure, DNS operator.
**Availability** — uptime history, response time, error rates, single points of failure.
**Security** — SSL issuer/expiry/chain/renewal, security headers, exposed software versions, admin surfaces, mixed content.
**Deliverability** — SPF, DKIM, DMARC, MX configuration, blacklist status.
**Continuity** — evidence of backups, retention, and whether restore has ever been tested.
**Performance** — load time, page weight, CDN presence, mobile performance.
**Estate coherence** — how many vendors, how much fragmentation, how much is undocumented.

### 3.3 Scoring

A grade out of 100 across six pillars — **Control, Availability, Security, Deliverability, Continuity, Performance** — rolled into a single letter grade.

The grade must be **memorable, defensible and shareable**. A business owner who receives a **D in Continuity** and learns nobody has ever tested their backup does not need a sales pitch.

Two design rules: **never inflate** — a good estate must score well or the instrument is worthless; and **every finding carries a consequence in plain language**, not a severity label. Not *"SPF misconfigured"* but *"roughly one in five of your emails is likely going to spam."*

### 3.4 From report to revenue

Each finding maps to a remedy, a stage and an owner:

| Finding | Remedy | Moves them to |
|---|---|---|
| Domain not owned by the business | Ownership recovery + Managed Domains | Stage 1–2 |
| Domain expiring, auto-renew off | Managed Domains | Stage 1 |
| No SSL / manual renewal | Managed Website Platform | Stage 2 |
| No tested backups | Business Continuity | Stage 2 |
| Poor deliverability | Corporate Email | Stage 1–2 |
| Slow or unstable site | Migration + Managed Hosting | Stage 2 |
| Fragmented vendors | Managed Business Infrastructure | Stage 2 |
| No analytics or visibility | Marketing/SEO *(other workstream)* | Stage 3 |
| Enquiries with no follow-up system | CRM *(other workstream)* | Stage 4 |
| Heavy repetitive manual work | Automation, then AI *(other workstream)* | Stage 5–6 |

**Every audit produces a CRM opportunity record** with the findings, the score, the recommended remedy, an estimated value and a priority.
> *Dependency: CRM. Owned by another workstream. Not modified.* INFRA888 produces structured opportunity data; the CRM owns pipeline.

### 3.5 As a commercial product

- **Free tier** — automated, self-service, top-line score and headline findings. Lead capture.
- **Full audit** — chargeable, detailed, with remediation plan and costs. **Frequently credited against migration**, so it is risk-free to buy.
- **Continuous monitoring** — for managed customers, the audit re-runs perpetually. Their score becomes a live number they can watch improve. *This turns the sales instrument into a retention instrument.*

**Additional uses:** outbound targeting at scale (audit first, approach with evidence), partner and agency co-selling, and portfolio audits for investors and groups with many subsidiaries.

**Reported metric:** audits run → audits delivered → opportunities created → migrations won → Stage 2 conversions.

---

## 4. RECURRING REVENUE — the board argument

### 4.1 The problem with the current model

Custom project revenue has three structural weaknesses: it is **lumpy** (feast and famine), it has **zero retention** (delivery ends the relationship), and it requires **100% re-acquisition** — every pound of next year's revenue must be won again from scratch.

A business built this way is valued on earnings, discounted for volatility, and is worth **3–5× EBITDA**.

### 4.2 What recurring infrastructure changes

**Customer lifetime value.** A £40k project, delivered and closed, is worth £40k. The same project with a £600/month managed subscription, retained five years, is worth £76k — and the subscription requires no new sale. **LTV rises without a single additional win.**

**Churn.** Single-product customers leave easily. Each additional product adds a switching cost — not contractual, but practical: a customer whose domain, email, hosting, CRM and marketing all sit with us faces a replatforming project, not a vendor change. Directionally, **each additional product materially reduces annual churn probability**, and the compounding effect across the maturity model is the strongest retention mechanism available to us.

**Cross-sell and expansion.** Selling to an existing customer costs a fraction of selling to a new one, and closes at several times the rate. The maturity model is a designed expansion path: each stage is a smaller step than the last, because trust accumulates.

**Net revenue retention.** If existing customers spend more each year than the revenue lost to churn, **the business grows without new customers**. NRR above 100% is the single metric that separates a services company from a platform, and the maturity model is built to produce it. **Target NRR: 115%+.**

**Operational leverage.** Project revenue scales with people. Recurring infrastructure revenue scales with automation. Once provisioning, renewals, certificates, backups and monitoring are automated, the marginal cost of the next customer approaches zero — so **gross margin expands as the business grows**, which is the opposite of an agency.

**Valuation.** This is the argument that matters most:

| Business shape | Typical valuation basis |
|---|---|
| Custom software agency | 3–5× EBITDA |
| Agency + recurring services | 4–6× EBITDA |
| Platform, strong NRR, low churn | **5–10× ARR** |

**The revenue mix determines the valuation basis, not the revenue total.** A company at £2M revenue that is 70% recurring with 115% NRR is worth several times a company at £2M revenue that is 90% projects. Shifting the mix is therefore the highest-leverage strategic act available — and INFRA888 is the mechanism that shifts it, because infrastructure is the only thing every customer needs continuously.

### 4.3 The measurable objective

| Metric | Why it matters |
|---|---|
| **% recurring revenue** | The valuation basis |
| **NRR** | Growth without acquisition |
| **Products per customer** | The churn predictor |
| **Stage distribution** | Where the customer base actually is |
| **Stage 1 → 2 conversion** | Whether entry products work |
| **Gross margin per managed customer** | Whether automation is real |
| **Cost to serve, entry tier** | The discipline that makes hosting-only viable |

---

## 5. IMPLICATIONS

**Product changes from v2:** hosting-only is accepted as a standardised, fully automated entry tier. Domains and Business Email are restored as standalone entry products. The Health Audit is promoted from a sales tactic to a product line with free, paid and continuous tiers.

**What does not change:** we still do not compete on commodity price; we still do not build the business around entry customers; support remains bundled; support volume in entry tiers remains a defect signal, not a revenue opportunity.

**Priority consequence.** The audit engine and the trigger/nurture engine move up the roadmap, because they are what convert entry customers into managed ones — and that conversion is the whole economic argument. Infrastructure that serves customers well but never advances them is a cost centre with good manners.

---

## 6. THE MISSION STATEMENT

> **INFRA888 is the infrastructure and customer-maturity platform of LevelUp Growth. It runs and takes responsibility for the digital infrastructure behind every website, application and business system we build or adopt — domains, DNS, certificates, hosting, email, backups, monitoring, continuity and the providers beneath them — so that no customer, and no Boss888 product, has to think about any of it. It is not a hosting company and does not compete on commodity price; it is the layer that makes everything else we sell possible, reliable and permanent. It exists because infrastructure is the one thing every business needs continuously, which makes it both the easiest relationship to begin and the hardest to end. INFRA888 accepts customers wherever they start — a single domain, a mailbox, a website someone else built badly — sees the truth of their estate, and uses that truth to guide them stage by stage toward marketing, CRM, automation and ultimately an AI-operated business. Every Boss888 product consumes INFRA888; INFRA888 depends on none of them. It turns one-time projects into lasting relationships, and lasting relationships into the recurring revenue that determines what this company is worth.**

---

## 7. THE ONE LINE

**v1:** *"We make it impossible to leave."*
**v2:** *"We take responsibility so completely that adopting the rest of the platform becomes obvious."*
**v3:** **"We meet a business wherever it is, take its infrastructure off its hands, and use what we learn to grow it — one stage at a time — into a company that runs on us."**
