<?php

namespace App\Core\Intelligence;

use Illuminate\Support\Facades\DB;

/**
 * Campaign Optimization Engine — structured experimentation.
 *
 * Not just "campaign_outcomes" storage. This is:
 *   - Hypothesis tracking: "Subject line A will outperform B by 20%"
 *   - Variant management: create, run, measure A/B/C tests
 *   - Statistical significance: is the result real or random?
 *   - Success metric normalization: compare apples to apples
 *   - Cross-campaign comparison: what strategies work across contexts?
 *   - Learning extraction: turn results into global knowledge
 */
class CampaignOptimizationEngine
{
    public function __construct(
        private GlobalKnowledgeService $globalKnowledge,
    ) {}

    /**
     * Create a new experiment (A/B test).
     */
    public function createExperiment(int $wsId, array $data): int
    {
        return DB::table('experiments')->insertGetId([
            'workspace_id' => $wsId,
            'name' => $data['name'],
            'engine' => $data['engine'],
            'hypothesis' => $data['hypothesis'],
            'status' => 'draft',
            'variants_json' => json_encode($data['variants'] ?? [
                ['id' => 'A', 'name' => 'Control', 'config' => [], 'results' => null],
                ['id' => 'B', 'name' => 'Variant', 'config' => [], 'results' => null],
            ]),
            'success_metrics_json' => json_encode($data['success_metrics'] ?? [
                ['metric' => 'conversion_rate', 'target' => 0.05, 'weight' => 1.0],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Start an experiment.
     */
    public function startExperiment(int $experimentId): void
    {
        DB::table('experiments')->where('id', $experimentId)->update([
            'status' => 'running', 'started_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Record results for a variant.
     */
    public function recordVariantResult(int $experimentId, string $variantId, array $results): void
    {
        $exp = DB::table('experiments')->where('id', $experimentId)->first();
        if (!$exp) return;

        $variants = json_decode($exp->variants_json, true);
        foreach ($variants as &$v) {
            if ($v['id'] === $variantId) {
                $v['results'] = $results;
                $v['recorded_at'] = now()->toISOString();
            }
        }

        DB::table('experiments')->where('id', $experimentId)->update([
            'variants_json' => json_encode($variants),
            'updated_at' => now(),
        ]);

        // Check if all variants have results
        $allRecorded = collect($variants)->every(fn($v) => $v['results'] !== null);
        if ($allRecorded) {
            $this->evaluateExperiment($experimentId);
        }
    }

    /**
     * Evaluate an experiment — determine winner, significance, conclusions.
     */
    public function evaluateExperiment(int $experimentId): array
    {
        $exp = DB::table('experiments')->where('id', $experimentId)->first();
        if (!$exp) return ['error' => 'Experiment not found'];

        $variants = json_decode($exp->variants_json, true);
        $metrics = json_decode($exp->success_metrics_json, true);

        // Score each variant against success metrics
        $scores = [];
        foreach ($variants as $v) {
            $results = $v['results'] ?? [];
            $score = 0;
            $totalWeight = 0;

            foreach ($metrics as $m) {
                $metricKey = $m['metric'];
                $target = $m['target'] ?? 0;
                $weight = $m['weight'] ?? 1.0;

                $actual = $results[$metricKey] ?? 0;
                $normalized = $target > 0 ? min(1.0, $actual / $target) : ($actual > 0 ? 1.0 : 0);
                $score += $normalized * $weight;
                $totalWeight += $weight;
            }

            $scores[$v['id']] = $totalWeight > 0 ? round($score / $totalWeight, 4) : 0;
        }

        // Determine winner
        arsort($scores);
        $winner = array_key_first($scores);
        $winnerScore = $scores[$winner];
        $secondScore = count($scores) > 1 ? array_values($scores)[1] : 0;

        // Calculate statistical significance (simplified z-test)
        $significance = $this->calculateSignificance($variants, $metrics);

        // Generate conclusion
        $conclusion = [
            'winner' => $winner,
            'scores' => $scores,
            'improvement' => $secondScore > 0 ? round((($winnerScore - $secondScore) / $secondScore) * 100, 1) : 0,
            'significant' => $significance >= 0.95,
            'recommendation' => $significance >= 0.95
                ? "Variant {$winner} is the clear winner. Deploy it."
                : "Results are not statistically significant yet. Consider running longer or with more samples.",
        ];

        DB::table('experiments')->where('id', $experimentId)->update([
            'status' => 'completed',
            'winner_variant' => $winner,
            'statistical_significance' => $significance,
            'conclusion_json' => json_encode($conclusion),
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        // Learn from experiment
        if ($significance >= 0.80) {
            $this->learnFromExperiment($exp, $variants, $conclusion);
        }

        return $conclusion;
    }

    /**
     * Get experiment details.
     */
    public function getExperiment(int $wsId, int $id): ?object
    {
        return DB::table('experiments')->where('workspace_id', $wsId)->where('id', $id)->first();
    }

    /**
     * List experiments for a workspace.
     */
    public function listExperiments(int $wsId, array $filters = []): array
    {
        $q = DB::table('experiments')->where('workspace_id', $wsId);
        if (!empty($filters['engine'])) $q->where('engine', $filters['engine']);
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        return $q->orderByDesc('created_at')->limit($filters['limit'] ?? 20)->get()->toArray();
    }

    /**
     * Compare strategies across campaigns.
     */
    public function compareStrategies(int $wsId, string $engine, int $limit = 10): array
    {
        $outcomes = DB::table('campaign_outcomes')
            ->where('workspace_id', $wsId)
            ->where('engine', $engine)
            ->whereNotNull('effectiveness_score')
            ->orderByDesc('effectiveness_score')
            ->limit($limit)
            ->get();

        if ($outcomes->isEmpty()) return ['strategies' => [], 'insights' => []];

        $best = $outcomes->first();
        $worst = $outcomes->last();

        return [
            'strategies' => $outcomes->map(fn($o) => [
                'type' => $o->campaign_type,
                'effectiveness' => $o->effectiveness_score,
                'strategy' => json_decode($o->strategy_json, true),
                'results' => json_decode($o->results_json, true),
            ])->toArray(),
            'insights' => [
                'best_type' => $best->campaign_type,
                'best_score' => $best->effectiveness_score,
                'worst_type' => $worst->campaign_type,
                'worst_score' => $worst->effectiveness_score,
                'avg_score' => round($outcomes->avg('effectiveness_score'), 2),
                'total_campaigns' => $outcomes->count(),
            ],
        ];
    }

    // ── Private ──────────────────────────────────────────

    private function calculateSignificance(array $variants, array $metrics): float
    {
        if (count($variants) < 2) return 0;

        $a = $variants[0]['results'] ?? [];
        $b = $variants[1]['results'] ?? [];
        $primaryMetric = $metrics[0]['metric'] ?? 'conversion_rate';

        $valueA = $a[$primaryMetric] ?? 0;
        $valueB = $b[$primaryMetric] ?? 0;
        $nA = $a['sample_size'] ?? $a['sent'] ?? $a['impressions'] ?? 100;
        $nB = $b['sample_size'] ?? $b['sent'] ?? $b['impressions'] ?? 100;

        if ($nA < 10 || $nB < 10) return 0.5; // Not enough samples

        // Pooled proportion z-test
        $pA = max(0.001, min(0.999, $valueA));
        $pB = max(0.001, min(0.999, $valueB));
        $pPooled = ($pA * $nA + $pB * $nB) / ($nA + $nB);
        $se = sqrt($pPooled * (1 - $pPooled) * (1/$nA + 1/$nB));

        if ($se <= 0) return 0.5;

        $z = abs($pA - $pB) / $se;

        // Convert z-score to approximate p-value (two-tailed)
        // Using error function approximation
        $significance = 1 - exp(-0.5 * $z * $z) * (0.3989 / (1 + 0.33267 * $z));

        return round(min(0.99, max(0, $significance)), 2);
    }

    private function learnFromExperiment(object $exp, array $variants, array $conclusion): void
    {
        $winner = $conclusion['winner'] ?? 'A';
        $winnerVariant = collect($variants)->firstWhere('id', $winner);
        if (!$winnerVariant) return;

        $workspace = \App\Models\Workspace::find($exp->workspace_id);

        $this->globalKnowledge->recordInsight([
            'category' => $exp->engine,
            'subcategory' => 'ab_test',
            'industry' => $workspace?->industry,
            'region' => $workspace?->location,
            'insight_type' => 'ab_result',
            'insight' => "A/B test '{$exp->name}': Variant {$winner} won with " . ($conclusion['improvement'] ?? 0) . "% improvement. Hypothesis: {$exp->hypothesis}",
            'metrics' => $winnerVariant['results'] ?? [],
            'confidence' => ($conclusion['significant'] ?? false) ? 0.7 : 0.4,
            'source_engine' => $exp->engine,
        ]);
    }
}
