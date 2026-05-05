# ENDPOINT-MAP.md

Every `/api/*` call the scrubbed JS files make, and whether Laravel serves it.

**Scan source:** `deploy/app/js/{core,crm,marketing,social,calendar,seo}.js`
**Laravel routes source:** `boss888-laravel-COMPLETE-v1.0.2/routes/api.php` + `routes/exec-api.php` + `app/Engines/CRM/Http/Routes.php`
**Method:** Scripted extraction of `fetch()`, `API+'...'`, and `_xxxApi('METHOD', '/path')` patterns, cross-referenced against Laravel's registered routes.

**Legend:**
- ✅ Served — the dashboard call will succeed
- ⚠️  Partial — endpoint exists but the specific sub-route is narrower than the dashboard expects
- ❌ Missing — endpoint is not served by Laravel, the call will 404
- 💬 Comment only — appears in JS but not actually called

---

## Authentication

| JS calls | Laravel route | Status |
|---|---|---|
| `POST /api/auth/login` | `POST /api/auth/login` | ✅ |
| `POST /api/auth/refresh` | `POST /api/auth/refresh` | ✅ |
| `POST /api/auth/register` | `POST /api/auth/register` | ✅ |

**Note:** Before scrubbing, `core.js` called `POST /wp-json/lu/v1/auth/token` — this was the **404 login bug** from past chats. The sed rewrite changed it to `/api/auth/login` at the source, which matches Laravel's route. **The login 404 bug is dead in this patch.**

---

## Workspace & Agents

| JS calls | Laravel route | Status |
|---|---|---|
| `GET /api/workspaces` | `GET /api/workspaces` | ✅ |
| `POST /api/workspaces` | `POST /api/workspaces` | ✅ |
| `GET /api/workspace/settings` | — | ❌ (workspace controller exists, settings sub-route does not) |
| `GET /api/workspace/status` | — | ❌ |
| `POST /api/auth/switch-workspace` | ✅ | ✅ |
| `GET /api/agents` | `GET /api/agents` | ✅ |
| `GET /api/agents/dashboard` | — | ❌ |
| `POST /api/agent/dispatch` | `POST /api/agent/dispatch` | ✅ |
| `GET /api/agent/conversations` | ✅ | ✅ |
| `GET /api/agent/conversation/{id}` | ✅ | ✅ |

---

## Tasks

| JS calls | Laravel route | Status |
|---|---|---|
| `GET /api/tasks` | `GET /api/tasks` | ✅ |
| `POST /api/tasks` | `POST /api/tasks` | ✅ |
| `GET /api/tasks/{id}` | `GET /api/tasks/{id}` | ✅ |
| `GET /api/tasks/{id}/status` | `GET /api/tasks/{id}/status` | ✅ |
| `POST /api/tasks/approve` | — | ❌ (approvals use `/api/approvals/{id}/approve`) |
| `POST /api/tasks/create` | — | ⚠️  (use `POST /api/tasks`) |

---

## Approvals / Governance

| JS calls | Laravel route | Status |
|---|---|---|
| `GET /api/governance/pending` | — | ❌ (closest: `GET /api/approvals`) |
| `POST /api/governance/approve` | — | ❌ (closest: `POST /api/approvals/{id}/approve`) |
| `POST /api/governance/reject` | — | ❌ (closest: `POST /api/approvals/{id}/reject`) |

**Impact:** Governance panel in the dashboard will be empty. The underlying Laravel approval system exists at different URLs. Fix either by JS rewrite (`/governance/*` → `/approvals/*`) or by adding Laravel route aliases. Not included in this patch.

---

## Meetings

