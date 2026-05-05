# BOSS888 MASTER PLAN
## Complete WordPress → Laravel Migration
## Version 1.0 — 2026-04-04

---

## ARCHITECTURE OVERVIEW

```
┌──────────────────────────────────────────────────────────────┐
│                    BOSS888 / LEVELUP OS                       │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐         │
│  │ Marketing   │  │ SaaS App    │  │ Admin       │         │
│  │ Website     │  │ (React)     │  │ Panel       │         │
│  │ levelup     │  │ app.levelup │  │ admin.level │         │
│  │ growth.ai   │  │ growth.ai   │  │ upgrowth.ai │         │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘         │
│         │                │                │                  │
│         │         ┌──────┴──────┐         │                  │
│         │         │  APP888     │         │                  │
│         │         │  (Mobile)   │         │                  │
│         │         └──────┬──────┘         │                  │
│         │                │                │                  │
│  ───────┴────────────────┴────────────────┴──────────        │
│                    Laravel API                                │
│                  api.levelupgrowth.ai                         │
│                                                              │
│  ┌────────────────────────────────────────────────┐          │
│  │              ENGINE KERNEL                      │          │
│  │  ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐    │          │
│  │  │ CRM │ │ SEO │ │Write│ │Crtv │ │Bldr │    │          │
│  │  └─────┘ └─────┘ └─────┘ └─────┘ └─────┘    │          │
│  │  ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐    │          │
│  │  │Mktg │ │Socl │ │ Cal │ │ BA  │ │Traf │    │          │
│  │  └─────┘ └─────┘ └─────┘ └─────┘ └─────┘    │          │
│  └────────────────────────────────────────────────┘          │
│                                                              │
│  ┌────────────────────────────────────────────────┐          │
│  │           EXECUTION PIPELINE                    │          │
│  │  Task → Approval → Queue → Orchestrate →       │          │
│  │  Execute → Verify → Credit → Audit              │          │
│  └────────────────────────────────────────────────┘          │
│                                                              │
│  ┌────────────────────────────────────────────────┐          │
│  │           AI LAYER (Phase 5)                    │          │
│  │  DeepSeek LLM → Agent Reasoning → Multi-step   │          │
│  │  planning → Autonomous execution                │          │
│  └────────────────────────────────────────────────┘          │
│                                                              │
│  ┌───────────┐ ┌───────────┐ ┌───────────┐                  │
│  │  MySQL    │ │  Redis    │ │  S3/CDN   │                  │
│  └───────────┘ └───────────┘ └───────────┘                  │
└──────────────────────────────────────────────────────────────┘
```

---

## 4 INTERFACES

| # | Interface | URL | Purpose | Users |
|---|-----------|-----|---------|-------|
| 1 | **Admin Panel** | admin.levelupgrowth.ai | API keys, user management, system config, monitoring + ALL SaaS features | IT / Operations / Admins |
| 2 | **SaaS App** | app.levelupgrowth.ai | All engines, manual execution, agent environment, onboarding | Paying customers |
| 3 | **APP888** | Mobile APK/iOS | Chat, approvals, task monitoring, notifications | Customers (Pro+) |
| 4 | **Marketing Website** | levelupgrowth.ai | Landing pages, pricing, sign up/login → redirects to app | Public |

---

## PRICING MODEL (CONFIRMED)

| Plan | Price | Credits/mo | AI Access | Sarah (DMM) | Specialists | Agent Level | Websites | Add-on |
|------|-------|-----------|-----------|-------------|-------------|-------------|----------|--------|
| Free | $0 | 0 | None | No | 0 | — | 1 (subdomain) | — |
| Starter | $19 | 0 | None | No | 0 | — | 1 (custom domain) | — |
| AI Lite | $49 | 50 | Research only (assistant, no agents, no generation) | No | 0 | — | 1 (custom domain) | — |
| Growth | $99 | 300 | Full (content + images) | Yes | 2 (user picks) | Specialist | 3 | +$20/agent |
| Pro | $199 | 900 | Full + priority | Yes | 5 (user picks) | Junior Specialist | 10 | +$20/agent |
| Agency | $399 | 2,500 | Full workforce | Yes | 10 (user picks) | Senior Specialist | 50 + white-label + unlimited team | +$10/agent |

