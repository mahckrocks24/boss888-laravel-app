<?php

namespace App\Core\Engineer888\Chat;

use App\Core\Engineer888\Access\Engineer888Capability as Cap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Messages, and the one place free text turns into engineering work.
 *
 * ATOMICITY. A user message, the routed intent, any task it created and any card
 * it issued commit together or not at all. A conversation showing "task created"
 * beside no task — or a card with no message to explain it — is worse than an
 * error, because it looks like it worked.
 *
 * CHAT IS NOT WORKFLOW TRUTH. Nothing here caches a task's status. Every status
 * shown is read from engineering_tasks at the moment it is rendered, so a
 * conversation reopened tomorrow describes the platform as it is tomorrow.
 */
final class MessageService
{
    public function __construct(
        private ConversationService $conversations,
        private IntentRouter $router,
        private ActionCardService $cards,
        private ProjectSelectionService $projects,
    ) {}

    /** History, oldest first, owner-scoped at the query. */
    public function history(?Request $request, string $conversationUuid, ?int $afterId = null, int $limit = 200): array
    {
        $conversation = $this->conversations->byUuid($request, $conversationUuid);

        $q = ChatOwner::scope(
            DB::table('e888_messages')->where('conversation_id', $conversation->id)
        );

        if ($afterId !== null) {
            $q->where('id', '>', $afterId);
        }

        $rows = $q->orderBy('id')->limit(min($limit, 500))->get();

        return [
            'conversation' => ['uuid' => $conversation->uuid, 'title' => $conversation->title],
            'messages' => $rows->map(fn ($m) => $this->present($m))->all(),
            'cursor' => $rows->last()->id ?? $afterId,
        ];
    }

    /**
     * The administrator says something. Everything that follows is one commit.
     *
     * @return array{messages:array, cards:array, intent:string}
     */
    public function post(?Request $request, string $conversationUuid, string $body): array
    {
        $owner = ChatOwner::resolve($request);

        if ($owner === null) {
            ChatAbsent::throw('message post by a non-canonical identity');
        }

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 20000) {
            ChatAbsent::throw('message body empty or over length');
        }

        $conversation = $this->conversations->byUuid($request, $conversationUuid);
        $routed = $this->router->route($body);

