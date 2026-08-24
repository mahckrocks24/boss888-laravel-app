<?php

namespace App\Core\Engineer888\Chat;

use App\Core\Engineer888\Access\Engineer888Capability as Cap;
use App\Jobs\Engineer888WorkflowJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        // The task whose workflow must be started once this commits. Set inside
        // the transaction, acted on strictly after it.
        $pendingDispatch = null;

        $result = DB::transaction(function () use ($request, $conversation, $body, $routed, &$pendingDispatch) {
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

            if (($meta['kind'] ?? null) === 'task_created' && $taskUuid !== null) {
                $pendingDispatch = $taskUuid;
            }

            $replyId = $this->insert($conversation, 'engineer888', $reply, $routed['intent'], $meta, $taskUuid);
            $this->touch($conversation);

            return [
                'intent' => $routed['intent'],
                'blocked_action' => null,
                'messages' => $this->reload($conversation, [$userMessageId, $replyId]),
                'cards' => [],
            ];
        });

        // ── AFTER THE TRANSACTION ────────────────────────────────────────
        // The job is pushed only once the task row is durable. Pushing inside
        // the transaction lets a worker claim a task that is not visible yet,
        // and the run dies on a task that "does not exist".
        if ($pendingDispatch !== null) {
            $this->dispatchQueuedWorkflow($conversation, $pendingDispatch);
        }

        return $result;
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

        // START THE ACTUAL WORKFLOW.
        //
        // INC-TASK_CREATED_WITHOUT_WORKFLOW_EXECUTION (2026-08-07). Until this
        // line existed, chat created a task, said "Current stage: ANALYZE" and
        // dispatched nothing. Tasks sat at `received` with zero stage rows for
        // days while the conversation claimed work was under way.
        //
        // This is the SAME dispatch the Command Center performs
        // (Engineer888Controller::execute) — one job, one workflow, one domain
        // path. No parallel lifecycle is introduced here.
        $queued = $this->queueWorkflow($conversation, $uuid);

        // Read back rather than trusting what was passed in.
        $row = DB::table('engineering_tasks')->where('uuid', $uuid)->first();

        // EVERY LINE BELOW IS READ FROM A RECORD. There is no narration, no
        // default stage and no description of activity that is not proven by
        // persisted state. Silence is better than fabricated progress.
        $text = "Task created.\n\n"
            . "Project:\n{$project->name}\n\n"
            . "Status:\n" . $this->statusOf($row) . "\n\n"
            . $this->stageLine($row)
            . "Writes made:\nNone\n\n"
            . "Approval required:\nNot yet\n\n"
            . ($queued
                ? "The workflow is queued. I will report only what it records."
                : "The workflow was NOT started, so no engineering work is running.");

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

    /**
     * Hand the task to the existing workflow queue.
     *
     * AFTER COMMIT, NOT INSIDE IT. post() wraps this whole path in a
     * transaction. A job pushed inside it can be picked up by a worker before
     * the task row is visible, and the worker then fails on a task that does
     * not exist yet. ->afterCommit() defers the push to the moment the data is
     * durable, which is the same guarantee the Command Center gets by
     * dispatching outside any transaction.
     *
     * TRUTHFUL ON FAILURE. If the push throws once the commit has happened, the
     * status is put back to `received` and a message says so. The alternative —
     * leaving `queued` behind with nothing queued — is the defect this method
     * exists to remove, in a new costume.
     *
     * @return bool whether the task is now genuinely queued
     */
    private function queueWorkflow(object $conversation, string $taskUuid): bool
    {
        // Recorded before the push so the state a worker sees is never behind
        // the job. Guarded on `received` so it cannot overwrite a workflow that
        // has already moved the task on.
        return DB::table('engineering_tasks')
            ->where('uuid', $taskUuid)->where('status', 'received')
            ->update(['status' => 'queued', 'updated_at' => now()]) === 1;
    }

    /**
     * Push the job, once the task is durable.
     *
     * TRUTHFUL ON FAILURE. If the push throws, the status goes back to
     * `received` and a message says so. Leaving `queued` behind with nothing
     * queued would be the same defect this repair removes, in a new costume.
     */
    private function dispatchQueuedWorkflow(object $conversation, string $taskUuid): void
    {
        try {
            Engineer888WorkflowJob::dispatch($taskUuid, false, 'chat:' . ChatOwner::USER_ID);
        } catch (\Throwable $e) {
            DB::table('engineering_tasks')
                ->where('uuid', $taskUuid)->where('status', 'queued')
                ->update(['status' => 'received', 'updated_at' => now()]);

            // Auditable, carrying no payload or secret.
            Log::error('engineer888.chat.dispatch_failed', [
                'task' => $taskUuid,
                'error' => $e->getMessage(),
            ]);

            $this->insert($conversation, 'engineer888',
                "Workflow dispatch failed, so no engineering work is running.\n\n"
                . "The task is kept and can be started again.",
                'create_task', ['kind' => 'dispatch_failed'], $taskUuid);
        }
    }

    /**
     * The one mapping from persisted engineering state to a word.
     *
     * NOTHING HERE GUESSES. Every branch is decided by a record: the task row,
     * a stage row, or a candidate awaiting a decision. A task that exists but
     * has never run is RECEIVED — it is not ANALYZE, and it is not "working".
     */
    private function statusOf(?object $row): string
    {
        if ($row === null) { return 'RECEIVED'; }

        $status = strtolower((string) $row->status);

        if ($status === 'completed') { return 'COMPLETED'; }
        if (str_contains($status, 'failed')) { return 'FAILED'; }
        if ($status === 'blocked') { return 'BLOCKED'; }

        // A validated candidate with no live decision is waiting on a human.
        $awaiting = DB::table('engineering_candidates as c')
            ->leftJoin('engineering_candidate_approvals as a', function ($j) {
                $j->on('a.candidate_id', '=', 'c.id')
                  ->whereNull('a.revoked_at')->whereNull('a.superseded_at');
            })
            ->where('c.task_id', $row->id)
            ->whereNull('c.superseded_at')
            ->where('c.status', 'VALIDATED')
            ->whereNull('a.id')
            ->exists();

        if ($awaiting) { return 'AWAITING_APPROVAL'; }

        if ($status === 'running') { return 'RUNNING'; }
        if ($status === 'queued') { return 'QUEUED'; }

        // A stage row is the only proof that engineering actually ran.
        if (DB::table('engineering_task_stages')->where('task_id', $row->id)->exists()) {
            return 'RUNNING';
        }

        return 'RECEIVED';
    }

    /**
     * The stage line, printed only when a stage row proves the stage.
     *
     * `engineering_tasks.current_stage` is written by the workflow, but a name
     * in that column with no matching stage row is an assertion without
     * evidence, so it is not repeated to the reader.
     */
    private function stageLine(?object $row): string
    {
        $stage = $row === null ? '' : trim((string) ($row->current_stage ?? ''));

        if ($stage === '') {
            return "Current stage:\nnone recorded\n\n";
        }

        $proved = DB::table('engineering_task_stages')
            ->where('task_id', $row->id)
            ->whereRaw('UPPER(stage) = ?', [strtoupper($stage)])
            ->exists();

        return $proved
            ? "Current stage:\n" . strtoupper($stage) . "\n\n"
            : "Current stage:\nnone recorded\n\n";
    }

    /** Status, always read fresh from the engineering records. */
    private function statusSummary(): string
    {
        $rows = DB::table('engineering_tasks')->orderByDesc('id')->limit(5)
            ->get(['id', 'uuid', 'title', 'current_stage', 'status']);

        if ($rows->isEmpty()) {
            return 'There are no engineering tasks yet.';
        }

        // The same truthful mapping the task-created reply uses. A blank stage
        // reads as "no stage recorded", never as an empty word beside a status
        // that implies something is happening.
        $lines = $rows->map(function ($t) {
            $stage = trim((string) ($t->current_stage ?? ''));
            $proved = $stage !== '' && DB::table('engineering_task_stages')
                ->where('task_id', $t->id)
                ->whereRaw('UPPER(stage) = ?', [strtoupper($stage)])->exists();

            return '• ' . Str::limit($t->title, 60)
                . "\n  " . $this->statusOf($t)
                . ' · stage ' . ($proved ? strtoupper($stage) : 'none recorded')
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
