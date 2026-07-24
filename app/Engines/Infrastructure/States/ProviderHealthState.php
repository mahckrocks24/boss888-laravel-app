<?php

namespace App\Engines\Infrastructure\States;

/**
 * Observed health of a provider or one of its capabilities (Phase 2B-4).
 *
 * ⚠️ THIS MACHINE IS DELIBERATELY UNCONSTRAINED IN ITS TRANSITIONS.
 *
 * Every other INFRA888 state machine restricts transitions because those states
 * are DECISIONS — someone chose to publish a plan or retire a product, and an
 * illegal choice should be refused. Health is not a decision. It is an
 * OBSERVATION of a system we do not control.
 *
 * A provider genuinely can go from `healthy` straight to `unauthorized` between
 * two requests, because someone revoked a token in another company's dashboard.
 * Refusing that transition would not prevent it happening — it would only stop us
 * recording the truth. So every non-terminal state may follow any other, and
 * there are no terminal states: nothing observed is permanent.
 *
 * WHAT IS STILL ENFORCED: the state must be a KNOWN state. An unrecognised health
 * verdict is rejected, so a typo cannot silently create a state that no
 * resolution rule matches and that therefore reads as "not unhealthy".
 *
 * "Do not reduce all provider problems to online/offline" (directive §2B-4).
 * These nine states exist because they demand different operator responses:
 *   unauthorized  -> a human with provider access must act; retrying is useless
 *   quota_limited -> retrying is useless until the quota resets
 *   rate_limited  -> retrying is correct, but only after a delay
 *   maintenance   -> expected, scheduled, not an incident
 *   unavailable   -> the provider is down; retry with backoff
 *   degraded      -> works, but slowly or partially
 *   disabled      -> WE turned it off; not a provider fault at all
 */
final class ProviderHealthState extends StateMachine
{
    public const UNKNOWN       = 'unknown';
    public const HEALTHY       = 'healthy';
    public const DEGRADED      = 'degraded';
    public const UNAVAILABLE   = 'unavailable';
    public const UNAUTHORIZED  = 'unauthorized';
    public const QUOTA_LIMITED = 'quota_limited';
    public const RATE_LIMITED  = 'rate_limited';
    public const MAINTENANCE   = 'maintenance';
    public const DISABLED      = 'disabled';

    public static function initial(): string
    {
        return self::UNKNOWN;
    }

    public static function states(): array
    {
        return [
            self::UNKNOWN, self::HEALTHY, self::DEGRADED, self::UNAVAILABLE,
            self::UNAUTHORIZED, self::QUOTA_LIMITED, self::RATE_LIMITED,
            self::MAINTENANCE, self::DISABLED,
        ];
    }

    /** Any known state may follow any other — see the class docblock. */
    public static function transitions(): array
    {
        $all = self::states();

        return array_combine($all, array_fill(0, count($all), $all));
    }

    public static function terminal(): array
    {
        return [];
    }

    /** States in which a capability may be selected for real work. */
    public static function selectable(): array
    {
        // `degraded` is included: a slow provider still beats no provider, and
        // excluding it would turn a partial impairment into a total outage.
        // `unknown` is EXCLUDED — fail closed. Never having checked is not
        // evidence of health.
        return [self::HEALTHY, self::DEGRADED];
    }

    /**
     * Failures that will NOT resolve by waiting. Retrying these is a retry storm
     * against a wall — the caller needs a human, not a backoff timer.
     */
    public static function permanentFailure(): array
    {
        return [self::UNAUTHORIZED, self::DISABLED];
    }

    /** Failures where waiting is the correct response. */
    public static function temporaryFailure(): array
    {
        return [self::UNAVAILABLE, self::RATE_LIMITED, self::QUOTA_LIMITED, self::MAINTENANCE];
    }

    public static function operational(): array
    {
        return [self::HEALTHY];
    }

    public static function failureStates(): array
    {
        return [
            self::DEGRADED, self::UNAVAILABLE, self::UNAUTHORIZED,
            self::QUOTA_LIMITED, self::RATE_LIMITED,
        ];
    }
}