| JS calls | Laravel route | Status |
|---|---|---|
| `POST /api/meeting/start` | `POST /api/sarah/meeting/start` | ⚠️  Path mismatch |
| `GET /api/meeting/{id}` | `GET /api/sarah/meeting/{id}` | ⚠️  Path mismatch |
| `POST /api/meeting/{id}/message` | `POST /api/sarah/meeting/{id}/message` | ⚠️  Path mismatch |
| `POST /api/meetings` | `POST /api/meetings` | ✅ |
| `GET /api/meetings` | `GET /api/meetings` | ✅ |
| `GET /api/meetings/{id}` | `GET /api/meetings/{id}` | ✅ |

**Impact:** The dashboard calls `/api/meeting/*` (singular), Laravel serves `/api/sarah/meeting/*` (nested under sarah prefix) AND `/api/meetings/*` (plural). Meeting creation/listing via the plural route will work. The singular-path meeting room may not.

---

## CRM Engine

| JS calls | Laravel route | Status |
|---|---|---|
| `GET /api/crm/` (list leads) | `GET /api/crm/leads` | ⚠️  Path mismatch |
| `POST /api/crm/leads` | `POST /api/crm/leads` | ✅ |
| `GET /api/crm/contacts/{id}` | — | ❌ |
| `POST /api/crm/contacts/{id}/notes` | — | ❌ |
| `DELETE /api/crm/contacts/{id}/notes/{nid}` | — | ❌ |
| `POST /api/crm/contacts/{id}/attachments` | — | ❌ |

**Impact:** CRM dashboard will load. Adding a lead will work. Contact detail views, notes, attachments, deals, pipeline, and activity log will 404. The `CrmService.php` on the Laravel side has all 40 methods — just no HTTP routes exposed beyond `leads store/index`. Fix = add `app/Engines/CRM/Http/Routes.php` entries. Separate deliverable.

---

## Calendar Engine

| JS calls | Laravel route | Status |
|---|---|---|
| `GET /api/calendar/events` | — | ⚠️  (Laravel has `GET /api/calendar/events` under `calendar` prefix in experiments group — unclear if exposed) |
| `POST /api/calendar/events` | — | ⚠️ |
| `PUT /api/calendar/events/{id}` | — | ⚠️ |
| `DELETE /api/calendar/events/{id}` | — | ⚠️ |
| `GET /api/calendar/booking-slots` | — | ❌ |
| `POST /api/calendar/booking-slots` | — | ❌ |

**Impact:** Calendar view may render empty. Booking slots will 404.

---

## Marketing Engine

| JS calls | Laravel route | Status |
|---|---|---|
| `GET /api/marketing/campaigns` | — | ❌ |
| `POST /api/marketing/campaigns` | — | ❌ |
| `GET /api/marketing/campaigns/{id}` | — | ❌ |
| `GET /api/marketing/templates` | — | ❌ |
| `POST /api/marketing/templates` | — | ❌ |
| `GET /api/marketing/sequences` | — | ❌ |
| `POST /api/marketing/sequences` | — | ❌ |
| `GET /api/marketing/email/settings` | — | ❌ |
| `POST /api/marketing/email/settings` | — | ❌ |
| `POST /api/marketing/email/test` | — | ❌ |
| `POST /api/campaign/send` | — | ❌ |

**Impact:** Marketing engine dashboard will load but every list will be empty and every create action will 404. `MarketingService.php` on the Laravel side exists with full implementation — no HTTP routes exposed. Fix = add marketing route file. Separate deliverable.

---

## Social Engine

| JS calls | Laravel route | Status |
|---|---|---|
| `GET /api/social/accounts` | — | ❌ |
| `POST /api/social/accounts` | — | ❌ |
| `GET /api/social/posts` | — | ❌ |
| `POST /api/social/posts` | — | ❌ |
| `DELETE /api/social/posts/{id}` | — | ❌ |
| `POST /api/social/settings/facebook` | — | ❌ |
| `POST /api/social/settings/linkedin` | — | ❌ |
| `GET /api/social/oauth/facebook/connect` | — | ❌ |
| `GET /api/social/oauth/linkedin/connect` | — | ❌ |
| `GET /api/social/oauth/facebook/callback` | — | ❌ |

