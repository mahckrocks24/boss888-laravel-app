# INFRA888 — Sprint 3: Production-Ready Domains (Cloudflare for SaaS)

**Date:** 2026-07-24
**Mode:** Backend + frontend build, staging = production. **Non-mutating / gated / additive only** — no live Cloudflare mutation, no production DB migration run (deferred to the authorized release gate), PTAA untouched.
**Architecture (LOCKED by Boss):** external custom domains connect via **Cloudflare for SaaS Custom Hostnames** — NOT DNS records in the LevelUp zone, NOT nginx+certbot, NOT DNS-only-as-connected.
**Frontend build:** `infrastructure.js?v=1.7.0-domains`. **Backend:** new provider/lifecycle stack + refactored `CustomDomainService`, all deployed to staging **gated OFF**.
**Backups:** `/root/domains-sprint3-20260724-060918/` (old CustomDomainService) · frontend `/root/hosting-sprint2-20260724-063825/`.

---

## Phase 1 — Recon (read-only, evidence)
- **Zone:** `levelupgrowth.io`, **Free Website** plan, active. Zone id present. Token active.
- **Existing token canNOT access custom hostnames:** `/custom_hostnames` and `/custom_hostnames/fallback_origin` both return **`10000 Authentication error`**. It can read/write `dns_records` + read the zone, but lacks SSL-for-SaaS/custom-hostname permission.
- **Fallback origin:** unknown (not readable with the current token).
- **Why the old code was fundamentally broken:** it created a `dns_records` CNAME **named after the customer's external domain** inside the LevelUp zone — Cloudflare hard-rejects names outside the zone, so it could never work for a real external domain (and 500'd on the missing `domain_verified_at` column anyway). All 9 existing CNAMEs in the zone are internal `*.levelupgrowth.io`.
- **Cloudflare for SaaS entitlement:** 100 custom hostnames **free on all plans** (incl. Free); SSL included; $0.10/hostname overage. No paid subscription needed to start — the zone just needs **Cloudflare for SaaS enabled**.
- **Apex vs subdomain:** subdomains work via CNAME. **Apex domains need Cloudflare Apex Proxying (Enterprise)** → **first release supports subdomains only**; the UI + service detect and reject apex with guidance.

## Phase 2–5 — Backend (built + mock-tested, gated)
Provider-portable, Cloudflare-specifics isolated in the adapter:
- `app/Services/Domains/Contracts/CustomHostnameProvider.php` — provider contract (create/get/list/verify/delete/getValidationInstructions/getCertificateStatus).
- `app/Services/Domains/Providers/CloudflareSaasProvider.php` — CF for SaaS v4 adapter (`/zones/{zone}/custom_hostnames`), DV/TXT certs, bounded retry on 429/5xx, idempotent delete (404 = deleted), maps CF payload → neutral DTO.
- `app/Services/Domains/CustomHostnameResult.php` (DTO) · `ProviderException.php`.
- `app/Models/CustomDomain.php` — tenant-scoped (`BelongsToWorkspace`) lifecycle model with an explicit **state machine**: `pending_setup → awaiting_dns → validating → ssl_pending → active`, plus `failed / disconnecting / disconnected`.
- `database/migrations/2026_07_24_070000_create_custom_domains_table.php` — **additive** table (website_id, workspace_id, domain, hostname, provider, provider_hostname_id, state, ownership/ssl/routing_status, verification_method, verification_records JSON, last_error, metadata, connected/verified/ssl_issued/disconnected/last_checked timestamps). **Not run on production** (release-gated).
- `app/Services/CustomDomainService.php` — rewritten orchestrator: derives website/workspace, **production mutation gate** (`config('cloudflare.saas_enabled')`, default false), **idempotent** connect (no duplicate hostnames), **persists provider id immediately**, **compensating cleanup** (deletes the CF hostname if local persist fails — never assumes a DB tx rolls back a CF call), apex rejection, correlation-id logging of every provider mutation (workspace/website/hostname/action/result), verify (re-trigger DCV + sync), status (read-only), idempotent disconnect (tolerates already-deleted). Safe pre-migration: gated + `Schema::hasTable` guards mean it never touches the absent table.
- `app/Console/Commands/ReconcileCustomDomains.php` — `domains:reconcile`; cross-tenant sweep that syncs in-flight hostnames, retries orphan compensation, ages-out never-created rows. No-op while gate closed.
- `config/cloudflare.php` — zone/token/account, fallback_origin, routing_target, ssl method, and the `saas_enabled` gate.

