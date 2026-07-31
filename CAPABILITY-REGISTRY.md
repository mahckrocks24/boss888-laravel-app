# CAPABILITY REGISTRY

**Phase:** P0-A · **Date:** 2026-07-26 · **Status:** RATIFIED (documentation only — nothing enforced)

> The authoritative catalogue of privileged capabilities. Naming follows the platform's **native
> `engine.action` idiom** (GD-007 / AZ-2), already used by `ApprovalPolicyRegistry` (18 live keys).
>
> **Fail-closed rule (AP-1):** any capability not listed here is **DENIED**. Adding a capability
> to the platform requires adding it here first.

**Risk levels:** `READ` · `LOW` · `MEDIUM` · `HIGH` · `CRITICAL`
**State:** `LIVE` (exists + governed) · `EXISTS-UNGOVERNED` (exists, no governance) · `PLANNED` (not built) · `REMOVED` (deliberately deprecated)

---

## SECTION A — CAPABILITIES ALREADY IN `ApprovalPolicyRegistry`

These 18 keys exist in code today with an approval classification. **They are the proof the model
works** and are carried forward unchanged unless noted.

| Capability key | State | Current classification | Future owner | Approval | Audit | Risk |
|---|---|---|---|---|---|---|
| `infrastructure.provision_hosting` | **LIVE** | separation_of_duties_required | Engineering | ✅ Yes | ✅ | HIGH |
| `infrastructure.activate_provider` | **LIVE** | separation_of_duties_required | Engineering | ✅ Yes | ✅ | HIGH |
| `infrastructure.activate_provider_credential` | **LIVE** | separation_of_duties_required | Engineering | ✅ Yes | ✅ | **CRITICAL** |
| `infrastructure.rotate_provider_credential` | **LIVE** | separation_of_duties_required | Engineering | ✅ Yes | ✅ | **CRITICAL** |
| `infrastructure.enable_provider_capability` | **LIVE** | separation_of_duties_required | Engineering | ✅ Yes | ✅ | HIGH |
| `infrastructure.publish_plan` | **LIVE** | classified | Finance | ✅ Yes | ✅ | HIGH |
| `infrastructure.withdraw_plan` | **LIVE** | classified | Finance | ✅ Yes | ✅ | HIGH |
| `infrastructure.publish_product` | **LIVE** | classified | Finance | ✅ Yes | ✅ | MEDIUM |
| `infrastructure.retire_product` | **LIVE** | classified | Finance | ✅ Yes | ✅ | HIGH |
| `infrastructure.deprecate_product` | **LIVE** | classified | Finance | ✅ Yes | ✅ | MEDIUM |
| `infrastructure.plan_subscriber_migration` | **LIVE** | classified | Finance | ✅ Yes | ✅ | **CRITICAL** |
| `builder.publish_website` | **LIVE** | self_service_confirmation ⚠ | Customer | Confirm | ✅ | MEDIUM |
| `builder.publish_builder_page` | **LIVE** | self_service_confirmation ⚠ | Customer | Confirm | ✅ | LOW |
| `write.publish_article` | **LIVE** | self_service_confirmation ⚠ | Customer | Confirm | ✅ | MEDIUM |
| `content.content_publish_pack` | **LIVE** | self_service_confirmation ⚠ | Customer | Confirm | ✅ | MEDIUM |
| `marketing.send_campaign` | **LIVE** | self_service_confirmation ⚠ | Marketing | Confirm | ✅ | HIGH |
| `social.social_publish_post` | **LIVE** | self_service_confirmation ⚠ | Customer | Confirm | ✅ | MEDIUM |
| `seo.autonomous_goal` | **LIVE** | classified | Customer | ✅ Yes | ✅ | MEDIUM |

> ⚠ The six `self_service_confirmation` entries are **explicitly flagged for product review** in
> the source itself — they preserve today's behaviour because all workspaces are single-owner, and
> a blanket no-self-approval rule would make publishing impossible. Per **AP-5** this is a
> documented decision, not an accident. **Retained in P0-A. Review deferred to a product decision.**

