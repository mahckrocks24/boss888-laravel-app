# P0-B IMPACT MATRIX — GOVERNANCE INTEGRATION LAYER

**Phase:** P0-B · **Date:** 2026-07-26 · **Status:** COMPLETE — required before implementation begins
**Commit context:** `416686e2fb75cca4c0a245a0d60f230ced178429` · branch `feature/studio888-mrc-2a`
**Principle:** *Integration over invention.* Connect existing governance; do not replace it.

---

## 0. BASELINE (measured before any change)

| Metric | Value | Source |
|---|---|---|
| Test suite | **353 passed · 1 failed · 1 skipped · 550 pending** (1,278 assertions, 233 s) | `php artisan test` |
| **Pre-existing failure** | `CircuitBreakerTest > cooldown transitions to half open` — cooldown/timing, unrelated to governance | same run |
| Routes | 978 | `route:list --json` |
| Migrations | 210 | `SELECT COUNT(*) FROM migrations` |
| `bella_chat` audit rows | **0** (dormant) | `audit_logs` |
| Gates defined | **0** | `grep Gate::define` |
| Policies directory | absent | `ls app/Policies` |

**Regression definition for P0-B:** *no NEW test failure beyond `CircuitBreakerTest`.*

---

## 1. STRUCTURAL CONSTRAINTS DISCOVERED

| Constraint | Evidence | Consequence for design |
|---|---|---|
| **`bootstrap/providers.php` does not exist**; `bootstrap/app.php` has **no `withProviders()`** | `cat`, `grep` | ❌ **Do NOT create a new ServiceProvider** — its registration path is unproven and touching boot wiring is the single highest-risk change available. |
| `AppServiceProvider::boot()` is **demonstrably loading** — registers the `auth.jwt` alias and `InfrastructurePolicyRegistrar::register()` | source | ✅ Register governance from here. Proven-loaded code path. |
| **`InfrastructurePolicyRegistrar` is an existing explicit-registration pattern** with a docblock explaining engine-namespaced models are not auto-discovered | source | ✅ **Mirror this exact pattern.** Integration over invention. |
| `query_database` is **fully contained to `BellaController`** | `grep` across `app routes config tests` | ✅ Removal touches exactly one file. |
| `audit_logs` columns: `id, workspace_id, user_id, action, entity_type, entity_id, metadata_json, created_at` | `Schema::getColumnListing` | ✅ Governance decisions fit as `action='governance.decision'` + `metadata_json`. **No migration needed.** |
| `workspace_users.role` data: `owner` 27, `viewer` 1 | SQL | Role vocabulary unification must not assume `admin`/`member` rows exist. |

---

## 2. CHANGE REGISTER

### C-01 — `config/governance.php` (NEW)

| Field | Value |
|---|---|
| **Change** | New config file holding the governance mode + registry toggles |
| **Files** | `config/governance.php` (create) |
| **Routes affected** | **0** |
| **Middleware affected** | **0** |
| **Database impact** | **None** |
| **User impact** | **None** |
| **Platform impact** | **None** — a config file that nothing reads until C-04 |
| **Rollback** | `rm config/governance.php` |
| **Risk** | 🟢 **NONE** |
| **Approval** | Not required |
| **Expected behaviour** | `config('governance.mode')` returns `shadow` |

### C-02 — `PermissionRegistry` (NEW)

| Field | Value |
|---|---|
| **Change** | Native permission registry — the single lookup layer for capability → roles / approval / MFA / risk. **No Spatie. No competing RBAC.** Structure deliberately mirrors `ApprovalPolicyRegistry` (static map + fail-closed default). |
| **Files** | `app/Core/Governance/PermissionRegistry.php` (create) |
| **Routes / Middleware / DB** | **0 / 0 / none** |
| **User impact** | **None** — pure data + lookup, no side effects, no I/O |
| **Platform impact** | **None** until a Gate calls it |
| **Rollback** | Delete file |
| **Risk** | 🟢 **NONE** |
| **Approval** | Not required |
| **Expected behaviour** | `PermissionRegistry::get('billing.adjust_credits')` returns the classification; unknown key returns `STRICT_DEFAULT` (deny) |

