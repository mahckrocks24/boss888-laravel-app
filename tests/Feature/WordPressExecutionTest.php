<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\Boss888TestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\AuditLog;
use App\Models\CreditTransaction;
use App\Core\TaskSystem\Orchestrator;
use App\Services\IdempotencyService;
use App\Services\ConnectorCircuitBreakerService;

class WordPressExecutionTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function create_post_success_full_pipeline()
    {
        Http::fake([
            '*/pluginconnector888/v1/posts' => Http::response([
                'id' => 42,
                'link' => 'https://staging1.shukranuae.com/test-post/',
                'status' => 'draft',
            ], 200),
        ]);

        $task = $this->createTask([
            'engine' => 'content',
            'action' => 'create_post',
            'payload_json' => ['title' => 'Test Post', 'content' => '<p>Hello</p>', 'status' => 'draft'],
            'credit_cost' => 5,
        ]);

        $orchestrator = app(Orchestrator::class);
        $orchestrator->execute($task);

        $task->refresh();

        // Task completed
        $this->assertEquals('completed', $task->status);
        $this->assertNotNull($task->result_json);
        $this->assertEquals(42, $task->result_json['data']['post_id'] ?? null);

        // Credits committed (reserved → committed)
        $commits = CreditTransaction::where('workspace_id', $this->testWorkspace->id)
            ->where('type', 'commit')->count();
        $this->assertEquals(1, $commits);

        // Audit log written
        $audit = AuditLog::where('workspace_id', $this->testWorkspace->id)
            ->where('action', 'task.executed')->first();
        $this->assertNotNull($audit);

        // Progress events recorded
        $events = TaskEvent::where('task_id', $task->id)->get();
        $this->assertTrue($events->count() >= 3); // started, step, completed

        // Credits deducted correctly
        $this->assertCreditBalance(5000 - 5);
        $this->assertReservedBalance(0);
    }

    /** @test */
    public function create_post_retry_on_transient_failure()
    {
        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            if (str_contains($request->url(), 'pluginconnector888/v1/posts')) {
                if ($callCount === 1) {
                    return Http::response(['error' => 'timeout'], 500);
                }
                return Http::response(['id' => 99, 'link' => 'https://example.com/post', 'status' => 'draft'], 200);
            }
            return Http::response([], 200);
        });

        $task = $this->createTask([
            'engine' => 'content',
            'action' => 'create_post',
            'payload_json' => ['title' => 'Retry Test', 'content' => '<p>Test</p>'],
            'credit_cost' => 5,
        ]);

        app(Orchestrator::class)->execute($task);
        $task->refresh();

        $this->assertEquals('completed', $task->status);
        $this->assertCreditBalance(5000 - 5);

        // Only one commit (not two)
        $commits = CreditTransaction::where('type', 'commit')
            ->where('workspace_id', $this->testWorkspace->id)->count();
        $this->assertEquals(1, $commits);
    }

    /** @test */
    public function duplicate_submission_returns_cached_result()
    {
        $idemKey = hash('sha256', "{$this->testWorkspace->id}:create_post:" .
            json_encode(['title' => 'Dup Test', 'content' => '<p>X</p>'], JSON_SORT_KEYS));

        // Pre-create a completed task with this key
        $existing = $this->createTask([
            'engine' => 'content',
            'action' => 'create_post',
            'payload_json' => ['title' => 'Dup Test', 'content' => '<p>X</p>'],
            'status' => 'completed',
            'idempotency_key' => $idemKey,
            'result_json' => ['success' => true, 'data' => ['post_id' => 77]],
            'completed_at' => now(),
            'credit_cost' => 5,
        ]);

        // Create a second task with the same key
        $duplicate = $this->createTask([
            'engine' => 'content',
            'action' => 'create_post',
            'payload_json' => ['title' => 'Dup Test', 'content' => '<p>X</p>'],
            'idempotency_key' => $idemKey,
            'credit_cost' => 5,
        ]);

        Http::fake(); // Should NOT be called

        app(Orchestrator::class)->execute($duplicate);
        $duplicate->refresh();

        $this->assertEquals('completed', $duplicate->status);

        // No HTTP calls made
        Http::assertNothingSent();

        // No additional credit transactions
        $commits = CreditTransaction::where('type', 'commit')
            ->where('workspace_id', $this->testWorkspace->id)->count();
        $this->assertEquals(0, $commits);
    }

    /** @test */
    public function verification_failure_marks_task_failed_and_releases_credits()
    {
        Http::fake([
            '*/pluginconnector888/v1/posts' => Http::response([
                'status' => 'ok',
                // Missing 'id' — verification should fail
            ], 200),
        ]);

        $task = $this->createTask([
            'engine' => 'content',
            'action' => 'create_post',
            'payload_json' => ['title' => 'Verify Fail', 'content' => '<p>X</p>'],
            'credit_cost' => 5,
        ]);

        app(Orchestrator::class)->execute($task);
        $task->refresh();

        $this->assertEquals('failed', $task->status);
        $this->assertStringContainsString('Verification failed', $task->error_text ?? '');

        // Credits released, not committed
        $this->assertCreditBalance(5000);
        $this->assertReservedBalance(0);

        $releases = CreditTransaction::where('type', 'release')
            ->where('workspace_id', $this->testWorkspace->id)->count();
        $this->assertGreaterThanOrEqual(1, $releases);
    }

    /** @test */
    public function circuit_breaker_blocks_execution_after_threshold()
    {
        $cb = app(ConnectorCircuitBreakerService::class);
        $threshold = config('execution.circuit_breaker.wordpress.failure_threshold',
            config('execution.circuit_breaker.default.failure_threshold', 5));

        for ($i = 0; $i < $threshold; $i++) {
            $cb->recordFailure('wordpress');
        }

        $task = $this->createTask([
            'engine' => 'content',
            'action' => 'create_post',
            'payload_json' => ['title' => 'Blocked', 'content' => '<p>X</p>'],
            'credit_cost' => 5,
        ]);

        Http::fake(); // Should NOT be called

        app(Orchestrator::class)->execute($task);
        $task->refresh();

        $this->assertEquals('degraded', $task->status);
        Http::assertNothingSent();

        // No credits consumed
        $this->assertCreditBalance(5000);
        $this->assertReservedBalance(0);
    }
}
