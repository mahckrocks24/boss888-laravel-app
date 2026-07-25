<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\TaskSystem\Orchestrator;
use App\Services\ExecutionRateLimiterService;
use App\Services\QueueControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 MRC-2A — IDEMPOTENCY / DUPLICATE-EXECUTION CHARACTERIZATION (test-only).
 *
 * Re-invoking the SAME already-completed task through the Orchestrator must not
 * dispatch the provider again, must not reserve/commit again, and must not create
 * duplicate side effects. Pins the current duplicate-protection contract.
 */
class IdempotencyDuplicateExecutionTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private int $imageCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '204800'])]);
    }

    private function allowGuards(): void
    {
        $rl = \Mockery::mock(ExecutionRateLimiterService::class)->makePartial();
        $rl->shouldReceive('check')->andReturn(['allowed' => true, 'reason' => '']);
        $rl->shouldReceive('record')->andReturnNull();
        $this->app->instance(ExecutionRateLimiterService::class, $rl);

        $qc = \Mockery::mock(QueueControlService::class)->makePartial();
        $qc->shouldReceive('canWorkspaceExecute')->andReturn(true);
        $this->app->instance(QueueControlService::class, $qc);
    }

    private function fakeRuntime(): void
    {
        $this->imageCalls = 0;
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('aiRun')->andReturn(['text' => '']);
        $rt->shouldReceive('imageGenerate')->andReturnUsing(function () {
            $this->imageCalls++;
            return ['success' => true, 'url' => 'https://cdn.test/i.png', 'size' => '1024x1024',
                    'quality' => 'standard', 'storage_path' => 'ai-images/1/i.png', 'model' => 'gpt-image-1', 'provider' => 'openai'];
        });
        $this->app->instance(RuntimeClient::class, $rt);
    }

    private function commitCount(): int
    {
        return DB::table('credit_transactions')->where('workspace_id', $this->testWorkspace->id)->where('type', 'commit')->count();
    }

    /** @test */
    public function re_executing_a_completed_task_does_not_dispatch_or_charge_again(): void
    {
        $this->allowGuards();
        $this->fakeRuntime();

        $task = $this->createTask([
            'engine' => 'creative', 'action' => 'generate_image',
            'payload_json' => ['prompt' => 'idempotent please', 'aspect_ratio' => '1:1'],
            'credit_cost' => 2,
        ]);

        // First execution → completes, dispatches once, charges once.
        app(Orchestrator::class)->execute($task);
        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame(1, $this->imageCalls);
        $this->assertSame(1, $this->commitCount());
        $this->assertCreditBalance(5000 - 2);

        // Second execution of the SAME task → no re-dispatch, no second charge.
        app(Orchestrator::class)->execute($task);
        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame(1, $this->imageCalls);       // provider NOT called again
        $this->assertSame(1, $this->commitCount());    // no second commit
        $this->assertCreditBalance(5000 - 2);          // balance unchanged
        $this->assertReservedBalance(0);
    }
}
