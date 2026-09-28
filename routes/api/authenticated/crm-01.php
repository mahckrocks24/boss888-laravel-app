<?php

/**
 * CR-22B — extracted route module: crm-01
 *
 * Source: routes/api.php lines 4113-4345 of the authoritative pre-extraction
 * file (sha256 9aa519a1445f8e26…), copied VERBATIM — not reformatted, reordered
 * or edited in any way.
 *
 * Included from INSIDE the authenticated group closure
 *   Route::middleware(['auth.jwt','traffic.defense','connector.brand'])->group(...)
 * at the exact position the code previously occupied, so middleware stack,
 * prefix nesting and registration order are unchanged. PHP `require` executes in
 * the including scope, so parent-closure variables remain visible.
 *
 * `use` aliases, however, do NOT cross a require boundary — they are resolved
 * per file at compile time. A missing import does not fatal: `TaskController::class`
 * silently becomes the string "TaskController" and the route registers against a
 * wrong action. The FULL parent import set is therefore re-declared below,
 * unconditionally, in every module. Unused imports trigger no autoload and cost
 * nothing; a missing one is a silent production defect.
 *
 * Owner: CRM   ·   Routes: 60   ·   Statements: 1
 *
 * CR-22 scope forbids improving anything in this file. Move it, do not edit it.
 */

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DesignTokenController;
use App\Http\Controllers\Api\EngineController;
use App\Http\Controllers\Api\ManualExecutionController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