---

## SECTION B — NEW GOVERNED CAPABILITIES (identity & access)

| Capability key | State | Current protection | Future owner | Approval | MFA | Audit | Risk |
|---|---|---|---|---|---|---|---|
| `admin.user_create` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | **Yes** | No | Yes | HIGH |
| `admin.user_update` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | **Yes** | No | Yes | HIGH |
| **`admin.user_delete`** | EXISTS-UNGOVERNED | `AdminMiddleware` | **Platform Owner** | **Yes** | **Yes** | Yes | **CRITICAL** |
| **`admin.user_suspend`** | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin / Support | **Yes** | No | Yes | HIGH |
| `admin.session_revoke` | EXISTS-UNGOVERNED | `AdminMiddleware` | Support | No | No | Yes | MEDIUM |
| `admin.api_key_revoke` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | No | No | Yes | HIGH |
| `admin.membership_update` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | **Yes** | No | Yes | HIGH |
| `admin.token_exchange` | EXISTS-UNGOVERNED | **OPEN** | Platform Owner | No | No | Yes | **CRITICAL** |
| `admin.role_grant` | **PLANNED** (P0-D) | — | Platform Owner | **Yes** | **Yes** | Yes | **CRITICAL** |
| `admin.role_revoke` | **PLANNED** (P0-D) | — | Platform Owner | **Yes** | No | Yes | HIGH |
| `admin.temporary_elevation` | **PLANNED** (P0-D) | — | Platform Owner | **Yes** | **Yes** | Yes | **CRITICAL** |
| `admin.break_glass` | **PLANNED** (P0-D) | — | **Platform Owner only** | No (is the escape hatch) | **Yes** | Yes | **CRITICAL** |

## SECTION C — FINANCIAL CAPABILITIES

| Capability key | State | Current protection | Future owner | Approval | MFA | Audit | Risk |
|---|---|---|---|---|---|---|---|
| **`billing.adjust_credits`** | EXISTS-UNGOVERNED | `AdminMiddleware` | Finance / Platform Admin | **Yes** | **Yes** | Yes | **CRITICAL** |
| **`billing.house_account_topup`** | EXISTS-UNGOVERNED | `AdminMiddleware` | Finance | **Yes** | **Yes** | Yes | **CRITICAL** |
| `billing.house_account_settings` | EXISTS-UNGOVERNED | `AdminMiddleware` | Finance | **Yes** | No | Yes | HIGH |
| `billing.assign_plan` | EXISTS-UNGOVERNED | `AdminMiddleware` | Finance | **Yes** | No | Yes | HIGH |
| `billing.plan_create` | EXISTS-UNGOVERNED | `AdminMiddleware` | Finance | **Yes** | No | Yes | HIGH |
| `billing.plan_update` | EXISTS-UNGOVERNED | `AdminMiddleware` | Finance | **Yes** | No | Yes | HIGH |
| `billing.refund` | **PLANNED** | — | Finance | **Yes** | **Yes** | Yes | **CRITICAL** |

## SECTION D — PLATFORM & CONFIGURATION

| Capability key | State | Current protection | Future owner | Approval | MFA | Audit | Risk |
|---|---|---|---|---|---|---|---|
| **`platform.config_write`** | EXISTS-UNGOVERNED | `AdminMiddleware` | **Platform Owner** | **Yes** | **Yes** | Yes | **CRITICAL** |
| `platform.engine_capability_update` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | **Yes** | No | Yes | HIGH |
| `platform.agent_update` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | No | No | Yes | MEDIUM |
| `platform.agent_capability_grant` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | **Yes** | No | Yes | HIGH |
| `platform.agent_capability_revoke` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | **Yes** | No | Yes | HIGH |
| `platform.notification_broadcast` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | **Yes** | No | Yes | HIGH |
| `platform.failed_jobs_purge` | EXISTS-UNGOVERNED | `AdminMiddleware` | **Platform Owner** | **Yes** | No | Yes | HIGH |

