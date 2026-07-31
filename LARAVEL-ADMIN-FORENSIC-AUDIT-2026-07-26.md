# LARAVEL ADMIN PLATFORM — FORENSIC AUDIT
**Date:** 2026-07-26 · **Baseline:** `ADMIN-AUDIT-BASELINE-2026-07-26.md`
**Commit audited:** `416686e2fb75cca4c0a245a0d60f230ced178429` · branch `feature/studio888-mrc-2a`
**Method:** codebase, database, routes, configuration, and live production behaviour. No documentation was trusted.
**Changes made:** **none.** This is forensic analysis only.

---

# 1. EXECUTIVE SUMMARY

The Laravel Admin platform is **functionally rich, operationally healthy, and structurally
under-governed.** It runs a real business — 25 workspaces, 24 active subscriptions, 2,411 tasks,
298k traffic rows — with zero failed jobs and a clean queue at audit time.

Three things define its true state:

**1. Authorization is a single binary flag, not an architecture.**
There are **zero Laravel Gates** (`grep -rn "Gate::define" app/` → no results) and **no
`app/Policies/` directory**. All 978 routes are protected exclusively by middleware, and admin
identity reduces to one boolean: `users.is_platform_admin`. There is no role table, no permission
table, and no delegation model. Of 11 users, 2 hold both `is_admin` and `is_platform_admin` — the
flags are perfectly correlated, so `is_admin` is effectively dead weight. **An administrator is
all-or-nothing.**

**2. Bella is a fully-built, never-executed autonomous admin agent — and it is reachable.**
1,748 lines, 38 methods, an action dispatcher that can adjust credits and suspend users. The
decisive test: `bella_conversations` = 0 rows, `bella_memory` = 0 rows, and
`audit_logs WHERE action='bella_chat'` = **0**. Every successful chat writes an audit row, so
Bella has **never completed a single execution in production**. Yet its routes are registered and
pass middleware for any platform admin. The most dangerous capability — arbitrary SQL — is
**inert by accident**, not by design: `DB::select(DB::raw($sql))` throws a `TypeError` on Laravel
11. Anyone "fixing" that one line silently arms full-database read access.

**3. The platform's best security engineering is real but unevenly applied.**
`DenyApiKeyAuth` and `RequireMfaStepUp` are genuinely excellent — the MFA middleware is a
four-state machine that deliberately **fails closed** when governance degrades, and its docblock
explicitly names the `BELLA_ADMIN_TOKEN` weakness. But `RequireMfaStepUp` guards **1 route out of
978**, and `DenyApiKeyAuth` guards 45 — while **103 admin routes, including all four Bella
routes, have neither.** The controls exist; the coverage does not.

**Enterprise readiness: 5.4 / 10.** The platform is a capable, well-instrumented product built at
speed by a small team. It is not yet governable at enterprise scale: no RBAC, one 19,363-line
route file, 508 backup files totalling 81 MB, and test coverage concentrated in two subsystems
while the admin surface itself is almost untested.

**Nothing found requires an emergency response.** The highest-severity item (Bella's ungoverned
action dispatcher) is latent because Bella has never run.

---

# 2. SYSTEM OVERVIEW

```
                      ┌──────────────────────────────────────┐
   95 unauth routes ──►                                      │
   41 api-key routes ─►   LARAVEL 11.51.0 / PHP 8.3.32       │
  612 user routes ────►   /var/www/levelup-staging           │
  103 admin routes ───►   APP_ENV=staging (IS production)    │
   24 hardened admin ─►                                      │
    1 MFA route ──────►   17 Engines · 27 Core modules       │
   23 runtime routes ─►   978 routes · 178 tables            │
                      └───────────────┬──────────────────────┘
                                      │ X-LevelUp-Secret
                                      ▼
                      ┌──────────────────────────────────────┐
                      │  RAILWAY RUNTIME (execution layer)   │
                      │  deepseek-v4-flash / -pro · OpenAI   │
                      └──────────────────────────────────────┘
```

| Dimension | Measure | Evidence |
|---|---|---|
| Routes | 978 | `php artisan route:list --json` |
| Tables | 178 · 115 foreign keys · 210 migrations | `SHOW TABLES`, `information_schema` |
| Engines | 17 | `ls app/Engines/` |
| Core modules | 27 | `ls app/Core/` |
| Admin controllers | 10 (4,995 lines) | `wc -l app/Http/Controllers/Api/Admin/*.php` |
| Console commands | 40 · 29 scheduled | `ls app/Console/Commands/`, `schedule:list` |
| Jobs | 7 · **0 Events · 0 Listeners · 0 Observers** | `ls app/Jobs app/Events app/Listeners app/Observers` |
| Tests | 60 files | `find tests -name '*.php'` |
| `.bak` files | **508 · 81.0 MB** | `find . -name '*.bak*'` |
| Largest file | `routes/api.php` — **1,096 KB / 19,363 lines** | `wc -l` |

---

# 3. DETAILED FINDINGS

## 3.1 Architecture

**F-A1 — Laravel is an orchestrator, not an executor.** Confirmed in the DeepSeek V4 incident and
re-verified: `RuntimeClient` is the boundary to all AI execution. `config/llm.php` still resolves
`llm.deepseek.model = deepseek-chat` — a **retired identifier** — yet nothing breaks, because that
path is dormant. This is a live proof that Laravel-side AI config is decorative.
*Evidence: `config('llm.deepseek.model')` returns `deepseek-chat`; production is healthy.*

