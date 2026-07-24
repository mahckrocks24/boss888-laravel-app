<?php

namespace App\Engines\Infrastructure\States;

/**
 * Catalog lifecycle of a PLAN VERSION.
 *
 * Plans are versioned, and a version is immutable once it has subscribers.
 * Publishing a price change means inserting version+1 and marking the previous
 * version `superseded` — never editing the row a customer bought.
 *
 *   draft      being prepared, never sold
 *   current    the version new customers buy
 *   superseded replaced by a newer version; existing subscribers grandfathered
 *   withdrawn  not sellable and not serviceable; requires a successor
 */
final class PlanState extends StateMachine
{
    public const DRAFT      = 'draft';
    public const CURRENT    = 'current';
    public const SUPERSEDED = 'superseded';
    public const WITHDRAWN  = 'withdrawn';

    public static function initial(): string
    {
        return self::DRAFT;
    }

    public static function states(): array
    {
        return [self::DRAFT, self::CURRENT, self::SUPERSEDED, self::WITHDRAWN];
    }

    public static function transitions(): array
    {
        return [
            self::DRAFT      => [self::CURRENT, self::WITHDRAWN],
            self::CURRENT    => [self::SUPERSEDED, self::WITHDRAWN],
            // A superseded version may be restored to current if a new version
            // is rolled back — grandfathered subscribers are unaffected either way.
            self::SUPERSEDED => [self::CURRENT, self::WITHDRAWN],
            self::WITHDRAWN  => [],
        ];
    }

    public static function terminal(): array
    {
        return [self::WITHDRAWN];
    }

    public static function purchasable(): array
    {
        return [self::CURRENT];
    }

    /** Grandfathering: superseded plans must still resolve entitlements. */
    public static function resolvable(): array
    {
        return [self::CURRENT, self::SUPERSEDED];
    }
}