### C-03 — `GovernanceService` + `GovernanceDecision` (NEW)

| Field | Value |
|---|---|
| **Change** | Evaluates a capability for a principal and returns a decision. In **shadow mode it never blocks** — it records what *would* have happened. Writes one `audit_logs` row per decision. |
| **Files** | `app/Core/Governance/GovernanceService.php`, `app/Core/Governance/GovernanceDecision.php` (create) |
| **Routes / Middleware** | **0 / 0** |
| **Database impact** | **INSERT into `audit_logs` only** (existing table, existing shape). No schema change. Volume: one row per governance evaluation. |
| **User impact** | **None in shadow mode** — decisions are advisory |
| **Platform impact** | Additional `audit_logs` writes. Mitigated: shadow writes are wrapped in try/catch and can be disabled by config |
| **Rollback** | `governance.mode=off` (instant, no deploy) → delete files |
| **Risk** | 🟡 **LOW** — only risk is audit-write volume |
| **Approval** | Not required |
| **Expected behaviour** | Returns `GovernanceDecision{allowed, reason, mode}`; in shadow, `allowed` is always advisory and the caller ignores it |

### C-04 — `GovernanceGateRegistrar` + Gates (NEW)

| Field | Value |
|---|---|
| **Change** | Registers a `Gate::define()` for every capability in the registry. **Every Gate delegates to `PermissionRegistry` — zero duplicated authorization logic.** In shadow mode every gate **returns `true`** after recording the decision. |
| **Files** | `app/Core/Governance/GovernanceGateRegistrar.php` (create) |
| **Routes affected** | **0** — no route is modified; no Gate is *called* by any route yet |
| **Middleware affected** | **0** |
| **Database impact** | via C-03 audit writes only |
| **User impact** | **None** — nothing calls `Gate::allows()` on these abilities yet |
| **Platform impact** | Gates exist in the container. Registration is O(n) over ~83 capabilities at boot |
| **Rollback** | Remove the one-line call in `AppServiceProvider::boot()` |
| **Risk** | 🟡 **LOW** — boot-time cost; a throw here would break every request ⇒ **the registrar is wrapped in try/catch and fails open with a logged warning** |
| **Approval** | Not required |
| **Expected behaviour** | `Gate::has('billing.adjust_credits')` is true; `Gate::allows(...)` returns true in shadow and records a decision |

### C-05 — `AppServiceProvider::boot()` (MODIFY — the only boot-path change)

| Field | Value |
|---|---|
| **Change** | Add **one line**: `\App\Core\Governance\GovernanceGateRegistrar::register();` — placed immediately after the existing `InfrastructurePolicyRegistrar::register();`, following the established pattern |
| **Files** | `app/Providers/AppServiceProvider.php` (modify, 1 line + comment) |
| **Routes / Middleware / DB** | **0 / 0 / none** |
| **User impact** | **None** if the registrar behaves; **total outage** if it throws |
| **Platform impact** | ⚠️ **This is the single highest-risk change in P0-B.** It executes on every request and every queue job |
| **Mitigation** | Registrar body is `try { … } catch (\Throwable) { Log::warning(); }` — **fails open**. Verified by `php artisan route:list` + a live HTTP 200 probe before workers restart |
| **Rollback** | Restore `.bak` (single line removal) → `php artisan config:clear` → restart workers. **~30 seconds.** |
| **Risk** | 🟠 **MEDIUM** |
| **Approval** | ✅ **Boss-approved as part of P0-B** |
| **Expected behaviour** | App boots normally; `route:list` succeeds; `/api/health` returns 200 |

### C-06 — Policies: `UserPolicy`, `WorkspacePolicy` (NEW)

