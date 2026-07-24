<?php

namespace App\Engines\Infrastructure\States;

/**
 * Lifecycle of a registered provider (Phase 2B-2).
 *
 *   draft       registered, not usable, being configured
 *   testing     usable in sandbox only; never selectable for production
 *   active      fully usable
 *   degraded    usable but impaired — existing resources served, new work discouraged
 *   disabled    deliberately switched off by an operator; nothing routes here
 *   deprecated  no NEW resources; existing ones still served
 *   retired     terminal; no traffic at all
 *
 * WHY `degraded` IS A LIFECYCLE STATE *AND* A HEALTH STATE
 * They mean different things and both are needed. Health `degraded` is an
 * OBSERVATION ("probes are failing"). Lifecycle `degraded` is a DECISION ("an
 * operator has judged this provider impaired"). An observation must never
 * silently become a decision — ProviderHealthService therefore never writes
 * lifecycle_state. A human, or an explicit governed operation, does.
 *
 * `retired` is terminal, matching ProductState: infrastructure history must stay
 * readable forever, so nothing is ever deleted and re-created.
 */
final class ProviderLifecycleState extends StateMachine
{
    public const DRAFT      = 'draft';
    public const TESTING    = 'testing';
    public const ACTIVE     = 'active';
    public const DEGRADED   = 'degraded';
    public const DISABLED   = 'disabled';
    public const DEPRECATED = 'deprecated';
    public const RETIRED    = 'retired';

    public static function initial(): string
    {
        return self::DRAFT;
    }

    public static function states(): array
    {
        return [
            self::DRAFT, self::TESTING, self::ACTIVE, self::DEGRADED,
            self::DISABLED, self::DEPRECATED, self::RETIRED,
        ];
    }

    public static function transitions(): array
    {
        return [
            // A provider cannot jump from draft straight to active: it must be
            // exercised in testing first. This is the whole point of the state.
            self::DRAFT      => [self::TESTING, self::DISABLED, self::RETIRED],
            self::TESTING    => [self::ACTIVE, self::DRAFT, self::DISABLED, self::RETIRED],
            self::ACTIVE     => [self::DEGRADED, self::DISABLED, self::DEPRECATED, self::RETIRED],
            // Recovery is legitimate and common.
            self::DEGRADED   => [self::ACTIVE, self::DISABLED, self::DEPRECATED, self::RETIRED],
            self::DISABLED   => [self::TESTING, self::ACTIVE, self::DEPRECATED, self::RETIRED],
            // A deprecated provider may be revived if its replacement fails.
            self::DEPRECATED => [self::ACTIVE, self::DISABLED, self::RETIRED],
            self::RETIRED    => [],
        ];
    }

    public static function terminal(): array
    {
        return [self::RETIRED];
    }

    /** States in which NEW resources may be created. */
    public static function acceptsNewWork(): array
    {
        return [self::TESTING, self::ACTIVE];
    }

    /** States in which EXISTING resources must continue to be served. */
    public static function serviceable(): array
    {
        return [self::TESTING, self::ACTIVE, self::DEGRADED, self::DEPRECATED];
    }

    /**
     * States in which a provider may be selected for PRODUCTION work.
     * `testing` is deliberately absent — that is the entire safety value of it.
     */
    public static function productionSelectable(): array
    {
        return [self::ACTIVE];
    }

    public static function operational(): array
    {
        return [self::ACTIVE];
    }
}
