<?php

namespace App\Core\Intelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Global Knowledge Store — THE shared brain across all workspaces.
 *
 * Stores anonymized best practices, trends, and campaign effectiveness data.
 * NEVER leaks company-specific information.
 * Agents use this to make better decisions for new workspaces.
 *
 * Examples:
 *   - "Arabic long-tail keywords in Dubai real estate convert 3.2x better in Q4"
 *   - "Instagram Reels outperform static posts by 4.5x for interior design in MENA"
 *   - "Email campaigns sent Tuesday 10am UAE time have 28% higher open rates"
 */
class GlobalKnowledgeService
{
    /**
     * Record a new insight or strengthen an existing one.
     * Called after every campaign outcome is measured.
     */
    public function recordInsight(array $data): void
    {
        $existing = DB::table('global_knowledge')
            ->where('category', $data['category'])
            ->where('subcategory', $data['subcategory'] ?? null)
            ->where('industry', $data['industry'] ?? null)
            ->where('insight_type', $data['insight_type'])
            ->where('insight', $data['insight'])
            ->first();

        if ($existing) {
            // Strengthen existing insight
            $newDataPoints = $existing->data_points + 1;
            $newConfidence = min(0.99, $existing->confidence + (1 - $existing->confidence) * 0.1);

            // Merge metrics (weighted average)
            $oldMetrics = json_decode($existing->metrics_json ?? '{}', true);
            $newMetrics = $data['metrics'] ?? [];
            $merged = $this->mergeMetrics($oldMetrics, $newMetrics, $existing->data_points);

            DB::table('global_knowledge')->where('id', $existing->id)->update([
                'data_points' => $newDataPoints,
                'confidence' => $newConfidence,
                'metrics_json' => json_encode($merged),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('global_knowledge')->insert([
                'category' => $data['category'],
                'subcategory' => $data['subcategory'] ?? null,
                'industry' => $data['industry'] ?? null,
                'region' => $data['region'] ?? null,
                'insight_type' => $data['insight_type'],
                'insight' => $data['insight'],
                'metrics_json' => json_encode($data['metrics'] ?? []),
                'confidence' => $data['confidence'] ?? 0.3,
                'data_points' => 1,
                'source_engine' => $data['source_engine'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Query knowledge for a specific context.
     * Used by agents when planning campaigns or making recommendations.
     */
    public function query(array $filters = [], int $limit = 20): array
    {
        $q = DB::table('global_knowledge')->where('confidence', '>=', 0.3);

        if (!empty($filters['category'])) $q->where('category', $filters['category']);
        if (!empty($filters['industry'])) $q->where(function ($q2) use ($filters) {
            $q2->where('industry', $filters['industry'])->orWhereNull('industry');
        });
        if (!empty($filters['region'])) $q->where(function ($q2) use ($filters) {
            $q2->where('region', $filters['region'])->orWhere('region', 'global')->orWhereNull('region');
        });
        if (!empty($filters['insight_type'])) $q->where('insight_type', $filters['insight_type']);
        if (!empty($filters['subcategory'])) $q->where('subcategory', $filters['subcategory']);

        return $q->orderByDesc('confidence')
            ->orderByDesc('data_points')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Build a context prompt for an agent based on available knowledge.
     * Returns a string that can be injected into agent reasoning prompts.
     */
    public function buildAgentContext(string $engine, ?string $industry = null, ?string $region = null): string
    {
        $insights = $this->query([
            'category' => $engine,
            'industry' => $industry,
            'region' => $region,
        ], 10);

        if (empty($insights)) return '';

        $lines = ["Available knowledge from past campaigns:"];
        foreach ($insights as $i) {
            $conf = round($i->confidence * 100);
            $lines[] = "- [{$conf}% confidence, {$i->data_points} data points] {$i->insight}";
        }

        return implode("\n", $lines);
    }

    /**
     * Extract and store learnings from a campaign outcome.
     * Anonymizes company-specific data before storing globally.
     */
    public function learnFromOutcome(int $wsId, string $engine, string $campaignType, array $strategy, array $results, ?string $industry = null, ?string $region = null): void
    {
        // Store raw outcome per workspace (not anonymized)
        DB::table('campaign_outcomes')->insert([
            'workspace_id' => $wsId,
            'engine' => $engine,
            'campaign_type' => $campaignType,
            'industry' => $industry,
            'region' => $region,
            'strategy_json' => json_encode($strategy),
            'results_json' => json_encode($results),
            'effectiveness_score' => $this->calculateEffectiveness($results),
            'learnings_json' => json_encode($this->extractLearnings($strategy, $results)),
            'contributed_to_global' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Contribute anonymized patterns to global knowledge
        $this->contributeToGlobal($engine, $campaignType, $strategy, $results, $industry, $region);
    }

    /**
     * Get top-performing strategies for a given context.
     * Used by Strategy Room and multi-step planner.
     */
    public function getTopStrategies(string $engine, ?string $industry = null, int $limit = 5): array
    {
        $q = DB::table('campaign_outcomes')
            ->where('engine', $engine)
            ->whereNotNull('effectiveness_score')
            ->where('effectiveness_score', '>', 0.5);

        if ($industry) $q->where('industry', $industry);

        return $q->orderByDesc('effectiveness_score')
            ->limit($limit)
            ->select('campaign_type', 'strategy_json', 'results_json', 'effectiveness_score', 'industry', 'region')
            ->get()
            ->toArray();
    }

    // ── Private ──────────────────────────────────────────

    private function mergeMetrics(array $old, array $new, int $oldWeight): array
    {
        $merged = $old;
        foreach ($new as $k => $v) {
            if (isset($merged[$k]) && is_numeric($merged[$k]) && is_numeric($v)) {
                // Weighted average
                $merged[$k] = round(($merged[$k] * $oldWeight + $v) / ($oldWeight + 1), 4);
            } else {
                $merged[$k] = $v;
            }
        }
        return $merged;
    }

    private function calculateEffectiveness(array $results): float
    {
        $score = 0;
        $factors = 0;

        if (isset($results['conversion_rate'])) { $score += min($results['conversion_rate'] / 0.05, 1); $factors++; }
        if (isset($results['ctr'])) { $score += min($results['ctr'] / 0.03, 1); $factors++; }
        if (isset($results['engagement_rate'])) { $score += min($results['engagement_rate'] / 0.05, 1); $factors++; }
        if (isset($results['roi'])) { $score += min($results['roi'] / 3, 1); $factors++; }
        if (isset($results['open_rate'])) { $score += min($results['open_rate'] / 0.3, 1); $factors++; }

        return $factors > 0 ? round($score / $factors, 2) : 0.5;
    }

    private function extractLearnings(array $strategy, array $results): array
    {
        // Extract key patterns from strategy that correlated with results
        return [
            'strategy_keys' => array_keys($strategy),
            'result_keys' => array_keys($results),
            'effective' => ($this->calculateEffectiveness($results) > 0.6),
        ];
    }

    private function contributeToGlobal(string $engine, string $type, array $strategy, array $results, ?string $industry, ?string $region): void
    {
        $effectiveness = $this->calculateEffectiveness($results);
        if ($effectiveness < 0.3) return; // Only learn from reasonably successful campaigns

        // Create anonymized insight
        $insight = "{$type} campaign" . ($industry ? " in {$industry}" : '') . " achieved " . round($effectiveness * 100) . "% effectiveness";

        $this->recordInsight([
            'category' => $engine,
            'subcategory' => $type,
            'industry' => $industry,
            'region' => $region,
            'insight_type' => 'ab_result',
            'insight' => $insight,
            'metrics' => array_intersect_key($results, array_flip(['ctr', 'conversion_rate', 'engagement_rate', 'roi', 'open_rate'])),
            'confidence' => min(0.5, $effectiveness),
            'source_engine' => $engine,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // TIME-BASED INTELLIGENCE
    // ═══════════════════════════════════════════════════════════

    /**
     * Detect seasonal patterns from campaign outcomes.
     * e.g. "Q4 campaigns in real estate perform 40% better in MENA"
     */
    public function getSeasonalPatterns(?string $engine = null, ?string $industry = null): array
    {
        $q = DB::table('campaign_outcomes')
            ->whereNotNull('effectiveness_score');

        if ($engine) $q->where('engine', $engine);
        if ($industry) $q->where('industry', $industry);

        $monthly = $q->select(
                DB::raw("MONTH(created_at) as month"),
                DB::raw("AVG(effectiveness_score) as avg_effectiveness"),
                DB::raw("COUNT(*) as campaign_count")
            )
            ->groupBy('month')
            ->having('campaign_count', '>=', 2)
            ->orderBy('month')
            ->get();

        if ($monthly->isEmpty()) return ['patterns' => [], 'insight' => 'Not enough data for seasonal analysis'];

        $avgOverall = $monthly->avg('avg_effectiveness');
        $patterns = [];
        $monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        foreach ($monthly as $m) {
            $deviation = $avgOverall > 0 ? round((($m->avg_effectiveness - $avgOverall) / $avgOverall) * 100, 1) : 0;
            if (abs($deviation) >= 10) {
                $patterns[] = [
                    'month' => $m->month,
                    'month_name' => $monthNames[$m->month] ?? '',
                    'avg_effectiveness' => round($m->avg_effectiveness, 2),
                    'deviation_percent' => $deviation,
                    'campaigns' => $m->campaign_count,
                    'insight' => $deviation > 0
                        ? "{$monthNames[$m->month]} performs {$deviation}% above average"
                        : "{$monthNames[$m->month]} performs " . abs($deviation) . "% below average",
                ];
            }
        }

        // Auto-generate seasonal insight for global knowledge
        foreach ($patterns as $p) {
            if ($p['campaigns'] >= 3 && abs($p['deviation_percent']) >= 20) {
                $this->recordInsight([
                    'category' => $engine ?? 'general',
                    'subcategory' => 'seasonal_pattern',
                    'industry' => $industry,
                    'insight_type' => 'trend',
                    'insight' => $p['insight'] . ($industry ? " in {$industry}" : ''),
                    'metrics' => ['deviation' => $p['deviation_percent'], 'month' => $p['month']],
                    'confidence' => min(0.8, 0.3 + ($p['campaigns'] * 0.05)),
                ]);
            }
        }

        return [
            'patterns' => $patterns,
            'monthly_data' => $monthly->toArray(),
            'overall_avg' => round($avgOverall, 2),
            'best_month' => $monthly->sortByDesc('avg_effectiveness')->first()?->month,
            'worst_month' => $monthly->sortBy('avg_effectiveness')->first()?->month,
        ];
    }

    /**
     * Track trends over time for a specific metric/category.
     * Detects if effectiveness is improving or declining.
     */
    public function getTrends(?string $engine = null, ?string $industry = null, int $months = 6): array
    {
        $q = DB::table('campaign_outcomes')
            ->where('created_at', '>=', now()->subMonths($months))
            ->whereNotNull('effectiveness_score');

        if ($engine) $q->where('engine', $engine);
        if ($industry) $q->where('industry', $industry);

        $monthly = $q->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as period"),
                DB::raw("AVG(effectiveness_score) as avg_effectiveness"),
                DB::raw("COUNT(*) as campaigns"),
                DB::raw("SUM(CASE WHEN effectiveness_score >= 0.6 THEN 1 ELSE 0 END) as successful")
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        if ($monthly->count() < 2) return ['trend' => 'insufficient_data', 'data' => $monthly->toArray()];

        // Calculate trend direction
        $values = $monthly->pluck('avg_effectiveness')->toArray();
        $first = array_slice($values, 0, (int) ceil(count($values) / 2));
        $second = array_slice($values, (int) ceil(count($values) / 2));

        $firstAvg = count($first) > 0 ? array_sum($first) / count($first) : 0;
        $secondAvg = count($second) > 0 ? array_sum($second) / count($second) : 0;

        $trendDirection = $secondAvg > $firstAvg * 1.05 ? 'improving' : ($secondAvg < $firstAvg * 0.95 ? 'declining' : 'stable');
        $changePercent = $firstAvg > 0 ? round((($secondAvg - $firstAvg) / $firstAvg) * 100, 1) : 0;

        return [
            'trend' => $trendDirection,
            'change_percent' => $changePercent,
            'data' => $monthly->toArray(),
            'total_campaigns' => $monthly->sum('campaigns'),
            'overall_success_rate' => $monthly->sum('campaigns') > 0
                ? round(($monthly->sum('successful') / $monthly->sum('campaigns')) * 100, 1) : 0,
        ];
    }

    /**
     * Campaign lifecycle learning — what works at each stage.
     * Tracks: launch → growth → maturity → decline
     */
    public function getLifecycleInsights(?string $engine = null): array
    {
        $outcomes = DB::table('campaign_outcomes')
            ->when($engine, fn($q) => $q->where('engine', $engine))
            ->whereNotNull('effectiveness_score')
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        if ($outcomes->count() < 5) return ['insight' => 'Not enough campaigns for lifecycle analysis'];

        // Split into quartiles (early, mid, late, recent)
        $chunks = $outcomes->chunk(max(1, (int) ceil($outcomes->count() / 4)));
        $phases = ['launch', 'growth', 'maturity', 'optimization'];

        $lifecycle = [];
        foreach ($chunks as $i => $chunk) {
            $phase = $phases[$i] ?? 'optimization';
            $lifecycle[] = [
                'phase' => $phase,
                'campaigns' => $chunk->count(),
                'avg_effectiveness' => round($chunk->avg('effectiveness_score'), 2),
                'best_type' => $chunk->sortByDesc('effectiveness_score')->first()?->campaign_type,
            ];
        }

        return [
            'phases' => $lifecycle,
            'current_phase' => $phases[min(count($chunks) - 1, 3)],
            'learning_velocity' => count($lifecycle) >= 2
                ? round(($lifecycle[count($lifecycle) - 1]['avg_effectiveness'] - $lifecycle[0]['avg_effectiveness']) * 100, 1) . '% improvement'
                : 'calculating',
        ];
    }
}
