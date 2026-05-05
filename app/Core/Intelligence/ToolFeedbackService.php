<?php

namespace App\Core\Intelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ToolFeedbackService — closes the learning loop.
 *
 * Called from EngineExecutionService Step 10 on every task completion
 * (success or failure). Converts raw outcomes into an effectiveness score
 * and feeds it back to EngineIntelligenceService::recordToolUsage().
 *
 * Signal inputs (any subset):
 *   - success: bool (binary outcome)
 *   - duration_ms: int (how long the task took)
 *   - expected_duration_ms: int (blueprint-based expectation)
 *   - quality_signal: float 0.0-1.0 (human approval, downstream metric)
 *   - tokens_used: int
 *
 * Output: effectiveness_score 0.0-1.0 fed into recordToolUsage().
 *
 * v1 signal combination (simple and robust):
 *   - base = success ? 1.0 : 0.0
 *   - quality bonus: if quality_signal provided, blend 50/50 with base
 *   - duration penalty: if >2x expected, -0.1
 *   - floor at 0.0, ceiling at 1.0
 */
class ToolFeedbackService
{
    public function __construct(
        private EngineIntelligenceService $engineIntel,
    ) {}

    /**
     * Record an outcome for a completed tool execution.
     */
    public function record(string $engine, string $toolKey, array $outcome): void
    {
        try {
            $score = $this->computeEffectivenessScore($outcome);
            $this->engineIntel->recordToolUsage($engine, $toolKey, $score);

            // Also record a separate effectiveness_data row for trend tracking
            if (isset($outcome['workspace_id'])) {
                $this->logOutcomeRow($engine, $toolKey, $score, $outcome);
            }
        } catch (\Throwable $e) {
            Log::warning("ToolFeedbackService.record failed: {$e->getMessage()}", [
                'engine' => $engine,
                'tool' => $toolKey,
                'outcome' => $outcome,
            ]);
        }
    }

    /**
     * Compute a 0.0-1.0 effectiveness score from raw outcome signals.
     */
    public function computeEffectivenessScore(array $outcome): float
    {
        // Base: success/failure binary
        $success = (bool) ($outcome['success'] ?? true);
        $base = $success ? 1.0 : 0.0;

        // Quality signal blend (if present)
        if (isset($outcome['quality_signal'])) {
            $quality = max(0.0, min(1.0, (float) $outcome['quality_signal']));
            $base = ($base + $quality) / 2.0;
        }

        // Duration penalty (if both actual and expected durations are present)
        if (isset($outcome['duration_ms'], $outcome['expected_duration_ms'])
            && $outcome['expected_duration_ms'] > 0) {
            $ratio = $outcome['duration_ms'] / $outcome['expected_duration_ms'];
            if ($ratio > 2.0) {
                $base -= 0.1;
            } elseif ($ratio > 3.0) {
                $base -= 0.2;
            }
        }

        // Clamp
        return max(0.0, min(1.0, $base));
    }

    /**
     * Log a detailed outcome row for trend analysis.
     * Separate from the rolling average in tool_blueprint rows.
     */
    private function logOutcomeRow(string $engine, string $toolKey, float $score, array $outcome): void
    {
        try {
            DB::table('engine_intelligence')->insert([
                'engine' => $engine,
                'knowledge_type' => 'effectiveness_data',
                'key' => $toolKey . '_' . time() . '_' . random_int(1000, 9999),
                'content' => "Outcome: " . ($outcome['success'] ?? false ? 'success' : 'failure')
                    . ", score: " . round($score, 2),
                'metadata_json' => json_encode([
                    'workspace_id' => $outcome['workspace_id'] ?? null,
                    'score' => $score,
                    'success' => $outcome['success'] ?? null,
                    'duration_ms' => $outcome['duration_ms'] ?? null,
                    'tokens_used' => $outcome['tokens_used'] ?? null,
                ]),
                'effectiveness_score' => $score,
                'usage_count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Silent — outcome logging is observability only
        }
    }
}
