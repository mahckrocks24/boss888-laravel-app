# SENSITIVE OPERATIONS REGISTER

**Phase:** P0-A · **Date:** 2026-07-26 · **Status:** RATIFIED (documentation only — no enforcement changed)
**Evidence:** live route enumeration at commit `416686e` — 978 routes, **64 mutating admin routes**, 16 hardened, 1 MFA.

> **Nothing in this register is enforced yet.** "Required protection" is the target state approved
> in the Governance Hardening Masterplan; "Current protection" is what is actually attached today.
> The gap between those two columns is the P0-B…P0-E work list.

**Classification:** R read · W write · A approve · O override · **D dangerous** · **C critical**
**Guard legend:** `admin` = `AdminMiddleware` · `hardened` = `+ DenyApiKeyAuth` · `MFA` = `+ RequireMfaStepUp` · `user` = JWT only · `OPEN` = unauthenticated

---

## 1. USERS & IDENTITY

| # | Operation | Route / entry point | Class | Owner (target) | Current | Required | Approval | MFA | Audit |
|---|---|---|---|---|---|---|---|---|---|
| U-01 | Create user | `POST /api/admin/users` | W·D | Platform Admin | admin | admin+hardened | **Yes** | No | Yes |
| U-02 | Update user | `PUT /api/admin/users/{id}` | W·D | Platform Admin | admin | admin+hardened | **Yes** | No | Yes |
| U-03 | **Delete user** | `DELETE /api/admin/users/{id}` | **C** | Platform Owner | admin | admin+hardened+**MFA** | **Yes** | **Yes** | Yes |
| U-04 | **Suspend user** | `POST /api/admin/users/{id}/suspend` | **D** | Platform Admin / Support | admin | admin+hardened | **Yes** | No | Yes |
| U-05 | **Suspend user (Bella)** | `bella.suspend_user` | **D** | Governance layer | **none** | **request-only** | **Yes** | **Yes** | Yes |
| U-06 | Revoke session | `POST /api/admin/sessions/{id}/revoke` | W | Support | admin | admin+hardened | No | No | Yes |
| U-07 | **Revoke API key** | `POST /api/admin/api-keys/{id}/revoke` | **D** | Platform Admin | admin | admin+hardened | No | No | Yes |
| U-08 | Update membership | `PUT /api/admin/memberships/{id}` | W·D | Platform Admin | admin | admin+hardened | **Yes** | No | Yes |
| U-09 | MFA enrol / confirm / verify / recovery | 4 × `POST /api/admin/mfa/*` | W | Self | **hardened** ✅ | unchanged | No | n/a | Yes |
| U-10 | Public registration | `POST /api/auth/register` | W | — | OPEN | classify | No | No | Yes |
| U-11 | Password reset | `POST /api/auth/{forgot,reset}-password` | W | — | OPEN+throttle | unchanged | No | No | Yes |
| U-12 | Invite accept | `POST /api/invite/{token}/accept` | W | — | OPEN | classify | No | No | Yes |

## 2. CREDITS & BILLING

| # | Operation | Route / entry point | Class | Owner (target) | Current | Required | Approval | MFA | Audit |
|---|---|---|---|---|---|---|---|---|---|
| B-01 | **Adjust workspace credits** | `POST /api/admin/workspaces/{id}/credits` | **C** | Finance / Platform Admin | admin | admin+hardened+**MFA** | **Yes** | **Yes** | Yes |
| B-02 | **Adjust credits (Bella)** | `bella.adjust_credits` | **C** | Governance layer | **none** | **request-only** | **Yes** | **Yes** | Yes |
| B-03 | **House account top-up** | `POST /api/admin/house-accounts/{id}/top-up` | **C** | Finance | admin | admin+hardened+**MFA** | **Yes** | **Yes** | Yes |
| B-04 | House account settings | `PUT /api/admin/house-accounts/{id}/settings` | W·D | Finance | admin | admin+hardened | **Yes** | No | Yes |
| B-05 | Assign plan to workspace | `POST /api/admin/workspaces/{id}/plan` | W·D | Finance | admin | admin+hardened | **Yes** | No | Yes |
| B-06 | Create plan | `POST /api/admin/plans` | W·D | Finance | admin | admin+hardened | **Yes** | No | Yes |
| B-07 | Update plan | `PUT /api/admin/plans/{id}` | W·D | Finance | admin | admin+hardened | **Yes** | No | Yes |
| B-08 | Customer billing ops | 9 × mutating under `/api/billing*` | W | Customer | user | classify | Per op | No | Yes |

