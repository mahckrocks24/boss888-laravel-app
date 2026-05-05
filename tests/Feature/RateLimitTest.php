<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\Boss888TestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use App\Services\ExecutionRateLimiterService;

class RateLimitTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function workspace_rate_limit_blocks_when_exceeded()
    {
        $rl = app(ExecutionRateLimiterService::class);
        $limit = config('execution.rate_limits.workspace.per_minute', 30);

        // Exhaust limit
        Cache::put("ratelimit:ws:{$this->testWorkspace->id}:per_minute", $limit, 60);

        $check = $rl->check($this->testWorkspace->id, null, 'wordpress');
        $this->assertFalse($check['allowed']);
        $this->assertStringContainsString('Workspace rate limit', $check['reason']);
    }

    /** @test */
    public function agent_rate_limit_blocks_when_exceeded()
    {
        $rl = app(ExecutionRateLimiterService::class);
        $limit = config('execution.rate_limits.agent.per_minute', 10);

        Cache::put('ratelimit:agent:dmm:per_minute', $limit, 60);

        $check = $rl->check($this->testWorkspace->id, 'dmm', 'wordpress');
        $this->assertFalse($check['allowed']);
        $this->assertStringContainsString('Agent dmm', $check['reason']);
    }

    /** @test */
    public function connector_rate_limit_blocks_when_exceeded()
    {
        $rl = app(ExecutionRateLimiterService::class);
        $limit = config('execution.rate_limits.connector.default.per_minute', 20);

        Cache::put('ratelimit:conn:wordpress:per_minute', $limit, 60);

        $check = $rl->check($this->testWorkspace->id, null, 'wordpress');
        $this->assertFalse($check['allowed']);
        $this->assertStringContainsString('Connector wordpress', $check['reason']);
    }

    /** @test */
    public function under_limit_allows_execution()
    {
        $rl = app(ExecutionRateLimiterService::class);

        $check = $rl->check($this->testWorkspace->id, null, 'wordpress');
        $this->assertTrue($check['allowed']);
        $this->assertNull($check['reason']);
    }

    /** @test */
    public function recording_increments_counters()
    {
        $rl = app(ExecutionRateLimiterService::class);

        $rl->record($this->testWorkspace->id, 'dmm', 'wordpress');
        $rl->record($this->testWorkspace->id, 'dmm', 'wordpress');

        $this->assertEquals(2, Cache::get("ratelimit:ws:{$this->testWorkspace->id}:per_minute"));
        $this->assertEquals(2, Cache::get('ratelimit:agent:dmm:per_minute'));
        $this->assertEquals(2, Cache::get('ratelimit:conn:wordpress:per_minute'));
    }
}
