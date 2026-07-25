# START HERE — INFRA888 / Hosting · Handoff 2026-07-24

Read this first. It orients a fresh session on the Hosting (INFRA888) work and how to resume.

## 1. Current running state (staging = production droplet)
- **Live build:** `public/app/js/infrastructure.js?v=1.7.0-domains` deployed at `levelupgrowth.io/app`. (2026-07-24 Sprint 3 — Cloudflare-for-SaaS Domains backend + gated frontend; see `SESSION-LOG-2026-07-24-SPRINT3-DOMAINS.md`. Sprint 2 = `...-SPRINT2-A11Y.md`.)
- **Domains (Sprint 3):** full Cloudflare-for-SaaS custom-hostname lifecycle built + mock-tested (16 pass), deployed **gated OFF** (`CLOUDFLARE_SAAS_ENABLED=false`). Customer-disabled by design until a scoped CF token + zone enablement + live validation. **To unblock: provision the scoped token (Phase 7 in the Sprint-3 log) + authorize the `custom_domains` prod migration.**
- **How to see it:** log into `/app` → sidebar toggle **Advanced** → sidebar **Hosting** section → **Websites**.
- **Gating:** Hosting nav shows in **Advanced mode** only (hidden in Basic — one-line flip in `index.html` Basic allowlist when ready). All 10 plans have the `infrastructure` flag on.
- **Connector reality:** hosting is still the **Null connector = PREVIEW**. No live server is provisioned. Every screen says so honestly. Managed Hosting + external migration are prepared, not live.

## 2. What exists now (the product)
Two customer journeys, one product (LOCKED strategy):
- **Native LevelUp websites** (Builder sites, already live on `*.levelupgrowth.io`) → begin at **Manage**, NEVER "provision".
- **External websites** (WordPress/Laravel/PHP/static) → **Add existing website** wizard → designed migration handoff.

Customer surface (sidebar-driven, no plane-mix): **Websites** (metric strip + rows) · **Domains** · **Email Accounts**. Website detail = tabs **Overview/Hosting/Domain/Activity/Settings**. Operational telemetry (assets/incidents/reliability) is **admin-only** behind an "Operations" surface.

Classification: reuse existing `websites.platform` column — empty = native, external value = external. NO new field. `SubdomainService` is the canonical subdomain authority; Builder's `check-subdomain` is the availability endpoint.

## 3. The governing documents (conform to these; do NOT re-plan)
- **The Product Bible** (constitutional — every future feature conforms or triggers an amendment): `LVL\infra888-product-bible-2026-07-24\product-bible.html`. 10 Laws, engine/maturity/entity/state/event/automation/API/tenancy/permission/commercial/metrics/design models. Website = atomic unit; two-plane split; included-never-resold.
- **Hosting Masterplan** (object-centric IA + P0–P4 roadmap): `LVL\hosting-masterplan-2026-07-24\masterplan.html`.
- **Commercial truth** (the Builder already IS hosting; don't re-sell it): `LVL\hosting-commercial-audit-2026-07-23\commercial-audit.html`.

## 4. History of this arc (newest first)
1. **2026-07-24 Enterprise frontend alignment** (v1.5.1) — native SPA shell, plane split, tabbed detail. → `SESSION-LOG-2026-07-24-ENTERPRISE-ALIGN.md`
2. **2026-07-24 Product Bible + Masterplan** — strategy docs (constitution).
3. **2026-07-24 Two-journey realignment** (v1.4.0) — Manage vs Add-existing. → `SESSION-LOG-2026-07-24-HOSTING-REALIGN.md`
4. **2026-07-23 Commercial audit** — found the Builder-is-hosting truth.
5. **2026-07-23 Hosting productization** (v1.3.1) — first customer "Hosting Services". → `SESSION-LOG-2026-07-23-HOSTING-PRODUCTIZATION.md`

## 5. Roadmap (Boss-locked 2026-07-24, REVISED with release sprint) & status
S1 Enterprise UX ✅ · S2 Accessibility ✅ · **S3 Domains (Cloudflare for SaaS)** — feature-complete, mock-tested, gated OFF · **➡ S3.5 PRODUCTION LAUNCH VALIDATION (next — Release Candidate): real Cloudflare, NO new code; prove Connect Domain end-to-end + failure recovery + runbook. Plan: `SPRINT3.5-PRODUCTION-VALIDATION.md`.** → Release Candidate → **Customer Live** · then **S4 Business Email** (architecture LOCKED, spec in `SPRINT4-BUSINESS-EMAIL-DESIGN.md`; does NOT start until Domains is customer-live) · S5 Managed Hosting · S6 Provider Marketplace · S7 Infrastructure Automation.

**Working style (Boss directives):** (1) continuous engagement — roll into the next sprint; pause only for a business decision, production-risk boundary, or architectural blocker ([[levelup-continuous-engagement]]). (2) **Measure progress by customer-completable journeys (proven or honestly gated), NOT by code written — Release-Candidate mindset** ([[levelup-ship-not-code]]). A sprint is done only when a paying customer could complete its journey today.

**S3 to finish → go live (needs Boss):** (1) provision the scoped CF token + enable Cloudflare for SaaS + fallback origin (Phase 7 in the S3 log); (2) authorize the additive `custom_domains` production migration; then flip `CLOUDFLARE_SAAS_ENABLED=true` and run Phase 8 live validation with a disposable hostname. Apex domains are out of first release (Enterprise apex proxying).

**Still open from earlier (not blocking S3):** Operations intelligence authz is UI-only (`/api/infrastructure/intelligence/*` = `auth.jwt` only, no admin gate → non-admin gets 200; tenant isolation intact per blastRadius audit) — a product decision.

Do NOT: write more strategy docs, add a 2nd website inventory/subdomain service, expose provider/Cloudflare terminology to customers, fake domain/SSL success, **make live Cloudflare mutations or run the domain migration without Boss authorization**, touch PTAA.

## 6. Operational reminders (from memory)
- Staging IS production. Backup every file before editing. Push with **scp** (never base64-over-pipe). Never `config:cache` (use `config:clear`).
- Use **script files for ssh** (inline commands corrupt). PowerShell **Get/Set-Content corrupts UTF-8** — inject artifact base64 with explicit UTF-8 read; write files with the Write tool.
- Server has puppeteer + Chromium at `.puppeteer-cache/...` for headless browser QA. Mint a token pair with `/root/infra-mint2.php` (user 1 / ws#1 Pro). Second approver for provisioning = user #990016 (`infra-approver-staging@levelupgrowth.io`, separation of duties).
- Bump the `infrastructure.js?v=` string on every deploy or the browser serves cached JS.
- Codename INFRA888/BOSS888 must NEVER render on a customer surface.

## 7. QA data note
ws#1 (LevelUp Growth) holds one QA hosting account (#14, Null connector) + a few `infra_operations` (31–34) from earlier provisioning tests. Harmless; remove on request. This session's QA created no records.

## Backups
`/root/hosting-align-20260723-212146/` (this sprint) · `/root/hosting-realign-20260723-200633/` · `/root/hosting-productization-20260723-134708/`.