## 3. PLATFORM CONFIGURATION

| # | Operation | Route / entry point | Class | Owner (target) | Current | Required | Approval | MFA | Audit |
|---|---|---|---|---|---|---|---|---|---|
| P-01 | **Update platform config** | `POST /api/admin/config` | **C** | Platform Owner | admin | admin+hardened+**MFA** | **Yes** | **Yes** | Yes |
| P-02 | Update engine capabilities | `PUT /api/admin/engines/capabilities` | W·D | Platform Admin | admin | admin+hardened | **Yes** | No | Yes |
| P-03 | Update agent | `PUT /api/admin/agents/{id}` | W·D | Platform Admin | admin | admin+hardened | No | No | Yes |
| P-04 | **Grant agent capability** | `POST /api/admin/agents/{slug}/capabilities` | **D** | Platform Admin | admin | admin+hardened | **Yes** | No | Yes |
| P-05 | **Revoke agent capability** | `DELETE /api/admin/agents/{slug}/capabilities/{toolId}` | **D** | Platform Admin | admin | admin+hardened | **Yes** | No | Yes |
| P-06 | **Admin token exchange** | `POST /api/admin/auth` | **C** | Platform Owner | **OPEN** | OPEN+**dedicated throttle** | No | No | Yes |

## 4. INFRASTRUCTURE · HOSTING · DOMAINS · DNS · SSL

**This domain is the platform's reference implementation — 13 hardened + 1 hardened+MFA.**

| # | Operation | Route / entry point | Class | Owner (target) | Current | Required | Approval | MFA | Audit |
|---|---|---|---|---|---|---|---|---|---|
| I-01 | Create provider | `POST /api/admin/infrastructure/providers` | W·D | Engineering | **hardened** ✅ | +MFA | Yes ✅ | Yes | Yes ✅ |
| I-02 | **Approve provider request** | `POST .../approvals/{aid}/approve` | **A·C** | Platform Admin | **hardened+MFA** ✅ | unchanged | ✅ | ✅ | ✅ |
| I-03 | Store credential | `POST .../providers/{id}/credentials` | **C** | Engineering | hardened ✅ | unchanged | Yes ✅ | Yes | Yes ✅ |
| I-04 | Verify credential | `POST .../credentials/{cid}/verify` | W | Engineering | hardened ✅ | unchanged | No | No | Yes ✅ |
| I-05 | Request credential activation | `POST .../credentials/{cid}/request-activation` | W | Engineering | hardened ✅ | unchanged | Yes ✅ | No | Yes ✅ |
| I-06 | Request credential rotation | `POST .../credentials/{cid}/request-rotation` | W·D | Engineering | hardened ✅ | unchanged | Yes ✅ | No | Yes ✅ |
| I-07 | **Revoke credential** | `POST .../credentials/{cid}/revoke` | **C** | Engineering | hardened ✅ | unchanged — **must remain reachable in DEGRADED (PO-4)** | No | No | Yes ✅ |
| I-08 | Disable provider | `POST .../providers/{id}/disable` | **D** | Platform Admin | hardened ✅ | unchanged — **PO-4** | No | No | Yes ✅ |
| I-09 | Disable capability | `POST .../providers/{id}/disable-capability` | **D** | Platform Admin | hardened ✅ | unchanged | No | No | Yes ✅ |
| I-10 | Declare capability | `POST .../providers/{id}/capabilities` | W | Engineering | hardened ✅ | unchanged | Yes ✅ | No | Yes ✅ |
| I-11 | Move to testing | `POST .../providers/{id}/testing` | W | Engineering | hardened ✅ | unchanged | No | No | Yes ✅ |
| I-12 | Probe provider | `POST .../providers/{id}/probe` | R·W | Engineering | hardened ✅ | unchanged | No | No | Yes ✅ |
| I-13 | Request provider activation | `POST .../providers/{id}/request-activation` | W | Engineering | hardened ✅ | unchanged | Yes ✅ | No | Yes ✅ |
| I-14 | Request capability enable | `POST .../providers/{id}/request-capability` | W | Engineering | hardened ✅ | unchanged | Yes ✅ | No | Yes ✅ |
| I-15 | Create hosting | `POST /api/infrastructure/hosting` | W·D | Customer / Engineering | hardened | +approval | **Yes** | No | Yes |
| I-16 | Connect custom domain | `POST /api/builder/websites/{id}/custom-domain` | W·D | Customer | **user** | user+classify | **Yes** | No | Yes |
| I-17 | Remove custom domain | `DELETE /api/builder/websites/{id}/custom-domain` | W·D | Customer | **user** | user+classify | **Yes** | No | Yes |
| I-18 | Set subdomain | `POST /api/builder/websites/{id}/set-subdomain` | W | Customer | **user** | unchanged | No | No | Yes |
| I-19 | **SSL issuance** | *(no direct route — Cloudflare-for-SaaS, gated OFF)* | **C** | Engineering | n/a | classify before enabling | **Yes** | No | Yes |