| Field | Value |
|---|---|
| **Change** | Two policies where they genuinely improve maintainability (model-scoped, repeatedly checked). **Not created for framework purity** — no policy for non-model operations like `platform.config_write`. Both delegate to `PermissionRegistry`. |
| **Files** | `app/Policies/UserPolicy.php`, `app/Policies/WorkspacePolicy.php` (create) |
| **Routes affected** | **0** — registered but **not invoked** by any controller in P0-B |
| **Middleware / DB** | **0 / none** |
| **User impact** | **None** — no `authorize()` call is added anywhere |
| **Platform impact** | Registered via the registrar, mirroring `InfrastructurePolicyRegistrar` |
| **Rollback** | Delete files + registration lines |
| **Risk** | 🟢 **LOW** |
| **Approval** | Not required |
| **Expected behaviour** | `Gate::getPolicyFor(User::class)` resolves; behaviour unchanged because nothing calls it |

### C-07 — Remove `query_database` from Bella (MODIFY) — **GD-002**

| Field | Value |
|---|---|
| **Change** | Remove the capability entirely: allowlist entry, dispatcher arm, `actionQueryDatabase()`, `executeSafeQuery()`, both system-prompt instruction blocks, and the now-purposeless `getSchemaMap()` (it existed **solely** to let the model author SQL). Leave a migration note in-file. |
| **Files** | `app/Http/Controllers/Api/Admin/BellaController.php` (modify) |
| **Routes affected** | **0** — `query_database` was never a route; it was an LLM-dispatched action |
| **Middleware affected** | **0** |
| **Database impact** | **None** |
| **User impact** | **ZERO.** Bella has **never executed** (`audit_logs action='bella_chat'` = 0; `bella_conversations` = 0). No user has ever reached this code path |
| **Platform impact** | Removes the platform's single highest-severity latent risk (R-02): unrestricted SELECT over `users.password`, `mfa_secret_encrypted`, `api_keys.key` |
| **Rollback** | Restore `.bak` — but ⚠️ **rollback is discouraged**: it restores a CRITICAL latent vulnerability |
| **Risk** | 🟢 **LOW** (removal) / 🔴 **CRITICAL** (if left) |
| **Approval** | ✅ **GD-002 — Platform Owner approved** |
| **Expected behaviour** | Bella remains dormant and unreachable-by-use. `query_database` is an unknown action ⇒ fail-closed. Schema map no longer injected into the prompt |

### C-08 — Bella governance integration (MODIFY, behaviour-neutral)

| Field | Value |
|---|---|
| **Change** | Register Bella's 11 remaining capabilities in `PermissionRegistry` as `bella.*` with `machine` principal class. **No change to `BellaController` execution flow.** Bella is *described* to the governance layer, not yet *governed by* it |
| **Files** | `app/Core/Governance/PermissionRegistry.php` (data only) |
| **Routes / Middleware / DB** | **0 / 0 / none** |
| **User impact** | **None** |
| **Platform impact** | **Bella remains dormant, unexposed, and behaviourally identical** |
| **Rollback** | Remove registry entries |
| **Risk** | 🟢 **NONE** |
| **Approval** | ✅ GD-001 (Govern and Gate — integrate, do not enable) |
| **Expected behaviour** | `PermissionRegistry::get('bella.adjust_credits')` returns `approval:true, mfa:true, principal:machine`. Bella still does not consult it |

### C-09 — Governance test suite (NEW)

| Field | Value |
|---|---|
| **Change** | `tests/Feature/Governance/` covering registry, gate resolution, policy resolution, capability lookup, approval lookup, shadow mode, audit generation, Bella governance, regression |
| **Files** | `tests/Feature/Governance/GovernanceIntegrationTest.php` (create) |
| **Routes / Middleware / DB** | **0 / 0 / test DB only** |
| **User impact** | **None** |
| **Platform impact** | **None** — tests do not run in production |
| **Rollback** | Delete file |
| **Risk** | 🟢 **NONE** |
| **Approval** | Not required |
| **Expected behaviour** | All new tests pass; the 353-pass baseline is preserved |

---

## 3. AGGREGATE IMPACT

