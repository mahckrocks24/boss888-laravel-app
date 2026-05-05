<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\Boss888TestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Services\TaskProgressService;
use App\Core\TaskSystem\Orchestrator;

class TaskProgressTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function task_emits_events_for_each_stage()
    {
        Http::fake([
            '*/pluginconnector888/v1/posts' => Http::response([
                'id' => 50, 'link' => 'https://example.com/p', 'status' => 'draft',
            ], 200),
        ]);

        $task = $this->createTask([
            'engine' => 'content',
            'action' => 'create_post',
            'payload_json' => ['title' => 'Progress Test', 'content' => '<p>X</p>'],
            'credit_cost' => 5,
        ]);

        app(Orchestrator::class)->execute($task);

        $events = TaskEvent::where('task_id', $task->id)->orderBy('created_at')->get();

        $eventNames = $events->pluck('event')->toArray();

        $this->assertContains('execution_started', $eventNames);
        $this->assertContains('credits_reserved', $eventNames);
        $this->assertContains('step_executed', $eventNames);
        $this->assertContains('step_verified', $eventNames);
        $this->assertContains('credits_committed', $eventNames);
        $this->assertContains('execution_completed', $eventNames);
    }

    /** @test */
    public function progress_percent_updates_correctly()
    {
        $progress = app(TaskProgressService::class);

        $task = $this->createTask(['total_steps' => 3, 'current_step' => 0]);

        $progress->updateProgress($task, 1, 3, 'Step 1 done');
        $task->refresh();
        $this->assertEquals(1, $task->current_step);

        $progress->updateProgress($task, 3, 3, 'All done');
        $task->refresh();
        $this->assertEquals(3, $task->current_step);

        $status = $progress->getStatus($task->id);
        $this->assertEquals(100, $status['progress_percent']);
    }

    /** @test */
    public function current_step_and_total_steps_accurate()
    {
        $progress = app(TaskProgressService::class);

        $task = $this->createTask(['total_steps' => 5]);

        $progress->updateProgress($task, 2, 5, 'Halfway');

        $status = $progress->getStatus($task->id);
        $this->assertEquals(2, $status['current_step']);
        $this->assertEquals(5, $status['total_steps']);
        $this->assertEquals(40, $status['progress_percent']);
    }

    /** @test */
    public function status_endpoint_returns_latest_state()
    {
        $task = $this->createTask([
            'status' => 'running',
            'current_step' => 1,
            'total_steps' => 2,
            'progress_message' => 'Running step 1',
        ]);

        $response = $this->getJson("/api/tasks/{$task->id}/status", $this->authHeaders());

        $response->assertOk();
        $response->assertJsonFragment([
            'task_id' => $task->id,
            'status' => 'running',
            'current_step' => 1,
            'total_steps' => 2,
        ]);
    }

    /** @test */
    public function events_endpoint_returns_full_timeline()
    {
        $progress = app(TaskProgressService::class);

        $task = $this->createTask();
        $progress->recordEvent($task->id, 'created', 'pending', message: 'Task created');
        $progress->recordEvent($task->id, 'started', 'running', message: 'Started');
        $progress->recordEvent($task->id, 'completed', 'completed', message: 'Done');

        $response = $this->getJson("/api/tasks/{$task->id}/events", $this->authHeaders());

        $response->assertOk();
        $response->assertJsonCount(3, 'events');
    }
}
