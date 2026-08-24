<?php

namespace App\Engines\Infrastructure\Observation;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · S8.4 — ASSET LIFECYCLE & OBSERVATION CONFIDENCE.
 *
 * S8.3 blocked internal scaffolding by behaviour rather than by hostname, which
 * was right — but it had a hole I flagged and am now closing: the test was
 * "registered by us, serves no website, never resolved". A brand-new customer
 * domain matches that description **for its first few hours**, before DNS
 * propagates and before the website is attached.
 *
 * Under S8.3 that domain would have been classified INTERNAL and its alerts
 * silently dropped — the worst possible failure, because it fails silently on
 * exactly the asset a customer just paid for.
 *
 * The fix is time and lifecycle, not more predicates. "Never resolved" only
 * means scaffolding once enough time and enough attempts have passed for
 * propagation to be a spent excuse. Before that, the honest answer is
 * PENDING_VALIDATION: not internal, and not yet trusted either.
 */
final class AssetLifecycle
{
    // ── lifecycle states ─────────────────────────────────────────────────────
    /** Seen somewhere, not yet placed in the estate. */
    public const DISCOVERED = 'discovered';
    /** In the estate, never observed. */
    public const IMPORTED = 'imported';
    /** Observed, but not yet proven to resolve or serve. Cold start lives here. */
    public const PENDING_VALIDATION = 'pending_validation';
    /** Resolves. Real DNS evidence exists. */
    public const OBSERVED = 'observed';
    /** Resolves AND serves a website: carrying customer traffic. */
    public const OPERATIONAL = 'operational';
    /** Released, expired or suspended. */
    public const RETIRED = 'retired';
    /** Proven scaffolding: past grace, repeatedly attempted, never resolved, serves nothing. */
    public const INTERNAL = 'internal';

    // ── observation confidence ladder ────────────────────────────────────────
    public const C_UNKNOWN = 'unknown';
    public const C_LOW = 'low';
    public const C_OBSERVED = 'observed';
    public const C_VERIFIED = 'verified';
    public const C_CUSTOMER_CONFIRMED = 'customer_confirmed';

    /**
     * How long a newly-added asset is given before "never resolved" is allowed
     * to mean "internal". DNS propagation is minutes-to-hours; a customer
     * onboarding over a weekend is days. 72h is deliberately generous, because
     * the cost of waiting is a delayed alert and the cost of being wrong is a
     * silently dropped one.
     */
    public const COLD_START_GRACE_HOURS = 72;

    /** Failed observations required before scaffolding is asserted. */
    public const MIN_FAILED_OBSERVATIONS = 3;

    /** @return array<int,string> worst-to-best ordering for confidence. */
    public static function confidenceLadder(): array
    {
        return [self::C_UNKNOWN, self::C_LOW, self::C_OBSERVED, self::C_VERIFIED, self::C_CUSTOMER_CONFIRMED];
    }

    public static function confidenceRank(string $c): int
    {
        $i = array_search($c, self::confidenceLadder(), true);

        return $i === false ? 0 : (int) $i;
    }

    public static function atLeast(string $actual, string $required): bool
    {
        return self::confidenceRank($actual) >= self::confidenceRank($required);
    }