> **Note:** `CLOUDFLARE_SAAS_ENABLED=false` — the custom-domain lifecycle is built, mock-tested,
> and deliberately gated off. DNS/SSL mutations are therefore **not live**.

## 5. DEPLOYMENT & RUNTIME

| # | Operation | Route / entry point | Class | Owner (target) | Current | Required | Approval | MFA | Audit |
|---|---|---|---|---|---|---|---|---|---|
| D-01 | **Runtime deployment (Railway)** | **Out-of-band — no Laravel route.** Manual, Boss-performed | **C** | Platform Owner | **none in-platform** | out-of-band governed act + `DeploymentStarted/Completed` event | **Yes** | **Yes** | **Yes** |
| D-02 | **Runtime model change** | Railway env (`DEEPSEEK_DEFAULT_MODEL`) | **C** | Platform Owner | Railway env only | governed + event | **Yes** | **Yes** | Yes |
| D-03 | **Force task transition** | `POST /api/admin/orchestration/transition` | **D** | Platform Admin | admin | admin+hardened | **Yes** | No | Yes |
| D-04 | Recover orphans | `POST /api/admin/orchestration/recover-orphans` | W | Platform Admin | admin | admin+hardened | No | No | Yes |
| D-05 | Recover stale | `POST /api/admin/recover-stale` | W | Platform Admin | admin | admin+hardened | No | No | Yes |
| D-06 | Retry task | `POST /api/admin/tasks/{id}/retry` | W | Platform Admin | admin | admin+hardened | Per GD-003 tier | No | Yes |
| D-07 | Cancel task | `POST /api/admin/tasks/{id}/cancel` | W | Platform Admin | admin | admin+hardened | No | No | Yes |
| D-08 | **Purge failed jobs** | `POST /api/admin/failed-jobs/purge` | **D** — destroys evidence | Platform Owner | admin | admin+hardened | **Yes** | No | Yes |
| D-09 | Delete failed job | `DELETE /api/admin/failed-jobs/{id}` | W·D | Platform Admin | admin | admin+hardened | No | No | Yes |
| D-10 | Retry failed job | `POST /api/admin/failed-jobs/{id}/retry` | W | Platform Admin | admin | admin+hardened | Per GD-003 tier | No | Yes |
| D-11 | Website publish / unpublish / deploy-prep | 3 × `/api/builder/websites/{id}/*` | W | Customer | user | unchanged (self-service ✅) | Confirm ✅ | No | Yes |
| D-12 | Publish queue approve / reject / bulk | 3 × `/api/publish-queue/*` | A | Customer | user | unchanged | ✅ | No | Yes |

## 6. AI PROVIDERS & MACHINE ACTORS

| # | Operation | Route / entry point | Class | Owner (target) | Current | Required | Approval | MFA | Audit |
|---|---|---|---|---|---|---|---|---|---|
| A-01 | **Bella chat** | `POST /api/admin/bella` | **D** | Governance layer | admin | admin+hardened+**rate-limit**+safe-mode | No (chat) | No | Yes |
| A-02 | Bella vision | `POST /api/admin/bella/vision` | W | Governance layer | admin | admin+hardened | No | No | Yes |
| A-03 | Bella artifacts | `GET /api/admin/bella/artifacts` | R | Governance layer | admin | admin+hardened | No | No | Yes |
| A-04 | Bella video status | `GET /api/admin/bella/video-status/{id}` | R | Governance layer | admin | admin+hardened | No | No | Yes |
| A-05 | **`bella.query_database`** | `bella.query_database` | **C** | — | **none** | **REMOVED per GD-002** | n/a | n/a | n/a |
| A-06 | Provider fallback policy | runtime `index.js` `/ai/run` | **D** | Platform Owner | none | governed + `ProviderFallbackEngaged` event | **Yes** | No | Yes |
| A-07 | Agent direct message | `/api/agent*` (22 mutating) | W | Customer | user | classify | No | No | Yes |

