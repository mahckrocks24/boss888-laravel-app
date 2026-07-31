# BOSS888 STATE

## PHASE LEDGER — as at 2026-07-27

| Phase | Status |
|---|---|
| P1 · P2-A · P2-B · P2-C | COMPLETE |
| CR-22 (A+B) | COMPLETE |
| ENTERPRISE888 E0 | COMPLETE |
| ENTERPRISE888 E0.5 | COMPLETE |
| ENTERPRISE888 E0.6 TECHNICAL | COMPLETE |
| **ENTERPRISE888 E0.6 OPERATIONAL** | **DEFERRED (Development Exception EX-2026-001)** |
| ENTERPRISE888 E1 BLUEPRINT | APPROVED 2026-07-27 |
| ENTERPRISE888 E1 KICKOFF | APPROVED |
| ENTERPRISE888 E1 — M0 Foundation | COMPLETE |
| **ENTERPRISE888 E1 — M1 Overview + Health** | **COMPLETE 2026-07-27** |
| ENTERPRISE888 E1 — M2..M6 | NOT STARTED |

### ⚠️ EX-2026-001 — MFA gate deferred (temporary project exception)
Administrator MFA enrolment + the 20-point verification are **deferred** by
Platform Owner decision to prioritise implementation velocity.

Safe for E1 because E1 is **read-only**, adds no privileged surface, and depends
on neither MFA nor governance enforcement.

**Binding boundaries (unchanged):** governance stays `bootstrap`/`shadow`; no
enforcement may activate; Bella stays admin-only and MFA-closed; no production
security control is weakened; the exception covers the IMPLEMENTATION gate only,
**not** the production readiness gate.

**Binding engineering deadline: before E5** — earlier than "before production
launch". E5 is the first phase where an agent writes to production, and an agent
mutating protected paths under an advisory lock (TD-14) with no MFA-verified
approver is the combination that carries real risk. E1–E4 do not.

**Side effect to be aware of:** Bella is currently inaccessible to *everyone*
(0 of 2 admins enrolled). That is the fail-closed gate working correctly — but
using Bella during development requires enrolment.

**EX-2026-001 remains OPEN until enrolment and verification complete.**



### ⏳ WAITING ON ONE HUMAN STEP
Administrator id 1 (Mark) must complete MFA enrolment with a live authenticator.
`TotpMfaService::confirm()` verifies a real TOTP code — it cannot be done
server-side. Runbook: `ADMIN-MFA-ENROLLMENT-RUNBOOK.md`.

**Nothing has been enrolled, simulated or fabricated.** Per-administrator status:

| id | status |
|---|---|
| 1 (Mark) | **not enrolled** |
| 990016 | **not enrolled** — validation identity, should stay that way (TD-21) |

When Mark confirms, a 20-point verification runs against authoritative production
state before E0.6 is closed.

Enrolling Mark does **NOT** activate governance (eligible count 0→1, bar ≥2).

### E1 BLUEPRINT DELIVERED (design only — no code)
`ENTERPRISE888-E1-ARCHITECTURE-BLUEPRINT.md` — the permanent E1 specification.
15 sections, 6 ADRs, truthfulness rules, 15 screen specs, TD re-evaluation,
12 risks, 6 milestones, success + anti-criteria.

**Three findings became architectural constraints:**
1. `tasks` (2,436) is the CUSTOMER marketing engine — the screen is renamed
   **Marketing Tasks**; engineering tasks are a separate model (ADR-002).
2. Incidents have **THREE** sources, not two: `infra_incidents` (3 — all PTAA
   monitor events about a *co-tenanted client app*), the markdown register
   (5 real platform incidents), and `engineering_incidents` (0, E3). Merging
   them would mislead in both directions (ADR-003).
3. **7 of 15 screens have no data.** They ship as explicit not-built states with
   why/phase/expected-evidence — never empty shells (§8).

**ADR-001 reverses my earlier advice:** correlation-ID columns should land at the
start of **E3**, not E1 — there is no functional need until something correlates,
and E3 is the last responsible moment before backfill becomes impossible.

**No implementation begins until the blueprint is formally approved.**

### E1 PLAN READY (planning only — no code)
`ENTERPRISE888-E1-IMPLEMENTATION-PLAN.md`.

**Headline: 6 of the 14 requested screens have no data to show.** Runs, Workers,
Reports, Deployments, Rollbacks have no model until E3/E5/E6; Providers has 0
health rows. The plan builds **8 real screens** and marks the other 6 explicitly
not-built rather than shipping empty shells — a hollow dashboard teaches you to
distrust the whole section.

Also flagged: `tasks` (2,436) is the CUSTOMER task engine, not engineering work,
and must be labelled so; and one open question — whether to include an additive
`audit_logs` correlation-column migration in E1 (recommended, but your call).


## 2026-07-27 — ENTERPRISE888 E0.6 · TECHNICAL COMPLETE · MFA PENDING

**E0.6 TECHNICAL: COMPLETE · E0.6 OPERATIONAL: PENDING HUMAN MFA ENROLMENT**

### TD-19 CLOSED — all active route source is protected
Protected patterns 5 → 8, files 16 → 19. Added `routes/web.php`,
`routes/exec-api.php`, and — found by inspecting the real loading path rather
than the two named files — `app/Engines/CRM/Http/Routes.php`.
New `ProtectedPaths::unmanagedBackups()` detector. 0 unmanaged backups, 0 drift.

### ⚠️ CORRECTION to the E0.5 warning
I said enrolling the second admin would "activate governance platform-wide".
**Both halves were wrong:**
1. `mfa.stepup` is on **ONE** route —
   `POST api/admin/infrastructure/providers/approvals/{aid}/approve`.
2. Admin `id 990016` is `validation_only` **and** the hard-coded validation
   identity, so it is excluded from eligibility **regardless of MFA**.

**Enrolling Mark takes the eligible count 0 → 1. The bar is ≥ 2. Governance does
NOT activate.** Only one enrolment is needed and it is low-risk.

### MFA status (per administrator)
| id | status |
|---|---|
| 1 (Mark) | **not enrolled** — enrol via `ADMIN-MFA-ENROLLMENT-RUNBOOK.md` |
| 990016 | **not enrolled** — and should stay that way (validation identity) |

Bella remains **safely closed** until Mark enrols.

### New tech debt
**TD-20** `app/Engines/CRM/Http/Routes.php` executes every boot but contributes
0 live routes — `crm-01.php` shadows it. A latent trap; now protected.
**TD-21** `id 990016` holds `is_platform_admin` but can never be
governance-eligible; the roster over-reports.

### Verified unchanged
982 routes · signature `4ed42286…` · `routes/web.php` `85adfa71…` with the
concurrent session's Cache-Control change intact · credits 80664.00 ·
4,335 transactions · 11 users, 0 suspended · health 200 · workers 3/3 ·
governance `shadow` / state `bootstrap`.

Tests: Security **67/207** · Routes **51/1,314** · Governance **18/59**.

**E1 has not started.**


## 2026-07-27 — ENTERPRISE888 E0.5 COMPLETE (capability removal + MFA gate)

**Bella can no longer mutate billing or user access. Bella is closed to everyone
until an administrator completes MFA enrolment.**

| | |
|---|---|
| Bella actions | **11 → 9** (6 read, 1 report, 2 write only to `bella_memory`) |
| `adjust_credits` / `suspend_user` | **REMOVED** — prompt, registry, dispatcher, handlers, `PermissionRegistry` |
| Alias variants blocked | **29**, behind an authoritative backend gate |
| Mode dependence | **none** — the capability is gone, not gated |
| Bella routes | 4, now behind **`mfa.enrolled`** (fail-closed) |
| Admin MFA enrolment | **0 of 2 — AWAITING INTERACTIVE COMPLETION** |
| Credits / users | 80664.00, 4,335 tx, 11 users, **0 suspended** — all unchanged |
| Routes | 982 · signature `c46d8a07…` → **`4ed42286…`** (4 Bella lines, middleware-only, justified) |
| Tests | Security 42 · Routes 51 · Chat 139 · Governance 18 · Studio 132 · Infra 352 |

### ⚠️ ACTION REQUIRED — MFA enrolment is yours to complete
`TotpMfaService::confirm()` needs a live code from your authenticator; I could not
and did not complete it. Staged instructions in `ADMIN-MFA-ENFORCEMENT-REPORT.md` §5.
**Note:** enrolling the SECOND admin activates governance platform-wide
(`maybeActivateGovernance()` fires at two eligible admins) — expect step-up
prompts elsewhere. Do it deliberately.

### Key discovery
`mfa.stepup` would **not** have protected Bella. It stands down while governance
is un-activated, and activation needs two MFA-enrolled admins — of which there
were zero. A dedicated fail-closed gate (`RequireEnrolledMfa`) was required.

### New tech debt
**TD-19** — `routes/web.php` and `routes/exec-api.php` are not protected paths.
A concurrent session left an unmanaged `.bak` there on 2026-07-27 (second
occurrence of the INC-2026-004 pattern). Quarantined. One-line fix available.

Docs: `/root/handoff-2026-07-27-e0/` — start at
`ENTERPRISE888-E0.5-IMPLEMENTATION-REPORT.md`.

**E1 has not started.**


## 2026-07-27 — ENTERPRISE888 E0 COMPLETE (audit + masterplan only)

**Read-only phase. Nothing built, nothing enabled, production unchanged.**

CR-22 is closed (CR-22A + CR-22B complete). The next initiative, ENTERPRISE888
E0, delivered an 18-document forensic audit and enterprise masterplan for Bella,
Engineer888 and the engineering control plane.

### Three findings that matter

1. **Evidence scores 0/10.** Nothing on this platform can substantiate a
   completion claim. Given the history of confident false success (CR-03
   fabricated reply, Sarah fabricated-success, runtime tool stubs, and CR-22B's
   gates passing green during an outage), this is the central gap.

2. **Bella is a reachable prototype with unguarded privileged actions.**
   `adjust_credits` and `suspend_user` write to billing and user access with **no
   approval, no governance, no MFA**, dispatched by regex over model output, with
   customer-influenceable log content in the prompt. She has **never been used**
   (0 conversations, 0 memory, 0 audit rows) — that is the only reason nothing
   has happened. Her docblock claims "DORMANT"; there is no dormancy gate.

3. **Governance records but does not enforce.** `GOVERNANCE_MODE=shadow` →
   `effective=true` regardless of decision. 73 permissions + 99 approval policies
   are authored; the switch is off. **Both platform admins have MFA disabled.**

### Verified reusable assets
`tasks` (2,424) + `TaskStateMachine` · `ExecutionPlanService` dependency graphs ·
`approvals` (127, real) · `PermissionRegistry`/`ApprovalPolicyRegistry` ·
the entire CR-22 safety layer · `RequireMfaStepUp` (exists, unused).

### Verified absences
Engineer888 (no code under any name) · evidence model · run/worker separation ·
tool permissions · deployment records · scheduled database backup ·
companion-app admin surface · Filament (the admin is a bespoke SPA).

### Readiness: 2.85 / 10 now → 8.75 target. Evidence and Mobile at 0.

### ⚠️ IMMEDIATE RECOMMENDATION — E0.5, awaiting approval
1. Remove `adjust_credits` and `suspend_user` from Bella's `allowedActions()`.
2. Enrol both platform admins in MFA; apply `mfa.stepup` to Bella's routes.

Deletion and configuration only. Closes the only live exposure; every later phase
assumes MFA step-up is possible, and today it is not.

### New tech debt
TD-17 vestigial `DeepSeekConnector` (0 invocations, live key) ·
TD-18 recurring `payload` column insert failure in the write path.

Docs: `/root/handoff-2026-07-27-e0/` (18) — masterplan is
`BELLA-ENGINEER888-ENTERPRISE-MASTERPLAN.md`; start at
`ENTERPRISE888-E0-FORENSIC-AUDIT-REPORT.md`.

**Bella and Engineer888 are NOT implemented. E1 has not started.**


## 2026-07-27 — CR-22B COMPLETE · MODULAR ROUTE STRUCTURE LIVE

**CR-22A complete · CR-22B complete · CR-22 overall COMPLETE**
(deployed to production, rollback certified)

`routes/api.php` **19,595 → 7,991 lines**. BLOCK17 (13,362 lines, 68% of the
file, all four INC-2026-003 collision points) is now **13 owned modules** at
`routes/api/authenticated/`.

| | |
|---|---|
| routes | **982** (unchanged) |
| ordered signature | **c46d8a07…** (unchanged, zero differing lines, no waiver) |
| byte-identical reconstruction | **yes** — modules rebuild the pre-extraction file exactly |
| routes tests | 39 / 1,280 assertions |
| chat tests | 139 / 561 assertions |
| health / workers | 200 / 3-of-3 |
| stray backups in routes/ | 0 |

**Two defects caught by gates, not inspection:**
1. indentation-derived boundaries cut through a JavaScript heredoc → caught by
   `php -l`; boundaries now come from PHP's lexer (172 children, not 352)
2. `use` aliases do not cross a `require`, **silently** — 8 routes registered
   against nonexistent controllers; caught only by the ordered-signature gate;
   every module now re-declares all 11 parent imports

**Rollback fired for real** during the first activation (a wrong `array_rand === 0`
assertion) and restored production automatically. Drill certified both directions.

### Ownership is now ENFORCED (TD-14 closed to a narrower gap)

CR-22B originally shipped the lock as advisory and logged the enforcement gap as
TD-14. The brief mandated enforcement, so it is built:

