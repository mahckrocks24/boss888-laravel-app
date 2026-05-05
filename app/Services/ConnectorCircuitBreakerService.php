<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class ConnectorCircuitBreakerService
{
    private string $prefix = 'circuit:';

    /**
     * Record a connector failure. Opens circuit if threshold reached.
     */
    public function recordFailure(string $connector): void
    {
        $key = $this->failureCountKey($connector);
        $count = (int) Cache::get($key, 0) + 1;
        $window = $this->getConfig($connector, 'failure_window', 300);
        Cache::put($key, $count, $window);

        $threshold = $this->getConfig($connector, 'failure_threshold', 5);

        if ($count >= $threshold) {
            $this->openCircuit($connector);
        }
    }

    /**
     * Record a connector success. Closes circuit if half-open.
     */
    public function recordSuccess(string $connector): void
    {
        $state = $this->getState($connector);

        if ($state === 'half_open') {
            $this->closeCircuit($connector);
        }

        // Decay failure count on success
        $key = $this->failureCountKey($connector);
        $current = (int) Cache::get($key, 0);
        if ($current > 0) {
            Cache::put($key, max(0, $current - 1), $this->getConfig($connector, 'failure_window', 300));
        }
    }

    /**
     * Check if connector is available for execution.
     */
    public function isAvailable(string $connector): bool
    {
        $state = $this->getState($connector);

        if ($state === 'closed') {
            return true;
        }

        if ($state === 'open') {
            // Check if cooldown expired → transition to half_open
            $openedAt = Cache::get($this->prefix . $connector . ':opened_at');
            $cooldown = $this->getConfig($connector, 'cooldown_seconds', 60);

            if ($openedAt && now()->diffInSeconds($openedAt) >= $cooldown) {
                $this->setState($connector, 'half_open');
                return true; // Allow one probe request
            }

            return false;
        }

        // half_open — allow execution (probe)
        return true;
    }

    /**
     * Get structured status for a connector.
     */
    public function getStatus(string $connector): array
    {
        return [
            'connector' => $connector,
            'state' => $this->getState($connector),
            'failure_count' => (int) Cache::get($this->failureCountKey($connector), 0),
            'threshold' => $this->getConfig($connector, 'failure_threshold', 5),
            'cooldown' => $this->getConfig($connector, 'cooldown_seconds', 60),
        ];
    }

    /**
     * Get all circuit statuses.
     */
    public function getAllStatuses(): array
    {
        $connectors = ['creative', 'email', 'social'];
        $statuses = [];
        foreach ($connectors as $c) {
            $statuses[$c] = $this->getStatus($c);
        }
        return $statuses;
    }

    /**
     * Get open circuits only.
     */
    public function getOpenCircuits(): array
    {
        return collect($this->getAllStatuses())
            ->filter(fn ($s) => $s['state'] !== 'closed')
            ->toArray();
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function openCircuit(string $connector): void
    {
        $this->setState($connector, 'open');
        Cache::put($this->prefix . $connector . ':opened_at', now(), 3600);
    }

    private function closeCircuit(string $connector): void
    {
        $this->setState($connector, 'closed');
        Cache::forget($this->prefix . $connector . ':opened_at');
        Cache::forget($this->failureCountKey($connector));
    }

    private function getState(string $connector): string
    {
        return Cache::get($this->prefix . $connector . ':state', 'closed');
    }

    private function setState(string $connector, string $state): void
    {
        Cache::put($this->prefix . $connector . ':state', $state, 3600);
    }

    private function failureCountKey(string $connector): string
    {
        return $this->prefix . $connector . ':failures';
    }

    private function getConfig(string $connector, string $key, mixed $default): mixed
    {
        return config("execution.circuit_breaker.{$connector}.{$key}",
            config("execution.circuit_breaker.default.{$key}", $default));
    }
}