## SECTION E — DEPLOYMENT & RUNTIME

| Capability key | State | Current protection | Future owner | Approval | MFA | Audit | Risk |
|---|---|---|---|---|---|---|---|
| **`runtime.deploy`** | **EXISTS — OUT OF BAND** | **none in-platform** (manual Railway) | **Platform Owner** | **Yes** | **Yes** | Yes | **CRITICAL** |
| **`runtime.model_change`** | **EXISTS — OUT OF BAND** | Railway env only | **Platform Owner** | **Yes** | **Yes** | Yes | **CRITICAL** |
| `runtime.fallback_policy` | EXISTS-UNGOVERNED | none | Platform Owner | **Yes** | No | Yes | HIGH |
| `runtime.force_task_transition` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | **Yes** | No | Yes | HIGH |
| `runtime.task_retry` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | Per GD-003 | No | Yes | MEDIUM |
| `runtime.task_cancel` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | No | No | Yes | MEDIUM |
| `runtime.recover_orphans` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | No | No | Yes | LOW |
| `runtime.secret_rotate` | **PLANNED** | — | Platform Owner | **Yes** | **Yes** | Yes | **CRITICAL** |

## SECTION F — INFRASTRUCTURE, DOMAINS, DNS, SSL

| Capability key | State | Current protection | Future owner | Approval | MFA | Audit | Risk |
|---|---|---|---|---|---|---|---|
| `infrastructure.provider_create` | **LIVE** | hardened ✅ | Engineering | Yes ✅ | Target | Yes ✅ | HIGH |
| `infrastructure.credential_store` | **LIVE** | hardened ✅ | Engineering | Yes ✅ | No | Yes ✅ | **CRITICAL** |
| `infrastructure.credential_revoke` | **LIVE** | hardened ✅ | Engineering | No — **PO-4: must stay reachable in DEGRADED** | No | Yes ✅ | **CRITICAL** |
| `infrastructure.provider_disable` | **LIVE** | hardened ✅ | Platform Admin | No — **PO-4** | No | Yes ✅ | HIGH |
| `infrastructure.hosting_create` | **LIVE** | hardened | Customer / Engineering | **Yes** | No | Yes | HIGH |
| `domain.connect` | EXISTS-UNGOVERNED | JWT only | Customer | **Yes** | No | Yes | HIGH |
| `domain.remove` | EXISTS-UNGOVERNED | JWT only | Customer | **Yes** | No | Yes | HIGH |
| `domain.subdomain_set` | EXISTS-UNGOVERNED | JWT only | Customer | No | No | Yes | MEDIUM |
| **`dns.record_change`** | **PLANNED** — gated OFF | `CLOUDFLARE_SAAS_ENABLED=false` | Engineering | **Yes** | No | Yes | **CRITICAL** |
| **`ssl.certificate_issue`** | **PLANNED** — gated OFF | gated | Engineering | **Yes** | No | Yes | **CRITICAL** |
| **`ssl.certificate_revoke`** | **PLANNED** — gated OFF | gated | Engineering | **Yes** | No | Yes | **CRITICAL** |

## SECTION G — DATA

| Capability key | State | Current protection | Future owner | Approval | MFA | Audit | Risk |
|---|---|---|---|---|---|---|---|
| **`data.direct_query`** | **REMOVED — GD-002** | *(inert via Laravel 11 TypeError)* | **none — deprecated** | n/a | n/a | n/a | **CRITICAL** |
| `data.media_bulk_delete` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | **Yes** | No | Yes | HIGH |
| `data.knowledge_purge` | EXISTS-UNGOVERNED | `AdminMiddleware` | Platform Admin | No | No | Yes | MEDIUM |
| `data.audit_log_read` | EXISTS-UNGOVERNED | `AdminMiddleware` | All admin roles | No | No | Yes | READ |
| `data.governed_read` | **PLANNED** | — | Platform Admin / Engineering | **Yes** | **Yes** | Yes | HIGH |

