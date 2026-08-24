# INFRA888 — HOSTING STATUS REPORT

**Date:** 2026-08-01 · **Scope:** Infrastructure only · **Method:** read-only inspection of live code, routes and production tables
**No code was written. No configuration changed. No provider contacted.**

Row counts are **post-restore** (production was rolled back to a 01:00 backup on 2026-07-30), so volumes are low. Capability findings come from code, not data.

---

## 1. DOMAINS — the most mature subsystem

| Capability | Maturity | Evidence |
|---|---|---|
| **Search** | **REAL** | `searchDomain()`; customer routes `GET /api/domains/search`, `/search-transfer` |
| **Purchase** | **REAL (proven once, sandbox)** | `DomainCommerceService::createOrder` → Stripe checkout → `markPaidAndProvision`; `POST /api/domains/orders`, `/orders/{id}/checkout` |
| **Registration** | **REAL adapter, NEVER executed in production** | `registerDomain()` with idempotency key; 5-layer idempotency; **currently gated off** (`domains.fulfilment.enabled=false`) |
| **Renewals** | **ADAPTER ONLY** | `renewDomain()`, `quoteRenewal()` exist. `infra_renewals` = **0 rows**. No scheduler, no renewal job, no customer surface |
| **Transfers** | **ADAPTER ONLY** | `transferDomain()`, `getTransferStatus()`, `quoteTransfer()`, `GET /api/domains/search-transfer`. No completion workflow |
| **DNS** | **ADAPTER ONLY** | `getDnsHosts()` / `setDnsHosts()` on the registrar. **No `DnsProviderConnector` implementation**, no customer DNS editor |
| **Nameservers** | **REAL** | `updateNameservers()` with idempotency |
| **WHOIS privacy** | **ABSENT** | no method, no field, no route |
| **Auto-renew** | **REAL** | `setAutoRenew()` with mandatory read-back returning `accepted` (not `verified`) when unconfirmed; `POST /api/domains/{id}/auto-renew` |
| **Registrar lock** | **REAL** | `getRegistrarLock()` / `setRegistrarLock()` |
| **Customer portal** | **PARTIAL** | 9 customer routes (list, detail, timeline, search, orders, checkout, auto-renew, sync). `domains-commerce.js` 41 KB |
| **Admin portal** | **REAL** | 29 admin routes: orders, items retry, sync, sync-all, attention, events, summary |

**Registrar adapter (Namecheap) is genuinely complete** — 25 public methods, sandbox-validated 22/22. It is the strongest asset INFRA888 has.

**The gap is lifecycle, not capability:** renewals and transfers have adapters but no orchestration, no schedule, no customer journey.

---

## 2. HOSTING — the honest answer is *we cannot host anything through INFRA888 today*

The configured hosting connector is `NullHostingConnector`. Every question below resolves against a deterministic no-op.

| Question | Answer | Evidence |
|---|---|---|
| Provision hosting? | **NO** | `provisionHosting()` exists **only** on the Null connector |
| Suspend? | **NO** | Null `suspendHosting()` |
| Terminate? | **NO** | Null `terminateHosting()` |
| Resize? | **NO** | **no method exists on any connector or contract** |
| Rebuild? | **NO** | no method exists |
| Backup? | **NO** | `BackupProviderConnector` contract only — **zero implementations** |
| Restore? | **NO** | same |
| Deploy? | **NO** | no deployment capability in the Infrastructure engine |
| Issue SSL? | **NO** | `CertificateProviderConnector` contract only — **zero implementations** |
| Renew SSL? | **NO** | same |
| Connect Builder websites? | **PARTIAL** | `infra_hosted_sites` = 1 row; `AssetGraphService` links assets |
| Connect purchased domains? | **PARTIAL / GATED** | `POST /api/builder/websites/{id}/custom-domain` exists; **`custom_domains` = 0 rows**; configured connector is `NullCustomHostnameConnector` |
| **Host customer websites today?** | **YES — but not through INFRA888** | 3 published sites live in `websites`, served by the **Builder**. INFRA888 records almost none of it: `infra_hosting_accounts` = 2, `infra_hosted_sites` = 1 |

**This is the central commercial truth:** LevelUp already hosts customer sites through the Builder. INFRA888 is not the thing doing it, and does not accurately reflect it. `HostingService` is **read-only** (`list`, `find`, `detail`). `ProvisioningService` has a real governed flow (`requestHostingProvision` → approval → `execute`) that terminates in a Null connector.

---

## 3. EMAIL — vapor

| Question | Answer |
|---|---|
| Create mailboxes? | **NO** |
| Delete? | **NO** |
| Reset passwords? | **NO** |
| Forwarders? | **NO** |
| Aliases? | **NO** |
| Catch-all? | **NO** |
| Provider adapters? | **NONE** |

The only artefact is the contract `app/Connectors/Infrastructure/Contracts/EmailProviderConnector.php`. **No implementation exists** — not Migadu, not anything. `GET /api/infrastructure/email` is a route with no provider behind it. Mailbox references elsewhere are entitlement/plan *fields*, not functionality.

---

## 4. INFRASTRUCTURE PORTAL

**Customer-ready (read-only):** overview, activity, domains list/detail/timeline, hosting list/detail, operations + timeline, intelligence dashboard, assets, blast-radius, incidents, reliability.

**Customer-ready (write):** domain search, order creation, checkout, auto-renew toggle, domain sync, custom-domain attach/verify/delete.

