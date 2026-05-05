<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\Boss888TestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Task;
use App\Services\QueueControlService;

class QueueStressTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function concurrency_cap_enforced_for_single_workspace()
    {
        $qc = app(QueueControlService::class);
        $cap = config('queue_control.workspace_concurrency', 10);

        // Fill to cap
        for ($i = 0; $i < $cap; $i++) {
            $this->createTask(['status' => 'running', 'started_at' => now()]);
        }

        $this->assertFalse($qc->canWorkspaceExecute($this->testWorkspace->id));

        // Adding a 101st task queued
        $extra = $this->createTask(['status' => 'queued']);
        $this->assertEquals('queued', $extra->status);
    }

    /** @test */
    public function hundred_tasks_build_queue_without_crash()
    {
        for ($i = 0; $i < 100; $i++) {
            $this->createTask([
                'status' => $i < 10 ? 'running' : 'queued',
                'started_at' => $i < 10 ? now() : null,
            ]);
        }

        $metrics = app(QueueControlService::class)->getMetrics();

        $this->assertEquals(10, $metrics['running']);
        $this->assertEquals(90, $metrics['queued']);
        $this->assertIsArray($metrics);
    }

    /** @test */
    public function multiple_workspaces_maintain_fairness()
    {
        $ws2 = $this->createAdditionalWorkspace('Workspace 2');
        $ws3 = $this->createAdditionalWorkspace('Workspace 3');

        $qc = app(QueueControlService::class);

        // Each workspace gets 3 running tasks
        foreach ([$this->testWorkspace->id, $ws2->id, $ws3->id] as $wsId) {
            for ($i = 0; $i < 3; $i++) {
                Task::create([
                    'workspace_id' => $wsId,
                    'engine' => 'crm',
                    'action' => 'create_lead',
                    'status' => 'running',
                    'started_at' => now(),
                ]);
            }
        }

        // All three should still have capacity
        $this->assertTrue($qc->canWorkspaceExecute($this->testWorkspace->id));
        $this->assertTrue($qc->canWorkspaceExecute($ws2->id));
        $this->assertTrue($qc->canWorkspaceExecute($ws3->id));

        // No workspace should be throttled
        $throttled = $qc->getThrottledWorkspaces();
        $this->assertEmpty($throttled);
    }

    /** @test */
    public function stale_task_detection_finds_stuck_jobs()
    {
        $qc = app(QueueControlService::class);
        $timeout = config('queue_control.stale_task_timeout', 600);

        // Create a task stuck in running beyond timeout
        $this->createTask([
            'status' => 'running',
            'started_at' => now()->subSeconds($timeout + 60),
        ]);

        // Create a normal running task
        $this->createTask([
            'status' => 'running',
            'started_at' => now()->subSeconds(10),
        ]);

        $stale = $qc->findStaleTasks();
        $this->assertEquals(1, $stale->count());
    }

    /** @test */
    public function queue_priority_resolves_correctly()
    {
        $qc = app(QueueControlService::class);

        $urgent = $this->createTask(['priority' => 'urgent']);
        $normal = $this->createTask(['priority' => 'normal']);
        $low = $this->createTask(['priority' => 'low']);

        $this->assertEquals('tasks-high', $qc->resolveQueue($urgent));
        $this->assertEquals('tasks', $qc->resolveQueue($normal));
        $this->assertEquals('tasks-low', $qc->resolveQueue($low));
    }
}
