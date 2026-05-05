<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\Boss888TestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Models\Task;
use App\Models\CreditTransaction;
use App\Core\TaskSystem\Orchestrator;
use App\Services\IdempotencyService;

class IdempotencyStressTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function same_task_dispatched_five_times_only_executes_once()
    {
        Http::fake([
            '*/pluginconnector888/v1/posts' => Http::response([
                'id' => 200, 'link' => 'https://example.com/p', 'status' => 'draft',
            ], 200),
        ]);

        $payload = ['title' => 'Concurrent Test', 'content' => '<p>Body</p>'];
        $idemKey = hash('sha256', "{$this->testWorkspace->id}:create_post:" . json_encode($payload, JSON_SORT_KEYS));

        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[] = $this->createTask([
                'engine' => 'content',
                'action' => 'create_post',
                'payload_json' => $payload,
                'idempotency_key' => $idemKey,
                'credit_cost' => 5,
            ]);
        }

        $orchestrator = app(Orchestrator::class);

        // Execute first — should succeed
        $orchestrator->execute($tasks[0]);
        $tasks[0]->refresh();
        $this->assertEquals('completed', $tasks[0]->status);

        // Execute remaining — should skip via idempotency
        for ($i = 1; $i < 5; $i++) {
            $orchestrator->execute($tasks[$i]);
            $tasks[$i]->refresh();
            $this->assertEquals('completed', $tasks[$i]->status);
        }

        // Only one commit transaction
        $commits = CreditTransaction::where('workspace_id', $this->testWorkspace->id)
            ->where('type', 'commit')->count();
        $this->assertEquals(1, $commits);

        // Credit deducted only once
        $this->assertCreditBalance(5000 - 5);
    }

    /** @test */
    public function retry_loop_reuses_same_idempotency_identity()
    {
        $idemService = app(IdempotencyService::class);
        $payload = ['name' => 'Retry Lead'];

        $key1 = $idemService->generateKey($this->testWorkspace->id, 'create_lead', $payload);
        $key2 = $idemService->generateKey($this->testWorkspace->id, 'create_lead', $payload);

        // Same inputs produce same key
        $this->assertEquals($key1, $key2);
    }

    /** @test */
    public function step_hash_is_deterministic()
    {
        $idemService = app(IdempotencyService::class);

        $hash1 = $idemService->generateStepHash(42, 'create_post', 0);
        $hash2 = $idemService->generateStepHash(42, 'create_post', 0);
        $hash3 = $idemService->generateStepHash(42, 'create_post', 1); // Different step

        $this->assertEquals($hash1, $hash2);
        $this->assertNotEquals($hash1, $hash3);
    }

    /** @test */
    public function lock_blocks_concurrent_execution()
    {
        $idemService = app(IdempotencyService::class);
        $key = 'lock_test_' . uniqid();

        $this->assertTrue($idemService->acquireLock($key));
        $this->assertFalse($idemService->acquireLock($key));

        $idemService->releaseLock($key);
        $this->assertTrue($idemService->acquireLock($key));
        $idemService->releaseLock($key);
    }
}
