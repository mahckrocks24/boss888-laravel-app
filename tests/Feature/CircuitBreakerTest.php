<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\Boss888TestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use App\Services\ConnectorCircuitBreakerService;

class CircuitBreakerTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function repeated_failures_open_circuit()
    {
        $cb = app(ConnectorCircuitBreakerService::class);
        $threshold = config('execution.circuit_breaker.default.failure_threshold', 5);

        for ($i = 0; $i < $threshold; $i++) {
            $cb->recordFailure('wordpress');
        }

        $this->assertFalse($cb->isAvailable('wordpress'));
        $this->assertEquals('open', $cb->getStatus('wordpress')['state']);
    }

    /** @test */
    public function cooldown_transitions_to_half_open()
    {
        $cb = app(ConnectorCircuitBreakerService::class);
        $threshold = config('execution.circuit_breaker.default.failure_threshold', 5);

        for ($i = 0; $i < $threshold; $i++) {
            $cb->recordFailure('creative');
        }

        $this->assertFalse($cb->isAvailable('creative'));

        // Simulate cooldown expiry
        Cache::put('circuit:creative:opened_at', now()->subMinutes(10), 3600);

        // Should transition to half_open and allow probe
        $this->assertTrue($cb->isAvailable('creative'));
        $this->assertEquals('half_open', $cb->getStatus('creative')['state']);
    }

    /** @test */
    public function successful_probe_closes_circuit()
    {
        $cb = app(ConnectorCircuitBreakerService::class);
        $threshold = config('execution.circuit_breaker.default.failure_threshold', 5);

        // Open circuit
        for ($i = 0; $i < $threshold; $i++) {
            $cb->recordFailure('email');
        }

        // Simulate half_open state
        Cache::put('circuit:email:opened_at', now()->subMinutes(10), 3600);
        $cb->isAvailable('email'); // transitions to half_open

        // Success closes it
        $cb->recordSuccess('email');
        $this->assertEquals('closed', $cb->getStatus('email')['state']);
        $this->assertTrue($cb->isAvailable('email'));
    }

    /** @test */
    public function open_circuits_listed_correctly()
    {
        $cb = app(ConnectorCircuitBreakerService::class);
        $threshold = config('execution.circuit_breaker.default.failure_threshold', 5);

        for ($i = 0; $i < $threshold; $i++) {
            $cb->recordFailure('social');
        }

        $open = $cb->getOpenCircuits();
        $this->assertArrayHasKey('social', $open);
        $this->assertArrayNotHasKey('wordpress', $open);
    }

    /** @test */
    public function success_decays_failure_count()
    {
        $cb = app(ConnectorCircuitBreakerService::class);

        $cb->recordFailure('wordpress');
        $cb->recordFailure('wordpress');
        $this->assertEquals(2, $cb->getStatus('wordpress')['failure_count']);

        $cb->recordSuccess('wordpress');
        $this->assertEquals(1, $cb->getStatus('wordpress')['failure_count']);
    }
}