## 7. EMAIL & MASS COMMUNICATION

| # | Operation | Route / entry point | Class | Owner (target) | Current | Required | Approval | MFA | Audit |
|---|---|---|---|---|---|---|---|---|---|
| E-01 | **Broadcast notification** | `POST /api/admin/notifications/broadcast` | **D** — mass comms | Platform Admin | admin | admin+hardened | **Yes** | No | Yes |
| E-02 | Create/update/delete email template | 4 × `/api/admin/email-templates*` | W | Marketing | admin | admin+hardened | No | No | Yes |
| E-03 | Email tracking pixels | 4 × public | R | — | OPEN by design | rate-limit review | No | No | No |
| E-04 | Unsubscribe / resubscribe | 2 × public token | W | — | OPEN by design | unchanged | No | No | Yes |

## 8. DATA & CONTENT

| # | Operation | Route / entry point | Class | Owner (target) | Current | Required | Approval | MFA | Audit |
|---|---|---|---|---|---|---|---|---|---|
| X-01 | **Direct database read** | *(Bella `query_database`)* | **C** | — | **none** | **REMOVED per GD-002** | n/a | n/a | n/a |
| X-02 | Media bulk delete | `POST /api/admin/media/bulk-delete` | **D** | Platform Admin | admin | admin+hardened | **Yes** | No | Yes |
| X-03 | Media delete / upload / generate / tags | 5 × `/api/admin/media*` | W | Marketing | admin | admin+hardened | No | No | Yes |
| X-04 | Purge expired knowledge | `POST /api/admin/knowledge/{wsId}/purge-expired` | W·D | Platform Admin | admin | admin+hardened | No | No | Yes |
| X-05 | Template upload / clone / toggle | 3 × `/api/admin/templates*` | W | Marketing | admin | admin+hardened | No | No | Yes |
| X-06 | Strategy outcome insert | `POST /api/admin/strategy/{wsId}/outcomes` | W | Platform Admin | admin | admin+hardened | No | No | Yes |

## 9. NOT IMPLEMENTED — registered for completeness

The Masterplan brief named these; **evidence shows they do not exist.** Registered so they are
governed **before** they are built, not after.

| # | Operation | Evidence of absence | Class if built | Required at build time |
|---|---|---|---|---|
| N-01 | **Delete workspace** | No `DELETE` route matching `workspace`; no `deleteWorkspace`/`destroyWorkspace` method anywhere in `app/` | **C** | Owner-only · approval · MFA · audit · rollback window |
| N-02 | **Transfer workspace ownership** | No route or method | **C** | Owner-only · approval · MFA · audit |
| N-03 | **Direct DNS record mutation** | Only custom-domain connect/remove exist; Cloudflare-for-SaaS gated OFF | **C** | Engineering · approval · audit |
| N-04 | **SSL certificate operations** | Part of the gated Cloudflare-for-SaaS lifecycle | **C** | Engineering · approval · audit |
| N-05 | **In-platform deployment trigger** | Runtime deploys are manual/out-of-band; no CLI or token in the environment | **C** | Owner/Engineering · approval · MFA · event |

---

## SUMMARY

| Metric | Value |
|---|---|
| Sensitive operations registered | **77** (72 implemented + 5 not-implemented) |
| Classified **CRITICAL** | 16 |
| Classified **DANGEROUS** | 21 |
| Currently hardened (`DenyApiKeyAuth`) | **16** |
| Currently MFA-protected | **1** |
| Target: approval required | **34** |
| Target: MFA required | **11** |
| Target: audit required | **72 of 72** implemented operations |
| **Gap to close in P0-B…P0-E** | **48 mutating admin routes** need `DenyApiKeyAuth`; **10** need MFA; **34** need approval wiring |

**Reference implementation:** the INFRA888 provider control plane (I-01…I-14) already satisfies
the target model — hardened, approval-backed, separation-of-duties enforced, one route MFA-gated.
**It is the pattern the rest of the platform should converge on.**

---

**Registered 2026-07-26 · P0-A · documentation only · no protection was added, removed, or altered**
