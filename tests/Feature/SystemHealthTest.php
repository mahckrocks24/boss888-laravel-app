<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\Boss888TestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use App\Core\SystemHealth\SystemHealthService;
use App\Services\ConnectorCircuitBreakerService;
use App\Models\Task;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function health_endpoint_returns_structured_response()
    {
        $response = $this->getJson('/api/system/health');

        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'timestamp',
            'checks' => [
                'database',
                'cache',
                'connectors',
                'circuits',
                'open_circuits',
                'queue',
                'stale_task_count',
            ],
        ]);
    }

    /** @test */
    public function health_status_ok_when_everything_healthy()
    {
        $health = app(SystemHealthService::class)->health();

        $this->assertEquals('ok', $health['status']);
        $this->assertEquals('connected', $health['checks']['database']);
        $this->assertEquals('connected', $health['checks']['cache']);
    }

    /** @test */
    public function health_status_degraded_with_open_circuits()
    {
        $cb = app(ConnectorCircuitBreakerService::class);
        $threshold = config('execution.circuit_breaker.default.failure_threshold', 5);

        for ($i = 0; $i < $threshold; $i++) {
            $cb->recordFailure('wordpress');
        }

        $health = app(SystemHealthService::class)->health();

        $this->assertContains($health['status'], ['degraded', 'error']);
        $this->assertContains('wordpress', $health['checks']['open_circuits']);
    }

    /** @test */
    public function stale_task_count_is_accurate()
    {
        $timeout = config('queue_control.stale_task_timeout', 600);

        $this->createTask([
            'status' => 'running',
            'started_at' => now()->subSeconds($timeout + 100),
        ]);

        $health = app(SystemHealthService::class)->health();

        $this->assertEquals(1, $health['checks']['stale_task_count']);
    }

    /** @test */
    public function throttled_workspaces_reported()
    {
        $cap = config('queue_control.workspace_concurrency', 10);

        for ($i = 0; $i < $cap; $i++) {
            $this->createTask(['status' => 'running', 'started_at' => now()]);
        }

        $health = app(SystemHealthService::class)->health();

        $this->assertArrayHasKey($this->testWorkspace->id, $health['checks']['throttled_workspaces']);
    }

    /** @test */
    public function connectors_endpoint_returns_status()
    {
        $response = $this->getJson('/api/system/connectors', $this->authHeaders());
        $response->assertOk();
        $response->assertJsonStructure(['connectors']);
    }
}
