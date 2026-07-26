<?php

namespace App\Engines\Studio\Readiness;

use App\Models\CreativeJob;
use Illuminate\Support\Facades\Schema;

/**
 * STUDIO888 Phase L — READ-ONLY aggregate readiness reporting over CreativeJob
 * records. Deterministic; never mutates a job; never activates anything. Reports
 * a state only: INSUFFICIENT_DATA | NOT_READY | REVIEW_REQUIRED | READY_FOR_CANARY_REVIEW.
 */
class CompilerReadinessService
{
    // Reporting thresholds (indicators only — they enable nothing).
    public const MIN_COMPARABLE_JOBS      = 100;
    public const NORMALIZED_AGREEMENT_PCT = 95.0;
    public const CRITICAL_CONFLICT_PCT    = 0.5;
    public const GUARDRAIL_FAIL_PCT       = 1.0;
    public const MISSING_OBSERVATION_PCT  = 1.0;
    public const CAPTURE_SUCCESS_PCT      = 99.0;

    public const STATE_INSUFFICIENT = 'INSUFFICIENT_DATA';
    public const STATE_NOT_READY    = 'NOT_READY';
    public const STATE_REVIEW       = 'REVIEW_REQUIRED';
    public const STATE_CANARY       = 'READY_FOR_CANARY_REVIEW';

    public function report(?int $workspaceId = null): array
    {
        if (! $this->tableAvailable()) {
            return ['state' => self::STATE_INSUFFICIENT, 'reason' => 'creative_jobs unavailable', 'jobs_observed' => 0, 'jobs_comparable' => 0];
        }

        $q = CreativeJob::query();
        if ($workspaceId !== null) {
            $q->where('workspace_id', $workspaceId);
        }
        $jobs = $q->get();

        $observed = 0; $comparable = 0; $missingObs = 0;
        $divergence = []; $verdicts = [];
        $normalizedAgree = 0; $exactAgree = 0; $capabilityAgree = 0; $aspectAgree = 0;
        $wouldPass = 0; $wouldReview = 0; $wouldFail = 0;
        $conflicts = 0; $scores = [];
        $compFailures = 0; $compilerFailures = 0; $guardrailFailures = 0;
        $corr = ['pass_success' => 0, 'pass_failure' => 0, 'fail_success' => 0, 'fail_failure' => 0];

        foreach ($jobs as $job) {
            $meta = is_array($job->metadata) ? $job->metadata : [];

            if (! isset($meta['compiler'])) { $compilerFailures++; }
            if (isset($meta['compiler']) && ! isset($meta['guardrails'])) { $guardrailFailures++; }

            $exObs = $meta['execution_observation'] ?? null;
            $r = $meta['compiler_readiness'] ?? null;

            if (is_array($exObs)) { $observed++; }
            if ($r === null) { continue; }

            if (! empty($r['comparison_failed'])) { $compFailures++; continue; }

            $class = $r['divergence_class'] ?? 'UNCOMPARABLE';
            $divergence[$class] = ($divergence[$class] ?? 0) + 1;

            if ($class === PromptComparisonService::MISSING_OBSERVATION) { $missingObs++; }

            if (in_array($class, [PromptComparisonService::MISSING_COMPILED, PromptComparisonService::MISSING_OBSERVATION, PromptComparisonService::UNCOMPARABLE], true)) {
                continue;
            }

            $comparable++;
            if (! empty($r['normalized_identical'])) { $normalizedAgree++; }
            if (! empty($r['byte_identical'])) { $exactAgree++; }
            if (! empty($r['capability_match'])) { $capabilityAgree++; }
            if (! empty($r['aspect_ratio_match'])) { $aspectAgree++; }
            if ($class === PromptComparisonService::CONFLICTING) { $conflicts++; }
            $scores[] = (int) ($r['readiness_score'] ?? 0);

            $verdict = $r['guardrail_verdict'] ?? null;
            if ($verdict !== null) { $verdicts[$verdict] = ($verdicts[$verdict] ?? 0) + 1; }
            if (! empty($r['would_pass'])) { $wouldPass++; }
            if (! empty($r['would_review'])) { $wouldReview++; }
            if (! empty($r['would_fail'])) { $wouldFail++; }

            // Neutral observed-outcome correlation (NOT labelled true/false positive).
            $succeeded = ($job->status === 'completed');
            if (! empty($r['would_pass'])) { $corr[$succeeded ? 'pass_success' : 'pass_failure']++; }
            if (! empty($r['would_fail'])) { $corr[$succeeded ? 'fail_success' : 'fail_failure']++; }
        }

        $pct = fn (int $n, int $d) => $d === 0 ? 0.0 : round($n / $d * 100, 2);
        $normalizedRate = $pct($normalizedAgree, $comparable);
        $conflictRate   = $pct($conflicts, $comparable);
        $failRate       = $pct($wouldFail, $comparable);
        $missingRate    = $pct($missingObs, max(1, $observed));

        $state = $this->state($comparable, $normalizedRate, $conflictRate, $failRate, $missingRate);

        sort($scores);
        return [
            'state'                    => $state,
            'jobs_observed'            => $observed,
            'jobs_comparable'          => $comparable,
            'missing_observation_rate' => $missingRate,
            'divergence_distribution'  => $divergence,
            'normalized_agreement_rate'=> $normalizedRate,
            'exact_agreement_rate'     => $pct($exactAgree, $comparable),
            'capability_agreement_rate'=> $pct($capabilityAgree, $comparable),
            'aspect_ratio_agreement_rate' => $pct($aspectAgree, $comparable),
            'conflict_rate'            => $conflictRate,
            'guardrail_verdict_distribution' => $verdicts,
            'would_pass'               => $wouldPass,
            'would_review'             => $wouldReview,
            'would_fail'               => $wouldFail,
            'guardrail_fail_rate'      => $failRate,
            'average_readiness_score'  => empty($scores) ? 0.0 : round(array_sum($scores) / count($scores), 2),
            'readiness_percentiles'    => $this->percentiles($scores),
            'observed_outcome_correlation' => $corr,
            'comparison_failures'      => $compFailures,
            'compiler_failures'        => $compilerFailures,
            'guardrail_failures'       => $guardrailFailures,
            'thresholds'               => [
                'min_comparable_jobs'       => self::MIN_COMPARABLE_JOBS,
                'normalized_agreement_pct'  => self::NORMALIZED_AGREEMENT_PCT,
                'critical_conflict_pct'     => self::CRITICAL_CONFLICT_PCT,
                'guardrail_fail_pct'        => self::GUARDRAIL_FAIL_PCT,
                'missing_observation_pct'   => self::MISSING_OBSERVATION_PCT,
            ],
        ];
    }

