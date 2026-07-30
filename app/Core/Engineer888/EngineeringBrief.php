<?php

namespace App\Core\Engineer888;

use App\Core\Engineer888\Signals\ErrorSignal;
use App\Core\Engineer888\Signals\GitSignal;
use App\Core\Engineer888\Signals\PlatformSignal;
use App\Core\Engineer888\Signals\RuntimeSignal;
use App\Core\Engineer888\Signals\WorkloadSignal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Assembles the daily engineering brief.
 *
 * Collects every signal, compares against the previous snapshot, evaluates the
 * deterministic rules, and persists the result.
 *
 * A failure in one signal must never lose the others: each collector is wrapped
 * so a broken sensor degrades the brief rather than cancelling it.
 */
final class EngineeringBrief
{
    public function __construct(private string $repoPath, private string $logPath) {}

    /** @return array{signals:array,recommendations:array,previous:?array,duration_ms:int,worst:string} */
    public function generate(bool $persist = true): array
    {
        $started = microtime(true);
        $previousRow = $this->previousSnapshot();
        $previous = $previousRow ? (json_decode($previousRow->signals, true) ?: []) : [];
        $lastOffset = (int) ($previousRow->log_offset ?? 0);

        $collectors = [
            new GitSignal($this->repoPath),
            new RuntimeSignal(),
            new PlatformSignal(),
            new WorkloadSignal(),
            // No previous snapshot means the log scan is a baseline, not a delta.
            // The production channel is Laravel's Monolog channel name, which is
            // config('app.env') — derived rather than hardcoded so this works on
            // any environment instead of only the one it was written on.
            new ErrorSignal(
                $this->logPath,
                $lastOffset,
                isBaseline: $previousRow === null,
                productionChannel: (string) config('app.env'),
            ),
        ];

        $signals = [];
        foreach ($collectors as $c) {
            try {
                $signals[$c->key()] = $c->collect();
            } catch (\Throwable $e) {
                // A sensor fault is reported, never fatal.
                $signals[$c->key()] = [
                    'available' => false,
                    'reason' => 'collector threw: ' . substr($e->getMessage(), 0, 160),
                ];
                Log::warning('[Engineer888] signal collector failed: ' . $c->key(), ['error' => $e->getMessage()]);
            }
        }

        // How long since the previous brief. Under the `_meta` key rather than a
        // signal because it describes the brief, not the platform.
        $signals['_meta'] = [
            'hours_since_previous' => $previousRow
                ? round((time() - strtotime((string) $previousRow->captured_at)) / 3600, 3)
                : null,
        ];

        $recommendations = Recommendations::evaluate($signals, $previous);
        $worst = Recommendations::worstSeverity($recommendations);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if ($persist) {
            try {
                DB::table('engineering_snapshots')->insert([
                    'captured_at'     => now(),
                    'signals'         => json_encode($signals),
                    'recommendations' => json_encode($recommendations),
                    // Only advance the offset when the log was actually read,
                    // otherwise a failed read would skip errors permanently.
                    'log_offset'      => (int) ($signals['errors']['offset'] ?? $lastOffset),
                    'worst_severity'  => $worst,
                    'duration_ms'     => $durationMs,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[Engineer888] snapshot persist failed: ' . $e->getMessage());
            }
        }

        return [
            'signals'         => $signals,
            'recommendations' => $recommendations,
            'previous'        => $previous ?: null,
            'previous_at'     => $previousRow->captured_at ?? null,
            'duration_ms'     => $durationMs,
            'worst'           => $worst,
        ];
    }

    private function previousSnapshot(): ?object
    {
        try {
            return DB::table('engineering_snapshots')->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;   // table not migrated yet — first run
        }
    }
}
