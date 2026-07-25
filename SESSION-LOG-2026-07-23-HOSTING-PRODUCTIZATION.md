# INFRA888 — Hosting Services Productization (Session Log)

**Date:** 2026-07-23
**Scope:** Customer-facing productization of the existing INFRA888 engine as "Hosting".
**Environment:** staging codebase on the DO droplet (which IS production — serves levelupgrowth.io + live customer domains). No second staging instance exists.
**Golden rule honoured:** engine codename INFRA888/BOSS888 never rendered to a customer surface; backend classes, namespaces, tables, APIs unchanged.

---

## What shipped (all customer-facing / additive)

### 1. Navigation rename — "Infrastructure" → "Hosting"
- Sidebar section label `Infrastructure` → `Hosting`.
- Nav item label `Infrastructure` → `Hosting` (id `ni-infrastructure` kept — route/handler unchanged).
- Added two sidebar sub-items: **Domains** (`ni-infra-domains`), **Email Accounts** (`ni-infra-email`), deep-linking to the matching tab via new `window.infraOpenTab(tab)`.
- Page `<h1>` `Infrastructure` → **Hosting Services**; subtitle rewritten for customers.
- Files: `public/app/index.html`, `public/app/js/infrastructure.js`.
- Cache-bust bumped `1.2.0-infra888-intelligence` → **1.3.1-hosting-productization**.

### 2. Tabs reordered + relabelled (customer vocabulary)
Products first, ops second:
`Hosting · Domains · Email Accounts | Overview · What's Running · Issues · Uptime`
(was `Overview · Assets · Incidents · Reliability | Hosting · Domains · Email`).
Default tab is now **Hosting** (was Overview).

### 3. Engineering vocabulary removed (directive §DATA-INTEGRITY preserved)
- `Assets`→`What's running`, `Incidents`→`Issues`, `Reliability`→`Uptime`.
- `MTTR`→`Average fix time`, `MTBF`→`Typical gap between issues`.
- Management modes: `Adopted`→`Connected` (we watch it, we didn't set it up), `Provisioned`→`Set up by LevelUp`, `Managed by INFRA888`→`Managed by LevelUp`.
- The adopted-vs-provisioned integrity distinction is preserved exactly — "connected" is never presented as "ours". The "not a claim of 100% uptime" honesty line is retained, reworded for customers.

### 4. Create-hosting wizard (Phase 3) — uses the EXISTING architecture
New 4-step flow replacing the single name field:
`Website → Plan → Web address → Review → Create`.
- Step 1: pick a website (`GET /api/builder/websites`).
- Step 2: plan = only the real entitlement allowance ("Included with your plan, N remaining"). No invented tiers/prices.
- Step 3: subdomain with `{label}.levelupgrowth.io` suffix and **live availability** via `GET /api/builder/check-subdomain?slug=` (the same endpoint Builder uses — one consistent answer).
- Step 4: review + Create.
- Submits `POST /api/infrastructure/hosting` with `{name, environment, website_id, subdomain}` and the existing Idempotency-Key.

### 5. Progress narrative (Phase 4)
Operation view gains a plain-English tracker: **Request received → Approved → Setting up hosting → Ready**. Stages tick ONLY from real backend state (never a timer, never optimistic). Failed/rejected shows no false ticks. Existing activity timeline retained.

### 6. Empty states (Phase 5) rewritten to the requested copy
Hosting / Domains / Email Accounts each now guide the customer instead of showing a blank panel.

---

## Backend (additive only — no second provisioning path)

### SubdomainService (NEW) — single canonical authority
`app/Engines/Infrastructure/Services/SubdomainService.php`
- Canonical rules: normalize (lowercase → strip non `[a-z0-9-]` → collapse hyphens → trim), regex `^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$`, length 3–50, hostname `{label}.levelupgrowth.io`.
- Reserved list copied verbatim from Builder's two closures so behaviour is identical when they are migrated onto it (that migration is the LAST step, deliberately not done yet).
- Resolves a pre-existing divergence: Builder's set-subdomain closure enforced 3–50 while its availability closure enforced 2–40. 3–50 is canonical.
- 37 tests, all green: `tests/Feature/Infrastructure/SubdomainServiceTest.php`.

### ProvisioningService::validateHostingPayload — extended
Adds OPTIONAL `website_id` (int) and `subdomain` (validated through `SubdomainService::requireAvailable`). Requests that omit them behave exactly as before. Confirmed persisted: op#34 `request_json` = `{"name":"LevelUp Growth hosting","subdomain":"my-new-shop-29387","website_id":2,"environment":"staging"}`.

---

## Proof (real browser, real auth, real API)

- Full walkthrough in headless Chromium against `https://levelupgrowth.io/app/`, authenticated as owner of ws#1.
- Wizard driven end-to-end; **operation #34 created → approved (by second owner, separation-of-duties) → queue worker ran Null connector → state `succeeded` in ~3s → hosting account #14 created**. Allowance moved to "1 used, 2 remaining".
- **0 page errors** across the flow. (Three 401s on `/exec/mode`, `/design-tokens`, `/policy` are unrelated pre-existing endpoints and appear regardless of this work — not introduced here.)
- Full `tests/Feature/Infrastructure` suite: **352 passed, 1 skipped, 0 failed.**

## Honest limitations / remaining gaps
- **Hosting is still the Null connector** — no live server is created. Every screen that can start/finish a request carries a customer-safe "Preview — real setup steps, live hosting not switched on yet" note. A real hosting connector (Phase: Shared-Origin Hosting) is the next build.
- **Domains + Email Accounts are empty-state only** (backend returns nothing yet) — labelled "Coming soon".
- **Builder's two subdomain closures NOT yet migrated onto SubdomainService** — deliberate (locked order: closures last). They still work; the service is a faithful superset ready for the swap.
- **Overview/What's-running still count `infra_assets`**, so a created hosting account does not yet appear there (asset-graph adoption is a separate layer). Hosting tab does reflect it.
- Availability hint uses Builder's 2–40 endpoint; a 41–50-char label (rare) would pass the wizard's 3–50 check but the hint may show the endpoint's length message. Server re-validates authoritatively on submit via SubdomainService.

## Gating decision
Hosting remains **Advanced-mode only** (hidden in default Basic mode). Given the Null-connector preview state, exposing a "Create hosting" CTA to all customers in Basic mode would be premature. Flip is a one-line change to the Basic allowlist in `index.html` when a real connector lands.

## Backups
`/root/hosting-productization-20260723-134708/` — index.html, infrastructure.js, InfrastructureController.php, routes/api.php, config/infrastructure.php, ProvisioningService.php (pre-wizard). sha256 of originals recorded in the backup run.

## Files changed
- `public/app/index.html` (nav labels, sub-items, version bump) — backup + `.bak-20260723-navfix` (earlier session's blog/write fix) preserved.
- `public/app/js/infrastructure.js` (rewrite of customer-facing copy + wizard + progress tracker + deep-link).
- `app/Engines/Infrastructure/Services/SubdomainService.php` (NEW).
- `app/Engines/Infrastructure/Services/ProvisioningService.php` (additive validation).
- `tests/Feature/Infrastructure/SubdomainServiceTest.php` (NEW, 37 tests).

## QA data left on ws#1 (LevelUp Growth, the company's own workspace)
- Hosting account #14 "LevelUp Growth hosting" (Null connector, state `provisioning`) — the succeeded demo. Harmless; remove on request.
- infra_operations #31–33 orphaned at `awaiting_approval` (their approvals rejected); create no accounts, invisible to customers.
- Leftover QA approvals rejected; approval queue clean (0 pending).