**Admin-ready:** provider registry, credentials, approvals, certification; catalog (products, plans, entitlements, subscriptions); domains admin (orders, retry, sync-all, attention, events, summary); `GET /api/admin/hosting`.

| Screen class | Reality |
|---|---|
| **Real providers** | Domains (Namecheap), Monitoring (`infra_monitor_results` = 3,335 rows, `infra_monitor_daily` = 13, `infra_incidents` = 5 — genuinely running) |
| **Null connectors** | Hosting (all lifecycle), Custom hostname |
| **Placeholder** | Email tab — no provider at all |
| **Empty-but-real** | Catalog: `infra_products` 0, `infra_plans` 0, `infra_subscriptions` 0, `infra_plan_entitlements` 0, `infra_entitlement_definitions` 0. The screens work; there is nothing to show |
| **Fake** | None found — no screen fabricates data. Null connectors return honest deterministic no-ops |

`infrastructure.js` is 151 KB; `domains-commerce.js` 41 KB.

---

## 5. PROVIDER LAYER

| Provider | Capability | Status |
|---|---|---|
| **Namecheap** | registrar | **REAL** — 25 methods, sandbox-validated, default connector |
| **Self-hosted monitoring** | monitoring | **REAL** — 3,335 monitor results in production |
| **Cloudflare for SaaS** | custom hostnames | **PARTIAL** — `CloudflareSaasProvider` has 8 real methods, but the *configured* `custom_hostname` connector is **Null**, `config/services.php` is **absent**, and `custom_domains` = 0 |
| **Null hosting** | hosting | **NULL** — deterministic no-op |
| **Null custom hostname** | custom_hostname | **NULL** — currently configured |
| Backup | backup | **PLANNED** — contract only |
| Certificate / SSL | certificate | **PLANNED** — contract only |
| DNS | dns | **PLANNED** — contract only |
| Email | email | **PLANNED** — contract only |

`infra_providers` = 1, `infra_provider_credentials` = **0**, `infra_provider_connections` = **0**.

---

## 6. TOP 20 INFRASTRUCTURE BLOCKERS TO PUBLIC LAUNCH

**Hosting cannot be sold**

1. No real hosting provider adapter — provisioning terminates in `NullHostingConnector`.
2. No resize capability anywhere in the contract set.
3. No rebuild capability.
4. No backup provider — contract only. Selling hosting without backups is not viable.
5. No restore capability.
6. No deployment mechanism.
7. No SSL issuance — `CertificateProviderConnector` unimplemented.
8. No SSL renewal — an expiring cert would take a customer site down with no recovery path.
9. `HostingService` is read-only; there is no write lifecycle behind the portal.
10. INFRA888 does not record the hosting that already exists — 3 live Builder sites, 1 `infra_hosted_sites` row.

**Domains cannot complete a real sale**

11. No production domain purchase has ever completed end-to-end; fulfilment is gated off.
12. No renewal orchestration — `infra_renewals` empty, no scheduler. Domains would silently expire.
13. No transfer completion workflow.
14. No WHOIS privacy — a table-stakes registrar feature.
15. No customer DNS management surface.

**Commercial layer is empty**

16. `infra_products`, `infra_plans`, `infra_subscriptions`, entitlements all **0 rows** — nothing is priced, so hosting cannot be billed.
17. No provider credentials stored (`infra_provider_credentials` = 0) — every real provider would need onboarding first.

**Custom domains blocked**

18. Cloudflare for SaaS is built but **gated off**; `config/services.php` absent; needs a scoped token and zone enablement.
19. `custom_domains` = 0 — the path has never run in production.

**Operational**

20. Email is entirely absent while the portal advertises an Email tab — the single largest gap between what the UI implies and what exists.

---

## 7. NEXT MILESTONE — by business value

**Recommendation: make INFRA888 tell the truth about hosting that already exists, before building provisioning.**

**S1 — Hosting Reconciliation (highest value, lowest risk).** Backfill `infra_hosted_sites` / `infra_hosting_accounts` from the live `websites` table so the Hosting portal reflects reality. Turns a Null-connector preview into a working management view with **no new provider**. This is the "Builder already IS hosting" conclusion from the 2026-07-23 audit, still unactioned.

**S2 — Custom Domains go live.** Cloudflare for SaaS is already built and mock-tested. Needs a scoped CF token, zone enablement and the `custom_hostname` connector switched off Null. Directly monetisable: customers can point their own domain at a Builder site.

**S3 — Domain renewals.** The registrar adapter already renews. Without orchestration, every domain sold will eventually lapse — this is a liability, not a feature.

**S4 — Real hosting provider.** Only after S1–S3. It is the largest build (provision, suspend, terminate, resize, backup, restore, SSL) and should not start while the portal misrepresents current hosting.

**Deprioritise:** Email. It needs a provider, mailbox CRUD, DNS records and billing — the biggest build with the least near-term revenue. Recommend hiding the Email tab until a provider exists.

---

## Parallel-session note

Other sessions are active in this repository (`app/Core/Engineer888/**`, `tests/TestCase.php`, `bootstrap/app.php` — the latter two declared in their `config/governed_files.php`). This report **touched nothing**: read-only inspection only, no files changed, no ownership contested.

One pending migration belongs to another session (`add_execution_provenance_to_api_usage_logs`) and was left alone.