- `ProtectedPaths` — 16 protected files (routes/api.php, routes/api/*,
  bootstrap/app.php, the ownership registry), sealed with expected hashes
- `GovernedWriter` — the 8 required steps, all fail-closed. The decisive one is
  **re-hashing immediately before the write**: if the file moved since
  acquisition the write is REFUSED, which is exactly the INC-2026-003 window
- `GovernedRestore` — additionally requires a manifest, a non-stale revision, a
  diff preview and post-restore equivalence, and **undoes itself** if routing
  comes out wrong
- `ProtectedPaths::drift()` — an ungoverned write cannot be prevented, but it is
  now DETECTED and must be recorded as an operational-policy violation
- `storage/app/source-locks/route-ownership.json` — module → owner registry,
  itself a protected path
- `tests/Feature/Routes/RouteOwnershipEnforcementTest.php` — 12 tests covering
  all eight brief-mandated concurrency/restore properties

**TD-14 is now narrowed** to: enforcement is tooling-level, not filesystem-level.
A session that never calls the tooling still writes — but no longer silently.

### Full battery (2026-07-27)

routes+ownership 51/1,314 · chat 139/561 · Studio 132/557 ·
Infrastructure 352/1,274 · Governance 25/196 · Domains 16/49 · Queue 1/1 ·
functional smoke 20/20 against live production.

Equivalence facts, counted with the lexer not grep: `array_rand` **call sites 0**
(the single occurrence is a comment documenting the removed CR-03 defect),
Phase O 2, Phase P 1, all P1→P2-C fixes present.

### Working rules added
- route source spans **14 files** — read `routes/api.php` +
  `routes/api/authenticated/*.php` (tests: `Tests\Support\RouteSource::all()`)
- never derive PHP structure from indentation in this file (heredoc JavaScript)
- never hand-restore `/root/cr22b-legacy/api.php.pre-extraction`; use
  `php /root/cr22b-legacy/rollback.php`

### ⚠️ OPEN — TD-14
`SourceOwnershipLock` is **advisory** and was violated again on 2026-07-27 (a
concurrent session wrote an unmanaged `.bak` into `routes/`, quarantined as
evidence). Nothing stops a session that never calls it. Highest-value remaining
infrastructure item.

### TD-15
`seo-01` is 5,266 lines and **one statement** — a single
`Route::prefix('seo')->group()`. Under the 5,500 threshold but not well-factored.
Splitting needs the SEO route group restructured: product work, not an
infrastructure refactor.

Docs: `CR-22B-IMPLEMENTATION-REPORT.md`, `CR-22B-IMPACT-MATRIX.md`,
`CR-22B-ROLLBACK-REPORT.md`, `ROUTE-SCOPE-DEPENDENCY-MAP.md`,
`ROUTE-NESTED-BOUNDARY-PROOF.md`, `CR-22B-DOC-UPDATES.md`.


Handoff: `C:\Users\markr\LVL\handoff-2026-07-26\START-HERE-2026-07-26.md`
Reports: `CR-22-IMPLEMENTATION-REPORT.md` · `CR-22-ROUTE-MODULARIZATION-IMPACT-MATRIX.md`

---

## CR-22 — ROUTE OWNERSHIP AND MODULARIZATION (2026-07-27)

**Delivered: the safety controls that address INC-2026-003's root cause.
Held: the extraction itself, pending ONE decision from the Boss.**

**Production route files are byte-identical to baseline. Nothing was extracted.**

### ⚠️ THE DECISION REQUIRED

The brief says *"split only at proven top-level boundaries."* I measured before extracting:

| | |
|---|---|
| Top-level `Route::` statements | 56 — **all 56 parse standalone (0 failures)** |
| **BLOCK17** | **13,362 lines = 68% of the file**, 165 children, every business domain |
| INC-2026-003 collision points inside BLOCK17 | **4 of 4** (Phase O, Phase P, agent persist, Studio CR-03) |

**A top-level-only split would NOT have prevented the incident.** Phase P's `creative` route and my Studio/agent fixes land in the same 13,362-line module.

- **Option A** (as instructed): ~15 modules, one of 13,362 lines. Safe, honest, changes nothing about the risk.
- **Option B** (recommended): also split BLOCK17's 165 children, `require`d **inside** the parent closure. ~30 modules, largest ~5,272 (seo). Needs "top-level" relaxed to "proven, independently-parseable".

**Enabling fact verified on the host, not assumed:** PHP `require` inside a closure inherits the enclosing variable scope, so all **110** `use ($var)` captures survive. Middleware and order are preserved because each require sits in its original position.

I did not extract: Option A spends the ownership window on something that doesn't help; Option B exceeds an explicit instruction.

### DELIVERED (boundary-independent, all tested)

| Control | Effect |
|---|---|
| **`SourceOwnershipLock`** | fail-closed ownership. **No lock = refused.** Scope, owner, phase, acquisition hashes, heartbeat, stale takeover (audited), release reporting `changed_paths` |
| **`ManifestBackup`** | **a backup without a manifest is not restorable**; a manifest predating current source is refused (`SOURCE_HAS_ADVANCED`); wholesale restores refused (`WOULD_TOUCH_UNRELATED`) |
| **Route-equivalence gate** | 982 routes, **ordered** signature `c46d8a07…`, one assertion per route. Order is behaviour; differences are never waived |
| **Legacy backup quarantine** | **63 of 64 `.bak` files moved out of `routes/`** |
| **Ownership registry** | 18 proposed modules, owners, approvals, high-risk routes, restore procedure |

**Tests: 22 / 1,034 assertions passing** — including all 8 required concurrency scenarios **and a full INC-2026-003 replay that now fails closed.**

### ⚠️ STRIKING FINDING — 61 OF 64 BACKUPS WERE UNSAFE
Every one of those 61 `.bak` files, if restored, would have **reintroduced the `array_rand` fabricated-reply defect**. They were sitting beside the live source where any session could mistake one for a valid restore point. That is the size of the trap that caused INC-2026-003. Now quarantined at `/root/route-backup-quarantine-20260727/` with a full classification manifest; the incident evidence (`api.php.bak-20260727-phaseP2`) is preserved in place.

### AUTHORITATIVE BASELINE — all 13 checks pass
P1 CR-18 (2) · P1 CR-01 (2) · P2-A CR-03 (1) · P2-A C4 (1) · P2-A C5 (1) · P2-B gate (1) · P2-B runner (1) · P2-C chatbot (2) · Phase O (2) · Phase P (1) · array_rand (0) · machine copy (0) · routes (982).
Stored outside the working tree at `/root/cr22-baseline-20260727/`.

### A CORRECTION WORTH KEEPING
My first equivalence run compared a `route:list --json` baseline against a `Route::getRoutes()` signature — they render middleware differently, so every route "differed". Waiving that as serialization noise would have made the gate worthless from its first run. Both sides now use **one** generator, so any surviving difference is real.

### Production
`routes/api.php` sha256 **9aa519a1445f8e26** (= baseline) · routes **982** · Phase O **2** · Phase P **1** · health **200** · workers **3/3** · chat suite **139/564** · route suite **22/1,034**.

### Honest limitation
The lock is **advisory**. A session that never calls `acquire()`/`assertOwned()` can still write. It stops a careful session, not a careless one — enforcement belongs in tooling, and every patch script should call `assertOwned()` first.

### Next
1. **Decide the module boundary** (impact matrix §0). Until then the monolith remains the largest operational risk.
2. Then chat roadmap: Aria classification → Studio billing (CR-23) → E-1 (refused message still discarded on s4 and s8) → agent surfaces.

---


Handoff: `C:\Users\markr\LVL\handoff-2026-07-26\START-HERE-2026-07-26.md`
Contract: `CHAT-CONTRACT-v1.md` — **STILL FROZEN at 1.0**
Reports: `CHAT-P2-C-IMPLEMENTATION-REPORT.md` · `CHAT-P2-C-DELTA-REPORT.md`

---

## CHAT P2-C — COMPLETE (2026-07-27). P2-D / broader migration NOT STARTED.

| | P2-B final | **P2-C final** |
|---|---|---|
| Pass / Fail | 215 / 195 | **217 / 193** |
| **Critical** | 43 | **41** |
| **Regressions** | — | **0** |
| Chat suite | 105 / 344 | **139 tests / 564 assertions** |

**Both P2-C targets closed:** `s8 persist.no_duplicate_on_safe_retry` and `s8 credits.no_double_charge_on_retry`. s8 criticals **4 → 2** (remaining X-09 and G-03 were excluded before work began — a message-schema change and a frontend change).

### 1. Chatbot adopted the P2-B primitive
`ChatbotResponseService` wraps its existing pipeline in `ChatExecutionCoordinator` — no new services, no second idempotency implementation, no second charge ledger. The gate sits **before** the quota gate and reservation, so a duplicate never reaches the pipeline. **`routes/api.php` was not edited for this** — the chatbot route points at a controller.
When the widget sends no key, a **canonical server-side key** `cb:sha256(session|message|60s bucket)` is derived, so the guarantee holds without a frontend change.

⚠️ **Correction to the P2-C impact matrix:** it predicted the coordinator would own the chatbot's charge. Wrong — that billed one request **twice** (2.0 credits), caught by the integration test. Charging is now **delegated**: the pipeline keeps its correct reserve/commit/release; the coordinator records linkage only. Double-charging is still prevented by the gate refusing to re-run the pipeline.

### 2. GENUINE process-parallel certification
`ProcessParallelTest` — **10 tests, 168 assertions**, independent `proc_open` processes on a shared timestamp barrier (P2-B's evidence was interleaved, not parallel).
```
10 independent OS processes, same key, simultaneous
   → 1 provider execution · 1 user message · 1 final message
   → 1 committed charge (balance −1, not −10) · 1 correlation id
```
0 deadlocks · 0 orphan charges · 0 stuck records · every reservation settled.
Limits stated: single host, 10 not 100 processes, simulated rather than SIGKILL-ed worker death, stubbed provider.

### 3. Environment safety — INC-2026-002 closed
```
BEFORE:  php artisan migrate --env=testing  →  levelup_staging   (production)
AFTER:   APP_ENV=testing                    →  levelup_chat_test
```
`.env.testing` created (its absence *was* the defect) · `ProductionDatabaseGuard` fails closed on **deny-then-allow** (a denylist alone cannot know tomorrow's production DB) · dedicated **`levelup_tester`** MySQL user **proven unable to read production** · `EnvironmentSafetyTest` 11 tests.
**Run the chat suite as:** `php artisan test -c phpunit.chat.xml tests/Feature/Chat`

### 4. ⚠️ INC-2026-003 — A CONCURRENT SESSION REVERTED THREE PHASES IN PRODUCTION
At 04:53 a **STUDIO888 Phase P** session rewrote `routes/api.php` from a backup predating P1/P2-A/P2-B. It added its own route and reverted **every** route-level fix of three phases — including **CR-03, so `array_rand($palettes)` fabricated replies were live in production again**, plus CR-01 machine copy, CR-18 silent catch, all structured errors, SEO persist-on-refusal and the P2-B idempotency gate.

**Detected by the conformance suite** (11 regressions; the F-06 detector fired on the restored `array_rand`) — exactly what the harness exists for.
**Recovered** by re-applying all seven edits **on top of their file**, never restoring a whole file, with per-edit match assertions. Verified: Phase O 2→2, Phase P 1→1 and its route registered, routes 982→982, fabrication 0, all markers restored, **zero regressions**. **No Phase P work was lost.**

**CR-22's file half remains OPEN and is now a prerequisite, not a recommendation.** Required convention: one session owns `routes/api.php` at a time; a backup must be taken from the **current** file immediately before writing, never from an older snapshot; patch on top rather than restore; verify other workstreams' markers after every write.

> A backup taken at the wrong moment is not a safety net. Phase P *did* take a backup — of a file that no longer reflected production.

### Freezes held
Contract **1.0** (every emitted code already in the v1 taxonomy; no v2 directory) · Studio **uncharged**, dead guard intact · Aria **ephemeral**, no store · `routes/api.php` **not decomposed** · all 15 P2-B invariants revalidated **through the chatbot integration**, 15/15 pass.

### Production
health **200** · routes **982** (981 + Phase P's `api/creative/resolve-asset`) · Phase O **2** · Phase P **1** · workers **3/3** · `chatbot_messages` **197** · `seo_assistant_messages` **105** · credit rows **16** · reconciliation: 0 orphan charges, 0 mismatches.

### Next (recommended)
1. **Resolve CR-22** before any further shared-file work — it has now caused a real production regression.
2. Decide **Aria** classification (7 criticals; we bill for a conversation the customer cannot retrieve).
3. Decide **Studio** billing (CR-23) or record that it stays free.
4. **E-1 on s4 and s8** — a refused message is still discarded on both.
5. **Agent surfaces** (17 criticals, 597 msgs/7d) — own phase, higher-concurrency certification first.

---


Handoff: `C:\Users\markr\LVL\handoff-2026-07-26\START-HERE-2026-07-26.md`
Contract: `CHAT-CONTRACT-v1.md` — **STILL FROZEN at 1.0, no bump was required**
Reports: `CHAT-P2-B-IMPLEMENTATION-REPORT.md` · `CHAT-P2-B-DELTA-REPORT.md`

---

## CHAT P2-B — COMPLETE (2026-07-27). P2-C NOT STARTED.

**The guarantee, measured:**
```
five identical requests → 1 provider execution · 1 user message · 1 final message
                        · 1 committed charge (balance −1, not −5) · 1 correlation id
```

| | P2-A | Harness-corrected | Production-final |
|---|---|---|---|
| Pass / Fail | 212 / 198 | 213 / 197 | **215 / 195** |
| **Critical** | 46 | 45 | **43** |
| **Regressions** | — | **0** | **0** |
| Chat tests | 39 | — | **105 tests / 344 assertions** |

Reported in two parts as required: **harness correction ≠ production improvement.**

### Delivered
- **`chat_idempotency_records`** — UNIQUE `(workspace_id, surface, idempotency_key)`. Acquisition **is** the INSERT, so the database decides who was first and correctness does not depend on timing. Deliberately **not** globally unique (`tasks.idempotency_key` is, and that pattern lets one workspace's key collide with another's).
- **`message_charges`** — UNIQUE `idempotency_record_id`; provides the message↔ledger join clause K-07 says does not exist. **The ledger stays authoritative; this holds no balance.**
- **`ChatIdempotencyService` · `MessageChargeService` · `ChatExecutionCoordinator` · `ChatIdempotencyGate`**
- **SEO assistant integrated** behind `CHAT_IDEMPOTENCY_SURFACES=s4_seo_assistant`; gate sits **before** the surface's meter, so a duplicate never re-meters, re-calls the provider, or re-persists.
- **Migrations additive only. Rollback drilled ON PRODUCTION: 499 ms down / 1,119 ms up, health 200, ledger identical.**

### Targeted criticals: **2 of 21 closed** (matrix ceiling was 5)
`s4 persist.no_duplicate_on_safe_retry` and `s4 credits.no_double_charge_on_retry`.
**Shortfall stated plainly:** s8 chatbot integration **deferred** (needs the gate inside `ChatbotResponseService`, a bigger change than the route wrapper — not attempted rather than attempted badly, worth 2); s5 Studio capability **not declared** (Studio is provably never charged, but K-06 is capability-driven and Studio has no gate — declaring it would be self-certification, worth 1).
**19 of the remaining 43 criticals are still this primitive**, now built and proven, awaiting surface integration.

### Two real bugs the tests caught
1. **`open()` was not idempotent per record** — a retry reclaims the same idempotency record, so a blind insert violated the unique index. **The constraint fired and forced the right design** (find-or-reopen; a committed charge is never reopened).
2. **A replayed refusal returned 409 instead of its original 402** — INV-10 requires a replay to return the existing result *unchanged*. The stored status is now replayed.

### Harness correction (reported separately)
`s4 / render.no_fabricated_replies` was a false positive: the old regex matched the **identifier** `extractKeywordFromReply()`, which parses a keyword *out of* a genuine reply. Replaced with an assignment-based detector, **proven to still catch the exact CR-03 fallback if reintroduced** (8 tests). One transition; criticals 46→45 — confirming the true count stated in P2-A.

### Frozen / untouched
- **Studio still charges nothing.** The dead `class_exists()` guard is deliberately preserved; repairing it would begin billing a free feature. → `STUDIO-BILLING-DECISION-NOTE.md` (5 options, recommendation: stay free pending a decision). **CR-23 open.**
- **Aria still ephemeral.** No store, no persistence, no durability claimed. → `ARIA-CONVERSATION-CLASSIFICATION.md` (recommends durable, **not urgently**; we already bill 0.1cr for a conversation the customer cannot retrieve, and the unread badge already promises durability). **Decision required.**
- Contract 1.0 · no message table altered · no ledger row rewritten · 1,707 messages / 442 unread / 16 credit rows unchanged.

### CR-22 ownership gate — all 10 steps evidenced
0 active runs · hash `243091c146bd` → recheck → `304f7bcb664f` · match counts asserted · Phase O **2→2** · routes **981→981**, **route set diff IDENTICAL** · `php -l` gate with auto-restore · ownership released after validation. **`routes/api.php` was NOT decomposed.**

### Disclosure
A rehearsal command used `--env=testing`; with no `.env.testing` present it resolved to production, so the two tables were created there earlier than the sequence intended. Empty, unreferenced, and deployment step 2 regardless — the production rollback drill was then run deliberately to verify. Stated because the ordering differed from the plan.

### Next (recommended)
1. Integrate **s8 chatbot** — finish the deferred step, worth 2 criticals, primitive ready.
2. Decide **Aria** classification. 3. Decide **Studio** billing. 4. Then the agent surfaces (9 criticals, primary customer path, own phase). 5. Genuinely parallel concurrency testing — current evidence is interleaved, not OS-parallel.

### Run the suite
```bash
php artisan test -c phpunit.chat.xml tests/Feature/Chat     # 105 tests, 344 assertions
```

---


Handoff: `C:\Users\markr\LVL\handoff-2026-07-26\START-HERE-2026-07-26.md`
Contract: `CHAT-CONTRACT-v1.md` — **FROZEN at `chat.contract.version = 1.0`**
Delta: `CHAT-P2A-DELTA-REPORT.md` · Proposal: `ROUTES-DECOMPOSITION-PROPOSAL.md`

---

## CHAT P2-A STABILIZATION SPRINT — COMPLETE (2026-07-26)

**Approved scope only.** P2-B **NOT STARTED**.

| | P1 end | P2-A end |
|---|---|---|
| Pass / Fail | 207 / 188 | **212 / 198** |
| Blocked | 62 | **47** |
| Conformance (all evaluated) | 52.4% | 51.7% |
| **Conformance (same measured set)** | 52.4% | **52.7%** |
| **Regressions** | — | **0** |
| Critical failures | 47 | **46** (45 real — one is a proven false positive) |
| Chat suite | 30 tests / 124 assertions | **39 tests / 141 assertions** |

**The headline % fell while the platform improved.** Enabling a probe P1 had wrongly marked unreachable turned **15 unmeasured cases into real measurements** (11 fails, 4 passes). On the set measured in *both* runs — the only fair comparison — conformance rose **52.4% → 52.7% with zero regressions**.

### Delivered
- **CR-03 CLOSED** — Studio's fabricated replies removed. It used to invent answers when the runtime was down: `"luxury"`/`"brand"` applied hard-coded palettes and `"color"` picked one with **`array_rand()`**, all returned `success:true`. Now returns **503 `CHAT_PROVIDER_UNAVAILABLE`** and leaves the design untouched. `s5` now **passes** clause F-06.
- **SEO persist-on-refusal CLOSED** — behaviourally proven: 402 returned **and** a `seo_assistant_messages` row exists, with `chat_error.persistence.user_message_saved = true`.
- **Structured errors** — `CHAT_VALIDATION_FAILED` (422) and `CHAT_PROVIDER_UNAVAILABLE` (503) on Studio, each with `retryable`/`provider_called`/`persistence`/`action`. Legacy `error` strings retained (clause S-03).
- **Silent failures eliminated** on the touched paths (clause O-04).

### ⚠️ THREE FINDINGS THAT CORRECT THE P1 AUDIT
1. **`App\Services\CreditService` DOES NOT EXIST.** `/api/studio/chat` guards its charge with `class_exists()` on that name — always false. **Studio chat has never charged anything.** The audit's *"three credit models across two CreditService classes"* was **wrong**: there is one CreditService. Left off deliberately — switching it on would start billing a free feature, which is a **pricing decision**. Logged **CR-23**.
2. **Aria (`/api/assistant`) is the Builder's assistant and is client-held by design** — `builder.js` keeps history in `bld_aiHistory` and replays 8 turns; the handler persists nothing. This also completes the CR-01 story end to end. **Persist-before-meter is impossible for Aria without a migration → BLOCKED, deferred to P2-B.**
3. **Aria is probably misclassified** (`ephemeral_tool`, not `durable_assistant`). **Deliberately not changed** — it would lift the score for reasons unrelated to the fixes and muddy the delta. P2-B product decision.

### ⚠️ KNOWN FALSE POSITIVE — STATED, NOT SILENTLY FIXED
`s4 / render.no_fabricated_replies` (critical) is a harness false positive: the regex matches `extractKeywordFromReply()`, a helper that *parses a keyword out of the model's reply* — the opposite of fabrication. No offline path exists in that service. **True critical count is 45, not 46.** The case was **not edited** — editing a conformance test right after a freeze, in the direction that improves the score, is exactly the move not to make quietly. P2-B harness fix.

### ⚠️ CR-22 ESCALATED — AND HALF FIXED
A concurrent STUDIO888 session ran `tests/Feature/Studio/` against the SAME `levelup_test` database
as the chat suite. Two `RefreshDatabase` runs on one database drop and recreate each other's tables:
20 spurious chat failures and a half-migrated DB (`workspaces` present, `migrations` gone).
**Production was never involved** (1,707 rows throughout, health 200). A reset was **refused by its
own guard** because their suite was still running.
**Fixed for chat:** `phpunit.chat.xml` forces `DB_DATABASE=levelup_chat_test`.
Run with: `php artisan test -c phpunit.chat.xml tests/Feature/Chat`
**Proof:** 39 passed WHILE two other test runs were active, and conformance is bit-for-bit identical
on both databases (212/198/47). The file-level half of CR-22 (`routes/api.php`) is **still open**.

### CONTRACT FROZEN
`chat.contract.version = 1.0`. No endpoint, payload, schema or response contract may change unless **all five** happen together: version bumped · adapters updated · schemas updated · conformance tests updated · documentation updated. Permitted without a bump: changes that only **remove a violation** or **add a field v1 already defines** (e.g. the §8.2 `chat_error`). **All four P2-A changes qualified; no bump was required.**

### CRITICALS REMAINING — 45 real
**21 of them (K-06 ×10, I-01 ×8, I-04 ×3) are the single missing idempotency primitive.** Still the highest-leverage work on the platform.

### PRODUCTION
`routes/api.php` `0a4a91570ec3 → 243091c146bd` (+3,705 bytes, 5 edits, **one file**) · health **200** · routes **981** · Phase O markers **2** (concurrent session's work intact) · workers **3/3** · `agent_messages` **1,707** · `seo_assistant_messages` **105** · unread **442** — all unchanged.
Rollback: `cp -p /root/backups/p2a-20260726/api.php routes/api.php` — single file, drilled at ~0.5s.

### ⚠️ CUSTOMER-VISIBLE CHANGE (flagged before implementation)
**Studio chat now says the AI is unavailable instead of appearing to answer.** That is the point of CR-03 and the only behaviour change from P2-A.

---

## ROUTES/API.PHP DECOMPOSITION — PROPOSAL ONLY, NOT IMPLEMENTED

`ROUTES-DECOMPOSITION-PROPOSAL.md`. Measured: **19,576 lines · 1.08 MB · 981 routes** (669 closures / 312 controllers) · **56 top-level `Route::` statements** = natural split points · **61 `.bak` files (~66 MB)** in `routes/`.

Two facts make it low-risk: `bootstrap/app.php` **already** loads a second route file (`exec-api.php`) via `then:`, so the include mechanism is proven here; and all **109** `use ($var)` captures are declared **inside** the block that captures them, so splitting at top-level boundaries breaks no binding.

Proposed: **18 module files under `routes/api/`**, split on URL prefix, required from a ~120-line manifest in a fixed array (not `glob`). Acceptance gate: `route:list --json` **byte-identical including order**. Rollback: restore one file, delete a directory (~0.5s).

**Blocked on CR-22** — the migration needs exclusive ownership of `routes/api.php`, and today it demonstrably does not have it. **Do not combine with P2-B.**

---


Handoff: `C:\Users\markr\LVL\handoff-2026-07-26\START-HERE-2026-07-26.md`
Normative contract: `CHAT-CONTRACT-v1.md` — **ratified, `chat.contract.version = 1.0`**
Baseline: `CHAT-CONFORMANCE-BASELINE-P1.md` · Decision: `CHAT-P2-DECISION-REPORT.md`

---

## CHAT PLATFORM P1 — COMPLETE (2026-07-26)

**P1 = Canonical Contract + Conformance Harness + Minimal Confirmed Fixes.** Approved and delivered.
**P2 NOT STARTED — awaiting Boss approval.**

### Delivered
| Item | Detail |
|---|---|
| `CHAT-CONTRACT-v1.md` | **118 normative clauses**, RFC-2119 language, REQUIRED/OPTIONAL/PROHIBITED/DEPRECATED classes, deprecation register with removal phases |
| Machine-readable schemas | **14 JSON Schema files** in `contracts/chat/v1/` — validated by 11 tests / 66 assertions |
| Conformance harness | `tests/Feature/Chat/` — **74 normative cases**, ONE suite, 11 surface adapters, 24 files |
| **Measured baseline** | **52.4% conformance · 207 pass · 188 fail · 47 critical · 62 blocked · 0 harness errors** |
| Production fixes | **CR-01, CR-02, CR-18 — all closed, 16 regression assertions** |
| Rollback | **Drilled live: 509 ms revert / 539 ms restore, health 200 throughout** |

### The first honest measurement this platform has ever had
| Group | Fails | |
|---|---|---|
| A Contract shape | 44 | |
| **E Credits** | **43** | **worst area — not read state** |
| I Observability | 29 | |
| B Persistence | 28 | |
| D Delivery | 13 | |
| H Errors | 12 | |
| G Rendering | 9 | |
| C Tenancy | 7 | |
| F Read state | **3** | **now the strongest group** |

Weakest surface: **Aria (`/api/assistant`) at 31.3%** — declares itself durable but persists nothing.
Strongest by applicable load: **S1 Agent Drawer, 52.2% over 67 clauses.**

### Fixes closed
- **CR-01 markup leak** — `arthur-chat.js:1039` (SVG→`textContent`), `builder.js:578` (error rendered as an *assistant* message with an icon prefixed — **the exact `Hi ✦ Assistant <svg…>` output**), and the Aria/SEO refusal copy at `routes/api.php:12748`/`:18589`, now human copy + a structured `chat_error` carrying `CHAT_INSUFFICIENT_CREDITS`.
- **CR-02** — duplicate `_msgMarkRead` at `messages-ui.js:399-400` (my own earlier defect).
- **CR-18** — the empty `catch (\Throwable $e) {}` after the persist-before-metering insert now logs `chat.persist_failed` with workspace/agent/exception. Deliberately does **not** rethrow.

### ⚠️ Corrections that measurement forced
1. **Authorization is sound.** All eight authenticated chat endpoints correctly return 401 to unauthenticated and invalid-token requests. An intermediate harness bug (Laravel's `withHeaders()` persists across requests, leaking the auth header) reported the opposite on 7 surfaces; a standalone probe disproved it **before** it reached any report.
2. **Credits, not read state, are the worst area** — 43 failures vs 3.
3. **Aria's real route is `/api/assistant`**, not `/api/ai/assistant`.
4. **`meterChat()` batches** — it charges on every 10th chat and returns `sufficient=true` for the nine between. A zero balance alone never produces a refusal; `chat_meter` must also be at 9.

### ⚠️ NEW RISKS
- **CR-21 (P2)** — the icon-into-`textContent` defect exists at **26 further sites across 6 bundles** (`builder.js` 9, `crm.js` 6, `creative.js` 5, `core.js` 3, `write.js` 2, `calendar.js` 1) — publish buttons, status labels, toasts. **UI chrome, not chat**, so out of P1 scope (it is the broad renderer rewrite P1 excludes). **Pinned at 26 by an automated test.** Needs a scoping decision.
- **CR-22 (P1)** — **a concurrent STUDIO888 Phase O session was editing `routes/api.php` during P1** (`.bak-phaseO-*` at 16:10 and 17:29; it added `POST /api/creative/edit` + `/assets/{id}/versions`, which explains the 979→981 route delta). Every patch used atomic read-modify-write with match-count assertions and Phase O was verified intact after every write — **no work was lost** — but two agents rewriting a 1.1 MB production file is not a safe steady state. **Must be resolved before P2.**

### Incident during P1 (disclosed)
`builder.js` was left syntactically broken for ~4 minutes. My patch script used `FIND_GUARD_REPL` to terminate a `<<<'REPL'` heredoc; PHP does not close on that identifier, so it scanned forward, absorbed the next edit's terminator, injected PHP source into `builder.js` **and** silently dropped the CR-02 edit. Caught by the `node --check` gate in the same run and restored from backup immediately (md5 verified). The retry added a hard syntax gate with automatic rollback. **My defect, not the platform's.**

### Guarantees held
No migration · no route added or removed · no middleware change · no credit/permission/governance change · **1,707 messages and 442 unread unchanged** · 16 credit rows untouched · health 200 · workers 3/3 · `GOVERNANCE_MODE=shadow`.

### P2 RECOMMENDATION (evidence-based)
- **P2-A now:** remove Studio's fabricated reply (**CR-03**, ~10 lines + honest 503) and apply persist-before-meter to Aria/SEO (**CR-04**). Both harm customers today; neither needs architecture.
- **P2-B core:** **idempotency key + `message_charges`** — closes ~**39 measured failures across 10 surfaces** from one additive schema change. Nothing else comes close.
- **Roadmap correction: migrate Aria FIRST, not last.** It is the weakest surface and the cheapest to move.
- **Exit gate:** conformance ≥75%, critical <15, zero regressions.

### How to re-run
```bash
cd /var/www/levelup-staging
php artisan test --filter=ChatSchemaValidationTest
CHAT_CONFORMANCE_MODE=postfix php artisan test --filter=ChatConformanceTest
php artisan test --filter='Cr01MarkupLeakTest|Cr02DuplicateMarkReadTest|Cr18SwallowedPersistenceFailureTest'
```
Tests are forced onto `levelup_test` by `phpunit.xml`; provider keys and `RUNTIME_URL` are blanked, so **no test run can reach a real AI provider**.

**Backups:** `/root/backups/p1-chatfix-20260726-p1cr/` (pre-fix) · `/root/backups/p1-chatfix-FIXED-20260726/` (post-fix).

---


Handoff: `C:\Users\markr\LVL\handoff-2026-07-26\START-HERE-2026-07-26.md`
Chat audit: `CHAT-PLATFORM-FORENSIC-AUDIT.md` — **complete 2026-07-26, awaiting roadmap approval**

---

## CHAT PLATFORM — FORENSIC AUDIT RESULT (2026-07-26)

**Finding: there is no chat platform. There are nine independent chat implementations that
share a login.** Overall grade **2/10** — matching the Boss's own assessment.

| Metric | Value |
|---|---|
| Conversational surfaces | **9** (+ meeting room = 10 entry points) |
| Frontend chat clients | **7 bundles** |
| Independent message stores | **5** |
| Names for the "reply" field | **4** (`reply`/`text`/`message`/`response`) |
| Credit models | **3** (0.1cr batched · 1cr flat · variable reserve) |
| `CreditService` classes in live use | **2** different classes |
| Real-time transports | **0** — no SSE, no WebSocket, no Echo/Pusher. Everything is polling. |
| Contract drifts catalogued | **16** |
| **Chat tests** | **0** |

### The five structural defects
- **SD-1 No conversation model** — one eternal thread per `(workspace, agent)`; `limit(100)` with
  no pagination; Studio/Studio-AI/Builder persist nothing at all.
- **SD-2 No delivery layer** — the reply is generated inside a php-fpm worker held open after
  `fastcgi_finish_request()`. Measured **p50 9 s · p90 26 s · max 71 s** against a 90 s client cap.
  No queue, no retry, no DLQ, no delivery record.
- **SD-3 No shared contract** — `content` vs `message`; two-phase on 3 surfaces, synchronous on 6;
  23 distinct credit-refusal strings.
- **SD-4 No commercial consistency** — identical user action costs 0.1cr, 1cr or a variable
  reserve depending only on which panel it was typed into. No charge joins to any message row.
- **SD-5 No verification** — zero tests. **Every chat regression in this platform's history was
  found by the Boss, in production.** That is the root cause of the incident pattern.

### Validation of the 2026-07-26 fixes — 8 shipped, 0 tested, 1 never applied
| Fix | Verdict |
|---|---|
| `POST /messages/read-all` | retain → supersede (workspace-scoped, not per-user) |
| `_msgMarkRead()` server-authoritative | retain — **duplicate call at `messages-ui.js:399–400` (CR-02)** |
| `_msgMarkAllRead()` + header control | retain |
| read-on-view / live read marking | retain — **S3 page view still has no background refresh** |
| Sarah two-phase in the widget | retain → supersede (19 s of headroom on the 90 s cap) |
| persist-before-meter | **promote to a platform rule** — currently 1 of 6 paths |
| **Aria markup-leak fix** | ❌ **NOT FIXED — was never applied. See CR-01.** |
| cache-busters | retain |

### ⚠️ CORRECTION ON RECORD
The Aria markup leak was **not** fixed on 2026-07-26 and must not be recorded as resolved.
`routes/api.php:12748` and `:18589` still return the original machine copy, and the root cause of
the visible `<svg…>` is now identified: `arthur-chat.js:1039` assigns an SVG string to
`.textContent`, and `builder.js:578` injects `window.icon()` into an assistant message body.

### CHAT TECHNICAL DEBT — 20 open risks (`CHAT-RISK-REGISTER.md`)
- 🔴 **CR-01 P0** markup leak — raw `<svg>` renders as visible text. **Live, customer-visible.**
- 🔴 **CR-03 P0** **Studio chat fabricates replies** via a keyword fallback when the runtime is
  down. The user cannot tell it is not the AI. Highest-severity trust defect found.
- 🔴 **CR-04 P1** Aria/SEO discard the user's message on credit refusal — same class as the
  Sarah incident, on two other surfaces.
- 🟠 **CR-05 P1** read state is workspace-scoped, not per-user (ws1: 2 members, ws990003: 3).
- 🟠 **CR-06 P1** no idempotency or lock on send — double-click ⇒ duplicate message and charge.
- 🟠 **CR-07 P1** no delivery guarantee — worker death ⇒ reply never exists, no signal.
- 🟠 **CR-08 P1** `meeting_messages` has **no `workspace_id` and no `user_id`** — tenancy relies
  entirely on the `meetings` join. **CR-09** `bella_conversations` has no `workspace_id` (latent —
  becomes P0 the moment Bella is enabled).
- 🟠 **CR-16 P1** zero chat tests.
- 🟡 **CR-02** duplicate `_msgMarkRead` · **CR-10** 100-row history cap · **CR-11** S3 no refresh ·
  **CR-12/13/14** credit inconsistency, no message↔charge join, no refund on failure ·
  **CR-15** no correlation id or provider/model attribution · **CR-17** 312 untyped rows ·
  **CR-18** swallowed persistence failure · **CR-19/20** render fragility, 90 s cap headroom.

### RECOMMENDED FIRST PHASE — **P1 Contract + Test Harness (approve this only)**
Zero-risk. Write the normative contract, build a ~60-case conformance suite, run it against all
nine surfaces to produce the platform's first honest baseline (≈22 predicted failures), and ship
four lines of corrective production change: **CR-01**, **CR-02**, **CR-18**. No architecture
changes, no data migration, no user-visible behaviour change beyond fixing the leak.

**Full roadmap:** `CHAT-MIGRATION-ROADMAP.md` — P1 harness → P2 unified core (shadow) →
P3 migrate Messages modal/page → P4 migrate Agent Drawer → P5 peripherals → P6 decommission.
**Not started. Awaiting Boss approval.**

### CHAT DOCUMENTS (2026-07-26)
`CHAT-PLATFORM-FORENSIC-AUDIT.md` · `CHAT-SURFACE-INVENTORY.md` ·
`CHAT-CONTRACT-DRIFT-MATRIX.md` · `CHAT-FUNCTIONAL-PARITY-MATRIX.md` ·
`CHAT-ENTERPRISE-ARCHITECTURE-MASTERPLAN.md` · `CHAT-MIGRATION-ROADMAP.md` ·
`CHAT-CONTRACT-TEST-PLAN.md` · `CHAT-RISK-REGISTER.md`

---


Handoff: `C:\Users\markr\LVL\handoff-2026-07-26\START-HERE-2026-07-26.md`
Masterplan: `GOVERNANCE-HARDENING-MASTERPLAN-P0.md` — **APPROVED 2026-07-26**
Decisions: `GOVERNANCE-DECISION-REGISTER.md` — **binding**

### PHASE STATUS
- **P0-A COMPLETE** — documentation, governance registration, architectural preparation.
- **P0-B NOT STARTED** — awaiting Boss approval.
- **Engineering888 PAUSED** — 9 of 9 mandatory dependencies (M1–M9) outstanding.

### ⚠️ ZERO PRODUCTION CHANGE IN P0-A
No code · no middleware · no route protection · no migrations · no runtime edits · no
authorization enforcement · no feature work. HEAD unchanged at `416686e`. Bella remains
**dormant** (`audit_logs action='bella_chat'` = 0) and **unmodified**.

### EXECUTIVE DECISIONS (binding — GOVERNANCE-DECISION-REGISTER.md)
- **GD-001 Bella → GOVERN AND GATE.** Not retired, not exposed. Permanent rule:
  **Bella NEVER directly executes privileged actions. Bella may REQUEST. The Governance layer
  decides.** No exceptions, no emergency bypass.
- **GD-002 `query_database` → REMOVE.** Intentionally deprecated. **DO NOT REPAIR** — its safety
  depends on an unintended Laravel 11 `TypeError`. Do not replace with unrestricted SQL. Do not
  reintroduce under another name. ⚠️ The broken line *looks like a trivial bug*; the correct
  action is deletion.
- **GD-003 Replay Policy → APPROVED.** Tier 1 automatic (infra, AI, SEO, sync, maintenance) ·
  Tier 2 manual approval (customer deliverables, billing, credits, emails, publishing,
  deployments, notifications) · Tier 3 discard (expired drafts, superseded reports, historical
  temp jobs, obsolete snapshots). Ambiguous ⇒ Tier 2.
- **GD-004 Platform Owner invariants** (PO-1…PO-5) · **GD-005 Machine authority invariants**
  (MA-1…MA-6) · **GD-006 Approval invariants** (AP-1…AP-6) · **GD-007 Authorization principles**
  (AZ-1…AZ-6).

### DOCUMENTS CREATED (P0-A)
| Document | Content |
|---|---|
| `GOVERNANCE-DECISION-REGISTER.md` | 7 binding entries · append-only · Owner-only change control |
| `SENSITIVE-OPERATIONS-REGISTER.md` | **77 operations** (72 implemented + 5 not-implemented) · 16 CRITICAL · 21 DANGEROUS |
| `CAPABILITY-REGISTRY.md` | **83 capabilities** · 23 LIVE · 42 EXISTS-UNGOVERNED · 17 PLANNED · 1 REMOVED |
| `BELLA-GOVERNANCE-SPECIFICATION.md` | 5-tier action model · 10-stage execution pipeline · enablement gate |
| `ENGINEERING888-DEPENDENCY-REGISTER.md` | M1–M9 mandatory · R1–R8 recommended · O1–O8 optional |
| `GOVERNANCE-VERIFICATION-PLAN-M9.md` | **87 tests · 84 MUST-PASS** across 8 suites · designed, NOT executed |

### KEY EVIDENCE ESTABLISHED IN P0-A
- **64 mutating admin routes**; only **16 hardened** (`DenyApiKeyAuth`), exactly **1 MFA**.
  Unguarded include `POST /workspaces/{id}/credits`, `DELETE /users/{id}`, `POST /config`,
  `POST /house-accounts/{id}/top-up`, `POST /notifications/broadcast`.
- **`delete_workspace` DOES NOT EXIST** — no route, no method. Registered as PLANNED so it is
  governed *before* it is built. Same for workspace ownership transfer, DNS record mutation,
  SSL operations, and in-platform deployment trigger.
- **18 capability keys already exist** in `ApprovalPolicyRegistry` with fail-closed
  `STRICT_DEFAULT` — the platform's native `engine.action` vocabulary is extended, not replaced.
- **INFRA888 provider control plane is the reference implementation** — 13 hardened + 1
  hardened+MFA, approval-backed, separation of duties enforced.

### GOVERNANCE MODEL ADOPTED
- **Authorization: HYBRID** — Gates + capability registry + Policies where model-scoped.
  **Rejected:** third-party RBAC package (would create a 5th competing authority vocabulary
  alongside `agent_capabilities`, `ApprovalPolicyRegistry`, `workspace_users.role`,
  `LaunchScopePolicy`).
- **12 principals:** Platform Owner · Platform Administrator · Engineering · Support · Finance ·
  Marketing · Customer · Workspace User · API · Service · System · Machine.
- **Three invariants:** no machine approves anything · no machine holds standing authority ·
  the Platform Owner cannot be suspended/demoted/deleted by any other principal.

### ROADMAP
**P0-A ✅ → P0-B (coverage: MFA enrolment + DenyApiKeyAuth) → P0-C (events + alerting) →
P0-D (authorization, shadow-mode first) → P0-E (machine governance + Bella) → P0-F (M9
certification) → ENGINEERING888.**

⚠️ **P0-B is the riskiest phase, not P0-D.** Attaching `DenyApiKeyAuth` will 403 any tooling
using the shared admin token, and enrolling **only one** admin trips the DEGRADED state → **423
platform-wide**. Both admins must enrol together. Inventory shared-token consumers first.

### CURRENT PRIORITIES
1. Boss approval to begin **P0-B**.
2. Inventory shared-admin-token consumers before P0-B (prevents a 403 outage).
3. V4 pricing lookup (TD-02) — still outstanding.
4. Replay implementation per GD-003 (213 failed + 63 blocked tasks).

### PROJECTED OUTCOME
Enterprise readiness **5.4 → 7.7** after full P0. Authorization 3.5 → 8.0 · Security 5.0 → 7.5 ·
Observability 5.0 → 7.5. 7.7 is the honest ceiling for a governance-only phase — the route
monolith (19,363 lines), 508 `.bak` files, and scalability headroom are deliberately out of scope.

### INSTALL STATUS
- Source (Laravel) — **NOT modified**. HEAD `416686e`, clean apart from documentation.
- Runtime — **NOT modified**. v2.37.4 content, `/health` reports 2.37.3.
- Database — **NOT modified**. No migrations.
- Middleware / routes / authorization — **NOT modified**.
- Bella — **NOT modified**, still dormant, still reachable, still ungoverned pending P0-E.
- Backups — `/root/backups/docsync-p0a-<stamp>/`.

---

# BOSS888 STATE - 2026-07-26 (DEEPSEEK V4 INCIDENT + RUNTIME RESTORATION)

Handoff: `C:\Users\markr\LVL\handoff-2026-07-26\START-HERE-2026-07-26.md`
(mirrored on droplet at `/root/handoff-2026-07-26/`).
Incident: `INCIDENT-REGISTER.md` → **INC-2026-001**.

### PRODUCTION STATE
- **RESOLVED.** AI content generation was down **~42 h** (2026-07-24 13:37 → 2026-07-26 08:45 UTC).
- Post-fix, first 90 min: **47 tasks completed · 3 running · 38 queued · 0 FAILED · 0 DeepSeek
  failures.** `failed_jobs` 0. Autonomous agents resumed unaided (ws 26, 27, 29, 990003).
- Baseline before fix: **36 failed tasks / 72 h** across 10 workspaces (incl. ws 7 Shukran Group).

### AI PROVIDER STATE
- **DeepSeek `deepseek-v4-flash` is the production default.** `deepseek-v4-pro` available by
  explicit tier only — **nothing routes to Pro by default** (measured reasoning ~6x flash).
- `deepseek-chat` / `deepseek-reasoner` **retired by DeepSeek 2026-07-24 15:59 UTC** → HTTP 400.
- OpenAI retained for images (`gpt-image-1`), vision (`gpt-4o`), and `/ai/run` fallback (`gpt-4o-mini`).

### RUNTIME
- **`levelup-runtime` v2.37.4-content deployed to Railway 2026-07-26 ~08:45 UTC by Boss.**
- ⚠️ **`/health` still reports `2.37.3`** — version constant deliberately NOT bumped.
  **Deploy proof is behavioural:** POST `/ai/run` with `model:"deepseek-chat"` → must return
  `400 DEEPSEEK_INVALID_MODEL`. Do not use `/health` as a deploy signal.
- Package `LVL\runtime-v2.37.4-deepseek-v4\` · prior `LVL\runtime-pkg-2.37.3\`.
  Deploy/rollback: `RUNTIME-2.37.4-DEEPSEEK-V4-DEPLOY-AND-ROLLBACK.md`.

### ⚠️ THE ARCHITECTURAL FACT (this incident's biggest lesson)
**Laravel orchestrates; the Railway Node runtime EXECUTES all provider calls.**
`config/llm.php` + `DeepSeekConnector` hold model names but that path is dormant (the
2026-04-12 hands-vs-brain refactor moved every call site to `RuntimeClient`).
**Patching Laravel would have fixed nothing.** The 8 hardcoded `deepseek-chat` literals were
all in the runtime. Full truth: `ARCHITECTURE-AI-EXECUTION-LAYER.md` (AD-01…AD-12).

### WHAT CHANGED (runtime only — ZERO Laravel changes this session)
- **NEW `deepseek-models.js`** — central model registry (`DEEPSEEK_DEFAULT_MODEL`), retired-name
  rejection, reasoning headroom (2000, capped 8192), empty-content + structured-output guards,
  usage split, secret redaction.
- `index.js` (+94/-20), `llm.js` (+30/-7), `lu-planner.js` (+23/-6), `lu-worker-manager.js` (+3/-2),
  `ai-complete-endpoint.js` (+2/-2), `.railway-trigger`. Removed stray `index.js.bak2`.
- **81 of 87 baseline files byte-identical** (SHA-256 verified).
- New error codes: `DEEPSEEK_INVALID_MODEL`, `DEEPSEEK_EMPTY_FINAL_CONTENT`,
  `DEEPSEEK_INVALID_STRUCTURED_OUTPUT`.
- **Behaviour change:** `/ai/run` `chat_json` now returns **502** on malformed JSON (was
  `success:true, parsed:null`). Previously-silent garbage now surfaces as failure.

### V4 GOTCHA THAT WILL BITE AGAIN IF FORGOTTEN
Reasoning is **always on and not disableable**, and `reasoning_tokens` are billed **inside**
`completion_tokens` — so they consume `max_tokens`. Exhausted budget returns **HTTP 200 with
empty content**. Measured on real workloads: flash 70-324 tokens, **pro 1238**. Flash at
existing budgets was never at risk; **Pro would have truncated** against the planner's 1500.
Headroom is near cost-neutral (+125% ceiling produced +1.8% spend).

### VALIDATION
50/50 tests (43 offline + 7 live API) · patched runtime booted on isolated port · exact upload
artifact re-verified · 13 production smoke tests passed incl. `writeDraft` (5,556 chars) and a
full Orchestrator `write_article` task → `completed`.

### KNOWN RISKS / TECHNICAL DEBT (full register in the incident doc)
- 🔴 **TD-04 no provider-error alerting** — the reason this ran 42 h undetected. Failures land in
  `tasks.error_text`, never `failed_jobs`, so nothing pages.
- 🟠 **TD-10** 213 failed + 78 blocked tasks never replayed (incl. 2595/2599, ws 7 customer).
- 🟠 **TD-02** V4 pricing unverified — `RuntimeClient.php:1207` still uses V3 rates. All DeepSeek
  cost figures in admin are wrong. Customer credits are flat-rate, so exposure is **margin**.
- 🟠 **TD-01/TD-03** Laravel still hardcodes `logApiUsage('deepseek',…)` and prices by provider,
  not model — OpenAI fallbacks record as DeepSeek. Runtime emits the truth; Laravel ignores it.
- 🟡 **TD-05/TD-06** runtime streaming is dead code — `stream-init` has a PRE-EXISTING body-parser
  bug (route line 271, parser line 438 → `req.body` undefined) and Laravel calls neither
  streaming route. The patched streaming lines are unexercised.
- 🟡 **TD-07** V4 vision unverified · **TD-08** no lockfile · **TD-09** runtime has no git repo ·
  **TD-12** `lu-worker-manager.js:53` requires a missing module (pre-existing) · **TD-13** 0 of 60
  Laravel test files reference DeepSeek.

### RESOLVED FROM EARLIER STATE
- **MASTERCONTEXT §5 "THE ONE BLOCKER" (Railway deploy access) is CLEARED** — Boss deployed
  successfully 2026-07-26. Runtime source for the deployed version now exists locally.
  (Note: still no Railway CLI/token *in the working environment* — Boss deploys manually.)

### CURRENT PRIORITIES
1. Boss decision: replay policy for the 213 failed / 78 blocked tasks (TD-10).
2. Boss action: V4 pricing lookup (TD-02) so cost/margin become truthful.
3. Provider-error alerting (TD-04) — prevents a repeat 42 h blind spot.
4. Laravel provider attribution + model-keyed pricing (TD-01/TD-03).

### NEXT PHASE (Boss-approved sequence)
**1. Laravel Admin Forensic Audit (NEXT)** → 2. AI Architecture Audit →
3. Engineering888 Masterplan → 4. Bella Masterplan.
Note: **Engineering888 and Bella do not exist in any codebase today** — 0 hits for
`engineer888`; `bella` is only an admin persona + `BellaController`. Both need their own phase.

### STUDIO888 MRC-2A
Still **PAUSED** at `416686e`, inert in production. V4 answered several revalidation questions
(empty-content enforcement, reasoning/content separation, structured-output shape); streaming,
vision, and latency baselines remain open. Pre-V4 and post-V4 readiness data must stay
segmented. See `STUDIO888-DEEPSEEK-V4-IMPACT-REGISTER.md`.

### ⚠️ GIT STATE (read before the next phase)
Repo is checked out on branch **`feature/studio888-mrc-2a` @ `416686e`** — the Studio freeze
point, **NOT a mainline branch**. Preserve branch `preserve/studio888-mrc-2a-pre-deepseek-v4`
and tag `studio888-mrc-2a-pre-deepseek-v4` both exist at that commit. All Studio work is
**committed**, not dirty. Only this documentation sync is uncommitted.
**Anyone starting the Laravel Admin Forensic Audit must know they are on a feature branch.**

### INSTALL STATUS
- Source (Laravel) — **NOT modified** (zero Laravel changes this incident).
- Runtime — ✅ modified, built, tested (50/50), **deployed to Railway by Boss**, verified in production.
- Database — **NOT modified** (no migration involved).
- Config cache — untouched (`config:cache` remains banned).
- Workers — **not restarted** (not required; no `app/` change).
- Test data — created on ws 990003 during verification and **removed**.
- Backups — `/root/backups/docsync-deepseek-v4-20260726-094249/` + dated `.bak-docsync-*`.

---

# BOSS888 STATE - 2026-07-24 (SARAH + PUBLISHING + FEATURED IMAGES)

Handoff: `/root/handoff-2026-07-24/HANDOFF-SARAH-PUBLISHING-2026-07-24.md`
(local mirror `C:\Users\markr\LVL\handoff-2026-07-24\`).

### DONE THIS SESSION
- **Chef Red (ws2): 0 articles missing a featured image** (was 28). Sarah ran it
  herself via chat. Credits 137 -> 66.
- **Article publishing works end-to-end** (was fully broken — 5 bugs fixed).
- **Sarah chat UX/intelligence fixed**: sensible greeting/social acks, no more
  28<->10 count flip-flop, no raw tool-language leaks, reliable bulk image fill.

### ⚠️ OPERATIONAL RULE (learned the hard way)
Queue workers (`levelup-worker:*`) are long-running and DO NOT reload `app/` code
on edit. After ANY `app/` change:
`php artisan queue:restart && supervisorctl restart levelup-worker:*`.
`routes/api.php` + `public/*` need no worker restart. Never `config:cache`.

### FIXES (each has dated .bak + /root/backups copy)
- W6 Cloudflare edge CLEARED -> W6 COMPLETE AND VERIFIED.
- Calendar IDOR + invalid-column patch (routes/api.php) -> SECURITY PATCH VERIFIED (26/26).
- AgentSeeder 'dormant'->'disabled' (8/8).
- Publishing (5): index.html BASIC_VISIBLE+blog/write; CapabilityMap publish_article;
  routes/api.php server-side target resolution + two-turn title carry; TaskService
  explicit-confirmation opt-out; Orchestrator image-prompt ordering.
- Params: CrmService::createLead name-derive; SeoService::deepAudit URL-resolve;
  CapabilityMap retry_blocked.
- UX: AckGeneratorService greeting/social acks; ToolSchemaService leak-fix +
  missing-image breakdown; routes/api.php image grounding breakdown; routes/api.php
  fill_missing_images DETERMINISTIC BACKSTOP; Orchestrator dispatch ENGINE
  NORMALIZATION (the real fill_missing_images killer — creative vs write).

### OPEN (nothing blocking)
1. Sarah "fabricated success" integrity bug (reports done when task failed/dropped/
   never emitted) — biggest open item; needs reply-vs-actual-outcome reconciliation.
2. competitor_gaps = stub ("not supported"). 3. calendar/create_event stuck pending.
4. ws2 stuck tasks: publish_article #2160/#2161 (07-22), delete_lead #1890 (07-15).
5. Draft #408 "Private Chef Cost NJ" still draft — one "yes publish" takes it live.

### CONCURRENT (not mine): INFRA888 Hosting created 5 QA-range ws (tattoo/realestate)
22:04-22:32 on 07-23 + ws4 articles. My clones all torn down (0 @qa.invalid).

---

# BOSS888 STATE - 2026-07-23 (PUBLISHING PIPELINE FIXES)

### ARTICLE PUBLISHING — END-TO-END FIXED AND VERIFIED (2026-07-23)
Boss report: "not able to publish articles" + "if the user explicitly gives a go,
Sarah confirms then publishes". Root-caused to FIVE independent defects, all fixed,
verified through the LIVE FPM path (CLI opcache reads were unreliable — FPM is authoritative).

**BUG 1 — Blog/Write unreachable in Basic mode (the primary "can't publish").**
`public/app/index.html:2399` `BASIC_VISIBLE` omitted 'blog' and 'write', so the
mode-switch guard swallowed `nav('blog')`, `blog.js` never loaded, and the Publish
button had NO handler. Zero publish POSTs ever reached the server (nginx 09-23 Jul).
Fix: added 'blog','write' to BASIC_VISIBLE. Verified in Chromium: publish POST fires
in basic/unset/advanced. Live through CF (`DYNAMIC`, uncacheable). Users must hard-refresh.

**BUG 2 — `publish_article` never registered as a capability (capability<->dispatch drift).**
`write/publish_article` was in the Orchestrator dispatch map, TaskCategoryService,
ApprovalController labels and Sarah's prompt — but MISSING from `CapabilityMapService`.
`Orchestrator::execute()` threw "No capability mapped for action: publish_article" at
step 2, so an agent-driven publish had NEVER once succeeded. Fix: registered
`'publish_article' => [engine=write, connector=null, approval_mode=protected, credit_cost=0]`.
Same class as the 2026-07-14 move_lead drift, mirrored.

**BUG 3 — Sarah invents article_id; task silently dropped.**
The chat LLM guesses `article_id` (ws2 2026-07-22: SIX publish tasks dropped as
"article_id=385..390 not in this workspace"; QA: article_id=1/2). The guard at the
Sarah-chat task loop discarded the task with only a Log::warning — the user was told
"publishing now" and nothing happened, silently. Fix: RESOLVE the target server-side by
title against the workspace's own drafts (single-draft workspaces are unambiguous);
"backend finds the id, Sarah never guesses" — the same principle the codebase already
uses for write/fill_missing_images.

**BUG 4 — publishing double-gated / language wrong (Boss decision).**
publish_article was in `$destructiveActions` (forcing requires_approval) AND
TaskService force-gates every 'publish'-category action. So even after the user
confirmed, the article sat in the Review Queue for a SECOND consent. Boss decision:
one explicit go is enough. Fixes: removed publish_article from $destructiveActions;
TaskService now honours an explicit-confirmation opt-out for `publish_article` ONLY
(website/page publishes and all other publish-category actions stay hard-gated);
the affirmative is matched SERVER-SIDE from the user's own message (deterministic —
not trusting the LLM); Sarah's prompt updated to emit the task on confirm and to stop
promising a Review-Queue step that no longer exists for article publishing.

**BUG 5 — featured-image generation fails ("Missing required parameters: prompt").**
ORDERING bug in `Orchestrator::execute()`: the parameter resolver (step 5) hard-requires
`prompt` and threw BEFORE the dispatch map (step 7) could call `ensureImagePrompt()`,
which derives a prompt from the article title/id. Sarah proposal/chat image tasks
carrying only title/article_id died on every retry, and repeated failures tripped the
creative connector's circuit breaker, degrading later image tasks. Hit ws2/ws29/ws990003.
Fix: derive the image prompt BEFORE validation for the three creative image actions.

**GUARDRAIL (live FPM, definitive):**
- Request-only, no confirmation -> NO publish task, article stays draft. Gate holds.
- Request THEN "yes, confirm" -> published, approval=0, ~10s. Works as asked.
- publish_website with a stray confirm flag -> still hard-gated. Scope not leaked.

**FILES CHANGED (6, each with dated .bak + /root/backups copy):**
- public/app/index.html                          (BASIC_VISIBLE; bug 1)
- app/Core/EngineKernel/CapabilityMapService.php (register publish_article; bug 2)
- routes/api.php                                 (server-side id resolution + confirm flag + $destructiveActions + Sarah prompt; bugs 3,4)
- app/Core/TaskSystem/TaskService.php            (confirmation opt-out; bug 4)
- app/Core/TaskSystem/Orchestrator.php           (image prompt ordering; bug 5)
- (AgentSeeder.php + Calendar security were earlier today — separate blocks)

**RECONCILIATION:** routes 978, workers 3/3, jobs/failed 0, transmitted:true 0,
QA leftovers 0. Baselines: users 11, workspaces 20, articles 349, tasks 2060, websites 28.
17 ERROR lines dated today are ALL accounted for: rolled-back Calendar repros, the image
bug now fixed, the "no capability" failures now fixed, and my own QA-script bugs. None are
new production breakage.

**STILL OPEN / FOR THE BOSS:**
- ws 27/28/29/990003 will still 422 `site_not_configured` on publish — their Builder site
  is `status=draft`. Publish the Builder site first (Boss action — outward-facing).
- The mobile companion app has NO publish route (11 exec-api routes, none for articles).
- ws1 & ws7 share an identical `webhook_secret` (looks like copy-paste).
- `_blApi` (blog.js:22) discards HTTP status — errors surface via body only; fragile.
- 4 Infrastructure files (ProvisioningService, SubdomainService, infrastructure.js,
  SubdomainServiceTest) show today's mtime but were NOT changed by this work — a separate
  INFRA888 process is active (infrastructure.js changed at 17:45, after my last edit).

---

# BOSS888 STATE - 2026-07-23 (W6 CLOSED + CALENDAR SECURITY PATCH)

### W6 COMPLETE AND VERIFIED (2026-07-23). Cloudflare edge condition CLEARED.
Canonical `N22` PASS + `N23` PASS (as originally written, origin-only) **plus** a new
edge-aware extension **32/32** and a real headless-Chromium execution proof **16/16**.
- The canonical N22/N23 were **origin-only** - that is precisely why they passed on
  2026-07-22 while Cloudflare was still serving the four removed bundles. Both layers
  are now kept: `/root/w6-edge-retest.sh` (edge+origin) and `/root/w6-edge-qa.cjs` (Chromium).
- All four URLs: HTTP 200, `content-type: text/html`, `cf-cache-status: BYPASS`, 349237 B,
  origin and edge **byte-identical** (sha256 `beef72b0b8223652`). PoPs covered: DXB, MRS, AMS.
- Signatures were extracted from the REAL pre-removal bundles in
  `/root/backups/w6b-presrc-20260722-191104.tar.gz`, not guessed. All 21 absent at edge AND origin.
- Chromium fired `onerror`, never `onload`: the browser **refuses to execute** all four on
  MIME/nosniff grounds. `LU_LOADED_ENGINES` empty, `socialLoad`/`mktLoad`/`automationLoad`/
  `mentionsLoad` all `undefined`, live app requests 0 of the 4, 0 page errors.
- **Why this is durable, not a TTL fluke:** the bundles are gone from disk, so the SPA
  fallback answers and it sets a session cookie - Cloudflare will not cache a `Set-Cookie`
  response, so `BYPASS` is now structural. The old objects had `max-age=14400` and no cookie.
- **CLEANUP ITEM (not a blocker):** a missing static asset returns the SPA document rather
  than 404. Fine for W6 (no removed source is reachable or executable) but should eventually
  be a real 404 for `/app/js/*.js`.

### CALENDAR/CRM TENANCY + SCHEMA SECURITY PATCH - COMPLETE AND VERIFIED
Re-derived independently from live code and schema; the prior line numbers had shifted.
- **IDOR** `PUT /api/crm/appointments/{id}` (`routes/api.php:4044`) updated
  `calendar_events` scoped by **id alone**. Any authenticated user of any workspace could
  mutate any workspace's event by global id. No role gate exists on the `crm` prefix.
- **INVALID COLUMNS** `calendar_events` has **no `type` and no `status` column**.
  `POST /appointments` wrote `type` (so it threw `SQLSTATE[42S22]` on *every* call and has
  never once succeeded); `PUT` wrote `status`.
- **FIX (one file, no migration):** `type` -> real column `category`; `status` dropped (no
  domain concept backs it - no column was added to preserve a controller mistake);
  `->where('workspace_id', $wsId)` added; uniform `404 {"error":"Event not found"}` for
  missing AND foreign ids; `title`/`starts_at` validated -> 422 instead of a SQL error.
  `/api/calendar/events/{id}` PUT+DELETE were already scoped but returned a bare bool and
  surfaced not-found as 500 - now a uniform 404.
- **26/26 isolated tenancy tests green.** Cross-workspace update/delete denied; workspace B
  row byte-unchanged; missing vs foreign responses **byte-identical** (no existence leak);
  client-supplied `workspace_id` cannot move an event; same-workspace CRUD still works.
- QA used two NEW isolated workspaces (990022/990023) - **workspace 2 was not reused** and no
  QA user was added to a customer workspace. Full teardown; all baselines restored exactly.
- **Exploitation evidence:** nginx logs retained 09-23 Jul show 10 hits on
  `/api/crm/appointments` (all `GET ?upcoming=1`, all 200) and 8 on `/api/calendar/events`
  (all GET). **Zero PUT/DELETE with a numeric id, ever, in the window.** Laravel log 13-23 Jul:
  26 `42S22` errors, **none on `calendar_events`**. **What this CANNOT prove:** anything before
  09 Jul (rotated away), and `calendar_events` is empty (0 rows, AUTO_INCREMENT 383) so there
  is no row-level audit trail. Absence of evidence, not evidence of absence.
- Backups: `routes/api.php.bak-20260723-calsec` + `/root/backups/api.php.bak-calsec-20260723-071400`.
  Rollback = restore either + `php artisan config:clear`. **Never `config:cache`.**

### AGENTSEEDER RESIDUAL - FIXED (8/8 regression green)
`AgentSeeder` set removed agents to status `'dormant'`, but `agents.status` is
`enum('active','disabled')` and MySQL runs `STRICT_TRANS_TABLES` - so **every reseed aborted**
with `Data truncated for column 'status'`. Changed to `'disabled'`, an existing enum member.
No migration, no deleted rows. Verified by running the seeder for real inside a transaction:
0 removed agents active, all 10 retained as disabled historical rows, 10 retained still active,
attribution preserved, then rolled back with production rows untouched.
- **OPEN DECISION:** the 10 removed agents are still `status='active'` in the live DB (reads are
  already neutralised by `AgentDirectory` + the model global scope, which key off slug not
  status). A reseed would now correct them to `disabled`. Not changed in this pass.
- **NEW RESIDUAL FOUND:** the seeder contains an **11th agent, `sam`, not present in the DB and
  not in either the removed or retained list**. A reseed would create it as **active**, breaking
  the "10 agents" invariant (check 43). Needs a scope decision before any reseed.

### DASHBOARD REACT BUNDLE - DEAD SOURCE, NOT SERVED (do not modify)
`resources/js/pages/Dashboard.jsx` does contain a removed-agent colour map
(`marcus:'#EC4899'`, `leo`) but it **does not participate in production**:
- vite `outDir` is `public/app-react/` - **that directory does not exist**; the build has never
  been run into the webroot.
- **No `@vite` directive in any blade.** No `public/build/`. No hashed vite bundles.
- The customer SPA is `public/app/index.html`, which loads only hand-written
  `/app/js/*.js` (launch-scope, orb, core, onboarding, media-picker, builder, messages-ui,
  arthur-chat, chatbot-spa, workspace-v2). No React.
- `resources/js/*` is outside the webroot: `/resources/js/App.jsx` -> **404**, `/main.jsx` -> **404**.
- Every file under `resources/js/` is untouched since the 2026-04-05 scaffold.
Per directive, unrelated React source was NOT altered.

### NEW FINDING (W6-class, NOT fixed - needs a decision)
`public/js/*.js` is a **legacy vanilla bundle set that IS publicly fetchable**:
`/js/dashboard.js` -> **200, `application/javascript`, 13108 B**. Five files contain removed-agent
or removed-feature copy: `agents.js`, `dashboard.js`, `engines.js`, `orb.js`, `tasks.js`
(e.g. `marcus:'social'` orb map, `'Campaigns sent (7d)'`).
It is referenced by **no** blade and **no** served app shell, so no customer sees it in-product -
but it is directly retrievable, same class as the `.bak` webroot finding W6b fixed.
**NOT removed in this pass:** `site.js`/`app.js`/`auth.js` were modified 11-12 May, later than the
rest, so published customer sites may reference them. Verify against published sites first.

### RECONCILIATION (2026-07-23)
Routes **978 -> 978**. Workers 3/3 RUNNING. jobs 0 / failed_jobs 0. `transmitted:true` = 0.
publisher_posts/social_posts/social_accounts = 0/0/0. leads 29, activities 10, pipeline_stages 20,
tasks 2039, articles 346, users 11, workspaces 20, calendar_events 0. **No provider contacted.**
W6b regression re-run post-patch: **76 passed, 5 failed** - all five are teardown artifacts
(4x "no token", 1x "QA accounts users=0 ws=0"), i.e. the cleanup succeeded. Not regressions.
One `ERROR` dated 2026-07-23 is mine: a deliberately rolled-back FK-constrained repro attempt
at 07:34:36; nothing was written.

### NEXT -> W7 public marketing + pricing truth (now UNBLOCKED).
`resources/views/marketing/{home,pricing,features,faq,specialists}.blade.php` + the static
`public/marketing/pages/*.html` twins. Check `specialists.blade.php` first.

---

# BOSS888 STATE - 2026-07-22 (W6b LAUNCH-SCOPE REMEDIATION)

### W6 COMPLETE WITH CONDITIONS (2026-07-22) - the real frontend/payload/mobile pass. LIVE.
**352 checks green** = 81 W6b harness (51 spec + 30 new-defect) + 127 prior regressions
+ 144 AUTHENTICATED headless-Chromium QA across 8 context/viewport combos.
**0 provider transmissions** (`transmitted:true` = 0 of 120 log lines). No route reopened.
Routes 978 -> 978. Queues 0. Stranded credit reservations 0. Workers 3/3.

- **THE FINDING THAT MATTERED:** `LU_SCOPE.filterAgents` and `isRemovedAgent` had **ZERO
  callers**. The agent half of the W6 guard was dead code. Shortest path to a customer
  seeing a removed agent was: log in -> Agents -> the STATIC Marcus card at
  `index.html:4167`. No API, no cache, no crafted reply needed.
- **MOBILE PERIMETER WAS ZERO:** `LaunchScopeRoutes` matched only `api/*`; every mobile
  route is `exec-api/*`. Fixed by namespace normalisation against the SAME blocklist
  (no second list). 23 live legacy task rows (marketing 16 / social 7) + 11 rows assigned
  to removed agents were reachable; now rejected as legacy, NOT deleted.
- **RAW-QUERY BYPASS:** `DashboardController::agentForSlug` + `ApprovalController::agentBadge`
  used raw `DB::table('agents')`, walking around the `Agent` global scope. NEW
  `app/Core/LaunchScope/AgentDirectory.php` is now the single authority - a removed agent
  resolves as `"<Name> - historical agent"`, `active=false`, `assignable=false`.
- **HISTORICAL RECORDS PRESERVED** per directive: 10 agent rows, 11 tasks, 129 messages,
  12 meeting messages, 16 proposals all KEPT. Authorship intact, availability removed.
- **LANGUAGE GUARD 2 -> 5 call sites** (+ mobile chat, + WordPress/SEO assistant, +
  historical message read). Added `SELF_SUFFICIENT_FRAMING`. Transactional-email wording
  ("SMTP is not configured", password-reset failures) verified PRESERVED.
- **`SeoAssistantService:2666`** was instructing the model to say *"The [Social/Marketing/
  CRM/Builder] section handles that"* - to WordPress SEO customers. Fixed.
- **PUBLIC WEBROOT:** 38 `.bak` files were fetchable (`/app/index.html.bak-20260622-bulkenrich`
  -> 200, 351 KB). Quarantined + NEW `nginx snippets/levelup-deny-artifacts.conf` on 4 server
  blocks. Two-arg `return 404 "..."` is deliberate: `error_page 404 /index.php` was answering
  artifact paths with the app shell.
- **PLAN TRUTH AT SOURCE:** `LaunchScopePolicy::filterPlanFeatures()` applied to
  `/api/billing/plans` + `PlanGatingService`. DB keys retained for one-UPDATE rollback.
- **CALENDAR:** `PublisherService::syncCalendar()` writes `Publish - Facebook` /
  `Status: published` rows. There was NO filter - the first pass's test 25 passed only
  because the table is empty. Now null-safe excluded; user rows survive.
- **PRODUCT DECISIONS (Boss):** Builder signup section KEPT (+ `email_signup`/`lead_capture`
  aliases). Key Competitors field KEPT - verified read by ZERO JS, so it unlocks nothing.

### CONDITION (the only one) - CLOUDFLARE EDGE CACHE
`/app/js/{social,marketing,automation,mentions}.js` are gone from origin but still
`cf-cache-status: HIT` (`max-age=14400`). `social.js` still carries "Connect your Facebook
Page so Marcus can publish and schedule posts." `CLOUDFLARE_API_TOKEN` is EMPTY in `.env`
so no programmatic purge is possible. **Boss action:** Cloudflare dashboard -> levelupgrowth.io
-> Caching -> Configuration -> Purge Custom URLs (the 4 URLs), then re-run the retest.

### QA ACCOUNTS - CREATED AND TORN DOWN SAME SESSION
3 users + 2 workspaces (990044/990045/990046, ws 990020/990021) for authenticated QA.
Removed via `scripts/w6-drop-qa-accounts.php --commit`: users 14->11, ws 22->20,
ws2 members 2->1. `tasks` 2037 and `articles` 346 UNCHANGED. Credentials never left
`/root/.w6qa-credentials` (0600), now deleted.

### OPERATIONAL NOTES
- `/api/auth/login` is `throttle:10,5` - repeated harness logins return 429. Reuse tokens.
- Never `php artisan config:cache` (RuntimeClient reads `env()`). `config:clear` only.
- This host serves live customer domains (chefredraymundo.com, amgtravelandtours.com,
  levelupgrowth.io) - it is PRODUCTION regardless of the `levelup-staging` path.

### NEXT -> W7 public marketing + pricing truth. All 10 removed specialists still appear in
### `resources/views/marketing/{specialists,features,home}.blade.php` AND the static
### `public/marketing/pages/*.html` twins. Blocked until the Cloudflare purge is confirmed.

---

# BOSS888 STATE - 2026-07-22 (W6)

### W6 COMPLETE WITH CONDITIONS (2026-07-22) - frontend launch-scope sanitation. LIVE.
Report: `BOSS888-W6-REPORT-2026-07-22.md`. **166 checks green (132 automated + 34 real headless
browser). No provider contacted.**
- **NEW `public/app/js/launch-scope.js`** - one authoritative allowlist enforced at sidebar, router,
  quick actions, chains, proposal rendering and restored browser state. Loads BEFORE core.js so
  nav() is wrapped pre-paint. **Activating Publisher later = removing 'publisher' from two arrays.**
- **Nav removed:** ni-social, ni-mentions, ni-marketing, ni-automation. Guard also strips anything
  wired to a removed view, matching labels, and empty sections.
- **SARAH ROOT CAUSE:** the launch-scope rule said "never propose" but never said HOW TO DECLINE, so
  the model invented "social media isn't connected — Marcus can't publish there". Fixed with an
  explicit refusal-language prompt block + NEW `LaunchScopeLanguageGuard` (deterministic backstop) +
  unhooked SocialContextProvider. Live retest: 16 replies, **0 removed specialists named, 0 "not
  connected/configured", 0 upgrade framing, 0 "coming soon"**.
- **Two subtle bugs the tests caught:** (1) matching used ASCII apostrophes but models emit U+2019,
  so the guard missed the exact sentence it existed to catch; (2) cache migration was gated entirely
  behind a version stamp, so a key rewritten afterwards by a stale tab was never cleaned - now
  two-tier (one-time purge + idempotent scrub every boot).
- **EXEC_INTENTS root cause:** intents were data with no policy between them and the renderer.
  Now filtered at intent array, chain array and _renderProposal. Browser-verified 19 retained / 0 removed.
- **CONDITIONS:** authenticated browser QA not done (needs a test login) - dashboard cards, Strategy
  Room, assignee dropdowns, billing/plan copy, onboarding not visually verified; mobile payload and
  WordPress plugin not verified; no command palette/global search exists in this SPA (N/A).
- **NEXT: W7** marketing/pricing truth. Check `marketing/specialists.blade.php` first - it likely
  still lists removed agents. Do NOT advertise Publisher/Facebook/Instagram until provider proof.

---

# BOSS888 STATE - 2026-07-22 (PROVIDER HARDENING)

### PUBLISHER PROVIDER: BLOCKED - META PERMISSIONS OR TEST ASSETS (2026-07-22)
Doc: `BOSS888-META-PROVIDER-BLOCKED-2026-07-22.md`. **109 tests green. NOTHING TRANSMITTED.
NO ROUTE ADDED OR REOPENED - all W4 routes incl. api/publisher/* still 404.**
- **THE BLOCKER:** publishing scopes were never even requested. `getAuthUrl()` asks only for
  `public_profile, pages_show_list`; the code comment says the publishing scopes need App Review.
  Missing: pages_manage_posts, pages_read_engagement, instagram_basic, instagram_content_publish.
  App is `app_type:0` (consumer, not Business), 1 admin, 0 test users, NO privacy/ToS URL (both
  required to even submit App Review). Zero connected accounts. No designated test assets.
  Meta's registered redirect currently 404s under W4 - which blocks the App Review screencast too.
- **SECURITY FINDING (fixed):** old OAuth put the workspace id in an UNSIGNED query param
  (`$state = "{ws}_{nonce}"`). Replaced by `SignedOAuthState` (HMAC, single-use, provider-bound).
- **SHIPPED (Meta-independent):** Transport seam (MockTransport has no HTTP at all; live sending OFF
  by default via config('publisher.live_transport', false) with no config file), MetaErrorMap,
  Facebook + Instagram connectors (IG uses the real container workflow), ConnectionHealth with six
  real states + encrypted credentials, approval token bound to caption_hash/media_hash/schedule_key,
  `publishing_unknown` + reconcile-before-retry.
- **3 REAL BUGS CAUGHT BY TESTS:** (1) failure_class varchar(32) overflowed INSIDE the failure
  handler; widened to 96. (2) honesty regression - mock "success" was marking rows `published`;
  now dry runs yield `dry_run_ok` / post status `validated`, external_post_id stays NULL.
  (3) `dry_run_ok` was terminal unconditionally, so enabling live transport would have skipped every
  validated post forever; now terminal only while the transport is dry.
- **NEXT (Boss):** Business-type app, privacy+ToS URLs, Business Verification, designated test Page +
  IG professional account, approve the route carve-out, submit App Review for the 4 scopes.
- **NEXT (me, once unblocked):** route carve-out -> real FB/IG proof -> then Sarah collaboration.
  Sarah remains UNWIRED from publishing by design until provider transmission is proven.

---

# BOSS888 STATE - 2026-07-22 (CONTENT PUBLISHER SCOPE CHANGE)

### SCOPE CHANGED: manual Content Publishing is RETAINED (2026-07-22)
Full doc: `BOSS888-PUBLISHER-SCOPE-CHANGE-2026-07-22.md`. **Publisher core LIVE on staging, 77/77 tests.**
Social Management / Social Intelligence stay REMOVED. Autonomous + recurring publishing stay REMOVED.
- **PLATFORMS DECIDED: Facebook + Instagram only.** LinkedIn/X have no credentials, Threads + Google
  Business have NO CODE AT ALL. They are modelled but never offered — advertising a channel we cannot
  publish to is a lie. See the table in the doc.
- **CORRECTION TO THE W5 REPORT:** SocialConnector's private createPost/publishPost POST to
  `{SOCIAL_CONNECTOR_URL}/api/social/posts`, an external microservice that was NEVER BUILT (env empty).
  There has never been a Graph API call here. Manual publishing is greenfield, not a restore.
- **NEW:** `app/Core/Distribution/PublisherService.php` + `publisher_posts` table. `social_posts` is
  REUSED as the per-platform variation/publication row (gains publisher_post_id). Not duplicated.
- **APPROVAL BOUNDARY IS STRUCTURAL:** `ArticleShareToken` v2 gained an `intent`; a manual-publish
  token cannot be minted without approval_method in EXPLICIT_APPROVALS (publish_now | schedule |
  ui_publish_button | ui_schedule_button | approval_click) AND an approver id. execute() needs that
  token. So upload/caption/edit CANNOT publish — autonomous publishing is unrepresentable, not just
  forbidden. Editing a variation clears approval and forces re-approval.
- **Still terminates at DryRunSocialConnector** — no provider transmission exists yet. Nothing is ever
  marked 'published' without a real send.
- **NEXT:** (1) real FB/IG Graph transmission, (2) reopen ONLY api/social/oauth/facebook/callback +
  new api/publisher/*, (3) Sarah collaboration (never approves for the user), (4) W6 nav rename
  Social -> Content Publisher, (5) W7 copy truth (FB+IG only, approval always required).

---

# BOSS888 STATE - 2026-07-22 (W5)

### W5 COMPLETE WITH CONDITIONS (2026-07-22) - retained article distribution. LIVE ON STAGING.
Full report: `BOSS888-W5-REPORT-2026-07-22.md`. **47/47 tests pass. No provider was ever contacted.**
- **NEW OWNER:** `app/Core/Distribution/ArticleDistributionService.php` - the retained service the
  `mode='article_share'` marker always implied but never had. Not an agent, not Marcus/Zoe.
  Plus CanonicalUrlResolver, ShareCaptionService, PlatformPolicy, DryRunSocialConnector,
  ArticleShareToken. PostPublishCoordinator rewritten to delegate to it.
- **ROOT CAUSE of the empty draft:** the coordinator queued a task whose payload had NO content and
  NO url; `SocialService::createPost()` line 79 wrote `content = $data['content'] ?? ''`. Nothing
  ever resolved a canonical URL. `article_share` was only a permission marker - no code branched on
  it. W3 stripped marcus/zoe correctly but no retained service took ownership.
- **SECURITY FIX:** `isArticleShareContext()` used to return TRUE for anyone supplying
  mode='article_share' / auto_share / source='blog_share' - unlocking create/publish/update/schedule
  with ARBITRARY content and no article. Now requires an **ArticleShareToken** (HMAC over ws +
  article + platform + account + canonical URL + idempotency key, keyed by APP_KEY); every signed
  claim must equal the live call, so tokens cannot be replayed onto another article/workspace.
- **PROVIDER PUBLISHING DOES NOT EXIST AND WAS NOT BUILT.** SocialConnector::publish() is a stub
  that never carries the caption; SOCIAL_CONNECTOR_URL empty; social_accounts=0; W4 404s the OAuth
  callbacks. The pipeline TERMINATES at DryRunSocialConnector, which has no HTTP client at all.
  Real transmission = separate, explicitly-authorised work (needs Graph impl + one reopened OAuth
  callback + a designated test Page).
- **DB:** social_posts += 10 additive nullable/defaulted columns + unique idempotency_key. Reversible.
- **ENVIRONMENT TRUTH:** there is NO separate production Laravel. /var/www/levelup-staging serves
  levelupgrowth.io AND live customer domains (chefredraymundo.com, amgtravelandtours.com).
  Treat it as production.
- **W3 probe item 9 (Sarah) DONE:** 8/8 refused, 0 removed-work tasks, 0 social_posts, 0 jobs.
  CONDITIONS: 3 replies named removed specialists (Marcus/Chris/Leo) and framed removal as
  "not connected" rather than "not in the product" -> fix in W6/W7.
- **W3 probe item 11 (Railway logs) STILL BOSS:** no railway CLI/token on the droplet.
- Rollback + residual risks: section 30/31 of the report. Backup /root/backups/w5-presrc-20260722-065615.tar.gz

### NEXT -> W6 (frontend: EXEC_INTENTS core.js:4171, nav, onboarding) -> W7 -> W8 -> 54-pt regression.

---

# BOSS888 STATE - 2026-07-22

### W3 RUNTIME v2.37.3 DEPLOYED + PROBED (2026-07-22) - BLOCKER CLEARED
Boss deployed to Railway. Verified against the LIVE service, not the off-Railway copy.
- `/health` = **version 2.37.3**, **10 agents** (dmm,james,alex,diana,ryan,sofia,priya,nora,elena,max),
  **43 tools**, 0 removed agents, 0 removed tools, 0 removed generation modes.
- `runtime-probe-2.37.3.sh`: **41 passed / 1 failed**. Handshake OK (secret accepted on
  `/internal/health`, unauthenticated = 401). All 7 out-of-scope generation modes REFUSED
  (social_post, email_generation, email_template_generate_v2, social_hashtag_pack,
  email_subject_suggest, email_block_rewrite, social_platform_adapt). Retained paths + Studio OK.
- **The 1 failure is PRE-EXISTING, not a launch-scope regression:** probe item 10 expects
  `export_website` in `/health`. It is granted to Alex in `capability-map.js:108` but has NEVER
  been defined in `tool-registry.js` - confirmed absent in the June 2026 `.bak` copies, months
  before this program. Same mismatch applies to `hydrate_page` and `export_page`.
  Effect: those three are unreachable, NOT leaked. No scope risk. Probe item 10 tests the
  registry while `launch-scope-validate.js:195` tests the capability map - two different layers,
  which is why off-Railway validation passed 87/87.
  **FOLLOW-UP (not blocking launch):** either register the 3 builder/export tools or drop them
  from Alex's capability map so the two layers agree.
- **STILL MANUAL (Boss):** probe item 9 (8 Sarah excluded-request chats in the app, expect refuse
  + redirect, 0 tasks/assignments for removed agents) and item 11 (`railway logs` clean +
  2 replicas Active).
- Rollback unchanged: one-click Railway redeploy of 2.37.2. Laravel kernel deny, cron guards, DB
  revocation and the boundary sanitizer are independent and stay live either way.

### W3 + W4 now both LIVE. NEXT -> W5 (article-share caption) -> W6 (frontend) -> W7 (plan/marketing
### copy truth) -> W8 (dormant data) -> 54-pt regression -> browser QA -> smoke. None need Railway.

---

# BOSS888 STATE - 2026-07-21

### RUNTIME v2.37.3 PACKAGED (2026-07-21) - RAILWAY DEPLOYMENT PENDING
**W3's runtime blocker is HALF-CLEARED.** Boss supplied the genuine v2.37.2 source (verified via
package.json). Patched -> validated -> packaged as **v2.37.3**. NOT deployed.
- **Package:** `levelup-runtime2-main-v2.37.3-launch-scope.zip` - SHA-256
  `CD33FFBF7E6D7A2F4B97035C221435284E9244EDD6606AFD745B5108E5BDE647` - 87 files, 313.2 KB.
- **Scope correction:** handoff named 6 files; real footprint was 14 (agents) / 20 (incl. tools).
  `registry.js` + `lu-agent-search.js` were already clean.
- **Changed:** 22 files + NEW `launch-scope.js` (runtime mirror of LaunchScopePolicy) +
  `launch-scope-validate.js`.
- **Validated OFF-RAILWAY** (Node 20.20.2, isolated /root/rt-w3rt, Redis DB9, workers off):
  node --check 77/77 - launch-scope 87/87 - intelligence 35/35 - boots clean - /health = 10 agents,
  43 tools, 0 removed - /ai/run refuses 6/6 out-of-scope, retained write_article passes.
- **Fixed pre-existing bug:** sparse-array holes made `hasCapability(agent, undefined)` return TRUE.
- **Caught 3 bugs before packaging:** list_campaigns leaked into Sarah's prompt via a param
  description; email_subject_suggest bypassed the guard; update_post sat in an executable dispatch
  allow-list bypassing registry+capmap.
- **`dmm` PRESERVED** as Sarah's key (~25 call sites). Removed work is unavailable, NOT reassigned.
- **PENDING (Boss):** deploy to Railway, then run `runtime-probe-2.37.3.sh`.
- **W3 IS NOT COMPLETE** until deployed + probes pass. Deployed roster/planner/Sarah verification
  all PENDING. Rollback = Railway redeploy of the last 2.37.2 build (one click).

### WORKSTREAM 4 COMPLETE (2026-07-21) - routes & public endpoints. LIVE ON STAGING.
NEW `app/Http/Middleware/LaunchScopeRoutes.php`, registered `bootstrap/app.php:429`.
**83 routes now return 404 `not_in_product`** (404 not 403 - 403 leaks that the surface exists):
all `api/social/*` incl. the PUBLIC OAuth callbacks (which could still store platform tokens),
`api/marketing/{campaigns,sequences,email,email-builder,automations}`, `api/mentions/*`,
`api/watchlists/*`, `api/studio/designs/*/publish-social`.
- Chose ONE middleware over ~60 closure edits in a 1.1 MB routes/api.php - reads the same
  LaunchScopePolicy as the kernel, so the route boundary cannot drift from the execution boundary.
- **Closed the real leak:** GET/read paths (listCampaigns/listSequences/listTemplates/
  getCampaignAnalytics/mentions stats) called services DIRECTLY, bypassing the W1 kernel deny, and
  were still returning real data. Write paths were already execution-dead.
- **Protect-list verified:** Stripe webhook 200, auth/password/email-verify/WP-plugin/internal OK.
- **Retained verified 200:** seo/keywords, seo/articles, write/articles, crm/leads, builder/pages,
  agents, tasks, studio/templates, calendar/events.
- **No email open/click tracking pixel routes exist** - nothing to remove there.
- Backups: `routes/api.php.bak-w4-20260721-134331`, `bootstrap/app.php.bak-w4-20260721-134331`,
  `/root/backups/w4-presrc-20260721-134331.tar.gz`.

### !! DO NOT RUN `php artisan config:cache` ON THIS APP !!
I ran it during W4 cache clearing and it BROKE the runtime connection: live
`app/Connectors/RuntimeClient.php` reads `env('RUNTIME_URL')`/`env('RUNTIME_SECRET')` DIRECTLY, and
`env()` returns null once config is cached. Result: 12 `RuntimeClient not configured` errors
14:00-14:06 and failed generate_meta tasks 2143/2144/2145.
**FIXED** via `config:clear` + fpm reload + worker restart (isConfigured: YES, 0 errors since 14:20).
Use `config:clear` only. FOLLOW-UP: finish the migration to a `config/services.php` entry - see
`RuntimeClient.php.bak-envconfig-20260707-194219`, started 07-07, never landed.
**Also:** this app logs to a SINGLE `storage/logs/laravel.log`, not `laravel-YYYY-MM-DD.log`.

### NEXT -> W5 (article-share caption) -> W6 (frontend) -> W7 (marketing/plan truth) -> W8 (dormant
data) -> 54-pt regression -> browser QA -> smoke. W5-W8 need NO Railway access.
The W4 guard's `[launch-scope]` INFO lines in laravel.log show exactly which surfaces the UI still
calls - use them to drive W6.

---

# BOSS888 STATE — 2026-07-20

### ✅ WORKSTREAM 3 COMPLETE (2026-07-20) — agent/runtime sanitation. Report: `BOSS888-W3-CHECKPOINT-2026-07-20.md`
**Removed 10 agents (marcus,jordan,tyler,zara,zoe,maya,vera,kai,chris,leo) + constrained 6 (sarah,priya,elena,max,nora,sofia) from the AUTHORITATIVE agent layer.**
- **DB grants:** all removed-agent grants + constrained social/email/sequence grants soft-disabled (`is_active=0`, reversible). Rollback `/root/backups/w3-capability-rollback.sql` + snapshots. `canUse` short-circuits removed regardless.
- **Source (22 files this session, `.bak-w3-*`):** routing landmines fixed (engine→agent maps, meeting candidates `AgentMeetingEngine`, `ProactiveRuleSet` rules 8/9/10 deleted, `PostPublishCoordinator` article-share OFF marcus + newsletter removed, `InstructionParser` fail-closed, badge/attribution maps); Sarah prompt surfaces sanitised (`ToolSchemaService`,`SarahDaily/Monthly`,`DiscoveryRun`,`PromptTemplates`); `AgentCapabilityService` grant()+accessors filtered; `WebActivityService.ALLOWED_AGENTS`; seeders launch-scope-aware (`AgentSeeder` dormant, seed migration filtered); **`TaskService::create` launch-scope guard** (memory-compat + routing safety net); **`RuntimeClient` boundary sanitizer**.
- **Roster APIs verified live:** `/api/internal/agents` = exactly 10 retained (alex present, 0 removed); customer rosters 0 removed.
- **Runtime (§5) BOUNDARY-ENFORCED, in-runtime rebuild BLOCKED:** deployed runtime v2.37.2 `/health` still lists 20 agents; Laravel `RuntimeClient` scrubs them → exposes only 10 retained. **Cannot rebuild/redeploy: no current runtime source (only stale 2.36.0 zip) + no Railway CLI/RAILWAY_TOKEN.** BOSS ACTION: provide 2.37.2 source + Railway access to sanitise capability-map.js/agents.js/registry.js/lu-planner.js/lu-agent-search.js/tool-registry.js.
- **Validation:** 64/64 automated assertions + 4 live Sarah negative chats (all refuse+redirect, 0 removed tasks/assignments created) + logs clean, workers 3/3.
- **INSTALL STATUS:** source ✅ modified · DB ✅ modified · runtime ⚠️ NOT built/deployed (blocked) · Laravel changes ✅ verified on staging · production untouched.
- **NEXT → W4** routes/public OAuth+email-tracking. (Sequence: W4→W5 blog-share caption→W6 frontend→W7 marketing/plan truth→W8 dormant data→regression→browser QA→smoke.) Do NOT re-checkpoint per accepted plan.

---


### 🚀 LAUNCH-SCOPE REMEDIATION IN PROGRESS (2026-07-20) — server-side enforcement DONE
Audit: `boss888-audit/LAUNCH-SCOPE-REMOVAL-AUDIT-2026-07-20.md` (SAFE WITH CONDITIONS, accepted).
Progress log: `boss888-audit/LAUNCH-REMEDIATION-PROGRESS-2026-07-20.md`. Backup `/root/backups/prelaunch-src-20260719-221710.tar.gz`.
**Removing: social automation/intelligence + email marketing + 10 agents. Retaining: SEO/Blog/Builder/CRM/Studio/Chatbot/Sarah + blog-share.**
**✅ W0:** baseline captured, ZERO drift vs audit, **Marcus identity RESOLVED** — single pure-social agent (id=12); Technical-SEO specialist is **Alex (id=3)**, NOT Marcus; Marcus=REMOVE correct.
**✅ W1 core:** NEW `app/Core/LaunchScope/LaunchScopePolicy.php` = single source of truth; wired as kernel Step-1a in `EngineExecutionService` (before credit/provider/job). Verified: 14 removed actions DENIED, retained blog-share + 6 core engines ALLOWED.
**✅ W2 core:** disabled crons `lu:sequences:run` + `mention:scan-daily` (gone from schedule:list); internal guards on RunSequences/queueSendCampaign/sendTestEmail/scanWatchlist — ALL verified REFUSING. Workers restarted.
**⏭ REMAINING:** W1-tail (read routes, plan copy) · W3 agent_capabilities revoke + Sarah 6 prompt surfaces + routing landmines + drop removed agents from `/api/internal/agents` (**runtime redeploy**) · W4 routes/public OAuth+email-tracking · W5 blog-share caption rebuild (reassign off marcus) · W6 frontend (EXEC_INTENTS core.js:4171, nav, onboarding) · W7 marketing/plan copy · W8 data archive-disable · 54-pt regression + browser QA + smoke.
**Locked decisions:** chris+leo=REMOVE agent (Studio video retained); email templates/blocks=ARCHIVE+hard-disable; blog-share caption=BUILD before launch; ContentPack=trace-then-dormant; prices unchanged but copy must be truthful.
⚠️ Execution hard-stop is complete+proven; remaining leaks are visibility/reasoning (agents in prompts, nav in Advanced mode), NOT execution.
### ✅ SESSION 2026-07-19 (D) — ALL-AGENT INTELLIGENCE + ANTI-FABRICATION
Two agents caught lying: James "I've queued the fix for myself — in progress", Priya "I've already flagged it
to Sarah" — both false; specialists CANNOT queue (only sarah is_dmm=1 delegates). Root cause: every rich
context block was inside `if($isSarah)`; the other 19 ran a ~10-line prompt. Four prompt rules failed to stop
it — same lesson as numeric fabrication: prompt=prevention not enforcement.
**Fix 1 — SHARED AGENT CORE** (`routes/api.php` else-branch, front-loaded): owner identity+NAMES ·
never-claim-unqueued-work · "you CANNOT queue, only Sarah can" · plain-language (no ids/raw-minutes/slugs) ·
escalate-don't-interrogate · disconnected-engines. All 19 specialists now Sarah-grade.
**Fix 2 — `AgentClaimValidator`** (`app/Core/Integrity/`, ENFORCEMENT): post-gen/pre-store at final-reply
insert (~api.php:3070). Sentence-level strip of completed/in-progress claims + role-correct honest line.
Specialists ALWAYS checked; Sarah only when this turn queued nothing (fresh `tasks.created_at>=now-25s` — the
in-scope counter isn't available there). `is_dmm` from DB, data-driven. **6/6 unit, 5/5 false-positive guard
(offers like "Shall I flag it to Sarah?" pass), live James strip confirmed in log.**
⚠️ RESIDUAL: validator kills the LIE reliably; James still sometimes INSTRUCTS the owner ("Please confirm once
you've done that") instead of escalating — prompt-level, improved not guaranteed. Not policed by validator
(would risk stripping legit approval/credential requests). Follow-up if it recurs.
Backups `.bak-agentcore/escalate/claimvalidator-*`. Server-side — live on installed APK, no rebuild.
Also this session: Sarah identity fix (called owner "Sarah"), disconnected-engines in her memory + dead
email/social approvals cleared, delegate-don't-instruct rule. All live+verified.
### ✅ 2026-07-18/19 SHIPPED (digest — full detail in daily-progress/2026-07-1{8,9}.md)
All LIVE + verified on staging. Backups per-file `.bak-*`; DB dumps in `/root/backups/`.
- **DataForSEO (DFS-F2):** account was broke since ~07-16 (402 hidden as "20000 Ok"); credit restored ~$49.70.
  Added response cache (serp 6h/keyword 7d PER-KEYWORD; ⛔ trackKeywordRank NEVER cached; ⛔ TTL_SERP<24h;
  Cache::=DB1 vs queue/sarah_state=DB0), `dfs_usage_log` http_status/ok/cache_hit, and SeoAssistant billing
  parity (was narrating "1 credit" while debiting 0; now reserve/commit at canonical serp1/ai_report2/audit3).
  ⚠️ generate_article/batch_articles NOT charged here — may already be metered in runtime; verify before extending.
- **SEO-DRAIN:** SarahAutoExecute `$spentToday` counted ALL workspace commits → drainer no-op; now scoped to
  own drain, **anchor=`approved_at` NOT updated_at (don't revert)**. ProactiveStrategyEngine 7-day blind wipe →
  signal-aware (gap>0 keeps pending; +`superseded_reason`).
- **Bugs:** improve_draft self-resolves target (was 9/10 failing; +IDOR fix, workspace-scoped); proposals can't
  report success when all tasks failed; mention-scan stamps only on success (fixed the 28-day lockout).
- **Runtime v2.37.2** (Boss-deployed): agent-search 3/5→2/5→**5/5** (per-route 75s budget + retry on transient
  40101 + depth 20→10 =43% cheaper). 28-day brand-monitoring outage CLOSED.
- **Web UI:** `LU_statusLabel()` — raw proposal/subscription status enums no longer leak as chip text.
  Cache-buster aligned 5.7.54.
- **Mobile v1.4.14 (APK BUILT `LevelUpGrowth_App/levelupgrowth-app888-v1.4.14.apk`, commit 7f25238):** in-chat
  Approve/reject were dead onPress → wired; Android reject (Alert.prompt iOS-only) → cross-platform Modal;
  attachments hit `undefined/media/upload` → apiBase; **logout loop** (refresh sent expired token in header →
  401 → logout; now auth:false + only genuine token-reject logs out) + **post-logout notifications** (device
  DELETE never checked res.ok → row survived; now parks+retries via flushPendingUnregister).
- **Four-surface audit** (`boss888-audit/FOUR-SURFACE-FORENSIC-AUDIT-2026-07-19.md`): WP v1.3.11 ships NO
  working SEO Assistant (admin-shell.js never enqueued; false v1.1.6 changelog). ws1 badge 57 vs Sarah ~3
  (different tables). No IDOR anywhere. **Agent voice LIVE:** 17/20 agents were mute; `agents:report-completions`
  hourly:20, completions-only-except-Sarah, batched as Sarah's delegation.
**⚠️ OPEN BOSS DECISIONS:** (1) backfill 6 falsely-`completed` proposals to failed, or keep history?
(2) Chef Red brand watchlist is in ws1 not ws2 — move? (3) ws2 email/social marked disconnected in memory +
dead approvals cleared — if another ws connects them, its own memory governs (not global).
## ⏸️ PAUSED 2026-07-17 (reboot) → READ `BOSS888-CHECKPOINT-2026-07-17.md` FIRST
═══════════════════════════════════════════════════════════════════════════
**Stopped mid-wiring the V2 Numerical Validator into the live chat flow.** Next action: deploy `boss888-ni/NumericalValidator.php` → `app/Core/Integrity/`, call it on `$reply` before `routes/api.php:~2920` (agent_messages insert) + the tool-follow-up path (~2232), then live-test + re-measure with the LOCKED scorer. LIVE on staging now: Phase-B NI mitigation (fabrication 43%→20%) + approval-followup gatherer. ws2 chat thread was CLEARED (backup: `boss888-ni/ws2_agent_messages_backup_20260716.sql`). Runtime approval-followup zip built, NOT deployed (Boss→Railway). Full detail: `BOSS888-CHECKPOINT-2026-07-17.md` (local `LVL\` + staging).

═══════════════════════════════════════════════════════════════════════════
## ➡️ HANDOFF (2026-07-16) — approval follow-up — archived summary
**Boss: "Sarah never follows up on approvals."** Fixed: `WorkspaceStateGatherer` had ZERO approval fields;
`readApprovalsState()` now surfaces `needs_owner_ok` (oldest-first) + count/age/credits, external asks only.
`SarahDailyOrchestrator` fallback prompt gained an "AWAITING YOUR OK" section. LIVE
(`.bak-approvals-20260716-idorfollowup`). **⚠️ Runtime half NOT deployed** — built to
`levelup-runtime2-main-2.36.0-approval-followup.zip` on `C:\Users\markr\LVL\`, **a path that does not exist
on this machine; rebuild before deploying.** Still open: 30+ stranded drafts need Boss's publish approval.
## ➡️ HANDOFF (2026-07-15 → 16) — archived summary
**Cross-tenant IDOR sweep across 9 engine surfaces — FIXED + VERIFIED** (CRM, Write, Builder incl.
custom-domain hijack, Social, Marketing incl. send, Calendar, Studio incl. duplicateDesign content-theft,
Creative route-guard, EES dispatch). Pattern: entities looked up by raw `id` with no `workspace_id` scope.
**Worth remembering:** several methods *received* `$wsId` but never used it to scope — `sendCampaign`,
`duplicateDesign`, `publishToSocial`, `saveExportToMedia`. Invisible to a signature grep. (Same class found
again 2026-07-18 in `WriteService::improveDraft` — see session 6.)
Detail: `BOSS888-SARAH-ENTERPRISE-AUDIT-2026-07-15.md` + `daily-progress/2026-07-15.md`.

## 🏗️ INFRA888 — INFRASTRUCTURE OPERATING SYSTEM (Phase 3B done 2026-07-20)
**No longer a feature: it is the infra OS inside LevelUp Growth. Canonical asset graph + operational
intelligence layer LIVE. First real capability (monitoring) feeds it. NO provider adapter (D1/D2 gated).**

### PHASE 3B — canonical intelligence foundation
- **Canonical asset graph**: infra_assets (10 types) + infra_asset_relationships (typed edges). ONE
  graph — hosted_sites/hosting_accounts/monitor_checks attach via asset_id; asset back-points via
  source_type/source_id. No parallel hierarchy.
- **First-class incidents**: infra_incidents (detected→ack→investigating→mitigated→resolved→closed,
  illegal transitions refused) + infra_incident_transitions (append-only, actor-attributed). MTTR on
  resolve. Monitoring PRODUCES into this one model (attached checks; bare checks = legacy bridge).
- **Historical intelligence**: MTTR/MTBF/uptime/latency/trends (deterministic SQL). infra_monitor_daily
  rollup + infra:rollup-monitor-daily (hourly) = retention foundation.
- **Executive API**: 6 read-only tenant-scoped endpoints /api/infrastructure/intelligence/* (dashboard/
  assets/asset/blast-radius/incidents/reliability). HTTP-verified against PTAA w/ real JWT.
- **PTAA in graph**: 3 assets (website+server ADOPTED, monitor managed_by_infra888), 2 edges, real
  health. management_mode enforces adopted != provisioned (audit integrity).

### INTELLIGENCE SPLIT (locked runtime rule honoured)
Deterministic aggregation + rule-based indicators = Laravel. Predictive/anomaly SCORING = runtime;
integration point built at risk_state surface (drops in without schema change). Rule-based = honest floor.

### FULL PLATFORM STATE
Governance (Founder Mode + credential roles + cert framework) + monitoring + canonical intelligence.
Tests: INFRA888 **314/0** (1 skip); full **357 pass / 23 fail (0 INFRA888) / 1 skip**.

### 🔴 BLOCKERS
- **D1** company CF account — OPEN. Gates provider ADAPTERS (populate DNS/SSL/deployment/cost/backup dims).
- **D2** diagnostics token — OPEN. Gates adapter validation.
- **D3** — resolved-in-principle by Founder Mode (founder MFA enrol pending = founder action).
- Model HOLDS gated dimensions as unknown/managed_externally — NOT faked. Only monitoring is real today.

### PARTIAL (honestly scoped next, not faked)
- Executive SPA dashboard VIEW: API done+HTTP-verified; the rendered /app view is the next milestone.
- Runtime predictive-scoring module: integration point done; deterministic floor working.
- Retention prune + queued monitoring fan-out: designed (scale levers), implement on scale ramp.

### NEXT MILESTONE (buildable now on real data, no adapter)
Enterprise SPA dashboard view on the intelligence API → retention prune + queued monitoring → founder
MFA enrol → (D1/D2) CF read-only adapter begins populating DNS/SSL dims via connector contracts.

### REFERENCES
boss888-audit/INFRA888/: PHASE-2B-1..R3, PHASE-3A-FOUNDER-MODE-PTAA-MONITORING,
PHASE-3B-ENTERPRISE-INTELLIGENCE-PLATFORM-2026-07-20.md. Daily: daily-progress/2026-07-19.md.
Scripts: infra888-verify-{control-plane,governance,diagnostics-cert,r3,ptaa-monitoring,intelligence}.php,
-onboard-ptaa.php, -reconcile-ptaa-graph.php, -cf-preflight.sh, -certify-cloudflare.php.

### ENVIRONMENT QUICK-REF
- Staging: `root@134.209.93.41`, key `~/.ssh/do_levelup`, Laravel at `/var/www/levelup-staging`, DB `levelup_staging`.
- Runtime: Railway v2.36.0 (Claude Code BUILDS zip to `C:\Users\User\Runtime`; Boss deploys).
- Test accounts: Laravel/APP888 → **chef-red (user2/ws2)**; WP connector → shukran (user6/ws7). AMG = ws26.
- tinker NOT installed → bootstrap Laravel in a standalone PHP script (`require vendor/autoload` + `bootstrap/app` + Kernel::bootstrap).
- After task-code edits: `queue:restart` + `supervisorctl restart levelup-worker:`.
- HARD RULES: CREATIVE888 logic LOCKED (route/connector fixes OK); backup before every edit; migrate:fresh PROHIBITED on prod; staging-only until Phase 9; server is source of truth (git drift — pull before local edits).
- Master docs on staging: `BOSS888-SARAH-ENTERPRISE-AUDIT-2026-07-15.md`, `BOSS888-PHASE2-CERTIFICATION-FINAL-2026-07-15.md`. Working plan: `boss888-audit/PLAN.md`; per-day notes: `boss888-audit/daily-progress/2026-07-15.md`.