### AI Lite Restrictions
- Access to AI Assistant only (research, brainstorming, Q&A)
- NO agent access (no Sarah, no specialists)
- NO content writing (write_article, improve_draft blocked)
- NO image generation (generate_image blocked)
- NO video generation (generate_video blocked)
- NO social post creation via AI
- Enforced at backend: plan feature gating on action dispatch

### Free/Starter Access (No AI)
- Website Builder (manual only, no AI generation)
- CRM (manual only)
- Calendar (manual only)
- Marketing tools (manual only, no AI campaigns)
- Social tools (manual only)

---

## AGENT ROSTER (20 SPECIALISTS + 1 DMM)

### Always Included (AI Plans)
| ID | Name | Role | Color |
|----|------|------|-------|
| sarah | Sarah | Digital Marketing Manager (DMM) | #F59E0B |

### SEO Category (5)
| ID | Name | Role | Level | Color |
|----|------|------|-------|-------|
| james | James | SEO Strategist | Junior | #3B82F6 |
| alex | Alex | Technical SEO Engineer | Senior | #06B6D4 |
| diana | Diana | Local SEO Specialist | Junior | #3B82F6 |
| ryan | Ryan | Link Building Specialist | Junior | #06B6D4 |
| sofia | Sofia | International SEO Director | Senior | #3B82F6 |

### Content Category (5)
| ID | Name | Role | Level | Color |
|----|------|------|-------|-------|
| priya | Priya | Content Manager | Specialist | #7C3AED |
| leo | Leo | Brand Copywriter | Specialist | #7C3AED |
| maya | Maya | Social Content Writer | Junior | #7C3AED |
| chris | Chris | Video Script Writer | Junior | #F97316 |
| nora | Nora | Content Strategy Director | Senior | #7C3AED |

### Social Category (5)
| ID | Name | Role | Level | Color |
|----|------|------|-------|-------|
| marcus | Marcus | Social Media Manager | Specialist | #EC4899 |
| zara | Zara | Instagram Growth Specialist | Junior | #EC4899 |
| tyler | Tyler | LinkedIn Marketing Expert | Junior | #EC4899 |
| aria | Aria | TikTok & Reels Creator | Junior | #F97316 |
| jordan | Jordan | Social Analytics Director | Senior | #EC4899 |

### CRM / Growth Category (5)
| ID | Name | Role | Level | Color |
|----|------|------|-------|-------|
| elena | Elena | Lead & CRM Manager | Junior | #00E5A8 |
| sam | Sam | Email Marketing Specialist | Specialist | #00E5A8 |
| kai | Kai | Lead Nurturing Specialist | Junior | #00E5A8 |
| vera | Vera | Marketing Automation Expert | Junior | #F97316 |
| max | Max | Growth & CRO Director | Senior | #00E5A8 |

---

## 11 ENGINES

| # | Engine | Slug | Description |
|---|--------|------|-------------|
| 1 | CRM | crm | Leads, contacts, deals, pipeline, activities, scoring |
| 2 | SEO | seo | 15 tools — SERP, audits, links, keywords, goals |
| 3 | Content / Write | write | Articles, drafts, outlines, headlines, meta, translation |
| 4 | Creative | creative | Image gen, video gen, edit, upscale, asset library |
| 5 | ManualEdit | manualedit | Canvas editor (Canva-style) for images/creative |
| 6 | Builder | builder | Website builder, templates, Arthur wizard, pages |
| 7 | Marketing | marketing | Email campaigns, automation, sequences, drip |
| 8 | Social | social | Multi-platform posting, scheduling, analytics |
| 9 | Calendar | calendar | Unified calendar across all engines |
| 10 | BeforeAfter | beforeafter | Interior design funnel with AI transformation |
| 11 | Traffic Defense | traffic | Bot detection, click fraud, traffic filtering |

---

## EXECUTION PHASES

### PHASE 1: FOUNDATION CORRECTION
**Goal:** Fix the base so everything built on top is correct.

