<?php

namespace App\Core\Engineer888\Deployment;

use Illuminate\Support\Facades\DB;

/**
 * Compare this verification against the last one.
 *
 * THE RULE THAT MATTERS: if a metric was collected differently, report NOT
 * COMPARABLE rather than a delta. On 2026-07-30 a counting change made the daily
 * brief report "+233 files" when nothing had been added, and a monitoring tool
 * that invents movement is worse than one that says nothing.
 */
final class BaselineComparison
{
    public const NOT_COMPARABLE = 'NOT_COMPARABLE';

    public function baselineFor(string $environment, ?int $excludeId = null): ?object
    {
        $query = DB::table('engineering_deployment_verifications')
            ->where('environment', $environment)
            ->whereIn('verdict', ['VERIFIED', 'HEALTHY_IDENTITY_UNPROVEN'])
            ->orderByDesc('id');

        if ($excludeId !== null) { $query->where('id', '<>', $excludeId); }

        return $query->first();
    }

    /** @return array<string,mixed> */
    public function compare(array $identity, array $checks, ?object $baseline): array
    {
        if ($baseline === null) {
            return [
                'baseline_id' => null,
                'comparable'  => false,
                'reason'      => 'no previous healthy verification exists for this environment; this run '
                               . 'establishes the baseline',
                'regressions' => [],
                'changes'     => [],
            ];
        }

        $previousChecks = json_decode((string) $baseline->checks, true) ?: [];
        $previousIdentity = json_decode((string) $baseline->identity, true) ?: [];

        $regressions = [];
        $changes = [];

        // ── check status regressions ─────────────────────────────────────
        $previousByName = [];
        foreach ($previousChecks as $check) { $previousByName[$check['name']] = $check; }

        foreach ($checks as $check) {
            $previous = $previousByName[$check['name']] ?? null;

            if ($previous === null) {
                $changes[] = ['check' => $check['name'], 'change' => 'new check, not present in the baseline'];
                continue;
            }
            if ($previous['status'] === $check['status']) { continue; }

            $worsened = $this->rank($check['status']) > $this->rank($previous['status']);
            $entry = [
                'check' => $check['name'],
                'from'  => $previous['status'],
                'to'    => $check['status'],
                'detail' => $check['detail'],
            ];

            $worsened ? $regressions[] = $entry : $changes[] = $entry + ['change' => 'improved'];
        }

        // Checks that existed and have vanished.
        foreach ($previousByName as $name => $previous) {
            $stillPresent = false;
            foreach ($checks as $check) { if ($check['name'] === $name) { $stillPresent = true; break; } }
            if (! $stillPresent) {
                $regressions[] = ['check' => $name, 'from' => $previous['status'], 'to' => 'ABSENT',
                    'detail' => 'this check ran against the baseline and did not run now'];
            }
        }

        // ── identity movement ────────────────────────────────────────────
        foreach (['git_head', 'runtime_version', 'critical_file_hashes'] as $field) {
            $now = $identity[$field]['value'] ?? null;
            $then = $previousIdentity[$field]['value'] ?? null;

            $nowConfidence = $identity[$field]['confidence'] ?? Evidence::UNKNOWN;
            $thenConfidence = $previousIdentity[$field]['confidence'] ?? Evidence::UNKNOWN;

            // Different collection confidence means the two numbers do not mean
            // the same thing.
            if ($nowConfidence !== $thenConfidence) {
                $changes[] = [
                    'field'  => $field,
                    'change' => self::NOT_COMPARABLE,
                    'detail' => "collected as {$thenConfidence} then and {$nowConfidence} now — "
                              . 'the two values do not measure the same thing',
                ];
                continue;
            }

            if ($now !== $then) {
                $changes[] = [
                    'field' => $field,
                    'from'  => is_array($then) ? '(' . count($then) . ' entries)' : $then,
                    'to'    => is_array($now) ? '(' . count($now) . ' entries)' : $now,
                    'change' => 'changed since the baseline',
                ];
            }
        }

        return [
            'baseline_id'  => $baseline->id,
            'baseline_at'  => $baseline->created_at,
            'comparable'   => true,
            'reason'       => 'compared against verification #' . $baseline->id,
            'regressions'  => $regressions,
            'changes'      => $changes,
        ];
    }

    private function rank(string $status): int
    {
        return match ($status) {
            SmokeChecks::PASS => 0,
            SmokeChecks::WARN => 1,
            SmokeChecks::UNKNOWN => 2,
            SmokeChecks::FAIL => 3,
            default => 2,
        };
    }
}