    /**
     * Resolve lifecycle from evidence. Pure read.
     *
     * @return array{state:string,reason:string,age_hours:?int,failed_observations:int,confidence:string}
     */
    public static function resolve(string $subject, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ?: now();
        $host = strtolower(trim($subject));

        $custody = Custody::resolve($host);

        $cd = $custody['customer_domain_id'] !== null
            ? DB::table('customer_domains')->where('id', $custody['customer_domain_id'])->first()
            : null;

        // Retired outranks everything: a released asset is not an incident.
        if ($cd !== null && in_array($cd->status, ['released', 'expired', 'suspended'], true)) {
            return self::r(self::RETIRED, "asset status is '{$cd->status}'", null, 0, self::C_UNKNOWN);
        }

        $servedByUs = DB::table('websites')->whereNull('deleted_at')
            ->whereRaw('LOWER(custom_domain) = ?', [$host])->exists()
            || DB::table('custom_domains')->whereRaw('LOWER(hostname) = ?', [$host])->exists();

        $facts = DB::table('infra_observation_facts')->where('subject', $host)->get();

        if ($custody['custody'] === Custody::UNKNOWN && $facts->isEmpty() && $cd === null && ! $servedByUs) {
            return self::r(self::DISCOVERED, 'no platform record and no observation', null, 0, self::C_UNKNOWN);
        }

        if ($facts->isEmpty()) {
            return self::r(self::IMPORTED, 'in the estate but never observed', null, 0, self::C_UNKNOWN);
        }

        $resolved = $facts->first(fn ($f) => $f->dimension === 'dns' && (bool) $f->success) !== null;
        $failedDns = $facts->filter(fn ($f) => $f->dimension === 'dns' && ! (bool) $f->success)->count();

        // Age from the earliest thing we know about this asset.
        $created = $cd->created_at ?? $facts->min('observed_at');
        $ageHours = $created !== null ? (int) Carbon::parse($created)->diffInHours($asOf) : null;

        $confidence = self::confidenceOf($facts, $servedByUs, $resolved, $cd);

        if ($resolved && $servedByUs) {
            return self::r(self::OPERATIONAL, 'resolves and serves a website', $ageHours, $failedDns, $confidence);
        }

        if ($resolved) {
            return self::r(self::OBSERVED, 'resolves in DNS', $ageHours, $failedDns, $confidence);
        }

        // Never resolved. Scaffolding ONLY once propagation has stopped being a
        // credible explanation — grace elapsed AND repeatedly attempted AND
        // serving nothing.
        $pastGrace = $ageHours !== null && $ageHours >= self::COLD_START_GRACE_HOURS;
        $attemptedEnough = $failedDns >= self::MIN_FAILED_OBSERVATIONS;

        if (! $servedByUs && $pastGrace && $attemptedEnough && $custody['custody'] === Custody::MANAGED_BY_US) {
            return self::r(self::INTERNAL,
                "registered by us, serves nothing, and has failed to resolve {$failedDns} time(s) over {$ageHours}h",
                $ageHours, $failedDns, $confidence);
        }

        $why = match (true) {
            ! $pastGrace => 'within the ' . self::COLD_START_GRACE_HOURS . 'h cold-start grace period (age ' . ($ageHours ?? 0) . 'h) — DNS may still be propagating',
            ! $attemptedEnough => "only {$failedDns} failed DNS observation(s); " . self::MIN_FAILED_OBSERVATIONS . ' required before asserting scaffolding',
            $servedByUs => 'serves a website, so it is not scaffolding regardless of DNS',
            default => 'not registered by us, so scaffolding cannot be asserted',
        };

        return self::r(self::PENDING_VALIDATION, $why, $ageHours, $failedDns, $confidence);
    }

    /**
     * Confidence ladder, from what we can actually stand behind.
     *
     * @param \Illuminate\Support\Collection $facts
     */
    private static function confidenceOf($facts, bool $servedByUs, bool $resolved, $cd): string
    {
        $successful = $facts->filter(fn ($f) => (bool) $f->success);

        if ($successful->isEmpty()) {
            return self::C_UNKNOWN;
        }

        // The customer attached it to a live website and it resolves: they have
        // effectively confirmed this is theirs and in use.
        if ($servedByUs && $resolved && ($cd === null || (int) ($cd->domain_verified ?? 0) !== 0 || $cd !== null)) {
            return self::C_CUSTOMER_CONFIRMED;
        }

        $hasVerified = $successful->first(fn ($f) => $f->confidence === Custody::VERIFIED) !== null;

        if ($hasVerified && $resolved) {
            return self::C_VERIFIED;
        }

        if ($resolved) {
            return self::C_OBSERVED;
        }

        return self::C_LOW;
    }

    /** @return array<string,mixed> */
    private static function r(string $state, string $reason, ?int $age, int $failed, string $confidence): array
    {
        return [
            'state' => $state,
            'reason' => $reason,
            'age_hours' => $age,
            'failed_observations' => $failed,
            'confidence' => $confidence,
        ];
    }

    /** Lifecycle states whose alerts may never leave INFRA888. */
    public static function blockedStates(): array
    {
        return [self::INTERNAL, self::RETIRED];
    }

    /** Lifecycle states where an alert is real but not yet trustworthy enough to interrupt. */
    public static function deferredStates(): array
    {
        return [self::DISCOVERED, self::IMPORTED, self::PENDING_VALIDATION];
    }
}
