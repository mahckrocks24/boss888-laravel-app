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
use Illuminate\Support\Str;

class AgentDispatchService
{
    public function __construct(
        private TaskService $taskService,
        private AuditLogService $auditLog,
        private InstructionParser $instructionParser,
        private AgentReasoningService $agentReasoning,
        private AgentBridgeService $agentBridge,
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

        // Parse instruction using LLM (falls back to keyword matching if LLM unavailable)
        $parsed = $this->instructionParser->parse($content, $workspaceId);

        // Inject creative context if this is a creative-related dispatch
        $creativeContext = $this->injectCreativeContext($workspaceId, $content, $parsed);

        // Generate agent reply (with creative context injected if applicable)
        $agentReply = $this->agentReasoning->respond($agentSlug, $workspaceId, $content, $meeting->id);

        // Store agent reply
        if ($agentReply['success'] && ! empty($agentReply['content'])) {
            MeetingMessage::create([
                'meeting_id' => $meeting->id,
                'sender_type' => 'agent',
                'sender_id' => $agent->id,
                'message' => $agentReply['content'],
            ]);
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
            'conversation_id' => (string) $meeting->id,
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
     * Maps meetings → APP888 conversation format.
     */
    public function listConversations(int $workspaceId, int $userId): array
    {
        $meetings = Meeting::where('workspace_id', $workspaceId)
            ->whereHas('participants', function ($q) use ($userId) {
                $q->where('participant_type', 'user')->where('participant_id', $userId);
            })
            ->with(['messages' => fn ($q) => $q->latest()->limit(1)])
            ->with(['participants' => fn ($q) => $q->where('participant_type', 'agent')])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        return $meetings->map(function ($meeting) {
            $agentParticipant = $meeting->participants->first();
            $agent = $agentParticipant ? Agent::find($agentParticipant->participant_id) : null;
            $lastMsg = $meeting->messages->first();

            // Count pending approvals linked to this conversation
            $pendingApprovals = Task::whereHas('approval', fn ($q) => $q->where('status', 'pending'))
                ->whereIn('id', $meeting->tasks()->pluck('tasks.id'))
                ->count();

            return [
                'id' => (string) $meeting->id,
                'agent_id' => $agent?->slug ?? 'sarah',
                'type' => $meeting->tasks()->count() > 0 ? 'task' : 'direct',
                'last_message' => $lastMsg?->message ?? '',
                'last_message_time' => ($lastMsg?->created_at ?? $meeting->created_at)->toIso8601String(),
                'unread_count' => 0, // TODO: implement read tracking
                'linked_task_id' => $meeting->tasks()->latest('meeting_tasks.created_at')->value('tasks.id'),
                'task_count' => $meeting->tasks()->count(),
                'has_approval_pending' => $pendingApprovals > 0,
            ];
        })->toArray();
    }

    /**
     * Get a single conversation with messages.
     */
    public function getConversation(int $workspaceId, int $conversationId): array
    {
        $meeting = Meeting::where('workspace_id', $workspaceId)
            ->where('id', $conversationId)
            ->firstOrFail();

        $messages = MeetingMessage::where('meeting_id', $meeting->id)
            ->orderBy('created_at')
            ->get()
            ->map(function ($msg) use ($meeting) {
                $agentSlug = null;
                if ($msg->sender_type === 'agent') {
                    $agent = Agent::find($msg->sender_id);
                    $agentSlug = $agent?->slug;
                }

                return [
                    'id' => (string) $msg->id,
                    'conversationId' => (string) $meeting->id,
                    'type' => $msg->sender_type === 'user' ? 'user' : 'agent_reply',
                    'senderId' => $msg->sender_type === 'user' ? 'user' : ($agentSlug ?? 'sarah'),
                    'content' => $msg->message,
                    'timestamp' => $msg->created_at->toIso8601String(),
                    'attachments' => $msg->attachments_json,
                ];
            })
            ->toArray();

        return [
            'id' => (string) $meeting->id,
            'title' => $meeting->title,
            'messages' => $messages,
        ];
    }

    /**
     * Cursor-based event stream for APP888 polling.
     * Merges meeting_messages + task_events since cursor.
     */
    public function getEvents(int $workspaceId, int $userId, ?string $cursor = null, ?string $conversationId = null): array
    {
        $since = $cursor ? \Carbon\Carbon::parse($cursor) : now()->subMinutes(5);

        // Get user's meeting IDs
        $meetingIds = Meeting::where('workspace_id', $workspaceId)
            ->whereHas('participants', fn ($q) => $q->where('participant_type', 'user')->where('participant_id', $userId))
            ->pluck('id');

        if ($conversationId) {
            $meetingIds = $meetingIds->filter(fn ($id) => $id == $conversationId)->values();
        }

        $events = collect();

        // 1. New meeting messages (agent replies) since cursor
        $newMessages = MeetingMessage::whereIn('meeting_id', $meetingIds)
            ->where('sender_type', '!=', 'user') // Don't echo back user messages
            ->where('created_at', '>', $since)
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        foreach ($newMessages as $msg) {
            $agent = $msg->sender_type === 'agent' ? Agent::find($msg->sender_id) : null;
            $events->push([
                'id' => 'msg_' . $msg->id,
                'type' => 'message',
                'conversation_id' => (string) $msg->meeting_id,
                'agent_id' => $agent?->slug,
                'content' => $msg->message,
                'timestamp' => $msg->created_at->toIso8601String(),
                'data' => [],
            ]);
        }

        // 2. Task events from tasks linked to these meetings
        $taskIds = \DB::table('meeting_tasks')
            ->whereIn('meeting_id', $meetingIds)
            ->pluck('task_id');

        if ($taskIds->isNotEmpty()) {
            $taskEvents = TaskEvent::whereIn('task_id', $taskIds)
                ->where('created_at', '>', $since)
                ->orderBy('created_at')
                ->limit(50)
                ->get();

            foreach ($taskEvents as $te) {
                $task = Task::find($te->task_id);
                $convId = \DB::table('meeting_tasks')
                    ->where('task_id', $te->task_id)
                    ->whereIn('meeting_id', $meetingIds)
                    ->value('meeting_id');

                $eventType = $this->mapTaskEventType($te->event);

                $events->push([
                    'id' => 'te_' . $te->id,
                    'type' => $eventType,
                    'conversation_id' => (string) ($convId ?? ''),
                    'task_id' => (string) $te->task_id,
                    'agent_id' => $task?->assigned_agents_json[0] ?? null,
                    'content' => $te->message,
                    'timestamp' => $te->created_at->toIso8601String(),
                    'data' => array_merge($te->data_json ?? [], [
                        'title' => $task?->action,
                        'status' => $task?->status,
                        'progress' => $task?->total_steps > 0
                            ? round(($task->current_step / $task->total_steps) * 100)
                            : 0,
                        'assigned_agents' => $task?->assigned_agents_json ?? [],
                        'created_at' => $task?->created_at?->toIso8601String(),
                        'completed_at' => $task?->completed_at?->toIso8601String(),
                    ]),
                ]);
            }
        }

        // Sort by timestamp and compute new cursor
        $sorted = $events->sortBy('timestamp')->values();
        $newCursor = $sorted->isNotEmpty()
            ? $sorted->last()['timestamp']
            : ($cursor ?? now()->toIso8601String());

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

    private function resolveEngine(string $agentSlug): string
    {
        return match ($agentSlug) {
            'james', 'alex' => 'seo',
            'priya' => 'content',
            'marcus' => 'social',
            'elena' => 'marketing',
            'sarah' => 'crm',
            default => 'crm',
        };
    }

    private function resolveAction(string $agentSlug, string $content): string
    {
        // Simple keyword-based resolution — upgrade with LLM later
        $lower = strtolower($content);

        if (str_contains($lower, 'lead') || str_contains($lower, 'crm')) return 'create_lead';
        if (str_contains($lower, 'post') || str_contains($lower, 'blog')) return 'create_post';
        if (str_contains($lower, 'seo') || str_contains($lower, 'audit')) return 'update_seo';
        if (str_contains($lower, 'image') || str_contains($lower, 'design')) return 'generate_image';
        if (str_contains($lower, 'video')) return 'generate_video';
        if (str_contains($lower, 'email') || str_contains($lower, 'send')) return 'send_email';
        if (str_contains($lower, 'social') || str_contains($lower, 'instagram')) return 'social_create_post';

        return 'create_lead'; // Safe default
    }

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