| # | Task | Priority |
|---|------|----------|
| 1.1 | Update PlanSeeder — 6 plans with correct pricing, credits, features, agent rules | Critical |
| 1.2 | Update AgentSeeder — 21 agents (Sarah + 20 specialists) with levels, categories, colors, skills | Critical |
| 1.3 | Add `category` and `level` and `skills_json` columns to agents table | Critical |
| 1.4 | Add `agent_addon_price` and `max_websites` and `ai_access` columns to plans table | Critical |
| 1.5 | Remove WordPressConnector — delete file, remove from resolver, remove from capability map | Critical |
| 1.6 | Create PlanGatingService — enforce what each plan can/cannot do | Critical |
| 1.7 | Add workspace onboarding fields (business_name, industry, services, goal, location) to workspaces table | High |
| 1.8 | Add agent team selection to workspace_agents (selected specialists per workspace) | High |
| 1.9 | Update API auth response to include plan features + agent team | High |

### PHASE 2: ENGINE BACKENDS (ALL 11)
**Goal:** Every engine has complete backend — models, services, actions, controllers, routes, engine.json.

Each engine follows the same pattern:
```
app/Engines/{Name}/
  ├── engine.json
  ├── EngineServiceProvider.php
  ├── Actions/           (business logic classes)
  ├── Services/          (domain services)
  ├── Repositories/      (data access)
  ├── Contracts/         (interfaces)
  ├── Events/            (domain events)
  ├── Jobs/              (engine-specific jobs)
  ├── Http/
  │   ├── Controllers/   (thin controllers)
  │   └── Routes.php     (engine routes)
  └── Models/            (engine-specific models if needed)
```

| # | Engine | Key Deliverables | Est. Complexity |
|---|--------|-----------------|-----------------|
| 2.1 | **CRM** (complete) | Leads CRUD + search + filters, Contacts CRUD, Deals CRUD + pipeline stages, Activities CRUD, Notes, Lead scoring, Pipeline Kanban data, Import/Export CSV, Revenue aggregation, Today View query | Large |
| 2.2 | **SEO** | 15 tool actions (serp_analysis through pause_goal), Keyword model, Audit model, Goal model, autonomous execution support | Large |
| 2.3 | **Content/Write** | write_article, improve_draft, generate_outline, generate_headlines, generate_meta, Article model, Content model, Version history, Content scoring | Large |
| 2.4 | **Creative** (native) | Native AI provider calls (OpenAI gpt-image-1, MiniMax Hailuo), generate_image, generate_video, edit_image, Asset model, Asset library CRUD, Provider fallback chain, White-label sanitization | Large |
| 2.5 | **Builder** | Page model (page/section/container/element schema), Template model, Website model, Arthur wizard (AI website generation), Page CRUD, Publish workflow, Website migration | Large |
| 2.6 | **Marketing** | Campaign model, Template model, Recipient list model, Automation model (trigger/condition/action), Campaign CRUD + send + schedule, Drip sequences, Analytics tracking | Large |
| 2.7 | **Social** | SocialPost model, Platform accounts, Scheduling, Multi-platform compose, Analytics, Content calendar data | Medium |
| 2.8 | **Calendar** | Event model, Category system, Unified event aggregation from all engines, Recurrence support | Medium |
| 2.9 | **BeforeAfter** | Design model, Image processing (upload + crop), AI transformation (gpt-image-1), Geometry analysis (GPT-4o Vision), Design report generation (7 sections), Slider data | Medium |
| 2.10 | **Traffic Defense** | Rule model, Traffic log model, Bot detection logic, Alert system, Whitelist/blacklist | Medium |
| 2.11 | **ManualEdit** | Operation model, Canvas state model, Operation dispatcher, Export service | Medium (backend only — editor is Phase 6) |

### PHASE 3: SAAS FRONTEND (MANUAL USE)
**Goal:** Users can manually use every engine and tool through the browser.

