<?php

namespace App\Engines\Infrastructure\States;

/**
 * INFRA888 · S3 — lifecycle of a single DOMAIN renewal.
 *
 * Deliberately modelled on the existing RenewalState vocabulary so operators
 * read one language across the platform — but it is a separate machine, because
 * a domain renewal has a failure mode a subscription renewal does not:
 *
 *   THE PROVIDER MAY CHARGE US AND THEN FAIL TO TELL US.
 *
 * A billable call that times out is not a failure and is not a success. Treating
 * it as failure causes a double renewal (double charge); treating it as success
 * causes an expired domain and a dead customer site. Both are worse than
 * admitting we do not know — so ambiguity gets its own state, VERIFYING, and the
 * only way out of it is reading the registrar's actual expiry date back.
 *
 * NEEDS_MANUAL is a real operational state, not an error bucket: it means
 * automation has correctly refused to guess and a human must decide.
 */
final class DomainRenewalState extends StateMachine
{
    public const SCHEDULED    = 'scheduled';
    public const DUE          = 'due';
    public const ATTEMPTING   = 'attempting';
    public const VERIFYING    = 'verifying';
    public const RENEWED      = 'renewed';
    public const FAILED       = 'failed';
    public const DUNNING      = 'dunning';
    public const NEEDS_MANUAL = 'needs_manual';
    public const ABANDONED    = 'abandoned';
    public const CANCELLED    = 'cancelled';

    public static function initial(): string
    {
        return self::SCHEDULED;
    }

    public static function states(): array
    {
        return [
            self::SCHEDULED, self::DUE, self::ATTEMPTING, self::VERIFYING,
            self::RENEWED, self::FAILED, self::DUNNING, self::NEEDS_MANUAL,
            self::ABANDONED, self::CANCELLED,
        ];
    }

    public static function transitions(): array
    {
        return [
            self::SCHEDULED  => [self::DUE, self::CANCELLED],
            self::DUE        => [self::ATTEMPTING, self::CANCELLED],

            // An attempt can only ever land in one of these three. It may NOT go
            // straight to RENEWED: success is established by read-back, not by
            // the provider's own say-so.
            // ABANDONED is reachable directly only for a PERMANENT provider
            // rejection: the registrar refused the request outright, so no money
            // moved and there is nothing to verify. Every other exit from an
            // attempt must still pass through VERIFYING or FAILED.
            self::ATTEMPTING => [self::VERIFYING, self::FAILED, self::NEEDS_MANUAL, self::ABANDONED],

            // Verification either proves the expiry moved, proves it did not, or
            // exhausts its attempts and escalates to a human.
            self::VERIFYING  => [self::RENEWED, self::FAILED, self::NEEDS_MANUAL],

            self::FAILED     => [self::DUNNING, self::ATTEMPTING, self::ABANDONED, self::NEEDS_MANUAL],
            self::DUNNING    => [self::ATTEMPTING, self::ABANDONED, self::NEEDS_MANUAL],

            // A human can resolve a stuck renewal either way.
            self::NEEDS_MANUAL => [self::RENEWED, self::ABANDONED, self::ATTEMPTING],

            self::RENEWED    => [],
            self::ABANDONED  => [],
            self::CANCELLED  => [],
        ];
    }

    public static function terminal(): array
    {
        return [self::RENEWED, self::ABANDONED, self::CANCELLED];
    }

    /** States the scheduler may pick up for an execution attempt. */
    public static function actionable(): array
    {
        return [self::SCHEDULED, self::DUE, self::DUNNING];
    }

    /** States that mean "money may already have moved" — never re-attempt blindly. */
    public static function inFlight(): array
    {
        return [self::ATTEMPTING, self::VERIFYING];
    }

    /** States a human must look at. */
    public static function needsAttention(): array
    {
        return [self::NEEDS_MANUAL, self::DUNNING, self::VERIFYING];
    }

    /** An open renewal blocks a second one being created for the same domain. */
    public static function open(): array
    {
        return [
            self::SCHEDULED, self::DUE, self::ATTEMPTING, self::VERIFYING,
            self::FAILED, self::DUNNING, self::NEEDS_MANUAL,
        ];
    }
}