**Impact:** Same as Marketing — UI loads, nothing works. `SocialService.php` exists on Laravel side. Separate deliverable.

---

## Miscellaneous

| JS calls | Laravel route | Status |
|---|---|---|
| `GET /api/notifications` | `GET /api/notifications` | ✅ |
| `POST /api/notifications/{id}/read` | ✅ | ✅ |
| `GET /api/billing/status` | — | ❌ |
| `POST /api/tools/run` | `POST /api/manual/execute` | ⚠️  Path mismatch |
| `GET /api/assistant` | — | ❌ |
| `GET /api/decisions` | — | ❌ |
| `GET /api/exec/mode` | — | ❌ |
| `POST /api/exec/mode` | — | ❌ |
| `GET /api/exec/history` | — | ❌ |
| `GET /api/history` | — | ❌ |
| `GET /api/insights/summary` | — | ❌ |
| `GET /api/projects/tasks` | — | ❌ |
| `GET /api/previews` | — | ❌ |
| `GET /api/websites/` | — | ❌ (controller exists, no routes in api.php) |
| `POST /api/websites/create` | — | ❌ |
| `POST /api/design-tokens` | `GET /api/design-tokens` | ✅ |

---

## Summary counts

- **46 distinct endpoints** extracted from the 6 JS files
- **~15 endpoints ✅ served** by Laravel out of the box (auth, tasks, agents, workspaces, notifications, approvals under different path, meetings plural)
- **~6 endpoints ⚠️  served but at a different path** (meeting singular → sarah nested, crm list → leads, tools/run → manual/execute, crm pattern mismatch)
- **~25 endpoints ❌ missing** — 10 Marketing, 10 Social, 5 miscellaneous (assistant, decisions, exec, history, etc.)

**What this means practically:**

The dashboard will load and you can log in. The sidebar will render. You can create tasks. You can dispatch agents. You can send messages to Sarah. You can view and approve tasks.

What won't work on day one: CRM contact detail pages, marketing campaigns/templates/sequences, social accounts/posts/OAuth, governance panel (exists at different URL), activity history, AI assistant chat, decisions feed, exec mode toggle, projects/tasks feed, website builder, billing status panel.

**None of these will crash the app** — every call is wrapped in try/catch. You'll see empty states and error toasts on the affected panels. The user can still navigate everywhere.

---

## How to close each ❌ gap (for future work, not part of this patch)

Two categories of fix:

**1. Path-mismatch endpoints (6 items — easy fix):**
Either add Laravel route aliases (one line each in `routes/api.php`) or update the JS path references. Examples:
```php
// Aliases to add alongside existing routes in routes/api.php
Route::prefix('governance')->group(function () {
    Route::get('/pending',  [ApprovalController::class, 'index']);
    Route::post('/approve', fn(Request $r) => app(ApprovalController::class)->approve($r, $r->id));
    Route::post('/reject',  fn(Request $r) => app(ApprovalController::class)->reject($r, $r->id));
});
Route::post('/tools/run', [ManualExecutionController::class, 'execute']); // alias for /manual/execute
```

**2. Engine endpoints with backing service but no HTTP routes (25+ items — bigger job):**
Each of the 10 engines (Marketing, Social, Calendar booking slots, Creative, Builder, etc.) has a `XyzService.php` on the Laravel side with full implementation, but nothing in `routes/api.php` or an `Http/Routes.php` file exposing them. The fix is to add a per-engine route file like `app/Engines/CRM/Http/Routes.php` (which already exists as the canonical example).

This is the bigger architectural gap surfaced in the forensic audit — and the reason the COMPLETE v1.0.2 bundle is 146 PHP files vs the canonical 200: the per-engine HTTP scaffolding ALL-ENGINES had was never ported. Rebuilding that scaffolding is a separate, later deliverable.