**Tests (mocked CF, on the `levelup_test` DB): 16 passed.** `tests/Feature/Domains/` — adapter mapping (create/active/404→null/idempotent-delete/hard-error→exception/routing-CNAME) + orchestration (gating, connect+persist, idempotency, apex reject, invalid, second-domain-requires-disconnect, verify→active, disconnect+idempotent, **compensating cleanup deletes the hostname on persist failure**, provider-error→failed-with-no-orphan).

**Deployed to staging, gated OFF, verified:** classes load; gate closed; `custom_domains` table correctly absent; gated `connect()` runs without touching the table. This is strictly **safer than the code it replaced** (which 500'd).

## Phase 6 — Frontend (wired, gated)
`infrastructure.js` v1.7.0 — Domain tab is now a real lifecycle:
- Enter domain → **client syntax + apex detection** (apex → steer to a subdomain, Connect disabled) → "looks good".
- **Connect** → hits the backend; while the gate is closed the customer gets an **honest "being finalized / not available yet" notice + a preview of the exact CNAME** they'll add (no fake success). When connected: separate **Ownership / HTTPS / Routing** stages, DNS-record instructions, **Check status** (verify), **Disconnect**. No Cloudflare jargon.
- **Browser QA (headless, real round-trips):** apex `acme.com` → guidance + disabled; `www.acme.com` → looks good + enabled; Connect → gated notice + CNAME preview (`www.acme.com → levelupgrowth.io`). No new JS errors (only pre-existing 401s). Screenshots: `screenshots/S3-01/02/03-domain-*.png`.

## Phase 7 — REQUIRED CREDENTIAL (this unblocks live validation)
To flip the gate on and validate live, provision a **scoped Cloudflare API token** (NOT a Global API Key) and enable the zone feature:

1. **Enable "Cloudflare for SaaS"** on the `levelupgrowth.io` zone (dashboard → SSL/TLS → Custom Hostnames). Free tier = 100 hostnames.
2. **Create an API token** scoped to the `levelupgrowth.io` zone with these permissions (exact current Cloudflare labels):
   - **Zone → SSL and Certificates → Edit**  ← required for custom-hostname create/read/delete + SSL for SaaS.
   - **Zone → Zone → Read**  ← resolve/read the zone.
   - **Zone → DNS → Edit**  ← only if this token should also publish the fallback-origin / CNAME-target records in our zone (recommended so provisioning is self-contained).
   - Zone Resources: **Include → Specific zone → levelupgrowth.io**.
3. **Configure a fallback origin** (a proxied A/AAAA/CNAME record in the zone) and set it via `PUT /zones/{zone}/custom_hostnames/fallback_origin`, and publish the proxied **routing target** customers CNAME toward.
4. Provide the token as `CLOUDFLARE_API_TOKEN` (+ optional `CLOUDFLARE_ACCOUNT_ID`, `CLOUDFLARE_SAAS_FALLBACK_ORIGIN`, `CLOUDFLARE_SAAS_ROUTING_TARGET`).

Verify readiness: `GET /zones/{zone}/custom_hostnames` should return `success:true` (not `10000`).

## Phase 8 — Live validation gate (after token, with a disposable hostname we own)
Not customer-facing. Flip `CLOUDFLARE_SAAS_ENABLED=true`, run the **production migration** (needs Boss authorization), then validate with a throwaway subdomain WE own: create → retrieve → DNS instructions → ownership validation → certificate issuance → routing → HTTPS 200 to the correct tenant → status sync → duplicate-connect idempotency → disconnect → provider deletion → reconnect → cleanup → failure recovery. **Never test against a live customer's primary domain.**

## Phase 9 — Release conditions (Connect stays customer-disabled until ALL proven)
fallback/routing configured · scoped token works · custom-hostname API succeeds · ownership validates · SSL active · HTTPS routes to the right tenant · no cross-tenant hostname access · duplicate idempotent · disconnect cleans up · reconciliation handles partial failure · browser journey passes · regression green · rollback documented+tested.

## Blockers / decisions for Boss
1. **[CREDENTIAL — unblocks everything] Provision the scoped token + enable Cloudflare for SaaS + fallback origin** (Phase 7). Until then the gate stays closed and Domains is customer-disabled (by design).
2. **[AUTHORIZATION] Run the `custom_domains` production migration** at release — additive, backed-up, reversible; deferred here per the "production migration needs explicit authorization" boundary.
3. **Apex domains** are out of first release (need Enterprise Apex Proxying). Confirm subdomain-only is acceptable for launch.

## Ops
Deploy: scp → /tmp → `deploy.sh` (backup+chown). Autoload regenerated (`composer dump-autoload -o`), `config:clear` run (never `config:cache`). Gate env: `CLOUDFLARE_SAAS_ENABLED` (default false). QA harness: `/root/qa-domain.cjs`.