**F-A2 — No event-driven architecture.** `app/Events/`, `app/Listeners/`, `app/Observers/` are all
empty or absent. Coordination happens through direct service calls, the `tasks` table, and 7 jobs.
Cross-cutting concerns (audit, notification) are invoked explicitly at each call site, which is
why coverage is inconsistent.
*Evidence: `ls app/Events app/Listeners app/Observers`.*

**F-A3 — `routes/api.php` is a 19,363-line monolith** containing not just routing but business
logic: **28 of 132 admin routes are closures with inline implementations**, some hundreds of lines
(e.g. `/bella/vision`, `/bella/artifacts`, `/bella/video-status` at lines 14063–14090).
*Evidence: `route:list` action field = `Closure`; `wc -l routes/api.php`.*

**F-A4 — Engine layering is coherent.** `EngineKernel` (execution, capability map, manifest,
registry) + 17 engines + `LaunchScopePolicy` as a single enforcement source is a genuinely sound
design, and the launch-scope boundary is enforced at kernel, capability, roster, task-creation,
runtime-boundary and route layers simultaneously.
*Evidence: `ls app/Core/EngineKernel/`, `LaunchScopeRoutes` middleware registered globally.*

## 3.2 Security

**F-S1 — `/api/admin/auth` exchanges one static shared secret for a platform-owner JWT.**
```php
$expected = env('BELLA_ADMIN_TOKEN', '');
if ($expected === '' || !hash_equals($expected, $token)) { return 401; }
$admin = \App\Models\User::find(1);   // hardcoded
```
The in-code justification is explicit: *"The admin token itself IS the auth — whoever has it is
authorized."* Consequences: no per-human attribution (every action logs as user 1), no MFA, no
expiry, no rotation mechanism. **User 1 = "Mark", `is_platform_admin=1`, `mfa_enabled=0`.**
*Mitigation present:* the issued token is provenance-tagged `shared_admin_token` on the **session**
(surviving refresh rotation), and `DenyApiKeyAuth` refuses it on 45 infrastructure routes.
*Evidence: `routes/api.php:86-126`; `route:list` shows middleware `['api']` only; user query.*

**F-S2 — The route is unauthenticated and only generically throttled.** `/api/admin/auth` carries
middleware `['api']`. `/api/auth/login` carries `ThrottleRequests:10,5`; `/api/auth/forgot-password`
carries `5,15`. The admin token exchange has **neither** — only the global `throttleApi()` default.
*Evidence: `route:list --json` middleware arrays.*

**F-S3 — Secrets exist in a filesystem backup.** `.env.bak-gsc-creds-20260612` (6,123 bytes)
matches an `sk-[A-Za-z0-9]{20,}` pattern. It is **not** web-reachable (outside docroot, and nginx
denies `.env`/`.bak` — verified HTTP 404), but it is a plaintext credential artefact on disk.
*Evidence: `grep -rlE 'sk-[A-Za-z0-9]{20,}' --include='*.bak*'` → 1 file; live probe → 404.*

**F-S4 — Backup-artifact exposure is properly controlled.** `/etc/nginx/snippets/levelup-deny-artifacts.conf`
blocks `.bak|backup|old|orig|save|swp|swo|rej|tmp`, archives, `.env`, `.log`, `.sql`, and VCS
directories, using the two-argument `return 404 "..."` form specifically to bypass
`error_page 404 /index.php` — which previously returned the SPA shell with HTTP 200 and hid the
absence of protection. Live probes confirm 404 on `.bak`, `.env`, and `storage/logs/laravel.log`.
**This is exemplary work and should be preserved.**
*Evidence: snippet source; 4 live HTTP probes.*