| Dimension | Impact |
|---|---|
| **Routes modified** | **0 of 978** |
| **Middleware modified** | **0 of 17** |
| **Route protection changed** | **NONE** |
| **Migrations** | **0** — no schema change; governance audit reuses `audit_logs` |
| **Existing files modified** | **2** — `AppServiceProvider.php` (1 line), `BellaController.php` (removal only) |
| **Files created** | **8** |
| **Authorization enforcement** | **NONE** — shadow mode; every gate returns `true` |
| **Bella** | dormant · unexposed · behaviour unchanged · one capability removed |
| **User-visible change** | **NONE** |

---

## 4. GOVERNANCE MODE LADDER

```
  off        registry inert; no gates; no audit                  ← instant kill switch
  shadow ◄── evaluates · logs the would-be decision · ALWAYS ALLOWS   ← P0-B TARGET
  audit      evaluates · logs · always allows · emits events      ← P0-C
  enforce    evaluates · logs · DENIES                            ← P0-D, per-capability
```
Set by `config/governance.php` ← `GOVERNANCE_MODE` env. **P0-B ships in `shadow` and never blocks.**

**Fail-open guarantee:** every governance code path is wrapped so that any exception is logged and
treated as *allow*. Governance must never be the reason a request fails during P0-B.

---

## 5. ROLLBACK STRATEGY

| Level | Trigger | Procedure | Time |
|---|---|---|---|
| **L0 — Instant** | Any anomaly | `GOVERNANCE_MODE=off` in `.env` → `php artisan config:clear` | **~10 s** |
| **L1 — Boot issue** | 500s after deploy | Restore `AppServiceProvider.php.bak-p0b-*` → `config:clear` → restart workers | **~30 s** |
| **L2 — Full revert** | Systemic problem | Restore both `.bak` files, delete the 8 created files → `config:clear` → restart workers | **~2 min** |
| **L3 — Bella only** | ⚠️ discouraged | Restore `BellaController.php.bak-p0b-*` — **restores a CRITICAL vulnerability (R-02)** | ~30 s |

**Rollback validation (must pass after any rollback):** `php -l` on restored files · `php artisan route:list` returns 978 · `/api/health` HTTP 200 · `php artisan test` matches the 353/1 baseline · workers RUNNING.

**Never `config:cache`** — `RuntimeClient` reads `env()` directly. Use `config:clear`.

---

## 6. RISK SUMMARY

| Change | Risk | Worst case | Mitigation |
|---|---|---|---|
| C-05 `AppServiceProvider` | 🟠 MEDIUM | Total outage if registrar throws | try/catch fail-open · syntax check · route:list · live HTTP probe **before** worker restart |
| C-03 audit writes | 🟡 LOW | `audit_logs` growth | try/catch · disable via `GOVERNANCE_MODE=off` |
| C-04 gate registration | 🟡 LOW | Boot cost | ~83 closures; measured against baseline |
| C-07 Bella removal | 🟢 LOW | None — code never executed | 0 production executions proven |
| C-01/02/06/08/09 | 🟢 NONE | — | Additive, unreferenced |

**Highest residual risk: C-05.** It is one line, in an already-loading method, following an existing
in-codebase pattern, wrapped to fail open, and verified by four independent checks before workers
are restarted.

---

## 7. SUCCESS CRITERIA (verified in the implementation report)

| Criterion | Verification |
|---|---|
| One governance vocabulary | All capability keys use `engine.action`; registry is the sole source |
| One permission lookup path | Gates + Policies both delegate to `PermissionRegistry`; no inline role logic |
| One capability registry | `PermissionRegistry` cross-references `ApprovalPolicyRegistry` rather than duplicating it |
| One approval lookup path | Registry defers to `ApprovalPolicyRegistry` for approval classification |
| Zero duplicated authorization logic | No new `is_platform_admin` check outside `AdminMiddleware` |
| Bella still dormant | `audit_logs action='bella_chat'` = 0 after deploy |
| No production regression | `route:list` = 978 · test baseline preserved · HTTP 200 |
| Rollback verified | L0 and L1 executed and validated on staging |

---

**MATRIX COMPLETE — implementation authorised to begin.**