| # | View/Component | Description |
|---|---------------|-------------|
| 3.1 | **App Shell** | Sidebar, topbar, router, auth gate — migrate from existing vanilla JS |
| 3.2 | **Auth** | Login, signup, password reset |
| 3.3 | **Onboarding** | Business info → industry → goal → website generation → agent team selection |
| 3.4 | **Command Center** | Dashboard with KPIs, agent status, recent tasks, insights |
| 3.5 | **Engine Hub** | Grid of all 8 engines with stats and launch buttons |
| 3.6 | **CRM View** | Lead list + filters, Kanban pipeline, Lead detail, Contacts, Deals, Activities, Today View |
| 3.7 | **SEO View** | Tool launcher, SERP results, Audit reports, Link suggestions, Goal management |
| 3.8 | **Write View** | Article list, Content editor, Brief creator, Draft improver |
| 3.9 | **Creative View** | Asset library, Generate image/video, Asset detail |
| 3.10 | **Builder View** | Website list, Page editor (basic — visual editor in Phase 6), Arthur wizard |
| 3.11 | **Marketing View** | Campaign list, Campaign builder, Template editor, Automation flows |
| 3.12 | **Social View** | Post composer, Content calendar, Scheduling, Analytics |
| 3.13 | **Calendar View** | Unified calendar with events from all engines |
| 3.14 | **Strategy Room** | Agent meeting launcher, Meeting chat, Task tracking |
| 3.15 | **Tasks View** | Task list, Task detail, Progress, Timeline, Approvals |
| 3.16 | **Analytics View** | Insights dashboard, Credit usage, Engine performance |
| 3.17 | **Settings** | Workspace settings, Billing, Team management |
| 3.18 | **Notifications** | Notification panel, Mark read |
| 3.19 | **Credit Display** | Balance, Usage, Transaction history |

### PHASE 4: ADMIN PANEL
**Goal:** IT/Operations can configure and monitor everything.

| # | Module | Features |
|---|--------|----------|
| 4.1 | **User Management** | List users, edit, suspend, delete, impersonate |
| 4.2 | **Workspace Management** | List workspaces, view details, override settings |
| 4.3 | **API Key Management** | Configure AI provider keys (DeepSeek, OpenAI, MiniMax), email provider keys (Postmark), social platform keys |
| 4.4 | **Plan Management** | View/edit plans, feature flags, credit limits |
| 4.5 | **System Health** | Connectors, circuits, queue, stale tasks, throttling |
| 4.6 | **Task Monitor** | Full task list with timeline, filters, manual recovery |
| 4.7 | **Validation Report** | Reliability score, failure analysis |
| 4.8 | **Queue Control** | Queue pressure, stuck jobs, trigger recovery |
| 4.9 | **Audit Logs** | Searchable audit log viewer |
| 4.10 | **All SaaS Features** | Admin has access to everything in the SaaS app too |

### PHASE 5: LLM INTEGRATION (AGENT INTELLIGENCE)
**Goal:** Agents can reason, plan, and execute autonomously.

| # | Feature | Description |
|---|---------|-------------|
| 5.1 | **DeepSeek Integration** | API client, prompt templates, response parsing |
| 5.2 | **Instruction Parser** | Natural language → structured task (engine, action, params) |
| 5.3 | **Multi-step Planner** | Complex request → sequence of tasks |
| 5.4 | **Agent Reasoning** | Each agent has role-specific prompts and context |
| 5.5 | **Strategy Meeting AI** | Multi-agent collaboration on a goal |
| 5.6 | **Autonomous Goals** | Sarah sets and monitors long-term goals |
| 5.7 | **Agent Cost Intelligence** | Value-tier classification, budget-aware planning |
| 5.8 | **Agent Experience** | Learn from past task outcomes |

### PHASE 6: REACT MIGRATION + VISUAL EDITORS
**Goal:** Professional editing tools.

| # | Feature | Description |
|---|---------|-------------|
| 6.1 | **React Migration** | Migrate entire SaaS frontend from vanilla JS to React + Vite |
| 6.2 | **ManualEdit888** | Canva-style image editor (canvas, layers, text, shapes, filters, export) |
| 6.3 | **Video Editor** | CapCut-style video editor (timeline, cuts, text overlay, transitions) |
| 6.4 | **Builder Visual Editor** | Full drag-and-drop page builder (React-based) |
| 6.5 | **Content Rich Editor** | WYSIWYG article editor with AI assist |

### PHASE 7: MARKETING WEBSITE MIGRATION
**Goal:** Remove WordPress entirely.

| # | Task | Description |
|---|------|-------------|
| 7.1 | Migrate all pages (index, pricing, use-cases, specialists, FAQ, etc.) | Static HTML/React served by Laravel or separate static host |
| 7.2 | Update all CTAs to point to app.levelupgrowth.ai | Sign up / Login redirects |
| 7.3 | SEO preservation | Maintain URLs, meta, sitemap |
| 7.4 | DNS cutover | levelupgrowth.ai → new host |

### PHASE 8: INTEGRATIONS + PAYMENTS
**Goal:** Real money flows and third-party connections.

