<?php

/**
 * CR-22B — extracted route module: agents-02
 *
 * Source: routes/api.php lines 3517-3649 of the authoritative pre-extraction
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
 * Owner: Agent platform   ·   Routes: 4   ·   Statements: 4
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
        // ── Unified Messaging System ────────────────────────────────
    Route::get('/messages/unread-count', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $byAgent = [];
        $total = 0;

        // Count from agent_messages
        try {
            $counts = \Illuminate\Support\Facades\DB::table('agent_messages')
                ->where('workspace_id', $wsId)
                ->where('role', 'agent')
                ->whereNull('read_at')
                ->selectRaw('agent_slug, COUNT(*) as cnt')
                ->groupBy('agent_slug')
                ->pluck('cnt', 'agent_slug')
                ->toArray();
            $byAgent = $counts;
            $total = array_sum($counts);
        } catch (\Throwable $e) {
            // Fallback: count from audit_logs
            $total = \Illuminate\Support\Facades\DB::table('audit_logs')
                ->where('workspace_id', $wsId)
                ->where('action', 'agent.direct_message')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.from')) != 'User'")
                ->count();
        }

        return response()->json(['total' => $total, 'by_agent' => $byAgent]);
    });

    // 2026-07-26 (chat forensic) — D6: there was no way to clear the whole
    // workspace. The floater badge sums unread across every agent, so a user
    // who only ever opens Sarah could never get the badge to zero. This is the
    // "mark all as read" primitive every messaging product has.
    Route::post('/messages/read-all', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $n = 0;
        try {
            $n = \Illuminate\Support\Facades\DB::table('agent_messages')
                ->where('workspace_id', $wsId)
                ->where('role', 'agent')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        } catch (\Throwable $e) {}
        return response()->json(['marked' => true, 'count' => $n]);
    });

    Route::post('/messages/{slug}/read', function (\Illuminate\Http\Request $r, $slug) {
        $wsId = $r->attributes->get('workspace_id');
        if ($slug === 'dmm') $slug = 'sarah';
        try {
            \Illuminate\Support\Facades\DB::table('agent_messages')
                ->where('workspace_id', $wsId)
                ->where('agent_slug', $slug)
                ->where('role', 'agent')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        } catch (\Throwable $e) {}
        return response()->json(['marked' => true]);
    });

    Route::get('/messages/conversations', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        // Get enabled agents for this workspace
        $agents = \Illuminate\Support\Facades\DB::table('agents')
            ->join('workspace_agents', 'agents.id', '=', 'workspace_agents.agent_id')
            ->where('workspace_agents.workspace_id', $wsId)
            ->where('workspace_agents.enabled', true)
            ->whereNotIn('agents.slug', \App\Core\LaunchScope\LaunchScopePolicy::REMOVED_AGENTS) // LAUNCH SCOPE 2026-07-20
            ->select('agents.slug', 'agents.name', 'agents.title', 'agents.color')
            ->get();

        $conversations = [];
        foreach ($agents as $a) {
            // Last message
            $lastMsg = null;
            try {
                $lastMsg = \Illuminate\Support\Facades\DB::table('agent_messages')
                    ->where('workspace_id', $wsId)
                    ->where('agent_slug', $a->slug)
                    ->orderByDesc('created_at')
                    ->first();
            } catch (\Throwable $e) {}

            if (!$lastMsg) {
                // Try audit_logs fallback
                $lastLog = \Illuminate\Support\Facades\DB::table('audit_logs')
                    ->where('workspace_id', $wsId)
                    ->where('action', 'agent.direct_message')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.agent_slug')) = ?", [$a->slug])
                    ->orderByDesc('created_at')
                    ->first();
                if ($lastLog) {
                    $meta = json_decode($lastLog->metadata_json, true);
                    $lastMsg = (object) ['sender' => $meta['from'] ?? '?', 'content' => $meta['content'] ?? '', 'created_at' => $lastLog->created_at];
                }
            }

            $unread = 0;
            try {
                $unread = \Illuminate\Support\Facades\DB::table('agent_messages')
                    ->where('workspace_id', $wsId)
                    ->where('agent_slug', $a->slug)
                    ->where('role', 'agent')
                    ->whereNull('read_at')
                    ->count();
            } catch (\Throwable $e) {}

            $conversations[] = [
                'slug' => $a->slug,
                'name' => $a->name,
                'title' => $a->title,
                'color' => $a->color,
                'unread' => $unread,
                'last_message' => $lastMsg ? [
                    'from' => $lastMsg->sender ?? '',
                    'content' => mb_substr($lastMsg->content ?? '', 0, 80),
                    'ts' => $lastMsg->created_at ?? null,
                ] : null,
            ];
        }

        // Sort: agents with messages first, then by last message time
        usort($conversations, function ($a, $b) {
            if ($a['slug'] === 'sarah') return -1;
            if ($b['slug'] === 'sarah') return 1;
            $aTs = $a['last_message']['ts'] ?? '0';
            $bTs = $b['last_message']['ts'] ?? '0';
            return strcmp($bTs, $aTs);
        });

        return response()->json(['conversations' => $conversations]);
    });
