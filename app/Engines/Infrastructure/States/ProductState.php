<?php

namespace App\Engines\Infrastructure\States;

/**
 * Catalog lifecycle of an infrastructure PRODUCT.
 *
 * A product is never deleted. Retirement is a state, and retiring a product that
 * still has active subscribers requires a successor — enforced in the service
 * layer so that historical rows never become unwritable.
 *
 *   draft      not sellable, not visible; being prepared
 *   active     sellable
 *   deprecated not sellable to NEW customers; existing subscribers still served
 *   retired    not sellable, no active subscribers permitted
 */
final class ProductState extends StateMachine
{
    public const DRAFT      = 'draft';
    public const ACTIVE     = 'active';
    public const DEPRECATED = 'deprecated';
    public const RETIRED    = 'retired';

    public static function initial(): string
    {
        return self::DRAFT;
    }

    public static function states(): array
    {
        return [self::DRAFT, self::ACTIVE, self::DEPRECATED, self::RETIRED];
    }

    public static function transitions(): array
    {
        return [
            self::DRAFT      => [self::ACTIVE, self::RETIRED],
            // Reactivation is legitimate: a product pulled for a pricing review
            // may return.
            self::ACTIVE     => [self::DEPRECATED, self::RETIRED],
            self::DEPRECATED => [self::ACTIVE, self::RETIRED],
            self::RETIRED    => [],
        ];
    }

    public static function terminal(): array
    {
        return [self::RETIRED];
    }

    /** States in which a product may be sold to a NEW customer. */
    public static function sellable(): array
    {
        return [self::ACTIVE];
    }

    /** States in which existing subscribers must continue to be served. */
    public static function serviceable(): array
    {
        return [self::ACTIVE, self::DEPRECATED];
    }
}