| # | Integration | Description |
|---|------------|-------------|
| 8.1 | **Stripe** | Subscription billing, plan upgrades, add-on agents, credit top-ups |
| 8.2 | **Firebase** | Push notifications for APP888 |
| 8.3 | **Social Platform APIs** | Instagram, Facebook, Twitter, LinkedIn, Snapchat |
| 8.4 | **WhatsApp Business** | MENA priority messaging |
| 8.5 | **Google Search Console** | SEO data integration |
| 8.6 | **Google Analytics** | Traffic data |
| 8.7 | **Cloudflare** | CDN, SSL, DNS for client websites |

### PHASE 9: APP888 FINAL ALIGNMENT
**Goal:** Mobile app fully connected and working.

| # | Task | Description |
|---|------|-------------|
| 9.1 | Apply auth patch (dual token) | Already prepared |
| 9.2 | Apply API endpoint patch | Already prepared |
| 9.3 | Push notification integration | FCM/APNs via Firebase |
| 9.4 | Test full flow | Login → chat → task → approve → see result |

### PHASE 10: PRODUCTION DEPLOYMENT
**Goal:** Live system serving real users.

| # | Task | Description |
|---|------|-------------|
| 10.1 | Production server setup | Laravel on dedicated server or container |
| 10.2 | Database migration | MySQL production instance |
| 10.3 | Redis production | Managed Redis |
| 10.4 | Queue workers | Supervisor with 5+ workers |
| 10.5 | DNS configuration | api/app/admin subdomains |
| 10.6 | SSL certificates | Let's Encrypt or Cloudflare |
| 10.7 | Monitoring | Health checks, error tracking, uptime |
| 10.8 | Backup strategy | Database + media backups |
| 10.9 | Load testing | Validate under real traffic |

---

## FILE DIRECTORY STRUCTURE (FINAL)