> **`data.governed_read`** is the sanctioned replacement path referenced by GD-002: dedicated
> services and approved runtime tools with explicit table/column allowlists and tenant scoping.
> **It is not free-form SQL and must never become free-form SQL.**

## SECTION H — WORKSPACE LIFECYCLE

| Capability key | State | Evidence | Future owner | Approval | MFA | Audit | Risk |
|---|---|---|---|---|---|---|---|
| `workspace.update` | EXISTS-UNGOVERNED | `PUT /api/admin/workspaces/{id}` | Platform Admin | No | No | Yes | MEDIUM |
| **`workspace.delete`** | **PLANNED — DOES NOT EXIST** | No DELETE route; no `deleteWorkspace` method anywhere in `app/` | **Platform Owner** | **Yes** | **Yes** | Yes | **CRITICAL** |
| **`workspace.transfer_ownership`** | **PLANNED — DOES NOT EXIST** | No route or method | **Platform Owner** | **Yes** | **Yes** | Yes | **CRITICAL** |

## SECTION I — MACHINE / AI CAPABILITIES

Governed by **GD-001** and the **MA-1…MA-6** invariants. Every entry is **request-only**.

| Capability key | State | Execution model | Approval | MFA | Audit | Risk |
|---|---|---|---|---|---|---|
| `bella.read_analytics` | EXISTS-UNGOVERNED | direct execute | No | No | Yes | READ |
| `bella.read_queue` | EXISTS-UNGOVERNED | direct execute | No | No | Yes | READ |
| `bella.read_engine_status` | EXISTS-UNGOVERNED | direct execute | No | No | Yes | READ |
| `bella.generate_report` | EXISTS-UNGOVERNED | direct execute | No | No | Yes | READ |
| `bella.read_audit_logs` | EXISTS-UNGOVERNED | direct execute + rate limit | No | No | Yes | LOW |
| `bella.memory_write` | EXISTS-UNGOVERNED | direct execute, own memory only | No | No | Yes | LOW |
| `bella.list_users` | EXISTS-UNGOVERNED | direct + **field allowlist** (never `password`, `mfa_*`) | No | No | Yes | MEDIUM |
| `bella.get_workspace` | EXISTS-UNGOVERNED | direct + tenancy logged | No | No | Yes | MEDIUM |
| **`bella.adjust_credits`** | EXISTS-UNGOVERNED | **REQUEST ONLY** → human approval | **Yes** | **Yes** | Yes | **CRITICAL** |
| **`bella.suspend_user`** | EXISTS-UNGOVERNED | **REQUEST ONLY** → human approval | **Yes** | **Yes** | Yes | **CRITICAL** |
| **`bella.query_database`** | **REMOVED — GD-002** | — | n/a | n/a | n/a | **CRITICAL** |
| `engineering.*` | **PLANNED** | **REQUEST ONLY** — all Engineering888 capabilities | **Yes** | Per risk | Yes | HIGH–CRITICAL |

---

## REGISTRY SUMMARY

| Metric | Count |
|---|---|
| Total capabilities registered | **83** |
| **LIVE** (exist + governed) | 23 |
| **EXISTS-UNGOVERNED** | 42 |
| **PLANNED** (not yet built) | 17 |
| **REMOVED** (deprecated) | **1** (`data.direct_query` / `bella.query_database`) |
| Risk = **CRITICAL** | 24 |
| Risk = HIGH | 31 |
| Require approval (target) | 55 |
| Require MFA (target) | 18 |
| **Require audit (target)** | **82 of 82 active** |

**Governance owners:** Platform Owner 14 · Platform Admin 24 · Engineering 15 · Finance 10 ·
Support 3 · Marketing 4 · Customer 11 · Governance layer (machine) 12

---

**Registered 2026-07-26 · P0-A · documentation only · no capability was added, removed, or enforced in code**
