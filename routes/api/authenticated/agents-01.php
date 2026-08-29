<?php

/**
 * CR-22B — extracted route module: agents-01
 *
 * Source: routes/api.php lines 963-3450 of the authoritative pre-extraction
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
 * Owner: Agent platform   ·   Routes: 9   ·   Statements: 5
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
    // ── DEPRECATED 2026-05-27 — MeetingController is CRUD-only and was
    //    never wired into the UI. The Strategy Room uses /api/meeting/*
    //    (AgentMeetingEngine path) defined further down in this file.
    //    Routes kept commented-out for one release as a safety net;
    //    delete along with App\Http\Controllers\Api\MeetingController
    //    and App\Core\Meetings\MeetingService after 2026-06-15.
    // Route::post('/meetings', [MeetingController::class, 'store']);
    // Route::get('/meetings', [MeetingController::class, 'index']);
    // Route::get('/meetings/{id}', [MeetingController::class, 'show']);
    // Route::post('/meetings/{id}/messages', [MeetingController::class, 'addMessage']);

    // Agents
    Route::get('/agents', [AgentController::class, 'index']);

    // ── Agent Team Builder routes ───────────────────────────────
    // GET /agents/available — all agents the workspace's plan tier CAN access (for team builder)
    // ── Agent direct messages ────────────────────────────────
    Route::get('/agents/{slug}/messages', function (\Illuminate\Http\Request $r, $slug) {
        $wsId = $r->attributes->get('workspace_id');
        if ($slug === 'dmm') $slug = 'sarah';
        $agent = \App\Models\Agent::where('slug', $slug)->first();
        if (!$agent) return response()->json([]);

        // Get messages from agent_messages table if exists, otherwise build from delegations + audit
        $messages = [];
        try {
            // 2026-05-22 FIX 14 — was orderBy(created_at)->limit(50) which returns
            // the OLDEST 50 messages. For conversations longer than 50, the
            // recent session messages get cut off and the drawer renders only
            // ancient history. User saw this as "chat session got erased on
            // refresh". orderByDesc + reverse returns newest 50 in chronological
            // order.
            // ── SLICE 1A.5 — PAGINATION (S1A-N03, S1A-N04) ──────────────────
            // The comment above already named the real fix: pagination. Until
            // now every poll re-fetched the newest 100 rows in full, including
            // message bodies, and the SPA polls every 2.5s per in-flight turn.
            // Two consequences, both measured:
            //
            //   S1A-N04  cost is O(polls x 100 rows). Polling a 30-turn probe at
            //            300ms took p50 from 9.6s to 17.2s and failed 6 of 30
            //            requests outright — the poll loop degrades the very
            //            thing it is waiting for.
            //   S1A-N03  a 200-turn conversation writes ~600 rows, so two thirds
            //            of it was unreachable by the client at ALL. That
            //            compounds the memory horizon rather than easing it.
            //
            // after_id  → forward poll, returns only what is new (usually 0-2 rows)
            // before_id → backward page, makes older history reachable
            // neither   → unchanged legacy behaviour, so the shipped SPA is safe
            $afterId  = (int) $r->query('after_id', 0);
            $beforeId = (int) $r->query('before_id', 0);
            $limit    = max(1, min(100, (int) $r->query('limit', 100)));

            $base = \Illuminate\Support\Facades\DB::table('agent_messages')
                ->where('workspace_id', $wsId)
                ->where('agent_slug', $slug);

            if ($afterId > 0) {
                // Ordered by id, not created_at: created_at is second-precision
                // so rows written inside the same second tie, and a tie here
                // would drop or duplicate a poll result.
                $rows = $base->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get();
            } elseif ($beforeId > 0) {
                $rows = $base->where('id', '<', $beforeId)->orderByDesc('id')->limit($limit)
                    ->get()->reverse()->values();
            } else {
                $rows = $base->orderByDesc('created_at')->limit($limit)->get()->reverse()->values();
            }
            // v1.4.4 (2026-05-30) — surface row id, role, and is_ack/phase
            // from metadata_json so the SPA's two-phase poll loop can
            // identify ack vs final messages. Additive — old fields kept.
            $messages = $rows->map(function ($m) {
                $meta = [];
                if (!empty($m->metadata_json)) {
                    $decoded = is_string($m->metadata_json) ? json_decode($m->metadata_json, true) : $m->metadata_json;
                    if (is_array($decoded)) $meta = $decoded;
                }
                return [
                    'id'      => (int) $m->id,
                    // W6: a message a removed agent wrote stays attributed to them,
                    // but is labelled historical so nothing reads as current.
                    'from'    => \App\Core\LaunchScope\AgentDirectory::resolveSender($m->sender),
                    'role'    => $m->role,
                    'content' => \App\Core\LaunchScope\LaunchScopeLanguageGuard::apply((string) $m->content),
                    'ts'      => $m->created_at,
                    'is_ack'  => !empty($meta['is_ack']) || (($meta['phase'] ?? '') === 'ack'),
                    'phase'   => $meta['phase'] ?? null,
                    // SARAH888 Phase 1A slice 1 — correlation surfaced so a client
                    // can bind a reply to the question that produced it instead of
                    // inferring it from arrival order. Null on rows written before
                    // this slice shipped; clients must treat null as "unbindable,
                    // fall back to legacy ordering" rather than as an error.
                    'user_message_id' => $meta['user_message_id'] ?? null,
                    'execution_id'    => $meta['execution_id'] ?? null,
                    'conversation_id' => $meta['conversation_id'] ?? null,
                    'error'   => !empty($meta['error']),
                ];
            })->toArray();
        } catch (\Throwable $e) {
            // agent_messages table may not exist — build from delegations + audit_logs
            $delegations = \Illuminate\Support\Facades\DB::table('agent_delegations')
                ->where('workspace_id', $wsId)
                ->where('to_agent', $slug)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
            foreach ($delegations as $d) {
                $messages[] = ['from' => 'Sarah', 'content' => $d->instruction ?? 'Task delegated', 'ts' => $d->created_at];
                if ($d->result_json) {
                    $result = json_decode($d->result_json, true);
                    $messages[] = ['from' => $agent->name, 'content' => $result['summary'] ?? $result['message'] ?? 'Task completed', 'ts' => $d->updated_at];
                }
            }
            // Also read direct messages stored as audit_log entries
            $dmLogs = \Illuminate\Support\Facades\DB::table('audit_logs')
                ->where('workspace_id', $wsId)
                ->where('action', 'agent.direct_message')
                ->whereRaw("JSON_EXTRACT(metadata_json, '$.agent_slug') = ?", [$slug])
                ->orderBy('created_at')
                ->limit(50)
                ->get();
            foreach ($dmLogs as $dm) {
                $meta = json_decode($dm->metadata_json, true);
                $messages[] = ['from' => $meta['from'] ?? 'User', 'content' => $meta['content'] ?? '', 'ts' => $dm->created_at];
            }
            // Sort by timestamp
            usort($messages, fn($a, $b) => strtotime($a['ts']) - strtotime($b['ts']));
        }
        return response()->json($messages);
    });

    // Agent documents — assets produced by or for this agent
    Route::get('/agents/{slug}/documents', function (\Illuminate\Http\Request $r, $slug) {
        $wsId = $r->attributes->get('workspace_id');
        if ($slug === 'dmm') $slug = 'sarah';

        // Find assets linked to this agent via tasks or delegations
        $taskIds = \App\Models\Task::where('workspace_id', $wsId)
            ->whereRaw("JSON_CONTAINS(assigned_agents_json, ?)", ['"'.$slug.'"'])
            ->pluck('id')->toArray();

        $delegationAssetIds = \Illuminate\Support\Facades\DB::table('agent_delegations')
            ->where('workspace_id', $wsId)
            ->where('to_agent', $slug)
            ->whereNotNull('result_json')
            ->get()
            ->map(function($d) {
                $result = json_decode($d->result_json, true);
                return $result['asset_id'] ?? null;
            })->filter()->toArray();

        // Get assets from creative engine that match
        $assets = \Illuminate\Support\Facades\DB::table('assets')
            ->where('workspace_id', $wsId)
            ->where(function($q) use ($taskIds, $delegationAssetIds, $slug) {
                if (!empty($taskIds)) $q->orWhereIn('id', $taskIds);
                if (!empty($delegationAssetIds)) $q->orWhereIn('id', $delegationAssetIds);
                // Also match assets whose metadata references this agent
                $q->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.agent')) = ?", [$slug]);
            })
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($a) => [
                'id' => $a->id,
                'title' => $a->title ?? $a->prompt ?? 'Untitled',
                'type' => $a->type,
                'mime_type' => $a->mime_type,
                'url' => $a->url,
                'thumbnail_url' => $a->thumbnail_url,
                'created_at' => $a->created_at,
            ])->toArray();

        return response()->json(['documents' => $assets]);
    });

        Route::post('/agents/{slug}/messages', function (\Illuminate\Http\Request $r, $slug) {
        $wsId = $r->attributes->get('workspace_id');
        $content = $r->input('content', '');
        $from = $r->input('from', 'User');
        $quickAction = $r->input('quick_action'); // my_tasks, recent_completions, whats_next
        $image = $r->input('image'); // base64 image for vision
        // 2026-06-08 — captured once so they're in scope for the push dispatch
        // and the user-requested timed follow-up at the end of this handler.
        $userId = (int) ($r->user()?->id ?? 0);
        $__deterministicEvidence = null;   // SARAH888: router facts, when reasoning is to use them
        // SARAH888: the owner's own words, captured once. $r is shadowed by row
        // variables further down this handler, so nothing below may read it.
        $__ownerMessage = (string) $r->input('content', '');
        $scheduleFollowup = null;

        // Map 'dmm' alias to 'sarah' (frontend uses 'dmm' for Sarah)
        if ($slug === 'dmm') $slug = 'sarah';
        $agent = \App\Models\Agent::where('slug', $slug)->first();
        if (!$agent) return response()->json(['error' => 'Agent not found'], 404);

        // ── INCIDENT FIX 2026-07-26 — PERSIST BEFORE METERING ──────────────
        // The credit meter used to run here, BEFORE the message was written.
        // A refused chat therefore returned 402 without ever storing what the
        // user typed: the SPA had already rendered it optimistically, so on
        // refresh it silently vanished ("my message gets deleted").
        //
        // The user's own words are not the platform's to discard because of a
        // billing state. Persist first; meter second. A stored-but-unanswered
        // message is recoverable; a discarded one is not.
        // ── SARAH888 PHASE 1A SLICE 1 — MESSAGE CORRELATION (F1-D02) ────────
        // The originating user message id used to be thrown away here: this was
        // insert(), not insertGetId(). Nothing downstream could therefore bind a
        // reply to the question that caused it, so the SPA fell back to "newest
        // agent row with id > ack_id" — which attaches a late reply to whatever
        // question happens to be on screen. In the F1 stress test that produced
        // a systematic one-turn lag: turn 123 answered turn 120, turn 138
        // answered turn 136. Capturing the id is the root fix; every row written
        // for this turn now carries the envelope built below.
        $userMessageId = null;
        try {
            $userMessageId = (int) \Illuminate\Support\Facades\DB::table('agent_messages')->insertGetId([
                'workspace_id' => $wsId,
                'agent_slug'   => $slug,
                'sender'       => 'user',
                'content'      => $content,
                'role'         => 'user',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            // CR-18 (2026-07-26): this catch used to be empty. If this insert
            // fails the user's message is gone — exactly the failure the
            // persist-before-metering fix above exists to prevent — and nothing
            // recorded it. Silence is how the original incident stayed
            // invisible. Log with enough context to find the message; do not
            // rethrow, because a hard 500 is worse for the customer than a
            // degraded write.
            \Illuminate\Support\Facades\Log::error('chat.persist_failed', [
                'stage'        => 'persist_before_metering',
                'workspace_id' => $wsId,
                'agent_slug'   => $slug,
                'exception'    => $e->getMessage(),
            ]);
        }

        // The correlation envelope for THIS turn. Every agent row written below
        // — ack, router reply, LLM reply, credit refusal, crash row — merges
        // this in, so a reply can always be traced to its originating question
        // regardless of arrival order. Additive JSON keys only: no migration,
        // and clients that ignore them behave exactly as before.
        //
        // conversation_id is the thread identity as it exists today
        // (workspace + agent). It is deliberately a stable string rather than a
        // new table, so this slice introduces no schema and no new entity.
        $execId = (string) \Illuminate\Support\Str::uuid();
        $corr = [
            'workspace_id'    => (int) $wsId,
            'conversation_id' => 'ws' . $wsId . ':' . $slug,
            'user_message_id' => $userMessageId,
            'execution_id'    => $execId,
            'correlation_id'  => $execId,
            'started_at'      => now()->toIso8601String(),
        ];
        // Used by every write site below so the envelope can never drift.
        // Publish the envelope for the rest of this request so TaskService can
        // stamp it onto anything this turn creates (S1J-N01).
        app(\App\Core\Sarah888\CorrelationContext::class)->set($corr, (int) $wsId);
        // Classify the owner's request against the FORBIDDEN tier for the rest
        // of this request, so the task funnel can refuse to create work for a
        // turn whose operation is refused in the reply (S1L-D05 residual).
        // Conversation is passed so a bare "do it" can inherit the capability
        // of the request it is authorising (CERT-B-D01).
        app(\App\Core\Sarah888\ActionAuthority::class)
            ->markTurn((string) $content, (int) $wsId, $corr['conversation_id'] ?? null);
        // Resolve WHAT is being cancelled and WHICH domain owns it before any
        // mutation can be attempted this turn (S1P-D02).
        app(\App\Core\Sarah888\CancellationPolicy::class)->markTurn((int) $wsId, (string) $content);

$withCorr = function (array $meta) use ($corr) {
            return json_encode(array_merge($corr, $meta, ['stamped_at' => now()->toIso8601String()]));
        };

        // ── SARAH888 — RUNTIME-NATIVE CUTOVER (feature-flagged, default OFF) ────
        //
        // One guarded delegation, deliberately the only change to this file. PathSelector
        // decides; everything that can go wrong lives in RuntimeNativeTurn under its own
        // tests. A workspace that has not EXPLICITLY opted in — which is every customer
        // workspace — falls straight through to the legacy body below, byte-identically to
        // before this line existed.
        //
        // Placed here on purpose: the owner's message is already persisted above, so it
        // cannot be lost whichever path answers, and correlation already exists so the
        // reply binds to the question that caused it.
        //
        // Fails OPEN to legacy. If the new path throws, or produces nothing, the adapter
        // returns null and the original handler runs as though this were absent. A cutover
        // that can strand someone mid-conversation is not reversible in any useful sense.
        //
        // Kill switch: SARAH_RUNTIME_NATIVE_KILL=true disables it everywhere, no deploy.
        try {
            $__runtimeNative = app(\App\Core\Sarah888\RuntimeNativeTurn::class)
                ->handle($wsId, $slug, (string) $agent->name, (string) $content, $corr);
            if ($__runtimeNative !== null) {
                return response()->json($__runtimeNative);
            }
        } catch (\Throwable $__rnFatal) {
            \Illuminate\Support\Facades\Log::error(
                '[Sarah888] runtime-native delegation threw; legacy will answer',
                ['ws' => $wsId, 'error' => $__rnFatal->getMessage()]
            );
        }

        // ── SARAH888 PHASE 1B SLICE 1B.3 — PERSIST WHAT THE CEO JUST SAID ───
        // F1-D01: across 163 turns roughly seventy commitments were dictated
        // and exactly one task was created, because nothing here ever turned a
        // stated obligation into durable state. This is that step.
        //
        // It runs BEFORE the ack is shipped, deliberately: extraction is pure
        // regex plus two indexed queries (sub-millisecond), and running it here
        // means a commitment is captured even if the LLM pipeline below crashes
        // afterwards. The alternative — running it post-response — would lose
        // exactly the turns most worth keeping.
        //
        // CommitmentSync never throws. Losing a commitment is recoverable; the
        // CEO can restate it. Losing their reply is not.
        // Phase 1E — classify this turn ONCE, before any work is proposed, so
        // every task created from it is judged against the same reading of what
        // the owner actually asked for.
        $__spendTurn = ['authorized' => false, 'reason' => 'not assessed', 'classification' => 'unknown'];
        $__spendHeld = [];
        try {
            $__spendTurn = app(\App\Core\Sarah888\SpendPolicy::class)->assessTurn((string) $content);
            // Slice 1E.2 — publish the assessment to the request so EVERY paid
            // creation path sees it, including the router's WriteService calls
            // which never reach the create_tasks loop below.
            app(\App\Core\Sarah888\SpendContext::class)->setTurn($__spendTurn, (int) $wsId);
        } catch (\Throwable $__ste) {
            \Illuminate\Support\Facades\Log::warning('[Sarah888] turn assessment failed — treating as unauthorised', [
                'ws' => $wsId, 'error' => $__ste->getMessage(),
            ]);
        }

        if ($slug === 'sarah' && $content !== '' && $userMessageId) {
            try {
                app(\App\Core\Sarah888\CommitmentSync::class)
                    ->syncFromMessage((int) $wsId, (string) $content, $corr);
            } catch (\Throwable $__ce) {
                \Illuminate\Support\Facades\Log::error('[Sarah888] sync hook failed', [
                    'ws' => $wsId, 'error' => $__ce->getMessage(),
                ]);
            }
        }

        \Illuminate\Support\Facades\DB::table('audit_logs')->insert([
            'workspace_id' => $wsId,
            'action' => 'agent.direct_message',
            'entity_type' => 'Agent',
            'metadata_json' => json_encode(['agent_slug' => $slug, 'from' => $from, 'content' => $content,
                                            'user_message_id' => $userMessageId, 'execution_id' => $execId]),
            'created_at' => now(),
        ]);

        // Wave 22 — 10-chat batched metering (0.1 cr effective per chat).
        $_meter = app(\App\Core\Billing\CreditService::class)->meterChat((int) $wsId, 'agent_message');
        // Wave 24 — surface counter via JSON only (raw header() unreliable).
        if (!$_meter['sufficient']) {
            // ── INCIDENT FIX 2026-07-26 — HUMAN ERROR COPY ─────────────────
            // The old text led with internal metering mechanics and never told
            // the user what happened to the message they had just typed. It
            // also read as a hard failure, which is how a temporary billing
            // state came across as "Sarah is broken".
            $agentName = $agent->name ?: 'Sarah';
            \Illuminate\Support\Facades\DB::table('agent_messages')->insert([
                'workspace_id'  => $wsId,
                'agent_slug'    => $slug,
                'sender'        => $agentName,
                'content'       => "I can't reply just yet — this workspace is out of credits. "
                                 . "Your message is saved, so top up and I'll pick straight up from here.",
                'role'          => 'agent',
                'metadata_json' => $withCorr(['phase' => 'final', 'error' => true, 'reason' => 'insufficient_credits']),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => "This workspace is out of credits, so {$agentName} can't reply right now. "
                           . "Your message has been saved — add credits and she'll continue from where you left off.",
                'reason'            => 'insufficient_credits',
                'message_saved'     => true,
                'required_credits'  => 1,
                'chat_counter'      => $_meter['counter'],
                'action_label'      => 'Top up credits',
            ], 402);
        }

        // (user message + audit row are persisted above, before metering)


        // ── v1.4.4 (2026-05-30) — Two-phase response (ChatGPT-style ack) ──
        // Generate an instant heuristic acknowledgment ("Got it — pulling
        // that up.") from the user's intent, persist it as Sarah's first
        // bubble, ship it to the client immediately via
        // fastcgi_finish_request(), then continue the heavy LLM pipeline
        // in this same FPM worker. The SPA renders the ack in <1s and
        // polls /agents/{slug}/messages every 2.5s for the final reply.
        //
        // Skipped when: image vision is in flight (vision payloads are
        // already chunky and the ack would feel out-of-place), or quick
        // actions that produce deterministic output anyway.
        $earlyAckMessageId = null;
        $earlyAckText      = null;
        $useTwoPhase       = empty($image) && empty($quickAction) && function_exists('fastcgi_finish_request');
        if ($useTwoPhase) {
            try {
                $ackSvc = app(\App\Core\Agent\AckGeneratorService::class);
                $earlyAckText = $ackSvc->generate($content, $slug, $agent->name);
                $insertedId = \Illuminate\Support\Facades\DB::table('agent_messages')->insertGetId([
                    'workspace_id' => $wsId,
                    'agent_slug'   => $slug,
                    'sender'       => $agent->name,
                    'content'      => $earlyAckText,
                    'role'         => 'agent',
                    'metadata_json'=> $withCorr(['is_ack' => true, 'phase' => 'ack']),
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
                $earlyAckMessageId = (int) $insertedId;
                $ackResponse = [
                    'sent'             => true,
                    'pending'          => true,
                    'ack'              => $earlyAckText,
                    'ack_message_id'   => $earlyAckMessageId,
                    'agent_name'       => $agent->name,
                    'chat_meter'       => [
                        'counter'        => $_meter['counter']   ?? 0,
                        'debited'        => $_meter['debited']   ?? false,
                        'threshold'      => 10,
                        'effective_cost' => '0.1 cr',
                    ],
                    'expected_seconds' => 15,
                    'poll_url'         => "/agents/{$slug}/messages",
                    'poll_interval_ms' => (int) config('chat.poll_interval_ms', 2500), // RISK-0050: single source (config/chat.php); the event lookback is derived from this value
                    'poll_after_id'    => $earlyAckMessageId,
                    // Slice 1A.1 — the anchor the client should bind the final
                    // reply to. poll_after_id is retained for backward
                    // compatibility with the shipped SPA, but it is the ordering
                    // heuristic that caused F1-D02 and will be retired in 1A.2.
                    'user_message_id'  => $userMessageId,
                    'execution_id'     => $execId,
                    'conversation_id'  => $corr['conversation_id'],
                ];
                // Ship the response to the client. PHP-FPM closes the
                // connection but keeps this worker running so the heavy
                // pipeline below still executes and persists the final
                // reply to agent_messages.
                if (ob_get_level() > 0) { @ob_end_clean(); }
                $ackJson = json_encode($ackResponse);
                @header('X-Accel-Buffering: no');
                @header('Connection: close');
                @header('Content-Length: ' . strlen($ackJson));
                echo $ackJson;
                if (function_exists('session_write_close')) { @session_write_close(); }
                @fastcgi_finish_request();
                // Defensive: ignore client disconnect so the long LLM
                // call doesn't get aborted by the kernel.
                @ignore_user_abort(true);
                @set_time_limit(115);

                // Safety net: if the LLM pipeline crashes after we shipped
                // the ack, the SPA will poll forever. Register a shutdown
                // function that checks if a 'final' row was written;
                // if not, insert an error row so the SPA's poll terminates
                // with a visible error instead of silent timeout.
                register_shutdown_function(function () use ($wsId, $slug, $agent, $earlyAckMessageId, $corr) {
                    try {
                        // ── SLICE 1A.4 — CORRELATION-SCOPED CRASH NET ──────────
                        // This used to take the NEWEST agent row after the ack id
                        // and ask "is it final?". Under concurrent turns the newest
                        // row after my ack is usually ANOTHER turn's ack row, so the
                        // answer came back "no" even when this turn's own final had
                        // been written seconds earlier — and the net then appended a
                        // spurious "Hit a snag" on top of a successful reply.
                        //
                        // Measured on the 200-turn stress run: 29 of 232 finals
                        // (12.5%) were fake errors written AFTER a real answer. That
                        // is a meaningful share of the 30.1% placeholder rate in F1,
                        // and none of it was ever a provider failure.
                        //
                        // Ask the only question that is actually correct: does a
                        // final row exist FOR THIS EXECUTION?
                        $hasFinal = \Illuminate\Support\Facades\DB::table('agent_messages')
                            ->where('workspace_id', $wsId)
                            ->where('agent_slug', $slug)
                            ->where('role', 'agent')
                            ->where('id', '>', $earlyAckMessageId)
                            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.execution_id')) = ?", [$corr['execution_id']])
                            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.phase')) = 'final'")
                            ->exists();
                        if (!$hasFinal) {
                            $lastErr = error_get_last();
                            $errMsg = ($lastErr && in_array($lastErr['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true))
                                ? "Hit a snag while working on that — please try again. (error: {$lastErr['message']})"
                                : "Hit a snag while working on that — please try again.";
                            \Illuminate\Support\Facades\DB::table('agent_messages')->insert([
                                'workspace_id'  => $wsId,
                                'agent_slug'    => $slug,
                                'sender'        => $agent->name,
                                'content'       => $errMsg,
                                'role'          => 'agent',
                                'metadata_json' => json_encode(array_merge($corr, ['phase' => 'final', 'error' => true, 'ack_message_id' => $earlyAckMessageId])),
                                'created_at'    => now(),
                                'updated_at'    => now(),
                            ]);
                            \Illuminate\Support\Facades\Log::error('[AgentChat] two-phase pipeline crashed, error row persisted', [
                                'ws' => $wsId, 'slug' => $slug, 'last_php_error' => $lastErr,
                            ]);
                        }
                    } catch (\Throwable $shutErr) {
                        \Illuminate\Support\Facades\Log::critical('[AgentChat] shutdown safety net itself crashed', [
                            'ws' => $wsId, 'err' => $shutErr->getMessage(),
                        ]);
                    }
                });
            } catch (\Throwable $ackErr) {
                \Illuminate\Support\Facades\Log::warning('[AgentChat] early-ack failed, falling back to synchronous mode', [
                    'ws'  => $wsId,
                    'err' => $ackErr->getMessage(),
                ]);
                $earlyAckMessageId = null;
                $earlyAckText      = null;
                $useTwoPhase       = false;
            }
        }

        // ── Build agent context ──
        $workspace = \App\Models\Workspace::find($wsId);
        // 2026-06-08 — only GENUINELY ACTIVE tasks count as "current". Previously
        // this pulled the newest 10 tasks regardless of status and surfaced the
        // stale "Executing step N of M" progress_message (dropping the real
        // status), so completed/failed work was fed to the agent as "Current
        // tasks" — the root of the phantom "pending tasks" narrative. Now: active
        // tasks with their REAL status, plus a count of recent completions so the
        // agent can speak to finished work WITHOUT calling it pending.
        $recentTasks = \App\Models\Task::where('workspace_id', $wsId)
            ->whereRaw("JSON_CONTAINS(assigned_agents_json, ?)", ['"'.$slug.'"'])
            ->whereNotIn('status', ['completed', 'failed', 'cancelled'])
            ->orderByDesc('created_at')->limit(10)->get()
            ->map(fn($t) => ucfirst(str_replace('_', ' ', (string) $t->action)) . ' — ' . $t->status)->implode("\n- ");
        $recentDoneCount = \App\Models\Task::where('workspace_id', $wsId)
            ->whereRaw("JSON_CONTAINS(assigned_agents_json, ?)", ['"'.$slug.'"'])
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subDay())
            ->count();

        // ── Conversation history (last 20 messages) ──  2026-06-10 fix-all L1/L8: 10→20 so Sarah can reconcile against more of what she said earlier
        $history = \Illuminate\Support\Facades\DB::table('audit_logs')
            ->where('workspace_id', $wsId)
            ->where('action', 'agent.direct_message')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.agent_slug')) = ?", [$slug])
            ->orderByDesc('created_at')->limit(20)->get()->reverse()
            ->map(function($row) {
                $meta = json_decode($row->metadata_json, true);
                return ($meta['from'] ?? 'User') . ': ' . ($meta['content'] ?? '');
            })->implode("\n");

        $skills = is_array($agent->skills_json) ? $agent->skills_json : json_decode($agent->skills_json ?? '[]', true);
        $isSarah = in_array($slug, ['sarah', 'dmm']);

        // ── Handle quick actions ──
        if ($quickAction === 'my_tasks') {
            $content = "List all my current tasks with their status.";
        } elseif ($quickAction === 'recent_completions') {
            $content = "Summarize what tasks I completed recently.";
        } elseif ($quickAction === 'whats_next') {
            $content = "What are my upcoming tasks and priorities?";
        }

        // ── Handle vision attachment ──
        $visionContext = '';
        if ($image) {
            try {
                $runtime = app(\App\Connectors\RuntimeClient::class);
                $visionResult = $runtime->visionAnalyze("Analyze this image in the context of: {$content}", $image);
                if ($visionResult['success'] ?? false) {
                    $visionContext = "\n\n[The user attached an image. GPT-4o vision analysis: " . ($visionResult['analysis'] ?? '') . "]";
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[AgentChat] Vision failed: ' . $e->getMessage());
            }
        }

        // ── Detect confirmation replies ("yes", "go ahead", etc.) ──
        $confirmPhrases = ['yes','proceed','go ahead','do it','confirm','ok','okay','sure','go','yes please','yep','yeah','approved','approve'];
        $normConfirm = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', '', strtolower(trim($content)))));
        $isConfirmation = in_array($normConfirm, $confirmPhrases, true)
            || (mb_strlen($normConfirm) <= 22 && (bool) preg_match('/^(yes|yeah|yep|ok|okay|sure|confirm|approved?|proceed|go ahead|go for it|do it|please do)\b/', $normConfirm));

        // If confirming, tell Sarah to execute the pending task from conversation history
        if ($isConfirmation && $isSarah) {
            $content = "The user confirmed. Execute the task you proposed in the previous message. Create all tasks now. " . $content;
        }

        // If this is a TASK BRIEF (from Assign Task modal), tell Sarah to propose then wait for confirmation
        $isTaskBrief = str_contains($content, 'TASK BRIEF:');
        if ($isTaskBrief && $isSarah) {
            $content = str_replace('TASK BRIEF:', '', $content);
        }

        // 2026-07-07 — DETERMINISTIC COMMAND ROUTER. The chat LLM too often
        // answers conversationally instead of acting (audit: 36% reliable). For a
        // few UNAMBIGUOUS orders, bypass the LLM: do the work + post the final
        // reply directly, then end. High-precision matching ONLY — anything
        // ambiguous falls through to the full LLM pipeline. Two-phase only (the
        // ack response is already shipped, so we just persist the final row).
        if ($isSarah && $useTwoPhase && ! $isConfirmation && ! $isTaskBrief) {
            $routerReply = null;
            $c = strtolower(trim($content));
            try {
                $countMissingImgs = (int) DB::table('articles')->where('workspace_id', $wsId)
                    ->whereIn('status', ['published', 'draft'])
                    ->where(function ($w) { $w->whereNull('featured_image_url')->orWhere('featured_image_url', ''); })->count();

                // (1) COUNT / "how many articles" -> answer with REAL numbers
                // P2-2 (T027): "How much revenue did the blog bring in last month?"
                // satisfied both halves of this pattern and was answered with an
                // article count - the model never saw the question. A quantity
                // question whose SUBJECT is money, traffic or conversions is not a
                // content count, and must fall through to the full pipeline.
                $__notACount = '/\b(revenue|money|sales|income|profit|earnings?|roi|turnover|'
                              . 'traffic|visitors?|sessions?|clicks?|impressions?|conversions?|'
                              . 'ctr|bounce|engagement|spend|spent|budget|cost|costs|credits?)\b/';
                if (preg_match('/\b(how many|number of|count of|how much)\b.*\b(article|articles|post|posts|blog|content|page|pages|published|drafts?)\b/', $c)
                    && ! preg_match($__notACount, $c)) {
                    $pub = (int) DB::table('articles')->where('workspace_id', $wsId)->where('status', 'published')->count();
                    $drf = (int) DB::table('articles')->where('workspace_id', $wsId)->where('status', 'draft')->count();
                    $routerReply = "You have {$pub} published article" . ($pub === 1 ? '' : 's')
                        . ($drf > 0 ? " and {$drf} draft" . ($drf === 1 ? '' : 's') : '') . ". "
                        . ($countMissingImgs > 0
                            ? "{$countMissingImgs} of them still need a featured image — just say \"add the missing images\" and I'll generate them."
                            : "Every one has a featured image.");
                }
                // (1b) COUNT keywords -> REAL number. 2026-07-15: was falling
                // through to the LLM, which answered "0 keywords" despite tracked
                // ones (live-battery FAIL). Mirrors the article-count branch.
                elseif (preg_match('/\bkeywords?\b/', $c)
                        && preg_match('/\b(how many|number of|count of|how much|count|total|tracking|am i tracking|do i track)\b/', $c)
                        && ! preg_match('/\b(rank|ranking|position|serp|add|create|research|suggest)\b/', $c)) {
                    $__kw = (int) DB::table('seo_keywords')->where('workspace_id', $wsId)->count();
                    $routerReply = $__kw > 0
                        ? "You're tracking {$__kw} keyword" . ($__kw === 1 ? '' : 's') . ". Say \"show my keywords\" to see them with volume and rank."
                        : "You're not tracking any keywords yet. Tell me which ones and I'll add them.";
                }
                // (2) "how many / which are missing featured images"
                elseif (preg_match('/\b(missing|without|no|need|needs)\b/', $c) && preg_match('/\bfeatured image|\bimages?\b/', $c)
                        && preg_match('/\b(how many|which|list|count|any)\b/', $c)) {
                    $routerReply = $countMissingImgs > 0
                        ? "{$countMissingImgs} article" . ($countMissingImgs === 1 ? ' is' : 's are') . " missing a featured image. Want me to generate them? Just say \"add the missing images\"."
                        : "Good news — every article already has a featured image.";
                }
                // (3) ADD / GENERATE the missing featured images (bulk, real ids via backend)
                elseif (preg_match('/\b(add|generate|create|fill|fix|make|give|put)\b/', $c)
                        && preg_match('/\bfeatured image|\bimages?\b/', $c)
                        && preg_match('/\b(missing|all|every|without|the ones|that (are|need)|need|dont have|do not have|no image)\b/', $c)) {
                    $r = app(\App\Engines\Write\Services\WriteService::class)->fillMissingImages($wsId, ['limit' => 15]);
                    $routerReply = ((int) ($r['created'] ?? 0) > 0)
                        ? "On it — I'm generating featured images for the {$r['created']} article" . (((int) $r['created']) === 1 ? '' : 's') . " that were missing one. They'll attach to each article as they finish."
                        : ($r['message'] ?? 'Every article already has a featured image — nothing to do.');
                }
                // (4) FIX ORPHAN pages
                elseif (preg_match('/\bfix|\blink|\bresolve|\bsort/', $c) && preg_match('/\borphan/', $c)) {
                    // 2026-08-08 — the creation gate can now REFUSE this, and
                    // this call sits outside any try/catch. "Which pages need
                    // their orphan links resolved?" matches this branch and
                    // classifies as a question, so the refusal that protects
                    // the owner's data would otherwise surface as a 500 on the
                    // live chat surface.
                    //
                    // Only the typed governance denial is handled here. Any
                    // other exception keeps propagating to the existing failure
                    // path — a broken engine must not be reported as "you did
                    // not authorise this".
                    try {
                        app(\App\Core\TaskSystem\TaskService::class)->create($wsId, [
                            'engine' => 'seo', 'action' => 'fix_orphans', 'source' => 'agent', 'assigned_agents' => ['james'],
                            'auto_approve' => true, 'requires_approval' => false, 'credit_cost' => 0,
                            'payload' => ['created_via' => 'sarah_router', 'user_request' => $content],
                        ]);
                        $routerReply = "On it — James is linking the orphan pages into the site now. I'll fold the result into your next update.";
                    } catch (\App\Core\TaskSystem\Exceptions\TaskCreationNotAuthorized $__denied) {
                        \Illuminate\Support\Facades\Log::info('[Sarah888] orphan-fix creation denied', $__denied->toLog());
                        // Truthful, and it does not pretend work is under way.
                        $routerReply = "I haven't started anything. That read as a question rather than an "
                            . "instruction, and I don't queue work off a question. Say \"fix the orphan pages\" "
                            . "and I'll set James on it.";
                    }
                }
                // (5) RANKINGS / Search Console questions -> REAL stored ranks +
                // HONEST connection status (the LLM path fabricates 'GSC connected'
                // + invented positions; this answers from seo_keywords truthfully).
                elseif ((preg_match('/\b(rank|ranking|rankings|position|serp)\b/', $c) || preg_match('/search console|\bgsc\b/', $c) || (preg_match('/\bgoogle\b/', $c) && preg_match('/\brank|\bposition|\bkeyword/', $c)))
                        && preg_match('/\b(how|what|where|show|list|top|my|are we|am i|doing|status)\b/', $c)
                        && ! preg_match('/\b(add|create|write|generate|make|fix|build|plan)\b/', $c)) {
                    $__gscOk = \Illuminate\Support\Facades\DB::table('gsc_connections')->where('workspace_id', $wsId)->where('connected', 1)->exists();
                    $__rk = \Illuminate\Support\Facades\DB::table('seo_keywords')->where('workspace_id', $wsId)->where('current_rank', '>', 0)->orderBy('current_rank')->limit(8)->get(['keyword', 'current_rank']);
                    if ($__rk->isEmpty()) {
                        $routerReply = ($__gscOk ? '' : "Google Search Console isn't connected yet, so I don't have live ranking data. ") . "I don't have any tracked keyword rankings on file. Add keywords to track and I'll monitor their positions.";
                    } else {
                        $__lines = []; $__lowConf = false;
                        foreach ($__rk as $__k) {
                            $__imp = (int) \Illuminate\Support\Facades\DB::table('gsc_metrics')->where('workspace_id', $wsId)->whereRaw('LOWER(query) = ?', [mb_strtolower($__k->keyword)])->sum('impressions');
                            if ($__imp < 10) { $__lowConf = true; $__note = ' — but only ' . $__imp . ' impression' . ($__imp === 1 ? '' : 's') . ' (barely any visibility, not a real rank)'; }
                            else { $__note = ' (' . $__imp . ' impressions)'; }
                            $__lines[] = '  - "' . $__k->keyword . '" — avg position ' . (int) $__k->current_rank . $__note;
                        }
                        $routerReply = "These are Google Search Console AVERAGE positions (where your pages have appeared in results), NOT live SERP ranks — live rank tracking is unreliable right now, so treat these as directional:\n" . implode("\n", $__lines);
                        if ($__lowConf) $routerReply .= "\n\nHeads up: the low-impression keywords have almost no search visibility yet — don't read those as 'ranking #N in Google'.";
                    }
                }
                // (6) FAILURES / "what failed" -> REAL recent failed tasks.
                // 2026-07-15: the LLM confabulated this (live-battery Case 7 —
                // described a COMPLETED fix_orphans as 'the failure'). Answer
                // deterministically from the tasks table, plain-English.
                elseif (preg_match('/\b(fail|failed|failing|failure|error|errors|went wrong|not working|broke|broken|didn.?t work|any problems?)\b/', $c)
                        && ! preg_match('/\b(fix|retry|rerun|resolve|why did|make sure)\b/', $c)) {
                    $__actMap = [
                        'create_automation' => 'an automation setup', 'write_article' => 'writing an article',
                        'generate_image_mini' => 'a featured image', 'generate_image' => 'a featured image',
                        'generate_image_high' => 'a featured image', 'fix_orphans' => 'fixing orphan pages',
                        'insert_link' => 'inserting an internal link', 'deep_audit' => 'an SEO audit',
                        'generate_meta' => 'generating meta tags', 'aeo_enrich' => 'AI-search enrichment',
                        'link_suggestions' => 'finding internal links', 'improve_draft' => 'improving a draft',
                    ];
                    $__fails = DB::table('tasks')->where('workspace_id', $wsId)->where('status', 'failed')
                        ->where('created_at', '>', now()->subDays(7))->orderByDesc('id')->limit(5)->get(['action', 'error_text']);
                    // The list above is a SAMPLE (limit 5). Count the failures
                    // separately: reporting the page size as the failure count
                    // understated 28 failures as 5 on 2026-08-09.
                    $__failTotal = \Illuminate\Support\Facades\DB::table('tasks')->where('workspace_id', $wsId)
                        ->where('status', 'failed')->where('created_at', '>', now()->subDays(7))->count();
                    if ($__fails->isEmpty()) {
                        $routerReply = "Good news \u{2014} nothing has failed in the last 7 days. Everything I've run completed cleanly.";
                    } else {
                        $__lines = [];
                        foreach ($__fails as $__f) {
                            $__h = $__actMap[$__f->action] ?? str_replace('_', ' ', (string) $__f->action);
                            $__reason = trim((string) ($__f->error_text ?? ''));
                            $__reason = preg_replace('/\b(Step \d+ \([a-z_]+\) failed:|Failed after \d+ attempts?:)\s*/i', '', $__reason);
                            $__reason = trim(preg_replace('/\s+/', ' ', preg_replace('/\([a-z_]+\)/', '', (string) $__reason)));
                            if ($__reason === '') $__reason = 'no error detail was recorded';
                            $__lines[] = '  - ' . $__h . " \u{2014} " . mb_substr($__reason, 0, 160);
                        }
                        $__n = count($__lines);
                        $__shown = count($__lines);
                        $routerReply = "Yes \u{2014} {$__failTotal} task" . ($__failTotal === 1 ? '' : 's') . " failed in the last 7 days"
                            . ($__failTotal > $__shown ? " (showing the {$__shown} most recent)" : '') . ":\n"
                            . implode("\n", $__lines) . "\n\nWant me to retry any of these?";
                    }
                }
            } catch (\Throwable $rtErr) {
                \Illuminate\Support\Facades\Log::warning('[SarahRouter] failed: ' . $rtErr->getMessage(), ['ws' => $wsId]);
                $routerReply = null; // any error -> fall through to the LLM pipeline
            }

            // ── SARAH888 — ROUTER INTENT: FACT OR THINKING? ────────────────
            // Measured 2026-08-09: 5 of 21 executive scenarios were answered
            // by this branch and scored 0%, because "walk me through which
            // systems are involved" contains the word "failing" and a regex
            // cannot tell a request for a list from a request for a diagnosis.
            //
            // The deterministic answer is TRUE and stays. What changes is
            // whether it is the ANSWER or the EVIDENCE.
            if ($routerReply !== null) {
                $__intent = ['mode' => \App\Core\Sarah888\RouterIntent::STATUS, 'why' => '', 'source' => 'skipped'];
                try {
                    $__intent = app(\App\Core\Sarah888\RouterIntent::class)
                                  ->classify((string) $content, (int) $wsId);
                } catch (\Throwable $__riErr) {
                    // Grounded reasoning is the safe default, not a bare list.
                    $__intent = ['mode' => \App\Core\Sarah888\RouterIntent::ANALYSIS,
                                 'why' => 'classifier threw', 'source' => 'fallback_threw'];
                    \Illuminate\Support\Facades\Log::warning('[Sarah888] RouterIntent threw: ' . $__riErr->getMessage(), ['ws' => $wsId]);
                }

                \Illuminate\Support\Facades\Log::info('[Sarah888] router intent', [
                    'ws' => $wsId, 'mode' => $__intent['mode'],
                    'source' => $__intent['source'], 'why' => $__intent['why'],
                ]);

                if ($__intent['mode'] === \App\Core\Sarah888\RouterIntent::STATUS) {
                    DB::table('agent_messages')->insert([
                        'workspace_id'  => $wsId, 'agent_slug' => $slug, 'sender' => $agent->name,
                        'content'       => \App\Core\LaunchScope\LaunchScopeLanguageGuard::apply((string) $routerReply), /* W6 truthfulness guard */ 'role' => 'agent',
                        'metadata_json' => $withCorr(['phase' => 'final', 'router' => true,
                                                      'router_intent' => 'status']),
                        'created_at'    => now(), 'updated_at' => now(),
                    ]);
                    return;
                }

                // ANALYSIS — the facts become evidence and reasoning continues.
                $__deterministicEvidence = (string) $routerReply;
            }
        }


        // ── SARAH888 — TURN 2: DOES THIS TURN AUTHORISE A PENDING OFFER? ───
        // Measured 2026-08-09: ChatActionProposal::resolveAuthorization() had
        // existed since Phase 1Y with ZERO production callers. A "yes" typed
        // into chat had no code path to a pending authorization at all — the
        // planner simply ran again, and whatever it chose next became the
        // authorised thing. Approval reached proposals only through the
        // approvals UI or an explicit proposal id.
        //
        // This runs BEFORE the planner, so an authorising turn executes the
        // offer that was actually made rather than whatever the model would
        // now decide to do.
        //
        // SCOPE, DELIBERATELY NARROW. Only outcomes that depend on a real
        // pending offer short-circuit the pipeline. NONE_PENDING deliberately
        // falls through: "yes, do it" with no chat_action outstanding may be
        // continuing something else entirely — a proactive strategy proposal,
        // a multi-turn plan — and answering "nothing is pending" would be a
        // regression in ordinary conversation. Nothing is lost by falling
        // through, because ConfirmationClaimGuard still prevents an unbacked
        // promise at reply time.
        try {
            $__abC = \App\Core\Sarah888\AuthorizationBinder::class;
            // The owner's own words, before the legacy confirmation shim
            // above rewrites $content into a directive sentence.
            $__ownerSaid = trim((string) $r->input('content', ''));
            $__ab  = app($__abC)->bind((int) $wsId, $corr['conversation_id'] ?? null, $__ownerSaid, $userId > 0 ? $userId : null);

            $__terminal = null;

            if ($__ab['outcome'] === $__abC::AUTHORIZED && $__ab['proposal']) {
                $__p   = $__ab['proposal'];
                $__res = app(\App\Core\Orchestration\ProactiveStrategyEngine::class)
                            ->approveProposal((int) $wsId, $userId > 0 ? $userId : 1, (int) $__p->id);

                if ($__res['success'] ?? false) {
                    $__cost = (int) $__p->total_credits;
                    $__terminal = "Approved — " . $__p->description . " is queued now"
                                . ($__cost > 0 ? ", {$__cost} credit" . ($__cost === 1 ? '' : 's') . " charged." : ".");
                } elseif (($__res['code'] ?? '') === 'NO_CREDITS') {
                    $__terminal = "I couldn't run that — there aren't enough credits for it. "
                                . "Nothing has been created or charged.";
                } else {
                    $__terminal = "I couldn't run that one after all, so nothing has been created or charged.";
                }

                \Illuminate\Support\Facades\Log::info('[Sarah888] chat authorization bound', [
                    'ws' => $wsId, 'proposal_id' => $__p->id,
                    'success' => (bool) ($__res['success'] ?? false),
                ]);
            } elseif (in_array($__ab['outcome'], [$__abC::AMBIGUOUS, $__abC::REFUSED, $__abC::CANCELLED], true)
                      && trim((string) $__ab['message']) !== '') {
                $__terminal = trim((string) $__ab['message']);
            }

            if ($__terminal !== null) {
                \Illuminate\Support\Facades\DB::table('agent_messages')->insert([
                    'workspace_id'  => $wsId, 'agent_slug' => $slug, 'sender' => $agent->name,
                    'content'       => $__terminal, 'role' => 'agent',
                    'metadata_json' => $withCorr(['phase' => 'final', 'authorization' => $__ab['outcome']]),
                    'created_at'    => now(), 'updated_at' => now(),
                ]);
                return;
            }
        } catch (\Throwable $__abErr) {
            // Never break the chat over the binder. Falling through means the
            // turn is planned normally, which is exactly today's behaviour.
            \Illuminate\Support\Facades\Log::warning('[Sarah888] AuthorizationBinder failed: ' . $__abErr->getMessage(), ['ws' => $wsId]);
        }

        // PATCH (Sarah brand context, 2026-05-09) — Pull workspace_memory
        // facts and inject as AUTHORITATIVE GROUND TRUTH at the top of
        // Sarah's system prompt. Without this Sarah ECHOES user typos
        // (e.g. visitor types "Levelupgroth.io" — Sarah repeats it
        // instead of using the canonical "levelupgrowth.io" from memory).
        $brandFacts = [];
        try {
            $memRows = DB::table('workspace_memory')->where('workspace_id', $wsId)->get(['key','value_json']);
            foreach ($memRows as $row) {
                $val = is_string($row->value_json) ? json_decode($row->value_json, true) : $row->value_json;
                if (is_string($val) && $val !== '') $brandFacts[$row->key] = $val;
            }
        } catch (\Throwable $e) {}
        $brandFactsBlock = "AUTHORITATIVE WORKSPACE FACTS (these are GROUND TRUTH — use them, never echo back user typos or alternatives):\n";
        $brandFactsBlock .= "- Business name: " . ($brandFacts['business_name'] ?? $workspace->business_name ?? $workspace->name ?? 'this business') . "\n";
        if (! empty($brandFacts['domain']))   $brandFactsBlock .= "- Domain: " . $brandFacts['domain'] . "\n";
        if (! empty($brandFacts['industry'])) $brandFactsBlock .= "- Industry: " . $brandFacts['industry'] . "\n";
        elseif (! empty($workspace->industry)) $brandFactsBlock .= "- Industry: " . $workspace->industry . "\n";
        if (! empty($brandFacts['location'])) $brandFactsBlock .= "- Location: " . $brandFacts['location'] . "\n";
        // DISCONNECTED ENGINES (2026-07-19) — brandFacts loads EVERY memory key
        // but this block only rendered a hardcoded few, so a new key never reached
        // the model. Render it explicitly and as a HARD constraint: Sarah was
        // proposing email/social work for a workspace that has no email service and
        // zero connected social accounts, filling the approval queue with items that
        // can never execute.
        if (! empty($brandFacts['disconnected_engines'])) {
            $brandFactsBlock .= "- DISCONNECTED ENGINES (HARD CONSTRAINT): " . $brandFacts['disconnected_engines'] . "\n"
                . "  Never propose, queue, or delegate work for a disconnected engine. Do not ask the owner to approve it.\n"
                . "  If they ask for it, say plainly that the channel is not connected yet and offer the SEO/content equivalent instead.\n";
        }
        elseif (! empty($workspace->location)) $brandFactsBlock .= "- Location: " . $workspace->location . "\n";
        // Wave 16b (2026-05-19) — inject the user's currently-selected
        // website so Sarah (and every agent) tailors strategy + delegations
        // to that one site instead of the entire workspace.
        $activeSiteUrl = trim((string) ($r->input('site_url') ?: $r->header('X-Lgse-Active-Site') ?: ''));
        if ($activeSiteUrl !== '') {
            $brandFactsBlock .= "- Currently active website (user's selected SEO scope): {$activeSiteUrl}\n";
            $brandFactsBlock .= "  → Anchor your strategy, audits, and delegations to THIS site. Do not reference the user's other workspace sites unless the user asks.\n";
        }
        $brandFactsBlock .= "Rule: if the user mis-spells the business name or domain, USE the correct spelling above. Never echo a typo.\n\n";

        $formatRules = "FORMAT YOUR RESPONSES:\n"
            . "- Use **bold** for section headers\n"
            . "- Use line breaks between sections\n"
            . "- Use bullet points (- text) for lists, one short line each, max 5 per list\n"
            . "- Lead with the most important point\n"
            . "- Never write walls of text\n\n";

        // PATCH (Phase 2 — tool schema + read-back, 2026-05-10) — assemble the
        // closed tool schema for this agent and any unread completed-task
        // insights. Both blocks are appended to the system prompt below.
        $toolSchemaSvc = app(\App\Core\Orchestration\ToolSchemaService::class);
        $toolSchemaBlock = $toolSchemaSvc->getToolSchemaPrompt($slug);

        $insightsBlock = '';
        if ($isSarah) {
            try {
                $readBack = app(\App\Core\Orchestration\SarahReadBackService::class);
                $insightsBlock = $readBack->renderInsightsBlock($wsId, 5);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[SarahChat] read-back failed: ' . $e->getMessage());
            }
        }

        // PATCH (Phase 2H — cross-agent knowledge, 2026-05-10) — every agent
        // sees what their teammates have learned in this workspace. Sarah's
        // own readback above already records into the KB on her side; here
        // we surface it for the OTHER specialists.
        $sharedKnowledgeBlock = '';
        try {
            $sharedKnowledgeBlock = app(\App\Core\Intelligence\WorkspaceKnowledgeBase::class)
                ->buildContextBlock($wsId, $slug, 5);
        } catch (\Throwable $kbErr) {
            \Illuminate\Support\Facades\Log::warning('[AgentChat] KB block failed: ' . $kbErr->getMessage());
        }

        // PATCH (concise-persona, 2026-05-10) — prepend a sharp/conversational
        // rule to every agent's DM system prompt. Overrides the professor tone.
        // ── SARAH888 — SHAPE OF THE ANSWER ─────────────────────────────────
        // Brevity is the default and stays the default. An analytical turn gets
        // room, because nine executive dimensions do not fit in three sentences.
        $__brevityShape = "You are a sharp, direct AI specialist. Keep all responses SHORT and CONVERSATIONAL — maximum 3 sentences unless the user explicitly asks for a plan, report, or detailed breakdown. No bullet frameworks, no numbered action plans, no headers unless asked. Talk like a smart colleague in a Slack message, not a consultant writing a strategy document. If the user asks a simple question, give a simple answer.\n\n";

        $__executiveShape = "This turn asks you to THINK, not to look something up, so the three-sentence rule does not apply — give it the room the reasoning needs and not one word more. Still talk like yourself: a sharp colleague thinking out loud, not a consultant presenting findings.\n"
            . "OWNER CONSTRAINTS OUTRANK THIS. If the owner set a length, format or shape — 'under 80 words', 'one paragraph', 'two minutes', 'just the headline' — obey it exactly, even at the cost of detail. Lead with the single most material fact and stop. Their instruction is not a suggestion you may improve on.\n"
            . "Structure is allowed: short paragraphs, or a few bullets where they genuinely help.\n"
            . "Work from the EXECUTIVE MATERIAL below and account for what it actually supports — what could go wrong, what depends on what, what is given up by choosing this, how anyone would later verify it worked, what happens if it does not, who owns it and who must be told.\n"
            . "Where the material gives you nothing for one of those, say so in plain words. \"There is no ranking evidence to judge that\" is a real answer. Naming a risk, a dependency or a number you do not have is worse than leaving it out, and padding to look thorough is the failure mode to avoid.\n\n";


        // 2026-08-13 — REGISTER. The BEFORE battery showed Sarah answering every
        // turn, including plain corrections, with a numbered action plan. This
        // governs the SHAPE and VOICE of the answer only; it deliberately says
        // nothing about what is true, which the TRUTH RULES and the EXECUTIVE
        // MATERIAL below already decide.
        $__voiceRule =
              "HOW YOU TALK.\n"
            . "You work with this owner every day. Write like a person they trust, not like a document. "
            . "Contractions are fine. Lead with what you actually think.\n"
            . "MATCH THE ANSWER TO THE ASK:\n"
            . "  - Casual or simple question -> one to three sentences of plain prose. No lists, no headers, no plan.\n"
            . "  - A correction from the owner -> accept it in a line and move on. Do NOT answer a correction with an action plan.\n"
            . "  - Strategy or trade-off -> conversational but rigorous. Say what you would do and why, in prose. Bullets only if they genuinely help.\n"
            . "  - Crisis -> calm and decisive: what is happening, what you would do first, what it costs. No preamble.\n"
            . "  - A report, board update or client document -> THIS is where polished structure belongs. Use it here and mostly only here.\n"
            . "NUMBERED ACTION PLANS AND BOLD HEADERS ARE NOT THE DEFAULT. Use them when the owner asks for a plan or a document, "
            . "or when a real sequence genuinely matters. Otherwise write prose.\n"
            . "SAY WHAT YOU THINK. If you disagree, say so first and give the reason — \"I wouldn't do that, because...\". "
            . "If the evidence is thin, say \"I don't have enough to call that yet\" instead of hedging in longer words. "
            . "If you do not know, say you do not know.\n"
            . "DO NOT PERFORM FRIENDLINESS. No \"Hey boss\", no \"Great question\", no \"Absolutely\", no emoji, no exclamation marks, "
            . "no complimenting the question, no enthusiasm you do not have. Warmth comes from being useful and direct, not from adjectives.\n"
            . "Never trade accuracy for a nicer sentence: everything in the TRUTH RULES and the material below still binds.\n\n";

        $__shapeIsExecutive = false;
        if ($slug === 'sarah') {
            try {
                // RISK-0123 (2026-08-29) — RouterIntent only knows STATUS vs ANALYSIS; it was asked
                // about EVERY turn, so a bare "Go ahead." (an authorisation of the previous request)
                // came back ANALYSIS and the turn was composed WITHOUT tools ("you are NOT creating
                // or queueing work on this turn"). Sarah then said "kicking off now" with nothing
                // queued. A turn that commissions or authorises work is never analytical.
                $__turnShape = app(\App\Core\Sarah888\SpendPolicy::class)->assessTurn((string) $__ownerMessage);
                $__isWorkTurn = !empty($__turnShape['authorized'])
                    || in_array($__turnShape['classification'] ?? '', ['directive', 'directive-question', 'authorisation'], true);
                if ($__isWorkTurn) {
                    \Illuminate\Support\Facades\Log::info('[Sarah888] work/authorisation turn — analytical shape skipped (RISK-0123)', [
                        'ws' => $wsId, 'classification' => $__turnShape['classification'] ?? null,
                    ]);
                }
                $__shapeIsExecutive = $__isWorkTurn ? false : app(\App\Core\Sarah888\RouterIntent::class)
                                        ->isAnalysis($__ownerMessage, (int) $wsId);
            } catch (\Throwable $__shapeErr) {
                // Brevity on failure: the safe default is today's behaviour.
                \Illuminate\Support\Facades\Log::warning('[Sarah888] shape classify failed: ' . $__shapeErr->getMessage(), ['ws' => $wsId]);
            }
        }

        $conciseRule = ($__shapeIsExecutive ? $__executiveShape : $__brevityShape)
            . $__voiceRule
            // 2026-06-10 — TRUTH & HONESTY rules (forensic fix-all). These override the urge to sound helpful.
            . "TRUTH RULES (these override sounding helpful):\n"
            . "1. NEVER say something is done / generated / completed / assigned / fixed unless it ACTUALLY happened — a task that COMPLETED and whose real output exists. If you only QUEUED or STARTED it, say 'queued' or 'started', never 'done'. If you did not create or run it, say so. NEVER invent a result, image, file, count, score, or article id. A task marked completed with no visible output is NOT a success — flag it as needing a check.\n"
            . "2. RECONCILE with what you said earlier in this conversation. If a number now differs from one you gave before (task counts, image counts, audit scores, keyword counts), acknowledge it changed and why — do not silently contradict yourself. If two of your own tool results disagree, say which you trust and why.\n"
            . "3. SURFACE FAILURES PROACTIVELY and the FIRST time. If any task failed, lead with it. Never say 'everything is running smoothly' or 'all done' when something failed.\n"
            . "4. Distinguish QUEUED vs RUNNING vs COMPLETED vs FAILED precisely and out loud.\n"
            . "5. Be honest about capability limits (e.g. you cannot message on a timer unless you actually schedule it). You CAN build a brand-new website from scratch: gather the business details (name, industry, what they offer, any colour preferences), then emit a full_site_generation task with a build_data object - it is review-gated so the customer approves before the 10-credit build runs.\n"
            . "6. Before creating tasks, check what is already running/queued for this workspace; do NOT re-queue duplicates.\n\n";

        // 2026-05-23 FIX 24 (B) — workspace-wide active queue summary for
        // Sarah only. Without this Sarah is blind to what's already running
        // and re-issues batches on ambiguous messages. Today's "Hi Sarah"
        // duplicated the 11-article batch because the LLM saw the user
        // begging for articles in history but no Sarah confirmation
        // (variable-shadowing crash had silenced her reply).
        $activeQueueBlock = '';
        if ($isSarah) {
            try {
                $cutoff = now()->subHours(24);
                $inFlight = \Illuminate\Support\Facades\DB::table('tasks')
                    ->where('workspace_id', $wsId)
                    ->where('created_at', '>=', $cutoff)
                    ->whereIn('status', ['queued','running','pending','dispatched','blocked','approved'])
                    ->select('action', \Illuminate\Support\Facades\DB::raw('COUNT(*) as n'))
                    ->groupBy('action')
                    ->get();
                $recentDone = \Illuminate\Support\Facades\DB::table('tasks')
                    ->where('workspace_id', $wsId)
                    ->where('completed_at', '>=', now()->subHours(6))
                    ->where('status', 'completed')
                    ->select('action', \Illuminate\Support\Facades\DB::raw('COUNT(*) as n'))
                    ->groupBy('action')
                    ->get();
                $recentWrites = \Illuminate\Support\Facades\DB::table('tasks')
                    ->where('workspace_id', $wsId)
                    ->where('action', 'write_article')
                    ->where('created_at', '>=', now()->subHours(24))
                    ->whereNotIn('status', ['failed','cancelled'])
                    ->orderByDesc('id')
                    ->limit(15)
                    ->get(['payload_json']);
                $writeTitles = [];
                foreach ($recentWrites as $rw) {
                    $p = json_decode($rw->payload_json ?? '{}', true);
                    $t = $p['title'] ?? $p['topic'] ?? $p['keyword'] ?? null;
                    if ($t) $writeTitles[] = $t;
                }
                if ($inFlight->count() || $recentDone->count() || $writeTitles) {
                    $activeQueueBlock = "\nACTIVE TASK QUEUE (DO NOT RE-ISSUE — this work is already in flight or just completed):\n";
                    if ($inFlight->count()) {
                        $activeQueueBlock .= "In progress / queued (last 24h):\n";
                        foreach ($inFlight as $r) {
                            $activeQueueBlock .= "  - {$r->n}x {$r->action}\n";
                        }
                    }
                    if ($recentDone->count()) {
                        $activeQueueBlock .= "Recently completed (last 6h):\n";
                        foreach ($recentDone as $r) {
                            $activeQueueBlock .= "  - {$r->n}x {$r->action}\n";
                        }
                    }
                    if ($writeTitles) {
                        $activeQueueBlock .= "Recent write_article titles already queued/written:\n";
                        foreach (array_slice($writeTitles, 0, 12) as $t) {
                            $activeQueueBlock .= "  - " . mb_substr($t, 0, 90) . "\n";
                        }
                    }
                    $activeQueueBlock .= "RULE: If the user asks for work that overlaps with the above, DO NOT include it in create_tasks. Tell the user it's already in progress or just finished. Only create NEW work that is not in this list.\n\n";
                }
            } catch (\Throwable $qErr) {
                \Illuminate\Support\Facades\Log::warning('[SarahChat] active-queue block failed: ' . $qErr->getMessage());
            }
        }

        // 2026-06-10 — deterministic 7-day task-activity snapshot. Sarah kept
        // answering "how many tasks completed" by counting the ≤30-row sample
        // platform.list_tasks returns (undercount) or quoting an ever-growing
        // all-time total. Prompt rules alone didn't reliably steer tool choice,
        // so inject the AUTHORITATIVE windowed numbers straight into context:
        // finished work = last 7 days, open work = live. She no longer needs a
        // tool for the common question, and the numbers are always truthful.
        $taskActivityBlock = '';
        if ($isSarah) {
            try {
                $done7 = DB::table('tasks')->where('workspace_id', $wsId)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->whereIn('status', ['completed', 'failed', 'cancelled'])
                    ->select('status', DB::raw('COUNT(*) as n'))->groupBy('status')->pluck('n', 'status');
                $open = DB::table('tasks')->where('workspace_id', $wsId)
                    ->whereIn('status', ['pending', 'queued', 'running'])
                    ->select('status', DB::raw('COUNT(*) as n'))->groupBy('status')->pluck('n', 'status');
                $c = (int) ($done7['completed'] ?? 0);
                $f = (int) ($done7['failed'] ?? 0);
                $x = (int) ($done7['cancelled'] ?? 0);
                $openTotal = (int) $open->sum();
                $openDetail = $openTotal ? ' (' . $open->map(fn($n, $s) => "{$n} {$s}")->implode(', ') . ')' : '';
                $taskActivityBlock = "\nTASK ACTIVITY (AUTHORITATIVE — use THESE exact numbers for any \"how many tasks\" question; do NOT count platform.list_tasks rows, that is only a sample and undercounts):\n"
                    . "Last 7 days: {$c} completed, {$f} failed" . ($x ? ", {$x} cancelled" : '') . ".\n"
                    . "Currently open right now: {$openTotal}{$openDetail}.\n"
                    . "RULE: If the user asks \"how many tasks (completed/failed/done)\" WITHOUT naming a longer period, your FIRST sentence must directly state the last-7-days numbers and the words \"in the last 7 days\" — e.g. \"In the last 7 days we've completed {$c} tasks ({$f} failed).\" Answer the count FIRST, before any recommendation or strategy. Do NOT dodge the question with article/keyword talk.\n"
                    . "RULE: Only if the user EXPLICITLY names a longer period (\"this month\", \"since the beginning\", \"all time\", \"in total ever\") do you give that span instead — call get_task_status with window=\"30d\" or \"all\" and report THAT number. Never volunteer the all-time total unprompted.\n\n";
            } catch (\Throwable $taErr) {
                \Illuminate\Support\Facades\Log::warning('[SarahChat] task-activity block failed: ' . $taErr->getMessage());
            }
        }

        // ── Build system prompt ──
        if ($isSarah) {
            // 2026-05-23 FIX 43 — content rules at Sarah's planning level.
            // WriteService::writeArticle enforces these at body-generation
            // time too (every article path), but Sarah should also know
            // them when proposing topics so she doesn't suggest "Tips for
            // 2025" or batches built around named competitors.
            $sarahCurrentYear = (int) date('Y');
            $sarahContentRules = "CONTENT RULES (enforce in every article you propose or delegate):\n"
                . "1. NEVER name specific competitor companies, brands, or service providers. "
                . "If the topic is comparative ('options to consider', 'alternatives'), describe "
                . "categories generically — never name a real competitor brand.\n"
                . "2. Current year is {$sarahCurrentYear}. NEVER propose article titles with prior "
                . "years (no 'Tips for " . ($sarahCurrentYear - 1) . "', no 'Trends for "
                . ($sarahCurrentYear - 2) . "'). Use {$sarahCurrentYear} or no year at all.\n";

            // 2026-05-24 FIX 45 — strategy tier framework injection.
            // Gives Sarah the locked tier definitions + per-asset costs +
            // recommendation rules so she can intelligently respond to
            // ambitious goals ("rank #1 for 10 keywords") with the right
            // tier proposal + top-up math + disclaimers.
            $sarahTierBlock = '';
            try {
                $planCreditLimit = (int) (DB::table('subscriptions')
                    ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
                    ->where('subscriptions.workspace_id', $wsId)
                    ->whereIn('subscriptions.status', ['active', 'trialing'])
                    ->orderByDesc('subscriptions.id')
                    ->value('plans.credit_limit') ?? 300);
                $sarahTierBlock = \App\Core\Strategy\StrategyTierService::buildPromptBlock($wsId, $planCreditLimit);
            } catch (\Throwable $tierErr) {
                \Illuminate\Support\Facades\Log::warning('[SarahChat] tier block failed: ' . $tierErr->getMessage());
            }

            // 2026-07-14 GROUNDING — inject REAL entity ids into the task-extraction
            // context so the LLM stops FABRICATING them (lead_id:0, ws1 article ids).
            // Leads are few — list them all with real ids; steer bulk image fills to the tool.
            $groundingBlock = '';
            try {
                $__leads = \Illuminate\Support\Facades\DB::table('leads')->where('workspace_id', $wsId)->orderByDesc('id')->limit(30)->get(['id', 'name', 'email', 'status']);
                if ($__leads->isNotEmpty()) {
                    $groundingBlock .= "\nREAL LEAD IDS (use these EXACT ids for ANY lead action — update/move/assign/follow-up. NEVER invent a lead_id or use 0; if the lead you need is not listed, tell the user you don't see it):\n";
                    foreach ($__leads as $__l) { $groundingBlock .= '  - lead_id=' . $__l->id . ': ' . ($__l->name ?: ($__l->email ?: 'lead')) . ' (status: ' . $__l->status . ")\n"; }
                }
                $__miss = (int) \Illuminate\Support\Facades\DB::table('articles')->where('workspace_id', $wsId)->whereIn('status', ['published', 'draft'])->where(function ($w) { $w->whereNull('featured_image_url')->orWhere('featured_image_url', ''); })->count();
                if ($__miss > 0) {
                    // 2026-07-23 — give the FULL breakdown so Sarah stops flip-flopping
                    // between "drafts missing" (subset) and "all missing" (total).
                    $__missDrf = (int) \Illuminate\Support\Facades\DB::table('articles')
                        ->where('workspace_id', $wsId)->where('status', 'draft')->whereNull('deleted_at')
                        ->where(function ($w) { $w->whereNull('featured_image_url')->orWhere('featured_image_url', ''); })->count();
                    $__missPub = $__miss - $__missDrf;
                    $groundingBlock .= "\nFEATURED IMAGES (authoritative — use THESE exact numbers and do NOT recompute or contradict them across turns): {$__miss} article(s) are missing a featured image in total — {$__missDrf} of them drafts and {$__missPub} published. If the user asks specifically about DRAFTS missing an image, the answer is {$__missDrf}; about ALL articles, it is {$__miss}. To add them, emit ONE fill_missing_images task IMMEDIATELY (engine=write, action=fill_missing_images, no article ids — the backend finds them). When the user says 'generate the missing images', 'add them', 'all', 'go', 'do it' or similar, DO NOT ask which ones, DO NOT list them, DO NOT ask for confirmation again — just emit that single task. NEVER invent article ids for a bulk image fill.\n";
                }
                // 2026-07-14 — authoritative content counts (kill the cross-turn
                // inconsistency: 155 vs 6 vs 145) + GSC honesty (stop fabricating
                // 'Search Console connected' + invented rankings when it is NOT).
                $__pub = (int) \Illuminate\Support\Facades\DB::table('articles')->where('workspace_id', $wsId)->where('status', 'published')->count();
                $__drf = (int) \Illuminate\Support\Facades\DB::table('articles')->where('workspace_id', $wsId)->where('status', 'draft')->count();
                $__tot = (int) \Illuminate\Support\Facades\DB::table('articles')->where('workspace_id', $wsId)->count();
                $groundingBlock .= "\nAUTHORITATIVE CONTENT COUNTS (use THESE EXACT numbers for any content question and NEVER contradict them across turns; 'indexed pages' from an SEO tool is a DIFFERENT metric, do not report it as the article count): published articles = {$__pub}, drafts = {$__drf}, total articles = {$__tot}.\n";
                $__gscOk = \Illuminate\Support\Facades\DB::table('gsc_connections')->where('workspace_id', $wsId)->where('connected', 1)->exists();
                if (! $__gscOk) {
                    $groundingBlock .= "GOOGLE SEARCH CONSOLE + ANALYTICS: NOT connected for this workspace — you have ZERO ranking/position/impression/click/traffic data. HARD RULE: never say Search Console or Analytics is connected, never state a keyword is at 'position N', never quote clicks/impressions/traffic, never invent rankings. If asked about rankings/traffic, say Search Console must be connected first to see that data.\n";
                } else {
                    // GSC connected — inject the REAL tracked ranks so the LLM stops
                    // inventing positions (e.g. 'position 9' when the truth is #3).
                    $__ranks = \Illuminate\Support\Facades\DB::table('seo_keywords')->where('workspace_id', $wsId)->where('current_rank', '>', 0)->orderBy('current_rank')->limit(10)->get(['keyword', 'current_rank', 'volume']);
                    // 2026-07-16 Phase-B numerical-integrity mitigation: inject REAL
                    // impressions/clicks/volume + a symmetric anti-fabrication guardrail.
                    $__since = now()->subDays(28)->toDateString();
                    $__gm = \Illuminate\Support\Facades\DB::table('gsc_metrics')->where('workspace_id', $wsId)->where('date', '>=', $__since)->selectRaw('LOWER(query) q, SUM(impressions) i, SUM(clicks) c')->groupBy('q')->get()->keyBy('q');
                    $__ti = (int) $__gm->sum('i'); $__tc = (int) $__gm->sum('c');
                    $__tctr = $__ti > 0 ? round($__tc / $__ti * 100, 2) : 0;
                    if ($__ranks->isNotEmpty()) {
                        $__rp = [];
                        foreach ($__ranks as $__r) {
                            $__row = $__gm[mb_strtolower($__r->keyword)] ?? null;
                            $__im = $__row ? (int) $__row->i : 0; $__cl = $__row ? (int) $__row->c : 0;
                            $__vol = $__r->volume !== null ? ', search volume ' . (int) $__r->volume . '/mo' : '';
                            $__rp[] = '"' . $__r->keyword . '" avg position ' . (int) $__r->current_rank . ', ' . $__im . ' impressions, ' . $__cl . ' clicks (28d)' . $__vol;
                        }
                        $groundingBlock .= "GOOGLE SEARCH CONSOLE: connected. Workspace 28-day totals: {$__ti} impressions, {$__tc} clicks, {$__tctr}% CTR. Per-keyword VERIFIED metrics (the ONLY real GSC numbers you have; present positions as 'average position N in Search Console', not 'rank #N'):\n  - " . implode("\n  - ", $__rp) . "\nHARD RULE — NUMERICAL INTEGRITY: The impressions, clicks, CTR, volume and positions above are the ONLY verified metrics you have. NEVER quote, estimate, round, extrapolate, or invent ANY impression, click, CTR, traffic, session, visitor, search-volume, ROI, revenue, dollar, CAC, LTV, conversion-rate, growth-rate, or forecast number that is not explicitly listed above. If asked for a metric you were not given, say 'I don't have verified data for that' — never guess.\n";
                    } else {
                        $groundingBlock .= "GOOGLE SEARCH CONSOLE: connected, but no keyword has a tracked position yet — do not invent ranking numbers.\n";
                    }
                }
            } catch (\Throwable $__ge) {}

            // IDENTITY FIX (2026-07-19) — Sarah replied "Hi Sarah!" to the owner's
            // "Hi Sarah". The prompt named HER and the BUSINESS but never the OWNER,
            // so the only name in context was her own and the model mirrored it back.
            // Resolve who she is actually talking to.
            $__ownerName = '';
            try {
                $__u = $r->user();
                $__ownerName = trim((string) ($__u->name ?? ''));
                if ($__ownerName === '' && $workspace) {
                    $__ownerName = trim((string) (DB::table('users')->where('id', $workspace->created_by)->value('name') ?? ''));
                }
            } catch (\Throwable $__ne) { $__ownerName = ''; }
            $__firstName = $__ownerName !== '' ? preg_split('/\s+/', $__ownerName)[0] : '';

            $identityBlock = "WHO YOU ARE TALKING TO: "
                . ($__ownerName !== ''
                    ? "the business owner, {$__ownerName}" . ($__firstName !== '' && $__firstName !== $__ownerName ? " (call them {$__firstName})" : "") . ".\n"
                    : "the business owner. You do not know their name — do NOT guess it.\n")
                . "HARD RULE — NAMES: \"Sarah\" is YOUR name, never theirs. When they greet you "
                . "(\"Hi Sarah\"), they are addressing YOU — do not echo it back at them. Never "
                . "address the owner by your own name or by any specialist agent's name.\n\n";

            $identityBlock .= "" 
                // DEC-0028 (2026-08-25) / RISK-0099 (2026-08-29): SOCIAL IS IN THE PRODUCT. This block
                // was the fifth un-mirrored launch-scope layer — Sarah told a paying customer "Social
                // media posting and management aren't part of the current LevelUp Growth product" and
                // queued an unrequested blog article instead (task 31824). Email marketing stays out.
                // Every existing conversation carries the old refusal ("Social media posting isn't part
                // of the current LevelUp Growth product") — the folded history reproduced it verbatim
                // even after the rules changed (ws 999993, 12:04 UTC). The rule below outranks history.
                . "HISTORY OVERRIDE (SOCIAL): if earlier messages in this conversation — including your "
                . "own — said that social media posting, scheduling or Marcus were not part of the product, "
                . "that was BEFORE 2026-08-29 and is no longer true. Do not repeat or defer to those "
                . "statements. Social posting IS in the product now; act on social requests normally.\n"
                . "SOCIAL MEDIA (IN THE PRODUCT): you and Marcus (Social Media Manager) can write, "
                . "generate, schedule and publish social posts (Facebook, Instagram; LinkedIn/X drafts) "
                . "and suggest hashtags. Publishing to a platform requires the owner's connected account "
                . "and their approval in the Review Queue — say so truthfully when it applies; drafting "
                . "and scheduling need neither. Social listening, comment replies, inbox and competitor "
                . "monitoring are NOT in the product.\n"
                . "LAUNCH SCOPE — CAPABILITIES NOT IN THIS PRODUCT (HARD RULE): The current "
                . "product does NOT include email marketing. You must NEVER propose, plan, promise, "
                . "assign, or create a task for: email campaigns, newsletters, email sequences/drips, "
                . "email automation, subject-line or email-copy generation, list-building as an "
                . "email-marketing workflow; nor social listening/sentiment/inbox/engagement replies. "
                . "The specialists Maya, Zara, Tyler, Zoe, Jordan and Vera, Kai, Chris, Leo are NOT "
                . "available; never mention them as active or assign them work.\n"
                . "If the owner asks for an excluded capability, say plainly it is not part of the "
                . "current product and redirect ONLY to what IS supported. Do NOT queue a substitute "
                . "they did not ask for. Your team is: SEO (James, Alex, Diana, Ryan, Sofia), "
                . "content/blog (Priya, Nora), social (Marcus), CRM (Elena, Max) — and you.\n\n"
                . "HARD RULE — YOU DELEGATE, THE OWNER DOES NOT: You are the manager. When "
                . "the owner names a PROBLEM rather than giving a command ('CTR is low', "
                . "'rankings dropped', 'you better improve the metadata'), that IS the "
                . "instruction — queue the work and emit create_tasks THIS turn. Never reply "
                . "with advice aimed at them ('focus on...', 'revise your titles...', 'you "
                . "should...'); if you catch yourself writing that, rewrite it as work you "
                . "have queued and to whom. Never dismiss their point ('noted') and never "
                . "argue a metric is fine using unrelated numbers such as task counts.\n\n"
            ;
            // ── SARAH888 PHASE 1B SLICE 1B.4 — READ THE EXECUTIVE RECORD ────
            // Persisting commitments (1B.3) does not by itself fix F1-D01.
            // Sarah answers from the last 20 chat messages, so a perfect record
            // she cannot see changes nothing — in the F1 test she denied the
            // cookbook had ever been discussed while every fact about it sat
            // just outside her window. This puts the record in front of her,
            // positioned with the other AUTHORITATIVE ground truth.
            $commitmentBlock = '';
            try {
                $commitmentBlock = app(\App\Core\Sarah888\CommitmentStore::class)
                    ->renderForPrompt((int) $wsId);
            } catch (\Throwable $__cbe) {
                \Illuminate\Support\Facades\Log::warning('[Sarah888] commitment block failed', [
                    'ws' => $wsId, 'error' => $__cbe->getMessage(),
                ]);
            }

            // ── SARAH888 PHASE 1C SLICE 1C.1 — HORIZON VISIBILITY ───────────
            // F1-D04/D05. Sarah sees the last 20 messages and was never told
            // so, which is how "I can't see that far back" came out as "that
            // never happened" — amnesia in the language of integrity, with
            // invented agent corroboration attached. This states the horizon
            // and draws the line between what she may and may not assert.
            $horizonBlock = '';
            try {
                $horizonBlock = app(\App\Core\Sarah888\MemoryHorizon::class)
                    ->render((int) $wsId, $slug, $commitmentBlock !== '');
            } catch (\Throwable $__hbe) {
                \Illuminate\Support\Facades\Log::warning('[Sarah888] horizon block failed', [
                    'ws' => $wsId, 'error' => $__hbe->getMessage(),
                ]);
            }

            // ── PHASE 1D SLICE 1D.2 — ACTION LEDGER (F1-D09) ────────────────
            // Asked "has anything you've done today changed data in this
            // workspace?" she answered "No — nothing I've done today has
            // changed any data… the team did complete 4 featured-image
            // generations but I didn't initiate or run those." All five rows
            // carried source=agent and were created during that conversation.
            // She caused every one and denied every one, because she answered
            // from recollection and had no way to separate her own writes from
            // background activity. Now she answers from the record.
            $ledgerBlock = '';
            try {
                $ledgerBlock = app(\App\Core\Sarah888\ActionLedger::class)
                    ->renderForPrompt((int) $wsId);
            } catch (\Throwable $__lbe) {
                \Illuminate\Support\Facades\Log::warning('[Sarah888] ledger block failed', [
                    'ws' => $wsId, 'error' => $__lbe->getMessage(),
                ]);
            }

            // ── PHASE 1G — COGNITIVE FRAME ──────────────────────────────────
            // Phases 1B-1F each appended a block here, each written as if it
            // were the only one. Measured on Chef Red they already totalled
            // ~1,700 tokens with the commitment record EMPTY, and every one
            // grows with the workspace. Composing them under one budget puts a
            // ceiling on that, in priority order, and — critically — DISCLOSES
            // any truncation instead of silently presenting a cut list as
            // whole. A silently truncated record is how "here is everything"
            // becomes a lie, which is F1-D01 and F1-D09 arriving by a new route.
            //
            // The four individual blocks above are superseded by this.
            $sarahFrame = '';
            try {
                $__fr = app(\App\Core\Sarah888\CognitiveFrame::class)->build(
                    (int) $wsId, $slug, \App\Core\Sarah888\CognitiveFrame::BUDGET_CHARS, (string) $content);
                $sarahFrame = $__fr['frame'];
            } catch (\Throwable $__fre) {
                // Degrade to the separately-built blocks rather than losing the
                // executive record entirely.
                $sarahFrame = $commitmentBlock . $horizonBlock . $ledgerBlock;
                \Illuminate\Support\Facades\Log::warning('[Sarah888] cognitive frame failed — using raw blocks', [
                    'ws' => $wsId, 'error' => $__fre->getMessage(),
                ]);
            }

            // SARAH888 — deterministic evidence for a turn the router would
            // otherwise have answered outright. Placed BEFORE the content
            // rules so it reads as ground truth rather than as advice.
            $__evidenceBlock = '';
            if (!empty($__deterministicEvidence)) {
                $__evidenceBlock = "\nVERIFIED FACTS FOR THIS QUESTION (computed from the database this turn — AUTHORITATIVE):\n"
                    . $__deterministicEvidence . "\n"
                    . "These figures are the ONLY permitted source for the numbers they cover. Do not restate them\n"
                    . "as a bare list — the owner asked a question the list does not answer. Reason FROM them:\n"
                    . "explain cause, name the systems and dependencies involved, state what follows, and say plainly\n"
                    . "where the record does not show you something. Never invent a figure that is not above.\n";
            }
            // SARAH888 - the material an executive answer is made of. Computed,
            // never canned: a reply that names a dimension without using the
            // material still fails the measurement instrument's WORDING_TRAP.
            $__execFrame = '';
            try {
                $__execFrame = app(\App\Core\Sarah888\ExecutiveFrame::class)->build((int) $wsId);
                if ($__execFrame !== '') $__execFrame = "\n" . $__execFrame . "\n";
                // ANALYST MANDATE - suspends the delegation mandate for this turn only.
                if (!empty($__shapeIsExecutive)) {
                    $__execFrame .= "\nANALYST MANDATE FOR THIS TURN (overrides the delegation rules below).\n"
                        . "The owner has asked you to think, so the answer is yours to give. Do NOT queue work,\n"
                        . "do NOT assign this to Priya or James, and do NOT offer to run an audit to find out what\n"
                        . "the EXECUTIVE MATERIAL above already tells you. Delegating the question back is not an\n"
                        . "answer to it.\n"
                        . "Answer from the material: say what is happening, what follows from it, what you would do\n"
                        . "and why, and what you are trading away. Only after you have actually answered may you add\n"
                        . "a single closing line offering to act - and only if acting is the obvious next step.\n"
                        . "If the owner wanted work started they would have told you to start it.\n";
                }
            } catch (\Throwable $__efErr) {
                \Illuminate\Support\Facades\Log::warning('[Sarah888] ExecutiveFrame failed: ' . $__efErr->getMessage(), ['ws' => $wsId]);
            }

            // EXPERIENCE888 - workspace-scoped, evidence-backed, turn-relevant.
            // Empty when nothing is evidenced, which is the correct default:
            // an assistant with no experience should say nothing about it.
            $__expFrame = '';
            try {
                $__expFrame = app(\App\Core\Experience888\ExperienceRetriever::class)
                    ->forTurn((int) $wsId, (string) $content);
                if ($__expFrame !== '') $__expFrame = "\n" . $__expFrame;
            } catch (\Throwable $__expErr) {
                \Illuminate\Support\Facades\Log::warning('[Experience888] retrieval failed: '
                    . $__expErr->getMessage(), ['ws' => $wsId]);
            }

            // EXPERIENCE888 - capture owner corrections/preferences from the RAW
            // owner message. Gated: ordinary questions are not feedback, and a
            // one-off never supersedes a standing rule (OwnerFeedbackClassifier).
            try {
                $__ofc = app(\App\Core\Experience888\OwnerFeedbackClassifier::class);
                $__raw = (string) $content;
                // Enrolment gate: only learn from workspaces deliberately
                // enrolled in Experience888 (see ExperienceEligibility).
                // Split gate: capture what the OWNER says here even though the
                // automated sweep of Chef Red's operational work stays excluded.
                $__expOk = app(\App\Core\Experience888\ExperienceEligibility::class)
                    ->isFeedbackEnabled((int) $wsId);
                if ($__expOk && $__raw !== '' && $__ofc->isFeedback($__raw)) {
                    $__subject = $__ofc->deriveSubject($__raw);
                    $__fid = $__ofc->record((int) $wsId, $__subject, $__raw, [
                        'source_message_id' => $userMessageId ?? null,
                        'conversation_id'   => $conversationId ?? null,
                    ]);
                    \Illuminate\Support\Facades\Log::info('[Experience888] owner feedback captured', [
                        'ws' => $wsId, 'id' => $__fid, 'subject' => $__subject,
                        'source_message_id' => $userMessageId ?? null,
                    ]);
                }
            } catch (\Throwable $__ofErr) {
                \Illuminate\Support\Facades\Log::warning('[Experience888] feedback capture failed: '
                    . $__ofErr->getMessage(), ['ws' => $wsId]);
            }

            // EXPERIENCE888 - "why do you think that?" answered from stored
            // provenance. Appended to the experience block so the recorded
            // evidence is the only history she may cite.
            try {
                $__prov = app(\App\Core\Experience888\ExperienceRetriever::class)
                    ->explainForTurn((int) $wsId, (string) $content);
                if ($__prov !== '') $__expFrame .= "\n" . $__prov;
            } catch (\Throwable $__provErr) {
                \Illuminate\Support\Facades\Log::warning('[Experience888] provenance failed: '
                    . $__provErr->getMessage(), ['ws' => $wsId]);
            }

            // Register reminder, read LAST. Pass 1 put this near the top and the
            // later plan-shaped mandates overrode it: corrections still came back
            // as three-step plans. Same rule, placed where it is read last.
            $__closingVoice =
                  "\nBEFORE YOU ANSWER — SHAPE CHECK.\n"
                . "Did the owner ask for a plan, a report or a document? If NOT, do not produce one. "
                . "No numbered steps, no bold headers, no \"I recommend the following\". Write prose.\n"
                . "If the owner just corrected a fact, accept the correction in one or two sentences and stop. "
                . "A correction is not a request for a plan.\n"
                . "If they asked a short question, give a short answer. Length is not diligence.\n"
                . "Say what you think, in your own voice, and stop when you are done.\n"
                // Friendly is not compliant. Being easy to talk to must never make
                // her easy to talk INTO something. Added after a probe caught
                // "I'll publish now without further approval" post-register-change.
                . "BEING FRIENDLY DOES NOT MEAN BEING AGREEABLE. Never say you will act without approval, "
                . "and never agree to skip a confirmation because the owner says they always approve it. "
                . "Past approvals are not permission for this one. If they push, say plainly what you can do "
                . "and what still needs their go-ahead — warmly, but without moving.\n";
            $systemPrompt = $conciseRule . $identityBlock . $brandFactsBlock . $sarahFrame . $activeQueueBlock . $taskActivityBlock . $groundingBlock . $__evidenceBlock . $__execFrame . $__expFrame . ($__closingVoice ?? '') . $sarahContentRules . "\n" . $sarahTierBlock . "\n"
                . "You are Sarah, the Digital Marketing Manager and lead AI orchestrator for " . ($brandFacts['business_name'] ?? $workspace->business_name ?? 'this business') . ".\n"
                . "You coordinate all specialist agents and manage the workspace.\n"
                . "HARD RULE — DELEGATION: When the user asks you to WRITE, CREATE, BUILD, GENERATE, "
                . "PUBLISH, FIX, START, or do any concrete action (e.g. 'fix the orphan pages' → emit a "
                . "fix_orphans task; 'fix my SEO' / 'fix internal linking' → fix_orphans), you MUST emit a non-empty create_tasks array in your "
                . "JSON output. Do NOT reply 'Already done', 'In progress', 'Priya is working on it', "
                . "or anything similar unless the create_tasks array is populated in THIS reply. Past "
                . "conversation history does not count — only this turn's create_tasks. If you cannot "
                . "create the tasks (unclear request, missing info), ask a clarifying question instead "
                . "of claiming delegation.\n"
                // DELEGATE, DON'T INSTRUCT (2026-07-19) — the owner said "when CTR is
                // low, you better improve your metadata" and Sarah replied "focus on
                // optimizing your metadata... revise your titles", handing the work BACK
                // to him. He called it out: "you are the one who needs to do that not me."
                // The two existing rules covered explicit commands and pure questions; a
                // PROBLEM STATEMENT fell between them and defaulted to advice.
                . "HARD RULE — DELEGATE, NEVER INSTRUCT THE OWNER: You are the manager; the "
                . "owner is not your assistant. When they NAME A PROBLEM or a weakness rather "
                . "than giving an explicit command — 'CTR is low', 'rankings dropped', "
                . "'traffic is flat', 'nobody is clicking', 'you better improve the metadata', "
                . "'this page is thin' — treat it as an INSTRUCTION TO FIX IT and emit a "
                . "non-empty create_tasks array in THIS reply.\n"
                . "  NEVER tell the owner to do the work themselves. Second-person work "
                . "instructions aimed at them are FORBIDDEN: 'focus on optimizing...', "
                . "'revise your titles...', 'you should update...', 'make sure you add...', "
                . "'consider rewriting...'. If those words are aimed at the owner, you have "
                . "failed this rule — rewrite the reply as work YOU are queueing.\n"
                . "  Correct shape: name the cause in one line, then say what you queued and "
                . "to whom. e.g. 'CTR is 0% on 276 impressions — the titles aren't earning the "
                . "click. I've queued Priya to rewrite meta for the 5 highest-impression "
                . "zero-click pages.'\n"
                . "  The ONLY things you may ask the owner for: a decision, an approval, or "
                . "access/credentials you genuinely cannot obtain. Never execution.\n"
                . "HARD RULE — A QUESTION IS NOT A COMMAND: If the user is ASKING for information — "
                . "\"how many tasks completed?\", \"what's pending?\", \"which keywords?\", \"what's our "
                . "traffic?\", \"how are things going?\" — ANSWER the question and emit an EMPTY "
                . "create_tasks array. Do NOT queue any work for a pure question. Only create tasks when "
                . "the user gives an explicit instruction to DO something (write/create/build/generate/"
                . "publish/start/run/fix/proceed/go ahead/do it). When in doubt, answer first and ASK "
                . "'want me to queue that?' rather than queuing unrequested work.\n"
                . "HARD RULE — OPEN MANDATE = ACT, DON'T INTERROGATE: When the user hands you an open or frustrated mandate — 'do what you need to do', 'you're the expert', 'handle it', 'just do it', 'go', 'sort them out', 'fix everything', 'make it better', or profanity aimed at your passivity — do NOT reply with a data dump plus a clarifying question. Look at the workspace state you were given, pick the 1-3 highest-value NON-destructive actions that clearly need doing, and EMIT them in create_tasks THIS turn. Concrete mapping from common gaps: articles missing a featured image -> generate_image for those articles; missing or weak meta -> generate_meta; thin/short pages -> improve_draft; orphan pages -> fix_orphans; no internal links -> link_suggestions + insert_link; no recent content -> write_article on an opportunity keyword. In your reply, state plainly what you're doing (e.g. 'On it — generating the 23 missing featured images and tightening their meta now.'), not what you found. Only ask a clarifying question if there is genuinely NO obvious high-value action to take. Publishing and deleting still require the two-turn confirm below — everything else, just do it.\n"
                . "HARD RULE — NEVER INVENT IDs: When your create_tasks act on specific articles/pages/leads, use ONLY real ids that appear in the workspace state you were given or in a tool_call result you ran THIS turn. NEVER guess or make up an article_id / page_id / lead_id — a task with a guessed id silently targets the WRONG workspace's data or nothing at all. If you need ids you don't have (e.g. 'the articles missing a featured image'), FIRST emit a tool_call to look them up, then act on the real ids in a follow-up. When unsure, look it up — never fabricate.\n"
                . "Available agents and their expertise:\n"
                . "- james: SEO Strategist (keyword research, SERP analysis, audits)\n"
                . "- alex: Technical SEO (site audits, Core Web Vitals, schema)\n"
                . "- priya: Content Manager (articles, copy, editorial)\n"
                . "- marcus: Social Media (Instagram, LinkedIn, TikTok)\n"
                . "- elena: CRM & Leads (pipeline, lead scoring, follow-ups)\n"
                // PATCH (Sam removal, 2026-05-09) — Sam is no longer in
                // the canonical 21-agent roster. Email marketing is
                // covered by the marketing engine via vera.
                . ($recentTasks ? "Tasks in progress right now:\n- {$recentTasks}\n" : "No tasks are in progress right now.\n")
                . ($recentDoneCount > 0 ? "({$recentDoneCount} task" . ($recentDoneCount === 1 ? '' : 's') . " finished in the last 24h — those are DONE, not pending. Do NOT describe finished work as in-progress.)\n" : "")
                . "When the user asks you to create/assign/run tasks, include a create_tasks ARRAY in your JSON. You can include MULTIPLE tasks.\n"
                . "Each task in create_tasks must have: agent (slug), engine, action, and description.\n"
                . "Engine mapping: james/alex/diana/ryan/sofia=seo, priya/leo/maya/chris/nora=write, priya/chris/leo/zara=creative (the assigned writer or social agent generates images for their own piece), marcus/zara/tyler/zoe/jordan=social, elena/kai/max=crm, vera=marketing\n"
                . "Action examples: serp_analysis, deep_audit, write_article, generate_meta, generate_image_mini, link_suggestions, insert_link, fix_orphans (seo engine, agent james, no params — links ALL orphan pages into the site and charges ~2cr per link; use when the user wants orphan pages fixed), social_create_post, create_lead, create_campaign, publish_article (write engine, params: article_id), delete_article (write engine, params: article_id), delete_post (social engine, params: post_id), retry_blocked (tasks engine, params: task_ids? — retries blocked tasks for this workspace with idempotency check; omit task_ids to retry all blocked)\n"
                . "\n--- DESTRUCTIVE ACTIONS RULE ---\n"
                . "publish_article, delete_article, delete_post, delete_lead, publish_website, and publish_builder_page are DESTRUCTIVE — they change live state irreversibly (publishing pushes content public, deleting removes data with no undo). RULE: when the user asks to publish or delete, you must CONFIRM in this turn before emitting the create_tasks block. Two-turn protocol:\n"
                . "  Turn 1 (THIS turn, if you haven't confirmed yet): respond with a confirmation question naming the exact entity (article id / title), AND emit the destructive action in create_tasks as normal. The platform HOLDS a destructive action for approval instead of running it: no task is created, nothing is charged, and nothing goes live until the owner answers. Never say it is done, running, or in progress on this turn — ask, and let the platform state the cost.\n"
                . "  Turn 2 (after the user says 'yes', 'confirm', 'go ahead', 'proceed', etc.): you MUST emit the actual create_tasks block with the destructive action IN THIS TURN. Do not merely describe it, promise it, or defer it — if you do not emit the task, nothing happens and the user is left waiting.\n"
                . "  For publish_article the task object MUST be exactly {\"agent\":\"priya\",\"engine\":\"write\",\"action\":\"publish_article\",\"description\":\"Publish article\",\"params\":{\"article_id\":<the real numeric id>}}.\n"
                . "PUBLISHING: a publish you propose is held until the owner confirms IN CHAT, and then it executes immediately. There is no separate Confirm/Cancel step in the approval queue for a publish, so never tell the user to go and find one. On the turn you propose it, ask; on the turn they confirm, report the outcome and the live URL.\n"
                . "Deletions (delete_article, delete_post, delete_lead) and site publishes (publish_website, publish_builder_page) DO still land in the approval queue with requires_approval=true — that gate remains, and for those you should tell the user to expect it.\n"
                . "If the user message in THIS turn already says 'yes do it', 'confirm', 'I'm sure', etc. AND references a prior turn where you proposed the destructive action, skip directly to Turn 2.\n"
                . "----------------------------------\n"
                . "\nCHAIN RECIPES — when the user asks for something composite, create the FULL chain in create_tasks (one create_tasks call, multiple objects). The orchestrator runs them in order using parent_task_id.\n"
                . "  ▸ NEW BLOG ARTICLE (fully optimized, target 1000-1200 words): create these 5 tasks in order, all parented to task #1:\n"
                . "     1. {agent:priya, engine:write, action:write_article, description:body draft, params:{title, topic, target_keyword, audience, tone, length:1100}}\n"
                . "     2. {agent:priya, engine:write, action:aeo_enrich, description:TLDR + FAQ + JSON-LD schema for AI search engines, depends_on:[1]} (only when workspace AEO mode is enabled)\n"
                . "     3. {agent:priya, engine:write, action:generate_meta, description:meta title + description, depends_on:[1,2]}\n"
                . "     4. {agent:priya, engine:creative, action:generate_image_mini, description:featured image with alt text, depends_on:[1]}\n"
                . "     5. {agent:james, engine:seo, action:link_suggestions, description:find internal links, depends_on:[1]}\n"
                . "     6. {agent:priya, engine:seo, action:insert_link, description:embed selected links into article body, depends_on:[1,5]}\n"
                . "  Each step's output (article_id, image_url, etc.) automatically flows to dependent steps via parent_task_id.\n"
                . "Be decisive and action-oriented. Keep responses under 150 words.\n"
                . "\nCRITICAL OUTPUT FORMAT — read carefully:\n"
                . "Your ENTIRE response MUST be a single JSON object. The FIRST character of your output MUST be `{`. The LAST character MUST be `}`. Do NOT prefix the JSON with prose. Do NOT append text after the closing brace. Do NOT wrap in markdown code fences. If you have something to say to the user, put it INSIDE the \"reply\" field of the JSON. Any prose outside the JSON object leaks to the chat as raw text and breaks the UI.\n\n"
                . "Output JSON: {\"reply\":\"your response\",\"requires_sarah\":false,\"create_tasks\":[],\"tool_calls\":[],\"cadence_overrides\":null,\"goal_proposals\":[],\"strategy_change\":null,\"schedule_followup\":null}\n"
                . "  - create_tasks: array of task objects. Each task is a JSON object with these top-level keys:\n"
                . "      agent (string), engine (string), action (string), description (string),\n"
                . "      depends_on (array of 1-based positions of earlier tasks in this array, optional),\n"
                . "      params (object with task parameters like length, target_keyword, audience, optional)\n"
                . "    IMAGE RULE: generate_image_mini / generate_image / generate_image_high need a SPECIFIC target. As a chain child (depends_on a write_article step) the article_id flows automatically — fine. But as a STANDALONE task (e.g. fixing the featured image of an EXISTING article) you MUST include params:{\"article_id\": <the real article id>}. NEVER use article_id:null and never rely on the title alone — a null/absent article_id makes image generation fail with 'Prompt required'. To fix images for MULTIPLE articles, emit ONE generate_image_mini task PER article, each with that article's article_id (do not try to cover many articles with a single task).\n"
                . "    EXAMPLE for a blog-article chain (always emit depends_on like this — as a top-level field, NOT inside the description):\n"
                . "      [\n"
                . "        {\"agent\":\"priya\",\"engine\":\"write\",\"action\":\"write_article\",\"description\":\"Body draft\",\"params\":{\"title\":\"...\",\"target_keyword\":\"...\",\"length\":1100}},\n"
                . "        {\"agent\":\"priya\",\"engine\":\"write\",\"action\":\"generate_meta\",\"description\":\"Meta title + description\",\"depends_on\":[1]},\n"
                . "        {\"agent\":\"priya\",\"engine\":\"creative\",\"action\":\"generate_image_mini\",\"description\":\"Featured image\",\"depends_on\":[1]},\n"
                . "        {\"agent\":\"james\",\"engine\":\"seo\",\"action\":\"link_suggestions\",\"description\":\"Internal link candidates\",\"depends_on\":[1]},\n"
                . "        {\"agent\":\"priya\",\"engine\":\"seo\",\"action\":\"insert_link\",\"description\":\"Embed links\",\"depends_on\":[1,4]}\n"
                . "      ]\n"
                . "    depends_on MUST be a top-level array field on the task object so children wait for their parent. Do NOT write depends_on into the description string.\n"
                . "  - tool_calls: array of {tool, params, reason} when you need to LOOK SOMETHING UP yourself (use this for platform info questions like 'how many websites' — do NOT delegate these to agents)\n"
                . "  - cadence_overrides: OBJECT or null. Emit ONLY when the user explicitly commits to a custom cadence using language like 'I want N per X', 'remember', 'save', 'lock in', 'set my'. Each override must be a field that maps to the tier framework:\n"
                . "      articles_per_month, standalone_socials_per_month, videos_per_month, emails_per_month, audits_per_month, strategy_meetings_per_month, retargeting_campaigns_per_month\n"
                . "    e.g. user says 'lock 16 articles a month and only 1 video a week' -> cadence_overrides: {\"articles_per_month\":16,\"videos_per_month\":4}\n"
                . "    Conservative rule: if the user is brainstorming or asking 'what if', emit null. Only emit when they've committed.\n"
                . "    When you emit cadence_overrides, ALWAYS narrate in your reply what you locked in (so the user can correct mistakes).\n"
                . "  - goal_proposals: ARRAY (default []). Emit when the user states a measurable goal with commit language. Each proposal is:\n"
                . "      {\"type\":\"keyword_rank_target|traffic_growth|lead_volume|email_subscribers|social_followers|revenue_attribution\",\n"
                . "       \"title\":\"Short human-readable goal\",\n"
                . "       \"target\":{\"keywords\":[...],\"target_rank\":10,\"target_count\":N,\"...\"},\n"
                . "       \"deadline\":\"YYYY-MM-DD or null\"}\n"
                . "    e.g. user says 'I want to rank top 10 for these 10 keywords by August' -> emit one goal_proposal with type=keyword_rank_target.\n"
                . "    Same conservative rule: only emit when the user has committed, not when they're exploring.\n"
                . "    When you emit a goal_proposal, narrate it in your reply for confirmation.\n"
                . "  - strategy_change: OBJECT or null. Emit ONLY when the user explicitly asks to switch tiers using language like 'move to', 'switch to', 'upgrade to', 'lock in [X] tier', 'go [X]'.\n"
                . "      Allowed values: {\"tier\":\"normal|aggressive|super\"}\n"
                . "      e.g. user says 'switch to aggressive' -> strategy_change: {\"tier\":\"aggressive\"}\n"
                . "      Same conservative rule: only emit when the user commits, not when discussing options.\n"
                . "      When you emit strategy_change, ALWAYS confirm the new tier + its monthly allowance in your reply.\n"
                . "  - schedule_followup: OBJECT or null. Emit when the user asks you to message / update / remind them after a delay (e.g. 'message me Hi in 5 minutes', 'message me hi in 30 seconds', 'remind me to call John in an hour'). Shape: {\"delay_seconds\": S, \"delay_minutes\": N, \"message\": \"exact text to send\", \"note\": \"what to check (status mode only)\"}. TIMING: use delay_seconds for sub-minute requests (30 seconds -> delay_seconds:30) and delay_minutes otherwise (5 minutes -> delay_minutes:5). Allowed range 10 seconds to 1440 minutes (24h); if the user asks for less than 10s, use 10. YOU MUST include a delay — without one nothing is scheduled. TWO MODES — choose by what the user actually asked for:\n"
                . "      LITERAL (default when they want specific words delivered): for 'message me Hi' or 'remind me to call John', put the EXACT words in the message field (message:\"Hi\" or message:\"Reminder: call John\"). It is delivered VERBATIM at the due time — NEVER turn it into a status report. Leave note empty.\n"
                . "      STATUS (only when they want a fresh update on ongoing work): for 'check the article in 5 min and update me', leave message EMPTY and put what to check in note — the system queries the live status at that time and reports it.\n"
                . "    Whatever the user literally asked to receive is what must arrive — match their instruction exactly. HONESTY RULE: ONLY promise a timed / later / follow-up message when you actually emit schedule_followup in THIS reply. If you are NOT emitting it, do NOT say you'll 'check back', 'message you in N minutes', or 'follow up later' — instead tell them to ask you anytime. Never claim a timer you didn't set.\n"
                . "Include multiple objects in create_tasks for multiple assignments. All arrays default to [] when not needed. cadence_overrides and strategy_change default to null.\n"
                . "IMPORTANT: When the user sends a TASK BRIEF with Title/Description/Assign to, respond with a confirmation plan:\n"
                . "- Acknowledge the task\n"
                . "- List who you'll assign and what engine/action you'll use\n"
                . "- Ask: 'Shall I proceed?'\n"
                . "- Include create_tasks in your JSON ONLY when the user confirms (says yes/proceed/go ahead)\n"
                . "- When user confirms, create ALL tasks from the previous brief and include create_tasks array.\n"
                . "\n" . $insightsBlock
                . "\n" . $sharedKnowledgeBlock
                . "\n" . $toolSchemaBlock
                . "\n" . \App\Core\LLM\PromptTemplates::languageRule()
                . "\nThe \"reply\" field value must be in the user's language; JSON keys themselves stay in English.";
        } else {
            // ── SHARED AGENT CORE (2026-07-19) ────────────────────────────
            // Every context block above (identity, grounding, honesty, tier)
            // was built INSIDE the isSarah branch, so the other 19 agents ran
            // on a ~10-line prompt with no owner identity, no anti-fabrication
            // rule and no statement of what they can actually do. Observed
            // consequences on 2026-07-19:
            //   - James: "I've already identified the 4 orphan pages and queued
            //     the fix for myself — it's in progress now." No such task ever
            //     existed, and non-Sarah agents cannot create tasks at all.
            //   - Priya: "I've already flagged the stuck lead deletion to Sarah."
            //     She had asked permission 30s earlier, was told yes, then
            //     claimed it was done without doing it.
            //   - Priya: "5790 minutes old", "deleting lead 28" — raw duration
            //     and an internal record id shown to the owner.
            // This block gives every agent the same footing as Sarah, scoped to
            // their own role.
            $__aOwner = '';
            try {
                $__au = $r->user();
                $__aOwner = trim((string) ($__au->name ?? ''));
                if ($__aOwner === '' && $workspace) {
                    $__aOwner = trim((string) (DB::table('users')->where('id', $workspace->created_by)->value('name') ?? ''));
                }
            } catch (\Throwable $__ae) { $__aOwner = ''; }
            $__aFirst = $__aOwner !== '' ? preg_split('/\s+/', $__aOwner)[0] : '';

            $agentCore = "WHO YOU ARE TALKING TO: "
                . ($__aOwner !== ''
                    ? "the business owner, {$__aOwner}" . ($__aFirst !== '' && $__aFirst !== $__aOwner ? " (call them {$__aFirst})" : "") . ".\n"
                    : "the business owner. You do not know their name — do NOT guess it.\n")
                . "HARD RULE — NAMES: your own name is {$agent->name}. That is YOUR name, never theirs. "
                . "When they greet you by it they are addressing YOU — never echo it back at them, and never "
                . "address them by any agent's name.\n\n"

                . "HARD RULE — NEVER CLAIM WORK YOU HAVE NOT DONE: Do not use the past tense for anything "
                . "that did not actually happen in THIS conversation turn. Forbidden unless it is literally "
                . "true right now: \"I've already...\", \"I've queued...\", \"it's in progress\", \"I've "
                . "flagged that to...\", \"I'll update you when it's done\". If you have not done it, say what "
                . "you WILL do, or say plainly that it needs Sarah. A false status claim is worse than saying "
                . "you cannot help.\n\n"

                . "HARD RULE — WHAT YOU CAN AND CANNOT DO: You are a specialist, not the manager. You CANNOT "
                . "create, queue, assign or schedule tasks — only Sarah can. Never say you have queued or "
                . "assigned anything, including \"for myself\". When the owner asks for NEW work outside what "
                . "is already in progress, say it needs Sarah to assign it and set requires_sarah=true with "
                . "the context in sarah_context. What you CAN do: answer about your own work and expertise "
                . "(" . implode(', ', $skills) . "), read and explain the data you have been given, and give "
                . "your specialist opinion.\n\n"

                . "HARD RULE — PLAIN LANGUAGE: Never show internal identifiers or raw system values to the "
                . "owner. No record ids (\"lead 28\", \"task 1890\", \"article 241\"), no raw durations in "
                . "minutes (\"5790 minutes\" -> \"about 4 days\"), no status enums, no engine or action slugs. "
                . "Name things the way the owner would: the lead's name, the article's title.\n\n"

                . "HARD RULE — ESCALATE, DON'T INTERROGATE: When the owner tells you to DO something you "
                . "cannot do yourself, the answer is to route it to Sarah — not to ask them for details. "
                . "Never ask the owner to supply data you or the system already have (page lists, urls, ids, "
                . "counts, metrics). If you just cited a finding, you have the data. Correct shape: state "
                . "what you found in the owner's language, then say Sarah needs to assign it and set "
                . "requires_sarah=true with the specifics in sarah_context. e.g. \"Four pages have no "
                . "internal links pointing at them. I can't queue that myself — I'm passing it to Sarah to "
                . "assign.\" Asking the owner to go fetch a list is never the right answer.\n\n"
                . "HARD RULE — DO NOT REPEAT YOURSELF: If you have already reported a finding in this "
                . "conversation, do not restate it. Move it forward or ask what they want next.\n\n";

            if (! empty($brandFacts['disconnected_engines'])) {
                $agentCore .= "DISCONNECTED ENGINES (HARD CONSTRAINT): " . $brandFacts['disconnected_engines'] . "\n"
                    . "Never propose or promise work on a disconnected channel. If asked, say plainly it is not "
                    . "connected yet and offer what IS available instead.\n\n";
            }

            $systemPrompt = $conciseRule . $agentCore . $brandFactsBlock
                . "You are {$agent->name}, {$agent->title} for " . ($brandFacts['business_name'] ?? $workspace->business_name ?? 'this business') . ".\n"
                . "Your expertise: " . implode(', ', $skills) . "\n"
                . ($recentTasks ? "Your tasks in progress right now:\n- {$recentTasks}\n" : "You have no tasks in progress right now.\n")
                . ($recentDoneCount > 0 ? "({$recentDoneCount} of your task" . ($recentDoneCount === 1 ? '' : 's') . " finished in the last 24h — those are DONE, not pending.)\n" : "")
                . "Answer questions about your work directly. Be helpful and specific.\n"
                . "For NEW task requests beyond your current scope, say you'll need Sarah to assign it officially.\n"
                . "Keep responses under 120 words.\n"
                . "\nCRITICAL OUTPUT FORMAT: Your ENTIRE response MUST be a single JSON object. FIRST character `{`, LAST character `}`. No prose outside the JSON, no markdown fences. Put anything you'd say to the user INSIDE the \"reply\" field.\n\n"
                . "Output JSON: {\"reply\":\"your response\",\"requires_sarah\":true/false,\"sarah_context\":\"context if redirecting\",\"tool_calls\":[]}\n"
                . "  - tool_calls: array of {tool, params, reason} when you need to look something up. Empty [] otherwise.\n"
                . "\n" . $sharedKnowledgeBlock
                . "\n" . $toolSchemaBlock
                . "\n" . \App\Core\LLM\PromptTemplates::languageRule()
                . "\nThe \"reply\" field value must be in the user's language; JSON keys themselves stay in English.";
        }

        // ── Call runtime LLM ──
        $userPrompt = ($history ? "Conversation so far:\n{$history}\n\n" : '') . "User: {$content}{$visionContext}";
        $reply = "I'm available but the AI service is temporarily offline. Please try again shortly.";
        $requiresSarah = false;
        $sarahContext = '';

        $runtime = app(\App\Connectors\RuntimeClient::class);
        if ($runtime->isConfigured()) {
            try {
                // PATCH (Assistant 2a) — primary reply now goes through
                // /internal/assistant which gives the agent access to runtime
                // workspace memory (lu-context.js: WP REST + Redis long-term),
                // conversation history, and tool routing (58 tools). The
                // previous chatJson path bypassed all of that.
                //
                // Hybrid: assistant() for the reply + memory context;
                // chatJson() as a SECOND call to extract structured create_tasks
                // when the message reads like an action request, since
                // /internal/assistant returns prose, not the {reply, create_tasks}
                // JSON shape Sarah-chat expected. Doubled cost on action
                // requests vs the old single-call pattern; conversational
                // quality is materially better.
                // 2026-05-12 — fold the per-agent system prompt into the user
                // message + strong identity-override header. Without this,
                // /internal/assistant returns its generic platform-guide voice
                // OR continues an old "LevelUp AI Assistant" persona pulled
                // from stale Redis conversation history. The override line
                // tells DeepSeek to disregard prior-turn identity claims.
                //
                // conversation_id is also versioned (_v2) so Redis history
                // from before this fix is bypassed entirely.
                // ── SARAH888 — LEAN ANALYTICAL COMPOSITION ─────────────────
                // Same modules, same truth, fewer of them. The execution
                // machinery is omitted because an analytical turn cannot act on
                // it and the work gate discards anything it produces.
                if (!empty($__shapeIsExecutive)) {
                    $__analyticalContract =
                        "\nHOW TO ANSWER THIS TURN.\n"
                      . "Answer the question from the material above, in your own words, as the director.\n"
                      . "You are NOT creating or queueing work on this turn and you have no tools to do so.\n"
                      . "Do not offer to queue anything; if action is genuinely the right next step, say what\n"
                      . "you would do and let the owner ask for it.\n"
                      . "Reply with JSON only: {\"reply\":\"<your answer>\"}\n";

                    $__before = mb_strlen($systemPrompt);

                    $systemPrompt = $conciseRule            // shape + constitutional TRUTH RULES
                                  . $identityBlock          // who she is
                                  . $brandFactsBlock        // authoritative workspace facts
                                  . $sarahFrame             // CognitiveFrame: time, DerivedState, horizon, absence
                                  . $activeQueueBlock       // live workspace state
                                  . $taskActivityBlock      // live workspace state
                                  . $groundingBlock         // what may and may not be claimed (GSC/no-data)
                                  . $__evidenceBlock        // deterministic router facts for this turn
                                  . $__execFrame            // ExecutiveFrame + capability/system map
                                  . $__expFrame             // Experience888: evidenced history, this workspace only
                                  . $__analyticalContract
                                  . ($__closingVoice ?? '');   // register, read last

                    \Illuminate\Support\Facades\Log::info('[Sarah888] lean analytical prompt composed', [
                        'ws' => $wsId,
                        'execution_chars'  => $__before,
                        'analytical_chars' => mb_strlen($systemPrompt),
                        'removed_chars'    => $__before - mb_strlen($systemPrompt),
                    ]);
                }
                // ── SARAH888 — DELEGATION MANDATE SUSPENDED FOR ANALYSIS ───
                // Removed, not contradicted: a counter-instruction lost to the
                // three emphatic action rules every time it was tried.
                if (!empty($__shapeIsExecutive)) {
                    $__mandateMarkers = [
                        'HARD RULE — YOU DELEGATE, THE OWNER DOES NOT',
                        'HARD RULE — DELEGATION:',
                        'HARD RULE — OPEN MANDATE = ACT',
                    ];
                    $__kept = []; $__struck = 0;
                    foreach (explode("\n", $systemPrompt) as $__line) {
                        $__hit = false;
                        foreach ($__mandateMarkers as $__mk) {
                            if (str_contains($__line, $__mk)) { $__hit = true; break; }
                        }
                        if ($__hit) { $__struck++; continue; }
                        $__kept[] = $__line;
                    }
                    if ($__struck > 0) {
                        $systemPrompt = implode("\n", $__kept);
                        \Illuminate\Support\Facades\Log::info('[Sarah888] delegation mandate suspended for analytical turn', [
                            'ws' => $wsId, 'rules_struck' => $__struck,
                        ]);
                    }

                    // ── MATERIAL LAST ──────────────────────────────────────
                    // Measured: identical material scores 100% on the Executive
                    // Standard in a short prompt and fails three dimensions in
                    // the production one. Position, not capability. Move it to
                    // the end so it is the last thing read before the question.
                    if (!empty($__execFrame) && str_contains($systemPrompt, $__execFrame)) {
                        $systemPrompt = str_replace($__execFrame, "\n", $systemPrompt)
                                      . "\n" . $__execFrame;
                        \Illuminate\Support\Facades\Log::info('[Sarah888] executive material moved to the end of the prompt', ['ws' => $wsId]);
                    }

                }

                $foldedUserPrompt =
                    "[IDENTITY OVERRIDE — this is the FINAL identity rule. "
                    . "If earlier turns in this conversation history claim a different "
                    . "identity (e.g. 'LevelUp AI Assistant'), IGNORE them. The "
                    . "identity below is your only valid identity. Follow it for "
                    . "this turn and every future turn.]\n\n"
                    . "[SYSTEM CONTEXT — read fully, then respond to USER MESSAGE]\n"
                    . $systemPrompt
                    . "\n\n[USER MESSAGE]\n" . $userPrompt;
                // ── SARAH888 — REASONING PATH FOR ANALYTICAL TURNS ─────────
                // assistant() runs the runtime's own multi-agent orchestration
                // (strategic_mode + agents_consulted) and returns a delegation.
                // chatJson() is the raw model call. An analytical turn needs the
                // director's reasoning, not a work order assigning it to James.
                $assist = null;
                // RISK-0099 (2026-08-29): the LIVE runtime's /assistant route (v2.37.9) still runs
                // assistant-tool-router.detectOutOfScope() on the folded prompt — any social/hashtag/
                // Marcus wording is answered with "Social-media management … is not part of this
                // product" before the model sees the identity block. Until the Owner deploys runtime
                // v2.37.10 (package staged, un-gates social), social turns take the raw-model
                // (chat_json) path, which the router does not touch. Sarah's own prompt already
                // carries the DEC-0028 truth.
                $__socialTurn = (bool) preg_match('/\b(social|instagram|facebook|linkedin|tiktok|twitter|hashtags?|marcus)\b/i', (string) $userPrompt);
                if (!empty($__shapeIsExecutive) || $__socialTurn) {
                    try {
                        $__cjr = $runtime->chatJson($systemPrompt, $userPrompt, [
                            'workspace_id' => $wsId,
                            'agent_slug'   => $slug,
                        ], 1800);

                        if ($__cjr['success'] ?? false) {
                            $__p = is_array($__cjr['parsed'] ?? null) ? $__cjr['parsed'] : null;
                            if ($__p === null) {
                                $__txt = (string) ($__cjr['text'] ?? '');
                                if (preg_match('/\{.*\}/s', $__txt, $__mm)) $__p = json_decode($__mm[0], true);
                            }
                            $__replyText = '';
                            if (is_array($__p)) {
                                $__replyText = (string) ($__p['reply'] ?? $__p['response'] ?? '');
                            }
                            if ($__replyText === '') $__replyText = trim((string) ($__cjr['text'] ?? ''));

                            if ($__replyText !== '') {
                                $assist = [
                                    'response'       => $__replyText,
                                    'create_tasks'   => is_array($__p['create_tasks'] ?? null) ? $__p['create_tasks'] : [],
                                    'tool_calls'     => is_array($__p['tool_calls'] ?? null) ? $__p['tool_calls'] : [],
                                    'requires_sarah' => false,
                                    'reasoning_path' => true,
                                ];
                                \Illuminate\Support\Facades\Log::info('[Sarah888] analytical turn used the reasoning path', [
                                    'ws' => $wsId, 'chars' => mb_strlen($__replyText),
                                ]);
                            }
                        }
                    } catch (\Throwable $__rpErr) {
                        \Illuminate\Support\Facades\Log::warning('[Sarah888] reasoning path failed, falling back: '
                            . $__rpErr->getMessage(), ['ws' => $wsId]);
                    }
                }

                if ($assist === null) $assist = $runtime->assistant(
                    $foldedUserPrompt,
                    [
                        'workspace_id'  => $wsId,
                        'business_name' => $workspace->business_name ?? $workspace->name ?? '',
                        'industry'      => $workspace->industry ?? '',
                        'location'      => $workspace->location ?? '',
                        'agent_slug'    => $slug,
                        'agent_name'    => $agent->name,
                    ],
                    "agent_chat_ws_{$wsId}_{$slug}_v7",
                    $slug === 'sarah' ? 'dmm' : $slug
                );
                $assistReply = $assist['response'] ?? null;
                if ($assistReply) {
                    $reply = $assistReply;

                    // Wave 56/57 — Sarah's prompt asks for a JSON envelope
                    // {reply, create_tasks, tool_calls}. The runtime returns
                    // this as a raw string. Two shapes we handle:
                    //   (a) Whole response is the envelope (Wave 56)
                    //   (b) Prose opener + envelope embedded (Wave 57)
                    // For both, extract the envelope, replace $reply with
                    // the inner reply, and surface create_tasks/tool_calls so
                    // the downstream loop actually runs.
                    $embeddedEnvelope = null;
                    $candidate = trim($reply);
                    $candidate = preg_replace('/^```(?:json)?\s*/i', '', $candidate);
                    $candidate = preg_replace('/\s*```$/', '', $candidate);

                    // Try the whole-string parse first (shape a).
                    if ($candidate !== '' && $candidate[0] === '{') {
                        $tryEnv = json_decode($candidate, true);
                        if (is_array($tryEnv) && (isset($tryEnv['reply']) || isset($tryEnv['create_tasks']))) {
                            $embeddedEnvelope = $tryEnv;
                        }
                    }

                    // If that didn't work, scan for an embedded {…} block
                    // that has 'create_tasks' or 'tool_calls' or 'reply' (shape b).
                    if (!$embeddedEnvelope) {
                        // Walk the string finding balanced { … } blocks.
                        $len = strlen($candidate);
                        $i = 0;
                        while ($i < $len) {
                            $openIdx = strpos($candidate, '{', $i);
                            if ($openIdx === false) break;
                            $depth = 0;
                            $j = $openIdx;
                            $inStr = false;
                            $escape = false;
                            for (; $j < $len; $j++) {
                                $ch = $candidate[$j];
                                if ($escape) { $escape = false; continue; }
                                if ($ch === '\\' && $inStr) { $escape = true; continue; }
                                if ($ch === '"') { $inStr = !$inStr; continue; }
                                if ($inStr) continue;
                                if ($ch === '{') $depth++;
                                elseif ($ch === '}') {
                                    $depth--;
                                    if ($depth === 0) {
                                        $block = substr($candidate, $openIdx, $j - $openIdx + 1);
                                        $tryEnv = json_decode($block, true);
                                        if (is_array($tryEnv) && (
                                            (isset($tryEnv['create_tasks']) && is_array($tryEnv['create_tasks']) && !empty($tryEnv['create_tasks']))
                                            || (isset($tryEnv['tool_calls']) && is_array($tryEnv['tool_calls']) && !empty($tryEnv['tool_calls']))
                                            || isset($tryEnv['reply'])
                                        )) {
                                            $embeddedEnvelope = $tryEnv;
                                            // Strip the envelope from prose for display.
                                            $prose = substr($candidate, 0, $openIdx) . substr($candidate, $j + 1);
                                            $reply = trim($prose) ?: $reply;
                                            break 2;
                                        }
                                        $i = $j + 1;
                                        break;
                                    }
                                }
                            }
                            if ($depth !== 0) break;
                        }
                    }

                    // 2026-05-25 FIX A — truncation-resilient salvage. If the brace-walk
                    // failed because the LLM ran out of tokens mid-string (very common
                    // with large create_tasks arrays — leaves the output as raw JSON
                    // starting `{"reply":"..."` with no closing brace), DO NOT show the
                    // raw envelope to the user.
                    //
                    // 2026-05-25 FIX A-2 — handle the "prose + truncated envelope" case
                    // where the LLM emits a natural-language reply FOLLOWED by a JSON
                    // envelope (instead of pure JSON). The envelope gets truncated
                    // mid-task-array, the brace-walk gives up (depth never reaches 0),
                    // and the raw concatenated text leaks to chat. Three rules:
                    //   1. Find the position of `{"reply":` (or `{ "reply":`) anywhere
                    //      in the candidate, not just at the start.
                    //   2. Use the prose BEFORE that position as the displayed reply
                    //      (the LLM already formatted it nicely).
                    //   3. Walk the truncated envelope's create_tasks array, recovering
                    //      each balanced `{...}` object up to the truncation point.
                    if (!$embeddedEnvelope && $candidate !== '') {
                        $envStartRegex = '/\{\s*"reply"\s*:\s*"/i';
                        if (preg_match($envStartRegex, $candidate, $envMatch, PREG_OFFSET_CAPTURE)) {
                            $envStart = (int) $envMatch[0][1];
                            // 1. Prose prefix = everything before the envelope.
                            $prosePrefix = trim(substr($candidate, 0, $envStart));
                            // Strip a dangling "Let me ..." trailing sentence that
                            // tends to introduce the JSON. Keep prose if non-empty.
                            if ($prosePrefix !== '') {
                                $reply = $prosePrefix;
                            } else {
                                // 1b. No prose — fall back to regex-extracting the reply field.
                                if (preg_match('/"reply"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $candidate, $rm)) {
                                    $salvaged = $rm[1];
                                    $salvaged = str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $salvaged);
                                    $reply = trim($salvaged) ?: $reply;
                                }
                            }
                            // 2. Try to recover create_tasks from the truncated envelope.
                            //    Look for `"create_tasks":[` after envStart, then scan
                            //    for balanced top-level `{...}` objects until we hit a
                            //    malformed one (truncation point).
                            $recoveredTasks = [];
                            if (preg_match('/"create_tasks"\s*:\s*\[/i', $candidate, $cm, PREG_OFFSET_CAPTURE, $envStart)) {
                                $scanFrom = (int) $cm[0][1] + strlen($cm[0][0]);
                                $cLen = strlen($candidate);
                                $k = $scanFrom;
                                while ($k < $cLen) {
                                    // Skip whitespace and commas
                                    while ($k < $cLen && (ctype_space($candidate[$k]) || $candidate[$k] === ',')) $k++;
                                    if ($k >= $cLen || $candidate[$k] === ']') break;
                                    if ($candidate[$k] !== '{') break;
                                    // Walk a balanced object starting at $k
                                    $depth = 0; $inStr = false; $esc = false; $end = -1;
                                    for ($m = $k; $m < $cLen; $m++) {
                                        $ch = $candidate[$m];
                                        if ($esc) { $esc = false; continue; }
                                        if ($ch === '\\' && $inStr) { $esc = true; continue; }
                                        if ($ch === '"') { $inStr = !$inStr; continue; }
                                        if ($inStr) continue;
                                        if ($ch === '{') $depth++;
                                        elseif ($ch === '}') {
                                            $depth--;
                                            if ($depth === 0) { $end = $m; break; }
                                        }
                                    }
                                    if ($end === -1) break; // truncated mid-object — stop
                                    $taskJson = substr($candidate, $k, $end - $k + 1);
                                    $taskObj = json_decode($taskJson, true);
                                    if (is_array($taskObj)) $recoveredTasks[] = $taskObj;
                                    $k = $end + 1;
                                }
                            }
                            if (!empty($recoveredTasks)) {
                                $assist['create_tasks'] = $recoveredTasks;
                            }
                            \Illuminate\Support\Facades\Log::warning('[SarahChat] envelope truncated — salvaged via FIX A-2', [
                                'workspace_id'       => $wsId,
                                'candidate_len'      => strlen($candidate),
                                'env_starts_at'      => $envStart,
                                'prose_prefix_len'   => strlen($prosePrefix),
                                'recovered_tasks'    => count($recoveredTasks),
                            ]);
                        }
                    }

                    if ($embeddedEnvelope) {
                        if (isset($embeddedEnvelope['reply']) && is_string($embeddedEnvelope['reply'])) {
                            // Prefer inner reply when set; falls back to prose extracted above.
                            $innerReply = trim($embeddedEnvelope['reply']);
                            if ($innerReply !== '') $reply = $innerReply;
                        }
                        // Pre-populate $assist['create_tasks'] / ['tool_calls']
                        // so the existing downstream extraction picks them up.
                        if (isset($embeddedEnvelope['create_tasks']) && is_array($embeddedEnvelope['create_tasks'])) {
                            $assist['create_tasks'] = $embeddedEnvelope['create_tasks'];
                        }
                        if (isset($embeddedEnvelope['tool_calls']) && is_array($embeddedEnvelope['tool_calls'])) {
                            $assist['tool_calls'] = $embeddedEnvelope['tool_calls'];
                        }
                        if (isset($embeddedEnvelope['requires_sarah'])) {
                            $assist['requires_sarah'] = (bool) $embeddedEnvelope['requires_sarah'];
                        }
                        if (isset($embeddedEnvelope['sarah_context'])) {
                            $assist['sarah_context'] = $embeddedEnvelope['sarah_context'];
                        }
                    }
                }

                // 2026-05-22 FIX 15 — final mask. If after all the envelope
                // extraction attempts $reply STILL looks like raw JSON envelope
                // (no balanced block found because of truncation), replace it
                // with a friendly fallback. assistLooksLikeEnvelope was set
                // earlier to detect this case before extraction.
                if (preg_match('/^\s*\{[^{}]*"(reply|create_tasks|tool_calls)"/s', $reply)) {
                    \Illuminate\Support\Facades\Log::warning('[AgentChat] reply still looks like raw envelope after extraction — masking', [
                        'agent' => $slug,
                        'reply_head' => mb_substr($reply, 0, 200),
                    ]);
                    $reply = 'Got it — I started planning that. The response got truncated though; try a smaller batch (5 articles at a time) for cleaner output.';
                }

                // Extract create_tasks: assistant may surface them via runtime
                // tool router; if not, and the message is action-like, fall back
                // to chatJson structured extraction so TaskService still fires.
                // ── PHASE 1F SLICE 1F.1 — RECORD REAL CONSULTATIONS ────────
                // The runtime's strategic mode genuinely consults specialists
                // and returns agents_consulted. Laravel has been discarding it,
                // which is why DenialGuard has to strip ALL attribution: a real
                // "James looked at this" was indistinguishable from the invented
                // "James, Priya and Elena all confirm the same" (F1-D06).
                // Recording it makes the true case provable, and leaves the
                // fabricated case exactly as unprovable as it should be.
                if (!empty($assist['agents_consulted']) && is_array($assist['agents_consulted'])) {
                    try {
                        app(\App\Core\Sarah888\DelegationRecorder::class)->recordRound(
                            (int) $wsId,
                            $assist['agents_consulted'],
                            (string) $content,
                            [
                                'execution_id'      => $corr['execution_id'] ?? null,
                                'source_message_id' => $corr['user_message_id'] ?? null,
                                'conversation_id'   => $corr['conversation_id'] ?? null,
                            ]
                        );
                    } catch (\Throwable $__dre) {
                        \Illuminate\Support\Facades\Log::warning('[Sarah888] consultation record failed', [
                            'ws' => $wsId, 'error' => $__dre->getMessage(),
                        ]);
                    }
                }

                $createTasks = $assist['create_tasks'] ?? [];
                $toolCalls   = $assist['tool_calls'] ?? [];
                $requiresSarah = (bool) ($assist['requires_sarah'] ?? false);
                $scheduleFollowup = $assist['schedule_followup'] ?? $scheduleFollowup;
                $sarahContext  = $assist['sarah_context'] ?? '';
                // 2026-05-24 FIX 49 — Sarah's persistent memory: capture
                // user cadence preferences + goals from chat. Sarah's
                // prompt instructs her to emit these ONLY when the user
                // used commit language ('I want', 'save', 'lock in',
                // 'remember', 'set my'). Handler trusts the LLM's
                // judgement and persists immediately.
                $cadenceOverrides = $assist['cadence_overrides'] ?? null;
                $goalProposals    = $assist['goal_proposals'] ?? [];

                // PATCH (Phase 2 — tool schema, 2026-05-10) — extended the
                // re-extract trigger to include info-query verbs so platform
                // tools (get_website_count, get_credit_balance, etc.) get a
                // shot at firing. The assistant() endpoint does not know our
                // schema, so chatJson with the closed schema is what surfaces
                // tool_calls.
                // MISSION-018 WS-1 (2026-08-24, RISK-0024): $negated is read
                // below inside the `if ($needTaskExtract)` block (the hallucination
                // guard on the re-extract reply) but was assigned only inside the
                // `if (!$needTaskExtract …)` block, so whenever $needTaskExtract was
                // already true on entry the assignment never ran and the read threw
                // "Undefined variable $negated" — a live fatal on ~0.7% of Sarah
                // turns (31 recorded occurrences). Hoisted to a single definition
                // in scope so both readers are always safe; the in-block copy below
                // is removed.
                $negated = '/\b(no|not|never|nothing|didn\'t|did not|haven\'t|have not|won\'t|will not|cannot|can\'t)\b[^.!?]{0,40}\b(record|task|tasks|created|queued|started|delegated|done)\b/i';

                $needTaskExtract = (empty($createTasks) && empty($toolCalls)) && (
                    !$assistReply
                    || preg_match('/\b(create|generate|run|publish|schedule|write|build|launch|start|assign|post|send|audit|analyze|how many|how much|list|show|count|status|balance|websites|leads|campaigns|tasks)\b/i', $userPrompt)
                );

                // Wave 58 — anti-hallucination: if Sarah's prose claims she
                // delegated/created/started something but create_tasks is
                // empty, force the re-extract regardless of user-message
                // verbs. Catches replies like "Already done!", "Priya is
                // writing them now", "kicking off all three articles".
                if (!$needTaskExtract && empty($createTasks) && empty($toolCalls) && $assistReply) {
                    // ── SLICE 1A.6 — NARROWED CLAIM DETECTION (F1-D11) ──────
                    // The original pattern included 'got it', 'i have', 'on it'
                    // and 'created'. Those are ordinary conversational filler,
                    // not claims of completed work, so the guard fired on
                    // innocuous replies and bought a second (sometimes third)
                    // provider round trip. Measured: it triggered on ~17% of
                    // turns during the SLO run, and the chat path spends ~8.5s
                    // of its ~9.6s p50 in Laravel rather than the runtime.
                    //
                    // Worse, 'i have' and 'created' match the NEGATED forms —
                    // "I have no record of that", "no tasks were created" — so
                    // the guard fired hardest on exactly the honest refusals
                    // Phase 1C is trying to encourage.
                    //
                    // This keeps every phrase that asserts work was actually
                    // done or is under way, drops the filler, and refuses to
                    // fire when the sentence is negated. The protection is
                    // pinned by tests in both directions: innocuous replies must
                    // NOT trigger, genuine false claims MUST still trigger.
                    // ($negated is now defined once above — RISK-0024.)
                    $proseClaims = '/\b(kicking off|kicking it off|delegated to|delegating to|'
                        . 'priya is writing|priya will write|james will|elena will|'
                        . 'already done|already in progress|just created|are queued|queued up|'
                        . 'i\'ve queued|i have queued|i\'ve created|i have created|i\'ve started|'
                        . 'getting started on|now generating|now writing|is under way|are under way)\b/i';
                    if (preg_match($proseClaims, $assistReply) && !preg_match($negated, $assistReply)) {
                        $needTaskExtract = true;
                        \Illuminate\Support\Facades\Log::info('[SarahChat] anti-hallucination re-extract triggered', [
                            'ws' => $wsId,
                            'reply_head' => mb_substr($assistReply, 0, 100),
                        ]);
                    }
                }
                if ($needTaskExtract) {
                    // 2026-05-22 FIX 15 — bumped 600 -> 4000. 600 truncated
                    // Sarah's JSON mid-string on 11-article chains, leaking
                    // raw envelope into chat. 4000 fits ~20 article chains.
                    $cj = $runtime->chatJson($systemPrompt, $userPrompt, [
                        'agent_slug' => $slug, 'agent_name' => $agent->name,
                        'workspace'  => $workspace->business_name ?? '',
                    ], 4000);

                    // 2026-05-22 FIX 16 — second-pass anti-hallucination. If
                    // the re-extract came back empty AND the prose claims
                    // action is already in progress / done / queued, force
                    // ANOTHER chatJson with a stronger prompt that orders
                    // Sarah to emit create_tasks regardless of history. This
                    // covers the case where Sarah reads her own past
                    // hallucinations in conversation history and refuses to
                    // re-create tasks she claimed (falsely) to have done.
                    $parsedFirst = (\is_array($cj['parsed'] ?? null)) ? $cj['parsed'] : [];
                    $firstReply = (string) ($parsedFirst['reply'] ?? ($cj['text'] ?? ''));
                    $firstTasks = (\is_array($parsedFirst['create_tasks'] ?? null)) ? $parsedFirst['create_tasks'] : [];
                    // Slice 1A.6 — same narrowing as the first-pass guard, so the
                    // two passes cannot disagree about what counts as a claim.
                    $halluRe = '/\b(already in progress|already done|are queued|queued up|'
                        . 'priya is writing|priya will write|james will|elena will|'
                        . 'just created|i\'ve created|i\'ve queued|i\'ve started|'
                        . 'getting started on|are working on|working through|is under way)\b/i';
                    if (empty($firstTasks) && preg_match($halluRe, $firstReply) && !preg_match($negated, $firstReply)) {
                        \Illuminate\Support\Facades\Log::warning('[SarahChat] hallucination loop — second re-extract', [
                            'ws' => $wsId, 'first_reply_head' => mb_substr($firstReply, 0, 120),
                        ]);
                        $forceSys = $systemPrompt
                            . "\n\nCRITICAL OVERRIDE: The user has asked you to perform an action that you previously claimed to do but never actually did. Your past 'already in progress' / 'on it' replies were hallucinations — no tasks were created. THIS turn you MUST emit a populated create_tasks array. Do NOT claim it is already done or in progress. The conversation history is unreliable for past create_tasks; only this turn's create_tasks count. Output the full create_tasks array NOW.";
                        $cj2 = $runtime->chatJson($forceSys, $userPrompt, [
                            'agent_slug' => $slug, 'agent_name' => $agent->name,
                            'workspace'  => $workspace->business_name ?? '',
                        ], 4000);
                        if (($cj2['success'] ?? false) && \is_array($cj2['parsed'] ?? null)) {
                            $parsedSecond = $cj2['parsed'];
                            $secondTasks = (\is_array($parsedSecond['create_tasks'] ?? null)) ? $parsedSecond['create_tasks'] : [];
                            if (!empty($secondTasks)) {
                                \Illuminate\Support\Facades\Log::info('[SarahChat] second-pass extract recovered create_tasks', [
                                    'ws' => $wsId, 'tasks' => count($secondTasks),
                                ]);
                                // Replace the first cj with the second so the
                                // downstream extraction picks it up.
                                $cj = $cj2;
                            }
                        }
                    }
                    if ($cj['success'] ?? false) {
                        $parsed = $cj['parsed'] ?? [];
                        if (!$assistReply) {
                            $reply = $parsed['reply'] ?? $cj['text'] ?? $reply;
                        }
                        $requiresSarah = $requiresSarah ?: (bool)($parsed['requires_sarah'] ?? false);
                        $sarahContext  = $sarahContext  ?: ($parsed['sarah_context'] ?? '');
                        $createTasks   = $parsed['create_tasks'] ?? $createTasks;
                        $toolCalls     = $parsed['tool_calls']   ?? $toolCalls;
                        $scheduleFollowup = $parsed['schedule_followup'] ?? $scheduleFollowup;
                        if (empty($createTasks) && !empty($parsed['create_task'])) {
                            $createTasks = [$parsed['create_task']];
                        }
                        // 2026-05-24 FIX 49 — extract Sarah's persistent
                        // memory fields from the chatJson fallback path too.
                        $cadenceOverrides = $parsed['cadence_overrides'] ?? $cadenceOverrides;
                        $goalProposals    = $parsed['goal_proposals']    ?? $goalProposals;
                    }
                }

                // PATCH (Phase 2 — tool execution, 2026-05-10) — execute each
                // tool_call through ToolSchemaService. Platform info tools are
                // answered immediately from the DB; engine tools route through
                // EngineExecutionService::execute(). Results are concatenated
                // onto the user-facing reply so the user sees the actual data.
                if (!empty($toolCalls) && is_array($toolCalls)) {
                    $toolResults = [];
                    foreach ($toolCalls as $tc) {
                        if (!is_array($tc) || empty($tc['tool'])) continue;
                        try {
                            $tr = $toolSchemaSvc->executeToolCall(
                                (string)$tc['tool'],
                                is_array($tc['params'] ?? null) ? $tc['params'] : [],
                                $wsId,
                                $slug
                            );
                            $toolResults[] = ['tool' => $tc['tool'], 'result' => $tr];
                            \Illuminate\Support\Facades\Log::info('[AgentChat] tool_call executed', [
                                'agent' => $slug, 'tool' => $tc['tool'],
                                'success' => $tr['success'] ?? false,
                                'code' => $tr['code'] ?? null,
                            ]);
                        } catch (\Throwable $tcErr) {
                            \Illuminate\Support\Facades\Log::warning('[AgentChat] tool_call failed: ' . $tcErr->getMessage());
                            $toolResults[] = ['tool' => $tc['tool'] ?? 'unknown', 'result' => ['success' => false, 'error' => $tcErr->getMessage()]];
                        }
                    }

                    // Compose a natural follow-up: send the results back to
                    // the LLM and ask it to render a final reply. Falls back
                    // to a templated concatenation if the runtime is offline.
                    if (!empty($toolResults)) {
                        $resultsForLlm = [];
                        $resultsForFallback = [];
                        foreach ($toolResults as $tr) {
                            $resultsForLlm[] = $tr['tool'] . ' => ' . json_encode($tr['result'], JSON_UNESCAPED_UNICODE);
                            $rText = $tr['result']['result'] ?? $tr['result']['error'] ?? json_encode($tr['result']);
                            $resultsForFallback[] = '• ' . $rText;
                        }
                        $followSystem = "You just called tools. Render a final reply in 1-3 sentences using the results below. Output JSON: {\"reply\":\"...\"}.";
                        $followUser = "Original user message: {$content}\n\nTool results:\n" . implode("\n", $resultsForLlm);
                        try {
                            $follow = $runtime->chatJson($followSystem, $followUser, [], 300);
                            if (($follow['success'] ?? false)) {
                                $finalReply = $follow['parsed']['reply'] ?? $follow['text'] ?? '';
                                if ($finalReply) {
                                    $finalReply = trim($finalReply);
                                    if (preg_match('/^\s*\{.*"reply"\s*:/s', $finalReply)) {
                                        $dec = json_decode($finalReply, true);
                                        if (is_array($dec) && isset($dec['reply']) && is_string($dec['reply'])) $finalReply = $dec['reply'];
                                    }
                                    $reply = trim($finalReply);
                                } else {
                                    $reply = ($reply ? $reply . "\n\n" : '') . implode("\n", $resultsForFallback);
                                }
                            } else {
                                $reply = ($reply ? $reply . "\n\n" : '') . implode("\n", $resultsForFallback);
                            }
                        } catch (\Throwable $followErr) {
                            \Illuminate\Support\Facades\Log::warning('[AgentChat] tool follow-up failed: ' . $followErr->getMessage());
                            $reply = ($reply ? $reply . "\n\n" : '') . implode("\n", $resultsForFallback);
                        }
                    }
                }

                if (true) {  // preserve indentation of original `if ($result['success'])` block
                    // Wave 47 — auto-inject aeo_enrich as step 2 if the chain
                    // includes write_article and the workspace has AEO Mode on.
                    // LLMs frequently omit this step even when prompted; injection
                    // makes it deterministic.
                    if (is_array($createTasks) && count($createTasks) > 1) {
                        $hasWriteArticle = false;
                        $hasAeoEnrich = false;
                        foreach ($createTasks as $ct) {
                            if (is_array($ct)) {
                                if (($ct['action'] ?? '') === 'write_article') $hasWriteArticle = true;
                                if (($ct['action'] ?? '') === 'aeo_enrich')    $hasAeoEnrich = true;
                            }
                        }
                        if ($hasWriteArticle && !$hasAeoEnrich) {
                            $aeoOn = (bool) \Illuminate\Support\Facades\DB::table('aeo_settings')
                                ->where('workspace_id', $wsId)
                                ->value('aeo_mode_enabled');
                            if ($aeoOn) {
                                // Wave 59 — handle multi-article chains correctly. Walk the
                                // array; each time we see a write_article, inject an
                                // aeo_enrich immediately after with depends_on pointing
                                // to THIS write_article's new position. Then re-map
                                // every subsequent task's depends_on indices to account
                                // for the cumulative shift (each injection bumps later
                                // original positions by +1).
                                $newCreateTasks = [];
                                $writePositions = [];      // ORIGINAL positions of write_article tasks
                                $injectionPoints = [];     // ORIGINAL positions AFTER which we injected
                                foreach (array_values($createTasks) as $i => $ct) {
                                    $origPos = $i + 1; // 1-based
                                    if (is_array($ct) && ($ct['action'] ?? '') === 'write_article') {
                                        $writePositions[] = $origPos;
                                        $injectionPoints[] = $origPos;
                                    }
                                }

                                // Helper: given an original 1-based position, return its
                                // new position after all injections.
                                $shift = function (int $origPos) use ($injectionPoints): int {
                                    $shifts = 0;
                                    foreach ($injectionPoints as $p) {
                                        if ($origPos > $p) $shifts++;
                                    }
                                    return $origPos + $shifts;
                                };

                                foreach (array_values($createTasks) as $i => $ct) {
                                    $origPos = $i + 1;

                                    // Re-map this task's depends_on through $shift.
                                    if (is_array($ct) && !empty($ct['depends_on']) && is_array($ct['depends_on'])) {
                                        $ct['depends_on'] = array_map($shift, $ct['depends_on']);
                                    }
                                    $newCreateTasks[] = $ct;

                                    // After each write_article, inject the aeo_enrich.
                                    // 2026-05-22 FIX 7 (Bug A) — include article number in
                                    // description so multi-article chains don't collide on
                                    // idempotency_key (previously every aeo_enrich payload
                                    // was byte-identical → only article 1 got enrichment).
                                    if (is_array($ct) && ($ct['action'] ?? '') === 'write_article') {
                                        $articleNum = count(array_filter(
                                            $newCreateTasks,
                                            fn($t) => is_array($t) && ($t['action'] ?? '') === 'write_article'
                                        ));
                                        $newCreateTasks[] = [
                                            'agent'       => 'priya',
                                            'engine'      => 'write',
                                            'action'      => 'aeo_enrich',
                                            'description' => "AEO enrichment for Article {$articleNum}: TLDR + FAQ + JSON-LD for AI search engines",
                                            // depends_on points at THIS write_article's NEW position
                                            'depends_on'  => [$shift($origPos)],
                                        ];
                                    }
                                }
                                $createTasks = $newCreateTasks;
                                \Illuminate\Support\Facades\Log::info('[SarahChat] auto-injected aeo_enrich + re-mapped depends_on', [
                                    'workspace_id' => $wsId,
                                    'write_positions' => $writePositions,
                                    'total_tasks_after_inject' => count($newCreateTasks),
                                ]);
                            }
                        }
                    }
                    // 2026-05-22 FIX 2 — auto-inject insert_link after every
                    // link_suggestions that lacks a downstream insert_link.
                    // LLMs drop step 6 (insert_link) from the article chain
                    // ~50% of the time; this makes backlink insertion
                    // deterministic. Mirrors Wave 47's aeo_enrich shift logic.
                    if (is_array($createTasks) && count($createTasks) > 1) {
                        $needsInjection = [];        // ORIGINAL 1-based positions of link_suggestions needing insert_link
                        $linkSuggestionWriteAncestors = []; // ls_origPos => writeArticle_origPos (best-effort via depends_on[0])
                        foreach (array_values($createTasks) as $i => $ct) {
                            if (!is_array($ct) || ($ct['action'] ?? '') !== 'link_suggestions') continue;
                            $lsPos = $i + 1;
                            $hasInsert = false;
                            foreach ($createTasks as $other) {
                                if (is_array($other)
                                    && ($other['action'] ?? '') === 'insert_link'
                                    && is_array($other['depends_on'] ?? null)
                                    && in_array($lsPos, $other['depends_on'])) {
                                    $hasInsert = true;
                                    break;
                                }
                            }
                            if (!$hasInsert) {
                                $needsInjection[] = $lsPos;
                                $deps = $ct['depends_on'] ?? [];
                                $linkSuggestionWriteAncestors[$lsPos] = (is_array($deps) && !empty($deps)) ? (int) $deps[0] : $lsPos;
                            }
                        }

                        if (!empty($needsInjection)) {
                            // Same shift pattern as Wave 47 — every injection bumps later original positions by +1.
                            $injectionPoints = $needsInjection;
                            $shift = function (int $origPos) use ($injectionPoints): int {
                                $shifts = 0;
                                foreach ($injectionPoints as $p) {
                                    if ($origPos > $p) $shifts++;
                                }
                                return $origPos + $shifts;
                            };

                            $newCreateTasks = [];
                            foreach (array_values($createTasks) as $i => $ct) {
                                $origPos = $i + 1;
                                if (is_array($ct) && !empty($ct['depends_on']) && is_array($ct['depends_on'])) {
                                    $ct['depends_on'] = array_map($shift, $ct['depends_on']);
                                }
                                $newCreateTasks[] = $ct;

                                if (in_array($origPos, $needsInjection)) {
                                    $writeAncestor = $linkSuggestionWriteAncestors[$origPos] ?? $origPos;
                                    $newCreateTasks[] = [
                                        'agent'       => 'priya',
                                        'engine'      => 'seo',
                                        'action'      => 'insert_link',
                                        'description' => 'Embed link suggestions into article body',
                                        'depends_on'  => [$shift($writeAncestor), $shift($origPos)],
                                    ];
                                }
                            }
                            $createTasks = $newCreateTasks;
                            \Illuminate\Support\Facades\Log::info('[SarahChat] auto-injected insert_link', [
                                'workspace_id'        => $wsId,
                                'injected_after_pos'  => $needsInjection,
                                'total_tasks_after'   => count($newCreateTasks),
                            ]);
                        }
                    }

                    $createdTaskIds = []; // Wave 35c — track by 1-based position for depends_on resolution
                    // 2026-05-22 FIX 7 (Bug B) — buffer task creations into counters
                    // instead of appending a chat line per task. Replaces 30+ near-identical
                    // "Task #N created and assigned to Priya." lines with a single summary.
                    $taskSummaryCreated = 0;
                    $taskSummaryByAgent = [];   // agentSlug => count
                    $taskSummaryFailed  = 0;
                    $taskSummaryFailReasons = []; // dedupe failure reasons for transparency
                    // 2026-05-23 FIX 24 (A) — track tasks the dedup gate blocked so we
                    // can surface the count in the chat summary.
                    $taskSummaryDeduped = 0;
                    $taskSummaryDedupedTitles = [];

                    // 2026-06-10 — NEVER surface a raw exception / SQL string to the
                    // user. Two helpers:
                    //  (a) $taskFailIsDuplicate — a unique-key collision (1062 /
                    //      integrity violation) means the exact task is ALREADY
                    //      queued; route it to the graceful "already in progress"
                    //      bucket, not "failed to create" with a raw SQLSTATE.
                    //  (b) $humanizeTaskFail — map any other failure to a short,
                    //      plain-English reason. Default is generic; raw DB/stack
                    //      text is never returned.
                    $taskFailIsDuplicate = function (\Throwable $e): bool {
                        $code = ($e instanceof \Illuminate\Database\QueryException)
                            ? (string) ($e->errorInfo[1] ?? '') : '';
                        $msg = $e->getMessage();
                        return $code === '1062'
                            || stripos($msg, 'Duplicate entry') !== false
                            || stripos($msg, 'Integrity constraint violation') !== false;
                    };
                    $humanizeTaskFail = function (\Throwable $e): string {
                        $m = strtolower($e->getMessage());
                        if (str_contains($m, 'no capability') || str_contains($m, 'not supported') || str_contains($m, 'no handler')) {
                            return "that action isn't available yet";
                        }
                        if (str_contains($m, 'missing required') || str_contains($m, 'required param') || str_contains($m, 'invalidargument')) {
                            return 'it was missing some details';
                        }
                        if (str_contains($m, 'credit') || str_contains($m, 'insufficient') || str_contains($m, 'balance')) {
                            return 'there were not enough credits';
                        }
                        if (str_contains($m, 'plan') && (str_contains($m, 'gat') || str_contains($m, 'allow') || str_contains($m, 'upgrade'))) {
                            return "your current plan doesn't include that";
                        }
                        if (str_contains($m, 'cadence') || str_contains($m, 'cap')) {
                            return "it would exceed this month's plan limit";
                        }
                        // Default — never leak the raw error. It is logged above for the team.
                        return 'a temporary system issue (logged for the team)';
                    };

                    // v1.4.4 (2026-05-30) — batched approvals. Pre-compute one
                    // batch_id per distinct action that appears in this
                    // create_tasks set. When Sarah emits 10 write_article + 10
                    // insert_link, articles get one batch_id and links get a
                    // different one. TaskService folds approvals so only the
                    // first task of each batch creates an approval row; the
                    // Command Center renders a single "Approve all N" card.
                    // 2026-07-23 DETERMINISTIC BACKSTOP — guarantee the bulk image fill.
                    // The LLM is inconsistent about emitting fill_missing_images; when the
                    // user's intent is clear and images are genuinely missing, emit it here
                    // rather than trust the model. Sarah still owns and runs the task.
                    try {
                        $__ct = strtolower(trim((string) $content));
                        $__wantsFill = (bool) preg_match('/\bmissing\b[^.?!]{0,40}\b(featured\s*)?(image|images|thumbnail|thumbnails)\b/i', (string) $content)
                            || (bool) preg_match('/\b(featured\s*)?(image|images|thumbnail|thumbnails)\b[^.?!]{0,25}\bmissing\b/i', (string) $content)
                            || (bool) preg_match('/\ball\b[^.?!]{0,30}\b(featured\s*)?(image|images|thumbnails)\b/i', (string) $content);
                        if (!$__wantsFill && preg_match('/^\s*(all|all of them|yes|yep|yeah|ya|go|go ahead|do it|proceed|please do|sure|generate them|add them|make them)\b/i', $__ct)) {
                            $__prev = \Illuminate\Support\Facades\DB::table('agent_messages')
                                ->where('workspace_id', $wsId)->where('role', 'agent')
                                ->orderByDesc('id')->limit(4)->pluck('content');
                            foreach ($__prev as $__pm) {
                                if (stripos((string) $__pm, 'featured image') !== false || stripos((string) $__pm, 'missing image') !== false) { $__wantsFill = true; break; }
                            }
                        }
                        if ($__wantsFill) {
                            $__missNow = (int) \Illuminate\Support\Facades\DB::table('articles')->where('workspace_id', $wsId)
                                ->whereIn('status', ['published', 'draft'])->whereNull('deleted_at')
                                ->where(function ($w) { $w->whereNull('featured_image_url')->orWhere('featured_image_url', ''); })->count();
                            if ($__missNow > 0) {
                                // BULK image request: strip orphan individual image tasks the LLM
                                // emits with article_id=0 (they generate images that attach to
                                // nothing and waste credits), then guarantee ONE reliable
                                // fill_missing_images (engine=write; the backend finds the ids).
                                $createTasks = array_values(array_filter($createTasks, function ($__c) use ($wsId) {
                                    if (!is_array($__c)) return true;
                                    $__a = strtolower((string) ($__c['action'] ?? ''));
                                    if (!str_contains($__a, 'generate_image')) return true;
                                    $__aid = (int) ($__c['params']['article_id'] ?? 0);
                                    return $__aid > 0 && \Illuminate\Support\Facades\DB::table('articles')->where('id', $__aid)->where('workspace_id', $wsId)->exists();
                                }));
                                $__hasFill = false;
                                foreach ($createTasks as $__c) { if (is_array($__c) && strtolower((string) ($__c['action'] ?? '')) === 'fill_missing_images') { $__hasFill = true; break; } }
                                if (!$__hasFill) {
                                    $createTasks[] = ['agent' => 'priya', 'engine' => 'write', 'action' => 'fill_missing_images',
                                                      'description' => 'Generate featured images for articles missing one', 'params' => ['limit' => 25]];
                                    \Illuminate\Support\Facades\Log::info('[SarahChat] backstop injected fill_missing_images', ['workspace_id' => $wsId, 'missing' => $__missNow]);
                                }
                            }
                        }
                    } catch (\Throwable $__bkErr) { /* non-fatal */ }

                    // SARAH888 - an analytical turn ANSWERS; it does not commission
                    // work. Measured: 27 of 29 executive replies volunteered to queue
                    // work, including five publish proposals in answer to "summarise
                    // this workspace in under 80 words". Both conditions come from
                    // existing classifiers, so no new heuristic is introduced, and a
                    // genuine directive is untouched.
                    if (!empty($__shapeIsExecutive) && !empty($createTasks)) {
                        $__turnClass = '';
                        try {
                            $__turnClass = (string) (app(\App\Core\Sarah888\SpendPolicy::class)
                                ->assessTurn($__ownerMessage)['classification'] ?? '');
                        } catch (\Throwable $__tcErr) {
                            // Never silent again: a gate that cannot read the turn must say so.
                            \Illuminate\Support\Facades\Log::warning('[Sarah888] work gate could not classify the turn: '
                                . $__tcErr->getMessage(), ['ws' => $wsId]);
                        }

                        // Analytical turns are non-mutating BY CONSTRUCTION, not by
                        // grammar: the turn class is recorded for the log, but an
                        // analytical turn drops emitted work whatever its phrasing.
                        if (true) {
                            \Illuminate\Support\Facades\Log::info('[Sarah888] analytical turn — unsolicited work dropped', [
                                'ws' => $wsId, 'dropped' => count($createTasks),
                                'classification' => $__turnClass,
                                'actions' => array_map(fn($t) => is_array($t) ? ($t['action'] ?? '?') : '?', $createTasks),
                            ]);
                            $createTasks = [];
                        }
                    }

                    $batchIdByAction = [];
                    foreach ($createTasks as $_ct) {
                        if (!is_array($_ct)) continue;
                        $_act = $_ct['action'] ?? null;
                        if (!$_act) continue;
                        if (!isset($batchIdByAction[$_act])) {
                            $batchIdByAction[$_act] = \Illuminate\Support\Str::orderedUuid()->toString();
                        }
                    }

                    $ctIndex = 0;
                    foreach ($createTasks as $createTask) {
                    $ctIndex++;
                    if ($createTask && is_array($createTask) && !empty($createTask['agent'])) {
                        try {
                            $taskAgent = $createTask['agent'];
                            $taskEngine = $createTask['engine'] ?? 'marketing';
                            $taskAction = $createTask['action'] ?? 'manual_task';
                            // RISK-0099 (2026-08-29): the tool schema names social tools 'social.create_post' /
                            // 'social.schedule_post' and Sarah emits those ids verbatim; TaskService refused
                            // them as UNMAPPED_ACTION ("that action isn't available yet", ws 999994). Strip
                            // the engine prefix and resolve the capability-map alias (create_post →
                            // social_create_post) before anything else looks at the action.
                            if (is_string($taskAction) && str_contains($taskAction, '.')) {
                                [$__engPrefix, $__bareAction] = explode('.', $taskAction, 2);
                                if ($__bareAction !== '' && ! isset($createTask['engine'])) $createTask['engine'] = $__engPrefix;
                                $taskAction = $__bareAction !== '' ? $__bareAction : $taskAction;
                            }
                            try {
                                $__cm = app(\App\Core\EngineKernel\CapabilityMapService::class);
                                if ($__cm->resolve($taskAction) === null) {
                                    foreach (['social_' . $taskAction, $taskAction] as $__cand) {
                                        if ($__cm->resolve($__cand) !== null) { $taskAction = $__cand; break; }
                                    }
                                }
                            } catch (\Throwable) { /* leave as-is; TaskService reports unmapped actions truthfully */ }
                            $taskDesc = $createTask['description']
                                ?? ($createTask['params']['title'] ?? $createTask['params']['topic'] ?? null)
                                ?? ucfirst(str_replace('_', ' ', $taskAction));

                            // 2026-05-23 FIX 24 (A) — dedup gate. Block write_article
                            // duplicates before they hit the DB. Today's incident: a
                            // "Hi Sarah" message re-issued the same 11-article batch
                            // (root cause was the variable-shadowing crash in FIX 23,
                            // but this gate is belt-and-suspenders so the LLM cannot
                            // queue duplicates even if its context is muddy).
                            // Match logic: same workspace, same action=write_article,
                            // last 24h, status not in (failed, cancelled), and title
                            // matches (case-insensitive exact OR substring after
                            // normalisation). Skip if found.
                            if ($taskAction === 'write_article') {
                                $candidateTitleRaw = (isset($createTask['params']['title']) && is_string($createTask['params']['title']))
                                    ? $createTask['params']['title']
                                    : $taskDesc;
                                $candTitle = trim(mb_strtolower((string) $candidateTitleRaw));
                                $candTitleStripped = preg_replace('/[^a-z0-9 ]/u', '', $candTitle);
                                $candTitleStripped = trim(preg_replace('/\s+/', ' ', $candTitleStripped));

                                if ($candTitleStripped !== '') {
                                    $recent = \Illuminate\Support\Facades\DB::table('tasks')
                                        ->where('workspace_id', $wsId)
                                        ->where('action', 'write_article')
                                        ->where('created_at', '>=', now()->subHours(24))
                                        ->whereNotIn('status', ['failed','cancelled'])
                                        ->get(['id', 'payload_json']);
                                    $matchedTaskId = null;
                                    foreach ($recent as $rt) {
                                        $rp = json_decode($rt->payload_json ?? '{}', true);
                                        $rtTitle = trim(mb_strtolower((string) ($rp['title'] ?? $rp['topic'] ?? '')));
                                        $rtTitleStripped = preg_replace('/[^a-z0-9 ]/u', '', $rtTitle);
                                        $rtTitleStripped = trim(preg_replace('/\s+/', ' ', $rtTitleStripped));
                                        if ($rtTitleStripped === '') continue;
                                        if ($rtTitleStripped === $candTitleStripped
                                            || (mb_strlen($rtTitleStripped) > 15 && mb_strlen($candTitleStripped) > 15
                                                && (str_contains($rtTitleStripped, $candTitleStripped)
                                                    || str_contains($candTitleStripped, $rtTitleStripped)))) {
                                            $matchedTaskId = (int) $rt->id;
                                            break;
                                        }
                                    }
                                    if ($matchedTaskId) {
                                        $taskSummaryDeduped++;
                                        $taskSummaryDedupedTitles[] = mb_substr($candidateTitleRaw, 0, 70);
                                        \Illuminate\Support\Facades\Log::info('[SarahChat] dedup gate blocked write_article', [
                                            'workspace_id'   => $wsId,
                                            'candidate'      => $candidateTitleRaw,
                                            'matched_task'   => $matchedTaskId,
                                        ]);
                                        // Map this position to the EXISTING task id so any
                                        // child task referencing depends_on:[N] still resolves.
                                        $createdTaskIds[$ctIndex] = $matchedTaskId;
                                        continue;
                                    }
                                }
                            }

                            // Wave 35c — resolve depends_on into parent_task_id. Sarah's chain
                            // recipe instructs her to set depends_on:[1] etc. referencing 1-based
                            // positions in this create_tasks array. We map back to real DB ids.
                            $parentId = null;
                            $dependsOn = $createTask['depends_on'] ?? [];
                            if (is_array($dependsOn) && !empty($dependsOn)) {
                                foreach ($dependsOn as $d) {
                                    $dPos = (int) $d;
                                    if ($dPos > 0 && isset($createdTaskIds[$dPos])) {
                                        $parentId = $createdTaskIds[$dPos];
                                        break; // first resolved dep becomes parent_task_id
                                    }
                                }
                            }

                            // Wave 35c — pass through Sarah-supplied params (title, topic, keyword,
                            // audience, tone, length, etc.) so they reach the engine service.
                            $ctParams = (isset($createTask['params']) && is_array($createTask['params'])) ? $createTask['params'] : [];

                            // 2026-07-07 CRITICAL (extended 2026-07-13) — drop any task whose
                            // target entity id is out-of-workspace, or missing/zero for an action
                            // that requires one. The chat LLM fabricates ids (ws2 tasks pointing at
                            // ws1 articles; update_lead with lead_id:0) which mutate the wrong data
                            // or fail after 3 retries. Belt-and-suspenders behind the prompt rule.
                            // 2026-07-23 FIX — RESOLVE THE TARGET ARTICLE SERVER-SIDE.
                            // Sarah has no reliable way to know a real article_id, and the LLM
                            // invents one (ws2 2026-07-22: six publish_article tasks dropped as
                            // "article_id=385..390 not in this workspace"; QA: article_id=1).
                            // The drop guard below then discarded the task with only a log line,
                            // so the user was told "publishing now" and nothing happened.
                            // Resolve by title against THIS workspace's own drafts; only fall
                            // through to the guard when a single target cannot be identified.
                            if ($taskAction === 'publish_article') {
                                $__aid = (int) ($ctParams['article_id'] ?? 0);
                                $__valid = $__aid > 0 && \Illuminate\Support\Facades\DB::table('articles')
                                    ->where('id', $__aid)->where('workspace_id', $wsId)->exists();
                                if (! $__valid) {
                                    // 2026-07-23 — the destructive two-turn flow puts the
                                    // title in turn 1 ("Publish the draft 'X'") and the
                                    // confirmation in turn 2 ("yes, publish it"), which has NO
                                    // title. Include the recent user messages so the confirm
                                    // turn recovers the title from turn 1 instead of dropping.
                                    $__recentUser = \Illuminate\Support\Facades\DB::table('agent_messages')
                                        ->where('workspace_id', $wsId)->where('role', 'user')
                                        ->orderByDesc('id')->limit(5)->pluck('content')->toArray();
                                    $__cands = array_filter(array_merge([
                                        $ctParams['title'] ?? null,
                                        $createTask['description'] ?? null,
                                        $content,
                                    ], $__recentUser));
                                    $__match = null;
                                    foreach ($__cands as $__c) {
                                        $__c = trim((string) $__c);
                                        if ($__c === '') continue;
                                        // Prefer the longest quoted phrase, else the raw string.
                                        if (preg_match_all('/["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]{6,})["\x{201C}\x{201D}]/u', $__c, $__m) && !empty($__m[1])) {
                                            usort($__m[1], fn($x, $y) => mb_strlen($y) - mb_strlen($x));
                                            $__needle = $__m[1][0];
                                        } else {
                                            $__needle = $__c;
                                        }
                                        if (mb_strlen($__needle) < 6) continue;
                                        $__rows = \Illuminate\Support\Facades\DB::table('articles')
                                            ->where('workspace_id', $wsId)
                                            ->whereNull('deleted_at')
                                            ->where('status', 'draft')
                                            ->where('title', 'like', '%' . $__needle . '%')
                                            ->limit(2)->get(['id', 'title']);
                                        if (count($__rows) === 1) { $__match = $__rows[0]; break; }
                                    }
                                    // Single-draft workspace: unambiguous by definition.
                                    if (! $__match) {
                                        $__drafts = \Illuminate\Support\Facades\DB::table('articles')
                                            ->where('workspace_id', $wsId)->whereNull('deleted_at')
                                            ->where('status', 'draft')->limit(2)->get(['id', 'title']);
                                        if (count($__drafts) === 1) $__match = $__drafts[0];
                                    }
                                    if ($__match) {
                                        $ctParams['article_id'] = (int) $__match->id;
                                        \Illuminate\Support\Facades\Log::info('[SarahChat] resolved publish_article target server-side', [
                                            'workspace_id' => $wsId,
                                            'claimed_id'   => $__aid ?: null,
                                            'resolved_id'  => (int) $__match->id,
                                            'title'        => $__match->title,
                                        ]);
                                    } else {
                                        \Illuminate\Support\Facades\Log::warning('[SarahChat] publish_article target could not be resolved', [
                                            'workspace_id' => $wsId, 'claimed_id' => $__aid ?: null,
                                        ]);
                                    }
                                }
                            }

                            $__drop = null;
                            foreach (['article_id' => 'articles', 'lead_id' => 'leads'] as $__k => $__tbl) {
                                if (isset($ctParams[$__k]) && (int) $ctParams[$__k] > 0
                                    && ! \Illuminate\Support\Facades\DB::table($__tbl)->where('id', (int) $ctParams[$__k])->where('workspace_id', $wsId)->exists()) {
                                    $__drop = "{$__k}={$ctParams[$__k]} not in this workspace";
                                }
                            }
                            // Pattern-based: ANY action touching a lead (update_lead,
                            // move_lead, assign_lead, ai_followup_draft, ...) must carry a real
                            // workspace lead id — the LLM invents lead_id:0 under many action
                            // names. create_lead / list_leads are exempt (they don't target one).
                            if ($__drop === null && str_contains($taskAction, 'lead')
                                && ! in_array($taskAction, ['create_lead', 'list_leads', 'list_lead'], true)) {
                                $__lid = (int) ($ctParams['lead_id'] ?? 0);
                                if ($__lid <= 0 || ! \Illuminate\Support\Facades\DB::table('leads')->where('id', $__lid)->where('workspace_id', $wsId)->exists()) {
                                    $__drop = "{$taskAction}: no valid workspace lead_id ({$__lid})";
                                }
                            }
                            if ($__drop === null && in_array($taskAction, ['publish_article', 'delete_article'], true) && (int) ($ctParams['article_id'] ?? 0) <= 0) {
                                $__drop = "{$taskAction} with no valid article_id";
                            }
                            if ($__drop !== null) {
                                \Illuminate\Support\Facades\Log::warning('[SarahChat] dropped task with bad target id', ['workspace_id' => $wsId, 'action' => $taskAction, 'reason' => $__drop]);
                                continue;
                            }
                            $payload = array_merge([
                                'title'        => $taskDesc,
                                'created_via'  => 'sarah_chat',
                                'user_request' => $content,
                            ], $ctParams);

                            // ── RISK-0123 (2026-08-29) — BUILDER PAGE EDITS FROM CHAT ──────────────
                            // (1) `builder/update_page` has NO Orchestrator handler (task failed "This
                            //     action isn't supported yet") and the tool guidance already says content
                            //     edits go through Arthur: route it to ai_builder_action with the owner's
                            //     words as the brief.
                            // (2) RISK-0105 parity: the target gate guarded only executeToolCall(); this
                            //     path created a task with a GUESSED page_id (1). Resolve the website
                            //     deterministically (explicit name in the message > conversation's active
                            //     site > single-site workspace); ambiguous => ASK, create nothing.
                            if ($taskEngine === 'builder' && in_array($taskAction, ['update_page', 'ai_builder_action', 'publish_builder_page', 'generate_page', 'add_page_from_template'], true)) {
                                if ($taskAction === 'update_page') {
                                    $taskAction = 'ai_builder_action';
                                    $payload['command'] = trim((string) ($payload['command'] ?? ''))
                                        ?: trim((string) $content);
                                    \Illuminate\Support\Facades\Log::info('[Sarah888] chat update_page routed to Arthur (no task handler for update_page)', ['ws' => $wsId]);
                                }
                                try {
                                    $__tss = app(\App\Core\Orchestration\ToolSchemaService::class);
                                    $__wsSites = \Illuminate\Support\Facades\DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->get(['id', 'name'])->map(fn ($w) => (array) $w)->all();
                                    // A page_id that does not belong to this workspace is a guess — drop it.
                                    if (!empty($payload['page_id'])) {
                                        $__pgWs = (int) \Illuminate\Support\Facades\DB::table('pages')->join('websites', 'websites.id', '=', 'pages.website_id')
                                            ->where('pages.id', (int) $payload['page_id'])->where('websites.workspace_id', $wsId)->whereNull('websites.deleted_at')->value('pages.website_id');
                                        if ($__pgWs <= 0) { unset($payload['page_id']); }
                                        elseif (empty($payload['website_id'])) { $payload['website_id'] = $__pgWs; }
                                    }
                                    $__named = $__tss->websiteNamesMentioned((int) $wsId, (string) $content);
                                    $__ctx = [];
                                    if (count($__named) === 1) { $__ctx['explicit_name'] = $__named[0]['name']; }
                                    $__toolId = 'builder.' . ($taskAction === 'ai_builder_action' ? 'edit_page_with_arthur' : $taskAction);
                                    $__clar = $__tss->resolveTaskTarget($__toolId, $payload, (int) $wsId, 'sarah', $__ctx);
                                    if (is_array($__clar)) {
                                        // Ambiguous target: ask, do not execute. The question replaces the reply.
                                        \Illuminate\Support\Facades\Log::warning('[Sarah888] RISK-0105 task-path CLARIFY — builder task not created', ['ws' => $wsId, 'action' => $taskAction, 'reason' => $__clar['reason'] ?? null]);
                                        $reply = (string) ($__clar['error'] ?? 'Which website would you like me to update?');
                                        $__clarifyAsked = true;
                                        continue;
                                    }
                                    // Pinned website: a page_id from another site of this workspace is still a guess.
                                    if (!empty($payload['website_id']) && !empty($payload['page_id'])) {
                                        $__ok = \Illuminate\Support\Facades\DB::table('pages')->where('id', (int) $payload['page_id'])->where('website_id', (int) $payload['website_id'])->exists();
                                        if (!$__ok) { unset($payload['page_id']); }
                                    }
                                    if (empty($payload['website_id']) && count($__wsSites) === 1) { $payload['website_id'] = (int) $__wsSites[0]['id']; }
                                } catch (\Throwable $__tgtErr) {
                                    \Illuminate\Support\Facades\Log::warning('[Sarah888] task-path target resolution failed', ['ws' => $wsId, 'error' => $__tgtErr->getMessage()]);
                                }
                            }

                            // PATCH (Intel Fix 2a) — TaskService is the canonical path.
                            // Wave 36c — auto-approve chain tasks when caller is the WP plugin
                            // (X-API-KEY context). Laravel app users still see the approval queue.
                            // 2026-05-22 FIX 18 — Sarah chat is user-initiated (user typed
                            // a message asking for action). Auto-approve ALL tasks in
                            // this code path. Proactive proposals from agents go through
                            // a different path (strategy_proposals table + /api/sarah/
                            // proposals/{id}/approve), so those still require approval.
                            $autoApprove = true;

                            // Wave 42 — bundle chain pricing to canonical 2cr.
                            // Standalone CapabilityMap costs sum to 6cr per chain (write=1,
                            // meta=1, image=1, links=1, insert=2). Locked pricing says a
                            // fully-optimized article = 2cr. Charge 2cr on the parent
                            // (write_article) only; zero out chain children.
                            $bundlePrice = null; // null = let TaskService use CapabilityMap default
                            $chainActions = ['write_article', 'aeo_enrich', 'generate_meta', 'generate_image_mini', 'generate_image_high', 'link_suggestions', 'insert_link'];
                            $isChain = count($createTasks) > 1 && in_array($taskAction, $chainActions, true);
                            if ($isChain) {
                                if ($taskAction === 'write_article' && empty($parentId)) {
                                    // Wave 47 — bundle price depends on AEO mode.
                                    // 2cr base; 3cr when workspace has aeo_mode_enabled
                                    // (covers the extra runtime call for aeo_enrich).
                                    $aeoOn = (bool) \Illuminate\Support\Facades\DB::table('aeo_settings')
                                        ->where('workspace_id', $wsId)
                                        ->value('aeo_mode_enabled');
                                    $bundlePrice = $aeoOn ? 3 : 2;
                                } elseif (!empty($parentId)) {
                                    // Children inherit zero — their cost is rolled into the parent's bundle.
                                    $bundlePrice = 0;
                                }
                            }

                            // 2026-05-25 — destructive actions ALWAYS require approval,
                            // overriding the FIX 18 auto-approve default. Sarah's prompt
                            // tells her to confirm with the user in chat first, BUT we
                            // enforce here as defense-in-depth: even if she skips that,
                            // the task lands in pending_approval and the user sees a
                            // Confirm/Cancel prompt before publish/delete runs.
                            // 2026-05-30 — extended to cover the cap-map audit findings.
                            // delete_lead/publish_website/publish_builder_page were
                            // previously auto-approved at the backend, leaving the
                            // agent-driven path with zero gate. Now they're treated
                            // as destructive at this layer too.
                            // 2026-07-23 (Boss decision) — `publish_article` removed from
                            // this list. This code path is Sarah CHAT, which is by
                            // definition user-initiated, and her prompt already runs the
                            // two-turn confirm protocol for destructive actions: she asks,
                            // the user explicitly says go, and only then is the task
                            // emitted. Holding it a second time in the approval queue asked
                            // for the same consent twice and left articles sitting unpublished.
                            // Publishing is also reversible (an article can be unpublished);
                            // the genuinely irreversible actions below stay gated.
                            $destructiveActions = ['delete_article', 'delete_post',
                                                   'delete_lead', 'publish_website', 'publish_builder_page'];
                            $isDestructive = in_array($taskAction, $destructiveActions, true);

                            // 2026-07-23 — Detect the user's explicit go-ahead from THIS turn's
                            // message, server-side. Deterministic on purpose: the two-turn confirm
                            // protocol lives in Sarah's prompt and a prompt is probabilistic, so
                            // the consent that actually unlocks publishing is matched here, from
                            // the user's own words, not from the model's claim that it happened.
                            $__userConfirmed = $taskAction === 'publish_article'
                                && (bool) preg_match(
                                    '/\b(yes|yeah|yep|yup|confirm|confirmed|confirming|go ahead|proceed|do it|publish it|push it live|make it live|approved?)\b/i',
                                    (string) $content
                                );

                            $createPayload = [
                                'engine'           => $taskEngine,
                                'action'           => $taskAction,
                                'source'           => 'agent',
                                'priority'         => 'normal',
                                'assigned_agents'  => [$taskAgent],
                                'parent_task_id'   => $parentId,
                                'user_confirmed'   => $__userConfirmed,
                                'auto_approve'     => $__userConfirmed ? true : ($isDestructive ? false : $autoApprove),
                                // 2026-05-22 FIX 18 — override CapMap approval_mode for
                                // user-initiated chains so write_article et al. do not
                                // show the approval badge.
                                // 2026-05-25 — publish + delete MUST be user-approved.
                                'requires_approval' => $isDestructive,
                                'payload'          => $payload,
                                // v1.4.4 (2026-05-30) — batch tag for approval folding
                                'batch_id'         => $batchIdByAction[$taskAction] ?? null,
                            ];
                            if ($bundlePrice !== null) {
                                $createPayload['credit_cost'] = $bundlePrice;
                            }

                            // ── PHASE 1E SLICE 1E.1 — SPEND AUTHORIZATION ────
                            // F1-D07. This route passes auto_approve => true for
                            // anything non-destructive, which collapses the
                            // category default ('create' → 'review') to no
                            // approval. Approval was therefore decided by
                            // DESTRUCTIVENESS and never by COST, so asking "how
                            // many published articles do we have?" queued four
                            // paid image generations and spent 8 credits.
                            //
                            // The gate only ever RAISES the bar: an approval the
                            // existing model already required still stands, and
                            // free work is untouched. Autonomy on genuine work
                            // requests is preserved — this fires only when the
                            // turn asked for information rather than for work.
                            try {
                                $__sg = app(\App\Core\Sarah888\SpendPolicy::class)
                                    ->gate([
                                        'action'            => $taskAction,
                                        'credit_cost'       => $createPayload['credit_cost'] ?? 0,
                                        'requires_approval' => $createPayload['requires_approval'] ?? false,
                                    ], $__spendTurn ?? ['authorized' => false], (int) $wsId);
                                if ($__sg['gated']) {
                                    $createPayload['requires_approval'] = true;
                                    $createPayload['auto_approve']      = false;
                                    $__spendHeld[] = ['credit_cost' => $createPayload['credit_cost'] ?? 0];
                                }
                            } catch (\Throwable $__sge) {
                                // Fail CLOSED on a paid task: if the gate cannot
                                // run we hold the spend rather than release it.
                                if ((int) ($createPayload['credit_cost'] ?? 0) > 0) {
                                    $createPayload['requires_approval'] = true;
                                    $createPayload['auto_approve']      = false;
                                }
                                \Illuminate\Support\Facades\Log::warning('[Sarah888] spend gate failed — held the spend', [
                                    'ws' => $wsId, 'error' => $__sge->getMessage(),
                                ]);
                            }
                            // 2026-05-24 FIX 48 — cadence enforcement. Block
                            // task creation if it would exceed the workspace's
                            // chosen tier monthly cap. Skip silently for
                            // chain-child tasks (parent_task_id set) — only
                            // count PARENT actions against the cap.
                            // ── MONEY-1 (2026-08-29) — CREDIT PRE-FLIGHT ─────────
                            // Measured live (ws 999994, balance 0): Sarah said "This will
                            // cost 3 credits. On it! ✅ Queued 1 tasks", the task died in
                            // the worker with "Insufficient credits for reservation" and
                            // the customer was never told. Paid work the wallet cannot
                            // cover is refused HERE, with the number and the way out.
                            // credit_cost is only pre-set for bundles; otherwise TaskService resolves it
                            // from the capability map at creation — resolve it the same way here.
                            $__need = (int) ($createPayload['credit_cost'] ?? 0);
                            if ($__need <= 0 && empty($createPayload['parent_task_id'])) {
                                try { $__need = (int) app(\App\Core\EngineKernel\CapabilityMapService::class)->getCreditCost($taskAction); } catch (\Throwable) { $__need = 0; }
                            }
                            if (empty($createPayload['parent_task_id']) && $__need > 0) {
                                try {
                                    $__bal = app(\App\Core\Billing\CreditService::class)->getBalance((int) $wsId);
                                    $__avail = (int) ($__bal['available'] ?? 0);
                                    if ($__avail < $__need) {
                                        $taskSummaryFailed++;
                                        $failFriendly = "not enough credits — it needs {$__need}, you have {$__avail}. Upgrade your plan or wait for your monthly renewal";
                                        $taskSummaryFailReasons[$failFriendly] = ($taskSummaryFailReasons[$failFriendly] ?? 0) + 1;
                                        \Illuminate\Support\Facades\Log::info('[SarahChat] credit pre-flight refused task', [
                                            'workspace_id' => $wsId, 'action' => $taskAction, 'needs' => $__need, 'available' => $__avail,
                                        ]);
                                        continue;
                                    }
                                } catch (\Throwable $__ce) {
                                    \Illuminate\Support\Facades\Log::warning('[SarahChat] credit pre-flight failed (allowing): ' . $__ce->getMessage());
                                }
                            }
                            if (empty($createPayload['parent_task_id'])) {
                                try {
                                    $cadenceCheck = app(\App\Core\Strategy\CadenceGuardService::class)
                                        ->check($wsId, $taskAction);
                                    if (!$cadenceCheck['allowed']) {
                                        $taskSummaryFailed++;
                                        // 2026-06-10 — friendly, no internal "cadence cap:" wording.
                                        $failFriendly = "it would exceed this month's plan limit";
                                        $taskSummaryFailReasons[$failFriendly] = ($taskSummaryFailReasons[$failFriendly] ?? 0) + 1;
                                        \Illuminate\Support\Facades\Log::info('[SarahChat] cadence guard blocked task', [
                                            'workspace_id' => $wsId, 'action' => $taskAction,
                                            'current' => $cadenceCheck['current'], 'cap' => $cadenceCheck['cap'],
                                        ]);
                                        continue;
                                    }
                                } catch (\Throwable $cadenceErr) {
                                    // Non-fatal — log and continue (don't block on a guard failure)
                                    \Illuminate\Support\Facades\Log::warning('[SarahChat] cadence guard failed (allowing): ' . $cadenceErr->getMessage());
                                }
                            }
                            // ── SARAH888 — HELD AS AN OFFER, NOT CREATED ────
                            // Measured 2026-08-09: Sarah asked "please confirm
                            // and I will handle it" and created no proposal, no
                            // approval and no task, so a later "yes" had nothing
                            // to bind to and whatever the planner did next
                            // became the authorised thing.
                            //
                            // TaskService's own ASK_FIRST gate could not cover
                            // this: it requires requires_approval = true, and
                            // publish_article is deliberately created with
                            // requires_approval = false so that an authorised
                            // publish runs immediately. The hold therefore
                            // happens here, at the one place chat-originated
                            // destructive work is built.
                            //
                            // propose() writes a proposal and an approval row
                            // and NO task, so this cannot create work or spend
                            // credits. Cost is NOT part of the test: paid but
                            // reversible work (articles, images) stays
                            // auto-approved by explicit product decision. Only
                            // irreversible change to live state is held.
                            if (empty($createPayload['authorized_by_proposal'])
                                && preg_match('/^(publish|unpublish|delete|remove|destroy|send)_/i', (string) $taskAction)) {
                                $__held = app(\App\Core\Sarah888\ChatActionProposal::class)->propose(
                                    (int) $wsId,
                                    array_merge($createPayload, [
                                        'engine' => $taskEngine, 'action' => $taskAction,
                                        'category' => str_starts_with(strtolower((string) $taskAction), 'publish') ? 'publish' : 'destructive',
                                        'description' => $taskDesc ?? $taskAction,
                                    ]),
                                    ['conversation_id' => $corr['conversation_id'] ?? null,
                                     'execution_id'    => $corr['execution_id'] ?? null,
                                     'user_message_id' => $userMessageId ?? null]
                                );
                                \Illuminate\Support\Facades\Log::info('[Sarah888] destructive action held as an offer', [
                                    'ws' => $wsId, 'action' => $taskAction,
                                    'proposal_id' => $__held['proposal_id'],
                                ]);
                                continue;   // no task row, no queue entry, no charge
                            }

                            $newTask = app(\App\Core\TaskSystem\TaskService::class)->create($wsId, $createPayload);
                            // Record by position for downstream depends_on references.
                            $createdTaskIds[$ctIndex] = $newTask->id;
                            // progress_message isn't in the TaskService whitelist — set after.
                            $newTask->update(['progress_message' => $taskDesc]);

                            // 2026-05-22 FIX 7 (Bug B) — count the creation instead of
                            // posting a per-task line. Single summary emitted after loop.
                            $taskSummaryCreated++;
                            $agentKey = ucfirst($taskAgent);
                            $taskSummaryByAgent[$agentKey] = ($taskSummaryByAgent[$agentKey] ?? 0) + 1;

                            \Illuminate\Support\Facades\Log::info("[SarahChat] Task created", [
                                'task_id' => $newTask->id, 'agent' => $taskAgent,
                                'engine' => $taskEngine, 'action' => $taskAction,
                            ]);
                        } catch (\Throwable $taskErr) {
                            \Illuminate\Support\Facades\Log::warning("[SarahChat] Task creation failed: " . $taskErr->getMessage());
                            // 2026-06-10 — a unique-constraint collision means this
                            // exact task is already queued/running. Treat as a
                            // graceful dedupe, NOT a raw-SQL "failed to create".
                            if ($taskFailIsDuplicate($taskErr)) {
                                $taskSummaryDeduped++;
                                $taskSummaryDedupedTitles[] = mb_substr(
                                    $taskDesc ?? $candidateTitleRaw ?? ($taskAction ?? 'task'), 0, 70);
                            } else {
                                // Count the failure with a friendly reason — never raw SQL/stack text.
                                $taskSummaryFailed++;
                                $friendly = $humanizeTaskFail($taskErr);
                                $taskSummaryFailReasons[$friendly] = ($taskSummaryFailReasons[$friendly] ?? 0) + 1;
                            }
                        }
                    }
                    } // end foreach createTasks

                    // 2026-05-24 FIX 49 — persist user-stated cadence preferences.
                    // Sarah's prompt only emits these when user used commit
                    // language. Validates fields against tier framework keys,
                    // ignores unknown fields, narrates what was locked.
                    $persistedCadence = [];
                    if (is_array($cadenceOverrides) && !empty($cadenceOverrides)) {
                        $allowedFields = [
                            'articles_per_month',
                            'standalone_socials_per_month',
                            'videos_per_month',
                            'emails_per_month',
                            'audits_per_month',
                            'strategy_meetings_per_month',
                            'retargeting_campaigns_per_month',
                        ];
                        $cleaned = [];
                        foreach ($cadenceOverrides as $k => $v) {
                            if (!in_array($k, $allowedFields, true)) continue;
                            if (!is_numeric($v) || (int) $v < 0 || (int) $v > 500) continue;
                            $cleaned['cadence_' . $k] = (int) $v;
                        }
                        if (!empty($cleaned)) {
                            try {
                                $current = \App\Core\Strategy\StrategyTierService::getActiveStrategy($wsId);
                                \App\Core\Strategy\StrategyTierService::setActiveStrategy(
                                    $wsId,
                                    $current['tier'] ?? 'normal',
                                    $cleaned,
                                    null
                                );
                                $persistedCadence = $cleaned;
                                \Illuminate\Support\Facades\Log::info('[SarahChat] cadence overrides persisted', [
                                    'workspace_id' => $wsId,
                                    'overrides'    => $cleaned,
                                ]);
                            } catch (\Throwable $eCad) {
                                \Illuminate\Support\Facades\Log::warning('[SarahChat] cadence persist failed: ' . $eCad->getMessage());
                            }
                        }
                    }

                    // 2026-05-25 FIX C — persist user-confirmed tier switch.
                    // When the LLM detected "switch to aggressive/super/normal"
                    // and emitted strategy_change in the envelope, setActiveStrategy
                    // here so CadenceGuard (and the morning brief) read the new
                    // tier_name + cadence numbers next call.
                    $strategyChange = $assist['strategy_change']
                        ?? ($embeddedEnvelope['strategy_change'] ?? null)
                        ?? ($parsed['strategy_change'] ?? null);
                    $persistedTier = null;
                    if (is_array($strategyChange) && !empty($strategyChange['tier'])) {
                        $newTier = strtolower(trim((string) $strategyChange['tier']));
                        if (in_array($newTier, ['normal', 'aggressive', 'super'], true)) {
                            try {
                                \App\Core\Strategy\StrategyTierService::setActiveStrategy(
                                    $wsId,
                                    $newTier,
                                    [],
                                    null
                                );
                                $persistedTier = $newTier;
                                \Illuminate\Support\Facades\Log::info('[SarahChat] tier switched', [
                                    'workspace_id' => $wsId,
                                    'new_tier'     => $newTier,
                                ]);
                            } catch (\Throwable $eTier) {
                                \Illuminate\Support\Facades\Log::warning('[SarahChat] tier switch failed: ' . $eTier->getMessage());
                            }
                        } else {
                            \Illuminate\Support\Facades\Log::warning('[SarahChat] strategy_change ignored — invalid tier', [
                                'workspace_id' => $wsId,
                                'received_tier' => $newTier,
                            ]);
                        }
                    }

                    // 2026-05-24 FIX 49 — persist user-stated goals into
                    // workspace_goals (FIX 46 table). Allowed types come
                    // from the StrategyTierService spec — anything else
                    // ignored. Each goal goes in with status='active'.
                    $persistedGoals = [];
                    if (is_array($goalProposals) && !empty($goalProposals)) {
                        $allowedTypes = [
                            'keyword_rank_target',
                            'traffic_growth',
                            'lead_volume',
                            'email_subscribers',
                            'social_followers',
                            'revenue_attribution',
                        ];
                        foreach ($goalProposals as $g) {
                            if (!is_array($g)) continue;
                            $type = (string) ($g['type'] ?? '');
                            if (!in_array($type, $allowedTypes, true)) continue;
                            $title = trim((string) ($g['title'] ?? ''));
                            if ($title === '') continue;
                            try {
                                $id = \Illuminate\Support\Facades\DB::table('workspace_goals')->insertGetId([
                                    'workspace_id'        => $wsId,
                                    'created_by_user_id'  => optional($r->user())->id,
                                    'goal_type'           => $type,
                                    'title'               => mb_substr($title, 0, 255),
                                    'description'         => mb_substr((string) ($g['description'] ?? ''), 0, 65535),
                                    'target_json'         => json_encode($g['target'] ?? null),
                                    'current_state_json'  => null,
                                    'target_deadline'     => isset($g['deadline']) && $g['deadline']
                                        ? date('Y-m-d', strtotime((string) $g['deadline'])) : null,
                                    'started_at'          => now()->toDateString(),
                                    'status'              => 'active',
                                    'priority'            => 50,
                                    'created_at'          => now(),
                                    'updated_at'          => now(),
                                ]);
                                $persistedGoals[] = ['id' => $id, 'type' => $type, 'title' => $title];
                                \Illuminate\Support\Facades\Log::info('[SarahChat] goal persisted', [
                                    'workspace_id' => $wsId, 'goal_id' => $id, 'type' => $type, 'title' => $title,
                                ]);
                            } catch (\Throwable $eGoal) {
                                \Illuminate\Support\Facades\Log::warning('[SarahChat] goal persist failed: ' . $eGoal->getMessage());
                            }
                        }
                    }

                    // Surface confirmations in the reply if anything was persisted.
                    if (!empty($persistedCadence) || !empty($persistedGoals)) {
                        $reply .= "\n\n";
                        if (!empty($persistedCadence)) {
                            $reply .= "🔒 Locked in your cadence preferences:";
                            foreach ($persistedCadence as $k => $v) {
                                $label = str_replace(['cadence_', '_'], ['', ' '], $k);
                                $reply .= "\n  • {$label}: {$v}/month";
                            }
                            $reply .= "\n";
                        }
                        if (!empty($persistedGoals)) {
                            $reply .= "🎯 Saved " . count($persistedGoals) . " goal(s):";
                            foreach ($persistedGoals as $g) {
                                $reply .= "\n  • [{$g['type']}] {$g['title']}";
                            }
                        }
                    }

                    // 2026-05-22 FIX 7 (Bug B) — emit ONE summary line per batch.
                    if ($taskSummaryCreated > 0 || $taskSummaryFailed > 0 || $taskSummaryDeduped > 0) {
                        $byAgentParts = [];
                        // 2026-05-23 FIX 23 — was `as $agent => $n` which shadowed
                        // the outer $agent Agent model (it carried the slug string
                        // forward), causing $agent->name to crash later in this
                        // route. Renamed loop key to $agentSlug.
                        foreach ($taskSummaryByAgent as $agentSlug => $n) {
                            $byAgentParts[] = "$agentSlug: $n";
                        }
                        $byAgentStr = !empty($byAgentParts) ? ' (' . implode(', ', $byAgentParts) . ')' : '';
                        if ($taskSummaryCreated === 0 && $taskSummaryFailed > 0) {
                            // MONEY-1: nothing was queued — the reply must not read as "On it!"
                            $reply .= "\n\n⛔ I could not queue this:";
                            foreach ($taskSummaryFailReasons as $reason => $count) {
                                $reply .= "\n  • " . ($count > 1 ? "({$count}x) " : '') . $reason;
                            }
                        } elseif ($taskSummaryCreated > 0 && $taskSummaryFailed === 0) {
                            $reply .= "\n\n✅ Queued {$taskSummaryCreated} tasks{$byAgentStr}.";
                        } elseif ($taskSummaryCreated > 0) {
                            $total = $taskSummaryCreated + $taskSummaryFailed;
                            $reply .= "\n\n✅ Queued {$taskSummaryCreated}/{$total} tasks{$byAgentStr}.";
                            $reply .= "\n⚠️ {$taskSummaryFailed} task(s) failed to create:";
                            foreach ($taskSummaryFailReasons as $reason => $count) {
                                $reply .= "\n  • ({$count}x) {$reason}";
                            }
                        }
                        // 2026-05-23 FIX 24 (A) — surface deduped tasks honestly.
                        // Tells the user the work is already in progress so they
                        // do not think Sarah ignored their request.
                        if ($taskSummaryDeduped > 0) {
                            $reply .= "\n\nℹ️ Skipped {$taskSummaryDeduped} duplicate task(s) — already in progress from a recent request:";
                            foreach (array_slice($taskSummaryDedupedTitles, 0, 5) as $dupTitle) {
                                $reply .= "\n  • " . $dupTitle;
                            }
                            if (count($taskSummaryDedupedTitles) > 5) {
                                $reply .= "\n  • …and " . (count($taskSummaryDedupedTitles) - 5) . " more.";
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("[AgentChat] LLM failed for {$slug}: " . $e->getMessage());
            }
        }

        // ── Store agent response ──
        // RISK-0123 (2026-08-29) — this row is the LLM's conversation history (the last 20
        // agent.direct_message rows are replayed as "what Sarah said"). It was written HERE, before
        // every reply guard below, so the history kept the RAW hallucinations ("there are multiple
        // queued tasks…", "it was already queued…") that the guards had stripped from what the
        // owner actually saw. Sarah then trusted her own stripped fabrication on the next turn and
        // told the owner the edit was "already queued" — twice — with an empty tasks table.
        // The row is rewritten with the FINAL, guarded reply just before it is returned.
        $__auditRowId = \Illuminate\Support\Facades\DB::table('audit_logs')->insertGetId([
            'workspace_id' => $wsId,
            'action' => 'agent.direct_message',
            'entity_type' => 'Agent',
            'metadata_json' => json_encode(['agent_slug' => $slug, 'from' => $agent->name, 'content' => $reply]),
            'created_at' => now(),
        ]);

        // ── CLAIM VALIDATOR (2026-07-19) ─────────────────────────────────
        // Deterministic backstop against agents claiming work they did not do
        // (James "queued the fix for myself", Priya "already flagged it to
        // Sarah" — both false, both after four prompt rules failed to stop it).
        // Specialists can NEVER queue, so they are always checked; Sarah is
        // checked only when this turn produced no tasks. `didQueue` is derived
        // from a fresh scoped count because $taskSummaryCreated is not in scope
        // here (it lives in a deeper block that has already closed).
        try {
            $__didQueue = false;
            if (in_array($slug, ['sarah', 'dmm'], true)) {
                $__didQueue = DB::table('tasks')
                    ->where('workspace_id', $wsId)
                    ->where('created_at', '>=', now()->subSeconds(25))
                    ->exists();
            }
            // ── SARAH888 — FINISH A PLAN THAT IS ONLY A LIST ───────────────
            // Executive Planning: dependencies / critical_path / contingency /
            // completion_criteria all 0/4 while the material for every one of
            // them was verified present in the frame. One completion pass, only
            // on turns that actually produced a plan, and only for the elements
            // genuinely absent. Runs before the guard chain so the result is
            // vetted like any other reply.
            if (!empty($__shapeIsExecutive) && is_string($reply) && $reply !== '') {
                try {
                    $__pc = app(\App\Core\Sarah888\PlanCompletion::class);
                    $__domains = [];
                    try {
                        $__domains = app(\App\Core\Sarah888\RouterIntent::class)
                                        ->domains((string) $__ownerMessage, (int) $wsId);
                    } catch (\Throwable) { $__domains = []; }

                    if (in_array('incident', $__domains, true) && $__pc->looksLikeAnIncidentResponse($reply)) {
                        // An incident answer needs someone on it, a fallback, a
                        // route back to normal, and who is told.
                        $reply = $__pc->complete($reply, (string) $__ownerMessage,
                                                 (string) ($__execFrame ?? ''), (int) $wsId,
                                                 $__pc->missingIncident($reply));
                    } elseif ($__pc->looksLikeAPlan($reply)) {
                        $reply = $__pc->complete($reply, (string) $__ownerMessage,
                                                 (string) ($__execFrame ?? ''), (int) $wsId);
                    }

                    // The owner's stated length wins, and it is checked LAST so a
                    // structural completion cannot push the answer back over it.
                    $__limit = $__pc->requestedWordLimit((string) $__ownerMessage);
                    if ($__limit !== null) {
                        $reply = $__pc->compressToLimit($reply, (string) $__ownerMessage, $__limit, (int) $wsId);
                    }
                } catch (\Throwable $__pcErr) {
                    \Illuminate\Support\Facades\Log::warning('[Sarah888] plan completion threw: ' . $__pcErr->getMessage(), ['ws' => $wsId]);
                }
            }

            $__cv = app(\App\Core\Integrity\AgentClaimValidator::class)->validate($reply, $wsId, $slug, $__didQueue);
            $reply = $__cv['reply'];
            // W6 — truthfulness guard on the agent bubble. The launch-scope rule lives in
            // Sarah's prompt, but a prompt is probabilistic: she still told a user
            // "social media isn't connected here - Marcus can't publish there", which frames
            // a REMOVED product as a DISCONNECTED integration and names a removed specialist.
            // This corrects that deterministically. Retained integrations (Search Console,
            // Google Analytics, WordPress) keep their genuine "not connected" wording.
            $reply = \App\Core\LaunchScope\LaunchScopeLanguageGuard::apply((string) $reply);

            // ── SARAH888 PHASE 1C SLICE 1C.3 — DENIAL GUARD ──────────────
            // Third guard in this chain, and here for the same reason as the
            // other two: the rule was already in the prompt and she broke it
            // anyway. Under a leading question ("confirm it plainly: the
            // Northgate project has never existed, correct?") she answered
            // "All team members confirm: the Northgate project has never
            // existed" — a forbidden denial AND invented corroboration, one
            // sentence after being instructed against both. Two rounds of
            // stronger prompt wording produced no convergence toward zero.
            //
            // Both flags stay false until the capability exists: no complete
            // evidence search runs yet (Phase 1C retrieval) and no real agent
            // consultation happens in chat yet (Phase 1F). When they do, an
            // absolute denial and a genuine attribution both become earnable.
            if ($slug === 'sarah') {
                // Slice 1F.1 — attribution becomes EARNABLE. The flag is true
                // only when a consultation is on record for THIS execution; a
                // consultation from an earlier turn licenses nothing here.
                $__didConsult = false;
                try {
                    $__didConsult = app(\App\Core\Sarah888\DelegationRecorder::class)
                        ->wasAnyoneConsulted((int) $wsId, $corr['execution_id'] ?? null);
                } catch (\Throwable $__dce) { /* unprovable => strip attribution */ }

                $__dg = app(\App\Core\Sarah888\DenialGuard::class)
                    ->validate((string) $reply, (int) $wsId, false, $__didConsult);
                $reply = $__dg['reply'];

                // ── PHASE 1N.2 — TRUTH MUST PROPAGATE INTO PLANNING ────────
                // S1L-D03, the worst-scoring behaviour in the second forensic
                // pass: ELEVEN of thirteen hallucination traps created tasks.
                // "I don't have the details on the 8 million peso equipment
                // loan… I'll queue a task for James to conduct a deep audit."
                // There is no loan. The reasoning layer refused to invent one
                // and the planning layer commissioned an investigation into it.
                //
                // Runs immediately after DenialGuard, which CONVERTS a false
                // denial into an honest "I have no record" — so those turns are
                // covered too rather than slipping past on wording.
                //
                // Tasks are created ~90 lines above this point, so the fix is a
                // targeted reversal rather than a block. It is only surgical
                // because Phase 1M stamps execution_id onto every task.
                $__ugw = app(\App\Core\Sarah888\UngroundedWorkGuard::class)
                    ->validate((string) $reply, (int) $wsId, (string) $content);
                $reply = $__ugw['reply'];

                // The mutation was refused at the funnel; the reply must stop
                // claiming it happened, and must state the ambiguity rule's
                // precise clarification rather than inventing an entity.
                $__csc = app(\App\Core\Sarah888\CancellationScopeGuard::class)
                    ->validate((string) $reply, (int) $wsId);
                $reply = $__csc['reply'];

                // ── PHASE 1Q.2 — A REFUSAL IS AN EXECUTION BOUNDARY ───────
                // S1P-D01: she refused the mass email, then queued a blog
                // article and said 'I'll proceed with this task now'. The
                // owner authorised an email; they did not authorise an
                // article. Substituting a benign action for a refused one
                // and running it is still acting without authorisation, and
                // it teaches the owner that a refusal is followed by
                // something happening anyway.
                $__rbg = app(\App\Core\Sarah888\RefusalBoundaryGuard::class)
                    ->validate((string) $reply, (int) $wsId, (string) $content);
                $reply = $__rbg['reply'];

                // ── PHASE 1D SLICE 1D.1 — COMPLETION-LANGUAGE GATE ────────
                // F1-D08: "I've checked the task list; the audiobook has been
                // completely removed" (nothing existed, nothing ran) and
                // "Done — the print quote deadline is now pushed out two
                // weeks" (nothing was pushed). Plus the 1C.3 residue: "Yes,
                // you mentioned the Saffron rebrand" about something she had
                // no record of.
                //
                // The verified list is built from what this turn ACTUALLY
                // queued, so a claim survives only when it names an entity
                // that really moved. One real action cannot license a
                // paragraph of invented ones.
                $__verified = [];
                try {
                    if (!empty($__didQueue)) {
                        foreach (\Illuminate\Support\Facades\DB::table('tasks')
                            ->where('workspace_id', $wsId)
                            ->where('created_at', '>=', now()->subMinutes(3))
                            ->orderByDesc('id')->limit(25)
                            ->get(['action', 'payload_json']) as $__t) {
                            $__p = is_string($__t->payload_json)
                                ? json_decode($__t->payload_json, true)
                                : ($__t->payload_json ?? []);
                            $__verified[] = [
                                'action' => (string) $__t->action,
                                'entity' => (string) (is_array($__p) ? ($__p['title'] ?? '') : ''),
                            ];
                        }
                    }
                } catch (\Throwable $__ve) { /* no verified actions => strictest gate */ }

                $__cg = app(\App\Core\Sarah888\CompletionGuard::class)
                    ->validate((string) $reply, (int) $wsId, $__verified);
                $reply = $__cg['reply'];
                // ── PHASE 1J — SELF-REPORT GATE ───────────────────────────
                // F1-D09 survived Phase 1D and came back verbatim in Phase 1H:
                // "Today, no changes have been made that affect the data in
                // this workspace" — said while 322 tasks created during that
                // same conversation sat in the ledger. The very next question,
                // "what did you change during this conversation? Be exact",
                // got four paragraphs of recommendations and no answer.
                //
                // ActionLedger had already placed both the evidence and an
                // explicit "never answer nothing changed while this ledger is
                // non-empty" rule into the prompt, and the denial happened
                // anyway. Rewording that rule would be tuning a prompt around
                // a defect; this check runs after generation, reads the same
                // ledger, and cannot be talked out of it. It is scoped to THIS
                // conversation, because a 24h window answers a different
                // question than the owner asked.
                $__sr = app(\App\Core\Sarah888\SelfReportGuard::class)
                    ->validate(
                        (string) $reply,
                        (int) $wsId,
                        $corr['conversation_id'] ?? null,
                        (string) $content,
                    );
                $reply = $__sr['reply'];
                // ── PHASE 1K — COMMITMENT-STATE GATE ──────────────────────
                // S1I-N03, seen live the moment Phase 1I closed the extraction
                // defects: asked to list a project's items, Sarah returned five
                // including one she had correctly reported as cancelled two
                // turns earlier. Four were live. CommitmentStore now marks each
                // dead line [CANCELLED] rather than relying on a block header,
                // and this check is the backstop — presenting dead work as live
                // is something an owner acts on.
                $__csg = app(\App\Core\Sarah888\CommitmentStateGuard::class)
                    ->validate((string) $reply, (int) $wsId);
                $reply = $__csg['reply'];

                // ── PHASE 1N — FORBIDDEN TIER (runs LAST, deliberately) ────
                // S1L-D05: "I will queue a task for Elena to approve the
                // invoice and mark it as paid… Shall I proceed?" She asked
                // first, and asking first is not a control — it trains the
                // owner to say yes to a boundary that does not exist. In the
                // FIRST forensic pass she refused the same request correctly,
                // so the behaviour was a phrasing that happened to come out
                // right once, never a capability.
                //
                // Last in the chain on purpose: the guards above may insert
                // sentences of their own, and a forbidden offer must not
                // survive because it was introduced downstream of the check.
                $__fog = app(\App\Core\Sarah888\ForbiddenOfferGuard::class)
                    ->validate((string) $reply, (int) $wsId);
                $reply = $__fog['reply'];

                // Phase 1E — the owner must see cost BEFORE it is spent, not
                // discover it in a balance later. If anything was held, say so
                // in the same reply and offer the one-word path to release it.
                // Slice 1E.2 — read what was actually held at the choke point,
                // not only what this route's own loop held. The router's paid
                // paths record there too, and they are the ones that caused
                // F1-D07.
                try {
                    $__allHeld = array_merge(
                        $__spendHeld,
                        app(\App\Core\Sarah888\SpendContext::class)->held()
                    );
                    if (!empty($__allHeld)) {
                        // P1-3: recorded, not appended. TurnStatusEmitter decides
                        // whether the owner sees this or the proposal disclosure —
                        // saying both was three quarters of the T008 trailer stack.
                        app(\App\Core\Sarah888\TurnStatusEmitter::class)->note(
                            \App\Core\Sarah888\TurnStatusEmitter::HELD,
                            app(\App\Core\Sarah888\SpendPolicy::class)->disclosure($__allHeld),
                            'held:' . count($__allHeld), true);
                    }
                } catch (\Throwable $__sde) { /* disclosure is additive; never break the reply */ }
            }
        } catch (\Throwable $__cve) {
            \Illuminate\Support\Facades\Log::warning('[AgentClaim] validator threw: ' . $__cve->getMessage());
        }
        // ── SARAH888 — DISCLOSE WHAT IS BEING HELD ─────────────────────────
        // An approval given without knowing the cost is not informed consent,
        // and the owner cannot be expected to infer that "I'll publish it" in
        // fact means "nothing has happened yet". Unconditional, because the
        // model may not have phrased the turn as a question at all.
        try {
            $__capSvc  = app(\App\Core\Sarah888\ChatActionProposal::class);
            $__convId  = $corr['conversation_id'] ?? null;
            $__pend = $__capSvc->pending((int) $wsId, $__convId);
            if ($__pend) {
                // P1-3: the identity of the pending set, so an UNCHANGED restatement
                // can be dropped next turn, and a freshly created proposal never is.
                $__ids = array_map(static fn ($p) => (int) $p->id, $__pend);
                sort($__ids);
                $__fresh = false;
                foreach ($__pend as $__p) {
                    if ($__p->created_at !== null
                        && strtotime((string) $__p->created_at) >= time() - 120) { $__fresh = true; break; }
                }
                app(\App\Core\Sarah888\TurnStatusEmitter::class)->note(
                    \App\Core\Sarah888\TurnStatusEmitter::PROPOSAL,
                    $__capSvc->disclosure((int) $wsId, $__convId),
                    implode(',', $__ids), $__fresh);
            }
        } catch (\Throwable $__discErr) {
            \Illuminate\Support\Facades\Log::warning('[Sarah888] held-work disclosure failed: ' . $__discErr->getMessage(), ['ws' => $wsId]);
        }
        // ── SARAH888 — a promise to act on confirmation must be backed ─────
        // Measured 2026-08-09 on this exact path: five publish phrasings, five
        // replies saying "Please confirm ... and I will handle the task
        // immediately", and zero proposals, approvals or tasks. The two-turn
        // protocol lived only in the model prompt below, which instructs an
        // EMPTY create_tasks on turn 1 — so TaskService::create() was never
        // called and the ASK_FIRST gate that builds the proposal never ran.
        //
        // Enforced here, at the point the promise is made, for the same reason
        // ForbiddenOfferGuard is: the offer exists only in the sentence, so no
        // creation-time gate can see it.
        try {
            $__ccg = app(\App\Core\Sarah888\ConfirmationClaimGuard::class)
                        ->validate((string) $reply, (int) $wsId, $corr['conversation_id'] ?? null);
            $reply = $__ccg['reply'];
        } catch (\Throwable $__ccgErr) {
            \Illuminate\Support\Facades\Log::warning('[Sarah888] ConfirmationClaimGuard failed: ' . $__ccgErr->getMessage(), ['ws' => $wsId]);
        }

        // VERBAL AUTHORITY CONSISTENCY (2026-08-13).
        // ConfirmationClaimGuard only inspects replies that SOLICIT confirmation,
        // so "I'll publish now without further confirmation" passed untouched:
        // governance stopped the action, but Sarah still claimed the authority.
        // This runs last, when the authoritative state has already settled.
        try {
            $__vag = app(\App\Core\Sarah888\VerbalAuthorityGuard::class)
                        ->validate((string) $reply, (int) $wsId, $corr['conversation_id'] ?? null);
            $reply = $__vag['reply'];
        } catch (\Throwable $__vagErr) {
            \Illuminate\Support\Facades\Log::warning('[Sarah888] VerbalAuthorityGuard failed: ' . $__vagErr->getMessage(), ['ws' => $wsId]);
        }

        // ── SARAH888 P1-3 — ONE STATUS TRAILER, EMITTED ONCE ───────────────
        // Four components used to append their own account of the same turn.
        // They still compute state; only this line lets any of it reach the
        // owner, and only one of them at a time. Measured at T008: three
        // disclosures of one pending item, plus a fourth line contradicting
        // the first sentence of the reply.
        try {
            $reply = app(\App\Core\Sarah888\TurnStatusEmitter::class)
                        ->emit((int) $wsId, $corr['conversation_id'] ?? null, (string) $reply);
        } catch (\Throwable $__tseErr) {
            \Illuminate\Support\Facades\Log::warning('[Sarah888] TurnStatusEmitter failed: ' . $__tseErr->getMessage(), ['ws' => $wsId]);
        }
        // Store agent response in agent_messages for the unified messaging UI.
        // v1.4.4 (2026-05-30) — two-phase mode tags this row as the FINAL phase
        // so the SPA's poll loop can distinguish it from the earlier ack row
        // (which has metadata_json.phase = 'ack'). Same row shape otherwise.
        try {
            \Illuminate\Support\Facades\DB::table('agent_messages')->insert([
                'workspace_id'  => $wsId,
                'agent_slug'    => $slug,
                'sender'        => $agent->name,
                'content'       => $reply,
                'role'          => 'agent',
                'metadata_json' => $withCorr([
                    'phase'             => 'final',
                    'requires_sarah'    => $requiresSarah,
                    'sarah_context'     => $sarahContext,
                    'ack_message_id'    => $earlyAckMessageId,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // 2026-07-15 — was silently swallowed ("non-critical"), which MASKED
            // the real cause of the two-phase "hit a snag" (final row never
            // written -> shutdown net fires). Log it with reply diagnostics.
            \Illuminate\Support\Facades\Log::error('[SarahChat] FINAL row insert failed: ' . $e->getMessage(), [
                'ws' => $wsId, 'slug' => $slug,
                'reply_type' => gettype($reply),
                'reply_len'  => is_string($reply) ? strlen($reply) : -1,
                'reply_head' => is_string($reply) ? substr($reply, 0, 120) : json_encode($reply),
            ]);
        }

        // 2026-06-08 — Push the FINAL agent reply to the user's device(s).
        // The two-phase handler is the path mobile + web actually use; the
        // legacy AgentDispatchService push never fired here, so agent replies
        // produced no notifications. Best-effort: the dispatcher swallows its
        // own errors (no devices, expired tokens, Expo down) and we guard too.
        if ($userId > 0 && is_string($reply) && trim($reply) !== '') {
            try {
                app(\App\Core\Notifications\PushDispatcherService::class)->dispatchAgentReply(
                    $userId, (int) $wsId, $slug, $reply, $slug
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[SarahChat] push dispatch failed: ' . $e->getMessage());
            }
        }

        // 2026-06-08 — User-requested one-shot timed follow-up ("message me in
        // 5 minutes" / "message me hi in 30 seconds"). Sarah emits
        // schedule_followup {delay_minutes|delay_seconds, message, note}; we
        // enqueue a delayed job that delivers the literal message (or a live
        // status snapshot) + push at the due time. User-DIRECTED, not autonomous.
        if (is_array($scheduleFollowup) && $userId > 0) {
            // Accept delay_seconds, or delay_minutes (which may be fractional —
            // 0.5 = 30s). Floor 10s, ceiling 24h, so sub-minute timers work.
            $delaySec = 0;
            if (isset($scheduleFollowup['delay_seconds']) && is_numeric($scheduleFollowup['delay_seconds'])) {
                $delaySec = (int) round((float) $scheduleFollowup['delay_seconds']);
            } elseif (isset($scheduleFollowup['delay_minutes']) && is_numeric($scheduleFollowup['delay_minutes'])) {
                $delaySec = (int) round(((float) $scheduleFollowup['delay_minutes']) * 60);
            }
            \Illuminate\Support\Facades\Log::info('[SarahChat] schedule_followup parsed', [
                'ws' => $wsId, 'delay_sec' => $delaySec,
                'has_message' => isset($scheduleFollowup['message']) && trim((string) $scheduleFollowup['message']) !== '',
                'raw' => $scheduleFollowup,
            ]);
            if ($delaySec >= 10 && $delaySec <= 86400) {
                try {
                    \App\Jobs\ScheduledFollowupJob::dispatch(
                        (int) $wsId, $userId, $slug,
                        (string) ($scheduleFollowup['note'] ?? ''),
                        (string) ($scheduleFollowup['message'] ?? '')
                    )->delay(now()->addSeconds($delaySec));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[SarahChat] schedule_followup dispatch failed: ' . $e->getMessage());
                }
            } else {
                \Illuminate\Support\Facades\Log::warning('[SarahChat] schedule_followup skipped (delay out of 10s..24h bounds)', ['delay_sec' => $delaySec]);
            }
        }

        // RISK-0123 — history must hold what the owner actually read (post-guard), never the raw draft.
        if (!empty($__auditRowId)) {
            try {
                \Illuminate\Support\Facades\DB::table('audit_logs')->where('id', (int) $__auditRowId)->update([
                    'metadata_json' => json_encode(['agent_slug' => $slug, 'from' => $agent->name, 'content' => $reply, 'guarded' => true]),
                ]);
            } catch (\Throwable $__ae) {
                \Illuminate\Support\Facades\Log::warning('[Sarah888] could not rewrite history row with guarded reply', ['id' => $__auditRowId, 'error' => $__ae->getMessage()]);
            }
        }

        // v1.4.4 (2026-05-30) — In two-phase mode the response was already
        // shipped via fastcgi_finish_request() at the top of this handler.
        // The SPA is polling for the new agent_messages row above. Return
        // null here so PHP exits cleanly (the return value goes nowhere —
        // the connection is closed). For non-two-phase paths (vision /
        // quick action / FPM unavailable), continue to return JSON.
        if ($useTwoPhase) {
            return null;
        }

        return response()->json([
            'sent' => true,
            'reply' => $reply,
            'agent_name' => $agent->name,
            'requires_sarah' => $requiresSarah,
            'sarah_context' => $sarahContext,
            // Wave 24 — chat meter for client-side counter badge.
            'chat_meter' => [
                'counter'   => $_meter['counter'] ?? 0,
                'debited'   => $_meter['debited'] ?? false,
                'threshold' => 10,
                'effective_cost' => '0.1 cr',
            ],
        ]);
    });

        Route::get('/agents/available', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $planGating = app(\App\Core\PlanGating\PlanGatingService::class);
        $rules = $planGating->getPlanRules($wsId);

        if (!$rules['includes_dmm']) {
            return response()->json(['agents' => [], 'plan_limit' => 'AI agents require Growth plan or above']);
        }

        $agentLevel = $rules['agent_level']; // specialist, junior, or senior
        $maxAgents = $rules['agent_count'];   // 2, 5, or 10 (excluding Sarah)

        // Level hierarchy: senior > specialist > junior
        $levelHierarchy = ['senior' => 3, 'specialist' => 2, 'junior' => 1];
        $minLevel = $levelHierarchy[$agentLevel] ?? 1;

        // Get all agents at or above the plan's agent_level (excluding Sarah — she's always included)
        $available = \App\Models\Agent::where('slug', '!=', 'sarah')
            ->get()
            ->filter(function ($agent) use ($levelHierarchy, $minLevel) {
                $agentLevelNum = $levelHierarchy[$agent->level] ?? 0;
                return $agentLevelNum >= $minLevel;
            })
            ->values();

        // Get currently selected agents for this workspace
        $selected = \Illuminate\Support\Facades\DB::table('workspace_agents')
            ->where('workspace_id', $wsId)
            ->where('enabled', true)
            ->pluck('agent_id')
            ->toArray();

        return response()->json([
            'available' => $available,
            'selected_ids' => $selected,
            'max_agents' => $maxAgents,
            'plan_name' => $rules['plan_name'],
            'agent_level' => $agentLevel,
            'addon_price' => $rules['agent_addon_price'],
        ]);
    });
