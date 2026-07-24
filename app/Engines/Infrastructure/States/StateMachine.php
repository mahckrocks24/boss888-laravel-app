<?php

namespace App\Engines\Infrastructure\States;

use InvalidArgumentException;

/**
 * INFRA888 — base explicit state machine.
 *
 * Directive §6: "Do not reduce provisioning to booleans such as verified = true /
 * active = true. Use auditable lifecycle states."
 *
 * The legacy custom-domain implementation is the cautionary example: it modelled a
 * multi-step, externally-dependent, failure-prone provisioning flow as two booleans
 * (custom_domain set, domain_verified true), which is why a half-provisioned domain
 * was indistinguishable from a working one.
 *
 * Concrete machines declare states(), transitions() and terminal(). Everything else
 * — validation, guards, reachability — is inherited.
 */
abstract class StateMachine
{
    /** @return array<int,string> every legal state */
    abstract public static function states(): array;

    /** @return array<string,array<int,string>> from-state => allowed to-states */
    abstract public static function transitions(): array;

    /** @return array<int,string> states from which nothing may follow */
    abstract public static function terminal(): array;

    abstract public static function initial(): string;

    public static function isValidState(string $state): bool
    {
        return in_array($state, static::states(), true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        if (!static::isValidState($from) || !static::isValidState($to)) {
            return false;
        }

        if (in_array($from, static::terminal(), true)) {
            return false;
        }

        return in_array($to, static::transitions()[$from] ?? [], true);
    }

    /**
     * @throws InvalidArgumentException when the transition is illegal.
     */
    public static function assertTransition(string $from, string $to): void
    {
        if (!static::isValidState($from)) {
            throw new InvalidArgumentException(
                static::label() . ": unknown current state '{$from}'."
            );
        }

        if (!static::isValidState($to)) {
            throw new InvalidArgumentException(
                static::label() . ": unknown target state '{$to}'."
            );
        }

        if (in_array($from, static::terminal(), true)) {
            throw new InvalidArgumentException(
                static::label() . ": '{$from}' is terminal; cannot move to '{$to}'."
            );
        }

        if (!static::canTransition($from, $to)) {
            throw new InvalidArgumentException(
                static::label() . ": illegal transition '{$from}' -> '{$to}'."
            );
        }
    }

    /** States that represent a healthy, customer-visible working service. */
    public static function operational(): array
    {
        return ['active'];
    }

    /** States that mean "something went wrong and a human may need to look". */
    public static function failureStates(): array
    {
        return array_values(array_intersect(
            static::states(),
            ['failed', 'degraded', 'past_due', 'suspended']
        ));
    }

    public static function label(): string
    {
        $parts = explode('\\', static::class);

        return end($parts);
    }
}