    /**
     * Phase N — compact V1-vs-V2 readiness side-by-side (read-only). Reads
     * compiler_readiness (V1) and compiler_readiness_v2 (V2) independently; neither
     * overwrites the other, so historical reports stay reproducible.
     */
    public function readinessByVersion(?int $workspaceId = null): array
    {
        if (! $this->tableAvailable()) {
            return ['v1' => ['state' => self::STATE_INSUFFICIENT, 'jobs_comparable' => 0],
                    'v2' => ['state' => self::STATE_INSUFFICIENT, 'jobs_comparable' => 0]];
        }
        $q = CreativeJob::query();
        if ($workspaceId !== null) {
            $q->where('workspace_id', $workspaceId);
        }
        $jobs = $q->get();
        return [
            'v1' => $this->summariseVersion($jobs, 'compiler_readiness'),
            'v2' => $this->summariseVersion($jobs, 'compiler_readiness_v2'),
        ];
    }

    private function summariseVersion($jobs, string $key): array
    {
        $comparable = 0; $norm = 0; $exact = 0; $conflicts = 0; $missing = 0; $compFail = 0;
        $dist = []; $wouldPass = 0; $scores = [];
        foreach ($jobs as $job) {
            $meta = is_array($job->metadata) ? $job->metadata : [];
            $r = $meta[$key] ?? null;
            if ($r === null) { continue; }
            if (! empty($r['comparison_failed'])) { $compFail++; continue; }
            $class = $r['divergence_class'] ?? 'UNCOMPARABLE';
            $dist[$class] = ($dist[$class] ?? 0) + 1;
            if ($class === PromptComparisonService::MISSING_OBSERVATION) { $missing++; }
            if (in_array($class, [PromptComparisonService::MISSING_COMPILED, PromptComparisonService::MISSING_OBSERVATION, PromptComparisonService::UNCOMPARABLE], true)) {
                continue;
            }
            $comparable++;
            if (! empty($r['normalized_identical'])) { $norm++; }
            if (! empty($r['byte_identical'])) { $exact++; }
            if ($class === PromptComparisonService::CONFLICTING) { $conflicts++; }
            if (! empty($r['would_pass'])) { $wouldPass++; }
            $scores[] = (int) ($r['readiness_score'] ?? 0);
        }
        $pct = fn (int $n, int $d) => $d === 0 ? 0.0 : round($n / $d * 100, 2);
        $normRate = $pct($norm, $comparable);
        $conflictRate = $pct($conflicts, $comparable);
        return [
            'state'                     => $this->state($comparable, $normRate, $conflictRate, 0.0, 0.0),
            'jobs_comparable'           => $comparable,
            'divergence_distribution'   => $dist,
            'normalized_agreement_rate' => $normRate,
            'exact_agreement_rate'      => $pct($exact, $comparable),
            'conflict_rate'             => $conflictRate,
            'would_pass'                => $wouldPass,
            'average_readiness_score'   => empty($scores) ? 0.0 : round(array_sum($scores) / count($scores), 2),
            'comparison_failures'       => $compFail,
            'missing_observation'       => $missing,
        ];
    }

    private function state(int $comparable, float $normRate, float $conflictRate, float $failRate, float $missingRate): string
    {
        if ($comparable < self::MIN_COMPARABLE_JOBS) {
            return self::STATE_INSUFFICIENT;
        }
        if ($normRate >= self::NORMALIZED_AGREEMENT_PCT
            && $conflictRate <= self::CRITICAL_CONFLICT_PCT
            && $failRate <= self::GUARDRAIL_FAIL_PCT
            && $missingRate <= self::MISSING_OBSERVATION_PCT) {
            return self::STATE_CANARY;
        }
        if ($normRate >= 80.0 && $conflictRate <= 2.0) {
            return self::STATE_REVIEW;
        }
        return self::STATE_NOT_READY;
    }

    private function percentiles(array $sorted): array
    {
        if (empty($sorted)) return ['p50' => 0, 'p90' => 0, 'p99' => 0];
        $at = function (float $p) use ($sorted) {
            $idx = (int) floor($p * (count($sorted) - 1));
            return $sorted[$idx];
        };
        return ['p50' => $at(0.50), 'p90' => $at(0.90), 'p99' => $at(0.99)];
    }

    private function tableAvailable(): bool
    {
        try {
            return Schema::hasTable('creative_jobs');
        } catch (\Throwable) {
            return false;
        }
    }
}
