<?php

namespace App\Core\Intelligence\Validation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Intelligence Validator — the immune system of the AI OS.
 *
 * Problems this solves:
 *   1. One bad campaign corrupting global knowledge
 *   2. Stale insights staying confident forever
 *   3. No way to trace where an insight came from
 *   4. "78% effective" means nothing without industry/region/audience context
 *   5. Gamified stats that don't affect decisions
 *
 * Protections:
 *   - Minimum data points before insight is trusted (threshold: 3)
 *   - Confidence decay over time (insights lose confidence without reinforcement)
 *   - Outlier detection (single extreme result doesn't corrupt)
 *   - Source tracking (trace every insight back to originating workspace type)
 *   - Segmented effectiveness (per industry × region × campaign type)
 */
class IntelligenceValidator
{
    private const MIN_DATA_POINTS = 3;      // Minimum before insight is trusted
    private const CONFIDENCE_DECAY_DAYS = 90; // Start decaying after 90 days without reinforcement
    private const DECAY_RATE = 0.02;         // Confidence drops 2% per decay cycle
    private const OUTLIER_THRESHOLD = 2.5;    // Standard deviations from mean

    /**
     * Validate an insight before it enters global knowledge.
     * Returns [valid => bool, reason => string, adjusted_confidence => float]
     */
    public function validateInsight(array $data): array
    {
        $confidence = $data['confidence'] ?? 0.3;
        $reasons = [];

        // 1. Check data point minimum
        $dataPoints = $data['data_points'] ?? 1;
        if ($dataPoints < self::MIN_DATA_POINTS) {
            $confidence = min($confidence, 0.3); // Cap at 30% until enough data
            $reasons[] = "Below minimum data points ({$dataPoints}/" . self::MIN_DATA_POINTS . ")";
        }

        // 2. Check for outlier metrics
        if (!empty($data['metrics'])) {
            $isOutlier = $this->detectOutlier($data['category'] ?? '', $data['metrics']);
            if ($isOutlier) {
                $confidence *= 0.5; // Halve confidence for outliers
                $reasons[] = "Metrics are outliers compared to historical data";
            }
        }

        // 3. Validate source — insights from single workspace get lower confidence
        if (($data['data_points'] ?? 1) === 1) {
            $confidence = min($confidence, 0.25);
            $reasons[] = "Single source — needs corroboration";
        }

        // 4. Industry/region specificity check
        if (empty($data['industry']) && empty($data['region'])) {
            // Generic insights get slightly lower confidence
            $confidence *= 0.9;
            $reasons[] = "No industry/region specificity — generic insight";
        }

        return [
            'valid' => $confidence >= 0.1,
            'adjusted_confidence' => round(max(0.05, min(0.99, $confidence)), 3),
            'reasons' => $reasons,
        ];
    }

    /**
     * Run confidence decay on all global knowledge.
     * Should be called daily via scheduler.
     */
    public function runConfidenceDecay(): int
    {
        $threshold = now()->subDays(self::CONFIDENCE_DECAY_DAYS);

        $decayed = DB::table('global_knowledge')
            ->where('updated_at', '<', $threshold)
            ->where('confidence', '>', 0.1)
            ->update([
                'confidence' => DB::raw("GREATEST(0.1, confidence - " . self::DECAY_RATE . ")"),
                'updated_at' => now(), // Note: this resets the decay clock — only decays if not reinforced
            ]);

        return $decayed;
    }

    /**
     * Detect if metrics are statistical outliers.
     */
    private function detectOutlier(string $category, array $metrics): bool
    {
        if (empty($metrics)) return false;

        // Get historical metrics for this category
        $historical = DB::table('global_knowledge')
            ->where('category', $category)
            ->whereNotNull('metrics_json')
            ->limit(100)
            ->pluck('metrics_json')
            ->map(fn($j) => json_decode($j, true))
            ->filter()
            ->toArray();

        if (count($historical) < 5) return false; // Not enough data to detect outliers

        // Check each metric against historical distribution
        foreach ($metrics as $key => $value) {
            if (!is_numeric($value)) continue;

            $historicalValues = array_filter(array_map(fn($h) => $h[$key] ?? null, $historical), fn($v) => is_numeric($v));
            if (count($historicalValues) < 3) continue;

            $mean = array_sum($historicalValues) / count($historicalValues);
            $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $historicalValues)) / count($historicalValues);
            $stddev = sqrt($variance);

            if ($stddev > 0 && abs($value - $mean) > self::OUTLIER_THRESHOLD * $stddev) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get segmented effectiveness data for an engine tool.
     * Returns effectiveness broken down by industry × region × campaign type.
     */
    public function getSegmentedEffectiveness(string $engine, string $toolKey): array
    {
        $data = DB::table('engine_intelligence')
            ->where('engine', $engine)
            ->where('key', $toolKey)
            ->first();

        if (!$data) return ['overall' => null, 'segments' => []];

        // Get from campaign outcomes for segmentation
        $outcomes = DB::table('campaign_outcomes')
            ->where('engine', $engine)
            ->whereNotNull('effectiveness_score')
            ->select('industry', 'region', 'campaign_type',
                DB::raw('AVG(effectiveness_score) as avg_effectiveness'),
                DB::raw('COUNT(*) as sample_size'))
            ->groupBy('industry', 'region', 'campaign_type')
            ->having('sample_size', '>=', 2)
            ->orderByDesc('avg_effectiveness')
            ->get();

        return [
            'overall' => $data->effectiveness_score,
            'usage_count' => $data->usage_count,
            'segments' => $outcomes->map(fn($o) => [
                'industry' => $o->industry,
                'region' => $o->region,
                'campaign_type' => $o->campaign_type,
                'effectiveness' => round($o->avg_effectiveness, 2),
                'sample_size' => $o->sample_size,
                'reliable' => $o->sample_size >= self::MIN_DATA_POINTS,
            ])->toArray(),
        ];
    }

    /**
     * Validate agent experience affects decisions.
     * Returns trust score that should weight the agent's autonomy.
     */
    public function getAgentTrustScore(int $agentId, ?string $industry = null): array
    {
        $stats = DB::table('agent_experience_stats')
            ->where('agent_id', $agentId)
            ->where('period', 'all_time')
            ->pluck('value_int', 'metric_key')
            ->toArray();

        $totalTasks = $stats['tasks_completed'] ?? 0;
        $succeeded = $stats['tasks_succeeded'] ?? 0;
        $failed = $stats['tasks_failed'] ?? 0;
        $successRate = $totalTasks > 0 ? $succeeded / $totalTasks : 0;

        // Base trust from success rate
        $trust = $successRate * 0.5;

        // Experience bonus (logarithmic)
        $trust += min(0.3, log($totalTasks + 1) / log(500));

        // Industry expertise bonus
        if ($industry) {
            $industryTasks = DB::table('agent_experience_stats')
                ->where('agent_id', $agentId)
                ->where('metric_key', 'industries_handled')
                ->where('industry', $industry)
                ->value('value_int') ?? 0;
            $trust += min(0.2, log($industryTasks + 1) / log(100));
        }

        // Determine autonomy level
        $autonomy = 'supervised'; // Sarah must approve
        if ($trust >= 0.8) $autonomy = 'autonomous';       // Can act independently
        elseif ($trust >= 0.6) $autonomy = 'semi_autonomous'; // Can act, Sarah reviews after
        elseif ($trust >= 0.4) $autonomy = 'guided';         // Sarah approves before

        return [
            'trust_score' => round(min(1.0, $trust), 2),
            'autonomy_level' => $autonomy,
            'total_tasks' => $totalTasks,
            'success_rate' => round($successRate * 100, 1),
            'industry_experience' => $industry ? ($stats["industries_{$industry}"] ?? 0) : null,
            'decision_weight' => round(min(1.0, $trust * 1.2), 2), // Slightly boosted for decision weighting
        ];
    }
}
