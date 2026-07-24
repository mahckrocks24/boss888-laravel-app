# Sprint 4 — Business Email · Architecture & Build Spec (Boss-locked 2026-07-24)

**Status:** Architecture LOCKED + all seams mapped. Code build is the next increment. **Gate (Boss):** build ONLY the agnostic engine (architecture, contracts, schema, orchestration, UI framework, mocked tests) — **no Migadu adapter, no provider credentials, no live provisioning until Domains ([[infra888-domains-cloudflare-saas]]) is production-ready.** Memory: [[infra888-business-email]].

## Decisions (locked)
- Provider-**agnostic** Business Email engine on the EXISTING contract `app/Connectors/Infrastructure/Contracts/EmailProviderConnector.php` (already designed: `capabilities/verifyDomain/getDomainAuthStatus/createMailbox/suspendMailbox/deleteMailbox/setMailboxQuota/requestPasswordReset/createAlias/createForwarder`, all returning `ProviderResult`, all idempotency-keyed).
- **Migadu** = first concrete adapter (Phase 2, post-Domains-live). Google/M365/Zoho later, no core change. **Cloudflare Email Routing REJECTED** as a product tier.
- **Root object = the connected DOMAIN**, not the mailbox (mirrors Website-as-root). Journey: Website → Connect Domain → Business Email → Create Mailbox → DNS Verified → Ready.
- Naming: "Business Email" everywhere customer-facing.

## Seams confirmed (real code)
- `InfrastructureConnector` base: `provider()/capability()/healthCheck()/synchronize()/verify()`. `EmailProviderConnector` extends it.
- `ProviderResult` DTO: `::verified()`, `::accepted()` (success-but-unverified), `::failed(code,summary,retryClass)`, `isRetryable()`, `toArray()`. Doctrine: connector success is NOT trusted until independently `verify()`'d.
- `InfrastructureConnectorResolver::resolve('email')` reads `config('infrastructure.connectors.email')` → **currently absent → throws**. `isLive(cap)` = `provider() !== 'null'`. `fake(cap, connector)` test seam.
- `InfraProviderConnection::CAPABILITY_EMAIL = 'email'`; `CredentialPurposeRegistry::PURPOSE_EMAIL = 'email_operations'`.

## Schema (additive; new tables; prod migration deferred to release gate)
Domain-centric, tenant-scoped (`BelongsToWorkspace`):
- **`email_domains`** — the unit of Business Email on a connected custom domain. Cols: `website_id`, `workspace_id`, `custom_domain_id` (FK → custom_domains), `domain`(hostname), `provider`, `provider_domain_id`, `state` (state machine below), `dns_mx/dns_spf/dns_dkim/dns_dmarc` (bool), `verification_records`(json), `catch_all_target`(nullable), `last_error`, `metadata`(json), timestamps + `verified_at/activated_at/disconnected_at/last_checked_at`.
- **`email_mailboxes`** — `email_domain_id`, `workspace_id`, `local_part`, `address`(local@domain), `provider`, `provider_mailbox_id`, `state`, `quota_mb`, `used_mb`(nullable), `display_name`, `last_error`, timestamps.
- **`email_aliases`** — `email_domain_id`, `workspace_id`, `mailbox_id`(nullable), `type`('alias'|'forward'), `source`, `destination`, `provider_alias_id`, `state`, timestamps.

State machine (customer-safe, both domain + mailbox): `pending_setup → dns_pending → verifying → provisioning → active`, plus `suspended / failed / disconnecting / disconnected`.

## Files to create (next increment)
- `app/Connectors/Infrastructure/Null/NullEmailProviderConnector.php` — implements `EmailProviderConnector`; `provider()='null'`; deterministic simulated `ProviderResult` (honours `config('infrastructure.null_connector.outcome')`); makes `resolve('email')` a safe no-op instead of throwing. Register: `config/infrastructure.php` connectors map → `'email' => env('INFRA_EMAIL_CONNECTOR', NullEmailProviderConnector::class)`.
- `app/Models/EmailDomain.php`, `EmailMailbox.php`, `EmailAlias.php` (tenant-scoped, state consts, casts).
- `database/migrations/2026_07_25_*_create_business_email_tables.php` (guarded `hasTable`).
- `app/Services/BusinessEmailService.php` — orchestrator. **Gate:** `config('business_email.enabled')` (default false) AND the target domain must be an **active** custom domain (`CustomDomain::STATE_ACTIVE`) — enforces the Domains dependency. Methods: `enableForDomain(websiteId, hostname)` (creates email_domain, calls provider `verifyDomain`, returns MX/SPF/DKIM/DMARC instructions), `syncDomain`, `createMailbox`, `setQuota`, `suspend/deleteMailbox`, `createAlias/Forwarder`, `requestPasswordReset`, `disconnectDomain`. Same rigor as `CustomDomainService`: persist provider id immediately, compensating cleanup, never trust success without `verify()`, correlation-id logging, idempotency keys, tenancy via `WorkspaceContext::run`.
- `app/Console/Commands/ReconcileBusinessEmail.php` — cross-tenant sync of in-flight email domains/mailboxes.
- `config/business_email.php` — `enabled` gate, provider key, default quotas.
- Routes: extend `InfrastructureController::emailIndex()` (currently returns `available:false`) + add mailbox CRUD endpoints (additive) OR a dedicated controller.

## Frontend (UI framework, gated)
Rewrite `infrastructure.js loadEmail()` (currently a "coming soon" empty state) to a **domain-first** Business Email surface: list the workspace's connected+verified custom domains; per domain → "Set up business email" (gated) → mailbox list + create form + aliases; show MX/SPF/DKIM/DMARC status separately with "Check DNS"; honest gated notice while `business_email.enabled=false` or no active domain. No provider/Cloudflare/Migadu jargon. Prerequisite chain surfaced: needs an active custom domain first (deep-link to Domain tab).

## Tests (mocked, `levelup_test` DB)
Fake `EmailProviderConnector` via `resolver->fake('email', $fake)`: enable-on-domain (requires active domain — reject otherwise), DNS-status sync, create-mailbox idempotency, quota, alias/forward, suspend/delete, compensating cleanup on persist failure, provider-error→failed-no-orphan, gate closed → no provider call, tenant isolation.

## Release gate (Phase 2, after Domains live)
Only then: implement `MigaduEmailProviderConnector`, obtain scoped Migadu API credentials, flip `business_email.enabled`, run the prod migration (Boss authorization), live-validate mailbox provisioning against a disposable domain we own, then expose Business Email to customers.
