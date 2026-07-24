<?php

namespace App\Engines\Infrastructure\States;

/**
 * Lifecycle of a single renewal event.
 *
 * A renewal is an event with retry history, not a timestamp. No payment
 * integration exists in this phase — `attempting` means "the renewal process is
 * running", which under Mode A is a staff action.
 *
 * `dunning` is distinct from `failed`: failed is one unsuccessful attempt,
 * dunning is the recovery process. `abandoned` closes a renewal that was never
 * recovered, without implying the subscription itself is cancelled — that is a
 * separate commercial decision on the subscription machine.
 */
final class RenewalState extends StateMachine
{
    public const SCHEDULED  = 'scheduled';
    public const DUE        = 'due';
    public const ATTEMPTING = 'attempting';
    public const RENEWED    = 'renewed';
    public const FAILED     = 'failed';
    public const DUNNING    = 'dunning';
    public const ABANDONED  = 'abandoned';
    public const CANCELLED  = 'cancelled';

    public static function initial(): string
    {
        return self::SCHEDULED;
    }

    public static function states(): array
    {
        return [
            self::SCHEDULED, self::DUE, self::ATTEMPTING, self::RENEWED,
            self::FAILED, self::DUNNING, self::ABANDONED, self::CANCELLED,
        ];
    }

    public static function transitions(): array
    {
        return [
            self::SCHEDULED  => [self::DUE, self::CANCELLED],
            self::DUE        => [self::ATTEMPTING, self::CANCELLED],
            self::ATTEMPTING => [self::RENEWED, self::FAILED],
            self::FAILED     => [self::DUNNING, self::ABANDONED, self::ATTEMPTING],
            self::DUNNING    => [self::ATTEMPTING, self::ABANDONED, self::RENEWED],
            self::RENEWED    => [],
            self::ABANDONED  => [],
            self::CANCELLED  => [],
        ];
    }

    public static function terminal(): array
    {
        return [self::RENEWED, self::ABANDONED, self::CANCELLED];
    }

    /** States a scheduler should pick up. */
    public static function actionable(): array
    {
        return [self::SCHEDULED, self::DUE, self::DUNNING];
    }
}