        return DB::transaction(function () use ($request, $conversation, $body, $routed) {
            $userMessageId = $this->insert($conversation, 'user', $body, $routed['intent']);

            // High-risk text is answered, never obeyed.
            if (! $this->router->isSafeForFreeText($routed['intent'])) {
                $reply = $this->refusalFor($routed['blocked_action']);
                $replyId = $this->insert($conversation, 'engineer888', $reply, $routed['intent'], [
                    'blocked_action' => $routed['blocked_action'],
                    'matched_phrase' => $routed['matched'],
                ]);

                $this->touch($conversation);

                return [
                    'intent' => $routed['intent'],
                    'blocked_action' => $routed['blocked_action'],
                    'messages' => $this->reload($conversation, [$userMessageId, $replyId]),
                    'cards' => [],
                ];
            }

            [$reply, $meta, $taskUuid] = $this->answer($request, $conversation, $routed['intent'], $body);

            $replyId = $this->insert($conversation, 'engineer888', $reply, $routed['intent'], $meta, $taskUuid);
            $this->touch($conversation);

            return [
                'intent' => $routed['intent'],
                'blocked_action' => null,
                'messages' => $this->reload($conversation, [$userMessageId, $replyId]),
                'cards' => [],
            ];
        });
    }

    /**
     * Answer a safe intent.
     *
     * Task creation delegates to the Workflow Engine. This method never writes
     * an engineering record itself — it asks the engine and reports what it did.
     *
     * @return array{0:string,1:array,2:?string}
     */
    private function answer(?Request $request, object $conversation, string $intent, string $body): array
    {
        if ($intent === IntentRouter::CREATE_TASK) {
            return $this->createTask($request, $conversation, $body);
        }

        if ($intent === IntentRouter::TASK_STATUS || $intent === IntentRouter::TASK_BLOCKER) {
            return [$this->statusSummary(), ['kind' => 'status'], null];
        }

        if ($intent === IntentRouter::PROJECT_STATUS) {
            $projects = DB::table('engineering_projects')->get(['key', 'name']);
            $lines = $projects->map(fn ($p) => "• {$p->name} ({$p->key})")->implode("\n");

            return ["Projects I can work in:\n\n{$lines}", ['kind' => 'projects'], null];
        }

        return [
            "I can create an engineering task, report status, explain a blocker, or open a "
            . "candidate for review.\n\nI will not approve, execute, or recover from a chat "
            . "message — those need a secure action card.",
            ['kind' => 'general'],
            null,
        ];
    }

    /**
     * Create a real engineering task through the Workflow Engine.
     *
     * The capability is re-checked here even though the route already gated it:
     * this method is reachable from two surfaces and will be reachable from more.
     */
    private function createTask(?Request $request, object $conversation, string $body): array
    {
        $access = new \App\Core\Engineer888\Access\Engineer888Access();

        if (! $access->allows($request, Cap::CREATE_TASK)) {
            ChatAbsent::throw('create_task without capability');
        }

        // THE PROJECT MUST HAVE BEEN CHOSEN. Never the first database row:
        // ordering is not intent, and a wrong guess files work against the
        // wrong repository. Re-read the conversation so a selection made on
        // another surface a moment ago is honoured.
        $fresh = ChatOwner::scope(
            DB::table('e888_conversations')->where('id', $conversation->id)
        )->first();

        $project = $fresh === null ? null : $this->projects->active($fresh);

        if ($project === null) {
            return [
                $this->projects->selectionRequiredMessage(),
                [
                    'kind' => 'project_selection_required',
                    'code' => ProjectSelectionService::REQUIRED,
                    'projects' => $this->projects->registry(),
                ],
                null,
            ];
        }

        $engine = new \App\Core\Engineer888\Workflow\WorkflowEngine();
        $project = (object) $project;

        $title = Str::limit(trim(preg_replace('/\s+/', ' ', $body)), 120, '');

        // The engine resolves by KEY through its own project registry, so the
        // task is bound to the project that was chosen — not to an id this
        // layer happened to be holding.
        $task = $engine->createTask($project->key, [
            'title' => $title,
            'description' => $body,
            'kind' => 'investigation',
            'priority' => 'normal',
            'requested_by' => 'chat:' . ChatOwner::USER_ID,
        ]);

        $uuid = is_object($task) ? ($task->uuid ?? null) : ($task['uuid'] ?? null);

        if ($uuid === null) {
            return ['I could not create the task; the engine returned no identifier.', ['kind' => 'error'], null];
        }

        // Read back rather than trusting what was passed in.
        $row = DB::table('engineering_tasks')->where('uuid', $uuid)->first();

        $text = "Task created.\n\n"
            . "Project:\n{$project->name}\n\n"
            . "Current stage:\n" . strtoupper((string) ($row->current_stage ?? 'ANALYZE')) . "\n\n"
            . "What I am doing:\nTracing the request through its execution chain before proposing any change.\n\n"
            . "Writes made:\nNone\n\n"
            . "Approval required:\nNot yet\n\n"
            . "I will return when the chain is proven or blocked.";

        // The project is recorded on the message as well as on the task. A card
        // rendered tomorrow shows the project the work was FILED against, even
        // if the conversation has since switched to another one.
        return [$text, [
            'kind' => 'task_created',
            'task_uuid' => $uuid,
            'project_key' => $project->key,
            'project_name' => $project->name,
            'project_id' => $project->id,
        ], $uuid];
    }

    /** Status, always read fresh from the engineering records. */
    private function statusSummary(): string
    {
        $rows = DB::table('engineering_tasks')->orderByDesc('id')->limit(5)
            ->get(['uuid', 'title', 'current_stage', 'status']);

        if ($rows->isEmpty()) {
            return 'There are no engineering tasks yet.';
        }

        $lines = $rows->map(function ($t) {
            return '• ' . Str::limit($t->title, 60)
                . "\n  stage " . strtoupper((string) $t->current_stage)
                . ' · status ' . $t->status
                . "\n  " . substr($t->uuid, 0, 8);
        })->implode("\n\n");

        return "Most recent engineering tasks:\n\n{$lines}";
    }

    private function insert(object $conversation, string $role, string $body, string $intent, array $meta = [], ?string $taskUuid = null): int
    {
        return (int) DB::table('e888_messages')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'owner_user_id' => ChatOwner::USER_ID,
            'role' => $role,
            'body' => $body,
            'intent' => $intent,
            'task_uuid' => $taskUuid,
            'metadata_json' => $meta === [] ? null : json_encode($meta),
            'read_at' => $role === 'user' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function refusalFor(?string $action): string
    {
        $name = match ($action) {
            'APPROVE_CANDIDATE' => 'approve a candidate',
            'EXECUTE_TASK' => 'execute a task',
            'APPROVE_RECOVERY' => 'approve a recovery',
            'APPROVE_MIGRATION' => 'approve a migration',
            'GRANT_ACCESS' => 'change access',
            default => 'perform that action',
        };

        return "I will not {$name} from a chat message.\n\n"
            . "A typed sentence is not a binding: it names no exact content, no workflow "
            . "state, and no session. If the proposal changed between your reading it and "
            . "your reply, the words would still say yes.\n\n"
            . "Approval happens on a secure action card, which binds your identity to an "
            . "exact fingerprint and is revalidated when you press it. Ask me to open the "
            . "candidate and I will issue one.";
    }

    private function touch(object $conversation): void
    {
        ChatOwner::scope(DB::table('e888_conversations')->where('id', $conversation->id))
            ->update(['last_message_at' => now(), 'updated_at' => now()]);
    }

    private function reload(object $conversation, array $ids): array
    {
        return ChatOwner::scope(
            DB::table('e888_messages')->where('conversation_id', $conversation->id)
        )->whereIn('id', $ids)->orderBy('id')->get()
            ->map(fn ($m) => $this->present($m))->all();
    }

    private function present(object $m): array
    {
        return [
            'uuid' => $m->uuid,
            'role' => $m->role,
            'body' => $m->body,
            'intent' => $m->intent,
            'task_uuid' => $m->task_uuid,
            'candidate_uuid' => $m->candidate_uuid,
            'metadata' => $m->metadata_json ? json_decode($m->metadata_json, true) : null,
            'created_at' => $m->created_at,
            'id' => $m->id,
        ];
    }
}