```
boss888-laravel/
├── app/
│   ├── Core/                          # Platform kernel
│   │   ├── Agent/                     # Agent dispatch + conversation
│   │   ├── Agents/                    # Agent registry
│   │   ├── Audit/                     # Audit logging
│   │   ├── Auth/                      # JWT auth, refresh, workspace switch
│   │   ├── Billing/                   # Credits, plans, subscriptions
│   │   ├── DesignTokens/             # Theme tokens
│   │   ├── EngineKernel/             # Registry, manifest, capability map
│   │   ├── Governance/               # Approval service
│   │   ├── Meetings/                 # Meeting/strategy room
│   │   ├── Memory/                   # Workspace memory
│   │   ├── Notifications/            # Notification service
│   │   ├── PlanGating/               # Plan feature enforcement (NEW)
│   │   ├── SystemHealth/             # Health monitoring
│   │   ├── TaskSystem/               # Orchestrator, dispatcher, service
│   │   └── Workspaces/              # Workspace service
│   │
│   ├── Engines/                      # Self-contained engines
│   │   ├── CRM/                     # Leads, contacts, deals, pipeline
│   │   │   ├── Actions/
│   │   │   ├── Services/
│   │   │   ├── Repositories/
│   │   │   ├── Contracts/
│   │   │   ├── Events/
│   │   │   ├── Models/
│   │   │   ├── Http/Controllers/
│   │   │   ├── Http/Routes.php
│   │   │   ├── engine.json
│   │   │   └── EngineServiceProvider.php
│   │   ├── SEO/                     # 15 SEO tools
│   │   ├── Write/                   # Content writing
│   │   ├── Creative/                # Image/video generation (native AI)
│   │   ├── Builder/                 # Website builder + Arthur wizard
│   │   ├── Marketing/               # Campaigns, automation
│   │   ├── Social/                  # Multi-platform social
│   │   ├── Calendar/                # Unified calendar
│   │   ├── BeforeAfter/             # Interior design funnel
│   │   ├── TrafficDefense/          # Bot/fraud protection
│   │   └── ManualEdit/              # Canvas editor backend
│   │
│   ├── Connectors/                   # External service connectors
│   │   ├── Contracts/
│   │   ├── BaseConnector.php
│   │   ├── ConnectorResolver.php
│   │   ├── OpenAIConnector.php      # gpt-image-1, GPT-4o, DALL-E
│   │   ├── DeepSeekConnector.php    # Agent reasoning LLM
│   │   ├── MiniMaxConnector.php     # Hailuo video
│   │   ├── RunwayConnector.php      # Video fallback
│   │   ├── EmailConnector.php       # Postmark + SMTP
│   │   ├── SocialConnector.php      # Platform APIs
│   │   └── StripeConnector.php      # Payments
│   │
│   ├── Services/                     # Cross-cutting services
│   │   ├── IdempotencyService.php
│   │   ├── ConnectorCircuitBreakerService.php
│   │   ├── ExecutionRateLimiterService.php
│   │   ├── QueueControlService.php
│   │   ├── TaskProgressService.php
│   │   ├── ParameterResolverService.php
│   │   ├── PerformanceCollector.php
│   │   ├── ValidationReportService.php
│   │   └── PlanGatingService.php    # NEW
│   │
│   ├── Http/
│   │   ├── Controllers/Api/         # All API controllers
│   │   ├── Middleware/
│   │   └── Requests/
│   │
│   ├── Models/                       # All Eloquent models
│   ├── Jobs/                         # Queue jobs
│   ├── Events/                       # Application events
│   ├── Providers/                    # Service providers
│   ├── Console/Commands/             # Artisan commands
│   └── Exceptions/
│
├── config/
│   ├── app.php
│   ├── database.php
│   ├── queue.php
│   ├── cache.php
│   ├── redis.php
│   ├── connectors.php               # AI provider configs
│   ├── execution.php                # Idempotency, circuit breaker, rate limits
│   ├── queue_control.php            # Concurrency, stale timeout
│   ├── plans.php                    # Plan definitions (NEW)
│   └── supervisor/
│
├── database/
│   ├── migrations/
│   └── seeders/
│
├── routes/
│   └── api.php                      # All API routes
│
├── frontend/                         # SaaS frontend (vanilla JS → React later)
│   ├── public/                      # Static assets
│   ├── css/
│   ├── js/
│   └── pages/
│
├── admin/                            # Admin panel frontend
│
├── marketing/                        # Marketing website (migrated from WP)
│
├── tests/
│   ├── Feature/
│   └── Helpers/
│
├── docker/
├── docs/
│   ├── MASTER-PLAN.md               # This file
│   ├── FRONTEND-API-ALIGNMENT.md
│   ├── INFRASTRUCTURE-SETUP.md
│   └── ENGINE-CONTRACTS.md          # API contracts per engine (NEW)
│
├── storage/
├── bootstrap/
├── public/
├── composer.json
├── phpunit.xml
└── .env.example
```

---

## RULES (NON-NEGOTIABLE, THROUGHOUT ALL PHASES)

1. **No WordPress.** Zero WP code, zero WP API calls, zero WP dependencies.
2. **All engines through the pipeline.** TaskService → Approval → Queue → Orchestrate → Execute → Verify → Credit → Audit.
3. **Manual and agent use the same code paths.** No separate logic for manual vs agent execution.
4. **Plan gating enforced at backend.** Frontend shows/hides UI, but backend BLOCKS unauthorized actions.
5. **No fake data in production.** Mock mode only for development, clearly flagged.
6. **Every action logged.** Audit trail for everything.
7. **Credits always correct.** Reserve → commit/release lifecycle, no exceptions.
8. **Engine isolation.** No cross-engine direct coupling. Engines communicate through task system.
9. **Thin controllers.** All business logic in Actions/Services.
10. **Test everything.** Each engine gets its own test suite.

---

## EXECUTION ORDER

```
Phase 1  →  Foundation Correction (pricing, agents, plan gating, remove WP)
Phase 2  →  Engine Backends (all 11 engines, backend complete)
Phase 3  →  SaaS Frontend (manual use of all engines, vanilla JS first)
Phase 4  →  Admin Panel (configuration + monitoring)
Phase 5  →  LLM Integration (DeepSeek, agent intelligence)
Phase 6  →  React Migration + Visual Editors (Canva/CapCut style)
Phase 7  →  Marketing Website Migration
Phase 8  →  Integrations + Payments (Stripe, social APIs, WhatsApp)
Phase 9  →  APP888 Final Alignment
Phase 10 →  Production Deployment
```

**We build. We test. We move. No phase ships broken.**

---

*This is the single source of truth. Every future task references this document.*
*Updated: 2026-04-04 by Claude + Shukran*
