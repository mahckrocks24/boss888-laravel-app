<?php

/**
 * CR-22B — extracted route module: agents-04
 *
 * Source: routes/api.php lines 13132-13295 of the authoritative pre-extraction
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
 * Owner: Agent platform   ·   Routes: 6   ·   Statements: 6
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
    // ── Meeting route aliases (JS calls /meeting/*, Laravel has /sarah/meeting/*) ──
    Route::post('/meeting/start', function (\Illuminate\Http\Request $r) {
        $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
        $goal = $r->input('topic', $r->input('goal', 'Strategy discussion'));
        return response()->json($engine->startMeeting(
            $r->attributes->get('workspace_id'), $r->user()->id, $goal, $r->input('agents', [])
        ));
    });
    Route::get('/meeting/{id}', function ($id) {
        $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
        return response()->json($engine->getMeetingTranscript($id));
    });
    Route::post('/meeting/{id}/message', function (\Illuminate\Http\Request $r, $id) {
        $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
        $msg = $r->input('content', $r->input('message', ''));
        return response()->json($engine->userMessage($id, $r->user()->id, $msg));
    });
    Route::post('/meeting/{id}/wrap', function (\Illuminate\Http\Request $r, $id) {
        $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
        return response()->json($engine->endMeeting($id, $r->user()->id));
    });

    // ── Meeting status (polled every 4s by frontend) ─────────────
    // Auto-advances meeting on each poll — gives progressive message delivery
    Route::get('/meeting/{id}/status', function (\Illuminate\Http\Request $r, $id) {
        $meeting = \App\Models\Meeting::findOrFail($id);
        $meta = $meeting->metadata_json ? json_decode($meeting->metadata_json, true) : [];

        // AUTO-ADVANCE: if meeting is active and current phase is done, advance to next
        // Uses cache lock to prevent double-advance from concurrent polls
        $phase = $meta['phase'] ?? 'opening';
        $lockKey = "meeting_advancing_{$id}";
        if ($meeting->status === 'active' && !in_array($phase, ['complete', 'synthesis', 'synthesis_done'])) {
            $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 30);
            if ($lock->get()) {
                try {
                    $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
                    $result = $engine->advanceMeeting($id);
                    $meeting = $meeting->fresh();
                    $meta = $meeting->metadata_json ? json_decode($meeting->metadata_json, true) : [];
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("[Meeting] Auto-advance failed for meeting {$id}: " . $e->getMessage());
                } finally {
                    $lock->release();
                }
            }
        } elseif ($phase === 'synthesis' && $meeting->status === 'active') {
            // Synthesis is the last real phase — complete the meeting
            $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 30);
            if ($lock->get()) {
                try {
                    $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
                    $engine->advanceMeeting($id); // synthesis → complete
                    $meeting = $meeting->fresh();
                    $meta = $meeting->metadata_json ? json_decode($meeting->metadata_json, true) : [];
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("[Meeting] Synthesis→complete failed: " . $e->getMessage());
                } finally {
                    $lock->release();
                }
            }
        }

        // Get all messages for this meeting
        $messages = \App\Models\MeetingMessage::where('meeting_id', $id)
            ->orderBy('created_at')
            ->get()
            ->map(function ($msg) {
                $agent = $msg->sender_type === 'agent' ? \App\Models\Agent::find($msg->sender_id) : null;
                $attachments = $msg->attachments_json ? json_decode($msg->attachments_json, true) : [];
                return [
                    'role'      => $msg->sender_type === 'user' ? 'user' : ($attachments['role'] ?? 'agent'),
                    'agent_id'  => $agent ? $agent->slug : ($msg->sender_type === 'user' ? 'user' : 'system'),
                    'name'      => $agent ? $agent->name : 'You',
                    'title'     => $agent ? $agent->title : '',
                    'content'   => $msg->message,
                    'phase'     => $attachments['phase'] ?? null,
                    'timestamp' => $msg->created_at?->toISOString(),
                ];
            })->toArray();

        // Determine current phase from metadata or last message
        $phase = $meta['phase'] ?? 'briefing';
        $status = $meeting->status;

        // Map meeting status to frontend expectations
        if ($status === 'completed' || $status === 'closed') $status = 'complete';

        // Find current speaker (last agent message sender if meeting is active)
        $currentSpeaker = null;
        $spokenAgents = [];
        foreach ($messages as $m) {
            if ($m['role'] !== 'user' && $m['agent_id'] !== 'system') {
                $spokenAgents[] = $m['agent_id'];
                $currentSpeaker = $m['agent_id'];
            }
        }
        $spokenAgents = array_values(array_unique($spokenAgents));
        // Only show current speaker if meeting is active
        if ($status === 'complete') $currentSpeaker = null;

        return response()->json([
            'messages'       => $messages,
            'phase'          => $phase,
            'status'         => $status,
            'current_speaker'=> $currentSpeaker,
            'spokenAgents'   => $spokenAgents,
            'topic'          => $meeting->title,
        ]);
    });

    // ── Meeting pending tasks (called after meeting ends) ────────
    Route::get('/meeting/{id}/pending-tasks', function (\Illuminate\Http\Request $r, $id) {
        $meeting = \App\Models\Meeting::findOrFail($id);
        $wsId = $meeting->workspace_id;

        // Check meeting_tasks pivot for tasks linked to this meeting
        $taskIds = \Illuminate\Support\Facades\DB::table('meeting_tasks')
            ->where('meeting_id', $id)
            ->pluck('task_id')
            ->toArray();

        $tasks = [];
        if (!empty($taskIds)) {
            $tasks = \App\Models\Task::whereIn('id', $taskIds)
                ->get()
                ->map(fn($t) => [
                    'id'          => $t->id,
                    'title'       => ucfirst(str_replace('_', ' ', $t->action)),
                    'description' => $t->progress_message ?? ('Execute ' . $t->action . ' via ' . $t->engine),
                    'engine'      => $t->engine,
                    'action'      => $t->action,
                    'agent_id'    => $t->assigned_agents_json[0] ?? 'sarah',
                    'credit_cost' => $t->credit_cost,
                    'tools'       => [$t->action],
                    'status'      => $t->status,
                ])->toArray();
        }

        // Also check for execution plans linked via metadata
        if (empty($tasks)) {
            $meta = $meeting->metadata_json ? json_decode($meeting->metadata_json, true) : [];
            $planId = $meta['plan_id'] ?? $meta['execution_plan_id'] ?? null;
            if ($planId) {
                $planTasks = \Illuminate\Support\Facades\DB::table('plan_tasks')
                    ->where('plan_id', $planId)
                    ->get()
                    ->map(fn($pt) => [
                        'id'          => $pt->id,
                        'title'       => ucfirst(str_replace('_', ' ', $pt->action ?? 'task')),
                        'description' => $pt->description ?? '',
                        'engine'      => $pt->engine ?? 'system',
                        'action'      => $pt->action ?? 'execute',
                        'agent_id'    => $pt->agent_slug ?? 'sarah',
                        'credit_cost' => $pt->credit_cost ?? 0,
                        'tools'       => [$pt->action ?? 'execute'],
                        'status'      => $pt->status ?? 'pending',
                    ])->toArray();
                $tasks = $planTasks;
            }
        }

        return response()->json(['tasks' => $tasks]);
    });