// ==== CR-22B MODULE BODY BEGINS - verbatim from routes/api.php, do not edit ====
    // ══════════════════════════════════════════════════════════════
    // ENGINE ROUTES (Phase 2) — direct CRUD for reads, execution bridge for writes
    // ══════════════════════════════════════════════════════════════

    // ── CRM Engine (100% complete — 32 routes) ─────────────────
    Route::prefix('crm')->group(function () {
        $c = \App\Engines\CRM\Http\Controllers\CrmController::class;

        // CRM-UX-2 (Clients revamp Phase 2): the new Clients screens
        $k = \App\Engines\CRM\Http\Controllers\ClientsController::class;
        Route::get('/setup', [$k, 'setup']);
        Route::put('/setup/{businessId}', [$k, 'saveSetup'])->whereNumber('businessId');
        Route::get('/today-v2', [$k, 'today']);
        Route::get('/reports-v2', [$k, 'reports']);
        Route::get('/clients', [$k, 'index']);
        Route::post('/clients', [$k, 'store']);
        Route::post('/clients/bulk', [$k, 'bulk']);
        Route::get('/clients/{id}', [$k, 'show'])->whereNumber('id');
        Route::put('/clients/{id}/stage', [$k, 'stage'])->whereNumber('id');
        Route::put('/clients/{id}/fields', [$k, 'fields'])->whereNumber('id');
        // CRM-SARAH-3: Sarah in Clients
        Route::get('/clients/{id}/summary', [$k, 'summary'])->whereNumber('id');
        Route::get('/drafts', [$k, 'drafts']);
        Route::post('/drafts/{id}/send', [$k, 'sendDraft'])->whereNumber('id');
        Route::post('/drafts/{id}/skip', [$k, 'skipDraft'])->whereNumber('id');
        Route::match(['get', 'put'], '/autoreply/{businessId}', [$k, 'autoreply'])->whereNumber('businessId');

        // Leads (12 routes)
        Route::get('/leads', [$c, 'listLeads']);
        Route::post('/leads', [$c, 'createLead']);
        Route::get('/leads/export', [$c, 'exportLeads']);
        Route::post('/leads/import', [$c, 'importLeads']);
        Route::get('/leads/{id}', [$c, 'getLead']);
        Route::put('/leads/{id}', [$c, 'updateLead']);
        Route::delete('/leads/{id}', [$c, 'deleteLead']);
        Route::post('/leads/{id}/restore', [$c, 'restoreLead']);
        Route::post('/leads/{id}/score', [$c, 'scoreLead']);
        Route::post('/leads/{id}/assign', [$c, 'assignLead']);

        // Contacts (7 routes)
        Route::get('/contacts', [$c, 'listContacts']);
        Route::post('/contacts', [$c, 'createContact']);
        Route::get('/contacts/duplicates', [$c, 'findDuplicates']);
        Route::get('/contacts/{id}', [$c, 'getContact']);
        Route::put('/contacts/{id}', [$c, 'updateContact']);
        Route::delete('/contacts/{id}', [$c, 'deleteContact']);
        Route::post('/contacts/merge', [$c, 'mergeContacts']);

        // Deals (5 routes)
        Route::get('/deals', [$c, 'listDeals']);
        Route::post('/deals', [$c, 'createDeal']);
        Route::get('/deals/{id}', [$c, 'getDeal']);
        Route::put('/deals/{id}', [$c, 'updateDeal']);
        Route::put('/deals/{id}/stage', [$c, 'updateDealStage']);

        // Pipeline stages (5 routes)
        Route::get('/pipeline', [$c, 'pipeline']);
        Route::get('/stages', [$c, 'stages']);
        Route::post('/stages', [$c, 'createStage']);
        Route::put('/stages/{id}', [$c, 'updateStage']);
        Route::delete('/stages/{id}', [$c, 'deleteStage']);
        Route::post('/stages/reorder', [$c, 'reorderStages']);

        // Activities (4 routes)
        Route::get('/activities', [$c, 'listActivities']);
        Route::post('/activities', [$c, 'logActivity']);
        Route::post('/activities/{id}/complete', [$c, 'completeActivity']);
        Route::put('/activities/{id}', [$c, 'updateActivity']);     // CRM-FIX-0: tick / reopen a task
        Route::delete('/activities/{id}', [$c, 'deleteActivity']);  // CRM-FIX-0: delete a note, call or task
        Route::get('/today', [$c, 'todayView']);

        // Notes (3 routes)
        Route::get('/notes', [$c, 'listNotes']);
        Route::post('/notes', [$c, 'addNote']);
        Route::delete('/notes/{id}', [$c, 'deleteNote']);

        // CRM settings & views (frontend compat)
        Route::get("/settings", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get("workspace_id");
            $stages = app(\App\Engines\CRM\Services\CrmService::class)->getStages($wsId);
            $crmSet = (json_decode((string) \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->value('settings_json'), true) ?: [])['crm'] ?? [];
            return response()->json([
                "business_type" => $crmSet['business_type'] ?? 'general',
                "stages" => collect($stages)->map(fn($s) => ["id" => is_object($s) ? $s->id : $s, "label" => is_object($s) ? $s->name : (string)$s, "color" => is_object($s) ? ($s->color ?? "#6C5CE7") : "#6C5CE7"])->values()->toArray(),
                "statuses" => [["id" => "active", "label" => "Active"], ["id" => "inactive", "label" => "Inactive"]],
                "categories" => [],
                "sources" => [["id" => "manual", "label" => "Manual"], ["id" => "import", "label" => "Import"], ["id" => "api", "label" => "API"]],
                "tags" => [],
            ]);
        });
        Route::get("/views", fn() => response()->json([]));
        // CRM-FIX-0: the Settings tab saves (it called a route that did not exist)
        Route::put("/settings", function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get("workspace_id");
            $row = \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->first(['settings_json']);
            if (! $row) return response()->json(['success' => false, 'message' => 'Workspace not found.'], 404);
            $set = json_decode((string) $row->settings_json, true) ?: [];
            $crm = $set['crm'] ?? [];
            $types = ['general', 'clinic', 'real_estate', 'agency', 'contractor'];
            if ($r->filled('business_type')) {
                if (! in_array($r->input('business_type'), $types, true)) return response()->json(['success' => false, 'message' => 'Unknown business type.'], 422);
                $crm['business_type'] = $r->input('business_type');
            }
            if (is_array($r->input('enabled_modules'))) {
                $crm['modules'] = ['appointments' => (bool) ($r->input('enabled_modules')['appointments'] ?? true)];
            }
            $set['crm'] = $crm;
            \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->update(['settings_json' => json_encode($set), 'updated_at' => now()]);
            return response()->json(['success' => true, 'business_type' => $crm['business_type'] ?? 'general']);
        });

        // Nested contact notes & attachments (frontend compat)
        Route::get('/contacts/{contactId}/notes', [$c, 'listNotes']);
        Route::post('/contacts/{contactId}/notes', [$c, 'addNote']);
        Route::delete('/contacts/{contactId}/notes/{noteId}', fn(\Illuminate\Http\Request $r, $contactId, $noteId) => app($c)->deleteNote($r, (int) $noteId)); // CRM-FIX-0: was deleting by contactId
        Route::post('/contacts/{contactId}/attachments', fn(\Illuminate\Http\Request $r, $contactId) => response()->json(["message" => "Attachments not yet implemented"], 501));
        Route::get('/contacts/{contactId}/attachments', fn(\Illuminate\Http\Request $r, $contactId) => response()->json(["attachments" => []]));

        // Revenue & Reporting (2 routes)
        Route::get('/revenue', [$c, 'revenue']);
        Route::get('/reporting', [$c, 'reporting']);

        // AI Outreach Generation — routed through Creative blueprint
        Route::post('/outreach/generate', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            return response()->json(
                app(\App\Engines\CRM\Services\CrmService::class)->generateOutreach($wsId, $r->all())
            );
        });
        Route::post('/outreach/follow-up', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            return response()->json(
                app(\App\Engines\CRM\Services\CrmService::class)->generateFollowUp($wsId, $r->all())
            );
        });

        // ── CRM route aliases (frontend path compat for restored crm-engine.js v3.6.0) ──
        Route::get('/dashboard', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $s = app(\App\Engines\CRM\Services\CrmService::class);
            $leads = $s->listLeads($wsId, ['business_id' => $r->input('business_id'), 'limit' => 200]); // CRM-DATA-1: per business
            $leadsList = collect($leads['leads'] ?? []);
            $stages = \Illuminate\Support\Facades\DB::table('pipeline_stages')->where('workspace_id', $wsId)->orderBy('position')->get();
            $todayActivities = \Illuminate\Support\Facades\DB::table('activities')->where('workspace_id', $wsId)->where('created_at', '>=', now()->startOfDay())->count();

            // CRM-2 (2026-08-29): the widgets crm.js renders. Leads carry a status (new/contacted/
            // qualified/…), not a pipeline stage id, so "Leads by Stage" is the status funnel — labelled
            // with the workspace's stage names when they line up by position, else the status itself.
            $statusOrder = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'converted', 'lost'];
            $byStatus = $leadsList->groupBy(fn($l) => strtolower((string) ($l['status'] ?? $l->status ?? 'new')))->map->count();
            $leadsByStage = [];
            foreach ($statusOrder as $st) {
                $n = (int) ($byStatus[$st] ?? 0);
                if ($n === 0 && !in_array($st, ['new', 'contacted', 'qualified'], true)) continue;
                $leadsByStage[] = ['name' => ucfirst($st), 'status' => $st, 'count' => $n];
            }
            foreach ($byStatus as $st => $n) {
                if (!in_array($st, $statusOrder, true)) $leadsByStage[] = ['name' => ucfirst((string) $st), 'status' => $st, 'count' => (int) $n];
            }
            $leadsBySource = $leadsList->groupBy(fn($l) => (string) (($l['source'] ?? $l->source ?? '') ?: 'unknown'))
                ->map(fn($g, $src) => ['source' => $src, 'source_website' => $src, 'count' => $g->count()])->values()->all();
            $todayTasks = \Illuminate\Support\Facades\DB::table('activities')
                ->where('workspace_id', $wsId)->where('type', 'task')->where('completed', 0)
                ->whereNotNull('scheduled_at')->where('scheduled_at', '<', now()->endOfDay())
                ->orderBy('scheduled_at')->limit(20)
                ->get(['id', 'subject', 'description', 'scheduled_at', 'activitable_id', 'activitable_type'])
                ->map(fn($t) => ['id' => $t->id, 'title' => $t->subject ?: mb_substr((string) $t->description, 0, 80), 'due_date' => $t->scheduled_at,
                                 'status' => 'open', 'lead_id' => $t->activitable_type === 'Lead' ? $t->activitable_id : null])->all();
            $upcoming = \Illuminate\Support\Facades\DB::table('calendar_events')
                ->where('workspace_id', $wsId)->where('starts_at', '>=', now())
                ->whereNotIn('category', ['booking_declined', 'cancelled'])
                ->orderBy('starts_at')->limit(8)
                ->get(['id', 'title', 'starts_at', 'ends_at', 'category', 'reference_type', 'reference_id'])
                ->map(fn($e) => ['id' => $e->id, 'title' => $e->title, 'starts_at' => $e->starts_at, 'start_at' => $e->starts_at, 'ends_at' => $e->ends_at,
                                 'category' => $e->category, 'lead_id' => ($e->reference_type === 'Lead' || $e->reference_type === 'lead') ? $e->reference_id : null])->all();

            return response()->json([
                'total_leads' => $leads['total'] ?? $leadsList->count(),
                'pipeline_value' => $leadsList->sum('deal_value'),
                'stages_count' => $stages->count(),
                'today_activities' => $todayActivities,
                'recent_leads' => $leadsList->take(5)->toArray(),
                'leads_by_stage' => $leadsByStage,
                'leads_by_source' => $leadsBySource,
                'today_tasks' => $todayTasks,
                'upcoming_appointments' => $upcoming,
            ]);
        });

        Route::get('/pipeline/stages', [$c, 'stages']);  // alias: crm-engine.js calls /pipeline/stages, Laravel has /stages

        Route::get('/modules', function (\Illuminate\Http\Request $r) {
            // CRM modules config — returns enabled module flags
            // CRM-FIX-0: only switches that change the screen. The old list showed four toggles that did nothing.
            $wsId = (int) $r->attributes->get('workspace_id');
            $crmSet = (json_decode((string) \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->value('settings_json'), true) ?: [])['crm'] ?? [];
            return response()->json([
                'leads' => ['enabled' => true, 'required' => true, 'label' => 'Leads, pipeline and tasks'],
                'appointments' => ['enabled' => (bool) ($crmSet['modules']['appointments'] ?? true), 'required' => false, 'label' => 'Appointments and bookings'],
            ]);
        });

        Route::get('/projects', [$c, 'listDeals']);  // alias: crm-engine.js "projects" are Laravel "deals"
        Route::post('/projects', fn(\Illuminate\Http\Request $r) => app($c)->createDeal($r));
        Route::get('/projects/{id}', fn(\Illuminate\Http\Request $r, $id) => app($c)->getDeal($r, $id));
        Route::put('/projects/{id}', fn(\Illuminate\Http\Request $r, $id) => app($c)->updateDeal($r, $id));
        Route::delete('/projects/{id}', function (\Illuminate\Http\Request $r, $id) { // CRM-FIX-0: said deleted, deleted nothing
            $d = \App\Models\Deal::where('workspace_id', (int) $r->attributes->get('workspace_id'))->find((int) $id);
            if (! $d) return response()->json(['success' => false, 'message' => 'Project not found.'], 404);
            $d->delete();
            return response()->json(['success' => true, 'deleted' => true]);
        });

        Route::get('/appointments', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $upcoming = $r->boolean('upcoming');
            $events = \Illuminate\Support\Facades\DB::table('calendar_events')
                ->where('workspace_id', $wsId)
                ->when($upcoming, fn($q) => $q->where('starts_at', '>=', now())->whereNotIn('category', ['cancelled', 'booking_declined']))
                ->where('category', '!=', 'task_deadline') // CRM-FIX-0: tasks live in the Tasks tab
                ->orderBy('starts_at')
                ->limit(50)
                ->get();
            // CRM-FIX-0: the screen reads start_at / end_at / status / lead_name
            $leadIds = $events->filter(fn($e) => in_array(strtolower((string) $e->reference_type), ['lead', 'app\\models\\lead'], true))->pluck('reference_id')->all();
            $names = $leadIds ? \Illuminate\Support\Facades\DB::table('leads')->where('workspace_id', $wsId)->whereIn('id', $leadIds)->pluck('name', 'id') : collect();
            $events = $events->map(function ($e) use ($names) {
                $isLead = in_array(strtolower((string) $e->reference_type), ['lead', 'app\\models\\lead'], true);
                $status = match ((string) $e->category) { 'booking_pending', 'callback_pending' => 'pending', 'booking_confirmed' => 'confirmed', 'cancelled', 'booking_declined' => 'cancelled', 'completed' => 'completed', 'no_show' => 'no_show', default => 'scheduled' };
                return array_merge((array) $e, ['start_at' => $e->starts_at, 'end_at' => $e->ends_at, 'status' => $status,
                    'lead_id' => $isLead ? $e->reference_id : null, 'lead_name' => $isLead ? ($names[$e->reference_id] ?? null) : null]);
            })->values();
            return response()->json(['appointments' => $events]);
        });
        Route::post('/appointments', function (\Illuminate\Http\Request $r) {
            // SECURITY/SCHEMA 2026-07-23: `type` is not a column on calendar_events.
            // The real column is `category` (varchar(30), default 'general').
            // workspace_id comes from the auth middleware only — never the payload.
            $wsId  = (int) $r->attributes->get('workspace_id');
            $title = trim((string) $r->input('title'));
            $start = $r->input('start_at');
            if ($title === '' || empty($start)) {
                return response()->json(['error' => 'title and start_at are required'], 422);
            }
            $id = \Illuminate\Support\Facades\DB::table('calendar_events')->insertGetId([
                'workspace_id' => $wsId,
                'title' => $title,
                'description' => $r->input('description'),
                'starts_at' => $start,
                'ends_at' => $r->input('end_at'),
                'category' => 'appointment',
                'engine' => 'crm',
                'reference_type' => ($r->filled('lead_id') && \Illuminate\Support\Facades\DB::table('leads')->where('workspace_id', $wsId)->where('id', (int) $r->input('lead_id'))->exists()) ? 'Lead' : null,
                'reference_id' => ($r->filled('lead_id') && \Illuminate\Support\Facades\DB::table('leads')->where('workspace_id', $wsId)->where('id', (int) $r->input('lead_id'))->exists()) ? (int) $r->input('lead_id') : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return response()->json(['id' => $id], 201);
        });
        Route::put('/appointments/{id}', function (\Illuminate\Http\Request $r, $id) {
            // SECURITY 2026-07-23 (IDOR): this update was scoped by id ALONE, so any
            // authenticated user could mutate any workspace's calendar event by global
            // id. Now scoped to the active workspace from the auth middleware.
            // SCHEMA: `status` is not a column on calendar_events — removed. There is no
            // status concept in this domain model, so no column was added to preserve it.
            $wsId = (int) $r->attributes->get('workspace_id');
            $update = array_filter([
                'title' => $r->input('title'),
                'description' => $r->input('description'),
                'starts_at' => $r->input('start_at'),
                'ends_at' => $r->input('end_at'),
            ], fn($v) => $v !== null && $v !== '');
            // CRM-FIX-0: status lives in `category` (cancel said "cancelled" and changed nothing)
            $stMap = ['cancelled' => 'cancelled', 'completed' => 'completed', 'no_show' => 'no_show', 'scheduled' => 'appointment', 'confirmed' => 'booking_confirmed'];
            if ($r->filled('status') && isset($stMap[$r->input('status')])) $update['category'] = $stMap[$r->input('status')];
            $update['updated_at'] = now();

            $n = \Illuminate\Support\Facades\DB::table('calendar_events')
                ->where('id', (int) $id)
                ->where('workspace_id', $wsId)
                ->update($update);

            // Identical response whether the event is missing, foreign, or otherwise
            // inaccessible — event existence must not leak across workspaces.
            if ($n === 0) {
                return response()->json(['error' => 'Event not found'], 404);
            }
            return response()->json(['updated' => true]);
        });

        Route::get('/tasks', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $status = $r->input('status');
            // CRM-FIX-0: the CRM's own tasks (activities of type task), in the shape the screen draws.
            $rows = \Illuminate\Support\Facades\DB::table('activities as a')
                ->leftJoin('leads as l', function ($j) { $j->on('l.id', '=', 'a.activitable_id')->whereIn('a.activitable_type', ['Lead', 'App\\Models\\Lead']); })
                ->where('a.workspace_id', $wsId)->where('a.type', 'task')
                ->when($status === 'pending', fn($q) => $q->where('a.completed', 0))
                ->when($status === 'done', fn($q) => $q->where('a.completed', 1))
                ->whereNull('l.deleted_at')
                ->orderByRaw('a.scheduled_at IS NULL, a.scheduled_at ASC')->orderByDesc('a.created_at')
                ->limit(200)
                ->get(['a.id', 'a.subject', 'a.description', 'a.scheduled_at', 'a.completed', 'a.metadata_json', 'a.activitable_id', 'l.name as lead_name']);
            $tasks = $rows->map(function ($t) {
                $meta = json_decode((string) $t->metadata_json, true) ?: [];
                return ['id' => $t->id, 'title' => $t->subject ?: mb_substr((string) $t->description, 0, 80), 'description' => $t->subject ? $t->description : null,
                    'due_date' => $t->scheduled_at, 'priority' => $meta['priority'] ?? 'medium', 'status' => $t->completed ? 'done' : 'pending',
                    'lead_id' => $t->activitable_id, 'lead_name' => $t->lead_name];
            })->values();
            return response()->json(['tasks' => $tasks]);
        });

        Route::post('/scores/recalculate', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $s = app(\App\Engines\CRM\Services\CrmService::class);
            $leads = \Illuminate\Support\Facades\DB::table('leads')->where('workspace_id', $wsId)->pluck('id');
            $count = 0;
            foreach ($leads as $leadId) {
                try { $s->scoreLead($leadId); $count++; } catch (\Throwable $e) {}
            }
            return response()->json(['recalculated' => $count, 'updated' => $count]);
        });

        Route::get('/leads/export/{format}', function (\Illuminate\Http\Request $r, $format) {
            $wsId = $r->attributes->get('workspace_id');
            $s = app(\App\Engines\CRM\Services\CrmService::class);
            $csv = $s->exportLeads($wsId, $r->all());
            if ($format === 'csv') return response()->json(['csv' => $csv]);
            if ($format === 'json') {
                $data = $s->listLeads($wsId);
                return response()->json($data['leads'] ?? []);
            }
            if ($format === 'xml') {
                $data = $s->listLeads($wsId);
                $xml = '<?xml version="1.0"?><leads>';
                foreach ($data['leads'] ?? [] as $l) { $xml .= '<lead><name>' . htmlspecialchars($l['name'] ?? '') . '</name><email>' . htmlspecialchars($l['email'] ?? '') . '</email></lead>'; }
                $xml .= '</leads>';
                return response()->json(['xml' => $xml]);
            }
            return response()->json(['error' => 'Unsupported format'], 400);
        });
    });
