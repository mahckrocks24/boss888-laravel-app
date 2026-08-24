<?php

namespace App\Core\Agent;

use App\Models\Meeting;
use App\Models\MeetingMessage;
use App\Models\MeetingParticipant;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\Agent;
use App\Models\User;
use App\Core\TaskSystem\TaskService;
use App\Core\Audit\AuditLogService;
use App\Core\LLM\InstructionParser;
use App\Core\LLM\AgentReasoningService;
// MultiStepPlanner removed 2026-04-12 (Phase 1.0.0 / doc 07) — was a dead injection
use App\Engines\Creative\Services\AgentBridgeService;
use App\Core\Notifications\PushDispatcherService; // v1.4.4 — phone push for agent replies
use Illuminate\Support\Str;

class AgentDispatchService
{
    public function __construct(
        private TaskService $taskService,
        private AuditLogService $auditLog,
        private InstructionParser $instructionParser,
        private AgentReasoningService $agentReasoning,
        private AgentBridgeService $agentBridge,
        private PushDispatcherService $pushDispatcher, // v1.4.4
    ) {}

    /**
     * Dispatch a message to an agent.
     * Creates or reuses conversation (meeting), stores message,
     * and optionally creates a task if the message implies an action.
     */
    public function dispatch(int $workspaceId, int $userId, array $data): array
    {
        $agentSlug = $data['agent_id'];
        $content = $data['content'];
        $conversationId = $data['conversation_id'] ?? null;
        $source = $data['source'] ?? 'app888';

        $agent = Agent::where('slug', $agentSlug)->first();
        if (! $agent) {
            abort(404, "Agent not found: {$agentSlug}");
        }

        // Resolve or create conversation (meeting)
        $meeting = $conversationId
            ? Meeting::where('workspace_id', $workspaceId)->where('id', $conversationId)->first()
            : null;

        if (! $meeting) {
            $meeting = Meeting::create([
                'workspace_id' => $workspaceId,
                'title' => "Chat with {$agent->name}",
                'status' => 'active',
                'created_by' => $userId,
            ]);

            // Add user as participant
            MeetingParticipant::create([
                'meeting_id' => $meeting->id,
                'participant_type' => 'user',
                'participant_id' => $userId,
            ]);

            // Add agent as participant
            MeetingParticipant::create([
                'meeting_id' => $meeting->id,
                'participant_type' => 'agent',
                'participant_id' => $agent->id,
            ]);
        }

        // Store user message
        $message = MeetingMessage::create([
            'meeting_id' => $meeting->id,
            'sender_type' => 'user',
            'sender_id' => $userId,
            'message' => $content,
            'attachments_json' => $data['attachments'] ?? null,
        ]);

        // v1.4.4 — mirror user message into agent_messages so the web SPA's
        // per-agent thread (POST /api/agents/{slug}/messages reads this
        // table) sees what the mobile app sent. Without this, mobile and
        // web each had their own message store and never sync'd.
        try {
            \Illuminate\Support\Facades\DB::table('agent_messages')->insert([
                'workspace_id' => $workspaceId,
                'agent_slug'   => $agentSlug,
                'sender'       => 'user',
                'content'      => $content,
                'role'         => 'user',
                'metadata_json'=> json_encode([
                    'source'         => $source,
                    'meeting_id'     => $meeting->id,
                    'message_id'     => $message->id,
                    'attachments'    => $data['attachments'] ?? null,
                ]),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[AgentDispatch] agent_messages mirror failed (user): ' . $e->getMessage());
        }

        // v1.4.4 — if the user attached files, ask the runtime to interpret
        // them (vision for images, text extraction for PDFs / DOCX / Excel)
        // and prepend the resulting USER_ATTACHMENTS block to the prompt
        // content the agent reasons over. Best-effort — interpretation
        // failures are non-fatal.
        $effectiveContent = $content;
        $attachments = $data['attachments'] ?? null;
        if (is_array($attachments) && count($attachments) > 0) {
            try {
                $runtime = app(\App\Connectors\RuntimeClient::class);
                $attaches = collect($attachments)->map(function ($a) {
                    $url = $a['original_url'] ?? $a['url'] ?? null;
                    if (! $url && isset($a['media_id'])) {
                        $row = \Illuminate\Support\Facades\DB::table('media')->where('id', $a['media_id'])->first(['url']);
                        $url = $row?->url ?? null;
                    }
                    if ($url && ! preg_match('#^https?://#', $url)) {
                        $url = rtrim(config('app.url', 'https://staging.levelupgrowth.io'), '/') . $url;
                    }
                    return $url ? [
                        'url'  => $url,
                        'mime' => $a['mime'] ?? null,
                        'kind' => $a['kind'] ?? null,
                        'name' => $a['name'] ?? null,
                    ] : null;
                })->filter()->values()->all();

                if (! empty($attaches) && method_exists($runtime, 'interpretAttachments')) {
                    $interpreted = $runtime->interpretAttachments($attaches);
                    $block = $interpreted['prompt_block'] ?? '';
                    if (is_string($block) && trim($block) !== '') {
                        $effectiveContent = $content . "\n\n" . $block;
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[AgentDispatch] attachment interpret failed: ' . $e->getMessage());
            }
        }

        // Parse instruction using LLM (falls back to keyword matching if LLM unavailable)
        $parsed = $this->instructionParser->parse($content, $workspaceId);

        // Inject creative context if this is a creative-related dispatch
        $creativeContext = $this->injectCreativeContext($workspaceId, $content, $parsed);

        // Generate agent reply (with creative context + attachment context injected if applicable)
        // W6: the mobile chat path never had the launch-scope language guard.
        // Applied below at :content so mobile gets the same truthful framing
        // the web SPA already had.
        $agentReply = $this->agentReasoning->respond($agentSlug, $workspaceId, $effectiveContent, $meeting->id);

        // W6: sanitise once, here, so the meeting message, the agent_messages
        // mirror and the push payload all inherit truthful framing. The guard
        // only rewrites sentences that are BOTH about a removed capability AND
        // use banned framing - transactional-email diagnostics are preserved.
        if (!empty($agentReply['content'])) {
            $agentReply['content'] = \App\Core\LaunchScope\LaunchScopeLanguageGuard::apply(
                (string) $agentReply['content']
            );
        }

        // Store agent reply
        if ($agentReply['success'] && ! empty($agentReply['content'])) {
            $agentMsg = MeetingMessage::create([
                'meeting_id' => $meeting->id,
                'sender_type' => 'agent',
                'sender_id' => $agent->id,
                'message' => $agentReply['content'],
            ]);

            // v1.4.4 — mirror agent reply into agent_messages so the web SPA
            // sees it in the per-agent thread without a second LLM call.
            try {
                \Illuminate\Support\Facades\DB::table('agent_messages')->insert([
                    'workspace_id' => $workspaceId,
                    'agent_slug'   => $agentSlug,
                    'sender'       => $agent->name ?? $agentSlug,
                    'content'      => $agentReply['content'],
                    'role'         => 'assistant',
                    'metadata_json'=> json_encode([
                        'source'      => $source,
                        'meeting_id'  => $meeting->id,
                        'message_id'  => $agentMsg->id,
                    ]),
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[AgentDispatch] agent_messages mirror failed (agent): ' . $e->getMessage());
            }

            // v1.4.4 — fire push notification to the user's registered devices.
            // PushDispatcher catches all exceptions internally so a push failure
            // never blocks the message-store path.
            $this->pushDispatcher->dispatchAgentReply(
                userId:          $userId,
                workspaceId:     $workspaceId,
                agentSlug:       $agentSlug,
                rawContent:      $agentReply['content'],
                conversationId:  (string) $meeting->id,
                messageId:       $agentMsg->id ?? null,
            );
        }

        // Create task if instruction parser identified an actionable request
        $taskId = null;
        if ($parsed['confidence'] >= 40 && ! empty($parsed['action'])) {
            try {
                $payload = array_merge($parsed['params'] ?? [], [
                    'instruction' => $content,
                    'conversation_id' => $meeting->id,
                    'source' => $source,
                ]);

                // Attach creative context to the task payload if present
                if (!empty($creativeContext)) {
                    $payload['creative_context'] = $creativeContext;
                }

                $task = $this->taskService->create($workspaceId, [
                    'engine' => $parsed['engine'],
                    'action' => $parsed['action'],
                    'payload' => $payload,
                    'source' => 'agent',
                    'assigned_agents' => [$parsed['agent_id'] ?? $agentSlug],
                    'priority' => $parsed['priority'] ?? ($data['priority'] ?? 'normal'),
                ]);
                $taskId = $task->id;

                // Link task to meeting
                $meeting->tasks()->syncWithoutDetaching([$task->id]);
            } catch (\Throwable $e) {
                // Task creation may fail due to plan gating — that's expected
            }
        }

        $this->auditLog->log($workspaceId, $userId, 'agent.message_dispatched', 'Meeting', $meeting->id, [
            'agent' => $agentSlug,
            'has_task' => $taskId !== null,
            'has_creative_context' => !empty($creativeContext),
            'source' => $source,
            'parse_source' => $parsed['source'] ?? 'unknown',
            'confidence' => $parsed['confidence'] ?? 0,
        ]);

        return [
            'success' => true,
            'message_id' => (string) $message->id,
            // v1.4.4 — conversation_id is the agent slug so mobile + web SPA
            // share the same identifier. Underlying meeting id stays
            // available for orchestration audit via `meeting_id`.
            'conversation_id' => $agentSlug,
            'meeting_id' => (string) $meeting->id,
            'task_id' => $taskId ? (string) $taskId : null,
        ];
    }

    /**
     * Inject creative context when the dispatch involves creative-related tasks.
     * Returns the creative context array, or empty array if not applicable.
     */
    private function injectCreativeContext(int $workspaceId, string $content, array $parsed): array
    {
        // Check if this is a creative-related dispatch
        $creativeEngines = ['creative', 'builder', 'manualedit'];
        $creativeActions = ['generate_image', 'generate_video', 'create_design', 'edit_design', 'create_page'];
        $creativeKeywords = ['image', 'design', 'creative', 'visual', 'banner', 'logo', 'video', 'graphic', 'photo'];

        $engine = $parsed['engine'] ?? '';
        $action = $parsed['action'] ?? '';
        $lower  = strtolower($content);

        $isCreative = in_array($engine, $creativeEngines)
            || in_array($action, $creativeActions)
            || collect($creativeKeywords)->contains(fn ($kw) => str_contains($lower, $kw));

        if (!$isCreative) {
            return [];
        }

        try {
            return $this->agentBridge->buildWorkspaceContext($workspaceId);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * List conversations for a user in a workspace.
     * v1.4.4 — Reads from agent_messages so mobile + web SPA see the same
     * threads. Conversation id == agent_slug (one thread per agent per
     * workspace, matching the web SPA's per-agent direct chat surface).
     */
    public function listConversations(int $workspaceId, int $userId): array
    {
        // v1.4.4 (2026-05-30) — Workspace-wide approval/task signals.
        // The mobile "Needs you" filter walks the conversation list and
        // shows conversations with has_approval_pending=true. Specialists
        // (Priya/Marcus/etc.) often have no agent_messages history because
        // they only execute task work — yet their tasks are exactly what
        // the user needs to approve. Solution: roll up workspace-wide
        // approval state onto Sarah (the orchestrator, always present in
        // the inbox via is_pinned), so "Needs you" always lights up when
        // work is waiting somewhere in the workspace.
        $wsPendingApprovals = \Illuminate\Support\Facades\DB::table('approvals')
            ->where('workspace_id', $workspaceId)
            ->where('status', 'pending')
            ->count();
        $wsActiveTaskCount = \Illuminate\Support\Facades\DB::table('tasks')
            ->where('workspace_id', $workspaceId)
            ->whereNotIn('status', ['completed', 'cancelled', 'failed'])
            ->count();

        $rows = \Illuminate\Support\Facades\DB::table('agent_messages as am')
            ->select(
                'am.agent_slug',
                \Illuminate\Support\Facades\DB::raw('MAX(am.created_at) as last_message_time'),
                \Illuminate\Support\Facades\DB::raw('COUNT(*) as msg_count')
            )
            ->where('am.workspace_id', $workspaceId)
            ->groupBy('am.agent_slug')
            ->orderByDesc('last_message_time')
            ->limit(50)
            ->get();

        $out = [];
        $sarahSeen = false;
        foreach ($rows as $row) {
            $slug = $row->agent_slug;
            $agent = Agent::where('slug', $slug)->first();
            if ($slug === 'sarah') $sarahSeen = true;
            $last  = \Illuminate\Support\Facades\DB::table('agent_messages')
                ->where('workspace_id', $workspaceId)
                ->where('agent_slug', $slug)
                ->orderByDesc('created_at')
                ->first(['content', 'role', 'created_at', 'metadata_json']);

            // Per-agent unread count = unread AGENT rows.
            // 2026-06-15 FIX — was role='assistant', but the two-phase chat
            // endpoint + every proactive write persist replies as role='agent'
            // (only the retired /exec-api dispatch wrote 'assistant'). The
            // read-marking path (markAgentThreadRead + /messages/{slug}/read)
            // already clears role='agent', so counting 'assistant' left the
            // badge permanently stuck on stale rows while ignoring real unread.
            // Count 'agent' to match the writers + the read-marking path.
            $unread = (int) \Illuminate\Support\Facades\DB::table('agent_messages')
                ->where('workspace_id', $workspaceId)
                ->where('agent_slug', $slug)
                ->where('role', 'agent')
                ->whereNull('read_at')
                ->count();

            // Linked task / approvals (best-effort — keep existing behaviour
            // where the mobile inbox surfaces these flags).
            $linkedTaskId      = null;
            $taskCount         = 0;
            $hasApprovalPending= false;
            if ($agent) {
                $taskCount = Task::where('workspace_id', $workspaceId)
                    ->whereRaw("JSON_CONTAINS(assigned_agents_json, ?)", ['"'.$slug.'"'])
                    ->whereNotIn('status', ['completed', 'cancelled', 'failed'])
                    ->count();
                $linkedTaskId = Task::where('workspace_id', $workspaceId)
                    ->whereRaw("JSON_CONTAINS(assigned_agents_json, ?)", ['"'.$slug.'"'])
                    ->orderByDesc('created_at')
                    ->value('id');
                $hasApprovalPending = Task::where('workspace_id', $workspaceId)
                    ->whereRaw("JSON_CONTAINS(assigned_agents_json, ?)", ['"'.$slug.'"'])
                    ->whereHas('approval', fn ($q) => $q->where('status', 'pending'))
                    ->exists();
            }

            // v1.4.4 (2026-05-30) — Sarah is the workspace inbox. Show
            // workspace-wide totals on her row so "Needs you" surfaces
            // approvals on specialists who don't have a conversation row.
            if ($slug === 'sarah') {
                if ($wsPendingApprovals > 0)   $hasApprovalPending = true;
                if ($wsActiveTaskCount > $taskCount) $taskCount    = $wsActiveTaskCount;
            }

            $out[] = [
                'id' => $slug, // conversation id == agent slug
                'agent_id' => $slug,
                'type' => $taskCount > 0 ? 'task' : 'direct',
                'last_message' => $last?->content ?? '',
                'last_message_time' => $last?->created_at
                    ? \Carbon\Carbon::parse($last->created_at)->toIso8601String()
                    : now()->toIso8601String(),
                'unread_count' => $unread,
                'linked_task_id' => $linkedTaskId ? (string) $linkedTaskId : null,
                'task_count' => $taskCount,
                'has_approval_pending' => $hasApprovalPending,
                'is_pinned' => $slug === 'sarah',
                'title' => $agent?->name,
            ];
        }

        // v1.4.4 (2026-05-30) — Sarah is always in the inbox even if no
        // chat history exists yet. Without this, a brand-new workspace
        // with pending approvals would show "Needs you" = 0 because
        // there are no agent_messages rows at all.
        if (!$sarahSeen) {
            $sarahAgent = Agent::where('slug', 'sarah')->first();
            if ($sarahAgent) {
                $out[] = [
                    'id'                   => 'sarah',
                    'agent_id'             => 'sarah',
                    'type'                 => $wsActiveTaskCount > 0 ? 'task' : 'direct',
                    'last_message'         => '',
                    'last_message_time'    => now()->toIso8601String(),
                    'unread_count'         => 0,
                    'linked_task_id'       => null,
                    'task_count'           => $wsActiveTaskCount,
                    'has_approval_pending' => $wsPendingApprovals > 0,
                    'is_pinned'            => true,
                    'title'                => $sarahAgent->name,
                ];
            }
        }

        return $out;
    }

    /**
     * v1.4.4 — Get a single conversation thread. $conversationId is the
     * agent_slug (matches web SPA's per-agent direct chat surface).
     * Reads from agent_messages so mobile + web see the same history.
     */
    public function getConversation(int $workspaceId, string $conversationId): array
    {
        $slug = $conversationId === 'dmm' ? 'sarah' : $conversationId;
        $agent = Agent::where('slug', $slug)->first();

        $rows = \Illuminate\Support\Facades\DB::table('agent_messages')
            ->where('workspace_id', $workspaceId)
            ->where('agent_slug', $slug)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(200)
            ->get(['id', 'sender', 'content', 'role', 'metadata_json', 'created_at']);

        $messages = $rows->map(function ($r) use ($slug) {
            $meta = is_string($r->metadata_json) ? (json_decode($r->metadata_json, true) ?: []) : ($r->metadata_json ?? []);
            $isUser = ($r->role === 'user');
            return [
                'id'             => (string) $r->id,
                'conversationId' => $slug,
                'type'           => $isUser ? 'user' : 'agent_reply',
                'senderId'       => $isUser ? 'user' : $slug,
                'content'        => $r->content,
                'timestamp'      => \Carbon\Carbon::parse($r->created_at)->toIso8601String(),
                'attachments'    => $meta['attachments'] ?? null,
            ];
        })->toArray();

        return [
            'id'       => $slug,
            'title'    => $agent?->name ?? $slug,
            'messages' => $messages,
        ];
    }

    /**
     * v1.4.4 — Cursor-based event stream for APP888 polling. Reads new
     * agent_messages rows so mobile picks up replies that were generated
     * by the web SPA's per-agent endpoint too. Conversation id == slug.
     */
    public function getEvents(int $workspaceId, int $userId, ?string $cursor = null, ?string $conversationId = null): array
    {
        // 2026-07-14 (message-refresh fix) — robust cursor parse.
        // (a) A client echoing the emitted ISO cursor un-encoded turns the
        //     "+00:00" offset into a space ("...16 00:00") -> Carbon threw
        //     DateMalformedStringException -> HTTP 500 -> the live poller
        //     self-disabled after 5 fails. Normalize space->"+" and guard.
        // (b) created_at is second-precision; the old strict ">" dropped any
        //     message landing in the cursor's exact second. Subtract a 2s
        //     safety overlap so boundary-second rows are never lost.
        //     Re-delivery within the overlap is idempotent — web
        //     (_acIsRendered) and mobile both dedup events by id.
        //
        // 2026-07-26 (Sarah live-refresh incident) — the overlap was 2s while
        // the client polls every 2.5s (core.js poll_interval_ms). The lookback
        // was therefore SMALLER than the poll interval, leaving a 0.5s blind
        // spot on every tick: a row written in that window satisfied neither
        // poll's range and was never delivered. Sarah's reply takes 15-47s, so
        // the poller ticks ~18 times per answer and only had to lose one race
        // for the reply to never appear until a manual refresh.
        //
        // The lookback MUST exceed the client poll interval. 6s covers the
        // 2.5s interval plus a slow tick, a backgrounded tab resuming, and
        // second-precision rounding on created_at. Duplicates inside the
        // overlap are deduped by event id on both clients, so a wider window
        // is free.
        //
        // INVARIANT: EVENT_LOOKBACK_SECONDS > core.js poll_interval_ms / 1000.
        $lookbackSeconds = 6;

        $since = now()->subMinutes(5);
        if ($cursor) {
            try {
                $since = \Carbon\Carbon::parse(str_replace(' ', '+', $cursor))->subSeconds($lookbackSeconds);
            } catch (\Throwable $e) {
                $since = now()->subMinutes(5);
            }
        }

        $events = collect();

        // 1. New agent replies since cursor (skip user echoes — mobile already
        // rendered those optimistically).
        $newRowsQ = \Illuminate\Support\Facades\DB::table('agent_messages')
            ->where('workspace_id', $workspaceId)
            // 2026-06-07 (Sarah live-refresh fix) — the two-phase ack endpoint
            // (/api/agents/{slug}/messages) persists agent replies with
            // role='agent'; the legacy /exec-api dispatch path writes
            // role='assistant'. Accept BOTH so mobile's event poller delivers
            // Sarah's async replies live instead of only on chat re-open.
            // Still excludes role='user' echoes (rendered optimistically).
            ->whereIn('role', ['assistant', 'agent'])
            ->where('created_at', '>', $since)
            ->orderBy('created_at')
            ->limit(50);
        if ($conversationId) {
            $slug = $conversationId === 'dmm' ? 'sarah' : $conversationId;
            $newRowsQ->where('agent_slug', $slug);
        }
        $newMessages = $newRowsQ->get(['id', 'agent_slug', 'content', 'created_at']);

        foreach ($newMessages as $msg) {
            $events->push([
                'id' => 'am_' . $msg->id,
                'type' => 'message',
                'conversation_id' => $msg->agent_slug,
                'agent_id' => $msg->agent_slug,
                'content' => $msg->content,
                'timestamp' => \Carbon\Carbon::parse($msg->created_at)->toIso8601String(),
                'data' => [],
            ]);
        }

        // 2. Task events for tasks in this workspace (conversation_id is the
        // first assigned agent's slug — matches the per-agent thread model).
        $taskIds = Task::where('workspace_id', $workspaceId)
            ->where('updated_at', '>', $since)
            ->pluck('id');

        if ($taskIds->isNotEmpty()) {
            $taskEvents = TaskEvent::whereIn('task_id', $taskIds)
                ->where('created_at', '>', $since)
                ->orderBy('created_at')
                ->limit(50)
                ->get();

            foreach ($taskEvents as $te) {
                $task = Task::find($te->task_id);
                $assigned = $task?->assigned_agents_json ?? [];
                $firstAgent = is_array($assigned) && count($assigned) > 0 ? $assigned[0] : null;
                $eventType = $this->mapTaskEventType($te->event);

                $events->push([
                    'id' => 'te_' . $te->id,
                    'type' => $eventType,
                    'conversation_id' => (string) ($firstAgent ?? ''),
                    'task_id' => (string) $te->task_id,
                    'agent_id' => $firstAgent,
                    'content' => $te->message,
                    'timestamp' => $te->created_at->toIso8601String(),
                    'data' => array_merge($te->data_json ?? [], [
                        'title' => $task?->action,
                        'status' => $task?->status,
                        'progress' => $task?->total_steps > 0
                            ? round(($task->current_step / $task->total_steps) * 100)
                            : 0,
                        'assigned_agents' => $assigned,
                        'created_at' => $task?->created_at?->toIso8601String(),
                        'completed_at' => $task?->completed_at?->toIso8601String(),
                    ]),
                ]);
            }
        }

        // Sort by timestamp and compute new cursor
        $sorted = $events->sortBy('timestamp')->values();
        // Always advance to server-now so an idle poll never freezes the
        // cursor on a dead second (which, with the 2s overlap, would re-emit
        // the same rows every tick). now() only moves forward, so the 2s
        // lookback above still catches any row written in the gap — no drops.
        $newCursor = now()->toIso8601String();

        return [
            'events' => $sorted->toArray(),
            'cursor' => $newCursor,
        ];
    }

    // ── Private helpers ──────────────────────────────────────────

    private function shouldCreateTask(string $content): bool
    {
        $actionKeywords = [
            'create', 'write', 'generate', 'build', 'make', 'send', 'publish',
            'analyze', 'audit', 'report', 'update', 'fix', 'optimize', 'schedule',
        ];

        $lower = strtolower($content);
        foreach ($actionKeywords as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }

        return strlen($content) > 100; // Long instructions likely imply tasks
    }

    // 2026-05-31 — resolveEngine + resolveAction removed as dead code.
    // resolveAction had a `return 'create_lead'` fallback that, if ever
    // wired, would turn every unparseable user message into a CRM lead
    // (the exact bug the InstructionParser fix on 2026-05-30 closed in
    // its own caller path). Confirmed no other file references either
    // method — they were never called. Deleted defensively so the bug
    // can't return via a future copy-paste rewire.

    private function mapTaskEventType(string $event): string
    {
        return match ($event) {
            'execution_started' => 'task_created',
            'execution_completed' => 'task_completed',
            'execution_failed' => 'task_failed',
            'step_executed', 'step_verified' => 'progress_update',
            'credits_reserved', 'credits_committed', 'credits_released' => 'task_status',
            'circuit_open', 'rate_limited', 'throttled' => 'task_status',
            'stale_recovered' => 'task_status',
            default => 'task_status',
        };
    }
}
