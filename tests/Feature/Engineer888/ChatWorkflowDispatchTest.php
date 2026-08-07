<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Chat\ConversationService;
use App\Core\Engineer888\Chat\MessageService;
use App\Jobs\Engineer888WorkflowJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * INC-TASK_CREATED_WITHOUT_WORKFLOW_EXECUTION — permanent regression.
 *
 * On 2026-08-07 an operator asked Engineer888 in chat to investigate something.
 * Chat created the task, replied "Current stage: ANALYZE / Tracing the request
 * through its execution chain / I will return when the chain is proven or
 * blocked" — and dispatched nothing. current_stage was NULL, there were no
 * stage rows, no queued job and no candidate. Tasks from two days earlier sat
 * in exactly the same state.
 *
 * Two claims are defended here, permanently:
 *
 *   1. Asking in chat STARTS the real workflow — the same job the Command
 *      Center dispatches, not a parallel one.
 *   2. Chat never describes engineering activity that persisted state does not
 *      prove. A task row is not proof. A message is not proof. Only a stage
 *      row, a status, or a candidate is proof.
 */
class ChatWorkflowDispatchTest extends TestCase
{
    use RefreshDatabase;

    private object $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $this->seedCanonical();
        DB::table('engineering_projects')->insert([
            'company' => 'LevelUp Growth', 'key' => 'fixture-project', 'name' => 'Fixture Project',
            'repository_path' => '/tmp/fixture', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->conversation = app(ConversationService::class)->forOwner($this->req());
        app(\App\Core\Engineer888\Chat\ProjectSelectionService::class)
            ->select($this->req(), $this->conversation, 'fixture-project');
        $this->conversation = app(ConversationService::class)->forOwner($this->req());
    }

    // ── the dispatch itself ──────────────────────────────────────────

    public function test_a_chat_created_task_dispatches_exactly_one_workflow_job(): void
    {
        Queue::fake();

        $this->ask('Investigate why the reasoning provider sometimes returns nothing');

        Queue::assertPushed(Engineer888WorkflowJob::class, 1);

        $task = DB::table('engineering_tasks')->orderByDesc('id')->first();
        Queue::assertPushed(Engineer888WorkflowJob::class,
            fn ($job) => $job->taskUuid === $task->uuid);
    }

    public function test_one_chat_request_creates_exactly_one_task(): void
    {
        Queue::fake();

        $before = DB::table('engineering_tasks')->count();
        $this->ask('Investigate why the admin page renders slowly');

        $this->assertSame($before + 1, DB::table('engineering_tasks')->count());
        Queue::assertPushed(Engineer888WorkflowJob::class, 1);
    }

    public function test_two_requests_create_two_tasks_and_two_jobs_not_duplicates_of_one(): void
    {
        Queue::fake();

        $this->ask('Investigate the first unrelated problem');
        $this->ask('Investigate the second unrelated problem');

        $uuids = DB::table('engineering_tasks')->orderByDesc('id')->limit(2)->pluck('uuid')->all();
        $this->assertCount(2, array_unique($uuids), 'each request must own its task');
        Queue::assertPushed(Engineer888WorkflowJob::class, 2);
    }

    public function test_reading_the_conversation_never_dispatches(): void
    {
        Queue::fake();
        $this->ask('Investigate something worth a task');
        Queue::assertPushed(Engineer888WorkflowJob::class, 1);

        // Polling is what both surfaces do constantly. It must never start work.
        for ($i = 0; $i < 4; $i++) {
            app(MessageService::class)->history($this->req(), $this->conversation->uuid, null, 200);
        }

        Queue::assertPushed(Engineer888WorkflowJob::class, 1);
    }

    public function test_chat_dispatches_the_same_job_class_the_command_center_uses(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/Api/Admin/Engineer888Controller.php'));
        $this->assertStringContainsString('Engineer888WorkflowJob::dispatch', $controller,
            'the Command Center dispatch is the reference path');

        Queue::fake();
        $this->ask('Investigate a thing that needs a task');

        // Same class, therefore the same WorkflowEngine::run behind it.
        Queue::assertPushed(Engineer888WorkflowJob::class);
    }

    // ── truthfulness ─────────────────────────────────────────────────

    public function test_a_task_with_no_recorded_stage_is_never_reported_as_analyze(): void
    {
        Queue::fake();
        $reply = $this->ask('Investigate a problem that has no stage yet');

        $task = DB::table('engineering_tasks')->orderByDesc('id')->first();
        $this->assertNull($task->current_stage, 'fixture precondition: no stage recorded');
        $this->assertSame(0, DB::table('engineering_task_stages')->where('task_id', $task->id)->count());

        $this->assertStringNotContainsStringIgnoringCase('ANALYZE', $reply,
            'a NULL stage means NOT STARTED; it does not mean ANALYZE');
        $this->assertStringContainsString('none recorded', $reply);
    }

    public function test_no_fabricated_activity_narration_is_emitted(): void
    {
        Queue::fake();
        $reply = $this->ask('Investigate a problem to check the wording');

        foreach ([
            'Tracing the request through its execution chain',
            'I will return when the chain is proven or blocked',
            'What I am doing',
        ] as $banned) {
            $this->assertStringNotContainsString($banned, $reply,
                'chat must not describe activity that no record proves');
        }
    }

    public function test_a_queued_task_is_reported_as_queued(): void
    {
        Queue::fake();
        $reply = $this->ask('Investigate something so the workflow is queued');

        $task = DB::table('engineering_tasks')->orderByDesc('id')->first();
        $this->assertSame('queued', $task->status, 'the record must say queued');
        $this->assertStringContainsString('QUEUED', $reply);
    }

    public function test_a_persisted_analyze_stage_is_reported_as_analyze(): void
    {
        Queue::fake();
        $this->ask('Investigate something that will reach a stage');

        $task = DB::table('engineering_tasks')->orderByDesc('id')->first();

        // Exactly what a worker writes when the stage genuinely runs.
        DB::table('engineering_task_stages')->insert([
            'task_id' => $task->id, 'stage' => 'ANALYZE', 'position' => 3, 'status' => 'ok',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('engineering_tasks')->where('id', $task->id)
            ->update(['current_stage' => 'ANALYZE', 'status' => 'running']);

        // The router requires the word to be ABOUT a task — bare "status" is
        // deliberately not a status query.
        $status = $this->ask('task status');
        $this->assertStringContainsString('ANALYZE', $status,
            'a stage proven by a stage row must be reported');
        $this->assertStringContainsString('RUNNING', $status);
    }

    public function test_a_stage_column_with_no_stage_row_is_not_reported(): void
    {
        Queue::fake();
        $this->ask('Investigate something with an unproven stage');

        $task = DB::table('engineering_tasks')->orderByDesc('id')->first();
        // Column set, but nothing ever recorded a stage. Evidence beats claims.
        DB::table('engineering_tasks')->where('id', $task->id)->update(['current_stage' => 'IMPLEMENT']);

        // The router requires the word to be ABOUT a task — bare "status" is
        // deliberately not a status query.
        $status = $this->ask('task status');
        $this->assertStringNotContainsString('IMPLEMENT', $status);
        $this->assertStringContainsString('none recorded', $status);
    }

    public function test_a_dispatch_failure_is_reported_truthfully(): void
    {
        // A queue that cannot accept the push.
        config(['queue.default' => 'database']);
        config(['queue.connections.database' => [
            'driver' => 'database', 'table' => 'jobs_table_that_does_not_exist',
            'queue' => 'default', 'retry_after' => 90,
        ]]);

        $this->ask('Investigate something while the queue is broken');

        $task = DB::table('engineering_tasks')->orderByDesc('id')->first();
        $this->assertSame('received', $task->status,
            'a task whose job could not be pushed must not be left claiming queued');

        $last = DB::table('e888_messages')->orderByDesc('id')->first();
        $this->assertStringContainsString('dispatch failed', strtolower($last->body));
        $this->assertStringNotContainsStringIgnoringCase('ANALYZE', $last->body);
    }

    // ── safeguards that must survive the repair ──────────────────────

    public function test_project_selection_is_still_required(): void
    {
        Queue::fake();

        DB::table('e888_conversations')->where('id', $this->conversation->id)
            ->update(['active_project_id' => null]);
        $conv = app(ConversationService::class)->forOwner($this->req());

        $out = app(MessageService::class)->post($this->req(), $conv->uuid,
            'Investigate something with no project chosen');

        $reply = end($out['messages'])['body'];
        $this->assertStringContainsStringIgnoringCase('project', $reply);
        $this->assertSame(0, DB::table('engineering_tasks')->count(),
            'no project means no task');
        Queue::assertNothingPushed();
    }

    public function test_no_action_card_appears_before_a_candidate_requires_one(): void
    {
        Queue::fake();
        $this->ask('Investigate something that has produced no candidate');

        $task = DB::table('engineering_tasks')->orderByDesc('id')->first();
        $this->assertSame(0, DB::table('engineering_candidates')->where('task_id', $task->id)->count());
        $this->assertSame(0, DB::table('e888_action_cards')->where('task_uuid', $task->uuid)->count(),
            'a card is an authority; it may not exist before a decision is genuinely due');
        $this->assertSame(0, DB::table('engineering_candidate_approvals')->where('task_id', $task->id)->count());
    }

    // ── the provider must not silently be the null one ───────────────

    public function test_the_configured_provider_does_not_silently_resolve_to_null(): void
    {
        config(['engineer888_reasoning.provider' => 'openai']);

        $registry = \App\Core\Engineer888\Reasoning\ProviderRegistry::fromConfig();

        $this->assertSame('openai', $registry->defaultName());
        $this->assertNotInstanceOf(
            \App\Core\Engineer888\Reasoning\Providers\NullReasoningProvider::class,
            $registry->make(null),
            'a configured provider must never fall back to the one that proposes nothing'
        );
    }

    public function test_the_null_provider_is_still_the_default_when_nothing_is_chosen(): void
    {
        config(['engineer888_reasoning.provider' => 'null']);

        $this->assertInstanceOf(
            \App\Core\Engineer888\Reasoning\Providers\NullReasoningProvider::class,
            \App\Core\Engineer888\Reasoning\ProviderRegistry::fromConfig()->make(null),
            'an unconfigured environment must not start making paid calls'
        );
    }

    // ── fixtures ─────────────────────────────────────────────────────

    private function ask(string $body): string
    {
        $out = app(MessageService::class)->post($this->req(), $this->conversation->uuid, $body);

        return end($out['messages'])['body'];
    }

    private function req(int $id = 1): Request
    {
        $user = User::find($id);
        $r = Request::create('/api/admin/engineer888/chat/messages', 'GET');
        $r->attributes->set('auth_via', 'jwt_cookie');
        app()->instance('request', $r);
        $r->setUserResolver(fn () => $user);

        return $r;
    }

    private function seedCanonical(): void
    {
        $u = new User();
        $u->id = 1; $u->name = 'Mark'; $u->email = Engineer888Access::CANONICAL_EMAIL;
        $u->password = 'irrelevant'; $u->is_platform_admin = true; $u->status = 'active';
        $u->save();

        DB::table('engineering_access_grants')->where('user_id', 1)->delete();
        DB::table('engineering_access_grants')->insert([
            'user_id' => 1, 'email' => Engineer888Access::CANONICAL_EMAIL,
            'capabilities' => json_encode(Engineer888Access::capabilitiesForCanonicalAdmin()),
            'policy_version' => Engineer888Access::POLICY_VERSION,
            'granted_by' => 'fixture', 'granted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
