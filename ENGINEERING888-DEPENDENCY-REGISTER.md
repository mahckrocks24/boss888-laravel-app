# ENGINEERING888 DEPENDENCY REGISTER

**Phase:** P0-A · **Date:** 2026-07-26 · **Status:** RATIFIED
**Gate:** Engineering888 implementation is **PAUSED**. It may not begin until every MANDATORY
dependency is complete **and** M9 certification passes.

> **Engineering888 does not exist in any codebase.** Verified by whole-droplet search: the only
> matches for `engineer888`/`engineering888` across `/var/www` and `/root` are the 2026-07-26
> governance documents that name it as future work. No table, route, command, queue, controller,
> or runtime artefact exists. This register defines what must be true **before** the first line
> is written.

---

## WHY THIS REGISTER EXISTS

Engineering888 is by definition a **privileged autonomous machine principal**. It will hold
engineering authority, act on infrastructure, and consume the runtime that failed silently for
42 hours in INC-2026-001.

On today's platform there is exactly one authority level — `is_platform_admin` — so granting
Engineering888 any authority means granting it **everything**, with no role to scope it, no event
stream to observe it, no approval path to gate it, and no active MFA to protect it.

**Bella is the proof of consequence.** A capable, complete, 1,748-line autonomous admin agent was
built to finish and could not be safely switched on. It has **never executed**. Building
Engineering888 on the same foundation reproduces that outcome at greater scale and cost.

---

## MANDATORY — Engineering888 may NOT begin without these

| ID | Dependency | Phase | Why blocking | Evidence of absence | Done when |
|---|---|---|---|---|---|
| **M1** | **Role / permission model** | P0-D | Engineering888 needs an *Engineering* authority distinct from platform admin. Today only `is_platform_admin` exists — granting it grants everything | 0 `Gate::define`; no `app/Policies/`; no role or permission tables; `is_admin`/`is_platform_admin` perfectly correlated across 11 users | `roles` + `user_roles` exist; `PermissionRegistry` enforcing; an Engineering role can be granted that **cannot** touch credits or delete users |
| **M2** | **Machine principal class + governed execution path** | P0-E | Engineering888 mutates infrastructure. Without the §4 pipeline it repeats Bella's defect — executing model-generated parameters | Bella executes LLM-supplied params with no validation, approval, or confirmation | A machine actor can **request** but provably **cannot execute** a Tier-3 action |
| **M3** | **Approval extension to machine actors** | P0-E | Self-approval must be structurally impossible, not merely discouraged | `approvals.requester_actor_type` holds only `NULL`, `'system'`, `'user'` — no machine class | `machine` actor type exists; `self_approval_allowed=false` enforced unconditionally (MA-3) |
| **M4** | **Event backbone** | P0-C | Engineering telemetry, audit, and alerting all depend on it. Without events every listener is a per-call-site invocation — the pattern that already produced inconsistent audit coverage | `app/Events`, `app/Listeners`, `app/Observers` all empty | `GovernanceEvent` base + `AuditLogListener` live; governance + financial event families emitting |
| **M5** | **Provider-error alerting** | P0-C | Engineering888 acts on the runtime that failed silently for 42 h. It must not be the thing that notices too late | `failed_jobs`=0 throughout INC-2026-001 while 904 `task.execution_failed` audit rows accumulated | Alerting watches `tasks.error_text`/`task_events`, **not** `failed_jobs`; threshold + fallback alerts fire |
| **M6** | **MFA enrolment for both platform admins** | P0-B | The fail-closed governance machine is built and correct but stands down in BOOTSTRAP | `mfa_enabled=1` on **0 of 11 users** | Both admins enrolled; governance ACTIVATED; step-up enforcing |
| **M7** | **`DenyApiKeyAuth` on privileged admin routes** | P0-B | Otherwise a shared static token drives Engineering888-adjacent operations with no individual attribution | 48 of 64 mutating admin routes lack it | All Sensitive-Operations-Register entries marked "hardened" carry it |
| **M8** | **Bella disposition executed** | P0-E | Two ungoverned autonomous agents is strictly worse than one. The governance precedent must be set before a second machine principal exists | Bella dormant but reachable; ungoverned action dispatcher | GD-001 implemented: Bella request-only; GD-002 executed: `query_database` removed |
| **M9** | **Governance certification passed** | P0-F | The final gate. Everything above must be *proven*, not merely implemented | No governance tests exist; 0 tests target admin or Bella | All M9 test suites green — see `GOVERNANCE-VERIFICATION-PLAN-M9.md` |

**M1…M9 are non-negotiable.** Any proposal to begin Engineering888 with an outstanding MANDATORY
dependency requires a superseding entry in the Governance Decision Register from the Platform Owner.

---

## RECOMMENDED — materially safer; not strictly blocking

| ID | Dependency | Value | Phase |
|---|---|---|---|
| **R1** | Sensitive Operations Register ratified | Engineering888 inherits a classified surface (77 operations) instead of re-deriving it | ✅ **Done — P0-A** |
| **R2** | Capability Registry ratified | Engineering888 capabilities (`engineering.*`) slot into an existing vocabulary | ✅ **Done — P0-A** |
| **R3** | Approval dry-run payloads | Engineering actions are high-consequence; approving an intention rather than a computed effect is weak governance | P0-E |
| **R4** | Temporary elevation | Lets Engineering888 hold **no** standing authority and request time-boxed elevation instead | P0-D |
| **R5** | Admin + Bella test coverage | Governance without tests regresses silently. Currently **zero** tests target the admin surface | P0-F |
| **R6** | Provider attribution + model-keyed pricing (TD-01/02/03) | Engineering888 cost attribution will otherwise be wrong from birth — fallbacks already mis-record as DeepSeek at V3 rates | P0-C / separate |
| **R7** | Replay policy implemented (GD-003) | Engineering888 would otherwise inherit 213 failed + 63 blocked tasks with no disposition rule | post-P0-A |
| **R8** | Runtime deployment governance | `runtime.deploy` is currently manual, out-of-band, unattributed, with bus-factor one | P0-C (event) / separate |

