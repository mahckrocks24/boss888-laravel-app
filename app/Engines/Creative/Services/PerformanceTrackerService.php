<?php

namespace App\Engines\Creative\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PerformanceTrackerService — Creative888 Performance Feedback Loop
 *
 * Ported from WP class-lucreative-performance-tracker.php (Phase 3A).
 *
 * Tracks which blueprints were applied during generation and updates their scores.
 * NEVER throws — silently skips on any failure.
 * NEVER modifies generation behavior — purely a data-tracking layer.
 */
class PerformanceTrackerService
{
    public function __construct(
        private ScoreEngineService $scoreEngine,
    ) {}

    /**
     * Increment usage stats for a set of blueprint IDs.
     *
     * @param  array $blueprintIds  Array of blueprint row IDs to update.
     * @return void  Always void — failures are silently logged.
     */
    public function trackUsage(array $blueprintIds): void
    {
        $ids = array_filter(array_map('intval', $blueprintIds));
        if (empty($ids)) {
            return;
        }

        try {
            if (!DB::getSchemaBuilder()->hasTable('creative_blueprints')) {
                return;
            }

            $now = now();

            // Atomic update: usage_count, score, last_used_at
            DB::table('creative_blueprints')
                ->whereIn('id', $ids)
                ->update([
                    'usage_count' => DB::raw('usage_count + 1'),
                    'score'       => DB::raw('score + 1'),
                    'last_used_at' => $now,
                    'updated_at'   => $now,
                ]);

            // Recompute and persist accurate scores for each updated blueprint
            $rows = DB::table('creative_blueprints')
                ->whereIn('id', $ids)
                ->get(['id', 'usage_count', 'last_used_at', 'external_score', 'external_count']);

            foreach ($rows as $row) {
                $newScore = $this->scoreEngine->computeScore(
                    (int) $row->usage_count,
                    (string) ($row->last_used_at ?? ''),
                    (float) ($row->external_score ?? 0.0),
                    (int) ($row->external_count ?? 0)
                );

                DB::table('creative_blueprints')
                    ->where('id', $row->id)
                    ->update(['score' => $newScore]);
            }

            Log::debug('[CREATIVE888 PerformanceTracker] Tracked ' . count($ids) . ' blueprint(s): IDs ' . implode(', ', $ids));

        } catch (\Throwable $e) {
            Log::warning('[CREATIVE888 PerformanceTracker] trackUsage exception (skipped): ' . $e->getMessage());
        }
    }
}
