# BOSS888 Intelligence Layer v2.0.0 — Install Guide

## What this package is

A patch on top of `boss888-laravel-ALL-ENGINES.zip` that makes Sarah actually think.

Before this release, the intelligence layer was a **ghost layer**: structurally
wired but semantically dead. Every `recordToolUsage()` call was a silent no-op
because no blueprints ever made it into the database. Sarah had dependencies
injected but never called them. This patch fixes all of that and closes the
learning loop.

## Sarah's 4 priorities — status after install

| # | Priority | Fix |
|---|---|---|
| 1 | Must query engine intelligence | `generateStrategy()` injects `buildEnginePrompt()` per engine into LLM context |
| 2 | Must select tools dynamically | `buildTaskSequence()` calls `ToolSelectorService` with 4-D scoring |
| 3 | Must estimate real costs | `estimateCredits()` calls `ToolCostCalculatorService` (reads blueprint metadata) |
| 4 | Feedback loop must close | Step 10 routes through `ToolFeedbackService::record()` |

## What's in the package

**5 new files:**
- `app/Core/Intelligence/ToolSelectorService.php`
- `app/Core/Intelligence/ToolCostCalculatorService.php`
- `app/Core/Intelligence/ToolFeedbackService.php`
- `app/Console/Commands/IntelligenceSeedCommand.php`
- `app/Console/Commands/IntelligenceAuditCommand.php`

**5 edited files:**
- `app/Core/Intelligence/EngineIntelligenceService.php` (full rewrite: 11 engines, seedAll, self-healing recordToolUsage)
- `app/Core/Orchestration/SarahOrchestrator.php` (3 methods refactored + createPlan upgraded)
- `app/Core/EngineKernel/EngineExecutionService.php` (Step 10 wired to ToolFeedbackService)
- `app/Core/Workspaces/WorkspaceService.php` (lazy seed hook)
- `app/Providers/AppServiceProvider.php` (3 new singletons registered)

**1 new migration:**
- `database/migrations/2026_04_05_000001_seed_intelligence_data.php` (deploy-time seed)

**1 doc update:**
- `docs/BOSS888-MASTER-DIRECTORY.md` (Section D change log entry)

**1 deletion (do this manually after extracting):**
- `app/Engines/CRM/Services/LeadService.php` — stale duplicate, must be removed

## Installation

### Step 1 — Extract over the existing bundle

```bash
cd /path/to/boss888-laravel
unzip -o boss888-laravel-intelligence-v1.0.0.zip
```

The zip preserves the project directory structure and will overwrite only the
12 changed files. Nothing else is touched.

### Step 2 — Delete the stale file

```bash
rm app/Engines/CRM/Services/LeadService.php
```

### Step 3 — Run the migration

```bash
php artisan migrate
```

This runs `2026_04_05_000001_seed_intelligence_data.php` which calls
`EngineIntelligenceService::seedAll()` and populates all blueprints,
practices, and constraints for all 11 engines.

### Step 4 — Verify

```bash
php artisan intelligence:audit
```

Expected output:
- All 11 engines with "✅ seeded" or "🟡 seeded, no usage" status
- Engines seeded: 11 / 11
- Sarah dependency health:
  - EngineIntelligenceService injected: ✅
  - ToolSelectorService injected: ✅
  - ToolCostCalculatorService injected: ✅
  - engineIntel actually called: ✅ N calls
  - toolSelector actually called: ✅ N calls
- Final verdict: "🟡 Intelligence layer is SEEDED but not yet producing data"
  (expected on fresh install — changes to ✅ HEALTHY after first real Sarah run)

### Step 5 — Run a real task through Sarah, then audit again

```bash
# Via SPA: go to Strategy Room, set a goal, approve the plan
# Or via API: POST /api/sarah/goal { "goal": "..." }

php artisan intelligence:audit
```

You should now see:
- Engines with usage: N > 0
- Some tools with "scored_tools" count > 0
- Final verdict: "✅ Intelligence layer is HEALTHY and operational"

## Architectural guarantees after install

1. **Ghost layer is dead.** Three independent paths guarantee the
   `engine_intelligence` table is never empty: (a) the deploy migration,
   (b) the lazy hook in `WorkspaceService::ensureIntelligenceSeeded()`,
   (c) the self-healing `recordToolUsage()` auto-creates stub rows on first
   use for known engines.

2. **Sarah actually thinks.** Every plan stored in
   `execution_plans.strategy_json` now contains the full selection trace:
   4-dimension scores per tool, justification strings, rejected tools with
   reasons, per-task cost breakdown, overall confidence score.

3. **Cold start is honest.** NULL effectiveness scores are treated as 0.5
   neutral priors in the scorer. No synthetic data is written to the DB.

4. **Dead dependencies are detectable.** `intelligence:audit` parses
   `SarahOrchestrator.php` source and counts actual method calls on
   `$this->engineIntel->` and `$this->toolSelector->`. If either is zero,
   the command reports `❌ DEAD DEPENDENCY` with a loud error. This prevents
   future ghost-layer regressions.

## Rollback

If something goes wrong:

```bash
php artisan migrate:rollback --step=1
```

The migration's `down()` clears only seeded rows (rows with NULL
`effectiveness_score` and no `auto_created` metadata flag), preserving any
real usage data that accumulated between install and rollback.

To fully revert the code: re-extract the previous bundle
(`boss888-laravel-ALL-ENGINES.zip`) over the directory.

## Lint status

PHP 8.3 `php -l` passes cleanly on all 10 edited/created PHP files plus the
modified `AppServiceProvider.php`. No syntax errors.

## Known limitations

- **First run scoring is neutral.** Until real tasks have completed, every
  tool gets a 0.5 effectiveness score from the scorer. This is intentional
  (cold-start honesty) and resolves organically as tasks run.
- **Past success query targets `lu_tasks` table.** If that table does not
  exist in your environment, `computePastSuccess()` falls back to the 0.5
  neutral prior. No error is raised.
- **Blueprint-based cost estimation** relies on blueprint `credit_cost`
  metadata. Tools without a blueprint fall back to the legacy per-engine
  map (`ToolCostCalculatorService::FALLBACK_COSTS`). Monitor the
  `unknown_tools` field in cost breakdowns.

---

**Version:** intelligence-v1.0.0
**Target:** boss888-laravel-ALL-ENGINES.zip
**PHP requirement:** 8.2+
**Install status:** SAFE TO INSTALL — STAGING ONLY
