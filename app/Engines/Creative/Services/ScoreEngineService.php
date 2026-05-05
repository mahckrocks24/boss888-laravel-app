<?php

namespace App\Engines\Creative\Services;

/**
 * ScoreEngineService — Creative888 Blueprint Scoring Engine
 *
 * Ported from WP class-lucreative-score-engine.php (Phase 3B).
 *
 * Computes dynamic scores for blueprints based on usage frequency,
 * recency, and external signal influence.
 *
 * Formula:
 *   score = (usage_count * 1.0) + recency_bonus - decay_penalty + external_influence
 *
 *   recency_bonus:
 *     +2  used within last 24 hours
 *     +1  used within last 7 days
 *      0  otherwise
 *
 *   decay_penalty:
 *     +2  not used in 30+ days (strong decay)
 *     +1  not used in 7+ days  (mild decay)
 *      0  used recently
 *
 *   external_influence:
 *     (external_score / max(1, external_count)) * 2.0
 *
 * NEVER throws. Returns a float, minimum 0.0.
 * No external calls. No DB access.
 */
class ScoreEngineService
{
    // Recency thresholds (seconds)
    const RECENT_24H = 86400;    // 1 day
    const RECENT_7D  = 604800;   // 7 days
    const RECENT_30D = 2592000;  // 30 days

    /**
     * Compute a dynamic score for a blueprint.
     *
     * @param  int         $usageCount    Number of times this blueprint has been used.
     * @param  string|null $lastUsedAt    MySQL datetime of last usage, or empty/null if never used.
     * @param  float       $externalScore Accumulated external signal score.
     * @param  int         $externalCount Number of external signals received.
     * @return float  Computed score, minimum 0.0.
     */
    public function computeScore(int $usageCount, ?string $lastUsedAt, float $externalScore = 0.0, int $externalCount = 0): float
    {
        try {
            $base = (float) max(0, $usageCount);

            $externalInfluence = $externalCount > 0
                ? ($externalScore / max(1, $externalCount)) * 2.0
                : 0.0;

            // Never used — only mild decay applies
            if (empty($lastUsedAt)) {
                $base = max(0.0, $base - 1.0);
                return max(0.0, $base + $externalInfluence);
            }

            $lastTs = strtotime($lastUsedAt);
            if ($lastTs === false || $lastTs <= 0) {
                return max(0.0, $base + $externalInfluence);
            }

            $elapsed = time() - $lastTs;

            // Recency bonus (mutually exclusive — best tier wins)
            $recencyBonus = 0.0;
            if ($elapsed <= self::RECENT_24H) {
                $recencyBonus = 2.0;
            } elseif ($elapsed <= self::RECENT_7D) {
                $recencyBonus = 1.0;
            }

            // Decay penalty (mutually exclusive — worst tier wins)
            $decayPenalty = 0.0;
            if ($elapsed >= self::RECENT_30D) {
                $decayPenalty = 2.0;
            } elseif ($elapsed >= self::RECENT_7D) {
                $decayPenalty = 1.0;
            }

            return max(0.0, $base + $recencyBonus - $decayPenalty + $externalInfluence);

        } catch (\Throwable $e) {
            // Fail-safe — return raw usage_count as floor
            return max(0.0, (float) $usageCount);
        }
    }
}
