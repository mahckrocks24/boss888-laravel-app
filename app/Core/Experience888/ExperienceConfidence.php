<?php

namespace App\Core\Experience888;

/**
 * EXPERIENCE888 — deterministic confidence.
 *
 * No model is asked how confident to be. Confidence is computed from evidence
 * and is reproducible: the same counts and dates always give the same answer.
 *
 * The scale is LOW / MEDIUM / HIGH deliberately. The evidence here is a handful
 * of observations from one workspace; reporting "87%" from nine data points is
 * false precision, and false precision is how an assistant sounds authoritative
 * about something it does not know.
 *
 * Inputs that matter, and why:
 *   sample size     one observation is an anecdote; three is the floor
 *   consistency     12 successes out of 12 is not 7 out of 12
 *   contradictions  evidence pointing the other way must cost something
 *   recency         a pattern last seen months ago should not stay HIGH
 *   attribution     OBSERVED evidence is weaker than DIRECTLY_ATTRIBUTABLE
 */
final class ExperienceConfidence
{
    public const LOW = 'LOW';
    public const MEDIUM = 'MEDIUM';
    public const HIGH = 'HIGH';

    /** Below this, nothing is ever more than a hypothesis. */
    public const MIN_SAMPLE_FOR_PATTERN = 3;
    /** HIGH additionally requires this much evidence. */
    public const MIN_SAMPLE_FOR_HIGH = 6;

    /** Days after which a pattern loses one confidence level. */
    public const STALE_AFTER_DAYS = 60;
    /** Days after which a pattern is stale outright and must not be asserted. */
    public const DEAD_AFTER_DAYS = 120;

    public const STATUS_HYPOTHESIS   = 'HYPOTHESIS';
    public const STATUS_PATTERN      = 'PATTERN';
    public const STATUS_EXPERIENCE   = 'EXPERIENCE';
    public const STATUS_CONTRADICTED = 'CONTRADICTED';
    public const STATUS_STALE        = 'STALE';

    /**
     * @param int      $positive       observations supporting the statement
     * @param int      $negative       observations against it
     * @param int      $contradictions evidence explicitly recorded as contradicting
     * @param int|null $ageDays        days since the pattern was last observed
     * @return array{level:string,status:string,consistency:float,sample:int,reason:string}
     */
    public static function evaluate(
        int $positive,
        int $negative,
        int $contradictions = 0,
        ?int $ageDays = null
    ): array {
        $sample = max(0, $positive) + max(0, $negative);

        if ($sample === 0) {
            return self::result(self::LOW, self::STATUS_HYPOTHESIS, 0.0, 0, 'no evidence recorded');
        }

        /**
         * A pattern is always phrased so that "supports" IS the claim, so
         * confidence is the share of evidence supporting the statement — never
         * max(positive, negative). Using the dominant side would score a
         * consistently REFUTED statement as a confident pattern, which is how a
         * learning system ends up asserting the opposite of what it observed.
         */
        $consistency = $positive / $sample;

        // $contradictions is an extra explicit channel for evidence recorded as
        // contradicting outside the normal negative count; the two combine.
        $against = $negative + max(0, $contradictions);

        if ($against > 0 && $against >= $positive) {
            return self::result(self::LOW, self::STATUS_CONTRADICTED, $consistency, $sample,
                "contradicted: {$against} observation(s) against {$positive} supporting");
        }

        $effective = max(0.0, ($positive - max(0, $contradictions)) / $sample);

        if ($sample < self::MIN_SAMPLE_FOR_PATTERN) {
            return self::result(self::LOW, self::STATUS_HYPOTHESIS, $consistency, $sample,
                "only {$sample} observation(s); {" . self::MIN_SAMPLE_FOR_PATTERN . "} required before this is a pattern");
        }

        if ($ageDays !== null && $ageDays > self::DEAD_AFTER_DAYS) {
            return self::result(self::LOW, self::STATUS_STALE, $consistency, $sample,
                "last observed {$ageDays} days ago; beyond the " . self::DEAD_AFTER_DAYS . "-day limit");
        }

        // Base level from consistency and sample size.
        if ($effective >= 0.8 && $sample >= self::MIN_SAMPLE_FOR_HIGH) {
            $level = self::HIGH;
        } elseif ($effective >= 0.6) {
            $level = self::MEDIUM;
        } else {
            $level = self::LOW;
        }

        // Ageing costs one level, never more - the evidence still happened.
        $aged = false;
        if ($ageDays !== null && $ageDays > self::STALE_AFTER_DAYS) {
            $level = self::demote($level);
            $aged = true;
        }

        $status = $level === self::LOW ? self::STATUS_HYPOTHESIS
                : ($level === self::HIGH && $sample >= self::MIN_SAMPLE_FOR_HIGH
                    ? self::STATUS_EXPERIENCE : self::STATUS_PATTERN);

        $pct = (int) round($effective * 100);
        $reason = "{$positive} supporting / {$negative} against"
                . ($contradictions ? " / {$contradictions} contradicting" : '')
                . " across {$sample} observation(s), {$pct}% consistent"
                . ($aged ? ", reduced one level for age ({$ageDays} days)" : '');

        return self::result($level, $status, $consistency, $sample, $reason);
    }

    /** Attribution caps confidence: correlation must never read as proof. */
    public static function capForAttribution(string $level, string $attribution): string
    {
        return match (strtoupper($attribution)) {
            'DIRECTLY_ATTRIBUTABLE' => $level,
            'LIKELY_CONTRIBUTOR'    => self::demote($level),
            'CORRELATED', 'OBSERVED' => self::demote(self::demote($level)),
            default                 => self::LOW,
        };
    }

    public static function demote(string $level): string
    {
        return match ($level) {
            self::HIGH   => self::MEDIUM,
            self::MEDIUM => self::LOW,
            default      => self::LOW,
        };
    }

    public static function rank(string $level): int
    {
        return match ($level) { self::HIGH => 3, self::MEDIUM => 2, default => 1 };
    }

    private static function result(string $l, string $s, float $c, int $n, string $why): array
    {
        return ['level' => $l, 'status' => $s, 'consistency' => round($c, 3),
                'sample' => $n, 'reason' => $why];
    }
}
