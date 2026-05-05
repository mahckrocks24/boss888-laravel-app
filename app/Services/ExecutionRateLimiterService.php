<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class ExecutionRateLimiterService
{
    /**
     * Check all applicable rate limits before execution.
     * Returns ['allowed' => bool, 'reason' => string|null]
     */
    public function check(int $workspaceId, ?string $agentSlug, string $connector): array
    {
        // Workspace limits
        $wsCheck = $this->checkLimit("ratelimit:ws:{$workspaceId}", [
            'per_minute' => config('execution.rate_limits.workspace.per_minute', 30),
            'per_hour' => config('execution.rate_limits.workspace.per_hour', 300),
            'per_day' => config('execution.rate_limits.workspace.per_day', 3000),
        ]);
        if (! $wsCheck['allowed']) {
            return ['allowed' => false, 'reason' => "Workspace rate limit exceeded: {$wsCheck['window']}"];
        }

        // Agent limits (if agent source)
        if ($agentSlug) {
            $agentCheck = $this->checkLimit("ratelimit:agent:{$agentSlug}", [
                'per_minute' => config('execution.rate_limits.agent.per_minute', 10),
                'per_hour' => config('execution.rate_limits.agent.per_hour', 100),
                'per_day' => config('execution.rate_limits.agent.per_day', 1000),
            ]);
            if (! $agentCheck['allowed']) {
                return ['allowed' => false, 'reason' => "Agent {$agentSlug} rate limit exceeded: {$agentCheck['window']}"];
            }
        }

        // Connector limits
        if ($connector) {
            $connCheck = $this->checkLimit("ratelimit:conn:{$connector}", [
                'per_minute' => config("execution.rate_limits.connector.{$connector}.per_minute",
                    config('execution.rate_limits.connector.default.per_minute', 20)),
                'per_hour' => config("execution.rate_limits.connector.{$connector}.per_hour",
                    config('execution.rate_limits.connector.default.per_hour', 200)),
                'per_day' => config("execution.rate_limits.connector.{$connector}.per_day",
                    config('execution.rate_limits.connector.default.per_day', 2000)),
            ]);
            if (! $connCheck['allowed']) {
                return ['allowed' => false, 'reason' => "Connector {$connector} rate limit exceeded: {$connCheck['window']}"];
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Record an execution (increment counters).
     */
    public function record(int $workspaceId, ?string $agentSlug, string $connector): void
    {
        $this->increment("ratelimit:ws:{$workspaceId}");

        if ($agentSlug) {
            $this->increment("ratelimit:agent:{$agentSlug}");
        }

        if ($connector) {
            $this->increment("ratelimit:conn:{$connector}");
        }
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function checkLimit(string $prefix, array $limits): array
    {
        foreach ($limits as $window => $max) {
            $ttl = match ($window) {
                'per_minute' => 60,
                'per_hour' => 3600,
                'per_day' => 86400,
                default => 3600,
            };

            $key = "{$prefix}:{$window}";
            $current = (int) Cache::get($key, 0);

            if ($current >= $max) {
                return ['allowed' => false, 'window' => $window];
            }
        }

        return ['allowed' => true, 'window' => null];
    }

    private function increment(string $prefix): void
    {
        $windows = ['per_minute' => 60, 'per_hour' => 3600, 'per_day' => 86400];

        foreach ($windows as $window => $ttl) {
            $key = "{$prefix}:{$window}";
            if (Cache::has($key)) {
                Cache::increment($key);
            } else {
                Cache::put($key, 1, $ttl);
            }
        }
    }
}
