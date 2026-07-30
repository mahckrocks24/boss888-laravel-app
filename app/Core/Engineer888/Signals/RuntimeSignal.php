<?php

namespace App\Core\Engineer888\Signals;

use Illuminate\Support\Facades\Http;

/**
 * External AI runtime health.
 *
 * Reports the version string but does NOT treat it as deployment evidence: the
 * runtime's /health version is a hardcoded literal (index.js:492) and has read
 * 2.37.3 across deployments. Recorded for change-detection only — if it ever
 * moves, that is itself news.
 */
final class RuntimeSignal implements Signal
{
    public function key(): string { return 'runtime'; }
    public function label(): string { return 'AI Runtime'; }

    public function collect(): array
    {
        $url = rtrim((string) env('RUNTIME_URL', ''), '/');
        if ($url === '') {
            return ['available' => false, 'reason' => 'RUNTIME_URL not set'];
        }

        $started = microtime(true);
        try {
            $res = Http::timeout(15)->get($url . '/health');
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => 'unreachable: ' . substr($e->getMessage(), 0, 120)];
        }
        $ms = (int) round((microtime(true) - $started) * 1000);

        if (! $res->successful()) {
            return ['available' => false, 'reason' => 'http ' . $res->status(), 'latency_ms' => $ms];
        }

        $body = $res->json() ?? [];

        return [
            'available'   => true,
            'status'      => $body['status'] ?? 'unknown',
            'version'     => $body['version'] ?? 'unknown',
            'latency_ms'  => $ms,
            'agents'      => is_array($body['agents'] ?? null) ? count($body['agents']) : null,
            'tools'       => is_array($body['tools'] ?? null) ? count($body['tools']) : null,
            'ai_run_tasks' => is_array($body['ai_run_tasks'] ?? null) ? count($body['ai_run_tasks']) : null,
        ];
    }
}
