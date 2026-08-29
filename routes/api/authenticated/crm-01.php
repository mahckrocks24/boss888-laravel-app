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
        Route::get('/today', [$c, 'todayView']);

        // Notes (3 routes)
        Route::get('/notes', [$c, 'listNotes']);
        Route::post('/notes', [$c, 'addNote']);
        Route::delete('/notes/{id}', [$c, 'deleteNote']);

        // CRM settings & views (frontend compat)
        Route::get("/settings", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get("workspace_id");
            $stages = app(\App\Engines\CRM\Services\CrmService::class)->getStages($wsId);
            return response()->json([
                "stages" => collect($stages)->map(fn($s) => ["id" => is_object($s) ? $s->id : $s, "label" => is_object($s) ? $s->name : (string)$s, "color" => is_object($s) ? ($s->color ?? "#6C5CE7") : "#6C5CE7"])->values()->toArray(),
                "statuses" => [["id" => "active", "label" => "Active"], ["id" => "inactive", "label" => "Inactive"]],
                "categories" => [],
                "sources" => [["id" => "manual", "label" => "Manual"], ["id" => "import", "label" => "Import"], ["id" => "api", "label" => "API"]],
                "tags" => [],
            ]);
        });
        Route::get("/views", fn() => response()->json([]));

        // Nested contact notes & attachments (frontend compat)
        Route::get('/contacts/{contactId}/notes', [$c, 'listNotes']);
        Route::post('/contacts/{contactId}/notes', [$c, 'addNote']);
        Route::delete('/contacts/{contactId}/notes/{noteId}', [$c, 'deleteNote']);
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
            $leads = $s->listLeads($wsId);
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
            return response()->json([
                'leads' => ['enabled' => true, 'required' => true, 'label' => 'Leads & Pipeline'],
                'contacts' => ['enabled' => true, 'required' => false, 'label' => 'Contact Management'],
                'deals' => ['enabled' => true, 'required' => false, 'label' => 'Deal Tracking'],
                'activities' => ['enabled' => true, 'required' => false, 'label' => 'Activities & Tasks'],
                'reporting' => ['enabled' => true, 'required' => false, 'label' => 'Revenue Reporting'],
            ]);
        });

        Route::get('/projects', [$c, 'listDeals']);  // alias: crm-engine.js "projects" are Laravel "deals"
        Route::post('/projects', fn(\Illuminate\Http\Request $r) => app($c)->createDeal($r));
        Route::get('/projects/{id}', fn(\Illuminate\Http\Request $r, $id) => app($c)->getDeal($r, $id));
        Route::put('/projects/{id}', fn(\Illuminate\Http\Request $r, $id) => app($c)->updateDeal($r, $id));
        Route::delete('/projects/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(['deleted' => true]));

        Route::get('/appointments', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $upcoming = $r->boolean('upcoming');
            $events = \Illuminate\Support\Facades\DB::table('calendar_events')
                ->where('workspace_id', $wsId)
                ->when($upcoming, fn($q) => $q->where('starts_at', '>=', now()))
                ->orderBy('starts_at')
                ->limit(50)
                ->get();
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
            $tasks = \App\Models\Task::where('workspace_id', $wsId)
                ->where('engine', 'crm')
                ->when($status, fn($q) => $q->where('status', $status))
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
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
            return response()->json(['recalculated' => $count]);
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