**F-S5 — `.bak` files are not autoloadable.** Composer uses PSR-4 (`App\` → `app/`). 353 files
match `app/**/*.php.bak*`; **0** match `app/**/*.bak*.php`. PSR-4 resolves only `.php`, so no
backup file can be autoloaded, and none is `require`d anywhere.
*Evidence: `composer.json` autoload block; two `find` counts.*

## 3.3 Authorization — complete architecture

**F-Z1 — Authorization is 100% middleware. There is no Gate and no Policy.**
```
grep -rn "Gate::define" app/  → (no results)
ls app/Policies/              → directory does not exist
```
Consequence: no per-model authorization, no `$user->can()` semantics, no policy tests. Every
access decision is a route-level middleware attachment, so **a route that forgets its middleware
has no second line of defence.**

**F-Z2 — The complete authorization mechanism inventory (10 middleware):**

| Middleware | Alias | Decision | Coverage |
|---|---|---|---|
| `JwtAuthMiddleware` | `auth.jwt` | validates JWT **or** api-key; sets `auth_via`, `auth_via_claim`, `workspace_id`, `workspace_role`, `session_id` | 883 routes |
| `AdminMiddleware` | `admin` | `if (! $user->is_platform_admin) → 403` | **129 routes** |
| `DenyApiKeyAuth` | — | refuses `auth_via=api_key` **and** `auth_via_claim=shared_admin_token` | **45 routes** |
| `RequireMfaStepUp` | `mfa.stepup` | four-state governance machine (below) | **1 route** |
| `ApiKeyAuth` | `api.key` | api-key credential resolution | 41 routes |
| `RuntimeSecretMiddleware` | `runtime.secret` | shared runtime secret | 23 routes |
| `TeamRoleMiddleware` | `team.role` | workspace-level role (`:admin`) | 5 routes |
| `PlanMiddleware` / `AeoPlanGate` | `plan` / `aeo.gate` | commercial gating | 12 routes |
| `ConnectorBrandFilter` | `connector.brand` | white-label scrub | 617 routes |
| `LaunchScopeRoutes` | — | 404s out-of-scope surfaces | global |

**F-Z3 — `RequireMfaStepUp` is the strongest control in the codebase and is almost unused.**
It implements four states — BOOTSTRAP (stand down), ACTIVATED+bar-met (enforce), **DEGRADED (fail
closed, HTTP 423)**, RECOVERY (time-boxed break-glass). Its docblock states the design intent
precisely: *"losing an admin must never be a way to switch off MFA."* Emergency credential
revocation is deliberately left outside the middleware so a leaked credential can always be killed.

**But:** it protects exactly one route —
`POST /api/admin/infrastructure/providers/approvals/{aid}/approve` — and with **0 of 11 users
having `mfa_enabled=1`**, governance sits in **BOOTSTRAP**, where the middleware stands down by
design and emits `X-INFRA-MFA-Stepup: disabled-below-governance-bar`.
*Evidence: middleware source; `route:list` MFA filter → 1 route; `SELECT COUNT(*) WHERE mfa_enabled=1` → 0.*

**F-Z4 — Privilege-escalation surface is narrow but real.** There is no vertical escalation path
from a normal user: `AdminMiddleware` reads a database column that no user-facing endpoint writes.
The realistic escalation is **credential-based**: possession of `BELLA_ADMIN_TOKEN` yields
platform-owner authority with no second factor and no individual attribution.

**F-Z5 — Platform-owner protections are partial.** `actionSuspendUser` explicitly refuses to
suspend a platform admin (`if ($user->is_platform_admin) return ['error' => ...]`). No equivalent
guard exists on `actionAdjustCredits`, which accepts an unbounded signed integer.

## 3.4 Routes — classification of all 978

| Class | Count | Definition / evidence |
|---|---|---|
| **Standard authenticated** | 612 | `JwtAuth + ConnectorBrandFilter + TrafficDefense` |
| **Platform admin (unhardened)** | **103** | `JwtAuth + AdminMiddleware` — **no `DenyApiKeyAuth`** |
| Authenticated (plain) | 61 | `JwtAuth` only |
| API-key integration | 41 | `ApiKeyAuth + ConnectorBrandFilter` |
| Web (SPA/static) | 34 | `web` group |
| **Unauthenticated API** | 33 | `api` only |
| **Platform admin (hardened)** | 24 | `+ DenyApiKeyAuth` |
| Runtime internal | 23 | `RuntimeSecretMiddleware` |
| Hardened non-admin | 20 | `DenyApiKeyAuth + JwtAuth` |
| Plan-gated | 11 | `PlanMiddleware:app888 + Throttle` |
| Rate-limited public | 5 | explicit `ThrottleRequests` |
| Team-role gated | 5 | `TeamRoleMiddleware:admin` |
| **MFA step-up** | **1** | `RequireMfaStepUp` |

**Unauthenticated surface = 95 routes** (33 API + 34 web + 23 runtime-secret + 5 throttled).
The genuinely open ones are the expected set: auth endpoints, public chatbot (6), public content
(blog/news/plans/workspace-count), email tracking pixels (4), invites (2), health/ping (3), plus
`POST /api/admin/auth`. The 23 `internal/*` routes are secret-protected, not open.

**Route ownership:** `AdminController` 39 · **Closure 28** · `ProviderControlPlaneController` 20 ·
`AdminMediaController` 10 · `AdminContentController` 7 · `AdminIntelligenceController` 6 ·
`InfrastructureCatalogController` 5 · `AdminTemplatesController` 5 · `MfaController` 4 ·
`AdminEngineController` 3 · `AdminAnalyticsController` 2 · `AdminNotificationController` 2 ·
`BellaController` 1.

**Dead / duplicate / legacy:** no unreachable routes were found — all 978 resolve. The legacy
surface is the **56 `routes/api.php.bak-*` files** (1.1 MB each) which are *not loaded* (only
`api.php`, `web.php`, `exec-api.php` are registered) and the launch-scope-disabled surfaces
(social/email/mentions), which return 404 via `LaunchScopeRoutes` while remaining registered.

## 3.5 Database

**F-D1 — Well-normalised, well-keyed: 178 tables, 115 foreign keys.** `tasks` carries 13 indexes
including a unique `idempotency_key` and an `execution_hash` — evidence of deliberate
idempotency engineering.

**F-D2 — Index coverage is thin on the largest tables.** `traffic_logs` holds **298,830 rows**
with only 2 indexes (PRIMARY + `workspace_id,created_at`); `seo_links` holds 4,806 rows with 2
(PRIMARY + a FK index). Any query on `traffic_logs` not leading with `workspace_id` scans.
*Evidence: `SHOW INDEX FROM ...` per table.*

**F-D3 — 39 of 178 tables are empty (22%)** — including `platform_settings` (which
`DeepSeekConnector` reads as an encrypted API-key fallback), all `campaigns`/`sequences`/`social_*`
tables (launch-scope-removed by design), and both Bella tables.

**F-D4 — Backlog of unfinished work is real and aging.** 213 failed tasks (oldest 2026-05-08),
63 blocked, 19 pending older than 24 h, 0 stuck in `running`. No cleanup or replay mechanism has
run against them.

## 3.6 Queues & Scheduler

**F-Q1 — Healthy at audit time.** All four queues (`tasks-high`, `tasks`, `tasks-low`, `default`)
at depth 0; `jobs` = 0; `failed_jobs` = 0; 3 Supervisor workers RUNNING with `autorestart=true`
and `--max-time=3600`.

**F-Q2 — Failures bypass `failed_jobs` entirely.** `TaskExecutionJob` catches exceptions and
records them in `tasks.error_text` / `task_events`. `failed_jobs` has been 0 throughout, including
during a 42-hour total AI outage. **Queue-health monitoring that watches `failed_jobs` is blind
to task failure.** This is the structural cause of the INC-2026-001 detection gap.
*Evidence: `audit_logs` shows 904 `task.execution_failed` vs `failed_jobs` = 0.*

**F-Q3 — Scheduler is healthy and dense.** 29 scheduled commands under the `www-data` crontab,
firing on schedule (verified via `schedule:list` next-due timers). Three `sarah:*` synthesis
commands are registered `0 * * * *` (hourly) each, which is unusual for daily/weekly/monthly
synthesis and warrants confirmation that internal guards debounce them.

## 3.7 Runtime & AI integration

Verified live: runtime `/health` = `2.37.3` (content v2.37.4), 10 agents, 43 tools. Model registry
rejects retired identifiers with `DEEPSEEK_INVALID_MODEL`. Provider `/models` returns exactly
`deepseek-v4-flash` and `deepseek-v4-pro`.

**F-R1 — Provider attribution is emitted but discarded.** The runtime returns
`actual_provider`/`actual_model`/`fallback_used`; `RuntimeClient::logApiUsage()` is called with a
**hardcoded** `'deepseek'` (line 373) and `estimateApiCost()` (line 1203) prices by *provider*, not
model. `api_usage_logs` therefore contains 70 rows reading `provider=deepseek, model=gpt-4o-mini`
priced at DeepSeek rates. (Carried from INC-2026-001 as TD-01/TD-03.)

**F-R2 — `config/llm.php` is stale and harmless.** It still names `deepseek-chat`. Because the
direct connector path is dormant, this has no production effect — but it is a trap for any future
engineer who assumes Laravel config drives model selection.

## 3.8 Maintainability

| Signal | Measure |
|---|---|
| Largest file | `routes/api.php` — 19,363 lines / 1,096 KB |
| Files > 70 KB | 12, incl. `ArthurService` 256 KB, `SeoService` 219 KB, `Orchestrator` 108 KB |
| `.bak` files | 508 / 81 MB — **347 created in July 2026 alone** |
| `Orchestrator.php` backups | **23** in one directory |
| `EngineExecutionService.php` backups | 14 |
| Tests | 60 files: Infrastructure 22, Studio 20, Feature 12, Domains 2, **Queue 1** |
| Admin test coverage | **0 tests target the admin controllers or Bella** |

The `.bak` convention is a deliberate, documented safety practice ("backup before every change")
and it has clearly prevented data loss. But at 508 files and accelerating, it now competes with
git for the role of history, and `find`/`grep` across `app/` returns backup hits by default —
which measurably slowed this audit and previously caused a mis-count of DeepSeek call sites.

---

# 4. BELLA REPORT

## 4.1 Verdict

**Bella is a complete, reachable, never-executed autonomous admin agent with mutating
capabilities and no human-in-the-loop.**

## 4.2 Decisive dormancy evidence

| Test | Result |
|---|---|
| `bella_conversations` rows | **0** |
| `bella_memory` rows | **0** |
| `audit_logs WHERE action='bella_chat'` | **0** |
| `audit_logs WHERE entity_type='bella'` | **0** |

`chat()` writes an `audit_logs` row and two `bella_conversations` rows on **every** successful
completion. Zero rows across all four measures ⇒ **Bella has never completed an execution in
production.** It is dormant code on a live route.

## 4.3 Architecture

```
POST /api/admin/bella
  └─ middleware: api → JwtAuthMiddleware → AdminMiddleware        (NO DenyApiKeyAuth)
     └─ BellaController::chat()
        ├─ intent interceptors (image / document / presentation / video)  ── bypass the LLM entirely
        ├─ loadConversationHistory()   ← bella_conversations
        ├─ loadMemory()                ← bella_memory
        ├─ gatherPlatformContext()     ← live platform stats
        ├─ getRecentLogErrors(N)       ← ⚠ reads storage/logs/laravel.log INTO the prompt
        ├─ getSchemaMap()              ← database schema INTO the prompt
        ├─ buildSystemPrompt()
        ├─ RuntimeClient::chatJson()   ─────────► Railway ─► DeepSeek
        ├─ extractActionBlock(llmOutput)         ⚠ TRUST BOUNDARY
        ├─ if action ∈ allowedActions() → executeAction(action, params)
        │     ⚠ params are entirely LLM-controlled; no confirmation, no approval
        ├─ second LLM call to summarise the action result
        ├─ parseAndStoreMemory()       → bella_memory
        ├─ saveConversationTurn() ×2   → bella_conversations
        └─ audit_logs insert (action='bella_chat')
```

## 4.4 Capability inventory (12 actions)

| Action | Class | Risk |
|---|---|---|
| `list_users` | read | PII exposure |
| `get_analytics` | read | low |
| `get_workspace` | read | cross-tenant read |
| `get_queue` | read | low |
| `get_audit_logs` | read | low |
| `get_engine_status` | read | low |
| `generate_report` | read | low |
| `remember` / `forget` | write (own memory) | prompt-persistence |
| **`query_database`** | **read, arbitrary SQL** | **HIGH — currently inert** |
| **`adjust_credits`** | **write, financial** | **HIGH** |
| **`suspend_user`** | **write, customer-impacting** | **HIGH** |

## 4.5 Security model — findings

**B-1 (CRITICAL, latent) — The action dispatcher trusts LLM output.**
`extractActionBlock()` regex-parses JSON out of the model's reply; if `action` is in the
allowlist, `executeAction()` runs it with **LLM-supplied params**. The allowlist gates the *verb*
and nothing gates the *object*. There is no confirmation step, no approval record, no dry-run, and
no second factor. A prompt injection that induces
`{"action":"adjust_credits","params":{"workspace_id":7,"amount":999999}}` executes it.

**B-2 (HIGH, latent) — Indirect prompt-injection surface.** `getRecentLogErrors()` tails
`storage/logs/laravel.log` and injects up to 200-character error lines into the system prompt.
Log lines contain user-influenced content (article topics, workspace names, error messages). An
attacker who can get text into an error log has a path to influence Bella's output — and therefore
its actions — without ever talking to Bella.

**B-3 (HIGH, currently inert) — `query_database` is arbitrary SQL behind a denylist.**
`executeSafeQuery()` requires a `SELECT` prefix, blocks 15 keywords, blocks stacked statements,
and forces `LIMIT 100` with a 5-second `MAX_EXECUTION_TIME`. It does **not** restrict *tables* or
*columns*, so `SELECT password, mfa_secret_encrypted FROM users` and `SELECT key FROM api_keys`
are structurally permitted, with no tenant scoping.
**It is currently non-functional:** `DB::select(DB::raw($sql))` raises
`TypeError: PDO::prepare(): Argument #1 ($query) must be of type string, Illuminate\Database\Query\Expression given`
— verified by live execution. The `catch (\Throwable)` converts this to
`['error' => 'Query failed: ...']`, so it **fails safe**. This is an accident of the Laravel 11
upgrade, not a control. **Repairing that single line arms full-database read access.**

**B-4 (HIGH) — Bella's mutating routes lack the platform's own strongest control.**
`DenyApiKeyAuth`'s docblock states the shared admin token is *"acceptable for read-only admin
panels; it is not acceptable for approving, overriding, retrying, cancelling or force-transitioning
infrastructure operations."* Bella can adjust credits and suspend users — mutations of comparable
consequence — yet its four routes carry only `JwtAuth + AdminMiddleware`. **The codebase already
contains the correct reasoning; it simply is not applied here.**

**B-5 (MEDIUM) — `adjust_credits` is unbounded and unapproved.** `(int)$params['amount']` with no
ceiling, no sign restriction, no approval, and no `is_platform_admin` protection on the target
workspace. It does write a `credit_transactions` row with `reference_type='admin_bella'` — the
audit trail is present.

**B-6 (MEDIUM) — Attribution collapses to user 1.** Via `/api/admin/auth`, every Bella action is
recorded as user 1 with no way to identify the acting human.

**B-7 (LOW, positive) — Genuine protections that do exist:** `suspend_user` refuses platform
admins; input validation caps message length (2,000) and history (20 turns); every completion
writes an `audit_logs` row capturing action, session, IP and token usage; the action allowlist is
a closed `match` with a `default => error`.

## 4.6 Dependency graph

```
BellaController
  ├─ App\Connectors\RuntimeClient          (constructor-injected — sole LLM path)
  ├─ App\Connectors\CreativeConnector      (image generation, via container)
  ├─ DB facade → 14+ tables (users, workspaces, credits, credit_transactions,
  │              audit_logs, tasks, subscriptions, bella_conversations, bella_memory, …)
  ├─ Cache facade
  ├─ Log facade
  └─ storage/logs/laravel.log              (direct filesystem read)
```
**Coupling verdict: tightly coupled, not isolated.** It reaches directly into 14+ tables via the
`DB` facade rather than through engine services, bypassing `EngineExecutionService`,
`LaunchScopePolicy`, `ApprovalService`, and credit-reservation logic. It is *architecturally
adjacent* to the platform rather than *integrated* with it. Notably, `DeepSeekConnector` injection
was already removed (PATCH 4, 2026-05-08) in favour of `RuntimeClient` — so its AI path is correct
and current.

## 4.7 Production readiness

**Not production-ready.** Blocking gaps: no front-end (`public/admin/index.php` is a 50-byte
redirect to `/admin/dashboard`; the sibling asset directory is literally named `{js,css}` and is
empty); no human-in-the-loop on mutations; `query_database` broken; no rate limiting on the chat
endpoint; no per-action approval; attribution collapse; zero tests.

---

# 5. ADMIN MODULE INVENTORY

| Module | Status | Routes | Controller | DB | Runtime | Notes |
|---|---|---|---|---|---|---|
| **Core Admin** (`AdminController`) | **Implemented** | 39 | 747 L / 39 methods | users, workspaces, tasks, sessions, credits, failed_jobs, memberships, subscriptions, audit_logs | no | The real admin backbone |
| **Media** | **Implemented** | 10 | 767 L / 10 | media (758), assets (392) | no | 2nd largest admin controller |
| **Chatbot** | **Implemented** | — | 402 L / 17 | 8 chatbot tables, 328 knowledge chunks | via engine | Live customer data |
| **Templates** | **Implemented** | 5 | 362 L / 5 | email_templates (115), email_blocks (742) | no | Data present, launch-scope removed |
| **Content** | **Implemented** | 7 | 314 L / 7 | articles (380), websites (3) | no | |
| **Intelligence** | **Implemented** | 6 | 207 L / 6 | engine_intelligence (587) | yes | |
| **Analytics** | **Partial** | 2 | 245 L / **2 methods** | reads across schema | no | Only overview + per-workspace |
| **Engine Registry** | **Partial** | 3 | 141 L / 3 | engine_registry (**1 row**) | no | Registry effectively unpopulated |
| **Notifications** | **Partial** | 2 | **62 L / 3** | notifications (3,051) | no | Thinnest controller; data volume is high |
| **Bella** | **Implemented, DORMANT** | 4 | 1,748 L / 2 public | bella_* (**0 rows**) | yes | §4 |
| **Infrastructure / INFRA888** | **Implemented, PREVIEW** | 25 | ProviderControlPlane 20 + Catalog 5 | 33 infra tables, 13 empty | no | Null connector; hardened with DenyApiKeyAuth + the sole MFA route |
| **MFA** | **Implemented, INACTIVE** | 4 | MfaController | users.mfa_* | no | **0 users enrolled** → governance in BOOTSTRAP |
| **Debug** | **Hidden** | — | `Api/Debug/` | — | no | `auth.jwt + AdminMiddleware`; `BOSS888_DEBUG_ENABLED` env flag |
| **Widget** | **Implemented** | — | `Api/Widget/` | chatbot_widget_tokens (15) | no | Public embed surface |
| **Orchestration admin** | **Implemented** | 6 | closures | tasks, task_events | yes | |
| **Strategy / Knowledge / Plans / Agents admin** | **Implemented** | 4 groups | closures | strategy_proposals (1,474) | yes | Closure-based |

---

# 6. ENGINEERING888 READINESS REPORT

**Question answered: what can Engineering888 reuse?** (No design proposed.)

## 6.1 Ready to reuse as-is

| Subsystem | Evidence of production use | Reuse value |
|---|---|---|
| **Task system** | `tasks` 2,411 · `task_events` 12,561 · `TaskService`, `TaskDispatcher`, `TaskRetryService`, `Orchestrator`, `TaskCategoryService`; unique `idempotency_key` + `execution_hash` | **Highest.** A proven engine-agnostic work model with retry, idempotency, parent/child, batching, and step tracking |
| **Approval system** | `approvals` 125 rows · `ApprovalService` (approve/reject/revise/requestIfNeeded) + `ApprovalPolicyRegistry` + `ApprovalAuthorizationService` · `audit_logs`: 109 `approval.approved`, 8 `approval.rejected` | **Highest.** A real, exercised human-in-the-loop with actor-type separation — precisely what Bella lacks |
| **Audit logging** | `audit_logs` 7,557 rows, 20+ action types, actively written | High. Workspace/user/entity/metadata shape is already generic |
| **Queues + workers** | 3 Supervisor workers, 4 priority queues, `--max-time=3600`, autorestart | High. Add a queue name; infrastructure is in place |
| **Scheduler** | 29 commands under `www-data` cron, verified firing | High |
| **Runtime / AI routing** | `RuntimeClient` + centralised `deepseek-models.js`; `tier` parameter already exposed | **High — Engineering888 can select Pro on day one with no new execution path** |
| **Redis** | v6.0.16, 82-day uptime, 4 logical DBs in use | High |
| **Notifications** | `NotificationService`, `NotificationTypes`, `PushDispatcherService`; `notifications` 3,051 rows | Medium-high |
| **Memory** | `WorkspaceMemoryService`; `workspace_memory` 41, `workspace_knowledge` 1,228, `agent_workspace_memory` 147 | Medium-high |
| **Meetings** | `MeetingService`; `meetings` 12, `meeting_messages` 105, `meeting_tasks` 23 | Medium — multi-agent deliberation already exists |
| **Engine kernel** | `EngineExecutionService`, `CapabilityMapService`, `EngineManifestLoader`, `EngineRegistryService` | High — the documented way to add an engine |
| **Intelligence** | `ToolSelectorService`, `ToolCostCalculatorService`, `ToolFeedbackService`, `AgentExperienceService`, `StrategyLearningService` | Medium |
| **System health** | `SystemHealthService`, `QueueHealthReportCommand`, `/api/admin/health`, `/api/admin/orchestration/health` | Medium |

## 6.2 Must be built or extended first

| Gap | Why it blocks Engineering888 |
|---|---|
| **Permissions** | No roles, no policies, no gates. An engineering role cannot be expressed today — only "platform admin or not" |
| **Events** | No `app/Events`/`Listeners`/`Observers`. Engineering telemetry would have to be invoked explicitly at every call site, repeating the inconsistency seen in audit coverage |
| **Reports** | No reporting subsystem; `generate_report` exists only inside Bella |
| **Engineering DB** | No tables; would need new migrations (schema is healthy and additive migrations are routine — 210 already applied) |
| **Provider-error alerting** | Does not exist; task failures never reach `failed_jobs` (F-Q2) |

## 6.3 Direct answer

**Engineering888 can reuse roughly 70% of the platform's operational substrate** — tasks,
approvals, audit, queues, scheduler, Redis, runtime/AI routing, notifications, memory, meetings,
and the engine kernel are all production-proven. **The two genuine blockers are permissions and
events.** Everything else is additive.

---

# 7. RISK REGISTER

| ID | Risk | Sev | Likelihood | Evidence |
|---|---|---|---|---|
| R-01 | Bella's LLM-driven dispatcher executes credit/suspension mutations without human confirmation | **Critical** | **Low today** (never executed) — High if enabled | §4.5 B-1 |
| R-02 | Repairing `DB::select(DB::raw())` silently arms unrestricted database reads incl. password hashes, MFA secrets, API keys | **Critical** | Medium (looks like a trivial bug fix) | §4.5 B-3 |
| R-03 | `BELLA_ADMIN_TOKEN` grants platform-owner authority: no MFA, no expiry, no individual attribution | **High** | Medium | §3.2 F-S1 |
| R-04 | Provider/task failures never reach `failed_jobs` → outages invisible to queue monitoring | **High** | **Occurred** (42 h, INC-2026-001) | §3.6 F-Q2 |
| R-05 | No RBAC — admin access is all-or-nothing; cannot delegate safely | **High** | Certain | §3.3 F-Z1 |
| R-06 | Indirect prompt injection via log lines injected into Bella's system prompt | **High** | Low today | §4.5 B-2 |
| R-07 | MFA governance in BOOTSTRAP (0 enrolled users) → step-up stands down platform-wide | **High** | Certain | §3.3 F-Z3 |
| R-08 | 103 admin routes lack `DenyApiKeyAuth`, incl. all Bella routes | **Medium** | Certain | §3.4 |
| R-09 | Cost/margin figures wrong — V3 pricing, provider-keyed, fallbacks mis-attributed | **Medium** | Certain | §3.7 F-R1 |
| R-10 | `.env.bak-gsc-creds-20260612` holds credential material on disk | **Medium** | Low (not web-reachable) | §3.2 F-S3 |
| R-11 | 213 failed + 63 blocked tasks aging since 2026-05-08, no replay path | **Medium** | Certain | §3.5 F-D4 |
| R-12 | `routes/api.php` at 19,363 lines with 28 inline admin closures | **Medium** | Certain | §3.8 |
| R-13 | `traffic_logs` 298k rows / 2 indexes | **Medium** | Medium | §3.5 F-D2 |
| R-14 | Zero admin/Bella test coverage | **Medium** | Certain | §3.8 |
| R-15 | 508 `.bak` files (81 MB) obscure search and duplicate git's role | **Low** | Certain | §3.8 |
| R-16 | `/api/admin/auth` lacks a dedicated throttle unlike `/api/auth/login` | **Low** | Low | §3.2 F-S2 |
| R-17 | No lockfile / no git repo for the runtime | **Low** | Certain | baseline §1.6 |

---

# 8. TECHNICAL DEBT REGISTER

### CRITICAL

**TD-A1 — Bella action dispatcher has no human-in-the-loop.**
*Impact:* financial mutation and customer suspension driven by model output.
*Risk:* Critical (latent). *Priority:* before Bella is enabled — not before.
*Complexity:* **Medium** — `ApprovalService` already exists and is exercised (125 rows); routing
Bella's mutating actions through it is integration, not invention.

**TD-A2 — `query_database` is a denylist over unrestricted SQL, currently inert by accident.**
*Impact:* full-database read incl. `users.password`, `mfa_secret_encrypted`, `api_keys.key`.
*Risk:* Critical if repaired. *Priority:* decide intent **before** anyone touches that line.
*Complexity:* Low to remove; Medium to make safe (table/column allowlist + tenant scoping).

**TD-04 — No provider-error alerting; failures bypass `failed_jobs`.** (carried, INC-2026-001)
*Impact:* 42 h undetected outage already occurred. *Complexity:* Low-Medium.

### HIGH

**TD-B1 — No RBAC.** No gates, no policies, no role/permission tables. *Blocks Engineering888
permissions.* *Complexity:* High (cross-cutting, 978 routes).

**TD-B2 — Shared admin token grants platform-owner authority without attribution or MFA.**
*Complexity:* Medium — provenance tagging already exists; extend `DenyApiKeyAuth` coverage and
enrol MFA.

**TD-B3 — MFA enrolled on 0 users; step-up covers 1 of 978 routes.**
*Complexity:* Low to enrol (machinery is built and correct); Medium to widen coverage.

**TD-01 / TD-03 — Provider attribution + model-keyed pricing.** (carried) *Complexity:* Low.

**TD-02 — V4 pricing unverified.** (carried) *Complexity:* Trivial once rates are known.

**TD-10 — 213 failed + 63 blocked tasks unreplayed.** *Complexity:* Low-Medium; needs a policy
decision first.

### MEDIUM

**TD-C1 — `routes/api.php` monolith (19,363 lines, 28 admin closures).** *Complexity:* High.
**TD-C2 — Zero admin/Bella test coverage.** *Complexity:* Medium.
**TD-C3 — `traffic_logs` index coverage.** *Complexity:* Low.
**TD-C4 — 103 admin routes without `DenyApiKeyAuth`.** *Complexity:* Low.
**TD-C5 — `.env.bak-gsc-creds-*` credential artefact.** *Complexity:* Trivial.
**TD-C6 — No event system.** *Complexity:* Medium; blocks clean Engineering888 telemetry.
**TD-05/06/07/12 — runtime streaming, vision, missing module.** (carried)

### LOW

**TD-D1 — 508 `.bak` files / 81 MB.** *Complexity:* Trivial (archive), Low (change convention).
**TD-D2 — `config/llm.php` names a retired model.** *Complexity:* Trivial.
**TD-D3 — `/api/admin/auth` throttle.** *Complexity:* Trivial.
**TD-11 — version drift.** (carried)

### FUTURE
Engineering888 permissions · Engineering888 events/telemetry · reporting subsystem ·
Bella front-end and enablement decision · Studio Phase M.

---

# 9. SUBSYSTEM SCORING

Each score is evidence-anchored. 1 = absent/broken · 10 = enterprise-grade.

| Subsystem | Score | Evidence |
|---|---|---|
| **Architecture** | **6.5** | + Clean engine/kernel separation (17 engines, `EngineKernel`, `LaunchScopePolicy` enforced at 6 layers); + correct orchestrator/executor split. − 19,363-line route file; − no event system; − 28 admin closures |
| **Security** | **5.0** | + `levelup-deny-artifacts.conf` (verified 404s); + `DenyApiKeyAuth` with explicit threat reasoning; + `RequireMfaStepUp` fail-closed state machine; + 115 FKs, CSP headers, TrustProxies. − shared static admin token → user 1; − 0 MFA enrolments; − Bella dispatcher; − credential in `.env.bak` |
| **Authorization** | **3.5** | − 0 Gates, 0 Policies, 0 role/permission tables; − single boolean admin model; − 103 admin routes unhardened. + consistent middleware application; + `is_platform_admin` never user-writable |
| **Maintainability** | **4.0** | − `routes/api.php` 1,096 KB; − 12 files > 70 KB; − 508 `.bak`/81 MB, 347 in July alone; − 23 backups of one file. + consistent naming; + heavy inline documentation of intent |
| **Scalability** | **6.0** | + 4-priority queues, 3 workers, Redis, idempotency keys, `minInstances=2` runtime. − 1.9 GiB RAM at 959 MiB used; − disk 74%; − `traffic_logs` 298k/2 indexes; − single MySQL instance |
| **Observability** | **5.0** | + `audit_logs` 7,557 with 20+ action types; + Sentry configured; + 4 health endpoints; + `QueueHealthReportCommand`. − failures bypass `failed_jobs`; − no provider-error alerting; − single undated `laravel.log` (6 MB) |
| **Extensibility** | **7.0** | + Engine manifest/registry/capability-map pattern; + `tier` parameter already exposed for future workloads; + additive migrations routine (210). − no events; − no permission model to extend |
| **Documentation** | **7.5** | + Exceptional inline docblocks explaining *why* (`DenyApiKeyAuth`, `RequireMfaStepUp`, nginx snippet); + `BOSS888-STATE.md`, incident register, architecture doc, baseline. − stale `config/llm.php`; − MASTERCONTEXT required correction |
| **Operational maturity** | **6.0** | + 29 scheduled commands verified firing; + 14 GB backups incl. nightly SQL + DO snapshots; + Supervisor autorestart; + 0 failed jobs. − 42 h undetected outage; − 213 aging failed tasks; − runtime deploy requires manual Boss action |

## FINAL ENTERPRISE READINESS SCORE

# **5.4 / 10**

**Interpretation:** A capable, honestly-built product with genuinely excellent isolated security
engineering, operating reliably in production. It is **not yet enterprise-governable**: the
authorization model cannot express delegation, the admin surface is untested, one route file
carries the application, and a dormant autonomous agent with financial powers sits behind a shared
static token.

**The gap is governance, not capability.**

---

# 10. PRIORITIZED RECOMMENDATIONS

> Recommendations only. **Nothing was changed.**

### Decide before doing anything (no code)
1. **Bella: enable, gate, or retire?** It is complete, reachable, and never used. Leaving it in
   its current state is the only option that carries risk without benefit.
2. **`query_database`: intended capability or accident?** Its inertness is a Laravel 11 side
   effect. Record the intent so nobody "fixes" it unaware.
3. **Replay policy** for 213 failed / 63 blocked tasks.

### P0 — before Engineering888 begins
4. Route Bella's `adjust_credits` / `suspend_user` through the existing `ApprovalService`, or
   disable them. (TD-A1)
5. Add `DenyApiKeyAuth` to the 103 unhardened admin routes — the codebase's own reasoning already
   demands it. (TD-C4)
6. Provider-error alerting that watches `tasks.error_text` / `task_events`, not `failed_jobs`. (TD-04)
7. Enrol MFA for both platform admins so governance leaves BOOTSTRAP and the fail-closed machine
   becomes live. (TD-B3)

### P1 — foundation for Engineering888
8. Introduce a permission model (gates or a role table). This is the **single largest blocker** to
   an Engineering888 role. (TD-B1)
9. Introduce events/listeners so telemetry is not per-call-site. (TD-C6)
10. Fix provider attribution + model-keyed pricing; obtain V4 rates. (TD-01/02/03)
11. Admin + Bella test coverage — currently zero. (TD-C2)

### P2 — maintainability
12. Extract the 28 admin closures from `routes/api.php`; split by domain. (TD-C1)
13. Archive the 508 `.bak` files to `/root/backups/` and rely on git going forward. (TD-D1)
14. Index review on `traffic_logs`. (TD-C3)
15. Remove `.env.bak-gsc-creds-20260612`; rotate anything it contains. (TD-C5)

### Preserve — do not regress
- `levelup-deny-artifacts.conf` — verified effective, well-reasoned.
- `DenyApiKeyAuth` and `RequireMfaStepUp` — the strongest security thinking in the codebase.
- `LaunchScopePolicy` six-layer enforcement.
- The `.bak`-before-edit discipline (change the storage location, keep the caution).

---

**AUDIT COMPLETE — 2026-07-26**
Commit `416686e2fb75cca4c0a245a0d60f230ced178429` · branch `feature/studio888-mrc-2a`
No code, configuration, database, or service was modified.
