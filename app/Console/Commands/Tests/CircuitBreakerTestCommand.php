<?php

namespace App\Console\Commands\Tests;

use Illuminate\Console\Command;
use App\Services\ConnectorCircuitBreakerService;
use App\Core\SystemHealth\SystemHealthService;
use Illuminate\Support\Facades\Cache;

class CircuitBreakerTestCommand extends Command
{
    protected $signature = 'boss888:circuit-test
        {--connector=creative : Connector to test}
        {--failures=10 : Number of failures to inject}
        {--cooldown=5 : Cooldown seconds for test}';

    protected $description = 'Phase 5: Validate circuit breaker under real Redis';

    public function handle(ConnectorCircuitBreakerService $cb, SystemHealthService $health): int
    {
        $connector = $this->option('connector');
        $failures = (int) $this->option('failures');
        $cooldown = (int) $this->option('cooldown');

        $this->info("═══ Circuit Breaker Test: {$connector} ═══");

        // Override cooldown for test speed
        config(["execution.circuit_breaker.{$connector}.cooldown_seconds" => $cooldown]);

        // 1. Verify starts closed
        $this->write('  Initial state... ');
        $state = $cb->getStatus($connector);
        $this->line("state={$state['state']}");

        // 2. Inject failures
        $this->info("  Injecting {$failures} failures...");
        for ($i = 0; $i < $failures; $i++) {
            $cb->recordFailure($connector);
        }

        // 3. Verify circuit opened
        $this->write('  After failures... ');
        $isAvailable = $cb->isAvailable($connector);
        $state = $cb->getStatus($connector);
        $this->line("state={$state['state']}, available={$this->bool($isAvailable)}");
        $openPassed = $state['state'] === 'open';

        // 4. Verify health reflects degraded
        $this->write('  Health status... ');
        $healthData = $health->health();
        $this->line("status={$healthData['status']}");
        $healthPassed = in_array($healthData['status'], ['degraded', 'error']);

        // 5. Wait for cooldown
        $this->info("  Waiting {$cooldown}s for cooldown...");
        sleep($cooldown + 1);

        // 6. Verify half-open
        $this->write('  After cooldown... ');
        $isAvailable = $cb->isAvailable($connector);
        $state = $cb->getStatus($connector);
        $this->line("state={$state['state']}, available={$this->bool($isAvailable)}");
        $halfOpenPassed = $state['state'] === 'half_open' && $isAvailable;

        // 7. Record success to close
        $cb->recordSuccess($connector);
        $this->write('  After success probe... ');
        $state = $cb->getStatus($connector);
        $this->line("state={$state['state']}");
        $closePassed = $state['state'] === 'closed';

        // Results
        $this->newLine();
        $this->table(
            ['Check', 'Status'],
            [
                ['Circuit opens after failures', $openPassed ? '✅' : '❌'],
                ['Health reflects degraded', $healthPassed ? '✅' : '❌'],
                ['Half-open after cooldown', $halfOpenPassed ? '✅' : '❌'],
                ['Closes after successful probe', $closePassed ? '✅' : '❌'],
            ]
        );

        $passed = $openPassed && $healthPassed && $halfOpenPassed && $closePassed;

        $this->newLine();
        $this->line($passed ? '✅ CIRCUIT BREAKER TEST PASSED' : '❌ CIRCUIT BREAKER TEST FAILED');

        return $passed ? 0 : 1;
    }

    private function bool(bool $v): string { return $v ? 'true' : 'false'; }
    private function write(string $t): void { $this->output->write($t); }
}
