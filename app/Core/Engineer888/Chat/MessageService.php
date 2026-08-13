<?php

namespace App\Core\Engineer888\Chat;

use App\Core\Engineer888\Access\Engineer888Capability as Cap;
use App\Core\Engineer888\Conversation\ConversationEngine;
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
            //
            // The REFUSAL IS DETERMINISTIC AND UNCHANGED: this branch is chosen
            // by the keyword router before any model is consulted, and nothing
            // in it can create, approve or execute. What the model is allowed to
            // do is phrase the answer against real state — "the candidate is
            // ready and waiting on your approval" reads like a colleague;
            // "I will not execute from a chat message" reads like a policy
            // notice, and Boss has had four screens of policy notices.
            if (! $this->router->isSafeForFreeText($routed['intent'])) {
                $reply = $this->spokenRefusal($conversation, $body, $routed['blocked_action'])
                      ?? $this->refusalFor($routed['blocked_action']);

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
        // ── LLM FIRST (2026-08-13) ───────────────────────────────────────
        //
        // Every safe turn goes to the conversational provider. The keyword
        // router keeps sole authority over HIGH-RISK classification — that
        // decision is made before this method is reached and is not delegated
        // to a model — but it no longer decides what an ordinary sentence
        // MEANS.
        //
        // WHY THE ROUTER'S OWN CREATE_TASK IS NOW ONLY A HINT. Measured
        // 2026-08-13: "Why is the Bug Tracker waiting for me?" matched the
        // Tier-3 pattern /\bwhy\s+(is|are|does|do|did)\b/ and created an
        // engineering task and dispatched a workflow job. Asking a question
        // filed work. A question is not an instruction, and no list of verbs
        // was ever going to know the difference reliably.
        //
        // So the model decides whether work was requested, and says so on a
        // single advisory line. It cannot create anything: the task is still
        // opened here, through the same WorkflowEngine path, with the same
        // capability check and the same project requirement.
        $engine = ConversationEngine::make();

        if ($engine->available()) {
            $reply = $engine->respond($conversation, $body, $this->situationFor($intent));

            if (! $reply->failed()) {
                // ── THE MODEL MAY ESCALATE TO GOVERNANCE, NEVER AWAY FROM IT ──
                //
                // Measured 2026-08-13: "Execute the Bug Tracker." was not
                // matched by HIGH_RISK_COMMANDS, because that table requires
                // the verb to land on one of {task, candidate, change,
                // implementation} and "the Bug Tracker" is none of them.
                // Nothing executed — free text has no execution path at all —
                // but Boss got a conversational answer where he should have
                // been handed the governed decision.
                //
                // Widening the regex would have been one synonym behind
                // forever, which is the failure Sarah's ActionAuthority
                // documents. So the model is allowed to say "he is asking to
                // execute", and that claim is ONLY ever able to make the turn
                // MORE governed: it produces a refusal and surfaces the card.
                // It cannot approve, execute, or create anything. A model that
                // is wrong here costs Boss one unnecessary explanation; a
                // regex that is wrong here costs him the ability to act.
                if (in_array($reply->proposedIntent, ['EXECUTE_REQUEST', 'APPROVE_REQUEST'], true)) {
                    return [$reply->text, [
                        'kind' => 'governed',
                        'blocked_action' => $reply->proposedIntent === 'EXECUTE_REQUEST'
                            ? 'EXECUTE_TASK' : 'APPROVE_CANDIDATE',
                        'escalated_by' => 'conversation',
                    ], null];
                }

                // CREATE_TASK is proposed to the server, not to the repository.
                if ($reply->proposedIntent === 'CREATE_TASK') {
                    [$text, $meta, $uuid] = $this->createTask($request, $conversation, $body);

                    // The model's sentence leads; the engine's record follows.
                    // Narration never replaces the record — it introduces it.
                    return [trim($reply->text) . "\n\n" . $text, $meta + ['spoken' => true], $uuid];
                }

                return [$reply->text, ['kind' => 'conversation', 'provider' => $reply->provider], null];
            }

            \Illuminate\Support\Facades\Log::warning('[engineer888] conversation unavailable', [
                'provider' => $reply->provider, 'error' => $reply->error,
            ]);
        }

        // ── DETERMINISTIC FALLBACK ───────────────────────────────────────
        // Reasoning is unreachable. Answer from records where a record exists,
        // and otherwise say plainly that analysis is not available. Never a
        // fabricated sentence about engineering state.
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

        return [ConversationEngine::unavailableText(), ['kind' => 'unavailable'], null];
    }

    /**
     * Let the model phrase a refusal that has already been decided.
     *
     * THE DECISION IS NOT THE MODEL'S. This is only reached after the keyword
     * router has classified the turn as high-risk, and the branch that calls it
     * creates nothing regardless of what comes back. If the provider is
     * unavailable, or returns nothing, the caller falls back to the fixed
     * refusal text — the refusal never depends on a model being reachable.
     *
     * The model is explicitly forbidden from implying the action happened.
     */
    private function spokenRefusal(object $conversation, string $body, ?string $action): ?string
    {
        $engine = ConversationEngine::make();

        if (! $engine->available()) { return null; }

        $named = match ($action) {
            'APPROVE_CANDIDATE' => 'approve a candidate',
            'EXECUTE_TASK'      => 'execute an approved candidate',
            'APPROVE_RECOVERY'  => 'approve a recovery',
            'APPROVE_MIGRATION' => 'approve a migration',
            'GRANT_ACCESS'      => 'change who may use Engineer888',
            default             => 'take a governed action',
        };

        $situation = "SITUATION: he is asking you to {$named}. That cannot happen from a message, and it "
            . "has NOT happened. Do not refuse formally and do not lecture him about governance. In one or "
            . "two sentences, tell him the real current state of the thing he named, and that the decision "
            . "is his to make on the card in this conversation. Never imply the action was taken or queued. "
            . "Do not emit an intent line.";

        $reply = $engine->respond($conversation, $body, $situation);

        if ($reply->failed() || trim($reply->text) === '') { return null; }

        // A model that talks itself into claiming it acted is not usable here.
        if (preg_match('/\b(i have (approved|executed|deployed|installed)|done|executed it|approved it)\b/i', $reply->text)) {
            return null;
        }

        return trim($reply->text);
    }

    /**
     * A deterministic note the model is told before it answers.
     *
     * The router's reading of the turn is evidence, not instruction. Passing it
     * as a situation lets the model use it ("he does seem to be asking for
     * work") without being bound by it ("...but he is actually asking why").
     */
    private function situationFor(string $intent): string
    {
        return match ($intent) {
            IntentRouter::CREATE_TASK =>
                "SITUATION: the phrasing of this turn resembles a request to start engineering work. "
                . "Treat that as a hint only. If he is asking a question or thinking aloud, answer him; "
                . "do not open work.",
            IntentRouter::TASK_STATUS, IntentRouter::TASK_BLOCKER =>
                "SITUATION: he appears to be asking about the state of work in progress.",
            IntentRouter::PROJECT_STATUS =>
                "SITUATION: he appears to be asking about projects.",
            IntentRouter::REVIEW_CANDIDATE =>
                "SITUATION: he appears to be asking to see a proposed change. You may describe it; "
                . "the diff itself opens from the card in the conversation.",
            default => '',
        };
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