---

## OPTIONAL — may follow Engineering888

| ID | Item | Note |
|---|---|---|
| O1 | `routes/api.php` decomposition | 19,363 lines; explicitly out of P0 scope |
| O2 | `.bak` archival | 508 files / 81 MB; 347 created in July alone |
| O3 | `traffic_logs` index review | 298,830 rows, 2 indexes |
| O4 | Runtime git repository + lockfile | No VCS for the runtime; NIXPACKS re-resolves `^` ranges |
| O5 | `/health` version bump to 2.37.4 | Cosmetic; deploy proof is behavioural |
| O6 | `config/llm.php` correction | Names retired `deepseek-chat`; harmless but a trap |
| O7 | Bella front-end | `public/admin/` is a 50-byte redirect; asset dir empty |
| O8 | `.env.bak-gsc-creds-20260612` removal + rotation | Not web-reachable; filesystem hygiene |

---

## DEPENDENCY GRAPH

```
                        ┌─────────────────────────┐
                        │  P0-A  (COMPLETE)       │
                        │  R1 · R2 · registers    │
                        └───────────┬─────────────┘
                                    │
        ┌───────────────────────────┼───────────────────────────┐
        ▼                           ▼                           ▼
 ┌──────────────┐          ┌────────────────┐          ┌────────────────┐
 │ P0-B         │          │ P0-C           │          │ P0-D           │
 │ M6 · M7      │          │ M4 · M5        │          │ M1  (+R4)      │
 │ coverage     │          │ events+alerts  │          │ authorization  │
 └──────┬───────┘          └───────┬────────┘          └───────┬────────┘
        │                          │                           │
        └──────────────────────────┴─────────────┬─────────────┘
                                                 ▼
                                     ┌───────────────────────┐
                                     │ P0-E                  │
                                     │ M2 · M3 · M8  (+R3)   │
                                     │ machine governance    │
                                     └───────────┬───────────┘
                                                 ▼
                                     ┌───────────────────────┐
                                     │ P0-F                  │
                                     │ M9 certification (+R5)│
                                     └───────────┬───────────┘
                                                 ▼
                                     ╔═══════════════════════╗
                                     ║  ENGINEERING888       ║
                                     ║  may begin            ║
                                     ╚═══════════════════════╝
```

---

## WHAT ENGINEERING888 WILL INHERIT

Once M1–M9 are complete, Engineering888 reuses — rather than rebuilds — roughly **70%** of the
operational substrate. All figures are measured, not estimated.

| Subsystem | Production evidence | Reuse |
|---|---|---|
| Task system | `tasks` 2,411 · `task_events` 12,561 · unique `idempotency_key` + `execution_hash` | Work model, retry, idempotency, parent/child, batching |
| Approval system | `approvals` 125 rows · 109 `approval.approved` / 8 `approval.rejected` audit rows · 18 classified capability keys | Human-in-the-loop, separation of duties, fail-closed default |
| Audit logging | `audit_logs` 7,557 rows, 20+ action types | Generic workspace/user/entity/metadata shape |
| Queues + workers | 4 priority queues · 3 Supervisor workers · autorestart · `--max-time=3600` | Add a queue name; infrastructure exists |
| Scheduler | 29 commands verified firing under `www-data` cron | Add a command |
| Runtime / AI routing | `RuntimeClient` + centralised `deepseek-models.js` · **`tier` parameter already exposed** | **Engineering888 can select Pro on day one with no new execution path** |
| Redis | v6.0.16 · 82-day uptime · 4 logical DBs | Caching, queues, memory |
| Notifications | `NotificationService` · `PushDispatcherService` · `notifications` 3,051 rows | Delivery |
| Memory | `WorkspaceMemoryService` · `workspace_knowledge` 1,228 | Context persistence |
| Meetings | `MeetingService` · `meetings` 12 · `meeting_messages` 105 | Multi-agent deliberation |
| Engine kernel | `EngineExecutionService` · `CapabilityMapService` · `EngineManifestLoader` · `EngineRegistryService` | The documented way to add an engine |
| Capability grants | `AgentCapabilityService` (`grant`/`revoke`) · `agent_capabilities` 381 rows | Per-agent tool authority |
| Intelligence | `ToolSelectorService` · `ToolCostCalculatorService` · `AgentExperienceService` | Tool selection + cost |
| System health | `SystemHealthService` · `QueueHealthReportCommand` · 4 health endpoints | Monitoring |

**Must be built new:** Engineering-specific tables, an `Engineering` role (M1), `engineering.*`
capabilities, an Engineering queue, and a reporting subsystem (none exists — `generate_report`
lives only inside Bella).

---

## STATUS SUMMARY

| Category | Total | Complete | Outstanding |
|---|---|---|---|
| **MANDATORY** | 9 | **0** | **9** |
| **RECOMMENDED** | 8 | **2** (R1, R2) | 6 |
| **OPTIONAL** | 8 | 0 | 8 |

**Engineering888 status: PAUSED — 9 of 9 mandatory dependencies outstanding.**

---

**Registered 2026-07-26 · P0-A · no Engineering888 code exists or was created**
