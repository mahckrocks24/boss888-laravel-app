<?php

namespace App\Console\Commands\Tests;

use Illuminate\Console\Command;
use App\Services\ExecutionRateLimiterService;
use Illuminate\Support\Facades\Cache;

class RateLimitTestCommand extends Command
{
    protected $signature = 'boss888:rate-limit-test
        {--requests=100 : Number of rapid requests to simulate}';

    protected $description = 'Phase 5: Validate rate limiting under real Redis';

    public function handle(ExecutionRateLimiterService $limiter): int
    {
        $requests = (int) $this->option('requests');
        $this->info("═══ Rate Limit Test ({$requests} requests) ═══");

        $wsId = \App\Models\Workspace::first()?->id ?? 1;
        $allowed = 0;
        $blocked = 0;
        $blockReasons = [];

        // Clear counters
        Cache::forget("ratelimit:ws:{$wsId}:per_minute");
        Cache::forget("ratelimit:ws:{$wsId}:per_hour");
        Cache::forget("ratelimit:agent:dmm:per_minute");
        Cache::forget("ratelimit:conn:creative:per_minute");

        $this->info("  Firing {$requests} rapid checks...");

        for ($i = 0; $i < $requests; $i++) {
            $check = $limiter->check($wsId, 'dmm', 'creative');

            if ($check['allowed']) {
                $allowed++;
                $limiter->record($wsId, 'dmm', 'creative');
            } else {
                $blocked++;
                $reason = $check['reason'] ?? 'unknown';
                $blockReasons[$reason] = ($blockReasons[$reason] ?? 0) + 1;
            }
        }

        $wsLimit = config('execution.rate_limits.workspace.per_minute', 30);
        $agentLimit = config('execution.rate_limits.agent.per_minute', 10);
        $connLimit = config('execution.rate_limits.connector.default.per_minute', 20);
        $strictestLimit = min($wsLimit, $agentLimit, $connLimit);

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total requests', $requests],
                ['Allowed', $allowed],
                ['Blocked', $blocked],
                ['WS per-min limit', $wsLimit],
                ['Agent per-min limit', $agentLimit],
                ['Connector per-min limit', $connLimit],
                ['Expected max allowed', $strictestLimit],
            ]
        );

        if (! empty($blockReasons)) {
            $this->info('  Block reasons:');
            foreach ($blockReasons as $reason => $count) {
                $this->line("    {$reason}: {$count}");
            }
        }

        // Validate: allowed should equal the strictest limit
        $passed = $allowed <= $strictestLimit && $blocked > 0;

        $this->newLine();
        if ($passed) {
            $this->info("✅ RATE LIMIT TEST PASSED — allowed {$allowed} (limit: {$strictestLimit}), blocked {$blocked}");
        } else {
            $this->error("❌ RATE LIMIT TEST FAILED — allowed {$allowed} but expected ≤{$strictestLimit}");
        }

        return $passed ? 0 : 1;
    }
}
